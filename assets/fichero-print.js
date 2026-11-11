// Impression d'étiquettes QR sur une Fichero D11s (Bluetooth du navigateur).
// Chrome / Edge / Opera : Web Bluetooth. Safari et Firefox ne savent pas.
(function (global) {
  'use strict';

  var SERVICE = '0000ff00-0000-1000-8000-00805f9b34fb';
  var WRITE = '0000ff02-0000-1000-8000-00805f9b34fb';
  var NOTIFY = '0000ff01-0000-1000-8000-00805f9b34fb';
  var CHUNK = 180;

  function base() { return global.APP_BASE || ''; }
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function supported() { return typeof navigator !== 'undefined' && !!navigator.bluetooth; }

  function b64ToBytes(b64) {
    var bin = atob(b64);
    var out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function concat() {
    var total = 0, i, j, off = 0, out;
    for (i = 0; i < arguments.length; i++) total += arguments[i].length;
    out = new Uint8Array(total);
    for (i = 0; i < arguments.length; i++) {
      out.set(arguments[i], off);
      off += arguments[i].length;
    }
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
    for (var i = 0; i < data.length; i += CHUNK) {
      var slice = data.slice(i, i + CHUNK);
      if (char.writeValueWithoutResponse) await char.writeValueWithoutResponse(slice);
      else await char.writeValue(slice);
      await sleep(20);
    }
  }

  async function connect() {
    if (!supported()) {
      throw new Error('Ouvrez cette page dans Chrome pour imprimer sur l’étiqueteuse Fichero.');
    }
    var device = await navigator.bluetooth.requestDevice({
      filters: [
        { namePrefix: 'FICHERO' },
        { namePrefix: 'Fichero' },
        { namePrefix: 'D11s' }
      ],
      optionalServices: [SERVICE]
    });
    var server = await device.gatt.connect();
    var service = await server.getPrimaryService(SERVICE);
    var char = await service.getCharacteristic(WRITE);
    try {
      var n = await service.getCharacteristic(NOTIFY);
      await n.startNotifications();
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
    await sleep(50);
    await writeChunks(char, concat(header(height), raster));
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
        alert(err && err.message ? err.message : 'Impression impossible.');
      });
    });
  }

  global.FicheroPrint = {
    supported: supported,
    printRefs: printRefs,
    bindButton: bindButton
  };
})(window);
