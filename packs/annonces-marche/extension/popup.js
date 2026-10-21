/* Popup de l’extension : connexion boutique, catalogue, envoi au formulaire ouvert. */
(function () {
  const $ = (id) => document.getElementById(id);
  const settings = $('settings');
  const conn = $('conn');
  const list = $('list');
  const detail = $('detail');
  const q = $('q');
  const btnSearch = $('btn-search');

  function normalizeApiBase(raw) {
    let u = (raw || '').trim().replace(/\/+$/, '');
    if (!u) return '';
    if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
    if (!/\/api\/annonces$/i.test(u)) u += '/api/annonces';
    return u;
  }

  async function loadConfig() {
    const data = await chrome.storage.sync.get(['apiBase', 'token', 'shopName']);
    return {
      apiBase: data.apiBase || '',
      token: data.token || '',
      shopName: data.shopName || '',
    };
  }

  async function saveConfig(apiBase, token) {
    await chrome.storage.sync.set({ apiBase, token });
  }

  async function api(path, opts = {}) {
    const cfg = await loadConfig();
    if (!cfg.apiBase || !cfg.token) throw new Error('Configurez l’URL et le jeton.');
    const url = cfg.apiBase.replace(/\/+$/, '') + path;
    const headers = Object.assign(
      { Accept: 'application/json', 'X-Annonces-Token': cfg.token },
      opts.headers || {}
    );
    if (opts.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(url, {
      method: opts.method || 'GET',
      headers,
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err = new Error(data.error || 'Erreur ' + res.status);
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

  async function refreshConn() {
    const cfg = await loadConfig();
    $('shop-url').value = cfg.apiBase;
    $('token').value = cfg.token;
    if (!cfg.apiBase || !cfg.token) {
      conn.textContent = 'Configurez la boutique dans Réglages.';
      q.disabled = true;
      btnSearch.disabled = true;
      showSettings(true);
      return;
    }
    try {
      const ping = await api('/ping');
      await chrome.storage.sync.set({ shopName: ping.shop || '' });
      if (!ping.entitled) {
        conn.textContent = (ping.shop || 'Boutique') + ' — option non active (abonnez-vous dans l’admin).';
        q.disabled = true;
        btnSearch.disabled = true;
        return;
      }
      if (!ping.authenticated) {
        conn.textContent = 'Jeton refusé. Régénérez-le dans l’administration.';
        q.disabled = true;
        btnSearch.disabled = true;
        return;
      }
      conn.textContent = (ping.shop || 'Boutique') + ' — connecté';
      q.disabled = false;
      btnSearch.disabled = false;
      showSettings(false);
      await search('');
    } catch (e) {
      conn.textContent = e.message || 'Connexion impossible.';
      q.disabled = true;
      btnSearch.disabled = true;
      showSettings(true);
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

  async function search(query) {
    conn.textContent = 'Recherche…';
    try {
      const data = await api('/catalogue?q=' + encodeURIComponent(query || '') + '&limit=25');
      conn.textContent = data.total + ' article' + (data.total > 1 ? 's' : '');
      renderList(data.items || []);
    } catch (e) {
      conn.textContent = e.message;
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
    const apiBase = normalizeApiBase($('shop-url').value);
    const token = ($('token').value || '').trim();
    await saveConfig(apiBase, token);
    $('settings-msg').textContent = 'Enregistré.';
    await refreshConn();
  });

  $('btn-ping').addEventListener('click', async () => {
    const apiBase = normalizeApiBase($('shop-url').value);
    const token = ($('token').value || '').trim();
    await saveConfig(apiBase, token);
    $('settings-msg').textContent = 'Test…';
    try {
      await refreshConn();
      $('settings-msg').textContent = 'OK.';
    } catch (e) {
      $('settings-msg').textContent = e.message || 'Échec';
    }
  });

  btnSearch.addEventListener('click', () => search(q.value));
  q.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') search(q.value);
  });

  refreshConn();
})();
