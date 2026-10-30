<?php
// Assistant d'organisation d'une galerie commerciale (vide-grenier, marché aux puces, marché de quartier) :
// démarrage pas à pas, rétroplanning, choix de date et veille des événements concurrents, questionnaire de sécurité,
// modèles de documents administratifs, contacts et sondage, plan de financement de la numérisation.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-assistant.php';
require_once PORTAIL_ROOT . '/includes/gallery-assistant-docs.php';

$tabs = [
    'demarrage' => 'Démarrage',
    'planning' => 'Rétroplanning',
    'date' => 'Date et concurrence',
    'securite' => 'Sécurité',
    'documents' => 'Documents',
    'contacts' => 'Contacts et sondage',
    'aides' => 'Financement',
    'plus' => 'Communication et bilan',
];

$self = '/portail/galerie-assistant.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$url = static function (array $params = []) use ($self): string {
    $sep = str_contains($self, '?') ? '&' : '?';
    return $self . ($params ? $sep . http_build_query($params) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $data = gass_read($gaSlug);
    $action = (string) ($_POST['action'] ?? '');
    $vue = (string) ($_POST['vue'] ?? 'demarrage');
    if (!isset($tabs[$vue])) $vue = 'demarrage';
    $err = null;
    $msg = 'Enregistré.';
    $save = true;

    switch ($action) {
        case 'profile':
            $data['profile'] = [
                'organizer' => (string) ($_POST['organizer'] ?? ''),
                'structure' => (string) ($_POST['structure'] ?? ''),
                'contact' => (string) ($_POST['contact'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'phone' => (string) ($_POST['phone'] ?? ''),
                'target_date' => (string) ($_POST['target_date'] ?? ''),
                'attendance' => (int) ($_POST['attendance'] ?? 0),
                'commune' => (string) ($_POST['commune'] ?? ''),
                'region' => (string) ($_POST['region'] ?? ''),
                'siret' => (string) ($_POST['siret'] ?? ''),
                'notes' => (string) ($_POST['notes'] ?? ''),
            ];
            $msg = 'Fiche de l’organisateur enregistrée.';
            break;

        case 'target_date':
            $d = (string) ($_POST['target_date'] ?? '');
            if ($d !== '' && !gass_date_valid($d)) {
                $err = 'Date invalide.';
            } else {
                $data['profile']['target_date'] = $d;
                $msg = $d !== '' ? 'Date de travail : ' . gass_date_fr($d) . '.' : 'Date de travail effacée.';
            }
            break;

        case 'task':
            $key = (string) ($_POST['key'] ?? '');
            if (!preg_match('/^plan-[a-z-]{1,30}$/', $key)) {
                $err = 'Tâche inconnue.';
            } else {
                $done = isset($data['tasks'][$key]);
                if ($done) unset($data['tasks'][$key]); else $data['tasks'][$key] = time();
                $msg = $done ? 'Tâche rouverte.' : 'Tâche cochée.';
            }
            break;

        case 'date_choose':
            $d = (string) ($_POST['date'] ?? '');
            if ($d === '') {
                $data['dates']['chosen'] = '';
                $msg = 'Date retenue effacée.';
            } elseif (gass_date_valid($d)) {
                $data['dates']['chosen'] = $d;
                $msg = 'Date retenue : ' . gass_date_fr($d) . '.';
            } else {
                $err = 'Date invalide.';
            }
            break;

        case 'candidate_add':
            $d = (string) ($_POST['date'] ?? '');
            if (!gass_date_valid($d)) {
                $err = 'Choisissez une date valide.';
            } elseif (count($data['dates']['candidates']) >= GASS_MAX_CANDIDATES) {
                $err = 'Vous pouvez proposer au maximum ' . GASS_MAX_CANDIDATES . ' dates.';
            } else {
                $data['dates']['candidates'][] = ['id' => substr(bin2hex(random_bytes(4)), 0, 8), 'date' => $d, 'label' => (string) ($_POST['label'] ?? '')];
                $msg = 'Date ajoutée aux options du sondage.';
            }
            break;

        case 'candidate_del':
            $id = (string) ($_POST['id'] ?? '');
            $data['dates']['candidates'] = array_values(array_filter((array) $data['dates']['candidates'], static fn ($c) => (string) $c['id'] !== $id));
            $msg = 'Date retirée des options.';
            break;

        case 'competitor_add':
            $d = (string) ($_POST['date'] ?? '');
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!gass_date_valid($d) || $name === '') {
                $err = 'Renseignez au moins le nom et la date de l’événement concurrent.';
            } elseif (count($data['dates']['competitors']) >= GASS_MAX_COMPETITORS) {
                $err = 'Vous pouvez suivre au maximum ' . GASS_MAX_COMPETITORS . ' événements.';
            } else {
                $data['dates']['competitors'][] = ['id' => substr(bin2hex(random_bytes(4)), 0, 8), 'name' => $name,
                    'date' => $d, 'city' => (string) ($_POST['city'] ?? ''), 'url' => (string) ($_POST['url'] ?? '')];
                $msg = 'Événement concurrent ajouté à la veille.';
            }
            break;

        case 'competitor_del':
            $id = (string) ($_POST['id'] ?? '');
            $data['dates']['competitors'] = array_values(array_filter((array) $data['dates']['competitors'], static fn ($c) => (string) $c['id'] !== $id));
            $msg = 'Événement retiré de la veille.';
            break;

        case 'security':
            $answers = is_array($_POST['answers'] ?? null) ? (array) $_POST['answers'] : [];
            $data['security'] = ['answers' => $answers, 'updated' => time()];
            $msg = 'Questionnaire de sécurité enregistré.';
            break;

        case 'doc_ready':
            $kind = (string) ($_POST['kind'] ?? '');
            if (!isset(gass_document_catalog()[$kind])) {
                $err = 'Document inconnu.';
            } else {
                $ready = isset($data['docs'][$kind]);
                if ($ready) unset($data['docs'][$kind]); else $data['docs'][$kind] = time();
                $msg = $ready ? 'Document marqué à revoir.' : 'Document marqué comme prêt.';
            }
            break;

        case 'survey_save':
            $data['survey']['enabled'] = !empty($_POST['enabled']);
            $data['survey']['open'] = $data['survey']['enabled'] && !empty($_POST['open']);
            $data['survey']['title'] = (string) ($_POST['title'] ?? '');
            $data['survey']['intro'] = (string) ($_POST['intro'] ?? '');
            $msg = 'Sondage enregistré.' . ($data['survey']['open'] ? ' Le lien public est actif.' : ' Le sondage est fermé au public.');
            break;

        case 'survey_clear':
            $save = false;
            if (ga_write($gaSlug, 'sondage', ['responses' => [], 'hits' => []])) {
                $msg = 'Toutes les réponses au sondage ont été supprimées.';
            } else {
                $err = 'Impossible de supprimer les réponses (droits du dossier data).';
            }
            break;

        case 'aid_save':
            $labels = (array) ($_POST['budget_label'] ?? []);
            $amounts = (array) ($_POST['budget_amount'] ?? []);
            $budget = [];
            foreach (array_slice($labels, 0, GASS_MAX_BUDGET_LINES, true) as $i => $label) {
                $label = trim((string) $label);
                $amount = gass_cents($amounts[$i] ?? 0);
                if ($label === '' && $amount === 0) continue;
                $budget[] = ['label' => $label ?: 'Poste de dépense', 'amount_cents' => $amount];
            }
            $data['aid'] = [
                'project' => (string) ($_POST['project'] ?? ''),
                'needs' => (string) ($_POST['needs'] ?? ''),
                'budget' => $budget,
                'requested_cents' => gass_cents($_POST['requested_eur'] ?? 0),
                'own_cents' => gass_cents($_POST['own_eur'] ?? 0),
                'other_cents' => gass_cents($_POST['other_eur'] ?? 0),
                'commune' => (string) ($_POST['commune'] ?? ''),
                'region' => (string) ($_POST['region'] ?? ''),
                'contact' => (string) ($_POST['contact'] ?? ''),
            ];
            $msg = 'Plan de financement enregistré.';
            break;
    }

    if ($err !== null) {
        portail_flash($err, 'error');
    } elseif ($save) {
        $ok = gass_save($gaSlug, $data);
        portail_flash($ok ? $msg : 'Impossible d’enregistrer (droits du dossier data).', $ok ? 'ok' : 'error');
    } else {
        portail_flash($msg, 'ok');
    }
    header('Location: ' . $url(['vue' => $vue]));
    exit;
}

$data = gass_read($gaSlug);
$event = gass_event($gaGallery);
$target = gass_target_date($gaGallery, $data);

// ── Téléchargements CSV ────────────────────────────────────────────────────
$export = (string) ($_GET['export'] ?? '');
if ($export === 'exposants' || $export === 'sondage') {
    $safe = static function ($v): string {
        $v = (string) $v;
        return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v;
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . ($export === 'exposants' ? 'contacts-exposants-' : 'sondage-') . $gaSlug . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($export === 'exposants') {
        fputcsv($out, ['Nom', 'E-mail', 'Téléphone', 'Emplacement', 'Source', 'Statut'], ';', '"', '');
        foreach (gass_former_exhibitors($gaGallery) as $row) {
            fputcsv($out, [$safe($row['name']), $safe($row['email']), $safe($row['phone']), $safe($row['slot']), $safe($row['source']), $safe($row['status'])], ';', '"', '');
        }
    } else {
        $questions = $data['survey']['questions'] ?: gass_survey_questions($data);
        $head = ['Date', 'E-mail', 'Accord de contact'];
        foreach ($questions as $q) $head[] = $q['label'];
        fputcsv($out, array_map($safe, $head), ';', '"', '');
        foreach (gass_survey_responses($gaSlug) as $response) {
            $line = [date('d/m/Y H:i', (int) ($response['created'] ?? 0)), $safe($response['email'] ?? ''), !empty($response['consent']) ? 'oui' : 'non'];
            foreach ($questions as $q) {
                $value = $response['answers'][$q['key']] ?? '';
                if (is_array($value)) {
                    $value = implode(' | ', array_map(static fn ($v) => (string) ($q['options'][$v] ?? $v), $value));
                }
                $line[] = $safe($value);
            }
            fputcsv($out, $line, ';', '"', '');
        }
    }
    fclose($out);
    exit;
}

// ── Aperçu et téléchargement d'un document ─────────────────────────────────
$docKind = (string) ($_GET['doc'] ?? '');
if ($docKind !== '') {
    $doc = gass_document($docKind, $gaGallery, $data);
    if (!$doc) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Document inconnu.');
    }
    $download = isset($_GET['telecharger']);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex, nofollow');
    if ($download) header('Content-Disposition: attachment; filename="' . $doc['filename'] . '"');
    $toolbar = '';
    if (!$download) {
        $toolbar = '<div class="doc-toolbar">'
            . '<a class="btn small" href="' . e($url(['vue' => 'documents'])) . '">← Retour aux documents</a>'
            . '<button class="btn small btn-primary" type="button" onclick="window.print()">Imprimer / PDF</button>'
            . '<a class="btn small" href="' . e($url(['doc' => $docKind, 'telecharger' => 1])) . '">Télécharger le fichier</a>'
            . '</div>';
    }
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . e($doc['title']) . '</title>' . gassd_css()
        . '<style>body{margin:0;background:#eceae4;}.doc-toolbar{position:sticky;top:0;z-index:5;display:flex;gap:8px;flex-wrap:wrap;justify-content:center;padding:10px 16px;background:#fff;border-bottom:1px solid #ddd;}'
        . '.paper{background:#fff;max-width:210mm;margin:18px auto;padding:14mm;box-shadow:0 2px 24px rgba(0,0,0,.08);}'
        . '@media print{body{background:#fff;}.doc-toolbar{display:none;}.paper{box-shadow:none;margin:0;padding:0;max-width:none;}@page{size:A4;margin:15mm 14mm;}}</style>'
        . '</head><body>' . $toolbar . '<div class="paper"><div class="doc">' . $doc['html'] . '</div></div></body></html>';
    exit;
}

// ── Données d'affichage ────────────────────────────────────────────────────
$vue = (string) ($_GET['vue'] ?? 'demarrage');
if (!isset($tabs[$vue])) $vue = 'demarrage';
$checklist = gass_checklist($gaGallery, $data);
$security = gass_security_result((array) ($data['security']['answers'] ?? []));
$former = gass_former_exhibitors($gaGallery);
$surveyResults = gass_survey_results($gaSlug, $data);
$plan = gass_aid_plan($data);
$targetText = $target !== '' ? gass_date_fr($target) : 'Date non définie';
$catalog = gass_document_catalog();
$eventDate = (string) ($event['date'] ?? '');
$surveyOn = !empty($data['survey']['enabled']);
$surveyOpen = $surveyOn && !empty($data['survey']['open']);
$surveyUrlValue = gass_survey_url($gaSlug);
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
    . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">')
    . '<input type="hidden" name="vue" value="' . e($vue) . '">';

ga_head('Assistant');
?>
<style>
  .gass-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
  .gass-progress { height: 10px; border-radius: 999px; background: var(--panel-2); border: 1px solid var(--line); overflow: hidden; }
  .gass-progress span { display: block; height: 100%; background: var(--ok); transition: width .2s; }
  .gass-check { display: grid; grid-template-columns: 14px 1fr auto; gap: 10px; align-items: start; padding: 9px 0; border-bottom: 1px solid var(--line); }
  .gass-check:last-child { border-bottom: 0; }
  .gass-dot { width: 12px; height: 12px; border-radius: 50%; border: 2px solid var(--line-strong); margin-top: 4px; }
  .gass-check.done .gass-dot { background: var(--ok); border-color: var(--ok); }
  .gass-check.done strong { opacity: .6; }
  .gass-q { padding: 10px 0; border-bottom: 1px solid var(--line); display: grid; gap: 7px; }
  .gass-q:last-of-type { border-bottom: 0; }
  .gass-opt { display: inline-flex; gap: 6px; align-items: center; border: 1px solid var(--line); border-radius: 8px; padding: 6px 10px; font-size: .85rem; background: var(--bg); cursor: pointer; }
  .gass-opt input { accent-color: var(--accent); }
  .gass-doc { display: grid; grid-template-columns: 1fr auto; gap: 10px; align-items: start; padding: 11px 0; border-bottom: 1px solid var(--line); }
  .gass-doc:last-child { border-bottom: 0; }
  .gass-bar { height: 8px; background: var(--panel-2); border: 1px solid var(--line); border-radius: 999px; overflow: hidden; min-width: 80px; }
  .gass-bar span { display: block; height: 100%; background: var(--accent); }
  .gass-date-card { border: 1px solid var(--line); border-radius: 10px; padding: 12px; display: grid; gap: 8px; background: var(--bg); }
  .gass-score { font: 700 1.05rem/1 "IBM Plex Mono", monospace; }
  .gass-line { display: flex; gap: 8px; align-items: center; }
  @media (max-width: 640px) { .gass-doc { grid-template-columns: 1fr; } }
</style>

<section class="gcard" style="gap:12px;">
  <div class="row" style="justify-content:space-between;align-items:flex-start;">
    <div>
      <h2 style="margin:0;">Assistant d’organisation</h2>
      <p class="meta" style="margin:4px 0 0;">Préparez votre <?= e(gt_word($gaGallery, 'gallery')) ?> pas à pas : rétroplanning, date, sécurité, documents, contacts et financement.</p>
    </div>
    <span class="pill<?= $checklist['percent'] >= 80 ? ' done' : '' ?>"><?= (int) $checklist['percent'] ?> % prêt</span>
  </div>
  <div class="gass-progress" aria-hidden="true"><span style="width:<?= (int) $checklist['percent'] ?>%"></span></div>
  <p class="meta" style="margin:0;"><?= (int) $checklist['done'] ?> étape(s) sur <?= (int) $checklist['total'] ?> · date de travail : <strong><?= e($targetText) ?></strong> · <?= (int) $surveyResults['total'] ?> réponse(s) au sondage</p>
  <div class="gass-tabs">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="btn<?= $vue === $key ? ' btn-primary' : '' ?>" href="<?= e($url(['vue' => $key])) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
</section>

<?php if ($vue === 'demarrage'): ?>
  <div class="kpis">
    <div class="kpi"><b><?= (int) $checklist['percent'] ?> %</b><span>mise en place</span></div>
    <div class="kpi"><b><?= e($target !== '' ? date('d/m/Y', strtotime($target . ' 12:00:00')) : '—') ?></b><span>date de travail</span></div>
    <div class="kpi"><b><?= count($former) ?></b><span>contacts exposants</span></div>
    <div class="kpi"><b><?= (int) $surveyResults['total'] ?></b><span>réponses au sondage</span></div>
    <div class="kpi"><b><?= e($security['level']) ?></b><span>niveau de sécurité</span></div>
  </div>

  <?php foreach ($checklist['groups'] as $group): ?>
    <section class="gcard">
      <h2><?= e($group['label']) ?></h2>
      <?php foreach ($group['items'] as $item): ?>
        <div class="gass-check<?= $item['done'] ? ' done' : '' ?>">
          <span class="gass-dot" aria-hidden="true"></span>
          <div>
            <strong><?= e($item['label']) ?></strong>
            <?php if ($item['badge'] !== ''): ?> <span class="pill"><?= e($item['badge']) ?></span><?php endif; ?>
            <br><small class="meta"><?= e($item['hint']) ?></small>
          </div>
          <?php if ($item['href'] !== ''): ?><a class="btn small" href="<?= e($item['href']) ?>">Ouvrir</a><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <form method="post" class="gcard" autocomplete="off">
    <?= $hid ?><input type="hidden" name="action" value="profile">
    <h2>Fiche de l’organisateur</h2>
    <p class="meta" style="margin:0;">Ces informations alimentent les courriers, la notice de sécurité et les demandes d’aide. Les champs manquants apparaissent en jaune dans les documents.</p>
    <div class="row">
      <label class="f">Organisateur<input type="text" name="organizer" maxlength="100" value="<?= e($data['profile']['organizer']) ?>" placeholder="Association des commerçants…" required></label>
      <label class="f">Structure<input type="text" name="structure" maxlength="80" value="<?= e($data['profile']['structure']) ?>" placeholder="association loi 1901, SARL…"></label>
      <label class="f">Responsable<input type="text" name="contact" maxlength="100" value="<?= e($data['profile']['contact']) ?>"></label>
      <label class="f">E-mail<input type="email" name="email" maxlength="160" value="<?= e($data['profile']['email']) ?>"></label>
      <label class="f">Téléphone<input type="text" name="phone" maxlength="40" value="<?= e($data['profile']['phone']) ?>"></label>
      <label class="f">SIRET (si professionnel)<input type="text" name="siret" maxlength="30" value="<?= e($data['profile']['siret']) ?>"></label>
    </div>
    <div class="row">
      <label class="f">Commune<input type="text" name="commune" maxlength="100" value="<?= e($data['profile']['commune']) ?>" autocomplete="address-level2"></label>
      <label class="f">Région<input type="text" name="region" maxlength="100" value="<?= e($data['profile']['region']) ?>"></label>
      <label class="f">Date visée<input type="date" name="target_date" value="<?= e($data['profile']['target_date']) ?>"></label>
      <label class="f">Fréquentation attendue<input type="number" name="attendance" min="0" max="1000000" step="10" value="<?= (int) $data['profile']['attendance'] ?>"></label>
    </div>
    <label class="f">Notes internes<textarea name="notes" rows="3" maxlength="1200" placeholder="Contraintes locales, contacts utiles, décisions prises…"><?= e($data['profile']['notes']) ?></textarea></label>
    <p><button class="btn btn-primary" type="submit">Enregistrer la fiche</button></p>
  </form>

<?php elseif ($vue === 'planning'): ?>
  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Rétroplanning</h2>
      <span class="pill<?= $target !== '' ? ' done' : '' ?>"><?= e($targetText) ?></span>
    </div>
    <p class="meta" style="margin:0;">Les échéances sont calculées à partir de la date de travail. La date de l’événement publié est prioritaire ; sinon la date retenue dans l’assistant, puis la date visée.</p>
    <form method="post" class="row" style="align-items:flex-end;">
      <?= $hid ?><input type="hidden" name="action" value="target_date">
      <label class="f" style="max-width:220px;">Date de travail<input type="date" name="target_date" value="<?= e($data['profile']['target_date']) ?>"></label>
      <button class="btn" type="submit">Mettre à jour</button>
      <a class="btn" href="<?= e($url(['vue' => 'date'])) ?>">Choisir parmi les dates suggérées</a>
    </form>
    <?php if ($target === ''): ?>
      <p class="meta">Renseignez une date pour afficher le rétroplanning de J-120 à J+7.</p>
    <?php else: ?>
      <?php $planning = gass_planning($gaGallery, $data); ?>
      <table class="t">
        <thead><tr><th>Échéance</th><th>Date</th><th>Tâche</th><th>État</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($planning as $task): ?>
          <tr>
            <td><strong><?= e($task['when']) ?></strong></td>
            <td><?= e($task['date_text']) ?></td>
            <td><strong><?= e($task['title']) ?></strong><br><small class="meta"><?= e($task['detail']) ?></small></td>
            <td>
              <?php if ($task['done']): ?><span class="pill done">Fait</span>
              <?php elseif ($task['status'] === 'late'): ?><span class="pill" style="color:var(--danger);">En retard</span>
              <?php elseif ($task['status'] === 'soon'): ?><span class="pill sent">À venir</span>
              <?php else: ?><span class="pill">Planifié</span><?php endif; ?>
            </td>
            <td class="row" style="gap:4px;justify-content:flex-end;">
              <form method="post"><?= $hid ?><input type="hidden" name="action" value="task"><input type="hidden" name="key" value="<?= e($task['key']) ?>">
                <button class="btn small<?= $task['done'] ? '' : ' btn-primary' ?>" type="submit"><?= $task['done'] ? 'Rouvrir' : 'Cocher' ?></button></form>
              <?php if ($task['href'] !== ''): ?><a class="btn small" href="<?= e($task['href']) ?>">Ouvrir</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p><a class="btn" href="<?= e($url(['doc' => 'planning'])) ?>" target="_blank" rel="noopener">Aperçu imprimable du rétroplanning</a></p>
    <?php endif; ?>
  </section>

<?php elseif ($vue === 'date'): ?>
  <?php
  $signals = $target !== '' ? gass_date_signals($gaGallery, $data, $target) : null;
  $suggestions = gass_date_candidates($gaGallery, $data, 6);
  $platformEvents = array_slice(gass_platform_events($gaSlug), 0, 20);
  ?>
  <section class="gcard">
    <h2>Date retenue</h2>
    <?php if ($eventDate !== ''): ?>
      <p class="meta" style="margin:0;">L’événement publié fixe la date au <strong><?= e(gass_date_fr($eventDate)) ?></strong> : c’est elle qui est utilisée dans le rétroplanning et les documents. <a href="<?= e(gass_portal_link('galerie-evenement.php', $gaSlug)) ?>">Modifier l’événement</a>.</p>
    <?php endif; ?>
    <form method="post" class="row" style="align-items:flex-end;">
      <?= $hid ?><input type="hidden" name="action" value="date_choose">
      <label class="f" style="max-width:240px;">Date de travail<input type="date" name="date" value="<?= e($data['dates']['chosen'] ?: $data['profile']['target_date']) ?>"></label>
      <button class="btn btn-primary" type="submit">Retenir cette date</button>
      <button class="btn" type="submit" name="date" value="" formnovalidate>Effacer</button>
    </form>
    <p class="meta" style="margin:0;">L’assistant croise le jour de la semaine, les jours fériés, la saison et les événements connus de la plateforme. Vérifiez toujours les disponibilités de la salle, de la voirie et des bénévoles.</p>
  </section>

  <?php if ($signals): ?>
    <section class="gcard">
      <div class="row" style="justify-content:space-between;">
        <h2>Analyse du <?= e($signals['label']) ?></h2>
        <span class="pill<?= $signals['score'] >= 60 ? ' done' : '' ?>"><?= e($signals['advice']) ?> · <?= (int) $signals['score'] ?>/100</span>
      </div>
      <ul>
        <?php foreach ($signals['reasons'] as $reason): ?><li><?= e($reason) ?></li><?php endforeach; ?>
      </ul>
      <?php if ($signals['competitors']): ?>
        <h3>Événements à moins de 30 jours</h3>
        <table class="t">
          <thead><tr><th>Date</th><th>Décalage</th><th>Événement</th><th>Lieu</th><th>Source</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($signals['competitors'] as $c): ?>
            <tr>
              <td><?= e(date('d/m/Y', strtotime($c['date'] . ' 12:00:00'))) ?></td>
              <td><?= $c['days'] === 0 ? 'même jour' : ($c['days'] > 0 ? 'J+' . (int) $c['days'] : 'J' . (int) $c['days']) ?></td>
              <td><?= e($c['name']) ?><?= $c['same_area'] ? ' <span class="pill sent">même secteur</span>' : '' ?></td>
              <td><?= e($c['city']) ?></td>
              <td><?= $c['source'] === 'plateforme' ? 'Plateforme' : 'Veille manuelle' ?></td>
              <td><?= $c['url'] !== '' ? '<a class="btn small" href="' . e($c['url']) . '" target="_blank" rel="noopener">Voir</a>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <section class="gcard">
    <h2>Dates suggérées</h2>
    <p class="meta" style="margin:0;">Les meilleurs week-ends des quatre prochains mois, selon les critères ci-dessus. Retenez une date, ou ajoutez-la aux options du sondage pour la faire voter aux exposants.</p>
    <?php if (!$suggestions): ?><p class="meta">Aucune suggestion disponible.</p><?php endif; ?>
    <div class="kpis" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr));">
      <?php foreach ($suggestions as $s): ?>
        <div class="gass-date-card">
          <div class="row" style="justify-content:space-between;">
            <strong><?= e($s['label']) ?></strong>
            <span class="gass-score"><?= (int) $s['score'] ?></span>
          </div>
          <span class="pill<?= $s['score'] >= 60 ? ' done' : '' ?>"><?= e($s['advice']) ?></span>
          <small class="meta"><?= e(implode(' ', array_slice($s['reasons'], 0, 2))) ?></small>
          <div class="row" style="gap:4px;">
            <form method="post"><?= $hid ?><input type="hidden" name="action" value="date_choose"><input type="hidden" name="date" value="<?= e($s['date']) ?>"><button class="btn small btn-primary" type="submit">Retenir</button></form>
            <form method="post"><?= $hid ?><input type="hidden" name="action" value="candidate_add"><input type="hidden" name="date" value="<?= e($s['date']) ?>"><input type="hidden" name="label" value=""><button class="btn small" type="submit">Ajouter au sondage</button></form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="gcard">
    <h2>Options proposées au sondage</h2>
    <p class="meta" style="margin:0;">Ces dates apparaissent dans la question « Quel(s) week-end(s) vous conviendrai(en)t ? » du sondage public.</p>
    <?php if (!$data['dates']['candidates']): ?><p class="meta">Aucune option : le sondage ne posera pas la question sur les dates.</p><?php else: ?>
      <table class="t"><thead><tr><th>Date</th><th>Libellé</th><th></th></tr></thead><tbody>
      <?php foreach ($data['dates']['candidates'] as $c): ?>
        <tr><td><?= e(gass_date_fr($c['date'])) ?></td><td><?= e($c['label'] ?: '—') ?></td>
          <td class="num"><form method="post"><?= $hid ?><input type="hidden" name="action" value="candidate_del"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn small danger" type="submit">Retirer</button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <form method="post" class="row" style="align-items:flex-end;">
      <?= $hid ?><input type="hidden" name="action" value="candidate_add">
      <label class="f" style="max-width:200px;">Date<input type="date" name="date" required></label>
      <label class="f">Libellé (facultatif)<input type="text" name="label" maxlength="60" placeholder="Week-end de la foire"></label>
      <button class="btn" type="submit">Ajouter une option</button>
    </form>
  </section>

  <section class="gcard">
    <h2>Veille des événements concurrents</h2>
    <p class="meta" style="margin:0;">La plateforme connaît déjà les événements de ses galeries. Ajoutez ici ceux repérés ailleurs (brocantes, vide-greniers, marchés) pour affiner l’analyse.</p>
    <?php if ($data['dates']['competitors']): ?>
      <table class="t"><thead><tr><th>Date</th><th>Événement</th><th>Ville</th><th>Lien</th><th></th></tr></thead><tbody>
      <?php foreach ($data['dates']['competitors'] as $c): ?>
        <tr><td><?= e(date('d/m/Y', strtotime($c['date'] . ' 12:00:00'))) ?></td><td><?= e($c['name']) ?></td><td><?= e($c['city']) ?></td>
          <td><?= $c['url'] !== '' ? '<a href="' . e($c['url']) . '" target="_blank" rel="noopener">Ouvrir</a>' : '—' ?></td>
          <td class="num"><form method="post"><?= $hid ?><input type="hidden" name="action" value="competitor_del"><input type="hidden" name="id" value="<?= e($c['id']) ?>"><button class="btn small danger" type="submit">Retirer</button></form></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
    <form method="post" class="row" style="align-items:flex-end;">
      <?= $hid ?><input type="hidden" name="action" value="competitor_add">
      <label class="f">Nom<input type="text" name="name" maxlength="100" required placeholder="Brocante de…"></label>
      <label class="f" style="max-width:180px;">Date<input type="date" name="date" required></label>
      <label class="f">Ville<input type="text" name="city" maxlength="60"></label>
      <label class="f">Lien<input type="url" name="url" maxlength="300" placeholder="https://…"></label>
      <button class="btn" type="submit">Ajouter à la veille</button>
    </form>
    <?php if ($platformEvents): ?>
      <h3>Événements de la plateforme</h3>
      <table class="t"><thead><tr><th>Date</th><th>Événement</th><th>Galerie</th><th>Ville</th><th></th></tr></thead><tbody>
      <?php foreach ($platformEvents as $pe): ?>
        <tr><td><?= e(date('d/m/Y', strtotime($pe['date'] . ' 12:00:00'))) ?></td><td><?= e($pe['title']) ?></td><td><?= e($pe['gallery']) ?></td><td><?= e($pe['city']) ?></td>
          <td><a class="btn small" href="<?= e($pe['url']) ?>" target="_blank" rel="noopener">Voir</a></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    <?php endif; ?>
  </section>

