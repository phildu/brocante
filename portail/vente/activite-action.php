<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fv_redirect('prospects.php');
}
fv_csrf_check();

$pdo = fv_db();
$prospectId = (int) ($_POST['prospect_id'] ?? 0);
$prospect = $prospectId > 0 ? fv_prospect_get($prospectId) : null;
$back = fv_back($prospect ? 'prospects.php?id=' . $prospectId : 'prospects.php');

if (!$prospect) {
    fv_flash('Ce prospect n’existe pas ou ne vous est pas attribué.', 'error');
    fv_redirect($back);
}

$kind = (string) ($_POST['kind'] ?? 'appel');
if (!in_array($kind, ['appel', 'visite', 'email', 'rdv', 'note'], true)) {
    $kind = 'appel';
}
$outcome = (string) ($_POST['outcome'] ?? '');
$allowed = fv_outcomes()[$kind] ?? [];
if (!array_key_exists($outcome, $allowed)) {
    $outcome = '';
}
$notes = fv_paragraph($_POST['notes'] ?? '', 4000);
$nextAt = trim((string) ($_POST['next_at'] ?? ''));
if ($nextAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextAt)) $nextAt = '';
$duration = max(0, min(600, (int) ($_POST['duration'] ?? 0)));
$points = fv_points_for($kind, $outcome);

try {
    fv_log_activity($prospectId, $kind, $outcome, $notes, $nextAt !== '' ? $nextAt : null, $duration);
    $label = fv_kind_label($kind);
    if ($outcome !== '') {
        $label .= ' · ' . fv_outcome_label($kind, $outcome);
    }
    fv_flash($label . ' enregistré' . ($points > 0 ? ' · +' . $points . ' pts' : '') . '.');
} catch (Throwable $e) {
    error_log('vente/activite-action : ' . $e->getMessage());
    fv_flash('L’échange n’a pas pu être enregistré. Réessayez.', 'error');
}

fv_redirect($back);
