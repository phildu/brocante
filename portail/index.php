<?php
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';
require_once PORTAIL_ROOT . '/includes/equipe.php';

$shops = tenant_list();
$flash = portail_flash();
$pendingRequests = count(array_filter(saas_requests(), static fn ($r) => $r['status'] === 'pending'));
$galleryCount = count(gallery_list());
$team = eq_stats();
$onlineCount = 0;
foreach ($shops as $shop) {
    if (portail_presence_mode($shop) !== 'physical') $onlineCount++;
}

$confirmReset = $_GET['reinitialiser'] ?? '';
$confirmDelete = $_GET['supprimer'] ?? '';
$editAccess = $_GET['acces'] ?? '';

// Adresse d'un commerce : <slug>.brocenstock.test en local, <portail>/<slug> en ligne (voir portail_shop_url()).
$viewing = (string) ($_GET['voir'] ?? active_slug());
if (!isset($shops[$viewing])) {
    $viewing = (string) array_key_first($shops);
}
// Base de démonstration créée au premier affichage d'un commerce.
if ($viewing !== '' && !is_file(tenant_file($shops[$viewing], 'db_file'))) {
    seed_tenant($shops[$viewing]);
}
$shopUrl = static fn (string $slug): string => portail_shop_url($slug, $shops[$slug]);
$shopLabel = static fn (string $slug): string => portail_shop_label($slug, $shops[$slug]);
$current = $shops[$viewing] ?? null;
$currentState = $viewing !== '' ? shop_state_load($viewing) : shop_state_defaults();
$currentLive = shop_state_is_public($currentState);
$currentPreview = $currentLive ? '' : '?apercu=' . rawurlencode(shop_preview_token($viewing));

