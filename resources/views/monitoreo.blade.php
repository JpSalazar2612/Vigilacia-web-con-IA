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
                        <span id="ia-badge" class="hidden absolute top-3 left-3 bg-green-600 text-white text-xs px-2 py-1 rounded">IA ACTIVA</span>
                    </div>
                    <div class="flex flex-wrap gap-2 mt-4">
                        <button id="btn-start" class="px-4 py-2 bg-black text-white rounded text-sm">Iniciar cámara</button>
                        <button id="btn-stop" class="px-4 py-2 bg-gray-200 rounded text-sm">Detener</button>
                        <button id="btn-rec" class="px-4 py-2 bg-red-600 text-white rounded text-sm" disabled>Grabar</button>
                        <a id="btn-dl" class="hidden px-4 py-2 bg-green-600 text-white rounded text-sm" download="vigilancia.webm">Descargar grabación</a>
                        <button id="btn-beep" class="px-4 py-2 bg-yellow-400 rounded text-sm">Probar sonido</button>
                    </div>
                    <div class="mt-3">
                        <label class="text-sm text-gray-600">Altura del perímetro: <span id="fence-val">60%</span></label>
                        <input id="fence-range" type="range" min="10" max="90" value="60" class="w-full">
                    </div>
                    <p id="cam-status" class="text-sm text-gray-500 mt-2">Cámara detenida.</p>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="font-semibold mb-3">Detección IA + API</h3>
                    <div class="grid md:grid-cols-3 gap-3 text-sm">
                        <div>
                            <label class="text-gray-600">Cámara registrada</label>
                            <select id="sel-cam" class="w-full border rounded px-2 py-1"></select>
                        </div>
                        <div>
                            <label class="text-gray-600">Token API (POST /api/v1/login)</label>
                            <input id="inp-token" type="password" placeholder="1|..." class="w-full border rounded px-2 py-1">
                        </div>
                        <div class="flex items-end gap-2">
                            <button id="btn-save-token" class="px-3 py-1 bg-gray-800 text-white rounded text-sm">Guardar</button>
                            <button id="btn-ia" class="px-3 py-1 bg-green-600 text-white rounded text-sm" disabled>Activar IA</button>
                        </div>
                    </div>
                    <p id="ia-status" class="text-sm text-gray-500 mt-2">IA detenida. Modelos en tu navegador: coco-ssd (personas) + blazeface (rostros).</p>
                    <div id="ia-log" class="mt-3 h-32 overflow-y-auto bg-gray-50 border rounded p-2 text-xs space-y-1"></div>
                </div>
            </div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.17.0/dist/tf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3/dist/coco-ssd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@tensorflow-models/blazeface@0.0.7/dist/blazeface.min.js"></script>
<script>
const video = document.getElementById('cam');
const canvas = document.getElementById('overlay');
const ctx = canvas.getContext('2d');
const statusEl = document.getElementById('cam-status');
const recDot = document.getElementById('rec-dot');
const btnRec = document.getElementById('btn-rec');
const btnDl = document.getElementById('btn-dl');
const iaStatus = document.getElementById('ia-status');
const iaLog = document.getElementById('ia-log');
const iaBadge = document.getElementById('ia-badge');
let stream = null, recorder = null, chunks = [];
let fenceY = 0.6;

// --- IA state ---
let model = null, aiActive = false, aiBusy = false;
let sessionTrack = null, lastSeenAt = 0;
let lastSent = { approaching: 0, climbing: 0, breach_confirmed: 0 };
const COOLDOWN = { approaching: 30000, climbing: 20000, breach_confirmed: 30000 };

function sizeCanvas() {
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
}

