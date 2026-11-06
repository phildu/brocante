<?php
// Modules payants (« add-ons ») : accès par boutique, essai gratuit unique, abonnement Stripe mensuel, accès offert.
// Socle commun des plugins packs/<id>/ qui se vendent à part (reçus, alertes chineur, ventes flash…).
//
// Prix, nom et durée d'essai par défaut : addon_defaults() ; la plateforme peut les remplacer dans data/saas/addons.json :
//   { "global": { "recus-compta": { "name": "…", "monthly_cents": 1490, "trial_days": 14 } } }
// Utilisable hors PLUGIN_RUNTIME (webhook Stripe) : la base de la boutique est alors imposée via addon_with_shop().

require_once __DIR__ . '/vocabulary.php';   // mot unique pour les « extensions » : ext_t()

const ADDON_STATUS_LABELS = [
    'inactive' => 'Non souscrit', 'active' => 'Abonné', 'complimentary' => 'Accès offert',
    'trial' => 'Essai gratuit', 'canceled' => 'Résilié', 'expired' => 'Essai terminé',
];

/** Modules vendus : id du plugin => nom, description, prix mensuel (centimes), jours d'essai. */
function addon_defaults(): array
{
    return [
        'recus-compta' => ['name' => 'Reçus & journal des ventes', 'description' => "Reçus d'achat avec mentions légales de l'occasion, envoyés par e-mail, et journal des ventes exportable (CSV, Excel, FEC) pour l'expert-comptable.", 'monthly_cents' => 1490, 'trial_days' => 14],
        'alerte-chineur' => ['name' => 'Alerte chineur', 'description' => "Vos visiteurs enregistrent leurs critères (mots-clés, catégorie, prix) et sont prévenus par e-mail dès qu'une pièce correspondante est mise en ligne.", 'monthly_cents' => 490, 'trial_days' => 14],
        'registre-police' => ['name' => 'Registre de police', 'description' => "Livre de police numérique des objets d'occasion : numérotation continue, description, prix et mode de règlement, identité du vendeur, puis sortie (date, prix, acquéreur) renseignée à la vente. Journal des modifications infalsifiable, export imprimable et CSV.", 'monthly_cents' => 990, 'trial_days' => 14],
        'qr-story' => ['name' => 'QR Code Story & certificat', 'description' => "Une page « histoire de la pièce » (époque, provenance, expertise) accessible par QR Code collé sur l'objet, des étiquettes QR imprimables et un certificat d'authenticité à imprimer ou enregistrer en PDF.", 'monthly_cents' => 690, 'trial_days' => 14],
        'tableau-de-bord' => ['name' => 'Tableau de bord', 'description' => "Suivez les visites, les pièces les plus vues, les ajouts au panier, les commandes et l'efficacité de vos réseaux sociaux (liens de campagne). Mesure sans cookie ni donnée personnelle conservée.", 'monthly_cents' => 790, 'trial_days' => 14],
        'messagerie' => ['name' => 'Messagerie & WhatsApp', 'description' => "Un bouton WhatsApp et un formulaire de question sur chaque fiche pièce : les messages arrivent dans l'administration et par e-mail, et vous répondez en un clic.", 'monthly_cents' => 590, 'trial_days' => 14],
        'reservation-arrhes' => ['name' => 'Réservation (arrhes ou acompte)', 'description' => "Un client bloque une pièce quelques jours en versant des arrhes ou un acompte (ex. 20 %) payés en ligne par carte ; la pièce est retenue puis libérée automatiquement à l'échéance (les arrhes restent acquises en cas d'absence selon vos conditions).", 'monthly_cents' => 990, 'trial_days' => 14],
        'retrait-stand' => ['name' => 'Retrait sur stand', 'description' => "Le client réserve sa pièce en ligne : la somme est seulement bloquée sur sa carte et un billet QR lui est envoyé. Sur le stand, vous scannez le billet : le paiement est encaissé, la pièce marquée vendue et le reçu émis. Sans retrait sous quelques jours, le blocage est levé.", 'monthly_cents' => 990, 'trial_days' => 14],
        'baisse-auto' => ['name' => 'Baisse de prix automatique', 'description' => "Les pièces qui tardent à partir baissent de prix toutes seules par paliers selon leur ancienneté (ex. -10 % après 30 jours, -20 % après 60), sans jamais descendre sous le prix plancher. Mode « je valide » ou automatique, historique et annulation en un clic. Le bot répercute la baisse sur les annonces Leboncoin.", 'monthly_cents' => 690, 'trial_days' => 14],
        'espace-visiteur' => ['name' => 'Espace visiteur & fidélité', 'description' => "Une web app pour vos visiteurs : points de fidélité et récompenses, alertes chineur, réservations et billets, scan des QR codes. Vous réglez les points par euro, les paliers et les récompenses.", 'monthly_cents' => 690, 'trial_days' => 14],
        'ventes-flash' => ['name' => 'Ventes flash', 'description' => "Baisses de prix programmées et automatiques (ex. -5 % toutes les heures) pour animer une fin de marché ou une braderie.", 'monthly_cents' => 490, 'trial_days' => 14],
        'actions-commerciales' => ['name' => 'Actions commerciales', 'description' => "Braderie, foire ou période de soldes : une remise programmée sur une sélection de pièces (catégories, références, prix, ancienneté), les prix d'origine rétablis à la fin, des étiquettes avec prix barré et un bilan des ventes exportable.", 'monthly_cents' => 690, 'trial_days' => 14],
        'annonces-marche' => ['name' => 'Annonces Leboncoin & Vinted', 'description' => "Préparez vos annonces Leboncoin et Vinted depuis le catalogue de la boutique avec une extension Chrome et une validation humaine.", 'monthly_cents' => 990, 'trial_days' => 14],
        'multilingue' => ['name' => 'Version multilingue', 'description' => "Une version figée de votre boutique dans une autre langue : l'IA traduit une fois tout le texte public (accueil, boutique, fiches, mentions légales) et l'écrit en dur dans un fichier de la boutique ; un sélecteur de langue apparaît sur le site. À relancer quand le catalogue change — seuls les textes nouveaux sont traduits.", 'monthly_cents' => 990, 'trial_days' => 14],
        // Modules pour vide-greniers (voir includes/shop-modules.php). La buvette et Invendus & dons n'en font plus partie : ce sont des extensions de galerie (includes/gallery-modules.php). Aucun prix n'est fixé d'office : à régler dans le portail
        // (Plugins → Tarifs et modalités) ; en attendant, essai gratuit et souscription manuelle seulement, aucun paiement Stripe possible.
        // Réservés aux galeries de type « vide-grenier » (ciblage modifiable dans le portail).
        'comptage-foule' => ['name' => 'Comptage de foule', 'description' => "Comptez les visiteurs aux entrées avec un téléphone (appuis ou détection par caméra), même hors ligne, par événement et par porte ; courbes de fréquentation et export.", 'monthly_cents' => null, 'trial_days' => 14, 'payment_modes' => ['monthly', 'manual'], 'gallery_types' => ['vide-grenier']],
    ];
}

