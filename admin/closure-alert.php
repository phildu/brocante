<?php
// Prévenir les clients et visiteurs référencés d'une fermeture exceptionnelle, d'un jour férié ou de congés (includes/closure-alerts.php).
// Jamais automatique : le vendeur relit le message, choisit le public et confirme. Droit exigé : Notifications · Envoyer (la page liste des
// personnes et leurs coordonnées).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/closure-alerts.php';
require_admin();
require_permission('portail.notifications.envoyer');

$back = '/admin/index.php?s=pages&p=horaires';
$key = (string) ($_GET['closure'] ?? $_POST['closure'] ?? '');
$closure = closure_alert_find($key);
if (!$closure) {
    flash_set('Fermeture introuvable : enregistrez-la d’abord dans les horaires.', 'error');
    header('Location: ' . $back);
    exit;
}
$self = '/admin/closure-alert.php?closure=' . rawurlencode($key);
$log = closure_alert_log()[$key] ?? null;
$default = closure_alert_message($closure);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    auth_require_action('closure-alert.php', (string) ($_POST['action'] ?? ''));
    $actor = (string) (admin_session()['name'] ?? '') ?: 'administrateur';
    $r = closure_alert_send($key, array_map('strval', (array) ($_POST['groups'] ?? [])), trim((string) ($_POST['subject'] ?? '')),
        (string) ($_POST['body'] ?? ''), (string) ($_POST['short_body'] ?? ''), $actor, !empty($_POST['force']));
    if ($r['error']) {
        flash_set($r['error'], 'error');
        header('Location: ' . $self);
        exit;
    }
    $p = $r['processed'] ?? ['sent' => 0, 'failed' => 0];
    $msg = 'Alerte envoyée à ' . (int) $r['recipients'] . ' personne(s) : ' . (int) $p['sent'] . ' message(s) livré(s)';
    if (!empty($p['failed'])) $msg .= ', ' . (int) $p['failed'] . ' en échec';
    $left = max(0, (int) $r['queued'] - (int) $p['sent'] - (int) ($p['failed'] ?? 0) - (int) ($p['skipped'] ?? 0));
    if ($left > 0) $msg .= ', ' . $left . ' en attente (reprises ou volume : ils partiront au prochain passage du cron ou via « Traiter la file » dans Notifications)';
    if ($r['imported'] > 0) $msg .= '. ' . (int) $r['imported'] . ' contact(s) ajouté(s) au module Notifications';
    flash_set($msg . '.', !empty($p['failed']) ? 'error' : 'ok');
    header('Location: ' . $back);
    exit;
}

