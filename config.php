<?php

// Marque blanche : les valeurs propres au commerce actif (nom, base, URL,
// mot de passe admin…) viennent de tenants/<slug>/tenant.php — voir
// includes/tenant.php.
require_once __DIR__ . '/includes/tenant.php';

// La base est un simple fichier SQLite (comme le projet Louxor) — aucun
// serveur de base de données à provisionner, ni en local ni sur OVH. Un
// fichier par commerce (tenant.php → db_file ; brocante.db pour le Petit Chalet).
define('DB_PATH', tenant_path('db_file'));

// Clés API (Stripe, Gemini, fal.ai) enregistrées depuis l'administration.
define('SECRETS_DIR', tenant_path('secrets_dir'));

// En local (Herd/Mac), config.local.php fournit les valeurs propres à cette
// machine (URL, binaires) et ce fichier s'arrête là. Il n'est jamais déployé
// sur le serveur (exclu dans deploy-brocante.sh) — sur OVH, on tombe dans le
// bloc ci-dessous.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
} else {
    // --- Valeurs de production / préprod OVH ---

    // Compte de l'espace Administration : variables d'environnement
    // ADMIN_USER / ADMIN_PASSWORD, sinon admin_user / admin_password du tenant.php.
    define('ADMIN_USER', getenv('ADMIN_USER') ?: (string) tenant('admin_user'));
    define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD') ?: (string) tenant('admin_password'));

    // Clés Stripe (mode test) — se règlent depuis Administration → Réglages
    // Stripe une fois le site en ligne (admin/save-stripe-keys.php écrit ces
    // fichiers), pas ici. Récupérables sur https://dashboard.stripe.com/test/apikeys.
    $stripeSecretFile = SECRETS_DIR . '/stripe_secret.key';
    $stripePublishableFile = SECRETS_DIR . '/stripe_publishable.key';
    define('STRIPE_SECRET_KEY', is_file($stripeSecretFile) ? trim(file_get_contents($stripeSecretFile)) : '');
    define('STRIPE_PUBLISHABLE_KEY', is_file($stripePublishableFile) ? trim(file_get_contents($stripePublishableFile)) : '');

    // URL réelle du site (utilisée pour les retours Stripe) : variable
    // d'environnement SITE_URL, sinon site_url du tenant.php — vérifier que ce
    // sous-domaine existe bien côté OVH/DNS avant le premier déploiement.
    define('SITE_URL', getenv('SITE_URL') ?: (string) tenant('site_url'));

    // Clé API Gemini — lue depuis un fichier local, jamais commitée/déployée en clair.
    $geminiKeyFile = SECRETS_DIR . '/gemini.key';
    define('GEMINI_API_KEY', is_file($geminiKeyFile) ? trim(file_get_contents($geminiKeyFile)) : '');

    // Clé API fal.ai — détourage à vrai fond transparent via leur modèle
    // rembg hébergé (https://fal.run/fal-ai/imageutils/rembg), en appel HTTP
    // classique : contrairement au rembg local, ne nécessite pas exec().
    $falKeyFile = SECRETS_DIR . '/fal.key';
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
        // Première visite d'un commerce dont la base n'existe pas encore (ex.
        // commerce créé ou déployé sans --with-db) : base créée à partir de
        // schema.sql et de son seed-data.json.
        if (!is_file(DB_PATH) && is_file(tenant_path('seed_file'))) {
            require_once __DIR__ . '/includes/seed.php';
            seed_tenant(tenant());
        }
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        // Bases créées avant l'ajout des visuels en deux formats.
        $photoColumns = $pdo->query('PRAGMA table_info(product_photos)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($photoColumns && !in_array('path_mobile', $photoColumns, true)) {
            $pdo->exec('ALTER TABLE product_photos ADD COLUMN path_mobile TEXT');
        }
    }
    return $pdo;
}
