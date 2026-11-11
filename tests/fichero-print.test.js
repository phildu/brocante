const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const script = fs.readFileSync(require('node:path').join(__dirname, '../assets/fichero-print.js'), 'utf8');

function setup(options = {}) {
  const calls = [];
  const alerts = [];
  const pixels = [];
  const shared = [];
  const urls = [];
  class Element {
    constructor(tag) {
      this.tag = tag;
      this.children = [];
      this.handlers = {};
      this.textContent = '';
    }
    setAttribute() {}
    appendChild(child) { this.children.push(child); }
    insertAdjacentElement(_where, child) { this.result = child; }
    addEventListener(type, handler) { this.handlers[type] = handler; }
    click() { return this.handlers.click(); }
    getContext() {
      return {
        createImageData: (w, h) => ({ data: new Uint8ClampedArray(w * h * 4) }),
        putImageData: data => pixels.push(Array.from(data.data))
      };
    }
    toBlob(callback) { callback(options.noBlob ? null : new Blob(['png'], { type: 'image/png' })); }
  }
  const characteristic = {
    properties: { write: true },
    writeValue: async () => {}
  };
  const service = {
    getCharacteristic: async uuid => uuid.includes('ff02') ? characteristic : { startNotifications: async () => {} }
  };
  const gatt = {
    connected: false,
    connect: async function () {
      this.connected = true;
      return { getPrimaryService: async () => service };
    },
    disconnect: function () { this.connected = false; calls.push('disconnect'); }
  };
  const navigator = {
    bluetooth: { requestDevice: () => { calls.push('requestDevice'); return Promise.resolve({ gatt }); } }
  };
  if (options.share) {
    navigator.canShare = ({ files }) => files.length === 1;
    navigator.share = data => { shared.push(data); return Promise.resolve(); };
  }
  const context = {
    window: { isSecureContext: true, APP_BASE: '/boutique' },
    navigator,
    document: { createElement: tag => new Element(tag) },
    fetch: async url => {
      calls.push('fetch');
      calls.push(url);
      return { json: async () => options.response || {
        ok: true, copies: 1,
        jobs: [{ ref: 'A1', width: 8, height: 1, raster: 'gQ==' }]
      } };
    },
    URL: {
      createObjectURL: blob => { const url = 'blob:' + urls.length; urls.push({ url, blob }); return url; },
      revokeObjectURL: url => calls.push('revoke:' + url)
    },
    File: class extends Blob {
      constructor(parts, name, opts) { super(parts, opts); this.name = name; }
    },
    Blob,
    Uint8Array,
    Uint8ClampedArray,
    Number,
    Promise,
    console,
    atob: value => Buffer.from(value, 'base64').toString('binary'),
    alert: message => alerts.push(message),
    setTimeout: callback => callback()
  };
  vm.runInNewContext(script, context);
  return { api: context.window.FicheroPrint, calls, alerts, pixels, shared, urls, Element, gatt };
}

test('Bluetooth est demandé pendant le clic, avant le chargement des étiquettes', async () => {
  const env = setup();
  const btn = new env.Element('button');
  env.api.bindButton(btn, () => ['A1'], () => 1);
  btn.click();
  assert.equal(env.calls[0], 'requestDevice');
  await new Promise(resolve => setImmediate(resolve));
  assert.ok(env.calls.indexOf('fetch') > env.calls.indexOf('requestDevice'));
  assert.ok(env.calls.includes('disconnect'));
  assert.equal(btn.disabled, false);
  assert.deepEqual(env.alerts, []);
});

test('une erreur de préparation déconnecte l’imprimante et reste visible', async () => {
  const env = setup({ response: { ok: false, error: 'Session expirée' } });
  await assert.rejects(env.api.printRefs(['A1']), /Session expirée/);
  assert.equal(env.gatt.connected, false);
  assert.ok(env.calls.includes('disconnect'));
});

test('plus de 50 pièces sont refusées avant la connexion', async () => {
  const env = setup();
  await assert.rejects(env.api.printRefs(Array.from({ length: 51 }, (_, i) => String(i))), /50 pièces/);
  assert.deepEqual(env.calls, []);
});

test('export PNG respecte les pixels noirs et blancs et partage au second clic', async () => {
  const env = setup({ share: true });
  const btn = new env.Element('button');
  env.api.bindExportButton(btn, () => ['A1']);
  await btn.click();
  assert.deepEqual(env.pixels[0], [
    0, 0, 0, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255,
    255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 255, 0, 0, 0, 255
  ]);
  const row = btn.result.children[1];
  assert.equal(row.children[0].download, 'etiquette-A1.png');
  assert.equal(row.children[0].href, 'blob:0');
  assert.equal(env.shared.length, 0);
  row.children[1].click();
  assert.equal(env.shared[0].files[0].name, 'etiquette-A1.png');
  await btn.click();
  assert.ok(env.calls.includes('revoke:blob:0'));
});

test('export conserve le téléchargement sans partage et signale les données invalides', async () => {
  const env = setup();
  const btn = new env.Element('button');
  env.api.bindExportButton(btn, () => ['A1']);
  await btn.click();
  assert.equal(btn.result.children[1].children.length, 1);
  const invalid = setup({ response: { ok: true, jobs: [{ ref: 'A1', width: 8, height: 2, raster: 'gQ==' }] } });
  const badBtn = new invalid.Element('button');
  invalid.api.bindExportButton(badBtn, () => ['A1']);
  await badBtn.click();
  assert.match(invalid.alerts[0], /Étiquette invalide/);
  assert.equal(badBtn.disabled, false);
});

test('export de plusieurs pièces produit une image distincte par référence', async () => {
  const env = setup({ response: { ok: true, jobs: [
    { ref: 'A1', width: 8, height: 1, raster: 'gQ==' },
    { ref: 'B2', width: 8, height: 1, raster: 'AA==' }
  ] } });
  const btn = new env.Element('button');
  env.api.bindExportButton(btn, () => ['A1', 'B2']);
  await btn.click();
  assert.equal(btn.result.children[1].children[0].download, 'etiquette-A1.png');
  assert.equal(btn.result.children[2].children[0].download, 'etiquette-B2.png');
  assert.equal(env.urls.length, 2);
  assert.notDeepEqual(env.pixels[0], env.pixels[1]);
});