function addon_settings_file(): string
{
    return dirname(__DIR__) . '/data/saas/addons.json';
}

function addon_settings_load(): array
{
    $file = addon_settings_file();
    if (!is_file($file)) return ['global' => [], 'galleries' => [], 'shops' => [], 'gallery_shops' => []];
    $json = file_get_contents($file);
    if ($json === false) {
        error_log('Unable to read add-on settings file: ' . $file);
        throw new RuntimeException(ext_t('Impossible de lire la configuration {des exts}.'));
    }
    try {
        $saved = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        error_log('Invalid add-on settings JSON: ' . $e->getMessage());
        throw new RuntimeException(ext_t('La configuration {des exts} est invalide ; elle n’a pas été modifiée.'), 0, $e);
    }
    if (!is_array($saved)) {
        error_log('Add-on settings JSON must contain an object: ' . $file);
        throw new RuntimeException(ext_t('La configuration {des exts} doit être un objet JSON.'));
    }
    if (isset($saved['global']) || isset($saved['galleries']) || isset($saved['shops']) || isset($saved['gallery_shops'])) {
        return [
            'global' => (array) ($saved['global'] ?? []),
            'galleries' => (array) ($saved['galleries'] ?? []),
            'shops' => (array) ($saved['shops'] ?? []),
            'gallery_shops' => (array) ($saved['gallery_shops'] ?? []),
        ];
    }
    // Compatibility with the original format: {plugin: {name, monthly_cents, trial_days}}.
    return ['global' => $saved, 'galleries' => [], 'shops' => [], 'gallery_shops' => []];
}

function addon_settings_save(array $settings): bool
{
    $file = addon_settings_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $data = [
        'global' => (array) ($settings['global'] ?? []),
        'galleries' => (array) ($settings['galleries'] ?? []),
        'shops' => (array) ($settings['shops'] ?? []),
        'gallery_shops' => (array) ($settings['gallery_shops'] ?? []),
    ];
    return @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function addon_settings_normalize(array $input): array
{
    $out = [];
    foreach (['name' => 120, 'description' => 500, 'terms' => 1200] as $key => $max) {
        if (array_key_exists($key, $input)) $out[$key] = mb_substr(trim((string) $input[$key]), 0, $max);
    }
    foreach (['monthly' => 'monthly_cents', 'yearly' => 'yearly_cents', 'once' => 'once_cents'] as $field => $key) {
        if (array_key_exists($field, $input)) {
            $value = trim((string) $input[$field]);
            $value = str_replace([' ', "\u{00a0}", '€'], '', $value);
            $value = str_replace(',', '.', $value);
            $out[$key] = $value === '' ? null : (is_numeric($value) ? max(0, min(9990000, (int) round((float) $value * 100))) : null);
        }
    }
    if (array_key_exists('trial_days', $input)) $out['trial_days'] = max(0, min(90, (int) $input['trial_days']));
    if (array_key_exists('payment_modes', $input)) {
        $allowed = ['monthly', 'yearly', 'once', 'manual', 'free'];
        $out['payment_modes'] = array_values(array_intersect($allowed, array_unique((array) $input['payment_modes'])));
    }
    if (array_key_exists('access', $input) && in_array($input['access'], ['available', 'included', 'unavailable'], true)) {
        $out['access'] = $input['access'];
    }
    if (array_key_exists('stage', $input) && in_array($input['stage'], ['live', 'beta'], true)) $out['stage'] = $input['stage'];
    if (array_key_exists('feedback_days', $input)) $out['feedback_days'] = max(1, min(90, (int) $input['feedback_days'] ?: 7));
    foreach (['feedback_required', 'beta_reminders'] as $flag) {
        if (array_key_exists($flag, $input)) $out[$flag] = !empty($input[$flag]);
    }
    if (isset($input['beta_since'])) $out['beta_since'] = max(0, (int) $input['beta_since']);
    if (array_key_exists('gallery_types', $input)) $out['gallery_types'] = addon_slug_list($input['gallery_types']);
    if (array_key_exists('shop_modes', $input)) $out['shop_modes'] = array_values(array_intersect(['physical', 'online', 'both'], (array) $input['shop_modes']));
    if (array_key_exists('testers', $input) && is_array($input['testers'])) {
        $out['testers'] = ['shops' => addon_slug_list($input['testers']['shops'] ?? []), 'galleries' => addon_slug_list($input['testers']['galleries'] ?? [])];
    }
    return $out;
}

/** Liste de slugs valides, sans doublon (tableau, ou texte séparé par des virgules/retours à la ligne). */
function addon_slug_list(mixed $value): array
{
    if (is_string($value)) $value = preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ((array) $value as $v) {
        $v = strtolower(trim((string) $v));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $v)) $out[$v] = $v;
    }
    return array_values($out);
}

/** Galeries (slug => type) dont la boutique est membre locale. */
function addon_shop_galleries(string $shop): array
{
    if ($shop === '') return [];
    if (!function_exists('gallery_list')) require_once __DIR__ . '/galleries.php';
    $out = [];
    foreach (gallery_list() as $slug => $gallery) {
        foreach ($gallery['members'] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shop) {
                $out[(string) $slug] = (string) ($gallery['type'] ?? 'marchande');
                break;
            }
        }
    }
    return $out;
}

/** Type de galerie effectif d'une boutique (sa galerie $gallery si fournie, sinon sa première galerie). */
function addon_gallery_type(string $shop, string $gallery): string
{
    if (!function_exists('gallery_load')) require_once __DIR__ . '/galleries.php';
    if ($gallery !== '') {
        $g = gallery_load($gallery);
        if ($g) return (string) ($g['type'] ?? 'marchande');
    }
    if ($shop === '') return '';
    foreach (gallery_list() as $g) {
        foreach ($g['members'] as $member) {
            if (($member['type'] ?? '') === 'local' && ($member['slug'] ?? '') === $shop) return (string) ($g['type'] ?? 'marchande');
        }
    }
    return '';
}

