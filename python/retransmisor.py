"""
Retransmisor de video (solo librería estándar, sin dependencias nuevas).

Problema que resuelve: la ESP32-CAM se satura con 2 clientes a la vez
(página Monitoreo + script YOLO) y uno pierde el video. Además, una conexión
MJPEG permanente bloquea el resto de rutas (/beep) en la ESP32.
Topología con proxy (la ESP32 solo atiende 1 petición corta a la vez):
  ESP32-CAM <-sondeo /capture- este proxy (puerto 8080) <-leen- navegador + YOLO + celular

Uso standalone:
  python retransmisor.py                       # usa ESP32_BASE_URL y PROXY_PORT del .env
  python retransmisor.py --port 8080 --source http://192.168.137.200

Uso integrado: vigilancia_yolo.py lo enciende solo si PROXY_PORT está puesto.
Rutas:
  /stream  -> video MJPEG (para Dashboard/Monitoreo/YOLO)
  /capture -> última foto JPEG
  /        -> estado
"""

import argparse
import os
import threading
import time
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SOURCE_DEFAULT = os.getenv("ESP32_BASE_URL", "http://192.168.137.200").rstrip("/") + "/capture"
PORT_DEFAULT = int(os.getenv("PROXY_PORT", "8080") or 0)

latest = {"jpg": None, "t": 0.0, "ok": False}


def lector(source: str) -> None:
    """Sondea /capture en bucle (~4 fps). Son peticiones cortas: la ESP32
    nunca queda con una conexión colgada y /beep siempre entra.
    4 fps basta para YOLO en maqueta y evita saturar/sobrecalentar la ESP32
    (a 10-12 fps la cámara se cuelga y deja de responder HTTP)."""
    last_warn = 0.0
    while True:
        try:
            req = urllib.request.Request(source, headers={"User-Agent": "vigilancia-proxy", "Connection": "close"})
            with urllib.request.urlopen(req, timeout=5) as r:
                ctype = r.headers.get("Content-Type") or ""
                if r.status != 200 or "image" not in ctype:
                    raise ValueError(f"HTTP {r.status} {ctype}")
                jpg = r.read(300000)
            if len(jpg) < 100 or jpg[:2] != b"\xff\xd8" or jpg[-2:] != b"\xff\xd9":
                raise ValueError("respuesta no es JPEG válido")
            latest["jpg"] = jpg
            latest["t"] = time.time()
            latest["ok"] = True
            time.sleep(0.25)
        except Exception as exc:
            latest["ok"] = latest["ok"] and (time.time() - latest["t"] < 10)
            if time.time() - last_warn > 5:
                last_warn = time.time()
                print(f"[proxy] fuente no disponible ({exc}), reintento…")
            time.sleep(1)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"  # los navegadores reproducen mejor MJPEG en 1.1 que en 1.0

    def log_message(self, *a):  # silencioso
        pass

    def _jpg(self):
        jpg = latest["jpg"]
        if not jpg or time.time() - latest["t"] > 10:
            self.send_response(503)
            self.end_headers()
            self.wfile.write("sin video de la ESP32 (revisala)".encode())
            return None
        return jpg

    def do_GET(self):
        ruta = self.path.split("?", 1)[0]  # ignora ?t=cache-buster de la página
        if ruta == "/capture":
            jpg = self._jpg()
            if jpg is None:
                return
            self.send_response(200)
            self.send_header("Content-Type", "image/jpeg")
            self.send_header("Content-Length", str(len(jpg)))
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            self.wfile.write(jpg)
        elif ruta == "/stream":
            self.send_response(200)
            self.send_header("Content-Type", "multipart/x-mixed-replace; boundary=frame")
            self.send_header("Access-Control-Allow-Origin", "*")
            self.end_headers()
            ultimo = 0.0
            try:
                while True:
                    if latest["jpg"] and latest["t"] > ultimo and time.time() - latest["t"] < 10:
                        jpg = latest["jpg"]
                        ultimo = latest["t"]
                        self.wfile.write(b"--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " +
                                         str(len(jpg)).encode() + b"\r\n\r\n" + jpg + b"\r\n")
                    else:
                        time.sleep(0.05)
            except (BrokenPipeError, ConnectionResetError):
                pass  # el navegador cerró, normal
        elif ruta == "/":
            body = f"proxy OK | fuente {self.server.fuente} | ultimo frame hace {time.time() - latest['t']:.1f}s".encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        else:
            self.send_response(404)
            self.end_headers()


def lanzar_en_hilo(port: int, source: str):
    """Enciende el proxy en segundo plano. Devuelve (server, hilo)."""
    threading.Thread(target=lector, args=(source,), daemon=True).start()
    server = ThreadingHTTPServer(("0.0.0.0", port), Handler)
    server.daemon_threads = True
    server.fuente = source
    hilo = threading.Thread(target=server.serve_forever, daemon=True)
    hilo.start()
    return server, hilo


def iniciar(port: int = PORT_DEFAULT, source: str = SOURCE_DEFAULT) -> None:
    print(f"[proxy] fuente {source} -> http://0.0.0.0:{port}/stream")
    lanzar_en_hilo(port, source)
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        pass


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=PORT_DEFAULT)
    ap.add_argument("--source", default=SOURCE_DEFAULT)
    a = ap.parse_args()
    iniciar(a.port, a.source)
