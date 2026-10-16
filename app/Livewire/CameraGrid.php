<?php

namespace App\Livewire;

use App\Models\Camera;
use Livewire\Component;

/**
 * Gestión manual de cámaras (maqueta 35x30 con 1x ESP32-CAM).
 * Ya no se depende de seeders: el usuario añade/edita/borra a mano
 * y cada tarjeta enlaza al monitoreo en vivo de esa cámara.
 */
class CameraGrid extends Component
{
    public string $name = '';

    public string $location = 'Maqueta casa 35x30';

    public string $zone = 'Frente casa';

    public string $stream_url = '';

    public string $snapshot_url = '';

    public string $status = 'online';

    public ?int $editId = null;

    public string $edit_stream_url = '';

    public string $edit_status = 'online';

    public function create(): void
    {
        $this->validate([
            'name' => 'required|string|max:255|unique:cameras,name',
            'location' => 'nullable|string|max:255',
            'zone' => 'required|string|max:100',
            'stream_url' => 'required|string|max:500',
            'snapshot_url' => 'nullable|string|max:500',
            'status' => 'in:online,offline,maintenance',
        ]);

        Camera::create([
            'name' => $this->name,
            'location' => $this->location ?: null,
            'zone' => $this->zone,
            'stream_url' => $this->stream_url,
            // snapshot_url vacío = se deriva del stream en monitoreo
            'snapshot_url' => $this->snapshot_url ?: null,
            'status' => $this->status,
            'is_active' => true,
            'ai_enabled' => true,
        ]);

        $this->reset(['name', 'stream_url', 'snapshot_url']);
        session()->flash('cam-ok', 'Cámara añadida. Haz clic en "Ver en vivo" para ir al monitoreo.');
    }

    public function delete(int $id): void
    {
        Camera::findOrFail($id)->delete();
    }

    public function edit(int $id): void
    {
        $camera = Camera::findOrFail($id);
        $this->editId = $camera->id;
        $this->edit_stream_url = $camera->stream_url;
        $this->edit_status = $camera->status;
    }

    public function cancel(): void
    {
        $this->reset(['editId', 'edit_stream_url']);
    }

    public function update(): void
    {
        $this->validate([
            'edit_stream_url' => 'required|string|max:500',
            'edit_status' => 'in:online,offline,maintenance',
        ]);

        $camera = Camera::findOrFail($this->editId);
        $camera->update([
            'stream_url' => $this->edit_stream_url,
            'status' => $this->edit_status,
        ]);
        $this->reset(['editId', 'edit_stream_url']);
        session()->flash('cam-ok', 'Cámara actualizada.');
    }

    public function render()
    {
        $cameras = Camera::withCount(['detections', 'alerts'])
            ->orderBy('name')
            ->get();

        return view('livewire.camera-grid', ['cameras' => $cameras]);
    }
}
