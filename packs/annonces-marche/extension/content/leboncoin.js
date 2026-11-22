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
  function note(message) {
    trace.push(message);
    if (trace.length > 12) trace.shift();
    try { sessionStorage.setItem('amLbcTrace', trace.join(' | ')); } catch (_) {}
  }

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
      ['univers', () => F.chooseMenuOption(['Univers'], [universCandidate(p)])],
      ['marque', () => F.chooseMenuOption(['Marque', 'Brand'], brand ? [brand] : [], { search: !!brand, fallback: ['Sans marque', 'Autre'] })],
    ];
    if (nature.includes('livre')) {
      jobs.push(['genre', () => F.chooseMenuOption(['Genre'], unique([p.genre, autre(p, 'genre')]), { fallback: ['Autre'] })]);
    }
    jobs.push(
      [size.label.toLowerCase(), () => F.chooseMenuOption([size.label], size.values)],
      ['couleur', () => F.chooseMenuOption(['Couleur'], colorCandidates(p))],
      ['état', () => F.chooseMenuOption(['État', 'Etat'], etatCandidates(p))],
    );
    for (const [name, run] of jobs) {
      await F.sleep(180 + Math.floor(Math.random() * 320));
      let res = null;
      try { res = await run(); } catch (_) { res = { ok: false }; }
      results[name] = res;
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
    const done = [];
    const retries = [];
    const record = (name, res, run) => {
      if (res && res.ok) { done.push(name); return; }
      if (res && (res.absent || res.skipped)) return;   // champ absent sur cette page : pas une erreur
      retries.push([name, run]);
    };
    const pause = () => F.sleep(180 + Math.floor(Math.random() * 320));

    record('titre', await stepTitle(product, F).catch(() => ({ ok: false })), () => stepTitle(product, F));
    await pause();
    const category = await stepCategory(product, F).catch(() => ({ ok: false }));
    record('catégorie', category, () => stepCategory(product, F));
    if (category.ok && category.guessed) {
      // Aucune suggestion ne correspond à la fiche : on a pris la première, à vérifier par le vendeur.
      done[done.indexOf('catégorie')] = 'catégorie (1re suggestion du site : ' + (category.text || '?') + ')';
      try { sessionStorage.setItem('amLbcCategoryGuess', category.text || '?'); } catch (_) {}
    }
    await pause();

    const menus = await stepMenus(product, F);
    for (const [name, res] of Object.entries(menus)) {
      const redo = async () => (await stepMenus(product, F))[name];
      record(name, res, redo);
    }
    await pause();
    record('description', await stepBody(product, F).catch(() => ({ ok: false })), () => stepBody(product, F));
    await pause();
    record('prix', await stepPrice(product, F).catch(() => ({ ok: false })), () => stepPrice(product, F));

    if (!quiet) watchLater(retries.slice(), F);
    return { done, pending: retries.map(([n]) => n) };
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

  globalThis.AmLbc = {
    etatCandidates, brandCandidate, sizeInfo, colorCandidates, universCandidate, categoryWords, priceText, fillFields,
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
        // La catégorie prise par défaut (aucune suggestion ne correspondait) doit apparaître dans l'historique de la file.
        let guess = '';
        try { guess = sessionStorage.getItem('amLbcCategoryGuess') || ''; } catch (_) {}
        if (res && res.action === 'blocked') {
          let steps = '';
          try { steps = sessionStorage.getItem('amLbcTrace') || ''; } catch (_) {}
          if (steps) res.reason = (res.reason ? res.reason + ' · ' : '') + 'détail : ' + steps;
        }
        if (guess && res && ['prepared', 'publish-clicked', 'blocked', 'published'].includes(res.action)) {
          res.reason = (res.reason ? res.reason + ' · ' : '') + 'catégorie prise par défaut (1re suggestion) : ' + guess;
          try { sessionStorage.removeItem('amLbcCategoryGuess'); } catch (_) {}
        }
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto() : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
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
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à venir quand le champ apparaît : ' + pending.join(', ') : '') +
          photoMsg +
          '. Vérifiez la catégorie et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
