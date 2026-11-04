// Selfie pour l'avatar du vendeur : ouvre la caméra (getUserMedia), cadre le visage, capture un JPEG
// et le place dans le champ « portrait » du formulaire existant. Aucun envoi ici : la génération IA
// reste déclenchée par le bouton du formulaire, donc le coût affiché et la validation serveur ne changent pas.
(function () {
  'use strict';
  var MAX_DIM = 1600;
  var QUALITY = 0.9;

  var overlay, dialog, video, still, errorBox, statusBox, captureBtn, retakeBtn, acceptBtn, switchBtn, closeBtn, hint;
  var stream = null, facing = 'user', blob = null, stillUrl = null, form = null, input = null, status = null, opener = null;

  function show(node, on) { if (node) node.hidden = !on; }
  function text(node, value) { if (node) node.textContent = value || ''; }

  function cameraSupported() {
    return window.isSecureContext && navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function';
  }

  function errorMessage(err) {
    var name = err && err.name ? err.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') {
      return "L'accès à la caméra a été refusé. Autorisez la caméra pour ce site dans les réglages du navigateur, ou importez une photo.";
    }
    if (name === 'NotFoundError' || name === 'OverconstrainedError') {
      return 'Aucune caméra utilisable sur cet appareil. Importez une photo à la place.';
    }
    if (name === 'NotReadableError' || name === 'AbortError') {
      return 'La caméra est déjà utilisée par une autre application. Fermez-la puis réessayez, ou importez une photo.';
    }
    return "La caméra n'a pas pu démarrer. Importez une photo à la place.";
  }

  function stopStream() {
    if (stream) {
      stream.getTracks().forEach(function (t) { try { t.stop(); } catch (e) {} });
      stream = null;
    }
    if (video) video.srcObject = null;
  }

  function resetStill() {
    if (stillUrl) { URL.revokeObjectURL(stillUrl); stillUrl = null; }
    blob = null;
    if (still) { still.removeAttribute('src'); still.hidden = true; }
    if (video) { video.hidden = false; video.classList.toggle('is-mirrored', facing === 'user'); }
    show(retakeBtn, false);
    show(acceptBtn, false);
    show(captureBtn, true);
    text(errorBox, '');
    show(errorBox, false);
  }

  function startCamera() {
    resetStill();
    text(hint, 'Placez votre visage au centre, puis appuyez sur « Prendre la photo ».');
    show(captureBtn, false);
    navigator.mediaDevices.getUserMedia({
      audio: false,
      video: {
        facingMode: facing === 'environment' ? { ideal: 'environment' } : { ideal: 'user' },
        width: { ideal: 1280 },
        height: { ideal: 960 }
      }
    }).then(function (s) {
      if (overlay.hidden) { s.getTracks().forEach(function (t) { t.stop(); }); return; }
      stream = s;
      video.srcObject = s;
      video.classList.toggle('is-mirrored', facing === 'user');
      return video.play().then(function () {
        show(captureBtn, true);
        if (navigator.mediaDevices.enumerateDevices) {
          navigator.mediaDevices.enumerateDevices().then(function (list) {
            show(switchBtn, list.filter(function (d) { return d.kind === 'videoinput'; }).length > 1);
          }).catch(function () {});
        }
      });
    }).catch(function (err) {
      text(errorBox, errorMessage(err));
      show(errorBox, true);
      show(captureBtn, false);
      show(switchBtn, false);
    });
  }

  function capture() {
    var vw = video.videoWidth || 0, vh = video.videoHeight || 0;
    if (!vw || !vh) { text(errorBox, 'La caméra se prépare, réessayez dans un instant.'); show(errorBox, true); return; }
    var scale = Math.min(1, MAX_DIM / Math.max(vw, vh));
    var w = Math.round(vw * scale), h = Math.round(vh * scale);
    var canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    if (facing === 'user') { ctx.translate(w, 0); ctx.scale(-1, 1); }
    ctx.drawImage(video, 0, 0, w, h);

    function done(b) {
      if (!b) { text(errorBox, "La photo n'a pas pu être créée. Réessayez."); show(errorBox, true); return; }
      blob = b;
      stillUrl = URL.createObjectURL(b);
      still.src = stillUrl;
      show(still, true);
      video.hidden = true;
      show(captureBtn, false);
      show(retakeBtn, true);
      show(acceptBtn, true);
      show(switchBtn, false);
      text(hint, 'Vérifiez votre selfie : reprenez-le si le cadrage ou la lumière ne vous convient pas.');
    }

    if (canvas.toBlob) {
      canvas.toBlob(function (b) { done(b); }, 'image/jpeg', QUALITY);
    } else {
      try {
        var data = canvas.toDataURL('image/jpeg', QUALITY).split(',')[1];
        var bin = atob(data), bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        done(new Blob([bytes], { type: 'image/jpeg' }));
      } catch (e) { done(null); }
    }
  }

  function fileFromBlob() {
    var name = 'selfie-' + new Date().toISOString().replace(/[:.]/g, '-') + '.jpg';
    try { return new File([blob], name, { type: 'image/jpeg' }); }
    catch (e) {
      blob.lastModified = Date.now();
      blob.name = name;
      return blob;
    }
  }

  function accept() {
    if (!blob || !form || !input) return;
    var file = fileFromBlob();
    var ok = false;
    try {
      if (typeof DataTransfer === 'function') {
        var dt = new DataTransfer();
        dt.items.add(file);
        input.files = dt.files;
        ok = input.files && input.files.length === 1;
      }
    } catch (e) { ok = false; }

    if (ok) {
      if (status) {
        var ko = Math.round(file.size / 1024);
        status.textContent = 'Selfie prêt (' + ko + ' ko). Appuyez sur « ' + (form.querySelector('[type="submit"]').textContent.trim()) + ' ».';
        status.hidden = false;
      }
      close();
      var submit = form.querySelector('[type="submit"]');
      if (submit) submit.focus();
      return;
    }

    // Navigateurs sans DataTransfer : envoi direct du formulaire avec le fichier.
    var fd = new FormData(form);
    fd.set('portrait', file, file.name);
    if (status) { status.textContent = 'Envoi du selfie…'; status.hidden = false; }
    close();
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function () { window.location.href = '/admin/profile.php'; })
      .catch(function () { window.location.href = '/admin/profile.php'; });
  }

  function open(trigger) {
    if (!overlay) return;
    form = trigger.closest('form');
    input = form ? form.querySelector('input[type="file"][name="portrait"]') : null;
    status = form ? form.querySelector('[data-avatar-file-status]') : null;
    if (!form || !input) return;
    opener = trigger;
    overlay.hidden = false;
    document.body.style.overflow = 'hidden';
    if (!cameraSupported()) {
      text(errorBox, 'La prise de photo nécessite une connexion sécurisée (https) et un navigateur récent. Importez une photo à la place.');
      show(errorBox, true);
      show(captureBtn, false);
      if (closeBtn) closeBtn.focus();
      return;
    }
    startCamera();
    if (captureBtn) captureBtn.focus();
  }

  function close() {
    if (!overlay || overlay.hidden) return;
    stopStream();
    overlay.hidden = true;
    document.body.style.overflow = '';
    resetStill();
    if (video) video.hidden = false;
    if (opener) { try { opener.focus(); } catch (e) {} }
    opener = null; form = null; input = null; status = null;
  }

  function bind() {
    overlay = document.querySelector('[data-avatar-camera]');
    if (!overlay) return;
    dialog = overlay.querySelector('.asnap-dialog');
    video = overlay.querySelector('[data-asnap-video]');
    still = overlay.querySelector('[data-asnap-still]');
    errorBox = overlay.querySelector('[data-asnap-error]');
    hint = overlay.querySelector('[data-asnap-hint]');
    captureBtn = overlay.querySelector('[data-asnap-capture]');
    retakeBtn = overlay.querySelector('[data-asnap-retake]');
    acceptBtn = overlay.querySelector('[data-asnap-accept]');
    switchBtn = overlay.querySelector('[data-asnap-switch]');
    closeBtn = overlay.querySelector('[data-asnap-close]');

    document.querySelectorAll('[data-avatar-selfie]').forEach(function (btn) {
      btn.hidden = !cameraSupported();
      btn.addEventListener('click', function () { open(btn); });
    });

    if (captureBtn) captureBtn.addEventListener('click', capture);
    if (retakeBtn) retakeBtn.addEventListener('click', startCamera);
    if (acceptBtn) acceptBtn.addEventListener('click', accept);
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (switchBtn) switchBtn.addEventListener('click', function () { facing = facing === 'user' ? 'environment' : 'user'; startCamera(); });
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !overlay.hidden) { e.preventDefault(); close(); }
    });
    window.addEventListener('pagehide', function () { stopStream(); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', bind);
  else bind();
})();
