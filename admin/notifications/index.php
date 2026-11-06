<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/index.php';

switch (nt_post_action()) {
    case 'process_queue': {
        $s = nt_process_queue(100, 20.0);
        $msg = sprintf('File traitée : %d envoyé(s), %d échec(s), %d à réessayer, %d ignoré(s).', $s['sent'], $s['failed'], $s['retry'], $s['skipped']);
        nt_redirect($self, $msg, $s['failed'] > 0 ? 'error' : 'ok');
    }

    case 'purge': {
        $days = max(7, min(730, (int) ($_POST['days'] ?? 90)));
        $n = nt_purge($days);
        nt_redirect($self, $n . ' envoi(s) de plus de ' . $days . ' jours supprimé(s) de l\'historique.');
    }
}

$stats = nt_stats(7);
$channels = nt_channel_status();
$configured = count(array_filter(array_column($channels, 'ready')));
$lastRun = (int) nt_meta_get('worker_last_run', '0');
$workerPath = dirname(__DIR__, 2) . '/scripts/notifications-worker.php';

nt_admin_header('Tableau de bord', 'dashboard');
?>
<div class="nt-stats">
  <div class="nt-stat <?= $stats['users'] > 0 ? '' : 'is-bad' ?>">
    <div class="label">Utilisateurs actifs</div>
    <div class="value"><?= (int) $stats['users'] ?></div>
    <div class="sub"><?= $stats['without_channel'] ? (int) $stats['without_channel'] . ' sans canal actif' : 'tous joignables' ?></div>
  </div>
  <div class="nt-stat <?= $configured > 0 ? 'is-good' : 'is-bad' ?>">
    <div class="label">Canaux configurés</div>
    <div class="value"><?= $configured ?> / <?= count($channels) ?></div>
    <div class="sub"><a href="/admin/notifications/canaux.php">Régler les canaux</a></div>
  </div>
  <div class="nt-stat <?= $stats['pending'] > 0 ? 'is-bad' : '' ?>">
    <div class="label">En attente</div>
    <div class="value"><?= (int) $stats['pending'] ?></div>
    <div class="sub">envois non partis</div>
  </div>
  <div class="nt-stat">
    <div class="label">Envoyés (7 jours)</div>
    <div class="value"><?= (int) $stats['sent'] ?></div>
  </div>
  <div class="nt-stat <?= $stats['failed'] > 0 ? 'is-bad' : '' ?>">
    <div class="label">Échecs (7 jours)</div>
    <div class="value"><?= (int) $stats['failed'] ?></div>
    <div class="sub"><?= $stats['failed'] ? '<a href="/admin/notifications/historique.php?status=failed">Voir les échecs</a>' : 'aucun' ?></div>
  </div>
</div>

<div class="nt-card">
  <h2 class="nt-h2">À traiter</h2>
  <ul class="nt-todo">
    <?php $todo = 0; ?>
    <?php if ($configured === 0): $todo++; ?>
      <li>⚠️ <span>Aucun canal n'est configuré — <a href="/admin/notifications/canaux.php">renseignez au moins l'e-mail</a> pour pouvoir envoyer.</span></li>
    <?php endif; ?>
    <?php if ($stats['users'] === 0): $todo++; ?>
      <li>👥 <span>Aucun utilisateur — <a href="/admin/notifications/utilisateurs.php">ajoutez-en ou importez vos bénévoles et clients</a>.</span></li>
    <?php endif; ?>
    <?php if ($stats['pending'] > 0): $todo++; ?>
      <li>⏳ <span><?= (int) $stats['pending'] ?> envoi(s) en attente (reprises, heures calmes ou gros envoi). Ils partent seuls si le cron est en place, sinon :</span>
        <?= nt_form_open('process_queue', [], '', 'nt-inline') ?><button class="btn-small" type="submit">Traiter la file maintenant</button></form></li>
    <?php endif; ?>
    <?php if ($stats['failed'] > 0): $todo++; ?>
      <li>❌ <span><?= (int) $stats['failed'] ?> échec(s) sur 7 jours — <a href="/admin/notifications/historique.php?status=failed">voir le détail et réessayer</a>.</span></li>
    <?php endif; ?>
    <?php if ($stats['without_channel'] > 0 && $stats['users'] > 0): $todo++; ?>
      <li>📭 <span><?= (int) $stats['without_channel'] ?> utilisateur(s) sans canal actif : <a href="/admin/notifications/utilisateurs.php">compléter leurs coordonnées</a>.</span></li>
    <?php endif; ?>
    <?php if ($lastRun === 0 || $lastRun < nt_now() - 2 * 86400): $todo++; ?>
      <li>🕒 <span><?= $lastRun === 0 ? 'Le script d\'envoi automatique (cron) n\'a jamais tourné' : 'Le cron n\'a pas tourné depuis le ' . h(nt_fr_datetime(nt_fmt($lastRun))) ?> — voir « Envoi automatique » ci-dessous. Sans lui, les reprises et envois différés n'ont lieu que via le bouton.</span></li>
    <?php endif; ?>
    <?php if ($todo === 0): ?><li><span class="nt-muted">Rien à signaler.</span></li><?php endif; ?>
  </ul>
</div>

<div class="nt-card">
  <h2 class="nt-h2">Canaux</h2>
  <div class="nt-table-wrap"><table class="nt-table">
    <thead><tr><th>Canal</th><th>État</th><th class="nt-right">Envoyés / échecs (7 j)</th></tr></thead>
    <tbody>
    <?php foreach ($channels as $key => $c): $bc = $stats['by_channel'][$key] ?? []; ?>
      <tr>
        <td><strong><?= h($c['label']) ?></strong></td>
        <td><?= $c['ready'] ? nt_badge('Prêt', 'ok') : ($c['webhook_only'] ? nt_badge('Webhooks seulement', 'warn') : nt_badge('Non configuré', 'warn')) ?></td>
        <td class="nt-right nt-num"><?= (int) ($bc['sent'] ?? 0) ?> / <?= (int) ($bc['failed'] ?? 0) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="nt-card" id="envoi-automatique">
  <h2 class="nt-h2">Envoi automatique (cron)</h2>
  <p class="nt-hint">Les petits envois partent tout de suite. Les reprises après une erreur, les SMS/WhatsApp marketing différés aux heures calmes et les gros envois passent par une file que ce script vide. Programmez-le toutes les 5 minutes (tâche CRON dans l'espace OVH, ou <code class="nt-code">crontab -e</code> en SSH) :</p>
  <pre class="nt-code">*/5 * * * * php <?= h($workerPath) ?> --quiet</pre>
  <p class="nt-hint">Dernier passage : <strong><?= $lastRun ? h(nt_fr_datetime(nt_fmt($lastRun))) : 'jamais' ?></strong>. Le script nettoie aussi l'historique de plus de 90 jours.</p>
  <div class="nt-actions">
    <?= nt_form_open('process_queue', [], '', 'nt-inline') ?><button class="btn" type="submit">Traiter la file maintenant</button></form>
    <?= nt_form_open('purge', ['days' => 90], 'Supprimer de l\'historique les envois terminés de plus de 90 jours ?', 'nt-inline') ?><button class="btn-small nt-btn-danger" type="submit">Purger l'historique (&gt; 90 jours)</button></form>
  </div>
</div>
<?php nt_admin_footer();
