/* Tests des règles de contrôle des fiches : `node tests/quality.test.js` depuis le dossier de l'extension. */
const assert = require('assert');
const Q = require('../quality.js');

const photos = (n) => Array.from({ length: n }, (_, i) => ({ url: 'https://x.test/p' + i + '.jpg' }));
const good = {
  title: 'Derbies plateforme Maria blanches', name: 'Derbies plateforme Maria blanches',
  description: 'Adoptez un look chic et tendance avec ces derbies plateforme blanches, en très bon état général.',
  price: 20, nature_label: 'Chaussures', subcategory_label: 'Mocassins et derbies', category_label: 'Accessoires',
  brand: 'Maria', size_text: '38', colors: 'blanc', photos: photos(6),
  condition: { leboncoin: { key: 'tres_bon', channel_label: 'Très bon état' }, vinted: { key: 'tres_bon', channel_label: 'Très bon état' } },
};
const fields = (r) => r.block.map((i) => i.field).concat(r.warn.map((i) => i.field));

// Fiche complète : rien à signaler.
assert.deepStrictEqual(Q.check(good, 'leboncoin'), { block: [], warn: [] });
assert.deepStrictEqual(Q.check(good, 'vinted'), { block: [], warn: [] });

// Prix absent, photos absentes, titre vide : bloquants.
let r = Q.check({ ...good, price: null, price_text: 'sur demande', photos: [], title: '', name: '' }, 'leboncoin');
assert.deepStrictEqual(r.block.map((i) => i.field).sort(), ['photos', 'prix', 'titre']);

// Leboncoin : prix décimal arrondi ; Vinted : pas d'avertissement.
assert.ok(fields(Q.check({ ...good, price: 22.4 }, 'leboncoin')).includes('prix'));
assert.ok(!fields(Q.check({ ...good, price: 22.4 }, 'vinted')).includes('prix'));

// Vêtements et chaussures sans taille : bloquant ; taille estimée : à vérifier.
r = Q.check({ ...good, size_text: '' }, 'vinted');
assert.deepStrictEqual(r.block.map((i) => i.field), ['taille']);
r = Q.check({ ...good, size_text: '38 (probable)' }, 'vinted');
assert.deepStrictEqual(r.block, []);
assert.ok(fields(r).includes('taille'));
// Un objet de décoration n'a pas besoin de taille.
assert.deepStrictEqual(Q.check({ ...good, nature_label: 'Décoration', subcategory_label: '', category_label: 'Maison', size_text: '15x15x5 cm (estimé)', brand: 'x', colors: '' }, 'vinted').block, []);

// État absent : jamais bloquant (repli « Bon état »), mais signalé sur les deux plateformes.
const noEtat = { ...good, condition: { leboncoin: { key: '', label: '', channel_label: '' }, vinted: { key: '', label: '', channel_label: '' } } };
for (const ch of ['vinted', 'leboncoin']) {
  const res = Q.check(noEtat, ch);
  assert.deepStrictEqual(res.block, []);
  assert.ok(res.warn.some((i) => i.field === 'état' && /Bon état/.test(i.message)));
}

// Forme du titre.
const titleWarn = (t) => Q.check({ ...good, title: t, name: t }, 'leboncoin').warn.filter((i) => i.field === 'titre').map((i) => i.message);
assert.ok(titleWarn('Veste courte violette en suédine - Vendeur').length >= 1);
assert.ok(titleWarn('ROBE NOIRE ET ROUGE MANCHES LONGUES').some((m) => /majuscules/.test(m)));
assert.ok(titleWarn('Robe  noire').some((m) => /Espaces/.test(m)));
assert.ok(titleWarn('Robe noire urgent').some((m) => /promotionnel/.test(m)));
assert.ok(titleWarn('Robe noire 20 euros pas chère').some((m) => /prix/i.test(m)));
assert.ok(titleWarn('Robe').some((m) => /court/.test(m)));
assert.ok(titleWarn('Robe noire -').length >= 1);
assert.deepStrictEqual(titleWarn('Derbies plateforme Maria blanches'), []);

