<?php
// Traitement du formulaire : aperçu imprimable (action=apercu) ou archive ZIP (action=zip). Rien n'est conservé sur le disque.
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/form.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /dossier/', true, 303);
    exit;
}

$input = $_POST;
$action = (string) ($input['action'] ?? 'apercu');
$d = ddoc_parse($input);
$errors = ddoc_errors($d);

if ($errors) {
    http_response_code(422);
    ddoc_page_form($input, $errors, ddoc_warnings($d));
    exit;
}

if ($action === 'zip') {
    try {
        $path = ddoc_zip($d);
    } catch (Throwable $e) {
        error_log('dossier/generer: ' . $e->getMessage());
        http_response_code(500);
        $errors['form'] = "L'archive n'a pas pu être créée. Réessayez dans quelques instants.";
        ddoc_page_form($input, $errors, ddoc_warnings($d));
        exit;
    }
    $name = 'dossier-subvention-' . ddoc_slug($d['assoc_name']) . '-' . date('Ymd') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    @unlink($path);
    exit;
}

// Aperçu : le dossier complet, imprimable ; le ZIP et le retour au formulaire renvoient les mêmes valeurs.
header('Cache-Control: no-store');
$toolbar = '<button type="button" onclick="window.print()">Imprimer / PDF</button>'
    . '<form method="post" action="/dossier/generer.php" style="display:inline">'
    . ddoc_hidden_inputs($input) . '<input type="hidden" name="action" value="zip">'
    . '<button type="submit">Télécharger le ZIP</button></form>'
    . '<form method="post" action="/dossier/" style="display:inline">'
    . ddoc_hidden_inputs($input) . '<button type="submit" class="ghost">Modifier</button></form>';

$notice = '';
foreach (ddoc_warnings($d) as $w) {
    $notice .= '<div>' . ddoc_e($w) . '</div>';
}
if ($notice !== '') $notice = '<div class="notice">' . $notice . '</div>';

echo ddoc_complet($d, $toolbar, $notice);
