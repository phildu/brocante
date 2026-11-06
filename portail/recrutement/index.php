<?php
// Recrutement : synthèse, besoins publiés, candidatures et bénévoles des
// vides-grenier. Les écritures passent par action.php ; le parcours public est
// postuler.php (partagé par lien d'offre ou par piste).
require __DIR__ . '/../_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/recrutement.php';

const RC_PAGE_TITLE = 'Recrutement';

function rc_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function rc_public_url(string $query = ''): string
{
    return rc_origin() . '/portail/recrutement/postuler.php' . ($query !== '' ? '?' . $query : '');
}

function rc_initials(array $a): string
{
    $parts = preg_split('/\s+/u', trim($a['name'] !== '' ? $a['name'] : $a['email'])) ?: [];
    $first = mb_substr((string) ($parts[0] ?? ''), 0, 1);
    $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';
    return mb_strtoupper($first . $last) ?: '?';
}

function rc_date_fr(string $date): string
{
    if ($date === '') return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
}

$vue = in_array($_GET['vue'] ?? '', ['synthese', 'postes', 'candidatures', 'benevoles', 'reglages'], true) ? (string) $_GET['vue'] : 'synthese';
$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'track' => isset(RC_TRACKS[(string) ($_GET['piste'] ?? '')]) ? (string) $_GET['piste'] : '',
    'status' => isset(RC_STATUSES[(string) ($_GET['statut'] ?? '')]) ? (string) $_GET['statut'] : '',
    'offer' => rc_valid_id((string) ($_GET['offre'] ?? '')) ? (string) $_GET['offre'] : '',
];
$applications = rc_applications($filters);
$allApplications = rc_applications();
$offers = rc_offers();
$stats = rc_stats();
$settings = rc_settings();
$events = rc_upcoming_events();
$formUrl = '/portail/recrutement/action.php';
$csrf = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="recrutement-' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach (rc_export_rows($applications) as $row) fputcsv($out, $row, ';', '"', '');
    fclose($out);
    exit;
}

$editing = null;
if ($vue === 'postes' && !empty($_GET['edit'])) $editing = rc_offer_load((string) $_GET['edit']);
$creating = $vue === 'postes' && isset($_GET['nouveau']);

// Modèles de besoin : préremplissent le formulaire de création.
$presets = [
    'dev' => [
        'track' => 'emploi', 'title' => 'Développeur PHP freelance', 'contract' => 'freelance',
        'domains' => ['developpement', 'fonctionnel'], 'remote' => true, 'location' => 'À distance',
        'description' => "Faire évoluer la plateforme des brocs : portail des commerces, boutiques en ligne et vides-grenier.",
        'missions' => "Développer les nouvelles fonctionnalités (portail, boutiques, galeries)\nCorriger les bugs signalés par les testeurs\nDocumenter et tester les livraisons",
        'profile' => "PHP 8, SQLite/MySQL, JavaScript sans framework lourd. Autonomie et goût du terrain.",
        'compensation' => 'À définir selon la mission',
    ],
    'design' => [
        'track' => 'emploi', 'title' => 'Designer UI/UX freelance', 'contract' => 'prestation',
        'domains' => ['design', 'fonctionnel'], 'remote' => true, 'location' => 'À distance',
        'description' => "Simplifier les parcours du portail et des boutiques, et harmoniser l'identité visuelle.",
        'missions' => "Auditer les écrans clés (inscription, création de boutique, plan de vide-grenier)\nProposer des maquettes et un système de composants\nAccompagner les développeurs sur l'intégration",
        'profile' => "Expérience de produits web utilitaires, sens du détail, goût pour les interfaces sobres et rapides.",
        'compensation' => 'À définir selon la mission',
    ],
    'primo' => [
        'track' => 'primo', 'title' => 'Primo aidants — design, fonctionnel, financement, tests', 'contract' => 'a_definir',
        'domains' => ['design', 'fonctionnel', 'financement', 'tests'], 'remote' => true, 'location' => 'À distance ou sur place',
        'description' => "Un coup de main de quelques heures pour relire, tester, conseiller ou aider à financer la dernière ligne droite.",
        'missions' => "Tester la boutique et le portail comme un vrai client\nRelire les parcours et signaler ce qui bloque\nDonner un avis sur les priorités\nÉventuellement prêter, avancer ou apporter un financement",
        'profile' => "Vous êtes un proche, un ami ou un membre de la famille. Aucune expérience technique nécessaire : votre regard et votre temps comptent.",
        'compensation' => 'Bénévolat ou contreparties à définir',
    ],
    'benevole' => [
        'track' => 'benevole', 'title' => 'Bénévoles pour le vide-grenier', 'contract' => 'benevolat',
        'domains' => ['logistique', 'accueil'], 'location' => '',
        'description' => "Renforcer l'équipe le jour du vide-grenier : installation, accueil des exposants, tenue du stand, démontage.",
        'missions' => "Installer les tables et les barrières\nAccueillir et placer les exposants\nTenir le stand de l'association\nRanger et démonter en fin de journée",
        'profile' => "Bonne humeur, ponctualité, envie d'aider. Plusieurs créneaux possibles dans la journée.",
        'compensation' => 'Bénévolat — repas et bonne compagnie offerts',
    ],
];

