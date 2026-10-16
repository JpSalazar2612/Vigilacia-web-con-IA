<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Puente Laravel -> Blynk IoT por HTTP API.
 *
 * Arquitectura maqueta (1x ESP32-CAM + ESP32-CAM-MB):
 * - El ESP32-CAM SOLO sirve streaming MJPEG (no corre librería Blynk:
 *   se queda sin RAM con cámara + WiFi + Blynk a la vez).
 * - Laravel es quien actualiza los pines virtuales y dispara el Evento:
 *   V0 = texto de alerta, datastream STRING (widget Label/Value, por valor)
 *   V1 = presencia 0/1, datastream INTEGER (widget LED, por valor)
 *   V2 = foto, datastream INTEGER + propiedad `urls` (widget Image Gallery)
 *   V3 = cámara online 0/1, datastream INTEGER (widget LED, por valor)
 *   Evento `intruso_casa` = push "¡Alguien entró a tu casa!" + timeline.
 *
 * Docs API: https://blynk.cloud/external/api/update?token=XXX&V1=1
 *            https://blynk.cloud/external/api/logEvent?token=XXX&code=intruso_casa
 */
class BlynkService
{
    public function enabled(): bool
    {
        return (bool) config('services.blynk.enabled')
            && filled(config('services.blynk.auth_token'));
    }

    protected function base(): string
    {
        $server = trim((string) config('services.blynk.server', 'blynk.cloud'));
        // Si ya trae esquema (http://... o https://...) se respeta tal cual.
        if (str_starts_with($server, 'http://') || str_starts_with($server, 'https://')) {
            return rtrim($server, '/').'/external/api';
        }
        $scheme = strtolower((string) config('services.blynk.scheme', 'http'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'http';
        }

        return $scheme.'://'.rtrim($server, '/').'/external/api';
    }

    protected function token(): string
    {
        return (string) config('services.blynk.auth_token');
    }

    /**
     * Actualiza un pin virtual. $pin = "V0".."V31".
     * Timeout corto (3s): corre dentro del request de detección y con
     * internet lento no debe tumbar el servidor.
     */
    public function updatePin(string $pin, string|int|float $value): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(3)->get($this->base().'/update', [
                'token' => $this->token(),
                $pin => $value,
            ]);

            if (! $res->successful()) {
                Log::warning('Blynk updatePin falló', ['pin' => $pin, 'status' => $res->status(), 'body' => $res->body()]);
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Blynk updatePin excepción: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Actualiza una PROPIEDAD del widget ligado al pin (no el valor).
     * Es lo que usa el widget Image Gallery: la foto se cambia con la
     * propiedad `urls` (lista completa) o `url` (índice), NO con update.
     * Endpoint: /external/api/update/property?token=..&pin=V2&urls=<urlencoded>
     */
    public function updateProperty(string $pin, string $property, string $value): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(3)->get($this->base().'/update/property', [
                'token' => $this->token(),
                'pin' => $pin,
                $property => $value,
            ]);

            if (! $res->successful()) {
                Log::warning('Blynk updateProperty falló', ['pin' => $pin, 'property' => $property, 'status' => $res->status(), 'body' => $res->body()]);
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Blynk updateProperty excepción: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Cambia la foto del widget Image Gallery (nota: el pin V2 debe ser
     * datastream tipo INTEGER con 1 URL inicial cualquiera en el widget;
     * con una sola URL el valor del pin se ignora y se muestra esa lista).
     */
    public function pushPhoto(string $imageUrl): bool
    {
        return $this->updateProperty((string) config('services.blynk.pin_foto', 'V2'), 'urls', $imageUrl);
    }

    /**
     * Actualiza varios pines en UNA sola llamada (endpoint batch).
     * pushIntrusion lo usa para V0/V1/V3: 1 llamada en vez de 3.
     */
    public function batchUpdate(array $pins): bool
    {
        if (! $this->enabled() || $pins === []) {
            return false;
        }

        try {
            $res = Http::timeout(3)->get($this->base().'/batch/update', array_merge(
                ['token' => $this->token()],
                $pins,
            ));

            if (! $res->successful()) {
                Log::warning('Blynk batchUpdate falló', ['status' => $res->status(), 'body' => $res->body()]);
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Blynk batchUpdate excepción: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Dispara un Evento (Events & Notifications) -> push + timeline + email.
     * El $code debe existir en la consola Blynk (ej: intruso_casa).
     */
    public function logEvent(string $code, string $description = ''): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $res = Http::timeout(3)->get($this->base().'/logEvent', array_filter([
                'token' => $this->token(),
                'code' => $code,
                'description' => mb_substr($description, 0, 300),
            ]));

            if (! $res->successful()) {
                Log::warning('Blynk logEvent falló', ['code' => $code, 'status' => $res->status(), 'body' => $res->body()]);
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('Blynk logEvent excepción: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Atajo para intrusión: actualiza V0/V1/V2 y dispara el push.
     * $imageUrl debe ser pública (https://tu-app/storage/evidencias/xxx.jpg)
     * para que el widget Galería la muestre fuera de tu WiFi.
     */
    public function pushIntrusion(string $message, ?string $imageUrl = null, bool $present = true): void
    {
        if (! $this->enabled()) {
            return;
        }

        // 1 sola llamada para texto + presencia + online (antes eran 3)
        $this->batchUpdate([
            (string) config('services.blynk.pin_texto', 'V0') => $message,
            (string) config('services.blynk.pin_presencia', 'V1') => $present ? 1 : 0,
            (string) config('services.blynk.pin_online', 'V3') => 1,
        ]);

        if (filled($imageUrl)) {
            // Galería: se actualiza por PROPIEDAD `urls`, no por valor del pin.
            $this->pushPhoto($imageUrl);
        }

        $this->logEvent((string) config('services.blynk.event_code', 'intruso_casa'), $message);
    }

    /** Marca cámara online/offline en V3 (widget LED). Devuelve si Blynk lo aceptó. */
    public function pushOnline(bool $online): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        return $this->updatePin((string) config('services.blynk.pin_online', 'V3'), $online ? 1 : 0);
    }

    /** Limpia presencia cuando la alerta se atiende (V1=0). */
    public function clearPresence(): void
    {
        $this->updatePin((string) config('services.blynk.pin_presencia', 'V1'), 0);
    }
}
