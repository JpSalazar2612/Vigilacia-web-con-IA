<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vigilancia IA - Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    @livewireStyles
</head>
<body class="bg-gray-100 p-6">
    <h1 class="text-2xl font-bold mb-1">Vigilancia perimetral IA</h1>
    <p class="text-gray-600 mb-4">Fence-Line Intrusion: Approaching → Climbing → Breach</p>
    @livewire('alert-feed')
    @livewireScripts
</body>
</html>
