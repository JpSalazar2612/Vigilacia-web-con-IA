<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    public function __construct(protected AlertService $alerts) {}

    public function index(): AnonymousResourceCollection
    {
        $alerts = Alert::with(['camera', 'detection'])
            ->orderBy('id', 'desc')
            ->paginate(20);

        return AlertResource::collection($alerts);
    }

    public function show(int $id): AlertResource
    {
        $alert = Alert::with(['camera', 'detection'])->findOrFail($id);

        return new AlertResource($alert);
    }

    public function acknowledge(int $id): AlertResource
    {
        $alert = Alert::findOrFail($id);

        return new AlertResource($this->alerts->acknowledge($alert));
    }

    public function destroy(int $id): JsonResponse
    {
        Alert::findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
