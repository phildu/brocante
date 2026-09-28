<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/index.php');
    exit;
}

// Un champ laissé vide ne touche pas à la clé déjà enregistrée — évite de
// devoir tout recoller à chaque fois qu'on modifie un autre réglage.
$secret = trim((string) ($_POST['stripe_secret'] ?? ''));
$publishable = trim((string) ($_POST['stripe_publishable'] ?? ''));

// Les deux champs sont facilement inversés au collage (les deux clés se
// ressemblent, l'autofill du navigateur s'y mêle parfois) — le préfixe les
// distingue sans ambiguïté, donc on corrige plutôt que d'enregistrer des
// clés visiblement échangées.
$swapped = false;
if (str_starts_with($secret, 'pk_') && str_starts_with($publishable, 'sk_')) {
    [$secret, $publishable] = [$publishable, $secret];
    $swapped = true;
}

$secretsDir = SECRETS_DIR;
if (!is_dir($secretsDir)) mkdir($secretsDir, 0700, true);

if ($secret !== '') {
    file_put_contents($secretsDir . '/stripe_secret.key', $secret);
    chmod($secretsDir . '/stripe_secret.key', 0600);
}
if ($publishable !== '') {
    file_put_contents($secretsDir . '/stripe_publishable.key', $publishable);
    chmod($secretsDir . '/stripe_publishable.key', 0600);
}

if ($swapped) {
    flash_set('Vos deux clés étaient inversées (secrète/publiable) — corrigé automatiquement et enregistré.');
} elseif ($secret !== '' || $publishable !== '') {
    $warn = ($secret !== '' && !str_starts_with($secret, 'sk_')) || ($publishable !== '' && !str_starts_with($publishable, 'pk_'));
    flash_set('Clés Stripe enregistrées.' . ($warn ? ' Attention : une des deux ne commence pas par le préfixe attendu (sk_/pk_), vérifiez le dashboard Stripe.' : ''), $warn ? 'error' : 'ok');
} else {
    flash_set('Aucune clé fournie — rien de changé.');
}
header('Location: /admin/index.php#stripe-settings');
exit;
