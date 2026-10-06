// Vidéos de la galerie d'une pièce (admin/gallery.php), deux options qui n'ont pas besoin de ffmpeg sur le serveur :
//  - « Petite vidéo (zoom, travelling) » : fabriquée ici, dans le navigateur (canvas + MediaRecorder), puis envoyée ;
//    gratuite. Si le serveur a ffmpeg, le formulaire normal est utilisé et ce script n'intervient pas.
//  - « Vidéo IA (Veo) » : lancée chez Google par admin/video-action.php, puis suivie toutes les 10 secondes.
(function () {
  'use strict';
  var form = document.getElementById('generate-form');
  if (!form) return;
  var BASE = window.APP_BASE || '';
  var ACTION = BASE + '/admin/video-action.php';
  var statusEl = document.getElementById('video-status');
  var submitBtn = form.querySelector('button[type="submit"]');
  var paths = {};
  try { paths = JSON.parse(form.dataset.paths || '{}'); } catch (e) {}
  var busy = false;

  function say(text, kind) {
    statusEl.hidden = !text;
    statusEl.textContent = text || '';
    statusEl.dataset.kind = kind || '';
  }
  function lock(on) {
    busy = on;
    if (submitBtn) submitBtn.disabled = on;
  }
  function post(data) {
    var fd = new FormData();
    fd.append('csrf', form.dataset.csrf);
    fd.append('ref', form.elements.ref.value);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(ACTION, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Réponse inattendue du serveur.' }; }); })
      .catch(function () { return { ok: false, error: 'Connexion perdue : réessayez.' }; });
  }

  // ── Zoom / travelling dans le navigateur ──
  var SIZE = 900, SECONDS = 3, FPS = 25;

  function pickMime() {
    if (!window.MediaRecorder) return '';
    var list = ['video/mp4;codecs=avc1.42E01E', 'video/mp4', 'video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm'];
    for (var i = 0; i < list.length; i++) if (MediaRecorder.isTypeSupported(list[i])) return list[i];
    return '';
  }

  function loadImage(url) {
    return new Promise(function (resolve, reject) {
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { reject(new Error('Photo illisible.')); };
      img.src = url;
    });
  }

  // Position de la fenêtre de cadrage (dans le carré central de la photo) à l'instant t ∈ [0, 1].
  function frameAt(effect, t) {
    var z = 1, shift = 0.5;
    if (effect === 'zoom_in') z = 1.05 + 0.25 * t;
    else if (effect === 'zoom_out') z = 1.30 - 0.25 * t;
    else if (effect === 'pan_left') { z = 1.18; shift = 1 - t; }
    else if (effect === 'pan_right') { z = 1.18; shift = t; }
    else z = 1.05 + 0.25 * t;
    return { z: z, shift: shift };
  }

  function recordKenBurns(img, effect, mime) {
    return new Promise(function (resolve, reject) {
      var canvas = document.createElement('canvas');
      canvas.width = canvas.height = SIZE;
      var ctx = canvas.getContext('2d');
      ctx.imageSmoothingQuality = 'high';
      var side = Math.min(img.naturalWidth, img.naturalHeight);
      var ox = (img.naturalWidth - side) / 2, oy = (img.naturalHeight - side) / 2;
      var panEffect = effect === 'pan_left' || effect === 'pan_right';

      function draw(t) {
        var f = frameAt(effect, t), w = side / f.z;
        var x = ox + (panEffect ? (side - w) * f.shift : (side - w) / 2);
        var y = oy + (side - w) / 2;
        ctx.fillStyle = '#fff'; // une photo détourée (fond transparent) se pose sur du blanc
        ctx.fillRect(0, 0, SIZE, SIZE);
        ctx.drawImage(img, x, y, w, w, 0, 0, SIZE, SIZE);
      }

      var chunks = [];
      var recorder;
      try { recorder = new MediaRecorder(canvas.captureStream(FPS), { mimeType: mime, videoBitsPerSecond: 4000000 }); }
      catch (e) { reject(e); return; }
      recorder.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      recorder.onerror = function () { reject(new Error("L'enregistrement a échoué.")); };
      recorder.onstop = function () { resolve(new Blob(chunks, { type: mime.split(';')[0] })); };

      draw(0);
      recorder.start();
      // Minuteur plutôt que requestAnimationFrame : ce dernier s'arrête si l'onglet passe en arrière-plan.
      var start = performance.now();
      var timer = setInterval(function () {
        var t = Math.min(1, (performance.now() - start) / (SECONDS * 1000));
        draw(t);
        if (t >= 1) {
          clearInterval(timer);
          setTimeout(function () { recorder.stop(); }, 150);
        }
      }, 1000 / FPS);
    });
  }

  function makeKenBurns() {
    var effect = form.elements.video_effect.value;
    var path = paths[form.elements.source_photo_id.value];
    var mime = pickMime();
    if (!path) { say('Choisissez une photo de départ.', 'error'); return; }
    if (!mime) { say("Ce navigateur ne sait pas enregistrer de vidéo : utilisez Chrome, Edge ou Safari récent.", 'error'); return; }
    lock(true);
    say('Fabrication de la vidéo (3 secondes)…');
    loadImage(BASE + '/' + path)
      .then(function (img) { return recordKenBurns(img, effect, mime); })
      .then(function (blob) {
        say('Envoi de la vidéo…');
        return post({ action: 'upload', effect: effect, file: new File([blob], 'video.' + (mime.indexOf('mp4') !== -1 ? 'mp4' : 'webm'), { type: blob.type }) });
      })
      .then(function (res) {
        if (res.ok) { location.reload(); return; }
        say(res.error || "L'envoi a échoué.", 'error'); lock(false);
      })
      .catch(function (err) { say((err && err.message) || 'La vidéo a échoué.', 'error'); lock(false); });
  }

  // ── Vidéo IA (Veo) ──
  var pollTimer = null;
  function poll(job, label, since) {
    clearTimeout(pollTimer);
    function elapsed() { var s = Math.max(0, Math.round(Date.now() / 1000 - since)); return Math.floor(s / 60) + ' min ' + ('0' + (s % 60)).slice(-2) + ' s'; }
    function step() {
      post({ action: 'veo_poll', job: job }).then(function (res) {
        if (res.state === 'done') { location.reload(); return; }
        if (res.state === 'failed' || !res.ok) { say((res.error || 'La vidéo a échoué.'), 'error'); lock(false); return; }
        say('Google génère « ' + label + ' » — ' + elapsed() + ' (1 à 6 minutes). Vous pouvez quitter cette page : la vidéo sera ajoutée à la galerie si vous revenez ici.');
        pollTimer = setTimeout(step, 10000);
      });
    }
    lock(true);
    say('Google génère « ' + label + ' » — ' + elapsed() + ' (1 à 6 minutes).');
    pollTimer = setTimeout(step, 8000);
  }

  function startVeo() {
    lock(true);
    say('Envoi de la photo à Google…');
    post({
      action: 'veo_start',
      source_photo_id: form.elements.source_photo_id.value,
      model: form.elements.model.value,
      seconds: form.elements.seconds.value,
      aspect: form.elements.aspect.value,
      effect: form.elements.veo_effect.value,
      keywords: form.elements.keywords.value
    }).then(function (res) {
      if (!res.ok) { say(res.error || 'Google a refusé la demande.', 'error'); lock(false); return; }
      poll(res.job, 'la vidéo IA', Date.now() / 1000);
    });
  }

  form.addEventListener('submit', function (e) {
    var kind = form.elements.kind.value;
    if (kind === 'video_ai') { e.preventDefault(); if (!busy) startVeo(); }
    else if (kind === 'video' && form.dataset.ffmpeg !== '1') { e.preventDefault(); if (!busy) makeKenBurns(); }
  });

  // Générations lancées avant un rechargement de la page : on reprend leur suivi.
  var pending = [];
  try { pending = JSON.parse(form.dataset.pending || '[]'); } catch (e) {}
  if (pending.length) poll(pending[0].id, pending[0].label, pending[0].since);
})();
