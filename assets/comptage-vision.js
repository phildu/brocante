/* Comptage par caméra — suivi des personnes et franchissement d'une ligne virtuelle.
 *
 * Module sans dépendance ni accès au navigateur (testé sous Node :
 * scripts/test-comptage-vision.js). Il reçoit, image après image, les boîtes
 * de personnes détectées et renvoie les franchissements de la ligne, avec leur
 * sens. La détection elle-même (MediaPipe) et la caméra sont dans comptage.js.
 *
 * Principe :
 *  1. chaque détection est rattachée à une « piste » (une personne suivie) en
 *     comparant sa position à celle prédite de chaque piste (vitesse constante) ;
 *  2. pour chaque piste, on retient de quel côté de la ligne elle se trouve,
 *     mais seulement quand elle est nettement d'un côté (bande morte autour de
 *     la ligne) : le tremblement de la détection ne peut donc pas compter deux fois ;
 *  3. une piste qui passe nettement d'un côté à l'autre, au niveau du segment
 *     tracé, déclenche un comptage : +1 « entrée » ou −1 « sortie » selon le sens.
 *
 * Coordonnées : normalisées (0..1) sur l'image, x vers la droite, y vers le bas.
 * Les distances tiennent compte du format de l'image (aspect = largeur / hauteur).
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.CpVision = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  var DEFAULTS = {
    aspect: 4 / 3,          // largeur / hauteur de l'image
    invert: false,          // false : arriver du côté « positif » de la ligne = entrée (voir locate)
    margin: 0.04,           // demi-largeur de la bande morte, en hauteurs d'image
    minHits: 3,             // images consécutives avant qu'une piste puisse compter
    maxMissingMs: 1000,     // une piste non revue pendant ce temps est abandonnée
    segmentSlack: 0.08,     // tolérance au-delà des extrémités de la ligne (fraction de sa longueur)
    smoothing: 0.6,         // lissage de la position (1 = aucune mémoire)
    cooldownMs: 1200,       // une même piste ne compte pas deux fois en moins de ce temps (tremblement, confusion d'identité)
    historyLen: 8,          // positions gardées pour vérifier le sens réel du déplacement
    minApproach: 0.5,       // le déplacement vers l'autre côté doit atteindre ce multiple de la bande morte
    gate: 0.5,              // distance maximale d'association, en hauteurs de personne (à 10 images/s)
    gateGrowth: 2           // la tolérance peut grandir jusqu'à ce multiple quand des images manquent
  };

  function LineCounter(options) {
    var o = options || {};
    this.opts = {};
    for (var k in DEFAULTS) this.opts[k] = o[k] !== undefined ? o[k] : DEFAULTS[k];
    // Ligne verticale tracée de bas en haut : traverser l'image de gauche à droite = entrée.
    this.line = o.line || { x1: 0.5, y1: 0.9, x2: 0.5, y2: 0.1 };
    this.tracks = [];
    this.nextId = 1;
    this.counts = { in: 0, out: 0 };
  }

  LineCounter.prototype.setLine = function (line) {
    this.line = line;
    // Les pistes gardent leur position, mais leur côté est à recalculer :
    // déplacer la ligne ne doit jamais fabriquer de franchissement.
    this.tracks.forEach(function (t) { t.side = 0; });
  };

  LineCounter.prototype.setInvert = function (invert) { this.opts.invert = !!invert; };
  LineCounter.prototype.setAspect = function (aspect) { this.opts.aspect = aspect; };

  LineCounter.prototype.reset = function () {
    this.tracks = [];
    this.counts = { in: 0, out: 0 };
  };

  /**
   * Distance signée au support de la ligne (en hauteurs d'image) et abscisse réduite u sur le segment (0 = A, 1 = B).
   * Le côté « positif » est celui qu'on a à sa droite en suivant A→B sur l'image telle qu'on la voit à l'écran
   * (ligne tracée vers le haut : la droite de l'écran).
   */
  LineCounter.prototype.locate = function (x, y) {
    var a = this.opts.aspect, L = this.line;
    var ax = L.x1 * a, ay = L.y1, bx = L.x2 * a, by = L.y2;
    var dx = bx - ax, dy = by - ay;
    var len = Math.sqrt(dx * dx + dy * dy) || 1e-9;
    var px = x * a - ax, py = y - ay;
    return {
      side: (dx * py - dy * px) / len,
      u: (px * dx + py * dy) / (len * len)
    };
  };

  /**
   * @param {Array<{x:number,y:number,w:number,h:number}>} detections boîtes normalisées (x, y = coin haut gauche)
   * @param {number} t horodatage en millisecondes (croissant)
   * @returns {Array<{dir:number,id:number,t:number}>} franchissements : dir = +1 entrée, −1 sortie
   */
  LineCounter.prototype.update = function (detections, t) {
    var o = this.opts, a = o.aspect, tracks = this.tracks, events = [];

    var dets = detections.map(function (d) {
      return { x: d.x + d.w / 2, y: d.y + d.h / 2, w: d.w, h: d.h };
    });

    // 1. association : paires (piste, détection) triées par distance à la position prédite
    var pairs = [];
    tracks.forEach(function (tr, i) {
      var dt = t - tr.t;
      var px = tr.x + tr.vx * dt, py = tr.y + tr.vy * dt;
      dets.forEach(function (d, j) {
        var dist = Math.hypot((d.x - px) * a, d.y - py);
        // la tolérance grandit avec le temps écoulé depuis la dernière vue (image manquée, téléphone lent)
        var gate = Math.max(0.05, o.gate * Math.max(tr.h, d.h)) * Math.min(o.gateGrowth, Math.max(1, dt / 100));
        if (dist <= gate) pairs.push({ i: i, j: j, d: dist });
      });
    });
    pairs.sort(function (p, q) { return p.d - q.d; });
    var usedT = {}, usedD = {};
    pairs.forEach(function (p) {
      if (usedT[p.i] || usedD[p.j]) return;
      usedT[p.i] = usedD[p.j] = true;
      var tr = tracks[p.i], d = dets[p.j];
      var dt = Math.max(1, t - tr.t);
      var s = o.smoothing;
      var nx = tr.x + (d.x - tr.x) * s, ny = tr.y + (d.y - tr.y) * s;
      tr.vx = 0.5 * tr.vx + 0.5 * (nx - tr.x) / dt;
      tr.vy = 0.5 * tr.vy + 0.5 * (ny - tr.y) / dt;
      tr.x = nx; tr.y = ny; tr.w = d.w; tr.h = d.h;
      tr.t = t; tr.hits++;
      tr.matched = true;
    });

    // 2. nouvelles pistes pour les détections non rattachées
    var self = this;
    dets.forEach(function (d, j) {
      if (usedD[j]) return;
      tracks.push({ id: self.nextId++, x: d.x, y: d.y, w: d.w, h: d.h, vx: 0, vy: 0, t: t, hits: 1, side: 0, matched: true, lastCount: -1e9, hist: [] });
    });

    // 3. franchissements
    tracks.forEach(function (tr) {
      if (!tr.matched) return;
      tr.matched = false;
      var loc = self.locate(tr.x, tr.y);
      tr.hist.push(loc.side);
      if (tr.hist.length > o.historyLen) tr.hist.shift();
      if (Math.abs(loc.side) <= o.margin) return;              // dans la bande morte : on attend
      var now = loc.side > 0 ? 1 : -1;
      if (tr.side === 0) { tr.side = now; return; }
      if (now === tr.side) return;
      if (tr.hits < o.minHits) return;                          // piste trop jeune : pas de décision
      // Le déplacement récent doit réellement aller vers l'autre côté : une boîte qui
      // saute d'un côté à l'autre sans que la personne bouge n'est pas un passage.
      var approach = (loc.side - tr.hist[0]) * now;
      var moving = approach >= o.margin * o.minApproach;
      var recent = t - tr.lastCount < o.cooldownMs;
      tr.side = now;
      if (!moving || recent) return;
      if (loc.u < -o.segmentSlack || loc.u > 1 + o.segmentSlack) return;   // a contourné la ligne
      var dir = (now > 0 ? 1 : -1) * (o.invert ? -1 : 1);
      tr.lastCount = t;
      if (dir > 0) self.counts.in++; else self.counts.out++;
      events.push({ dir: dir, id: tr.id, t: t });
    });

    // 4. pistes perdues
    this.tracks = tracks.filter(function (tr) { return t - tr.t <= o.maxMissingMs; });
    return events;
  };

  /** Pistes confirmées, pour l'affichage (boîtes et numéros). */
  LineCounter.prototype.visible = function () {
    var min = this.opts.minHits;
    return this.tracks.filter(function (tr) { return tr.hits >= min; });
  };

  return { LineCounter: LineCounter, DEFAULTS: DEFAULTS };
});
