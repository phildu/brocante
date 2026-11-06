<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/invendus-layout.php';
unsold_public_enter();   // galerie d'après l'adresse (/galerie/<galerie>/dons/…), extension active

// Éditions qui acceptent encore des propositions (avant l'ouverture du point, ou pendant).
$open = [];
foreach (unsold_public_events() as $e) {
    if (in_array(unsold_event_phase($e), ['proposals', 'collecting'], true)) $open[] = $e;
}

$eventId = (int) (unsold_param($_POST, 'event_id') ?: unsold_param($_GET, 'event'));
$event = null;
foreach ($open as $e) {
    if ((int) $e['id'] === $eventId) $event = $e;
}
$event ??= $open[0] ?? null;

$errors = [];
$prefill = $_SESSION['dons_donor'] ?? [];
$values = array_merge(['handover' => 'self', 'size' => 'M', 'quantity' => 1, 'category' => 'autre'], $prefill);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_require_csrf();
    unsold_scalar_post();

    // Piège à robots : ce champ est invisible pour un humain.
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: /dons.php');
        exit;
    }

    $values = array_merge($values, $_POST);

    if (!$event) {
        $errors[] = 'Les propositions sont closes pour cette édition.';
    }

    // Formulaire rempli « trop vite » (robot) ou session surchargée.
    $renderedAt = (int) ($_SESSION['dons_form_at'] ?? 0);
    if (!$errors && $renderedAt && time() - $renderedAt < 3) {
        $errors[] = 'Le formulaire a été envoyé trop vite — vérifiez vos informations et réessayez.';
    }
    $recent = array_filter($_SESSION['dons_submits'] ?? [], fn($t) => $t > time() - 3600);
    if (!$errors && count($recent) >= UNSOLD_MAX_OFFERS_PER_SESSION_HOUR) {
        $errors[] = 'Vous avez déjà envoyé beaucoup de propositions. Réessayez dans un moment, ou parlez-en aux bénévoles.';
    }
    if (!$errors && $event && unsold_recent_offer_count((int) $event['id']) >= UNSOLD_MAX_PUBLIC_OFFERS_PER_HOUR) {
        $errors[] = 'Beaucoup de propositions arrivent en ce moment. Réessayez dans quelques minutes.';
    }

    $clean = [];
    if (!$errors) {
        [$clean, $errors] = unsold_offer_from_post($_POST);
    }

    $photo = null;
    if (!$errors) {
        try {
            $photo = unsold_store_photo('photo');
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors && $event) {
        $eid = (int) $event['id'];
        $token = $_SESSION['dons_tokens'][$eid] ?? null; // les propositions d'un même visiteur partagent un lien de suivi
        $created = unsold_offer_create($eid, $clean, $token, $photo);
        $_SESSION['dons_tokens'][$eid] = $created['token'];
        $recent[] = time();
        $_SESSION['dons_submits'] = array_values($recent);
        $_SESSION['dons_donor'] = [
            'donor_name' => $clean['donor_name'], 'donor_email' => $clean['donor_email'],
            'donor_phone' => $clean['donor_phone'], 'stand_ref' => $clean['stand_ref'],
            'handover' => $clean['handover'],
        ];
        flash_set('Merci ! Votre proposition est enregistrée.');
        header('Location: /dons-suivi.php?t=' . $created['token'] . '&new=1');
        exit;
    }
}

// Le chronomètre anti-robot démarre à l'affichage du formulaire, pas à son
// réaffichage après une erreur (la personne y a déjà passé du temps).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['dons_form_at'] = time();
}
$val =fn(string $k, $default = '') => $values[$k] ?? $default;

unsold_public_head('Proposer un objet à donner');
?>
<section class="tight">
  <div class="don-wrap don-narrow">
    <p class="eyebrow">Invendus &amp; dons</p>
    <h1 style="font-size:1.9rem;margin:12px 0 12px;">Proposer un objet à donner</h1>

<?php if (!$event): ?>
    <p class="empty-state">Aucun vide-grenier n'accepte de propositions pour le moment.</p>
    <p><a class="btn btn-ghost" href="/dons.php">Retour</a></p>