function drawScene(persons) {
    sizeCanvas();
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    const sx = canvas.width / (video.videoWidth || 640);
    const sy = canvas.height / (video.videoHeight || 480);
    // perímetro
    ctx.strokeStyle = '#22c55e'; ctx.lineWidth = 3; ctx.setLineDash([10, 6]);
    ctx.beginPath(); ctx.moveTo(0, canvas.height * fenceY); ctx.lineTo(canvas.width, canvas.height * fenceY); ctx.stroke();
    ctx.setLineDash([]); ctx.fillStyle = '#22c55e'; ctx.font = '16px sans-serif';
    ctx.fillText('PERÍMETRO', 10, canvas.height * fenceY - 8);
    // personas
    (persons || []).forEach(p => {
        const [x, y, w, h] = p.bbox;
        const cy = (y + h / 2) / (video.videoHeight || 480);
        ctx.strokeStyle = cy > fenceY ? '#ef4444' : (Math.abs(cy - fenceY) < 0.08 ? '#eab308' : '#22c55e');
        ctx.lineWidth = 3;
        ctx.strokeRect(x * sx, y * sy, w * sx, h * sy);
        ctx.fillStyle = ctx.strokeStyle;
        ctx.beginPath(); ctx.arc((x + w / 2) * sx, (y + h / 2) * sy, 5, 0, 7); ctx.fill();
    });
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

function log(msg) {
    const d = document.createElement('div');
    d.textContent = new Date().toLocaleTimeString() + ' - ' + msg;
    iaLog.prepend(d);
}

function apiToken() { return localStorage.getItem('vig_token') || ''; }

async function uploadBlob(blob, name) {
    const fd = new FormData();
    fd.append('image', blob, name);
    const res = await fetch('/api/v1/evidencias', { method: 'POST', headers: { 'Authorization': 'Bearer ' + apiToken(), 'Accept': 'application/json' }, body: fd });
    if (!res.ok) throw new Error('evidencia ' + res.status);
    return (await res.json()).path;
}

async function uploadSnapshot() {
    const c = document.createElement('canvas');
    c.width = video.videoWidth; c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
    return uploadBlob(blob, 'frame.jpg');
}

let faceModel = null;

// Recorta el rostro principal y lo sube. Devuelve {path, confidence} o null.
async function captureFace() {
    try {
        faceModel = faceModel || await blazeface.load();
        const faces = await faceModel.estimateFaces(video, false);
        if (!faces.length) return null;
        const f = faces.sort((a, b) => b.probability - a.probability)[0];
        const [x1, y1] = f.topLeft, [x2, y2] = f.bottomRight;
        const pad = 20;
        const sx = Math.max(0, x1 - pad), sy = Math.max(0, y1 - pad);
        const sw = Math.min(video.videoWidth - sx, (x2 - x1) + pad * 2);
        const sh = Math.min(video.videoHeight - sy, (y2 - y1) + pad * 2);
        const c = document.createElement('canvas');
        c.width = sw; c.height = sh;
        c.getContext('2d').drawImage(video, sx, sy, sw, sh, 0, 0, sw, sh);
        const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.9));
        const path = await uploadBlob(blob, 'face.jpg');
        return { path: path, confidence: f.probability };
    } catch (e) {
        log('Rostro no capturado: ' + e.message);
        return null;
    }
}