<?php elseif ($vue === 'securite'): ?>
  <?php $questions = gass_security_questions(); ?>
  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Questionnaire de sécurité</h2>
      <span class="pill<?= $security['tone'] === 'ok' ? ' done' : '' ?>"><?= e($security['label']) ?></span>
    </div>
    <p class="meta" style="margin:0;">Treize points pour préparer la manifestation. L’assistant calcule un niveau de risque indicatif et des recommandations ; il ne remplace pas l’avis de la commission de sécurité ni les obligations locales.</p>
    <form method="post">
      <?= $hid ?><input type="hidden" name="action" value="security">
      <?php foreach ($questions as $key => $q): ?>
        <div class="gass-q">
          <div><strong><?= e($q['label']) ?></strong><br><small class="meta"><?= e($q['hint']) ?></small></div>
          <div class="row" style="gap:6px;">
            <?php foreach ($q['options'] as $ok => $opt): ?>
              <label class="gass-opt"><input type="radio" name="answers[<?= e($key) ?>]" value="<?= e($ok) ?>"<?= ($data['security']['answers'][$key] ?? '') === $ok ? ' checked' : '' ?>> <?= e($opt['label']) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <p><button class="btn btn-primary" type="submit">Enregistrer le questionnaire</button></p>
    </form>
  </section>

  <section class="gcard">
    <h2>Synthèse</h2>
    <div class="kpis">
      <div class="kpi"><b><?= e($security['level']) ?></b><span>niveau de risque</span></div>
      <div class="kpi"><b><?= (int) $security['score'] ?>/<?= (int) $security['max'] ?></b><span>score</span></div>
      <div class="kpi"><b><?= (int) $security['answered'] ?>/<?= (int) $security['total'] ?></b><span>questions renseignées</span></div>
    </div>
    <?php if ($security['recommendations']): ?>
      <h3>Recommandations</h3>
      <ul><?php foreach ($security['recommendations'] as $advice): ?><li><?= e($advice) ?></li><?php endforeach; ?></ul>
    <?php elseif ($security['answered'] < $security['total']): ?>
      <p class="meta">Complétez le questionnaire pour obtenir des recommandations.</p>
    <?php endif; ?>
    <?php if ($security['missing']): ?>
      <p class="meta">Points non renseignés : <?= e(implode(', ', $security['missing'])) ?>.</p>
    <?php endif; ?>
    <p><a class="btn" href="<?= e($url(['doc' => 'securite'])) ?>" target="_blank" rel="noopener">Générer la notice de sécurité</a></p>
  </section>

