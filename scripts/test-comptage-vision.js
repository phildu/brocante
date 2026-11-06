// Tests du suivi de personnes et du franchissement de ligne (comptage par caméra).
//   node scripts/test-comptage-vision.js
// Trajectoires simulées : aucune caméra ni modèle nécessaires.

const { LineCounter } = require('../assets/comptage-vision.js');

let failures = 0, checks = 0;
function check(label, actual, expected) {
  checks++;
  const ok = JSON.stringify(actual) === JSON.stringify(expected);
  if (!ok) { failures++; console.log(`  ✗ ${label}\n      attendu : ${JSON.stringify(expected)}\n      obtenu  : ${JSON.stringify(actual)}`); }
  else console.log(`  ✓ ${label}`);
}

// Une « personne » : fonction du temps (ms) -> centre {x, y} ou null (hors champ / masquée).
const H = 0.3, W = 0.12;
const person = (path) => ({ path });
const box = (c) => ({ x: c.x - W / 2, y: c.y - H / 2, w: W, h: H });
const walk = (x0, x1, y, t0, durMs) => (t) => (t < t0 || t > t0 + durMs) ? null : { x: x0 + (x1 - x0) * (t - t0) / durMs, y };

/** Rejoue une scène à `fps` images par seconde ; renvoie tous les événements. */
function run(counter, people, endMs, { fps = 10, jitter = 0, seed = 7, miss = () => false } = {}) {
  let s = seed;
  const rnd = () => { s = (s * 16807) % 2147483647; return s / 2147483647 - 0.5; };
  const events = [];
  for (let t = 0; t <= endMs; t += 1000 / fps) {
    const dets = [];
    people.forEach((p, i) => {
      const c = p.path(t);
      if (!c || miss(i, t)) return;
      dets.push(box({ x: c.x + rnd() * jitter, y: c.y + rnd() * jitter }));
    });
    events.push(...counter.update(dets, t));
  }
  return events;
}
const dirs = (ev) => ev.map(e => e.dir);
const newCounter = (o = {}) => new LineCounter(Object.assign({ aspect: 4 / 3 }, o));
// Ligne par défaut : verticale au milieu, tracée vers le haut -> gauche→droite = entrée.

console.log('Sens de passage');
check('gauche → droite = 1 entrée', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500)), [1]);
check('droite → gauche = 1 sortie', dirs(run(newCounter(), [person(walk(0.9, 0.1, 0.5, 0, 3000))], 3500)), [-1]);
check('sens inversé par réglage', dirs(run(newCounter({ invert: true }), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500)), [-1]);
check('ligne tracée dans l\'autre sens = sens inverse',
  dirs(run(newCounter({ line: { x1: 0.5, y1: 0.1, x2: 0.5, y2: 0.9 } }), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500)), [-1]);
check('ligne horizontale tracée vers la droite : le côté positif est en bas, haut → bas = entrée',
  dirs(run(newCounter({ line: { x1: 0.1, y1: 0.5, x2: 0.9, y2: 0.5 } }), [person((t) => t > 3000 ? null : { x: 0.5, y: 0.15 + 0.7 * t / 3000 })], 3500)), [1]);

console.log('\nPlusieurs personnes');
check('deux personnes de front, même sens', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.35, 0, 3000)), person(walk(0.1, 0.9, 0.7, 0, 3000))], 3500)), [1, 1]);
check('deux personnes qui se croisent en sens opposés', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.4, 0, 3000)), person(walk(0.9, 0.1, 0.6, 0, 3000))], 3500)).sort(), [-1, 1]);
check('file de 5 personnes espacées d\'une seconde', dirs(run(newCounter(), [0, 1, 2, 3, 4].map(i => person(walk(0.1, 0.9, 0.5, i * 1000, 3000))), 8500)), [1, 1, 1, 1, 1]);
const mixed = run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000)), person(walk(0.9, 0.1, 0.5, 4000, 3000)), person(walk(0.1, 0.9, 0.5, 8000, 3000))], 12000);
check('entrée, sortie, entrée', dirs(mixed), [1, -1, 1]);

console.log('\nRobustesse');
check('tremblement de la détection : un seul comptage', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { jitter: 0.012 })), [1]);
check('tremblement fort : jamais de doublon', run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { jitter: 0.03 }).length <= 1, true);
check('personne qui s\'arrête sur la ligne puis repart du même côté : 0',
  dirs(run(newCounter(), [person((t) => t < 2000 ? { x: 0.1 + 0.39 * t / 2000, y: 0.5 } : t < 4000 ? { x: 0.49 + 0.01 * Math.sin(t / 90), y: 0.5 } : t < 6000 ? { x: 0.49 - 0.39 * (t - 4000) / 2000, y: 0.5 } : null)], 6500)), []);
check('personne qui hésite : va-et-vient sur la ligne = 1 entrée puis 1 sortie',
  dirs(run(newCounter(), [person((t) => t < 2000 ? { x: 0.2 + 0.5 * t / 2000, y: 0.5 } : t < 4000 ? { x: 0.7 - 0.5 * (t - 2000) / 2000, y: 0.5 } : null)], 4500)), [1, -1]);
