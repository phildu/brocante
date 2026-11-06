<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();
fv_seed_survey();

$ctx = fv_current();
$member = $ctx['member'];
$pdo = fv_db();
$manager = $ctx['manager'];
if (!$manager && (isset($_GET['resultats']) || isset($_GET['editer']) || isset($_GET['nouveau']))) {
    fv_flash('Les résultats détaillés et la création de sondages sont réservés à la direction.', 'error');
    fv_redirect('sondages.php');
}
$referral = $member ? fv_referral_code((int) $member['id']) : null;

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https' ? 'https' : 'http';
$scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/portail/vente/sondages.php'))), '/');
$baseUrl = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost') . $scriptDir;
$publicUrl = static function (array $survey, ?string $code = null) use ($baseUrl): string {
    return $baseUrl . '/sondage.php?slug=' . rawurlencode((string) $survey['slug']) . ($code ? '&c=' . rawurlencode($code) : '');
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    fv_csrf_check();
    if (!$manager) {
        fv_flash('Seule la direction peut modifier les sondages.', 'error');
        fv_redirect('sondages.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'enregistrer') {
            $id = (int) ($_POST['id'] ?? 0);
            $title = fv_text($_POST['title'] ?? '', 160);
            if ($title === '') {
                fv_flash('Donnez un titre au sondage.', 'error');
                fv_redirect('sondages.php' . ($id > 0 ? '?editer=' . $id : '?nouveau=1'));
            }
            $questions = fv_survey_questions_normalize(
                (array) ($_POST['q_label'] ?? []),
                (array) ($_POST['q_type'] ?? []),
                (array) ($_POST['q_options'] ?? []),
                (array) ($_POST['q_required'] ?? [])
            );
            if (!$questions) {
                fv_flash('Ajoutez au moins une question valide (un choix unique demande deux réponses possibles).', 'error');
                fv_redirect('sondages.php' . ($id > 0 ? '?editer=' . $id : '?nouveau=1'));
            }
            $active = !empty($_POST['active']) ? 1 : 0;
            $intro = fv_paragraph($_POST['intro'] ?? '', 600);
            if ($id > 0) {
                $survey = fv_survey_get($id);
                if (!$survey) {
                    fv_flash('Ce sondage n’existe plus.', 'error');
                    fv_redirect('sondages.php');
                }
                $pdo->prepare('UPDATE surveys SET title = ?, intro = ?, questions = ?, active = ? WHERE id = ?')
                    ->execute([$title, $intro, json_encode($questions, JSON_UNESCAPED_UNICODE), $active, $id]);
                fv_flash('Sondage mis à jour.');
                fv_redirect('sondages.php?resultats=' . $id);
            }
            $slug = fv_survey_slug($title);
            $candidate = $slug;
            $n = 2;
            $check = $pdo->prepare('SELECT 1 FROM surveys WHERE slug = ?');
            while (true) {
                $check->execute([$candidate]);
                if (!$check->fetchColumn()) break;
                $candidate = $slug . '-' . $n++;
            }
            $pdo->prepare('INSERT INTO surveys (slug, title, intro, questions, active, created_at) VALUES (?,?,?,?,?,?)')
                ->execute([$candidate, $title, $intro, json_encode($questions, JSON_UNESCAPED_UNICODE), $active, fv_now()]);
            $newId = (int) $pdo->lastInsertId();
            fv_flash('Sondage créé. Le lien public est prêt à être partagé.');
            fv_redirect('sondages.php?resultats=' . $newId);
        }

        if ($action === 'basculer' || $action === 'supprimer') {
            $id = (int) ($_POST['id'] ?? 0);
            $survey = $id > 0 ? fv_survey_get($id) : null;
            if (!$survey) {
                fv_flash('Ce sondage n’existe plus.', 'error');
                fv_redirect('sondages.php');
            }
            if ($action === 'supprimer') {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM survey_responses WHERE survey_id = ?')->execute([$id]);
                $pdo->prepare('DELETE FROM surveys WHERE id = ?')->execute([$id]);
                $pdo->commit();
                fv_flash('Sondage et réponses supprimés.');
            } else {
                $pdo->prepare('UPDATE surveys SET active = ? WHERE id = ?')->execute([$survey['active'] ? 0 : 1, $id]);
                fv_flash($survey['active'] ? 'Sondage mis en pause.' : 'Sondage ouvert aux réponses.');
            }
            fv_redirect('sondages.php');
        }

        fv_flash('Action inconnue.', 'error');
        fv_redirect('sondages.php');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('vente/sondages : ' . $e->getMessage());
        fv_flash('L’enregistrement a échoué. Réessayez.', 'error');
        fv_redirect('sondages.php');
    }
}

