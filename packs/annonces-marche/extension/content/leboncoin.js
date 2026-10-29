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

  // Retente les champs absents quand la page change (étape suivante du formulaire) ; s'arrête quand tout est rempli, ou après 15 minutes.
  let watcher = null;
  function watchLater(pending, F) {
    if (watcher) { watcher.disconnect(); watcher = null; }
    if (!pending.length) return;
    let timer = null;
    const started = Date.now();
    const attempt = () => {
      timer = null;
      const filled = [];
      pending = pending.filter(([name, run]) => {
        let ok = false;
        try { ok = run(); } catch (_) { ok = false; }
        if (ok) filled.push(name);
        return !ok;
      });
      if (filled.length) F.toast('Champs remplis à leur apparition : ' + filled.join(', ') + (pending.length ? '. En attente : ' + pending.map(([n]) => n).join(', ') + '.' : '. Vérifiez la catégorie et publiez vous-même.'));
      if (!pending.length || Date.now() - started > 15 * 60 * 1000) { watcher.disconnect(); watcher = null; }
    };
    watcher = new MutationObserver(() => { if (!timer) timer = setTimeout(attempt, 500); });
    watcher.observe(document.body, { childList: true, subtree: true });
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
      // Le formulaire Leboncoin se déroule par étapes : le titre est là d'emblée, mais la description et le prix n'apparaissent
      // qu'après le choix de la catégorie. Chaque champ non trouvé est donc retenté à son apparition (voir « watchLater »).
      const cond = (p.condition && p.condition.leboncoin && p.condition.leboncoin.channel_label) || '';
      const steps = [
        ['titre', () => F.fillText(['titre', 'title', 'subject', 'ad_title'], p.title || p.name)],
        ['description', () => F.fillText(['description', 'body', 'texte', 'contenu'], p.description)],
        ['prix', () => F.fillPrice(p.price)],
      ];
      if (cond) steps.push(['état', () => F.fillText(['état', 'etat', 'condition', 'state'], cond)]);
      if (p.brand) steps.push(['marque', () => F.fillText(['marque', 'brand'], p.brand)]);
      if (p.colors) steps.push(['couleur', () => F.fillText(['couleur', 'couleurs', 'color', 'colors'], p.colors)]);
      if (p.size_text) steps.push(['taille / dimensions', () => F.fillText(['taille', 'size', 'dimensions'], p.size_text)]);
      const done = [];
      let pending = steps.filter(([, run]) => {
        let ok = false;
        try { ok = run(); } catch (_) { ok = false; }
        return !ok;
      });
      steps.forEach(([name]) => { if (!pending.some(([n]) => n === name)) done.push(name); });
      watchLater(pending, F);

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à venir quand le champ apparaît : ' + pending.map(([n]) => n).join(', ') : '') +
          photoMsg +
          '. Vérifiez la catégorie et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });

  ensureFill();
})();