<?php elseif ($vue === 'documents'): ?>
  <section class="gcard">
    <h2>Documents de la manifestation</h2>
    <p class="meta" style="margin:0;">Chaque modèle reprend les informations de la fiche, de l’événement, de la sécurité et du budget. Les repères <span style="background:#fff3c4;border:1px dashed #c79a18;padding:0 3px;">[à compléter]</span> signalent les données manquantes. Relisez et adaptez avant tout envoi.</p>
  </section>
  <section class="gcard">
    <?php foreach ($catalog as $kind => $doc): $ready = isset($data['docs'][$kind]); ?>
      <div class="gass-doc">
        <div>
          <strong><?= e($doc['label']) ?></strong>
          <span class="pill<?= $ready ? ' done' : '' ?>"><?= $ready ? 'Prêt' : 'À faire' ?></span>
          <?php if ($ready): ?><span class="meta">depuis le <?= e(date('d/m/Y', (int) $data['docs'][$kind])) ?></span><?php endif; ?>
          <br><small class="meta"><?= e($doc['hint']) ?></small>
          <br><small class="meta">Pour : <?= e($doc['audience']) ?></small>
        </div>
        <div class="row" style="gap:4px;justify-content:flex-end;">
          <a class="btn small" href="<?= e($url(['vue' => 'documents', 'doc' => $kind])) ?>" target="_blank" rel="noopener">Aperçu</a>
          <a class="btn small" href="<?= e($url(['vue' => 'documents', 'doc' => $kind, 'telecharger' => 1])) ?>">Télécharger</a>
          <form method="post"><?= $hid ?><input type="hidden" name="action" value="doc_ready"><input type="hidden" name="kind" value="<?= e($kind) ?>"><button class="btn small" type="submit"><?= $ready ? 'À revoir' : 'Marquer prêt' ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

