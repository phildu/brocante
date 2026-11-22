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

  async function stepBrand(p, F) {
    const brand = String(p.brand || vt(p).marque || '').trim();
    if (!brand) return { ok: true, skipped: true };
    const input = document.getElementById('brand');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    await openField(F, 'brand');
    const search = await F.waitFor(() => document.getElementById('brand-search-input'), 3000, 200);
    if (!search) { await closeDropdowns(F); return { ok: false }; }
    await F.typeInto(search, brand, { keepFocus: true });
    await F.sleep(1500);
    const rows = Array.from(document.querySelectorAll('input[id^="brand-radio"]'));
    const hit = rows.find((r) => rowText(r.closest('li') || r) === squash(brand));
    if (!hit) { await closeDropdowns(F); return { ok: true, skipped: true, missing: brand }; }   // marque inconnue de Vinted : on ne met jamais une marque voisine
    F.pressEl(hit.closest('label') || hit.closest('li') || hit);
    await F.sleep(800);
    return { ok: !!String(input.value || '').trim(), value: input.value };
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
    if (!hit) { await closeDropdowns(F); return { ok: false }; }
    F.pressEl(hit);
    await F.sleep(700);
    await closeDropdowns(F);
    return { ok: !!String(input.value || '').trim(), value: input.value };
  }

  async function stepCondition(p, F) {
    const input = document.getElementById('condition');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    const wanted = etatCandidates(p).map(squash);
    if (!wanted.length) return { ok: true, skipped: true };
    await openField(F, 'condition');
    const radios = await F.waitFor(() => {
      const list = Array.from(document.querySelectorAll('input[id^="condition-radio"]'));
      return list.length ? list : null;
    }, 3000, 200);
    if (!radios) { await closeDropdowns(F); return { ok: false }; }
    const hit = radios.map((r) => ({ r, text: rowText(r) })).find((o) => wanted.some((w) => o.text === w || o.text.startsWith(w + ' ')));
    if (!hit) { await closeDropdowns(F); return { ok: false }; }
    F.pressEl(hit.r.closest('label') || hit.r.parentElement.parentElement || hit.r);
    await F.sleep(900);
    await closeDropdowns(F);
    return { ok: !!String(input.value || '').trim(), value: input.value };
  }

  async function stepColor(p, F) {
    const input = document.getElementById('color');
    if (!input || !visible(input)) return { ok: true, absent: true };
    if (String(input.value || '').trim()) return { ok: true, already: true };
    const names = colorNames(p);
    if (!names.length) return { ok: true, skipped: true };
    await openField(F, 'color');
    await F.waitFor(() => document.querySelector('[data-testid^="color-"]:not([data-testid^="color_code"]):not([data-testid*="dropdown"])'), 3000, 200);
    let chosen = 0;
    for (const name of names) {
      const cell = Array.from(document.querySelectorAll('[data-testid^="color-"]'))
        .filter((e) => visible(e) && !/color_code|dropdown/.test(e.getAttribute('data-testid') || ''))
        .find((e) => squash(e.innerText) === squash(name));
      if (!cell) continue;
      F.pressEl(cell);
      chosen++;
      await F.sleep(500);
    }
    await closeDropdowns(F);
    return { ok: chosen > 0 && !!String(input.value || '').trim(), value: input.value };
  }

  async function stepMaterial(p, F) {
    const input = document.getElementById('material');
    if (!input || !visible(input) || String(input.value || '').trim()) return { ok: true, skipped: true };
    const wanted = squash(String(p.materials || '').split(/[,;/]/)[0]);
    if (!wanted) return { ok: true, skipped: true };
    await openField(F, 'material');
    const cell = Array.from(document.querySelectorAll('[role="checkbox"][data-testid^="material-"]')).filter(visible)
      .find((e) => squash(e.innerText) === wanted);
    if (cell) { F.pressEl(cell); await F.sleep(500); }
    await closeDropdowns(F);
    return { ok: true, skipped: !cell };   // matériau recommandé mais facultatif
  }

  async function stepPrice(p, F) {
    const n = Number(p.price);
    if (p.price == null || p.price === '' || !Number.isFinite(n)) return { ok: false };
    const el = document.getElementById('price');
    if (!el || !visible(el)) return { ok: false };
    return { ok: await F.typeInto(el, n.toLocaleString('fr-FR', { useGrouping: false, maximumFractionDigits: 2 })) };
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
    const record = (name, res, run) => {
      if (res && res.ok) { if (!res.skipped && !res.absent) done.push(name + (res.guessed ? ' (1er choix du site : ' + (res.text || '?') + ')' : '')); return; }
      retries.push([name, run]);
    };
    const pause = () => F.sleep(180 + Math.floor(Math.random() * 320));
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
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'vinted') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto() : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
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
      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à compléter : ' + pending.join(', ') : '') +
          photoMsg +
          '. Vérifiez catégorie / livraison et ajoutez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
