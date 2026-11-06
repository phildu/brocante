<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

header('Content-Type: application/json');

// Appel synchrone (pas de tâche de fond) : cette fonctionnalité ne se
// déclenche que sur des images déjà signalées en faible résolution (voir
// assets/admin-upload-check.js), donc déjà petites — le fichier envoyé à
// Gemini reste léger et la réponse arrive normalement en quelques dizaines
// de secondes. La version précédente passait par exec()/nohup, indisponible
// sur un hébergement mutualisé (OVH inclus) qui désactive exec()/shell_exec()
// — voir shell_exec_available() — ce qui bloquait complètement la
// fonctionnalité en production.

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['photo']['tmp_name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'Fichier manquant.']);
    exit;
}

if (!GEMINI_API_KEY) {
    echo json_encode(['ok' => false, 'error' => 'Clé Gemini absente dans la configuration — amélioration IA indisponible.']);
    exit;
}

$tmpName = $_FILES['photo']['tmp_name'];
if (!@getimagesize($tmpName)) {
    echo json_encode(['ok' => false, 'error' => "Ce fichier n'est pas une image valide."]);
    exit;
}

$binary = gemini_generate_image($tmpName, build_detail_enhance_prompt(), 1);
if (!$binary) {
    echo json_encode(['ok' => false, 'error' => "L'amélioration a échoué (service IA indisponible ou surchargé) — réessayez dans un instant."]);
    exit;
}

$path = save_binary_photo($binary, 'enhance', 'jpg', 1600, 88);
if (!$path) {
    echo json_encode(['ok' => false, 'error' => "Impossible d'enregistrer l'image améliorée."]);
    exit;
}

echo json_encode(['ok' => true, 'url' => '/' . $path]);