$editId = (int) ($_GET['editer'] ?? 0);
$isNew = isset($_GET['nouveau']);
$editing = $editId > 0 ? fv_survey_get($editId) : null;
if ($editId > 0 && !$editing) {
    fv_flash('Ce sondage n’existe plus.', 'error');
    fv_redirect('sondages.php');
}
$showForm = $manager && ($isNew || $editing);

$list = $pdo->query('SELECT s.*, (SELECT COUNT(*) FROM survey_responses r WHERE r.survey_id = s.id) AS responses FROM surveys s ORDER BY s.created_at DESC, s.id DESC')->fetchAll();
foreach ($list as &$row) {
    $row['questions'] = json_decode((string) $row['questions'], true) ?: [];
}
unset($row);

$resultId = (int) ($_GET['resultats'] ?? 0);
$resultSurvey = $resultId > 0 ? fv_survey_get($resultId) : null;
if ($resultId > 0 && !$resultSurvey) {
    fv_flash('Ce sondage n’existe plus.', 'error');
    fv_redirect('sondages.php');
}
$results = $resultSurvey ? fv_survey_results($resultSurvey) : null;

if ($resultSurvey && isset($_GET['export'])) {
    $header = ['Date', 'Répondant', 'Contact', 'Commercial', 'Code'];
    foreach ($resultSurvey['questions'] as $q) $header[] = $q['label'];
    $rows = [$header];
    foreach ($results['responses'] as $response) {
        $answers = json_decode((string) $response['answers'], true) ?: [];
        $line = [
            $response['created_at'],
            $response['respondent'],
            $response['contact'],
            '',
            '',
        ];
        $memberName = '';
        if ((int) $response['member_id'] > 0) {
            $st = $pdo->prepare('SELECT name FROM members WHERE id = ?');
            $st->execute([(int) $response['member_id']]);
            $memberName = (string) ($st->fetchColumn() ?: '');
        }
        $line[3] = $memberName;
        foreach ($resultSurvey['questions'] as $q) {
            $value = $answers[$q['id']] ?? '';
            if ($q['type'] === 'scale10' && $value !== '') $value .= '/10';
            if ($q['type'] === 'scale' && $value !== '') $value .= '/5';
            $line[] = is_array($value) ? implode(', ', $value) : (string) $value;
        }
        $rows[] = $line;
    }
    fv_csv_download('sondage-' . preg_replace('/[^a-z0-9-]+/', '-', (string) $resultSurvey['slug']) . '-' . date('Y-m-d') . '.csv', $rows);
}

$formQuestions = $editing && $editing['questions'] ? $editing['questions'] : [['label' => '', 'type' => 'choice', 'options' => [], 'required' => false]];
$formTitle = $editing['title'] ?? '';
$formIntro = $editing['intro'] ?? '';
$formActive = $editing ? (bool) $editing['active'] : true;

fv_layout_start($resultSurvey ? 'Résultats · ' . $resultSurvey['title'] : 'Sondages', [
    'eyebrow' => $resultSurvey ? 'Réponses collectées' : 'Voix du terrain',
    'desc' => $resultSurvey
        ? 'Les réponses arrivent en direct. Partagez le lien, puis lisez les tendances et les verbatims.'
        : 'Créez un questionnaire, partagez un lien, lisez les réponses. Les commerciaux peuvent suivre leur code pour savoir qui a répondu.',
    'wide' => true,
    'actions' => $resultSurvey
        ? '<a class="fv-btn fv-btn-sm" href="sondages.php">' . fv_icon('arrow', 14) . ' Tous les sondages</a>'
        : ($manager ? '<a class="fv-btn fv-btn-sm fv-btn-primary" href="sondages.php?nouveau=1">' . fv_icon('plus', 14) . ' Nouveau sondage</a>' : ''),
]);
?>

