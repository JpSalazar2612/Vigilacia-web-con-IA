<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Monitoreo en vivo') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <p class="text-sm text-gray-500 mb-3">Cámara de tu Mac en tiempo real. Acepta el permiso de cámara del navegador.</p>
                    <div class="relative bg-black rounded overflow-hidden">
                        <video id="cam" class="w-full" autoplay playsinline muted></video>
                        <canvas id="overlay" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>
                        <span id="rec-dot" class="hidden absolute top-3 right-3 text-red-500 font-bold">● REC</span>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button id="btn-start" class="px-4 py-2 bg-black text-white rounded text-sm">Iniciar cámara</button>
                        <button id="btn-stop" class="px-4 py-2 bg-gray-200 rounded text-sm">Detener</button>
                        <button id="btn-rec" class="px-4 py-2 bg-red-600 text-white rounded text-sm" disabled>Grabar</button>
                        <a id="btn-dl" class="hidden px-4 py-2 bg-green-600 text-white rounded text-sm" download="vigilancia.webm">Descargar grabación</a>
                        <button id="btn-beep" class="px-4 py-2 bg-yellow-400 rounded text-sm">Probar sonido</button>
                    </div>
                    <p id="cam-status" class="text-sm text-gray-500 mt-3">Cámara detenida.</p>
                </div>
            </div>
        </div>
    </div>

<script>
const video = document.getElementById('cam');
const canvas = document.getElementById('overlay');
const ctx = canvas.getContext('2d');
const statusEl = document.getElementById('cam-status');
const recDot = document.getElementById('rec-dot');
const btnRec = document.getElementById('btn-rec');
const btnDl = document.getElementById('btn-dl');
let stream = null, recorder = null, chunks = [];

// Línea del perímetro (fracción de la altura, ajustable en Lote B)
let fenceY = 0.6;

function sizeCanvas() {
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
}

function drawFence() {
    sizeCanvas();
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#22c55e';
    ctx.lineWidth = 3;
    ctx.setLineDash([10, 6]);
    ctx.beginPath();
    ctx.moveTo(0, canvas.height * fenceY);
    ctx.lineTo(canvas.width, canvas.height * fenceY);
    ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = '#22c55e';
    ctx.font = '16px sans-serif';
    ctx.fillText('PERÍMETRO', 10, canvas.height * fenceY - 8);
}

function beep(freq = 880, ms = 400, times = 3) {
    const AC = window.AudioContext || window.webkitAudioContext;
    const ac = new AC();
    for (let i = 0; i < times; i++) {
        const o = ac.createOscillator();
        const g = ac.createGain();
        o.connect(g); g.connect(ac.destination);
        o.frequency.value = freq;
        const t = ac.currentTime + i * 0.5;
        g.gain.setValueAtTime(0.001, t);
        g.gain.exponentialRampToValueAtTime(0.9, t + 0.05);
        g.gain.exponentialRampToValueAtTime(0.001, t + ms / 1000);
        o.start(t); o.stop(t + ms / 1000 + 0.05);
    }
}

document.getElementById('btn-start').onclick = async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720 }, audio: false });
        video.srcObject = stream;
        await video.play();
        drawFence();
        statusEl.textContent = 'Cámara activa (' + video.videoWidth + 'x' + video.videoHeight + ').';
        btnRec.disabled = false;
    } catch (e) {
        statusEl.textContent = 'No se pudo abrir la cámara: ' + e.message;
    }
};

document.getElementById('btn-stop').onclick = () => {
    if (recorder && recorder.state !== 'inactive') recorder.stop();
    if (stream) stream.getTracks().forEach(t => t.stop());
    video.srcObject = null;
    statusEl.textContent = 'Cámara detenida.';
    btnRec.disabled = true;
};

btnRec.onclick = () => {
    if (recorder && recorder.state === 'recording') { recorder.stop(); return; }
    chunks = [];
    recorder = new MediaRecorder(stream, { mimeType: 'video/webm' });
    recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
    recorder.onstop = () => {
        const blob = new Blob(chunks, { type: 'video/webm' });
        btnDl.href = URL.createObjectURL(blob);
        btnDl.classList.remove('hidden');
        recDot.classList.add('hidden');
        btnRec.textContent = 'Grabar';
        statusEl.textContent = 'Grabación lista para descargar (' + (blob.size / 1048576).toFixed(1) + ' MB).';
    };
    recorder.start();
    recDot.classList.remove('hidden');
    btnRec.textContent = 'Detener grabación';
    statusEl.textContent = 'Grabando…';
};

document.getElementById('btn-beep').onclick = () => beep();
video.addEventListener('loadedmetadata', drawFence);
window.addEventListener('resize', () => { if (stream) drawFence(); });
</script>
</x-app-layout>
