<?php
// Actions de l'administration des pages légales : identité du vendeur, texte d'une page, rétablissement du modèle, validation.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/legal.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/legal.php');
    exit;
}
admin_csrf_check();
db();
$action = (string) ($_POST['action'] ?? '');
$page = (string) ($_POST['page'] ?? '');

if ($action === 'identity') {
    // Statut du vendeur : seuls les champs demandés pour ce statut sont enregistrés ; les autres gardent leur valeur (on peut revenir en arrière).
    $kind = legal_kind_normalize((string) ($_POST['kind'] ?? ''));
    shop_setting_set('legal_kind', $kind);
    foreach (LEGAL_KIND_FIELDS[$kind] as $f) {
        $v = legal_clean_field($f, (string) ($_POST[$f] ?? ''));
        if ($f === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) { flash_set("Adresse e-mail de contact invalide.", 'error'); header('Location: /admin/legal.php#legal-identity'); exit; }
        // Hébergeur identique au défaut du portail : rien n'est enregistré, pour suivre ce défaut s'il change.
        if ($f === 'host' && $v === legal_default_host()) $v = '';
        shop_setting_set('legal_' . $f, $v);
    }
    // Point exact choisi sur la carte (facultatif) : deux valeurs vides l'effacent.
    $lat = isset($_POST['point_lat']) && is_numeric($_POST['point_lat']) ? (float) $_POST['point_lat'] : null;
    $lng = isset($_POST['point_lng']) && is_numeric($_POST['point_lng']) ? (float) $_POST['point_lng'] : null;
    legal_point_save($lat !== null && $lng !== null ? $lat : null, $lat !== null && $lng !== null ? $lng : null);
    flash_set("Identité enregistrée : les textes qui n'ont pas été modifiés à la main en tiennent compte.");
} elseif ($action === 'text' && isset(LEGAL_PAGES[$page])) {
    $text = mb_substr(trim(str_replace("\r", '', (string) ($_POST['text'] ?? ''))), 0, 40000);
    // Un texte identique au modèle reste « modèle » (il suivra les changements d'identité).
    shop_setting_set('legal_text_' . $page, $text === trim(legal_default_text($page)) ? '' : $text);
    flash_set('« ' . LEGAL_PAGES[$page]['title'] . ' » enregistré : à valider de nouveau.');
} elseif ($action === 'reset' && isset(LEGAL_PAGES[$page])) {
    shop_setting_set('legal_text_' . $page, '');
    flash_set('Modèle rétabli pour « ' . LEGAL_PAGES[$page]['title'] . ' ».');
} elseif ($action === 'validate') {
    if (empty($_POST['ok'])) flash_set('Cochez la case pour valider.', 'error');
    else { legal_validate(); flash_set('Pages légales validées.'); }
} else {
    flash_set('Action inconnue.', 'error');
}
header('Location: /admin/legal.php' . ($action === 'identity' ? '#legal-identity' : ''));
exit;