<?php elseif ($vue === 'contacts'): ?>
  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Anciens exposants et contacts</h2>
      <a class="btn small" href="<?= e($url(['export' => 'exposants'])) ?>">Exporter en CSV</a>
    </div>
    <p class="meta" style="margin:0;">Exposants déjà attribués et prospects enregistrés. Le courrier de mobilisation et le lien du sondage sont dans les documents.</p>
    <?php if (!$former): ?>
      <p class="meta">Aucun contact pour le moment. <a href="<?= e(gass_portal_link('galerie-prospects.php', $gaSlug)) ?>">Ajouter des prospects</a> ou importer un fichier.</p>
    <?php else: ?>
      <table class="t">
        <thead><tr><th>Nom</th><th>E-mail</th><th>Téléphone</th><th>Source</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($former, 0, 60) as $row): ?>
          <tr><td><strong><?= e($row['name'] ?: '—') ?></strong></td><td><?= e($row['email'] ?: '—') ?></td><td><?= e($row['phone'] ?: '—') ?></td><td><?= e($row['source']) ?></td><td><?= e($row['status']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($former) > 60): ?><p class="meta">Affichage des 60 premiers contacts sur <?= count($former) ?> ; l’export CSV contient tout.</p><?php endif; ?>
    <?php endif; ?>
    <div class="row">
      <a class="btn" href="<?= e(gass_portal_link('galerie-prospects.php', $gaSlug)) ?>">Prospects et relances</a>
      <a class="btn" href="<?= e(gass_portal_link('galerie-import.php', $gaSlug)) ?>">Importer des contacts</a>
      <a class="btn" href="<?= e($url(['doc' => 'exposants'])) ?>" target="_blank" rel="noopener">Courrier aux anciens exposants</a>
    </div>
  </section>

  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Sondage auprès des prospects</h2>
      <span class="pill<?= $surveyOpen ? ' done' : '' ?>"><?= $surveyOpen ? 'Ouvert' : ($surveyOn ? 'Fermé' : 'Non activé') ?></span>
    </div>
    <p class="meta" style="margin:0;">Un lien public, sans compte. Les réponses sont anonymes sauf si la personne laisse son e-mail avec son accord. Une limite de <?= GASS_RESPONSES_PER_HOUR ?> réponses par heure et par connexion limite les envois automatisés.</p>
    <div class="row">
      <input type="text" id="survey-url" readonly value="<?= e($surveyUrlValue) ?>" style="flex:1;min-width:220px;">
      <button class="btn" type="button" data-copy="survey-url">Copier le lien</button>
      <a class="btn" href="<?= e($surveyUrlValue) ?>" target="_blank" rel="noopener">Ouvrir le sondage</a>
    </div>
    <form method="post" class="gcard" style="background:var(--bg);">
      <?= $hid ?><input type="hidden" name="action" value="survey_save">
      <label class="f">Titre<input type="text" name="title" maxlength="100" value="<?= e($data['survey']['title']) ?>"></label>
      <label class="f">Introduction<textarea name="intro" rows="2" maxlength="600"><?= e($data['survey']['intro']) ?></textarea></label>
      <div class="row">
        <label class="f"><span><input type="checkbox" name="enabled" value="1"<?= $surveyOn ? ' checked' : '' ?>> Activer le sondage</span></label>
        <label class="f"><span><input type="checkbox" name="open" value="1"<?= $surveyOpen ? ' checked' : '' ?>> Accepter les réponses</span></label>
      </div>
      <p class="meta" style="margin:0;">Questions posées : participation, fréquence, dates proposées, budget, produits, services et commentaire libre. Ajoutez des dates dans l’onglet « Date et concurrence » pour les faire figurer dans le sondage.</p>
      <p><button class="btn btn-primary" type="submit">Enregistrer le sondage</button></p>
    </form>
  </section>

  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Résultats</h2>
      <div class="row" style="gap:4px;">
        <a class="btn small" href="<?= e($url(['export' => 'sondage'])) ?>">Exporter en CSV</a>
        <a class="btn small" href="<?= e($url(['doc' => 'sondage'])) ?>" target="_blank" rel="noopener">Invitation à envoyer</a>
      </div>
    </div>
    <p class="meta" style="margin:0;"><?= (int) $surveyResults['total'] ?> réponse(s), dont <?= (int) $surveyResults['emails'] ?> avec une adresse de contact.</p>
    <?php if ($surveyResults['total'] === 0): ?>
      <p class="meta">Aucune réponse pour le moment.</p>
    <?php else: ?>
      <?php foreach ($surveyResults['questions'] as $question): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--line);">
          <strong><?= e($question['label']) ?></strong>
          <?php if ($question['type'] === 'textarea'): ?>
            <?php if ($question['comments']): ?>
              <ul style="margin:6px 0 0;padding-left:18px;">
                <?php foreach ($question['comments'] as $comment): ?><li><?= nl2br(e($comment['text'])) ?> <small class="meta">— <?= e(date('d/m/Y', (int) $comment['created'])) ?></small></li><?php endforeach; ?>
              </ul>
            <?php else: ?><br><small class="meta">Aucun commentaire.</small><?php endif; ?>
          <?php else: ?>
            <div style="display:grid;gap:5px;margin-top:6px;">
              <?php foreach ($question['options'] as $option): $pct = $surveyResults['total'] > 0 ? (int) round($option['count'] / $surveyResults['total'] * 100) : 0; ?>
                <div class="gass-line">
                  <span style="flex:1;font-size:.88rem;"><?= e($option['label']) ?></span>
                  <div class="gass-bar" style="width:35%;"><span style="width:<?= $pct ?>%"></span></div>
                  <span class="meta" style="min-width:64px;text-align:right;"><?= (int) $option['count'] ?> · <?= $pct ?> %</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($surveyResults['total'] > 0): ?>
      <form method="post" onsubmit="return confirm('Supprimer définitivement toutes les réponses au sondage ?');">
        <?= $hid ?><input type="hidden" name="action" value="survey_clear">
        <button class="btn small danger" type="submit">Supprimer toutes les réponses</button>
      </form>
    <?php endif; ?>
  </section>

