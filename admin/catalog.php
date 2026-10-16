<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$flash = flash_get();
$q = trim((string) ($_GET['q'] ?? ''));

$sortKey = (string) ($_GET['sort'] ?? 'date');
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$columns = ['name' => 'name', 'price' => 'price', 'date' => 'created_at'];
$orderCol = $columns[$sortKey] ?? 'created_at';

$where = '';
$params = [];
if ($q !== '') {
    $where = 'WHERE name LIKE ? OR ref LIKE ?';
    $params = ["%$q%", "%$q%"];
}

$stmt = db()->prepare("SELECT * FROM products $where ORDER BY $orderCol $dir, ref");
$stmt->execute($params);
$products = $stmt->fetchAll();

$missingNature = db()->query("SELECT ref FROM products WHERE nature IS NULL OR nature = '' ORDER BY ref")->fetchAll(PDO::FETCH_COLUMN);
$currentUrl = '/admin/catalog.php?' . http_build_query(array_filter(['sort' => $sortKey, 'dir' => $dir, 'q' => $q]));
$badgeOptions = ['Chiné', 'Fait main', 'Pièce unique', 'Promo'];
// Compteurs des visuels promo (30 derniers jours) par article de cette boutique.
$promoCounts = [];
foreach (promo_stats(gmdate('Y-m-d', time() - 29 * 86400), gmdate('Y-m-d'), 'ref', ['shop' => tenant_slug()]) as $row) {
    $promoCounts[$row['ref']]['impressions'] = ($promoCounts[$row['ref']]['impressions'] ?? 0) + $row['impressions'];
    $promoCounts[$row['ref']]['clicks'] = ($promoCounts[$row['ref']]['clicks'] ?? 0) + $row['clicks'];
}

/** Petit bouton « ↻ IA » à côté d'une étiquette de champ (assets/product-ai.js). */
$aiBtn = static fn (string $field, string $title): string =>
    '<button type="button" class="ai-btn" data-ai-field="' . h($field) . '" title="' . h($title) . '">↻ IA</button>';

