/* Service worker : préremplissage manuel + file de publication locale (opt-in, locale uniquement). */

try { importScripts('quality.js'); } catch (_) { /* contrôle des fiches indisponible : la file reste utilisable */ }

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
  saveDraft: false,    // enregistre un brouillon sur la plateforme au lieu de publier (prioritaire sur la simulation)
  intervalMin: 5,
  dailyCap: 10,
  hourlyCap: 3,        // publications réelles par heure glissante au plus
  activeFrom: 8,       // plage horaire locale de travail (publication réelle uniquement)
  activeTo: 21,
  cooldownHours: 6,    // pause obligatoire après une vérification demandée par la plateforme
  keepTabs: false,
  depositUrls: {
    leboncoin: 'https://www.leboncoin.fr/deposer-une-annonce',
    vinted: 'https://www.vinted.fr/items/new',
  },
};

/** Publication réelle : ni simulation, ni brouillon. Seule elle est soumise aux plafonds, à la plage horaire et au délai de repos. */
const isLive = (settings) => !settings.dryRun && !settings.saveDraft;
/** Simulation pure (rien n'est cliqué sur la plateforme). */
const isSimulation = (settings) => !!settings.dryRun && !settings.saveDraft;

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

/* ---------- Rythme (publication réelle uniquement) ---------- */

async function getStamps() {
  const { amAutoStamps } = await chrome.storage.local.get('amAutoStamps');
  const hourAgo = Date.now() - 60 * 60 * 1000;
  return (Array.isArray(amAutoStamps) ? amAutoStamps : []).filter((t) => t > hourAgo);
}

async function pushStamp() {
  const stamps = await getStamps();
  stamps.push(Date.now());
  await chrome.storage.local.set({ amAutoStamps: stamps });
}

/** Un délai aléatoire (minutes → ms) : jamais deux écarts identiques, et de temps en temps une vraie pause. */
function humanDelay(minutes) {
  let factor = 0.8 + Math.random() * 0.6;
  if (Math.random() < 0.15) factor += 0.5 + Math.random();   // « pause café » occasionnelle
  return Math.round(minutes * 60000 * factor);
}

function hhmm(ts) {
  const d = new Date(ts);
  return String(d.getHours()).padStart(2, '0') + 'h' + String(d.getMinutes()).padStart(2, '0');
}

/**
 * Garde de rythme avant chaque pièce en publication réelle : plage horaire, plafond par heure.
 * Retourne null (on peut y aller) ou { until, message } : la file attend, sans se mettre en pause.
 */
async function pacingHold(settings) {
  if (!isLive(settings)) return null;
  const now = new Date();
  const from = Math.max(0, Math.min(23, Number(settings.activeFrom)));
  const to = Math.max(from + 1, Math.min(24, Number(settings.activeTo)));
  const hour = now.getHours();
  if (hour < from || hour >= to) {
    const start = new Date(now);
    if (hour >= to) start.setDate(start.getDate() + 1);
    start.setHours(from, Math.floor(Math.random() * 40), 0, 0);
    return { until: start.getTime(), message: 'Hors de la plage horaire (' + from + 'h–' + to + 'h) : reprise à ' + hhmm(start.getTime()) + '.' };
  }
  const stamps = await getStamps();
  if (stamps.length >= Math.max(1, Number(settings.hourlyCap) || 3)) {
    const until = Math.min(...stamps) + 60 * 60 * 1000 + Math.floor(Math.random() * 6 * 60000);
    return { until, message: 'Plafond horaire atteint (' + stamps.length + ') : reprise à ' + hhmm(until) + '.' };
  }
  return null;
}

