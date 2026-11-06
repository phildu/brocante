<?php
// Tarifs et disponibilité des extensions de galerie (les anciennes « options » : carte interactive, espace organisateur, file d'attente,
// buvette…) : proposée ou non, mode d'origine, prix mensuel et annuel, jours d'essai, types de galerie concernés.
// Réglages dans data/saas/galeries-modules.json (includes/gallery-modules.php). Chaque type de galerie peut ensuite régler le mode de chacune
// (portail → Types de galerie) ; l'essai et l'abonnement se font dans l'espace de la galerie (onglet des extensions).
require __DIR__ . '/_bootstrap.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page
require_once PORTAIL_ROOT . '/includes/gallery-modules.php';
require_once PORTAIL_ROOT . '/includes/gallery-types.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $key = (string) ($_POST['module'] ?? '');
    $err = gm_save_settings($key, [
        'active' => !empty($_POST['active']), 'default_mode' => $_POST['default_mode'] ?? 'option', 'month' => $_POST['month'] ?? '', 'year' => $_POST['year'] ?? '',
        'trial_days' => $_POST['trial_days'] ?? '0', 'types' => $_POST['types'] ?? [],
    ]);
    if ($err === null) {
        saas_event('config_changed', ['note' => ext_t('Tarifs et disponibilité {du ext} de galerie « ') . $key . ' » mis à jour', 'actor' => 'exploitant']);
        portail_flash('{Ext} « ' . (gm_defaults()[$key]['name'] ?? $key) . ' » : réglages enregistrés.', 'ok');
    } else {
        portail_flash($err, 'error');
    }
    header('Location: /portail/extensions-galerie.php#m-' . rawurlencode($key));
    exit;
}

$flash = portail_flash();
$modules = gm_modules();
$types = gt_types();
$pageTitle = ext_t('Tarifs des {exts} de galerie');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> — Portail</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .xg-card { border: 1px solid var(--line); border-radius: var(--radius, 10px); background: var(--panel); padding: 16px 18px; display: grid; gap: 12px; max-width: 920px; margin: 0 0 16px; }
  .xg-card h2 { margin: 0; font-size: 1.1rem; }
  .xg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
  .xg-types { display: flex; flex-wrap: wrap; gap: 6px 16px; }
  .xg-types label { display: inline-flex; gap: 6px; align-items: center; font-size: .9rem; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => $pageTitle,
    'eyebrow' => 'Boutiques',
    'desc' => ext_t('Prix, essai et disponibilité des {exts} que les organisateurs de galeries activent depuis leur espace.'),
    'section' => 'Boutiques',
]); ?>

  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <p class="hint" style="max-width:76ch;">Ces réglages valent pour toutes les galeries. Pour chaque type de galerie, vous pouvez en plus choisir si {le ext} est {?inclus|incluse}, payant{e} ou {?absent|absente} :
    <a href="/portail/offres-galerie.php">Types de galerie</a>. Un prix à 0 n’ouvre aucun paiement : seul l’essai gratuit ou un accès offert par vos soins reste possible.</p>

  <?php foreach ($modules as $key => $m): ?>
    <form class="xg-card" method="post" action="/portail/extensions-galerie.php" id="m-<?= e($key) ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="module" value="<?= e($key) ?>">
      <h2><?= e($m['emoji']) ?> <?= e($m['name']) ?><?= ext_scope_badge(ext_scope($key, 'gallery')) ?> <small class="hint" style="font-weight:400;"><?= e($key) ?></small></h2>
      <p class="hint" style="margin:0;max-width:76ch;"><?= e($m['description']) ?></p>
      <div class="xg-grid">
        <label class="field">Mode d’origine
          <select name="default_mode">
            <?php foreach (gm_mode_labels() as $mode => $label): ?><option value="<?= e($mode) ?>"<?= $m['default_mode'] === $mode ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="field">Prix mensuel (€)<input type="text" name="month" inputmode="decimal" value="<?= e(number_format($m['month_cents'] / 100, 2, ',', '')) ?>"></label>
        <label class="field">Prix annuel (€)<input type="text" name="year" inputmode="decimal" value="<?= e(number_format($m['year_cents'] / 100, 2, ',', '')) ?>"></label>
        <label class="field">Essai gratuit (jours)<input type="number" name="trial_days" min="0" max="365" value="<?= (int) $m['trial_days'] ?>"></label>
      </div>
      <div>
        <p class="hint" style="margin:0 0 6px;">Proposée d’office aux types de galerie cochés (aucun coché = tous). Chaque type peut la régler lui-même.</p>
        <div class="xg-types">
          <?php foreach ($types as $tk => $gt): ?>
            <label><input type="checkbox" name="types[]" value="<?= e($tk) ?>"<?= in_array($tk, $m['types'], true) ? ' checked' : '' ?>><?= e(($gt['emoji'] ?? '') . ' ' . $gt['name']) ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="send-actions" style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
        <label style="display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="active" value="1"<?= $m['active'] ? ' checked' : '' ?>> Proposée aux galeries</label>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>
</body>
</html>
