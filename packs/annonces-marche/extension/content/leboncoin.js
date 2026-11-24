/* Préremplissage du formulaire Leboncoin (validation humaine par défaut).
 * Reprend la logique du script Playwright d'origine : titre, catégorie proposée, photos, menus (Univers, Marque, Genre,
 * Taille / Pointure, Couleur, État), puis description et prix. */
(function () {
  const F0 = () => globalThis.AmFill;
  const squash = (v) => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

  // --- Correspondances Leboncoin (libellés exacts des menus) ---
  const ETATS = {
    neuf: 'Neuf sans étiquette',
    neuf_etiquette: 'Neuf avec étiquette',
    neuf_emballe: 'Neuf avec étiquette',
    neuf_boite: 'Neuf sans étiquette',
    neuf_sans_etiquette: 'Neuf sans étiquette',
    comme_neuf: 'Très bon état',
    presque_neuf: 'Très bon état',
    excellent: 'Très bon état',
    tres_bon: 'Très bon état',
    bon: 'Bon état',
    bon_age: 'Bon état',
    patine: 'Bon état',
    satisfaisant: 'État satisfaisant',
    correct: 'État satisfaisant',
    usage: 'État satisfaisant',
    defauts: 'État satisfaisant',
  };
  // Un même état se dit différemment selon la catégorie (vêtements / autres) : on essaie les variantes.
  const ETAT_VARIANTES = {
    'etat satisfaisant': ['État correct'],
    'etat correct': ['État satisfaisant'],
    'neuf sans etiquette': ['Neuf'],
    'neuf avec etiquette': ['Neuf'],
    neuf: ['Neuf sans étiquette', 'Neuf avec étiquette'],
    'pour pieces': ['État satisfaisant'],
  };
  const TAILLES = {
    XXS: '32 - XXS', XS: '34 - XS', S: '36 - S', M: '38 - M', L: '40 - L', XL: '42 - XL', XXL: '44 - XXL',
    32: '32 - XXS', 34: '34 - XS', 36: '36 - S', 38: '38 - M', 40: '40 - L', 42: '42 - XL', 44: '44 - XXL',
  };
  const COULEURS = {
    noir: 'Noir', blanc: 'Blanc', rouge: 'Rouge / Bordeaux', bordeaux: 'Rouge / Bordeaux', orange: 'Orange / Corail',
    corail: 'Orange / Corail', bleu: 'Bleu', vert: 'Vert', jaune: 'Jaune', rose: 'Rose / Fuchsia', fuchsia: 'Rose / Fuchsia',
    violet: 'Violet / Mauve', mauve: 'Violet / Mauve', marron: 'Marron', gris: 'Gris', beige: 'Beige', kaki: 'Vert',
  };
  const MARQUES = ['zara', 'h&m', 'mango', 'nike', 'adidas', "levi's", 'promod', 'penta'];

  function unique(list) {
    const seen = new Set();
    return list.filter((v) => {
      const key = squash(v);
      if (!v || !key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function lbc(p) {
    return (p && p.mapped && p.mapped.leboncoin) || {};
  }

  /** Valeur imposée par la table de correspondances de la boutique pour un menu donné (« autre »). */
  function autre(p, label) {
    const table = lbc(p).autre || {};
    return table[squash(label)] || '';
  }

  function etatCandidates(p) {
    const c = (p.condition && p.condition.leboncoin) || {};
    const base = unique([lbc(p).etat, ETATS[String(c.key || '').toLowerCase()], c.channel_label]);
    const extra = [];
    for (const label of base) (ETAT_VARIANTES[squash(label)] || []).forEach((v) => extra.push(v));
    return unique(base.concat(extra));
  }

  function brandCandidate(p) {
    const brand = String(p.brand || lbc(p).marque || '').trim();
    if (brand) return brand;
    const title = String(p.title || p.name || '').toLowerCase();
    for (const m of MARQUES) {
      const escaped = m.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      if (new RegExp('(^|[^a-z0-9])' + escaped + '([^a-z0-9]|$)', 'i').test(title)) return m.charAt(0).toUpperCase() + m.slice(1);
    }
    return '';
  }

  /** { label: 'Taille' | 'Pointure', values: [...] } : liste de valeurs à essayer dans le menu. */
  function sizeInfo(p) {
    const sizeText = String(p.size_text || '');
    const nature = squash(p.nature_label);
    const mapped = lbc(p).taille;
    if (nature.includes('chaussure') || /pointure/i.test(sizeText)) {
      const m = sizeText.match(/\b(3[5-9]|4[0-6])\b/) || String(p.title || '').match(/\b(3[5-9]|4[0-6])\b/);
      return { label: 'Pointure', values: unique([mapped, m ? m[1] : '']) };
    }
    const m = sizeText.match(/\b(XXS|XS|S|M|L|XL|XXL|3[2-8]|4[0-8]|5[0-2])\b/i);
    if (!m) return { label: 'Taille', values: unique([mapped]) };
    const code = m[1].toUpperCase();
    return { label: 'Taille', values: unique([mapped, TAILLES[code], code]) };
  }

  function colorCandidates(p) {
    const mapped = lbc(p).couleur;
    const list = Array.isArray(mapped) ? mapped.slice() : mapped ? [mapped] : [];
    const text = squash([p.colors, p.title, p.description].join(' '));
    for (const [word, label] of Object.entries(COULEURS)) {
      if (new RegExp('\\b' + word + '\\b').test(text)) { list.push(label); break; }
    }
    return unique(list);
  }

  function universCandidate(p) {
    const forced = autre(p, 'univers');
    if (forced) return forced;
    const text = squash([p.title, p.description].join(' '));
    if (/\bhomme\b/.test(text) && !/\bfemme\b/.test(text)) return 'Homme';
    return 'Femme';
  }

  // --- Univers : on lit la liste réelle du site et on prend l'entrée la plus proche de la pièce ---

  // Mots de la pièce qui désignent un univers du site (clé = mot de l'univers, tel que normalisé et sans pluriel).
  const UNIVERS_SYNONYMES = {
    femme: ['dame', 'robe', 'jupe', 'escarpin', 'ballerine', 'lingerie'],
    homme: ['monsieur', 'cravate', 'costume', 'derbie'],
    enfant: ['garcon', 'fille', 'junior', 'ado'],
    bebe: ['naissance', 'puericulture', 'layette'],
    maison: ['deco', 'decoration', 'panier', 'rangement', 'cuisine', 'vaisselle', 'linge', 'meuble', 'luminaire', 'vase', 'coussin', 'bougie', 'cadre', 'miroir', 'tapis', 'plaid', 'rideau'],
    jardin: ['plante', 'pot', 'jardiniere', 'outil', 'terrasse'],
    deco: ['decoration', 'maison', 'cadre', 'vase', 'bougie', 'miroir'],
    decoration: ['deco', 'maison', 'cadre', 'vase', 'bougie', 'miroir', 'panier'],
    bricolage: ['outil', 'perceuse', 'visserie'],
    livre: ['roman', 'bd', 'manga', 'album'],
    jeu: ['jouet', 'puzzle', 'console', 'jeux'],
    jouet: ['jeu', 'peluche', 'puzzle'],
    sport: ['velo', 'raquette', 'ballon', 'randonnee'],
    musique: ['vinyle', 'guitare', 'disque', 'cd'],
    accessoire: ['sac', 'ceinture', 'echarpe', 'foulard', 'bijou', 'chapeau', 'lunette'],
    bijou: ['collier', 'bracelet', 'bague'],
    chaussure: ['basket', 'botte', 'bottine', 'sandale', 'mocassin'],
  };
  const MODE_WORDS = /\b(vetement|robe|jupe|pantalon|jean|short|veste|manteau|blouson|pull|gilet|chemise|t-?shirt|chaussure|basket|botte|bottine|sandale|escarpin|derbie|mocassin|ballerine|costume|lingerie|maillot|combinaison|doudoune|sac|ceinture|echarpe|foulard|chapeau)s?\b/;

  function stem(word) {
    return String(word || '').replace(/(s|x)$/, '');
  }
  function tokensOf(text) {
    return squash(text).split(/[^a-z0-9]+/).map(stem).filter((w) => w.length >= 3);
  }

  /** Mots de la pièce avec leur poids : catégorie ×3, titre ×2, description ×1. */
  function productTokens(p) {
    const weights = new Map();
    const add = (text, weight) => tokensOf(text).forEach((w) => weights.set(w, Math.max(weights.get(w) || 0, weight)));
    add([p.nature_label, p.subcategory_label, p.category_label, p.nature, p.sous_categorie].join(' '), 3);
    add(p.title || p.name, 2);
    add(p.description, 1);
    return weights;
  }

  function isFashion(p) {
    const info = squash([p.nature_label, p.subcategory_label, p.category_label, p.nature, p.sous_categorie].join(' '));
    // Aucune catégorie connue : comportement historique (univers « Femme / Homme »).
    return !info.trim() || MODE_WORDS.test(info);
  }

  function scoreUnivers(optionText, weights) {
    let score = 0;
    for (const token of tokensOf(optionText)) {
      if (weights.has(token)) score += weights.get(token);
      for (const synonym of UNIVERS_SYNONYMES[token] || []) {
        if (weights.has(stem(synonym))) { score += 2; break; }
      }
    }
    return score;
  }

  /**
   * Entrée de la liste « Univers » du site qui convient à la pièce, ou '' si aucune n'est assez sûre :
   * 1) la valeur imposée par la table de correspondances ; 2) pour la mode, Femme / Homme ; 3) la plus proche par les mots de la pièce.
   */
  function universChoice(p, texts) {
    const options = (texts || []).map((t) => ({ text: String(t || '').trim(), key: squash(t) })).filter((o) => o.key);
    if (!options.length) return '';
    const forced = squash(autre(p, 'univers'));
    if (forced) {
      const hit = options.find((o) => o.key === forced);
      if (hit) return hit.text;
    }
    if (isFashion(p)) {
      const gender = squash(universCandidate(p));
      const hit = options.find((o) => o.key === gender);
      if (hit) return hit.text;
    }
    const weights = productTokens(p);
    const scored = options.map((o) => ({ text: o.text, score: scoreUnivers(o.text, weights) })).sort((a, b) => b.score - a.score);
    // Seuil : un mot de catégorie, ou un mot du titre appuyé par un synonyme. Égalité : on ne devine pas.
    if (scored[0].score >= 2 && (!scored[1] || scored[0].score > scored[1].score)) return scored[0].text;
    return '';
  }

  /** Mots à repérer dans les suggestions de catégorie proposées après le titre. */
  function categoryWords(p, withParent) {
    // La catégorie « parente » (Maison, Mode…) revient dans toutes les suggestions : elle ne sert que de dernier recours.
    const parts = [lbc(p).categorie, p.nature_label, p.subcategory_label].concat(withParent === false ? [] : [p.category_label])
      .flatMap((v) => (v ? String(v).split(/[,/]| et /) : []).concat(v ? [String(v)] : []))
      .map((v) => v.trim())
      .filter((v) => v.length > 3);
    return unique(parts);
  }

  function priceText(p) {
    const n = Number(p.price);
    if (p.price == null || p.price === '' || !Number.isFinite(n)) return '';
    return String(Math.round(n));
  }

  // Trace des étapes (affichée dans l'historique de la file quand la pièce est bloquée) : sans elle, impossible de savoir où ça coince.
  const trace = [];
  function renderTrace() {
    // Petit panneau resté affiché (contrairement à la bulle qui disparaît) : on y lit où le remplissage en est ou s'arrête.
    let box = document.getElementById('am-ext-trace');
    if (!box) {
      box = document.createElement('div');
      box.id = 'am-ext-trace';
      Object.assign(box.style, {
        position: 'fixed', zIndex: '2147483646', left: '16px', bottom: '16px', maxWidth: '420px', maxHeight: '45vh', overflow: 'auto',
        padding: '10px 12px', background: '#1d1a16', color: '#fffaf2', font: '12px/1.4 ui-monospace, Menlo, monospace',
        borderRadius: '10px', boxShadow: '0 8px 24px rgba(0,0,0,.25)', whiteSpace: 'pre-wrap',
      });
      const close = document.createElement('button');
      close.type = 'button';
      close.textContent = '×';
      close.setAttribute('aria-label', 'Fermer');
      Object.assign(close.style, { position: 'absolute', top: '2px', right: '6px', background: 'none', border: '0', color: 'inherit', fontSize: '16px', cursor: 'pointer' });
      close.addEventListener('click', () => box.remove());
      const text = document.createElement('div');
      text.id = 'am-ext-trace-text';
      box.append(close, text);
      document.documentElement.appendChild(box);
    }
    const text = box.querySelector('#am-ext-trace-text');
    if (text) text.textContent = 'Synchronisation — étapes\n' + trace.map((t) => '• ' + t).join('\n');
  }
  function note(message) {
    trace.push(message);
    if (trace.length > 14) trace.shift();
    try { sessionStorage.setItem('amLbcTrace', trace.join(' | ')); } catch (_) {}
    try { renderTrace(); } catch (_) {}
  }

  /** Signale au portail une valeur introuvable sur le site (liste « À renseigner » de la page Correspondances). Une seule fois par page. */
  function reportUnknown(p, fieldLabel, options) {
    const source = String(p.subcategory_label || p.nature_label || p.category_label || '').trim();
    const F = F0();
    if (F && F.reportUnknown) F.reportUnknown('leboncoin', p, { label: fieldLabel, type: 'autre', source, options });
  }

  // Menus dont une valeur de la fiche peut manquer sur le site : intitulé, type de correspondance, valeur de la boutique.
  const UNKNOWN_MENU = {
    marque: ['Marque', 'marque', (p) => p.brand],
    taille: ['Taille', 'taille', (p) => p.size_text],
    pointure: ['Pointure', 'taille', (p) => p.size_text],
    couleur: ['Couleur', 'couleur', (p) => p.colors],
    'état': ['État', 'etat', (p) => (p.condition && p.condition.leboncoin && p.condition.leboncoin.key) || ''],
    'type de chaussures': ['Type de chaussures', 'autre', (p) => p.subcategory_label || p.nature_label],
    genre: ['Genre', 'autre', (p) => p.genre || p.subcategory_label],
  };

  // --- Étapes de remplissage ---

  const TITLE_SELECTOR = "input[name='subject'], input[data-qa-id='adplus-title-input']";
  const BODY_SELECTOR = "textarea#body, textarea[name='body'], textarea[data-spark-component='textarea']";
  const PRICE_SELECTOR = "input[name='price'], input[id*='price' i], input[aria-label*='Prix' i]";
  const MENU_LABELS = ['Univers', 'Marque', 'État', 'Couleur', 'Taille', 'Pointure', 'Genre'];

  function visible(el) {
    return !!el && F0().isVisible(el);
  }

  function findVisible(selector, F, labels) {
    const direct = Array.from(document.querySelectorAll(selector)).find(visible);
    if (direct) return direct;
    const byLabel = F.findByLabels(labels);
    return visible(byLabel) ? byLabel : null;
  }

  const findTitle = (F) => findVisible(TITLE_SELECTOR, F, ['subject', 'titre', 'title']);
  const findBody = (F) => findVisible(BODY_SELECTOR, F, ['description', 'body', 'texte']);
  const findPrice = (F) => findVisible(PRICE_SELECTOR, F, ['prix', 'price']);

  /** Le formulaire s'est-il déployé (catégorie choisie) ? Menus d'attributs ou description visibles. */
  function formExpanded(F) {
    if (visible(findBody(F))) return true;
    return MENU_LABELS.some((label) => F.findMenuTrigger([label]));
  }

  async function stepTitle(p, F) {
    const el = findTitle(F);
    if (!el) { note('titre : champ introuvable'); return { ok: false }; }
    const wanted = p.title || p.name;
    const unchanged = squash(el.value) === squash(wanted);
    const before = suggestionRows().map((r) => r.text).join('|');
    const ok = await F.typeInto(el, wanted);
    if (ok && !unchanged) {
      // Laisse le site recalculer les catégories suggérées pour ce nouveau titre (sinon on cliquerait sur d'anciennes lignes).
      await F.waitFor(() => suggestionRows().map((r) => r.text).join('|') !== before, 4000, 250);
      await F.sleep(900);
    }
    note('titre : ' + (ok ? 'saisi' : 'saisie non confirmée'));
    return { ok };
  }

  /**
   * Lignes de catégories suggérées sous le titre : des boutons-radio (« Maison & Jardin › Décoration »), chacun dans un
   * conteneur role="button". Retourne [{ el, text }] dans l'ordre d'affichage (la première est la meilleure estimation du site).
   */
  function suggestionRows() {
    const rows = [];
    for (const radio of document.querySelectorAll('main input[type="radio"], input[type="radio"]')) {
      const row = radio.closest('[role="button"], li, label');
      if (!row || rows.some((r) => r.el === row) || !visible(row)) continue;
      rows.push({ el: row, text: squash(row.innerText || row.textContent || '') });
    }
    return rows;
  }

  /** Choisit la ligne qui correspond aux mots de la fiche ; à défaut la première suggestion (signalée à l'utilisateur). */
  function pickSuggestion(p) {
    const rows = suggestionRows();
    if (!rows.length) return null;
    for (const word of categoryWords(p, false).map(squash)) {
      const hit = rows.find((r) => r.text.includes(word));
      if (hit) return { el: hit.el, text: hit.text, guessed: false };
    }
    return { el: rows[0].el, text: rows[0].text, guessed: true };
  }

  async function stepCategory(p, F) {
    // Le titre n'est affiché que sur la première étape : ailleurs, la catégorie est déjà choisie.
    if (!visible(findTitle(F))) return { ok: true, already: true };
    if (formExpanded(F)) return { ok: true, already: true };
    // La page « avance » quand le titre disparaît ou que le formulaire se déploie.
    const moved = () => !visible(findTitle(F)) || formExpanded(F);
    let choice = null;
    for (let attempt = 0; attempt < 3 && !moved(); attempt++) {
      let fresh = await F.waitFor(() => pickSuggestion(p), attempt ? 3000 : 8000, 300);   // relu à chaque essai : le site réaffiche les lignes
      let target = fresh && fresh.el;
      if (fresh) choice = fresh;
      if (!target) {
        // Pas de boutons-radio : repli sur un texte cliquable (autre présentation du site).
        target = await F.waitFor(() => F.findClickableByText(categoryWords(p)), 2000, 300);
        if (!target) { note('catégorie : aucune suggestion affichée'); break; }
        choice = { el: target, text: '', guessed: false };
      }
      const radio = target.querySelector ? target.querySelector('input[type="radio"]') : null;
      F.pressEl(attempt === 1 && radio ? radio : target);
      note('catégorie : clic ' + (attempt + 1) + ' sur « ' + ((choice && choice.text) || '?') + ' »');
      if (await F.waitFor(moved, 4000, 250)) break;
    }
    const ok = !!moved();
    if (!ok) note('catégorie : la page n’a pas avancé');
    return { ok, guessed: !!(choice && choice.guessed), text: (choice && choice.text) || '' };
  }

  async function stepMenus(p, F) {
    const results = {};
    const size = sizeInfo(p);
    const nature = squash(p.nature_label);
    const brand = brandCandidate(p);
    const jobs = [
      ['univers', () => F.chooseMenuOption(['Univers'], unique([autre(p, 'univers')]), { nearest: (texts) => universChoice(p, texts) })],
      ['marque', () => F.chooseMenuOption(['Marque', 'Brand'], brand ? [brand] : [], { search: !!brand, fallback: ['Sans marque', 'Autre'] })],
    ];
    if (nature.includes('chaussure')) {
      // Leboncoin pré-remplit parfois ce menu d'après le titre : on ne l'écrase pas.
      const kinds = unique([autre(p, 'type de chaussures')].concat(
        [p.subcategory_label].flatMap((v) => (v ? String(v).split(/[,/]| et /).concat([String(v)]) : [])).map((v) => v.trim()).filter((v) => v.length > 3)
      ));
      jobs.push(['type de chaussures', () => F.chooseMenuOption(['Type de chaussures'], kinds, { keepExisting: true })]);
    }
    if (nature.includes('livre')) {
      jobs.push(['genre', () => F.chooseMenuOption(['Genre'], unique([p.genre, autre(p, 'genre')]), { fallback: ['Autre'] })]);
    }
    jobs.push(
      [size.label.toLowerCase(), () => F.chooseMenuOption([size.label], size.values)],
      ['couleur', () => F.chooseMenuOption(['Couleur'], colorCandidates(p), { keepExisting: true })],
      ['état', () => {
        const candidates = etatCandidates(p);
        if (candidates.length) return F.chooseMenuOption(['État', 'Etat'], candidates);
        // État absent de la fiche : repli « Bon état » (milieu de l'échelle), signalé à l'utilisateur.
        F.noteIssue('état non renseigné dans la fiche : « Bon état » choisi par défaut, à corriger.');
        return F.chooseMenuOption(['État', 'Etat'], ['Bon état', 'État correct', 'État satisfaisant']);
      }],
    );
    for (const [name, run] of jobs) {
      await F.sleep(180 + Math.floor(Math.random() * 320));
      let res = null;
      try { res = await run(); } catch (e) { res = { ok: false, error: (e && e.message) || String(e) }; }
      results[name] = res;
      // Menu présent, liste lue, mais aucune entrée ne convient : la pièce sera passée et le manque signalé au portail.
      if (name === 'univers' && res && !res.ok && !res.absent && !res.skipped && res.options && res.options.length) res.unresolved = true;
      // Valeur de la fiche absente de la liste du site (menu non rempli, ou repli « Sans marque » / « Autre » / « Bon état ») : signalée au portail.
      const meta = UNKNOWN_MENU[name];
      if (meta && res && res.options && res.options.length && (res.fallback || (!res.ok && !res.absent && !res.skipped))) {
        const F0r = F0();
        if (F0r && F0r.reportUnknown) F0r.reportUnknown('leboncoin', p, { label: meta[0], type: meta[1], source: meta[2](p), options: res.options });
      }
      if (res && res.nearest) note(name + ' : entrée la plus proche choisie (' + (res.value || '?') + ')');
      if (!(res && res.absent)) {
        note(name + ' : ' + (res && res.ok ? (res.already ? 'déjà ' : res.kept ? 'conservé ' : 'ok ') + (res.value || '') + (res.fallback ? ' (repli)' : '') : res && res.skipped ? 'pas de valeur' : 'échec' + (res && res.error ? ' (' + res.error + ')' : '')));
      }
    }
    return results;
  }

  async function stepBody(p, F) {
    const el = findBody(F);
    if (!el) return { ok: false };
    const text = String(p.description || '').trim();
    if (!text) return { ok: true, skipped: true };
    return { ok: await F.typeInto(el, text) };
  }

  async function stepPrice(p, F) {
    const el = findPrice(F);
    if (!el) return { ok: false };
    const text = priceText(p);
    if (!text) return { ok: false };
    return { ok: await F.typeInto(el, text) };
  }

  /**
   * Remplit le formulaire dans l'ordre de Leboncoin. Retourne { done, pending, retries } :
   * « retries » sont les étapes à retenter quand un champ apparaît plus tard (mode manuel).
   */
  async function fillFields(p, F, quiet) {
    const product = p || {};
    note('début du remplissage : ' + (product.title || product.name || '?'));
    const done = [];
    const retries = [];
    const record = (name, res, run) => {
      if (res && res.ok) {
        done.push(name);
        if (res.fallback) F.noteIssue(name + ' : valeur de la fiche absente de la liste du site, « ' + (res.value || res.fallback) + ' » choisi par défaut.');
        return;
      }
      if (res && (res.absent || res.skipped)) return;   // champ absent sur cette page : pas une erreur
      retries.push([name, run]);
      // Titre, description et prix sont répartis sur plusieurs pages : leur absence ponctuelle est normale. Seuls les menus comptent.
      if (!['titre', 'catégorie', 'description', 'prix'].includes(name)) {
        F.noteIssue(name + ' : non rempli (valeur de la fiche absente de la liste du site, ou champ modifié).');
      }
    };
    // Pause variable entre deux champs, avec de temps en temps un temps de relecture plus long.
    const pause = () => F.sleep(180 + Math.floor(Math.random() * 320) + (Math.random() < 0.08 ? 1000 + Math.floor(Math.random() * 1500) : 0));

    record('titre', await stepTitle(product, F).catch((e) => { note('titre : erreur ' + (e && e.message || e)); return { ok: false }; }), () => stepTitle(product, F));
    await pause();
    const category = await stepCategory(product, F).catch((e) => { note('catégorie : erreur ' + (e && e.message || e)); return { ok: false }; });
    record('catégorie', category, () => stepCategory(product, F));
    if (category.ok && category.guessed) {
      // Aucune suggestion ne correspond à la fiche : on a pris la première, à vérifier par le vendeur.
      done[done.indexOf('catégorie')] = 'catégorie (1re suggestion du site : ' + (category.text || '?') + ')';
      F.noteIssue('catégorie prise par défaut (1re suggestion du site) : ' + (category.text || '?') + '.');
    }
    await pause();

    const menus = await stepMenus(product, F);
    let skip = '';
    for (const [name, res] of Object.entries(menus)) {
      if (res && res.unresolved) {
        skip = 'menu « Univers » : aucune entrée ne correspond à la pièce (' + res.options.slice(0, 12).join(', ') + '). À renseigner dans Correspondances du portail.';
        reportUnknown(product, 'Univers', res.options);
        F.noteIssue('univers : aucune entrée proche parmi ' + res.options.slice(0, 12).join(', ') + ' — à choisir à la main, et à renseigner dans Correspondances.');
        continue;
      }
      const redo = async () => (await stepMenus(product, F))[name];
      record(name, res, redo);
    }
    await pause();
    record('description', await stepBody(product, F).catch(() => ({ ok: false })), () => stepBody(product, F));
    await pause();
    record('prix', await stepPrice(product, F).catch(() => ({ ok: false })), () => stepPrice(product, F));

    if (!quiet) watchLater(retries.slice(), F);
    return { done, pending: retries.map(([n]) => n), skip };
  }

  // Retente les champs absents quand la page change (étape suivante du formulaire) ; s'arrête quand tout est rempli, ou après 15 minutes.
  let watcher = null;
  function watchLater(pending, F) {
    if (watcher) { watcher.disconnect(); watcher = null; }
    if (!pending.length) return;
    let timer = null;
    let busy = false;
    const started = Date.now();
    const attempt = async () => {
      timer = null;
      if (busy) return;
      busy = true;
      try {
        const filled = [];
        const left = [];
        for (const item of pending) {
          let res = null;
          try { res = await item[1](); } catch (_) { res = null; }
          if (res && (res.ok || res.absent || res.skipped)) { if (res.ok) filled.push(item[0]); } else left.push(item);
        }
        pending = left;
        if (filled.length) {
          F.toast('Champs remplis à leur apparition : ' + filled.join(', ') +
            (pending.length ? '. En attente : ' + pending.map(([n]) => n).join(', ') + '.' : '. Vérifiez la catégorie et publiez vous-même.'));
        }
      } finally {
        busy = false;
      }
      if (!pending.length || Date.now() - started > 15 * 60 * 1000) { if (watcher) watcher.disconnect(); watcher = null; }
    };
    watcher = new MutationObserver(() => { if (!timer && !busy) timer = setTimeout(attempt, 800); });
    watcher.observe(document.body, { childList: true, subtree: true });
  }

  /** Les repères du formulaire attendus sur la page affichée (pour détecter un changement du site). */
  function selftest(F) {
    if (/\/options/.test(location.pathname)) {
      return F.probeAnchors('écran « Boostez votre annonce »', [['bouton « Déposer sans booster »', () => F.findPublishButton()]]);
    }
    if (findBody(F)) {
      return F.probeAnchors('aperçu final', [
        ['titre', () => findTitle(F)], ['description', () => findBody(F)], ['prix', () => findPrice(F)], ['bouton « Continuer »', () => F.findNextButton()],
      ]);
    }
    if (visible(findTitle(F))) return F.probeAnchors('titre et catégorie', [['champ titre', () => findTitle(F)]]);
    if (document.querySelector('input[type=file]')) {
      const anchors = [['champ photos', () => document.querySelector('input[type=file]')], ['bouton « Continuer »', () => F.findNextButton()]];
      if (Array.from(document.querySelectorAll('button')).some((b) => /Retirer la photo/i.test(b.getAttribute('aria-label') || ''))) {
        anchors.push(['listes déroulantes', () => MENU_LABELS.map((l) => F.findCombo([l]) || F.findMenuTrigger([l])).find(Boolean)]);
      }
      return F.probeAnchors('photos et attributs', anchors);
    }
    return { page: 'page non reconnue', found: [], missing: ['page de dépôt reconnue'] };
  }

  // --- Annonces en ligne : mise à jour et suppression (lancées depuis la popup) ---

  const MINE = '/compte/part/mes-annonces';

  /** Cartes d'« Mes annonces » : { card, id, text }. Chaque carte contient ses boutons « Supprimer » et « Modifier ». */
  function adCards(F) {
    const cards = [];
    for (const a of document.querySelectorAll('a[href^="/ad/"]')) {
      let card = a;
      for (let i = 0; i < 8 && card; i++) {
        if (Array.from(card.querySelectorAll('button')).some((b) => /^supprimer$/i.test((b.innerText || '').trim()))) break;
        card = card.parentElement;
      }
      if (!card || cards.some((c) => c.card === card)) continue;
      cards.push({ card, id: F.idFromUrl(a.getAttribute('href')), text: card.innerText || '' });
    }
    return cards;
  }

  function findAdCard(F, target) {
    return adCards(F).find((c) => (target.id ? c.id === target.id : F.titleMatches(c.text, target.title)));
  }

  async function manageStep(msg, F) {
    const kind = msg.kind;
    const p = msg.product || {};
    const target = msg.target || {};
    const path = location.pathname;
    const blocked = (reason) => ({ ok: true, action: 'blocked', reason });

    if (/^\/annonce\/\d+\/editer/.test(path)) {
      if (kind === 'delete') { location.assign(MINE); return { ok: true, action: 'next' }; }
      const subject = await F.waitFor(() => document.querySelector('input[name="subject"]'), 15000, 400);
      if (!subject) return blocked('formulaire de modification introuvable.');
      const body = document.querySelector('textarea[name="body"]');
      const price = document.querySelector('input[name="price_cents"]');
      await F.typeInto(subject, String(p.title || p.name || ''));
      if (body && String(p.description || '').trim()) await F.typeInto(body, String(p.description));
      if (price && priceText(p)) await F.typeInto(price, priceText(p));
      if (msg.dryRun) return { ok: true, action: 'prepared', reason: 'mise à jour prête à valider (simulation)' };
      const save = Array.from(document.querySelectorAll('button[type="submit"]')).find((b) => /valider les modifications/i.test(b.innerText || ''));
      if (!save) return blocked('bouton « Valider les modifications » introuvable.');
      F.clickEl(save);
      return { ok: true, action: 'submitted' };
    }

    if (path !== MINE) { location.assign(MINE); return { ok: true, action: 'next' }; }

    const found = await F.waitFor(() => findAdCard(F, target), 12000, 500);
    if (!found) return { ok: true, action: 'not-found', reason: 'annonce introuvable dans « Mes annonces » (déjà supprimée, expirée ou titre modifié ?).' };
    if (kind === 'update') {
      const edit = found.card.querySelector('a[href*="/editer"]');
      if (!edit) return blocked('lien « Modifier » introuvable.');
      location.assign(new URL(edit.getAttribute('href'), location.origin).href);
      return { ok: true, action: 'next' };
    }
    const del = Array.from(found.card.querySelectorAll('button')).find((b) => /^supprimer$/i.test((b.innerText || '').trim()));
    if (!del) return blocked('bouton « Supprimer » introuvable.');
    F.clickEl(del);
    const confirm = await F.waitFor(() => document.querySelector('[data-qa-id="delete-ad-modal-confirm"]'), 6000, 250);
    if (!confirm) return blocked('confirmation de suppression introuvable.');
    if (msg.dryRun) {
      const close = Array.from(document.querySelectorAll('[role="dialog"] button, [aria-modal="true"] button')).find((b) => /fermer/i.test(b.innerText || b.getAttribute('aria-label') || ''));
      if (close) F.clickEl(close);
      return { ok: true, action: 'prepared', reason: 'suppression prête (simulation)' };
    }
    F.clickEl(confirm);
    return { ok: true, action: 'submitted' };
  }

  function manageVerify(msg, F) {
    const target = msg.target || {};
    if (msg.kind === 'update') return { ok: true, done: !/\/editer/.test(location.pathname) };
    // Suppression : la liste est affichée, la fenêtre de confirmation est fermée et la carte a disparu.
    const listShown = location.pathname === MINE && /En ligne \(\d+\)/.test(document.body ? document.body.innerText : '');
    const modalOpen = !!document.querySelector('[data-qa-id="delete-ad-modal-confirm"]');
    return { ok: true, done: listShown && !modalOpen && !findAdCard(F, target) };
  }

  globalThis.AmLbc = {
    etatCandidates, brandCandidate, sizeInfo, colorCandidates, universCandidate, universChoice, categoryWords, priceText, fillFields,
  };

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg) return;
    if (msg.type === 'am-auto-ping') {
      sendResponse({ ready: !!globalThis.AmFill, channel: 'leboncoin' });
      return;
    }
    if (msg.type === 'am-auto-step' && msg.channel === 'leboncoin') {
      (async () => {
        const F = globalThis.AmFill;
        if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
        const res = await F.autoStep({
          product: msg.product || {},
          options: msg.options || {},
          fill: (p, quiet) => fillFields(p, F, quiet),
        });
        const terminal = res && ['prepared', 'publish-clicked', 'blocked', 'published', 'no-match'].includes(res.action);
        if (res && res.action === 'blocked') {
          let steps = '';
          try { steps = sessionStorage.getItem('amLbcTrace') || ''; } catch (_) {}
          if (steps) res.reason = (res.reason ? res.reason + ' · ' : '') + 'détail : ' + steps;
          const probe = selftest(F);
          if (probe.missing.length >= 2) {
            res.drift = probe.missing;
            res.reason = (res.reason ? res.reason + ' · ' : '') + 'le formulaire Leboncoin a peut-être changé (repères absents : ' + probe.missing.join(', ') + ')';
            F.raiseAlert('block', 'Leboncoin : le formulaire semble avoir changé (page « ' + probe.page + ' », repères absents : ' + probe.missing.join(', ') + '). Remplissez à la main et mettez l’extension à jour.');
          }
        }
        if (terminal) {
          const issues = F.takeIssues();
          if (issues.length) res.reason = (res.reason ? res.reason + ' · ' : '') + '⚠ ' + issues.join(' ; ');
        }
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-selftest' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      sendResponse(F ? { ok: true, ...selftest(F) } : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type === 'am-manage-step' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
      manageStep(msg, F).then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-manage-verify' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      sendResponse(F ? manageVerify(msg, F) : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto(msg) : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type !== 'am-fill' || msg.channel !== 'leboncoin') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const { done, pending } = await fillFields(p, F, false);

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      await F.waitFor(() => document.querySelector('input[type=file]'), 6000, 300);
      const photoRes = await F.tryAttachPhotos(photos);
      if (photoRes.ok > 0) {
        // Sur Leboncoin, les menus (Univers, Pointure, Marque, État…) n'apparaissent qu'après l'ajout d'une photo : seconde passe.
        await F.sleep(3500);
        const second = await fillFields(p, F, false);
        for (const name of second.done) if (!done.includes(name)) done.push(name);
        pending.length = 0;
        second.pending.forEach((n) => pending.push(n));
      }
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      const issues = F.takeIssues();
      const probe = selftest(F);
      if (!done.length && probe.missing.length >= 2) {
        F.raiseAlert('block', 'Leboncoin : le formulaire semble avoir changé (page « ' + probe.page + ' », repères absents : ' + probe.missing.join(', ') + '). Remplissez à la main et mettez l’extension à jour.');
      }
      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à venir quand le champ apparaît : ' + pending.join(', ') : '') +
          photoMsg +
          (issues.length ? ' · ⚠ ' + issues.join(' ; ') : '') +
          '. Vérifiez la catégorie et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
