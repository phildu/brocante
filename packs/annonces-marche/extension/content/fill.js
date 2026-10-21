/* Helpers partagés : remplir des champs sans contourner les contrôles du site. */
(function (global) {
  function setNativeValue(el, value) {
    if (!el) return false;
    const proto =
      el instanceof HTMLTextAreaElement
        ? HTMLTextAreaElement.prototype
        : HTMLInputElement.prototype;
    const desc = Object.getOwnPropertyDescriptor(proto, 'value');
    if (desc && desc.set) desc.set.call(el, value);
    else el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }

  function findByLabels(candidates) {
    const inputs = Array.from(
      document.querySelectorAll('input:not([type=hidden]):not([type=file]):not([type=checkbox]):not([type=radio]), textarea')
    );
    const lower = candidates.map((c) => c.toLowerCase());
    for (const el of inputs) {
      if (el.disabled || el.readOnly) continue;
      const bits = [
        el.getAttribute('name'),
        el.getAttribute('id'),
        el.getAttribute('placeholder'),
        el.getAttribute('aria-label'),
        el.getAttribute('data-testid'),
      ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();
      if (lower.some((c) => bits.includes(c))) return el;
      const label =
        (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) ||
        el.closest('label');
      const labelText = (label && label.textContent ? label.textContent : '').toLowerCase();
      if (lower.some((c) => labelText.includes(c))) return el;
    }
    return null;
  }

  function fillText(candidates, value) {
    if (value == null || value === '') return false;
    const el = findByLabels(candidates);
    return setNativeValue(el, String(value));
  }

  function fillPrice(value) {
    if (value == null || Number.isNaN(Number(value))) return false;
    const text = String(value).replace('.', ',');
    return fillText(['prix', 'price', 'montant'], text) || fillText(['prix', 'price'], String(value));
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
