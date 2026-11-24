/* Préremplissage du formulaire Vinted (validation humaine par défaut).
 * Le formulaire tient sur une seule page : photos, titre, description, catégorie (arbre avec recherche), puis — une fois la catégorie
 * choisie — marque, taille, état, couleurs, matériau, prix et format du colis. Le bouton final s'appelle « Ajouter ». */
(function () {
  const F0 = () => globalThis.AmFill;
  const squash = (v) => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

  // --- Correspondances Vinted (libellés exacts des listes) ---
  const ETATS = {
    neuf: 'Neuf sans étiquette',
    neuf_etiquette: 'Neuf avec étiquette',
    neuf_emballe: 'Neuf avec étiquette',
    neuf_boite: 'Neuf sans étiquette',
    comme_neuf: 'Très bon état',
    presque_neuf: 'Très bon état',
    excellent: 'Très bon état',
    tres_bon: 'Très bon état',
    bon: 'Bon état',
    bon_age: 'Satisfaisant',
    patine: 'Satisfaisant',
    correct: 'Satisfaisant',
    usage: 'Satisfaisant',
    defauts: 'Satisfaisant',
    use: 'Satisfaisant',
    restaurer: 'Satisfaisant',
    pieces: 'Satisfaisant',
  };
  const COULEURS = {
    noir: 'Noir', gris: 'Gris', blanc: 'Blanc', creme: 'Crème', beige: 'Beige', abricot: 'Abricot', orange: 'Orange', corail: 'Corail',
    rouge: 'Rouge', bordeaux: 'Bordeaux', fuchsia: 'Fuchsia', rose: 'Rose', violet: 'Violet', mauve: 'Violet', bleu: 'Bleu', turquoise: 'Turquoise',
    vert: 'Vert', kaki: 'Kaki', jaune: 'Jaune', moutarde: 'Moutarde', marron: 'Marron', dore: 'Doré', argente: 'Argenté', multicolore: 'Multicolore',
  };

  function unique(list) {
    const seen = new Set();
    return list.filter((v) => {
      const key = squash(v);
      if (!v || !key || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }

  function vt(p) {
    return (p && p.mapped && p.mapped.vinted) || {};
  }

  function etatCandidates(p) {
    const c = (p.condition && p.condition.vinted) || {};
    return unique([vt(p).etat, c.channel_label, ETATS[String(c.key || '').toLowerCase()]]);
  }

  /** Jetons de taille à reconnaître dans « XS / 34 / 6 », « 38 »… */
  function sizeTokens(p) {
    const text = String(p.size_text || '');
    const mapped = vt(p).taille;
    const nature = squash(p.nature_label);
    const tokens = [];
    if (mapped) tokens.push(String(mapped));
    if (nature.includes('chaussure') || /pointure/i.test(text)) {
      const m = text.match(/\b(3[5-9]|4[0-6])(?:[.,]5)?\b/) || String(p.title || '').match(/\b(3[5-9]|4[0-6])\b/);
      if (m) tokens.push(m[0].replace(',', '.'));
    } else {
      const m = text.match(/\b(XXS|XS|S|M|L|XL|XXL|XXXL|3[2-9]|4[0-9]|5[0-2])\b/i);
      if (m) tokens.push(m[1].toUpperCase());
    }
    return unique(tokens);
  }

  function colorNames(p) {
    const mapped = vt(p).couleur;
    const list = Array.isArray(mapped) ? mapped.slice() : mapped ? [mapped] : [];
    const text = squash([p.colors, p.title, p.description].join(' '));
    for (const [word, label] of Object.entries(COULEURS)) {
      if (new RegExp('\\b' + word + '(?:he|e)?s?\\b').test(text)) list.push(label);   // blanc, blanche, blanches, blancs…
    }
    return unique(list).slice(0, 2);
  }

  /** Mots à chercher dans l'arbre des catégories (recherche par nom de catégorie). */
  function categoryWords(p) {
    const parts = [vt(p).categorie, p.subcategory_label, p.nature_label]
      .flatMap((v) => (v ? String(v).split(/[,/]| et /).concat([String(v)]) : []))
      .map((v) => v.trim())
      .filter((v) => v.length > 3);
    const first = [];
    for (const part of unique(parts)) {
      first.push(part);
      first.push(...part.split(/\s+/).filter((w) => w.length > 4));
    }
    return unique(first);
  }

  function genderWord(p) {
    const text = squash([p.title, p.description, p.nature_label].join(' '));
    if (/\b(garcon|fille|bebe|enfant)s?\b/.test(text)) return 'Enfants';
    if (/\bhommes?\b/.test(text) && !/\bfemmes?\b/.test(text)) return 'Hommes';
    return 'Femmes';
  }

  // --- Étapes ---

  function visible(el) {
    return !!el && F0().isVisible(el);
  }

  function rowText(el) {
    let node = el;
    for (let i = 0; i < 5 && node && !(node.innerText || '').trim(); i++) node = node.parentElement;
    return squash((node && node.innerText) || '');
  }

  async function closeDropdowns(F) {
    try { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); } catch (_) {}
    await F.sleep(300);
    // Les listes à choix multiple (couleur, matériau) restent ouvertes après Échap : seul un clic à l'extérieur les referme.
    try {
      document.body.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
      document.body.click();
    } catch (_) {}
    await F.sleep(400);
  }

  async function openField(F, id) {
    const input = document.getElementById(id);
    if (!input || !visible(input)) return null;
    try { input.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) {}
    F.pressEl(input);
    await F.sleep(900);
    return input;
  }

  async function stepText(F, id, value) {
    const el = document.getElementById(id);
    if (!el || !visible(el)) return { ok: false };
    if (value == null || String(value).trim() === '') return { ok: true, skipped: true };
    return { ok: await F.typeInto(el, String(value)) };
  }

  async function stepCategory(p, F) {
    const input = document.getElementById('category');
    if (!input || !visible(input)) return { ok: false };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    const gender = genderWord(p);
    for (const word of categoryWords(p).slice(0, 6)) {
      await openField(F, 'category');
      const search = await F.waitFor(() => Array.from(document.querySelectorAll('input')).find((i) => visible(i) && /trouver une cat/i.test(i.placeholder || '')), 3000, 200);
      if (!search) { await closeDropdowns(F); continue; }
      await F.typeInto(search, word, { keepFocus: true });
      await F.sleep(1500);
      // Les boutons-radio des résultats sont masqués visuellement : on les prend sans test de visibilité, le texte est dans leur <li>.
      const rows = Array.from(document.querySelectorAll('input[type="radio"][id^="catalog-search-"]'));
      if (!rows.length) { await closeDropdowns(F); continue; }
      const preferred = rows.find((r) => rowText(r.closest('li') || r).includes(squash(gender))) || null;
      const pick = preferred || rows[0];
      F.pressEl(pick.closest('label') || pick.closest('li') || pick);
      await F.sleep(1500);
      if (String(document.getElementById('category').value || '').trim()) {
        return { ok: true, guessed: !preferred && rows.length > 1, text: rowText(pick.closest('li') || pick), word };
      }
    }
    await closeDropdowns(F);
    return { ok: false };
  }

  /** Ouvre la liste des marques, cherche `name` et clique l'entrée identique ; null si Vinted ne la connaît pas. */
  async function chooseBrand(F, input, name) {
    const search = await F.waitFor(() => document.getElementById('brand-search-input'), 3000, 200);
    if (!search) return false;
    await F.typeInto(search, name, { keepFocus: true });
    await F.sleep(1500);
    const rows = Array.from(document.querySelectorAll('input[id^="brand-radio"]'));
    const hit = rows.find((r) => rowText(r.closest('li') || r) === squash(name));
    if (!hit) return false;
    F.pressEl(hit.closest('label') || hit.closest('li') || hit);
    await F.sleep(800);
    return !!String(input.value || '').trim();
  }

  async function stepBrand(p, F) {
    const brand = String(p.brand || vt(p).marque || '').trim();
    const input = document.getElementById('brand');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    await openField(F, 'brand');
    // Jamais une marque voisine : la marque de la fiche à l'identique, sinon « Inconnu » (Vinted refuse l'annonce sans marque dans la plupart des catégories).
    if (brand && (await chooseBrand(F, input, brand))) return { ok: true, value: input.value };
    if (!document.getElementById('brand-search-input')) await openField(F, 'brand');
    if (await chooseBrand(F, input, 'Inconnu')) return { ok: true, value: input.value, fallback: 'Inconnu' };
    await closeDropdowns(F);
    return { ok: true, skipped: true, missing: brand };
  }

  async function stepSize(p, F) {
    const input = document.getElementById('size');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    const tokens = sizeTokens(p);
    if (!tokens.length) return { ok: true, skipped: true };
    await openField(F, 'size');
    const options = await F.waitFor(() => {
      const list = Array.from(document.querySelectorAll('[role="checkbox"][data-testid*="size-group"]')).filter(visible);
      return list.length ? list : null;
    }, 3000, 200);
    if (!options) { await closeDropdowns(F); return { ok: false }; }
    const wanted = tokens.map(squash);
    const hit = options.find((o) => squash(o.innerText).split(/[\/\s]+/).some((t) => wanted.includes(t)));
    if (!hit) { const listed = options.map((o) => String(o.innerText || '').trim()).filter(Boolean); await closeDropdowns(F); return { ok: false, options: listed }; }
    F.pressEl(hit);
    await F.sleep(700);
    await closeDropdowns(F);
    return { ok: !!String(input.value || '').trim(), value: input.value };
  }

  async function stepCondition(p, F) {
    const input = document.getElementById('condition');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    let wanted = etatCandidates(p).map(squash);
    if (!wanted.length) {
      // État absent de la fiche : repli « Bon état » (milieu de l'échelle), signalé à l'utilisateur.
      wanted = [squash('Bon état')];
      F.noteIssue('état non renseigné dans la fiche : « Bon état » choisi par défaut, à corriger.');
    }
    await openField(F, 'condition');
    const radios = await F.waitFor(() => {
      const list = Array.from(document.querySelectorAll('input[id^="condition-radio"]'));
      return list.length ? list : null;
    }, 3000, 200);
    if (!radios) { await closeDropdowns(F); return { ok: false }; }
    const rows = radios.map((r) => ({ r, text: rowText(r) }));
    const hit = rows.find((o) => wanted.some((w) => o.text === w || o.text.startsWith(w + ' ')));
    if (!hit) { await closeDropdowns(F); return { ok: false, options: rows.map((o) => o.text).filter(Boolean) }; }
    F.pressEl(hit.r.closest('label') || hit.r.parentElement.parentElement || hit.r);
    await F.sleep(900);
    await closeDropdowns(F);
    return { ok: !!String(input.value || '').trim(), value: input.value };
  }

  async function stepColor(p, F) {
    const input = document.getElementById('color');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    await openField(F, 'color');
    await F.waitFor(() => document.querySelector('[data-testid^="color-"]:not([data-testid^="color_code"]):not([data-testid*="dropdown"])'), 3000, 200);
    const pick = async (list) => {
      let chosen = 0;
      for (const name of list) {
        const cell = Array.from(document.querySelectorAll('[data-testid^="color-"]'))
          .filter((e) => visible(e) && !/color_code|dropdown/.test(e.getAttribute('data-testid') || ''))
          .find((e) => squash(e.innerText) === squash(name));
        if (!cell) continue;
        F.pressEl(cell);
        chosen++;
        await F.sleep(500);
      }
      return chosen;
    };
    const names = colorNames(p);
    await pick(names);
    const listed = Array.from(document.querySelectorAll('[data-testid^="color-"]'))
      .filter((e) => visible(e) && !/color_code|dropdown/.test(e.getAttribute('data-testid') || ''))
      .map((e) => String(e.innerText || '').trim()).filter(Boolean);
    let fallback = '';
    if (!String(input.value || '').trim()) {
      // Vinted exige une couleur : sans couleur lisible dans la fiche (ou absente de sa liste), « Multicolore », signalé à corriger.
      await pick(['Multicolore']);
      if (String(input.value || '').trim()) fallback = 'Multicolore';
    }
    await closeDropdowns(F);
    return { ok: !!String(input.value || '').trim(), value: input.value, fallback, options: listed, wanted: names };
  }

  async function stepMaterial(p, F) {
    const input = document.getElementById('material');
    if (!input || !visible(input) || String(input.value || '').trim()) return { ok: true, skipped: true };
    const wanted = squash(String(p.materials || '').split(/[,;/]/)[0]);
    if (!wanted) return { ok: true, skipped: true };
    await openField(F, 'material');
    const cells = Array.from(document.querySelectorAll('[role="checkbox"][data-testid^="material-"]')).filter(visible);
    // « Métal peint » → « Métal » : à défaut d'une entrée identique, un mot de la fiche qui est une entrée de la liste.
    const words = wanted.split(/\s+/);
    const cell = cells.find((e) => squash(e.innerText) === wanted) || cells.find((e) => words.includes(squash(e.innerText)));
    if (cell) { F.pressEl(cell); await F.sleep(500); }
    const listed = cells.map((e) => String(e.innerText || '').trim()).filter(Boolean);
    await closeDropdowns(F);
    return { ok: true, skipped: !cell, options: listed, missing: cell ? '' : wanted };   // matériau recommandé mais facultatif
  }

  /** Montant lu dans le champ prix de Vinted (« 10,00 € ») ; NaN si le champ est vide ou cassé (« NaN € »). */
  function readAmount(el) {
    const cleaned = String(el.value || '').replace(/[^\d,.-]/g, '').replace(',', '.');
    return cleaned === '' ? NaN : Number(cleaned);
  }

  async function stepPrice(p, F) {
    const n = Number(p.price);
    if (p.price == null || p.price === '' || !Number.isFinite(n)) return { ok: false };
    const el = document.getElementById('price');
    if (!el || !visible(el)) return { ok: false };
    // Vinted met le champ en forme (« 10,00 € ») : on compare les montants, sinon on retaperait le prix à chaque passage (jusqu'à « NaN € »).
    if (Math.abs(readAmount(el) - n) < 0.005) return { ok: true, already: true };
    if (/nan/i.test(String(el.value || ''))) await F.typeInto(el, '');
    const typed = await F.typeInto(el, n.toLocaleString('fr-FR', { useGrouping: false, maximumFractionDigits: 2 }));
    await F.sleep(300);
    return { ok: typed || Math.abs(readAmount(el) - n) < 0.005 };
  }

  /** Format du colis : celui que Vinted recommande pour la catégorie (à défaut, « Moyen »). */
  async function stepPackage(p, F) {
    const radios = Array.from(document.querySelectorAll('input[id^="package_type_selector_"]')).filter((r) => !/_label$/.test(r.id));
    if (!radios.length) return { ok: true, absent: true };
    const rows = radios.map((r) => ({ r, text: rowText(r) }));
    const hit = rows.find((o) => o.text.includes('recommande')) || rows.find((o) => o.text.startsWith('moyen'));
    if (!hit) return { ok: radios.some((r) => r.checked), skipped: !radios.some((r) => r.checked) };
    if (hit.r.checked) return { ok: true, already: true };
    // Vinted peut avoir préselectionné un autre format : on prend celui qu'il recommande pour la catégorie.
    F.pressEl(document.getElementById(hit.r.id + '_label') || hit.r.closest('label') || hit.r);
    await F.sleep(700);
    return { ok: hit.r.checked };
  }

  /** Remplit le formulaire dans l'ordre de Vinted. Retourne { done, pending }. */
  async function fillFields(p, F, quiet) {
    const product = p || {};
    const done = [];
    const retries = [];
    // Valeur de la fiche sans équivalent chez Vinted (menu non rempli, repli, matériau absent) : signalée au portail (« À renseigner »).
    const UNKNOWN = {
      marque: ['Marque', 'marque', (p) => p.brand],
      taille: ['Taille', 'taille', (p) => p.size_text],
      'état': ['État', 'etat', (p) => (p.condition && p.condition.vinted && p.condition.vinted.key) || ''],
      couleur: ['Couleur', 'couleur', (p) => p.colors],
      'matériau': ['Matériau', 'matiere', (p) => p.materials],
    };
    const reportMissing = (name, res) => {
      const meta = UNKNOWN[name];
      if (!meta || !res) return;
      const missed = res.fallback || res.missing || (!res.ok && res.options);
      if (missed) F.reportUnknown('vinted', product, { label: meta[0], type: meta[1], source: meta[2](product), options: res.options || [] });
    };
    const record = (name, res, run) => {
      reportMissing(name, res);
      if (res && res.ok) {
        if (!res.skipped && !res.absent) done.push(name + (res.guessed ? ' (1er choix du site : ' + (res.text || '?') + ')' : '') + (res.fallback ? ' (repli « ' + res.fallback + ' »)' : ''));
        if (res.guessed) F.noteIssue('catégorie prise par défaut (1re suggestion du site) : ' + (res.text || '?') + '.');
        if (res.fallback) F.noteIssue(name + ' : « ' + res.fallback + ' » choisi, la valeur de la fiche n’existe pas chez Vinted.');
        if (res.skipped && res.missing) F.noteIssue(name + ' : « ' + res.missing + ' » introuvable chez Vinted, champ laissé vide.');
        return;
      }
      retries.push([name, run]);
      F.noteIssue(name + ' : non rempli (valeur de la fiche absente de la liste du site, ou champ modifié).');
    };
    // Pause variable entre deux champs, avec de temps en temps un temps de relecture plus long.
    const pause = () => F.sleep(180 + Math.floor(Math.random() * 320) + (Math.random() < 0.08 ? 1000 + Math.floor(Math.random() * 1500) : 0));
    const run = async (name, step) => {
      let res;
      try { res = await step(); } catch (e) { res = { ok: false, error: (e && e.message) || String(e) }; }
      record(name, res, step);
      await pause();
      return res;
    };

    await run('titre', () => stepText(F, 'title', product.title || product.name));
    await run('description', () => stepText(F, 'description', product.description));
    const category = await run('catégorie', () => stepCategory(product, F));
    if (category && category.ok) {
      // Les champs de détail (marque, taille, état…) n'apparaissent qu'après le choix de la catégorie.
      await F.waitFor(() => document.getElementById('condition') && visible(document.getElementById('condition')), 8000, 250);
      await run('marque', () => stepBrand(product, F));
      await run('taille', () => stepSize(product, F));
      await run('état', () => stepCondition(product, F));
      await run('couleur', () => stepColor(product, F));
      await run('matériau', () => stepMaterial(product, F));
    }
    await run('prix', () => stepPrice(product, F));
    await run('colis', () => stepPackage(product, F));

    if (!quiet && retries.length) watchLater(retries.slice(), F);
    return { done, pending: retries.map(([n]) => n) };
  }

  // Retente les champs manquants quand la page change ; s'arrête quand tout est rempli, ou après 15 minutes.
  let watcher = null;
  function watchLater(pending, F) {
    if (watcher) { watcher.disconnect(); watcher = null; }
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
          if (res && res.ok) { if (!res.skipped && !res.absent) filled.push(item[0]); } else left.push(item);
        }
        pending = left;
        if (filled.length) F.toast('Champs remplis à leur apparition : ' + filled.join(', ') + (pending.length ? '. En attente : ' + pending.map(([n]) => n).join(', ') + '.' : '. Vérifiez la catégorie et la livraison, puis ajoutez vous-même.'));
      } finally {
        busy = false;
      }
      if (!pending.length || Date.now() - started > 15 * 60 * 1000) { if (watcher) watcher.disconnect(); watcher = null; }
    };
    watcher = new MutationObserver(() => { if (!timer && !busy) timer = setTimeout(attempt, 1200); });
    watcher.observe(document.body, { childList: true, subtree: true });
  }

  /** Les repères du formulaire Vinted (une seule page) : leur absence signale un changement du site. */
  function selftest(F) {
    const anchors = [
      ['titre', () => document.getElementById('title')],
      ['description', () => document.getElementById('description')],
      ['catégorie', () => document.getElementById('category')],
      ['prix', () => document.getElementById('price')],
      ['photos', () => document.querySelector('input[type="file"]')],
      ['bouton « Ajouter »', () => F.findPublishButton()],
    ];
    const cat = document.getElementById('category');
    if (cat && String(cat.value || '').trim()) {
      anchors.push(['état', () => document.getElementById('condition')], ['marque', () => document.getElementById('brand')]);
    }
    return F.probeAnchors('formulaire « Vends ton article »', anchors);
  }

  // --- Annonces en ligne : mise à jour et suppression (lancées depuis la popup) ---

  const itemIdOfPath = () => (/^\/items\/(\d+)/.exec(location.pathname) || [])[1] || '';

  function manageItemLink(F, target) {
    return Array.from(document.querySelectorAll('a[href*="/items/"]')).find((a) => {
      const href = a.getAttribute('href') || '';
      const id = (/\/items\/(\d+)/.exec(href) || [])[1];
      if (!id) return false;
      return target.id ? id === target.id : F.titleMatches(a.innerText || a.getAttribute('title') || '', target.title);
    });
  }

  async function manageStep(msg, F) {
    const kind = msg.kind;
    const p = msg.product || {};
    const target = msg.target || {};
    const path = location.pathname;
    const id = itemIdOfPath();
    const blocked = (reason) => ({ ok: true, action: 'blocked', reason });

    if (/^\/items\/\d+\/edit/.test(path)) {
      if (target.id && id !== target.id) return blocked('cette page n’est pas l’annonce attendue.');
      if (kind === 'delete') { location.assign('/items/' + id); return { ok: true, action: 'next' }; }
      await F.waitFor(() => { const t = document.getElementById('title'); return t && visible(t); }, 12000, 300);
      await stepText(F, 'title', p.title || p.name);
      await stepText(F, 'description', p.description);
      await stepPrice(p, F);
      if (msg.dryRun) return { ok: true, action: 'prepared', reason: 'mise à jour prête à valider (simulation)' };
      const save = document.querySelector('[data-testid="upload-form-save-button"]');
      if (!save) return blocked('bouton « Valider » introuvable.');
      F.clickEl(save);
      return { ok: true, action: 'submitted' };
    }

    if (id) {   // page de l'article
      if (target.id && id !== target.id) return blocked('cette page n’est pas l’annonce attendue.');
      if (kind === 'update') { location.assign('/items/' + id + '/edit'); return { ok: true, action: 'next' }; }
      const del = await F.waitFor(() => document.querySelector('[data-testid="item-delete-button"]'), 10000, 300);
      if (!del) return blocked('bouton « Supprimer » introuvable (annonce déjà supprimée, vendue ou compte différent).');
      F.clickEl(del);
      const confirm = await F.waitFor(() => document.querySelector('[data-testid="item-delete-confirmation-button"]'), 6000, 250);
      if (!confirm) return blocked('confirmation de suppression introuvable.');
      if (msg.dryRun) {
        const cancel = document.querySelector('[data-testid="item-delete-cancelation-button"]');
        if (cancel) F.clickEl(cancel);
        return { ok: true, action: 'prepared', reason: 'suppression prête (simulation)' };
      }
      F.clickEl(confirm);
      return { ok: true, action: 'submitted' };
    }

    if (/^\/member\/\d+/.test(path)) {   // profil du vendeur : on y retrouve l'annonce
      const link = await F.waitFor(() => manageItemLink(F, target), 10000, 400);
      if (!link) return { ok: true, action: 'not-found', reason: 'annonce introuvable sur votre profil (titre modifié ?).' };
      location.assign(new URL(link.getAttribute('href'), location.origin).href);
      return { ok: true, action: 'next' };
    }

    // Autre page (accueil) : on rejoint le profil par le lien de l'en-tête.
    const me = Array.from(document.querySelectorAll('a[href^="/member/"]'))
      .find((a) => /^\/member\/\d+/.test(a.getAttribute('href') || '') && visible(a) && a.getBoundingClientRect().top < 90);
    if (!me) return blocked('profil introuvable : connectez-vous à Vinted, ou ouvrez votre profil puis réessayez.');
    location.assign(new URL(me.getAttribute('href').split('?')[0], location.origin).href);
    return { ok: true, action: 'next' };
  }

  function manageVerify(msg) {
    if (msg.kind === 'update') return { ok: true, done: !/\/edit/.test(location.pathname) };
    return { ok: true, done: !/^\/items\/\d+/.test(location.pathname) };
  }

  globalThis.AmVinted = { etatCandidates, sizeTokens, colorNames, categoryWords, genderWord, fillFields };

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg) return;
    if (msg.type === 'am-auto-ping') {
      sendResponse({ ready: !!globalThis.AmFill, channel: 'vinted' });
      return;
    }
    if (msg.type === 'am-auto-step' && msg.channel === 'vinted') {
      (async () => {
        const F = globalThis.AmFill;
        if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
        const res = await F.autoStep({
          product: msg.product || {},
          options: msg.options || {},
          fill: (prod, quiet) => fillFields(prod, F, quiet),
        });
        if (res && res.action === 'blocked') {
          const probe = selftest(F);
          if (probe.missing.length >= 2) {
            res.drift = probe.missing;
            res.reason = (res.reason ? res.reason + ' · ' : '') + 'le formulaire Vinted a peut-être changé (repères absents : ' + probe.missing.join(', ') + ')';
            F.raiseAlert('block', 'Vinted : le formulaire semble avoir changé (repères absents : ' + probe.missing.join(', ') + '). Remplissez à la main et mettez l’extension à jour.');
          }
        }
        if (res && ['prepared', 'publish-clicked', 'blocked', 'published'].includes(res.action)) {
          const issues = F.takeIssues();
          if (issues.length) res.reason = (res.reason ? res.reason + ' · ' : '') + '⚠ ' + issues.join(' ; ');
        }
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-selftest' && msg.channel === 'vinted') {
      const F = globalThis.AmFill;
      sendResponse(F ? { ok: true, ...selftest(F) } : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type === 'am-manage-step' && msg.channel === 'vinted') {
      const F = globalThis.AmFill;
      if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
      manageStep(msg, F).then(sendResponse).catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-manage-verify' && msg.channel === 'vinted') {
      sendResponse(manageVerify(msg));
      return;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'vinted') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto(msg) : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type !== 'am-fill' || msg.channel !== 'vinted') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const { done, pending } = await fillFields(p, F, false);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';
      const issues = F.takeIssues();
      const probe = selftest(F);
      if (!done.length && probe.missing.length >= 2) {
        F.raiseAlert('block', 'Vinted : le formulaire semble avoir changé (repères absents : ' + probe.missing.join(', ') + '). Remplissez à la main et mettez l’extension à jour.');
      }
      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à compléter : ' + pending.join(', ') : '') +
          photoMsg +
          (issues.length ? ' · ⚠ ' + issues.join(' ; ') : '') +
          '. Vérifiez catégorie / livraison et ajoutez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
