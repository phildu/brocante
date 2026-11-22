/* Service worker : préremplissage manuel + file de publication locale (opt-in, locale uniquement). */

const CHANNELS = ['leboncoin', 'vinted'];
const ALARM_NEXT = 'am-auto-next';
const ALARM_WATCHDOG = 'am-auto-watchdog';
const LOCK_MS = 5 * 60 * 1000;
const MAX_STEPS = 20;
const ERROR_LIMIT = 2;

const AUTO_DEFAULTS = {
  armed: false,
  armedAt: 0,
  dryRun: true,
  intervalMin: 5,
  dailyCap: 10,
  keepTabs: false,
  depositUrls: {
    leboncoin: 'https://www.leboncoin.fr/deposer-une-annonce',
    vinted: 'https://www.vinted.fr/items/new',
  },
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function getSettings() {
  const { amAutoSettings } = await chrome.storage.local.get('amAutoSettings');
  const saved = amAutoSettings || {};
  return {
    ...AUTO_DEFAULTS,
    ...saved,
    depositUrls: { ...AUTO_DEFAULTS.depositUrls, ...(saved.depositUrls || {}) },
  };
}

async function saveSettings(patch) {
  const current = await getSettings();
  const next = {
    ...current,
    ...(patch || {}),
    depositUrls: { ...current.depositUrls, ...((patch && patch.depositUrls) || {}) },
  };
  if (patch && patch.armed === true && !current.armed) next.armedAt = Date.now();
  if (patch && patch.armed === false) next.armedAt = 0;
  await chrome.storage.local.set({ amAutoSettings: next });
  return next;
}

async function getQueue() {
  const { amQueue } = await chrome.storage.local.get('amQueue');
  return amQueue || null;
}

async function setQueue(queue) {
  await chrome.storage.local.set({ amQueue: queue });
  updateBadge(queue);
}

function updateBadge(queue) {
  let text = '';
  let color = '#1d1a16';
  if (queue && queue.status === 'running') {
    text = String(Math.max(0, (queue.refs || []).length - (queue.index || 0)));
    color = '#1e5f8c';
  } else if (queue && queue.status === 'paused') {
    text = 'P';
    color = '#a15c00';
  }
  chrome.action.setBadgeText({ text });
  chrome.action.setBadgeBackgroundColor({ color });
}

function todayKey() {
  return new Date().toISOString().slice(0, 10);
}

async function getDayCount() {
  const { amAutoDay } = await chrome.storage.local.get('amAutoDay');
  if (!amAutoDay || amAutoDay.day !== todayKey()) return 0;
  return Number(amAutoDay.count) || 0;
}

async function bumpDayCount() {
  const count = (await getDayCount()) + 1;
  await chrome.storage.local.set({ amAutoDay: { day: todayKey(), count } });
  return count;
}

async function getHistory() {
  const { amAutoHistory } = await chrome.storage.local.get('amAutoHistory');
  return Array.isArray(amAutoHistory) ? amAutoHistory : [];
}

async function pushHistory(entry) {
  const history = await getHistory();
  history.push(entry);
  await chrome.storage.local.set({ amAutoHistory: history.slice(-500) });
}

function secureApiBase(raw) {
  let url;
  try { url = new URL(String(raw || '').trim()); } catch (_) { return ''; }
  const localHost = /^(localhost|127\.|\[::1\]|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i.test(url.hostname);
  if (url.protocol === 'https:' || (url.protocol === 'http:' && localHost)) {
    return url.toString().replace(/\/+$/, '');
  }
  return '';
}

async function api(pathname, options = {}) {
  const { apiBase, token } = await chrome.storage.local.get(['apiBase', 'token']);
  if (!apiBase || !token) {
    throw new Error('Boutique non configurée : renseignez l’adresse et le jeton dans Réglages.');
  }
  const base = secureApiBase(apiBase);
  if (!base) {
    throw new Error('Adresse non sécurisée : utilisez HTTPS (HTTP accepté uniquement en local).');
  }
  const headers = { Accept: 'application/json', 'X-Annonces-Token': token, ...(options.headers || {}) };
  if (options.json) headers['Content-Type'] = 'application/json';
  const res = await fetch(base + pathname, {
    method: options.method || 'GET',
    headers,
    body: options.json ? JSON.stringify(options.json) : undefined,
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Erreur HTTP ' + res.status);
  return data;
}

async function clearAlarms() {
  await chrome.alarms.clear(ALARM_NEXT);
  await chrome.alarms.clear(ALARM_WATCHDOG);
}

async function scheduleNext(settings) {
  // En simulation, on enchaîne plus vite (mais jamais en rafale) ; en réel, respect du réglage.
  const minutes = settings.dryRun ? 0.6 : Math.max(2, Math.min(30, Number(settings.intervalMin) || 5));
  const delay = Math.round(minutes * 60000 * (0.85 + Math.random() * 0.3));
  await chrome.alarms.clear(ALARM_NEXT);
  await chrome.alarms.create(ALARM_NEXT, { when: Date.now() + delay });
}

async function ensureWatchdog() {
  const existing = await chrome.alarms.get(ALARM_WATCHDOG);
  if (!existing) await chrome.alarms.create(ALARM_WATCHDOG, { periodInMinutes: 1 });
}

function actionLabel(action) {
  return {
    published: 'publiée',
    prepared: 'prête (simulation)',
    blocked: 'arrêtée',
    error: 'erreur',
  }[action] || action;
}

/* ---------- File d'attente ---------- */

async function startAuto(channel) {
  if (!CHANNELS.includes(channel)) throw new Error('Canal inconnu.');
  const settings = await getSettings();
  if (!settings.armed) {
    throw new Error('Cochez « J’assume le risque » avant de lancer la publication automatique.');
  }
  const existing = await getQueue();
  if (existing && (existing.status === 'running' || existing.status === 'paused')) {
    throw new Error('Une file est déjà en cours : reprenez-la ou arrêtez-la avant d’en lancer une autre.');
  }
  const dayCount = await getDayCount();
  if (dayCount >= settings.dailyCap) {
    throw new Error('Quota quotidien atteint (' + settings.dailyCap + '). Reprenez demain ou augmentez le quota.');
  }

  const selection = await api('/selection');
  const items = Array.isArray(selection.items) ? selection.items : [];

  let published = [];
  try {
    const res = await api('/publications?channel=' + encodeURIComponent(channel));
    published = Array.isArray(res.refs) ? res.refs : [];
  } catch (_) {
    // Ancienne version du serveur : l'historique local reste la seule protection anti-doublon.
  }
  const history = await getHistory();
  const seen = new Set([
    ...published,
    ...history
      .filter((h) => h.channel === channel && (h.action === 'published' || h.action === 'skipped'))
      .map((h) => h.ref),
  ]);

  const refs = items
    .map((item) => item && item.product && item.product.ref)
    .filter((ref) => ref && !seen.has(ref))
    .slice(0, Math.max(0, settings.dailyCap - dayCount));

  if (!refs.length) {
    throw new Error('Aucune pièce à traiter : la sélection est vide ou déjà publiée sur ce canal.');
  }

  const queue = {
    runId: 'run-' + Date.now(),
    channel,
    refs,
    index: 0,
    status: 'running',
    startedAt: Date.now(),
    stats: { published: 0, prepared: 0, blocked: 0, error: 0 },
    consecutiveErrors: 0,
    lockAt: 0,
    activeTabId: null,
    activeRef: '',
    lastMessage: 'File démarrée : ' + refs.length + ' pièce(s).',
  };
  await setQueue(queue);
  await ensureWatchdog();
  processNext().catch(() => {});
  return queue;
}

async function pauseAuto(message) {
  const queue = await getQueue();
  if (!queue || queue.status !== 'running') return queue;
  const paused = {
    ...queue,
    status: 'paused',
    lockAt: 0,
    lastMessage: message || 'File mise en pause.',
  };
  await setQueue(paused);
  await clearAlarms();
  return paused;
}

async function resumeAuto() {
  const queue = await getQueue();
  if (!queue) throw new Error('Aucune file à reprendre.');
  if (queue.status === 'running') throw new Error('La file est déjà en cours.');
  const settings = await getSettings();
  if (!settings.armed) throw new Error('Cochez « J’assume le risque » pour reprendre.');
  if (queue.index >= queue.refs.length) throw new Error('Cette file est terminée : relancez un nouveau passage.');
  const resumed = {
    ...queue,
    status: 'running',
    lockAt: 0,
    consecutiveErrors: 0,
    lastMessage: 'Reprise de la file.',
  };
  await setQueue(resumed);
  await ensureWatchdog();
  processNext().catch(() => {});
  return resumed;
}

async function stopAuto() {
  const queue = await getQueue();
  await clearAlarms();
  if (queue) {
    if (queue.activeTabId) {
      try { await chrome.tabs.remove(queue.activeTabId); } catch (_) {}
    }
    const stopped = {
      ...queue,
      status: 'idle',
      lockAt: 0,
      activeTabId: null,
      activeRef: '',
      lastMessage: 'File arrêtée.',
    };
    await setQueue(stopped);
    return stopped;
  }
  await updateBadge(null);
  return null;
}

async function skipAuto() {
  const queue = await getQueue();
  if (!queue || queue.status !== 'paused') throw new Error('La file n’est pas en pause.');
  const settings = await getSettings();
  if (!settings.armed) throw new Error('Cochez « J’assume le risque » pour reprendre.');

  if (queue.activeTabId) {
    try { await chrome.tabs.remove(queue.activeTabId); } catch (_) {}
  }
  await pushHistory({
    ref: queue.activeRef || queue.refs[queue.index] || '',
    channel: queue.channel,
    action: 'skipped',
    reason: 'ignorée manuellement',
    dryRun: !!settings.dryRun,
    at: Date.now(),
  });

  const nextIndex = queue.index + 1;
  const updated = {
    ...queue,
    index: nextIndex,
    activeTabId: null,
    activeRef: '',
    consecutiveErrors: 0,
    lockAt: 0,
    lastMessage: 'Pièce ignorée.',
  };
  if (nextIndex >= queue.refs.length) {
    await setQueue({ ...updated, status: 'done', lastMessage: 'File terminée.' });
    await clearAlarms();
    return updated;
  }
  await setQueue({ ...updated, status: 'running' });
  await ensureWatchdog();
  processNext().catch(() => {});
  return updated;
}

async function processNext() {
  const queue = await getQueue();
  if (!queue || queue.status !== 'running') return;
  if (queue.lockAt && Date.now() - queue.lockAt < LOCK_MS) return;
  if (queue.index >= queue.refs.length) {
    await setQueue({ ...queue, status: 'done', lockAt: 0, lastMessage: 'File terminée.' });
    await clearAlarms();
    return;
  }

  const settings = await getSettings();
  if ((await getDayCount()) >= settings.dailyCap) {
    await pauseAuto('Quota quotidien atteint (' + settings.dailyCap + ').');
    return;
  }

  const ref = queue.refs[queue.index];
  const locked = { ...queue, lockAt: Date.now(), activeRef: ref };
  await setQueue(locked);

  let tabId = queue.activeTabId || null;
  if (tabId) {
    try {
      const tab = await chrome.tabs.get(tabId);
      tabId = tab && tab.id ? tab.id : null;
    } catch (_) {
      tabId = null;
    }
  }

  try {
    const data = await api('/produit/' + encodeURIComponent(ref));
    const product = data.product;
    if (!product) throw new Error('Produit introuvable.');

    if (!tabId) {
      const depositUrl = (settings.depositUrls[queue.channel] || '').trim() ||
        AUTO_DEFAULTS.depositUrls[queue.channel];
      const tab = await chrome.tabs.create({ url: depositUrl, active: false });
      tabId = tab.id;
    }
    await setQueue({ ...(await getQueue()), activeTabId: tabId });

    await waitForContent(tabId, queue.channel, 45000);
    const result = await runAutoInTab(tabId, queue.channel, product, {
      dryRun: !!settings.dryRun,
      runId: queue.runId,
    });
    await finalizeItem(product, result, tabId, settings);
  } catch (error) {
    await finalizeItem({ ref }, { action: 'error', reason: error.message || 'erreur inconnue' }, tabId, settings);
  }
}

/**
 * Injecte les scripts de la page de dépôt dans l'onglet. Nécessaire quand l'onglet était déjà ouvert à l'installation ou au
 * rechargement de l'extension : Chrome n'y injecte pas les scripts tout seul (« Receiving end does not exist »).
 */
async function injectContent(tabId, channel) {
  const files = ['content/fill.js', channel === 'vinted' ? 'content/vinted.js' : 'content/leboncoin.js'];
  await chrome.scripting.executeScript({ target: { tabId }, files });
}

function isNoReceiver(error) {
  return /Receiving end does not exist|Could not establish connection/i.test((error && error.message) || '');
}

/** Envoie un message à la page de dépôt ; si aucun script n'y écoute, l'injecte puis réessaie une fois. */
async function sendToDeposit(tabId, channel, message) {
  try {
    return await chrome.tabs.sendMessage(tabId, message);
  } catch (error) {
    if (!isNoReceiver(error)) throw error;
    await injectContent(tabId, channel);
    await sleep(400);
    return await chrome.tabs.sendMessage(tabId, message);
  }
}

async function waitForContent(tabId, channel, timeoutMs) {
  const started = Date.now();
  let injected = false;
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await chrome.tabs.sendMessage(tabId, { type: 'am-auto-ping' });
      if (res && res.ready && res.channel === channel) return true;
    } catch (error) {
      // Pas de script dans l'onglet : navigation en cours, ou onglet ouvert avant le rechargement de l'extension.
      if (!injected && isNoReceiver(error) && Date.now() - started > 4000) {
        injected = true;
        try { await injectContent(tabId, channel); } catch (_) {}
      }
    }
    await sleep(600);
  }
  throw new Error(
    'La page de dépôt n’a pas répondu. Vérifiez que l’onglet est connecté au bon site, puis relancez.'
  );
}

async function runAutoInTab(tabId, channel, product, options) {
  let publishClicks = 0;
  for (let step = 0; step < MAX_STEPS; step++) {
    const res = await sendWithRetry(tabId, { type: 'am-auto-step', channel, product, options });
    if (!res || res.ok === false) throw new Error((res && res.error) || 'Étape impossible.');
    if (['blocked', 'prepared', 'published'].includes(res.action)) return res;
    if (res.action === 'publish-clicked') {
      publishClicks++;
      const verified = await verifyPublished(tabId, channel);
      if (verified) return verified;
      if (publishClicks >= 2) {
        return {
          ok: true,
          action: 'blocked',
          reason: 'clic sur « Publier » envoyé, mais la confirmation n’est pas détectée. Vérifiez l’onglet.',
        };
      }
      // Une modale de confirmation peut demander un second clic sur « Publier ».
      await sleep(1500);
      continue;
    }
    // Laisse le temps à une navigation d'étape de démarrer, puis attend le nouveau content script.
    await sleep(2000);
    await waitForContent(tabId, channel, 45000);
  }
  return {
    ok: true,
    action: 'blocked',
    reason: 'trop d’étapes sans publier (limite ' + MAX_STEPS + ').',
  };
}

async function sendWithRetry(tabId, message, attempts = 6) {
  let lastError = null;
  for (let i = 0; i < attempts; i++) {
    try {
      const res = await chrome.tabs.sendMessage(tabId, message);
      if (res !== undefined) return res;
    } catch (error) {
      lastError = error;
    }
    await sleep(700);
  }
  throw new Error((lastError && lastError.message) || 'La page ne répond pas.');
}

async function verifyPublished(tabId, channel) {
  for (let i = 0; i < 6; i++) {
    await sleep(i === 0 ? 1500 : 2500);
    try {
      const res = await chrome.tabs.sendMessage(tabId, { type: 'am-auto-verify', channel });
      if (res && res.action === 'published') {
        return { ok: true, action: 'published', listing_url: res.listing_url };
      }
      if (res && res.action === 'blocked') return { ok: true, action: 'blocked', reason: res.reason };
    } catch (_) {
      // Navigation en cours : on retente.
    }
  }
  let tab = null;
  try { tab = await chrome.tabs.get(tabId); } catch (_) {}
  if (tab && /(confirmation|success|merci|annonce-publiee|published)/i.test(tab.url || '')) {
    return { ok: true, action: 'published', listing_url: tab.url };
  }
  return null;
}

async function finalizeItem(product, result, tabId, settings) {
  const current = await getQueue();
  if (!current) return;

  if (current.status !== 'running') {
    if (tabId) {
      try { await chrome.tabs.remove(tabId); } catch (_) {}
    }
    await setQueue({ ...current, lockAt: 0, activeTabId: null, activeRef: '' });
    return;
  }

  const action = result.action;
  const ref = product.ref || current.activeRef || '';
  const note = action + (result.reason ? ' — ' + result.reason : '') + (settings.dryRun ? ' [simulation]' : '');
  const status = action === 'published' ? 'published' : action === 'error' ? 'error' : 'draft';

  try {
    await api('/marquer', {
      method: 'POST',
      json: {
        product_ref: ref,
        channel: current.channel,
        status,
        listing_url: result.listing_url || '',
        note,
      },
    });
  } catch (_) {
    // L'historique local suffit pour éviter un doublon immédiat.
  }

  const key = ['published', 'prepared', 'blocked'].includes(action) ? action : 'error';
  const stats = { ...(current.stats || {}) };
  stats[key] = (stats[key] || 0) + 1;

  await pushHistory({
    ref,
    channel: current.channel,
    action,
    reason: result.reason || '',
    dryRun: !!settings.dryRun,
    at: Date.now(),
  });
  if (action === 'published' && !settings.dryRun) await bumpDayCount();

  if (tabId && (action === 'published' || action === 'prepared') && !settings.keepTabs) {
    try { await chrome.tabs.remove(tabId); } catch (_) {}
  }

  const advanced = action === 'published' || action === 'prepared';
  const updated = {
    ...current,
    // En cas d'arrêt ou d'erreur, la même pièce est retentée à la reprise (l'onglet reste ouvert).
    index: advanced ? current.index + 1 : current.index,
    stats,
    consecutiveErrors: action === 'error' ? (current.consecutiveErrors || 0) + 1 : 0,
    lockAt: 0,
    activeTabId: advanced ? null : tabId || null,
    activeRef: advanced ? '' : ref,
    lastMessage: ref + ' : ' + actionLabel(action) + (result.reason ? ' — ' + result.reason : ''),
  };

  if (action === 'blocked') {
    // L'onglet passe au premier plan : le commerçant voit l'étape à compléter avant de reprendre.
    if (tabId) {
      try { await chrome.tabs.update(tabId, { active: true }); } catch (_) {}
    }
    await setQueue({
      ...updated,
      status: 'paused',
      lastMessage: 'Arrêt sur ' + ref + ' — ' + (result.reason || action),
    });
    await clearAlarms();
    return;
  }

  if (updated.consecutiveErrors >= ERROR_LIMIT) {
    await setQueue({
      ...updated,
      status: 'paused',
      lastMessage: 'Deux erreurs consécutives : file mise en pause.',
    });
    await clearAlarms();
    return;
  }

  if (advanced && updated.index >= current.refs.length) {
    await setQueue({ ...updated, status: 'done', lastMessage: 'File terminée.' });
    await clearAlarms();
    return;
  }

  await setQueue(updated);
  await ensureWatchdog();
  await scheduleNext(settings);
}

/* ---------- Messages ---------- */

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || !message.type) return false;

  if (message.type === 'am-start-fill') {
    (async () => {
      const { tabId, channel, product } = message;
      if (!Number.isInteger(tabId) || !CHANNELS.includes(channel) || !product) {
        throw new Error('Article ou onglet invalide.');
      }
      await chrome.storage.local.set({
        amSelectedProduct: { product, channel, selectedAt: Date.now() },
        amFillStatus: 'Préremplissage en cours…',
      });
      const result = await sendToDeposit(tabId, channel, { type: 'am-fill', channel, product });
      if (!result || !result.ok) {
        throw new Error((result && result.error) || 'Le formulaire n’a pas répondu. Rechargez la page et réessayez.');
      }
      let historyMessage = '';
      try {
        await api('/marquer', {
          method: 'POST',
          json: {
            product_ref: product.ref,
            channel,
            status: 'draft',
            note: 'Formulaire prérempli depuis l’extension',
          },
        });
      } catch (error) {
        historyMessage = ' Le formulaire est rempli, mais l’historique n’a pas pu être enregistré : ' +
          (error.message || 'erreur inconnue');
      }
      await chrome.storage.local.set({
        amFillStatus: 'Préremplissage effectué. Vérifiez les champs et les photos sur le site.' + historyMessage,
      });
      sendResponse({ ok: true });
    })().catch(async (error) => {
      const text = error.message || 'Impossible de remplir cette page.';
      await chrome.storage.local.set({ amFillStatus: text });
      sendResponse({ ok: false, error: text });
    });
    return true;
  }

  if (message.type === 'am-fetch-photo') {
    // Le service worker lit la photo sans contrainte CORS de la page, puis la renvoie en base64.
    (async () => {
      try {
        const url = String(message.url || '');
        if (!/^https?:\/\//i.test(url)) throw new Error('URL de photo invalide.');
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        let res;
        try {
          res = await fetch(url, { signal: controller.signal });
        } finally {
          clearTimeout(timer);
        }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const type = ((res.headers.get('content-type') || '').split(';')[0] || 'image/jpeg').trim();
        if (!/^image\//i.test(type)) throw new Error('Le fichier n’est pas une image.');
        const buffer = await res.arrayBuffer();
        if (buffer.byteLength > 12 * 1024 * 1024) throw new Error('Photo trop lourde (max 12 Mo).');
        const bytes = new Uint8Array(buffer);
        let binary = '';
        const chunk = 0x8000;
        for (let i = 0; i < bytes.length; i += chunk) {
          binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
        }
        sendResponse({ ok: true, data: btoa(binary), type });
      } catch (error) {
        sendResponse({ ok: false, error: error.message });
      }
    })();
    return true;
  }

  if (message.type === 'am-auto-state') {
    (async () => {
      const settings = await getSettings();
      const queue = await getQueue();
      const dayCount = await getDayCount();
      const history = (await getHistory()).slice(-12).reverse();
      const { apiBase, token } = await chrome.storage.local.get(['apiBase', 'token']);
      sendResponse({
        ok: true,
        settings,
        queue,
        dayCount,
        history,
        configured: !!(apiBase && token),
      });
    })().catch((error) => sendResponse({ ok: false, error: error.message }));
    return true;
  }

  if (message.type === 'am-auto-settings') {
    (async () => {
      const settings = await saveSettings(message.patch || {});
      if (!settings.armed) {
        const queue = await getQueue();
        if (queue && queue.status === 'running') await pauseAuto('Risque non accepté : file mise en pause.');
      }
      sendResponse({ ok: true, settings });
    })().catch((error) => sendResponse({ ok: false, error: error.message }));
    return true;
  }

  if (message.type === 'am-auto-start') {
    (async () => {
      try {
        const queue = await startAuto(message.channel);
        sendResponse({ ok: true, queue });
      } catch (error) {
        sendResponse({ ok: false, error: error.message });
      }
    })();
    return true;
  }

  if (message.type === 'am-auto-pause') {
    pauseAuto().then((queue) => sendResponse({ ok: true, queue }))
      .catch((error) => sendResponse({ ok: false, error: error.message }));
    return true;
  }

  if (message.type === 'am-auto-resume') {
    (async () => {
      try {
        sendResponse({ ok: true, queue: await resumeAuto() });
      } catch (error) {
        sendResponse({ ok: false, error: error.message });
      }
    })();
    return true;
  }

  if (message.type === 'am-auto-stop') {
    stopAuto().then((queue) => sendResponse({ ok: true, queue }))
      .catch((error) => sendResponse({ ok: false, error: error.message }));
    return true;
  }

  if (message.type === 'am-auto-skip') {
    (async () => {
      try {
        sendResponse({ ok: true, queue: await skipAuto() });
      } catch (error) {
        sendResponse({ ok: false, error: error.message });
      }
    })();
    return true;
  }

  if (message.type === 'am-auto-clear-history') {
    chrome.storage.local.set({ amAutoHistory: [] })
      .then(() => sendResponse({ ok: true }))
      .catch((error) => sendResponse({ ok: false, error: error.message }));
    return true;
  }

  return false;
});

chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name === ALARM_NEXT) {
    const queue = await getQueue();
    if (queue && queue.status === 'running') await processNext();
    return;
  }
  if (alarm.name === ALARM_WATCHDOG) {
    const queue = await getQueue();
    if (!queue || queue.status !== 'running') {
      await clearAlarms();
      return;
    }
    if (queue.lockAt && Date.now() - queue.lockAt < LOCK_MS) return;
    await processNext();
  }
});

chrome.runtime.onInstalled.addListener(async () => {
  const { amAutoSettings } = await chrome.storage.local.get('amAutoSettings');
  if (!amAutoSettings) await chrome.storage.local.set({ amAutoSettings: AUTO_DEFAULTS });
  const queue = await getQueue();
  if (queue && queue.status === 'running') {
    await setQueue({
      ...queue,
      status: 'paused',
      lockAt: 0,
      lastMessage: 'Pause après mise à jour de l’extension : relancez ou reprenez la file.',
    });
    await clearAlarms();
  }
});

chrome.runtime.onStartup.addListener(async () => {
  const queue = await getQueue();
  if (queue && queue.status === 'running') {
    await setQueue({
      ...queue,
      status: 'paused',
      lockAt: 0,
      lastMessage: 'Pause après redémarrage du navigateur : relancez ou reprenez la file.',
    });
    await clearAlarms();
  }
});
