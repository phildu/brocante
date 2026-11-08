<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$members = $ctx['manager'] ? fv_members_list(false) : [];
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'city' => trim((string) ($_GET['ville'] ?? '')),
    'zone' => trim((string) ($_GET['zone'] ?? '')),
    'owner_id' => (int) ($_GET['owner'] ?? 0),
    'due' => (string) ($_GET['due'] ?? ''),
    'active_only' => !isset($_GET['tous']),
    'has_address' => true,
];

$idsParam = $_GET['ids'] ?? [];
if (is_string($idsParam)) $idsParam = explode(',', $idsParam);
$selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) $idsParam))));
$selectedIds = array_slice($selectedIds, 0, 60);

$allBase = isset($_GET['tout']);   // « toute la base » : tous les commerces avec adresse correspondant aux filtres, sans cocher
$matches = fv_prospects_search($filters, 2000);
$selected = [];
if ($allBase) {
    $selected = $matches;
    $selectedIds = [];
} else {
    foreach ($selectedIds as $id) {
        if ($prospect = fv_prospect_get($id)) $selected[] = $prospect;
    }
}
usort($selected, static fn (array $a, array $b): int => [$a['city'], $a['postal'], $a['address']] <=> [$b['city'], $b['postal'], $b['address']]);
$shortest = ($_GET['ordre'] ?? '') === 'court';
if ($shortest) $selected = fv_route_order($selected);   // plus court chemin d'après les positions déjà connues (les autres arrêts suivent)
$ungeo = count(array_filter($selected, static fn (array $p): bool => !fv_geo_has($p)));

$pool = array_slice($matches, 0, 400);   // la liste à cocher et la carte de sélection se limitent aux 400 premières fiches
$poolIds = array_map(static fn (array $p): int => (int) $p['id'], $pool);
$zones = fv_zones();

$addressOf = static function (array $p): string {
    $street = trim((string) $p['address']);
    $city = trim(trim((string) $p['postal']) . ' ' . trim((string) $p['city']));
    return trim($street . ($street !== '' && $city !== '' ? ', ' : '') . $city);
};
$mapSearch = static fn (array $p): string => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($addressOf($p));
$routeQuery = static function (array $stops) use ($addressOf): string {
    $points = [];
    foreach ($stops as $stop) {
        $address = $addressOf($stop);
        if ($address !== '') $points[] = $address;
    }
    if (count($points) < 2) return '';
    $origin = array_shift($points);
    $destination = array_pop($points);
    $params = ['api' => 1, 'travelmode' => 'driving', 'origin' => $origin, 'destination' => $destination];
    if ($points) $params['waypoints'] = implode('|', array_slice($points, 0, 8));
    return 'https://www.google.com/maps/dir/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
};
$routeUrl = $routeQuery($selected);
$stopLabel = static fn (array $p): string => $p['business'] !== '' ? $p['business'] : $p['name'];
$mapData = [
    'csrf' => fv_csrf(),
    'geoUrl' => 'tournee-geo.php',
    'tiles' => 'https://data.geopf.fr/wmts?SERVICE=WMTS&REQUEST=GetTile&VERSION=1.0.0&LAYER=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&STYLE=normal&TILEMATRIXSET=PM&TILEMATRIX={z}&TILEROW={y}&TILECOL={x}&FORMAT=image/png',
    'stops' => array_map(static fn (array $p, int $i): array => [
        'id' => (int) $p['id'], 'n' => $i + 1, 'label' => $stopLabel($p), 'addr' => $addressOf($p),
        'lat' => fv_geo_has($p) ? (float) $p['lat'] : null, 'lng' => fv_geo_has($p) ? (float) $p['lng'] : null,
        'url' => $mapSearch($p),
    ], $selected, array_keys($selected)),
    'pool' => array_map(static fn (array $p): array => [
        'id' => (int) $p['id'], 'label' => $stopLabel($p), 'addr' => $addressOf($p),
        'lat' => fv_geo_has($p) ? (float) $p['lat'] : null, 'lng' => fv_geo_has($p) ? (float) $p['lng'] : null,
    ], $pool),
];
$currentUrl = 'tournees.php?' . http_build_query(array_filter([
    'ordre' => $shortest ? 'court' : null,
    'ids' => $selectedIds ? implode(',', $selectedIds) : null,
    'ville' => $filters['city'] ?: null,
    'zone' => $filters['zone'] ?: null,
    'due' => $filters['due'] ?: null,
    'owner' => $filters['owner_id'] ?: null,
    'tous' => isset($_GET['tous']) ? 1 : null,
    'tout' => $allBase ? 1 : null,
    'q' => $filters['q'] ?: null,
]));

