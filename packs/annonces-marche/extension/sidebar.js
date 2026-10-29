/* Panneau latéral persistant pendant le préremplissage et l’ajout des photos. */
(function () {
  const $ = (id) => document.getElementById(id);
  const title = $('title');
  const channel = $('channel');
  const status = $('status');
  const fields = $('fields');
  const retry = $('retry');
  let selected = null;

  const labels = [
    ['brand', 'Marque'],
    ['categoryHint', 'Catégorie suggérée'],
    ['size_text', 'Taille / dimensions'],
    ['colors', 'Couleur'],
    ['materials', 'Matière'],
    ['price', 'Prix'],
    ['conditionLabel', 'État'],
    ['description', 'Description'],
  ];

  function renderSelection(data) {
    selected = data && data.product ? data : null;
    fields.replaceChildren();
    if (!selected) {
      title.textContent = 'Aucun article sélectionné';
      channel.textContent = 'Choisissez un article dans la popup de l’extension.';
      status.textContent = 'Les informations de l’article sélectionné apparaîtront ici.';
      fields.hidden = true;
      retry.hidden = true;
      return;
    }

    const product = selected.product;
    title.textContent = product.title || product.name || 'Article';
    channel.textContent = selected.channel === 'vinted' ? 'Préparation pour Vinted' : 'Préparation pour Leboncoin';
    status.textContent = 'Informations prêtes à reporter. Vous gardez la main sur la publication.';
    for (const [key, label] of labels) {
      const value = key === 'price'
        ? product.price == null ? '' : Number(product.price).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) + ' €'
        : key === 'conditionLabel'
          ? product.condition && product.condition[selected.channel] && product.condition[selected.channel].channel_label
          : key === 'categoryHint'
            ? [product.nature_label, product.subcategory_label].filter(Boolean).join(' › ') || product.category_label
            : product[key];
      if (value == null || String(value).trim() === '') continue;
      const item = document.createElement('div');
      item.className = 'field';
      const term = document.createElement('dt');
      term.textContent = label;
      const description = document.createElement('dd');
      description.textContent = String(value);
      item.append(term, description);
      fields.appendChild(item);
    }
    fields.hidden = false;
    retry.hidden = false;
  }

  chrome.storage.local.get(['amSelectedProduct', 'amFillStatus']).then((data) => {
    renderSelection(data.amSelectedProduct);
    if (data.amFillStatus) status.textContent = data.amFillStatus;
  });
  chrome.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local') return;
    if (changes.amSelectedProduct) renderSelection(changes.amSelectedProduct.newValue);
    if (changes.amFillStatus) status.textContent = changes.amFillStatus.newValue || '';
  });

  retry.addEventListener('click', async () => {
    if (!selected) return;
    retry.disabled = true;
    status.textContent = 'Recherche de l’onglet actif…';
    try {
      const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
      if (!tab || !tab.id) throw new Error('Aucun onglet actif.');
      const host = new URL(tab.url || '').hostname;
      const supported = selected.channel === 'vinted'
        ? /(^|\.)vinted\.fr$/i.test(host)
        : /(^|\.)leboncoin\.fr$/i.test(host);
      if (!supported) throw new Error('Ouvrez d’abord un formulaire de dépôt sur ' + (selected.channel === 'vinted' ? 'Vinted.' : 'Leboncoin.'));
      const response = await chrome.tabs.sendMessage(tab.id, {
        type: 'am-fill',
        channel: selected.channel,
        product: selected.product,
      });
      if (!response || !response.ok) throw new Error(response && response.error || 'Le formulaire n’a pas répondu. Rechargez la page et réessayez.');
      status.textContent = 'Préremplissage relancé. Vérifiez les champs sur le site.';
      await chrome.storage.local.set({ amFillStatus: status.textContent });
    } catch (error) {
      status.textContent = error.message || 'Impossible de relancer le préremplissage.';
      await chrome.storage.local.set({ amFillStatus: status.textContent });
    } finally {
      retry.disabled = false;
    }
  });
})();
