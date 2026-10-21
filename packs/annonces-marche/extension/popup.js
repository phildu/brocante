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
      desc.textContent = (p.description || '').slice(0, 280) + ((p.description || '').length > 280 ? '…' : '');
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
      const note = document.createElement('p');
      note.className = 'msg';
      note.textContent = 'Ouvrez d’abord le formulaire de dépôt, puis cliquez. Vous validez la publication vous-même.';
      detail.append(title, desc, actions, note);
    } catch (e) {
      detail.innerHTML = '<p class="msg">' + (e.message || 'Erreur') + '</p>';
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
    try {
      await chrome.tabs.sendMessage(tab.id, { type: 'am-fill', channel, product });
      await api('/marquer', {
        method: 'POST',
        body: {
          product_ref: product.ref,
          channel,
          status: 'draft',
          note: 'Formulaire prérempli depuis l’extension',
        },
      });
    } catch (e) {
      alert(e.message || 'Impossible de remplir cette page. Rechargez l’onglet et réessayez.');
    }
  }

  $('btn-settings').addEventListener('click', () => {
    showSettings(settings.hidden);
  });

  $('btn-save').addEventListener('click', async () => {
    const cfg = readFormConfig();
    if (!cfg.apiBase || !cfg.token) {
      $('settings-msg').textContent = 'Adresse et jeton requis.';
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
      $('settings-msg').textContent = 'Adresse et jeton requis.';
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
