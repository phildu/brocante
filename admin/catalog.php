<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/key-check.php';
require_once __DIR__ . '/../includes/connectors.php';
require_admin();
connectors_ensure_schema(); // colonnes d'import (source_id…) créées à la volée sur une base existante

$content = get_content();
$flash = flash_get();
$conditionRatesJson = json_encode(product_condition_price_rates(), JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$q = trim((string) ($_GET['q'] ?? ''));

$sortKey = (string) ($_GET['sort'] ?? 'date');
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$columns = ['name' => 'name', 'price' => 'price', 'date' => 'created_at'];
$orderCol = $columns[$sortKey] ?? 'created_at';

// ?source=ID : ne montre que les produits importés d'une boutique connectée.
$sourceId = (int) ($_GET['source'] ?? 0);
$conds = [];
$params = [];
if ($q !== '') {
    $conds[] = '(name LIKE ? OR ref LIKE ?)';
    array_push($params, "%$q%", "%$q%");
}
if ($sourceId > 0) {
    $conds[] = 'source_id = ?';
    $params[] = $sourceId;
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stmt = db()->prepare("SELECT * FROM products $where ORDER BY $orderCol $dir, ref");
$stmt->execute($params);
$products = $stmt->fetchAll();

$missingNature = db()->query("SELECT ref FROM products WHERE nature IS NULL OR nature = '' ORDER BY ref")->fetchAll(PDO::FETCH_COLUMN);
$currentUrl = '/admin/catalog.php?' . http_build_query(array_filter(['sort' => $sortKey, 'dir' => $dir, 'q' => $q, 'source' => $sourceId ?: null]));
$badgeOptions = ['Chiné', 'Fait main', 'Pièce unique', 'Promo'];
// Compteurs des visuels promo (30 derniers jours) par article de cette boutique.
$promoCounts = [];
foreach (promo_stats(gmdate('Y-m-d', time() - 29 * 86400), gmdate('Y-m-d'), 'ref', ['shop' => tenant_slug()]) as $row) {
    $promoCounts[$row['ref']]['impressions'] = ($promoCounts[$row['ref']]['impressions'] ?? 0) + $row['impressions'];
    $promoCounts[$row['ref']]['clicks'] = ($promoCounts[$row['ref']]['clicks'] ?? 0) + $row['clicks'];
}

// IA facultative : désactivée dans le portail, ses boutons disparaissent du catalogue.
$aiOn = ai_shop_enabled();
/** Petit bouton « ↻ IA » à côté d'une étiquette de champ (assets/product-ai.js), absent quand l'IA est désactivée. */
$aiBtn = static fn (string $field, string $title): string => $aiOn
    ? '<button type="button" class="ai-btn" data-ai-field="' . h($field) . '" title="' . h($title) . '">↻ IA</button>'
    : '';

function catalog_sort_link(string $key, string $label, string $sortKey, string $dir, string $q, int $sourceId = 0): string
{
    $nextDir = ($sortKey === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sortKey === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $url = '/admin/catalog.php?sort=' . urlencode($key) . '&dir=' . urlencode($nextDir) . ($q !== '' ? '&q=' . urlencode($q) : '') . ($sourceId > 0 ? '&source=' . $sourceId : '');
    return '<a href="' . h($url) . '" style="color:inherit;text-decoration:none;">' . h($label . $arrow) . '</a>';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catalogue — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/barcode-scan.css">
<link rel="stylesheet" href="/assets/product-modal.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/product-modal.css') ?>">
<link rel="stylesheet" href="/assets/promo-visual.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/promo-visual.css') ?>">
<style>
  .price-mgmt { border: 1px solid var(--line); padding: 10px 14px; margin: 6px 0 14px; background: var(--surface); } .price-mgmt summary { cursor: pointer; font-weight: 600; margin-bottom: 8px; }
  .price-line { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
  .price-line input { flex: 1 1 90px; min-width: 0; }
  .promo-btn { white-space: nowrap; }
  .promo-btn[aria-pressed="true"] { background: var(--accent); color: var(--accent-ink); }
  .promo-pcts { display: flex; flex-wrap: wrap; gap: 6px; }
  [data-promo-info][data-kind="error"] { color: #b3261e; }
  [data-promo-row][hidden] { display: none; }
  .catalog-table { width: 100%; border-collapse: collapse; }
  .catalog-table th { text-align: left; font-family: var(--font-mono); font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--ink-soft); padding: 10px 12px; border-bottom: 1px solid var(--line); }
  .catalog-table td { padding: 8px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
  .catalog-table tr:hover td { background: var(--surface-2); }
  .catalog-table tr.is-hidden-product td { opacity: 0.5; }
  .catalog-thumb { width: 52px; height: 52px; background: var(--surface-2); overflow: hidden; }
  .catalog-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .catalog-table a.catalog-name { color: var(--ink); text-decoration: none; font-weight: 500; }
  .catalog-table a.catalog-name:hover { text-decoration: underline; }
  .catalog-filters { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; }
  .catalog-filters input[type="search"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem;
  }
  .catalog-table td:first-child, .catalog-table th:first-child { width: 28px; }
  .catalog-edit-row td { background: var(--bg); padding: 20px 12px; }
  .bulk-toolbar { flex-wrap: wrap; gap: 10px 20px; }
  /* Mobile : la table devient une liste de cartes (case, vignette, nom, prix, date) ; le tri reste accessible en haut de liste. */
  @media (max-width: 780px) {
    .bulk-toolbar { flex-wrap: nowrap; overflow-x: auto; -webkit-overflow-scrolling: touch; gap: 10px; padding: 8px 12px; scrollbar-width: thin; }
    .bulk-toolbar > * { flex: none; white-space: nowrap; }
    .bulk-toolbar #nature-detect-status:empty { display: none; }
    .catalog-filters { flex-wrap: wrap; } .catalog-filters input[type="search"] { flex: 1 1 200px; min-height: 44px; }
    .catalog-table, .catalog-table tbody, .catalog-table thead { display: block; }
    .catalog-table thead tr { display: flex; flex-wrap: wrap; gap: 4px 14px; padding: 6px 2px 10px; border-bottom: 1px solid var(--line); }
    .catalog-table thead th { padding: 6px 0; border: 0; width: auto; } .catalog-table thead th:empty { display: none; } .catalog-table thead th a { display: inline-block; padding: 10px 0; }
    .catalog-table tbody tr:not(.catalog-edit-row) { display: grid; grid-template-columns: 24px 52px minmax(0, 1fr) auto; grid-template-areas: "chk thumb name name" "chk thumb price date"; gap: 4px 10px; align-items: center; padding: 10px 2px; border-bottom: 1px solid var(--line); }
    .catalog-table tbody tr:not(.catalog-edit-row) td { display: block; padding: 0; border: 0; min-width: 0; background: transparent; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(1) { grid-area: chk; width: auto; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(2) { grid-area: thumb; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(3) { grid-area: name; overflow-wrap: anywhere; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(3) a { display: inline-block; padding: 4px 0; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(4) { grid-area: price; font-weight: 600; white-space: nowrap; align-self: start; }
    .catalog-table tbody tr:not(.catalog-edit-row) td:nth-child(5) { grid-area: date; font-size: 0.8rem; color: var(--ink-soft); text-align: right; }
    .catalog-table tr:hover td { background: transparent; }
    .catalog-thumb { width: 52px; height: 52px; } .admin-block { padding-left: 6px; padding-right: 6px; }
    .catalog-edit-row:not([hidden]) { display: block; } .catalog-edit-row td { display: block; padding: 14px 4px; }
  }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'catalogue'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 20px;">Catalogue (<?= count($products) ?> pièces)</h1>
    <p class="hint">Cliquez un nom pour ouvrir sa fiche d'édition. Cochez « mise en avant » pour choisir les 3 pièces affichées en page d'accueil ; « masquer » retire une pièce de la boutique sans la supprimer.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:20px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div id="keycheck-box"><?= keycheck_alert_html() ?></div>
    <?php if (keycheck_stale()): ?>
    <script>
      fetch('/admin/key-check.php?json=1', {credentials: 'same-origin'}).then(function (r) { return r.json(); })
        .then(function (d) { document.getElementById('keycheck-box').innerHTML = d.html; }).catch(function () {});
    </script>
    <?php endif; ?>

    <form method="get" action="/admin/catalog.php" class="catalog-filters">
      <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
      <input type="hidden" name="dir" value="<?= h($dir) ?>">
      <?php if ($sourceId > 0): ?><input type="hidden" name="source" value="<?= $sourceId ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher (nom, réf.)...">
      <button type="submit" class="btn-small">Rechercher</button>
      <?php if ($q !== '' || $sourceId > 0): ?><a class="btn-small" href="/admin/catalog.php">Réinitialiser</a><?php endif; ?>
    </form>

    <?php if (!$products): ?>
      <p class="empty-state">Aucune pièce ne correspond.</p>
    <?php else: ?>
      <div class="bulk-toolbar">
        <label class="featured-check"><input type="checkbox" id="bulk-select-all"> Tout sélectionner</label>
        <span id="bulk-count" class="hint" style="margin:0;">0 sélectionnée(s)</span>
        <?php if ($aiOn): ?>
        <button type="button" class="btn-small" id="nature-detect" disabled title="L'IA détecte la nature (vêtement, déco…) et la sous-catégorie des pièces cochées d'après leurs photos">Détecter la nature (IA)</button>
        <?php if ($missingNature): ?><button type="button" class="btn-small" id="nature-detect-missing" data-refs="<?= h(json_encode($missingNature)) ?>" title="Détecte la nature de toutes les pièces qui n'en ont pas encore">Détecter pour les <?= count($missingNature) ?> pièces sans nature</button><?php endif; ?>
        <span class="hint" id="nature-detect-status" role="status" style="margin:0;"></span>
        <?php endif; ?>
        <button type="button" class="btn-small" data-bulk-action="hide" disabled>Masquer</button>
        <button type="button" class="btn-small" data-bulk-action="unhide" disabled>Réafficher</button>
        <button type="button" class="btn-small" data-bulk-action="promo" disabled>Mettre en promo</button>
        <button type="button" class="btn-small" data-bulk-action="unpromo" disabled>Retirer la promo</button>
        <button type="button" class="btn-small" id="bulk-labels" disabled title="Imprimer des étiquettes (prix, référence, QR code) pour les pièces cochées">🏷️ Étiquettes</button>
        <button type="button" class="admin-delete" data-bulk-action="delete" data-bulk-confirm="Supprimer définitivement {n} du catalogue ?" disabled>Supprimer la sélection</button>
      </div>
      <form method="post" action="/admin/bulk-action.php" id="bulk-action-form">
        <input type="hidden" name="redirect" value="<?= h($currentUrl) ?>">
      </form>

      <div class="admin-block" style="overflow-x:auto;">
        <table class="catalog-table">
          <thead>
            <tr>
              <th></th>
              <th></th>
              <th><?= catalog_sort_link('name', 'Nom', $sortKey, $dir, $q, $sourceId) ?></th>
              <th><?= catalog_sort_link('price', 'Prix', $sortKey, $dir, $q, $sourceId) ?></th>
              <th><?= catalog_sort_link('date', 'Mise en ligne', $sortKey, $dir, $q, $sourceId) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr class="<?= $p['is_hidden'] ? 'is-hidden-product' : '' ?>" data-ref="<?= h($p['ref']) ?>">
                <td><input type="checkbox" class="bulk-select" data-ref="<?= h($p['ref']) ?>"></td>
                <td>
                  <div class="catalog-thumb">
                    <?php if (!empty($p['photo'])): ?>
                      <img src="/<?= h($p['photo']) ?>" alt="">
                    <?php endif; ?>
                  </div>
                </td>
                <td><a class="catalog-name" href="#produit-<?= h($p['ref']) ?>" data-toggle-ref="<?= h($p['ref']) ?>">Réf. N°<?= h($p['ref']) ?> — <?= h($p['name']) ?></a><?php if ((int) $p['stock'] <= 0): ?> <span class="sold-tag">Vendue<?= product_keeps_when_sold($p) ? ' · au catalogue' : '' ?></span><?php endif; ?></td>
                <td><?= h($p['price']) ?></td>
                <td><?= h(date('d/m/Y', strtotime($p['created_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="pm-sources" hidden>
        <?php foreach ($products as $p):
          $ref = $p['ref'];
          $offerCount = (int) db()->query("SELECT COUNT(*) FROM price_offers WHERE ref = " . db()->quote($ref) . " AND status = 'new'")->fetchColumn();
          $estMin = $p['est_min'] !== null ? rtrim(rtrim(number_format((float) $p['est_min'], 2, '.', ''), '0'), '.') : '';
          $estMax = $p['est_max'] !== null ? rtrim(rtrim(number_format((float) $p['est_max'], 2, '.', ''), '0'), '.') : '';
          $pvRow = promo_visual_get($ref);
          $pvLive = has_promo_price($p);
          $pvFresh = $pvLive && promo_visual_fresh($p, $pvRow);
          $pvPhotos = array_values(array_map(static fn ($ph) => ['path' => $ph['path'], 'label' => $ph['label']], array_filter(product_photos_list($ref), static fn ($ph) => ($ph['type'] ?? 'photo') === 'photo')));
          if (!empty($p['photo'])) array_unshift($pvPhotos, ['path' => $p['photo'], 'label' => 'Couverture']);
          $allThumbs = product_photos_list($ref);
          $thumbs = array_values(array_filter($allThumbs, static fn ($t) => !$t['is_hidden']));
          $nbHiddenMedia = count($allThumbs) - count($thumbs);
          $pChannels = product_channels_of($p);
        ?>
        <form method="post" action="/admin/save-product.php" enctype="multipart/form-data" class="pm-form" id="pmf-<?= h($ref) ?>" data-ai-form data-ref="<?= h($ref) ?>" data-name="<?= h($p['name']) ?>" data-condition-rates="<?= h($conditionRatesJson) ?>">
          <input type="hidden" name="ref" value="<?= h($ref) ?>">
          <button type="submit" class="pm-default" tabindex="-1" aria-hidden="true">Enregistrer</button>
          <header class="pm-head">
            <div class="pm-title">
              <span class="pm-ref">Réf. N°<?= h($ref) ?></span>
              <strong data-pm-title><?= h($p['name']) ?></strong>
              <?php if ((int) $p['stock'] <= 0): ?><span class="sold-tag">Vendue<?= product_keeps_when_sold($p) ? ' · au catalogue' : '' ?></span><?php endif; ?>
              <?php if ($p['is_hidden']): ?><span class="sold-tag">Masquée</span><?php endif; ?>
            </div>
            <div class="pm-nav">
              <button type="button" class="pm-ico" data-pm-prev aria-label="Pièce précédente" title="Pièce précédente">‹</button>
              <button type="button" class="pm-ico" data-pm-next aria-label="Pièce suivante" title="Pièce suivante">›</button>
              <button type="button" class="pm-ico" data-pm-close aria-label="Fermer" title="Fermer (Échap)">✕</button>
            </div>
          </header>
          <p class="pm-flash" data-pm-flash hidden role="status"></p>
          <nav class="pm-tabs" role="tablist" aria-label="Sections de la fiche">
            <button type="button" role="tab" data-pm-tab="essentiel" aria-selected="true">Essentiel</button>
            <button type="button" role="tab" data-pm-tab="details" aria-selected="false">Détails</button>
            <button type="button" role="tab" data-pm-tab="prix" aria-selected="false">Prix<?= $offerCount ? ' <b class="pm-badge">' . $offerCount . '</b>' : '' ?></button>
            <button type="button" role="tab" data-pm-tab="vente" aria-selected="false">Vente</button>
          </nav>
          <div class="pm-body">
            <aside class="pm-media">
              <div class="admin-photo-preview"><?= product_media_html($p) ?></div>
              <?php if ($thumbs || $nbHiddenMedia): ?>
                <div class="fiche-media-strip">
                  <?php foreach ($thumbs as $t): $isVideo = ($t['type'] ?? 'photo') === 'video'; ?>
                    <figure class="fiche-media" tabindex="0" title="<?= h($t['label']) ?>">
                      <?php if ($isVideo): ?>
                        <video src="/<?= h($t['path']) ?>#t=0.1" muted playsinline preload="metadata"></video><span class="fiche-media-badge fiche-media-badge-video" aria-label="Vidéo">▶</span>
                      <?php else: ?>
                        <img src="/<?= h($t['path']) ?>" alt="<?= h($t['label']) ?>" loading="lazy">
                      <?php endif; ?>
                      <?php if (!empty($t['is_illustration'])): ?><span class="fiche-media-badge fiche-media-badge-ia" title="Visuel généré par l'IA">IA</span><?php endif; ?>
                      <figcaption><?= h($t['label']) ?></figcaption>
                    </figure>
                  <?php endforeach; ?>
                  <?php if ($nbHiddenMedia): ?>
                    <a class="fiche-media-note" href="/admin/gallery.php?ref=<?= h($ref) ?>"><?= $nbHiddenMedia ?> visuel<?= $nbHiddenMedia > 1 ? 's' : '' ?> masqué<?= $nbHiddenMedia > 1 ? 's' : '' ?> du diaporama — gérer</a>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
              <div class="pm-links">
                <a class="btn-small" href="/admin/gallery.php?ref=<?= h($ref) ?>">🖼 Gérer les photos</a>
                <a class="btn-small" href="/produit.php?ref=<?= h($ref) ?>" target="_blank" rel="noopener">Voir la fiche ↗</a>
              </div>
            </aside>

            <div class="pm-panels">
              <section class="pm-panel product-admin-fields" data-pm-panel="essentiel" role="tabpanel">
                <?php if ($aiOn): ?>
                <div class="ai-bar">
                  <button type="button" class="btn-small ai-all" data-ai-field="all" title="Propose un nom, une description, une catégorie, des matières et un prix d'après les photos de la pièce">✨ Tout (re)générer par l'IA</button>
                  <span class="ai-status" data-ai-status role="status"></span>
                </div>
                <?php endif; ?>
                <div class="field"><label>Nom <?= $aiBtn('name', 'Générer / régénérer le nom') ?></label><input type="text" name="name" value="<?= h($p['name']) ?>"></div>
                <div class="field-row-3">
                  <div class="field"><label>Univers <?= $aiBtn('category', 'Choisir la catégorie d\'après la pièce') ?></label>
                    <select name="cat">
                      <?php foreach (category_list() as $c): ?>
                        <option value="<?= h($c['key']) ?>"<?= $c['key'] === $p['cat'] ? ' selected' : '' ?>><?= h($c['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="field"><label>Mention</label><input type="text" name="badge" value="<?= h($p['badge']) ?>" list="badge-options"></div>
                  <div class="field"><label>Prix affiché (€) <?= $aiBtn('price', 'Estimer un prix') ?> <button type="button" class="ai-btn" data-price-research title="Chercher sur le web le prix du même article, neuf et d'occasion — coût estimé <?= h(ai_estimate_label(['search' => 1])) ?>">Marché</button></label>
                    <div class="price-line"><input type="number" name="price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['price'])) ?>" placeholder="ex : 25">
                      <button type="button" class="btn-small promo-btn" data-promo-toggle aria-pressed="false">🏷 Mettre en promo</button></div>
                    <?php if (price_input_value($p['price']) === '' && trim((string) $p['price']) !== ''): ?><p class="hint" style="margin:4px 0 0;">Prix actuel non numérique : « <?= h($p['price']) ?> ». Saisissez un montant pour le remplacer (laissé vide, il est conservé).</p><?php endif; ?>
                  </div>
                </div>
                <div class="field-row-3">
                  <div class="field">
                    <label>Prix d'achat (€) — pour la marge</label>
                    <input type="text" name="cost_price" inputmode="decimal" value="<?= isset($p['cost_cents']) && $p['cost_cents'] !== null ? h(number_format($p['cost_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="ex : 8,50 — ce que la pièce vous a coûté">
                  </div>
                  <div></div><div></div>
                </div>
                <div class="field-row-3" data-promo-row<?= $p['badge'] === 'Promo' ? '' : ' hidden' ?>>
                  <div class="field"><label>Prix promo (€)</label><input type="number" name="promo_price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['promo_price'])) ?>" placeholder="ex : 20"></div>
                  <div class="field"><label>Remise rapide</label><div class="promo-pcts"><?php foreach ([10, 20, 30, 50] as $pct): ?><button type="button" class="btn-small" data-promo-pct="<?= $pct ?>">−<?= $pct ?> %</button><?php endforeach; ?></div></div>
                  <div class="field"><label>&nbsp;</label><p class="hint" data-promo-info style="margin:0;"></p>
                    <div class="promo-visual-box">
                      <?php if (!$pvLive): ?>
                        <p class="hint" style="margin:6px 0 0;">Enregistrez la promo (mention « Promo » et prix promo) pour créer son visuel.</p>
                      <?php else: ?>
                        <button type="button" class="btn-small" data-promo-visual data-csrf="<?= h(admin_csrf_token()) ?>" data-ref="<?= h($ref) ?>" data-name="<?= h($p['name']) ?>" data-price="<?= h($p['price']) ?>" data-promo="<?= h($p['promo_price']) ?>" data-headline="<?= h($pvRow['headline'] ?? '') ?>" data-cta="<?= h($pvRow['cta'] ?? '') ?>" data-photos="<?= h(json_encode($pvPhotos)) ?>">🎨 <?= $pvRow ? 'Refaire le visuel promo' : 'Générer le visuel promo' ?></button>
                        <?php if ($pvRow): ?>
                          <a href="/<?= h($pvRow['path']) ?>" target="_blank" rel="noopener"><img src="/<?= h($pvRow['path']) ?>" alt="Visuel promo" style="display:block;max-width:100%;margin-top:6px;border:1px solid var(--line);"></a>
                          <p class="hint" style="margin:4px 0 0;"><?= $pvFresh ? 'À jour : affiché sur l\'accueil et dans les galeries.' : 'Périmé (le prix a changé) : refaites-le, il n\'est plus affiché.' ?>
                            <?php $c = $promoCounts[$ref] ?? null; if ($c): ?> · 30 j : <?= (int) $c['impressions'] ?> affichage<?= $c['impressions'] > 1 ? 's' : '' ?>, <?= (int) $c['clicks'] ?> clic<?= $c['clicks'] > 1 ? 's' : '' ?>.<?php endif; ?>
                            <a href="#" data-promo-visual-remove data-csrf="<?= h(admin_csrf_token()) ?>" data-ref="<?= h($ref) ?>">Retirer</a></p>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="field"><label>Description <?= $aiBtn('description', 'Générer / régénérer la description') ?></label><textarea name="description" rows="7"><?= h($p['description']) ?></textarea></div>
              </section>

              <section class="pm-panel product-admin-fields" data-pm-panel="details" role="tabpanel" hidden>
                <div class="field-row-3">
                  <div class="field"><label>Matières <?= $aiBtn('materials', 'Reconnaître les matières visibles') ?></label><input type="text" name="materials" value="<?= h($p['materials'] ?? '') ?>" placeholder="ex : grès émaillé, bois de chêne"></div>
                  <div class="field"><label>État <?= $aiBtn('etat', 'Estimer l\'état d\'après les photos') ?></label><select name="etat"><?= product_condition_select_html($p['etat'] ?? null) ?></select></div>
                  <div class="field"><label>Couleurs <?= $aiBtn('colors', 'Lire les couleurs dominantes sur la photo') ?></label><input type="text" name="colors" maxlength="120" value="<?= h($p['colors'] ?? '') ?>" placeholder="ex : bleu marine, blanc"></div>
                </div>
                <div class="field-row-3">
                  <div class="field"><label>Marque <?= $aiBtn('brand', 'Lire la marque sur l\'objet ou son étiquette') ?></label><input type="text" name="brand" maxlength="80" value="<?= h($p['brand'] ?? '') ?>" placeholder="ex : Levi's, Olivetti, Le Creuset"></div>
                  <div class="field"><label>Distributeur</label><input type="text" name="distributor" maxlength="80" value="<?= h($p['distributor'] ?? '') ?>" placeholder="ex : éditeur, revendeur, importateur"></div>
                  <div class="field"><label>Code-barres / ISBN</label><input type="text" name="barcode" inputmode="numeric" maxlength="20" value="<?= h($p['barcode'] ?? '') ?>" placeholder="EAN, ISBN" data-barcode-field></div>
                </div>
                <div class="field-row-3">
                  <div class="field"><label>Nature <?= $aiBtn('nature', 'Détecter la nature (vêtement, déco…) et la sous-catégorie') ?></label><select name="nature"><?= product_nature_select_html($p['nature'] ?? null) ?></select></div>
                  <div class="field"><label>Sous-catégorie</label><select name="sous_categorie"><?= product_subcategory_select_html($p['sous_categorie'] ?? null) ?></select></div>
                  <div></div>
                </div>
                <div class="field-row-3">
                  <div class="field"><label>Taille <?= $aiBtn('size', 'Lire la taille sur l\'étiquette ou estimer les dimensions') ?></label><input type="text" name="size_text" value="<?= h($p['size_text']) ?>" placeholder="ex : 20 × 15 × 30 cm"></div>
                  <div class="field">
                    <label>Poids <?= $aiBtn('weight', 'Estimer le poids (à vérifier avec une balance)') ?></label>
                    <input type="text" name="weight_text" value="<?= h($p['weight_text']) ?>" placeholder="ex : 1,2 kg">
                    <input type="number" name="weight_grams" min="0" step="1" value="<?= (int) $p['weight_grams'] ?>" placeholder="poids en g (frais de port)" style="margin-top:6px;">
                  </div>
                  <div></div>
                </div>
              </section>

              <section class="pm-panel product-admin-fields" data-pm-panel="prix" role="tabpanel" hidden>
                <input type="hidden" name="price_fields" value="1">
                <p class="hint" style="margin:0;">Gestion du prix de vente<?= $offerCount ? ' — <a href="/admin/offers.php">' . $offerCount . ' offre' . ($offerCount > 1 ? 's' : '') . ' à traiter</a>' : '' ?>. Le prix affiché et la promo se règlent dans « Essentiel ».</p>
                <div class="field-row-3">
                  <div class="field"><label>Prix de vente minimum (€)</label><input type="number" name="price_floor" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['price_floor'] ?? '')) ?>" placeholder="ex : 18"><p class="hint" style="margin:4px 0 0;">Interne : jamais affiché. Sert à juger les offres et la marge.</p></div>
                  <div class="field"><label>Négociation</label><label class="featured-check"><input type="checkbox" name="negotiable" value="1"<?= !empty($p['negotiable']) ? ' checked' : '' ?>> Prix négociable</label><p class="hint" style="margin:4px 0 0;">La fiche indique « Prix négociable » et propose « Faire une offre » au client.</p></div>
                  <div class="field"><label>Fourchette estimée (€)</label>
                    <div class="price-line"><input type="number" name="est_min" min="0" step="0.01" inputmode="decimal" value="<?= h($estMin) ?>" placeholder="de" style="max-width:90px"><span>à</span><input type="number" name="est_max" min="0" step="0.01" inputmode="decimal" value="<?= h($estMax) ?>" placeholder="à" style="max-width:90px"></div>
                    <label class="featured-check" style="margin-top:6px;"><input type="checkbox" name="est_show" value="1"<?= !empty($p['est_show']) ? ' checked' : '' ?>> Afficher sur la fiche</label></div>
                </div>
                <div class="field-row-3">
                  <div class="field"><label>Prix du neuf (€)</label><input type="number" name="new_price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['new_price'] ?? '')) ?>" placeholder="si trouvé">
                    <a class="hint" href="/admin/condition-pricing.php">Configurer le barème des états</a></div>
                  <div class="field pm-span2"><label>Lien vers le prix du neuf</label><input type="url" name="new_url" maxlength="500" value="<?= h($p['new_url'] ?? '') ?>" placeholder="https://… (rempli par « Marché » quand il est trouvé)">
                    <label class="featured-check" style="margin-top:6px;"><input type="checkbox" name="new_show" value="1"<?= !empty($p['new_show']) ? ' checked' : '' ?>> Afficher le prix et le lien du neuf sur la fiche</label></div>
                </div>
              </section>

              <section class="pm-panel product-admin-fields" data-pm-panel="vente" role="tabpanel" hidden>
                <div class="field-row-3">
                  <label class="featured-check"><input type="checkbox" name="featured" value="1"<?= $p['featured'] ? ' checked' : '' ?>> Mise en avant (accueil)</label>
                  <label class="featured-check"><input type="checkbox" name="is_hidden" value="1"<?= $p['is_hidden'] ? ' checked' : '' ?>> Masquer (hors boutique)</label>
                  <div></div>
                </div>
                <div class="field-row-3">
                  <div class="field">
                    <label>Stock<?= (int) $p['stock'] <= 0 ? ' — Vendue' : '' ?></label>
                    <input type="number" name="stock" min="0" step="1" value="<?= (int) $p['stock'] ?>">
                  </div>
                  <div class="field pm-span2">
                    <label>Vente en lot — pièces dans le lot</label>
                    <input type="number" name="lot_qty" min="1" max="999" step="1" value="<?= product_lot_qty($p) ?>">
                    <p class="hint" style="margin:4px 0 0;">1 = pièce seule. Au-delà, la fiche indique « Lot de N pièces » : le prix saisi est celui du lot entier, et le stock compte des lots.</p>
                  </div>
                </div>
                <div class="field-row-3">
                  <div class="field pm-span2">
                    <label>Rangement de l'article</label>
                    <input type="hidden" name="pickup_form" value="1">
                    <input type="text" name="storage_location" maxlength="80" value="<?= h($p['storage_location'] ?? '') ?>" placeholder="ex : carton 12 · étagère B">
                    <p class="hint" style="margin:4px 0 0;">Interne : jamais affiché au client. Rappelé sur les commandes pour retrouver la pièce à la préparation.</p>
                  </div>
                  <div class="field">
                    <label>Délai de récupération estimé</label>
                    <input type="number" name="pickup_delay_minutes" min="0" max="10080" step="5" value="<?= (int) ($p['pickup_delay_minutes'] ?? 0) ?>">
                    <p class="hint" style="margin:4px 0 0;">En minutes (0 = non précisé). Ex. : 15. La fiche affiche « Prêt au retrait sous 15 min ».</p>
                  </div>
                </div>
                <div class="field">
                  <label>Canaux de vente</label>
                  <input type="hidden" name="channels_form" value="1">
                  <div class="channel-checks">
                    <?php foreach (product_channels() as $chKey => $chLabel): ?>
                      <label class="featured-check"><input type="checkbox" name="channels[]" value="<?= h($chKey) ?>"<?= in_array($chKey, $pChannels, true) ? ' checked' : '' ?>> <?= h($chLabel) ?></label>
                    <?php endforeach; ?>
                  </div>
                  <p class="hint" style="margin:4px 0 0;">Où la pièce est proposée (enregistré avec « Enregistrer »).<?php if ((int) $p['stock'] <= 0 && !empty($p['sold_at'])): ?> <strong>Vendue le <?= h(date('d/m/Y', strtotime($p['sold_at']))) ?><?= !empty($p['sold_channel']) ? ' via ' . h(product_channels()[$p['sold_channel']] ?? $p['sold_channel']) : '' ?>.</strong><?php endif; ?><?php if (!empty($p['restocked_at'])): ?> Dernier réassort : <?= h(date('d/m/Y', strtotime($p['restocked_at']))) ?>.<?php endif; ?></p>
                </div>
                <div class="field-row-3">
                  <div class="field">
                    <label>Réassort possible (fiche)</label>
                    <input type="hidden" name="restock_form" value="1">
                    <label class="featured-check"><input type="checkbox" name="restock_possible" value="1"<?= !empty($p['restock_possible']) ? ' checked' : '' ?>> Réassort possible</label>
                    <p class="hint" style="margin:4px 0 0;">La fiche indique que la pièce peut être retrouvée, avec le délai et le prix ci-contre.</p>
                  </div>
                  <div class="field">
                    <label>Délai de réassort</label>
                    <input type="text" name="restock_delay" maxlength="60" value="<?= h($p['restock_delay'] ?? '') ?>" placeholder="ex : 10 jours">
                  </div>
                  <div class="field">
                    <label>Prix du réassort (€)</label>
                    <input type="number" name="restock_price" min="0" step="0.01" inputmode="decimal" value="<?= h(price_input_value($p['restock_price'] ?? '')) ?>" placeholder="ex : 25">
                  </div>
                </div>
                <div class="field-row-3 sale-actions">
                  <div class="field">
                    <label>Vente</label>
                    <?php if ((int) $p['stock'] > 0): ?>
                      <select name="sold_channel" aria-label="Vendue via">
                        <option value="">Vendue via… (facultatif)</option>
                        <?php foreach (product_channels() as $chKey => $chLabel): ?><option value="<?= h($chKey) ?>"><?= h($chLabel) ?></option><?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn-small btn-sold" style="margin-top:6px;" formaction="/admin/product-sold.php" formnovalidate name="sold_action" value="sold"
                              onclick="return confirm('Marquer « <?= h(addslashes($p['name'])) ?> » comme vendue ?\nSon stock passe à 0<?= product_keeps_when_sold($p) ? ' ; elle reste visible au catalogue (maintenue)' : ' et elle quitte la boutique' ?>.');">✓ Marquer vendu</button>
                    <?php else: ?>
                      <p class="hint" style="margin:0;">Pièce vendue (stock à 0).</p>
                    <?php endif; ?>
                  </div>
                  <div class="field">
                    <label>Réassort</label>
                    <input type="number" name="restock_qty" min="1" max="999" step="1" value="1" aria-label="Quantité à ajouter au stock">
                    <button type="submit" class="btn-small btn-sold" style="margin-top:6px;" formaction="/admin/product-sold.php" formnovalidate name="sold_action" value="reassort"
                            onclick="return confirm('Réassort de « <?= h(addslashes($p['name'])) ?> » : ajouter la quantité indiquée au stock<?= (int) $p['stock'] <= 0 ? ' (la pièce est remise en vente)' : '' ?> ?');">📦 Réassort</button>
                  </div>
                  <div class="field">
                    <label>Catalogue</label>
                    <button type="submit" class="btn-small btn-sold" formaction="/admin/product-sold.php" formnovalidate name="sold_action" value="keep"><?= product_keeps_when_sold($p) ? '📌 Maintenue au catalogue — retirer' : '📌 Maintenir au catalogue si vendue' ?></button>
                    <p class="hint" style="margin:6px 0 0;"><?= product_keeps_when_sold($p) ? 'Une fois vendue, la pièce reste visible avec le badge « Vendue ».' : 'Sinon la pièce vendue disparaît de la boutique.' ?></p>
                  </div>
                </div>
              </section>
            </div>
          </div>
          <footer class="pm-foot">
            <button type="submit" form="pmd-<?= h($ref) ?>" class="admin-delete pm-del" formnovalidate>Supprimer</button>
            <span class="pm-dirty" data-pm-dirty hidden>● Modifications non enregistrées</span>
            <span class="pm-foot-actions">
              <button type="submit" class="btn-small" name="close" value="1">Enregistrer et fermer</button>
              <button type="submit" class="btn-small btn-primary pm-save">Enregistrer</button>
            </span>
          </footer>
        </form>
        <form method="post" action="/admin/delete-product.php" id="pmd-<?= h($ref) ?>" onsubmit="return confirm('Supprimer « <?= h(addslashes($p['name'])) ?> » du catalogue ? (ses photos seront conservées dans la médiathèque)');">
          <input type="hidden" name="ref" value="<?= h($ref) ?>">
        </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="admin-block" style="margin-top:24px;">
      <p class="hint" style="margin:0 0 12px;">Sur smartphone : <a href="/admin/quick-add.php"><strong>Nouvelle pièce en photos</strong></a> — <?= $aiOn ? 'photos sous plusieurs angles, puis détourage, mise en situation et fiche rédigés automatiquement.' : 'photos sous plusieurs angles, puis fiche de la pièce.' ?> <a href="/admin/condition-pricing.php">Régler le barème des états</a>.</p>
      <details>
        <summary class="add-product-btn" style="cursor:pointer;">+ Ajouter une pièce</summary>
        <form method="post" action="/admin/add-product.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--line);" data-ai-form data-scan-form data-condition-rates="<?= h($conditionRatesJson) ?>">
          <div class="field-row-3">
            <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*" data-check-resolution></div>
            <?php if ($aiOn): ?>
            <div class="ai-bar" style="align-self:end;">
              <button type="button" class="btn-small ai-all" data-ai-field="all" title="Propose un nom, une description, une catégorie, des matières et un prix d'après la photo choisie">Tout générer par l'IA</button>
              <span class="ai-status" data-ai-status role="status"></span>
            </div>
            <?php endif; ?>
            <div></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Nom <?= $aiBtn('name', 'Générer / régénérer le nom') ?></label><input type="text" name="name" placeholder="Nom de la pièce"></div>
            <div class="field"><label>Univers <?= $aiBtn('category', 'Choisir la catégorie d\'après la pièce') ?></label>
              <select name="cat">
                <?php foreach (category_list() as $c): ?>
                  <option value="<?= h($c['key']) ?>"><?= h($c['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field"><label>Prix affiché (€) <?= $aiBtn('price', 'Estimer un prix') ?> <button type="button" class="ai-btn" data-price-research title="Chercher sur le web le prix du même article, neuf et d'occasion — coût estimé <?= h(ai_estimate_label(['search' => 1])) ?>">Marché</button></label><input type="number" name="price" min="0" step="0.01" inputmode="decimal" data-price placeholder="ex : 25"></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Prix d'achat (€) — pour la marge</label><input type="text" name="cost_price" inputmode="decimal" placeholder="ex : 8,50"></div>
            <div></div><div></div>
          </div>
          <div class="field"><label>Description <?= $aiBtn('description', 'Générer / régénérer la description') ?></label><textarea name="description"></textarea></div>
          <div class="field-row-3">
            <div class="field"><label>Matières <?= $aiBtn('materials', 'Reconnaître les matières visibles') ?></label><input type="text" name="materials" placeholder="ex : grès émaillé, bois de chêne"></div>
            <div class="field"><label>État <?= $aiBtn('etat', 'Estimer l\'état d\'après la photo') ?></label><select name="etat"><?= product_condition_select_html(null) ?></select></div>
            <div></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Prix du neuf (€)</label><input type="number" name="new_price" min="0" step="0.01" inputmode="decimal" data-price placeholder="si trouvé"><a class="hint" href="/admin/condition-pricing.php">Configurer le barème des états</a></div>
            <div></div><div></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Marque <?= $aiBtn('brand', 'Lire la marque sur l\'objet ou son étiquette') ?></label><input type="text" name="brand" maxlength="80" placeholder="ex : Levi's, Olivetti, Le Creuset"></div>
            <div class="field"><label>Distributeur</label><input type="text" name="distributor" maxlength="80" placeholder="ex : éditeur, revendeur, importateur"></div>
            <div></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Nature <?= $aiBtn('nature', 'Détecter la nature (vêtement, déco…) et la sous-catégorie') ?></label><select name="nature"><?= product_nature_select_html(null) ?></select></div>
            <div class="field"><label>Sous-catégorie</label><select name="sous_categorie"><?= product_subcategory_select_html(null) ?></select></div>
            <div class="field"><label>Code-barres / ISBN</label><input type="text" name="barcode" inputmode="numeric" maxlength="20" placeholder="EAN, ISBN, UPC"></div>
          </div>
          <div class="field-row-3">
            <div class="field"><label>Taille <?= $aiBtn('size', 'Lire la taille sur l\'étiquette ou estimer les dimensions') ?></label><input type="text" name="size_text" placeholder="ex : 20 × 15 × 30 cm, M, 38"></div>
            <div class="field"><label>Poids (g) <?= $aiBtn('weight', 'Estimer le poids (à vérifier avec une balance)') ?></label><input type="number" name="weight_grams" min="0" step="1" placeholder="poids en g (frais de port)"><input type="hidden" name="weight_text" value=""></div>
            <div class="field"><label>Mention</label><input type="text" name="badge" value="Chiné" list="badge-options"></div>
          </div>
          <button type="submit" class="btn btn-primary">Ajouter cette pièce</button>
        </form>
      </details>
    </div>
  </div>
</section>
</main>

<datalist id="badge-options">
  <?php foreach ($badgeOptions as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?>
</datalist>

<script>
(function () {
  var boxes = Array.prototype.slice.call(document.querySelectorAll('.bulk-select'));
  var selectAll = document.getElementById('bulk-select-all');
  if (!selectAll) return;
  var countEl = document.getElementById('bulk-count');
  var actionBtns = Array.prototype.slice.call(document.querySelectorAll('[data-bulk-action]'));
  var form = document.getElementById('bulk-action-form');

  function refresh() {
    var checked = boxes.filter(function (b) { return b.checked; });
    countEl.textContent = checked.length + ' sélectionnée(s)';
    actionBtns.forEach(function (btn) { btn.disabled = checked.length === 0; });
    var labelsBtn = document.getElementById('bulk-labels');
    if (labelsBtn) labelsBtn.disabled = checked.length === 0;
    selectAll.checked = checked.length > 0 && checked.length === boxes.length;
  }

  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  selectAll.addEventListener('change', function () {
    boxes.forEach(function (b) { b.checked = selectAll.checked; });
    refresh();
  });

  actionBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var checked = boxes.filter(function (b) { return b.checked; });
      if (!checked.length) return;
      var confirmMsg = btn.dataset.bulkConfirm;
      if (confirmMsg) {
        var noun = checked.length > 1 ? 'les ' + checked.length + ' pièces sélectionnées' : 'cette pièce';
        if (!confirm(confirmMsg.replace('{n}', noun))) return;
      }
      form.querySelectorAll('input[name="refs[]"]').forEach(function (el) { el.remove(); });
      checked.forEach(function (b) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'refs[]';
        input.value = b.dataset.ref;
        form.appendChild(input);
      });
      var actionInput = form.querySelector('input[name="action"]');
      if (!actionInput) {
        actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        form.appendChild(actionInput);
      }
      actionInput.value = btn.dataset.bulkAction;
      form.submit();
    });
  });

  var labelsBtn = document.getElementById('bulk-labels');
  if (labelsBtn) labelsBtn.addEventListener('click', function () {
    var refs = boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.dataset.ref; });
    if (refs.length) window.open('/admin/labels.php?refs=' + encodeURIComponent(refs.join(',')), '_blank');
  });

  refresh();
})();

</script>
<script src="/assets/admin-upload-check.js"></script>
<script src="/assets/product-ai.js"></script>
<script src="/assets/nature-select.js"></script>
<script src="/assets/nature-detect.js"></script>
<script src="/assets/promo-visual.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/promo-visual.js') ?>"></script>
<script src="/assets/price-field.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/price-field.js') ?>"></script>
<script src="/assets/condition-pricing.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/condition-pricing.js') ?>"></script>
<script src="/assets/price-research.js"></script>
<script src="/assets/barcode-scan.js"></script>
<script src="/assets/lightbox.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/lightbox.js') ?>" defer></script>
<script src="/assets/product-modal.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/product-modal.js') ?>"></script>
</body>
</html>
