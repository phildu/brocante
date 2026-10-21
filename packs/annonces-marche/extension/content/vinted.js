/* Préremplissage assisté du formulaire Vinted (validation humaine obligatoire). */
(function () {
  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg || msg.type !== 'am-fill' || msg.channel !== 'vinted') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const done = [];
      if (F.fillText(['titre', 'title', 'heading'], p.title || p.name)) done.push('titre');
      if (F.fillText(['description', 'details', 'texte'], p.description)) done.push('description');
      if (F.fillPrice(p.price)) done.push('prix');
      const cond = (p.condition && p.condition.vinted && p.condition.vinted.channel_label) || '';
      if (cond && F.fillText(['état', 'etat', 'condition', 'status'], cond)) done.push('état');
      if (p.brand && F.fillText(['marque', 'brand'], p.brand)) done.push('marque');
      if (p.size_text && F.fillText(['taille', 'size'], p.size_text)) done.push('taille');

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          photoMsg +
          '. Vérifiez catégorie / livraison et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
