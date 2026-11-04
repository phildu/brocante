<?php
// Rendez-vous — gestion d'une consultation : annonce, créneaux, tableau des réponses, créneau confirmé.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rdv.php';

$pollId = (int) ($_GET['id'] ?? 0);
$poll = rdv_poll($pollId);
$listUrl = plugin_page_url($plugin['id'], 'index');
$flash = rdv_flash_take();
if (!$poll) {
    http_response_code(404);
    echo '<div class="rdv-page"><p class="rdv-eyebrow">Rendez-vous</p><h1 class="rdv-title">Consultation introuvable</h1>'
        . '<p class="rdv-lede">Elle a peut-être été supprimée. <a href="' . h($listUrl) . '">Retour à la liste</a>.</p></div>';
    return;
}
$slots = rdv_slots($pollId);
$answers = rdv_answers($pollId);
$counts = rdv_counts($pollId);
$choices = rdv_choices_map($pollId);
$bestId = rdv_best_slot($slots, $counts);
$confirmedId = (int) ($poll['confirmed_slot_id'] ?? 0);
$confirmedSlot = null;
$bestSlot = null;
foreach ($slots as $slot) {
    if ((int) $slot['id'] === $confirmedId) $confirmedSlot = $slot;
    if ((int) $slot['id'] === $bestId) $bestSlot = $slot;
}
$status = (string) $poll['status'];
$publicPath = rdv_public_path((string) $poll['token']);
$publicUrl = rdv_public_url((string) $poll['token']);
$defaultNotify = implode(', ', array_filter(pricing_admin_emails()));
$notifyValue = (string) $poll['notify_email'] !== '' ? (string) $poll['notify_email'] : $defaultNotify;
$genFrom = (new DateTimeImmutable('tomorrow', rdv_tz()))->format('Y-m-d');
$genTo = (new DateTimeImmutable('+14 days', rdv_tz()))->format('Y-m-d');
$mark = static fn (int $value): string => $value === 2 ? '✓' : ($value === 1 ? '~' : '·');
?>
<link rel="stylesheet" href="<?= h($pluginAsset('rdv.css')) ?>">
<div class="rdv-page" id="rdv-admin"
  data-api="<?= h($pluginApi('admin')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-page="manage"
  data-poll="<?= $pollId ?>" data-list="<?= h($listUrl) ?>">
  <div class="rdv-actions" style="justify-content:space-between;align-items:flex-start;">
    <div>
      <p class="rdv-eyebrow"><a href="<?= h($listUrl) ?>" style="color:inherit;">Rendez-vous</a> · gestion</p>
      <h1 class="rdv-title" style="margin:6px 0;"><?= h($poll['title']) ?></h1>
      <p class="rdv-lede"><?= h(rdv_poll_range($slots, true) ?: 'Aucun créneau') ?>
        · <?= count($slots) ?> créneau<?= count($slots) > 1 ? 'x' : '' ?> · <?= count($answers) ?> réponse<?= count($answers) > 1 ? 's' : '' ?></p>
    </div>
    <span class="rdv-badge" data-status="<?= h($status) ?>"><?= h(rdv_status_label($status)) ?></span>
  </div>

  <?php if ($flash): ?>
    <p class="rdv-flash" data-kind="<?= h($flash['kind'] ?? 'ok') ?>" role="status"><?= h($flash['message'] ?? '') ?></p>
  <?php endif; ?>
  <p class="rdv-note" id="rdv-msg" role="status" hidden></p>

  <?php if ($status === 'confirmed' && $confirmedSlot): ?>
    <section class="rdv-card" style="border-left:4px solid var(--rdv-ok);">
      <h2>Créneau confirmé</h2>
      <p style="margin:0;font-size:1.05rem;"><strong><?= h(rdv_slot_label($confirmedSlot)) ?></strong>
        <?= $poll['place'] !== '' ? ' · ' . h($poll['place']) : '' ?></p>
      <p class="rdv-note" style="margin:0;">
        <?= $poll['notified_at'] !== '' ? 'Participants prévenus le ' . h(date('d/m/Y à H:i', strtotime((string) $poll['notified_at'] . ' UTC'))) . '.' : 'Les participants n’ont pas encore été prévenus.' ?>
      </p>
      <div class="rdv-actions">
        <button type="button" class="rdv-btn rdv-btn-primary" data-rdv-action="notify">Prévenir les participants</button>
        <button type="button" class="rdv-btn" data-rdv-action="status" data-status="open" data-confirm="Réouvrir la consultation et retirer le créneau confirmé ?">Réouvrir les réponses</button>
      </div>
    </section>
  <?php endif; ?>

  <section class="rdv-card">
    <h2>Lien public à partager</h2>
    <div class="rdv-linkbox">
      <input type="text" value="<?= h($publicUrl) ?>" readonly aria-label="Lien public" onclick="this.select()">
      <button type="button" class="rdv-btn rdv-btn-small" data-copy="<?= h($publicUrl) ?>">Copier</button>
      <a class="rdv-btn rdv-btn-small" href="<?= h($publicPath) ?>" target="_blank" rel="noopener">Ouvrir la page</a>
    </div>
    <p class="rdv-note" style="margin:0;">Envoyez ce lien par e-mail, SMS ou réseaux sociaux. Les visiteurs n’ont pas besoin de compte ;
      ils peuvent modifier leur réponse avec le lien reçu par e-mail.</p>
  </section>

  <details class="rdv-card">
    <summary style="cursor:pointer;font-weight:700;">Modifier l’annonce</summary>
    <form id="rdv-edit-form" style="display:grid;gap:12px;margin-top:14px;">
      <div class="rdv-grid-2">
        <label>Titre <input type="text" name="title" maxlength="140" required value="<?= h($poll['title']) ?>"></label>
        <label>Lieu ou adresse <input type="text" name="place" maxlength="160" value="<?= h($poll['place']) ?>"></label>
      </div>
      <label>Présentation <textarea name="description" rows="3" maxlength="2000"><?= h($poll['description']) ?></textarea></label>
      <div class="rdv-grid-2">
        <label>Adresses à prévenir <input type="text" name="notify_email" maxlength="300" value="<?= h($notifyValue) ?>"></label>
        <label>Réponses closes après le <input type="date" name="closes_on" value="<?= h($poll['closes_on']) ?>"></label>
      </div>
      <fieldset>
        <legend>Options</legend>
        <div style="display:grid;gap:8px;">
          <input type="hidden" name="results_public" value="0">
          <label class="rdv-inline"><input type="checkbox" name="results_public" value="1"<?= (int) $poll['results_public'] ? ' checked' : '' ?>> Afficher les résultats aux participants après leur réponse</label>
          <input type="hidden" name="names_public" value="0">
          <label class="rdv-inline"><input type="checkbox" name="names_public" value="1"<?= (int) $poll['names_public'] ? ' checked' : '' ?>> Afficher aussi les noms des autres participants</label>
          <input type="hidden" name="allow_maybe" value="0">
          <label class="rdv-inline"><input type="checkbox" name="allow_maybe" value="1"<?= (int) $poll['allow_maybe'] ? ' checked' : '' ?>> Proposer la réponse « peut-être »</label>
        </div>
      </fieldset>
      <div class="rdv-actions"><button type="submit" class="rdv-btn">Enregistrer l’annonce</button></div>
    </form>
  </details>

  <section class="rdv-card">
    <div class="rdv-actions" style="justify-content:space-between;">
      <h2 style="margin:0;">Créneaux proposés (<?= count($slots) ?>)</h2>
      <?php if ($bestSlot): ?><span class="rdv-note">Meilleur : <?= h(rdv_slot_label($bestSlot, true)) ?></span><?php endif; ?>
    </div>
    <?php if (!$slots): ?>
      <p class="rdv-note" style="margin:0;">Aucun créneau : ajoutez-en ci-dessous pour que la page publique puisse recevoir des réponses.</p>
    <?php else: ?>
      <ul class="rdv-slot-list">
        <?php foreach ($slots as $slot):
          $sid = (int) $slot['id'];
          $c = $counts[$sid] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0];
          $isBest = $sid === $bestId;
          $isConfirmed = $sid === $confirmedId;
          ?>
          <li<?= $isBest ? ' style="border-color:var(--rdv-ok);"' : '' ?>>
            <span>
              <strong><?= h(rdv_slot_label($slot)) ?></strong>
              <?php if ($isConfirmed): ?><span class="rdv-badge" data-status="confirmed" style="margin-left:8px;">Confirmé</span><?php endif; ?>
              <?php if ($isBest && !$isConfirmed): ?><span class="rdv-badge" data-status="open" style="margin-left:8px;">Meilleur</span><?php endif; ?>
              <span class="rdv-note" style="display:block;margin-top:3px;"><?= $c['yes'] ?> oui · <?= $c['maybe'] ?> peut-être · <?= $c['no'] ?> non</span>
            </span>
            <button type="button" class="rdv-btn rdv-btn-small rdv-btn-danger" data-rdv-action="delete-slot" data-slot-id="<?= $sid ?>"
              data-confirm="Supprimer ce créneau ? Les réponses qui le concernent seront retirées.">Supprimer</button>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($status !== 'confirmed' && $status !== 'cancelled'): ?>
      <details style="margin-top:6px;">
        <summary style="cursor:pointer;font-weight:600;">Ajouter des créneaux</summary>
        <form id="rdv-add-slots" style="display:grid;gap:10px;margin-top:12px;">
          <label>Nouveaux créneaux — une date par ligne
            <textarea name="slots" id="rdv-slots-add" rows="4" required placeholder="2026-11-21 14:00-18:00"></textarea>
          </label>
          <p class="rdv-note">Mêmes formats que la création. Un créneau déjà présent est ignoré.</p>
          <div class="rdv-gen" data-rdv-generator data-target="#rdv-slots-add">
            <strong>Générer plusieurs créneaux</strong>
            <div class="rdv-grid-3">
              <label>Du <input type="date" data-gen="from" value="<?= h($genFrom) ?>"></label>
              <label>Au <input type="date" data-gen="to" value="<?= h($genTo) ?>"></label>
              <label>Durée (minutes) <input type="number" data-gen="duration" min="0" max="480" value="60" step="15"></label>
              <label>Premier horaire <input type="time" data-gen="start"></label>
              <label>Dernier horaire <input type="time" data-gen="end"></label>
            </div>
            <div class="rdv-days">
              <?php foreach (['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'] as $day => $label): ?>
                <label><input type="checkbox" class="rdv-gen-day" value="<?= $day ?>" checked> <?= h($label) ?></label>
              <?php endforeach; ?>
            </div>
            <div class="rdv-actions">
              <button type="button" class="rdv-btn rdv-btn-small" data-gen-run>Générer et ajouter</button>
              <span class="rdv-note" data-gen-status>Laissez les horaires vides pour proposer des journées entières.</span>
            </div>
          </div>
          <div class="rdv-actions"><button type="submit" class="rdv-btn rdv-btn-primary">Ajouter ces créneaux</button></div>
        </form>
      </details>
    <?php endif; ?>
  </section>

  <section class="rdv-card">
    <h2>Réponses (<?= count($answers) ?>)</h2>
    <?php if (!$answers): ?>
      <p class="rdv-note" style="margin:0;">Aucune réponse pour le moment. Partagez le lien public pour commencer à recueillir les disponibilités.</p>
    <?php else: ?>
      <div class="rdv-table-wrap">
        <table class="rdv-table">
          <thead>
            <tr>
              <th>Participant</th>
              <?php foreach ($slots as $slot):
                $sid = (int) $slot['id'];
                $tc = $counts[$sid] ?? ['yes' => 0, 'maybe' => 0];
                ?>
                <th class="rdv-center<?= $sid === $bestId ? ' is-best' : '' ?><?= $sid === $confirmedId ? ' is-confirmed' : '' ?>">
                  <?= h(rdv_fr_date((string) $slot['slot_date'], true)) ?><br>
                  <span class="rdv-note"><?= h(trim(rdv_time_label((string) $slot['start_time']) . ((string) $slot['end_time'] !== '' ? ' – ' . rdv_time_label((string) $slot['end_time']) : '')) ?: 'journée') ?></span><br>
                  <span class="rdv-note"><?= $tc['yes'] ?> oui · <?= $tc['maybe'] ?> ?</span>
                </th>
              <?php endforeach; ?>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($answers as $answer):
              $aid = (int) $answer['id'];
              $row = $choices[$aid] ?? [];
              ?>
              <tr>
                <td>
                  <strong><?= h($answer['name']) ?></strong>
                  <div class="rdv-note"><?= h($answer['email']) ?><?= $answer['phone'] !== '' ? ' · ' . h($answer['phone']) : '' ?></div>
                  <div class="rdv-note">Répondu le <?= h(date('d/m/Y à H:i', strtotime((string) ($answer['updated_at'] ?: $answer['created_at']) . ' UTC'))) ?></div>
                  <?php if ((string) $answer['comment'] !== ''): ?><div class="rdv-note" style="margin-top:4px;">« <?= h($answer['comment']) ?> »</div><?php endif; ?>
                </td>
                <?php foreach ($slots as $slot):
                  $sid = (int) $slot['id'];
                  $value = (int) ($row[$sid] ?? 0);
                  ?>
                  <td class="rdv-center<?= $sid === $bestId ? ' is-best' : '' ?><?= $sid === $confirmedId ? ' is-confirmed' : '' ?>">
                    <span class="rdv-mark" data-choice="<?= $value ?>" title="<?= h(RDV_CHOICES[$value] ?? 'Non') ?>"><?= $mark($value) ?></span>
                  </td>
                <?php endforeach; ?>
                <td class="rdv-center">
                  <button type="button" class="rdv-btn rdv-btn-small rdv-btn-danger" data-rdv-action="delete-answer" data-answer-id="<?= $aid ?>"
                    data-confirm="Supprimer la réponse de <?= h($answer['name']) ?> ?">×</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="rdv-note" style="margin:0;">✓ disponible · ~ peut-être · · non. Les colonnes vertes correspondent au meilleur créneau
        et au créneau confirmé. Les noms restent privés sauf si l’option « afficher les noms » est cochée.</p>
    <?php endif; ?>
  </section>

  <section class="rdv-card">
    <h2>Décision</h2>
    <?php if (!$slots): ?>
      <p class="rdv-note" style="margin:0;">Ajoutez d’abord des créneaux.</p>
    <?php else: ?>
      <div class="rdv-grid-2">
        <label>Créneau retenu
          <select id="rdv-confirm-slot">
            <?php foreach ($slots as $slot):
              $sid = (int) $slot['id'];
              $c = $counts[$sid] ?? ['yes' => 0, 'maybe' => 0];
              $selected = $sid === ($confirmedId ?: $bestId);
              ?>
              <option value="<?= $sid ?>"<?= $selected ? ' selected' : '' ?>><?= h(rdv_slot_label($slot)) ?> — <?= $c['yes'] ?> oui, <?= $c['maybe'] ?> peut-être</option>
            <?php endforeach; ?>
          </select>
        </label>
        <div style="display:grid;gap:10px;align-content:end;">
          <input type="hidden" id="rdv-confirm-notify" value="0">
          <label class="rdv-inline"><input type="checkbox" id="rdv-confirm-notify-check" value="1" checked> Prévenir les participants par e-mail</label>
          <button type="button" class="rdv-btn rdv-btn-primary" data-rdv-action="confirm">Confirmer ce créneau</button>
        </div>
      </div>
      <p class="rdv-note" style="margin:0;">Les personnes ayant répondu « oui » ou « peut-être » à ce créneau reçoivent un e-mail avec la date,
        le lieu et le lien de la consultation. Vous pouvez renvoyer le message plus tard avec « Prévenir les participants ».</p>
    <?php endif; ?>

    <div class="rdv-actions" style="padding-top:8px;border-top:1px dashed var(--rdv-line);">
      <?php if ($status === 'open'): ?>
        <button type="button" class="rdv-btn" data-rdv-action="status" data-status="closed" data-confirm="Clore les réponses ? Le lien public restera consultable.">Clore les réponses</button>
      <?php else: ?>
        <button type="button" class="rdv-btn" data-rdv-action="status" data-status="open" data-confirm="Réouvrir les réponses ?">Réouvrir les réponses</button>
      <?php endif; ?>
      <?php if ($status !== 'cancelled'): ?>
        <button type="button" class="rdv-btn rdv-btn-danger" data-rdv-action="status" data-status="cancelled" data-confirm="Annuler ce rendez-vous ? Les réponses restent conservées et le lien public l’indiquera.">Annuler le rendez-vous</button>
      <?php endif; ?>
      <button type="button" class="rdv-btn rdv-btn-danger" data-rdv-action="delete-poll" data-confirm="Supprimer définitivement cette consultation et toutes ses réponses ?">Supprimer la consultation</button>
    </div>
  </section>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
