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
        </div>
    @empty
        <p class="text-gray-500">Sin cámaras registradas.</p>
    @endforelse
</div>
