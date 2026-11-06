<?php
// Rendez-vous — page publique (/rendez-vous.php, servie par le socle quand ce plugin est actif).
// Deux vues : la liste des consultations ouvertes et confirmées, puis une consultation où chaque visiteur indique ses
// disponibilités sans créer de compte. Le lien reçu par e-mail permet de revenir modifier sa réponse.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rdv.php';

$token = trim((string) ($_GET['p'] ?? ''));
$linkToken = trim((string) ($_GET['r'] ?? ''));
$poll = $token !== '' ? rdv_poll_by_token($token) : null;
$css = plugin_asset_url($m, 'rdv.css');
$errors = [];
$posted = [];
$currentAnswer = null;
$justSent = isset($_GET['envoye']);

function rdv_public_redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

if ($token !== '' && !$poll) {
    http_response_code(404);
}

if ($poll) {
    $pollId = (int) $poll['id'];
    $slots = rdv_slots($pollId);
    $answers = rdv_answers($pollId);
    $counts = rdv_counts($pollId);
    $choices = rdv_choices_map($pollId);
    $confirmedId = (int) ($poll['confirmed_slot_id'] ?? 0);
    $confirmedSlot = null;
    foreach ($slots as $slot) {
        if ((int) $slot['id'] === $confirmedId) $confirmedSlot = $slot;
    }
    $editToken = $linkToken;
    if ($editToken !== '') {
        $currentAnswer = rdv_answer_by_token($pollId, $editToken);
        if (!$currentAnswer) $errors[] = 'Ce lien de modification n’est plus valable. Demandez un nouveau lien en répondant à nouveau si l’adresse est libre.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['p'] ?? '') === $token) {
        $posted = $_POST;
        $postEdit = trim((string) ($_POST['edit'] ?? ''));
        if (trim((string) ($_POST['website'] ?? '')) !== '') {
            rdv_public_redirect(rdv_public_path($token));
        }
        if (!rdv_csrf_valid()) {
            $errors[] = 'Votre session a expiré : vérifiez votre nom et votre adresse, puis renvoyez le formulaire.';
        } elseif (!rdv_rate_ok()) {
            $errors[] = 'Vous avez envoyé plusieurs réponses à l’instant : réessayez dans un moment.';
        } else {
            [$answer, $submitErrors, $created] = rdv_submit_answer($poll, $_POST, $postEdit);
            if ($submitErrors) {
                $errors = array_merge($errors, $submitErrors);
            } else {
                rdv_rate_note();
                rdv_notify_participant($poll, $answer);
                rdv_notify_new_answer($poll, $answer, $created);
                rdv_public_redirect(rdv_public_path($token) . '&r=' . rawurlencode((string) $answer['token']) . '&envoye=1');
            }
        }
    }

    $editing = $currentAnswer !== null;
    $value = static function (string $field, string $default = '') use ($posted, $currentAnswer): string {
        if (array_key_exists($field, $posted)) return trim((string) $posted[$field]);
        if ($currentAnswer !== null) return (string) ($currentAnswer[$field] ?? $default);
        return $default;
    };
    $choiceFor = static function (int $slotId) use ($posted, $currentAnswer, $choices): int {
        if (isset($posted['slots'][$slotId])) return (int) $posted['slots'][$slotId];
        if ($currentAnswer !== null) return (int) ($choices[(int) $currentAnswer['id']][$slotId] ?? 0);
        return 0;
    };
    $consent = !empty($posted['consent']) || $currentAnswer !== null;
    $open = rdv_poll_open($poll);
    $status = (string) $poll['status'];
    $showResults = (int) $poll['results_public'] === 1 && ($editing || $justSent);
    $formPath = rdv_public_path($token) . ($editing ? '&r=' . rawurlencode((string) $currentAnswer['token']) : '');
}

if ($css !== ''): ?><link rel="stylesheet" href="<?= h($css) ?>"><?php endif; ?>

<?php if ($token !== '' && !$poll): ?>
  <section class="wrap rdv-page rdv-public">
    <p class="rdv-eyebrow">Rendez-vous</p>
    <h1 class="rdv-title">Cette consultation n’existe pas ou a été supprimée.</h1>
    <p class="rdv-lede">Le lien est peut-être incomplet. <a href="/rendez-vous.php">Voir les rendez-vous proposés</a>.</p>
  </section>
