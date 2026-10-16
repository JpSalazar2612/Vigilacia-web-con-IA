"""
Vigilancia perimetral con YOLO (Ultralytics) + ESP32-CAM + API Laravel + Blynk.

Flujo maqueta (casa de madera 35x30, barda, 1x ESP32-CAM + ESP32-CAM-MB):
  ESP32-CAM (MJPEG http://192.168.137.97/stream)
      -> este script (OpenCV + YOLOv8n, clase `person`)
      -> al cruzar el perímetro: guarda foto, POST /api/v1/evidencias,
         POST /api/v1/detections (Laravel crea la alerta y empuja a Blynk:
         V0 texto, V1 presencia, V2 foto, Evento intruso_casa = push
         "¡Alguien entró a tu casa!")
      -> opcional: hace sonar el buzzer físico de la maqueta (GET http://IP/beep)

Instalación (PC con Python 3.9+):
  pip install -r requirements.txt
  # El primer arranque descarga yolov8n.pt automáticamente (~6 MB).

Uso:
  cp .env.ejemplo .env   # o exporta las variables
  python vigilancia_yolo.py --camera-id 5
  python vigilancia_yolo.py --camera-id 5 --stream http://192.168.137.97/stream
  python vigilancia_yolo.py --source 0 --camera-id 5   # webcam del PC (prueba YOLO sin ESP32)
  python vigilancia_yolo.py --test-api --camera-id 5  # prueba API+fotos sin YOLO ni cámara

Getion de credenciales:
  1) Crea un usuario en tu Laravel y pide token: POST /api/v1/login
     { "email": "...", "password": "..." } -> { "token": "1|..." }
  2) Pon ese token en API_TOKEN (solo necesita POST evidencias/detections).
"""

import argparse
import os
import time
import uuid
from collections import deque
from typing import Optional

import cv2
import requests

try:
    from dotenv import load_dotenv

    # .env junto al script (funciona desde cualquier carpeta donde lo corras)
    load_dotenv(dotenv_path=os.path.join(os.path.dirname(os.path.abspath(__file__)), ".env"))
except ImportError:
    pass  # funciona también solo con variables de entorno

try:
    import winsound  # pitido local en Windows

    def beep_local(freq=440, ms=500, times=3):
        for _ in range(times):
            winsound.Beep(freq, ms)
            time.sleep(0.15)

except ImportError:

    def beep_local(freq=440, ms=500, times=3):
        print("\a" * times, flush=True)  # campana del terminal (macOS/Linux)
        print(f"[ALERTA] pitido {freq}Hz x{times}")

# --- Configuración (env con defaults para la maqueta) ---
ESP32_STREAM = os.getenv("ESP32_STREAM_URL", "http://192.168.1.50/stream")
ESP32_BASE = os.getenv("ESP32_BASE_URL", "http://192.168.1.50")  # para /beep físico (mismo puerto 80)
API_BASE = os.getenv("API_BASE_URL", "http://localhost:8000/api/v1").rstrip("/")
API_TOKEN = os.getenv("API_TOKEN", "")
FENCE_Y = float(os.getenv("FENCE_Y", "0.60"))  # 0.60 = línea al 60% (barda maqueta)
CONF_MIN = float(os.getenv("YOLO_CONF", "0.50"))
MODEL_NAME = os.getenv("YOLO_MODEL", "yolov8n.pt")
IMGSZ = int(os.getenv("YOLO_IMGSZ", "640"))  # súbelo a 960 si las figuras de la maqueta salen pequeñas
BUZZER_FISICO = os.getenv("BUZZER_FISICO", "true").lower() in ("1", "true", "si", "sí")
# Latido Blynk: con el token del Device, Python abre la sesión Blynk que el
# ESP32-CAM no puede abrir (toda su RAM se va en la cámara + WiFi).
BLYNK_TOKEN = os.getenv("BLYNK_TOKEN", "")
BLYNK_SERVER = os.getenv("BLYNK_SERVER", "blynk.cloud")