async function getCooldown() {
  const { amAutoCooldown } = await chrome.storage.local.get('amAutoCooldown');
  return Number(amAutoCooldown) > Date.now() ? Number(amAutoCooldown) : 0;
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

async function scheduleNext(settings, fixedDelay) {
  // En simulation, on enchaîne plus vite (mais jamais en rafale) ; en réel, respect du réglage.
  const minutes = !isLive(settings) ? 0.6 : Math.max(2, Math.min(30, Number(settings.intervalMin) || 5));
  const delay = fixedDelay != null ? fixedDelay : !isLive(settings) ? Math.round(minutes * 60000 * (0.85 + Math.random() * 0.3)) : humanDelay(minutes);
  await chrome.alarms.clear(ALARM_NEXT);
  await chrome.alarms.create(ALARM_NEXT, { when: Date.now() + delay });
}

async function ensureWatchdog() {
  const existing = await chrome.alarms.get(ALARM_WATCHDOG);
  if (!existing) await chrome.alarms.create(ALARM_WATCHDOG, { periodInMinutes: 1 });
}

/** Texte d'une note de publication → empreinte du contenu publié ('' si absente). Format : « [h:0123abcd…] … ». */
function hashFromNote(note) {
  const m = /\[h:([0-9a-f]{8,32})\]/.exec(String(note || ''));
  return m ? m[1] : '';
}

/**
 * Compare la fiche actuelle à ce qui a été publié. Renvoie 'same' (inchangée), 'changed' (modifiée) ;
 * une publication sans empreinte (faite avant cette fonction) est adoptée telle quelle : on enregistre l'empreinte actuelle.
 */
async function compareWithPublished(ref, channel, listing) {
  let product = null;
  try {
    product = (await api('/produit/' + encodeURIComponent(ref))).product;
  } catch (_) {
    return 'same'; // fiche illisible : on ne touche à rien
  }
  if (!product || !globalThis.AmQuality) return 'same';
  const current = AmQuality.fingerprint(product, channel);
  const before = hashFromNote(listing.note);
  if (!before) {
    try {
      await api('/marquer', {
        method: 'POST',
        json: { product_ref: ref, channel, status: 'published', listing_url: listing.listing_url || '', external_id: listing.external_id || '', note: '[h:' + current + '] empreinte enregistrée' },
      });
    } catch (_) {}
    return 'same';
  }
  return before === current ? 'same' : 'changed';
}

function actionLabel(action) {
  return {
    'to-update': 'à mettre à jour',
    published: 'publiée',
    prepared: 'prête (simulation)',
    drafted: 'enregistrée en brouillon',
    rejected: 'écartée (contrôle de la fiche)',
    blocked: 'arrêtée',
    skipped: 'passée',
    'no-match': 'passée (menu sans valeur)',
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
  if (isLive(settings)) {
    const cooldown = await getCooldown();
    if (cooldown) {
      throw new Error('Une vérification a été demandée par la plateforme : pas de publication automatique avant ' + hhmm(cooldown) + '. Vérifiez votre compte à la main (la simulation reste possible).');
    }
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
  let listings = [];
  let drafted = [];
  try {
    const res = await api('/publications?channel=' + encodeURIComponent(channel));
    published = Array.isArray(res.refs) ? res.refs : [];
    listings = Array.isArray(res.listings) ? res.listings : [];
    drafted = Array.isArray(res.drafts) ? res.drafts.map(String) : [];
  } catch (_) {
    // Ancienne version du serveur : l'historique local reste la seule protection anti-doublon.
  }
  const history = await getHistory();
  const known = new Map(listings.map((l) => [String(l.ref), l]));
  const seen = new Set([
    ...published,
    ...history
      .filter((h) => h.channel === channel && (h.action === 'published' || h.action === 'skipped'))
      .map((h) => h.ref),
  ]);

  // Articles cochés dans la popup de l'extension ; à défaut, sélection faite dans l'administration de la boutique.
  const { amPicked } = await chrome.storage.local.get('amPicked');
  const picked = (Array.isArray(amPicked) ? amPicked : []).map(String).filter(Boolean);
  const candidates = picked.length
    ? picked
    : items.map((item) => item && item.product && item.product.ref);
  // Pièces déjà en ligne : inchangées → on passe à la suivante ; modifiées depuis la publication → à mettre à jour.
  const unchanged = [];
  const toUpdate = [];
  for (const ref of candidates) {
    const listing = ref && known.get(String(ref));
    if (!listing) continue;
    const state = await compareWithPublished(ref, channel, listing);
    if (state === 'changed') toUpdate.push(ref);
    else unchanged.push(ref);
  }
  const alreadyOnline = new Set([...unchanged, ...toUpdate]);
  // En mode brouillon, une pièce déjà enregistrée en brouillon sur ce canal n'est pas redéposée (sinon doublon de brouillons).
  const alreadyDrafted = settings.saveDraft ? new Set(drafted) : new Set();
  const draftSkipped = candidates.filter((ref) => ref && alreadyDrafted.has(String(ref)) && !seen.has(ref) && !alreadyOnline.has(ref));
  const refs = candidates
    .filter((ref) => ref && !seen.has(ref) && !alreadyOnline.has(ref) && !alreadyDrafted.has(String(ref)))
    .slice(0, Math.max(0, settings.dailyCap - dayCount));
  const source = picked.length ? ' (cochées dans l’extension)' : ' (sélection de la boutique)';
  const skipNote = (unchanged.length ? ' ' + unchanged.length + ' inchangée(s) passée(s).' : '') +
    (toUpdate.length ? ' ' + toUpdate.length + ' à mettre à jour (' + toUpdate.join(', ') + ').' : '') +
    (draftSkipped.length ? ' ' + draftSkipped.length + ' déjà en brouillon sur la plateforme (' + draftSkipped.join(', ') + ').' : '');
  for (const ref of toUpdate) {
    await pushHistory({ ref, channel, action: 'to-update', reason: 'contenu modifié depuis la publication', dryRun: isSimulation(settings), at: Date.now() });
  }

  if (!refs.length) {
    throw new Error('Aucune pièce à publier : sélection vide ou déjà en ligne sur ce canal.' + skipNote);
  }

  const queue = {
    runId: 'run-' + Date.now(),
    channel,
    refs,
    index: 0,
    status: 'running',
    startedAt: Date.now(),
    stats: { published: 0, drafted: 0, prepared: 0, blocked: 0, error: 0 },
    consecutiveErrors: 0,
    lockAt: 0,
    activeTabId: null,
    activeRef: '',
    lastMessage: 'File démarrée : ' + refs.length + ' pièce(s)' + source + '.' + skipNote,
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
  if (isLive(settings)) {
    const cooldown = await getCooldown();
    if (cooldown) {
      throw new Error('Une vérification a été demandée par la plateforme : pas de publication automatique avant ' + hhmm(cooldown) + '. Vérifiez votre compte à la main (la simulation reste possible).');
    }
  }
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
    dryRun: isSimulation(settings),
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

/** Met à jour le message de la file (affiché dans la popup) : sans cela, la popup reste figée sur « File démarrée » pendant tout le traitement. */
async function progress(message) {
  try {
    const q = await getQueue();
    if (q && q.status === 'running') await setQueue({ ...q, lastMessage: message });
  } catch (_) {}
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

  const hold = await pacingHold(settings);
  if (hold) {
    if (queue.lastMessage !== hold.message) await setQueue({ ...queue, lastMessage: hold.message });
    await chrome.alarms.clear(ALARM_NEXT);
    await chrome.alarms.create(ALARM_NEXT, { when: hold.until });
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
    await progress('Lecture de la fiche ' + ref + '…');
    const data = await api('/produit/' + encodeURIComponent(ref));
    const product = data.product;
    if (!product) throw new Error('Produit introuvable.');

    // Contrôle de la fiche avant d'ouvrir quoi que ce soit : une fiche qui bloquera la plateforme est écartée, la file continue.
    const quality = globalThis.AmQuality ? AmQuality.check(product, queue.channel) : { block: [], warn: [] };
    if (quality.block.length) {
      await finalizeItem(product, { action: 'rejected', reason: AmQuality.lines({ block: quality.block, warn: [] }).join(' ; ') }, tabId, settings);
      return;
    }
    if (quality.warn.length) await progress('Fiche ' + ref + ' : ' + AmQuality.summary(quality) + '…');

    let openDeposit = false;
    if (!tabId) {
      const depositUrl = (settings.depositUrls[queue.channel] || '').trim() ||
        AUTO_DEFAULTS.depositUrls[queue.channel];
      // Départ de session comme le vendeur : accueil du site, puis clic sur « Déposer une annonce » / « Vends ton article ».
      // Une adresse de dépôt personnalisée (réglages) est respectée telle quelle.
      let startUrl = depositUrl;
      if (depositUrl === AUTO_DEFAULTS.depositUrls[queue.channel]) {
        startUrl = new URL(depositUrl).origin + '/';
        openDeposit = true;
      }
      // Onglet au premier plan : une page ouverte en arrière-plan est ralentie par Chrome et Leboncoin y affiche un écran de chargement interminable.
      await progress(openDeposit ? 'Ouverture du site, puis clic sur « Déposer une annonce »…' : 'Ouverture de la page de dépôt…');
      const tab = await chrome.tabs.create({ url: startUrl, active: true });
      tabId = tab.id;
    }
    await setQueue({ ...(await getQueue()), activeTabId: tabId });

    await progress('Attente de la page de dépôt (jusqu’à 90 s)…');
    await waitForContent(tabId, queue.channel, 90000);
    await progress('Remplissage de ' + ref + '…');
    const result = await runAutoInTab(tabId, queue.channel, product, {
      dryRun: isSimulation(settings),
      draft: !!settings.saveDraft,
      runId: queue.runId,
      openDeposit,
    });
    if (quality.warn.length) result.reason = (result.reason ? result.reason + ' · ' : '') + 'fiche : ' + quality.warn.map((i) => i.field + ' (' + i.message.split(/[:.(]/)[0].trim() + ')').join(', ');
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
  const files = ['quality.js', 'content/fill.js', channel === 'vinted' ? 'content/vinted.js' : 'content/leboncoin.js'];
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
  let suggested = null; // prix proposé par la plateforme, relevé à l'étape du formulaire
  const withSuggestion = (res) => (suggested && res && !res.suggested ? { ...res, suggested } : res);
  for (let step = 0; step < MAX_STEPS; step++) {
    // Le clic de départ n'a lieu qu'à la première étape d'un onglet neuf.
    const res = await sendWithRetry(tabId, { type: 'am-auto-step', channel, product, options: { ...options, openDeposit: !!options.openDeposit && step === 0 } });
    if (!res || res.ok === false) throw new Error((res && res.error) || 'Étape impossible.');
    await progress('Étape ' + (step + 1) + ' : ' + res.action + (res.reason ? ' — ' + res.reason : ''));
    if (res.suggested) suggested = res.suggested;
    if (['blocked', 'prepared', 'published', 'no-match'].includes(res.action)) return withSuggestion(res);
    if (res.action === 'draft-clicked') {
      const saved = await verifyDrafted(tabId, channel);
      return withSuggestion(saved || {
        ok: true,
        action: 'blocked',
        reason: 'clic sur « Sauvegarder le brouillon » envoyé, mais l’enregistrement n’est pas confirmé. Vérifiez l’onglet.',
      });
    }
    if (res.action === 'publish-clicked') {
      publishClicks++;
      const verified = await verifyPublished(tabId, channel);
      if (verified) return withSuggestion(verified);
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

/** Brouillon enregistré : message de confirmation du site, ou formulaire quitté (le site renvoie vers ses brouillons). */
async function verifyDrafted(tabId, channel) {
  for (let i = 0; i < 6; i++) {
    await sleep(i === 0 ? 1500 : 2000);
    try {
      const res = await chrome.tabs.sendMessage(tabId, { type: 'am-auto-verify', channel, draft: true });
      if (res && res.action === 'drafted') return { ok: true, action: 'drafted' };
    } catch (_) {
      // Navigation en cours : on contrôle l'adresse ci-dessous.
    }
    try {
      const tab = await chrome.tabs.get(tabId);
      const path = new URL(tab.url || 'about:blank').pathname;
      if (channel === 'vinted' && tab.url && !/^\/items\/new/.test(path)) return { ok: true, action: 'drafted' };
    } catch (_) {}
  }
  return null;
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
  if (result.suggested) {
    const sg = result.suggested;
    const seen = sg.min != null
      ? 'prix proposé ' + sg.min + (sg.max !== sg.min ? '–' + sg.max : '') + ' €'
      : 'prix proposé non lu (« ' + String(sg.text || '').slice(0, 60) + ' »)';
    result = { ...result, reason: (result.reason ? result.reason + ' · ' : '') + seen };
  }
  const hashMark = action === 'published' && isLive(settings) && globalThis.AmQuality && (product.title !== undefined || product.name !== undefined)
    ? '[h:' + AmQuality.fingerprint(product, current.channel) + '] ' : '';
  const suggestionMark = globalThis.AmQuality ? AmQuality.suggestionTag(result.suggested) : '';
  const note = hashMark + suggestionMark + action + (result.reason ? ' — ' + result.reason : '') + (isSimulation(settings) ? ' [simulation]' : '');
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

  const key = ['published', 'drafted', 'prepared', 'blocked', 'rejected', 'no-match'].includes(action) ? action : 'error';
  const stats = { ...(current.stats || {}) };
  stats[key] = (stats[key] || 0) + 1;

  await pushHistory({
    ref,
    channel: current.channel,
    action,
    reason: result.reason || '',
    dryRun: isSimulation(settings),
    at: Date.now(),
  });
  if (action === 'published' && isLive(settings)) { await bumpDayCount(); await pushStamp(); }

  if (tabId && (action === 'published' || action === 'prepared' || action === 'drafted' || action === 'no-match') && !settings.keepTabs) {
    try { await chrome.tabs.remove(tabId); } catch (_) {}
  }

  const advanced = action === 'published' || action === 'prepared' || action === 'drafted' || action === 'rejected' || action === 'no-match';
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

  if (action === 'blocked' && isLive(settings) && /v[ée]rification|captcha/i.test(result.reason || '')) {
    // La plateforme demande une vérification : on arrête tout et on ne relance pas en automatique avant plusieurs heures.
    const hours = Math.max(1, Number(settings.cooldownHours) || 6);
    await chrome.storage.local.set({ amAutoCooldown: Date.now() + hours * 3600 * 1000 });
  }

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

  if (action === 'rejected') {
    await chrome.storage.local.set({ amAlert: { level: 'warn', message: 'Fiche ' + ref + ' écartée avant remplissage :\n' + (result.reason || '').replace(/ ; /g, '\n'), at: Date.now() } });
  }
  if (action === 'no-match') {
    await chrome.storage.local.set({ amAlert: { level: 'warn', message: 'Pièce ' + ref + ' passée, la file continue :\n' + (result.reason || ''), at: Date.now() } });
  }

  await setQueue(updated);
  await ensureWatchdog();
  // Une fiche écartée n'a rien publié : on enchaîne vite, sans attendre l'intervalle entre publications.
  await scheduleNext(settings, action === 'rejected' || action === 'no-match' ? 8000 + Math.floor(Math.random() * 6000) : undefined);
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

  if (message.type === 'am-selftest') {
    (async () => {
      const { tabId, channel } = message;
      if (!Number.isInteger(tabId) || !CHANNELS.includes(channel)) throw new Error('Onglet invalide.');
      const res = await sendToDeposit(tabId, channel, { type: 'am-selftest', channel });
      sendResponse(res && res.ok ? res : { ok: false, error: (res && res.error) || 'Le formulaire n’a pas répondu. Rechargez la page.' });
    })().catch((error) => sendResponse({ ok: false, error: error.message || 'Contrôle impossible.' }));
    return true;
  }

  if (message.type === 'am-report-unknown') {
    // Valeur du site introuvable (ex. Univers) : elle rejoint la liste « À renseigner » de la page Correspondances du portail.
    (async () => {
      const items = Array.isArray(message.items) ? message.items.slice(0, 20) : [];
      if (!CHANNELS.includes(message.channel) || !items.length) { sendResponse({ ok: false }); return; }
      await api('/champs-inconnus', { method: 'POST', json: { channel: message.channel, items } });
      sendResponse({ ok: true });
    })().catch(() => sendResponse({ ok: false }));
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
