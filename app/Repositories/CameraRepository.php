<?php

namespace App\Repositories;

use App\Models\Camera;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class CameraRepository
{
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return Camera::withCount('detections')->orderBy('id', 'desc')->paginate($perPage);
    }

    public function find(int $id): ?Camera
    {
        return Camera::find($id);
    }

    public function create(array $data): Camera
    {
        return Camera::create($data);
    }

    public function update(Camera $camera, array $data): Camera
    {
        $camera->update($data);

        return $camera->refresh();
    }

    public function delete(Camera $camera): bool
    {
        return (bool) $camera->delete();
    }

    /** @return Collection<int, Camera> */
    public function active(): Collection
    {
        return Camera::active()->online()->get();
    }
}
