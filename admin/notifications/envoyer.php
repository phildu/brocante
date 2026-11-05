<?php
require_once __DIR__ . '/../../includes/notifications-layout.php';
nt_admin_init();
$self = '/admin/notifications/envoyer.php';

$topics = nt_topics();
$channels = nt_channels();
$status = nt_channel_status();
$action = nt_post_action();

// Valeurs du formulaire (réaffichées après un aperçu ou une erreur).
$f = [
    'topic' => (string) ($_POST['topic'] ?? array_key_first($topics)),
    'audience' => ($_POST['audience'] ?? 'abonnes') === 'choix' ? 'choix' : 'abonnes',
    'users' => array_values(array_filter(array_map('intval', (array) ($_POST['users'] ?? [])))),
    'channels' => array_values(array_intersect(array_keys($channels), (array) ($_POST['channels'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? [] : array_keys($channels))))),
    'strategy' => ($_POST['strategy'] ?? 'all') === 'fallback' ? 'fallback' : 'all',
    'subject' => trim((string) ($_POST['subject'] ?? '')),
    'body' => trim((string) ($_POST['body'] ?? '')),
    'short_body' => trim((string) ($_POST['short_body'] ?? '')),
];
$plan = null;
$error = null;

if ($action === 'preview' || $action === 'send') {
    if (!isset($topics[$f['topic']])) $error = 'Choisissez une rubrique.';
    elseif ($f['body'] === '') $error = 'Le message est vide.';
    elseif (!$f['channels']) $error = 'Sélectionnez au moins un canal.';
    elseif ($f['audience'] === 'choix' && !$f['users']) $error = 'Sélectionnez au moins un utilisateur.';
    else {
        $ids = $f['audience'] === 'choix' ? $f['users'] : null; // null = tous : seuls les abonnés à la rubrique seront retenus
        $opts = ['channels' => $f['channels'], 'strategy' => $f['strategy'], 'short_body' => $f['short_body']];
        if ($action === 'send') {
            $r = nt_notify($f['topic'], $ids ?? array_keys(nt_users_load(null)), $f['subject'], $f['body'], $opts);
            if ($r['error']) nt_redirect($self, $r['error'], 'error');
            if ($r['queued'] === 0) nt_redirect($self, 'Personne à notifier : aucun destinataire n\'est abonné à cette rubrique sur les canaux choisis.', 'error');
            $p = $r['processed'] ?? ['sent' => 0, 'failed' => 0, 'retry' => 0, 'skipped' => 0, 'deferred' => 0];
            $left = max(0, $r['queued'] - $p['sent'] - $p['failed'] - $p['skipped']);
            $msg = sprintf('Message envoyé à %d personne(s) : %d livré(s)', $r['recipients'], $p['sent']);
            if ($p['failed']) $msg .= sprintf(', %d en échec', $p['failed']);
            if ($left) $msg .= sprintf(', %d en attente (reprises, heures calmes ou volume) — ils partiront au prochain passage du cron ou via « Traiter la file » du tableau de bord', $left);
            nt_redirect('/admin/notifications/historique.php?message=' . (int) $r['message_id'], $msg . '.', $p['failed'] ? 'error' : 'ok');
        }
        $plan = nt_plan($f['topic'], $ids, $opts);
    }
}

$allUsers = nt_users_load(null);

/** Nombre de segments SMS approximatif (GSM-7 : 160/153 ; sinon UCS-2 : 70/67). */
function nt_sms_info(string $text): string
{
    $len = mb_strlen($text);
    if ($len === 0) return '';
    $gsm = (bool) preg_match('/^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&\'()*+,\-.\/:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\\\\[~\]|€]*$/u', $text);
    [$single, $multi] = $gsm ? [160, 153] : [70, 67];
    $segments = $len <= $single ? 1 : (int) ceil($len / $multi);
    return $len . ' caractères · environ ' . $segments . ' SMS' . ($gsm ? '' : ' (caractères spéciaux : 70 par SMS)');
}

nt_admin_header('Envoyer un message', 'envoyer');
?>
<?php if ($error): ?><p class="publish-status nt-flash" role="alert" data-kind="error"><?= h($error) ?></p><?php endif; ?>

