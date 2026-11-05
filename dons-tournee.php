<?php
/**
 * Page du bénévole de tournée (vélo cargo), accessible par lien secret.
 * Pas de compte : le jeton de la tournée donne accès à CETTE tournée seulement.
 */
session_start();
require_once __DIR__ . '/includes/invendus-layout.php';
unsold_private_headers();

$token = unsold_param($_POST, 't') ?: unsold_param($_GET, 't');
$tour = unsold_tour_by_token($token);
if (!$tour) {
    unsold_field_not_found('Ce lien de tournée n\'existe pas ou a été supprimé. Demandez-en un nouveau à l\'organisation.');
}
$event = unsold_event_get((int) $tour['event_id']);
$self = '/dons-tournee.php?t=' . $token;
$who = 'Tournée ' . $tour['volunteer_name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    unsold_scalar_post();
    $action = unsold_param($_POST, 'action');
    $offerId = (int) unsold_param($_POST, 'offer_id');
    $anchor = '';
    $isDone = $tour['status'] === 'done';

    if ($action === 'start' && !$isDone) {
        unsold_tour_start((int) $tour['id']);
        flash_set('Bonne tournée !');
    } elseif ($action === 'finish' && !$isDone) {
        $res = unsold_tour_finish((int) $tour['id'], 'tour');
        if ($res) {
            flash_set($res['moved'] . ' objet' . ($res['moved'] > 1 ? 's déposés' : ' déposé') . ' au point de collecte'
                . ($res['released'] ? ' · ' . $res['released'] . ' non visité' . ($res['released'] > 1 ? 's' : '') . ' (rendu' . ($res['released'] > 1 ? 's' : '') . ' à l\'organisation)' : '') . '. Merci !');
        }
    } elseif (in_array($action, ['pick', 'skip', 'undo', 'restore'], true) && !$isDone) {
        $o = unsold_offer_get($offerId);
        // Le bénévole n'agit que sur les objets de SA tournée.
        if ($o && (int) $o['tour_id'] === (int) $tour['id']) {
            $anchor = '#o' . $offerId;
            if ($action === 'pick') {
                unsold_tour_start((int) $tour['id']); // le premier ramassage lance la tournée
                $ok = unsold_transition($offerId, 'picked', 'tour', $who);
            } elseif ($action === 'skip') {
                $ok = unsold_transition($offerId, 'withdrawn', 'tour', $who . ' : plus disponible');
            } elseif ($action === 'undo') {
                $ok = unsold_transition($offerId, 'proposed', 'tour', $who . ' : annulation');
            } else {
                $ok = unsold_transition($offerId, 'proposed', 'tour', $who . ' : remis en liste', ['keep_tour' => true]);
            }
            if (!$ok) flash_set('Déjà modifié par quelqu\'un d\'autre — la liste vient d\'être actualisée.', 'error');
        }
    }
    header('Location: ' . $self . $anchor);
    exit;
}

$tour = unsold_tour_get((int) $tour['id']); // état frais
$isDone = $tour['status'] === 'done';
$offers = unsold_tour_offers((int) $tour['id']);
$load = unsold_tour_load((int) $tour['id']);
$cap = max(1, (int) $tour['capacity']);
$pctPicked = min(100, round($load['units_picked'] / $cap * 100));
$pctPlanned = min(100, round($load['units_planned'] / $cap * 100));
$over = $load['units_planned'] > $cap;

$toVisit = 0;
foreach ($offers as $o) {
    if ($o['status'] === 'proposed') $toVisit++;
}

// Regroupement par emplacement, dans l'ordre de passage.
$stops = [];
foreach ($offers as $o) {
    $stops[$o['stand_ref'] !== '' ? $o['stand_ref'] : 'Sans emplacement'][] = $o;
}

unsold_field_head('Tournée de ' . $tour['volunteer_name']);
?>
<div class="don-field-bar">
  <div class="don-wrap">
    <div class="don-row">
      <div>
        <h1>Tournée de <?= h($tour['volunteer_name']) ?></h1>
        <span class="don-muted don-small"><?= h($tour['vehicle']) ?><?= $event ? ' · ' . h($event['name']) : '' ?></span>
      </div>
      <span class="don-right"><?= h(unsold_tour_status_labels()[$tour['status']] ?? $tour['status']) ?></span>
    </div>
    <div class="don-meter<?= $over ? ' don-meter--over' : '' ?>" style="margin-top:10px;" role="img"
         aria-label="Chargement : <?= (int) $load['units_picked'] ?> sur <?= $cap ?>">
      <i style="width:<?= $pctPlanned ?>%"></i><b style="width:<?= $pctPicked ?>%"></b>
    </div>
    <p class="don-small don-muted" style="margin:4px 0 0;">
      Chargé <strong><?= (int) $load['units_picked'] ?></strong> / <?= $cap ?> · prévu <?= (int) $load['units_planned'] ?>
      <?= $over ? ' — <strong style="color:var(--accent);">plus que la capacité : prévoyez deux voyages</strong>' : '' ?>
    </p>
  </div>