<?php if ($resultSurvey && $results): ?>
  <?php
  $shareCode = $referral ? (string) $referral['code'] : null;
  $shareUrl = $publicUrl($resultSurvey, $shareCode);
  ?>
  <div class="fv-kpis fv-kpis-4" style="margin-bottom:16px;">
    <div class="fv-kpi"><span class="n"><?= count($results['responses']) ?></span><span class="l">réponse<?= count($results['responses']) > 1 ? 's' : '' ?></span></div>
    <div class="fv-kpi"><span class="n"><?= count($resultSurvey['questions']) ?></span><span class="l">questions</span></div>
    <div class="fv-kpi"><span class="n"><?= $resultSurvey['active'] ? 'Ouvert' : 'En pause' ?></span><span class="l">état du sondage</span></div>
    <div class="fv-kpi"><span class="n" style="font-size:.95rem;line-height:1.3;"><?= e(fv_date($resultSurvey['created_at'])) ?></span><span class="l">créé le</span></div>
  </div>

  <div class="fv-grid fv-grid-2-1">
    <div class="fv-grid" style="gap:14px;">
      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Résultats par question</h2><p>Les tendances se lisent mieux à partir de dix réponses.</p></div></div>
        <div class="fv-card-body" style="display:grid;gap:18px;">
          <?php foreach ($resultSurvey['questions'] as $q): $stat = $results['stats'][$q['id']] ?? ['counts' => [], 'texts' => [], 'average' => null, 'total' => 0]; ?>
            <div>
              <p style="font-weight:600;margin-bottom:8px;"><?= e($q['label']) ?> <span class="fv-sub">· <?= (int) $stat['total'] ?> réponse<?= (int) $stat['total'] > 1 ? 's' : '' ?></span></p>
              <?php if ($q['type'] === 'choice'): ?>
                <?php $max = max(1, ...array_values($stat['counts'] ?: [1])); foreach ($stat['counts'] as $option => $count): ?>
                  <div class="fv-answer-bar">
                    <span><?= e($option) ?></span>
                    <div class="track"><div class="fill" style="width:<?= (int) round(($count / $max) * 100) ?>%"></div></div>
                    <span class="n"><?= (int) $count ?></span>
                  </div>
                <?php endforeach; ?>
              <?php elseif ($q['type'] === 'scale' || $q['type'] === 'scale10'): ?>
                <p class="fv-mono" style="font-size:1.3rem;"><?= $stat['average'] !== null ? str_replace('.', ',', (string) $stat['average']) : '—' ?> <span class="fv-sub">/ <?= $q['type'] === 'scale' ? 5 : 10 ?></span></p>
                <?php
                $maxNote = $q['type'] === 'scale' ? 5 : 10;
                $dist = array_fill(1, $maxNote, 0);
                foreach ($stat['values'] as $v) { if ($v >= 1 && $v <= $maxNote) $dist[$v]++; }
                $maxCount = max(1, ...array_values($dist));
                ?>
                <?php for ($v = 1; $v <= $maxNote; $v++): ?>
                  <div class="fv-answer-bar"><span><?= $v ?></span><div class="track"><div class="fill" style="width:<?= (int) round(($dist[$v] / $maxCount) * 100) ?>%"></div></div><span class="n"><?= (int) $dist[$v] ?></span></div>
                <?php endfor; ?>
              <?php else: ?>
                <?php if (!$stat['texts']): ?><p class="fv-hint">Aucune réponse écrite.</p><?php else: ?>
                  <div class="fv-list" style="border:1px solid var(--fv-line);border-radius:9px;">
                    <?php foreach (array_slice($stat['texts'], 0, 12) as $text): ?><div class="fv-list-item" style="cursor:default;"><span><?= nl2br(e($text)) ?></span></div><?php endforeach; ?>
                  </div>
                  <?php if (count($stat['texts']) > 12): ?><p class="fv-hint"><?= count($stat['texts']) - 12 ?> autre<?= count($stat['texts']) - 12 > 1 ? 's' : '' ?> réponse<?= count($stat['texts']) - 12 > 1 ? 's' : '' ?> dans l’export CSV.</p><?php endif; ?>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Réponses</h2><p>Par ordre d’arrivée, la plus récente d’abord.</p></div><span class="fv-badge"><?= count($results['responses']) ?></span></div>
        <div class="fv-table-wrap">
          <?php if (!$results['responses']): ?>
            <div class="fv-empty"><b>Aucune réponse pour l’instant</b>Partagez le lien public pour lancer la collecte.</div>
          <?php else: ?>
            <table class="fv-table">
              <thead><tr><th>Date</th><th>Répondant</th><th>Contact</th><th>Commercial</th></tr></thead>
              <tbody>
                <?php foreach ($results['responses'] as $response): ?>
                  <?php
                  $answers = json_decode((string) $response['answers'], true) ?: [];
                  $respondent = $response['respondent'] !== '' ? $response['respondent'] : 'Anonyme';
                  $by = '';
                  if ((int) $response['member_id'] > 0) {
                      $st = $pdo->prepare('SELECT name FROM members WHERE id = ?');
                      $st->execute([(int) $response['member_id']]);
                      $by = (string) ($st->fetchColumn() ?: '');
                  }
                  ?>
                  <tr>
                    <td><span class="sub"><?= e(fv_datetime($response['created_at'])) ?></span></td>
                    <td>
                      <b><?= e($respondent) ?></b>
                      <details><summary class="fv-linkbtn" style="font-size:.78rem;">Voir les réponses</summary>
                        <div style="margin-top:6px;display:grid;gap:5px;font-size:.78rem;">
                          <?php foreach ($resultSurvey['questions'] as $q): $value = $answers[$q['id']] ?? ''; if ($value === '') continue; ?>
                            <span><b><?= e($q['label']) ?></b> — <?= e(is_array($value) ? implode(', ', $value) : (string) $value) ?></span>
                          <?php endforeach; ?>
                        </div>
                      </details>
                    </td>
                    <td><span class="sub"><?= e($response['contact'] !== '' ? $response['contact'] : '—') ?></span></td>
                    <td><span class="sub"><?= e($by !== '' ? $by : '—') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="fv-grid" style="gap:14px;">
      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Partager</h2><p>Lien public, sans connexion.</p></div></div>
        <div class="fv-card-body">
          <div class="fv-readonly"><?= e($shareUrl) ?></div>
          <div class="fv-actions" style="margin-top:10px;">
            <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($shareUrl) ?>">Copier le lien</button>
            <a class="fv-btn fv-btn-sm" href="<?= e($shareUrl) ?>" target="_blank" rel="noopener">Ouvrir</a>
            <a class="fv-btn fv-btn-sm fv-btn-ghost" href="sondages.php?resultats=<?= (int) $resultSurvey['id'] ?>&amp;export=1"><?= fv_icon('download', 13) ?> CSV</a>
          </div>
          <?php if ($shareCode): ?><p class="fv-hint">Votre code <?= e($shareCode) ?> est ajouté au lien : les réponses vous seront attribuées.</p><?php endif; ?>
        </div>
      </section>

      <section class="fv-card">
        <div class="fv-card-head"><div><h2>Bon à savoir</h2></div></div>
        <div class="fv-card-body fv-hint">
          <p><b>Quand l’envoyer ?</b> 48 h après la création d’une boutique, puis à J+30 pour mesurer l’usage.</p>
          <p><b>Comment ?</b> Par e-mail, en fin de rendez-vous, ou via un QR code sur le flyer.</p>
          <p><b>Combien de réponses ?</b> À partir de dix, les tendances sont lisibles. Ne surinterprétez pas trois réponses.</p>
          <ul style="padding-left:18px;">
            <li><?= count($results['responses']) ?> réponse<?= count($results['responses']) > 1 ? 's' : '' ?> collectée<?= count($results['responses']) > 1 ? 's' : '' ?>.</li>
            <li>Les textes libres sont dans l’export CSV complet.</li>
          </ul>
        </div>
      </section>
    </aside>
  </div>