<?php elseif ($vue === 'aides'): ?>
  <section class="gcard">
    <div class="row" style="justify-content:space-between;">
      <h2>Plan de financement de la numérisation</h2>
      <a class="btn small" href="<?= e($url(['doc' => 'budget'])) ?>" target="_blank" rel="noopener">Aperçu imprimable</a>
    </div>
    <p class="meta" style="margin:0;">Décrivez le projet, chiffrez les postes et répartissez le financement. Les courriers à la commune et à la région reprennent automatiquement ce plan.</p>
    <div class="kpis">
      <div class="kpi"><b><?= e(gass_eur($plan['total'])) ?></b><span>budget total</span></div>
      <div class="kpi"><b><?= e(gass_eur($plan['requested'])) ?></b><span>aide demandée</span></div>
      <div class="kpi"><b><?= e(gass_eur($plan['own'])) ?></b><span>autofinancement</span></div>
      <div class="kpi"><b><?= e(gass_eur($plan['gap'])) ?></b><span>reste à financer</span></div>
    </div>
    <?php if ($plan['gap'] > 0): ?><p class="meta">Il manque <?= e(gass_eur($plan['gap'])) ?> pour équilibrer le plan : ajustez les montants ou ajoutez un cofinancement.</p><?php endif; ?>
    <form method="post" class="gcard" style="background:var(--bg);">
      <?= $hid ?><input type="hidden" name="action" value="aid_save">
      <label class="f">Intitulé du projet<input type="text" name="project" maxlength="160" value="<?= e($data['aid']['project']) ?>" placeholder="Numérisation de la galerie et réservation en ligne"></label>
      <label class="f">Besoins et objectifs<textarea name="needs" rows="4" maxlength="1600" placeholder="Ce que la numérisation doit apporter : vitrine, réservations, paiement, communication, données de fréquentation…"><?= e($data['aid']['needs']) ?></textarea></label>
      <h3>Postes de dépense</h3>
      <?php for ($i = 0; $i < GASS_MAX_BUDGET_LINES; $i++): $line = $data['aid']['budget'][$i] ?? ['label' => '', 'amount_cents' => 0]; ?>
        <div class="row">
          <input type="text" name="budget_label[]" maxlength="100" value="<?= e($line['label']) ?>" placeholder="Poste <?= $i + 1 ?> — site, photos, matériel…">
          <input type="text" name="budget_amount[]" inputmode="decimal" value="<?= $line['amount_cents'] > 0 ? e(number_format($line['amount_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="0,00 €" style="max-width:140px;">
        </div>
      <?php endfor; ?>
      <h3>Répartition</h3>
      <div class="row">
        <label class="f">Aide demandée (€)<input type="text" name="requested_eur" inputmode="decimal" value="<?= $data['aid']['requested_cents'] > 0 ? e(number_format($data['aid']['requested_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="0,00"></label>
        <label class="f">Autofinancement (€)<input type="text" name="own_eur" inputmode="decimal" value="<?= $data['aid']['own_cents'] > 0 ? e(number_format($data['aid']['own_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="0,00"></label>
        <label class="f">Autres cofinancements (€)<input type="text" name="other_eur" inputmode="decimal" value="<?= $data['aid']['other_cents'] > 0 ? e(number_format($data['aid']['other_cents'] / 100, 2, ',', '')) : '' ?>" placeholder="0,00"></label>
      </div>
      <div class="row">
        <label class="f">Commune sollicitée<input type="text" name="commune" maxlength="100" value="<?= e($data['aid']['commune'] ?: $data['profile']['commune']) ?>"></label>
        <label class="f">Région sollicitée<input type="text" name="region" maxlength="100" value="<?= e($data['aid']['region'] ?: $data['profile']['region']) ?>"></label>
        <label class="f">Contact pour les financeurs<input type="text" name="contact" maxlength="160" value="<?= e($data['aid']['contact']) ?>" placeholder="Nom, fonction, e-mail"></label>
      </div>
      <p><button class="btn btn-primary" type="submit">Enregistrer le plan</button></p>
    </form>
    <div class="row">
      <a class="btn" href="<?= e($url(['doc' => 'aide-commune'])) ?>" target="_blank" rel="noopener">Courrier à la commune</a>
      <a class="btn" href="<?= e($url(['doc' => 'aide-region'])) ?>" target="_blank" rel="noopener">Courrier à la région</a>
    </div>
    <p class="meta" style="margin:0;">Les dispositifs, taux et plafonds varient selon les collectivités : vérifiez le règlement d’intervention et joignez les devis avant dépôt.</p>
  </section>

