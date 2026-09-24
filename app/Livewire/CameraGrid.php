<?php

namespace App\Livewire;

use App\Models\Camera;
use Livewire\Component;

class CameraGrid extends Component
{
    public function render()
    {
        $cameras = Camera::withCount(['detections', 'alerts'])
            ->orderBy('name')
            ->get();

        return view('livewire.camera-grid', ['cameras' => $cameras]);
    }
}
