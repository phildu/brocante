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
    const url = cfg.apiBase.replace(/\/+$/, '') + path;
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

  function renderList(items) {
    list.innerHTML = '';
    detail.hidden = true;
    if (!items.length) {
      list.innerHTML = '<li class="empty">Aucun article.</li>';
      return;
    }
    items.forEach((item) => {
      const li = document.createElement('li');
      const thumb = item.photos && item.photos[0] ? item.photos[0].url : '';
      li.innerHTML =
        (thumb ? '<img src="' + thumb + '" alt="">' : '<span class="ph"></span>') +
        '<span class="meta"><strong></strong><small></small></span>';
      li.querySelector('strong').textContent = item.title || item.name;
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
      detail.append(title, desc, actions, note);
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

  btnSearch.addEventListener('click', () => search(q.value));
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') search(q.value);
  });

  refreshConn();
})();
