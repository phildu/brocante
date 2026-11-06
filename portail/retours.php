<?php
// Retours des testeurs : boîte de triage, réponses par e-mail, relances et
// pilotage du programme (boutiques et galeries testeuses, widget).
// Les retours arrivent par le widget de l'administration des boutiques
// (admin/feedback-api.php, voir includes/feedback.php). Export CSV.

require __DIR__ . '/_bootstrap.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page
require_once PORTAIL_ROOT . '/includes/feedback.php';

$by = trim((string) ($_SESSION['portail_user'] ?? '')) ?: 'Exploitant local';
$focus = preg_match('/^fb[0-9]{6}-[a-f0-9]{8}$/', (string) ($_GET['focus'] ?? '')) ? (string) $_GET['focus'] : '';
$statusChoices = ['all' => 'Tous', 'open' => 'À traiter (ouverts)', 'new' => 'Nouveaux',
    'qualified' => 'Qualifiés', 'in_progress' => 'En cours', 'waiting' => 'En attente testeur',
    'done' => 'Traités', 'declined' => 'Écartés'];
$filters = [
    'statut' => in_array($_GET['statut'] ?? '', array_keys($statusChoices), true)
        ? (string) $_GET['statut'] : ($focus !== '' ? 'all' : 'open'),
    'type' => in_array($_GET['type'] ?? '', FEEDBACK_TYPES, true) ? (string) $_GET['type'] : '',
    'shop' => preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) ($_GET['shop'] ?? '')) ? (string) $_GET['shop'] : '',
    'q' => feedback_clean_text($_GET['q'] ?? '', 80),
    'jours' => in_array((int) ($_GET['jours'] ?? 0), [0, 7, 30, 90], true) ? (int) ($_GET['jours'] ?? 0) : 0,
    'module' => preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', (string) ($_GET['module'] ?? '')) ? (string) $_GET['module'] : '',
];
$backQuery = http_build_query(array_filter($filters, static fn ($v): bool => $v !== '' && $v !== 0), '', '&');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $redirect = '/portail/retours.php';
    $back = (string) ($_POST['back'] ?? '');
    if ($back !== '' && preg_match('/^[a-z0-9%&=_.+-]*$/i', $back)) $redirect .= '?' . $back;
    try {
        if ($action === 'settings') {
            $testers = is_array($_POST['testers'] ?? null) ? $_POST['testers'] : [];
            feedback_settings_save([
                'widget' => !empty($_POST['widget']),
                'open_to_all' => !empty($_POST['open_to_all']),
                'include_beta_testers' => !empty($_POST['include_beta_testers']),
                'testers' => [
                    'shops' => is_array($testers['shops'] ?? null) ? $testers['shops'] : [],
                    'galleries' => is_array($testers['galleries'] ?? null) ? $testers['galleries'] : [],
                ],
                'notify_email' => (string) ($_POST['notify_email'] ?? ''),
                'reply_days' => (int) ($_POST['reply_days'] ?? 15),
            ]);
            portail_flash('Programme testeurs enregistré.');
        } elseif ($action === 'update') {
            feedback_update((string) ($_POST['id'] ?? ''), [
                'status' => (string) ($_POST['status'] ?? ''),
                'priority' => (string) ($_POST['priority'] ?? ''),
            ], $by);
            portail_flash('Suivi mis à jour.');
        } elseif ($action === 'note') {
            feedback_note_add((string) ($_POST['id'] ?? ''), (string) ($_POST['text'] ?? ''), $by);
            portail_flash('Note interne ajoutée.');
        } elseif ($action === 'reply') {
            $record = feedback_reply((string) ($_POST['id'] ?? ''), (string) ($_POST['text'] ?? ''), $by, (string) ($_POST['to'] ?? ''));
            portail_flash('Réponse envoyée à ' . $record['email'] . '.');
        } elseif ($action === 'remind') {
            $sent = feedback_send_reminders();
            portail_flash($sent > 0
                ? $sent . ' relance(s) envoyée(s).'
                : "Aucune relance envoyée : les testeurs ont répondu récemment, la relance est déjà partie, ou leur adresse e-mail manque.", $sent > 0 ? 'ok' : 'error');
        } else {
            portail_flash('Action inconnue.', 'error');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        portail_flash($e->getMessage(), 'error');
    } catch (Throwable $e) {
        error_log('retours: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')');
        portail_flash('Action impossible pour le moment, réessayez.', 'error');
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

$settings = feedback_settings_load();
$all = feedback_all();
$list = feedback_filter_list($all, $filters);
$stats = feedback_stats($all, $settings);
$weekly = feedback_weekly($all);
$participation = feedback_participation($all, $settings);
$flash = portail_flash();
$shops = feedback_tenant_list();
$galleries = feedback_gallery_list();
$testerShops = feedback_tester_shops($settings);
$focusRecord = $focus !== '' ? feedback_find($focus) : null;
$shopChoices = [];
foreach ($shops as $slug => $cfg) $shopChoices[$slug] = (string) ($cfg['name'] ?? $slug);
foreach ($all as $f) $shopChoices[$f['shop']] = $f['shop_name'] !== '' ? $f['shop_name'] : $f['shop'];
ksort($shopChoices);

if (($_GET['export'] ?? '') === 'csv') {
    [$headers, $rows] = feedback_csv($list);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="retours-testeurs-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ';', '"', '');
    foreach ($rows as $row) fputcsv($out, $row, ';', '"', '');
    exit;
}

$dt = static fn (string $iso): string => $iso !== '' && strtotime($iso) ? date('d/m/Y H:i', (int) strtotime($iso)) : '—';
$day = static fn (int $ts): string => $ts > 0 ? date('d/m/Y', $ts) : 'jamais';
$hours = static fn (?float $h): string => $h === null ? '—' : ($h < 24 ? str_replace('.', ',', (string) $h) . ' h' : str_replace('.', ',', (string) round($h / 24, 1)) . ' j');
$statusTone = static fn (string $s): string => feedback_status_tone($s);
$testerEmailCount = 0;
foreach ($participation as $row) if ($row['email'] !== '') $testerEmailCount++;
$maxWeek = max(1, ...array_column($weekly, 'count'));
$contactFallback = '';
if (function_exists('saas_config')) {
    try { $contactFallback = trim((string) (saas_config()['contact'] ?? '')); } catch (Throwable $e) { $contactFallback = ''; }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Retours testeurs — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1180px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.02rem; }
  .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
  .kpi { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 14px 16px; display: grid; gap: 2px; }
  .kpi .n { font-size: 1.8rem; font-weight: 700; line-height: 1.1; } .kpi .l { font-size: .8rem; color: var(--muted); font-weight: 600; }
  .kpi .s { font-size: .74rem; color: var(--muted); }
  .flash { margin: 0; } .muted { color: var(--muted); }
  .fb-toolbar { display: flex; align-items: end; gap: 10px; flex-wrap: wrap; }
  .fb-toolbar label { display: grid; gap: 4px; font-size: .78rem; font-weight: 600; color: var(--muted); }
  .fb-toolbar input, .fb-toolbar select { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 8px; padding: 7px 9px; font: inherit; font-size: .86rem; }
  .fb-toolbar input[type="search"] { min-width: 210px; }
  .fb-list { display: grid; gap: 10px; }
  .fb-card { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; overflow: hidden; }
  .fb-card[data-type="bug"] { border-left: 4px solid #b3261e; }
  .fb-card[data-type="idea"] { border-left: 4px solid #b6891f; }
  .fb-card[data-type="improvement"] { border-left: 4px solid #2f6b3c; }
  .fb-card[data-type="other"] { border-left: 4px solid var(--line-strong); }
  .fb-card > summary { list-style: none; cursor: pointer; padding: 13px 15px; display: grid; gap: 6px; }
  .fb-card > summary::-webkit-details-marker { display: none; }
  .fb-card > summary:hover { background: var(--panel-2); }
  .fb-summary-top { display: flex; align-items: center; gap: 7px; flex-wrap: wrap; font-size: .82rem; }
  .fb-summary-shop { font-weight: 700; }
  .fb-summary-date { margin-left: auto; color: var(--muted); font-size: .76rem; font-variant-numeric: tabular-nums; }
  .fb-excerpt { color: var(--muted); font-size: .87rem; line-height: 1.45; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
  .fb-chip { display: inline-flex; align-items: center; min-height: 22px; padding: 0 8px; border-radius: 999px; border: 1px solid var(--line); font-size: .68rem; font-weight: 700; letter-spacing: .02em; white-space: nowrap; }
  .fb-chip svg { width: 12px; height: 12px; margin-right: 4px; }
  .fb-chip[data-tone="new"] { border-color: var(--accent, #a9542f); color: var(--accent, #a9542f); }
  .fb-chip[data-tone="doing"] { background: color-mix(in srgb, var(--accent, #a9542f) 12%, transparent); border-color: transparent; color: var(--accent, #a9542f); }
  .fb-chip[data-tone="wait"] { background: color-mix(in srgb, #b6891f 18%, transparent); border-color: transparent; }
  .fb-chip[data-tone="ok"] { background: color-mix(in srgb, #2f6b3c 14%, transparent); border-color: transparent; color: #2f6b3c; }
  .fb-chip[data-tone="off"] { opacity: .7; }
  .fb-chip--prio[data-prio="high"], .fb-chip--prio[data-prio="urgent"] { border-color: #b3261e; color: #b3261e; }
  .fb-body { padding: 0 15px 16px; display: grid; gap: 12px; border-top: 1px solid var(--line); }
  .fb-message { margin: 10px 0 0; font-size: .93rem; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; }
  .fb-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: .76rem; color: var(--muted); }
  .fb-meta code { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: .72rem; }
  .fb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px; }
  .fb-box { border: 1px solid var(--line); border-radius: 9px; padding: 11px; display: grid; gap: 7px; background: var(--panel-2); }
  .fb-box h3 { margin: 0; font-size: .8rem; letter-spacing: .02em; }
  .fb-box label { display: grid; gap: 4px; font-size: .74rem; font-weight: 600; color: var(--muted); }
  .fb-box select, .fb-box input, .fb-box textarea { width: 100%; border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 7px; padding: 6px 8px; font: inherit; font-size: .84rem; }
  .fb-box textarea { resize: vertical; min-height: 58px; }
  .fb-box .row { display: flex; gap: 7px; } .fb-box .row > * { flex: 1; min-width: 0; }
  .fb-box button { justify-self: start; }
  .fb-timeline { display: grid; gap: 7px; font-size: .78rem; }
  .fb-event { border-left: 2px solid var(--line); padding: 1px 0 1px 10px; color: var(--muted); }
  .fb-event b { color: var(--ink); font-weight: 600; }
  .fb-event[data-kind="reply"] { border-left-color: var(--accent, #a9542f); }
  .fb-event[data-kind="note"] { border-left-color: #b6891f; }
  .fb-event p { margin: 3px 0 0; color: var(--ink); white-space: pre-wrap; }
  .fb-link { color: var(--accent, #a9542f); font-weight: 600; }
  .fb-program { display: grid; gap: 12px; }
  .fb-program fieldset { border: 1px solid var(--line); border-radius: 9px; padding: 10px 12px; display: grid; gap: 8px; }
  .fb-program legend { font-size: .78rem; font-weight: 700; color: var(--muted); padding: 0 4px; }
  .fb-checks { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 5px 12px; max-height: 210px; overflow: auto; }
  .fb-checks label { display: flex; align-items: flex-start; gap: 6px; font-size: .82rem; line-height: 1.3; }
  .fb-checks input { margin-top: 2px; }
  .fb-toggle { display: flex; gap: 8px; align-items: flex-start; font-size: .84rem; }
  .fb-toggle input { margin-top: 3px; }
  .fb-row2 { display: flex; gap: 10px; flex-wrap: wrap; }
  .fb-row2 label { display: grid; gap: 4px; font-size: .78rem; font-weight: 600; color: var(--muted); }
  .fb-row2 input, .fb-row2 select { border: 1px solid var(--line); background: var(--panel); color: var(--ink); border-radius: 8px; padding: 7px 9px; font: inherit; font-size: .86rem; }
  .fb-bars { display: flex; align-items: end; gap: 8px; height: 128px; padding-top: 8px; overflow-x: auto; }
  .fb-bar { flex: 1 0 34px; display: flex; flex-direction: column; align-items: center; justify-content: end; gap: 4px; height: 100%; font-size: .7rem; color: var(--muted); }
  .fb-bar i { display: block; width: 100%; max-width: 34px; min-height: 2px; background: color-mix(in srgb, var(--accent, #a9542f) 70%, var(--line)); border-radius: 5px 5px 0 0; }
  .fb-bar b { color: var(--ink); font-variant-numeric: tabular-nums; }
  table.t { width: 100%; border-collapse: collapse; font-size: .86rem; }
  .t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 6px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; } .t tr:last-child td { border-bottom: 0; }
  .t td.r, .t th.r { text-align: right; font-variant-numeric: tabular-nums; }
  .scroll { overflow-x: auto; }
  .fb-state { font-size: .72rem; font-weight: 700; }
  .fb-state[data-state="active"] { color: #2f6b3c; } .fb-state[data-state="silent"] { color: #b6891f; } .fb-state[data-state="never"] { color: var(--muted); }
  .phead-actions form { display: inline-flex; }
  .fb-warn { color: #b3261e; font-weight: 600; }
  @media (max-width: 720px) { .fb-summary-date { margin-left: 0; width: 100%; } }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => 'Retours testeurs',
    'eyebrow' => 'Pilotage',
    'desc' => 'Bugs, idées et gênes envoyés depuis le widget des boutiques testeuses : triage, réponses, relances et suivi de la participation.',
    'actions' => '<a class="btn" href="?' . e($backQuery . ($backQuery !== '' ? '&' : '') . 'export=csv') . '">Exporter en CSV</a>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="back" value="' . e($backQuery) . '"><button class="btn" name="action" value="remind" title="Envoie un e-mail aux testeurs sans retour depuis le délai réglé">Relancer les testeurs</button></form>',
]); ?>

  <div class="owrap">
    <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

    <section class="kpis" aria-label="Chiffres clés">
      <div class="kpi"><span class="l">Nouveaux</span><span class="n"><?= (int) $stats['new'] ?></span><span class="s"><?= (int) $stats['open'] ?> ouvert(s) au total</span></div>
      <div class="kpi"><span class="l">Cette semaine</span><span class="n"><?= (int) $stats['week'] ?></span><span class="s"><?= (int) $stats['prev_week'] ?> la semaine précédente</span></div>
      <div class="kpi"><span class="l">Traités (7 j)</span><span class="n"><?= (int) $stats['done_7'] ?></span><span class="s">délai moyen <?= e($hours($stats['avg_resolve_days'] !== null ? $stats['avg_resolve_days'] * 24 : null)) ?></span></div>
      <div class="kpi"><span class="l">1re réponse</span><span class="n"><?= e($hours($stats['avg_first_reply_hours'])) ?></span><span class="s">moyenne</span></div>
      <div class="kpi"><span class="l">Testeurs actifs</span><span class="n"><?= (int) $stats['active_shops_30'] ?><span style="font-size:1rem;color:var(--muted);"> / <?= (int) $stats['testers'] ?></span></span><span class="s">sur 30 jours · <?= (int) $stats['silent'] ?> silencieux</span></div>
      <div class="kpi"><span class="l">Volume total</span><span class="n"><?= (int) $stats['total'] ?></span><span class="s"><?php foreach (FEEDBACK_TYPE_LABELS as $key => $label): ?><?= e($label) ?> <?= (int) ($stats['by_type'][$key] ?? 0) ?> · <?php endforeach; ?></span></div>
    </section>

    <section class="ocard">
      <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;">
        <h2>Dernières semaines</h2><span class="muted" style="font-size:.78rem;">retours reçus par semaine</span>
      </div>
      <div class="fb-bars" role="img" aria-label="Volume de retours par semaine">
        <?php foreach ($weekly as $week): ?>
          <div class="fb-bar" title="<?= e($week['label']) ?> : <?= (int) $week['count'] ?> retour(s)">
            <b><?= (int) $week['count'] ?></b>
            <i style="height:<?= (int) max(2, round($week['count'] / $maxWeek * 88)) ?>%"></i>
            <span><?= e($week['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <details class="ocard fb-program"<?= (!$settings['testers']['shops'] && !$settings['testers']['galleries']) ? ' open' : '' ?>>
      <summary style="cursor:pointer;display:flex;align-items:center;gap:10px;flex-wrap:wrap;list-style:none;">
        <h2 style="margin:0;">Programme testeurs</h2>
        <span class="fb-chip"><?= (int) feedback_program_shop_count($settings) ?> boutique(s)</span>
        <?php if ($settings['open_to_all']): ?><span class="fb-chip" data-tone="doing">Widget ouvert à tous</span><?php endif; ?>
        <span class="muted" style="font-size:.78rem;margin-left:auto;">depuis le <?= e($day((int) $settings['since'])) ?> · <?= (int) $testerEmailCount ?> adresse(s) e-mail</span>
      </summary>
      <form method="post" style="display:grid;gap:12px;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="back" value="<?= e($backQuery) ?>">
        <div class="fb-checks" style="max-height:none;">
          <label class="fb-toggle"><input type="checkbox" name="widget" value="1"<?= !empty($settings['widget']) ? ' checked' : '' ?>><span><strong>Afficher le widget « Un retour ? »</strong><br><span class="muted">Dans l’administration des boutiques couvertes.</span></span></label>
          <label class="fb-toggle"><input type="checkbox" name="open_to_all" value="1"<?= !empty($settings['open_to_all']) ? ' checked' : '' ?>><span><strong>Ouvrir à toutes les boutiques</strong><br><span class="muted">Le widget apparaît même hors programme testeurs.</span></span></label>
          <label class="fb-toggle"><input type="checkbox" name="include_beta_testers" value="1"<?= !empty($settings['include_beta_testers']) ? ' checked' : '' ?>><span><strong>Inclure les testeurs {des exts} « En test »</strong><br><span class="muted">Boutiques et galeries déjà déclarées dans Tarifs {des exts}.</span></span></label>
        </div>
        <div class="fb-row2">
          <label>Prévenir par e-mail à chaque nouveau retour (vide = adresse de contact<?= $contactFallback !== '' ? ' — ' . e($contactFallback) : '' ?>)
            <input type="email" name="notify_email" value="<?= e((string) $settings['notify_email']) ?>" placeholder="vous@exemple.fr">
          </label>
          <label>Délai de relance / d’inactivité (jours)
            <input type="number" name="reply_days" min="1" max="90" value="<?= (int) $settings['reply_days'] ?>">
          </label>
        </div>
        <fieldset>
          <legend>Boutiques testeuses (<?= count($settings['testers']['shops']) ?>)</legend>
          <div class="fb-checks">
            <?php foreach ($shops as $slug => $cfg): ?>
              <label><input type="checkbox" name="testers[shops][]" value="<?= e((string) $slug) ?>"<?= in_array((string) $slug, $settings['testers']['shops'], true) ? ' checked' : '' ?>><?= e((string) ($cfg['name'] ?? $slug)) ?> <span class="muted">(<?= e((string) $slug) ?>)</span></label>
            <?php endforeach; ?>
            <?php if (!$shops): ?><span class="muted">Aucune boutique sur ce serveur.</span><?php endif; ?>
          </div>
        </fieldset>
        <fieldset>
          <legend>Galeries testeuses (<?= count($settings['testers']['galleries']) ?>) — tous leurs membres locaux</legend>
          <div class="fb-checks">
            <?php foreach ($galleries as $slug => $gallery): ?>
              <label><input type="checkbox" name="testers[galleries][]" value="<?= e((string) $slug) ?>"<?= in_array((string) $slug, $settings['testers']['galleries'], true) ? ' checked' : '' ?>><?= e((string) $gallery['name']) ?> <span class="muted">(<?= count(array_filter($gallery['members'] ?? [], static fn ($m): bool => ($m['type'] ?? '') === 'local')) ?> boutique(s))</span></label>
            <?php endforeach; ?>
            <?php if (!$galleries): ?><span class="muted">Aucune galerie sur ce serveur.</span><?php endif; ?>
          </div>
        </fieldset>
        <p class="muted" style="margin:0;font-size:.8rem;">Les boutiques sans adresse e-mail ne peuvent pas recevoir de relance ni de réponse : renseignez l’identifiant de connexion de la boutique avec une adresse, ou répondez depuis la fiche du retour en saisissant l’adresse.</p>
        <div><button class="btn btn-primary" type="submit">Enregistrer le programme</button></div>
      </form>
    </details>

    <section class="ocard">
      <h2>Triage des retours <span class="muted" style="font-weight:400;font-size:.84rem;">— <?= count($list) ?> résultat(s)</span></h2>
      <form class="fb-toolbar" method="get">
        <label>Statut<select name="statut"><?php foreach ($statusChoices as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['statut'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Type<select name="type"><option value="">Tous</option><?php foreach (FEEDBACK_TYPE_LABELS as $key => $label): ?><option value="<?= e($key) ?>"<?= $filters['type'] === $key ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Boutique<select name="shop"><option value="">Toutes</option><?php foreach ($shopChoices as $slug => $name): ?><option value="<?= e((string) $slug) ?>"<?= $filters['shop'] === (string) $slug ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label>
        <label>Période<select name="jours"><?php foreach ([0 => 'Tout', 7 => '7 jours', 30 => '30 jours', 90 => '90 jours'] as $d => $label): ?><option value="<?= $d ?>"<?= $filters['jours'] === $d ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label>Recherche<input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="message, boutique, e-mail…"></label>
        <button class="btn btn-primary" type="submit">Filtrer</button>
        <a class="btn" href="/portail/retours.php">Réinitialiser</a>
      </form>
      <?php if (!$list): ?>
        <p class="muted" style="margin:6px 0;">Aucun retour ne correspond à ces filtres. <?= $settings['open_to_all'] || $testerShops ? 'Le widget est peut-être encore peu visible : vérifiez le programme et relancez les testeurs silencieux.' : 'Ajoutez des boutiques au programme testeurs ou ouvrez le widget à toutes les boutiques.' ?></p>
      <?php else: ?>
      <div class="fb-list">
        <?php foreach ($list as $f):
          $isFocus = $focusRecord && $focusRecord['id'] === $f['id'];
        ?>
        <details class="fb-card" data-type="<?= e($f['type']) ?>"<?= $isFocus ? ' open' : '' ?>>
          <summary>
            <span class="fb-summary-top">
              <span class="fb-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= feedback_type_icon($f['type']) ?></svg><?= e(FEEDBACK_TYPE_LABELS[$f['type']] ?? $f['type']) ?></span>
              <?php if ($f['severity'] !== ''): ?><span class="fb-chip"><?= e(FEEDBACK_SEVERITY_LABELS[$f['severity']] ?? $f['severity']) ?></span><?php endif; ?>
              <span class="fb-chip" data-tone="<?= e($statusTone($f['status'])) ?>"><?= e(FEEDBACK_STATUS_LABELS[$f['status']] ?? $f['status']) ?></span>
              <?php if (in_array($f['priority'], ['high', 'urgent'], true)): ?><span class="fb-chip fb-chip--prio" data-prio="<?= e($f['priority']) ?>"><?= e(FEEDBACK_PRIORITY_LABELS[$f['priority']] ?? $f['priority']) ?></span><?php endif; ?>
              <?php if (!empty($f['attachment'])): ?><span class="fb-chip">Capture</span><?php endif; ?>
              <?php if (($f['source'] ?? 'equipe') === 'client'): ?><span class="fb-chip">Client</span><?php endif; ?>
              <span class="fb-summary-shop"><?= e($f['shop_name']) ?></span>
              <?php if ($f['account'] !== ''): ?><span class="muted">· <?= e($f['account']) ?></span><?php endif; ?>
              <span class="fb-summary-date"><?= e($dt((string) $f['at'])) ?></span>
            </span>
            <span class="fb-excerpt"><?= e(mb_substr(trim((string) strtok((string) $f['message'], "\n")), 0, 180)) ?></span>
          </summary>
          <div class="fb-body">
            <p class="fb-message"><?= nl2br(e((string) $f['message'])) ?></p>
            <div class="fb-meta">
              <span>Réf. <code><?= e($f['id']) ?></code></span>
              <?php $fbUrl = trim((string) ($f['url'] ?? '')); ?>
              <?php if ($fbUrl !== ''): ?><span>Page <a class="fb-link" href="<?= e($fbUrl) ?>" target="_blank" rel="noopener"><?= e($f['page'] !== '' ? $f['page'] : $fbUrl) ?></a></span><?php elseif ($f['page'] !== ''): ?><span>Page <code><?= e($f['page']) ?></code></span><?php endif; ?>
              <?php if ($f['module'] !== ''): ?><span>{Ext} <code><?= e($f['module']) ?></code></span><?php endif; ?>
              <?php if (!empty($f['context']['viewport'])): ?><span>Écran <?= e((string) $f['context']['viewport']) ?></span><?php endif; ?>
              <?php if ($f['email'] !== ''): ?><span>E-mail <a class="fb-link" href="mailto:<?= e($f['email']) ?>"><?= e($f['email']) ?></a></span><?php else: ?><span class="fb-warn">Aucune adresse e-mail</span><?php endif; ?>
              <?php if (!empty($f['context']['ua'])): ?><span title="<?= e((string) $f['context']['ua']) ?>">Navigateur connu</span><?php endif; ?>
              <?php if (!empty($f['attachment'])): ?><span><a class="fb-link" href="/portail/retour-media.php?id=<?= e($f['id']) ?>" target="_blank" rel="noopener">Voir la capture</a></span><?php endif; ?>
            </div>
            <div class="fb-grid">
              <form class="fb-box" method="post">
                <h3>Suivi</h3>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="back" value="<?= e($backQuery) ?>">
                <div class="row">
                  <label>Statut<select name="status"><?php foreach (FEEDBACK_STATUSES as $s): ?><option value="<?= e($s) ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(FEEDBACK_STATUS_LABELS[$s]) ?></option><?php endforeach; ?></select></label>
                  <label>Priorité<select name="priority"><?php foreach (FEEDBACK_PRIORITIES as $p): ?><option value="<?= e($p) ?>"<?= $f['priority'] === $p ? ' selected' : '' ?>><?= e(FEEDBACK_PRIORITY_LABELS[$p]) ?></option><?php endforeach; ?></select></label>
                </div>
                <button class="btn" type="submit">Enregistrer</button>
              </form>
              <form class="fb-box" method="post">
                <h3>Note interne</h3>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="back" value="<?= e($backQuery) ?>">
                <label>Visible seulement dans le portail<textarea name="text" rows="3" maxlength="2000" placeholder="Piste technique, décision, doublon…"></textarea></label>
                <button class="btn" type="submit">Ajouter la note</button>
              </form>
              <form class="fb-box" method="post">
                <h3>Répondre au testeur</h3>
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reply"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="back" value="<?= e($backQuery) ?>">
                <label>Adresse e-mail<input type="email" name="to" value="<?= e($f['email']) ?>" placeholder="testeur@exemple.fr" required></label>
                <label>Message<textarea name="text" rows="3" maxlength="4000" placeholder="Merci pour ce retour, nous avons corrigé…"></textarea></label>
                <button class="btn btn-primary" type="submit">Envoyer la réponse</button>
              </form>
            </div>
            <div class="fb-timeline">
              <?php foreach ($f['status_log'] as $log): ?>
                <div class="fb-event"><b><?= e(FEEDBACK_STATUS_LABELS[$log['from']] ?? $log['from']) ?> → <?= e(FEEDBACK_STATUS_LABELS[$log['to']] ?? $log['to']) ?></b> · <?= e($dt((string) ($log['at'] ?? ''))) ?><?= !empty($log['by']) ? ' · ' . e((string) $log['by']) : '' ?></div>
              <?php endforeach; ?>
              <?php foreach ($f['notes'] as $note): ?>
                <div class="fb-event" data-kind="note"><b>Note interne</b> · <?= e($dt((string) ($note['at'] ?? ''))) ?><?= !empty($note['by']) ? ' · ' . e((string) $note['by']) : '' ?><p><?= nl2br(e((string) ($note['text'] ?? ''))) ?></p></div>
              <?php endforeach; ?>
              <?php foreach ($f['replies'] as $reply): ?>
                <div class="fb-event" data-kind="reply"><b>Réponse envoyée</b> · <?= e($dt((string) ($reply['at'] ?? ''))) ?><?= !empty($reply['by']) ? ' · ' . e((string) $reply['by']) : '' ?> <?= !empty($reply['to']) ? '→ ' . e((string) $reply['to']) : '' ?><p><?= nl2br(e((string) ($reply['text'] ?? ''))) ?></p></div>
              <?php endforeach; ?>
              <?php if (!$f['status_log'] && !$f['notes'] && !$f['replies']): ?><div class="fb-event">Aucun suivi pour l’instant. <?= $f['status'] === 'new' ? 'Qualifiez ou répondez pour démarrer.' : '' ?></div><?php endif; ?>
            </div>
          </div>
        </details>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>

    <section class="ocard">
      <h2>Participation des testeurs <span class="muted" style="font-weight:400;font-size:.84rem;">— <?= count($participation) ?> boutique(s)</span></h2>
      <div class="scroll">
        <table class="t">
          <thead><tr><th>Boutique</th><th class="r">Retours</th><th>Dernier</th><th>Inactivité</th><th>État</th><th>Adresse de réponse</th></tr></thead>
          <tbody>
            <?php foreach ($participation as $row): ?>
              <tr>
                <td><strong><?= e($row['name']) ?></strong><br><span class="muted" style="font-size:.76rem;"><?= e($row['shop']) ?></span></td>
                <td class="r"><?= (int) $row['count'] ?></td>
                <td><?= e($day((int) $row['last'])) ?></td>
                <td><?= $row['days'] === null ? '—' : (int) $row['days'] . ' j' ?></td>
                <td><span class="fb-state" data-state="<?= e($row['state']) ?>"><?= $row['state'] === 'active' ? 'Actif' : ($row['state'] === 'silent' ? 'Silencieux' : 'Jamais') ?></span></td>
                <td><?= $row['email'] !== '' ? e($row['email']) : '<span class="fb-warn">manquante</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$participation): ?><tr><td colspan="6" class="muted">Aucun testeur : cochez des boutiques ou des galeries dans le programme.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:0;font-size:.8rem;">« Silencieux » : aucun retour depuis plus de <?= (int) $settings['reply_days'] ?> jours. Le bouton « Relancer les testeurs » envoie au plus un e-mail par période à chaque boutique sans retour.</p>
    </section>

    <p class="muted" style="font-size:.78rem;">Les retours sont stockés dans <code>data/saas/feedback.json</code> sur ce serveur (hors portée du web). Les captures restent dans <code>data/saas/feedback-media/</code> et ne sont servies qu’aux personnes connectées au portail.</p>
  </div>
</div>
</body>
</html>
