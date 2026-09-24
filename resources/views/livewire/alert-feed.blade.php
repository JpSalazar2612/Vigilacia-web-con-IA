<div class="space-y-2" wire:poll.5s>
    @forelse($alerts as $alert)
        <div class="flex items-center justify-between rounded-lg border p-3 {{ $alert->level === 'critical' ? 'border-red-500 bg-red-50' : 'border-gray-200' }}">
            <div>
                <p class="font-semibold">{{ $alert->message }}</p>
                <p class="text-sm text-gray-500">
                    {{ $alert->camera?->zone }} · Track {{ $alert->detection?->track_id }} · {{ $alert->status }}
                </p>
            </div>
            @if($alert->status === 'pending')
                <button wire:click="acknowledge({{ $alert->id }})" class="rounded bg-black px-3 py-1 text-white text-sm">
                    Atender
                </button>
            @endif
        </div>
    @empty
        <p class="text-gray-500">Sin alertas. Sistema perimetral activo.</p>
    @endforelse
</div>