</div>

<div class="don-wrap" style="padding-top:6px;padding-bottom:40px;">
  <?php unsold_flash_html(); ?>

  <?php if ($tour['notes'] !== ''): ?>
    <div class="don-alert" style="margin-top:14px;"><?= nl2br(h($tour['notes'])) ?></div>
  <?php endif; ?>

  <?php if ($tour['status'] === 'planned'): ?>
    <form method="post" action="<?= h($self) ?>" style="margin-top:14px;">
      <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="action" value="start">
      <button class="don-big don-big--ok don-big--wide" type="submit">Démarrer la tournée</button>
    </form>
  <?php endif; ?>

  <?php if ($isDone): ?>
    <div class="don-alert" style="margin-top:14px;"><strong>Tournée terminée.</strong> Merci&nbsp;! Les objets ramassés sont au point de collecte.</div>
  <?php endif; ?>

  <?php if (!$offers): ?>
    <p class="empty-state" style="margin-top:20px;">Aucun arrêt dans cette tournée pour l'instant — l'organisation va en ajouter.</p>
  <?php endif; ?>

  <?php foreach ($stops as $stand => $items): ?>
    <section class="don-stop">
      <div class="don-stop-head">
        <span class="don-stand"><?= h($stand) ?></span>
      </div>
      <?php foreach ($items as $o):
          $done = in_array($o['status'], ['picked', 'at_point', 'taken', 'donated', 'withdrawn'], true);
          $size = unsold_sizes()[$o['size']] ?? null;
      ?>
        <div class="don-item" id="o<?= (int) $o['id'] ?>" data-done="<?= $done ? 1 : 0 ?>">
          <div class="don-row">
            <h3><?= h($o['title']) ?><?= (int) $o['quantity'] > 1 ? ' <span class="don-qty">× ' . (int) $o['quantity'] . '</span>' : '' ?></h3>
            <span class="don-right"><?= unsold_status_badge($o['status']) ?></span>
          </div>
          <div class="don-small don-muted">
            <?= $size ? h($size['label']) . ' (' . h($size['hint']) . ') · ' : '' ?>
            <?= h($o['donor_name']) ?>
            <?php if ($o['donor_phone'] !== ''): ?> · <a href="tel:<?= h(preg_replace('/[^+0-9]/', '', $o['donor_phone'])) ?>"><?= h($o['donor_phone']) ?></a><?php endif; ?>
            <?= $o['confirmed_at'] ? ' · <strong style="color:var(--sage);">invendu confirmé</strong>' : '' ?>
          </div>
          <?php if ($o['pickup_note'] !== ''): ?><div class="don-small">« <?= h($o['pickup_note']) ?> »</div><?php endif; ?>
          <?php if ($o['description'] !== ''): ?><div class="don-small don-muted"><?= h(mb_strimwidth($o['description'], 0, 160, '…')) ?></div><?php endif; ?>

          <?php if (!$isDone): ?>
            <div class="don-item-actions">
              <?php if ($o['status'] === 'proposed'): ?>
                <?php foreach ([['pick', 'Récupéré', 'don-big don-big--ok'], ['skip', 'Plus dispo', 'don-big don-big--no']] as [$act, $label, $cls]): ?>
                  <form method="post" action="<?= h($self) ?>" style="flex:1;">
                    <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>">
                    <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                    <button class="<?= $cls ?> don-big--wide" type="submit"><?= $label ?></button>
                  </form>
                <?php endforeach; ?>
              <?php elseif ($o['status'] === 'picked' || $o['status'] === 'withdrawn'): ?>
                <form method="post" action="<?= h($self) ?>">
                  <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>">
                  <input type="hidden" name="action" value="<?= $o['status'] === 'picked' ? 'undo' : 'restore' ?>"><input type="hidden" name="offer_id" value="<?= (int) $o['id'] ?>">
                  <button class="btn-small" type="submit">Annuler</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if (!$isDone && $offers): ?>
    <form method="post" action="<?= h($self) ?>" style="margin-top:22px;"
          onsubmit="return confirm('<?= $toVisit ? $toVisit . ' arrêt(s) non visité(s) seront rendus à l\\\'organisation. ' : '' ?>Terminer la tournée et déposer tout ce qui est chargé au point de collecte ?');">
      <?= csrf_input() ?><input type="hidden" name="t" value="<?= h($token) ?>"><input type="hidden" name="action" value="finish">
      <button class="don-big don-big--wide" type="submit">Tournée terminée — déposé au point de collecte</button>
    </form>
  <?php endif; ?>

  <p class="don-muted don-small" style="margin-top:24px;">Page personnelle de la tournée : ne partagez pas son adresse.</p>
</div>
<?php unsold_field_foot(); ?>