<?php else: ?>
    <p class="lede">
      <?= h($event['name']) ?> — <?= h(unsold_fr_date($event['event_date'])) ?>.
      Vous donnez <strong>seulement si l'objet reste invendu</strong> : rien ne vous engage à le retirer de la vente.
      Une proposition = un objet ou un lot (« un carton de livres »).
    </p>

    <?php if ($errors): ?>
      <div class="don-errors" role="alert">
        <strong>Quelques points à corriger :</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form class="don-form" method="post" action="/dons-proposer.php" enctype="multipart/form-data" novalidate>
      <?= shop_csrf_input() ?>
      <div class="don-hp" aria-hidden="true">
        <label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
      </div>

      <?php if (count($open) > 1): ?>
        <div class="field">
          <label for="f-event">Vide-grenier</label>
          <select id="f-event" name="event_id">
            <?php foreach ($open as $e): ?>
              <option value="<?= (int) $e['id'] ?>"<?= (int) $e['id'] === (int) $event['id'] ? ' selected' : '' ?>><?= h($e['name']) ?> — <?= h(unsold_fr_date($e['event_date'])) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php else: ?>
        <input type="hidden" name="event_id" value="<?= (int) $event['id'] ?>">
      <?php endif; ?>

      <fieldset>
        <legend>Vous</legend>
        <div class="field-row-2">
          <div class="field">
            <label for="f-name">Votre nom ou prénom *</label>
            <input type="text" id="f-name" name="donor_name" value="<?= h($val('donor_name')) ?>" maxlength="80" required autocomplete="name">
          </div>
          <div class="field">
            <label for="f-stand">N° d'emplacement</label>
            <input type="text" id="f-stand" name="stand_ref" value="<?= h($val('stand_ref')) ?>" maxlength="20" placeholder="ex : B12">
          </div>
        </div>
        <div class="field-row-2">
          <div class="field">
            <label for="f-phone">Téléphone</label>
            <input type="tel" id="f-phone" name="donor_phone" value="<?= h($val('donor_phone')) ?>" maxlength="20" autocomplete="tel">
          </div>
          <div class="field">
            <label for="f-email">E-mail</label>
            <input type="email" id="f-email" name="donor_email" value="<?= h($val('donor_email')) ?>" maxlength="120" autocomplete="email">
          </div>
        </div>
        <p class="don-muted don-small" style="margin:-6px 0 14px;">Au moins un des deux. Jamais affichés publiquement : seuls les bénévoles les voient.</p>
      </fieldset>

      <fieldset>
        <legend>L'objet</legend>
        <div class="field">
          <label for="f-title">Que donnez-vous ? *</label>
          <input type="text" id="f-title" name="title" value="<?= h($val('title')) ?>" maxlength="100" required placeholder="ex : Carton de livres de poche">
        </div>
        <div class="field-row-2">
          <div class="field">
            <label for="f-cat">Catégorie</label>
            <select id="f-cat" name="category">
              <?php foreach (unsold_categories() as $key => $label): ?>
                <option value="<?= h($key) ?>"<?= $val('category') === $key ? ' selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="f-qty">Nombre de pièces</label>
            <input type="number" id="f-qty" name="quantity" value="<?= (int) $val('quantity', 1) ?>" min="1" max="999">
          </div>
        </div>
        <div class="field">
          <label for="f-desc">Détails (état, taille…)</label>
          <textarea id="f-desc" name="description" maxlength="600" rows="3"><?= h($val('description')) ?></textarea>
        </div>
        <div class="field">
          <label for="f-photo">Photo (facultatif)</label>
          <input type="file" id="f-photo" name="photo" accept="image/*">
          <small>JPEG, PNG ou WebP — 8 Mo maximum. Une photo aide les visiteurs à repérer l'objet.</small>
        </div>
        <div class="field">
          <label>Volume de l'ensemble</label>
          <div class="don-choices">
            <?php foreach (unsold_sizes() as $key => $s): ?>
              <label class="don-choice">
                <input type="radio" name="size" value="<?= h($key) ?>"<?= $val('size') === $key ? ' checked' : '' ?>>
                <div><b><?= h($s['label']) ?></b><span><?= h($s['hint']) ?></span></div>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Comment l'objet arrive au point de collecte ?</legend>
        <div class="don-choices">
          <label class="don-choice">
            <input type="radio" name="handover" value="self"<?= $val('handover') === 'self' ? ' checked' : '' ?>>
            <div><b>Je l'apporte moi-même</b><span>Au <?= h($event['point_name']) ?>, à partir de <?= h(unsold_fr_time($event['collect_start'])) ?>.</span></div>
          </label>
          <label class="don-choice">
            <input type="radio" name="handover" value="pickup"<?= $val('handover') === 'pickup' ? ' checked' : '' ?>>
            <div><b>Un bénévole passe le prendre à mon stand</b><span>Tournée à vélo cargo en fin de journée — indiquez bien votre emplacement.</span></div>
          </label>
        </div>
        <div class="field" id="pickup-fields">
          <label for="f-pnote">Précision pour retrouver l'objet (si ramassage)</label>
          <input type="text" id="f-pnote" name="pickup_note" value="<?= h($val('pickup_note')) ?>" maxlength="200" placeholder="ex : sous la table, à côté du camion bleu">
        </div>
      </fieldset>

      <label class="don-check">
        <input type="checkbox" name="consent" value="1"<?= !empty($values['consent']) ? ' checked' : '' ?>>
        <span>Je donne gratuitement cet objet s'il reste invendu, et j'accepte que mes coordonnées soient transmises aux bénévoles de l'organisation, uniquement pour organiser la remise.</span>
      </label>

      <button type="submit" class="btn btn-primary">Envoyer ma proposition</button>
      <a class="btn btn-ghost" href="/dons.php">Annuler</a>
    </form>

    <script>
    (function () {
      var radios = document.querySelectorAll('input[name="handover"]');
      var box = document.getElementById('pickup-fields');
      function sync() {
        var pick = document.querySelector('input[name="handover"]:checked');
        box.style.display = pick && pick.value === 'pickup' ? '' : 'none';
      }
      radios.forEach(function (r) { r.addEventListener('change', sync); });
      sync();
    })();
    </script>
<?php endif; ?>
  </div>
</section>
<?php unsold_public_foot(); ?>
