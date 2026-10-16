<?php

namespace App\Console\Commands;

use App\Services\BlynkService;
use Illuminate\Console\Command;

/**
 * Verifica la conexión Laravel -> Blynk IoT sin necesidad de una intrusión real.
 * Uso: php artisan blynk:test
 * Lee BLYNK_SERVER / BLYNK_AUTH_TOKEN / pines del .env.
 */
class BlynkTest extends Command
{
    protected $signature = 'blynk:test';

    protected $description = 'Prueba la conexión con Blynk (pines V0/V1/V3 + evento intruso_casa)';

    public function handle(BlynkService $blynk): int
    {
        $this->info('Servidor: '.config('services.blynk.server'));
        $this->info('Evento: '.config('services.blynk.event_code'));

        if (! $blynk->enabled()) {
            $this->error('Blynk deshabilitado: pon BLYNK_ENABLED=true y BLYNK_AUTH_TOKEN en tu .env');

            return self::FAILURE;
        }

        $ok = true;
        $ok = $this->check('V0 texto', fn () => $blynk->updatePin((string) config('services.blynk.pin_texto', 'V0'), 'Prueba desde Laravel '.now()->format('H:i:s'))) && $ok;
        $ok = $this->check('V1 presencia=0', fn () => $blynk->updatePin((string) config('services.blynk.pin_presencia', 'V1'), 0)) && $ok;
        $ok = $this->check('V3 online=1', fn () => $blynk->pushOnline(true)) && $ok;
        // Galería: propiedad `urls` (requiere V2 INTEGER + 1 URL inicial en el widget)
        $ok = $this->check('V2 galería (propiedad urls)', fn () => $blynk->pushPhoto('https://via.placeholder.com/640x480.jpg?text=prueba')) && $ok;
        $ok = $this->check('Evento intruso_casa', fn () => $blynk->logEvent((string) config('services.blynk.event_code', 'intruso_casa'), 'Prueba de conexión desde Laravel')) && $ok;

        $ok ? $this->info('Todo OK: revisa tu app/web Blynk.') : $this->error('Algún paso falló: revisa storage/logs/laravel.log y el token.');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    protected function check(string $label, callable $fn): bool
    {
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $result = false;
            $this->line('  '.$e->getMessage());
        }
        $this->line(($result ? '<info>[OK]</info> ' : '<error>[FALLO]</error> ').$label);

        return (bool) $result;
    }
}
