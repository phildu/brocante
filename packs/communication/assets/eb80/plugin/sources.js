/**
 * EB80 Plugin — Sources de données de l'éditeur visuel et des générateurs IA.
 *
 * Une « source » est une entité du site (événement, article, produit, partenaire, fiche…) mise sous une
 * forme commune pour alimenter l'affiche, le script IA (Prompt créatif) et la Vidéo IA.
 * Chaque site choisit, dans le configurateur, quelles entités sont proposées et peut en déclarer
 * d'autres (endpoint REST + correspondance des champs) sans toucher au code.
 *
 *   EB80Source.init({ apiBase, token, cle, stockage, integrees })   réglages d'hébergement (tous facultatifs)
 *   window.EB80SourceConfig = { … }                      mêmes réglages, posés AVANT le chargement du script (lus avant le
 *                                                        montage automatique)
 *   integrees : sources intégrées du site hôte, à la place de celles d'ELECTROBOY 80 (événement, merch, partenaire) :
 *               [{ id, label, icone, profil, endpoint, cleListe, champs, … }] (même description qu'une source
 *               personnalisée, voir SOURCES.md) ; le configurateur ne laisse régler que leur affichage
 *   EB80Source.monter('#conteneur')                       panneau « Source des données » (éditeur)
 *   EB80Source.monterConfigurateur('#conteneur')          écran de configuration
 *   EB80Source.sourcesActives()                           [{ id, label, icone, profil }]
 *   EB80Source.liste(id) / charger(id, entite)            entités d'une source / une entité normalisée
 *   EB80Source.appliquerAffiche(src)                      remplit POSTER (hôte : éditeur visuel)
 *   EB80Source.pourIA()                                   sujet courant allégé (corps des appels IA)
 *
 * Profils (pilotent la mise en forme de l'affiche, de la carte vidéo et le ton des textes IA) :
 *   evenement · produit · organisation · generique
 *
 * Contrat avec l'hôte : réglages lus/écrits par GET/PATCH {apiBase}/settings (clé `cle`), sinon
 * localStorage. L'éditeur doit exposer POSTER, schedulePreview() ; showPage()/setEditorMode()
 * sont facultatifs. Voir plugin/SOURCES.md. Informations publiques seulement : jamais de notes
 * internes ni de contacts.
 */
