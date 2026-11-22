/* Popup de l’extension : connexion boutique, catalogue, envoi au formulaire ouvert. */
(function () {
  const $ = (id) => document.getElementById(id);
  const settings = $('settings');
  const conn = $('conn');
  const list = $('list');
  const detail = $('detail');
  const q = $('q');
  const btnSearch = $('btn-search');
  const store = chrome.storage.local;

  /* ---------- Cases à cocher : articles de la file automatique ---------- */
  const picked = new Set();
  let shownRefs = [];

  function updatePickBar() {
    const bar = $('pick-bar');
    if (!bar) return;
    bar.hidden = !shownRefs.length && !picked.size;
    $('pick-hint').hidden = bar.hidden;
    $('pick-count').textContent = String(picked.size);
  }

  async function savePicked() {
    await store.set({ amPicked: Array.from(picked) });
    updatePickBar();
  }

  store.get('amPicked').then((data) => {
    (Array.isArray(data.amPicked) ? data.amPicked : []).forEach((ref) => picked.add(String(ref)));
    updatePickBar();
  });

  /**
   * Accepte l’URL boutique, …/api ou …/api/annonces et renvoie toujours …/api/annonces.
   * Ex. https://host/slug/api → https://host/slug/api/annonces
   */
  function normalizeApiBase(raw) {
    let u = (raw || '').trim();
    if (!u) return '';
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
    try {
      const parsed = new URL(u);
      // Le jeton et le catalogue ne transitent jamais en clair hors développement local.
      const localHost = /^(localhost|127\.|\[::1\]|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i.test(parsed.hostname);
      if (parsed.protocol === 'http:' && !localHost) parsed.protocol = 'https:';
      let path = parsed.pathname.replace(/\/+$/, '') || '';
      path = path.replace(/\/boutique\.php(?:\/api(?:\/annonces)?)?$/i, '');
      if (/\/api\/annonces$/i.test(path)) {
        /* déjà bon */
      } else if (/\/api$/i.test(path)) {
        path = path.replace(/\/api$/i, '/api/annonces');
      } else {
        path += '/api/annonces';
      }
      parsed.pathname = path;
      parsed.search = '';
      parsed.hash = '';
      return parsed.toString().replace(/\/+$/, '');
    } catch (_) {
      return '';
    }
  }

  async function loadConfig() {
    const data = await store.get(['apiBase', 'token', 'shopName']);
    return {
      apiBase: data.apiBase || '',
      token: data.token || '',
      shopName: data.shopName || '',
    };
  }

  async function saveConfig(apiBase, token) {
    await store.set({ apiBase, token });
  }

  function readFormConfig() {
    return {
      apiBase: normalizeApiBase($('shop-url').value),
      token: ($('token').value || '').trim(),
    };
  }

  async function api(path, opts = {}) {
    const cfg = opts.cfg || (await loadConfig());
    if (!cfg.apiBase || !cfg.token) throw new Error('Indiquez l’adresse de la boutique et le jeton, puis Enregistrer.');
    const base = normalizeApiBase(cfg.apiBase);
    if (!base) throw new Error('Adresse de boutique invalide : utilisez HTTPS (HTTP accepté uniquement en local).');
    const url = base + path;
    const headers = Object.assign(
      { Accept: 'application/json', 'X-Annonces-Token': cfg.token },
      opts.headers || {}
    );
    if (opts.body) headers['Content-Type'] = 'application/json';
    let res;
    try {
      res = await fetch(url, {
        method: opts.method || 'GET',
        headers,
        body: opts.body ? JSON.stringify(opts.body) : undefined,
      });
    } catch (_) {
      throw new Error('Réseau inaccessible. Vérifiez l’URL (doit finir par /api/annonces).');
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.error || 'Erreur HTTP ' + res.status + ' — URL : ' + cfg.apiBase);
      err.code = data.code || '';
      err.status = res.status;
      throw err;
    }
    return data;
  }

  function showSettings(open) {
    settings.hidden = !open;
    $('btn-settings').setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function lockSearch(message) {
    conn.textContent = message;
    q.disabled = true;
    btnSearch.disabled = true;
  }

  function unlockSearch(message) {
    conn.textContent = message;
    q.disabled = false;
    btnSearch.disabled = false;
  }

  /**
   * @param {{ apiBase?: string, token?: string } | null} override
   * @returns {Promise<{ ok: boolean, message: string }>}
   */
  async function refreshConn(override) {
    const cfg = override
      ? { apiBase: override.apiBase || '', token: override.token || '', shopName: '' }
      : await loadConfig();
    if (cfg.apiBase) $('shop-url').value = cfg.apiBase;
    if (cfg.token) $('token').value = cfg.token;

    if (!cfg.apiBase || !cfg.token) {
      lockSearch('Configurez la boutique dans Réglages.');
      showSettings(true);
      return { ok: false, message: 'Adresse et jeton requis.' };
    }

    try {
      const ping = await api('/ping', { cfg });
      await store.set({ shopName: ping.shop || '', apiBase: cfg.apiBase, token: cfg.token });
      if (!ping.entitled) {
        lockSearch((ping.shop || 'Boutique') + ' — option non active (essai ou abonnement dans l’admin).');
        showSettings(true);
        return { ok: false, message: 'Option non active sur cette boutique.' };
      }
      if (!ping.authenticated) {
        lockSearch('Jeton refusé. Régénérez-le dans l’administration.');
        showSettings(true);
        return { ok: false, message: 'Jeton invalide.' };
      }
      unlockSearch((ping.shop || 'Boutique') + ' — connecté');
      showSettings(false);
      await search('', cfg);
      return { ok: true, message: 'Connecté à « ' + (ping.shop || 'boutique') + ' ».' };
    } catch (e) {
      lockSearch(e.message || 'Connexion impossible.');
      showSettings(true);
      return { ok: false, message: e.message || 'Connexion impossible.' };
    }
  }

  /* ---------- Contrôle des fiches ---------- */
  const CHANNEL_NAMES = { leboncoin: 'Leboncoin', vinted: 'Vinted' };

  function qualityChip(item) {
    if (!globalThis.AmQuality) return null;
    const results = ['leboncoin', 'vinted'].map((ch) => ({ ch, r: AmQuality.check(item, ch) }));
    const blocks = results.filter((x) => x.r.block.length);
    const warns = results.filter((x) => x.r.warn.length);
    if (!blocks.length && !warns.length) return null;
    const chip = document.createElement('span');
    chip.className = 'chip ' + (blocks.length ? 'block' : 'warn');
    chip.textContent = blocks.length ? '⛔' : '⚠';
    chip.title = results.map((x) => CHANNEL_NAMES[x.ch] + ' : ' + (AmQuality.lines(x.r).join(' | ') || 'rien à signaler')).join('\n');
    return chip;
  }

  function qualityBox(product) {
    const box = document.createElement('div');
    box.className = 'check';
    const title = document.createElement('h3');
    title.textContent = 'Contrôle avant remplissage';
    box.appendChild(title);
    if (!globalThis.AmQuality) return box;
    for (const ch of ['leboncoin', 'vinted']) {
      const r = AmQuality.check(product, ch);
      const head = document.createElement('div');
      head.innerHTML = '<strong></strong>';
      head.querySelector('strong').textContent = CHANNEL_NAMES[ch] + ' ';
      if (!r.block.length && !r.warn.length) {
        const ok = document.createElement('span');
        ok.className = 'ok';
        ok.textContent = '✓ fiche complète';
        head.appendChild(ok);
        box.appendChild(head);
        continue;
      }
      box.appendChild(head);
      const ul = document.createElement('ul');
      for (const item of r.block.concat(r.warn)) {
        const li = document.createElement('li');
        li.className = r.block.includes(item) ? 'block' : 'warn';
        li.textContent = (r.block.includes(item) ? '⛔ ' : '⚠ ') + item.field + ' : ' + item.message;
        ul.appendChild(li);
      }
      box.appendChild(ul);
    }
    return box;
  }

  /** Avant un remplissage : si la fiche a des points bloquants, on demande confirmation (l'utilisateur peut corriger la fiche d'abord). */
  function confirmQuality(product, channel) {
    if (!globalThis.AmQuality) return true;
    const r = AmQuality.check(product, channel);
    if (!r.block.length) return true;
    return confirm('Cette fiche a des points bloquants pour ' + CHANNEL_NAMES[channel] + ' :\n\n' + AmQuality.lines({ block: r.block, warn: [] }).join('\n') + '\n\nRemplir quand même ?');
  }

  function renderList(items) {
    list.innerHTML = '';
    detail.hidden = true;
    if (!items.length) {
      list.innerHTML = '<li class="empty">Aucun article.</li>';
      return;
    }
    shownRefs = items.map((item) => String(item.ref));
    updatePickBar();
    items.forEach((item) => {
      const li = document.createElement('li');
      const thumb = item.photos && item.photos[0] ? item.photos[0].url : '';
      li.innerHTML =
        '<label class="pick"><input type="checkbox" aria-label="Cocher pour la file automatique"></label>' +
        (thumb ? '<img src="' + thumb + '" alt="">' : '<span class="ph"></span>') +
        '<span class="meta"><strong></strong><small></small></span>';
      const box = li.querySelector('.pick input');
      box.checked = picked.has(String(item.ref));
      box.addEventListener('click', (event) => event.stopPropagation());
      li.querySelector('.pick').addEventListener('click', (event) => event.stopPropagation());
      box.addEventListener('change', () => {
        if (box.checked) picked.add(String(item.ref)); else picked.delete(String(item.ref));
        savePicked();
      });
      li.querySelector('strong').textContent = item.title || item.name;
      const chip = qualityChip(item);
      if (chip) li.querySelector('strong').appendChild(chip);
      li.querySelector('small').textContent =
        (item.price != null ? item.price.toFixed(2).replace('.', ',') + ' €' : item.price_text || '') +
        ' · réf. ' +
        item.ref;
      li.addEventListener('click', () => showDetail(item.ref));
      list.appendChild(li);
    });
  }

  async function search(query, cfg) {
    conn.textContent = 'Recherche…';
    try {
      const data = await api('/catalogue?q=' + encodeURIComponent(query || '') + '&limit=25', cfg ? { cfg } : {});
      unlockSearch(data.total + ' article' + (data.total > 1 ? 's' : ''));
      renderList(data.items || []);
    } catch (e) {
      lockSearch(e.message);
      list.innerHTML = '';
    }
  }

  async function showDetail(ref) {
    detail.hidden = false;
    detail.innerHTML = '<p class="msg">Chargement…</p>';
    try {
      const data = await api('/produit/' + encodeURIComponent(ref));
      const p = data.product;
      detail.innerHTML = '';
      const title = document.createElement('h2');
      title.textContent = p.title;
      const desc = document.createElement('p');
      desc.className = 'desc';
      desc.textContent = p.description || '';
      const note = document.createElement('p');
      note.className = 'msg';
      note.setAttribute('role', 'status');
      note.textContent = 'Ouvrez d’abord le formulaire de dépôt, puis cliquez. Vous validez la publication vous-même.';
      const actions = document.createElement('div');
      actions.className = 'row';
      const btnLbc = document.createElement('button');
      btnLbc.type = 'button';
      btnLbc.textContent = 'Remplir Leboncoin';
      btnLbc.addEventListener('click', () => fillTab('leboncoin', p));
      const btnVin = document.createElement('button');
      btnVin.type = 'button';
      btnVin.className = 'ghost';
      btnVin.textContent = 'Remplir Vinted';
      btnVin.addEventListener('click', () => fillTab('vinted', p));
      actions.append(btnLbc, btnVin);
      const exportButton = document.createElement('button');
      exportButton.type = 'button';
      exportButton.className = 'ghost';
      exportButton.textContent = 'Exporter les données JSON';
      exportButton.addEventListener('click', () => exportProductJson(p, note, exportButton));
      actions.appendChild(exportButton);
      if (p.photos && p.photos.length) {
        const download = document.createElement('button');
        download.type = 'button';
        download.className = 'ghost';
        download.textContent = 'Télécharger les photos';
        download.addEventListener('click', () => downloadPhotos(p, download, note));
        actions.appendChild(download);
      }
      detail.append(title, qualityBox(p), desc, actions, note);
    } catch (e) {
      detail.innerHTML = '<p class="msg">' + (e.message || 'Erreur') + '</p>';
    }
  }

  function exportProductJson(product, status, button) {
    let blobUrl = '';
    button.disabled = true;
    try {
      const payload = {
        exported_at: new Date().toISOString(),
        product,
      };
      const blob = new Blob([JSON.stringify(payload, null, 2) + '\n'], {
        type: 'application/json;charset=utf-8',
      });
      blobUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = blobUrl;
      link.download = safeFolderName(product) + '.json';
      link.click();
      status.textContent = 'Export JSON téléchargé avec les données complètes de l’article et les URL des photos.';
    } catch (error) {
      status.textContent = 'Impossible de créer le fichier JSON : ' + (error.message || 'erreur inconnue');
    } finally {
      button.disabled = false;
      if (blobUrl) setTimeout(() => URL.revokeObjectURL(blobUrl), 1000);
    }
  }

  function safeFolderName(product) {
    const name = (product.name || product.title || 'article')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 48);
    const ref = (product.ref || 'article').replace(/[^A-Za-z0-9_-]/g, '-');
    return 'annonce-' + (name || 'article') + '-' + ref;
  }

  function photoExtension(blob, url) {
    const extensions = {
      'image/jpeg': 'jpg',
      'image/png': 'png',
      'image/webp': 'webp',
      'image/gif': 'gif',
      'image/avif': 'avif',
    };
    if (extensions[blob.type]) return extensions[blob.type];
    try {
      const match = new URL(url).pathname.match(/\.([a-z0-9]{1,5})$/i);
      if (match) return match[1].toLowerCase();
    } catch (_) {
      // Use a safe default when the photo URL has no usable extension.
    }
    return 'jpg';
  }

  async function downloadPhotos(product, button, status) {
    if (!window.showDirectoryPicker) {
      status.textContent = 'La sélection d’un dossier nécessite une version récente de Chrome.';
      return;
    }

    button.disabled = true;
    status.textContent =
      'Choisissez un dossier dédié (pas la racine du Bureau ou de Documents). Créez par exemple « Photos annonces », puis sélectionnez-le.';
    try {
      const desktop = await window.showDirectoryPicker({ mode: 'readwrite' });
      const folder = await desktop.getDirectoryHandle(safeFolderName(product), { create: true });
      let saved = 0;
      const failures = [];

      for (let i = 0; i < product.photos.length; i++) {
        const photo = product.photos[i];
        try {
          if (!/^https?:\/\//i.test(photo.url || '')) throw new Error('URL de photo invalide');
          const response = await fetch(photo.url);
          if (!response.ok) throw new Error('HTTP ' + response.status);
          const blob = await response.blob();
          const handle = await folder.getFileHandle(
            'photo-' + String(i + 1).padStart(2, '0') + '.' + photoExtension(blob, photo.url),
            { create: true }
          );
          const writable = await handle.createWritable();
          await writable.write(blob);
          await writable.close();
          saved++;
        } catch (e) {
          failures.push(
            'photo ' +
              (i + 1) +
              ' : ' +
              (e.name ? e.name + ' — ' : '') +
              (e.message || 'échec du téléchargement')
          );
        }
      }

      const blockedByProtectedFolder = failures.some((failure) => failure.includes('SecurityError'));
      status.textContent = failures.length
        ? blockedByProtectedFolder && saved === 0
          ? 'Chrome bloque l’écriture dans ce dossier protégé. Créez un dossier dédié dans le Bureau ou Documents, puis sélectionnez ce dossier (pas le dossier parent).'
          : saved +
            ' photo(s) téléchargée(s) ; échec(s) : ' +
            failures.join(', ') +
            (blockedByProtectedFolder
              ? ' — choisissez un dossier dédié plutôt que la racine du Bureau ou de Documents.'
              : '')
        : saved + ' photo(s) téléchargée(s) dans le dossier « ' + safeFolderName(product) + ' ».';
    } catch (e) {
      status.textContent =
        e.name === 'AbortError'
          ? 'Sélection du dossier annulée.'
          : e.name === 'SecurityError'
            ? 'Chrome bloque l’écriture dans ce dossier protégé. Créez un dossier dédié dans le Bureau ou Documents, puis sélectionnez ce dossier (pas le dossier parent).'
            : 'Impossible de créer le dossier ou d’écrire les photos : ' + (e.message || 'erreur inconnue');
    } finally {
      button.disabled = false;
    }
  }

  async function fillTab(channel, product) {
    if (!confirmQuality(product, channel)) return;
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.id) {
      alert('Aucun onglet actif.');
      return;
    }
    const host = (() => {
      try {
        return new URL(tab.url || '').hostname;
      } catch {
        return '';
      }
    })();
    const okHost =
      (channel === 'leboncoin' && /leboncoin\.fr$/i.test(host)) ||
      (channel === 'vinted' && /vinted\.fr$/i.test(host));
    if (!okHost) {
      alert(
        channel === 'leboncoin'
          ? 'Ouvrez d’abord leboncoin.fr (page de dépôt), puis réessayez.'
          : 'Ouvrez d’abord vinted.fr (page de dépôt), puis réessayez.'
      );
      return;
    }
    const fillRequest = chrome.runtime.sendMessage({
      type: 'am-start-fill',
      tabId: tab.id,
      channel,
      product,
    });
    const panelOpening = chrome.sidePanel
      ? chrome.sidePanel.open({ tabId: tab.id }).catch(() => false)
      : Promise.resolve(false);
    const [response] = await Promise.all([fillRequest, panelOpening]);
    if (!response || !response.ok) {
      alert(response && response.error || 'Impossible de remplir cette page. Rechargez l’onglet et réessayez.');
    }
  }

  $('btn-settings').addEventListener('click', () => {
    showSettings(settings.hidden);
  });

  function missingConfigMessage(cfg) {
    const urlEmpty = !($('shop-url').value || '').trim();
    const tokenEmpty = !($('token').value || '').trim();
    if (urlEmpty && tokenEmpty) return 'Collez l’adresse de la boutique et le jeton, puis Tester.';
    if (urlEmpty) return 'Adresse de la boutique manquante.';
    if (tokenEmpty) return 'Jeton manquant : collez-le dans le champ (pas seulement l’URL).';
    if (!cfg.apiBase) return 'Adresse invalide.';
    if (!cfg.token) return 'Jeton manquant.';
    return 'Adresse et jeton requis.';
  }

  /**
   * Un « code de connexion » = adresse de la boutique + jeton am_… collés ensemble (dans l'un ou l'autre champ).
   * Renvoie { url, token } ou null si le texte ne contient pas les deux.
   */
  function parseConnectionCode(text) {
    const raw = String(text || '');
    const token = (raw.match(/\bam_[A-Za-z0-9_-]{8,}/) || [])[0];
    const url = (raw.match(/https?:\/\/[^\s"'<>]+/i) || [])[0];
    return token && url ? { url, token } : null;
  }

  async function applyConnectionCode(field) {
    const code = parseConnectionCode(field.value);
    if (!code) return;
    $('shop-url').value = code.url;
    $('token').value = code.token;
    $('btn-save').click();
  }
  ['shop-url', 'token'].forEach((id) => {
    $(id).addEventListener('paste', () => setTimeout(() => applyConnectionCode($(id)), 0));
  });

  $('btn-save').addEventListener('click', async () => {
    const cfg = readFormConfig();
    if (!cfg.apiBase || !cfg.token) {
      $('settings-msg').textContent = missingConfigMessage(cfg);
      return;
    }
    $('shop-url').value = cfg.apiBase;
    await saveConfig(cfg.apiBase, cfg.token);
    $('settings-msg').textContent = 'Enregistrement…';
    const result = await refreshConn(cfg);
    $('settings-msg').textContent = result.ok ? 'Enregistré — ' + result.message : result.message;
  });

  $('btn-ping').addEventListener('click', async () => {
    const cfg = readFormConfig();
    if (!cfg.apiBase || !cfg.token) {
      $('settings-msg').textContent = missingConfigMessage(cfg);
      lockSearch('Configurez la boutique dans Réglages.');
      return;
    }
    $('shop-url').value = cfg.apiBase;
    await saveConfig(cfg.apiBase, cfg.token);
    $('settings-msg').textContent = 'Test…';
    const result = await refreshConn(cfg);
    $('settings-msg').textContent = result.ok ? 'OK — ' + result.message : 'Échec : ' + result.message;
  });

  $('pick-all').addEventListener('click', () => {
    shownRefs.forEach((ref) => picked.add(ref));
    list.querySelectorAll('.pick input').forEach((box) => { box.checked = true; });
    savePicked();
  });
  $('pick-none').addEventListener('click', () => {
    picked.clear();
    list.querySelectorAll('.pick input').forEach((box) => { box.checked = false; });
    savePicked();
  });

  /* ---------- Alerte persistante (formulaire modifié, fiche écartée…) ---------- */
  const alertBar = $('alert-bar');
  function showAlert(a) {
    if (!a || !a.message) { alertBar.hidden = true; return; }
    $('alert-text').textContent = a.message;
    alertBar.className = 'alert-bar' + (a.level === 'warn' ? ' warn' : '');
    alertBar.hidden = false;
  }
  store.get('amAlert').then((data) => showAlert(data.amAlert));
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area === 'local' && changes.amAlert) showAlert(changes.amAlert.newValue);
  });
  $('alert-close').addEventListener('click', () => { store.remove('amAlert'); alertBar.hidden = true; });

  /* ---------- Contrôle du formulaire ouvert (détecte un changement de la plateforme) ---------- */
  $('btn-selftest').addEventListener('click', async () => {
    const msg = $('selftest-msg');
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    let host = '';
    try { host = new URL((tab && tab.url) || '').hostname; } catch (_) {}
    const channel = /leboncoin\.fr$/i.test(host) ? 'leboncoin' : /vinted\.fr$/i.test(host) ? 'vinted' : '';
    if (!tab || !channel) {
      msg.textContent = 'Ouvrez d’abord la page de dépôt Leboncoin ou Vinted, puis recliquez.';
      return;
    }
    msg.textContent = 'Contrôle…';
    const res = await new Promise((resolve) => chrome.runtime.sendMessage({ type: 'am-selftest', tabId: tab.id, channel }, (r) => resolve(chrome.runtime.lastError ? { ok: false, error: chrome.runtime.lastError.message } : r)));
    if (!res || !res.ok) { msg.textContent = res && res.error || 'Contrôle impossible.'; return; }
    const name = CHANNEL_NAMES[channel];
    msg.textContent = res.missing.length
      ? '⚠ ' + name + ' (' + res.page + ') : repères absents : ' + res.missing.join(', ') + '. La plateforme a peut-être changé son formulaire : remplissez à la main et signalez-le.'
      : '✓ ' + name + ' (' + res.page + ') : ' + res.found.length + ' repère(s) reconnus, le remplissage peut fonctionner.';
  });

  btnSearch.addEventListener('click', () => search(q.value));
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') search(q.value);
  });

  /* ---------- Publication automatique locale ---------- */

  const auto = {
    badge: $('auto-badge'),
    armed: $('auto-armed'),
    armedAt: $('auto-armed-at'),
    dry: $('auto-dry'),
    interval: $('auto-interval'),
    cap: $('auto-cap'),
    keepTabs: $('auto-keep-tabs'),
    urlLbc: $('auto-url-lbc'),
    urlVinted: $('auto-url-vinted'),
    status: $('auto-status'),
    queue: $('auto-queue'),
    history: $('auto-history'),
    clearHistory: $('auto-clear-history'),
    lbc: $('auto-lbc'),
    vinted: $('auto-vinted'),
    pause: $('auto-pause'),
    resume: $('auto-resume'),
    skip: $('auto-skip'),
    stop: $('auto-stop'),
  };

  let autoState = null;

  function autoSend(message) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage(message, (response) => {
        if (chrome.runtime.lastError) {
          resolve({ ok: false, error: chrome.runtime.lastError.message });
          return;
        }
        resolve(response || { ok: false, error: 'Pas de réponse de l’extension.' });
      });
    });
  }

  function fillIfIdle(el, value, prop) {
    if (document.activeElement === el) return;
    if (prop === 'checked') el.checked = !!value;
    else el.value = value;
  }

  function intInRange(value, min, max, fallback) {
    const n = Number(value);
    if (!Number.isFinite(n)) return fallback;
    return Math.max(min, Math.min(max, Math.round(n)));
  }

  function statsLine(queue) {
    const s = (queue && queue.stats) || {};
    return [
      s.published ? s.published + ' publiée(s)' : '',
      s.prepared ? s.prepared + ' simulée(s)' : '',
      s.rejected ? s.rejected + ' écartée(s)' : '',
      s.blocked ? s.blocked + ' arrêt(s)' : '',
      s.error ? s.error + ' erreur(s)' : '',
    ].filter(Boolean).join(' · ') || 'aucune pièce traitée';
  }

  function renderAuto() {
    if (!autoState) return;
    const s = autoState.settings || {};
    const queue = autoState.queue;
    const status = queue ? queue.status : '';
    const running = status === 'running';
    const paused = status === 'paused';
    const queued = running || paused;

    fillIfIdle(auto.armed, !!s.armed, 'checked');
    auto.armedAt.textContent = s.armed && s.armedAt
      ? 'Risque accepté le ' + new Date(s.armedAt).toLocaleString('fr-FR') + '.'
      : 'Sans cette case, aucune publication automatique n’est possible.';
    fillIfIdle(auto.dry, s.dryRun !== false, 'checked');
    fillIfIdle(auto.keepTabs, !!s.keepTabs, 'checked');
    fillIfIdle(auto.interval, String(s.intervalMin || 5));
    fillIfIdle(auto.cap, String(s.dailyCap || 10));
    fillIfIdle(auto.urlLbc, (s.depositUrls && s.depositUrls.leboncoin) || '');
    fillIfIdle(auto.urlVinted, (s.depositUrls && s.depositUrls.vinted) || '');

    auto.lbc.disabled = !s.armed || queued || !autoState.configured;
    auto.vinted.disabled = !s.armed || queued || !autoState.configured;
    auto.pause.disabled = !running;
    auto.resume.disabled = !paused || !s.armed;
    auto.skip.disabled = !paused;
    auto.stop.disabled = !queued;

    auto.badge.className = 'badge' + (running ? ' running' : paused ? ' paused' : status === 'done' ? ' done' : '');
    auto.badge.textContent = running ? 'En cours' : paused ? 'En pause' : status === 'done' ? 'Terminée' : 'Arrêtée';

    if (!autoState.configured) {
      auto.status.textContent = 'Configurez d’abord la boutique et le jeton dans Réglages.';
      auto.status.className = 'msg err';
    } else if (!queue || status === 'idle') {
      auto.status.textContent = s.armed
        ? (s.dryRun !== false
          ? 'Prêt (simulation) : choisissez une plateforme.'
          : 'Prêt : choisissez une plateforme. La publication réelle cliquera « Publier ».')
        : 'Publication automatique désactivée : cochez « J’assume le risque » pour l’autoriser.';
      auto.status.className = 'msg';
    } else if (running) {
      auto.status.textContent = queue.channel + ' · ' + Math.min(queue.index + 1, queue.refs.length) + '/' +
        queue.refs.length + ' · ' + (queue.lastMessage || 'en cours');
      auto.status.className = 'msg';
    } else if (paused) {
      auto.status.textContent = 'En pause · ' + (queue.lastMessage || 'vérifiez l’onglet ouvert.');
      auto.status.className = 'msg err';
    } else {
      auto.status.textContent = 'Terminée · ' + statsLine(queue);
      auto.status.className = 'msg ok';
    }

    auto.queue.hidden = !queue || queue.status === 'idle';
    auto.queue.replaceChildren();
    if (queue && queue.status !== 'idle') {
      const total = queue.refs.length || 1;
      const pct = Math.min(100, Math.round((queue.index / total) * 100));
      const line = document.createElement('div');
      line.textContent = queue.index + '/' + queue.refs.length + ' traitée(s) · ' + statsLine(queue);
      const bar = document.createElement('div');
      bar.className = 'bar';
      const fill = document.createElement('i');
      fill.style.width = pct + '%';
      bar.appendChild(fill);
      auto.queue.append(line, bar);
    }

    auto.history.replaceChildren();
    const history = autoState.history || [];
    auto.clearHistory.hidden = !history.length;
    history.forEach((entry) => {
      const li = document.createElement('li');
      const left = document.createElement('span');
      left.textContent = new Date(entry.at).toLocaleString('fr-FR', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit',
      }) + ' · ' + entry.ref;
      const right = document.createElement('span');
      right.className = 'act ' + entry.action;
      right.textContent = ({
        rejected: 'écartée (fiche)',
        published: 'publiée',
        prepared: 'simulée',
        blocked: 'arrêt',
        skipped: 'passée',
        'to-update': 'à mettre à jour',
        error: 'erreur',
      }[entry.action] || entry.action) + (entry.dryRun ? ' (sim.)' : '');
      li.append(left, right);
      auto.history.appendChild(li);
    });
  }

  async function refreshAuto() {
    const state = await autoSend({ type: 'am-auto-state' });
    if (!state || !state.ok) {
      auto.status.textContent = 'Extension : ' + ((state && state.error) || 'état indisponible.');
      auto.status.className = 'msg err';
      return;
    }
    autoState = state;
    renderAuto();
  }

  async function patchAuto(patch) {
    const res = await autoSend({ type: 'am-auto-settings', patch });
    if (!res || !res.ok) {
      auto.status.textContent = (res && res.error) || 'Réglage non enregistré.';
      auto.status.className = 'msg err';
      return;
    }
    if (!autoState) autoState = {};
    autoState.settings = res.settings;
    renderAuto();
  }

  async function startAutoRun(channel) {
    if (!autoState) return;
    if (!autoState.settings.armed) {
      auto.status.textContent = 'Cochez « J’assume le risque » d’abord.';
      auto.status.className = 'msg err';
      return;
    }
    if (autoState.settings.dryRun === false) {
      const label = channel === 'leboncoin' ? 'Leboncoin' : 'Vinted';
      if (!confirm('Publier automatiquement sur ' + label + ' ?\n\nLe navigateur remplira puis cliquera « Publier » à votre place. Vous restez responsable du respect des CGU de la plateforme.')) {
        return;
      }
    }
    auto.status.textContent = 'Démarrage…';
    auto.status.className = 'msg';
    const res = await autoSend({ type: 'am-auto-start', channel });
    if (!res || !res.ok) {
      auto.status.textContent = (res && res.error) || 'Démarrage impossible.';
      auto.status.className = 'msg err';
      return;
    }
    await refreshAuto();
  }

  auto.armed.addEventListener('change', () => patchAuto({ armed: auto.armed.checked }));
  auto.dry.addEventListener('change', () => patchAuto({ dryRun: auto.dry.checked }));
  auto.keepTabs.addEventListener('change', () => patchAuto({ keepTabs: auto.keepTabs.checked }));
  auto.interval.addEventListener('change', () => {
    const value = intInRange(auto.interval.value, 2, 30, 5);
    auto.interval.value = String(value);
    patchAuto({ intervalMin: value });
  });
  auto.cap.addEventListener('change', () => {
    const value = intInRange(auto.cap.value, 1, 30, 10);
    auto.cap.value = String(value);
    patchAuto({ dailyCap: value });
  });
  auto.urlLbc.addEventListener('change', () => patchAuto({ depositUrls: { leboncoin: auto.urlLbc.value.trim() } }));
  auto.urlVinted.addEventListener('change', () => patchAuto({ depositUrls: { vinted: auto.urlVinted.value.trim() } }));
  auto.lbc.addEventListener('click', () => startAutoRun('leboncoin'));
  auto.vinted.addEventListener('click', () => startAutoRun('vinted'));

  auto.pause.addEventListener('click', async () => {
    const res = await autoSend({ type: 'am-auto-pause' });
    if (res && !res.ok) auto.status.textContent = res.error;
    await refreshAuto();
  });
  auto.resume.addEventListener('click', async () => {
    const res = await autoSend({ type: 'am-auto-resume' });
    if (res && !res.ok) {
      auto.status.textContent = res.error;
      auto.status.className = 'msg err';
    }
    await refreshAuto();
  });
  auto.skip.addEventListener('click', async () => {
    const res = await autoSend({ type: 'am-auto-skip' });
    if (res && !res.ok) {
      auto.status.textContent = res.error;
      auto.status.className = 'msg err';
    }
    await refreshAuto();
  });
  auto.stop.addEventListener('click', async () => {
    const res = await autoSend({ type: 'am-auto-stop' });
    if (res && !res.ok) auto.status.textContent = res.error;
    await refreshAuto();
  });
  auto.clearHistory.addEventListener('click', async () => {
    await autoSend({ type: 'am-auto-clear-history' });
    await refreshAuto();
  });

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local') return;
    if (changes.amQueue || changes.amAutoSettings || changes.amAutoHistory || changes.amAutoDay) {
      refreshAuto();
    }
  });
  setInterval(refreshAuto, 2500);

  refreshConn();
  refreshAuto();
})();
