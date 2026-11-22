/* Préremplissage assisté du formulaire Vinted (validation humaine par défaut). */
(function () {
  function fieldSteps(p, F) {
    const steps = [
      ['titre', () => F.fillText(['titre', 'title', 'heading'], p.title || p.name)],
      ['description', () => F.fillText(['description', 'details', 'texte'], p.description)],
      ['prix', () => F.fillPrice(p.price)],
    ];
    const cond = (p.condition && p.condition.vinted && p.condition.vinted.channel_label) || '';
    if (cond) steps.push(['état', () => F.fillText(['état', 'etat', 'condition', 'status'], cond)]);
    if (p.brand) steps.push(['marque', () => F.fillText(['marque', 'brand', 'brand name'], p.brand)]);
    if (p.size_text) steps.push(['taille', () => F.fillText(['taille', 'size'], p.size_text)]);
    if (p.colors) steps.push(['couleur', () => F.fillText(['couleur', 'couleurs', 'color', 'colors'], p.colors)]);
    if (p.materials) steps.push(['matière', () => F.fillText(['matière', 'matières', 'material', 'materials'], p.materials)]);

    const categoryValue = p.subcategory_label || p.nature_label || p.category_label || '';
    if (categoryValue) {
      steps.push(['catégorie', () => F.fillText(['catégorie', 'categorie', 'category', 'item category'], categoryValue)]);
    }
    return steps;
  }

  function categoryHint(p) {
    return [p.nature_label, p.subcategory_label].filter(Boolean).join(' › ') || p.category_label || '';
  }

  async function fillFields(p, F, quiet) {
    const product = p || {};
    const steps = fieldSteps(product, F);
    const done = [];
    const pending = [];
    for (let i = 0; i < steps.length; i++) {
      if (i) await F.sleep(180 + Math.floor(Math.random() * 320));
      const [name, run] = steps[i];
      let ok = false;
      try { ok = run(); } catch (_) { ok = false; }
      if (ok) done.push(name);
      else pending.push(name);
    }
    void quiet;
    return { done, pending, categoryFilled: done.includes('catégorie'), hint: categoryHint(product) };
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg) return;
    if (msg.type === 'am-auto-ping') {
      sendResponse({ ready: !!globalThis.AmFill, channel: 'vinted' });
      return;
    }
    if (msg.type === 'am-auto-step' && msg.channel === 'vinted') {
      (async () => {
        const F = globalThis.AmFill;
        if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
        const res = await F.autoStep({
          product: msg.product || {},
          options: msg.options || {},
          fill: (prod, quiet) => fillFields(prod, F, quiet),
        });
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'vinted') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto() : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type !== 'am-fill' || msg.channel !== 'vinted') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const { done, categoryFilled, hint } = await fillFields(p, F, false);

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (!categoryFilled && hint ? ' · catégorie à choisir : ' + hint : '') +
          photoMsg +
          '. Vérifiez catégorie / livraison et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