/** Étape d'un module : « live » (validé, en production) ou « beta » (en test : réservé aux testeurs, gratuit, avec retours). */
const ADDON_STAGE_LABELS = ['live' => 'Validé', 'beta' => 'En test'];

function addon_gallery_for_shop(string $shop, string $module = ''): string
{
    if ($shop === '' || !function_exists('gallery_list')) {
        if (!function_exists('gallery_list')) require_once __DIR__ . '/galleries.php';
    }
    if ($shop === '') return '';
    $settings = addon_settings_load();
    $fallback = '';
    foreach (gallery_list() as $slug => $gallery) {
        foreach ($gallery['members'] as $member) {
            if (($member['type'] ?? '') !== 'local' || ($member['slug'] ?? '') !== $shop) continue;
            if ($fallback === '') $fallback = (string) $slug;
            if ($module !== '' && (isset($settings['galleries'][$slug][$module]) || isset($settings['gallery_shops'][$slug][$shop][$module]))) return (string) $slug;
        }
    }
    return $fallback;
}

function addon_info(string $module, ?string $shop = null, ?string $gallery = null): ?array
{
    $settings = addon_settings_load();
    if ($shop === null && function_exists('tenant_slug')) $shop = tenant_slug();
    if ($gallery === null) $gallery = addon_gallery_for_shop((string) $shop, $module);
    return addon_info_resolve($module, $settings, (string) $shop, (string) $gallery);
}

function addon_info_resolve(string $module, array $settings, string $shop = '', string $gallery = ''): ?array
{
    $d = addon_defaults()[$module] ?? null;
    if (!$d) return null;
    $layers = [
        $settings['global'][$module] ?? [],
        $settings['galleries'][$gallery][$module] ?? [],
        $settings['gallery_shops'][$gallery][$shop][$module] ?? [],
        $settings['shops'][$shop][$module] ?? [],
    ];
    $limits = ['monthly_cents' => 9990000, 'yearly_cents' => 9990000, 'once_cents' => 9990000];
    foreach ($layers as $layer) {
        if (!is_array($layer)) continue;
        foreach (['name' => 120, 'description' => 500] as $key => $max) {
            if (isset($layer[$key]) && trim((string) $layer[$key]) !== '') $d[$key] = mb_substr(trim((string) $layer[$key]), 0, $max);
        }
        if (array_key_exists('terms', $layer)) $d['terms'] = mb_substr(trim((string) $layer['terms']), 0, 1200);
        foreach ($limits as $key => $max) {
            if (array_key_exists($key, $layer) && ($layer[$key] === null || $layer[$key] === '')) $d[$key] = null;
            elseif (isset($layer[$key]) && is_numeric($layer[$key])) $d[$key] = max(0, min($max, (int) $layer[$key]));
        }
        if (isset($layer['trial_days']) && is_numeric($layer['trial_days'])) $d['trial_days'] = max(0, min(90, (int) $layer['trial_days']));
        if (isset($layer['payment_modes']) && is_array($layer['payment_modes'])) {
            $d['payment_modes'] = array_values(array_intersect(['monthly', 'yearly', 'once', 'manual', 'free'], $layer['payment_modes']));
        }
        if (isset($layer['access']) && in_array($layer['access'], ['available', 'included', 'unavailable'], true)) $d['access'] = $layer['access'];
        if (isset($layer['stage']) && in_array($layer['stage'], ['live', 'beta'], true)) $d['stage'] = $layer['stage'];
        foreach (['feedback_required', 'beta_reminders'] as $flag) if (isset($layer[$flag])) $d[$flag] = !empty($layer[$flag]);
        foreach (['feedback_days', 'beta_since'] as $num) if (isset($layer[$num]) && is_numeric($layer[$num])) $d[$num] = (int) $layer[$num];
        if (isset($layer['gallery_types']) && is_array($layer['gallery_types'])) $d['gallery_types'] = addon_slug_list($layer['gallery_types']);
        if (isset($layer['shop_modes']) && is_array($layer['shop_modes'])) $d['shop_modes'] = array_values(array_intersect(['physical', 'online', 'both'], $layer['shop_modes']));
        if (isset($layer['testers']) && is_array($layer['testers'])) {
            $d['testers'] = ['shops' => addon_slug_list($layer['testers']['shops'] ?? []), 'galleries' => addon_slug_list($layer['testers']['galleries'] ?? [])];
        }
    }
    $d['yearly_cents'] ??= null;
    $d['once_cents'] ??= null;
    $d['trial_days'] = max(0, min(90, (int) ($d['trial_days'] ?? 0)));
    $d['payment_modes'] = array_values(array_intersect(['monthly', 'yearly', 'once', 'manual', 'free'], (array) ($d['payment_modes'] ?? ['monthly'])));
    $d['access'] = in_array($d['access'] ?? '', ['available', 'included', 'unavailable'], true) ? $d['access'] : 'available';
    $d['stage'] = ($d['stage'] ?? '') === 'beta' ? 'beta' : 'live';
    $d['gallery_types'] = (array) ($d['gallery_types'] ?? []);
    $d['shop_modes'] = (array) ($d['shop_modes'] ?? []);
    $d['testers'] = ['shops' => (array) ($d['testers']['shops'] ?? []), 'galleries' => (array) ($d['testers']['galleries'] ?? [])];
    $d['feedback_required'] = !empty($d['feedback_required']);
    $d['beta_reminders'] = !empty($d['beta_reminders']);
    $d['feedback_days'] = max(1, min(90, (int) ($d['feedback_days'] ?? 7)));
    $d['beta_since'] = (int) ($d['beta_since'] ?? 0);
    $d['beta_tester'] = false;
    $d['beta_locked'] = false;
    $d['restriction'] = '';

    // Rôle du plugin pour les vendeurs de ce type de galerie (configurateur « Types de galerie »). Un rôle explicite prime sur le ciblage global.
    $typeMode = 'inherit';
    if ($shop !== '' || $gallery !== '') {
        if (!function_exists('gt_vendor_plugin_mode') && is_file(__DIR__ . '/gallery-types.php')) require_once __DIR__ . '/gallery-types.php';
        if (function_exists('gt_vendor_plugin_mode')) {
            $typeKey = addon_gallery_type($shop, $gallery);
            if ($typeKey !== '') $typeMode = gt_vendor_plugin_mode($typeKey, $module);
        }
    }
    if ($typeMode === 'included') {
        $d['access'] = 'included';
        $d['restriction'] = 'gallery_type_plugin';
        $d['payment_modes'] = ['free'];
        $d['stage'] = 'live';
        $d['beta_tester'] = false;
        $d['beta_locked'] = false;
    } elseif ($typeMode === 'unavailable') {
        $d['access'] = 'unavailable';
        $d['restriction'] = 'gallery_type_plugin';
    } elseif ($typeMode === 'available') {
        $d['access'] = 'available';
        $d['restriction'] = '';
        $d['stage'] = 'live';
        $d['beta_tester'] = false;
        $d['beta_locked'] = false;
    }

    // Ciblage : type de galerie et type de boutique (présence) autorisés ; vide = tous.
    if ($typeMode === 'inherit' && $d['access'] !== 'unavailable' && ($shop !== '' || $gallery !== '')) {
        $galleries = $shop !== '' ? addon_shop_galleries($shop) : [];
        if ($gallery !== '' && !isset($galleries[$gallery])) {
            if (!function_exists('gallery_load')) require_once __DIR__ . '/galleries.php';
            $g = gallery_load($gallery);
            if ($g) $galleries[$gallery] = (string) ($g['type'] ?? 'marchande');
        }
        if ($d['gallery_types'] && !array_intersect($d['gallery_types'], $galleries)) {
            $d['access'] = 'unavailable';
            $d['restriction'] = 'gallery_type';
        }
        if ($d['access'] !== 'unavailable' && $d['shop_modes'] && $shop !== '' && function_exists('tenant_load')) {
            $mode = (string) (tenant_load($shop)['presence_mode'] ?? 'online');
            if (!in_array($mode, $d['shop_modes'], true)) {
                $d['access'] = 'unavailable';
                $d['restriction'] = 'shop_mode';
            }
        }
        // Phase de test : réservé aux boutiques ou galeries testeuses.
        if ($d['access'] !== 'unavailable' && $d['stage'] === 'beta') {
            $isTester = ($shop !== '' && in_array($shop, $d['testers']['shops'], true))
                || array_intersect(array_keys($galleries), $d['testers']['galleries']);
            if ($isTester) {
                $d['beta_tester'] = true;
                // Retours obligatoires (option) : sans retour dans le délai, l'accès est suspendu jusqu'au prochain retour.
                if ($d['feedback_required'] && $shop !== '' && addon_feedback_due($module, $shop, $d)) $d['beta_locked'] = true;
            } else {
                $d['access'] = 'unavailable';
                $d['restriction'] = 'beta';
            }
        }
    }
    return $d;
}

