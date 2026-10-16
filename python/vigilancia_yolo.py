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
# http por defecto: el TLS :443 a blynk.cloud se cuelga en redes compartidas
# (probado: handshake sin ServerHello, todo da timeout). Con http responde al instante.
BLYNK_SCHEME = os.getenv("BLYNK_SCHEME", "http").strip().lower() or "http"
if BLYNK_SCHEME not in ("http", "https"):
    BLYNK_SCHEME = "http"
BLYNK_ARMA_PIN = os.getenv("BLYNK_ARMA_PIN", "V4")  # switch en Blynk: 1 armada, 0 desarmada
# Rendimiento: procesa 1 de cada N frames (los demás solo se muestran).
# N=2 duplica FPS efectivos con pérdida mínima en maqueta.
YOLO_FRAME_SKIP = max(1, int(os.getenv("YOLO_FRAME_SKIP", "2")))
# Cada cuántos segundos se re-lee V4/perímetro sin congelar el video.
POLL_SEGUNDOS = float(os.getenv("POLL_SEGUNDOS", "10"))

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


def leer_armado_blynk() -> Optional[bool]:
    """Lee el switch de armado en Blynk (V4: 1 armada, 0 desarmada).
    None si no hay token o falla la red (se asume ARMADA para no dejar
    la maqueta sin protección por un corte de internet).
    Usa http por defecto: https a blynk.cloud:443 se cuelga en esta red."""
    if not BLYNK_TOKEN:
        return None
    try:
        # Formato exacto Blynk: /external/api/get?token=XXX&V4 (pin sin valor)
        r = requests.get(f"{BLYNK_SCHEME}://{BLYNK_SERVER}/external/api/get?token={BLYNK_TOKEN}&{BLYNK_ARMA_PIN}",
                         timeout=3)
        # Blynk responde el valor como texto: ["1"] / ["0"] o 1 / 0
        txt = r.text.strip().strip('[" ]')
        if txt.startswith("1"):
            return True
        if txt.startswith("0"):
            return False
        return None
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
    """Hilo fondo: latido Online por HTTP API (NO sesión hardware).

    Por qué no hay sesión hardware: Blynk IoT solo acepta hardware por TLS
    :443 y en esta red el TLS a 64.225.16.22 (blynk.cloud/ny3) se cuelga en
    el ClientHello (probado: HTTP :80 responde al instante, TLS nunca).
    Los puertos 80/8080 son solo API HTTP y cierran el binario hardware.
    Sin socket hardware, `isHardwareConnected` siempre será false y la app
    mostrará "offline": es cosmético. Lo que SÍ funciona por HTTP y mantiene
    útil el sistema: V0/V1/V2/V3, evento intruso_casa y lectura de V4.
    Este latido refresca V3=1 cada 60s para que el LED "sistema activo"
    de la app quede encendido aunque el badge nativo diga offline.
    """
    intervalo = float(os.getenv("BLYNK_HEARTBEAT_SEG", "60") or 60)
    print(f"[blynk] sin sesión hardware (TLS bloqueado a {BLYNK_SERVER}). "
          f"Latido HTTP V3=1 cada {intervalo:.0f}s; pines y V4 por API siguen OK.")
    while True:
        try:
            requests.get(
                f"{BLYNK_SCHEME}://{BLYNK_SERVER}/external/api/update",
                params={"token": BLYNK_TOKEN, "V3": 1},
                timeout=5,
            )
        except Exception as exc:
            print(f"[blynk] latido HTTP falló ({exc}). Reintento en {intervalo:.0f}s.")
        time.sleep(intervalo)


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
    import threading as _th

    class CapturaFluida:
        """Lee el MJPEG en hilo propio y guarda SOLO el último frame.

        Sin esto, cv2.VideoCapture acumula buffer (2-5s de retraso) y cada
        cap.read() bloquea al ritmo de la red → video a tirones. Con hilo,
        el loop YOLO/display siempre toma lo más fresco y nunca se atasca.
        """
        def __init__(self, fuente):
            self.cap = cv2.VideoCapture(fuente)
            # Buffer mínimo: no guardar historia, solo lo fresco
            try:
                self.cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
            except Exception:
                pass
            self.fuente = fuente
            self.frame = None
            self.ok = False
            self._vive = True
            self._hilo = _th.Thread(target=self._bucle, daemon=True)
            self._hilo.start()

        def _bucle(self):
            while self._vive:
                ok, fr = self.cap.read()
                if ok and fr is not None:
                    self.frame = fr
                    self.ok = True
                else:
                    self.ok = False
                    time.sleep(0.2)
                    try:
                        self.cap.open(self.fuente)
                    except Exception:
                        pass

        def leer(self):
            return (self.frame is not None), (self.frame.copy() if self.frame is not None else None)

        def abierta(self):
            return self.cap.isOpened() or self.frame is not None

    cap = CapturaFluida(stream)
    time.sleep(1.5)  # deja que el hilo llene el primer frame
    if not cap.abierta() or cap.frame is None:
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
    estado = {"armado": True}  # lo actualiza el hilo de fondo, el loop solo lee

    def _hilo_poll(camera_id: int):
        """Lee V4 + perímetro sin congelar el video (antes se hacía en el
        loop cada 5s y con https colgado congelaba YOLO 5s)."""
        global FENCE_Y
        while True:
            try:
                nuevo = leer_perimetro_api(camera_id)
                if nuevo is not None and abs(nuevo - FENCE_Y) > 0.005:
                    FENCE_Y = nuevo
                    print(f"[perimetro] movido desde la web: {FENCE_Y:.0%}")
            except Exception:
                pass
            try:
                a = leer_armado_blynk()
                if a is not None and a != estado["armado"]:
                    estado["armado"] = a
                    print("[alarma] " + ("ARMADA desde Blynk." if a else "DESARMADA desde Blynk: video sí, detección no."))
            except Exception:
                pass
            time.sleep(POLL_SEGUNDOS)

    if BLYNK_TOKEN:
        import threading

        threading.Thread(target=_latido_blynk, daemon=True).start()
        threading.Thread(target=_hilo_poll, args=(args.camera_id,), daemon=True).start()
    else:
        import threading

        threading.Thread(target=_hilo_poll, args=(args.camera_id,), daemon=True).start()
        print("[blynk] sin BLYNK_TOKEN: el Device saldrá 'offline' (pines y push siguen funcionando).")

    print(f"[ok] cámara DB id={args.camera_id} | perímetro={FENCE_Y} | conf>={CONF_MIN} | skip=1/{YOLO_FRAME_SKIP} | imgsz={IMGSZ}. Pulsa Q para salir.")
    persons_cache = []
    fps_t = time.time()
    fps_n = 0
    fps_show = 0.0
    while True:
        ok, frame = cap.leer()
        if not ok or frame is None:
            time.sleep(0.05)
            continue

        H, W = frame.shape[:2]
        frames += 1
        armado = estado["armado"]
        # Línea de perímetro (barda de la maqueta)
        cv2.line(frame, (0, int(H * FENCE_Y)), (W, int(H * FENCE_Y)), (0, 255, 0), 2)
        cv2.putText(frame, "PERIMETRO", (10, int(H * FENCE_Y) - 8), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (0, 255, 0), 2)

        toca_inferir = armado and (frames % YOLO_FRAME_SKIP == 1)
        t0 = time.time()
        if toca_inferir:
            results = model(frame, imgsz=IMGSZ, verbose=False)[0]
            persons_cache = [b for b in results.boxes or [] if int(b.cls[0]) == 0 and float(b.conf[0]) >= CONF_MIN]
            persons = persons_cache
        elif armado:
            persons = persons_cache  # frame intermedio: reutiliza cajas, no gasta CPU
        else:
            persons = []  # desarmada desde Blynk: se ve video pero no se detecta ni alerta
            persons_cache = []
            cv2.putText(frame, "ALARMA DESARMADA (Blynk V4)", (10, 50), cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 165, 255), 2)
        inf_ms = (time.time() - t0) * 1000 if toca_inferir else 0
        now = time.time()

        if now - last_status > 5:
            last_status = now
            print(f"[estado] frame {W}x{H} #{frames} | inferencia {inf_ms:.0f}ms | personas: {len(persons)} | armada: {'SI' if armado else 'NO'}" +
                  (f" ({last_event})" if persons and last_event else ""))
            if toca_inferir and inf_ms > 2000:
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

        fps_n += 1
        if time.time() - fps_t >= 2:
            fps_show = fps_n / (time.time() - fps_t)
            fps_t, fps_n = time.time(), 0
        cv2.putText(frame, f"{fps_show:.0f}fps", (W - 90, 24), cv2.FONT_HERSHEY_SIMPLEX, 0.6, (255, 255, 255), 2)

        if not args.no_show:
            cv2.imshow(f"Vigilancia YOLO - cam {args.camera_id} (Q=salir)", frame)
            if cv2.waitKey(1) & 0xFF in (ord("q"), ord("Q")):
                break

    try:
        cap._vive = False
    except Exception:
        pass
    cv2.destroyAllWindows()


if __name__ == "__main__":
    main()
