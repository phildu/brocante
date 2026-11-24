/* Helpers partagés : remplir des champs sans contourner les contrôles du site. */
(function (global) {
  function normalizeLabel(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .trim();
  }

  function setNativeValue(el, value) {
    if (!el) return false;
    if (el.isContentEditable) {
      el.textContent = value;
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    const proto = el instanceof HTMLTextAreaElement
      ? HTMLTextAreaElement.prototype
      : el instanceof HTMLSelectElement
        ? HTMLSelectElement.prototype
        : HTMLInputElement.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
    if (desc && desc.set) desc.set.call(el, value);
    else if (el.isContentEditable) el.textContent = value;
    else el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function findByLabels(candidates) {
    const inputs = Array.from(document.querySelectorAll(
      'input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]), textarea, select, [contenteditable="true"]'
    ));
    const lower = candidates.map(normalizeLabel);
    for (const el of inputs) {
      if (el.disabled || el.readOnly || el.getAttribute('aria-disabled') === 'true') continue;
      const bits = [
        el.getAttribute('name'),
        el.getAttribute('id'),
        el.getAttribute('placeholder'),
        el.getAttribute('aria-label'),
        el.getAttribute('title'),
        el.getAttribute('autocomplete'),
        el.getAttribute('data-testid'),
        el.getAttribute('data-test-id'),
        el.getAttribute('data-test'),
        el.getAttribute('data-qa'),
        el.getAttribute('data-cy'),
      ]
        .filter(Boolean)
        .join(' ')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase();
      if (lower.some((c) => bits.includes(c))) return el;
      const labels = Array.from(el.labels || []);
      const wrappingLabel = el.closest('label');
      if (wrappingLabel) labels.push(wrappingLabel);
      const labelledBy = (el.getAttribute('aria-labelledby') || '').split(/\s+/).filter(Boolean);
      const labelText = labels.map((label) => label.textContent || '').join(' ') + ' ' +
        labelledBy.map((id) => document.getElementById(id)?.textContent || '').join(' ');
      if (lower.some((c) => normalizeLabel(labelText).includes(c))) return el;
    }
    return null;
  }

  function setSelectValue(el, value) {
    const wanted = String(value)
      .split(/[,;/|]/)
      .map(normalizeLabel)
      .filter(Boolean);
    if (!wanted.length) return false;
    const options = Array.from(el.options);
    const matching = wanted.map((label) =>
      options.find((option) =>
        normalizeLabel(option.value) === label || normalizeLabel(option.textContent) === label
      )
    );
    if (matching.some((option) => !option)) return false;
    if (el.multiple) {
      const values = new Set(matching.map((option) => option.value));
      options.forEach((option) => { option.selected = values.has(option.value); });
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
      return true;
    }
    if (matching.length !== 1) return false;
    return setNativeValue(el, matching[0].value);
  }

  function fillText(candidates, value) {
    if (value == null || value === '') return false;
    const el = findByLabels(candidates);
    if (!el) return false;
    if (el instanceof HTMLSelectElement) return setSelectValue(el, value);
    if (el.isContentEditable) return setNativeValue(el, String(value));
    return setNativeValue(el, String(value));
  }

  function fillPrice(value) {
    const amount = Number(value);
    if (value == null || value === '' || !Number.isFinite(amount)) return false;
    const el = findByLabels([
      'prix',
      'price',
      'montant',
      'sale price',
      'selling price',
      'prix de vente',
      'your price',
    ]);
    if (!el) return false;
    if (el instanceof HTMLSelectElement || el.isContentEditable) return false;
    const text = el instanceof HTMLInputElement && el.type === 'number'
      ? String(amount)
      : amount.toLocaleString('fr-FR', { useGrouping: false, maximumFractionDigits: 2 });
    return setNativeValue(el, text);
  }

  function blobFromBase64(data, type) {
    const binary = atob(data);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return new Blob([bytes], { type: type || 'image/jpeg' });
  }

  /**
   * Récupère une photo depuis la page. Repli via le service worker de l'extension :
   * lui n'est pas soumis au CORS de la page et peut lire les photos de la boutique.
   */
  async function fetchPhotoBlob(url) {
    try {
      const res = await fetch(url);
      if (res.ok) return await res.blob();
    } catch (_) {
      /* CORS probable : on tente le service worker */
    }
    const remote = await new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type: 'am-fetch-photo', url }, (response) => {
          if (chrome.runtime.lastError) resolve(null);
          else resolve(response || null);
        });
      } catch (_) {
        resolve(null);
      }
    });
    if (!remote || !remote.ok || !remote.data) return null;
    return blobFromBase64(remote.data, remote.type);
  }

  async function tryAttachPhotos(urls) {
    if (!urls || !urls.length) return { ok: 0, total: 0 };
    const input = document.querySelector('input[type=file][multiple], input[type=file][accept*="image"]');
    if (!input) return { ok: 0, total: urls.length, reason: 'no-file-input' };
    const files = [];
    for (let i = 0; i < Math.min(urls.length, 8); i++) {
      try {
        const blob = await fetchPhotoBlob(urls[i]);
        if (!blob) continue;
        const type = blob.type || 'image/jpeg';
        const ext = type.includes('png') ? 'png' : type.includes('webp') ? 'webp' : 'jpg';
        files.push(new File([blob], 'photo-' + (i + 1) + '.' + ext, { type }));
      } catch (_) {
        /* photo ignorée */
      }
    }
    if (!files.length) return { ok: 0, total: urls.length, reason: 'fetch-failed' };
    const dt = new DataTransfer();
    files.forEach((f) => dt.items.add(f));
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));
    return { ok: files.length, total: urls.length };
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  /** Attend qu'une condition devienne vraie (retourne sa valeur) ou que le délai expire (null). */
  async function waitFor(fn, timeoutMs, stepMs) {
    const end = Date.now() + (timeoutMs || 4000);
    for (;;) {
      let value = null;
      try { value = fn(); } catch (_) { value = null; }
      if (value) return value;
      if (Date.now() >= end) return null;
      await sleep(stepMs || 150);
    }
  }

  function squash(value) {
    return normalizeLabel(value).replace(/\s+/g, ' ').trim();
  }

  function readValue(el) {
    return el.isContentEditable ? (el.textContent || '') : String(el.value || '');
  }

  /**
   * Saisie dans un champ contrôlé (React) : focus, sélection du contenu, puis insertion de texte par le navigateur
   * (événements « input » natifs). Repli sur l'écriture directe de la valeur. Termine par un blur : le site valide alors le champ.
   */
  async function typeInto(el, text, opts) {
    if (!el) return false;
    const wanted = String(text == null ? '' : text);
    if (wanted !== '' && squash(readValue(el)) === squash(wanted)) return true;
    try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) {}
    try { el.focus(); } catch (_) {}
    try {
      if (el.isContentEditable) {
        const range = document.createRange();
        range.selectNodeContents(el);
        const selection = getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
      } else if (typeof el.select === 'function') {
        el.select();
      }
    } catch (_) {}
    let inserted = false;
    try { inserted = document.execCommand('insertText', false, wanted); } catch (_) { inserted = false; }
    if (!inserted || squash(readValue(el)) !== squash(wanted)) setNativeValue(el, wanted);
    await sleep(120);
    if (opts && opts.keepFocus) return squash(readValue(el)) === squash(wanted);   // champ de recherche : un blur refermerait la liste de résultats
    // Dans un onglet ouvert en arrière-plan, la page n'a pas le focus : le navigateur ne déclenche alors pas blur / focusout.
    let blurred = false;
    const onBlur = () => { blurred = true; };
    el.addEventListener('focusout', onBlur, { once: true });
    try { el.blur(); } catch (_) {}
    el.removeEventListener('focusout', onBlur);
    if (!blurred) {
      el.dispatchEvent(new FocusEvent('blur', { bubbles: false, relatedTarget: null }));
      el.dispatchEvent(new FocusEvent('focusout', { bubbles: true, relatedTarget: null }));
    }
    return squash(readValue(el)) === squash(wanted);
  }

  const TRIGGER_SELECTOR = 'button, [role="combobox"], [role="button"], [aria-haspopup]';

  /**
   * Bouton qui ouvre un menu déroulant, repéré par son aria-label / ses attributs de test, ou à défaut par le libellé visible
   * placé juste avant lui (« Marque », « Taille »…).
   */
  function findMenuTrigger(labels) {
    const wanted = labels.map(squash).filter(Boolean);
    if (!wanted.length) return null;
    const candidates = Array.from(document.querySelectorAll(TRIGGER_SELECTOR))
      .filter((el) => isVisible(el) && !el.disabled && el.getAttribute('aria-disabled') !== 'true');
    for (const el of candidates) {
      const own = squash([
        el.getAttribute('aria-label'), el.getAttribute('name'), el.id,
        el.getAttribute('data-test-id'), el.getAttribute('data-qa-id'), el.getAttribute('data-testid'),
      ].filter(Boolean).join(' '));
      if (wanted.some((w) => own.includes(w))) return el;
    }
    const labelNodes = Array.from(document.querySelectorAll('label, legend, span, p, div, h3, h4'))
      .filter((el) => isVisible(el) && el.children.length <= 1 && wanted.includes(squash(el.textContent).replace(/\s*\*$/, '')));
    for (const label of labelNodes) {
      let scope = label;
      for (let depth = 0; depth < 3 && scope; depth++) {
        scope = scope.parentElement;
        if (!scope) break;
        const trigger = Array.from(scope.querySelectorAll(TRIGGER_SELECTOR)).find((el) =>
          el !== label && isVisible(el) && !label.contains(el) &&
          (label.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING));
        if (trigger) return trigger;
      }
    }
    return null;
  }

  const OPTION_SELECTOR = '[role="option"], [role="menuitem"], [role="radio"], li, button, a';

  function visibleOptionSet() {
    return new Set(Array.from(document.querySelectorAll(OPTION_SELECTOR)).filter(isVisible));
  }

  function bestOption(options, wantedList) {
    const items = options.map((el) => ({ el, text: squash(el.innerText || el.textContent || '') })).filter((o) => o.text);
    for (const wantedRaw of wantedList) {
      const wanted = squash(wantedRaw);
      if (!wanted) continue;
      const exact = items.find((o) => o.text === wanted);
      if (exact) return { el: exact.el, wanted: wantedRaw };
      const short = wanted.replace(/ - /g, '-').split('/')[0].trim();
      const near = items.find((o) => o.text.length <= wanted.length + 25 && (o.text.includes(wanted) || (short && o.text.replace(/ - /g, '-').includes(short))));
      if (near) return { el: near.el, wanted: wantedRaw };
    }
    return null;
  }

  /**
   * Ouvre un menu déroulant et y choisit la première valeur proposée trouvée dans la liste.
   * Retourne { ok, absent, value } : « absent » = le champ n'existe pas sur cette page (pas une erreur).
   * options.search : saisit la valeur dans le champ de recherche du menu (marques) ; options.fallback : valeurs de repli (« Autre »).
   */
  /**
   * Liste déroulante « combobox » (Leboncoin) : un <label>, un champ role="combobox", un bouton « Ouvrir la liste » et une
   * <ul role="listbox"> dont les options sont toujours dans la page (même fermée, elles ont une hauteur : on s'en remet à aria-expanded).
   */
  function findCombo(labels) {
    const wanted = labels.map(squash).filter(Boolean);
    const label = Array.from(document.querySelectorAll('label'))
      .find((l) => isVisible(l) && wanted.includes(squash(l.textContent).replace(/\s*\*$/, '')));
    if (!label) return null;
    let box = label.parentElement;
    for (let depth = 0; depth < 3 && box; depth++, box = box.parentElement) {
      const listbox = box.querySelector('[role="listbox"]');
      const input = box.querySelector('input[role="combobox"]');
      if (listbox && input) {
        return { box, input, listbox, toggle: box.querySelector('button[aria-label*="liste" i], button[aria-controls], button[aria-haspopup]') };
      }
    }
    return null;
  }

  async function chooseComboOption(combo, wanted, opts) {
    const { input, listbox, toggle } = combo;
    const current = squash(input.value);
    if (current && opts.keepExisting) return { ok: true, value: input.value, already: true, kept: true };
    if (current && wanted.some((w) => squash(w) === current)) return { ok: true, value: input.value, already: true };

    const isOpen = () => input.getAttribute('aria-expanded') === 'true';
    const open = async () => {
      if (isOpen()) return true;
      pressEl(toggle || input);
      if (await waitFor(isOpen, 1500, 150)) return true;
      if (toggle) { pressEl(input); return !!(await waitFor(isOpen, 1500, 150)); }
      return false;
    };
    const close = async () => {
      if (isOpen()) { pressEl(toggle || input); await sleep(250); }
    };
    const options = () => Array.from(listbox.querySelectorAll('[role="option"]'));

    if (!(await open())) return { ok: false, absent: false };
    let found = null;
    const listedTexts = () => options().map((o) => String(o.innerText || o.textContent || '').trim()).filter(Boolean);
    const seen = listedTexts();   // liste complète lue avant tout filtrage par saisie
    if (!opts.search && wanted.length) found = bestOption(options(), wanted);
    if (!found && typeof opts.nearest === 'function') {
      // Aucune valeur de la fiche dans la liste : on laisse l'appelant choisir l'entrée la plus proche de la pièce.
      const near = opts.nearest(seen);
      if (near) { found = bestOption(options(), [near]); if (found) found.nearest = true; }
    }
    if (!found && wanted.length) {
      // Longue liste (marques) : on filtre en tapant dans le champ.
      await typeInto(input, String(wanted[0]));
      await sleep(800);
      if (!isOpen()) await open();
      found = bestOption(options(), wanted);
    }
    let usedFallback = false;
    if (!found && (opts.fallback || []).length) {
      // La liste n'affiche qu'un extrait : on tape chaque valeur de repli (« Sans marque », « Autre ») pour la faire apparaître.
      for (const value of opts.fallback) {
        await typeInto(input, String(value));
        await sleep(800);
        if (!isOpen()) await open();
        found = bestOption(options(), [value]);
        if (found) { usedFallback = true; break; }
      }
    }
    if (!found) {
      try { await typeInto(input, ''); } catch (_) {}
      await close();
      return { ok: false, absent: false, options: seen };
    }
    try { found.el.scrollIntoView({ block: 'nearest' }); } catch (_) {}
    pressEl(found.el);
    await sleep(500);
    if (isOpen()) await close();
    return { ok: !!squash(input.value), value: input.value, fallback: usedFallback, nearest: !!found.nearest };
  }

  async function chooseMenuOption(labels, wantedList, options) {
    const opts = options || {};
    const wanted = (wantedList || []).filter((v) => v != null && String(v).trim() !== '');
    const combo = findCombo(labels);
    const canGuess = typeof opts.nearest === 'function';
    if (combo) {
      if (!wanted.length && !(opts.fallback || []).length && !canGuess) return { ok: false, absent: false, skipped: true };
      return chooseComboOption(combo, wanted, opts);
    }
    const trigger = findMenuTrigger(labels);
    if (!trigger) return { ok: false, absent: true };
    if (!wanted.length && !(opts.fallback || []).length && !canGuess) return { ok: false, absent: false, skipped: true };

    const current = squash(trigger.innerText || trigger.textContent || '');
    if (wanted.some((w) => squash(w) && current === squash(w))) return { ok: true, value: current, already: true };

    const before = visibleOptionSet();
    try { trigger.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) {}
    trigger.click();
    await sleep(500);

    let box = null;
    if (opts.search && wanted.length) {
      box = Array.from(document.querySelectorAll('input[type="search"], input[placeholder*="echercher" i], input[placeholder*="earch" i]'))
        .find((el) => isVisible(el) && !el.closest('header, nav')) || null;
      if (box) {
        await typeInto(box, String(wanted[0]));
        box.focus();
        await sleep(800);
      }
    }

    const listed = () => Array.from(visibleOptionSet()).filter((el) => !before.has(el) && el !== trigger && !trigger.contains(el));
    let found = bestOption(listed(), wanted);
    if (!found) found = bestOption(Array.from(visibleOptionSet()).filter((el) => el !== trigger && !trigger.contains(el)), wanted);
    const seen = listed().map((el) => String(el.innerText || el.textContent || '').trim()).filter(Boolean);
    if (!found && canGuess) {
      const near = opts.nearest(seen);
      if (near) { found = bestOption(listed(), [near]); if (found) found.nearest = true; }
    }
    let usedFallback = false;
    if (!found && (opts.fallback || []).length) {
      found = bestOption(listed(), opts.fallback);
      if (!found && box) {
        // La recherche n'a rien donné : on vide le champ pour retrouver « Autre » / « Sans marque » dans la liste complète.
        await typeInto(box, '');
        box.focus();
        await sleep(800);
        found = bestOption(listed(), opts.fallback);
      }
      usedFallback = !!found;
    }
    if (!found) {
      try { document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })); } catch (_) {}
      await sleep(250);
      if (listed().length) { try { trigger.click(); } catch (_) {} }
      return { ok: false, absent: false, options: seen };
    }
    clickEl(found.el);
    await sleep(450);
    return { ok: true, value: String(found.wanted), fallback: usedFallback, nearest: !!found.nearest };
  }

  /** Élément cliquable dont le texte visible correspond à l'un des libellés (suggestions de catégorie…). */
  function findClickableByText(texts, root) {
    const wanted = texts.map(squash).filter(Boolean);
    if (!wanted.length) return null;
    const scope = root || document.querySelector('main, [role="main"]') || document;
    const candidates = Array.from(scope.querySelectorAll(
      'button, [role="button"], [role="radio"], [role="option"], [role="menuitem"], a, label, li, [tabindex]'
    )).filter((el) => isVisible(el) && !el.disabled && el.getAttribute('aria-disabled') !== 'true' &&
      !el.closest('header, nav, footer'));
    const items = candidates
      .map((el) => ({ el, text: squash(el.innerText || el.textContent || '') }))
      .filter((o) => o.text && o.text.length <= 80 && !BLOCKED_BUTTON.test(o.text));
    for (const w of wanted) {
      const exact = items.find((o) => o.text === w);
      if (exact) return exact.el;
    }
    for (const w of wanted) {
      const near = items.find((o) => o.text.includes(w));
      if (near) return near.el;
    }
    return null;
  }

  function isVisible(el) {
    if (!el || !el.isConnected) return false;
    const style = getComputedStyle(el);
    if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false;
    const rect = el.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  }

  function clickEl(el) {
    if (!el) return false;
    try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) {}
    el.click();
    return true;
  }

  /** Clic complet (survol, appui, relâchement, clic) : certains composants du site ignorent un simple click(). */
  function pressEl(el) {
    if (!el) return false;
    try { el.scrollIntoView({ block: 'center', inline: 'nearest' }); } catch (_) {}
    const rect = el.getBoundingClientRect();
    const init = { bubbles: true, cancelable: true, view: window, button: 0, clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2 };
    try {
      for (const type of ['pointerover', 'pointerenter', 'pointerdown']) el.dispatchEvent(new PointerEvent(type, { ...init, pointerType: 'mouse', isPrimary: true }));
      for (const type of ['mouseover', 'mousedown']) el.dispatchEvent(new MouseEvent(type, init));
      try { el.focus({ preventScroll: true }); } catch (_) {}
      el.dispatchEvent(new PointerEvent('pointerup', { ...init, pointerType: 'mouse', isPrimary: true }));
      el.dispatchEvent(new MouseEvent('mouseup', init));
    } catch (_) {}
    el.click();
    return true;
  }

  function buttonLabel(el) {
    const text = el.innerText || el.textContent || el.value ||
      el.getAttribute('aria-label') || el.getAttribute('title') || '';
    return normalizeLabel(text).replace(/\s+/g, ' ').trim();
  }

  const BLOCKED_BUTTON = /supprimer|delete|annuler|cancel|brouillon|enregistrer|sauvegarder|retour|precedent|modifier|edit|apercu|previsualiser|plus tard/;

  function findButton(patterns) {
    const candidates = Array.from(document.querySelectorAll(
      'button, [role="button"], a, input[type="submit"], input[type="button"]'
    ));
    for (const el of candidates) {
      if (!isVisible(el) || el.disabled || el.getAttribute('aria-disabled') === 'true') continue;
      const label = buttonLabel(el);
      if (!label || BLOCKED_BUTTON.test(label)) continue;
      if (patterns.some((pattern) => pattern.test(label))) return el;
    }
    return null;
  }

  function findPublishButton() {
    // Vinted : le bouton de mise en ligne s'appelle simplement « Ajouter » (le brouillon est « Sauvegarder le brouillon », exclu plus haut).
    const vintedExtra = /(^|\.)vinted\./i.test(location.hostname) ? [/^ajouter$/] : [];
    return findButton(vintedExtra.concat([
      /^publier$/,
      /^publier l['’]annonce$/,
      /^publier mon annonce$/,
      /^publier l['’]offre$/,
      /^mettre en ligne$/,
      /^deposer l['’]annonce$/,
      /^valider et publier$/,
      // Leboncoin : écran « Boostez votre annonce » — on ne choisit jamais d'option payante.
      /^deposer sans booster( mon annonce| l['’]annonce)?$/,
      /^deposer mon annonce$/,
    ]));
  }

  /** Bouton « Sauvegarder le brouillon » (Vinted). Leboncoin n'en propose pas : null. */
  function findDraftButton() {
    const candidates = Array.from(document.querySelectorAll('button, [role="button"], a, input[type="submit"], input[type="button"]'));
    for (const el of candidates) {
      if (!isVisible(el) || el.disabled || el.getAttribute('aria-disabled') === 'true') continue;
      if (/^(sauvegarder|enregistrer)( le| un| mon| en)? brouillon$/.test(buttonLabel(el))) return el;
    }
    return null;
  }

  /** Prix proposé par la plateforme (fourchette ou valeur) affiché sur le formulaire, ou null. */
  function readSuggestedPrice() {
    if (!globalThis.AmQuality || !document.body) return null;
    return AmQuality.parseSuggestedPrice(document.body.innerText.slice(0, 40000));
  }

  /** La suggestion s'affiche après coup (catégorie, marque, état renseignés) : on lui laisse quelques secondes. */
  async function waitSuggestedPrice() {
    for (let i = 0; i < 4; i++) {
      const s = readSuggestedPrice();
      if (s && s.min != null) return s;
      await sleep(1000);
    }
    return readSuggestedPrice();
  }

  function findNextButton() {
    return findButton([
      /^continuer$/,
      /^suivant$/,
      /^next$/,
      /^valider$/,
      /^ok$/,
      /^etape suivante$/,
    ]);
  }

  function detectChallenge() {
    const selectors = [
      'iframe[src*="captcha" i]',
      'iframe[src*="recaptcha" i]',
      'iframe[src*="hcaptcha" i]',
      'iframe[src*="datadome" i]',
      '[id*="captcha" i]',
      '[class*="captcha" i]',
      '[data-testid*="captcha" i]',
    ];
    for (const selector of selectors) {
      const el = document.querySelector(selector);
      if (el && isVisible(el)) return 'captcha';
    }
    const text = normalizeLabel(document.body ? document.body.innerText.slice(0, 20000) : '');
    if (/(verification de securite|verifiez votre identite|code de securite|confirmez votre numero|authentification a deux facteurs|two factor authentication|activite inhabituelle)/.test(text)) {
      return 'verification';
    }
    return '';
  }

  function detectPublished() {
    if (/\/(confirmation|success|merci|annonce-publiee|published)/i.test(location.href)) return true;
    // Vinted : après la mise en ligne, on arrive sur la page de l'article (/items/<numéro>-<titre>), plus sur /items/new.
    if (/(^|\.)vinted\./i.test(location.hostname) && /^\/items\/\d+/.test(location.pathname)) return true;
    const text = normalizeLabel(document.body ? document.body.innerText.slice(0, 20000) : '');
    // Vinted renvoie maintenant sur le profil du vendeur (/member/<n>?promo_shown=…) avec la fenêtre « Article en vente ».
    if (/(^|\.)vinted\./i.test(location.hostname) && /^\/member\/\d+/.test(location.pathname) &&
        (/promo_shown/i.test(location.search) || /article en vente[\s\S]{0,400}ajouter un autre article/.test(text))) return true;
    return /(annonce (est )?(en ligne|publiee|visible)|votre annonce a bien ete publiee|felicitations[^.]{0,40}annonce|article (est )?(en ligne|publie))/.test(text);
  }

  function missingRequired(product) {
    const missing = [];
    for (const el of Array.from(document.querySelectorAll('input, textarea, select'))) {
      if (!isVisible(el) || el.disabled || el.readOnly) continue;
      const type = (el.getAttribute('type') || '').toLowerCase();
      if (['hidden', 'file', 'submit', 'button', 'reset', 'image', 'search'].includes(type)) continue;
      const required = el.required || el.getAttribute('aria-required') === 'true';
      if (!required) continue;
      if (type === 'checkbox' || type === 'radio') {
        if (!el.checked) missing.push('consentement / case obligatoire');
        continue;
      }
      if (String(el.value || '').trim() === '') {
        const label = (el.labels && el.labels[0] ? el.labels[0].textContent : '') ||
          el.getAttribute('aria-label') || el.getAttribute('placeholder') || 'champ obligatoire';
        missing.push(normalizeLabel(label).slice(0, 40) || 'champ obligatoire');
      }
    }
    for (const [name, labels] of [
      ['titre', ['titre', 'title', 'subject', 'ad_title']],
      ['description', ['description', 'body', 'texte', 'contenu']],
      ['prix', ['prix', 'price', 'montant', 'sale price', 'selling price']],
      ['catégorie', ['categorie', 'category']],
    ]) {
      const el = findByLabels(labels);
      if (!el || !isVisible(el) || el.disabled || el.readOnly) continue;
      const value = el.isContentEditable ? el.textContent : el.value;
      if (String(value || '').trim() === '') missing.push(name);
    }
    const bodyText = normalizeLabel(document.body ? document.body.innerText.slice(0, 20000) : '');
    if (!findByLabels(['categorie', 'category']) && /(choisir|selectionner) une categorie/.test(bodyText)) {
      missing.push('catégorie');
    }
    return Array.from(new Set(missing));
  }

  async function attachPhotosIfNeeded(runId, urls) {
    if (!urls || !urls.length) return { ok: 0, total: 0, reason: 'none' };
    const input = document.querySelector('input[type=file][multiple], input[type=file][accept*="image"]');
    if (!input) return { ok: 0, total: urls.length, reason: 'no-file-input' };
    const key = 'amSawFileInput:' + (runId || 'run');
    try { sessionStorage.setItem(key, '1'); } catch (_) {}
    if (input.files && input.files.length) {
      try { sessionStorage.setItem('amPhotosAttached:' + (runId || 'run'), '1'); } catch (_) {}
      return { ok: input.files.length, total: urls.length, reason: 'already' };
    }
    const attachKey = 'amPhotoAttach:' + (runId || 'run') + ':' + location.pathname;
    try {
      if (sessionStorage.getItem(attachKey)) return { ok: 0, total: urls.length, reason: 'already' };
    } catch (_) {}
    const res = await tryAttachPhotos(urls);
    // Ne marquer comme fait qu'en cas de succès : un échec réseau doit pouvoir être retenté.
    if (res.ok > 0) {
      try {
        sessionStorage.setItem(attachKey, '1');
        sessionStorage.setItem('amPhotosAttached:' + (runId || 'run'), '1');
      } catch (_) {}
    }
    return res;
  }

  /**
   * Départ de session : si la page affichée n'est pas le formulaire de dépôt (accueil du site), clique le bouton
   * « Déposer une annonce » (Leboncoin) / « Vends ton article » (Vinted) comme le ferait le vendeur.
   * Renvoie null quand on est déjà sur le formulaire ; sinon { clicked, via } ou { clicked:false, reason }.
   */
  async function openDepositIfNeeded() {
    const vinted = /(^|\.)vinted\./i.test(location.hostname);
    const onForm = vinted ? /^\/items\/new/.test(location.pathname) : /\/deposer-une-annonce/.test(location.pathname);
    if (onForm) return null;
    const label = vinted ? /^(vends (ton article|tes articles)|vendre maintenant)$/ : /^deposer une annonce$/;
    const candidates = () => Array.from(document.querySelectorAll('a, button, [role="button"]'))
      .filter((el) => isVisible(el) && !el.disabled && !/\/help\//.test(el.getAttribute('href') || ''));
    const find = () => {
      const all = candidates();
      // Fenêtre « Article en vente » (Vinted, après une publication) : son bouton mène directement au formulaire.
      const again = vinted ? all.find((el) => /^ajouter un autre article$/.test(squash(el.innerText || el.textContent || ''))) : null;
      return again || all.find((el) => label.test(squash(el.innerText || el.textContent || '')));
    };
    let el = null;
    for (let i = 0; i < 12 && !el; i++) {
      el = find();
      if (!el) await sleep(1000);
    }
    if (!el) {
      // Bouton introuvable (bandeau, changement du site) : on rejoint le formulaire par son adresse.
      location.assign(vinted ? '/items/new' : '/deposer-une-annonce');
      return { clicked: false, reason: 'bouton de dépôt introuvable : accès direct au formulaire' };
    }
    pressEl(el);
    // Navigation du site (script détruit) ou changement de route sans rechargement : on laisse le temps de l'un ou l'autre.
    for (let i = 0; i < 15; i++) {
      await sleep(1000);
      if (vinted ? /^\/items\/new/.test(location.pathname) : /\/deposer-une-annonce/.test(location.pathname)) break;
    }
    return { clicked: true, via: (el.innerText || '').trim().slice(0, 40) };
  }

  /**
   * Une étape atomique : remplit, vérifie, puis clique « Continuer » ou « Publier ».
   * Le service worker orchestre les étapes suivantes (la navigation recrée le content script).
   * Plusieurs tentatives courtes : un formulaire monopage peut afficher ses champs après coup.
   */
  async function autoStep({ product, options, fill }) {
    const opts = options || {};
    const p = product || {};
    const photoUrls = (p.photos || []).map((x) => x && x.url).filter(Boolean);
    let filled = [];
    let missing = [];
    let photos = { ok: 0, total: photoUrls.length, reason: 'none' };

    if (opts.openDeposit) {
      const started = await openDepositIfNeeded();
      if (started) return { ok: true, action: 'next', reason: started.clicked ? 'clic sur « ' + started.via + ' »' : started.reason, filled, photos };
    }

    for (let attempt = 0; attempt < 5; attempt++) {
      if (attempt) await sleep(1200);

      const challenge = detectChallenge();
      if (challenge) return { ok: true, action: 'blocked', reason: 'vérification demandée (' + challenge + ')', filled, photos };
      if (detectPublished()) return { ok: true, action: 'published', listing_url: publishedUrl(p), filled, photos };

      try {
        const result = await fill(p, true);
        filled = (result && result.done) || filled;
        // Un menu obligatoire sans valeur utilisable (ex. Univers) : inutile d'insister, on passe à la pièce suivante.
        if (result && result.skip) return { ok: true, action: 'no-match', reason: result.skip, filled, photos };
      } catch (_) {}

      photos = await attachPhotosIfNeeded(opts.runId, photoUrls);
      // Laisse le site téléverser les photos avant de chercher le bouton « Continuer » (il reste grisé pendant l'envoi).
      if (photos.ok > 0 && photos.reason !== 'already') await sleep(3500);

      missing = missingRequired(p);
      if (missing.length) continue;

      const publish = findPublishButton();
      if (publish) {
        if (photoUrls.length) {
          let sawInput = false;
          let attached = false;
          try {
            sawInput = sessionStorage.getItem('amSawFileInput:' + (opts.runId || 'run')) === '1';
            attached = sessionStorage.getItem('amPhotosAttached:' + (opts.runId || 'run')) === '1';
          } catch (_) {}
          const photoProblem =
            photos.reason === 'fetch-failed' ||
            (photos.reason === 'no-file-input' && !attached);
          if (photoProblem) {
            return {
              ok: true,
              action: 'blocked',
              reason: sawInput
                ? 'photos non jointes automatiquement — ajoutez-les à la main puis reprenez'
                : 'aucun champ photo détecté — ajoutez les photos à la main puis reprenez',
              filled,
              photos,
            };
          }
        }
        const suggested = await waitSuggestedPrice();
        if (opts.draft) {
          const draftBtn = findDraftButton();
          if (draftBtn) {
            clickEl(draftBtn);
            return { ok: true, action: 'draft-clicked', filled, photos, suggested };
          }
          return {
            ok: true,
            action: 'blocked',
            reason: 'cette plateforme n’a pas de brouillon : la fiche est remplie, vérifiez-la et publiez à la main, puis passez à la suivante',
            filled,
            photos,
            suggested,
          };
        }
        if (opts.dryRun) {
          return { ok: true, action: 'prepared', reason: 'prêt à publier (simulation)', filled, photos, suggested };
        }
        clickEl(publish);
        return { ok: true, action: 'publish-clicked', filled, photos, suggested };
      }

      const next = findNextButton();
      if (next) {
        clickEl(next);
        return { ok: true, action: 'next', filled, photos };
      }
    }

    return {
      ok: true,
      action: 'blocked',
      reason: missing.length
        ? 'champs requis : ' + missing.join(', ')
        : 'aucun bouton d’avance détecté',
      filled,
      photos,
    };
  }

  /** Vinted confirme un brouillon par un message (et quitte souvent le formulaire). */
  function detectDrafted() {
    const text = normalizeLabel(document.body ? document.body.innerText.slice(0, 20000) : '');
    return /(brouillon (a ete |est )?(enregistre|sauvegarde|ajoute)|(enregistre|sauvegarde)e? (en|comme) brouillon|ton article a ete (enregistre|sauvegarde))/.test(text);
  }

  /** Identifiant d'une annonce d'après son adresse (Vinted /items/123-…, Leboncoin /ad/<cat>/123 ou /annonce/123/…). */
  function idFromUrl(url) {
    const s = String(url || '');
    const m = /\/items\/(\d{5,})/.exec(s) || /\/ad\/[^/]+\/(\d{5,})/.exec(s) || /\/annonce\/(\d{5,})/.exec(s) || /\/(\d{7,})\.htm/.exec(s);
    return m ? m[1] : '';
  }

  /** Le texte (carte d'annonce, titre de page…) contient-il le début du titre de la pièce ? */
  function titleMatches(text, title) {
    const t = normalizeLabel(title).replace(/\s+/g, ' ').slice(0, 35).trim();
    return t.length >= 6 && normalizeLabel(text).replace(/\s+/g, ' ').includes(t);
  }

  /** Adresse de l'annonce qui vient d'être publiée : sur Vinted, la page profil renvoyée après la mise en ligne liste l'article. */
  function publishedUrl(p) {
    if (/(^|\.)vinted\./i.test(location.hostname) && /^\/member\/\d+/.test(location.pathname)) {
      const title = p && (p.title || p.name);
      const link = Array.from(document.querySelectorAll('a[href*="/items/"]'))
        .find((a) => /\/items\/\d+/.test(a.getAttribute('href') || '') && titleMatches(a.innerText || a.getAttribute('title') || '', title));
      if (link) return new URL(link.getAttribute('href'), location.origin).href;
    }
    return location.href;
  }

  function verifyAuto(opts) {
    if (opts && opts.draft) {
      return detectDrafted() ? { ok: true, action: 'drafted' } : { ok: true, action: 'still-open' };
    }
    if (detectPublished()) return { ok: true, action: 'published', listing_url: publishedUrl(opts && opts.product) };
    const challenge = detectChallenge();
    if (challenge) return { ok: true, action: 'blocked', reason: 'vérification demandée (' + challenge + ')' };
    return { ok: true, action: 'still-open' };
  }

  function toast(message) {
    let el = document.getElementById('am-ext-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'am-ext-toast';
      el.setAttribute('role', 'status');
      Object.assign(el.style, {
        position: 'fixed',
        zIndex: '2147483647',
        right: '16px',
        bottom: '16px',
        maxWidth: '320px',
        padding: '12px 14px',
        background: '#1d1a16',
        color: '#fffaf2',
        font: '14px/1.35 system-ui, sans-serif',
        borderRadius: '10px',
        boxShadow: '0 8px 24px rgba(0,0,0,.25)',
      });
      document.documentElement.appendChild(el);
    }
    el.textContent = message;
    clearTimeout(el._t);
    el._t = setTimeout(() => el.remove(), 6000);
  }

  // --- Alertes du remplissage : ce qui a été choisi par défaut ou n'a pas pu être rempli, remonté à l'utilisateur ---
  function noteIssue(text) {
    try {
      const list = JSON.parse(sessionStorage.getItem('amIssues') || '[]');
      if (!list.includes(text)) list.push(text);
      sessionStorage.setItem('amIssues', JSON.stringify(list.slice(-12)));
    } catch (_) {}
  }

  function takeIssues() {
    try {
      const list = JSON.parse(sessionStorage.getItem('amIssues') || '[]');
      sessionStorage.removeItem('amIssues');
      return Array.isArray(list) ? list : [];
    } catch (_) {
      return [];
    }
  }

  /** Alerte persistante affichée en haut de la popup (« le formulaire a changé », etc.). */
  function raiseAlert(level, message) {
    try { chrome.storage.local.set({ amAlert: { level, message, at: Date.now() } }); } catch (_) {}
  }

  /**
   * Contrôle des repères du formulaire : retourne { page, found, missing }.
   * `anchors` : [[nom, fonction → élément ou null]]. Moins de repères que prévu = la plateforme a probablement changé son formulaire.
   */
  function probeAnchors(page, anchors) {
    const found = [];
    const missing = [];
    for (const [name, find] of anchors) {
      let el = null;
      try { el = find(); } catch (_) { el = null; }
      if (el) found.push(name); else missing.push(name);
    }
    return { page, found, missing };
  }

  global.AmFill = {
    noteIssue,
    takeIssues,
    raiseAlert,
    probeAnchors,
    fillText,
    fillPrice,
    tryAttachPhotos,
    toast,
    setNativeValue,
    findByLabels,
    typeInto,
    waitFor,
    squash,
    findMenuTrigger,
    findCombo,
    chooseMenuOption,
    findClickableByText,
    openDepositIfNeeded,
    sleep,
    isVisible,
    clickEl,
    pressEl,
    findPublishButton,
    findDraftButton,
    readSuggestedPrice,
    detectDrafted,
    findNextButton,
    detectChallenge,
    detectPublished,
    missingRequired,
    autoStep,
    verifyAuto,
    idFromUrl,
    titleMatches,
    publishedUrl,
  };
})(globalThis);