function catalog_sort_link(string $key, string $label, string $sortKey, string $dir, string $q): string
{
    $nextDir = ($sortKey === $key && $dir === 'asc') ? 'desc' : 'asc';
    $arrow = $sortKey === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    $url = '/admin/catalog.php?sort=' . urlencode($key) . '&dir=' . urlencode($nextDir) . ($q !== '' ? '&q=' . urlencode($q) : '');
    return '<a href="' . h($url) . '" style="color:inherit;text-decoration:none;">' . h($label . $arrow) . '</a>';
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Catalogue — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/barcode-scan.css">
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
    <p class="hint">Cliquez un nom pour l'éditer directement ici. Cochez « mise en avant » pour choisir les 3 pièces affichées en page d'accueil ; « masquer » retire une pièce de la boutique sans la supprimer.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:20px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <form method="get" action="/admin/catalog.php" class="catalog-filters">
      <input type="hidden" name="sort" value="<?= h($sortKey) ?>">
      <input type="hidden" name="dir" value="<?= h($dir) ?>">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Rechercher (nom, réf.)...">
      <button type="submit" class="btn-small">Rechercher</button>
      <?php if ($q !== ''): ?><a class="btn-small" href="/admin/catalog.php">Réinitialiser</a><?php endif; ?>
    </form>

    <?php if (!$products): ?>
      <p class="empty-state">Aucune pièce ne correspond.</p>
    <?php else: ?>
      <div class="bulk-toolbar">
        <label class="featured-check"><input type="checkbox" id="bulk-select-all"> Tout sélectionner</label>
        <span id="bulk-count" class="hint" style="margin:0;">0 sélectionnée(s)</span>
        <button type="button" class="btn-small" id="nature-detect" disabled title="L'IA détecte la nature (vêtement, déco…) et la sous-catégorie des pièces cochées d'après leurs photos">Détecter la nature (IA)</button>
        <?php if ($missingNature): ?><button type="button" class="btn-small" id="nature-detect-missing" data-refs="<?= h(json_encode($missingNature)) ?>" title="Détecte la nature de toutes les pièces qui n'en ont pas encore">Détecter pour les <?= count($missingNature) ?> pièces sans nature</button><?php endif; ?>
        <span class="hint" id="nature-detect-status" role="status" style="margin:0;"></span>
        <button type="button" class="btn-small" data-bulk-action="hide" disabled>Masquer</button>
        <button type="button" class="btn-small" data-bulk-action="unhide" disabled>Réafficher</button>
        <button type="button" class="btn-small" data-bulk-action="promo" disabled>Mettre en promo</button>
        <button type="button" class="btn-small" data-bulk-action="unpromo" disabled>Retirer la promo</button>
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
              <th><?= catalog_sort_link('name', 'Nom', $sortKey, $dir, $q) ?></th>
              <th><?= catalog_sort_link('price', 'Prix', $sortKey, $dir, $q) ?></th>
              <th><?= catalog_sort_link('date', 'Mise en ligne', $sortKey, $dir, $q) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr class="<?= $p['is_hidden'] ? 'is-hidden-product' : '' ?>">
                <td><input type="checkbox" class="bulk-select" data-ref="<?= h($p['ref']) ?>"></td>
                <td>
                  <div class="catalog-thumb">
                    <?php if (!empty($p['photo'])): ?>
                      <img src="/<?= h($p['photo']) ?>" alt="">
                    <?php endif; ?>
                  </div>
                </td>
                <td><a class="catalog-name" href="#produit-<?= h($p['ref']) ?>" data-toggle-ref="<?= h($p['ref']) ?>">Réf. N°<?= h($p['ref']) ?> — <?= h($p['name']) ?></a></td>
                <td><?= h($p['price']) ?></td>
                <td><?= h(date('d/m/Y', strtotime($p['created_at']))) ?></td>
              </tr>
              <tr class="catalog-edit-row" id="produit-<?= h($p['ref']) ?>" hidden>
                <td colspan="5">
                  <div class="product-admin-row<?= $p['is_hidden'] ? ' is-hidden-product' : '' ?>">
                    <form method="post" action="/admin/save-product.php" enctype="multipart/form-data" style="display:contents;" data-ai-form>
                      <input type="hidden" name="ref" value="<?= h($p['ref']) ?>">
                      <div>
                        <div class="admin-photo-preview"><?= product_media_html($p) ?></div>
                      </div>
                      <div class="product-admin-fields">
                        <div class="ai-bar">
                          <button type="button" class="btn-small ai-all" data-ai-field="all" title="Propose un nom, une description, une catégorie, des matières et un prix d'après les photos de la pièce">Tout (re)générer par l'IA</button>
                          <span class="ai-status" data-ai-status role="status"></span>
                        </div>
                        <div class="field-row-3">
                          <div class="field"><label>Nom <?= $aiBtn('name', 'Générer / régénérer le nom') ?></label><input type="text" name="name" value="<?= h($p['name']) ?>"></div>
                          <div class="field"><label>Univers <?= $aiBtn('category', 'Choisir la catégorie d\'après la pièce') ?></label>
                            <select name="cat">
                              <?php foreach (category_list() as $c): ?>
                                <option value="<?= h($c['key']) ?>"<?= $c['key'] === $p['cat'] ? ' selected' : '' ?>><?= h($c['label']) ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="field"><label>Prix affiché (€) <?= $aiBtn('price', 'Estimer un prix') ?> <button type="button" class="ai-btn" data-price-research title="Chercher sur le web le prix du même article, neuf et d'occasion — coût estimé <?= h(ai_estimate_label(['search' => 1])) ?>">Marché</button></label>
                            <div class="price-line"><input type="number" name="price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['price'])) ?>" placeholder="ex : 25">
                              <button type="button" class="btn-small promo-btn" data-promo-toggle aria-pressed="false">🏷 Mettre en promo</button></div>
                            <?php if (price_input_value($p['price']) === '' && trim((string) $p['price']) !== ''): ?><p class="hint" style="margin:4px 0 0;">Prix actuel non numérique : « <?= h($p['price']) ?> ». Saisissez un montant pour le remplacer (laissé vide, il est conservé).</p><?php endif; ?>
                          </div>
                        </div>
                        <div class="field-row-3" data-promo-row<?= $p['badge'] === 'Promo' ? '' : ' hidden' ?>>
                          <div class="field"><label>Prix promo (€)</label><input type="number" name="promo_price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['promo_price'])) ?>" placeholder="ex : 20"></div>
                          <div class="field"><label>Remise rapide</label><div class="promo-pcts"><?php foreach ([10, 20, 30, 50] as $pct): ?><button type="button" class="btn-small" data-promo-pct="<?= $pct ?>">−<?= $pct ?> %</button><?php endforeach; ?></div></div>
                          <div class="field"><label>&nbsp;</label><p class="hint" data-promo-info style="margin:0;"></p>
                            <?php
                              $pvRow = promo_visual_get($p['ref']);
                              $pvLive = has_promo_price($p);
                              $pvFresh = $pvLive && promo_visual_fresh($p, $pvRow);
                              $pvPhotos = array_values(array_map(static fn ($ph) => ['path' => $ph['path'], 'label' => $ph['label']], array_filter(product_photos_list($p['ref']), static fn ($ph) => ($ph['type'] ?? 'photo') === 'photo')));
                              if (!empty($p['photo'])) array_unshift($pvPhotos, ['path' => $p['photo'], 'label' => 'Couverture']);
                            ?>
                            <div class="promo-visual-box">
                              <?php if (!$pvLive): ?>
                                <p class="hint" style="margin:6px 0 0;">Enregistrez la promo (mention « Promo » et prix promo) pour créer son visuel.</p>
                              <?php else: ?>
                                <button type="button" class="btn-small" data-promo-visual data-csrf="<?= h(admin_csrf_token()) ?>" data-ref="<?= h($p['ref']) ?>" data-name="<?= h($p['name']) ?>" data-price="<?= h($p['price']) ?>" data-promo="<?= h($p['promo_price']) ?>" data-headline="<?= h($pvRow['headline'] ?? '') ?>" data-cta="<?= h($pvRow['cta'] ?? '') ?>" data-photos="<?= h(json_encode($pvPhotos)) ?>">🎨 <?= $pvRow ? 'Refaire le visuel promo' : 'Générer le visuel promo' ?></button>
                                <?php if ($pvRow): ?>
                                  <a href="/<?= h($pvRow['path']) ?>" target="_blank" rel="noopener"><img src="/<?= h($pvRow['path']) ?>" alt="Visuel promo" style="display:block;max-width:100%;margin-top:6px;border:1px solid var(--line);"></a>
                                  <p class="hint" style="margin:4px 0 0;"><?= $pvFresh ? 'À jour : affiché sur l\'accueil et dans les galeries.' : 'Périmé (le prix a changé) : refaites-le, il n\'est plus affiché.' ?>
                                    <?php $c = $promoCounts[$p['ref']] ?? null; if ($c): ?> · 30 j : <?= (int) $c['impressions'] ?> affichage<?= $c['impressions'] > 1 ? 's' : '' ?>, <?= (int) $c['clicks'] ?> clic<?= $c['clicks'] > 1 ? 's' : '' ?>.<?php endif; ?>
                                    <a href="#" data-promo-visual-remove data-csrf="<?= h(admin_csrf_token()) ?>" data-ref="<?= h($p['ref']) ?>">Retirer</a></p>
                                <?php endif; ?>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                        <?php
                          $offerCount = (int) db()->query("SELECT COUNT(*) FROM price_offers WHERE ref = " . db()->quote($p['ref']) . " AND status = 'new'")->fetchColumn();
                          $estMin = $p['est_min'] !== null ? rtrim(rtrim(number_format((float) $p['est_min'], 2, '.', ''), '0'), '.') : '';
                          $estMax = $p['est_max'] !== null ? rtrim(rtrim(number_format((float) $p['est_max'], 2, '.', ''), '0'), '.') : '';
                        ?>
                        <details class="price-mgmt" open>
                          <summary>Gestion du prix de vente<?= $offerCount ? ' — ' . $offerCount . ' offre' . ($offerCount > 1 ? 's' : '') . ' à traiter' : '' ?></summary>
                          <input type="hidden" name="price_fields" value="1">
                          <div class="field-row-3">
                            <div class="field"><label>Prix de vente minimum (€)</label><input type="number" name="price_floor" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['price_floor'] ?? '')) ?>" placeholder="ex : 18"><p class="hint" style="margin:4px 0 0;">Interne : jamais affiché. Sert à juger les offres et la marge.</p></div>
                            <div class="field"><label>Négociation</label><label class="featured-check"><input type="checkbox" name="negotiable" value="1"<?= !empty($p['negotiable']) ? ' checked' : '' ?>> Prix négociable</label><p class="hint" style="margin:4px 0 0;">La fiche indique « Prix négociable » et propose « Faire une offre » au client.<?php if ($offerCount): ?> <a href="/admin/offers.php">Voir les <?= $offerCount ?> offre<?= $offerCount > 1 ? 's' : '' ?></a>.<?php endif; ?></p></div>
                            <div class="field"><label>Fourchette estimée (€)</label>
                              <div class="price-line"><input type="number" name="est_min" min="0" step="0.01" inputmode="decimal" value="<?= h($estMin) ?>" placeholder="de" style="max-width:90px"><span>à</span><input type="number" name="est_max" min="0" step="0.01" inputmode="decimal" value="<?= h($estMax) ?>" placeholder="à" style="max-width:90px"></div>
                              <label class="featured-check" style="margin-top:6px;"><input type="checkbox" name="est_show" value="1"<?= !empty($p['est_show']) ? ' checked' : '' ?>> Afficher sur la fiche</label></div>
                          </div>
                          <div class="field-row-3">
                            <div class="field"><label>Prix du neuf (€)</label><input type="number" name="new_price" min="0" step="0.01" inputmode="decimal" data-price value="<?= h(price_input_value($p['new_price'] ?? '')) ?>" placeholder="si trouvé"></div>
                            <div class="field" style="grid-column: span 2;"><label>Lien vers le prix du neuf</label><input type="url" name="new_url" maxlength="500" value="<?= h($p['new_url'] ?? '') ?>" placeholder="https://… (rempli par « Marché » quand il est trouvé)">
                              <label class="featured-check" style="margin-top:6px;"><input type="checkbox" name="new_show" value="1"<?= !empty($p['new_show']) ? ' checked' : '' ?>> Afficher le prix et le lien du neuf sur la fiche</label></div>
                          </div>
                        </details>
                        <div class="field"><label>Description <?= $aiBtn('description', 'Générer / régénérer la description') ?></label><textarea name="description"><?= h($p['description']) ?></textarea></div>
                        <div class="field-row-3">
                          <div class="field"><label>Matières <?= $aiBtn('materials', 'Reconnaître les matières visibles') ?></label><input type="text" name="materials" value="<?= h($p['materials'] ?? '') ?>" placeholder="ex : grès émaillé, bois de chêne"></div>
                          <div class="field"><label>État <?= $aiBtn('etat', 'Estimer l\'état d\'après les photos') ?></label><select name="etat"><?= product_condition_select_html($p['etat'] ?? null) ?></select></div>
                          <div></div>
                        </div>
                        <div class="field-row-3">
                          <div class="field"><label>Couleurs <?= $aiBtn('colors', 'Lire les couleurs dominantes sur la photo') ?></label><input type="text" name="colors" maxlength="120" value="<?= h($p['colors'] ?? '') ?>" placeholder="ex : bleu marine, blanc"></div>
                          <div class="field"><label>Couleurs <?= $aiBtn('colors', 'Lire les couleurs dominantes sur la photo') ?></label><input type="text" name="colors" maxlength="120" placeholder="ex : bleu marine, blanc"></div>
            <div class="field"><label>Marque <?= $aiBtn('brand', 'Lire la marque sur l\'objet ou son étiquette') ?></label><input type="text" name="brand" maxlength="80" value="<?= h($p['brand'] ?? '') ?>" placeholder="ex : Levi's, Olivetti, Le Creuset"></div>
                          <div class="field"><label>Distributeur</label><input type="text" name="distributor" maxlength="80" value="<?= h($p['distributor'] ?? '') ?>" placeholder="ex : éditeur, revendeur, importateur"></div>
                          <div></div>
                        </div>
                        <div class="field-row-3">
                          <div class="field"><label>Nature <?= $aiBtn('nature', 'Détecter la nature (vêtement, déco…) et la sous-catégorie') ?></label><select name="nature"><?= product_nature_select_html($p['nature'] ?? null) ?></select></div>
                          <div class="field"><label>Sous-catégorie</label><select name="sous_categorie"><?= product_subcategory_select_html($p['sous_categorie'] ?? null) ?></select></div>
                          <div class="field"><label>Code-barres / ISBN</label><input type="text" name="barcode" inputmode="numeric" maxlength="20" value="<?= h($p['barcode'] ?? '') ?>" placeholder="EAN, ISBN" data-barcode-field></div>
                        </div>
                        <div class="field-row-3">
                          <div class="field"><label>Taille <?= $aiBtn('size', 'Lire la taille sur l\'étiquette ou estimer les dimensions') ?></label><input type="text" name="size_text" value="<?= h($p['size_text']) ?>" placeholder="ex : 20 × 15 × 30 cm"></div>
                          <div class="field">
                            <label>Poids <?= $aiBtn('weight', 'Estimer le poids (à vérifier avec une balance)') ?></label>
                            <input type="text" name="weight_text" value="<?= h($p['weight_text']) ?>" placeholder="ex : 1,2 kg">
                            <input type="number" name="weight_grams" min="0" step="1" value="<?= (int) $p['weight_grams'] ?>" placeholder="poids en g (frais de port)" style="margin-top:6px;">
                          </div>
                          <div class="field"><label>Mention</label><input type="text" name="badge" value="<?= h($p['badge']) ?>" list="badge-options"></div>
                        </div>
                        <div class="field-row-3">
                          <label class="featured-check"><input type="checkbox" name="featured" value="1"<?= $p['featured'] ? ' checked' : '' ?>> Mise en avant (accueil)</label>
                          <label class="featured-check"><input type="checkbox" name="is_hidden" value="1"<?= $p['is_hidden'] ? ' checked' : '' ?>> Masquer (hors boutique)</label>
                          <div class="field">
                            <label>Stock<?= (int) $p['stock'] <= 0 ? ' — Vendue' : '' ?></label>
                            <input type="number" name="stock" min="0" step="1" value="<?= (int) $p['stock'] ?>">
                          </div>
                        </div>
                        <div class="field-row-3">
                          <a class="btn-small" style="text-align:center;text-decoration:none;" href="/admin/gallery.php?ref=<?= h($p['ref']) ?>">🖼 Gérer les photos →</a>
                          <a class="btn-small" style="text-align:center;text-decoration:none;" href="/produit.php?ref=<?= h($p['ref']) ?>" target="_blank">Voir la fiche</a>
                        </div>
                        <?php $thumbs = product_photos_list($p['ref']); ?>
                        <?php if ($thumbs): ?>
                          <div style="display:flex;gap:8px;margin-top:4px;flex-wrap:wrap;">
                            <?php foreach ($thumbs as $t): ?>
                              <div style="text-align:center;">
                                <div class="admin-photo-preview" style="width:50px;height:50px;"><img src="/<?= h($t['path']) ?>" alt="<?= h($t['label']) ?>" style="width:100%;height:100%;object-fit:cover;"></div>
                                <span style="font-size:0.62rem;color:var(--ink-soft);"><?= h($t['label']) ?></span>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </div>
                      <div style="display:flex;flex-direction:column;gap:8px;">
                        <button type="submit" class="btn-small">Enregistrer</button>
                      </div>
                    </form>
                    <form method="post" action="/admin/delete-product.php" onsubmit="return confirm('Supprimer « <?= h(addslashes($p['name'])) ?> » du catalogue ? (ses photos seront conservées dans la médiathèque)');" style="grid-column:3;margin-top:-8px;">
                      <input type="hidden" name="ref" value="<?= h($p['ref']) ?>">
                      <button type="submit" class="admin-delete">Supprimer</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="admin-block" style="margin-top:24px;">
      <p class="hint" style="margin:0 0 12px;">Sur smartphone : <a href="/admin/quick-add.php"><strong>Nouvelle pièce en photos</strong></a> — photos sous plusieurs angles, puis détourage, mise en situation et fiche rédigés automatiquement.</p>
      <details>
        <summary class="add-product-btn" style="cursor:pointer;">+ Ajouter une pièce</summary>
        <form method="post" action="/admin/add-product.php" enctype="multipart/form-data" style="margin-top:18px;padding-top:18px;border-top:1px solid var(--line);" data-ai-form data-scan-form>
          <div class="field-row-3">
            <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*" data-check-resolution></div>
            <div class="ai-bar" style="align-self:end;">
              <button type="button" class="btn-small ai-all" data-ai-field="all" title="Propose un nom, une description, une catégorie, des matières et un prix d'après la photo choisie">Tout générer par l'IA</button>
              <span class="ai-status" data-ai-status role="status"></span>
            </div>
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
          <div class="field"><label>Description <?= $aiBtn('description', 'Générer / régénérer la description') ?></label><textarea name="description"></textarea></div>
          <div class="field-row-3">
            <div class="field"><label>Matières <?= $aiBtn('materials', 'Reconnaître les matières visibles') ?></label><input type="text" name="materials" placeholder="ex : grès émaillé, bois de chêne"></div>
            <div class="field"><label>État <?= $aiBtn('etat', 'Estimer l\'état d\'après la photo') ?></label><select name="etat"><?= product_condition_select_html(null) ?></select></div>
            <div></div>
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

  refresh();
})();