check('détection manquée pendant 4 images au milieu : compté une fois',
  dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { miss: (i, t) => t >= 1300 && t <= 1600 })), [1]);
check('détection manquée sur la ligne même : compté une fois',
  dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { miss: (i, t) => t >= 1300 && t <= 1900 })), [1]);
check('fausse détection d\'une image : ignorée', dirs(run(newCounter(), [person((t) => t === 1000 ? { x: 0.5, y: 0.5 } : null)], 2000)), []);
check('fausse détection de 2 images de part et d\'autre : ignorée', dirs(run(newCounter(), [person((t) => t >= 1000 && t <= 1100 ? { x: 0.3 + 0.4 * (t - 1000) / 100, y: 0.5 } : null)], 2000)), []);
check('passe au-delà de l\'extrémité de la ligne : non compté',
  dirs(run(newCounter({ line: { x1: 0.5, y1: 0.9, x2: 0.5, y2: 0.55 } }), [person(walk(0.1, 0.9, 0.2, 0, 3000))], 3500)), []);
check('passe juste au-delà de l\'extrémité (dans la tolérance) : compté',
  dirs(run(newCounter({ line: { x1: 0.5, y1: 0.9, x2: 0.5, y2: 0.55 } }), [person(walk(0.1, 0.9, 0.54, 0, 3000))], 3500)), [1]);
check('personne qui marche le long de la ligne sans la couper : 0', dirs(run(newCounter(), [person(walk2(0.4, 0.15, 0.85))], 3500)), []);
function walk2(x, y0, y1) { return (t) => t > 3000 ? null : { x, y: y0 + (y1 - y0) * t / 3000 }; }

console.log('\nCadence');
check('5 images/s (téléphone lent) : compté', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { fps: 5 })), [1]);
check('15 images/s', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { fps: 15 })), [1]);
check('marche rapide (1,2 s pour traverser)', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 1200))], 2000, { fps: 10 })), [1]);

console.log('\nCadence faible et allers-retours rapides');
check('2,5 images/s (téléphone très lent) : compté', dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500, { fps: 2.5 })), [1]);
check('retour en arrière moins d\'une seconde après le passage : un seul comptage',
  dirs(run(newCounter(), [person((t) => t < 800 ? { x: 0.3 + 0.4 * t / 800, y: 0.5 } : t < 1600 ? { x: 0.7 - 0.4 * (t - 800) / 800, y: 0.5 } : null)], 2200)), [1]);
check('boîte qui saute d\'un côté à l\'autre sans que la personne bouge : 0',
  dirs(run(newCounter(), [person((t) => t > 4000 ? null : { x: Math.round(t / 100) % 2 ? 0.4 : 0.6, y: 0.5 })], 4500)), []);
check('personne immobile près de la ligne : 0', dirs(run(newCounter(), [person((t) => ({ x: 0.52, y: 0.5 }))], 5000)), []);
check('deux personnes proches qui se suivent à 0,6 s : deux comptages',
  dirs(run(newCounter(), [person(walk(0.1, 0.9, 0.5, 0, 3000)), person(walk(0.1, 0.9, 0.5, 600, 3000))], 4500)), [1, 1]);

console.log('\nIdentités');
check('deux personnes qui se croisent au niveau de la ligne gardent leur sens',
  dirs(run(newCounter(), [person(walk(0.2, 0.8, 0.45, 0, 3000)), person(walk(0.8, 0.2, 0.55, 0, 3000))], 3500)).sort(), [-1, 1]);
const c = newCounter();
run(c, [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500);
check('totaux du compteur', c.counts, { in: 1, out: 0 });
c.update([], 6000);
check('pistes abandonnées après disparition', c.tracks.length, 0);

console.log('\nRéglage de la ligne');
const moved = newCounter();
// la ligne est déplacée pendant qu'une personne se tient entre l'ancienne et la nouvelle position
const evs = [];
for (let t = 0; t <= 1500; t += 100) evs.push(...moved.update([box({ x: 0.55, y: 0.5 })], t));
moved.setLine({ x1: 0.6, y1: 0.9, x2: 0.6, y2: 0.1 });
for (let t = 1600; t <= 3000; t += 100) evs.push(...moved.update([box({ x: 0.55, y: 0.5 })], t));
check('déplacer la ligne ne fabrique aucun passage', evs.length, 0);
moved.reset();
check('remise à zéro', [moved.counts, moved.tracks.length], [{ in: 0, out: 0 }, 0]);
check('format d\'image : à 16/9 comme à 4/3, même résultat',
  [4 / 3, 16 / 9, 9 / 16].map(a => dirs(run(newCounter({ aspect: a }), [person(walk(0.1, 0.9, 0.5, 0, 3000))], 3500)).join()).join('|'), '1|1|1');

console.log('\n' + (failures ? `ÉCHEC : ${failures} / ${checks}` : `OK : ${checks} vérifications`));
process.exit(failures ? 1 : 0);
