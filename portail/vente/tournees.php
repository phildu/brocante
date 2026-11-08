<?php
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_tournee.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$members = $ctx['manager'] ? fv_members_list(false) : [];
$filters = fv_tournee_filters($_GET);   // utilisés par « Toute la base » ; la modale applique les mêmes règles dans le navigateur

$idsParam = $_GET['ids'] ?? [];
if (is_string($idsParam)) $idsParam = explode(',', $idsParam);
$selectedIds = array_values(array_unique(array_filter(array_map('intval', (array) $idsParam))));
$selectedIds = array_slice($selectedIds, 0, FV_TOURNEE_MAX_IDS);

$allBase = isset($_GET['tout']);   // « toute la base » : tous les commerces avec adresse correspondant aux filtres, sans cocher
$candidates = fv_tournee_candidates();
$today = fv_today();
$week = fv_days_from(7);
$matches = array_values(array_filter($candidates, static fn (array $p): bool => fv_tournee_match($p, $filters, $today, $week)));
$selected = [];
if ($allBase) {
    $selected = array_slice($matches, 0, FV_TOURNEE_MAX_ALL);
    $selectedIds = [];
} else {
    foreach ($selectedIds as $id) {
        if ($prospect = fv_prospect_get($id)) $selected[] = $prospect;
    }
    $selectedIds = array_map(static fn (array $p): int => (int) $p['id'], $selected);   // seuls les arrêts encore accessibles restent cochés
}
usort($selected, static fn (array $a, array $b): int => [$a['city'], $a['postal'], $a['address']] <=> [$b['city'], $b['postal'], $b['address']]);
$shortest = ($_GET['ordre'] ?? '') === 'court';
if ($shortest) $selected = fv_route_order($selected);   // plus court chemin d'après les positions déjà connues (les autres arrêts suivent)
$ungeo = count(array_filter($selected, static fn (array $p): bool => !fv_geo_has($p)));

$toVisit = count(array_filter($candidates, static fn (array $p): bool => !in_array($p['status'], ['perdu', 'boutique_creee'], true)));

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
];
$currentUrl = 'tournees.php?' . http_build_query(array_filter([
    'ordre' => $shortest ? 'court' : null,
    'ids' => $selectedIds ? implode(',', $selectedIds) : null,
    'tout' => $allBase ? 1 : null,
] + fv_tournee_filter_params($filters)));
$filteredBase = $allBase && (fv_tournee_filtered($filters) || $filters['tous']);

fv_layout_start('Tournées & terrain', [
    'eyebrow' => fv_is_scoped() ? 'Force de vente · tournée' : 'Porte-à-porte',
    'desc' => 'Choisissez les commerces d’une même ville ou zone, dans l’ordre de la rue. La tournée se prépare, s’imprime et se consigne arrêt par arrêt.',
    'actions' => '<a class="fv-btn fv-btn-sm" href="prospects.php?nouveau=1">' . fv_icon('plus', 14) . ' Ajouter un commerce</a>',
    'head' => '<link rel="stylesheet" href="/assets/lib/leaflet/leaflet.min.css"><link rel="stylesheet" href="assets/tournee-arrets.css?v=3">',
]);
?>

