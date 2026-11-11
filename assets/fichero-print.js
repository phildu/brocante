// Impression d'étiquettes QR sur une Fichero D11s (Bluetooth du navigateur).
// Chrome / Edge / Opera : Web Bluetooth. Safari et Firefox ne savent pas.
(function (global) {
  'use strict';

  var SERVICE = '0000ff00-0000-1000-8000-00805f9b34fb';
  var WRITE = '0000ff02-0000-1000-8000-00805f9b34fb';
  var NOTIFY = '0000ff01-0000-1000-8000-00805f9b34fb';
  var NOTIFY_FF03 = '0000ff03-0000-1000-8000-00805f9b34fb';
  var CHUNK = 20;

  function base() { return global.APP_BASE || ''; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function isChrome() {
    var ua = navigator.userAgent || '';
    return /Chrome\//.test(ua) && !/Edg\/|OPR\/|SamsungBrowser/.test(ua);
  }
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
      return 'Chrome voit l’imprimante mais n’arrive pas à s’y brancher (souvent déjà liée au Mac ou à un autre programme).\n\n'
        + '1. Réglages Mac → Bluetooth : oublier « FICHERO » si elle y figure, et ne pas la reconnecter là.\n'
        + '2. Éteindre l’imprimante 5 secondes, puis la rallumer (le voyant clignote).\n'
        + '3. Dans Chrome, coller chrome://settings/content/bluetoothDevices — retirer ce site.\n'
        + '4. Recharger la page, recliquer le bouton, choisir l’imprimante dès qu’elle apparaît.';
    }
    return msg || 'Impression impossible.';
  }

  async function gattConnect(device) {
    var last = null;
    var attempt;
    for (attempt = 0; attempt < 3; attempt++) {
      try {
        if (device.gatt.connected) {
          try { device.gatt.disconnect(); } catch (e) {}
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
    if (!isChrome()) {
      throw new Error('Ouvrez cette page dans Google Chrome (pas Safari) pour imprimer sur l’étiqueteuse Fichero.');
    }
    if (!window.isSecureContext) {
      throw new Error('Chrome n’autorise le Bluetooth que sur une page https (cadenas dans la barre d’adresse).');
    }
    if (!navigator.bluetooth) {
      throw new Error('Bluetooth indisponible dans ce Chrome. Activez-le dans chrome://flags si besoin.');
    }
    var device = await navigator.bluetooth.requestDevice({
      filters: [
        { namePrefix: 'FICHERO' },
        { namePrefix: 'Fichero' },
        { namePrefix: 'D11s' },
        { namePrefix: 'D11' }
      ],
      optionalServices: [SERVICE]
    });
    var server = await gattConnect(device);
    var service = await server.getPrimaryService(SERVICE);
    var char = await service.getCharacteristic(WRITE);
    try {
      var n = await service.getCharacteristic(NOTIFY);
      await n.startNotifications();
    } catch (e) {}
    try {
      var n3 = await service.getCharacteristic(NOTIFY_FF03);
      await n3.startNotifications();
    } catch (e) {}
    return { device: device, char: char };
  }

  async function printJob(char, job) {
    var height = job.height || 240;
    var raster = b64ToBytes(job.raster);
    await writeChunks(char, new Uint8Array([0x10, 0xff, 0x10, 0x00, 0x01]));
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

  async function fetchJobs(refs, copies) {
    var url = base() + '/admin/fichero-print.php?refs=' + encodeURIComponent(refs.join(',')) + '&copies=' + encodeURIComponent(copies || 1);
    var res = await fetch(url, { credentials: 'same-origin' });
    var data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Préparation des étiquettes impossible.');
    return data;
  }

  async function printRefs(refs, opts) {
    opts = opts || {};
    var copies = Math.max(1, Math.min(20, parseInt(opts.copies, 10) || 1));
    if (!refs || !refs.length) throw new Error('Aucune pièce à imprimer.');
    try {
      var data = await fetchJobs(refs, copies);
      var printer = await connect();
      try {
        for (var c = 0; c < (data.copies || copies); c++) {
          for (var i = 0; i < data.jobs.length; i++) {
            await printJob(printer.char, data.jobs[i]);
          }
        }
      } finally {
        try { printer.device.gatt.disconnect(); } catch (e) {}
      }
    } catch (e) {
      throw new Error(explainBleError(e));
    }
  }

  function bindButton(btn, getRefs, getCopies) {
    if (!btn) return;
    btn.addEventListener('click', function () {
      var refs = getRefs() || [];
      if (!refs.length) { alert('Cochez au moins une pièce.'); return; }
      var copies = getCopies ? getCopies() : 1;
      btn.disabled = true;
      var prev = btn.textContent;
      btn.textContent = 'Impression…';
      printRefs(refs, { copies: copies }).then(function () {
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
    bindButton: bindButton
  };
})(window);