(function () {
  // Chaque pièce est repliée par défaut (127 formulaires dépliés en même
  // temps est illisible) — cliquer le nom déplie son édition complète ; un
  // lien vers #produit-<ref> (ex : après enregistrement) la déplie et la
  // fait défiler jusqu'à elle automatiquement.
  function expand(ref) {
    var row = document.getElementById('produit-' + ref);
    if (!row) return;
    row.hidden = false;
    row.scrollIntoView({ block: 'center' });
  }

  document.querySelectorAll('[data-toggle-ref]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      var row = document.getElementById('produit-' + link.dataset.toggleRef);
      if (!row) return;
      row.hidden = !row.hidden;
      if (!row.hidden) row.scrollIntoView({ block: 'center' });
    });
  });

  if (location.hash.indexOf('#produit-') === 0) {
    expand(location.hash.slice('#produit-'.length));
  }
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
<script src="/assets/product-ai.js"></script>
<script src="/assets/nature-select.js"></script>
<script src="/assets/nature-detect.js"></script>
<script src="/assets/promo-visual.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/promo-visual.js') ?>"></script>
<script src="/assets/price-field.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/price-field.js') ?>"></script>
<script src="/assets/price-research.js"></script>
<script src="/assets/barcode-scan.js"></script>
</body>
</html>