<?php if ($selected): ?>
<section class="fv-card fv-route-card" id="tournee">
  <div class="fv-card-head">
    <div>
      <h2>Tournée du <?= e(date('d/m/Y')) ?> <small class="fv-sub"><?= count($selected) ?> arrêt<?= count($selected) > 1 ? 's' : '' ?><?= $allBase ? ' · toute la base' . ($filteredBase ? ' filtrée' : '') : '' ?></small></h2>
      <p>Ordre proposé par ville, code postal puis rue. Ajustez à pied selon le terrain.<?= $allBase && count($matches) > FV_TOURNEE_MAX_ALL ? ' Les ' . number_format(FV_TOURNEE_MAX_ALL, 0, ',', ' ') . ' premières fiches seulement : filtrez par ville ou zone pour affiner.' : '' ?></p>
    </div>
    <div class="fv-actions fv-no-print">
      <?php if ($routeUrl !== ''): ?><a class="fv-btn fv-btn-sm" href="<?= e($routeUrl) ?>" target="_blank" rel="noopener"><?= fv_icon('route', 14) ?> Itinéraire Google Maps</a><?php endif; ?>
      <a class="fv-btn fv-btn-sm<?= $shortest ? ' fv-btn-primary' : '' ?>" href="<?= e($shortest ? preg_replace('/(\?|&)ordre=court(&|$)/', '$1', $currentUrl) . '#tournee' : $currentUrl . '&ordre=court#tournee') ?>" title="Réordonne les arrêts pour limiter les kilomètres, à partir du premier"><?= fv_icon('route', 14) ?> <?= $shortest ? 'Ordre par rue' : 'Ordre le plus court' ?></a>
      <button class="fv-btn fv-btn-sm" type="button" data-print="route"><?= fv_icon('print', 14) ?> Imprimer la feuille</button>
      <a class="fv-btn fv-btn-sm fv-btn-ghost" href="#arrets" data-ta-open><?= fv_icon('plus', 14) ?> Modifier les arrêts</a>
    </div>
  </div>

  <div class="fv-print-only fv-route-print-head">
    <div>
      <span class="fv-eyebrow">Feuille de tournée</span>
      <h1><?= e(fv_setting('marque_nom', 'Brocs')) ?> — <?= e($filters['ville'] !== '' ? $filters['ville'] : 'tournée du jour') ?></h1>
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
  <section class="fv-card" id="choisir">
    <div class="fv-card-head">
      <div><h2>Choisir les arrêts</h2><p><?= number_format($toVisit, 0, ',', ' ') ?> commerce<?= $toVisit > 1 ? 's' : '' ?> à visiter (avec une adresse), sur <?= number_format(count($candidates), 0, ',', ' ') ?> fiche<?= count($candidates) > 1 ? 's' : '' ?>. Triez et filtrez par ville, rue, statut, zone, catégorie…, cochez, puis ajoutez à la tournée.</p></div>
    </div>
    <div class="fv-card-body">
      <?php if (!$candidates): ?>
        <div class="fv-empty"><b>Aucun commerce à visiter</b>Ajoutez une adresse à vos fiches ou importez un annuaire.</div>
      <?php else: ?>
        <div class="fv-actions">
          <button class="fv-btn fv-btn-primary" type="button" data-ta-open aria-haspopup="dialog"><?= fv_icon('route', 15) ?> <?= $selected ? 'Modifier les arrêts' : 'Choisir les arrêts' ?></button>
          <a class="fv-btn" href="tournees.php?tout=1<?= $shortest ? '&amp;ordre=court' : '' ?>#tournee" title="Prend tous les commerces à visiter, sans les cocher"><?= fv_icon('route', 15) ?> Toute la base (<?= $toVisit ?>)</a>
        </div>
        <p class="fv-hint">Dans la fenêtre : tri par colonne, filtres cumulables, « tout cocher » sur le résultat filtré. Les arrêts déjà dans la tournée restent cochés.</p>
      <?php endif; ?>
    </div>
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
          <div class="fv-kpi"><span class="n"><?= $toVisit ?></span><span class="l">à visiter</span></div>
          <div class="fv-kpi"><span class="n"><?= count($selected) ?></span><span class="l">dans la tournée</span></div>
        </div>
        <p class="fv-hint">L’itinéraire Google Maps reprend les 9 premiers arrêts avec une adresse. Au-delà, coupez la tournée en deux boucles.</p>
      </div>
    </section>
  </aside>
</div>

