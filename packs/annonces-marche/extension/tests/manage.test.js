/* Annonces en ligne : états face à la boutique, mise à jour et suppression (service worker). */
const vm = require('vm'), fs = require('fs'), assert = require('assert'), path = require('path');
const dir = path.join(__dirname, '..');
const noop = () => {};
const calls = [], removedTabs = [];
const store = { apiBase: 'https://x.test/s/api/annonces', token: 'am_x', amSettings: {}, amAutoSettings: { armed: true, dryRun: false } };
const products = { 1: { ref: '1', title: 'Robe rouge fleurie', description: 'Belle robe', price: 12, photos: [{ url: 'u' }] },
  2: { ref: '2', title: 'Panier en osier', description: 'Joli panier', price: 9, photos: [{ url: 'u' }] } };
let sent = [];
const chrome = {
  storage: { local: { get: async (k) => { const o = {}; (Array.isArray(k) ? k : [k]).forEach((n) => (o[n] = store[n])); return o; }, set: async (o) => Object.assign(store, o), remove: noop } },
  alarms: { create: noop, get: async () => null, clear: noop, onAlarm: { addListener: noop } },
  runtime: { onMessage: { addListener: noop }, onInstalled: { addListener: noop }, onStartup: { addListener: noop } },
  tabs: { onRemoved: { addListener: noop }, onUpdated: { addListener: noop }, create: async () => ({ id: 7 }), remove: async (id) => removedTabs.push(id), update: noop,
    sendMessage: async (_id, msg) => { sent.push(msg); if (msg.type === 'am-auto-ping') return { ready: true, channel: 'vinted' }; if (msg.type === 'am-manage-step') return { ok: true, action: 'submitted' }; if (msg.type === 'am-manage-verify') return { ok: true, done: true }; return {}; } },
  action: { setBadgeText: noop, setBadgeBackgroundColor: noop }, sidePanel: { setPanelBehavior: async () => {} }, scripting: {}, windows: {},
};
let ctx;
const fetch = async (url, o = {}) => {
  calls.push({ url, method: o.method || 'GET', body: o.body && JSON.parse(o.body) });
  const hash1 = ctx.AmQuality.fingerprint(products[1], 'vinted');
  if (/publications/.test(url)) return { ok: true, status: 200, json: async () => ({ listings: [
    { ref: '1', note: '[h:' + hash1 + '] published', listing_url: 'https://www.vinted.fr/items/123456789-robe', external_id: '' },
    { ref: '2', note: '[h:0000000000000000] published', listing_url: '', external_id: '987654321' },
    { ref: '3', note: '[h:1111111111111111] published', listing_url: 'https://www.vinted.fr/items/55555555-x', external_id: '' },
  ] }) };
  const m = /produit\/(\w+)/.exec(url);
  if (m) return products[m[1]] ? { ok: true, status: 200, json: async () => ({ product: products[m[1]] }) } : { ok: false, status: 404, json: async () => ({ error: 'Produit introuvable ou indisponible.' }) };
  return { ok: true, status: 200, json: async () => ({ ok: true }) };
};
ctx = { chrome, fetch, console, setTimeout, clearTimeout, Date, Math, JSON, URL, Promise, importScripts: (f) => vm.runInContext(fs.readFileSync(dir + '/' + f, 'utf8'), ctx) };
ctx.globalThis = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(dir + '/background.js', 'utf8') + ';this.__t={listOnline,manageListing,listingIdFromUrl}', ctx);
const T = ctx.__t;

(async () => {
  assert.strictEqual(T.listingIdFromUrl('https://www.vinted.fr/items/123456789-robe'), '123456789');
  assert.strictEqual(T.listingIdFromUrl('https://www.leboncoin.fr/ad/chaussures/3292826227'), '3292826227');
  assert.strictEqual(T.listingIdFromUrl('https://www.vinted.fr/member/251069429'), '');

  const items = await T.listOnline('vinted');
  const byRef = Object.fromEntries(items.map((i) => [i.ref, i]));
  assert.strictEqual(byRef['1'].state, 'same');
  assert.strictEqual(byRef['2'].state, 'changed', 'empreinte différente : fiche modifiée');
  assert.strictEqual(byRef['3'].state, 'gone', 'pièce absente de la boutique');
  assert.strictEqual(byRef['1'].external_id, '123456789', 'identifiant tiré de l’adresse');
  assert.strictEqual(items.map((i) => i.ref).join(','), '2,3,1', 'modifiées, puis retirées, puis à jour');

  // Mise à jour réussie : l'annonce est ouverte à l'adresse d'édition, puis la fiche est marquée à jour.
  calls.length = 0; sent = [];
  const upd = await T.manageListing('vinted', '2', 'update');
  assert.strictEqual(upd.action, 'done', JSON.stringify(upd));
  const step = sent.find((m) => m.type === 'am-manage-step');
  assert.strictEqual(step.kind, 'update'); assert.strictEqual(step.target.id, '987654321'); assert.strictEqual(step.dryRun, false);
  const mark = calls.find((c) => /marquer/.test(c.url));
  assert.strictEqual(mark.body.status, 'published'); assert.ok(/^\[h:[0-9a-f]{16}\] updated/.test(mark.body.note));
  assert.deepStrictEqual(removedTabs, [7]);

  // Suppression d'une pièce retirée de la boutique : repérée par son identifiant, marquée « removed ».
  calls.length = 0; sent = [];
  const del = await T.manageListing('vinted', '3', 'delete');
  assert.strictEqual(del.action, 'done');
  assert.strictEqual(sent.find((m) => m.type === 'am-manage-step').target.id, '55555555');
  assert.strictEqual(calls.find((c) => /marquer/.test(c.url)).body.status, 'removed');

  // Mise à jour d'une pièce qui n'existe plus : refusée, rien n'est ouvert.
  calls.length = 0; sent = [];
  const gone = await T.manageListing('vinted', '3', 'update');
  assert.strictEqual(gone.action, 'not-found');
  assert.ok(!sent.length && !calls.some((c) => /marquer/.test(c.url)));

  // Simulation : on s'arrête avant le clic final, rien n'est marqué.
  store.amAutoSettings = { armed: true, dryRun: true };
  chrome.tabs.sendMessage = async (_id, msg) => { sent.push(msg); if (msg.type === 'am-auto-ping') return { ready: true, channel: 'vinted' }; return { ok: true, action: 'prepared', reason: 'prête (simulation)' }; };
  calls.length = 0;
  const sim = await T.manageListing('vinted', '3', 'delete');
  assert.strictEqual(sim.action, 'prepared');
  assert.ok(!calls.some((c) => /marquer/.test(c.url)), 'simulation : l’annonce n’est pas marquée supprimée');
  console.log('manage-test OK');
})().catch((e) => { console.error(e); process.exit(1); });