(() => {
  const OPT = {
    apiBase: '/api',
    token: () => { try { return localStorage.getItem('eb80_token') || ''; } catch (e) { return ''; } },
    // Le nom contient « private » : l'API des réglages ne le sert pas aux visiteurs.
    cle: 'editor_sources_private',
    stockage: null,
    integrees: null, // sources intégrées du site hôte (sinon celles d'ELECTROBOY 80, PRESETS plus bas)
  };
  // Réglages posés par le site hôte avant le chargement (lus avant le montage automatique du panneau).
  if (typeof window !== 'undefined' && window.EB80SourceConfig && typeof window.EB80SourceConfig === 'object') Object.assign(OPT, window.EB80SourceConfig);

  // ── Utilitaires ─────────────────────────────────────────────────────────────
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const maj = (s) => String(s || '').toUpperCase();
  const court = (s, n) => String(s || '').replace(/\s+/g, ' ').trim().slice(0, n);
  const unique = (a) => [...new Set(a.filter(Boolean))];
  const $ = (id) => document.getElementById(id);
  const chemin = (o, p) => String(p).split('.').reduce((a, k) => (a == null ? undefined : a[k]), o);
  const memeOrigine = (u) => { try { return new URL(u, location.href).origin === location.origin; } catch (e) { return false; } };
  const http = async (url, opts = {}) => {
    const h = Object.assign({}, opts.headers || {});
    // Le jeton n'est envoyé qu'au site lui-même, jamais à un service tiers.
    if (memeOrigine(url) && OPT.token()) h.Authorization = 'Bearer ' + OPT.token();
    const r = await fetch(url, Object.assign({}, opts, { headers: h }));
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return r.json();
  };

  const SYM = { eur: '€', usd: '$', gbp: '£' };
  const prixCentimes = (c, devise) => {
    const n = Number(c);
    if (!isFinite(n) || n <= 0) return '';
    const v = n / 100;
    const d = String(devise || 'eur');
    return (Number.isInteger(v) ? String(v) : v.toFixed(2).replace('.', ',')) + ' ' + (SYM[d.toLowerCase()] || d.toUpperCase());
  };
  const JOURS = ['DIMANCHE', 'LUNDI', 'MARDI', 'MERCREDI', 'JEUDI', 'VENDREDI', 'SAMEDI'];
  const MOIS = ['JANVIER', 'FÉVRIER', 'MARS', 'AVRIL', 'MAI', 'JUIN', 'JUILLET', 'AOÛT', 'SEPTEMBRE', 'OCTOBRE', 'NOVEMBRE', 'DÉCEMBRE'];
  const dateLongue = (v) => {
    if (!/^\d{4}-\d{2}-\d{2}/.test(String(v))) return String(v || '');
    const d = new Date(String(v).slice(0, 10) + 'T12:00:00');
    return isNaN(d) ? String(v) : `${JOURS[d.getDay()]} ${d.getDate()} ${MOIS[d.getMonth()]}`;
  };
  const heure = (t) => String(t || '').replace(/^(\d{1,2}):(\d{2}).*$/, (_, h, m) => `${Number(h)}H${m === '00' ? '' : m}`);
  const lienAbsolu = (base, l) => {
    if (!l) return '';
    if (/^https?:/i.test(l)) return l;
    return (base || location.origin) + (l.startsWith('/') ? '' : '/') + l;
  };

  // ── Profils ─────────────────────────────────────────────────────────────────
  const PROFILS = {
    evenement:    { label: 'Événement (date, lieu)', etiquette: '' },
    produit:      { label: 'Produit / article (prix)', etiquette: 'NOUVEAUTÉ' },
    organisation: { label: 'Organisation / partenaire', etiquette: 'PARTENAIRE' },
    generique:    { label: 'Fiche générique', etiquette: '' },
  };

  // Textes de l'affiche et de la carte vidéo selon le profil.
  const deriver = (s, d) => {
    const lieuAff = d.lieuAffiche || '';
    let affiche; let carte;
    if (s.profil === 'evenement') {
      affiche = { title: s.titre, date: [s.date, s.heure].filter(Boolean).join(' — '), venue: [s.lieu, s.ville].filter(Boolean).join('\n').toUpperCase(), info: s.info };
      carte = { date: maj(s.date), heure: s.heure, lieu: maj(s.lieu || s.nom), ville: s.ville, mentions: s.mentions };
    } else if (s.profil === 'produit') {
      affiche = { title: s.titre, date: s.prix, venue: [maj(s.sousTitre), lieuAff].filter(Boolean).join(' · '), info: s.info };
      carte = { date: maj(s.prix), heure: '', lieu: maj(s.nom), ville: d.lieuCarte || '', mentions: s.mentions };
    } else if (s.profil === 'organisation') {
      affiche = { title: s.titre, date: maj(s.sousTitre) || maj(d.etiquette || PROFILS.organisation.etiquette), venue: maj(s.ville), info: s.info };
      carte = { date: maj(s.sousTitre || d.etiquette || PROFILS.organisation.etiquette), heure: '', lieu: maj(s.nom), ville: s.ville, mentions: s.mentions };
    } else {
      affiche = { title: s.titre, date: maj(s.sousTitre) || s.date, venue: [s.lieu, s.ville].filter(Boolean).join('\n').toUpperCase(), info: s.info || court(s.description, 60) };
      carte = { date: maj(s.sousTitre || s.date), heure: s.heure, lieu: maj(s.nom), ville: s.ville, mentions: s.mentions };
    }
    carte.etiquette = maj(d.etiquette || PROFILS[s.profil].etiquette);
    carte.texteInfos = court(d.texteInfos, 22);
    return { affiche, carte };
  };

  const fiche = (d, base) => {
    const s = Object.assign({ source: d.id, profil: d.profil, rubrique: maj(d.label), nom: '', titre: '', sousTitre: '', description: '',
      prix: '', date: '', heure: '', lieu: '', ville: '', mentions: [], photos: [], logo: '', lien: '', info: '' }, base);
    s.titre = maj(s.nom);
    if (s.profil === 'organisation' && !s.info) s.info = ('Partenaire ' + (d.signature || '')).trim();
    s.lien = s.lien || lienAbsolu('', d.lienPage || '');
    Object.assign(s, deriver(s, d));
    return s;
  };

  // ── Fournisseurs intégrés (l'API d'ELECTROBOY 80) ───────────────────────────
  const partCat = (k) => {
    try { return (PARTNER_CATS.find((c) => c.key === k) || {}).label || ''; } catch (e) { return ''; }
  };
  const FOURNISSEURS = {
    evenements: {
      async brut() {
        const d = await http(OPT.apiBase + '/events?all=1');
        const auj = new Date().toISOString().slice(0, 10);
        const l = (Array.isArray(d) ? d : (d.events || [])).filter((e) => e.date);
        l.sort((a, b) => (a.date >= auj) === (b.date >= auj) ? (a.date >= auj ? a.date.localeCompare(b.date) : b.date.localeCompare(a.date)) : (a.date >= auj ? -1 : 1));
        return l;
      },
      entree: (e) => ({ id: e.id, label: `${e.date} · ${e.venue || e.name}${e.city ? ' (' + e.city + ')' : ''}`, groupe: e.date >= new Date().toISOString().slice(0, 10) ? 'À venir' : 'Passés' }),
      base(e) {
        const entree = { free: 'Entrée libre', hat: 'Au chapeau', pwyw: 'Prix libre', paid: e.entry_price || 'Tarif fixe' }[e.entry_type] || '';
        return {
          id: e.id, nom: e.name, sousTitre: e.subtitle || '', description: court(e.description, 400),
          prix: e.entry_type === 'paid' ? (e.entry_price || '') : '',
          date: dateLongue(e.date), heure: heure(e.time), lieu: e.venue || '', ville: e.city || '',
          photos: unique([e.poster]), lien: e.ticket_url || e.info_url || '',
          info: [entree, e.doors_time ? 'Portes ' + String(e.doors_time).replace(':', 'h') : ''].filter(Boolean).join(' · '),
        };
      },
    },
    merch: {
      async brut() { return (await http(OPT.apiBase + '/merch/all')).products || []; },
      entree: (p) => ({ id: p.id, label: `${p.name} — ${prixCentimes(p.price, p.currency) || 'prix ?'}`, groupe: p.active ? 'En vente' : 'Inactifs' }),
      base(p) {
        const images = Array.isArray(p.images) ? p.images : [];
        const tailles = court(p.size, 60); const couleurs = court(p.color, 60);
        return {
          id: p.id, nom: p.name, sousTitre: p.category || '', description: court(p.description, 400),
          prix: prixCentimes(p.price, p.currency),
          mentions: unique([p.category, couleurs, tailles]).map((m) => court(m, 24)).slice(0, 3),
          photos: unique([p.image_type === 'video' ? '' : p.image, ...images]).filter((u) => !/\.(mp4|webm|mov)$/i.test(u)),
          info: [tailles && 'Tailles ' + tailles, couleurs, p.stock > -1 ? (p.stock > 0 ? `Stock : ${p.stock}` : 'Épuisé') : ''].filter(Boolean).join(' · '),
        };
      },
    },
    partenaires: {
      async brut() { return (typeof STATE !== 'undefined' && STATE.partners) || []; },
      entree: (p) => ({ id: p.id, label: `${p.name}${p.city ? ' (' + p.city + ')' : ''}`, groupe: partCat(p.cat) || 'Autre' }),
      base(p) {
        const cat = partCat(p.cat);
        return {
          id: p.id, nom: p.name, sousTitre: cat, ville: p.city || '', mentions: unique([cat]).slice(0, 3),
          logo: p.logo || '', lien: p.website ? (/^https?:/i.test(p.website) ? p.website : 'https://' + p.website) : '',
        };
      },
    },
  };

  // ── Fournisseur REST des sources personnalisées ─────────────────────────────
  const lire = (o, expr) => {
    if (Array.isArray(expr)) return expr.map((e) => String(lire(o, e) ?? '').trim()).filter(Boolean).join(' · ');
    if (!expr) return '';
    const e = String(expr).trim();
    if (e.includes('{')) return e.replace(/\{([^}]+)\}/g, (_, p) => String(chemin(o, p.trim()) ?? '')).replace(/\s+/g, ' ').trim();
    const v = chemin(o, e);
    return v == null ? '' : v;
  };
  const listePhotos = (o, expr) => {
    const v = lire(o, expr);
    const l = Array.isArray(v) ? v : (v ? [v] : []);
    return l.map((x) => (x && typeof x === 'object' ? (x.url || x.src || '') : String(x))).filter(Boolean);
  };
  const fournisseurRest = (d) => ({
    async brut() {
      if (!d.endpoint) throw new Error('Endpoint non renseigné');
      const j = await http(d.endpoint);
      const l = d.cleListe ? chemin(j, d.cleListe) : j;
      if (!Array.isArray(l)) throw new Error(d.cleListe ? `« ${d.cleListe} » n'est pas une liste dans la réponse` : 'La réponse n\'est pas une liste (indiquer la clé de la liste)');
      return l;
    },
    entree: (o) => ({ id: lire(o, d.champs.id || 'id'), label: String(lire(o, d.champs.nom) || lire(o, d.champs.id || 'id')), groupe: d.champs.groupe ? String(lire(o, d.champs.groupe) || 'Autres') : 'Éléments' }),
    base(o) {
      const c = d.champs || {};
      let prix = String(lire(o, c.prix));
      if (prix && d.prixCentimes) prix = prixCentimes(prix, d.devise || 'eur');
      else if (prix && d.devise) prix = prix + ' ' + d.devise;
      const date = String(lire(o, c.date));
      return {
        id: lire(o, c.id || 'id'), nom: String(lire(o, c.nom)), sousTitre: String(lire(o, c.sousTitre)),
        description: court(lire(o, c.description), 400), prix,
        date: dateLongue(date), heure: heure(lire(o, c.heure)), lieu: String(lire(o, c.lieu)), ville: String(lire(o, c.ville)),
        mentions: unique((Array.isArray(c.mentions) ? c.mentions.map((e) => String(lire(o, e))) : String(lire(o, c.mentions)).split(',')).map((m) => court(m, 24))).slice(0, 3),
        photos: unique([...listePhotos(o, c.photo), ...listePhotos(o, c.photos)]),
        logo: String(lire(o, c.logo)), lien: lienAbsolu('', String(lire(o, c.lien))), info: String(lire(o, c.info)),
      };
    },
  });

  // ── Configuration (par site) ────────────────────────────────────────────────
  const PRESETS = [
    { id: 'event', fournisseur: 'evenements', profil: 'evenement', label: 'Événement', icone: '🎤', actif: true },
    { id: 'merch', fournisseur: 'merch', profil: 'produit', label: 'Article merch', icone: '🛍', actif: true,
      lieuAffiche: 'BOUTIQUE EN LIGNE', lieuCarte: 'BOUTIQUE', etiquette: 'EN BOUTIQUE', texteInfos: 'Dispo en boutique', lienPage: '/boutique.html' },
    { id: 'partner', fournisseur: 'partenaires', profil: 'organisation', label: 'Partenaire', icone: '🤝', actif: true,
      etiquette: 'PARTENAIRE', texteInfos: 'En savoir plus', signature: 'ELECTROBOY 80' },
  ];
  // Sources intégrées : celles du site hôte (OPT.integrees) ou, par défaut, celles d'ELECTROBOY 80.
  const integrees = () => (Array.isArray(OPT.integrees) ? OPT.integrees : PRESETS);
  // Champs d'une source intégrée que la configuration enregistrée ne peut pas remplacer (seul l'affichage se règle).
  const CHAMPS_FIGES = ['fournisseur', 'profil', 'endpoint', 'cleListe', 'champs', 'prixCentimes', 'devise', 'champsDisponibles'];
  // Champs choisis dans les options d'une source intégrée (champsChoisis : emplacement → champ, liste de champs, ou '' pour aucun),
  // appliqués sur sa correspondance par défaut.
  const appliquerChoix = (defaut, choix) => {
    const ch = Object.assign({}, defaut || {});
    Object.entries(choix || {}).forEach(([k, v]) => {
      if (v === '' || (Array.isArray(v) && !v.length)) delete ch[k];
      else if (v != null) ch[k] = v;
    });
    return ch;
  };
  let cfg = null; // [{ id, actif, label, icone, ... }] dans l'ordre d'affichage
  const caches = {}; // id → entités brutes

  const stockerLocal = (k, v) => { try { v === undefined ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } };
  const lireLocal = (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } };

  const lireStockage = async () => {
    if (OPT.stockage) return OPT.stockage.charger();
    try {
      const d = await http(OPT.apiBase + '/settings');
      return (d.settings || {})[OPT.cle] || lireLocal(OPT.cle) || '';
    } catch (e) { return lireLocal(OPT.cle) || ''; }
  };
  const ecrireStockage = async (json) => {
    if (OPT.stockage) return OPT.stockage.sauver(json);
    stockerLocal(OPT.cle, json);
    await http(OPT.apiBase + '/settings', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ [OPT.cle]: json }) });
  };

  const fusionner = (sauve) => {
    const liste = [];
    const vus = new Set();
    (Array.isArray(sauve) ? sauve : []).forEach((s) => {
      if (!s || !s.id || vus.has(s.id)) return;
      const preset = integrees().find((p) => p.id === s.id);
      if (preset) {
        const fige = {};
        CHAMPS_FIGES.forEach((k) => { if (preset[k] !== undefined) fige[k] = preset[k]; });
        if (preset.champs) { fige.champsDefaut = preset.champs; fige.champs = appliquerChoix(preset.champs, s.champsChoisis); }
        liste.push(Object.assign({}, preset, s, fige));
      }
      else if (s.custom) liste.push(Object.assign({ actif: true, icone: '🔗', profil: 'generique', champs: {} }, s));
      else return;
      vus.add(s.id);
    });
    integrees().forEach((p) => { if (!vus.has(p.id)) liste.push(Object.assign({}, p, p.champs ? { champsDefaut: p.champs } : {})); });
    return liste;
  };

  const init = async (opts) => {
    if (opts) Object.assign(OPT, opts);
    if (cfg && !opts) return cfg;
    let sauve = [];
    try { sauve = JSON.parse(await lireStockage() || '[]'); } catch (e) { sauve = []; }
    cfg = fusionner(sauve);
    Object.keys(caches).forEach((k) => delete caches[k]);
    return cfg;
  };
  const sauverConfig = async (liste) => {
    await ecrireStockage(JSON.stringify(liste));
    cfg = fusionner(liste);
    Object.keys(caches).forEach((k) => delete caches[k]);
    document.dispatchEvent(new CustomEvent('eb80-sources-change'));
  };

  const def = (id) => (cfg || fusionner([])).find((s) => s.id === id);
  const sourcesActives = () => (cfg || fusionner([])).filter((s) => s.actif).map((s) => ({ id: s.id, label: s.label, icone: s.icone, profil: s.profil }));
  // Source personnalisée, ou intégrée décrite par un endpoint (site hôte) : fournisseur REST ; sinon fournisseur nommé.
  const fournisseur = (d) => (d.custom || (d.endpoint && !FOURNISSEURS[d.fournisseur]) ? fournisseurRest(d) : FOURNISSEURS[d.fournisseur]);

  const brutes = async (id, force) => {
    if (!cfg) await init();
    const d = def(id);
    if (!d) throw new Error('Source inconnue : ' + id);
    if (force || !caches[id]) caches[id] = await fournisseur(d).brut();
    return caches[id];
  };
  const liste = async (id) => {
    const d = def(id);
    if (!d) throw new Error('Source inconnue : ' + id);
    const four = fournisseur(d);
    return (await brutes(id, true)).map(four.entree);
  };

  const courante = { src: null };
  const charger = async (id, entite) => {
    const d = def(id);
    if (!d) throw new Error('Source inconnue : ' + id);
    const four = fournisseur(d);
    const l = await brutes(id);
    const o = l.find((x) => String(four.entree(x).id) === String(entite));
    if (!o) throw new Error('Élément introuvable');
    const s = fiche(d, four.base(o));
    courante.src = s;
    return s;
  };

  // Texte lu par l'IA : informations publiques seulement.
  const contexte = (s) => {
    const d = def(s.source) || {};
    const l = [`Type de contenu : ${d.label || s.rubrique}`, `Nom : ${s.nom}`];
    if (s.sousTitre) l.push(`Catégorie : ${s.sousTitre}`);
    if (s.prix) l.push(`Prix : ${s.prix}`);
    if (s.date) l.push(`Date : ${s.date}${s.heure ? ' à ' + s.heure : ''}`);
    if (s.lieu && s.profil === 'evenement') l.push(`Lieu : ${s.lieu}${s.ville ? ' (' + s.ville + ')' : ''}`);
    else if (s.ville) l.push(`Ville : ${s.ville}`);
    if (s.description) l.push(`Description : ${s.description}`);
    if (s.info) l.push(`Détails : ${s.info}`);
    if (s.lien) l.push(`Lien : ${s.lien}`);
    return l.join('\n');
  };
  const pourIA = () => {
    const s = courante.src;
    if (!s) return null;
    return {
      source: s.source, profil: s.profil, rubrique: s.rubrique, nom: s.nom, categorie: s.sousTitre, prix: s.prix,
      description: s.description, date: s.date, heure: s.heure, lieu: s.lieu, ville: s.ville,
      lien: s.lien, info: s.info, mentions: s.mentions, photos: s.photos, contexte: contexte(s),
    };
  };

  // ── Affiche (éditeur visuel hôte) ───────────────────────────────────────────
  const champ = (id, v) => { const el = $(id); if (el) el.value = v; };
  const appliquerAffiche = (s, opts = {}) => {
    if (typeof POSTER === 'undefined') throw new Error("L'éditeur n'est pas prêt");
    const o = Object.assign({ fond: true, logo: true }, opts);
    const t = s.affiche;
    if (t.title) POSTER.title.text = t.title;
    if (t.date)  POSTER.date.text  = t.date;
    if (t.venue) POSTER.venue.text = t.venue;
    if (t.info)  POSTER.info.text  = t.info;
    if (s.lien)  POSTER.footer.text = s.lien.replace(/^https?:\/\/(www\.)?/i, '').replace(/\/$/, '');
    champ('title-input', POSTER.title.text);
    champ('date-input', POSTER.date.text);
    champ('venue-input', POSTER.venue.text);
    champ('info-input', POSTER.info.text);
    if (o.fond && s.photos[0]) {
      const url = s.photos[0];
      POSTER.bg.imageSrc = url;
      POSTER.bg.type = 'image';
      if (typeof _pImgCache !== 'undefined') delete _pImgCache[url];
      if (typeof setBgType === 'function') setBgType('image');
      const thumb = $('bg-img-thumb');
      if (thumb && typeof _cssBgUrl === 'function') { thumb.style.backgroundImage = _cssBgUrl(url); thumb.style.display = 'block'; }
    }
    POSTER.layers = (POSTER.layers || []).filter((l) => !l._srcAuto);
    if (o.logo && s.logo) {
      POSTER.layers.push({ id: 'layer_src_' + Date.now(), type: 'image', src: s.logo, x: 0.5, y: 0.9, width: 0.2, opacity: 1, _srcAuto: true });
    }
    POSTER._source = { source: s.source, profil: s.profil, id: s.id, nom: s.nom };
    courante.src = s;
    if (typeof schedulePreview === 'function') schedulePreview();
  };

  // ── Panneau « Source des données » ──────────────────────────────────────────
  const statut = (m, ok = true) => {
    const el = $('src-status');
    if (el) { el.textContent = m; el.style.color = ok ? '#4caf50' : 'var(--pink, #ff006e)'; }
  };
  const PANNEAU = `
    <div style="border-bottom:2px solid var(--border,#222);flex-shrink:0;background:var(--mid)">
      <div id="src-head" style="display:flex;align-items:center;justify-content:space-between;padding:7px 10px;cursor:pointer;user-select:none">
        <span style="font-family:'VT323',monospace;font-size:14px;letter-spacing:2px;color:var(--cyan,#0ff)">🔗 SOURCE DES DONNÉES</span>
        <span id="src-arrow" style="font-size:9px;color:var(--muted,#888)">▼</span>
      </div>
      <div id="src-feeder" style="display:flex;flex-direction:column;gap:6px;padding:0 10px 10px">
        <select id="src-type" class="form-input" style="font-size:11px"></select>
        <select id="src-entity" class="form-input" style="font-size:11px"><option value="">— Choisir —</option></select>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--muted,#888)"><input type="checkbox" id="src-auto" checked> Appliquer dès le choix</label>
          <label style="display:flex;align-items:center;gap:5px;font-size:10px;color:var(--muted,#888)"><input type="checkbox" id="src-fond" checked> Photo en fond</label>
        </div>
        <button class="btn btn-ghost btn-sm" id="src-appliquer" style="font-size:11px">↻ Alimenter l'affiche</button>
        <div style="font-size:9px;color:var(--muted,#888);line-height:1.5">L'affiche, le Prompt créatif ✨ et la Vidéo IA reprennent ce sujet. Les entités proposées se règlent dans « API &amp; Réseaux → Sources éditeur ».</div>
      </div>
      <div id="src-status" style="font-size:10px;padding:0 10px 6px"></div>
    </div>`;

  const remplirTypes = () => {
    const sel = $('src-type');
    if (!sel) return;
    const courant = sel.value;
    const act = sourcesActives();
    sel.innerHTML = '<option value="">— Aucune source —</option>' + act.map((s) => `<option value="${esc(s.id)}">${esc(s.icone)} ${esc(s.label)}</option>`).join('');
    if (act.some((s) => s.id === courant)) sel.value = courant;
    if (!act.length) statut('Aucune source activée : voir « Sources éditeur »', false);
  };
  const remplirEntites = async () => {
    const id = $('src-type').value;
    const sel = $('src-entity');
    sel.innerHTML = '<option value="">— Choisir —</option>';
    if (!id) return;
    try {
      const l = await liste(id);
      const groupes = {};
      l.forEach((e) => { (groupes[e.groupe] = groupes[e.groupe] || []).push(e); });
      sel.innerHTML += Object.entries(groupes).map(([g, es]) => `<optgroup label="${esc(g)}">${es.map((e) => `<option value="${esc(e.id)}">${esc(e.label)}</option>`).join('')}</optgroup>`).join('');
      statut(l.length ? '' : 'Aucun élément dans cette source', !!l.length);
    } catch (e) { statut('Chargement impossible : ' + e.message, false); }
  };
  const appliquer = async () => {
    const id = $('src-type').value; const ent = $('src-entity').value;
    if (!id || !ent) { statut('Choisis un élément', false); return; }
    try {
      const s = await charger(id, ent);
      appliquerAffiche(s, { fond: $('src-fond').checked });
      statut('✓ Affiche alimentée : ' + s.nom);
    } catch (e) { statut(e.message, false); }
  };
  const monter = async (conteneur) => {
    const c = typeof conteneur === 'string' ? document.querySelector(conteneur) : conteneur;
    if (!c || c.dataset.srcMonte) return;
    c.dataset.srcMonte = '1';
    await init();
    c.innerHTML = PANNEAU;
    remplirTypes();
    $('src-type').addEventListener('change', remplirEntites);
    $('src-entity').addEventListener('change', () => { if ($('src-auto').checked) appliquer(); });
    $('src-appliquer').addEventListener('click', appliquer);
    const ouvert = (v) => {
      $('src-feeder').style.display = v ? 'flex' : 'none';
      $('src-arrow').textContent = v ? '▼' : '▶';
      stockerLocal('eb80_src_open', v ? '1' : '0');
    };
    $('src-head').addEventListener('click', () => ouvert($('src-feeder').style.display === 'none'));
    if (lireLocal('eb80_src_open') === '0') ouvert(false);
    $('src-auto').checked = lireLocal('eb80_src_auto') !== '0';
    $('src-auto').addEventListener('change', () => stockerLocal('eb80_src_auto', $('src-auto').checked ? '1' : '0'));
    document.addEventListener('eb80-sources-change', remplirTypes);
  };

  // Depuis une fiche du site : ouvre l'éditeur déjà alimenté.
  const ouvrirDansEditeur = async (id, entite) => {
    try {
      await init();
      const s = await charger(id, entite);
      if (typeof showPage === 'function') showPage('editor');
      if (typeof setEditorMode === 'function') setEditorMode('poster');
      if ($('src-type')) {
        if (![...$('src-type').options].some((o) => o.value === id)) remplirTypes();
        $('src-type').value = id;
        await remplirEntites();
        $('src-entity').value = String(entite);
      }
      appliquerAffiche(s, { fond: $('src-fond') ? $('src-fond').checked : true });
      statut('✓ Affiche alimentée : ' + s.nom);
    } catch (e) {
      if (typeof showToast === 'function') showToast('Source indisponible : ' + e.message, 'error');
    }
  };

  // ── Configurateur ───────────────────────────────────────────────────────────
  const CHAMPS_REST = [
    ['id', 'Identifiant', 'id'], ['nom', 'Nom / titre *', 'title'], ['sousTitre', 'Catégorie', 'category'],
    ['description', 'Description', 'excerpt'], ['prix', 'Prix', 'price'], ['date', 'Date (AAAA-MM-JJ)', 'date'],
    ['heure', 'Heure', 'time'], ['lieu', 'Lieu', 'venue'], ['ville', 'Ville', 'city'],
    ['photo', 'Photo principale', 'image'], ['photos', 'Autres photos (liste)', 'gallery'], ['logo', 'Logo', 'logo'],
    ['lien', 'Lien public', 'url'], ['info', 'Détail court', 'info'], ['mentions', 'Étiquettes (séparées par virgule)', 'tags'],
    ['groupe', 'Regrouper la liste par', 'status'],
  ];
  // Emplacements réglables d'une source intégrée : [clé, libellé, plusieurs champs possibles].
  const EMPLACEMENTS_CHOIX = [
    ['sousTitre', 'Catégorie / sous-titre', false], ['description', 'Description', false], ['prix', 'Prix', false],
    ['info', 'Détail court (affiche, carte vidéo)', true], ['mentions', 'Étiquettes (3 au plus)', true], ['groupe', 'Regrouper la liste par', false],
  ];
  const CSS_CONF = `
.srcc{font-family:'IBM Plex Mono',monospace;font-size:12px;color:var(--white,#f0f0f0)}
.srcc *{box-sizing:border-box}
.srcc-ligne{display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--border,#222);background:var(--mid);margin-bottom:6px;flex-wrap:wrap}
.srcc-ligne .srcc-nom{flex:1;min-width:140px}
.srcc-ligne input[type=text]{background:var(--mid);border:1px solid var(--border);color:inherit;font:inherit;padding:4px 6px}
.srcc-tag{font-size:9px;letter-spacing:1px;padding:1px 6px;border:1px solid var(--border);color:var(--muted)}
.srcc-form{border:1px solid var(--yellow,#ff0);padding:12px;margin:10px 0;background:var(--mid)}
.srcc-grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
.srcc label{display:block;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin:6px 0 2px}
.srcc-form input[type=text],.srcc-form select{width:100%;background:var(--mid);border:1px solid var(--border);color:inherit;font:inherit;padding:6px 8px}
.srcc-aide{font-size:10px;color:var(--muted);line-height:1.6;margin:6px 0}
.srcc-coches{display:flex;flex-wrap:wrap;gap:4px 12px;padding:4px 0}
.srcc-coches label{display:flex;align-items:center;gap:4px;font-size:11px;letter-spacing:0;text-transform:none;margin:0;color:inherit}
.srcc-res{margin-top:8px;padding:8px;background:var(--mid);border:1px solid var(--border);font-size:11px;white-space:pre-wrap;word-break:break-all}
.srcc-ok{color:#4caf50}.srcc-ko{color:#ff006e}`;

  const monterConfigurateur = async (conteneur) => {
    const c = typeof conteneur === 'string' ? document.querySelector(conteneur) : conteneur;
    if (!c || c.dataset.srccMonte) return;
    c.dataset.srccMonte = '1';
    if (!document.getElementById('srcc-css')) {
      const st = document.createElement('style'); st.id = 'srcc-css'; st.textContent = CSS_CONF; document.head.appendChild(st);
    }
    await init();
    let liste_ = cfg.map((s) => JSON.parse(JSON.stringify(s)));
    let edition = null; // source personnalisée en cours d'édition (objet)

    const ligne = (s, i) => `
      <div class="srcc-ligne" data-i="${i}">
        <label style="display:flex;align-items:center;gap:6px;margin:0"><input type="checkbox" data-a="actif" ${s.actif ? 'checked' : ''}> <span style="font-size:10px;color:var(--muted)">Actif</span></label>
        <input type="text" data-a="icone" value="${esc(s.icone)}" style="width:42px;text-align:center" title="Icône">
        <input type="text" class="srcc-nom" data-a="label" value="${esc(s.label)}" title="Libellé affiché">
        <span class="srcc-tag">${esc(PROFILS[s.profil].label)}</span>
        <span class="srcc-tag">${s.custom ? 'PERSONNALISÉE · ' + esc(s.endpoint || '—') : 'INTÉGRÉE'}</span>
        <button class="btn btn-ghost btn-sm" data-act="haut" ${i === 0 ? 'disabled' : ''} title="Monter">↑</button>
        <button class="btn btn-ghost btn-sm" data-act="bas" ${i === liste_.length - 1 ? 'disabled' : ''} title="Descendre">↓</button>
        <button class="btn btn-ghost btn-sm" data-act="options">⚙ Options</button>
        ${s.custom ? '<button class="btn btn-ghost btn-sm" style="color:var(--pink,#ff006e)" data-act="suppr" title="Supprimer cette source">✕</button>' : ''}
      </div>`;

    const formulaire = (s) => {
      const ch = s.champs || {};
      return `
      <div class="srcc-form" id="srcc-form">
        <div style="font-family:'VT323',monospace;font-size:16px;letter-spacing:2px;color:var(--yellow,#ff0)">${s.custom ? (s._nouveau ? 'NOUVELLE SOURCE' : 'SOURCE PERSONNALISÉE') : 'OPTIONS · ' + esc(s.label.toUpperCase())}</div>
        <div class="srcc-grille">
          <div><label>Libellé</label><input type="text" data-f="label" value="${esc(s.label)}"></div>
          <div><label>Profil (mise en forme et ton IA)</label><select data-f="profil" ${s.custom ? '' : 'disabled'}>${Object.entries(PROFILS).map(([k, p]) => `<option value="${k}" ${s.profil === k ? 'selected' : ''}>${esc(p.label)}</option>`).join('')}</select></div>
          <div><label>Page publique (lien repris)</label><input type="text" data-f="lienPage" value="${esc(s.lienPage || '')}" placeholder="/boutique"></div>
          <div><label>Étiquette de la carte vidéo</label><input type="text" data-f="etiquette" value="${esc(s.etiquette || '')}" placeholder="${esc(PROFILS[s.profil].etiquette || 'ex. NOUVEAUTÉ')}"></div>
          <div><label>Appel final vidéo (22 car.)</label><input type="text" data-f="texteInfos" value="${esc(s.texteInfos || '')}" placeholder="En savoir plus"></div>
          ${s.profil === 'organisation' ? `<div><label>Signature (« Partenaire … » sur l'affiche)</label><input type="text" data-f="signature" value="${esc(s.signature || '')}" placeholder="nom du site"></div>` : ''}
          ${s.profil === 'produit' ? `
          <div><label>Lieu affiché (affiche)</label><input type="text" data-f="lieuAffiche" value="${esc(s.lieuAffiche || '')}" placeholder="BOUTIQUE EN LIGNE"></div>
          <div><label>Lieu court (carte vidéo)</label><input type="text" data-f="lieuCarte" value="${esc(s.lieuCarte || '')}" placeholder="BOUTIQUE"></div>` : ''}
        </div>
        ${s.custom ? `
        <div class="srcc-aide">Source REST : l'URL doit répondre en JSON avec une liste. Chaque champ se règle avec le nom de la propriété (<code>title</code>, <code>author.name</code>) ou un modèle (<code>{venue} — {city}</code>). Le jeton de connexion n'est envoyé qu'aux URL de ce site.</div>
        <div class="srcc-grille">
          <div><label>Endpoint (URL ou chemin) *</label><input type="text" data-f="endpoint" value="${esc(s.endpoint || '')}" placeholder="/api/articles"></div>
          <div><label>Clé de la liste dans la réponse</label><input type="text" data-f="cleListe" value="${esc(s.cleListe || '')}" placeholder="items (vide si la réponse est la liste)"></div>
          <div><label>Prix en centimes ?</label><select data-f="prixCentimes"><option value="">Non</option><option value="1" ${s.prixCentimes ? 'selected' : ''}>Oui</option></select></div>
          <div><label>Devise / symbole</label><input type="text" data-f="devise" value="${esc(s.devise || '')}" placeholder="€"></div>
        </div>
        <div style="font-size:10px;letter-spacing:2px;color:var(--muted);margin:10px 0 0">CORRESPONDANCE DES CHAMPS</div>
        <div class="srcc-grille">${CHAMPS_REST.map(([k, l, ex]) => `<div><label>${esc(l)}</label><input type="text" data-c="${k}" value="${esc(ch[k] || '')}" placeholder="${esc(ex)}"></div>`).join('')}</div>` : ''}
        ${!s.custom && s.endpoint && Array.isArray(s.champsDisponibles) && s.champsDisponibles.length ? (() => {
          const dispo = s.champsDisponibles;
          const nom = (k) => (dispo.find(([c]) => c === k) || [k, k])[1];
          const liste = (v) => (Array.isArray(v) ? v : (v ? [v] : []));
          return `
        <div style="font-size:10px;letter-spacing:2px;color:var(--muted);margin:10px 0 0">CHAMPS UTILISÉS</div>
        <div class="srcc-aide">Ce que chaque emplacement reprend de l'entité. « Par défaut » suit le réglage du site.</div>
        <div class="srcc-grille">${EMPLACEMENTS_CHOIX.map(([k, l, multi]) => {
          const choisi = (s.champsChoisis || {})[k];
          const defaut = liste((s.champsDefaut || {})[k]);
          const libDefaut = defaut.length ? defaut.map(nom).join(' + ') : 'aucun';
          if (!multi) {
            return `<div><label>${esc(l)}</label><select data-ch="${k}">
              <option value="__defaut" ${choisi === undefined ? 'selected' : ''}>Par défaut (${esc(libDefaut)})</option>
              <option value="" ${choisi === '' ? 'selected' : ''}>— aucun —</option>
              ${dispo.map(([c, lib]) => `<option value="${esc(c)}" ${choisi === c ? 'selected' : ''}>${esc(lib)}</option>`).join('')}
            </select></div>`;
          }
          const actifs = choisi === undefined ? defaut : liste(choisi);
          return `<div style="grid-column:1/-1"><label>${esc(l)} · par défaut : ${esc(libDefaut)}</label><div class="srcc-coches" data-chm="${k}">
            ${dispo.map(([c, lib]) => `<label><input type="checkbox" value="${esc(c)}" ${actifs.includes(c) ? 'checked' : ''}> ${esc(lib)}</label>`).join('')}
          </div></div>`;
        }).join('')}</div>`;
        })() : ''}
        <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
          ${s.custom || s.endpoint ? '<button class="btn btn-ghost btn-sm" data-act="tester" style="border-color:#4a9eff;color:#4a9eff">⚡ Tester</button>' : ''}
          <button class="btn btn-yellow btn-sm" data-act="valider">✓ Valider</button>
          <button class="btn btn-ghost btn-sm" data-act="annuler">Annuler</button>
        </div>
        <div class="srcc-res" id="srcc-res" hidden></div>
      </div>`;
    };

    const dessiner = () => {
      c.innerHTML = `<div class="srcc">
        <div class="srcc-aide">Choisis les entités qui peuvent alimenter l'éditeur visuel, le Prompt créatif et la Vidéo IA. L'ordre ci-dessous est celui du menu. Une source personnalisée permet d'y ajouter n'importe quelle liste JSON du site (articles, spectacles, produits…).</div>
        <div id="srcc-liste">${liste_.map(ligne).join('')}</div>
        ${edition ? formulaire(edition) : ''}
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
          <button class="btn btn-ghost btn-sm" data-act="ajout">+ Source personnalisée</button>
          <button class="btn btn-yellow btn-sm" data-act="sauver">✓ Enregistrer la configuration</button>
          <span id="srcc-statut" style="font-size:11px"></span>
        </div></div>`;
    };
    const dire = (m, ok = true) => { const e = c.querySelector('#srcc-statut'); if (e) { e.textContent = m; e.className = ok ? 'srcc-ok' : 'srcc-ko'; } };

    const lireForm = () => {
      const f = c.querySelector('#srcc-form');
      const s = edition;
      f.querySelectorAll('[data-f]').forEach((el) => {
        const k = el.dataset.f;
        s[k] = k === 'prixCentimes' ? el.value === '1' : el.value.trim();
      });
      if (s.custom) {
        s.champs = {};
        f.querySelectorAll('[data-c]').forEach((el) => { if (el.value.trim()) s.champs[el.dataset.c] = el.value.trim(); });
      } else if (f.querySelector('[data-ch], [data-chm]')) {
        // Source intégrée : champs choisis (seuls les écarts au réglage par défaut sont gardés).
        const choix = {};
        const memes = (a, b) => JSON.stringify(a) === JSON.stringify(b);
        const defaut = s.champsDefaut || {};
        f.querySelectorAll('[data-ch]').forEach((el) => { if (el.value !== '__defaut') choix[el.dataset.ch] = el.value; });
        f.querySelectorAll('[data-chm]').forEach((el) => {
          const k = el.dataset.chm;
          const coches = [...el.querySelectorAll('input:checked')].map((i) => i.value);
          const d = Array.isArray(defaut[k]) ? defaut[k] : (defaut[k] ? [defaut[k]] : []);
          if (!memes(coches, d)) choix[k] = coches.length ? coches : '';
        });
        s.champsChoisis = choix;
        s.champs = appliquerChoix(defaut, choix);
      }
    };

    c.addEventListener('change', (ev) => {
      const l = ev.target.closest('.srcc-ligne');
      if (!l || !ev.target.dataset.a) return;
      const s = liste_[Number(l.dataset.i)];
      s[ev.target.dataset.a] = ev.target.type === 'checkbox' ? ev.target.checked : ev.target.value;
    });
    c.addEventListener('input', (ev) => {
      const l = ev.target.closest('.srcc-ligne');
      if (l && ev.target.dataset.a && ev.target.type === 'text') liste_[Number(l.dataset.i)][ev.target.dataset.a] = ev.target.value;
    });
    c.addEventListener('click', async (ev) => {
      const b = ev.target.closest('[data-act]');
      if (!b) return;
      const act = b.dataset.act;
      const l = b.closest('.srcc-ligne');
      const i = l ? Number(l.dataset.i) : -1;
      if (act === 'haut' && i > 0) { [liste_[i - 1], liste_[i]] = [liste_[i], liste_[i - 1]]; dessiner(); }
      else if (act === 'bas' && i < liste_.length - 1) { [liste_[i + 1], liste_[i]] = [liste_[i], liste_[i + 1]]; dessiner(); }
      else if (act === 'suppr') { if (confirm('Supprimer la source « ' + liste_[i].label + ' » ?')) { liste_.splice(i, 1); dessiner(); } }
      else if (act === 'options') { edition = liste_[i]; dessiner(); c.querySelector('#srcc-form').scrollIntoView({ block: 'nearest' }); }
      else if (act === 'ajout') {
        edition = { id: 'src_' + Date.now().toString(36), custom: true, _nouveau: true, actif: true, label: 'Nouvelle source', icone: '🔗', profil: 'generique', endpoint: '', cleListe: '', champs: {} };
        liste_.push(edition); dessiner(); c.querySelector('#srcc-form').scrollIntoView({ block: 'nearest' });
      }
      else if (act === 'annuler') { if (edition && edition._nouveau) liste_ = liste_.filter((s) => s !== edition); edition = null; dessiner(); }
      else if (act === 'valider') {
        lireForm();
        if (edition.custom && (!edition.endpoint || !(edition.champs || {}).nom)) { const r = c.querySelector('#srcc-res'); r.hidden = false; r.className = 'srcc-res srcc-ko'; r.textContent = 'Renseigne au minimum l\'endpoint et le champ « Nom / titre ».'; return; }
        delete edition._nouveau; edition = null; dessiner();
      }
      else if (act === 'tester') {
        lireForm();
        const r = c.querySelector('#srcc-res'); r.hidden = false; r.className = 'srcc-res'; r.textContent = '⏳ Appel de ' + (edition.endpoint || '…');
        try {
          const four = fournisseurRest(edition);
          const brut = await four.brut();
          // Exemple : la première entité dont le détail court est rempli (sinon la première), pour voir l'effet des champs choisis.
          const fiches = brut.slice(0, 50).map((o) => fiche(edition, four.base(o)));
          const ex = fiches.find((x) => x.info) || fiches[0] || fiche(edition, four.base({}));
          r.className = 'srcc-res srcc-ok';
          r.textContent = `✓ ${brut.length} élément${brut.length > 1 ? 's' : ''} trouvé${brut.length > 1 ? 's' : ''}.\nExemple : ${ex.nom || '(nom vide : vérifie le champ Nom)'}\n` + JSON.stringify({ categorie: ex.sousTitre, prix: ex.prix, detail: ex.info, etiquettes: ex.mentions, date: ex.date, lieu: ex.lieu, ville: ex.ville, photos: ex.photos.slice(0, 2), lien: ex.lien }, null, 1);
        } catch (e) { r.className = 'srcc-res srcc-ko'; r.textContent = '✕ ' + e.message; }
      }
      else if (act === 'sauver') {
        if (edition) { dire('Valide d\'abord la source en cours d\'édition', false); return; }
        try {
          await sauverConfig(liste_.map((s) => { const o = Object.assign({}, s); delete o._nouveau; return o; }));
          dire('✓ Configuration enregistrée');
        } catch (e) { dire('Échec : ' + e.message, false); }
      }
    });
    dessiner();
  };

  window.EB80Source = {
    init, monter, monterConfigurateur, sourcesActives, liste, charger, contexte, pourIA, appliquerAffiche,
    ouvrirDansEditeur, courante, PROFILS,
  };
  document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('src-mount')) monter('#src-mount');
    if (document.getElementById('src-config-mount')) monterConfigurateur('#src-config-mount');
  });
})();
