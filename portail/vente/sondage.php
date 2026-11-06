<?php
require __DIR__ . '/_bootstrap.php';
fv_seed_survey();

$slug = trim((string) ($_GET['slug'] ?? ''));
$survey = $slug !== '' ? fv_survey_by_slug($slug) : null;
$codeParam = trim((string) ($_GET['c'] ?? ($_POST['c'] ?? '')));

$memberId = 0;
if ($codeParam !== '') {
    $st = fv_db()->prepare('SELECT c.member_id FROM codes c JOIN members m ON m.id = c.member_id WHERE UPPER(c.code) = UPPER(?) AND c.active = 1 AND m.active = 1 LIMIT 1');
    $st->execute([$codeParam]);
    $memberId = (int) ($st->fetchColumn() ?: 0);
}

if (!$survey) {
    http_response_code(404);
    fv_public_start('Sondage introuvable');
    ?>
    <div class="fv-thanks">
      <span class="fv-mark" aria-hidden="true">?</span>
      <h1>Ce sondage n’existe pas</h1>
      <p>Le lien est peut-être incomplet ou le sondage a été retiré.</p>
      <p><a class="fv-btn" href="<?= e(rtrim(fv_setting('site_url', ''), '/')) ?>">Retour au site</a></p>
    </div>
    <?php
    fv_public_end();
    exit;
}

$brand = fv_setting('marque_nom', 'Brocs');
$questions = $survey['questions'];

if (!$survey['active']) {
    fv_public_start($survey['title']);
    ?>
    <div class="fv-public-head">
      <span class="fv-mark" aria-hidden="true"><?= e(mb_substr($brand, 0, 1)) ?></span>
      <h1><?= e($survey['title']) ?></h1>
      <p>Ce questionnaire est momentanément fermé. Merci de votre intérêt.</p>
    </div>
    <?php
    fv_public_end();
    exit;
}

$errors = [];
$old = ['name' => '', 'contact' => '', 'answers' => []];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    fv_csrf_check();
    $old['name'] = fv_text($_POST['respondent'] ?? '', 120);
    $old['contact'] = fv_text($_POST['contact'] ?? '', 190);
    $old['answers'] = (array) ($_POST['answers'] ?? []);

    // Piège à robots : rempli par les automates, ignoré silencieusement.
    if (trim((string) ($_POST['site'] ?? '')) !== '') {
        fv_redirect('sondage.php?slug=' . rawurlencode((string) $survey['slug']) . '&merci=1');
    }
    $started = (int) ($_POST['t'] ?? 0);
    if ($started > 0 && (time() - $started < 2 || time() - $started > 7200)) {
        $errors[] = 'Le formulaire a expiré. Rechargez la page et recommencez.';
    }

    $clean = [];
    foreach ($questions as $q) {
        $raw = $old['answers'][$q['id']] ?? '';
        $value = '';
        if ($q['type'] === 'choice') {
            $choice = fv_text($raw, 80);
            $value = in_array($choice, $q['options'], true) ? $choice : '';
        } elseif ($q['type'] === 'scale' || $q['type'] === 'scale10') {
            $max = $q['type'] === 'scale' ? 5 : 10;
            $n = (int) $raw;
            $value = $n >= 1 && $n <= $max ? (string) $n : '';
        } else {
            $value = fv_paragraph($raw, 1500);
        }
        if (!empty($q['required']) && $value === '') {
            $errors[] = 'Répondez à la question : « ' . $q['label'] . ' ».';
            continue;
        }
        if ($value !== '') $clean[$q['id']] = $value;
    }

    if (!$errors) {
        $st = fv_db()->prepare('INSERT INTO survey_responses (survey_id, member_id, prospect_id, respondent, contact, answers, created_at) VALUES (?,?,?,?,?,?,?)');
        $st->execute([
            (int) $survey['id'],
            $memberId,
            0,
            $old['name'],
            $old['contact'],
            json_encode($clean, JSON_UNESCAPED_UNICODE),
            fv_now(),
        ]);
        fv_redirect('sondage.php?slug=' . rawurlencode((string) $survey['slug']) . '&merci=1');
    }
}

