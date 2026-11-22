/* Contrôle des fiches avant remplissage : repère ce qui va bloquer ou mal passer sur Leboncoin / Vinted.
 * Chargé par la popup (<script>) et par le service worker (importScripts). Fonctions pures, sans accès au navigateur.
 *   AmQuality.check(product, channel) → { block: [{field, message}], warn: [{field, message}] }
 * « block » : la plateforme refusera l'annonce ou le remplissage ne peut pas aboutir ; « warn » : à vérifier. */
(function (global) {
  const squash = (v) => String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

  const CLOTHES_OR_SHOES = /(vetement|robe|jupe|pantalon|jean|short|veste|manteau|blouson|pull|gilet|chemise|t-?shirt|chaussure|basket|botte|bottine|sandale|escarpin|derbie|mocassin|ballerine|costume|lingerie|maillot|combinaison|doudoune)/;
  const CONTACT = /(https?:\/\/|www\.|[\w.+-]+@[\w-]+\.[a-z]{2,}|(?:\+33|\b0)[1-9](?:[ .-]?\d{2}){4}\b)/i;
  const PROMO_WORDS = /\b(urgent|promo|pas cher|gratuit|soldes?|affaire|bonne affaire|top prix)\b/i;
  const ESTIMATED = /(probable|estim|environ|\?|~|approx)/i;

  function nature(p) {
    return squash([p.nature_label, p.subcategory_label, p.category_label, p.nature, p.sous_categorie].join(' '));
  }

  function needsSize(p) {
    return CLOTHES_OR_SHOES.test(nature(p));
  }

  function hasSize(p) {
    const text = String(p.size_text || '');
    const mapped = p.mapped && !Array.isArray(p.mapped) ? p.mapped : {};
    if ((mapped.leboncoin && mapped.leboncoin.taille) || (mapped.vinted && mapped.vinted.taille)) return true;
    return /\b(XXS|XS|S|M|L|XL|XXL|XXXL|3[2-9]|4[0-9]|5[0-2])\b/i.test(text);
  }

  function conditionKnown(p, channel) {
    const c = p.condition && p.condition[channel];
    if (c && (c.channel_label || c.key || c.label)) return true;
    const mapped = p.mapped && !Array.isArray(p.mapped) ? p.mapped : {};
    return !!(mapped[channel] && mapped[channel].etat);
  }

  function letters(text) {
    return String(text || '').replace(/[^A-Za-zÀ-ÿ]/g, '');
  }

  function check(product, channel) {
    const p = product || {};
    const platform = channel === 'vinted' ? 'vinted' : 'leboncoin';
    const name = platform === 'vinted' ? 'Vinted' : 'Leboncoin';
    const block = [];
    const warn = [];
    const add = (list, field, message) => list.push({ field, message });

    // --- Titre : présence, longueur, forme ---
    const title = String(p.title || p.name || '').trim();
    if (!title) {
      add(block, 'titre', 'Titre vide.');
    } else {
      if (title.length < 12) add(warn, 'titre', 'Titre très court (« ' + title + ' ») : les acheteurs trouveront moins l’annonce.');
      if (String(p.name || '').trim().length > 70) add(warn, 'titre', 'Titre coupé à 70 caractères pour l’annonce : vérifiez qu’il garde son sens.');
      if (/\s[-–—:]\s*(vendeur|vente|annonce)?\s*$/i.test(title) || /[-–—:,;]\s*$/.test(title)) add(warn, 'titre', 'Le titre se termine par un séparateur (« … ' + title.slice(-12) + ' »).');
      if (/\s-\s*(vendeur|vente)\b/i.test(title)) add(warn, 'titre', 'Le titre contient « Vendeur » : reste d’une saisie interne ?');
      if (/\s{2,}/.test(title)) add(warn, 'titre', 'Espaces en double dans le titre.');
      const L = letters(title);
      if (L.length >= 8 && letters(title.replace(/[a-zà-ÿ]/g, '')).length / L.length > 0.6) add(warn, 'titre', 'Titre presque tout en majuscules : refusé ou mal classé par les plateformes.');
      if (/[!?]{2,}|[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/u.test(title)) add(warn, 'titre', 'Points d’exclamation répétés ou émoticônes dans le titre.');
      if (PROMO_WORDS.test(title)) add(warn, 'titre', 'Mot promotionnel dans le titre (« urgent », « promo »…) : déconseillé par ' + name + '.');
      if (/\d+\s*(€|euros?)/i.test(title)) add(warn, 'titre', 'Le prix figure dans le titre : à retirer.');
    }

    // --- Description ---
    const desc = String(p.description || '');
    if (desc.trim().length < 40) add(warn, 'description', 'Description très courte : ajoutez l’état, les dimensions, les défauts.');
    if (CONTACT.test(desc)) add(warn, 'description', 'Adresse web, e-mail ou téléphone dans la description : interdit par ' + name + ', l’annonce peut être refusée.');
    if (platform === 'vinted' && desc.length > 1900) add(warn, 'description', 'Description de ' + desc.length + ' caractères : Vinted limite à 2 000.');
    if (platform === 'leboncoin' && desc.length > 3900) add(warn, 'description', 'Description de ' + desc.length + ' caractères : Leboncoin limite à 4 000.');

    // --- Prix ---
    const price = Number(p.price);
    if (p.price == null || p.price === '' || !Number.isFinite(price) || price <= 0) {
      add(block, 'prix', 'Prix absent ou nul' + (p.price_text ? ' (« ' + p.price_text + ' » n’est pas un montant)' : '') + '.');
    } else {
      if (price < 1) add(warn, 'prix', 'Prix inférieur à 1 €.');
      if (platform === 'leboncoin' && Math.abs(price - Math.round(price)) > 0.001) add(warn, 'prix', 'Leboncoin n’accepte que des euros entiers : ' + String(price).replace('.', ',') + ' € sera arrondi à ' + Math.round(price) + ' €.');
      if (price > 5000) add(warn, 'prix', 'Prix supérieur à 5 000 € : vérifiez le montant.');
    }

    // --- Photos ---
    const photos = Array.isArray(p.photos) ? p.photos.filter((x) => x && x.url) : [];
    if (!photos.length) add(block, 'photos', 'Aucune photo : ' + name + ' exige au moins une photo.');
    else if (photos.length < 3) add(warn, 'photos', 'Seulement ' + photos.length + ' photo' + (photos.length > 1 ? 's' : '') + ' : une annonce avec 3 photos ou plus est bien plus vue.');

    // --- État ---
    if (!conditionKnown(p, platform)) {
      if (platform === 'vinted') add(block, 'état', 'État non renseigné : Vinted l’exige pour publier.');
      else add(warn, 'état', 'État non renseigné : le menu « État » restera vide sur Leboncoin.');
    }

    // --- Taille / pointure ---
    if (needsSize(p)) {
      if (!hasSize(p)) add(block, 'taille', 'Taille ou pointure absente : ' + name + ' l’exige pour cette catégorie (vêtements, chaussures).');
      else if (ESTIMATED.test(String(p.size_text || ''))) add(warn, 'taille', 'Taille estimée (« ' + String(p.size_text).trim().slice(0, 30) + ' ») : vérifiez-la avant de publier, une erreur entraîne des retours.');
    }

    // --- Marque, couleur, catégorie ---
    if (!String(p.brand || '').trim() && platform === 'vinted') add(warn, 'marque', 'Marque absente : « Inconnu » sera choisi sur Vinted (marque obligatoire).');
    if (!String(p.colors || '').trim() && needsSize(p)) add(warn, 'couleur', 'Couleur non renseignée : le menu « Couleur » sera laissé au choix du site.');
    if (!String(p.nature_label || '').trim() && !String(p.subcategory_label || '').trim()) add(warn, 'catégorie', 'Catégorie non renseignée : l’extension prendra la première suggestion du site, à vérifier.');
    if (Number(p.lot_qty) > 1) add(warn, 'lot', 'Vendu en lot de ' + Number(p.lot_qty) + ' : vérifiez que le titre et le prix le disent.');

    return { block, warn };
  }

  /** « ⛔ 2 bloquant(s), ⚠ 1 à vérifier » ou '' quand tout va bien. */
  function summary(result) {
    const parts = [];
    if (result.block.length) parts.push('⛔ ' + result.block.length + ' bloquant' + (result.block.length > 1 ? 's' : ''));
    if (result.warn.length) parts.push('⚠ ' + result.warn.length + ' à vérifier');
    return parts.join(', ');
  }

  function lines(result) {
    return result.block.map((i) => '⛔ ' + i.field + ' : ' + i.message).concat(result.warn.map((i) => '⚠ ' + i.field + ' : ' + i.message));
  }

  global.AmQuality = { check, summary, lines, needsSize, hasSize };
  if (typeof module !== 'undefined' && module.exports) module.exports = global.AmQuality;
})(typeof globalThis !== 'undefined' ? globalThis : this);
