/* Préremplissage assisté du formulaire Leboncoin (validation humaine par défaut). */
(function () {
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

  // Le formulaire Leboncoin se déroule par étapes : le titre est là d'emblée, mais la description et le prix
  // n'apparaissent qu'après le choix de la catégorie. Les champs non trouvés sont donc retentés à leur apparition.
  function fieldSteps(p, F) {
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
    return steps;
  }

  /**
   * Remplit champ par champ avec un court tempo, comme une saisie posée.
   * En mode auto (quiet), ne programme pas de réessai : le service worker rappelle l'étape suivante.
   */
  async function fillFields(p, F, quiet) {
    const steps = fieldSteps(p || {}, F);
    const done = [];
    const pending = [];
    for (let i = 0; i < steps.length; i++) {
      if (i) await F.sleep(180 + Math.floor(Math.random() * 320));
      const [name, run] = steps[i];
      let ok = false;
      try { ok = run(); } catch (_) { ok = false; }
      if (ok) done.push(name);
      else pending.push([name, run]);
    }
    if (!quiet) watchLater(pending, F);
    return { done, pending: pending.map(([n]) => n) };
  }

  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (!msg) return;
    if (msg.type === 'am-auto-ping') {
      sendResponse({ ready: !!globalThis.AmFill, channel: 'leboncoin' });
      return;
    }
    if (msg.type === 'am-auto-step' && msg.channel === 'leboncoin') {
      (async () => {
        const F = globalThis.AmFill;
        if (!F) { sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' }); return; }
        const res = await F.autoStep({
          product: msg.product || {},
          options: msg.options || {},
          fill: (p, quiet) => fillFields(p, F, quiet),
        });
        sendResponse(res);
      })().catch((e) => sendResponse({ ok: false, error: String(e) }));
      return true;
    }
    if (msg.type === 'am-auto-verify' && msg.channel === 'leboncoin') {
      const F = globalThis.AmFill;
      sendResponse(F ? F.verifyAuto() : { ok: false, error: 'Helpers absents : rechargez l’extension.' });
      return;
    }
    if (msg.type !== 'am-fill' || msg.channel !== 'leboncoin') return;
    (async () => {
      const F = globalThis.AmFill;
      if (!F) {
        sendResponse({ ok: false, error: 'Helpers absents : rechargez l’extension.' });
        return;
      }
      const p = msg.product || {};
      const { done, pending } = await fillFields(p, F, false);

      const photos = (p.photos || []).map((x) => x.url).filter(Boolean);
      const photoRes = await F.tryAttachPhotos(photos);
      let photoMsg = '';
      if (photoRes.ok > 0) photoMsg = ' · ' + photoRes.ok + ' photo(s) jointe(s)';
      else if (photos.length) photoMsg = ' · photos à ajouter à la main (le site refuse l’injection automatique)';

      F.toast(
        (done.length ? 'Champs remplis : ' + done.join(', ') : 'Aucun champ reconnu sur cette page') +
          (pending.length ? ' · à venir quand le champ apparaît : ' + pending.join(', ') : '') +
          photoMsg +
          '. Vérifiez la catégorie et publiez vous-même.'
      );
      sendResponse({ ok: true, filled: done, photos: photoRes });
    })().catch((e) => sendResponse({ ok: false, error: String(e) }));
    return true;
  });
})();