<form method="post" class="nt-card" id="compose">
  <?= csrf_input() ?>
  <div class="nt-form-grid">
    <div class="field"><label for="topic">Rubrique</label>
      <select class="nt-input" id="topic" name="topic">
        <?php foreach ($topics as $k => $t): ?><option value="<?= h($k) ?>"<?= $f['topic'] === $k ? ' selected' : '' ?>><?= h($t['label']) ?><?= $t['is_marketing'] ? ' — marketing (abonnés seulement)' : '' ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label for="strategy">Stratégie</label>
      <select class="nt-input" id="strategy" name="strategy">
        <option value="all"<?= $f['strategy'] === 'all' ? ' selected' : '' ?>>Tous les canaux choisis par la personne</option>
        <option value="fallback"<?= $f['strategy'] === 'fallback' ? ' selected' : '' ?>>Son canal préféré, les autres en secours</option>
      </select></div>
  </div>

  <h3 class="nt-h2" style="font-size:1rem;margin:20px 0 6px;">Destinataires</h3>
  <label class="nt-check"><input type="radio" name="audience" value="abonnes"<?= $f['audience'] === 'abonnes' ? ' checked' : '' ?>> Tous les utilisateurs abonnés à cette rubrique (<?= count($allUsers) ?> utilisateur(s) au total)</label>
  <label class="nt-check" style="margin-top:6px;"><input type="radio" name="audience" value="choix"<?= $f['audience'] === 'choix' ? ' checked' : '' ?>> Seulement ceux que je choisis :</label>
  <div class="nt-pick" style="margin-top:8px;">
    <?php foreach ($allUsers as $uid => $u): ?>
      <label class="nt-check"><input type="checkbox" name="users[]" value="<?= $uid ?>"<?= in_array($uid, $f['users'], true) ? ' checked' : '' ?>> <?= h($u['name']) ?></label>
    <?php endforeach; ?>
    <?php if (!$allUsers): ?><span class="nt-muted">Aucun utilisateur.</span><?php endif; ?>
  </div>

  <h3 class="nt-h2" style="font-size:1rem;margin:20px 0 6px;">Canaux autorisés pour cet envoi</h3>
  <div class="nt-actions" style="margin-top:0;gap:6px 20px;">
    <?php foreach ($channels as $k => $d): ?>
      <label class="nt-check"><input type="checkbox" name="channels[]" value="<?= h($k) ?>"<?= in_array($k, $f['channels'], true) ? ' checked' : '' ?>> <?= h($d['label']) ?><?= $status[$k]['ready'] || $k === 'discord' ? '' : ' <span class="nt-muted">(non configuré)</span>' ?></label>
    <?php endforeach; ?>
  </div>

  <h3 class="nt-h2" style="font-size:1rem;margin:20px 0 6px;">Message</h3>
  <div class="nt-field"><label for="subject">Objet (e-mail) / titre</label><input class="nt-input" id="subject" name="subject" value="<?= h($f['subject']) ?>" maxlength="200"></div>
  <div class="nt-field"><label for="body">Texte</label><textarea class="nt-input" id="body" name="body" rows="7" required><?= h($f['body']) ?></textarea>
    <div class="nt-counter">Variables : <code class="nt-code">{prenom}</code> <code class="nt-code">{nom}</code> <code class="nt-code">{site}</code> <code class="nt-code">{lien_preferences}</code>. Texte brut ; les liens deviennent cliquables par e-mail.</div></div>
  <div class="nt-field"><label for="short_body">Version courte pour SMS, WhatsApp, Discord, Telegram (facultatif)</label><textarea class="nt-input" id="short_body" name="short_body" rows="3" maxlength="1500"><?= h($f['short_body']) ?></textarea>
    <div class="nt-counter" id="sms-counter"><?= h(nt_sms_info($f['short_body'] !== '' ? $f['short_body'] : $f['body'])) ?></div>
    <div class="nt-counter">Vide : ces canaux reçoivent le titre puis le texte complet. Un SMS long est facturé par tranche de 153 caractères.</div></div>

  <div class="nt-actions">
    <button class="btn" type="submit" name="action" value="preview">Voir qui recevra quoi</button>
    <?php if ($plan && $plan['recipients'] > 0): ?>
      <button class="btn btn-primary" type="submit" name="action" value="send" onclick="return confirm('Envoyer ce message à <?= (int) $plan['recipients'] ?> personne(s) ? Un envoi ne s\'annule pas.');">Envoyer à <?= (int) $plan['recipients'] ?> personne(s)</button>
    <?php endif; ?>
  </div>
