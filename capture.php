<?php
// Page de prise de vue ouverte sur le téléphone par le code QR de la médiathèque
// (Administration → Médiathèque → « Utiliser mon téléphone »). Pas de connexion :
// le jeton du lien autorise l'envoi de photos et de courtes vidéos, et expire.
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store');
$content = get_content();
$token = (string) ($_GET['t'] ?? '');
$session = capture_session_by_token($token);
$maxBytes = CAPTURE_VIDEO_MAX_BYTES;
$chunkBytes = capture_chunk_bytes();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Envoyer des photos — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .cap { max-width: 480px; margin: 0 auto; padding: 28px 18px 60px; }
  .cap-logo { display: block; max-height: 44px; max-width: 70%; margin-bottom: 18px; }
  .cap h1 { font-size: 1.5rem; margin: 0 0 6px; }
  .cap .lede { margin: 0 0 22px; color: var(--ink-soft); font-size: 0.95rem; }
  .cap-btn { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; box-sizing: border-box; min-height: 68px; margin-bottom: 12px;
    border: 2px solid var(--accent); background: var(--surface); color: var(--ink); font-size: 1.05rem; font-weight: 600; cursor: pointer; padding: 12px; text-align: center; }
  .cap-btn.primary { background: var(--accent); color: var(--accent-ink); }
  .cap-btn.ghost { border-color: var(--line); font-weight: 500; min-height: 52px; }
  .cap-btn small { display: block; font-weight: 400; opacity: 0.85; font-size: 0.8rem; }
  .cap-btn[aria-disabled="true"] { opacity: 0.5; pointer-events: none; }
  .cap-count { margin: 18px 0 8px; font-weight: 600; }
  .cap-log { list-style: none; margin: 0; padding: 0; font-size: 0.88rem; }
  .cap-log li { display: flex; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--line); }
  .cap-log .name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .cap-log .st { white-space: nowrap; }
  .cap-log .ok { color: var(--accent); font-weight: 600; }
  .cap-log .ko { color: #b3261e; white-space: normal; text-align: right; max-width: 60%; }
  .cap-expired { border: 1px solid var(--line); background: var(--surface); padding: 18px; }
</style>
</head>
<body>
<main class="cap">
  <img class="cap-logo" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($content['site_name']) ?>">
  <h1>Envoyer vers la médiathèque</h1>
  <?php if (!$session): ?>
    <div class="cap-expired" role="alert">
      <p style="margin:0 0 8px;"><strong>Ce lien n'est plus valable.</strong></p>
      <p style="margin:0;">Il a expiré ou a été fermé. Sur l'ordinateur, ouvrez la médiathèque et affichez un nouveau code.</p>
    </div>
  <?php else: ?>
    <p class="lede">Prenez une photo ou filmez : elle arrive tout de suite sur l'ordinateur, dans la médiathèque de <?= h($content['site_name']) ?>.</p>

    <label class="cap-btn primary" id="btn-photo">📷 Prendre une photo
      <input type="file" accept="image/*" capture="environment" id="in-photo" hidden>
    </label>
    <label class="cap-btn" id="btn-video"><span>🎥 Filmer<small>courte vidéo, <?= CAPTURE_VIDEO_MAX_SECONDS ?> secondes maximum</small></span>
      <input type="file" accept="video/*" capture="environment" id="in-video" hidden>
    </label>
    <label class="cap-btn ghost" id="btn-gallery">🖼 Choisir dans la galerie
      <input type="file" accept="image/*,video/*" multiple id="in-gallery" hidden>
    </label>

    <p class="cap-count" id="count" hidden></p>
    <ul class="cap-log" id="log" aria-live="polite"></ul>

    <div class="cap-expired" id="expired" role="alert" hidden>
      <p style="margin:0 0 8px;"><strong>Le code a expiré ou a été fermé.</strong></p>
      <p style="margin:0;">Les fichiers déjà envoyés sont dans la médiathèque. Pour continuer, affichez un nouveau code sur l'ordinateur.</p>
    </div>
  <?php endif; ?>
</main>
<?php if ($session): ?>
<script>
(function () {
  var TOKEN = <?= json_encode($token) ?>;
  var UPLOAD_URL = <?= json_encode(app_prefix('/capture-upload.php')) ?>;
  var MAX_BYTES = <?= (int) $maxBytes ?>;
  var CHUNK = <?= (int) $chunkBytes ?>;
  var MAX_SECONDS = <?= CAPTURE_VIDEO_MAX_SECONDS ?>;
  var sent = 0;
  var stopped = false;

  function $(id) { return document.getElementById(id); }
  function mb(bytes) { return (bytes / 1048576).toFixed(bytes > 10485760 ? 0 : 1).replace('.', ',') + ' Mo'; }

  function addLine(name) {
    var li = document.createElement('li');
    li.innerHTML = '<span class="name"></span><span class="st">…</span>';
    li.querySelector('.name').textContent = name;
    $('log').insertBefore(li, $('log').firstChild);
    var st = li.querySelector('.st');
    return {
      progress: function (pct) { st.className = 'st'; st.textContent = pct + ' %'; },
      ok: function () { st.className = 'st ok'; st.textContent = '✓ envoyé'; },
      fail: function (msg) { st.className = 'st ko'; st.textContent = msg; }
    };
  }

  // Réduit la photo sur le téléphone (1600 px, JPEG) avant l'envoi : plus rapide en 4G.
  function shrink(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        var canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        URL.revokeObjectURL(url);
        canvas.toBlob(function (blob) { resolve(blob || file); }, 'image/jpeg', 0.85);
      };
      img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
      img.src = url;
    });
  }

  // Durée de la vidéo, lue sur le téléphone ; null si le navigateur ne sait pas la lire (alors seul le poids est contrôlé).
  function videoDuration(file) {
    return new Promise(function (resolve) {
      var url = URL.createObjectURL(file);
      var v = document.createElement('video');
      var done = function (d) { URL.revokeObjectURL(url); v.removeAttribute('src'); resolve(d); };
      var timer = setTimeout(function () { done(null); }, 5000);
      v.preload = 'metadata';
      v.onloadedmetadata = function () { clearTimeout(timer); done(isFinite(v.duration) ? v.duration : null); };
      v.onerror = function () { clearTimeout(timer); done(null); };
      v.src = url;
    });
  }

  function post(fd, onProgress) {
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', UPLOAD_URL);
      if (onProgress) xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(e.loaded); };
      xhr.onload = function () {
        var data = null;
        try { data = JSON.parse(xhr.responseText); } catch (e) {}
        resolve({ data: data, status: xhr.status });
      };
      xhr.onerror = function () { resolve({ network: true }); };
      xhr.send(fd);
    });
  }

  function finish(res, line) {
    var data = res.data;
    if (res.network) { line.fail('pas de connexion, réessayez'); return false; }
    if (data && data.ok) {
      if (data.done === false) return true;
      sent++; line.ok();
      $('count').hidden = false; $('count').textContent = sent + ' fichier' + (sent > 1 ? 's' : '') + ' envoyé' + (sent > 1 ? 's' : '');
      return true;
    }
    if (data && data.code === 'expired') { line.fail('code expiré'); stop(); }
    else if (!data && res.status === 413) { line.fail('trop lourd pour le serveur'); }
    else { line.fail((data && data.error) || 'échec de l\'envoi, réessayez'); }
    return false;
  }

  function upload(blob, filename, line) {
    var fd = new FormData();
    fd.append('t', TOKEN);
    fd.append('file', blob, filename);
    return post(fd, function (loaded) { line.progress(Math.round(loaded / blob.size * 100)); }).then(function (res) { finish(res, line); });
  }

  // Vidéo : découpée en morceaux de CHUNK octets, envoyés l'un après l'autre (3 essais chacun).
  async function uploadVideo(file, line) {
    var total = Math.ceil(file.size / CHUNK);
    var id = Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    for (var i = 0; i < total; i++) {
      var piece = file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK));
      var res = null;
      for (var attempt = 0; attempt < 3; attempt++) {
        var fd = new FormData();
        fd.append('t', TOKEN);
        fd.append('upload_id', id);
        fd.append('index', i);
        fd.append('total', total);
        fd.append('size', file.size);
        fd.append('name', file.name || 'video.mp4');
        fd.append('file', piece, 'part');
        var base = i * CHUNK;
        res = await post(fd, function (loaded) { line.progress(Math.min(99, Math.round((base + loaded) / file.size * 100))); });
        if (!res.network && !(res.status >= 500 && !(res.data && res.data.error))) break;
        await new Promise(function (r) { setTimeout(r, 1500 * (attempt + 1)); });
      }
      if (!finish(res, line)) return;
    }
  }

  function stop() {
    stopped = true;
    $('expired').hidden = false;
    ['btn-photo', 'btn-video', 'btn-gallery'].forEach(function (id) { $(id).setAttribute('aria-disabled', 'true'); });
  }

  async function handle(files) {
    for (var i = 0; i < files.length && !stopped; i++) {
      var file = files[i];
      var isVideo = /^video\//.test(file.type) || /\.(mp4|mov|m4v|webm)$/i.test(file.name);
      var line = addLine(isVideo ? 'Vidéo' : 'Photo');
      if (isVideo) {
        if (file.size > MAX_BYTES) { line.fail('trop lourde (' + mb(file.size) + ', max ' + mb(MAX_BYTES) + ') : filmez plus court ou en qualité standard'); continue; }
        var duration = await videoDuration(file);
        if (duration !== null && duration > MAX_SECONDS + 1) { line.fail('trop longue (' + Math.round(duration) + ' s, max ' + MAX_SECONDS + ' s)'); continue; }
        await uploadVideo(file, line);
      } else {
        var blob = await shrink(file);
        await upload(blob, 'photo.jpg', line);
      }
    }
  }

  ['in-photo', 'in-video', 'in-gallery'].forEach(function (id) {
    $(id).addEventListener('change', function () {
      var files = Array.prototype.slice.call(this.files);
      this.value = '';
      handle(files);
    });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
