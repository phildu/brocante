<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
admin_csrf_check();

// Un champ laissé vide conserve la valeur enregistrée (comme pour les autres clés).
$file = SECRETS_DIR . '/modal-video.json';
$url = rtrim(trim((string) ($_POST['modal_url'] ?? '')), '/');
$token = trim((string) ($_POST['modal_token'] ?? ''));

if (!empty($_POST['remove'])) {
    if (is_file($file)) unlink($file);
    flash_set('Configuration du service Modal retirée.');
} elseif ($url === '' && $token === '') {
    flash_set('Rien à enregistrer.');
} else {
    $url = $url !== '' ? $url : MODAL_VIDEO_URL;
    $token = $token !== '' ? $token : MODAL_VIDEO_TOKEN;
    if (!modal_video_url_valid($url)) {
        flash_set("Adresse non valide : elle doit ressembler à https://votre-compte--boutique-ltx-video-web.modal.run (affichée par « modal deploy »).", 'error');
    } elseif (!preg_match('/^[A-Za-z0-9._~+\/=-]{8,200}$/', $token)) {
        flash_set("Jeton non valide : 8 à 200 caractères, sans espace.", 'error');
    } else {
        if (!is_dir(SECRETS_DIR)) mkdir(SECRETS_DIR, 0700, true);
        file_put_contents($file, json_encode(['url' => $url, 'token' => $token]));
        chmod($file, 0600);
        // Test immédiat avec les valeurs qui viennent d'être enregistrées.
        $ch = curl_init($url . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200 && !empty((json_decode((string) $body, true) ?: [])['ok'])) {
            flash_set('Service Modal enregistré : connexion réussie. Wan 2.2 (vidéo) et le détourage haute précision sont disponibles dans la galerie.');
        } else {
            flash_set('Enregistré, mais le test a échoué : ' . modal_video_error_message($code, json_decode((string) $body, true) ?: []), 'error');
        }
    }
}

header('Location: /admin/index.php#modal-video-settings');
exit;
