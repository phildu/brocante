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
        el.getAttribute('data-testid'),
        el.getAttribute('data-test-id'),
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

  async function tryAttachPhotos(urls) {
    if (!urls || !urls.length) return { ok: 0, total: 0 };
    const input = document.querySelector('input[type=file][multiple], input[type=file][accept*="image"]');
    if (!input) return { ok: 0, total: urls.length, reason: 'no-file-input' };
    const files = [];
    for (let i = 0; i < Math.min(urls.length, 8); i++) {
      try {
        const res = await fetch(urls[i]);
        if (!res.ok) continue;
        const blob = await res.blob();
        const type = blob.type || 'image/jpeg';
        const ext = type.includes('png') ? 'png' : type.includes('webp') ? 'webp' : 'jpg';
        files.push(new File([blob], 'photo-' + (i + 1) + '.' + ext, { type }));
      } catch (_) {
        /* CORS ou réseau : on ignore cette photo */
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

  global.AmFill = { fillText, fillPrice, tryAttachPhotos, toast, setNativeValue, findByLabels };
})(globalThis);