// ── Accès d'une boutique ────────────────────────────────────────────────────

function addon_pdo(): PDO
{
    return (!empty($GLOBALS['addon_pdo_override']) && $GLOBALS['addon_pdo_override'] instanceof PDO) ? $GLOBALS['addon_pdo_override'] : db();
}

function addon_ensure(?PDO $pdo = null): void
{
    $pdo ??= addon_pdo();
    $pdo->exec("CREATE TABLE IF NOT EXISTS addon_access (
      module TEXT PRIMARY KEY,
      status TEXT NOT NULL DEFAULT 'inactive',
      trial_until INTEGER NOT NULL DEFAULT 0,
      trial_used INTEGER NOT NULL DEFAULT 0,
      stripe_subscription_id TEXT NOT NULL DEFAULT '',
      stripe_customer_id TEXT NOT NULL DEFAULT '',
      stripe_session_id TEXT NOT NULL DEFAULT '',
      billing_mode TEXT NOT NULL DEFAULT '',
      subscribed_at TEXT NOT NULL DEFAULT '',
      canceled_at TEXT NOT NULL DEFAULT ''
    )");
    $columns = array_column($pdo->query('PRAGMA table_info(addon_access)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('billing_mode', $columns, true)) $pdo->exec("ALTER TABLE addon_access ADD COLUMN billing_mode TEXT NOT NULL DEFAULT ''");
}

function addon_row(string $module, ?PDO $pdo = null): array
{
    $pdo ??= addon_pdo();
    addon_ensure($pdo);
    $st = $pdo->prepare('SELECT * FROM addon_access WHERE module = ?');
    $st->execute([$module]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['module' => $module, 'status' => 'inactive', 'trial_until' => 0, 'trial_used' => 0,
        'stripe_subscription_id' => '', 'stripe_customer_id' => '', 'stripe_session_id' => '', 'billing_mode' => '', 'subscribed_at' => '', 'canceled_at' => ''];
}

function addon_save(string $module, array $fields, ?PDO $pdo = null): void
{
    $pdo ??= addon_pdo();
    addon_ensure($pdo);
    $pdo->prepare('INSERT OR IGNORE INTO addon_access (module) VALUES (?)')->execute([$module]);
    $allowed = ['status', 'trial_until', 'trial_used', 'stripe_subscription_id', 'stripe_customer_id', 'stripe_session_id', 'billing_mode', 'subscribed_at', 'canceled_at'];
    foreach ($fields as $k => $v) {
        if (!in_array($k, $allowed, true)) continue;
        $pdo->prepare("UPDATE addon_access SET $k = ? WHERE module = ?")->execute([$v, $module]);
    }
}

/** Le module est-il utilisable maintenant ? (abonné, offert, ou essai non expiré) */
function addon_is_entitled(string $module): bool
{
    $info = addon_info($module);
    if (!$info || $info['access'] === 'unavailable') return false;
    if ($info['access'] === 'included') return true;
    if (!empty($info['beta_tester'])) return empty($info['beta_locked']);
    $r = addon_row($module);
    if (in_array($r['status'], ['active', 'complimentary'], true)) return true;
    return $r['status'] === 'trial' && (int) $r['trial_until'] > time();
}

function addon_entitlement(string $module): array
{
    $info = addon_info($module) ?? ['name' => $module, 'description' => '', 'monthly_cents' => 0, 'yearly_cents' => null,
        'once_cents' => null, 'trial_days' => 0, 'payment_modes' => [], 'access' => 'unavailable', 'terms' => '', 'stage' => 'live', 'beta_tester' => false, 'restriction' => ''];
    $r = addon_row($module);
    $status = $r['status'];
    if ($status === 'trial' && (int) $r['trial_until'] <= time()) $status = 'expired';
    if (!empty($info['beta_tester'])) {
        $status = !empty($info['beta_locked']) ? 'beta_locked' : 'beta';
    } elseif ($info['access'] === 'included') {
        $status = 'included';
    } elseif ($info['access'] === 'unavailable') {
        $status = 'unavailable';
    }
    $priceLabel = static function (?int $cents, string $period): string {
        if ($cents === null) return '';
        if ($cents === 0) return 'Gratuit';
        return number_format($cents / 100, 2, ',', ' ') . ' €' . $period;
    };
    return [
        'module' => $module,
        'name' => $info['name'],
        'description' => $info['description'],
        'status' => $status,
        'label' => ['beta' => 'Test gratuit — retours attendus', 'beta_locked' => 'Test suspendu — retour attendu', 'included' => ext_t('{?Inclus|Incluse} par votre galerie'), 'unavailable' => 'Indisponible'][$status] ?? (ADDON_STATUS_LABELS[$status] ?? $status),
        'entitled' => addon_is_entitled($module),
        'trial_until' => $status === 'trial' ? gmdate('d/m/Y', (int) $r['trial_until']) : null,
        'trial_used' => (int) $r['trial_used'] === 1,
        'trial_days' => $info['trial_days'],
        'monthly_cents' => $info['monthly_cents'],
        'yearly_cents' => $info['yearly_cents'],
        'once_cents' => $info['once_cents'],
        'payment_modes' => $info['payment_modes'],
        'access' => $info['access'],
        'stage' => $info['stage'] ?? 'live',
        'beta_tester' => !empty($info['beta_tester']),
        'beta_locked' => !empty($info['beta_locked']),
        'feedback_days' => (int) ($info['feedback_days'] ?? 7),
        'restriction' => $info['restriction'] ?? '',
        'terms' => $info['terms'] ?? '',
        'billing_mode' => (string) ($r['billing_mode'] ?? ''),
        'monthly_label' => $priceLabel($info['monthly_cents'], ' / mois'),
        'yearly_label' => $priceLabel($info['yearly_cents'], ' / an'),
        'once_label' => $priceLabel($info['once_cents'], ' (paiement unique)'),
    ];
}

/** Démarre l'essai gratuit (une seule fois par boutique et par module). -> message d'erreur ou null. */
function addon_start_trial(string $module): ?string
{
    $info = addon_info($module);
    if (!$info) return ext_t('{Ext} inconnu{e}.');
    if ($info['access'] === 'unavailable' || $info['trial_days'] < 1) return ext_t("L'essai n'est pas disponible pour {ce ext}.");
    if (!array_intersect(['monthly', 'yearly', 'once'], $info['payment_modes'])) return ext_t("Aucune offre d'essai n'est disponible pour {ce ext}.");
    if ($info['access'] === 'included') return ext_t('{Ce ext} est déjà {?inclus|incluse} dans votre galerie.');
    if (!empty($info['beta_tester'])) return ext_t('{Ext} en test : accès gratuit jusqu’à sa mise en production.');
    if (addon_is_entitled($module)) return ext_t('{Le ext} est déjà {?actif|active}.');
    $r = addon_row($module);
    if ((int) $r['trial_used'] === 1) return "L'essai gratuit a déjà été utilisé : souscrivez pour continuer.";
    addon_save($module, ['status' => 'trial', 'trial_until' => time() + $info['trial_days'] * 86400, 'trial_used' => 1]);
    return null;
}

// ── Abonnement Stripe (compte de la plateforme) ─────────────────────────────

/** Ouvre le paiement. -> [url, null] ou [null, message d'erreur]. */
function addon_checkout(string $module, string $successUrl, string $cancelUrl, string $billing = 'monthly'): array
{
    require_once __DIR__ . '/saas.php';
    $info = addon_info($module);
    if (!$info) return [null, ext_t('{Ext} inconnu{e}.')];
    if ($info['access'] === 'unavailable') return [null, ext_t('{Ce ext} n’est pas {?proposé|proposée} pour votre boutique.')];
    if ($info['access'] === 'included') return [null, ext_t('{Ce ext} est déjà {?inclus|incluse} dans votre galerie.')];
    if (!empty($info['beta_tester'])) return [null, ext_t('{Ext} en test : accès gratuit jusqu’à sa mise en production.')];
    if (!in_array($billing, ['monthly', 'yearly', 'once'], true) || !in_array($billing, $info['payment_modes'], true)) return [null, 'Cette modalité de paiement n’est pas disponible.'];
    if ($billing === 'yearly' && $info['yearly_cents'] === null) return [null, 'Le tarif annuel n’est pas renseigné.'];
    if ($billing === 'once' && $info['once_cents'] === null) return [null, 'Le tarif unique n’est pas renseigné.'];
    if (addon_row($module)['status'] === 'active') return [null, ext_t('{Ce ext} est déjà {?souscrit|souscrite}.')];
    if (!saas_stripe_configured()) return [null, "Le paiement n'est pas encore configuré sur la plateforme. Démarrez l'essai gratuit ou contactez l'exploitant."];
    $slug = tenant_slug();
    $shop = (string) (get_content()['site_name'] ?? tenant('name'));
    $meta = ['type' => 'module_addon', 'shop' => $slug, 'plugin' => $module, 'billing' => $billing];
    $recurring = $billing === 'monthly' || $billing === 'yearly';
    $cents = match ($billing) {
        'yearly' => $info['yearly_cents'],
        'once' => $info['once_cents'],
        default => $info['monthly_cents'],
    };
    if ($cents === null || $cents <= 0) return [null, 'Un montant positif doit être renseigné pour ce paiement Stripe.'];
    $price = ['currency' => 'eur', 'unit_amount' => (int) $cents,
        'product_data' => ['name' => $info['name'] . ' — ' . $shop, 'description' => mb_substr($info['description'], 0, 300)]];
    if ($recurring) $price['recurring'] = ['interval' => $billing === 'yearly' ? 'year' : 'month'];
    try {
        $params = [
            'mode' => $recurring ? 'subscription' : 'payment', 'locale' => 'fr', 'client_reference_id' => 'module:' . $module . ':' . $slug,
            'line_items' => [['quantity' => 1, 'price_data' => $price]],
            'metadata' => $meta,
            'success_url' => $successUrl, 'cancel_url' => $cancelUrl,
        ];
        if ($recurring) $params['subscription_data'] = ['metadata' => $meta];
        $s = saas_stripe_request('POST', '/checkout/sessions', $params);
    } catch (Throwable $e) {
        error_log('addon_checkout ' . $module . ': ' . $e->getMessage());
        return [null, "Impossible d'ouvrir le paiement : " . $e->getMessage()];
    }
    return empty($s['url']) ? [null, "Stripe n'a pas renvoyé d'adresse de paiement."] : [$s['url'], null];
}

/** Base d'une autre boutique (webhook : aucune boutique courante). */
function addon_with_shop(string $slug, callable $fn): mixed
{
    if (function_exists('tenant_slug') && function_exists('db') && tenant_slug() === $slug) return $fn();
    if (!function_exists('plugin_open_tenant_db')) require_once __DIR__ . '/plugins.php';
    [$pdo] = plugin_open_tenant_db($slug);
    $prev = $GLOBALS['addon_pdo_override'] ?? null;
    $GLOBALS['addon_pdo_override'] = $pdo;
    try {
        return $fn();
    } finally {
        if ($prev === null) unset($GLOBALS['addon_pdo_override']); else $GLOBALS['addon_pdo_override'] = $prev;
    }
}

/** Session Checkout payée (metadata.type = module_addon) → abonnement actif. -> ['state' => added|already|unpaid|invalid, 'slug', 'module']. */
function addon_apply_checkout_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'module_addon') return ['state' => 'invalid'];
    $slug = (string) ($m['shop'] ?? '');
    $module = (string) ($m['plugin'] ?? '');
    $billing = in_array(($m['billing'] ?? ''), ['monthly', 'yearly', 'once'], true) ? (string) $m['billing'] : 'monthly';
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug) || !addon_info($module, $slug)) return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => $slug, 'module' => $module];
    if ($billing === 'once' ? !empty($session['subscription']) : empty($session['subscription'])) return ['state' => 'invalid'];
    $sid = (string) ($session['id'] ?? '');
    return addon_with_shop($slug, static function () use ($module, $slug, $session, $sid, $billing): array {
        $r = addon_row($module);
        if ($r['status'] === 'active' && $sid !== '' && $r['stripe_session_id'] === $sid) return ['state' => 'already', 'slug' => $slug, 'module' => $module];
        addon_save($module, ['status' => 'active', 'stripe_subscription_id' => (string) ($session['subscription'] ?? ''),
            'stripe_customer_id' => (string) ($session['customer'] ?? ''), 'stripe_session_id' => $sid,
            'billing_mode' => $billing,
            'subscribed_at' => gmdate('c'), 'canceled_at' => '']);
        return ['state' => 'added', 'slug' => $slug, 'module' => $module];
    });
}

