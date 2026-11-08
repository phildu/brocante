<?php
// Force de vente : accès à l'extension (essai, abonnement) et entrée vers le carnet de prospects de la boutique (admin/vente/prospects.php).
// Le carnet vit dans data/vente/boutiques/<boutique>.db (includes/vente-scope.php), séparé de toutes les autres boutiques.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';
require_once dirname(__DIR__, 3) . '/includes/vente-scope.php';
$ent = addon_entitlement('force-de-vente');
$count = null;
$carnet = vs_boutique_db_file(tenant_slug());
if ($ent['entitled'] && $carnet !== '' && is_file($carnet)) {
    try {
        $pdo = new PDO('sqlite:' . $carnet, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $count = (int) $pdo->query('SELECT COUNT(*) FROM prospects')->fetchColumn();
    } catch (Throwable $e) {
        $count = null;
    }
}
$accountProspects = 0;
try {
    $accountProspects = (int) db()->query("SELECT COUNT(*) FROM accounts WHERE role = 'prospect'")->fetchColumn();
} catch (Throwable $e) {
}
?>
<div style="display:grid;gap:16px;max-width:900px;">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Force de vente</h1>
  <?= addon_panel_html('force-de-vente', $pluginApi('access'), $pluginCsrf) ?>
  <?php if (!$ent['entitled']): ?>
    <?= addon_locked_html('force-de-vente') ?>
  <?php else: ?>
  <p class="hint" style="max-width:66ch;">Votre carnet de prospects : fiches et champs personnalisés (emplacement, Instagram…), import d'un fichier Excel, CSV ou base SQLite, tournée sur carte et journal des échanges. Il est à vous : aucune autre boutique ne le voit.</p>
  <section style="border:1px solid var(--line);padding:14px 16px;display:grid;gap:10px;">
    <h2 style="margin:0;font-size:1.05rem;">Votre carnet</h2>
    <p style="margin:0;"><?= $count === null ? 'Pas encore ouvert.' : (int) $count . ' prospect' . ($count > 1 ? 's' : '') . ' dans le carnet.' ?>
      <?php if ($count === null && $accountProspects > 0): ?>Les <?= $accountProspects ?> compte<?= $accountProspects > 1 ? 's' : '' ?> « prospect » de la boutique y seront repris à la première ouverture (les comptes ne sont pas modifiés).<?php endif; ?></p>
    <p style="margin:0;display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn-small" href="vente/prospects.php">Ouvrir la force de vente</a>
      <a class="btn-small" href="vente/prospects-import.php">Importer un fichier</a>
      <a class="btn-small" href="vente/tournees.php">Tournée sur carte</a>
    </p>
  </section>
  <?php endif; ?>
</div>