<?php elseif (!$poll): ?>
  <?php $polls = rdv_public_polls(); ?>
  <section class="wrap rdv-page rdv-public">
    <div class="rdv-hero">
      <p class="rdv-eyebrow"><?= h(rdv_shop_name()) ?></p>
      <h1 class="rdv-title">Rendez-vous</h1>
      <p class="rdv-lede">Choisissez une consultation, puis indiquez les dates et horaires qui vous conviennent. Aucun compte
        n’est nécessaire : votre nom et votre adresse e-mail suffisent, et vous pourrez modifier votre réponse avec le lien
        reçu par e-mail.</p>
    </div>

    <?php if (!$polls): ?>
      <div class="rdv-card">
        <h2>Aucun rendez-vous en ligne pour le moment</h2>
        <p class="rdv-note" style="margin:0;">La boutique n’a pas publié de consultation de disponibilités. Revenez bientôt, ou
          contactez-la directement.</p>
      </div>
    <?php else: ?>
      <div class="rdv-cards">
        <?php foreach ($polls as $entry):
          $entrySlots = (array) ($entry['_slots'] ?? []);
          $entryStatus = (string) $entry['status'];
          $entryConfirmed = null;
          foreach ($entrySlots as $entrySlot) {
              if ((int) $entrySlot['id'] === (int) ($entry['confirmed_slot_id'] ?? 0)) $entryConfirmed = $entrySlot;
          }
          ?>
          <article class="rdv-poll-card">
            <div class="rdv-actions" style="justify-content:space-between;">
              <span class="rdv-badge" data-status="<?= h($entryStatus) ?>"><?= h(rdv_status_label($entryStatus)) ?></span>
              <span class="rdv-meta"><?= count($entrySlots) ?> créneau<?= count($entrySlots) > 1 ? 'x' : '' ?></span>
            </div>
            <h2><a href="<?= h(rdv_public_path((string) $entry['token'])) ?>" style="color:inherit;"><?= h($entry['title']) ?></a></h2>
            <p class="rdv-meta"><?= h(rdv_poll_range($entrySlots, true)) ?><?= (string) $entry['place'] !== '' ? ' · ' . h($entry['place']) : '' ?></p>
            <?php if ($entryConfirmed): ?>
              <p class="rdv-meta">Rendez-vous confirmé : <strong><?= h(rdv_slot_label($entryConfirmed, true)) ?></strong></p>
            <?php elseif ($entryStatus === 'open'): ?>
              <p class="rdv-meta"><?= (string) $entry['closes_on'] !== ''
                  ? 'Répondez avant le ' . h(rdv_fr_date((string) $entry['closes_on'], true)) . '.'
                  : 'Sans date limite : répondez quand vous voulez.' ?></p>
            <?php endif; ?>
            <div class="rdv-actions">
              <a class="rdv-btn rdv-btn-primary rdv-btn-small" href="<?= h(rdv_public_path((string) $entry['token'])) ?>">
                <?= $entryStatus === 'open' ? 'Indiquer mes disponibilités' : 'Voir le rendez-vous' ?>
              </a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <p class="rdv-signature">Proposé par <?= h(rdv_shop_name()) ?>.</p>
  </section>