/** Abonnement résilié (webhook) : cherche la boutique et le module concernés. -> [slug, module] ou null. */
function addon_cancel_subscription(string $subscriptionId): ?array
{
    if ($subscriptionId === '') return null;
    if (!function_exists('tenant_list')) require_once __DIR__ . '/tenant.php';
    if (!function_exists('plugin_open_tenant_db')) require_once __DIR__ . '/plugins.php';
    foreach (array_keys(tenant_list()) as $slug) {
        try {
            [$pdo] = plugin_open_tenant_db($slug);
            if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='addon_access'")->fetchColumn()) continue;
            $st = $pdo->prepare('SELECT module FROM addon_access WHERE stripe_subscription_id = ?');
            $st->execute([$subscriptionId]);
            $module = $st->fetchColumn();
            if (!$module) continue;
            addon_save((string) $module, ['status' => 'canceled', 'canceled_at' => gmdate('c')], $pdo);
            return [$slug, (string) $module];
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

// ── Retours des testeurs ────────────────────────────────────────────────────

function addon_feedback_file(): string
{
    return dirname(__DIR__) . '/data/saas/addon-feedback.json';
}

/** Retours enregistrés (du plus récent au plus ancien), éventuellement filtrés par module et/ou boutique. */
function addon_feedback_list(string $module = '', string $shop = ''): array
{
    $data = is_file(addon_feedback_file()) ? json_decode((string) file_get_contents(addon_feedback_file()), true) : [];
    $out = [];
    foreach (is_array($data) ? array_reverse($data) : [] as $f) {
        if (!is_array($f) || ($module !== '' && ($f['module'] ?? '') !== $module) || ($shop !== '' && ($f['shop'] ?? '') !== $shop)) continue;
        $out[] = $f;
    }
    return $out;
}

/** Date (timestamp) à partir de laquelle un retour est attendu : dernier retour de la boutique, sinon début du test. */
function addon_feedback_due(string $module, string $shop, array $info): bool
{
    $last = (int) ($info['beta_since'] ?? 0);
    foreach (addon_feedback_list($module, $shop) as $f) {
        $last = max($last, (int) strtotime((string) ($f['at'] ?? '')));
        break;
    }
    return $last > 0 && time() > $last + (int) $info['feedback_days'] * 86400;
}

/** Boutiques testeuses d'un module en test (directes + membres des galeries testeuses). */
function addon_beta_shops(array $info): array
{
    if (!function_exists('gallery_list')) require_once __DIR__ . '/galleries.php';
    $out = (array) ($info['testers']['shops'] ?? []);
    $galleries = gallery_list();
    foreach ((array) ($info['testers']['galleries'] ?? []) as $g) {
        foreach ($galleries[$g]['members'] ?? [] as $m) if (($m['type'] ?? '') === 'local') $out[] = (string) $m['slug'];
    }
    return array_values(array_unique($out));
}

/** Envoie le rappel de retour aux boutiques testeuses en retard (modules « En test » avec rappels activés). -> nombre d'e-mails. */
function addon_beta_send_reminders(bool $dry = false): int
{
    require_once __DIR__ . '/saas.php';
    $file = dirname(__DIR__) . '/data/saas/addon-reminders.json';
    $sent = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    $n = 0;
    foreach (array_keys(addon_defaults()) as $module) {
        $info = addon_info($module);
        if (!$info || $info['stage'] !== 'beta' || !$info['beta_reminders']) continue;
        foreach (addon_beta_shops($info) as $shop) {
            $cfg = tenant_load($shop);
            $to = trim((string) ($cfg['admin_user'] ?? ''));
            if (!$cfg || !filter_var($to, FILTER_VALIDATE_EMAIL) || !addon_feedback_due($module, $shop, $info)) continue;
            $key = $module . '|' . $shop;
            if (time() - (int) ($sent[$key] ?? 0) < $info['feedback_days'] * 86400) continue;
            $url = rtrim((string) ($cfg['site_url'] ?? ''), '/') . '/admin/plugin.php?p=' . rawurlencode($module) . '&v=index';
            $body = "Bonjour,\n\nvous testez gratuitement {le ext} « {$info['name']} » sur « " . ($cfg['name'] ?? $shop) . " ».\n"
                . "En échange, nous attendons votre retour (note et commentaire) : " . $url . "\n\n"
                . ($info['feedback_required'] ? "Sans retour, l'accès {au ext} est suspendu jusqu'à ce que vous en envoyiez un.\n\n" : '') . "Merci !";
            $body = ext_t($body);
            if (!$dry && !saas_mail($to, 'Votre retour sur « ' . $info['name'] . ' »', $body)) continue;
            $sent[$key] = time();
            $n++;
        }
    }
    if (!$dry && $n) @file_put_contents($file, json_encode($sent), LOCK_EX);
    return $n;
}

/** Enregistre le retour d'un testeur. -> message d'erreur ou null. */
function addon_feedback_add(string $module, string $shop, int $rating, string $text): ?string
{
    $text = trim(mb_substr($text, 0, 2000));
    if ($rating < 1 || $rating > 5) return 'Donnez une note de 1 à 5.';
    if (mb_strlen($text) < 10) return 'Décrivez votre retour en quelques mots (10 caractères minimum).';
    $file = addon_feedback_file();
    if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) return 'Enregistrement impossible.';
    $fh = @fopen($file, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) return 'Enregistrement impossible.';
    $data = json_decode((string) stream_get_contents($fh), true);
    $data = is_array($data) ? $data : [];
    $data[] = ['module' => $module, 'shop' => $shop, 'rating' => $rating, 'text' => $text, 'at' => gmdate('c')];
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode(array_slice($data, -2000), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return null;
}

// ── Route d'API et panneau d'administration communs ─────────────────────────

/** Corps de la route « access » d'un plugin : GET = état ; POST {action: trial|subscribe}. À appeler depuis packs/<id>/api/access.php. */
function addon_api_access(string $module): never
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') plugin_json(['entitlement' => addon_entitlement($module)]);
    $input = plugin_input();
    $action = (string) ($input['action'] ?? '');
    if ($action === 'trial') {
        $err = addon_start_trial($module);
        if ($err) plugin_json(['error' => $err], 409);
        $e = addon_entitlement($module);
        plugin_json(['ok' => true, 'entitlement' => $e, 'message' => "Essai de {$e['trial_days']} jours activé jusqu'au {$e['trial_until']}."]);
    }
    if ($action === 'subscribe') {
        $billing = (string) ($input['billing'] ?? 'monthly');
        require_once __DIR__ . '/share.php';
        [$url, $err] = addon_checkout($module,
            shop_abs_url('/admin/plugin.php?p=' . rawurlencode($module) . '&v=index') . '&paye=1&s={CHECKOUT_SESSION_ID}',
            shop_abs_url('/admin/plugin.php?p=' . rawurlencode($module) . '&v=index'), $billing);
        if ($err) plugin_json(['error' => $err], 502);
        plugin_json(['ok' => true, 'url' => $url]);
    }
    if ($action === 'feedback') {
        $info = addon_info($module);
        if (!$info || empty($info['beta_tester'])) plugin_json(['error' => ext_t('Les retours ne concernent que {les exts} en test.')], 409);
        $err = addon_feedback_add($module, tenant_slug(), (int) ($input['rating'] ?? 0), (string) ($input['text'] ?? ''));
        if ($err) plugin_json(['error' => $err], 422);
        plugin_json(['ok' => true, 'message' => 'Merci pour votre retour !']);
    }
    if ($action === 'free') {
        $info = addon_info($module);
        if (!$info || $info['access'] === 'unavailable' || !in_array('free', $info['payment_modes'], true)) {
            plugin_json(['error' => 'Cette offre gratuite n’est pas disponible.'], 409);
        }
        if (addon_is_entitled($module)) plugin_json(['error' => ext_t('{Ce ext} est déjà {?actif|active} ou {?inclus|incluse}.')], 409);
        addon_save($module, ['status' => 'complimentary', 'billing_mode' => 'free', 'subscribed_at' => gmdate('c'), 'canceled_at' => '']);
        plugin_json(['ok' => true, 'message' => ext_t('{Le ext} {?gratuit|gratuite} est {?activé|activée}.'), 'entitlement' => addon_entitlement($module)]);
    }
    plugin_json(['error' => 'Action invalide.'], 422);
}

/** Au retour de Stripe (?paye=1&s=cs_…) : confirme l'abonnement sans attendre le webhook. -> message ou ''. */
function addon_confirm_return(string $module): string
{
    $id = (string) ($_GET['s'] ?? '');
    if (empty($_GET['paye']) || !preg_match('/^cs_[A-Za-z0-9_]+$/', $id)) return '';
    require_once __DIR__ . '/saas.php';
    try {
        $s = saas_stripe_request('GET', '/checkout/sessions/' . rawurlencode($id));
        if (($s['metadata']['shop'] ?? '') !== tenant_slug() || ($s['metadata']['plugin'] ?? '') !== $module) return ext_t('Cette session de paiement ne correspond pas à {ce ext}.');
        $r = addon_apply_checkout_session($s);
        return match ($r['state']) {
            'added', 'already' => ext_t('Merci ! {Le ext} est {?actif|active}.'),
            'unpaid' => "Le paiement n'est pas encore confirmé par Stripe : {le ext} s'activera dès sa confirmation.",
            default => 'Paiement non reconnu.',
        };
    } catch (Throwable $e) {
        return 'Impossible de vérifier le paiement pour le moment.';
    }
}

/** Carte « Accès au module » (état, essai, abonnement) à placer en haut de la page d'un plugin. $api = $pluginApi('access'). */
function addon_panel_html(string $module, string $api, string $csrf): string
{
    $e = addon_entitlement($module);
    $flash = addon_confirm_return($module);
    if ($flash !== '') $e = addon_entitlement($module);
    $h = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $billingLabels = ['monthly' => 'mensuel', 'yearly' => 'annuel', 'once' => 'paiement unique', 'free' => 'gratuit', 'manual' => 'paiement manuel'];
    $contact = '';
    if (!function_exists('saas_config') && is_file(dirname(__DIR__) . '/includes/saas.php')) {
        require_once __DIR__ . '/saas.php';
    }
    if (function_exists('saas_config')) $contact = (string) (saas_config()['contact'] ?? '');
    $stripeOn = function_exists('saas_stripe_configured') && saas_stripe_configured();
    ob_start(); ?>
<section class="addon-card" data-addon-api="<?= $h($api) ?>" data-csrf="<?= $h($csrf) ?>" style="border:1px solid var(--line);padding:14px 16px;margin:0 0 16px;display:grid;gap:8px;max-width:820px;">
  <h2 style="margin:0;font-size:1.05rem;"><?= $h($e['name']) ?> <span style="font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;border:1px solid var(--line);padding:2px 8px;color:var(--ink-soft);"><?= $h($e['label']) ?></span></h2>
  <p class="hint" style="margin:0;"><?= $h($e['description']) ?></p>
  <?php if ($e['terms'] !== ''): ?><p class="hint" style="margin:0;"><?= nl2br($h($e['terms'])) ?></p><?php endif; ?>
  <?php if ($flash !== ''): ?><p class="hint" style="margin:0;font-weight:600;"><?= $h($flash) ?></p><?php endif; ?>
  <?php if ($e['status'] === 'trial'): ?><p class="hint" style="margin:0;">Essai gratuit jusqu'au <strong><?= $h($e['trial_until']) ?></strong>.</p><?php endif; ?>
  <?php if ($e['status'] === 'active'): ?><p class="hint" style="margin:0;">Accès actif<?= !empty($e['billing_mode']) ? ' (' . $h($billingLabels[$e['billing_mode']] ?? $e['billing_mode']) . ')' : '' ?>.</p><?php endif; ?>
  <?php if ($e['status'] === 'complimentary'): ?><p class="hint" style="margin:0;"><?= $e['billing_mode'] === 'free' ? 'Accès gratuit.' : 'Accès offert par la plateforme.' ?></p><?php endif; ?>
  <?php if ($e['status'] === 'included'): ?><p class="hint" style="margin:0;">{Ce ext} est {?inclus|incluse} par votre galerie.</p><?php endif; ?>
  <?php if (in_array($e['status'], ['beta', 'beta_locked'], true)): ?>
    <?php if ($e['beta_locked']): ?><p class="hint" style="margin:0;font-weight:600;color:#b3261e;">Accès suspendu : aucun retour depuis plus de <?= (int) $e['feedback_days'] ?> jours. Envoyez votre retour pour le réactiver.</p><?php endif; ?>
    <p class="hint" style="margin:0;">{Ce ext} est en <strong>phase de test</strong> : {?il est gratuit|elle est gratuite} jusqu’à sa mise en production, en échange de vos retours.</p>
    <?php $mine = addon_feedback_list($module, function_exists('tenant_slug') ? tenant_slug() : ''); ?>
    <p class="hint" style="margin:0;"><?= $mine ? count($mine) . ' retour(s) envoyé(s) — merci !' : 'Aucun retour envoyé pour l’instant.' ?></p>
    <div style="display:grid;gap:6px;max-width:520px;">
      <label class="hint">Note <select data-fb-rating><option value="5">5 — Excellent</option><option value="4">4 — Bien</option><option value="3">3 — Moyen</option><option value="2">2 — Décevant</option><option value="1">1 — Inutilisable</option></select></label>
      <textarea data-fb-text rows="3" maxlength="2000" placeholder="Ce qui fonctionne, ce qui manque, les bugs rencontrés…"></textarea>
      <div><button type="button" class="btn-small" data-addon-act="feedback">Envoyer mon retour</button> <span class="hint" data-addon-msg role="status"></span></div>
    </div>
  <?php endif; ?>
  <?php if ($e['status'] === 'unavailable'): ?><p class="hint" style="margin:0;">{Ce ext} n’est pas {?proposé|proposée} par votre plateforme ou votre galerie.</p><?php endif; ?>
  <?php if (!$e['entitled'] && $e['access'] !== 'unavailable'): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <?php if (!$e['trial_used'] && $e['trial_days'] > 0 && in_array('monthly', $e['payment_modes'], true)): ?><button type="button" class="btn-small" data-addon-act="trial">Essayer gratuitement <?= (int) $e['trial_days'] ?> jours</button><?php endif; ?>
      <?php foreach (['monthly' => $e['monthly_label'], 'yearly' => $e['yearly_label'], 'once' => $e['once_label']] as $billing => $label):
        $priceKey = ['monthly' => 'monthly_cents', 'yearly' => 'yearly_cents', 'once' => 'once_cents'][$billing];
        if ($stripeOn && in_array($billing, $e['payment_modes'], true) && $e[$priceKey] !== null && $e[$priceKey] > 0): ?>
        <button type="button" class="btn-small" data-addon-act="subscribe" data-billing="<?= $billing ?>">Souscrire — <?= $h($label) ?></button>
      <?php endif; endforeach; ?>
      <?php if (in_array('free', $e['payment_modes'], true)): ?><button type="button" class="btn-small" data-addon-act="free">Activer gratuitement</button><?php endif; ?>
      <?php if (in_array('manual', $e['payment_modes'], true)): ?>
      <?php if ($contact !== ''): ?><a class="btn-small" href="mailto:<?= $h($contact) ?>?subject=<?= rawurlencode(ext_t('Souscription {au ext} ') . $e['name']) ?>">Demander une souscription manuelle<?= $e['monthly_label'] !== '' ? ' — ' . $h($e['monthly_label']) : '' ?></a>
        <?php else: ?><span class="hint">Souscription manuelle : contactez l’exploitant.</span><?php endif; ?>
      <?php endif; ?>
      <span class="hint" data-addon-msg role="status"></span>
    </div>
  <?php endif; ?>
</section>
<script>
(function(){var c=document.currentScript.previousElementSibling;if(!c)return;
c.addEventListener('click',function(ev){var b=ev.target.closest('[data-addon-act]');if(!b)return;var m=c.querySelector('[data-addon-msg]');
fetch(c.dataset.addonApi,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-Token':c.dataset.csrf},body:JSON.stringify({action:b.dataset.addonAct,billing:b.dataset.billing||'',rating:(c.querySelector('[data-fb-rating]')||{}).value,text:(c.querySelector('[data-fb-text]')||{}).value})})
.then(function(r){return r.json()}).then(function(d){if(d.error){m.textContent=d.error;return}if(d.url){location.href=d.url;return}m.textContent=d.message||'OK';if(b.dataset.addonAct==='feedback'){var t=c.querySelector('[data-fb-text]');if(t)t.value=''}setTimeout(function(){location.reload()},700)})
.catch(function(){m.textContent='Erreur réseau.'})});})();
</script>
<?php return ext_t((string) ob_get_clean());
}

/** Garde de page ou d'API : le module est-il utilisable ? Sinon, message prêt à afficher. */
function addon_locked_html(string $module): string
{
    $e = addon_entitlement($module);
    $message = $e['access'] === 'unavailable'
        ? ext_t('{Ce ext} n’est pas {?proposé|proposée} pour cette boutique.')
        : ext_t('Démarrez l’essai gratuit ou souscrivez ci-dessus pour utiliser {ce ext}.');
    return '<p class="hint" style="max-width:62ch;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}
