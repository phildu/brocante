/**
 * Conversion automatique des photos HEIC/HEIF (format par défaut des iPhone)
 * en JPEG, côté navigateur, avant l'envoi au serveur — GD/PHP ne sait pas les
 * lire et l'hébergement mutualisé n'offre ni Imagick ni libheif.
 *
 *  - Tout <input type="file"> qui reçoit un fichier HEIC : le fichier est
 *    remplacé par son équivalent JPEG, puis l'évènement "change" est rejoué
 *    pour que les autres scripts de la page voient le JPEG.
 *  - Zones de dépôt (glisser-déposer) : window.heicConvertFiles(fichiers)
 *    renvoie une Promise<File[]> où chaque HEIC est devenu un JPEG.
 *
 * La bibliothèque de décodage (≈ 3 Mo, libheif en WebAssembly) n'est chargée
 * qu'à la première photo HEIC rencontrée.
 */
(function () {
  if (window.heicConvertFiles) return;

  var script = document.currentScript;
  var libUrl = script && script.src
    ? script.src.replace(/[^\/]*(\?.*)?$/, '') + 'vendor/heic-to.js'
    : '/assets/vendor/heic-to.js';
  var libPromise = null;

  function isHeicFile(f) {
    if (!f) return false;
    return /\.(heic|heif)$/i.test(f.name || '') || /^image\/hei[cf]/i.test(f.type || '');
  }

  function loadLib() {
    if (window.HeicTo) return Promise.resolve(window.HeicTo);
    if (!libPromise) {
      libPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = libUrl;
        s.onload = function () { window.HeicTo ? resolve(window.HeicTo) : reject(new Error('décodeur HEIC indisponible')); };
        s.onerror = function () { libPromise = null; reject(new Error('décodeur HEIC introuvable')); };
        document.head.appendChild(s);
      });
    }
    return libPromise;
  }

  function convertOne(file) {
    return loadLib().then(function (HeicTo) {
      return HeicTo({ blob: file, type: 'image/jpeg', quality: 0.9 });
    }).then(function (blob) {
      return new File([blob], (file.name || 'photo').replace(/\.(heic|heif)$/i, '') + '.jpg',
        { type: 'image/jpeg', lastModified: file.lastModified || Date.now() });
    });
  }

  /** Renvoie une Promise<{files: File[], failed: string[]}> — les HEIC sont convertis, les échecs conservés tels quels. */
  function convertAll(list) {
    var files = Array.prototype.slice.call(list || []);
    var failed = [];
    return files.reduce(function (p, f) {
      return p.then(function (out) {
        if (!isHeicFile(f)) { out.push(f); return out; }
        return convertOne(f).then(function (j) { out.push(j); return out; }, function () { failed.push(f.name); out.push(f); return out; });
      });
    }, Promise.resolve([])).then(function (out) { return { files: out, failed: failed }; });
  }

  window.heicConvertFiles = function (list) {
    return convertAll(list).then(function (r) {
      if (r.failed.length) notify(null, 'Conversion HEIC impossible pour : ' + r.failed.join(', ') + ' — exportez-les en JPEG.');
      return r.files;
    });
  };
  window.heicIsHeic = isHeicFile;

  function notify(input, msg, busy) {
    var id = 'heic-note';
    var el = document.getElementById(id);
    if (!msg) { if (el) el.remove(); return; }
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.setAttribute('role', 'status');
      el.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:99999;'
        + 'background:#222;color:#fff;padding:10px 16px;border-radius:8px;font:14px/1.3 system-ui,sans-serif;'
        + 'box-shadow:0 4px 16px rgba(0,0,0,.3);max-width:90vw';
      document.body.appendChild(el);
    }
    el.textContent = msg;
    if (!busy) setTimeout(function () { if (el.parentNode && el.textContent === msg) el.remove(); }, 8000);
  }

  // <input type="file"> : écoute en phase de capture pour passer avant les autres scripts.
  document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || input.tagName !== 'INPUT' || input.type !== 'file' || e.__heicDone) return;
    var files = Array.prototype.slice.call(input.files || []);
    if (!files.some(isHeicFile)) return;

    e.stopImmediatePropagation();
    notify(input, 'Conversion des photos HEIC en JPEG…', true);
    convertAll(files).then(function (r) {
      try {
        var dt = new DataTransfer();
        r.files.forEach(function (f) { dt.items.add(f); });
        input.files = dt.files;
      } catch (err) { /* navigateur sans DataTransfer : on laisse les originaux */ }
      notify(input, r.failed.length
        ? 'Conversion HEIC impossible pour : ' + r.failed.join(', ') + ' — exportez-les en JPEG.'
        : (files.filter(isHeicFile).length > 1 ? 'Photos HEIC converties en JPEG.' : 'Photo HEIC convertie en JPEG.'));
      var again = new Event('change', { bubbles: true });
      again.__heicDone = true;
      input.dispatchEvent(again);
    });
  }, true);

  // Les sélecteurs à liste explicite (image/jpeg,image/png…) grisent les HEIC dans la fenêtre de choix.
  function widenAccept(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('input[type=file][accept]'), function (i) {
      var a = i.getAttribute('accept') || '';
      if (/image\//.test(a) && a.indexOf('image/*') === -1 && !/heic|heif/i.test(a)) i.setAttribute('accept', a + ',.heic,.heif');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { widenAccept(); });
  else widenAccept();
})();
