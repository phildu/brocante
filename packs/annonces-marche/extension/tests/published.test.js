/* Détection de la page de confirmation après publication (content/fill.js). */
const vm = require('vm'), fs = require('fs'), assert = require('assert'), path = require('path');
const src = fs.readFileSync(path.join(__dirname, '..', 'content/fill.js'), 'utf8');
function detect(url, text) {
  const u = new URL(url);
  const ctx = { console, location: { href: url, hostname: u.hostname, pathname: u.pathname, search: u.search }, document: { body: { innerText: text } }, chrome: {} };
  ctx.globalThis = ctx; vm.createContext(ctx); vm.runInContext(src, ctx);
  return ctx.AmFill.detectPublished();
}
assert.ok(detect('https://www.vinted.fr/member/251069429?promo_shown=true', 'Article en vente\nPlus tu as d’articles, plus tu vends !\nAjouter un autre article\nPlus tard'), 'profil Vinted + fenêtre « Article en vente »');
assert.ok(detect('https://www.vinted.fr/member/251069429-phildu1', 'Article en vente Ajouter un autre article'), 'fenêtre seule sur le profil');
assert.ok(detect('https://www.vinted.fr/items/123-robe', ''), 'page article (déjà géré)');
assert.ok(!detect('https://www.vinted.fr/member/251069429-phildu1', 'Vends tes articles Modifier mon profil'), 'simple visite du profil : pas une publication');
assert.ok(!detect('https://www.vinted.fr/items/new', 'Ajouter un article'), 'formulaire de dépôt : pas une publication');

// Aides communes : identifiant d'annonce et repérage par le titre.
function helpers() {
  const ctx = { console, location: { href: '', hostname: 'www.vinted.fr', pathname: '/', search: '' }, document: { body: { innerText: '' } }, chrome: {} };
  ctx.globalThis = ctx; vm.createContext(ctx); vm.runInContext(src, ctx);
  return ctx.AmFill;
}
const H = helpers();
assert.strictEqual(H.idFromUrl('https://www.vinted.fr/items/10926219794-panier-decoratif'), '10926219794');
assert.strictEqual(H.idFromUrl('/ad/chaussures/3292826227'), '3292826227');
assert.strictEqual(H.idFromUrl('https://www.leboncoin.fr/annonce/3292826227/editer'), '3292826227');
assert.strictEqual(H.idFromUrl('https://www.vinted.fr/member/251069429?promo_shown=true'), '');
assert.ok(H.titleMatches('Panier décoratif métal doré feuilles, marque: Inconnu, état: Très bon', 'Panier décoratif métal doré feuilles'));
assert.ok(!H.titleMatches('Robe asymétrique noire et rouge', 'Panier décoratif métal doré feuilles'));
assert.ok(!H.titleMatches('n’importe quoi', 'Vase'), 'titre trop court : pas de correspondance hasardeuse');
console.log('published-test OK');