</form>

<?php if ($plan): $topic = $topics[$f['topic']]; ?>
<div class="nt-card">
  <h2 class="nt-h2">Aperçu de la diffusion</h2>
  <?php if ($plan['recipients'] === 0): ?>
    <p class="nt-callout">Personne ne recevrait ce message<?= $topic['is_marketing'] ? ' : aucun des destinataires n\'est abonné à cette rubrique marketing sur les canaux choisis' : '' ?>. Voir les raisons ci-dessous.</p>
  <?php else: ?>
    <p><strong><?= (int) $plan['recipients'] ?></strong> personne(s) seront notifiées<?= $plan['none'] ? ', <strong>' . (int) $plan['none'] . '</strong> ne le seront pas' : '' ?>.</p>
    <div class="nt-preview">
      <?php foreach ($plan['counts'] as $ch => $n): if ($n === 0) continue; ?>
        <div class="cell"><div class="n"><?= (int) $n ?></div><?= h($channels[$ch]['label']) ?></div>
      <?php endforeach; ?>
    </div>
    <?php if ($f['strategy'] === 'fallback'): ?><p class="nt-hint">Chiffres pour le canal préféré de chacun ; les autres ne servent qu'en cas d'échec.</p><?php endif; ?>
    <?php if ($topic['is_marketing'] && ($plan['counts']['sms'] > 0 || $plan['counts']['whatsapp'] > 0)): ?>
      <p class="nt-hint">Les SMS et WhatsApp marketing partent hors des heures calmes (<?= (int) nt_cfg('quiet.end') ?> h – <?= (int) nt_cfg('quiet.start') ?> h<?= nt_cfg('quiet.sunday') ? ', hors dimanche' : '' ?>) : s'il est trop tard, ils attendent le prochain créneau.</p>
    <?php endif; ?>
  <?php endif; ?>

  <?php $excluded = []; foreach ($plan['users'] as $p) foreach ($p['skipped'] as [$ch, $why]) $excluded[] = [$p['user']['name'], $ch, $why]; ?>
  <?php if ($excluded): ?>
    <details><summary class="nt-muted" style="cursor:pointer;"><?= count($excluded) ?> exclusion(s) (canal ou personne)</summary>
      <div class="nt-table-wrap"><table class="nt-table"><thead><tr><th>Utilisateur</th><th>Canal</th><th>Raison</th></tr></thead><tbody>
        <?php foreach (array_slice($excluded, 0, 100) as [$name, $ch, $why]): ?>
          <tr><td><?= h($name) ?></td><td><?= h($ch === '*' ? '—' : nt_channel_label($ch)) ?></td><td><?= h($why) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <?php if (count($excluded) > 100): ?><p class="nt-muted">… et <?= count($excluded) - 100 ?> autres.</p><?php endif; ?>
    </details>
  <?php endif; ?>

  <?php $sample = null; foreach ($plan['users'] as $p) if ($p['targets']) { $sample = $p; break; } ?>
  <?php if ($sample): $su = $sample['user']; $mkt = (bool) $topic['is_marketing']; ?>
    <h3 class="nt-h2" style="font-size:1rem;margin:16px 0 6px;">Exemple de message reçu par <?= h($su['name']) ?></h3>
    <?php foreach (array_slice($sample['targets'], 0, 2) as $tg):
        $r = nt_render(['subject' => $f['subject'], 'body' => $f['body'], 'short_body' => $f['short_body']], $su, $topic, $tg['channel']); ?>
      <p class="nt-muted" style="margin:10px 0 4px;"><?= h(nt_channel_label($tg['channel'])) ?><?= $tg['channel'] === 'email' ? ' — objet : <strong>' . h($r['subject']) . '</strong>' : '' ?></p>
      <pre class="nt-code" style="white-space:pre-wrap;"><?= h($r['text']) ?></pre>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php endif; ?>

<script>
// Compteur de caractères de la version courte (ou du texte complet à défaut).
(function () {
  var s = document.getElementById('short_body'), b = document.getElementById('body'), c = document.getElementById('sms-counter');
  function upd() { var t = s.value || b.value, n = t.length; c.textContent = n ? n + ' caractères · environ ' + Math.ceil(n / (n <= 160 ? 160 : 153)) + ' SMS' : ''; }
  s.addEventListener('input', upd); b.addEventListener('input', upd);
})();
</script>
<?php nt_admin_footer();
