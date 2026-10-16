<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BlynkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Control Armar/Desarmar vía servidor (funciona aunque la app Blynk
 * marque "dispositivo sin conexión": ese badge exige socket hardware
 * TLS :443, bloqueado en esta red; el pin V4 se lee/escribe por HTTP API).
 */
class BlynkController extends Controller
{
    public function __construct(protected BlynkService $blynk) {}

    public function status(): JsonResponse
    {
        return response()->json([
            'enabled' => $this->blynk->enabled(),
            'armed' => $this->blynk->getArmed(),
        ]);
    }

    public function arm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'armed' => ['required', 'boolean'],
        ]);

        $ok = $this->blynk->setArmed((bool) $validated['armed']);

        return response()->json([
            'armed' => (bool) $validated['armed'],
            'accepted' => $ok,
        ], $ok ? 200 : 502);
    }
}
