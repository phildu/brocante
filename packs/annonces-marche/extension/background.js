/* Service worker minimal : relais éventuel, pas de publication automatique. */
chrome.runtime.onInstalled.addListener(() => {
  // Rien à initialiser : la config vit dans chrome.storage.sync (popup).
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (!message || message.type !== 'am-start-fill') return false;

  (async () => {
    const { tabId, channel, product } = message;
    if (!Number.isInteger(tabId) || !['leboncoin', 'vinted'].includes(channel) || !product) {
      throw new Error('Article ou onglet invalide.');
    }

    await chrome.storage.local.set({
      amSelectedProduct: { product, channel, selectedAt: Date.now() },
      amFillStatus: 'Préremplissage en cours…',
    });

    const result = await chrome.tabs.sendMessage(tabId, { type: 'am-fill', channel, product });
    if (!result || !result.ok) {
      throw new Error(result && result.error || 'Le formulaire n’a pas répondu. Rechargez la page et réessayez.');
    }

    let historyMessage = '';
    try {
      const { apiBase, token } = await chrome.storage.local.get(['apiBase', 'token']);
      if (apiBase && token) {
        const response = await fetch(apiBase.replace(/\/+$/, '') + '/marquer', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Annonces-Token': token,
          },
          body: JSON.stringify({
            product_ref: product.ref,
            channel,
            status: 'draft',
            note: 'Formulaire prérempli depuis l’extension',
          }),
        });
        if (!response.ok) {
          const data = await response.json().catch(() => ({}));
          throw new Error(data.error || 'Erreur HTTP ' + response.status);
        }
      }
    } catch (error) {
      historyMessage = ' Le formulaire est rempli, mais l’historique n’a pas pu être enregistré : ' +
        (error.message || 'erreur inconnue');
    }

    await chrome.storage.local.set({
      amFillStatus: 'Préremplissage effectué. Vérifiez les champs et les photos sur le site.' + historyMessage,
    });
    sendResponse({ ok: true });
  })().catch(async (error) => {
    const messageText = error.message || 'Impossible de remplir cette page.';
    await chrome.storage.local.set({ amFillStatus: messageText });
    sendResponse({ ok: false, error: messageText });
  });

  return true;
});