if ($creating || $editing) {
    $presetKey = (string) ($_GET['modele'] ?? '');
    $preset = isset($presets[$presetKey]) ? $presets[$presetKey] : [];
    if (isset($_GET['track']) && isset(RC_TRACKS[(string) $_GET['track']])) $preset['track'] = (string) $_GET['track'];
    if (!empty($_GET['event']) && preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', (string) $_GET['event'])) $preset['event'] = (string) $_GET['event'];
    $presetTrack = (string) ($preset['track'] ?? 'emploi');
    $defaults = rc_offer_normalize(array_merge([
        'status' => 'ouvert',
        'slots' => 1,
        'contract' => $presetTrack === 'benevole' ? 'benevolat' : ($presetTrack === 'primo' ? 'a_definir' : 'freelance'),
    ], $preset));
    $offer = $editing ?? $defaults;
    if ($editing === null && !empty($preset['event'])) {
        foreach ($events as $event) {
            if ($event['slug'] === $preset['event']) {
                $offer['date'] = $event['date'];
                $offer['location'] = trim($event['place'] . ($event['city'] !== '' ? ' · ' . $event['city'] : ''));
                break;
            }
        }
    }
} else {
    $offer = null;
}

$pending = array_values(array_filter($allApplications, static fn (array $a): bool => $a['status'] === 'recue'));
$recent = array_slice($allApplications, 0, 6);
$benevoleApps = array_values(array_filter($allApplications, static fn (array $a): bool => $a['track'] === 'benevole'));
$offersById = [];
foreach ($offers as $o) $offersById[$o['id']] = $o;
$tabs = [
    ['label' => 'Synthèse', 'href' => '/portail/recrutement/?vue=synthese', 'active' => $vue === 'synthese'],
    ['label' => 'Besoins (' . count($offers) . ')', 'href' => '/portail/recrutement/?vue=postes', 'active' => $vue === 'postes'],
    ['label' => 'Candidatures (' . count($pending) . ')', 'href' => '/portail/recrutement/?vue=candidatures', 'active' => $vue === 'candidatures'],
    ['label' => 'Bénévoles', 'href' => '/portail/recrutement/?vue=benevoles', 'active' => $vue === 'benevoles'],
    ['label' => 'Partage', 'href' => '/portail/recrutement/?vue=reglages', 'active' => $vue === 'reglages'],
];
$statusOptions = static function (string $current, string $track): string {
    $html = '';
    foreach (RC_STATUSES as $key => $label) {
        $html .= '<option value="' . e($key) . '"' . ($current === $key ? ' selected' : '') . '>' . e(rc_status_label($key, $track)) . '</option>';
    }
    return $html;
};
$flash = portail_flash();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e(RC_PAGE_TITLE) ?> — Portail des commerces</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .rc-wrap { display: grid; gap: 16px; max-width: 1180px; }
  .rc-tracks { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
  .rc-track { display: grid; gap: 8px; padding: 16px; border: 1px solid var(--line); border-radius: 14px; background: var(--panel); }
  .rc-track h3 { margin: 0; font-size: 1rem; display: flex; gap: 8px; align-items: center; justify-content: space-between; }
  .rc-track p { margin: 0; color: var(--muted); font-size: 0.84rem; line-height: 1.45; }
  .rc-track .rc-nums { display: flex; gap: 12px; flex-wrap: wrap; color: var(--muted); font-size: 0.78rem; }
  .rc-track .rc-nums b { color: var(--ink); font-size: 1rem; display: block; line-height: 1.05; }
  .rc-track .rc-links { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 2px; }
  .rc-badge { display: inline-block; padding: 1px 8px; border: 1px solid var(--line); border-radius: 999px; font-size: 0.72rem; font-weight: 600; color: var(--muted); }
  .rc-badge.emploi { border-color: color-mix(in srgb, var(--accent) 30%, var(--line)); color: var(--accent); }
  .rc-badge.primo { border-color: color-mix(in srgb, var(--ok) 35%, var(--line)); color: var(--ok); }
  .rc-badge.benevole { border-color: color-mix(in srgb, var(--warn) 40%, var(--line)); color: var(--warn); }
  .rc-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; padding: 12px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel-2); }
  .rc-bar label { display: grid; gap: 4px; font-size: 0.74rem; font-weight: 700; color: var(--muted); }
  .rc-bar input[type="search"], .rc-bar select { min-width: 150px; }
  .rc-bar .rc-grow { flex: 1 1 220px; }
  .rc-bar .rc-grow input { width: 100%; }
  .rc-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
  .rc-grid label { display: grid; gap: 4px; font-size: 0.78rem; font-weight: 700; color: var(--muted); }
  .rc-grid label input, .rc-grid label select { width: 100%; }
  .rc-grid .rc-full { grid-column: 1 / -1; }
  .rc-grid textarea { width: 100%; min-height: 74px; resize: vertical; }
  .rc-checks { display: grid; gap: 6px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
  .rc-check { display: flex; gap: 7px; align-items: flex-start; font-size: 0.84rem; color: var(--ink); font-weight: 500; padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); cursor: pointer; }
  .rc-check input { margin-top: 2px; }
  .rc-card { display: grid; gap: 10px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); }
  .rc-card h3 { margin: 0; font-size: 1rem; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .rc-card p { margin: 0; }
  .rc-member { display: flex; align-items: center; gap: 10px; }
  .rc-avatar { display: grid; place-items: center; width: 36px; height: 36px; flex: none; border-radius: 10px; background: var(--accent-soft); color: var(--accent); font-weight: 700; font-size: 0.8rem; }
  .rc-meta { color: var(--muted); font-size: 0.82rem; }
  .rc-msg { margin-top: 4px; padding: 9px 11px; border-left: 3px solid var(--line-strong); background: var(--panel-2); border-radius: 0 8px 8px 0; font-size: 0.86rem; white-space: pre-line; }
  .rc-actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
  .rc-actions form { display: inline-flex; gap: 4px; align-items: center; margin: 0; flex-wrap: wrap; }
  .rc-actions select { padding: 4px 6px; font-size: 0.78rem; }
  .rc-invite { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .rc-invite code { font-family: var(--font-mono); font-size: 0.78rem; padding: 5px 8px; border: 1px solid var(--line); border-radius: 7px; background: var(--panel-2); word-break: break-all; }
  .rc-notes { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none; }
  .rc-notes li { padding: 7px 10px; border-radius: 8px; background: var(--panel-2); border: 1px solid var(--line); font-size: 0.83rem; white-space: pre-line; }
  .rc-notes time { display: block; color: var(--muted); font-size: 0.7rem; margin-bottom: 2px; }
  .rc-detail { display: grid; gap: 4px; margin: 0; padding: 0; list-style: none; font-size: 0.84rem; }
  .rc-detail li { display: flex; gap: 6px; flex-wrap: wrap; }
  .rc-detail span:first-child { color: var(--muted); min-width: 108px; }
  .rc-event { display: grid; gap: 8px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); }
  .rc-event h3 { margin: 0; font-size: 0.98rem; }
  .rc-event .rc-date { font-family: var(--font-mono); font-size: 0.78rem; color: var(--accent); }
  .rc-log { display: grid; gap: 6px; }
  .rc-log div { display: flex; gap: 8px; align-items: center; font-size: 0.84rem; }
  .rc-log span { color: var(--muted); }
  @media (max-width: 760px) { .rc-table thead { display: none; } .rc-table, .rc-table tbody, .rc-table tr, .rc-table td { display: block; width: 100%; } .rc-table tr { border: 1px solid var(--line); border-radius: 10px; margin-bottom: 8px; } .rc-table td { border: 0; padding: 6px 10px; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => RC_PAGE_TITLE,
    'eyebrow' => 'Collaborateurs',
    'desc' => 'Recrutez les personnes qui font avancer le projet : salariés et freelances, proches primo aidants, bénévoles des vides-grenier. Publiez un besoin, partagez son lien, suivez les candidatures.',
    'actions' => '<a class="btn btn-primary" href="/portail/recrutement/?vue=postes&amp;nouveau=1#offre">' . portail_icon('plus', 16) . ' Nouveau besoin</a>'
        . '<a class="btn" href="/portail/recrutement/postuler.php" target="_blank" rel="noopener">' . portail_icon('external', 16) . ' Page publique</a>',
    'tabs' => $tabs,
]); ?>

  <?php if ($flash): ?>
    <p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p>
  <?php endif; ?>

  <div class="rc-wrap">

  <?php if ($vue === 'synthese'): ?>
    <div class="kpis">
      <div class="kpi"><span class="n"><?= (int) $stats['offres_ouvertes'] ?></span><span class="l">Besoins ouverts</span><span class="s"><?= (int) $stats['offres'] ?> besoin<?= $stats['offres'] > 1 ? 's' : '' ?> au total</span></div>
      <div class="kpi<?= $stats['attente'] ? ' bad' : '' ?>"><span class="n"><?= (int) $stats['attente'] ?></span><span class="l">Candidatures à traiter</span><span class="s"><a href="/portail/recrutement/?vue=candidatures&statut=recue"><?= $stats['attente'] ? 'Ouvrir la file' : 'File à jour' ?></a></span></div>
      <div class="kpi"><span class="n"><?= (int) $stats['entretiens'] ?></span><span class="l">Entretiens en cours</span><span class="s"><?= (int) $stats['candidatures'] ?> candidature<?= $stats['candidatures'] > 1 ? 's' : '' ?> reçue<?= $stats['candidatures'] > 1 ? 's' : '' ?></span></div>
      <div class="kpi"><span class="n"><?= (int) $stats['integrees'] ?></span><span class="l">Personnes intégrées</span><span class="s"><?= (int) $stats['benevoles_confirmes'] ?> bénévole<?= $stats['benevoles_confirmes'] > 1 ? 's' : '' ?> confirmé<?= $stats['benevoles_confirmes'] > 1 ? 's' : '' ?></span></div>
    </div>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Les trois pistes</h2>
          <p class="hint">Chaque piste a sa page publique et son formulaire adapté. Un besoin publié peut être partagé par son lien court.</p>
        </div>
      </div>
      <div class="rc-tracks">
        <?php foreach (RC_TRACKS as $key => $track): $t = $stats['par_piste'][$key]; ?>
          <article class="rc-track">
            <h3><span><?= e($track['label']) ?></span><span class="rc-badge <?= e($key) ?>"><?= (int) $t['offres'] ?> ouvert<?= $t['offres'] > 1 ? 's' : '' ?></span></h3>
            <p><?= e($track['desc']) ?></p>
            <div class="rc-nums">
              <span><b><?= (int) $t['candidatures'] ?></b>Candidatures</span>
              <span><b><?= (int) $t['attente'] ?></b>À traiter</span>
              <span><b><?= (int) $t['integrees'] ?></b>Intégré<?= $t['integrees'] > 1 ? 's' : '' ?></span>
            </div>
            <div class="rc-links">
              <a class="btn small" href="/portail/recrutement/?vue=postes&piste=<?= e($key) ?>">Voir les besoins</a>
              <a class="btn small" href="/portail/recrutement/postuler.php?piste=<?= e($key) ?>" target="_blank" rel="noopener">Formulaire ↗</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Dernières candidatures</h2>
          <p class="hint">Cliquez sur une candidature pour la traiter dans l’onglet Candidatures.</p>
        </div>
        <a class="btn small" href="/portail/recrutement/?vue=candidatures">Tout voir</a>
      </div>
      <?php if (!$recent): ?>
        <div class="empty-state"><b>Aucune candidature pour le moment</b>Créez un besoin puis partagez son lien public : les réponses arriveront ici.</div>
      <?php else: ?>
        <div class="rc-log">
          <?php foreach ($recent as $a): ?>
            <div>
              <span class="rc-badge <?= e($a['track']) ?>"><?= e(rc_track_label($a['track'])) ?></span>
              <b><?= e($a['name'] !== '' ? $a['name'] : $a['email']) ?></b>
              <span><?= e($offersById[$a['offer_id']]['title'] ?? 'Candidature spontanée') ?></span>
              <span><?= e(date('d/m/Y H:i', $a['created'])) ?></span>
              <span class="pill<?= $a['status'] === 'recue' ? '' : ($a['status'] === 'integree' ? ' done' : ' sent') ?>"><?= e(rc_status_label($a['status'], $a['track'])) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Démarrer vite</h2>
          <p class="hint">Un modèle préremplit le besoin : vous ajustez puis publiez.</p>
        </div>
      </div>
      <div class="rc-actions">
        <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&modele=dev#offre">Développeur freelance</a>
        <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&modele=design#offre">Designer UI/UX</a>
        <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&modele=primo#offre">Primo aidants</a>
        <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&modele=benevole#offre">Bénévoles vide-grenier</a>
      </div>
    </section>

  <?php elseif ($vue === 'postes'): ?>
    <?php if ($offer !== null): $isEdit = $editing !== null; ?>
      <section class="panel" id="offre">
        <div class="panel-head">
          <div>
            <h2><?= $isEdit ? 'Modifier « ' . e($offer['title']) . ' »' : 'Nouveau besoin' ?></h2>
            <p class="hint">Le lien public est créé automatiquement. Décrivez le besoin simplement : le formulaire s’adapte à la piste choisie.</p>
          </div>
          <a class="btn small" href="/portail/recrutement/?vue=postes">Fermer</a>
        </div>
        <form method="post" action="<?= e($formUrl) ?>" class="rc-grid" autocomplete="off">
          <?= $csrf ?>
          <input type="hidden" name="action" value="offer_save">
          <input type="hidden" name="id" value="<?= e($offer['id']) ?>">
          <label class="rc-full">Titre du besoin<input type="text" name="title" maxlength="120" required value="<?= e($offer['title']) ?>" placeholder="ex. Développeur PHP freelance"></label>
          <label>Piste<select name="track" id="rc-track"><?php foreach (RC_TRACKS as $key => $track): ?><option value="<?= e($key) ?>"<?= $offer['track'] === $key ? ' selected' : '' ?>><?= e($track['label']) ?></option><?php endforeach; ?></select></label>
          <label>Contrat<select name="contract"><?php foreach (RC_CONTRACTS as $key => $label): ?><option value="<?= e($key) ?>"<?= $offer['contract'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label>Statut<select name="status"><?php foreach (RC_OFFER_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $offer['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
          <label>Nombre de places<input type="number" name="slots" min="1" max="500" value="<?= (int) $offer['slots'] ?>"></label>
          <label>Lieu<input type="text" name="location" maxlength="120" value="<?= e($offer['location']) ?>" placeholder="Ville, lieu ou « À distance »"></label>
          <label>Rémunération / contrepartie<input type="text" name="compensation" maxlength="200" value="<?= e($offer['compensation']) ?>" placeholder="ex. 250 €/jour, à définir, bénévolat"></label>
          <label>Date de la mission<input type="date" name="date" value="<?= e($offer['date']) ?>"></label>
          <label>Date limite de candidature<input type="date" name="deadline" value="<?= e($offer['deadline']) ?>"></label>
          <label class="rc-full" id="rc-event-wrap">Vide-grenier associé (bénévoles)
            <select name="event">
              <option value="">— aucun —</option>
              <?php foreach ($events as $event): ?>
                <option value="<?= e($event['slug']) ?>"<?= $offer['event'] === $event['slug'] ? ' selected' : '' ?>><?= e($event['title']) ?><?= $event['date'] !== '' ? ' · ' . e(rc_date_fr($event['date'])) : '' ?><?= $event['city'] !== '' ? ' · ' . e($event['city']) : '' ?></option>
              <?php endforeach; ?>
              <?php if ($offer['event'] !== '' && !array_filter($events, static fn ($event): bool => $event['slug'] === $offer['event'])): ?>
                <option value="<?= e($offer['event']) ?>" selected><?= e($offer['event']) ?> (événement non listé)</option>
              <?php endif; ?>
            </select>
            <span class="rc-meta"><?= $events ? 'Seuls les vides-grenier à venir sont proposés.' : 'Aucun vide-grenier à venir dans les galeries : créez-en un depuis Galeries.' ?></span>
          </label>
          <div class="rc-full">
            <p class="rc-meta" style="margin:0 0 6px;">Domaines</p>
            <div class="rc-checks">
              <?php foreach (RC_DOMAINS as $key => $label): ?>
                <label class="rc-check"><input type="checkbox" name="domains[]" value="<?= e($key) ?>"<?= in_array($key, $offer['domains'], true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <label class="rc-full">Présentation<textarea name="description" maxlength="3000" placeholder="Pourquoi ce besoin, dans quel contexte…"><?= e($offer['description']) ?></textarea></label>
          <label class="rc-full">Missions<textarea name="missions" maxlength="3000" placeholder="Une mission par ligne"><?= e($offer['missions']) ?></textarea></label>
          <label class="rc-full">Profil recherché<textarea name="profile" maxlength="2000"><?= e($offer['profile']) ?></textarea></label>
          <label class="rc-full"><span><input type="checkbox" name="remote" value="1"<?= $offer['remote'] ? ' checked' : '' ?>> Possible à distance</span></label>
          <div class="rc-actions rc-full">
            <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Enregistrer le besoin' : 'Publier le besoin' ?></button>
            <a class="btn" href="/portail/recrutement/?vue=postes">Annuler</a>
            <?php if ($isEdit): ?>
              <a class="btn small" href="<?= e(rc_public_url('offre=' . urlencode($offer['code']))) ?>" target="_blank" rel="noopener">Voir la page publique ↗</a>
              <span class="rc-meta">Code public : <code><?= e($offer['code']) ?></code></span>
            <?php endif; ?>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Besoins publiés <small><?= count($offers) ?></small></h2>
          <p class="hint">Ouvrez un besoin pour modifier son texte, le fermer ou copier son lien public.</p>
        </div>
        <div class="rc-actions">
          <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1#offre">Nouveau besoin</a>
          <a class="btn small" href="/portail/recrutement/?vue=postes&export=csv">Exporter les candidatures</a>
        </div>
      </div>
      <?php if (!$offers): ?>
        <div class="empty-state"><b>Aucun besoin pour le moment</b>Créez un besoin ou partez d’un modèle depuis la synthèse.</div>
      <?php else: ?>
        <div class="scroll">
          <table class="t rc-table">
            <thead><tr><th>Besoin</th><th>Piste</th><th>Domaine</th><th>Candidatures</th><th>Statut</th><th>Lien</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($offers as $o): $count = rc_offer_candidate_count($o['id']); ?>
              <tr>
                <td>
                  <b><?= e($o['title']) ?></b>
                  <div class="rc-meta"><?= e(RC_CONTRACTS[$o['contract']]) ?><?= $o['location'] !== '' ? ' · ' . e($o['location']) : '' ?><?= $o['deadline'] !== '' ? ' · avant le ' . e(rc_date_fr($o['deadline'])) : '' ?></div>
                </td>
                <td><span class="rc-badge <?= e($o['track']) ?>"><?= e(RC_TRACKS[$o['track']]['short']) ?></span></td>
                <td class="rc-meta"><?= e($o['domains'] ? implode(', ', array_map(static fn ($d) => RC_DOMAINS[$d], $o['domains'])) : '—') ?></td>
                <td><a href="/portail/recrutement/?vue=candidatures&offre=<?= e($o['id']) ?>"><?= (int) $count ?></a></td>
                <td><span class="pill<?= $o['status'] === 'ouvert' ? ' done' : '' ?>"><?= e(RC_OFFER_STATUSES[$o['status']]) ?></span></td>
                <td><code class="rc-meta"><?= e($o['code']) ?></code></td>
                <td>
                  <div class="rc-actions">
                    <a class="btn small" href="/portail/recrutement/?vue=postes&edit=<?= e($o['id']) ?>#offre">Modifier</a>
                    <button class="btn small" type="button" data-copy="<?= e(rc_public_url('offre=' . urlencode($o['code']))) ?>">Copier le lien</button>
                    <form method="post" action="<?= e($formUrl) ?>">
                      <?= $csrf ?><input type="hidden" name="action" value="offer_status"><input type="hidden" name="id" value="<?= e($o['id']) ?>">
                      <select name="status" aria-label="Statut de <?= e($o['title']) ?>" onchange="this.form.submit()"><?php foreach (RC_OFFER_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $o['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                    </form>
                    <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Supprimer ce besoin ? Les candidatures déjà reçues sont conservées.');">
                      <?= $csrf ?><input type="hidden" name="action" value="offer_delete"><input type="hidden" name="id" value="<?= e($o['id']) ?>">
                      <button class="btn small danger" type="submit">Supprimer</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <?php elseif ($vue === 'candidatures'): ?>
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Candidatures <small><?= count($applications) ?></small></h2>
          <p class="hint">Faites avancer chaque candidature : contact, entretien, décision. Les notes restent internes.</p>
        </div>
        <a class="btn small" href="/portail/recrutement/?vue=candidatures&export=csv">Exporter le CSV</a>
      </div>
      <form method="get" action="/portail/recrutement/" class="rc-bar">
        <input type="hidden" name="vue" value="candidatures">
        <label class="rc-grow">Rechercher<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Nom, e-mail, ville, message…"></label>
        <label>Piste<select name="piste"><option value="">Toutes</option><?php foreach (RC_TRACKS as $key => $track): ?><option value="<?= e($key) ?>"<?= $filters['track'] === $key ? ' selected' : '' ?>><?= e($track['label']) ?></option><?php endforeach; ?></select></label>
        <label>Besoin<select name="offre"><option value="">Tous</option><?php foreach ($offers as $o): ?><option value="<?= e($o['id']) ?>"<?= $filters['offer'] === $o['id'] ? ' selected' : '' ?>><?= e($o['title']) ?></option><?php endforeach; ?></select></label>
        <label>Statut<select name="statut"><option value="">Tous</option><?php foreach (RC_STATUSES as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['status'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <button class="btn small" type="submit">Filtrer</button>
        <?php if (array_filter($filters)): ?><a class="btn small" href="/portail/recrutement/?vue=candidatures">Réinitialiser</a><?php endif; ?>
      </form>

      <?php if (!$applications): ?>
        <div class="empty-state"><b>Aucune candidature<?= array_filter($filters) ? ' avec ces filtres' : '' ?></b><?= array_filter($filters) ? 'Élargissez la recherche ou réinitialisez les filtres.' : 'Partagez un lien de besoin depuis l’onglet Besoins : les candidatures apparaîtront ici.' ?></div>
      <?php else: ?>
        <div class="rc-grid" style="grid-template-columns:1fr;">
          <?php foreach ($applications as $a): ?>
            <article class="rc-card">
              <h3>
                <span class="rc-member">
                  <span class="rc-avatar" aria-hidden="true"><?= e(rc_initials($a)) ?></span>
                  <span><?= e($a['name'] !== '' ? $a['name'] : $a['email']) ?><span class="rc-meta" style="display:block;font-weight:400;"><?= e(rc_track_label($a['track'])) ?> · <?= e($offersById[$a['offer_id']]['title'] ?? 'Candidature spontanée') ?></span></span>
                </span>
                <span class="pill<?= $a['status'] === 'recue' ? '' : ($a['status'] === 'integree' ? ' done' : ' sent') ?>"><?= e(rc_status_label($a['status'], $a['track'])) ?></span>
                <span class="rc-meta" style="font-weight:400;">reçue le <?= e(date('d/m/Y H:i', $a['created'])) ?></span>
                <?php if ($a['team_sent']): ?><span class="rc-badge primo">envoyée à l’équipe</span><?php endif; ?>
              </h3>
              <p class="rc-meta">
                <a href="mailto:<?= e($a['email']) ?>?subject=<?= rawurlencode('Votre candidature — ' . ($offersById[$a['offer_id']]['title'] ?? 'Brocs')) ?>"><?= e($a['email']) ?></a>
                <?= $a['phone'] !== '' ? ' · ' . e($a['phone']) : '' ?><?= $a['city'] !== '' ? ' · ' . e($a['city']) : '' ?>
              </p>
              <ul class="rc-detail">
                <li><span>Domaines</span><span><?= $a['domains'] ? e(implode(', ', array_map(static fn ($d) => RC_DOMAINS[$d], $a['domains']))) : '—' ?></span></li>
                <?php if ($a['track'] === 'emploi'): ?>
                  <li><span>Contrat</span><span><?= e(RC_CONTRACTS[$a['contract']]) ?><?= $a['salary'] !== '' ? ' · ' . e($a['salary']) : '' ?></span></li>
                <?php endif; ?>
                <li><span>Disponibilité</span><span><?= e($a['availability'] !== '' ? $a['availability'] : '—') ?></span></li>
                <?php if ($a['portfolio'] !== ''): ?><li><span>Portfolio</span><a href="<?= e($a['portfolio']) ?>" target="_blank" rel="noopener"><?= e($a['portfolio']) ?></a></li><?php endif; ?>
                <?php if ($a['track'] === 'benevole'): ?>
                  <li><span>Événement</span><span><?= e($a['event_date'] !== '' ? $a['event_date'] : ($offersById[$a['offer_id']]['date'] !== '' ? rc_date_fr($offersById[$a['offer_id']]['date']) : '—')) ?></span></li>
                  <li><span>Mobilité</span><span><?= e($a['mobility'] !== '' ? $a['mobility'] : '—') ?></span></li>
                <?php endif; ?>
              </ul>
              <?php if ($a['experience'] !== ''): ?><p class="rc-msg"><b>Parcours</b><br><?= e($a['experience']) ?></p><?php endif; ?>
              <?php if ($a['tasks'] !== ''): ?><p class="rc-msg"><b>Tâches souhaitées</b><br><?= e($a['tasks']) ?></p><?php endif; ?>
              <?php if ($a['motivation'] !== ''): ?><p class="rc-msg"><?= e($a['motivation']) ?></p><?php endif; ?>
              <?php if ($a['notes']): ?>
                <ul class="rc-notes">
                  <?php foreach (array_reverse($a['notes']) as $note): ?><li><time><?= e(date('d/m/Y H:i', $note['ts'])) ?></time><?= e($note['text']) ?></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if ($a['reason'] !== ''): ?><p class="rc-meta">Motif : <?= e($a['reason']) ?></p><?php endif; ?>

              <div class="rc-actions">
                <form method="post" action="<?= e($formUrl) ?>">
                  <?= $csrf ?><input type="hidden" name="action" value="application_status"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <select name="status" aria-label="Statut de <?= e($a['name']) ?>"><?= $statusOptions($a['status'], $a['track']) ?></select>
                  <button class="btn small" type="submit">Mettre à jour</button>
                </form>
                <?php if ($a['track'] === 'primo' && !$a['team_sent'] && in_array($a['status'], ['retenue', 'integree'], true)): ?>
                  <form method="post" action="<?= e($formUrl) ?>">
                    <?= $csrf ?><input type="hidden" name="action" value="application_to_team"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                    <button class="btn small btn-primary" type="submit">Envoyer à l’équipe</button>
                  </form>
                <?php endif; ?>
                <details>
                  <summary class="btn small">Ajouter une note</summary>
                  <form method="post" action="<?= e($formUrl) ?>" class="rc-invite" style="margin-top:8px;">
                    <?= $csrf ?><input type="hidden" name="action" value="application_note"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                    <input type="text" name="note" maxlength="1500" placeholder="Compte rendu d’appel, disponibilité, suite…" style="min-width:260px;">
                    <button class="btn small" type="submit">Enregistrer</button>
                  </form>
                </details>
                <form method="post" action="<?= e($formUrl) ?>" onsubmit="return confirm('Supprimer définitivement cette candidature ?');">
                  <?= $csrf ?><input type="hidden" name="action" value="application_delete"><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <button class="btn small danger" type="submit">Supprimer</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

  <?php elseif ($vue === 'benevoles'): ?>
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Prochains vides-grenier</h2>
          <p class="hint">Repris des galeries (type vide-grenier ou puces). Créez un besoin de bénévolat rattaché à un événement, puis suivez les confirmations.</p>
        </div>
        <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&modele=benevole#offre">Créer une mission</a>
      </div>
      <?php if (!$events): ?>
        <div class="empty-state"><b>Aucun vide-grenier à venir</b>Déclarez la date de l’événement dans Galeries → Événement, ou créez une mission de bénévolat sans événement.</div>
      <?php else: ?>
        <div class="rc-grid" style="grid-template-columns:repeat(auto-fit,minmax(300px,1fr));">
          <?php foreach ($events as $event):
              $eventOffers = rc_offers_for_event($event['slug']);
              $volunteers = rc_event_volunteers($event['slug']); ?>
            <article class="rc-event">
              <h3><?= e($event['title']) ?></h3>
              <p class="rc-date"><?= e($event['date'] !== '' ? rc_date_fr($event['date']) : 'date à définir') ?><?= $event['opening'] !== '' ? ' · ' . e($event['opening']) : '' ?><?= $event['closing'] !== '' ? '–' . e($event['closing']) : '' ?></p>
              <p class="rc-meta"><?= e(trim($event['place'] . ($event['city'] !== '' ? ' · ' . $event['city'] : '')) ?: $event['gallery']) ?><?= $event['published'] ? '' : ' · non publié' ?></p>
              <p class="rc-meta">
                <?= count($eventOffers) ?> mission<?= count($eventOffers) > 1 ? 's' : '' ?> ·
                <?= count($volunteers) ?> bénévole<?= count($volunteers) > 1 ? 's' : '' ?> confirmé<?= count($volunteers) > 1 ? 's' : '' ?>
              </p>
              <?php if ($volunteers): ?>
                <ul class="rc-detail">
                  <?php foreach ($volunteers as $v): ?><li><span><?= e($v['name']) ?></span><span><?= e($v['phone'] !== '' ? $v['phone'] : $v['email']) ?></span></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="rc-actions">
                <a class="btn small" href="/portail/recrutement/?vue=postes&nouveau=1&track=benevole&event=<?= e($event['slug']) ?>&modele=benevole#offre">Créer un besoin</a>
                <?php foreach ($eventOffers as $o): ?>
                  <a class="btn small" href="/portail/recrutement/?vue=candidatures&offre=<?= e($o['id']) ?>"><?= e($o['title']) ?> (<?= rc_offer_candidate_count($o['id']) ?>)</a>
                <?php endforeach; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Candidatures bénévoles <small><?= count($benevoleApps) ?></small></h2>
          <p class="hint">Toutes les candidatures de la piste bénévolat, quel que soit l’événement.</p>
        </div>
        <a class="btn small" href="/portail/recrutement/?vue=candidatures&piste=benevole">Ouvrir dans Candidatures</a>
      </div>
      <?php if (!$benevoleApps): ?>
        <div class="empty-state"><b>Aucune candidature bénévole</b>Partagez le formulaire bénévole ou le lien d’une mission.</div>
      <?php else: ?>
        <div class="scroll">
          <table class="t rc-table">
            <thead><tr><th>Bénévole</th><th>Mission</th><th>Disponibilité</th><th>Statut</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($benevoleApps as $a): ?>
              <tr>
                <td><b><?= e($a['name']) ?></b><br><span class="rc-meta"><?= e($a['phone'] !== '' ? $a['phone'] : $a['email']) ?></span></td>
                <td><?= e($offersById[$a['offer_id']]['title'] ?? 'Spontanée') ?></td>
                <td class="rc-meta"><?= e($a['availability'] !== '' ? $a['availability'] : '—') ?><?= $a['mobility'] !== '' ? ' · ' . e($a['mobility']) : '' ?></td>
                <td><span class="pill<?= in_array($a['status'], ['retenue', 'integree'], true) ? ' done' : '' ?>"><?= e(rc_status_label($a['status'], $a['track'])) ?></span></td>
                <td><a class="btn small" href="/portail/recrutement/?vue=candidatures&q=<?= urlencode($a['email']) ?>">Traiter</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Partager le recrutement</h2>
          <p class="hint">Trois formulaires publics, adaptés à chaque piste, plus un lien par besoin. Aucune inscription préalable n’est demandée au candidat.</p>
        </div>
        <a class="btn small" href="/portail/recrutement/postuler.php" target="_blank" rel="noopener">Ouvrir la page publique ↗</a>
      </div>
      <div class="rc-grid" style="grid-template-columns:1fr;">
        <article class="rc-card">
          <h3>Liens par piste</h3>
          <?php foreach (RC_TRACKS as $key => $track): $url = rc_public_url('piste=' . $key); ?>
            <div class="rc-invite">
              <span class="rc-badge <?= e($key) ?>"><?= e($track['label']) ?></span>
              <code><?= e($url) ?></code>
              <button class="btn small" type="button" data-copy="<?= e($url) ?>">Copier</button>
              <a class="btn small" href="<?= e($url) ?>" target="_blank" rel="noopener">Ouvrir ↗</a>
            </div>
          <?php endforeach; ?>
        </article>
        <article class="rc-card">
          <h3>Liens par besoin</h3>
          <?php if (!$offers): ?><p class="rc-meta">Créez un besoin pour obtenir un lien dédié.</p><?php endif; ?>
          <?php foreach ($offers as $o): $url = rc_public_url('offre=' . urlencode($o['code'])); ?>
            <div class="rc-invite">
              <span class="rc-badge <?= e($o['track']) ?>"><?= e($o['code']) ?></span>
              <span><?= e($o['title']) ?></span>
              <button class="btn small" type="button" data-copy="<?= e($url) ?>">Copier</button>
              <a class="btn small" href="<?= e($url) ?>" target="_blank" rel="noopener">Ouvrir ↗</a>
            </div>
          <?php endforeach; ?>
        </article>
        <article class="rc-card">
          <h3>Réglages</h3>
          <form method="post" action="<?= e($formUrl) ?>" class="rc-grid">
            <?= $csrf ?><input type="hidden" name="action" value="settings_save">
            <label class="rc-full">E-mail de notification<input type="email" name="notify" maxlength="190" value="<?= e($settings['notify']) ?>" placeholder="vous@exemple.fr"><span class="rc-meta">Une alerte par candidature. Laisser vide pour ne rien envoyer.</span></label>
            <label class="rc-full">Introduction de la page publique<textarea name="intro" maxlength="600"><?= e($settings['intro']) ?></textarea></label>
            <label class="rc-full"><span><input type="checkbox" name="open" value="1"<?= $settings['open'] ? ' checked' : '' ?>> Laisser les candidatures spontanées ouvertes (sans lien de besoin)</span></label>
            <div class="rc-actions rc-full"><button class="btn btn-primary" type="submit">Enregistrer les réglages</button></div>
          </form>
        </article>
      </div>
    </section>
  <?php endif; ?>

  </div>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (button) {
  button.addEventListener('click', function () {
    var text = button.getAttribute('data-copy') || '';
    var done = function () { var was = button.textContent; button.textContent = 'Copié'; setTimeout(function () { button.textContent = was; }, 1400); };
    if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(text).then(done).catch(function () {}); return; }
    var input = document.createElement('input'); input.value = text; document.body.appendChild(input); input.select();
    try { document.execCommand('copy'); done(); } catch (e) {} document.body.removeChild(input);
  });
});
(function () {
  var track = document.getElementById('rc-track');
  var wrap = document.getElementById('rc-event-wrap');
  if (!track || !wrap) return;
  var sync = function () { wrap.hidden = track.value !== 'benevole'; };
  track.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
