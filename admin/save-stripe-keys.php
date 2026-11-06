<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}
admin_csrf_check();

$action = (string) ($_POST['action'] ?? 'save');
$mode = (string) ($_POST['mode'] ?? stripe_mode_current(SECRETS_DIR));
if (!isset(STRIPE_MODES[$mode])) $mode = 'test';

if ($action === 'activate') {
    [$ok, $message] = stripe_mode_activate($mode, SECRETS_DIR);
    flash_set($message, $ok ? 'ok' : 'error');
    header('Location: /admin/index.php?s=paiement#stripe-settings');
    exit;
}

// Un champ laissé vide ne touche pas à la clé déjà enregistrée — évite de
// devoir tout recoller à chaque fois qu'on modifie un autre réglage.
$secret = trim((string) ($_POST['stripe_secret'] ?? ''));
$publishable = trim((string) ($_POST['stripe_publishable'] ?? ''));
$webhook = trim((string) ($_POST['stripe_webhook'] ?? ''));

// Les deux champs sont facilement inversés au collage (les deux clés se
// ressemblent, l'autofill du navigateur s'y mêle parfois) — le préfixe les
// distingue sans ambiguïté, donc on corrige plutôt que d'enregistrer des
// clés visiblement échangées.
$swapped = false;
if (str_starts_with($secret, 'pk_') && str_starts_with($publishable, 'sk_')) {
    [$secret, $publishable] = [$publishable, $secret];
    $swapped = true;
}

$hadSecret = stripe_read_secret(SECRETS_DIR . '/stripe_secret.key') !== '';
[$ok, $message] = stripe_mode_save($mode, ['secret' => $secret, 'publishable' => $publishable, 'webhook' => $webhook], SECRETS_DIR);
if ($ok && !$hadSecret) {
    // Première paire enregistrée : elle entre en service immédiatement, quel
    // que soit le mode (sinon une clé réelle resterait inactive derrière le
    // mode test par défaut).
    [$ok, $message] = stripe_mode_activate($mode, SECRETS_DIR);
}
if ($swapped && $ok) {
    $message .= ' Vos deux clés étaient inversées (secrète/publiable) : corrigé automatiquement.';
}
flash_set($message, $ok ? 'ok' : 'error');
header('Location: /admin/index.php?s=paiement#stripe-settings');
exit;