$content = get_content();
$flash = flash_get();
$audience = closure_alert_audience();
$channels = nt_channel_status();
$readyChannels = array_filter($channels, static fn ($c) => $c['ready']);
$type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture exceptionnelle';
$upcoming = closure_alert_is_upcoming($closure);
$form = [
    'subject' => trim((string) ($_POST['subject'] ?? '')) ?: $default['subject'],
    'body' => $default['body'],
    'short_body' => $default['short_body'],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Prévenir les clients — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .ca-groups { display: grid; gap: 8px; margin: 8px 0 16px; }
  .ca-groups label { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1px solid var(--line); background: var(--surface); }
  .ca-groups small { display: block; color: var(--ink-soft); }
  .ca-box { border: 1px solid var(--line); background: var(--surface); padding: 12px 14px; margin: 12px 0; }
  .ca-warn { border-color: #e0b4b4; background: #fff7f6; }
  textarea.ca-text { width: 100%; min-height: 210px; }
  textarea.ca-short { width: 100%; min-height: 70px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'reglages-pages'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap" style="max-width:760px;">
    <p class="eyebrow">Horaires &amp; fermetures</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Prévenir les clients</h1>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>

    <div class="ca-box">
      <strong><?= h($type) ?></strong> · <?= h(closure_alert_range_text($closure)) ?><?= trim((string) $closure['label']) !== '' ? ' · ' . h($closure['label']) : '' ?>
      <p class="hint" style="margin:6px 0 0;">Cette fermeture s’affiche déjà en haut du site. Ce message prévient en plus, directement, les personnes référencées.</p>
    </div>

    <?php if (!$upcoming): ?>
      <p class="publish-status" data-kind="error">Cette fermeture est terminée : il n’y a plus rien à annoncer.</p>
      <p><a class="btn" href="<?= h($back) ?>">← Retour aux horaires</a></p>
    <?php else: ?>

    <?php if ($log): ?>
      <p class="ca-box ca-warn">Les clients ont déjà été prévenus le <strong><?= h(date('d/m/Y à H:i', (int) $log['at'])) ?></strong> (<?= (int) $log['recipients'] ?> personne(s)). Cochez « Renvoyer quand même » ci-dessous pour envoyer de nouveau.</p>
    <?php endif; ?>
    <?php if (!$readyChannels): ?>
      <p class="ca-box ca-warn">Aucun canal d’envoi n’est configuré : <a href="/admin/notifications/canaux.php">réglez au moins l’e-mail dans Notifications → Canaux &amp; réglages</a> avant d’envoyer.</p>
    <?php else: ?>
      <p class="hint">Canaux prêts : <?= h(implode(', ', array_map(static fn ($c) => $c['label'], $readyChannels))) ?>. Chaque personne reçoit <strong>un seul message</strong>, sur son canal préféré (le suivant n’est utilisé qu’en cas d’échec), et peut se retirer de ces alertes depuis le lien de son message.</p>
    <?php endif; ?>

    <form method="post" id="alert-form">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <input type="hidden" name="action" value="send">
      <input type="hidden" name="closure" value="<?= h($key) ?>">

      <h2 style="font-size:1.15rem;">Qui prévenir ?</h2>
      <div class="ca-groups">
        <?php foreach ($audience as $gKey => $g): $count = $g['existing'] + $g['to_import']; ?>
          <label>
            <input type="checkbox" name="groups[]" value="<?= h($gKey) ?>" data-count="<?= (int) $count ?>"<?= $count > 0 ? ' checked' : '' ?>>
            <span><strong><?= h($g['label']) ?></strong> — <?= (int) $count ?> contact(s)
              <?php if ($g['to_import'] > 0): ?><small><?= (int) $g['to_import'] ?> seront ajoutés à la liste des contacts (module Notifications) au moment de l’envoi, sans abonnement commercial.</small><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="hint">Les bénévoles de la buvette ne sont jamais prévenus. Les personnes qui se sont retirées des alertes de fermeture, ou sans canal actif, sont ignorées.</p>

      <h2 style="font-size:1.15rem;">Message</h2>
      <div class="field"><label for="ca-subject">Objet (e-mail)</label><input type="text" id="ca-subject" name="subject" maxlength="200" value="<?= h($form['subject']) ?>"></div>
      <div class="field"><label for="ca-body">Texte</label><textarea id="ca-body" class="ca-text" name="body" maxlength="5000"><?= h($form['body']) ?></textarea></div>
      <div class="field"><label for="ca-short">Version courte (SMS, WhatsApp, Discord, Telegram)</label><textarea id="ca-short" class="ca-short" name="short_body" maxlength="600"><?= h($form['short_body']) ?></textarea>
        <p class="hint" style="margin:4px 0 0;">Variables : <code>{prenom}</code> <code>{nom}</code> <code>{site}</code> <code>{lien_preferences}</code>.</p></div>

      <?php if ($log): ?><label style="display:flex;gap:8px;align-items:center;margin:10px 0;"><input type="checkbox" name="force" value="1" id="ca-force"> Renvoyer quand même</label><?php endif; ?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
        <button type="submit" class="btn btn-primary"<?= $readyChannels ? '' : ' disabled' ?>>Envoyer l’alerte</button>
        <a class="btn" href="<?= h($back) ?>">Annuler</a>
      </div>
    </form>
    <script>
    (function () {
      var form = document.getElementById('alert-form');
      form.addEventListener('submit', function (e) {
        var n = 0;
        form.querySelectorAll('input[name="groups[]"]:checked').forEach(function (c) { n += parseInt(c.dataset.count, 10) || 0; });
        if (!n) { alert('Choisissez au moins un public à prévenir.'); e.preventDefault(); return; }
        if (!confirm('Envoyer cette alerte à environ ' + n + ' contact(s) ?\nLe message part tout de suite et ne peut pas être rappelé.')) e.preventDefault();
      });
    })();
    </script>
    <?php endif; ?>
  </div>
</section>
</main>
</body>
</html>