<?php else: ?>

<?php if ($showForm): ?>
<section class="fv-card">
  <div class="fv-card-head">
    <div><h2><?= $editing ? 'Modifier le sondage' : 'Nouveau sondage' ?></h2><p>Jusqu’à douze questions. Un choix unique demande au moins deux réponses possibles.</p></div>
    <a class="fv-btn fv-btn-sm" href="sondages.php">Fermer</a>
  </div>
  <div class="fv-card-body">
    <form class="fv-form" method="post" action="sondages.php">
      <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
      <input type="hidden" name="action" value="enregistrer">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
      <div class="fv-form-grid">
        <label class="fv-field">Titre
          <input name="title" maxlength="160" required value="<?= e($formTitle) ?>" placeholder="Satisfaction après création de boutique">
        </label>
        <label class="fv-field">Introduction
          <input name="intro" maxlength="600" value="<?= e($formIntro) ?>" placeholder="Deux phrases pour situer la démarche.">
        </label>
      </div>
      <label class="fv-check"><input type="checkbox" name="active" value="1"<?= $formActive ? ' checked' : '' ?>> Ouvrir le sondage aux réponses</label>

      <div data-question-builder style="display:grid;gap:10px;margin-top:6px;">
        <?php foreach ($formQuestions as $q): ?>
          <div class="fv-question-row" data-question-row>
            <div class="fv-form-grid">
              <label class="fv-field full">Question
                <input name="q_label[]" maxlength="200" value="<?= e($q['label'] ?? '') ?>" placeholder="Comment s’est passée la création ?">
              </label>
              <label class="fv-field">Type
                <select name="q_type[]">
                  <?php foreach (fv_question_types() as $key => $label): ?><option value="<?= e($key) ?>"<?= ($q['type'] ?? '') === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label class="fv-field">Réponse obligatoire
                <select name="q_required[]">
                  <option value="0"<?= empty($q['required']) ? ' selected' : '' ?>>Facultative</option>
                  <option value="1"<?= !empty($q['required']) ? ' selected' : '' ?>>Obligatoire</option>
                </select>
              </label>
              <label class="fv-field full">Réponses possibles (choix unique uniquement)
                <textarea name="q_options[]" rows="2" placeholder="Très simple; Simple; Compliquée"><?= e(implode('; ', (array) ($q['options'] ?? []))) ?></textarea>
              </label>
            </div>
            <button class="fv-btn fv-btn-sm fv-btn-ghost" type="button" data-remove-question>Retirer cette question</button>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="fv-actions">
        <button class="fv-btn" type="button" data-add-question><?= fv_icon('plus', 14) ?> Ajouter une question</button>
        <button class="fv-btn fv-btn-primary" type="submit"><?= $editing ? 'Enregistrer' : 'Créer le sondage' ?></button>
      </div>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="fv-card">
  <div class="fv-card-head">
    <div><h2>Sondages <small class="fv-sub"><?= count($list) ?></small></h2><p>Un lien, des réponses, des décisions. Le premier sondage est créé automatiquement.</p></div>
    <?php if ($manager && !$showForm): ?><a class="fv-btn fv-btn-sm fv-btn-primary" href="sondages.php?nouveau=1"><?= fv_icon('plus', 14) ?> Nouveau</a><?php endif; ?>
  </div>
  <div class="fv-card-body" style="display:grid;gap:10px;">
    <?php foreach ($list as $survey): ?>
      <article class="fv-list-item" style="cursor:default;align-items:flex-start;">
        <span style="min-width:0;flex:1;">
          <b><?= e($survey['title']) ?> <span class="fv-badge" data-s="<?= $survey['active'] ? 'boutique_creee' : 'perdu' ?>"><?= $survey['active'] ? 'Ouvert' : 'En pause' ?></span></b>
          <small><?= e(mb_substr((string) $survey['intro'], 0, 140)) ?></small>
          <small><?= count($survey['questions']) ?> question<?= count($survey['questions']) > 1 ? 's' : '' ?> · <?= (int) $survey['responses'] ?> réponse<?= (int) $survey['responses'] > 1 ? 's' : '' ?> · créé le <?= e(fv_date($survey['created_at'])) ?></small>
          <div class="fv-actions" style="margin-top:6px;">
            <?php if ($manager): ?><a class="fv-btn fv-btn-sm" href="sondages.php?resultats=<?= (int) $survey['id'] ?>">Résultats</a><?php endif; ?>
            <button class="fv-btn fv-btn-sm" type="button" data-copy="<?= e($publicUrl($survey, $referral ? (string) $referral['code'] : null)) ?>">Copier le lien</button>
            <?php if ($manager): ?>
              <a class="fv-btn fv-btn-sm" href="sondages.php?editer=<?= (int) $survey['id'] ?>">Modifier</a>
              <form method="post" action="sondages.php" style="display:inline;">
                <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                <input type="hidden" name="action" value="basculer">
                <input type="hidden" name="id" value="<?= (int) $survey['id'] ?>">
                <button class="fv-btn fv-btn-sm fv-btn-ghost" type="submit"><?= $survey['active'] ? 'Mettre en pause' : 'Ouvrir' ?></button>
              </form>
              <form method="post" action="sondages.php" style="display:inline;" data-confirm="Supprimer ce sondage et toutes ses réponses ?">
                <input type="hidden" name="csrf" value="<?= e(fv_csrf()) ?>">
                <input type="hidden" name="action" value="supprimer">
                <input type="hidden" name="id" value="<?= (int) $survey['id'] ?>">
                <button class="fv-btn fv-btn-sm fv-btn-danger" type="submit">Supprimer</button>
              </form>
            <?php endif; ?>
          </div>
        </span>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<?php endif; ?>

<?php fv_layout_end(); ?>