<?php else: ?>
  <section class="wrap rdv-page rdv-public">
    <div class="rdv-hero">
      <p class="rdv-eyebrow"><?= h(rdv_shop_name()) ?> · consultation de disponibilités</p>
      <div class="rdv-actions" style="justify-content:space-between;align-items:flex-start;">
        <h1 class="rdv-title"><?= h($poll['title']) ?></h1>
        <span class="rdv-badge" data-status="<?= h($status) ?>"><?= h(rdv_status_label($status)) ?></span>
      </div>
      <p class="rdv-lede"><?= $slots ? h(rdv_poll_range($slots)) : 'Les créneaux sont en préparation.' ?></p>
      <p class="rdv-meta" style="margin:0;color:var(--rdv-muted);">
        <?php $meta = []; ?>
        <?php if ((string) $poll['place'] !== ''): ?><?php $meta[] = 'Lieu : ' . h($poll['place']); ?><?php endif; ?>
        <?php if ((string) $poll['closes_on'] !== ''): ?><?php $meta[] = 'Réponses jusqu’au ' . h(rdv_fr_date((string) $poll['closes_on'], true)); ?><?php endif; ?>
        <?php $meta[] = count($answers) . ' réponse' . (count($answers) > 1 ? 's' : ''); ?>
        <?= implode(' · ', $meta) ?>
      </p>
      <?php if ((string) $poll['description'] !== ''): ?>
        <p class="rdv-lede" style="white-space:pre-line;"><?= h($poll['description']) ?></p>
      <?php endif; ?>
    </div>

    <?php if ($status === 'confirmed' && $confirmedSlot): ?>
      <div class="rdv-confirmed">
        <p class="rdv-eyebrow" style="margin:0;">Rendez-vous confirmé</p>
        <h2><?= h(rdv_slot_label($confirmedSlot)) ?></h2>
        <?php if ((string) $poll['place'] !== ''): ?><p style="margin:0;"><?= h($poll['place']) ?></p><?php endif; ?>
        <p class="rdv-note" style="margin:0;">Le créneau est validé. Si vous ne pouvez plus venir, prévenez la boutique au plus vite.</p>
      </div>
    <?php elseif ($status === 'cancelled'): ?>
      <div class="rdv-card" style="border-left:4px solid var(--rdv-danger, #a33b2e);">
        <h2>Rendez-vous annulé</h2>
        <p class="rdv-note" style="margin:0;">Cette consultation a été annulée par la boutique. Aucune nouvelle réponse n’est
          enregistrée.</p>
      </div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="rdv-flash" data-kind="error" role="alert">
        <strong>Votre réponse n’a pas été enregistrée.</strong>
        <ul class="rdv-errors" style="margin:6px 0 0;">
          <?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($justSent && $currentAnswer): ?>
      <div class="rdv-success" role="status">
        <h2 style="margin:0;">Merci, votre réponse est enregistrée.</h2>
        <p style="margin:0;">Un e-mail de confirmation part vers <strong><?= h($currentAnswer['email']) ?></strong>. Il contient
          le lien pour modifier votre réponse.</p>
        <p class="rdv-note" style="margin:0;white-space:pre-line;"><?= h(rdv_answer_summary($poll, $currentAnswer)) ?></p>
        <?php if ($status === 'confirmed' && $confirmedSlot): ?>
          <p class="rdv-note" style="margin:0;">Le rendez-vous est confirmé : <?= h(rdv_slot_label($confirmedSlot)) ?>.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($open && $slots): ?>
      <section class="rdv-card">
        <h2><?= $editing ? 'Modifier ma réponse' : 'Indiquer mes disponibilités' ?></h2>
        <p class="rdv-note" style="margin:0;">Pour chaque créneau : <strong>oui</strong> si vous êtes disponible,
          <strong>peut-être</strong> si vous pouvez vous organiser<?= empty($poll['allow_maybe']) ? ' (option désactivée)' : '' ?>,
          <strong>non</strong> sinon. Indiquez au moins un créneau où vous êtes disponible.</p>

        <form method="post" action="<?= h($formPath) ?>" class="rdv-choice-grid">
          <?= rdv_csrf_field() ?>
          <input type="hidden" name="p" value="<?= h($token) ?>">
          <?php if ($editing): ?><input type="hidden" name="edit" value="<?= h((string) $currentAnswer['token']) ?>"><?php endif; ?>
          <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
            <label>Ne pas remplir <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
          </div>

          <div class="rdv-grid-2">
            <label>Votre nom
              <input type="text" name="name" maxlength="80" required autocomplete="name" value="<?= h($value('name')) ?>">
            </label>
            <label>Votre e-mail
              <input type="email" name="email" maxlength="160" required autocomplete="email" value="<?= h($value('email')) ?>">
            </label>
          </div>
          <div class="rdv-grid-2">
            <label>Téléphone (facultatif)
              <input type="tel" name="phone" maxlength="30" autocomplete="tel" value="<?= h($value('phone')) ?>">
            </label>
            <label>Message (facultatif)
              <input type="text" name="comment" maxlength="1000" value="<?= h($value('comment')) ?>">
            </label>
          </div>

          <div class="rdv-choice-grid">
            <?php foreach ($slots as $slot):
              $sid = (int) $slot['id'];
              $pickedValue = $choiceFor($sid);
              ?>
              <div class="rdv-choice-row">
                <span class="rdv-slot-label"><?= h(rdv_slot_label($slot)) ?></span>
                <span class="rdv-options">
                  <label><input type="radio" name="slots[<?= $sid ?>]" value="2"<?= $pickedValue === 2 ? ' checked' : '' ?>> Oui</label>
                  <?php if (!empty($poll['allow_maybe'])): ?>
                    <label><input type="radio" name="slots[<?= $sid ?>]" value="1"<?= $pickedValue === 1 ? ' checked' : '' ?>> Peut-être</label>
                  <?php endif; ?>
                  <label><input type="radio" name="slots[<?= $sid ?>]" value="0"<?= $pickedValue === 0 ? ' checked' : '' ?>> Non</label>
                </span>
              </div>
            <?php endforeach; ?>
          </div>

          <label class="rdv-inline" style="display:flex;gap:8px;align-items:flex-start;">
            <input type="checkbox" name="consent" value="1" required<?= $consent ? ' checked' : '' ?>>
            <span>J’accepte d’être contacté au sujet de ce rendez-vous. Mes coordonnées servent uniquement à
              l’organisation et ne sont pas publiées.</span>
          </label>

          <div class="rdv-actions">
            <button type="submit" class="rdv-btn rdv-btn-primary"><?= $editing ? 'Enregistrer mes modifications' : 'Envoyer mes disponibilités' ?></button>
            <span class="rdv-note">Vous recevrez un e-mail avec le lien pour modifier votre réponse.</span>
          </div>
        </form>
      </section>
    <?php elseif ($slots && $status !== 'cancelled'): ?>
      <div class="rdv-card">
        <h2><?= $status === 'confirmed' ? 'Le rendez-vous est calé' : 'Les réponses sont closes' ?></h2>
        <p class="rdv-note" style="margin:0;">
          <?= $status === 'confirmed'
              ? 'Le créneau retenu est indiqué ci-dessus. Pour toute question, contactez la boutique.'
              : 'Cette consultation n’accepte plus de nouvelles réponses. Contactez la boutique si vous devez signaler un changement.' ?>
        </p>
      </div>
    <?php endif; ?>

    <?php if ($showResults): ?>
      <?php
      $namesBySlot = [2 => [], 1 => []];
      if ((int) $poll['names_public'] === 1) {
          foreach ($answers as $answer) {
              foreach ($slots as $slot) {
                  $sid = (int) $slot['id'];
                  $choice = (int) ($choices[(int) $answer['id']][$sid] ?? 0);
                  if ($choice === 2 || $choice === 1) $namesBySlot[$choice][$sid][] = (string) $answer['name'];
              }
          }
      }
      ?>
      <section class="rdv-card">
        <h2>Disponibilités recueillies (<?= count($answers) ?> réponse<?= count($answers) > 1 ? 's' : '' ?>)</h2>
        <div class="rdv-table-wrap">
          <table class="rdv-table rdv-results">
            <thead>
              <tr>
                <th>Créneau</th>
                <th class="rdv-center">Oui</th>
                <th class="rdv-center">Peut-être</th>
                <th class="rdv-center">Non</th>
                <?php if ((int) $poll['names_public'] === 1): ?><th>Participants disponibles</th><?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($slots as $slot):
                $sid = (int) $slot['id'];
                $c = $counts[$sid] ?? ['yes' => 0, 'maybe' => 0, 'no' => 0];
                ?>
                <tr>
                  <td><?= h(rdv_slot_label($slot)) ?><?= $sid === $confirmedId ? ' <span class="rdv-badge" data-status="confirmed">Confirmé</span>' : '' ?></td>
                  <td class="rdv-center"><strong><?= $c['yes'] ?></strong></td>
                  <td class="rdv-center"><?= $c['maybe'] ?></td>
                  <td class="rdv-center"><?= $c['no'] ?></td>
                  <?php if ((int) $poll['names_public'] === 1): ?>
                    <td class="rdv-names"><?= h(implode(' · ', array_merge($namesBySlot[2][$sid] ?? [], array_map(static fn (string $n): string => $n . ' (?)', $namesBySlot[1][$sid] ?? []))) ?: '—') ?></td>
                  <?php endif; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($editing && $open): ?>
          <p class="rdv-note" style="margin:0;">Vous pouvez encore modifier votre réponse ci-dessus tant que la consultation
            reste ouverte.</p>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <p class="rdv-signature">Proposé par <?= h(rdv_shop_name()) ?> · <a href="/rendez-vous.php">tous les rendez-vous</a></p>
  </section>
<?php endif; ?>
