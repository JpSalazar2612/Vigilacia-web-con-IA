<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDetectionRequest;
use App\Http\Resources\AlertResource;
use App\Http\Resources\DetectionResource;
use App\Repositories\DetectionRepository;
use App\Services\DetectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DetectionController extends Controller
{
    public function __construct(
        protected DetectionRepository $detections,
        protected DetectionService $service,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return DetectionResource::collection($this->detections->paginate());
    }

    public function store(StoreDetectionRequest $request): JsonResponse
    {
        // Delgado: valida arriba, toda la IA vive en DetectionService
        $detection = $this->service->ingest($request->validated());
        $detection->load('camera');
        $alert = $detection->alert()->latest()->first();

        return response()->json([
            'detection' => [
                'id' => $detection->id,
                'track_id' => $detection->track_id,
                'event_type' => $detection->event_type,
                'confidence' => $detection->confidence,
                'camera' => [
                    'id' => $detection->camera?->id,
                    'zone' => $detection->camera?->zone,
                ],
            ],
            'alert' => $alert ? new AlertResource($alert->load(['camera', 'detection'])) : null,
        ], 201);
    }
}
