(function () {
  'use strict';

  var config = window.__brocsKiosk;
  if (!config) return;

  var STORAGE_KEY = 'brocs_vitrine';
  var params;
  try { params = new URLSearchParams(window.location.search); } catch (e) { params = null; }
  var mode = params ? params.get('vitrine') : null;

  if (mode === '1') {
    try { localStorage.setItem(STORAGE_KEY, '1'); } catch (e) {}
  } else if (mode === '0') {
    try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
  }

  var enabled = mode === '1';
  if (!enabled) {
    try { enabled = localStorage.getItem(STORAGE_KEY) === '1'; } catch (e) {}
  }

  function stripModeParam() {
    if (!params) return;
    params.delete('vitrine');
    var query = params.toString();
    try {
      history.replaceState(null, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
    } catch (e) {}
  }

  function showNote(text) {
    var note = document.createElement('div');
    note.className = 'shop-kiosk-note';
    note.textContent = text;
    document.body.appendChild(note);
    window.setTimeout(function () { note.remove(); }, 6000);
  }

  if (mode === '0') {
    stripModeParam();
    showNote('Mode vitrine désactivé sur cet appareil.');
    return;
  }
  if (!enabled) return;

  var overlay = document.getElementById('shop-kiosk');
  var frame = document.getElementById('shop-kiosk-frame');
  var closeButton = document.getElementById('shop-kiosk-close');
  if (!overlay || !frame || !closeButton) return;

  var idleMs = Math.max(1, Number(config.minutes) || 3) * 60000;
  var timer = null;
  var open = false;
  var previousOverflow = '';
  var lastFocus = null;

  function schedule() {
    window.clearTimeout(timer);
    if (open || document.visibilityState === 'hidden') return;
    timer = window.setTimeout(show, idleMs);
  }

  function show() {
    if (open || document.visibilityState === 'hidden') return;
    open = true;
    lastFocus = document.activeElement;
    frame.src = config.url;
    overlay.hidden = false;
    overlay.setAttribute('aria-hidden', 'false');
    previousOverflow = document.documentElement.style.overflow;
    document.documentElement.style.overflow = 'hidden';
    try { closeButton.focus({ preventScroll: true }); } catch (e) { closeButton.focus(); }
  }

  function hide() {
    if (!open) return;
    open = false;
    overlay.hidden = true;
    overlay.setAttribute('aria-hidden', 'true');
    frame.src = 'about:blank';
    document.documentElement.style.overflow = previousOverflow;
    if (lastFocus && typeof lastFocus.focus === 'function') {
      try { lastFocus.focus({ preventScroll: true }); } catch (e) {}
    }
    schedule();
  }

  closeButton.addEventListener('click', function (event) {
    event.stopPropagation();
    hide();
  });

  window.addEventListener('message', function (event) {
    if (event.origin !== window.location.origin) return;
    if (event.data && event.data.type === 'brocs-kiosk:close') hide();
  });

  ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'].forEach(function (name) {
    window.addEventListener(name, schedule, { passive: true });
  });

  var lastMove = 0;
  window.addEventListener('mousemove', function () {
    var now = Date.now();
    if (now - lastMove < 1000) return;
    lastMove = now;
    schedule();
  }, { passive: true });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') window.clearTimeout(timer);
    else schedule();
  });
  window.addEventListener('pageshow', schedule);

  schedule();

  if (mode === '1') {
    stripModeParam();
    showNote('Mode vitrine activé — diaporama après ' + config.minutes + ' min sans activité.');
  }
})();