async function sendEvent(type, person) {
    const now = Date.now();
    if (now - (lastSent[type] || 0) < COOLDOWN[type]) return;
    lastSent[type] = now;
    const camId = document.getElementById('sel-cam').value;
    if (!camId) { log('Sin cámara seleccionada, evento no enviado.'); return; }
    try {
        const snap = await uploadSnapshot();
        const H = video.videoHeight || 480, W = video.videoWidth || 640;
        const [x, y, w, h] = person ? person.bbox : [0, 0, W, H];
        const body = {
            camera_id: parseInt(camId),
            track_id: sessionTrack,
            event_type: type,
            confidence: person ? person.score : 0.7,
            bbox: { x: x / W, y: y / H, w: w / W, h: h / H },
            snapshot_path: snap,
            meta: { source: 'mac-browser', zone: 'live' },
        };
        if (type !== 'approaching') {
            const face = await captureFace();
            if (face) {
                body.face_image_path = face.path;
                body.face_confidence = Math.round(face.confidence * 100) / 100;
                body.face_label = 'Desconocido-' + String(sessionTrack).replace('MAC-', '');
            }
        }
        const res = await fetch('/api/v1/detections', { method: 'POST', headers: { 'Authorization': 'Bearer ' + apiToken(), 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(body) });
        log(type + ' → API ' + res.status);
    } catch (e) { log('Error enviando ' + type + ': ' + e.message); }
}

async function iaLoop() {
    if (!aiActive || aiBusy || !stream || !model) return;
    aiBusy = true;
    try {
        const preds = await model.detect(video);
        const persons = preds.filter(p => p.class === 'person' && p.score > 0.6);
        drawScene(persons);
        const now = Date.now();
        if (persons.length) {
            if (!sessionTrack || now - lastSeenAt > 5000) sessionTrack = 'MAC-' + String(Math.floor(1000 + Math.random() * 9000));
            lastSeenAt = now;
            const H = video.videoHeight || 480;
            const cy = (persons[0].bbox[1] + persons[0].bbox[3] / 2) / H;
            if (cy > fenceY) {
                await sendEvent('breach_confirmed', persons[0]);
                beep(440, 500, 3);
            } else if (Math.abs(cy - fenceY) < 0.08) {
                await sendEvent('climbing', persons[0]);
                beep(880, 300, 2);
            } else {
                await sendEvent('approaching', persons[0]);
            }
        }
    } catch (e) { iaStatus.textContent = 'Error IA: ' + e.message; }
    aiBusy = false;
}

document.getElementById('btn-ia').onclick = async (e) => {
    if (aiActive) { aiActive = false; iaBadge.classList.add('hidden'); e.target.textContent = 'Activar IA'; iaStatus.textContent = 'IA detenida.'; return; }
    if (!apiToken()) { iaStatus.textContent = 'Guarda primero tu token API.'; return; }
    e.target.disabled = true;
    try {
        iaStatus.textContent = 'Cargando modelo coco-ssd…';
        model = model || await cocoSsd.load();
        aiActive = true;
        iaBadge.classList.remove('hidden');
        e.target.textContent = 'Detener IA';
        iaStatus.textContent = 'IA activa: detectando personas cada 0.8s.';
        log('IA activada.');
    } catch (err) { iaStatus.textContent = 'No se pudo cargar el modelo: ' + err.message; }
    e.target.disabled = false;
};
setInterval(iaLoop, 800);

document.getElementById('btn-save-token').onclick = () => {
    const v = document.getElementById('inp-token').value.trim();
    if (v) { localStorage.setItem('vig_token', v); log('Token guardado.'); }
};
document.getElementById('inp-token').value = apiToken();

async function loadCameras() {
    try {
        const res = await fetch('/api/v1/cameras', { headers: { 'Accept': 'application/json' } });
        const json = await res.json();
        const sel = document.getElementById('sel-cam');
        (json.data || []).forEach(c => {
            const o = document.createElement('option');
            o.value = c.id; o.textContent = c.name + ' (' + c.zone + ')';
            sel.appendChild(o);
        });
    } catch (e) { log('No se pudieron cargar cámaras.'); }
}
loadCameras();

document.getElementById('fence-range').oninput = (e) => {
    fenceY = e.target.value / 100;
    document.getElementById('fence-val').textContent = e.target.value + '%';
    if (stream) drawScene([]);
};

document.getElementById('btn-start').onclick = async () => {
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { width: 1280, height: 720 }, audio: false });
        video.srcObject = stream;
        await video.play();
        drawScene([]);
        statusEl.textContent = 'Cámara activa (' + video.videoWidth + 'x' + video.videoHeight + ').';
        btnRec.disabled = false;
        document.getElementById('btn-ia').disabled = false;
    } catch (e) {
        statusEl.textContent = 'No se pudo abrir la cámara: ' + e.message;
    }
};

document.getElementById('btn-stop').onclick = () => {
    aiActive = false; iaBadge.classList.add('hidden');
    if (recorder && recorder.state !== 'inactive') recorder.stop();
    if (stream) stream.getTracks().forEach(t => t.stop());
    video.srcObject = null;
    statusEl.textContent = 'Cámara detenida.';
    btnRec.disabled = true;
    document.getElementById('btn-ia').disabled = true;
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
video.addEventListener('loadedmetadata', () => drawScene([]));
window.addEventListener('resize', () => { if (stream) drawScene([]); });
</script>
</x-app-layout>
