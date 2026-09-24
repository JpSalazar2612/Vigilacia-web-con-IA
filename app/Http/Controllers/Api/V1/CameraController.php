<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCameraRequest;
use App\Http\Resources\CameraResource;
use App\Repositories\CameraRepository;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CameraController extends Controller
{
    public function __construct(protected CameraRepository $cameras) {}

    public function index(): AnonymousResourceCollection
    {
        return CameraResource::collection($this->cameras->paginate());
    }

    public function store(StoreCameraRequest $request): CameraResource
    {
        $camera = $this->cameras->create($request->validated());

        return new CameraResource($camera);
    }

    public function show(int $id): CameraResource
    {
        $camera = $this->cameras->find($id);
        abort_if(! $camera, 404);

        return new CameraResource($camera);
    }

    public function destroy(int $id): \Illuminate\Http\JsonResponse
    {
        $camera = $this->cameras->find($id);
        abort_if(! $camera, 404);
        $this->cameras->delete($camera);

        return response()->json(null, 204);
    }
}