COOLDOWN = {"approaching": 30.0, "climbing": 20.0, "breach_confirmed": 30.0}

HEADERS_JSON = {"Accept": "application/json", "Content-Type": "application/json"}
HEADERS_AUTH = {"Accept": "application/json", "Authorization": f"Bearer {API_TOKEN}"}


def clasificar(cy: float, fence: float) -> str:
    """approaching (fuera) / climbing (sobre la barda) / breach_confirmed (dentro)."""
    if cy > fence:
        return "breach_confirmed"
    if abs(cy - fence) < 0.08:
        return "climbing"
    return "approaching"


def perimetro_desde_fence(fence) -> Optional[float]:
    """Extrae la altura 0-1 desde fence_line [[x1,y1],[x2,y2]]. None si inválido."""
    try:
        y = float(fence[0][1])
        return y if 0.05 <= y <= 0.95 else None
    except (TypeError, IndexError, ValueError):
        return None


def leer_perimetro_api(camera_id: int) -> Optional[float]:
    """Lee el perímetro que se movió en la web (campo fence_line de la cámara)."""
    try:
        r = requests.get(f"{API_BASE}/cameras/{camera_id}", headers={"Accept": "application/json"}, timeout=5)
        r.raise_for_status()
        return perimetro_desde_fence((r.json().get("data") or {}).get("fence_line"))
    except Exception:
        return None


def sonar_buzzer_maqueta():
    """Activa el buzzer/LED de la maqueta (firmware expone GET /beep, puerto 80)."""
    if not BUZZER_FISICO:
        return
    try:
        requests.get(f"{ESP32_BASE.rstrip('/')}/beep", timeout=3)
    except Exception as exc:  # la maqueta sigue funcionando sin buzzer
        print(f"[buzzer] no disponible: {exc}")


def subir_evidencia(frame) -> Optional[str]:
    """Sube un frame JPEG a Laravel. Devuelve snapshot_path."""
    ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 85])
    if not ok:
        return None
    files = {"image": (f"frame_{uuid.uuid4().hex[:8]}.jpg", buf.tobytes(), "image/jpeg")}
    r = requests.post(f"{API_BASE}/evidencias", headers={"Accept": "application/json", "Authorization": f"Bearer {API_TOKEN}"}, files=files, timeout=15)
    r.raise_for_status()
    return r.json()["path"]


def enviar_deteccion(camera_id: int, track_id: str, event: str, conf: float, bbox_norm: dict, snapshot: Optional[str]):
    body = {
        "camera_id": camera_id,
        "track_id": track_id,
        "event_type": event,
        "confidence": round(float(conf), 2),
        "bbox": bbox_norm,
        "meta": {"source": "python-yolo", "model": MODEL_NAME, "fence_y": FENCE_Y},
    }
    if snapshot:
        body["snapshot_path"] = snapshot
    r = requests.post(f"{API_BASE}/detections", headers={**HEADERS_JSON, "Authorization": f"Bearer {API_TOKEN}"}, json=body, timeout=15)
    r.raise_for_status()
    return r.json()


def test_api_chain(camera_id: int) -> int:
    """Prueba la cadena Laravel+Blynk sin YOLO ni cámara.

    Sube una foto de prueba y envía un evento `approaching` (no crea alerta
    ni push: es solo para comprobar que API_TOKEN, CAMERA_ID y la subida
    de fotos funcionan). Devuelve exit code.
    """
    import numpy as np

    frame = np.zeros((480, 640, 3), dtype=np.uint8)
    cv2.putText(frame, "PRUEBA API", (150, 240), cv2.FONT_HERSHEY_SIMPLEX, 1.5, (255, 255, 255), 3)
    try:
        snapshot = subir_evidencia(frame)
        print(f"[test-api] foto OK: {snapshot}");
        resp = enviar_deteccion(camera_id, "TEST-API", "approaching", 0.99,
                                {"x": 0.1, "y": 0.1, "w": 0.2, "h": 0.3}, snapshot)
        print(f"[test-api] deteccion OK: id={resp['detection']['id']} alerta={resp.get('alert') is not None}")
        print("[test-api] Cadena API correcta. Si YOLO no detecta, el problema es el modelo/cámara, no la API.")
        return 0
    except Exception as exc:
        print(f"[test-api] FALLO: {exc}")
        print("[test-api] Revisa API_BASE_URL, API_TOKEN y que la cámara exista en el Dashboard.")
        return 1


