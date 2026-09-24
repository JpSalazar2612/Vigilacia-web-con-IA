<?php

namespace App\Livewire;

use App\Models\Alert;
use Livewire\Component;

class AlertFeed extends Component
{
    public function acknowledge(int $id): void
    {
        $alert = Alert::findOrFail($id);
        app(\App\Services\AlertService::class)->acknowledge($alert);
    }

    public function render()
    {
        $alerts = Alert::with(['camera', 'detection'])
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get();

        return view('livewire.alert-feed', ['alerts' => $alerts]);
    }
}
