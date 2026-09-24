<?php

namespace App\Repositories;

use App\Models\Detection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class DetectionRepository
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Detection::with('camera')->orderBy('detected_at', 'desc')->paginate($perPage);
    }

    public function create(array $data): Detection
    {
        return Detection::create($data);
    }

    public function latestByTrack(string $trackId, int $limit = 10)
    {
        return Detection::forTrack($trackId)->orderBy('detected_at', 'desc')->limit($limit)->get();
    }
}