def esperar_proxy(port: str, segundos: int = 20) -> bool:
    """Espera a que el proxy tenga video fresco de la ESP32. Sin esto,
    OpenCV falla con un críptico 'Couldn't read video stream'."""
    import urllib.request

    url = f"http://localhost:{port}/"
    for _ in range(segundos * 2):
        try:
            with urllib.request.urlopen(url, timeout=3) as r:
                txt = r.read().decode(errors="ignore")
                if "ultimo frame hace" in txt:
                    try:
                        hace = float(txt.split("hace")[1].split("s")[0])
                    except ValueError:
                        hace = 999.0
                    if hace < 5:
                        return True
        except Exception:
            pass
        time.sleep(0.5)
    return False


def _latido_blynk():
    """Hilo fondo: sesión Blynk (protocolo hardware, stdlib) para el estado Online.

    El ESP32-CAM no puede abrirla (toda su RAM se va en cámara+WiFi), así que la
    abre el PC. Si la red bloquea el puerto o falla algo, reintenta en silencio:
    Laravel ya actualiza V0/V1/V2/V3 por HTTP API y eso no depende de esto.
    """
    import socket
    import ssl
    import struct

    def recvn(s, n):
        buf = b''
        while len(buf) < n:
            chunk = s.recv(n - len(buf))
            if not chunk:
                raise ConnectionError('cerrada por el servidor')
            buf += chunk
        return buf

    def send(s, mtype, body=b'', mid=1):
        if isinstance(body, str):
            body = body.encode()
        s.sendall(struct.pack('>BHH', mtype, mid, len(body)) + body)

    mid = 0
    while True:
        try:
            raw = socket.create_connection((BLYNK_SERVER, 443), timeout=10)
            s = ssl.create_default_context().wrap_socket(raw, server_hostname=BLYNK_SERVER)
            mid = (mid % 60000) + 1
            send(s, 2, BLYNK_TOKEN, mid)  # LOGIN
            s.settimeout(12)
            mtype, rmid, status = struct.unpack('>BHH', recvn(s, 5))
            if mtype == 5:  # REDIRECT a servidor regional
                raise ConnectionError('redirect no soportado aquí, reintento')
            if not (mtype == 0 and status == 200):
                raise ConnectionError(f'login rechazado (status={status})')
            print('[blynk] sesión abierta, Device online.')
            s.settimeout(25)
            while True:
                try:
                    mtype, rmid, ln = struct.unpack('>BHH', recvn(s, 5))
                    if ln and mtype != 0:
                        recvn(s, ln)
                    if mtype == 6:  # PING del servidor -> respondo
                        s.sendall(struct.pack('>BHH', 0, rmid, 200))
                except socket.timeout:
                    mid = (mid % 60000) + 1
                    send(s, 6, b'', mid)  # PING propio mantiene viva la sesión
        except Exception as exc:
            print(f'[blynk] sesión no disponible ({exc}). Reintento en 30s; pines por API siguen OK.')
            try:
                s.close()
            except Exception:
                pass
            time.sleep(30)


