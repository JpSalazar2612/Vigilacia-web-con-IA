<?php

namespace App\Repositories;

use App\Models\Alert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class AlertRepository
{
    public function paginate(int $perPage = 20): LengthAwarePaginator
    {
        return Alert::with(['camera', 'detection'])
            ->orderBy('id', 'desc')
            ->paginate($perPage);
    }

    public function find(int $id): ?Alert
    {
        return Alert::with(['camera', 'detection'])->find($id);
    }

    public function create(array $data): Alert
    {
        return Alert::create($data);
    }

    public function update(Alert $alert, array $data): Alert
    {
        $alert->update($data);

        return $alert->refresh();
    }

    /** @return Collection<int, Alert> */
    public function pending(): Collection
    {
        return Alert::pending()->with(['camera', 'detection'])->orderBy('id', 'desc')->get();
    }
}
