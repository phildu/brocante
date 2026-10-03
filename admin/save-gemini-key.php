<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

// Un champ laissé vide ne touche pas à la clé déjà enregistrée — même
// logique que save-stripe-keys.php.
$key = trim((string) ($_POST['gemini_key'] ?? ''));

if ($key !== '') {
    $secretsDir = SECRETS_DIR;
    if (!is_dir($secretsDir)) mkdir($secretsDir, 0700, true);
    file_put_contents($secretsDir . '/gemini.key', $key);
    chmod($secretsDir . '/gemini.key', 0600);
    flash_set('Clé Gemini enregistrée.');
} else {
    flash_set('Aucune clé fournie — rien de changé.');
}

header('Location: /admin/index.php#gemini-settings');
exit;