require_once __DIR__ . '/todo/_bootstrap.php';
$tdCounts = td_count();
$tdTasks = td_tasks('todo', '', 6);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Vue d'ensemble — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<link rel="stylesheet" href="/portail/todo/todo.css">
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => "Vue d'ensemble",
    'eyebrow' => 'Pilotage',
    'desc' => 'Toutes les boutiques du serveur, leur adresse et leur accès. Choisissez une boutique pour l’apercevoir ou ouvrir son administration.',
    'actions' => '<a class="btn" href="/portail/vente/">Forces de vente</a>'
        . ' <a class="btn btn-primary" href="/portail/nouveau.php">' . portail_icon('plus', 16) . ' Nouveau commerce</a>'
        . ' <a class="btn" href="/portail/todo/">' . portail_icon('receipt', 16) . ' Tâches' . ($tdCounts['open'] ? ' · ' . (int) $tdCounts['open'] : '') . '</a>',
]); ?>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="kpis">
    <div class="kpi">
      <span class="n"><?= count($shops) ?></span>
      <span class="l">Boutiques</span>
      <span class="s"><?= $onlineCount ?> en ligne · <?= count($shops) - $onlineCount ?> physique seul</span>
    </div>
    <div class="kpi">
      <span class="n"><?= $galleryCount ?></span>
      <span class="l">Galeries commerciales</span>
      <span class="s"><a href="/portail/galeries.php">Gérer les galeries</a></span>
    </div>
    <div class="kpi<?= $pendingRequests ? ' bad' : '' ?>">
      <span class="n"><?= $pendingRequests ?></span>
      <span class="l">Inscriptions à traiter</span>
      <span class="s"><a href="/portail/inscriptions.php"><?= $pendingRequests ? 'Ouvrir la file d’attente' : 'File à jour' ?></a></span>
    </div>
    <div class="kpi<?= $team['attente'] ? ' bad' : '' ?>">
      <span class="n"><?= (int) $team['actifs'] ?></span>
      <span class="l">Équipe du projet</span>
      <span class="s"><a href="/portail/equipe.php"><?= $team['attente'] ? (int) $team['attente'] . ' candidature' . ($team['attente'] > 1 ? 's' : '') . ' à traiter' : 'Aucune candidature en attente' ?></a></span>
    </div>
    <div class="kpi kpi-wide">
      <span class="n" style="font-size:1rem;line-height:1.35;"><?= e(portail_address_pattern()) ?></span>
      <span class="l">Adresse d’une boutique</span>
      <span class="s">Chaque commerce garde son adresse propre.</span>
    </div>
  </div>

  <section class="td-dash" aria-labelledby="td-dash-title">
    <header class="td-dash-head">
      <div>
        <h2 id="td-dash-title">Paramétrages à faire</h2>
        <p class="hint"><?= (int) $tdCounts['open'] ?> à faire · <?= (int) $tdCounts['urgent'] ?> prioritaires · <?= (int) $tdCounts['done'] ?> faites</p>
      </div>
      <a class="btn btn-primary" href="/portail/todo/">Gérer la liste</a>
    </header>
    <?php if (!$tdTasks): ?>
      <p class="td-dash-empty">Rien en attente. <a href="/portail/todo/">Ajouter une tâche</a> quand un réglage reste à brancher.</p>
    <?php else: ?>
      <ul class="td-dash-list">
        <?php foreach ($tdTasks as $task): ?>
          <li class="td-dash-item">
            <form class="td-toggle" method="post" action="/portail/todo/action.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $task['id'] ?>">
              <input type="hidden" name="retour" value="dashboard">
              <button class="td-check" type="submit" aria-pressed="false" aria-label="Marquer comme fait : <?= e($task['title']) ?>"><span aria-hidden="true"></span></button>
            </form>
            <div>
              <span class="td-dash-title"><?= e($task['title']) ?></span>
              <?php if ($task['detail'] !== ''): ?><p class="td-dash-detail"><?= e($task['detail']) ?></p><?php endif; ?>
              <span class="td-dash-meta">
                <span class="td-pill"><span class="td-dot" style="background:<?= e(TD_CATEGORY_COLORS[$task['category']] ?? TD_CATEGORY_COLORS['autre']) ?>"></span><?= e(TD_CATEGORIES[$task['category']] ?? TD_CATEGORIES['autre']) ?></span>
                <?php if ((int) $task['priority'] === 1): ?><span class="td-prio" data-p="1">Prioritaire</span><?php endif; ?>
                <?php if ($task['shop'] !== ''): ?><span><?= e((string) ($shops[$task['shop']]['name'] ?? $task['shop'])) ?></span><?php endif; ?>
              </span>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($tdCounts['open'] > count($tdTasks)): ?>
        <p class="hint" style="margin:0;"><a href="/portail/todo/">Voir les <?= (int) $tdCounts['open'] ?> tâches à faire</a></p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <div class="dash">
    <div class="dash-main">
      <section class="panel dash-panel" aria-labelledby="shops-title">
        <div class="panel-head">
          <div>
            <h2 id="shops-title">Boutiques <small><?= count($shops) ?></small></h2>
            <p class="hint">Une carte par commerce : adresse, accès administration et actions.</p>
          </div>
          <span class="hint" data-shop-count><?= count($shops) ?> boutique<?= count($shops) > 1 ? 's' : '' ?></span>
        </div>
        <div class="shops-toolbar">
          <label class="shop-search">
            <?= portail_icon('search', 16) ?>
            <input type="search" data-shop-filter placeholder="Filtrer par nom, identifiant ou adresse…" aria-label="Filtrer les boutiques" autocomplete="off" spellcheck="false">
          </label>
        </div>
        <div class="shops-list">
          <?php foreach ($shops as $slug => $shop):
              $isActive = (string) $slug === $viewing;
              $look = portail_appearance($shop);
              $mode = portail_presence_mode($shop);
              $presence = business_presence_label($mode);
              $blocker = tenant_delete_blocker($slug);
              $shopState = shop_state_load($slug);
              $shopStripe = stripe_modes_status(tenant_file($shop, 'secrets_dir'));
              $shopLive = shop_state_is_public($shopState);
              $shopPreview = $shopLive ? '' : '?apercu=' . rawurlencode(shop_preview_token($slug));
              $searchText = $shop['name'] . ' ' . $slug . ' ' . $shopLabel($slug) . ' ' . $presence . ' ' . $shop['admin_user']; ?>
            <article class="shopcard<?= $isActive ? ' is-active' : '' ?>" data-shop-card data-search="<?= e($searchText) ?>">
              <div class="shopcard-head">
                <span class="shopmark" style="--mark:<?= e($look['colors']['accent']) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $shop['name'], 0, 1))) ?></span>
                <div class="shopcard-id">
                  <h3>
                    <?= e($shop['name']) ?>
                    <?php if ($isActive): ?><span class="pill done">À l’écran</span><?php endif; ?>
                    <?php if (!$shopLive): ?><span class="pill sent"><?= ($shopState['status'] ?? '') === 'paused' ? 'En pause' : 'En test' ?></span><?php endif; ?>
                    <?php if (!empty($shopState['gesture'])): ?><span class="pill <?= shop_gesture_current($shopState) ? 'done' : 'sent' ?>" title="<?= e((string) ($shopState['gesture']['note'] ?? '')) ?>"><?= e(shop_gesture_label($shopState)) ?></span><?php endif; ?>
                    <span class="pill<?= $mode === 'physical' ? '' : ' sent' ?>"><?= e($presence) ?></span>
                  </h3>
                  <p class="shopcard-url"><a href="<?= e($shopUrl($slug)) ?>/<?= e($shopPreview) ?>" target="_blank" rel="noopener"><?= e($shopLabel($slug)) ?> ↗</a></p>
                </div>
              </div>
              <div class="shopcard-meta">
                <span>Administration : <b><?= e($shop['admin_user']) ?></b></span>
                <span>Stripe :
                  <a href="/portail/stripe.php?boutique=<?= e(urlencode($slug)) ?>"><b>mode <?= e(STRIPE_MODES[$shopStripe['mode']]) ?></b></a><?= $shopStripe['modes'][$shopStripe['mode']]['secret'] ? '' : ' — sans clé' ?>
                </span>
                <span class="look" title="Apparence<?= $look['custom'] ? ' réglée dans l’administration du commerce' : ' de départ' ?>">
                  <span class="look-colors" aria-hidden="true">
                    <?php foreach (['bg', 'accent', 'accent-2', 'ink'] as $key): ?><span style="background:<?= e($look['colors'][$key]) ?>"></span><?php endforeach; ?>
                  </span>
                  <span>Titres <?= e($look['fonts']['display']) ?> · textes <?= e($look['fonts']['body']) ?></span>
                </span>
              </div>
              <div class="shopcard-actions">
                <a class="btn small<?= $isActive ? ' btn-primary' : '' ?>" href="/portail/?voir=<?= e($slug) ?>#apercu">Aperçu</a>
                <a class="btn small" href="<?= e($shopUrl($slug)) ?>/admin/" target="_blank" rel="noopener">Administration ↗</a>
                <a class="btn small" href="/portail/legal.php?slug=<?= e(rawurlencode($slug)) ?>">Pages légales</a>
                <details class="shop-more">
                  <summary class="btn small">Gérer</summary>
                  <div class="shop-more-panel">
                    <a href="<?= e($shopUrl($slug)) ?>/<?= e($shopPreview) ?>" target="_blank" rel="noopener">Ouvrir la boutique ↗</a>
                    <a href="/portail/boutique-statut.php?slug=<?= e(rawurlencode($slug)) ?>">Statut &amp; geste commercial — <?= $shopLive ? 'publiée' : (($shopState['status'] ?? '') === 'paused' ? 'en pause' : 'en test') ?></a>
                    <a href="/portail/?voir=<?= e($viewing) ?>&amp;acces=<?= e($slug) ?>">Changer l’accès admin</a>
                    <?php if ($slug !== TENANT_DEFAULT): ?>
                      <a href="/portail/?voir=<?= e($viewing) ?>&amp;reinitialiser=<?= e($slug) ?>">Réinitialiser les données</a>
                    <?php endif; ?>
                    <?php if ($blocker === null): ?>
                      <a class="danger" href="/portail/?voir=<?= e($viewing) ?>&amp;supprimer=<?= e($slug) ?>">Supprimer la boutique</a>
                    <?php endif; ?>
                  </div>
                </details>
              </div>

              <?php if ($editAccess === $slug): ?>
                <form class="confirm access" method="post" action="/portail/action.php" autocomplete="off">
                  <span>Nouvel accès à l’administration de <strong><?= e($shop['name']) ?></strong> (<?= e($shopLabel($slug)) ?>/admin/) :</span>
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="acces">
                  <input type="hidden" name="slug" value="<?= e($slug) ?>">
                  <label class="field">Identifiant<input name="admin_user" required pattern="[A-Za-z0-9._@\-]{3,40}" maxlength="40" autocapitalize="none" value="<?= e($shop['admin_user']) ?>"></label>
                  <label class="field">Nouveau mot de passe
                    <input type="password" name="admin_password" id="pw-<?= e($slug) ?>" required minlength="6" maxlength="60" autocomplete="new-password" placeholder="6 caractères minimum">
                  </label>
                  <span class="actions" style="margin:0;">
                    <button class="btn small btn-primary" type="submit">Enregistrer</button>
                    <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>">Annuler</a>
                  </span>
                </form>
              <?php endif; ?>

              <?php if ($confirmReset === $slug): ?>
                <form class="confirm" method="post" action="/portail/action.php">
                  <span>Remettre le contenu et les produits de <strong><?= e($shop['name']) ?></strong> à leur état de départ (seed-data.json) ? Les modifications faites dans l’administration seront perdues.</span>
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="reinitialiser">
                  <input type="hidden" name="slug" value="<?= e($slug) ?>">
                  <span class="actions" style="margin:0;">
                    <button class="btn small btn-primary" type="submit">Réinitialiser</button>
                    <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>">Annuler</a>
                  </span>
                </form>
              <?php endif; ?>

              <?php if ($confirmDelete === $slug && $blocker === null): ?>
                <form class="confirm danger-zone" method="post" action="/portail/action.php" autocomplete="off">
                  <span>Supprimer <strong><?= e($shop['name']) ?></strong> de ce serveur ? Sa boutique, ses produits, ses commandes et son administration deviennent inaccessibles.
                    Les fichiers (configuration, logo, base de données, clés) sont <strong>déplacés dans <code>data/corbeille/</code></strong>, pas effacés : ils peuvent être remis en place à la main. Les photos de <code>uploads/</code> ne sont pas touchées.</span>
                  <span class="hint">Cela ne concerne que ce serveur. Si ce commerce existe aussi ailleurs (votre Mac, le portail en ligne), supprimez-le là-bas aussi : sinon un prochain déploiement le recréera, vide.</span>
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="slug" value="<?= e($slug) ?>">
                  <label class="field">Pour confirmer, tapez l’identifiant <code><?= e($slug) ?></code>
                    <input name="confirm_slug" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="<?= e($slug) ?>">
                  </label>
                  <span class="actions" style="margin:0;">
                    <button class="btn small danger-solid" type="submit">Supprimer définitivement ce commerce</button>
                    <a class="btn small" href="/portail/?voir=<?= e($viewing) ?>">Annuler</a>
                  </span>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="hint" data-shop-empty hidden style="padding:16px 18px;">Aucune boutique ne correspond à ce filtre.</p>
      </section>

      <p class="hint">Chaque commerce s’ouvre à l’adresse <code><?= e(portail_address_pattern()) ?></code> (ou à celle que déclare son <code>tenant.php</code>). Le Petit Chalet utilise toujours sa base <code>brocante.db</code> : il n’est jamais réinitialisé ni supprimé depuis ce portail.</p>
    </div>

    <aside class="dash-side" id="apercu" aria-label="Aperçu de la boutique">
      <?php if ($current !== null): ?>
        <div class="preview-panel">
          <div class="preview-head">
            <div>
              <h2>Aperçu — <?= e($current['name']) ?></h2>
              <p><?= e($shopLabel($viewing)) ?></p>
            </div>
            <a class="btn small" href="<?= e($shopUrl($viewing)) ?>/<?= e($currentPreview) ?>" target="_blank" rel="noopener">Ouvrir ↗</a>
          </div>
          <div class="preview-tools">
            <div class="seg" id="pages" role="group" aria-label="Page">
              <button type="button" data-src="/" aria-pressed="true">Accueil</button>
              <button type="button" data-src="/boutique.php" aria-pressed="false">Boutique</button>
              <button type="button" data-src="/cart.php" aria-pressed="false">Panier</button>
              <button type="button" data-src="/admin/" aria-pressed="false">Administration</button>
            </div>
            <div class="seg" id="device" role="group" aria-label="Format">
              <button type="button" data-device="desktop" aria-pressed="true">Ordinateur</button>
              <button type="button" data-device="mobile" aria-pressed="false">Mobile</button>
            </div>
          </div>
          <div class="preview-stage" id="stage">
            <div class="stage">
              <iframe id="frame" src="<?= e($shopUrl($viewing)) ?>/<?= e($currentPreview) ?>" data-origin="<?= e($shopUrl($viewing)) ?>" data-preview="<?= e($currentPreview) ?>" title="Boutique <?= e($current['name']) ?>"></iframe>
            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="preview-panel"><p class="preview-empty">Aucune boutique à prévisualiser.</p></div>
      <?php endif; ?>
    </aside>
  </div>
</div>
<script>
(function () {
  var frame = document.getElementById('frame');
  if (!frame) return;
  function press(group, btn) {
    group.querySelectorAll('button').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
  }
  document.getElementById('pages').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    press(this, b); frame.src = frame.dataset.origin + b.dataset.src + (frame.dataset.preview || '');
  });
  document.getElementById('device').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    press(this, b); document.getElementById('stage').classList.toggle('mobile', b.dataset.device === 'mobile');
  });
})();
</script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
