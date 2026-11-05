<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/dashboard.php');
    exit;
}
require_csrf();
analytics_ensure_schema();

// Retour sur la même vue (mois/année) et la même section après l'action.
$back = (string) ($_POST['return'] ?? '');
if (!preg_match('#^/admin/dashboard\.php(\?[A-Za-z0-9=&_%-]*)?(\#[A-Za-z0-9_-]+)?$#', $back)) $back = '/admin/dashboard.php';

function dash_redirect(string $back, string $anchor = ''): void
{
    $base = preg_replace('/#.*$/', '', $back);
    header('Location: ' . $base . ($anchor !== '' ? '#' . $anchor : ''));
    exit;
}

function dash_valid_date(?string $d): ?string
{
    $d = trim((string) $d);
    if ($d === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : null;
}

$action = (string) ($_POST['action'] ?? '');
$pdo = db();

if ($action === 'cost_save') {
    $id = (int) ($_POST['id'] ?? 0);
    $label = trim((string) ($_POST['label'] ?? ''));
    $category = array_key_exists($_POST['category'] ?? '', cost_categories()) ? $_POST['category'] : 'autre';
    $kind = array_key_exists($_POST['kind'] ?? '', cost_kinds()) ? $_POST['kind'] : 'fixed';
    $amount = parse_euros($_POST['amount'] ?? null);
    $period = in_array($_POST['period'] ?? '', ['monthly', 'yearly', 'once'], true) ? $_POST['period'] : 'monthly';
    $unit = $kind === 'per_unit' && array_key_exists($_POST['unit'] ?? '', cost_usage_units()) ? $_POST['unit'] : null;
    $status = ($_POST['status'] ?? '') === 'test' ? 'test' : 'actif';
    $start = dash_valid_date($_POST['start_date'] ?? null);
    $end = dash_valid_date($_POST['end_date'] ?? null);
    $note = trim((string) ($_POST['note'] ?? ''));

    if ($label === '' || $amount === null) {
        flash_set('Indiquez au moins un libellé et un montant (ex : 12,50).', 'error');
        dash_redirect($back, 'frais-gerer');
    }
    if ($kind === 'per_unit' && $unit === null) {
        flash_set("Choisissez l'usage mesuré à facturer (images générées, analyses, détourages).", 'error');
        dash_redirect($back, 'frais-gerer');
    }
    if ($kind === 'per_order_pct' && $amount > 100) {
        flash_set('Un pourcentage ne peut pas dépasser 100.', 'error');
        dash_redirect($back, 'frais-gerer');
    }
    if ($start && $end && $end < $start) {
        flash_set('La date de fin précède la date de début.', 'error');
        dash_redirect($back, 'frais-gerer');
    }
    $vals = [$label, $category, $kind, $amount, $period, $unit, $status, $start, $end, $note];
    if ($id > 0) {
        $pdo->prepare('UPDATE shop_costs SET label=?, category=?, kind=?, amount=?, period=?, unit=?, status=?, start_date=?, end_date=?, note=? WHERE id=?')
            ->execute([...$vals, $id]);
        flash_set('Frais « ' . $label . ' » mis à jour.');
    } else {
        $pdo->prepare('INSERT INTO shop_costs (label, category, kind, amount, period, unit, status, start_date, end_date, note) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute($vals);
        flash_set('Frais « ' . $label . ' » ajouté.');
    }
    dash_redirect($back, 'frais-gerer');
}

if ($action === 'cost_delete') {
    $pdo->prepare('DELETE FROM shop_costs WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
    flash_set('Ligne de frais supprimée.');
    dash_redirect($back, 'frais-gerer');
}

if ($action === 'cost_toggle') {
    // Bascule « option de test » ⇄ « payant réellement » (ex : on passe Stripe en mode live).
    $pdo->prepare("UPDATE shop_costs SET status = CASE status WHEN 'actif' THEN 'test' ELSE 'actif' END WHERE id = ?")->execute([(int) ($_POST['id'] ?? 0)]);
    flash_set('Statut du frais modifié.');
    dash_redirect($back, 'frais-gerer');
}

if ($action === 'physical_save') {
    $day = dash_valid_date($_POST['day'] ?? null);
    if (!$day || $day > dash_now()->format('Y-m-d')) {
        flash_set('Choisissez une date valide, pas dans le futur.', 'error');
        dash_redirect($back, 'physique');
    }
    $visitors = max(0, (int) ($_POST['visitors'] ?? 0));
    $salesCount = max(0, (int) ($_POST['sales_count'] ?? 0));
    $sales = parse_euros($_POST['sales_amount'] ?? null) ?? 0.0;
    $cogs = parse_euros($_POST['cogs_amount'] ?? null);
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($salesCount > 0 && $visitors > 0 && $salesCount > $visitors) {
        flash_set('Il ne peut pas y avoir plus de ventes que de visiteurs ce jour-là.', 'error');
        dash_redirect($back, 'physique');
    }
    $pdo->prepare('INSERT INTO physical_visits (day, visitors, sales_count, sales_cents, cogs_cents, note) VALUES (?,?,?,?,?,?)
                   ON CONFLICT(day) DO UPDATE SET visitors=excluded.visitors, sales_count=excluded.sales_count, sales_cents=excluded.sales_cents, cogs_cents=excluded.cogs_cents, note=excluded.note')
        ->execute([$day, $visitors, $salesCount, (int) round($sales * 100), $cogs === null ? null : (int) round($cogs * 100), $note]);
    flash_set('Fréquentation du ' . date('d/m/Y', strtotime($day)) . ' enregistrée.');
    dash_redirect($back, 'physique');
}

if ($action === 'physical_inc') {
    // Compteur rapide « +1 visiteur » depuis la boutique, pour la journée en cours.
    $day = dash_now()->format('Y-m-d');
    $delta = ($_POST['delta'] ?? '1') === '-1' ? -1 : 1;
    $pdo->prepare('INSERT INTO physical_visits (day, visitors) VALUES (?, ?)
                   ON CONFLICT(day) DO UPDATE SET visitors = MAX(visitors + ?, 0)')
        ->execute([$day, max(0, $delta), $delta]);
    dash_redirect($back, 'physique');
}

if ($action === 'physical_delete') {
    $day = dash_valid_date($_POST['day'] ?? null);
    if ($day) $pdo->prepare('DELETE FROM physical_visits WHERE day = ?')->execute([$day]);
    flash_set('Journée supprimée.');
    dash_redirect($back, 'physique');
}

if ($action === 'settings_save') {
    $launch = dash_valid_date($_POST['launch_date'] ?? null);
    analytics_set_setting('launch_date', $launch ?? '');
    $goal = parse_euros($_POST['monthly_goal'] ?? null);
    analytics_set_setting('monthly_goal_cents', $goal === null ? '' : (string) (int) round($goal * 100));
    flash_set('Réglages du tableau de bord enregistrés.');
    dash_redirect($back, 'reglages-dashboard');
}

flash_set('Action inconnue.', 'error');
dash_redirect($back);
