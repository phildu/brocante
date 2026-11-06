<?php
// Messagerie interne de la galerie : boîte de réception des conversations avec les exposants et les personnes
// contactées, réponse, ouverture d'une nouvelle conversation. Les messages vivent dans data/galeries/<id>.messages.json.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-messages.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';

$actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
$self = '/portail/galerie-messages.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$threadId = (string) ($_GET['i'] ?? $_POST['i'] ?? '');
$linkTo = static fn (string $id): string => $self . (str_contains($self, '?') ? '&' : '?') . 'i=' . urlencode($id);
$newSlot = (string) ($_GET['new'] ?? '');
$newSlot = str_starts_with($newSlot, 'slot:') ? substr($newSlot, 5) : '';
$newRow = $newSlot !== '' ? gb_row($gaGallery, $newSlot) : null;

if (!gt_feature($gaGallery, 'messages')) {
    ga_head('Messages');
    ?>
    <section class="gcard">
      <h2>Fonctionnalité absente de ce type de galerie</h2>
      <p class="meta" style="margin:0;">La messagerie interne se règle dans <?= $limited ? 'les réglages du type de galerie' : '<a href="/portail/galerie-types.php">Types de galerie</a>' ?> (fonctionnalité « <?= e(GT_FEATURES['messages']) ?> »).</p>
    </section>
    <?php
    ga_foot();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'reply') {
        $err = gmsg_post($gaSlug, $threadId, 'admin', (string) ($_POST['body'] ?? ''), ['by' => $actor]);
        if (!$err && ($thread = gmsg_thread($gaSlug, $threadId))) gmsg_notify_guest($gaGallery, $thread, (string) ($_POST['body'] ?? ''));
        portail_flash($err ?? 'Message envoyé.', $err ? 'error' : 'ok');
        header('Location: ' . $linkTo($threadId));
        exit;
    }
    if ($action === 'new') {
        $body = (string) ($_POST['body'] ?? '');
        $slot = (string) ($_POST['slot'] ?? '');
        $row = $slot !== '' ? gb_row($gaGallery, $slot) : null;
        if ($row) {
            $id = gmsg_open_exposant($gaGallery, $row);
            $err = $id === null ? 'Trop de conversations : supprimez les anciennes avant d\'en ouvrir une nouvelle.' : gmsg_post($gaSlug, $id, 'admin', $body, ['by' => $actor]);
            if (!$err && $id !== null && ($thread = gmsg_thread($gaSlug, $id))) gmsg_notify_guest($gaGallery, $thread, $body);
        } else {
            [$id, $err] = gmsg_contact_open($gaGallery, (string) ($_POST['name'] ?? ''), (string) ($_POST['email'] ?? ''),
                (string) ($_POST['subject'] ?? ''), $body, $actor);
            if (!$err && $id !== null && ($thread = gmsg_thread($gaSlug, $id))) gmsg_notify_guest($gaGallery, $thread, $body);
        }
        portail_flash($err ?? 'Conversation ouverte : un e-mail vient d\'être envoyé.', $err ? 'error' : 'ok');
        header('Location: ' . ($err ? $self : $linkTo((string) $id)));
        exit;
    }
    if ($action === 'delete') {
        gmsg_delete($gaSlug, $threadId);
        portail_flash('Conversation supprimée.');
        header('Location: ' . $self);
        exit;
    }
    portail_flash('Action inconnue.', 'error');
    header('Location: ' . $self);
    exit;
}

$thread = $threadId !== '' ? gmsg_thread($gaSlug, $threadId) : null;
if ($threadId !== '' && !$thread) {
    portail_flash('Conversation introuvable.', 'error');
    header('Location: ' . $self);
    exit;
}
$threads = gmsg_threads($gaSlug);
$unread = 0;
foreach ($threads as $t) $unread += $t['unread_admin'];
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');

if ($thread) {
    gmsg_mark_read($gaSlug, $thread['id'], 'admin');
    $thread = gmsg_thread($gaSlug, $thread['id']) ?? $thread;
}

