<?php

// Environnement d'exécution (local / preprod / prod) — voir includes/app-env.php et DEPLOIEMENT.md.
require_once __DIR__ . '/includes/app-env.php';
app_env_send_headers();
app_env_enable_badge(); // cartouche « LOCAL / PRÉPROD / PRODUCTION » en haut à gauche

// Marque blanche : les valeurs propres au commerce actif (nom, base, URL,
// mot de passe admin…) viennent de tenants/<slug>/tenant.php — voir
// includes/tenant.php.
require_once __DIR__ . '/includes/tenant.php';

// Commerce servi sous un préfixe (bricbrok.com/<slug>/) : liens,
// formulaires et redirections vers les pages dynamiques reçoivent ce préfixe
// — voir tenant_rewrite_output() et tenant_rewrite_location().
tenant_enable_base_path();

// Clés API partagées pendant la période de test (voir includes/shared-secrets.php).
require_once __DIR__ . '/includes/shared-secrets.php';

// Surveillance : capture des erreurs fatales et battement de cœur (voir
// includes/watchdog.php et health.php).
require_once __DIR__ . '/includes/watchdog.php';
wd_install();

// La base est un simple fichier SQLite (comme le projet Louxor) — aucun
// serveur de base de données à provisionner, ni en local ni sur OVH. Un
// fichier par commerce (tenant.php → db_file ; brocante.db pour le Petit Chalet).
// Prédéfinissable : les tests automatiques pointent vers une base jetable pour
// ne jamais toucher à la vraie base — soit en définissant DB_PATH avant de charger
// ce fichier, soit via la variable d'environnement BROCANTE_DB
// (scripts/test-buvette.php, scripts/test-invendus.php).
if (!defined('DB_PATH')) {
    define('DB_PATH', getenv('BROCANTE_DB') ?: tenant_path('db_file'));
}

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
    define('SITE_URL', getenv('SITE_URL') ?: tenant_site_url());

    // Clé API Gemini — lue depuis un fichier local, jamais commitée/déployée en clair.
    $geminiKeyFile = secret_path('gemini.key');
    define('GEMINI_API_KEY', is_file($geminiKeyFile) ? trim(file_get_contents($geminiKeyFile)) : '');

    // Clé API fal.ai — détourage à vrai fond transparent via leur modèle
    // rembg hébergé (https://fal.run/fal-ai/imageutils/rembg), en appel HTTP
    // classique : contrairement au rembg local, ne nécessite pas exec().
    $falKeyFile = secret_path('fal.key');
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
    // Traitement d'un événement Stripe Connect pour une autre boutique : les
    // fonctions du parcours commande lisent alors la base du commerce visé
    // (voir order_apply_connect_event() dans includes/order-flow.php).
    if (!empty($GLOBALS['db_pdo_override']) && $GLOBALS['db_pdo_override'] instanceof PDO) {
        return $GLOBALS['db_pdo_override'];
    }
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
        // Parcours commande (P0) : mode de livraison, retrait, remboursements,
        // journal et idempotence des webhooks Stripe.
        require_once __DIR__ . '/includes/order-flow.php';
        order_ensure_schema($pdo);
        // Comptes (équipe, clients, prospects) : table créée si la base date d'avant.
        require_once __DIR__ . '/includes/accounts.php';
        accounts_ensure_schema($pdo);
        // Catégories et ordre de la barre latérale d'administration (admin/menu.php).
        require_once __DIR__ . '/includes/admin-menu.php';
        admin_menu_ensure_schema($pdo);
        // Comptes clients (e-mail + mot de passe) : jetons des liens et limites d'essais.
        require_once __DIR__ . '/includes/customers.php';
        customers_ensure_schema($pdo);
        // Prise de vue depuis un téléphone proche (codes QR) : tables créées si besoin.
        require_once __DIR__ . '/includes/capture.php';
        capture_ensure_schema($pdo);
        // Consommation de l'IA (coûts estimés, solde) et notation des résultats par les testeurs.
        require_once __DIR__ . '/includes/ai-usage.php';
        ai_usage_ensure_schema($pdo);
        require_once __DIR__ . '/includes/ai-rating.php';
        ai_rating_ensure_schema($pdo);
        // Vidéos IA (Veo) en cours de génération.
        require_once __DIR__ . '/includes/veo.php';
        veo_ensure_schema($pdo);
        // Visuels promo (photo + encart CTA) et compteurs de mises en avant.
        require_once __DIR__ . '/includes/promo.php';
        promo_visuals_ensure_schema($pdo);
        require_once __DIR__ . '/includes/pricing-extras.php';
        price_offers_ensure_schema($pdo);
        // Prompts (mots-clés / précisions) enregistrés pour la génération IA.
        require_once __DIR__ . '/includes/prompts.php';
        require_once __DIR__ . '/includes/ambiance-scenes.php';
        prompts_ensure_schema($pdo);
        // Univers enregistrés avec d'anciens rayons (« Hauts et t-shirts »…) : ramenés aux grands domaines, une fois.
        require_once __DIR__ . '/includes/universes.php';
        universes_migrate_legacy($pdo);
        // Application smartphone Studio : suivi des pièces créées.
        require_once __DIR__ . '/includes/studio.php';
        studio_ensure_schema($pdo);
        // Règle « retrait seul » : seuil (en €) sous lequel un article n'est pas livré + message affiché (voir pickup_only_threshold_cents()).
        $contentColumns = $pdo->query('PRAGMA table_info(content)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($contentColumns && !in_array('pickup_only_below', $contentColumns, true)) {
            $pdo->exec("ALTER TABLE content ADD COLUMN pickup_only_below TEXT NOT NULL DEFAULT '10'");
            $pdo->exec("ALTER TABLE content ADD COLUMN pickup_only_message TEXT NOT NULL DEFAULT 'Retrait à l''adresse du vendeur.'");
        }
        // Emballage cadeau proposé au panier (voir gift_wrap_enabled()).
        foreach (['gift_wrap_enabled' => "TEXT NOT NULL DEFAULT '0'", 'gift_wrap_price' => "TEXT NOT NULL DEFAULT ''", 'gift_wrap_label' => "TEXT NOT NULL DEFAULT 'Emballage cadeau'"] as $giftColumn => $giftDefinition) {
            if ($contentColumns && !in_array($giftColumn, $contentColumns, true)) {
                $pdo->exec('ALTER TABLE content ADD COLUMN ' . $giftColumn . ' ' . $giftDefinition);
            }
        }
        // Remises du panier : paliers par total ou par nombre d'articles (voir cart_discount_for()).
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS cart_discounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,                 -- 'total' : total du panier ; 'qty' : nombre d'articles
  threshold INTEGER NOT NULL,         -- centimes (total) ou nombre d'articles (qty) à atteindre
  type TEXT NOT NULL,                 -- 'percent' ou 'fixed'
  value INTEGER NOT NULL,             -- pourcentage (1-100) ou centimes
  label TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);
        // Commandes payées par la plateforme (Stripe Connect, voir includes/connect.php) : mode de paiement et commission prélevée.
        $orderColumns = $pdo->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_COLUMN, 1);
        // Coordonnées recueillies au panier : téléphone, adresse de l'acheteur, acceptation des conditions (date et version des textes), demande de création de compte.
        foreach (['payment_via' => 'TEXT', 'connect_fee_cents' => 'INTEGER', 'connect_gallery_cents' => 'INTEGER', 'phone' => 'TEXT', 'terms_at' => 'TEXT', 'terms_version' => 'TEXT', 'create_account' => 'INTEGER NOT NULL DEFAULT 0', 'ship_name' => 'TEXT', 'ship_phone' => 'TEXT', 'declared_address' => 'TEXT', 'buyer_address' => 'TEXT'] as $extraColumn => $extraType) {
            if ($orderColumns && !in_array($extraColumn, $orderColumns, true)) $pdo->exec('ALTER TABLE orders ADD COLUMN ' . $extraColumn . ' ' . $extraType);
        }
        // Bases créées avant l'ajout du champ « Matières » des fiches.
        $productColumns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($productColumns && !in_array('materials', $productColumns, true)) {
            $pdo->exec('ALTER TABLE products ADD COLUMN materials TEXT');
        }
        // Gestion du prix de vente : prix de vente minimum (interne), fourchette estimée, prix négociable, prix du neuf et son lien (includes/pricing-extras.php).
        foreach (['price_floor' => 'TEXT', 'est_min' => 'REAL', 'est_max' => 'REAL', 'est_show' => 'INTEGER NOT NULL DEFAULT 0', 'negotiable' => 'INTEGER NOT NULL DEFAULT 0',
                  'new_url' => 'TEXT', 'new_price' => 'TEXT', 'new_show' => 'INTEGER NOT NULL DEFAULT 0',
                  'lot_qty' => 'INTEGER NOT NULL DEFAULT 1',
                  'channels' => 'TEXT', 'sold_channel' => 'TEXT', 'sold_at' => 'TEXT', 'restocked_at' => 'TEXT',
                  'keep_when_sold' => 'INTEGER NOT NULL DEFAULT 0'] as $extraColumn => $extraType) {
            if ($productColumns && !in_array($extraColumn, $productColumns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN ' . $extraColumn . ' ' . $extraType);
            }
        }
        // Réassort possible : délai et prix indicatifs affichés sur la fiche (voir product_restock_line_html()).
        foreach (['restock_possible' => 'INTEGER NOT NULL DEFAULT 0', 'restock_delay' => 'TEXT', 'restock_price' => 'TEXT'] as $extraColumn => $extraType) {
            if ($productColumns && !in_array($extraColumn, $productColumns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN ' . $extraColumn . ' ' . $extraType);
            }
        }
        // Rangement interne et délai de récupération estimé (voir product_pickup_line_html()).
        foreach (['storage_location' => 'TEXT', 'pickup_delay_minutes' => 'INTEGER NOT NULL DEFAULT 0'] as $extraColumn => $extraType) {
            if ($productColumns && !in_array($extraColumn, $productColumns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN ' . $extraColumn . ' ' . $extraType);
            }
        }
        // Marque et distributeur de l'article (fiche, recherche, page publique).
        foreach (['brand', 'distributor', 'colors'] as $extraColumn) {
            if ($productColumns && !in_array($extraColumn, $productColumns, true)) {
                $pdo->exec('ALTER TABLE products ADD COLUMN ' . $extraColumn . ' TEXT');
            }
        }
        // Champ « État » (neuf, presque neuf, très bon état…) : voir product_condition_options().
        if ($productColumns && !in_array('etat', $productColumns, true)) {
            $pdo->exec('ALTER TABLE products ADD COLUMN etat TEXT');
        }
        // Nature du produit et sous-catégorie (vêtements › hauts…) : voir product_nature_options().
        foreach (['nature', 'sous_categorie'] as $col) {
            if ($productColumns && !in_array($col, $productColumns, true)) {
                $pdo->exec("ALTER TABLE products ADD COLUMN $col TEXT");
            }
        }
        // Code-barres / ISBN de l'article (scanné ou saisi, voir includes/barcode.php).
        if ($productColumns && !in_array('barcode', $productColumns, true)) {
            $pdo->exec('ALTER TABLE products ADD COLUMN barcode TEXT');
        }
        // Bases créées avant l'ajout des visuels en deux formats.
        $photoColumns = $pdo->query('PRAGMA table_info(product_photos)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if ($photoColumns && !in_array('path_mobile', $photoColumns, true)) {
            $pdo->exec('ALTER TABLE product_photos ADD COLUMN path_mobile TEXT');
        }
        if ($photoColumns && !in_array('mobile_pending', $photoColumns, true)) {
            $pdo->exec('ALTER TABLE product_photos ADD COLUMN mobile_pending TEXT');
        }
        // Détourage rattaché à la photo de départ dont il est tiré (recto / verso automatique).
        if ($photoColumns && !in_array('cutout_of', $photoColumns, true)) {
            $pdo->exec('ALTER TABLE product_photos ADD COLUMN cutout_of INTEGER');
        }
        require_once __DIR__ . '/includes/recto-verso.php';
        // Sauvegarde du jour de la base, à la première ouverture (voir includes/backup.php).
        require_once __DIR__ . '/includes/backup.php';
        db_backup_daily($pdo);
    }
    return $pdo;
}
