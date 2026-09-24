<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vigilancia IA - Dashboard</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-gray-100 p-6">
    <h1 class="text-2xl font-bold mb-1">Vigilancia perimetral IA</h1>
    <p class="text-gray-600 mb-4">Fence-Line Intrusion: Approaching → Climbing → Breach</p>
    <h2 class="text-lg font-semibold mt-2 mb-2">Cámaras</h2>
    @livewire('camera-grid')
    <h2 class="text-lg font-semibold mt-6 mb-2">Alertas</h2>
    @livewire('alert-feed')
    @livewireScripts
</body>
</html>