<?php elseif ($vue === 'plus'): ?>
  <section class="gcard">
    <h2>Communication, équipe et bilan</h2>
    <p class="meta" style="margin:0;">Trois documents pour la dernière ligne droite et l’après-événement. Ils reprennent la date, le lieu et les chiffres connus de l’assistant.</p>
    <?php foreach (['presse', 'benevoles', 'bilan'] as $kind): $doc = $catalog[$kind]; $ready = isset($data['docs'][$kind]); ?>
      <div class="gass-doc">
        <div><strong><?= e($doc['label']) ?></strong> <span class="pill<?= $ready ? ' done' : '' ?>"><?= $ready ? 'Prêt' : 'À faire' ?></span><br><small class="meta"><?= e($doc['hint']) ?></small></div>
        <div class="row" style="gap:4px;">
          <a class="btn small" href="<?= e($url(['doc' => $kind])) ?>" target="_blank" rel="noopener">Aperçu</a>
          <a class="btn small" href="<?= e($url(['doc' => $kind, 'telecharger' => 1])) ?>">Télécharger</a>
          <form method="post"><?= $hid ?><input type="hidden" name="action" value="doc_ready"><input type="hidden" name="kind" value="<?= e($kind) ?>"><button class="btn small" type="submit"><?= $ready ? 'À revoir' : 'Marquer prêt' ?></button></form>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="gcard">
    <h2>Liens utiles</h2>
    <div class="row">
      <a class="btn" href="<?= e(gass_portal_link('galerie-evenement.php', $gaSlug)) ?>">Événement et réservations</a>
      <a class="btn" href="<?= e(gass_portal_link('galerie-inscriptions.php', $gaSlug)) ?>">Inscriptions</a>
      <a class="btn" href="<?= e(gass_portal_link('galerie-registre.php', $gaSlug)) ?>">Registre de police</a>
      <a class="btn" href="<?= e(gass_portal_link('galerie-prospects.php', $gaSlug)) ?>">Prospects et abonnés</a>
      <a class="btn" href="<?= e($url(['vue' => 'contacts'])) ?>">Sondage et résultats</a>
      <a class="btn" href="<?= e(ga_public_url('/galerie/' . $gaSlug . '/')) ?>" target="_blank" rel="noopener">Page publique de la galerie</a>
    </div>
    <p class="meta" style="margin:0;">Après l’événement, remplissez le bilan puis réutilisez les contacts et le sondage pour préparer l’édition suivante : l’assistant conserve les réponses et le rétroplanning.</p>
  </section>
<?php endif; ?>

<script>
document.querySelectorAll('[data-copy]').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.copy);
    if (!input || !navigator.clipboard) { if (input) input.select(); return; }
    navigator.clipboard.writeText(input.value).then(function () {
      var old = btn.textContent;
      btn.textContent = 'Copié';
      setTimeout(function () { btn.textContent = old; }, 1400);
    });
  });
});
</script>
<?php ga_foot();
