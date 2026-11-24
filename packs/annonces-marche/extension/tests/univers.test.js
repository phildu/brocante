/* Choix de l'« Univers » Leboncoin d'après la liste réelle du site (content/leboncoin.js). */
const vm = require('vm'), fs = require('fs'), assert = require('assert'), path = require('path');
const dir = path.join(__dirname, '..');
const noop = () => {};
const ctx = { chrome: { runtime: { onMessage: { addListener: noop }, sendMessage: noop } }, console, setTimeout, clearTimeout, Date, Math, JSON, Promise, sessionStorage: { getItem: () => null, setItem: noop } };
ctx.globalThis = ctx;
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(dir + '/content/leboncoin.js', 'utf8'), ctx);
const { universChoice } = ctx.AmLbc;

const MODE = ['Femme', 'Homme', 'Enfant'];
const MIXTE = ['Femme', 'Homme', 'Enfant', 'Maison'];

// Mode : Femme / Homme comme avant.
assert.strictEqual(universChoice({ title: 'Robe fleurie', nature_label: 'Robes' }, MODE), 'Femme');
assert.strictEqual(universChoice({ title: 'Chemise homme à carreaux', nature_label: 'Chemises' }, MODE), 'Homme');
// Sans aucune catégorie connue : comportement historique (Femme).
assert.strictEqual(universChoice({ title: 'Pièce sans catégorie' }, MODE), 'Femme');

// Hors mode : l'entrée la plus proche, jamais « Femme » par défaut.
assert.strictEqual(universChoice({ title: 'Panier en osier', nature_label: 'Paniers' }, MIXTE), 'Maison');
assert.strictEqual(universChoice({ title: 'Vase en grès', nature_label: 'Vases' }, ['Mode', 'Maison & Jardin', 'Loisirs']), 'Maison & Jardin');
assert.strictEqual(universChoice({ title: 'Panier en osier', nature_label: 'Paniers' }, MODE), '', 'rien de proche : la pièce sera passée');

// Égalité ou liste vide : on ne devine pas.
assert.strictEqual(universChoice({ title: 'Objet', nature_label: 'Objets' }, ['Maison', 'Jardin']), '');
assert.strictEqual(universChoice({ title: 'Panier', nature_label: 'Paniers' }, []), '');

// La valeur imposée par la table de correspondances l'emporte, si le site la propose.
const forced = { title: 'Robe fleurie', nature_label: 'Robes', mapped: { leboncoin: { autre: { univers: 'Enfant' } } } };
assert.strictEqual(universChoice(forced, MODE), 'Enfant');
assert.strictEqual(universChoice({ ...forced, mapped: { leboncoin: { autre: { univers: 'Absent' } } } }, MODE), 'Femme', 'valeur imposée absente de la liste : retour aux règles');

console.log('univers-test OK');
