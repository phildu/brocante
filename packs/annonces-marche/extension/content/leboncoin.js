/* Préremplissage assisté du formulaire Leboncoin (validation humaine obligatoire). */
(function () {
  function ensureFill() {
    return new Promise((resolve) => {
      if (globalThis.AmFill) return resolve(globalThis.AmFill);
      const s = document.createElement('script');
      // Les content scripts du même pack partagent le monde isolé : fill.js est chargé via importScripts? Non en MV3 content.
      // On inline les helpers si absents (fill.js listé avant dans le manifeste).
      resolve(null);
    });
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg || msg.type !== 'am-fill' || msg.channel !== 'leboncoin') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const done = [];
      if (F.fillText(['titre', 'title', 'subject', 'ad_title'], p.title || p.name)) done.push('titre');
      if (F.fillText(['description', 'body', 'texte', 'contenu'], p.description)) done.push('description');
      if (F.fillPrice(p.price)) done.push('prix');
      const cond = (p.condition && p.condition.leboncoin && p.condition.leboncoin.channel_label) || '';
      if (cond && F.fillText(['état', 'etat', 'condition', 'state'], cond)) done.push('état');
      if (p.brand && F.fillText(['marque', 'brand'], p.brand)) done.push('marque');

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          photoMsg +
          '. Vérifiez la catégorie et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });

  ensureFill();
})();