ga_head($thread ? 'Message' : 'Messages');
?>
<?php if ($thread): ?>
    <section class="gcard">
      <div class="row" style="justify-content:space-between;">
        <h2 style="margin:0;"><?= e($thread['subject'] !== '' ? $thread['subject'] : 'Conversation avec ' . $thread['name']) ?></h2>
        <a class="btn" href="<?= e($self) ?>">← Toutes les conversations</a>
      </div>
      <p class="meta">
        <?= e($thread['name']) ?> · <?= e($thread['email']) ?>
        <?php if ($thread['kind'] === 'exposant'): ?> · exposant<?php endif; ?>
        <?php if ($thread['slot_id'] !== '' && ($tRow = gb_row($gaGallery, $thread['slot_id']))): ?>
          · <a href="/portail/galerie-exposant.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) . '&amp;' ?>slot=<?= e(urlencode($thread['slot_id'])) ?>"><?= e(gmsg_slot_label($gaGallery, $tRow)) ?></a>
        <?php endif; ?>
        · ouverte le <?= e(date('d/m/Y H:i', $thread['created'])) ?>
      </p>
      <?php if (!$thread['messages']): ?><p class="meta">Aucun message pour le moment : écrivez le premier ci-dessous.</p><?php else: ?>
      <div class="gmsg-list">
        <?php foreach ($thread['messages'] as $m): ?>
          <div class="gmsg-msg <?= $m['from'] === 'admin' ? 'admin' : 'guest' ?>">
            <div class="gmsg-head"><strong><?= $m['from'] === 'admin' ? e($m['by'] !== '' ? $m['by'] : 'Organisateur') : e($thread['name']) ?></strong><span><?= e(date('d/m/Y H:i', $m['at'])) ?></span></div>
            <p><?= nl2br(e($m['body'])) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <form method="post" class="gmsg-new" style="margin-top:6px;">
        <?= $hid ?><input type="hidden" name="i" value="<?= e($thread['id']) ?>"><input type="hidden" name="action" value="reply">
        <label class="f">Répondre<textarea name="body" maxlength="<?= GMSG_MAX_BODY ?>" rows="4" required placeholder="Votre réponse…"></textarea></label>
        <div class="row"><button class="btn btn-primary" type="submit">Envoyer la réponse</button></div>
      </form>
      <p class="meta">La personne reçoit un e-mail avec un lien personnel vers cette conversation ; elle peut répondre sans créer de compte.</p>
    </section>

    <section class="gcard">
      <h2>Supprimer la conversation</h2>
      <p class="meta">Les messages sont définitivement effacés du fichier de la galerie. La personne ne pourra plus ouvrir son lien.</p>
      <form method="post" onsubmit="return confirm('Supprimer cette conversation et tous ses messages ?')"><?= $hid ?><input type="hidden" name="i" value="<?= e($thread['id']) ?>"><input type="hidden" name="action" value="delete">
        <button class="btn danger" type="submit">Supprimer</button>
      </form>
    </section>
<?php else: ?>
    <div class="kpis">
      <div class="kpi"><b><?= count($threads) ?></b><span>conversations</span></div>
      <div class="kpi"><b><?= (int) $unread ?></b><span>messages non lus</span></div>
    </div>

    <section class="gcard">
      <h2>Nouvelle conversation</h2>
      <?php if ($newRow): ?>
        <p class="meta">À propos de la demande de <strong><?= e($newRow['name']) ?></strong> (<?= e(gmsg_slot_label($gaGallery, $newRow)) ?>) : l'e-mail part à <?= e($newRow['email']) ?>.</p>
      <?php else: ?>
        <p class="meta">Écrivez à un exposant, un commerçant, un prospect ou toute personne de votre connaissance. Elle reçoit un lien personnel : aucun compte à créer.</p>
      <?php endif; ?>
      <form method="post" class="gmsg-new">
        <?= $hid ?><input type="hidden" name="action" value="new">
        <?php if ($newRow): ?><input type="hidden" name="slot" value="<?= e($newRow['slot_id']) ?>"><?php endif; ?>
        <div class="row">
          <?php if ($newRow): ?>
            <p class="meta" style="margin:0;">Destinataire : <strong><?= e($newRow['name']) ?></strong> · <?= e($newRow['email']) ?></p>
          <?php else: ?>
            <label class="f">Nom<input type="text" name="name" maxlength="120" required placeholder="Marie Dupont"></label>
            <label class="f">E-mail<input type="email" name="email" maxlength="160" required placeholder="marie@exemple.fr"></label>
            <label class="f">Objet<input type="text" name="subject" maxlength="150" placeholder="Votre emplacement du 14 juin"></label>
          <?php endif; ?>
        </div>
        <label class="f">Message<textarea name="body" maxlength="<?= GMSG_MAX_BODY ?>" rows="5" required placeholder="Bonjour, …"></textarea></label>
        <div class="row"><button class="btn btn-primary" type="submit">Envoyer le message</button></div>
      </form>
    </section>

    <section class="gcard">
      <h2>Conversations</h2>
      <?php if (!$threads): ?><p class="meta">Aucune conversation pour le moment.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th></th><th>Interlocuteur</th><th>Objet</th><th>Dernier message</th><th>Date</th><th></th></tr></thead><tbody>
      <?php foreach ($threads as $t): ?>
        <tr>
          <td><?= $t['unread_admin'] ? '<span class="pill sent">' . (int) $t['unread_admin'] . '</span>' : '' ?></td>
          <td><?= e($t['name']) ?><br><small class="meta"><?= e($t['email']) ?><?= $t['kind'] === 'exposant' ? ' · exposant' : '' ?></small></td>
          <td><?= e($t['subject']) ?></td>
          <td class="meta"><?= e(mb_strimwidth((string) ($t['last']['body'] ?? ''), 0, 90, '…')) ?></td>
          <td><?= e(date('d/m/Y H:i', $t['updated'])) ?></td>
          <td><a class="btn small<?= $t['unread_admin'] ? ' btn-primary' : '' ?>" href="<?= e($linkTo($t['id'])) ?>">Ouvrir</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
<?php endif; ?>
<?php ga_foot(); ?>
