<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Vigilancia perimetral IA') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-3">Cámaras</h3>
                    @livewire('camera-grid')
                </div>
            </div>
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-semibold mb-3">Alertas en vivo</h3>
                    <p class="text-sm text-gray-500 mb-3">Fence-Line Intrusion: Approaching → Climbing → Breach</p>
                    @livewire('alert-feed')
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