fv_layout_start('Tournées & terrain', [
    'eyebrow' => fv_is_scoped() ? 'Force de vente · tournée' : 'Porte-à-porte',
    'desc' => 'Choisissez les commerces d’une même ville ou zone, dans l’ordre de la rue. La tournée se prépare, s’imprime et se consigne arrêt par arrêt.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php?nouveau=1">' . fv_icon('plus', 14) . ' Ajouter un commerce</a>',
    'head' => '<link rel="stylesheet" href="/assets/lib/leaflet/leaflet.min.css">',
]);
?>

<?php if ($selected): ?>
<section class="fv-card fv-route-card" id="tournee">
  <div class="fv-card-head">
    <div>
      <h2>Tournée du <?= e(date('d/m/Y')) ?> <small class="fv-sub"><?= count($selected) ?> arrêt<?= count($selected) > 1 ? 's' : '' ?><?= $allBase ? ' · toute la base' . ($filters['city'] . $filters['zone'] . $filters['q'] !== '' ? ' filtrée' : '') : '' ?></small></h2>
      <p>Ordre proposé par ville, code postal puis rue. Ajustez à pied selon le terrain.<?= $allBase && count($matches) >= 2000 ? ' Les 2 000 premières fiches seulement : filtrez par ville ou zone pour affiner.' : '' ?></p>
    </div>
    <div class="fv-actions fv-no-print">
      <?php if ($routeUrl !== ''): ?><a class="fv-btn fv-btn-sm" href="<?= e($routeUrl) ?>" target="_blank" rel="noopener"><?= fv_icon('route', 14) ?> Itinéraire Google Maps</a><?php endif; ?>
      <a class="fv-btn fv-btn-sm<?= $shortest ? ' fv-btn-primary' : '' ?>" href="<?= e($shortest ? preg_replace('/(\?|&)ordre=court(&|$)/', '$1', $currentUrl) . '#tournee' : $currentUrl . '&ordre=court#tournee') ?>" title="Réordonne les arrêts pour limiter les kilomètres, à partir du premier"><?= fv_icon('route', 14) ?> <?= $shortest ? 'Ordre par rue' : 'Ordre le plus court' ?></a>
      <button class="fv-btn fv-btn-sm" type="button" data-print="route"><?= fv_icon('print', 14) ?> Imprimer la feuille</button>
      <a class="fv-btn fv-btn-sm fv-btn-ghost" href="tournees.php<?= $filters['city'] !== '' ? '?ville=' . e(rawurlencode($filters['city'])) : '' ?>">Changer la sélection</a>
    </div>
  </div>

  <div class="fv-print-only fv-route-print-head">
    <div>
      <span class="fv-eyebrow">Feuille de tournée</span>
      <h1><?= e(fv_setting('marque_nom', 'Brocs')) ?> — <?= e($filters['city'] !== '' ? $filters['city'] : 'tournée du jour') ?></h1>
      <p><?= e(date('d/m/Y')) ?> · <?= count($selected) ?> arrêt<?= count($selected) > 1 ? 's' : '' ?><?= $member ? ' · ' . e($member['name']) : '' ?></p>
    </div>
  </div>

  <div class="fv-tmap-wrap fv-no-print">
    <div class="fv-tmap" id="fv-route-map" role="img" aria-label="Carte de la tournée : arrêts numérotés dans l’ordre de passage"></div>
    <p class="fv-hint" id="fv-route-status" aria-live="polite">Situation des arrêts sur la carte…</p>
    <?php if ($shortest): ?><p class="fv-hint">Ordre le plus court à vol d’oiseau, à partir du premier arrêt<?= $ungeo ? ' · ' . $ungeo . ' arrêt' . ($ungeo > 1 ? 's' : '') . ' sans position placé' . ($ungeo > 1 ? 's' : '') . ' à la fin (rechargez une fois la carte affichée)' : '' ?>.</p><?php endif; ?>
  </div>

  <div class="fv-route-list">
    <?php foreach ($selected as $i => $p): ?>
      <?php $last = fv_prospect_last_activity((int) $p['id']); ?>
      <article class="fv-stop">
        <span class="fv-stop-n"><?= $i + 1 ?></span>
        <div class="fv-stop-main">
          <h3><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?><span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span></h3>
          <p class="fv-stop-meta"><?= e(trim(($p['name'] !== '' ? $p['name'] . ' · ' : '') . ($p['kind'] !== '' ? $p['kind'] . ' · ' : '') . $addressOf($p))) ?></p>
          <?php if ($p['phone'] !== ''): ?><p class="fv-stop-meta"><a href="tel:<?= e($p['phone']) ?>"><?= e($p['phone']) ?></a></p><?php endif; ?>
          <?php if ($last): ?><p class="fv-stop-last">Dernier échange : <?= e(fv_kind_label($last['kind'])) ?><?= $last['outcome'] !== '' ? ' · ' . e(fv_outcome_label($last['kind'], $last['outcome'])) : '' ?> le <?= e(fv_date($last['occurred_at'])) ?></p><?php endif; ?>
          <?php if ($p['notes'] !== ''): ?><p class="fv-stop-note"><?= nl2br(e(mb_substr($p['notes'], 0, 220))) ?></p><?php endif; ?>
          <div class="fv-stop-lines" aria-hidden="true"><span>Visite faite</span><span>Notes</span></div>
        </div>
        <div class="fv-stop-side fv-no-print">
          <?php if ($addressOf($p) !== ''): ?><a class="fv-btn fv-btn-sm" href="<?= e($mapSearch($p)) ?>" target="_blank" rel="noopener"><?= fv_icon('route', 13) ?> Y aller</a><?php endif; ?>
          <a class="fv-btn fv-btn-sm" href="prospects.php?id=<?= (int) $p['id'] ?>">Fiche</a>
          <details class="fv-stop-form">
            <summary class="fv-btn fv-btn-sm fv-btn-primary">Consigner</summary>
            <form class="fv-form" method="post" action="activite-action.php">
              <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
              <input type="hidden" name="prospect_id" value="<?= (int) $p['id'] ?>">
              <input type="hidden" name="kind" value="visite">
              <input type="hidden" name="retour" value="<?= e($currentUrl) ?>#tournee">
              <label class="fv-field">Résultat
                <select name="outcome">
                  <?php foreach (fv_outcomes()['visite'] as $value => $label): ?><option value="<?= e($value) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="fv-field">Notes
                <textarea name="notes" rows="2" placeholder="Ce qui s’est dit, la suite…"></textarea>
              </label>
              <label class="fv-field">Repasser le
                <input type="date" name="next_at">
              </label>
              <button class="fv-btn fv-btn-sm fv-btn-primary" type="submit">Enregistrer la visite</button>
            </form>
          </details>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="fv-grid fv-grid-side fv-no-print">
  <section class="fv-card">
    <div class="fv-card-head">
      <div><h2>Sélectionner les arrêts</h2><p><?= count($matches) ?> fiche<?= count($matches) > 1 ? 's' : '' ?> avec une adresse<?= count($matches) > count($pool) ? ' (les ' . count($pool) . ' premières sont listées)' : '' ?>. Cochez puis préparez la tournée, ou prenez toute la base.</p></div>
      <button class="fv-btn fv-btn-sm" type="button" data-select-all>Tout cocher</button>
    </div>

    <div class="fv-card-pad" style="padding-top:12px;padding-bottom:0;">
      <form class="fv-toolbar" method="get">
        <label class="fv-search"><?= fv_icon('search', 15) ?><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, rue, ville…" aria-label="Rechercher"></label>
        <input name="ville" value="<?= e($filters['city']) ?>" placeholder="Ville" aria-label="Ville" style="max-width:150px;">
        <select name="zone" onchange="this.form.submit()">
          <option value="">Toutes les zones</option>
          <?php foreach ($zones as $z): ?><option value="<?= e($z) ?>"<?= $filters['zone'] === $z ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?>
        </select>
        <?php if ($ctx['manager']): ?>
          <select name="owner" onchange="this.form.submit()">
            <option value="0">Toute l’équipe</option>
            <?php foreach ($members as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $filters['owner_id'] === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <select name="due" onchange="this.form.submit()">
          <option value="">Toutes les échéances</option>
          <option value="today"<?= $filters['due'] === 'today' ? ' selected' : '' ?>>Dues aujourd’hui</option>
          <option value="week"<?= $filters['due'] === 'week' ? ' selected' : '' ?>>Cette semaine</option>
          <option value="none"<?= $filters['due'] === 'none' ? ' selected' : '' ?>>Sans échéance</option>
        </select>
        <label class="fv-check"><input type="checkbox" name="tous" value="1"<?= isset($_GET['tous']) ? ' checked' : '' ?> onchange="this.form.submit()"> Inclure les fiches closes</label>
        <button class="fv-btn fv-btn-sm" type="submit">Filtrer</button>
      </form>
    </div>

    <div class="fv-card-pad fv-tmap-wrap">
      <div class="fv-tmap" id="fv-pool-map" role="img" aria-label="Carte des commerces à visiter"></div>
      <div class="fv-tmap-bar">
        <span class="fv-hint" id="fv-pool-status" aria-live="polite"></span>
        <button class="fv-btn fv-btn-sm" type="button" id="fv-pool-locate"><?= fv_icon('route', 13) ?> Situer les commerces sur la carte</button>
        <button class="fv-btn fv-btn-sm" type="button" id="fv-pool-inview">Cocher ceux de la vue</button>
      </div>
    </div>

    <form method="get" action="tournees.php">
      <input type="hidden" name="q" value="<?= e($filters['q']) ?>">
      <input type="hidden" name="ville" value="<?= e($filters['city']) ?>">
      <input type="hidden" name="zone" value="<?= e($filters['zone']) ?>">
      <input type="hidden" name="due" value="<?= e($filters['due']) ?>">
      <?php if ($filters['owner_id']): ?><input type="hidden" name="owner" value="<?= (int) $filters['owner_id'] ?>"><?php endif; ?>
      <?php if (isset($_GET['tous'])): ?><input type="hidden" name="tous" value="1"><?php endif; ?>
      <?php foreach ($selectedIds as $id): ?><?php if (!in_array($id, $poolIds, true)): ?><input type="hidden" name="ids[]" value="<?= $id ?>"><?php endif; ?><?php endforeach; ?>

      <div class="fv-route-pick fv-table-wrap">
        <?php if (!$pool): ?>
          <div class="fv-empty"><b>Aucun commerce à visiter</b>Ajoutez une adresse à vos fiches ou élargissez les filtres.</div>
        <?php else: ?>
          <table class="fv-table">
            <thead><tr><th style="width:30px;"></th><th>Commerce</th><th>Adresse</th><th>Statut</th><th>Échéance</th></tr></thead>
            <tbody>
              <?php foreach ($pool as $p): ?>
                <tr>
                  <td><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>" data-select-item<?= in_array((int) $p['id'], $selectedIds, true) ? ' checked' : '' ?> aria-label="Inclure <?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?>"></td>
                  <td>
                    <a class="fv-rowlink main" href="prospects.php?id=<?= (int) $p['id'] ?>"><?= e($p['business'] !== '' ? $p['business'] : $p['name']) ?></a>
                    <span class="sub"><?= e(trim(($p['name'] !== '' ? $p['name'] . ' · ' : '') . ($p['kind'] !== '' ? $p['kind'] : ''))) ?></span>
                  </td>
                  <td><span class="sub"><?= e($addressOf($p)) ?></span><?php if ($p['zone'] !== ''): ?><br><span class="sub">Zone <?= e($p['zone']) ?></span><?php endif; ?></td>
                  <td><span class="fv-badge" data-s="<?= e($p['status']) ?>"><?= e(fv_status_label($p['status'])) ?></span></td>
                  <td><span class="sub"<?= $p['next_action_at'] && $p['next_action_at'] < fv_today() ? ' style="color:var(--fv-warn);font-weight:700;"' : '' ?>><?= e($p['next_action_at'] ? fv_date($p['next_action_at']) : '—') ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="fv-route-bar">
        <span class="fv-hint" data-select-count hidden></span>
        <?php if ($matches): ?><button class="fv-btn" type="submit" name="tout" value="1" title="Prend tous les commerces avec adresse correspondant aux filtres, sans les cocher"><?= fv_icon('route', 15) ?> Toute la base (<?= count($matches) ?>)</button><?php endif; ?>
        <button class="fv-btn fv-btn-primary" type="submit"><?= fv_icon('route', 15) ?> Préparer la tournée</button>
      </div>
    </form>
  </section>

  <aside class="fv-grid" style="gap:14px;">
    <?php if (!fv_is_scoped()): ?>
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Le bon réflexe</h2></div></div>
      <div class="fv-card-body fv-script">
        <p><b>Avant de partir</b> — vérifiez les horaires (souvent fermé le lundi), préparez trois phrases : qui vous êtes, ce que la boutique y gagne, ce que vous proposez ensuite.</p>
        <details><summary>Entrer sans déranger</summary><p>« Bonjour, je passais dans la rue. Deux minutes pour vous montrer un service qui aide les commerces comme le vôtre à vendre aussi en ligne, sans commission ? »</p></details>
        <details><summary>Repartir avec une trace</summary><p>Notez le nom du contact, le meilleur créneau et une phrase de contexte. Consignez la visite depuis la fiche : le statut et la prochaine action se mettent à jour tout seuls.</p></details>
        <details><summary>Préparer le passage suivant</summary><p>Un commerce intéressé se revoit sous 48 h avec la boutique de démonstration ouverte sur votre téléphone. Utilisez le pitch de 2 minutes.</p></details>
        <a class="fv-btn fv-btn-sm" href="pitch.php">Ouvrir le pitch</a>
      </div>
    </section>
    <?php endif; ?>
    <section class="fv-card">
      <div class="fv-card-head"><div><h2>Repères</h2></div></div>
      <div class="fv-card-body">
        <div class="fv-kpis" style="grid-template-columns:1fr 1fr;">
          <div class="fv-kpi"><span class="n"><?= count($pool) ?></span><span class="l">à visiter</span></div>
          <div class="fv-kpi"><span class="n"><?= count($selected) ?></span><span class="l">dans la tournée</span></div>
        </div>
        <p class="fv-hint">L’itinéraire Google Maps reprend les 9 premiers arrêts avec une adresse. Au-delà, coupez la tournée en deux boucles.</p>
      </div>
    </section>
  </aside>
</div>

<script type="application/json" id="fv-tmap-data"><?= json_encode($mapData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/lib/leaflet/leaflet.min.js"></script>
<script src="<?= e(fv_asset('assets/tournee-carte.js?v=3')) ?>"></script>
<?php fv_layout_end(); ?>
