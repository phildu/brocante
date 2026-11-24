/* Recherche de catégorie : le titre prime pour un objet, la nature pour un vêtement (content/vinted.js et leboncoin.js). */
const vm = require('vm'), fs = require('fs'), assert = require('assert'), path = require('path');
const dir = path.join(__dirname, '..');
const noop = () => {};
const ctx = { chrome: { runtime: { onMessage: { addListener: noop }, sendMessage: noop } }, console, setTimeout, clearTimeout, Date, Math, JSON, Promise, sessionStorage: { getItem: () => null, setItem: noop } };
ctx.globalThis = ctx;
vm.createContext(ctx);
for (const f of ['quality.js', 'content/vinted.js', 'content/leboncoin.js']) vm.runInContext(fs.readFileSync(path.join(dir, f), 'utf8'), ctx);
const Q = ctx.AmQuality, V = ctx.AmVinted, L = ctx.AmLbc;
const list = (a) => Array.from(a).join('|');

// Mots du titre : l'objet d'abord, sans les mots vides.
assert.strictEqual(list(Q.titleWords({ title: 'Duo de salières en bois tourné' })), 'salières|bois|tourné');
assert.strictEqual(list(Q.titleWords({ title: 'Salière de table en verre' })), 'Salière|table|verre');
assert.strictEqual(list(Q.titleWords({ name: 'Belle théière ancienne' })), 'théière');

// Objet rangé dans un univers de vêtements, sans nature : on cherche « salière », pas la catégorie large.
const saliere = { title: 'Salière de table en verre', category_label: 'Robes & jupes' };
assert.strictEqual(Q.sizeNeed(saliere), 'maybe');
assert.strictEqual(list(V.categoryWords(saliere)).split('|')[0], 'Salière', 'Vinted : le titre d’abord');
assert.strictEqual(list(L.categoryWords(saliere)).split('|')[0], 'Salière', 'Leboncoin : le titre d’abord');

// Nature précise « non vêtement » : on la garde après les mots du titre.
const tasse = { title: 'Tasse en porcelaine', nature_label: 'Arts de la table et cuisine', subcategory_label: 'Tasses, bols et théières' };
assert.strictEqual(Q.sizeNeed(tasse), 'no');
assert.ok(list(V.categoryWords(tasse)).includes('Tasses'));

// Vêtement (nature ou titre) : la nature d'abord, comme avant.
const robe = { title: 'Robe fleurie été', nature_label: 'Vêtements', subcategory_label: 'Robes et jupes' };
assert.strictEqual(Q.sizeNeed(robe), 'yes');
assert.strictEqual(list(V.categoryWords(robe)).split('|')[0], 'Robes', 'vêtement : la nature précise d’abord, comme avant');
assert.strictEqual(Q.sizeNeed({ title: 'Perfecto cuir noir', category_label: 'Vêtements' }), 'yes', 'perfecto : vêtement reconnu par son titre');

console.log('category-test OK');