def main() -> None:
    ap = argparse.ArgumentParser(description="YOLO personas -> API Laravel -> Blynk")
    ap.add_argument("--camera-id", type=int, default=int(os.getenv("CAMERA_ID", "1")))
    ap.add_argument("--stream", default=None, help="URL MJPEG (por defecto ESP32_STREAM_URL)")
    ap.add_argument("--source", default=None, help="fuente OpenCV: 0 = webcam del PC (prueba YOLO)")
    ap.add_argument("--no-show", action="store_true", help="sin ventana (servidor)")
    ap.add_argument("--test-api", action="store_true", help="solo prueba API+fotos, sin YOLO ni cámara")
    args = ap.parse_args()

    if not API_TOKEN:
        raise SystemExit("Falta API_TOKEN. Haz POST /api/v1/login y exporta API_TOKEN='1|...'")

    if args.test_api:
        raise SystemExit(test_api_chain(args.camera_id))

    global FENCE_Y
    inicial = leer_perimetro_api(args.camera_id)
    if inicial is not None:
        FENCE_Y = inicial
        print(f"[perimetro] inicial desde la web: {FENCE_Y:.0%} (FENCE_Y del .env queda como respaldo)")

    from ultralytics import YOLO

    print(f"[yolo] cargando {MODEL_NAME} (clase person=0, imgsz={IMGSZ})…")
    model = YOLO(MODEL_NAME)

    # Proxy local: YOLO + navegadores leen del PC y la ESP32 solo ve 1 cliente.
    # Se enciende solo con PROXY_PORT en el .env (8081 recomendado).
    proxy_port = (os.getenv("PROXY_PORT", "") or "").strip()
    proxy_ok = False
    if proxy_port and args.source is None and not args.stream:
        try:
            from retransmisor import lanzar_en_hilo

            # El proxy sondea /capture (foto corta); /stream directo saturaría la ESP32
            lanzar_en_hilo(int(proxy_port), ESP32_BASE.rstrip("/") + "/capture")
            proxy_ok = True
            print(f"[proxy] retransmitiendo ESP32 -> http://localhost:{proxy_port}/stream")
        except Exception as exc:
            print(f"[proxy] no se pudo abrir :{proxy_port} ({exc}). YOLO leerá directo de la ESP32.")

    if proxy_ok:
        print("[proxy] esperando video de la ESP32 (máx 20s)…")
        if esperar_proxy(proxy_port, 20):
            stream = f"http://localhost:{proxy_port}/stream"
        else:
            print("[proxy] sin video: la ESP32 no le entrega nada al proxy. Intento directo…")
            proxy_ok = False
    if not proxy_ok:
        stream = args.source if args.source is not None else (args.stream or ESP32_STREAM)
    try:
        stream = int(stream)  # --source 0 -> webcam (índice numérico)
    except (TypeError, ValueError):
        pass
    print(f"[stream] abriendo {stream} …")
    print(f"[puertos] web Laravel http://localhost:8000 | video proxy http://localhost:{proxy_port or '?'} (interno, no se abre en el navegador)")
    cap = cv2.VideoCapture(stream)
    if not cap.isOpened():
        raise SystemExit(
            "No hay video. Revisa en orden:\n"
            " 1) ESP32-CAM encendida y conectada a tu WiFi (Monitor Serie 115200, pulsa RST).\n"
            f" 2) Que ESP32_STREAM_URL={ESP32_STREAM} abra en tu navegador (prueba /capture).\n"
            " 3) PC y ESP32 en la misma red. La IP cambia si no usaste IP fija en el .ino.")

    track_id = f"YOLO-{uuid.uuid4().hex[:4].upper()}"
    last_seen = 0.0
    last_sent = {k: 0.0 for k in COOLDOWN}
    last_skip_log = {k: 0.0 for k in COOLDOWN}
    historial = deque(maxlen=30)
    frames = 0
    last_status = time.time()
    last_event = None

    if BLYNK_TOKEN:
        import threading

        threading.Thread(target=_latido_blynk, daemon=True).start()
    else:
        print("[blynk] sin BLYNK_TOKEN: el Device saldrá 'offline' (pines y push siguen funcionando).")

    print(f"[ok] cámara DB id={args.camera_id} | perímetro={FENCE_Y} | conf>={CONF_MIN}. Pulsa Q para salir.")
    while True:
        ok, frame = cap.read()
        if not ok:
            print("[stream] frame perdido, reintentando…")
            time.sleep(1.0)
            cap.open(stream)
            continue

        H, W = frame.shape[:2]
        frames += 1
        # Línea de perímetro (barda de la maqueta)
        cv2.line(frame, (0, int(H * FENCE_Y)), (W, int(H * FENCE_Y)), (0, 255, 0), 2)
        cv2.putText(frame, "PERIMETRO", (10, int(H * FENCE_Y) - 8), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2)

        t0 = time.time()
        results = model(frame, imgsz=IMGSZ, verbose=False)[0]
        inf_ms = (time.time() - t0) * 1000
        persons = [b for b in results.boxes or [] if int(b.cls[0]) == 0 and float(b.conf[0]) >= CONF_MIN]
        now = time.time()

        if now - last_status > 5:
            last_status = now
            nuevo = leer_perimetro_api(args.camera_id)
            if nuevo is not None and abs(nuevo - FENCE_Y) > 0.005:
                FENCE_Y = nuevo
                print(f"[perimetro] movido desde la web: {FENCE_Y:.0%}")
            print(f"[estado] frame {W}x{H} #{frames} | inferencia {inf_ms:.0f}ms | personas: {len(persons)}" +
                  (f" ({last_event})" if persons and last_event else ""))
            if inf_ms > 2000:
                print("[aviso] inferencia muy lenta: cierra otros programas o baja YOLO_IMGSZ a 416.")

        if persons:
            # La más grande = la más cercana a la maqueta
            b = max(persons, key=lambda b: (float(b.xywh[0][2]) * float(b.xywh[0][3])))
            x1, y1, x2, y2 = map(float, b.xyxy[0])
            conf = float(b.conf[0])
            cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
            cy_n = cy / H

            if not track_id or now - last_seen > 5:
                track_id = f"YOLO-{uuid.uuid4().hex[:4].upper()}"
            last_seen = now
            historial.append(cy_n)

            event = clasificar(cy_n, FENCE_Y)
            last_event = event
            color = (0, 0, 255) if event == "breach_confirmed" else ((0, 255, 255) if event == "climbing" else (0, 255, 0))
            cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), color, 2)
            cv2.circle(frame, (int(cx), int(cy)), 5, color, -1)
            cv2.putText(frame, f"{event} {conf:.2f}", (int(x1), int(y1) - 6), cv2.FONT_HERSHEY_SIMPLEX, 0.6, color, 2)

            if now - last_sent[event] >= COOLDOWN[event]:
                last_sent[event] = now
                try:
                    snapshot = subir_evidencia(frame)
                    bbox = {"x": x1 / W, "y": y1 / H, "w": (x2 - x1) / W, "h": (y2 - y1) / H}
                    resp = enviar_deteccion(args.camera_id, track_id, event, conf, bbox, snapshot)
                    print(f"[api] {event} -> alerta={resp.get('alert') is not None} foto={snapshot}")
                except Exception as exc:
                    print(f"[api] error enviando {event}: {exc}")
            elif now - last_skip_log[event] > 10:
                last_skip_log[event] = now
                print(f"[{event}] detectado pero en cooldown, se reenvía en {COOLDOWN[event] - (now - last_sent[event]):.0f}s")

            if event == "breach_confirmed":
                print("¡Alguien entró a tu casa!")
                beep_local(440, 500, 3)
                sonar_buzzer_maqueta()
            elif event == "climbing":
                beep_local(880, 300, 2)
        else:
            cv2.putText(frame, "sin personas", (10, 24), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (200, 200, 200), 2)

        if not args.no_show:
            cv2.imshow(f"Vigilancia YOLO - cam {args.camera_id} (Q=salir)", frame)
            if cv2.waitKey(1) & 0xFF in (ord("q"), ord("Q")):
                break

    cap.release()
    cv2.destroyAllWindows()


if __name__ == "__main__":
    main()
