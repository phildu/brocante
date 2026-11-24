/* Une pièce « passée » (menu sans valeur utilisable) n'arrête pas la file : elle avance, est notée, et le manque est signalé. */
const vm = require('vm'), fs = require('fs'), assert = require('assert'), path = require('path');
const dir = path.join(__dirname, '..');
const calls = [];
const store = { apiBase: 'https://x.test/s/api/annonces', token: 'am_x', amSettings: {}, amAutoSettings: { dryRun: false, keepTabs: false } };
const noop = () => {};
const removed = [];
const chrome = {
  storage: { local: { get: async (k) => { const o = {}; (Array.isArray(k) ? k : [k]).forEach((n) => (o[n] = store[n])); return o; }, set: async (o) => Object.assign(store, o), remove: noop } },
  alarms: { create: async (n, o) => { store.__alarm = { n, o }; }, get: async () => null, clear: noop, onAlarm: { addListener: noop } },
  runtime: { onMessage: { addListener: noop }, onInstalled: { addListener: noop }, onStartup: { addListener: noop } },
  tabs: { onRemoved: { addListener: noop }, onUpdated: { addListener: noop }, remove: async (id) => removed.push(id), update: noop },
  action: { setBadgeText: noop, setBadgeBackgroundColor: noop }, sidePanel: { setPanelBehavior: async () => {} }, scripting: {}, windows: {},
};
const fetch = async (url, o = {}) => { calls.push({ url, method: o.method || 'GET', body: o.body && JSON.parse(o.body) }); return { ok: true, status: 200, json: async () => ({ ok: true }) }; };
const ctx = { chrome, fetch, console, setTimeout, clearTimeout, Date, Math, JSON, URL, Promise, importScripts: (f) => vm.runInContext(fs.readFileSync(dir + '/' + f, 'utf8'), ctx) };
ctx.globalThis = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(dir + '/background.js', 'utf8') + ';this.__t={finalizeItem,actionLabel}', ctx);
const T = ctx.__t;

(async () => {
  assert.ok(/passée/.test(T.actionLabel('no-match')));
  store.amQueue = { status: 'running', channel: 'leboncoin', refs: ['006', '007'], index: 0, stats: {}, consecutiveErrors: 0, activeRef: '006' };
  await T.finalizeItem({ ref: '006' }, { action: 'no-match', reason: 'menu « Univers » : aucune entrée ne correspond' }, 42, { dryRun: false });
  const q = store.amQueue;
  assert.strictEqual(q.status, 'running', 'la file continue');
  assert.strictEqual(q.index, 1, 'on passe à la pièce suivante');
  assert.strictEqual(q.stats['no-match'], 1);
  assert.deepStrictEqual(removed, [42], 'l’onglet est refermé');
  assert.ok(store.__alarm && store.__alarm.n, 'la pièce suivante est programmée');
  const mark = calls.find((c) => /marquer/.test(c.url));
  assert.ok(mark && mark.body.product_ref === '006' && mark.body.status === 'draft' && /^no-match — /.test(mark.body.note), 'inscrite au portail comme non publiée');
  assert.ok(store.amAlert && /006/.test(store.amAlert.message), 'alerte affichée dans la popup');
  console.log('skip-test OK');
})().catch((e) => { console.error(e); process.exit(1); });