<?php if ($candidates): ?>
<dialog id="fv-ta" class="fv-ta fv-no-print" aria-labelledby="fv-ta-title" data-src="tournee-arrets.php" data-selected="<?= e(implode(',', $selectedIds)) ?>" data-shortest="<?= $shortest ? '1' : '0' ?>">
  <div class="fv-ta-box">
    <header class="fv-ta-head">
      <div><h2 id="fv-ta-title">Choisir les arrêts de la tournée</h2><p class="fv-hint">Cochez les commerces à visiter. Échap ferme la fenêtre.</p></div>
      <button type="button" class="fv-btn fv-btn-sm" data-ta-close aria-label="Fermer la fenêtre">Fermer ✕</button>
    </header>

    <form class="fv-ta-form" data-ta-filters role="search" aria-label="Filtres des commerces" autocomplete="off">
      <details data-ta-details open>
        <summary>Filtres</summary>
        <div class="fv-ta-grid">
          <label class="fv-field fv-ta-wide">Recherche
            <input type="search" name="q" placeholder="Nom, activité, adresse, ville…">
          </label>
          <label class="fv-field">Ville
            <input type="text" name="ville" list="fv-ta-cities" placeholder="Toutes" autocapitalize="off">
          </label>
          <label class="fv-field">Rue
            <input type="text" name="rue" list="fv-ta-streets" placeholder="Toutes" autocapitalize="off">
          </label>
          <label class="fv-field">Statut
            <select name="statut"><option value="">Tous (sauf fiches closes)</option></select>
          </label>
          <label class="fv-field">Zone
            <select name="zone"><option value="">Toutes</option></select>
          </label>
          <label class="fv-field">Catégorie · activité
            <select name="cat"><option value="">Toutes</option></select>
          </label>
          <label class="fv-field">Priorité
            <select name="prio"><option value="0">Toutes</option><option value="3">3 · Prioritaire</option><option value="2">2 · Normale</option><option value="1">1 · À froid</option></select>
          </label>
          <?php if ($ctx['manager'] && $members): ?>
          <label class="fv-field">Propriétaire
            <select name="owner"><option value="0">Toute l’équipe</option></select>
          </label>
          <?php endif; ?>
          <label class="fv-field">Prochaine action
            <select name="due"><option value="">Toutes</option><option value="today">Due aujourd’hui ou en retard</option><option value="week">Dans la semaine</option><option value="none">Sans échéance</option></select>
          </label>
          <label class="fv-field">Position GPS
            <select name="gps"><option value="">Toutes</option><option value="oui">Avec position</option><option value="non">Sans position</option></select>
          </label>
        </div>
        <div class="fv-ta-checks">
          <label class="fv-check"><input type="checkbox" name="tous"> Inclure les fiches closes</label>
          <label class="fv-check"><input type="checkbox" name="only"> Seulement les cochés</label>
          <button type="button" class="fv-btn fv-btn-sm fv-btn-ghost" data-ta-reset>Réinitialiser les filtres</button>
        </div>
      </details>

      <div class="fv-ta-bar">
        <label class="fv-field fv-ta-sort">Trier par
          <span class="fv-ta-sortrow">
            <select name="sort">
              <option value="c">Ville</option><option value="s">Rue</option><option value="b">Commerce</option><option value="st">Statut</option>
              <option value="z">Zone</option><option value="k">Catégorie</option><option value="pr">Priorité</option>
              <?php if ($ctx['manager'] && $members): ?><option value="o">Propriétaire</option><?php endif; ?>
              <option value="d">Prochaine action</option><option value="g">Position GPS</option>
            </select>
            <button type="button" class="fv-btn fv-btn-sm" data-ta-dir>A → Z</button>
          </span>
        </label>
        <div class="fv-ta-bulk">
          <button type="button" class="fv-btn fv-btn-sm" data-ta-check>Tout cocher (résultat)</button>
          <button type="button" class="fv-btn fv-btn-sm" data-ta-uncheck>Tout décocher (résultat)</button>
        </div>
      </div>
    </form>

    <div class="fv-ta-scroll" data-ta-scroll>
      <p class="fv-empty" data-ta-msg role="status">Chargement des commerces…</p>
      <table class="fv-ta-table" data-ta-table hidden>
        <thead>
          <tr>
            <th class="c-check"><span class="fv-sr">Cocher</span></th>
            <th aria-sort="none"><button type="button" data-sort="b">Commerce <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="c">Ville <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="s">Adresse <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="st">Statut <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="z">Zone <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="pr">Prio. <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <?php if ($ctx['manager'] && $members): ?><th aria-sort="none"><button type="button" data-sort="o">Propriétaire <span class="fv-ta-mark" aria-hidden="true"></span></button></th><?php endif; ?>
            <th aria-sort="none"><button type="button" data-sort="d">Prochaine action <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
            <th aria-sort="none"><button type="button" data-sort="g">GPS <span class="fv-ta-mark" aria-hidden="true"></span></button></th>
          </tr>
        </thead>
        <tbody data-ta-body></tbody>
      </table>
    </div>

    <footer class="fv-ta-foot">
      <div class="fv-ta-pager" data-ta-pager hidden>
        <button type="button" class="fv-btn fv-btn-sm" data-ta-prev>← Précédent</button>
        <span data-ta-page aria-live="polite"></span>
        <button type="button" class="fv-btn fv-btn-sm" data-ta-next>Suivant →</button>
      </div>
      <p class="fv-ta-count" data-ta-count role="status" aria-live="polite"></p>
      <p class="fv-ta-over" data-ta-over role="alert" hidden></p>
      <div class="fv-ta-actions">
        <button type="button" class="fv-btn" data-ta-close>Annuler</button>
        <button type="button" class="fv-btn" data-ta-all title="Prend tous les commerces correspondant aux filtres, sans les cocher (2 000 au plus)"><span>Toute la base filtrée (<span data-ta-all-n>0</span>)</span></button>
        <button type="button" class="fv-btn fv-btn-primary" data-ta-add><span>Ajouter à la tournée (<span data-ta-add-n>0</span>)</span></button>
      </div>
    </footer>
  </div>
  <datalist id="fv-ta-cities"></datalist>
  <datalist id="fv-ta-streets"></datalist>
</dialog>
<script src="assets/tournee-arrets.js?v=1"></script>
<?php endif; ?>

<script type="application/json" id="fv-tmap-data"><?= json_encode($mapData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="/assets/lib/leaflet/leaflet.min.js"></script>
<script src="<?= e(fv_asset('assets/tournee-carte.js?v=4')) ?>"></script>
<?php fv_layout_end(); ?>
