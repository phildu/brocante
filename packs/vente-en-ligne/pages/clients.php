<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vel.php';

$role = in_array($_GET['role'] ?? '', ['client', 'prospect'], true) ? (string) $_GET['role'] : null;
$q = trim((string) ($_GET['q'] ?? ''));
$counts = accounts_counts();
$rows = array_values(array_filter(accounts_list($role, $q), static fn (array $a): bool => !account_is_staff((string) $a['role'])));
$notes = vel_note_map();
$flash = flash_get();

$paidCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'paid'")->fetchColumn();
$paidSum = (int) db()->query("SELECT COALESCE(SUM(amount_total), 0) FROM orders WHERE status = 'paid'")->fetchColumn();
$pendingCount = (int) db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$noteCount = count($notes);
$average = $paidCount > 0 ? intdiv($paidSum, $paidCount) : 0;

$lastOrders = [];
foreach (db()->query("SELECT id, email, amount_total, created_at, status, fulfillment_status FROM orders WHERE email <> '' ORDER BY created_at DESC, id DESC LIMIT 500") as $o) {
    $email = vel_email((string) $o['email']);
    if (count($lastOrders[$email] ?? []) < 3) $lastOrders[$email][] = $o;
}
?>
<link rel="stylesheet" href="<?= h($pluginAsset('vel.css')) ?>">
<div id="vel-page" data-api="<?= h($pluginApi('action')) ?>" data-csrf="<?= h($pluginCsrf) ?>">
  <p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
  <h1 style="font-size:1.8rem;margin:12px 0 4px;">Clients (CRM)</h1>
  <p class="hint" style="max-width:78ch;">
    Vos clients et prospects, rapprochés des commandes par e-mail. Notez chaque contact (préférences, échanges, litiges) :
    la note reste interne. Les comptes de l'équipe et les mots de passe se gèrent dans
    <a href="/admin/accounts.php">Comptes</a> ; un client est créé automatiquement à chaque commande payée.
  </p>

  <?php if ($flash): ?>
    <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:14px 0;"><?= h($flash['message']) ?></p>
  <?php endif; ?>
  <p class="vel-msg" id="vel-msg" role="status"></p>

  <div class="vel-kpis">
    <div class="vel-kpi"><span>Clients</span><strong><?= (int) $counts['client'] ?></strong></div>
    <div class="vel-kpi"><span>Prospects</span><strong><?= (int) $counts['prospect'] ?></strong></div>
    <div class="vel-kpi"><span>CA payé</span><strong><?= h(format_cents($paidSum)) ?></strong></div>
    <div class="vel-kpi"><span>Panier moyen</span><strong><?= h(format_cents($average)) ?></strong></div>
    <div class="vel-kpi"><span>Notes</span><strong><?= $noteCount ?></strong></div>
    <?php if ($pendingCount): ?><div class="vel-kpi"><span>En attente</span><strong><?= $pendingCount ?></strong></div><?php endif; ?>
  </div>

  <div class="vel-actions" style="margin:14px 0;">
    <form data-vel-form="sync_clients"><button type="submit" class="btn-small" title="Ajoute une fiche client pour chaque adresse e-mail ayant une commande payée">Importer les clients depuis les commandes</button></form>
    <a class="btn-small" href="/admin/accounts-action.php?export=1&amp;role=client">Exporter les contacts (CSV)</a>
    <a class="btn-small" href="/admin/accounts.php">Tous les comptes (équipe, mots de passe)</a>
  </div>

  <nav class="vel-tabs" aria-label="Filtrer par rôle">
    <a href="<?= h(vel_page_url($plugin, 'clients', $q !== '' ? ['q' => $q] : [])) ?>"<?= $role === null ? ' aria-current="page"' : '' ?>>Contacts<small><?= (int) $counts['client'] + (int) $counts['prospect'] ?></small></a>
    <a href="<?= h(vel_page_url($plugin, 'clients', ['role' => 'client'] + ($q !== '' ? ['q' => $q] : []))) ?>"<?= $role === 'client' ? ' aria-current="page"' : '' ?>>Clients<small><?= (int) $counts['client'] ?></small></a>
    <a href="<?= h(vel_page_url($plugin, 'clients', ['role' => 'prospect'] + ($q !== '' ? ['q' => $q] : []))) ?>"<?= $role === 'prospect' ? ' aria-current="page"' : '' ?>>Prospects<small><?= (int) $counts['prospect'] ?></small></a>
  </nav>

  <form method="get" action="" class="vel-form" role="search" style="margin-top:0;">
    <input type="hidden" name="p" value="<?= h($plugin['id']) ?>">
    <input type="hidden" name="v" value="clients">
    <?php if ($role): ?><input type="hidden" name="role" value="<?= h($role) ?>"><?php endif; ?>
    <label>Rechercher <input type="search" name="q" value="<?= h($q) ?>" placeholder="nom, e-mail, téléphone…" style="min-width:240px;"></label>
    <button type="submit" class="btn-small">Rechercher</button>
    <?php if ($q !== ''): ?><a class="btn-small" href="<?= h(vel_page_url($plugin, 'clients', $role ? ['role' => $role] : [])) ?>">Effacer</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <p class="empty-state" style="margin-top:16px;"><?= $q !== '' ? 'Aucun contact ne correspond à cette recherche.' : 'Aucun contact pour l\'instant — les clients apparaissent à leur première commande payée.' ?></p>
  <?php else: ?>
    <div style="overflow-x:auto;margin-top:12px;">
      <table class="vel">
        <thead><tr><th>Contact</th><th>Rôle</th><th class="r">Commandes</th><th class="hide-sm">Dernière</th><th>Note interne</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a):
          $email = vel_email((string) $a['email']);
          $note = $notes[$email] ?? null;
          $recent = $lastOrders[$email] ?? [];
        ?>
          <tr>
            <td>
              <strong><?= h($a['name'] !== '' ? $a['name'] : $a['email']) ?></strong>
              <small><?= $a['name'] !== '' ? h($a['email']) : '' ?><?= $a['phone'] !== '' ? ' · ' . h($a['phone']) : '' ?></small>
              <small><a href="mailto:<?= h($a['email']) ?>">Écrire</a> · <a href="/admin/accounts.php?edit=<?= (int) $a['id'] ?>">Modifier la fiche</a></small>
              <?php if ($recent): ?>
                <details><summary>Dernières commandes</summary>
                  <ul>
                    <?php foreach ($recent as $o): ?>
                      <li>#<?= (int) $o['id'] ?> · <?= h(date('d/m/Y', strtotime($o['created_at']))) ?> · <?= h(format_cents((int) $o['amount_total'])) ?> · <?= $o['status'] === 'paid' ? h(fulfillment_label((string) $o['fulfillment_status'])) : 'En attente' ?></li>
                    <?php endforeach; ?>
                  </ul>
                </details>
              <?php endif; ?>
            </td>
            <td><span class="badge"><?= h(account_role_label((string) $a['role'])) ?></span><?= $a['is_active'] ? '' : '<small>Désactivé</small>' ?></td>
            <td class="r"><?= (int) $a['orders_count'] ?><small><?= h(format_cents((int) $a['orders_total'])) ?></small></td>
            <td class="hide-sm"><?= $recent ? h(date('d/m/Y', strtotime($recent[0]['created_at']))) : '—' ?></td>
            <td style="min-width:260px;">
              <form data-vel-form="client_note" data-vel-stay="1">
                <input type="hidden" name="email" value="<?= h($email) ?>">
                <textarea class="vel-note" name="note" rows="2" maxlength="2000" placeholder="Préférences, échanges, points à suivre…"><?= h((string) ($note['note'] ?? '')) ?></textarea>
                <div class="vel-actions" style="margin-top:6px;">
                  <button type="submit" class="btn-small">Enregistrer la note</button>
                  <?php if ($note): ?><small style="font-size:.72rem;">mise à jour le <?= h(date('d/m/Y', strtotime($note['updated_at']))) ?></small><?php endif; ?>
                </div>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<script src="<?= h($pluginAsset('vel.js')) ?>" defer></script>
