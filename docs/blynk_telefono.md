# Blynk en el teléfono: video + switch + galería

## 1. Video en vivo (teléfono)

**URL a poner en el widget Video:**

```
http://192.168.137.242:8080/stream
```

Reglas:

- El teléfono debe estar en el **mismo WiFi** que el PC y la ESP32
  (red `192.168.137.x`, puerta `192.168.137.1`).
- **NO** uses `localhost` (eso apunta al propio teléfono) ni
  `192.168.137.200` directo (la ESP32 solo admite 1 cliente y se cuelga;
  el proxy `:8080` es el que reparte a YOLO + web + teléfono).
- Prueba primero en el **navegador del teléfono**:
  `http://192.168.137.242:8080/` debe decir `proxy OK`.
  Si ahí no abre, el widget tampoco abrirá (problema de red, no de Blynk).
- Con **datos móviles** (fuera del WiFi) esa IP privada nunca abrirá.
  Alternativa fuera de casa: ver las **fotos en Galería V2** (esas sí
  llegan por la nube) o exponer el proxy con ngrok/Tailscale.
- Si cambias de red y la IP del PC cambia (`ipconfig getifaddr en0`),
  actualiza la URL del widget y el `stream_url` de la cámara 5.

## 2. Switch Armar/Desarmar (V4)

- Datastream **V4**: tipo `Integer`, min `0`, max `1`.
- Widget Switch en `V4`. YOLO lo lee cada ~10s (`POLL_SEGUNDOS`):
  `1` = detecta y alerta, `0` = solo video.
- Si la app dice **"dispositivo sin conexión"**: ese badge exige socket
  hardware TLS `:443`, bloqueado en esta red (probado: el TLS a
  `blynk.cloud`/`ny3` se cuelga, el HTTP `:80` responde al instante).
  Es **cosmético**: los pines por HTTP sí funcionan.
- Respaldo que siempre funciona: página **Monitoreo → Armar/Desarmar**
  (vía `POST /api/v1/blynk/arm`, requiere token). Úsalo desde el
  navegador del teléfono si el switch de la app no responde.

## 3. Widgets y datastreams

| Widget | Pin | Tipo | Notas |
|---|---|---|---|
| Texto alerta | V0 | String | `pushIntrusion` lo llena |
| Presencia | V1 | Integer 0-1 | LED |
| Galería fotos | V2 | Integer + Image Gallery, propiedad `urls` | 1 URL inicial cualquiera |
| Sistema activo | V3 | Integer 0-1 | LED; latido HTTP cada 60s (`BLYNK_HEARTBEAT_SEG`) |
| Armar switch | V4 | Integer 0-1 | Switch |
| Evento | `intruso_casa` | Events & Notifications | push + email + timeline |

## 4. Verificación rápida

```sh
# V4 actual (1 armada, 0 desarmada)
curl -s "http://blynk.cloud/external/api/get?token=TU_TOKEN&V4"
# Estado desde Laravel
curl -s http://localhost:8000/api/v1/blynk/status
# Test completo (V0/V1/V3/V2/evento)
php artisan blynk:test
```

`isHardwareConnected` será `false` en esta red (TLS bloqueado) aunque
todo lo demás esté en `OK`: es esperado, no bloquea video (directo LAN)
ni switch (HTTP).
