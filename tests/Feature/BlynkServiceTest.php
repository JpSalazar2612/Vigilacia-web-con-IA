<?php

namespace Tests\Feature;

use App\Services\BlynkService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BlynkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.blynk', [
            'server' => 'blynk.cloud',
            'auth_token' => 'TOKEN-PRUEBA',
            'pin_texto' => 'V0',
            'pin_presencia' => 'V1',
            'pin_foto' => 'V2',
            'pin_online' => 'V3',
            'event_code' => 'intruso_casa',
            'enabled' => true,
        ]);
    }

    public function test_push_intrusion_usa_batch_y_no_mas_de_3_llamadas(): void
    {
        Http::fake();
        app(BlynkService::class)->pushIntrusion('¡Alguien entró!', 'https://x/foto.jpg');

        // 1) batch V0+V1+V3 en una sola llamada
        Http::assertSent(fn ($req) => str_contains($req->url(), '/batch/update')
            && $req['V0'] === '¡Alguien entró!'
            && $req['V1'] == 1
            && $req['V3'] == 1);

        // 2) foto por propiedad urls (así lo exige Image Gallery)
        Http::assertSent(fn ($req) => str_contains($req->url(), '/update/property')
            && ($req['urls'] ?? null) === 'https://x/foto.jpg');

        // 3) evento push
        Http::assertSent(fn ($req) => str_contains($req->url(), '/logEvent')
            && $req['code'] === 'intruso_casa');

        Http::assertSentCount(3);
    }

    public function test_apagado_no_llama_a_blynk(): void
    {
        config()->set('services.blynk.enabled', false);
        Http::fake();
        app(BlynkService::class)->pushIntrusion('x');
        Http::assertNothingSent();
    }
}