// Coordonnées dans la description ; description longue.
assert.ok(fields(Q.check({ ...good, description: good.description + ' Appelez le 06 12 34 56 78' }, 'vinted')).includes('description'));
assert.ok(fields(Q.check({ ...good, description: good.description + ' voir www.exemple.fr' }, 'leboncoin')).includes('description'));
assert.ok(fields(Q.check({ ...good, description: 'x'.repeat(2100) }, 'vinted')).includes('description'));
assert.ok(!fields(Q.check({ ...good, description: 'x'.repeat(2100) }, 'leboncoin')).includes('description'));

// Peu de photos ; marque absente sur Vinted ; lot.
assert.ok(fields(Q.check({ ...good, photos: photos(2) }, 'leboncoin')).includes('photos'));
assert.ok(fields(Q.check({ ...good, brand: '' }, 'vinted')).includes('marque'));
assert.ok(!fields(Q.check({ ...good, brand: '' }, 'leboncoin')).includes('marque'));
assert.ok(fields(Q.check({ ...good, lot_qty: 3 }, 'vinted')).includes('lot'));

// mapped peut être un tableau vide (PHP) sans casser les règles.
assert.doesNotThrow(() => Q.check({ ...good, mapped: [] }, 'vinted'));

// Résumé lisible.
assert.strictEqual(Q.summary({ block: [], warn: [] }), '');
assert.strictEqual(Q.summary({ block: [{}, {}], warn: [{}] }), '⛔ 2 bloquants, ⚠ 1 à vérifier');
// Empreinte : stable, change avec le contenu, insensible aux espaces en trop.
const fp = Q.fingerprint(good, 'vinted');
assert.match(fp, /^[0-9a-f]{16}$/);
assert.strictEqual(Q.fingerprint({ ...good }, 'vinted'), fp);
assert.strictEqual(Q.fingerprint({ ...good, title: '  ' + good.title + '  ' }, 'vinted'), fp);
assert.notStrictEqual(Q.fingerprint({ ...good, price: Number(good.price) + 1 }, 'vinted'), fp);
assert.notStrictEqual(Q.fingerprint({ ...good, description: good.description + ' !' }, 'vinted'), fp);
assert.notStrictEqual(Q.fingerprint({ ...good, photos: photos(1) }, 'vinted'), fp);

// Prix proposé par la plateforme : fourchette, valeur seule, texte sans montant, rien.
const sp = (t) => Q.parseSuggestedPrice(t);
assert.deepStrictEqual({ ...sp('Prix suggéré : 8,50 € - 15 €') }.min, 8.5);
assert.strictEqual(sp('Prix suggéré : 8,50 € - 15 €').max, 15);
assert.strictEqual(sp('Prix recommandé\u00a010\u00a0€ à 25\u00a0€ selon l’état').max, 25);
assert.strictEqual(sp('Fourchette de prix : 1 200 € et 1 500 €').min, 1200);
assert.deepStrictEqual([sp('Estimation 12 €').min, sp('Estimation 12 €').max], [12, 12]);
assert.deepStrictEqual([sp('Prix suggéré bientôt disponible').min, sp('Prix suggéré bientôt disponible').max], [null, null]);
assert.ok(sp('Prix suggéré bientôt disponible').text.length > 0);
assert.strictEqual(sp('Ajoute des photos. Prix 20 €'), null);
assert.strictEqual(sp(''), null);
assert.strictEqual(sp('prix suggere 15 - 8 €').min, 8);
// Étiquette de note et relecture.
assert.strictEqual(Q.suggestionTag({ min: 8.5, max: 15 }), '[s:8.5-15] ');
assert.strictEqual(Q.suggestionTag({ min: null, max: null, text: 'x' }), '[s:?] ');
assert.strictEqual(Q.suggestionTag(null), '');
assert.deepStrictEqual(Q.suggestionFromNote('[h:0123456789abcdef] [s:8.5-15] published'), { min: 8.5, max: 15 });
assert.strictEqual(Q.suggestionFromNote('[s:?] prepared'), null);

