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
    return findButton([
      /^publier$/,
      /^publier l['’]annonce$/,
      /^publier mon annonce$/,
      /^publier l['’]offre$/,
      /^mettre en ligne$/,
      /^deposer l['’]annonce$/,
      /^valider et publier$/,
    ]);
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
    const text = normalizeLabel(document.body ? document.body.innerText.slice(0, 20000) : '');
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

    for (let attempt = 0; attempt < 3; attempt++) {
      if (attempt) await sleep(1200);

      const challenge = detectChallenge();
      if (challenge) return { ok: true, action: 'blocked', reason: 'vérification demandée (' + challenge + ')', filled, photos };
      if (detectPublished()) return { ok: true, action: 'published', listing_url: location.href, filled, photos };

      try {
        const result = await fill(p, true);
        filled = (result && result.done) || filled;
      } catch (_) {}

      photos = await attachPhotosIfNeeded(opts.runId, photoUrls);

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
        if (opts.dryRun) {
          return { ok: true, action: 'prepared', reason: 'prêt à publier (simulation)', filled, photos };
        }
        clickEl(publish);
        return { ok: true, action: 'publish-clicked', filled, photos };
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

  function verifyAuto() {
    if (detectPublished()) return { ok: true, action: 'published', listing_url: location.href };
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

  global.AmFill = {
    fillText,
    fillPrice,
    tryAttachPhotos,
    toast,
    setNativeValue,
    findByLabels,
    sleep,
    isVisible,
    clickEl,
    findPublishButton,
    findNextButton,
    detectChallenge,
    detectPublished,
    missingRequired,
    autoStep,
    verifyAuto,
  };
})(globalThis);