fv_public_start($survey['title']);
?>
<div class="fv-public-head">
  <span class="fv-mark" aria-hidden="true"><?= e(mb_substr($brand, 0, 1)) ?></span>
  <h1><?= e($survey['title']) ?></h1>
  <?php if ($survey['intro'] !== ''): ?><p><?= nl2br(e($survey['intro'])) ?></p><?php endif; ?>
  <p class="fv-mono" style="font-size:.72rem;"><?= count($questions) ?> question<?= count($questions) > 1 ? 's' : '' ?> · moins de 3 minutes</p>
</div>

<?php if (isset($_GET['merci'])): ?>
  <div class="fv-thanks">
    <span class="fv-mark" aria-hidden="true">✓</span>
    <h1>Merci pour vos réponses</h1>
    <p>Elles nous aident à améliorer la boutique et l’accompagnement des commerces.</p>
    <p style="margin-top:18px;"><a class="fv-btn fv-btn-primary" href="<?= e(rtrim(fv_setting('site_url', ''), '/')) ?>">Retour au site</a></p>
  </div>
<?php else: ?>
  <?php if ($errors): ?>
    <div class="fv-flash" data-kind="error" role="alert" style="margin-bottom:14px;">
      <?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="fv-form" method="post" action="sondage.php?slug=<?= e(rawurlencode((string) $survey['slug'])) ?><?= $codeParam !== '' ? '&amp;c=' . e(rawurlencode($codeParam)) : '' ?>">
    <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
    <input type="hidden" name="t" value="<?= time() ?>">
    <?php if ($codeParam !== ''): ?><input type="hidden" name="c" value="<?= e($codeParam) ?>"><?php endif; ?>
    <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
      <label>Site web <input name="site" tabindex="-1" autocomplete="off"></label>
    </div>

    <div class="fv-form-grid">
      <label class="fv-field">Votre nom <span class="fv-hint">(facultatif)</span>
        <input name="respondent" maxlength="120" value="<?= e($old['name']) ?>" autocomplete="name">
      </label>
      <label class="fv-field">E-mail ou téléphone <span class="fv-hint">(facultatif)</span>
        <input name="contact" maxlength="190" value="<?= e($old['contact']) ?>" autocomplete="email">
      </label>
    </div>

    <?php foreach ($questions as $q): ?>
      <?php $current = (string) ($old['answers'][$q['id']] ?? ''); ?>
      <fieldset class="fv-survey-q">
        <legend><?= e($q['label']) ?><?= !empty($q['required']) ? ' *' : '' ?></legend>
        <?php if ($q['type'] === 'choice'): ?>
          <div class="fv-choices">
            <?php foreach ($q['options'] as $option): ?>
              <label><input type="radio" name="answers[<?= e($q['id']) ?>]" value="<?= e($option) ?>"<?= $current === $option ? ' checked' : '' ?> required> <?= e($option) ?></label>
            <?php endforeach; ?>
          </div>
        <?php elseif ($q['type'] === 'scale' || $q['type'] === 'scale10'): ?>
          <?php $max = $q['type'] === 'scale' ? 5 : 10; ?>
          <div class="fv-scale">
            <?php for ($n = 1; $n <= $max; $n++): ?>
              <label><input type="radio" name="answers[<?= e($q['id']) ?>]" value="<?= $n ?>"<?= $current === (string) $n ? ' checked' : '' ?> required><span><?= $n ?></span></label>
            <?php endfor; ?>
          </div>
          <span class="fv-hint" style="font-size:.72rem;">1 = très insuffisant · <?= $max ?> = excellent</span>
        <?php else: ?>
          <textarea name="answers[<?= e($q['id']) ?>]" rows="3" maxlength="1500" placeholder="Votre réponse…"<?= !empty($q['required']) ? ' required' : '' ?>><?= e($current) ?></textarea>
        <?php endif; ?>
      </fieldset>
    <?php endforeach; ?>

    <div class="fv-actions" style="margin-top:6px;">
      <button class="fv-btn fv-btn-primary" type="submit">Envoyer mes réponses</button>
      <span class="fv-hint">Vos réponses sont confidentielles et servent uniquement à améliorer le service.</span>
    </div>
  </form>
<?php endif; ?>
<?php fv_public_end(); ?>