// Couleur absente : Vinted l'exige, « Multicolore » est choisi par défaut et annoncé comme tel.
const noColor = { title: 'Robe fleurie été', nature_label: 'Robes', description: 'x'.repeat(50), price: 10, photos: [{ url: 'u' }], size_text: 'M' };
assert.ok(Q.check(noColor, 'vinted').warn.some((w) => w.field === 'couleur' && /Multicolore/.test(w.message)));
assert.ok(!Q.check(noColor, 'leboncoin').warn.some((w) => /Multicolore/.test(w.message)));

// Un objet rangé dans l'univers « Vêtements » d'une friperie n'est pas écarté pour la taille.
const saliere = { title: 'Duo de salières en bois tourné', category_label: 'Vêtements', description: 'x'.repeat(50), price: 8, photos: [{ url: 'u' }], size_text: '≈ 8 × 8 × 10 cm chacune (estimation)' };
assert.ok(!Q.check(saliere, 'vinted').block.some((i) => i.field === 'taille'), 'pas de blocage taille pour une salière');
assert.ok(!Q.check(saliere, 'vinted').warn.some((i) => i.field === 'taille' && /estim/i.test(i.message)), 'pas d’alerte « taille estimée » pour un objet');
const sansTaille = { ...saliere, title: 'Objet ancien', size_text: '' };
assert.ok(Q.check(sansTaille, 'vinted').warn.some((i) => i.field === 'taille'), 'catégorie large seule : simple avertissement');
assert.ok(!Q.check(sansTaille, 'vinted').block.some((i) => i.field === 'taille'));
// Nature précise « non vêtement » : aucun avertissement, même si la catégorie large dit « Vêtements ».
assert.ok(!Q.check({ ...sansTaille, nature_label: 'Arts de la table et cuisine' }, 'vinted').warn.some((i) => i.field === 'taille'));
// Vêtement reconnu par sa nature ou son titre : taille exigée comme avant.
assert.ok(Q.check({ title: 'Robe fleurie été', nature_label: 'Vêtements', description: 'x'.repeat(50), price: 10, photos: [{ url: 'u' }] }, 'vinted').block.some((i) => i.field === 'taille'));
assert.ok(Q.check({ title: 'Robe fleurie été', category_label: 'Vêtements', description: 'x'.repeat(50), price: 10, photos: [{ url: 'u' }] }, 'vinted').block.some((i) => i.field === 'taille'), 'mot du titre');

// Vinted : « Recommandation de prix » avec trois paliers.
const vintedText = 'Prix\n\nRecommandation de prix\nEn savoir plus\n\n2,00 €\n\nBonne affaire\n\n4,00 €\n\nPrix idéal\n\n5,00 €\n\nPremium\n\nVoir le prix de vente d’articles similaires';
const tiers = Q.parseSuggestedPrice(vintedText);
assert.strictEqual(tiers.min, 2); assert.strictEqual(tiers.max, 5); assert.strictEqual(tiers.ideal, 4);
// Écart entre le prix de la boutique et la fourchette proposée.
const gapHigh = Q.priceGap(10, tiers, 30);
assert.strictEqual(gapHigh.state, 'high'); assert.strictEqual(gapHigh.pct, 100); assert.ok(gapHigh.big);
assert.ok(!Q.priceGap(5.5, tiers, 30).big, '10 % au-dessus : pas un gros écart');
assert.strictEqual(Q.priceGap(3, tiers, 30).state, 'ok');
assert.ok(Q.priceGap(1, tiers, 30).big && Q.priceGap(1, tiers, 30).state === 'low', 'trop bas : signalé aussi');
assert.ok(Q.priceGap(5.5, tiers, 5).big, 'seuil réglable');
assert.strictEqual(Q.priceGap(10, null, 30), null); assert.strictEqual(Q.priceGap(10, { min: null, max: null }, 30), null);
console.log('quality.test.js : tous les tests passent');
