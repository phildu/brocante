// Impression d'étiquettes QR sur une Fichero D11s (Bluetooth du navigateur).
// Web Bluetooth pour Fichero, export d'images pour les applications d'impression.
(function (global) {
  'use strict';

  var SERVICE = '0000ff00-0000-1000-8000-00805f9b34fb';
  var WRITE = '0000ff02-0000-1000-8000-00805f9b34fb';
  var NOTIFY = '0000ff01-0000-1000-8000-00805f9b34fb';
  var NOTIFY_FF03 = '0000ff03-0000-1000-8000-00805f9b34fb';
  var CHUNK = 20;
  var PRINT_DENSITY = 2;

  function base() { return global.APP_BASE || ''; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function supported() {
    return typeof navigator !== 'undefined' && !!navigator.bluetooth && !!window.isSecureContext;
  }

  function b64ToBytes(b64) {
    var bin = atob(b64);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function header(height) {
    var h = new Uint8Array(8);
    h[0] = 0x1d; h[1] = 0x76; h[2] = 0x30; h[3] = 0x00;
    h[4] = 12; h[5] = 0;
    h[6] = height & 0xff; h[7] = (height >> 8) & 0xff;
    return h;
  }

  async function writeChunks(char, data) {
    var withResp = !!(char.properties && char.properties.write);
    for (var i = 0; i < data.length; i += CHUNK) {
      var slice = data.slice(i, i + CHUNK);
      if (withResp) await char.writeValue(slice);
      else if (char.writeValueWithoutResponse) await char.writeValueWithoutResponse(slice);
      else await char.writeValue(slice);
      await sleep(withResp ? 10 : 20);
    }
  }

  function explainBleError(err) {
    var msg = err && err.message ? String(err.message) : '';
    var name = err && err.name ? String(err.name) : '';
    if (/cancel/i.test(msg)) return 'Association annulée.';
    if (/Unsupported device|GATT|NetworkError|NotFoundError/i.test(msg + ' ' + name)) {
      return 'La connexion à l’imprimante a échoué. Vérifiez qu’elle est allumée et qu’aucune autre application ne l’utilise, puis réessayez.';
    }
    return msg || 'Impression impossible.';
  }

  async function gattConnect(device) {
    var last = null;
    var attempt;
    for (attempt = 0; attempt < 3; attempt++) {
      try {
        if (device.gatt.connected) {
          try { device.gatt.disconnect(); } catch (e) { console.warn('Déconnexion Bluetooth impossible', e); }
          await sleep(400);
        }
        return await device.gatt.connect();
      } catch (e) {
        last = e;
        await sleep(500);
      }
    }
    throw last;
  }

  async function connect() {
    if (!window.isSecureContext) {
      throw new Error('L’impression Bluetooth nécessite une page https (cadenas dans la barre d’adresse).');
    }
    if (!navigator.bluetooth) {
      throw new Error('Bluetooth indisponible dans ce navigateur. Sur Android ou ordinateur, utilisez Chrome ; sur iPhone, exportez l’étiquette vers une application d’impression.');
    }
    var device = await navigator.bluetooth.requestDevice({
      filters: [
        { namePrefix: 'FICHERO' },
        { namePrefix: 'Fichero' },
        { namePrefix: 'D11s' }
      ],
      optionalServices: [SERVICE]
    });
    try {
      var server = await gattConnect(device);
      var service = await server.getPrimaryService(SERVICE);
      var char = await service.getCharacteristic(WRITE);
      try {
        var n = await service.getCharacteristic(NOTIFY);
        await n.startNotifications();
      } catch (e) { console.warn('Notification Fichero indisponible', e); }
      try {
        var n3 = await service.getCharacteristic(NOTIFY_FF03);
        await n3.startNotifications();
      } catch (e) { console.warn('Notification Fichero ff03 indisponible', e); }
      return { device: device, char: char };
    } catch (e) {
      if (device.gatt.connected) device.gatt.disconnect();
      throw e;
    }
  }

  async function printJob(char, job) {
    var height = job.height || 240;
    var raster = b64ToBytes(job.raster);
    await writeChunks(char, new Uint8Array([0x10, 0xff, 0x10, 0x00, PRINT_DENSITY]));
    await sleep(50);
    await writeChunks(char, new Uint8Array([0x10, 0xff, 0x84, 0x00]));
    await sleep(50);
    await writeChunks(char, new Uint8Array(12));
    await sleep(50);
    await writeChunks(char, new Uint8Array([0x10, 0xff, 0xfe, 0x01]));
    await sleep(80);
    await writeChunks(char, header(height));
    await sleep(30);
    await writeChunks(char, raster);
    await writeChunks(char, new Uint8Array([0x1d, 0x0c]));
    await sleep(50);
    await writeChunks(char, new Uint8Array([0x10, 0xff, 0xfe, 0x45]));
    await sleep(800);
  }

  async function fetchJobs(refs, copies, code) {
    if (refs.length > 50) throw new Error('Choisissez au maximum 50 pièces à la fois.');
    var url = base() + '/admin/fichero-print.php?refs=' + encodeURIComponent(refs.join(',')) + '&copies=' + encodeURIComponent(copies || 1) + '&code=' + encodeURIComponent(code || 'qr');
    var res = await fetch(url, { credentials: 'same-origin' });
    var data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Préparation des étiquettes impossible.');
    return data;
  }

  async function printRefs(refs, opts) {
    opts = opts || {};
    var copies = Math.max(1, Math.min(20, parseInt(opts.copies, 10) || 1));
    if (!refs || !refs.length) throw new Error('Aucune pièce à imprimer.');
    if (refs.length > 50) throw new Error('Choisissez au maximum 50 pièces à la fois.');
    try {
      var printer = await connect();
      try {
        var data = await fetchJobs(refs, copies, opts.code);
        for (var c = 0; c < (data.copies || copies); c++) {
          for (var i = 0; i < data.jobs.length; i++) {
            await printJob(printer.char, data.jobs[i]);
          }
        }
      } finally {
        try { printer.device.gatt.disconnect(); } catch (e) { console.warn('Déconnexion Bluetooth impossible', e); }
      }
    } catch (e) {
      throw new Error(explainBleError(e));
    }
  }

  function labelPng(job) {
    var width = job.width, height = job.height;
    var bytes = b64ToBytes(job.raster);
    if (!Number.isInteger(width) || !Number.isInteger(height) || width <= 0 ||
        height <= 0 || width % 8 !== 0 || bytes.length !== width / 8 * height) {
      throw new Error('Étiquette invalide : impossible de préparer l’image.');
    }
    var canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    var ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('Préparation de l’image impossible dans ce navigateur.');
    var pixels = ctx.createImageData(width, height);
    for (var y = 0; y < height; y++) {
      for (var x = 0; x < width; x++) {
        var black = bytes[y * width / 8 + Math.floor(x / 8)] & (128 >> (x % 8));
        var pos = (y * width + x) * 4;
        pixels.data[pos] = pixels.data[pos + 1] = pixels.data[pos + 2] = black ? 0 : 255;
        pixels.data[pos + 3] = 255;
      }
    }
    ctx.putImageData(pixels, 0, 0);
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error('Création de l’image impossible.'));
      }, 'image/png');
    });
  }

  async function renderPreview(container, refs, code) {
    if (!container) return;
    var request = {};
    container.ficheroPreviewRequest = request;
    container.textContent = 'Préparation de l’aperçu…';
    try {
      var data = await fetchJobs(refs, 1, code);
      var images = await Promise.all(data.jobs.map(async function (job) {
        return { ref: job.ref, blob: await labelPng(job) };
      }));
      if (container.ficheroPreviewRequest !== request) return;
      container.textContent = '';
      images.forEach(function (item) {
        var figure = document.createElement('figure');
        var image = document.createElement('img');
        var caption = document.createElement('figcaption');
        var url = URL.createObjectURL(item.blob);
        image.src = url;
        image.alt = 'Étiquette Fichero pour la pièce ' + item.ref;
        image.addEventListener('load', function () { URL.revokeObjectURL(url); }, { once: true });
        image.addEventListener('error', function () {
          URL.revokeObjectURL(url);
          caption.textContent = 'Aperçu indisponible pour ' + item.ref;
        }, { once: true });
        caption.textContent = item.ref;
        figure.appendChild(image);
        figure.appendChild(caption);
        container.appendChild(figure);
      });
    } catch (err) {
      if (container.ficheroPreviewRequest === request) container.textContent = 'Aperçu Fichero indisponible : ' + (err && err.message ? err.message : 'erreur de préparation.');
    }
  }

  function bindExportButton(btn, getRefs, getCode) {
    if (!btn) return;
    var result = document.createElement('div');
    result.setAttribute('aria-live', 'polite');
    btn.insertAdjacentElement('afterend', result);
    var objectUrls = [];
    btn.addEventListener('click', async function () {
      var refs = getRefs() || [];
      if (!refs.length) { alert('Cochez au moins une pièce.'); return; }
      btn.disabled = true;
      result.textContent = 'Préparation des images…';
      objectUrls.forEach(function (url) { URL.revokeObjectURL(url); });
      objectUrls = [];
      try {
        var data = await fetchJobs(refs, 1, getCode ? getCode() : 'qr');
        var files = await Promise.all(data.jobs.map(async function (job) {
          var blob = await labelPng(job);
          return { ref: job.ref, name: 'etiquette-' + job.ref + '.png', blob: blob };
        }));
        result.textContent = '';
        var hint = document.createElement('p');
        hint.textContent = 'Enregistrez chaque image puis importez-la dans votre application d’impression. Le partage direct dépend de l’application.';
        result.appendChild(hint);
        files.forEach(function (item) {
          var row = document.createElement('div');
          var link = document.createElement('a');
          var url = URL.createObjectURL(item.blob);
          objectUrls.push(url);
          link.href = url;
          link.download = item.name;
          link.textContent = 'Enregistrer l’étiquette ' + item.ref;
          row.appendChild(link);
          if (typeof File === 'function' && navigator.share && navigator.canShare) {
            var file = new File([item.blob], item.name, { type: 'image/png' });
            if (navigator.canShare({ files: [file] })) {
              var share = document.createElement('button');
              share.type = 'button';
              share.textContent = 'Partager ' + item.ref;
              share.addEventListener('click', function () {
                navigator.share({ files: [file] }).catch(function (err) {
                  if (err.name !== 'AbortError') alert(err.message || 'Partage impossible : enregistrez l’image.');
                });
              });
              row.appendChild(share);
            }
          }
          result.appendChild(row);
        });
      } catch (err) {
        result.textContent = '';
        alert(err && err.message ? err.message : 'Préparation des images impossible.');
      } finally {
        btn.disabled = false;
      }
    });
  }

  function bindButton(btn, getRefs, getCopies, getCode) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      var refs = getRefs() || [];
      if (!refs.length) { alert('Cochez au moins une pièce.'); return; }
      var copies = getCopies ? getCopies() : 1;
      btn.disabled = true;
      var prev = btn.textContent;
      btn.textContent = 'Impression…';
      printRefs(refs, { copies: copies, code: getCode ? getCode() : 'qr' }).then(function () {
        btn.textContent = prev;
        btn.disabled = false;
      }).catch(function (err) {
        btn.textContent = prev;
        btn.disabled = false;
        alert(explainBleError(err));
      });
    });
  }

  global.FicheroPrint = {
    supported: supported,
    printRefs: printRefs,
    bindButton: bindButton,
    bindExportButton: bindExportButton,
    renderPreview: renderPreview
  };
})(window);
