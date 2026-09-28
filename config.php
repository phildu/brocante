<?php

// La base est un simple fichier SQLite (comme le projet Louxor) — aucun
// serveur de base de données à provisionner, ni en local ni sur OVH. Le même
// chemin relatif fonctionne dans les deux environnements.
define('DB_PATH', __DIR__ . '/brocante.db');

// En local (Herd/Mac), config.local.php fournit les valeurs propres à cette
// machine (URL, binaires) et ce fichier s'arrête là. Il n'est jamais déployé
// sur le serveur (exclu dans deploy-brocante.sh) — sur OVH, on tombe dans le
// bloc ci-dessous.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    // --- Valeurs de production / préprod OVH ---

    // Mot de passe de l'espace Administration — à changer avant toute mise en ligne réelle.
    define('ADMIN_PASSWORD', 'armoire2026');

    // Clés Stripe (mode test) — se règlent depuis Administration → Réglages
    // Stripe une fois le site en ligne (admin/save-stripe-keys.php écrit ces
    // fichiers), pas ici. Récupérables sur https://dashboard.stripe.com/test/apikeys.
    $stripeSecretFile = __DIR__ . '/.secrets/stripe_secret.key';
    $stripePublishableFile = __DIR__ . '/.secrets/stripe_publishable.key';
    define('STRIPE_SECRET_KEY', is_file($stripeSecretFile) ? trim(file_get_contents($stripeSecretFile)) : '');
    define('STRIPE_PUBLISHABLE_KEY', is_file($stripePublishableFile) ? trim(file_get_contents($stripePublishableFile)) : '');

    // URL réelle de la préprod (utilisée pour les retours Stripe) — vérifier
    // que ce sous-domaine existe bien côté OVH/DNS avant le premier déploiement
    // (comme electroboy80.arrimage.com et louxor.arrimage.com), sinon l'adapter.
    define('SITE_URL', 'https://brocante.arrimage.com');

    // Clé API Gemini — lue depuis un fichier local, jamais commitée/déployée en clair.
    $geminiKeyFile = __DIR__ . '/.secrets/gemini.key';
    define('GEMINI_API_KEY', is_file($geminiKeyFile) ? trim(file_get_contents($geminiKeyFile)) : '');

    // Clé API fal.ai — détourage à vrai fond transparent via leur modèle
    // rembg hébergé (https://fal.run/fal-ai/imageutils/rembg), en appel HTTP
    // classique : contrairement au rembg local, ne nécessite pas exec().
    $falKeyFile = __DIR__ . '/.secrets/fal.key';
    define('FAL_API_KEY', is_file($falKeyFile) ? trim(file_get_contents($falKeyFile)) : '');

    // Détourage (Python/rembg), vidéos Ken Burns (ffmpeg) et lecture de leurs
    // dimensions (ffprobe) ont besoin d'exec()/shell_exec() et de binaires
    // qu'un hébergement mutualisé classique n'autorise/n'installe en général
    // PAS. Laissés vides : le code désactive proprement ces fonctions
    // (voir shell_exec_available() dans functions.php) plutôt que d'échouer
    // silencieusement. Sur un serveur avec accès SSH et ces outils installés,
    // renseigner les vrais chemins (ex : `which ffmpeg` en SSH).
    define('PYTHON_BIN', '');
    define('PHP_CLI_BIN', '');
    define('FFMPEG_BIN', '');
    define('FFPROBE_BIN', '');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}
