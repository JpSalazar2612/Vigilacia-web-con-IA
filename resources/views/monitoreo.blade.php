<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Monitoreo en vivo — ESP32-CAM') }}
        </h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-4">
            {{-- Selector de cámara manual --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <div class="grid md:grid-cols-3 gap-3 text-sm items-end">
                    <div>
                        <label class="text-gray-600">Cámara (añadida en Dashboard)</label>
                        <select id="sel-cam" class="w-full border rounded px-2 py-1"></select>
                    </div>
                    <div>
                        <label class="text-gray-600">Token API (para Atender alertas)</label>
                        <input id="inp-token" type="password" placeholder="1|..." class="w-full border rounded px-2 py-1">
                    </div>
                    <div class="flex gap-2">
                        <button id="btn-save-token" class="px-3 py-1 bg-gray-800 text-white rounded text-sm">Guardar</button>
                        <button id="btn-beep" class="px-3 py-1 bg-yellow-400 rounded text-sm">Probar sonido</button>
                        <button id="btn-reload" class="px-3 py-1 bg-gray-200 rounded text-sm">Recargar stream</button>
                    </div>
                </div>
                <div class="flex items-center gap-2 mt-3 text-sm">
                    <span class="text-gray-600">Alarma:</span>
                    <span id="arma-estado" class="px-2 py-1 rounded bg-gray-100 text-gray-600">leyendo…</span>
                    <button id="btn-armar" class="px-3 py-1 bg-green-700 text-white rounded text-sm">Armar</button>
                    <button id="btn-desarmar" class="px-3 py-1 bg-red-700 text-white rounded text-sm">Desarmar</button>
                    <span class="text-xs text-gray-400">(vía servidor: funciona aunque Blynk diga "offline". YOLO lo toma en ~10s)</span>
                </div>
                <p id="cam-info" class="text-sm text-gray-500 mt-2">Selecciona tu ESP32-CAM…</p>
            </div>

            {{-- Stream ESP32-CAM + perímetro --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <div class="relative bg-black rounded overflow-hidden">
                    <img id="esp-stream" class="w-full" style="min-height:240px" alt="Stream ESP32-CAM">
                    <canvas id="overlay" class="absolute inset-0 w-full h-full pointer-events-none"></canvas>
                    <span id="live-badge" class="absolute top-3 left-3 bg-red-600 text-white text-xs px-2 py-1 rounded">● EN VIVO</span>
                    <span id="ia-badge" class="absolute top-3 right-3 bg-green-600 text-white text-xs px-2 py-1 rounded">YOLO · clase person</span>
                </div>
                <div class="mt-3">
                    <label class="text-sm text-gray-600">Altura del perímetro (barda maqueta): <span id="fence-val">60%</span></label>
                    <input id="fence-range" type="range" min="10" max="90" value="60" class="w-full">
                    <p class="text-xs text-gray-500">La detección la hace <b>Python + YOLO (ultralytics)</b> sobre este mismo stream. Cuando el centro de la persona cruza la línea verde → la API crea alerta y aquí suena el pitido + mensaje emergente.</p>
                </div>
                <p id="stream-status" class="text-sm text-gray-500 mt-2">Stream detenido.</p>
            </div>

            {{-- Galería evidencias (paralela al widget Galería de Blynk) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <h3 class="font-semibold">Galería de imágenes (últimas detecciones)</h3>
                    <span class="text-xs text-gray-500">Las mismas fotos se empujan al widget Galería de Blynk (V2)</span>
                </div>
                <div id="gallery" class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-3"></div>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-4">
                <h3 class="font-semibold text-sm mb-1">Log de monitoreo</h3>
                <div id="ia-log" class="h-28 overflow-y-auto bg-gray-50 border rounded p-2 text-xs space-y-1"></div>
            </div>
        </div>
    </div>

    {{-- Modal emergente intrusión --}}
    <div id="alert-modal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white rounded-xl max-w-md w-full p-6 text-center shadow-2xl border-4 border-red-500">
            <p class="text-4xl">🚨</p>
            <h3 class="text-xl font-bold text-red-600 mt-1">¡Alguien entró a tu casa!</h3>
            <p id="modal-detail" class="text-sm text-gray-600 mt-1"></p>
            <img id="modal-img" class="w-full rounded mt-3 hidden" alt="Evidencia">
            <div class="flex gap-2 justify-center mt-4">
                <button id="btn-ack" class="px-4 py-2 bg-black text-white rounded text-sm">Atender</button>
                <button id="btn-mute" class="px-4 py-2 bg-gray-200 rounded text-sm">Cerrar y silenciar</button>
            </div>
        </div>
    </div>

<script>
const PRESELECT = @json($cameraId ?? null);
const selCam = document.getElementById('sel-cam');
const img = document.getElementById('esp-stream');
const canvas = document.getElementById('overlay');
const ctx = canvas.getContext('2d');
const camInfo = document.getElementById('cam-info');
const streamStatus = document.getElementById('stream-status');
const gallery = document.getElementById('gallery');
const iaLog = document.getElementById('ia-log');
const modal = document.getElementById('alert-modal');
const modalDetail = document.getElementById('modal-detail');
const modalImg = document.getElementById('modal-img');
let fenceY = 0.6, currentCamera = null, lastAlertId = 0, currentAlertId = null, silenced = false;
let streamOk = false, retryTimer = null;

function log(m){ const d=document.createElement('div'); d.textContent=new Date().toLocaleTimeString()+' - '+m; iaLog.prepend(d); }
function apiToken(){ return localStorage.getItem('vig_token')||''; }
document.getElementById('inp-token').value = apiToken();
document.getElementById('btn-save-token').onclick = () => {
    const v = document.getElementById('inp-token').value.trim();
    if (v) { localStorage.setItem('vig_token', v); log('Token guardado.'); }
};

function beep(freq=880, ms=400, times=3){
    try{
        const AC = window.AudioContext || window.webkitAudioContext;
        // Reutiliza un solo contexto (los navegadores bloquean el audio
        // hasta el primer clic: se desbloquea con pointerdown de abajo).
        if (!window._vigAC) window._vigAC = new AC();
        const ac = window._vigAC;
        if (ac.state === 'suspended') ac.resume();
        for (let i=0;i<times;i++){
            const o=ac.createOscillator(), g=ac.createGain();
            o.connect(g); g.connect(ac.destination); o.frequency.value=freq;
            const t=ac.currentTime + i*0.5;
            g.gain.setValueAtTime(0.001,t);
            g.gain.exponentialRampToValueAtTime(0.9,t+0.05);
            g.gain.exponentialRampToValueAtTime(0.001,t+ms/1000);
            o.start(t); o.stop(t+ms/1000+0.05);
        }
    }catch(e){}
}
// Desbloqueo de audio: sin un clic previo el navegador silencia el pitido.
window.addEventListener('pointerdown', () => {
    try{
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!window._vigAC) window._vigAC = new AC();
        if (window._vigAC.state === 'suspended') window._vigAC.resume();
    }catch(e){}
});
document.getElementById('btn-beep').onclick = () => beep();

// --- Perímetro sobre el stream ---
function sizeCanvas(){
    const r = img.getBoundingClientRect();
    canvas.width = img.naturalWidth || r.width || 640;
    canvas.height = img.naturalHeight || r.height || 480;
}
function drawFence(){
    sizeCanvas();
    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.strokeStyle='#22c55e'; ctx.lineWidth=3; ctx.setLineDash([10,6]);
    ctx.beginPath(); ctx.moveTo(0,canvas.height*fenceY); ctx.lineTo(canvas.width,canvas.height*fenceY); ctx.stroke();
    ctx.setLineDash([]); ctx.fillStyle='#22c55e'; ctx.font='16px sans-serif';
    ctx.fillText('PERÍMETRO',10,canvas.height*fenceY-8);
}
let fenceSaveTimer = null;
document.getElementById('fence-range').oninput = (e) => {
    fenceY = e.target.value/100;
    document.getElementById('fence-val').textContent = e.target.value+'%';
    drawFence();
    // Guarda en la cámara (debounce 1s): YOLO lo lee de la API y mueve el suyo solo
    clearTimeout(fenceSaveTimer);
    fenceSaveTimer = setTimeout(async () => {
        if (!currentCamera) return;
        if (!apiToken()) { log('Perímetro local OK, pero sin token no se sincroniza con YOLO.'); return; }
        try {
            const res = await fetch('/api/v1/cameras/' + currentCamera.id, {
                method: 'PUT',
                headers: { 'Authorization': 'Bearer ' + apiToken(), 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ fence_line: [[0, fenceY], [1, fenceY]] })
            });
            if (res.ok) { currentCamera.fence_line = [[0, fenceY], [1, fenceY]]; log('Perímetro ' + Math.round(fenceY*100) + '% guardado → YOLO lo toma en ~5s.'); }
            else log('No se guardó el perímetro (HTTP ' + res.status + ').');
        } catch (err) { log('No se guardó el perímetro: ' + err.message); }
    }, 1000);
};
img.addEventListener('load', drawFence);
window.addEventListener('resize', drawFence);
setInterval(()=>{ if (img.src) drawFence(); }, 2000);

function setStream(cam){
    currentCamera = cam;
    streamOk = false;
    if (retryTimer) { clearInterval(retryTimer); retryTimer = null; }
    if (!cam){ streamStatus.textContent='Sin cámara.'; return; }
    camInfo.textContent = cam.name+' · '+cam.zone+' · '+(cam.location||'maqueta');
    // Si la cámara trae perímetro guardado (movido antes), el slider lo respeta
    if (cam.fence_line && cam.fence_line[0] && cam.fence_line[0][1] !== undefined) {
        const y = parseFloat(cam.fence_line[0][1]);
        if (y >= 0.05 && y <= 0.95) {
            fenceY = y;
            document.getElementById('fence-range').value = Math.round(y * 100);
            document.getElementById('fence-val').textContent = Math.round(y * 100) + '%';
        }
    }
    // MJPEG del proxy local. Cache-buster para forzar reconexión.
    img.src = cam.stream_url + (cam.stream_url.includes('?')?'&':'?') + 't=' + Date.now();
    streamStatus.textContent = 'Conectando a '+cam.stream_url+' …';
    // Si el proxy aún no tenía video (terminal 2 recién encendida), reintenta solo cada 10s
    retryTimer = setInterval(() => {
        if (!streamOk && currentCamera) {
            img.src = currentCamera.stream_url + (currentCamera.stream_url.includes('?')?'&':'?') + 't=' + Date.now();
            streamStatus.textContent = 'Reintentando ' + currentCamera.stream_url + ' … (¿terminal 2 corriendo?)';
        }
    }, 10000);
}
img.onload = () => { streamOk = true; streamStatus.textContent = 'Grabando en vivo desde ESP32-CAM ('+img.naturalWidth+'x'+img.naturalHeight+'). Perímetro al '+Math.round(fenceY*100)+'%.'; drawFence(); };
img.onerror = () => { streamOk = false; streamStatus.textContent = 'Sin video todavía. Revisa que la terminal 2 esté corriendo; reintento automático cada 10s…'; };
document.getElementById('btn-reload').onclick = () => { if (currentCamera) setStream(currentCamera); };

async function loadCameras(){
    try{
        const res = await fetch('/api/v1/cameras',{headers:{'Accept':'application/json'}});
        const json = await res.json();
        const list = json.data || [];
        selCam.innerHTML='';
        list.forEach(c=>{
            const o=document.createElement('option');
            o.value=c.id; o.textContent=c.name+' ('+c.zone+')';
            selCam.appendChild(o);
        });
        let target = PRESELECT || new URLSearchParams(location.search).get('camera') || (list[0]&&list[0].id);
        if (target){
            selCam.value = target;
            const detail = await (await fetch('/api/v1/cameras/'+target,{headers:{'Accept':'application/json'}})).json();
            setStream(detail.data || detail);
        } else {
            camInfo.textContent = 'No hay cámaras. Añade tu ESP32-CAM en el Dashboard.';
        }
    }catch(e){ log('No se pudieron cargar cámaras.'); }
}
selCam.onchange = async () => {
    const detail = await (await fetch('/api/v1/cameras/'+selCam.value,{headers:{'Accept':'application/json'}})).json();
    setStream(detail.data || detail);
};

// --- Polling de alertas: mensaje emergente + pitido ---
// Nota: `approaching` es solo informativo (no crea alerta por diseño en
// DetectionService): modal+sonido solo para critical/warning. La galería
// sí se refresca con cualquier evento nuevo porque trae foto.
async function pollAlerts(){
    try{
        const res = await fetch('/api/v1/alerts',{headers:{'Accept':'application/json'}});
        const json = await res.json();
        const alerts = json.data || [];
        if (!alerts.length) return;
        const newest = alerts[0];
        if (newest.id > lastAlertId){
            const isNew = lastAlertId !== 0;
            lastAlertId = newest.id;
            refreshGallery();
            if (!isNew) { mostrarPendienteInicial(alerts); return; } // no alarmar por historial al cargar
            const camId = newest.camera?.id;
            if (currentCamera && camId && String(camId) !== String(currentCamera.id)) return;
            if (newest.level === 'critical' || newest.level === 'warning'){
                currentAlertId = newest.id;
                showIntrusion(newest);
            } else {
                log('Evento ' + (newest.detection?.event_type || newest.level) + ': ' + (newest.message || ''));
            }
        }
    }catch(e){}
}

// Al abrir la página, si hay una alerta pendiente reciente (<10 min) se
// muestra de inmediato: antes solo sonaban las que llegaban DESPUÉS de abrir.
function mostrarPendienteInicial(alerts){
    try{
        const a = alerts[0];
        if (!a || (a.level !== 'critical' && a.level !== 'warning')) return;
        if (a.status && a.status !== 'pending') return;
        const t = a.sent_at || a.created_at;
        if (t && (Date.now() - new Date(t).getTime()) > 10*60*1000) return;
        const camId = a.camera?.id;
        if (currentCamera && camId && String(camId) !== String(currentCamera.id)) return;
        currentAlertId = a.id;
        showIntrusion(a);
    }catch(e){}
}

function showIntrusion(alert){
    const zone = alert.camera?.zone || currentCamera?.zone || 'tu casa';
    const track = alert.detection?.track_id ? ' · Track '+alert.detection.track_id : '';
    modalDetail.textContent = zone + track + ' · ' + (alert.message||'');
    modalImg.classList.add('hidden');
    modal.classList.remove('hidden');
    if (!silenced){
        if (alert.level === 'critical') beep(440, 500, 3); // pitido grave de intrusión
        else beep(880, 300, 2);                            // aviso acercándose
    }
    log('ALERTA ' + alert.level + ': ' + alert.message);
    // Foto de evidencia si la detección trae snapshot
    const detId = alert.detection?.id;
    if (detId) fetch('/api/v1/detections',{headers:{'Accept':'application/json'}}).catch(()=>{});
}

document.getElementById('btn-mute').onclick = () => { modal.classList.add('hidden'); silenced = true; setTimeout(()=>silenced=false, 60000); };
document.getElementById('btn-ack').onclick = async () => {
    modal.classList.add('hidden');
    if (!currentAlertId) return;
    if (!apiToken()){ log('Sin token: no se pudo Atender (solo se cerró el aviso).'); return; }
    try{
        const res = await fetch('/api/v1/alerts/'+currentAlertId+'/acknowledge',{method:'POST',headers:{'Authorization':'Bearer '+apiToken(),'Accept':'application/json'}});
        log('Atender alerta → '+res.status);
    }catch(e){ log('Error atendiendo: '+e.message); }
};

// --- Galería: últimas fotos de detecciones ---
async function refreshGallery(){
    try{
        const res = await fetch('/api/v1/detections',{headers:{'Accept':'application/json'}});
        const json = await res.json();
        const dets = (json.data||[]).filter(d=>d.snapshot_path).slice(0,8);
        gallery.innerHTML='';
        dets.forEach(d=>{
            const url = '/storage/'+String(d.snapshot_path).replace(/^\/+/,'');
            const a = document.createElement('a');
            a.href=url; a.target='_blank';
            a.innerHTML = '<img src="'+url+'" class="w-full h-28 object-cover rounded border" loading="lazy"><p class="text-[11px] text-gray-500 truncate">'+d.event_type+' · '+Math.round((d.confidence||0)*100)+'%</p>';
            gallery.appendChild(a);
        });
        if (!dets.length) gallery.innerHTML='<p class="text-sm text-gray-400 col-span-4">Sin fotos aún. Python sube aquí cada cruce de perímetro.</p>';
        // Si el modal está abierto y hay foto nueva, mostrarla
        const first = dets[0];
        if (first && !modal.classList.contains('hidden')){
            modalImg.src='/storage/'+String(first.snapshot_path).replace(/^\/+/,'');
            modalImg.classList.remove('hidden');
        }
    }catch(e){}
}

loadCameras().then(()=>{ refreshGallery(); refreshArma(); });
setInterval(pollAlerts, 3000);
setInterval(refreshGallery, 15000);
setInterval(refreshArma, 15000);

async function refreshArma(){
    try{
        const res = await fetch('/api/v1/blynk/status',{headers:{'Accept':'application/json'}});
        const j = await res.json();
        const el = document.getElementById('arma-estado');
        if (j.armed === true) { el.textContent = 'ARMADA'; el.className = 'px-2 py-1 rounded bg-green-100 text-green-800'; }
        else if (j.armed === false) { el.textContent = 'DESARMADA'; el.className = 'px-2 py-1 rounded bg-red-100 text-red-800'; }
        else { el.textContent = 'sin lectura'; el.className = 'px-2 py-1 rounded bg-gray-100 text-gray-600'; }
    }catch(e){}
}
async function setArma(v){
    if (!apiToken()){ log('Sin token: guarda tu token API para Armar/Desarmar.'); return; }
    try{
        const res = await fetch('/api/v1/blynk/arm',{
            method:'POST',
            headers:{'Authorization':'Bearer '+apiToken(),'Content-Type':'application/json','Accept':'application/json'},
            body: JSON.stringify({armed: v})
        });
        log(v ? 'Alarma ARMADA (YOLO la toma en ~10s).' : 'Alarma DESARMADA (solo video, sin detección).');
        refreshArma();
    }catch(e){ log('Error armando: '+e.message); }
}
document.getElementById('btn-armar').onclick = () => setArma(true);
document.getElementById('btn-desarmar').onclick = () => setArma(false);
</script>
</x-app-layout>
