<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/historique.php';

switch (nt_post_action()) {
    case 'retry': {
        $ok = nt_retry_delivery((int) ($_POST['id'] ?? 0));
        $back = (string) ($_POST['back'] ?? '');
        if (!preg_match('#^/admin/notifications/historique\.php(\?[A-Za-z0-9=&_%-]*)?$#', $back)) $back = $self;
        nt_redirect($back, $ok ? 'Remis en file : il repartira au prochain passage (ou via « Traiter la file » du tableau de bord).' : 'Seuls les envois en échec peuvent être relancés.', $ok ? 'ok' : 'error');
    }
}

$status = (string) ($_GET['status'] ?? '');
$channel = (string) ($_GET['channel'] ?? '');
$topic = (string) ($_GET['topic'] ?? '');
$message = (int) ($_GET['message'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;

$where = [];
$args = [];
if (in_array($status, ['queued', 'sending', 'sent', 'failed', 'skipped'], true)) { $where[] = 'd.status = ?'; $args[] = $status; } else { $status = ''; }
if (isset(nt_channels()[$channel])) { $where[] = 'd.channel = ?'; $args[] = $channel; } else { $channel = ''; }
if ($topic !== '') { $where[] = 'm.topic = ?'; $args[] = $topic; }
if ($message > 0) { $where[] = 'd.message_id = ?'; $args[] = $message; }
if ($q !== '') { $where[] = "u.name LIKE ? ESCAPE '\\'"; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$from = 'FROM nt_deliveries d JOIN nt_messages m ON m.id = d.message_id JOIN nt_users u ON u.id = d.user_id ' . $whereSql;
$st = db()->prepare("SELECT COUNT(*) $from");
$st->execute($args);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);

$st = db()->prepare("SELECT d.*, m.topic, m.subject, u.name AS user_name $from ORDER BY d.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();
$topics = nt_topics();

$qs = fn(array $over) => $self . '?' . http_build_query(array_filter(array_merge(['status' => $status, 'channel' => $channel, 'topic' => $topic, 'message' => $message ?: '', 'q' => $q], $over), fn($v) => $v !== '' && $v !== null));
$back = $qs(['page' => $page > 1 ? $page : '']);

nt_admin_header('Historique des envois', 'historique');
?>
<form method="get" class="nt-actions" style="margin:0 0 16px;">
  <select class="nt-input" name="status" aria-label="Statut">
    <option value="">Tous les statuts</option>
    <?php foreach (['sent' => 'Envoyés', 'failed' => 'Échecs', 'queued' => 'En attente', 'sending' => 'En cours', 'skipped' => 'Ignorés'] as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select>
  <select class="nt-input" name="channel" aria-label="Canal">
    <option value="">Tous les canaux</option>
    <?php foreach (nt_channels() as $k => $d): ?><option value="<?= h($k) ?>"<?= $channel === $k ? ' selected' : '' ?>><?= h($d['label']) ?></option><?php endforeach; ?>
  </select>
  <select class="nt-input" name="topic" aria-label="Rubrique">
    <option value="">Toutes les rubriques</option>
    <?php foreach ($topics as $k => $t): ?><option value="<?= h($k) ?>"<?= $topic === $k ? ' selected' : '' ?>><?= h($t['label']) ?></option><?php endforeach; ?>
    <option value="test"<?= $topic === 'test' ? ' selected' : '' ?>>Messages de test</option>
  </select>
  <input class="nt-input" style="max-width:220px;" type="search" name="q" value="<?= h($q) ?>" placeholder="Nom de l'utilisateur" aria-label="Utilisateur">
  <?php if ($message): ?><input type="hidden" name="message" value="<?= $message ?>"><?php endif; ?>
  <button class="btn-small" type="submit">Filtrer</button>
  <?php if ($status || $channel || $topic || $q || $message): ?><a href="<?= h($self) ?>">Effacer les filtres</a><?php endif; ?>
</form>
<?php if ($message): ?><p class="nt-callout">Envois du message n° <?= $message ?> uniquement.</p><?php endif; ?>

<div class="nt-card">
  <?php if (!$rows): ?>
    <p class="nt-muted">Aucun envoi<?= $where ? ' pour ces filtres' : ' pour l\'instant' ?>.</p>
  <?php else: ?>
  <div class="nt-table-wrap"><table class="nt-table">
    <thead><tr><th>Date</th><th>Utilisateur</th><th>Canal</th><th>Rubrique / objet</th><th>Statut</th><th class="nt-right">Essais</th><th>Détail</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nt-num"><?= h(nt_fr_datetime($r['created_at'])) ?></td>
        <td><?= h($r['user_name']) ?></td>
        <td><?= h(nt_channel_label($r['channel'])) ?><br><span class="nt-muted nt-mono"><?= h(nt_mask_address($r['channel'], $r['address'])) ?></span></td>
        <td><?= h($topics[$r['topic']]['label'] ?? ($r['topic'] === 'test' ? 'Test' : $r['topic'])) ?><?= $r['subject'] !== '' ? '<br><span class="nt-muted" style="font-size:.82rem;">' . h(mb_strimwidth($r['subject'], 0, 60, '…')) . '</span>' : '' ?></td>
        <td><?= nt_status_badge($r['status']) ?><?= (int) $r['rank'] > 0 ? '<br><span class="nt-muted" style="font-size:.74rem;">secours n°' . (int) $r['rank'] . '</span>' : '' ?></td>
        <td class="nt-right nt-num"><?= (int) $r['attempts'] ?></td>
        <td style="max-width:340px;">
          <?php if ($r['status'] === 'sent'): ?><span class="nt-muted" style="font-size:.8rem;"><?= h($r['sent_at'] ? nt_fr_datetime($r['sent_at']) : '') ?></span>
          <?php elseif ($r['status'] === 'queued' && $r['error'] === ''): ?><span class="nt-muted" style="font-size:.8rem;">prévu <?= h(nt_fr_datetime(nt_fmt((int) $r['next_attempt_at']))) ?></span>
          <?php else: ?><span style="font-size:.82rem;"><?= h($r['error']) ?></span><?= $r['status'] === 'queued' ? '<br><span class="nt-muted" style="font-size:.78rem;">nouvel essai ' . h(nt_fr_datetime(nt_fmt((int) $r['next_attempt_at']))) . '</span>' : '' ?>
          <?php endif; ?>
          <?php if ($r['status'] === 'failed'): ?>
            <?= nt_form_open('retry', ['id' => $r['id'], 'back' => $back], '', 'nt-inline') ?><button class="btn-small" type="submit">Réessayer</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="nt-actions">
    <span class="nt-muted"><?= $total ?> envoi(s) · page <?= $page ?> / <?= $pages ?></span>
    <?php if ($page > 1): ?><a class="btn-small" href="<?= h($qs(['page' => $page - 1])) ?>">← Plus récents</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn-small" href="<?= h($qs(['page' => $page + 1])) ?>">Plus anciens →</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<p class="nt-hint">Les adresses sont partiellement masquées. L'historique terminé est supprimé automatiquement après 90 jours par le script d'envoi automatique, ou à la demande depuis le tableau de bord.</p>
<?php nt_admin_footer();
