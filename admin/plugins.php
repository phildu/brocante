<?php
// Plugins de CETTE boutique (administrateur), pour un déploiement sans portail — c'est le cas du Petit Chalet
// (brocante.arrimage.com), dont la base n'est pas celle du portail : activer, mettre à jour ou désactiver un plugin
// (includes/plugins.php) s'y fait ici. Sur un déploiement avec portail (brocs.arrimage.com), tout passe par portail → Plugins.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plugins.php';
require_once __DIR__ . '/../includes/addons.php';
require_admin();

$hasPortal = is_file(__DIR__ . '/../portail/index.php');
$catalog = plugin_catalog();

function admin_plugins_pdo(): PDO
{
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        flash_set('Page expirée : rechargez-la et recommencez.', 'error');
        header('Location: /admin/plugins.php');
        exit;
    }
    $action = (string) ($_POST['action'] ?? '');
    $m = $catalog[(string) ($_POST['plugin'] ?? '')] ?? null;
    if (!$m || !in_array($action, ['enable', 'update', 'disable'], true)) {
        flash_set('Plugin introuvable.', 'error');
    } elseif ($hasPortal && $action !== 'disable' && (($ai = addon_info($m['id'])) === null || $ai['access'] === 'unavailable')) {
        flash_set('Ce plugin n’est pas proposé pour votre boutique.', 'error');
    } else {
        try {
            $pdo = admin_plugins_pdo();
            if ($action === 'disable') {
                plugin_disable($pdo, $m['id']);
                flash_set('Plugin « ' . $m['name'] . ' » désactivé (ses données sont conservées).');
            } else {
                $r = $action === 'enable' ? plugin_enable($pdo, DB_PATH, $m) : plugin_install($pdo, DB_PATH, $m);
                flash_set(($action === 'enable' ? 'Plugin activé : ' : 'Plugin mis à jour : ') . '« ' . $m['name'] . ' » v' . $r['to']
                    . ($r['applied'] ? ' (' . $r['applied'] . ' migration' . ($r['applied'] > 1 ? 's' : '') . ', base sauvegardée avant)' : '') . '.');
            }
        } catch (Throwable $e) {
            flash_set('Échec : ' . $e->getMessage() . '. La base n\'a pas été modifiée.', 'error');
        }
    }
    header('Location: /admin/plugins.php');
    exit;
}

$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();
$states = [];
$stateError = '';
try {
    $states = plugin_states(admin_plugins_pdo());
} catch (Throwable $e) {
    $stateError = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Plugins — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .pl { display: grid; gap: 14px; margin-top: 16px; max-width: 760px; }
  .pl-item { border: 1px solid var(--line); background: var(--surface); padding: 16px 18px; }
  .pl-item h2 { font-size: 1.1rem; margin: 0 0 4px; }
  .pl-item p { margin: 0 0 10px; color: var(--ink-soft); font-size: .9rem; }
  .pl-state { font-family: var(--font-mono); font-size: .8rem; }
  .pl-state.ok { color: #1b6e3c; } .pl-state.warn { color: #b3261e; }
  .pl-item form { display: inline; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'plugins'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Administration</p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Plugins</h1>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:20px;"><?= h($flash['message']) ?></p><?php endif; ?>
    <p class="lede">Activez les modules de votre boutique. Les modules payants démarrent par leur propre page (essai, abonnement) ; les modules en test sont gratuits, en échange de vos retours. La désactivation conserve les données.</p>
      <?php if ($stateError): ?><p class="publish-status" data-kind="error"><?= h($stateError) ?></p><?php endif; ?>
      <div class="pl">
      <?php foreach ($catalog as $m):
        $row = $states[$m['id']] ?? null;
        $status = plugin_status($row, $m);
        $ent = addon_info($m['id']) !== null ? addon_entitlement($m['id']) : null;
        if ($hasPortal && (!$ent || $ent['access'] === 'unavailable') && $status !== 'ready' && $status !== 'update') continue;
      ?>
        <div class="pl-item">
          <h2><?= h($m['name']) ?><?php if ($ent && $ent['stage'] === 'beta'): ?> <span class="pl-state warn">En test</span><?php endif; ?></h2>
          <?php if ($m['description'] !== ''): ?><p><?= h($m['description']) ?></p><?php endif; ?>
          <?php if ($ent): ?><p class="pl-state"><?= h($ent['label']) ?><?php $pr = array_filter([$ent['monthly_label'], $ent['yearly_label'], $ent['once_label']]); if ($pr && !$ent['beta_tester'] && $ent['access'] !== 'included'): ?> · <?= h(implode(' ou ', $pr)) ?><?= $ent['trial_days'] > 0 && !$ent['trial_used'] ? ' · essai ' . (int) $ent['trial_days'] . ' j' : '' ?><?php endif; ?></p><?php endif; ?>
          <p class="pl-state <?php /* état technique */ ?><?= $status === 'ready' ? 'ok' : ($status === 'update' || $status === 'newer' ? 'warn' : '') ?>"><?= h(plugin_status_label($status, $row, $m)) ?></p>
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="plugin" value="<?= h($m['id']) ?>">
            <?php if ($status === 'absent' || $status === 'disabled'): ?><button class="btn btn-primary" name="action" value="enable">Activer</button>
            <?php elseif ($status === 'update'): ?><button class="btn btn-primary" name="action" value="update">Mettre à jour</button> <a class="btn" href="<?= h(plugin_page_url($m['id'], 'index')) ?>">Ouvrir</a> <button class="btn" name="action" value="disable" onclick="return confirm('Désactiver ce plugin ?')">Désactiver</button>
            <?php elseif ($status === 'ready'): ?><a class="btn btn-primary" href="<?= h(plugin_page_url($m['id'], 'index')) ?>">Ouvrir</a> <button class="btn" name="action" value="disable" onclick="return confirm('Désactiver ce plugin ? Ses données sont conservées.')">Désactiver</button>
            <?php endif; ?>
          </form>
        </div>
      <?php endforeach; ?>
      </div>
  </div>
</section>
</main>
</body>
</html>
