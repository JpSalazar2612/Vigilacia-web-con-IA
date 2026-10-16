{{-- Alta manual + misma info de antes + salto a monitoreo en vivo --}}
<div class="space-y-4">
    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
        <h4 class="font-semibold text-sm mb-1">Añadir cámara manualmente (ESP32-CAM)</h4>
        <p class="text-xs text-gray-500 mb-3">
            Ej. Nombre: <b>ESP32-CAM Maqueta</b> · Zona: <b>Frente casa</b> ·
            Stream: <b>http://192.168.1.50/stream</b> · Snapshot: <b>http://192.168.1.50/capture</b>
        </p>
        @if(session('cam-ok'))
            <p class="text-xs text-green-700 bg-green-100 rounded px-2 py-1 mb-2">{{ session('cam-ok') }}</p>
        @endif
        <form wire:submit="create" class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm">
            <input wire:model="name" placeholder="Nombre (único)" class="border rounded px-2 py-1" required>
            <input wire:model="zone" placeholder="Zona (Frente casa)" class="border rounded px-2 py-1" required>
            <input wire:model="location" placeholder="Ubicación (Maqueta 35x30)" class="border rounded px-2 py-1">
            <select wire:model="status" class="border rounded px-2 py-1">
                <option value="online">online</option>
                <option value="offline">offline</option>
                <option value="maintenance">maintenance</option>
            </select>
            <input wire:model="stream_url" placeholder="http://IP-ESP32:81/stream" class="border rounded px-2 py-1 md:col-span-2" required>
            <input wire:model="snapshot_url" placeholder="http://IP-ESP32/capture (opcional)" class="border rounded px-2 py-1 md:col-span-2">
            <div class="md:col-span-2">
                <button class="rounded bg-black px-4 py-1.5 text-white text-sm">Añadir cámara</button>
                @error('name') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                @error('stream_url') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
            </div>
        </form>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" wire:poll.10s>
        @forelse($cameras as $camera)
            <div class="rounded-lg border p-4 {{ $camera->status === 'online' ? 'border-green-500' : 'border-gray-300 opacity-60' }}">
                <div class="flex items-center justify-between">
                    <p class="font-bold">{{ $camera->name }}</p>
                    <span class="text-xs px-2 py-1 rounded {{ $camera->status === 'online' ? 'bg-green-100 text-green-800' : 'bg-gray-200 text-gray-600' }}">
                        {{ $camera->status }}
                    </span>
                </div>
                <p class="text-sm text-gray-500">{{ $camera->zone }} · {{ $camera->location }}</p>
                <p class="text-sm mt-2">
                    {{ $camera->detections_count }} detecciones · {{ $camera->alerts_count }} alertas
                </p>
                <p class="text-xs text-gray-400">Vista: {{ $camera->last_seen_at?->diffForHumans() ?? '—' }}</p>
                <p class="text-xs text-gray-400 truncate" title="{{ $camera->stream_url }}">{{ $camera->stream_url }}</p>
                <div class="flex gap-2 mt-3">
                    <a href="{{ route('monitoreo', ['camera' => $camera->id]) }}"
                       class="rounded bg-green-600 px-3 py-1 text-white text-sm">Ver en vivo →</a>
                    <button wire:click="edit({{ $camera->id }})"
                            class="rounded bg-gray-200 px-3 py-1 text-sm">Editar IP</button>
                    <button wire:click="delete({{ $camera->id }})"
                            wire:confirm="¿Borrar {{ $camera->name }}?"
                            class="rounded bg-red-100 px-3 py-1 text-red-700 text-sm">Borrar</button>
                </div>
                @if($editId === $camera->id)
                    <div class="mt-2 flex flex-col gap-2 text-sm">
                        <input wire:model="edit_stream_url" placeholder="http://IP/stream" class="border rounded px-2 py-1">
                        <div class="flex gap-2">
                            <select wire:model="edit_status" class="border rounded px-2 py-1">
                                <option value="online">online</option>
                                <option value="offline">offline</option>
                                <option value="maintenance">maintenance</option>
                            </select>
                            <button wire:click="update" class="rounded bg-black px-3 py-1 text-white text-sm">Guardar</button>
                            <button wire:click="cancel" class="rounded bg-gray-100 px-3 py-1 text-sm">Cancelar</button>
                        </div>
                        @error('edit_stream_url') <span class="text-red-600 text-xs">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>
        @empty
            <p class="text-gray-500">Sin cámaras registradas. Añade tu ESP32-CAM arriba.</p>
        @endforelse
    </div>
</div>
