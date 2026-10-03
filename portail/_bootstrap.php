<?php

// Portail de la marque blanche : liste des commerces, création d'un
// commerce, accès admin. Il écrit dans le dossier du projet :
//  - en local (Herd, php -S, hôte .test / localhost), il est ouvert ;
//  - en ligne (déploiement « portail » de deploy-brocante.sh, par ex.
//    brocs.arrimage.com), il exige une connexion avec le compte de
//    .secrets/portail.json (créé par le script de déploiement). Sans ce
//    fichier, il reste fermé.

const PORTAIL_ROOT = __DIR__ . '/..';

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$portailLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'));

session_start();
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/seed.php';

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Adresse d'un commerce, sans barre finale. En local : <identifiant>.<domaine>
 * (Herd, un sous-domaine par commerce). En ligne : l'adresse propre du
 * commerce quand son tenant.php en déclare une réelle (site_url, ex. le Petit
 * Chalet sur son sous-domaine, ou <portail>/<identifiant>), sinon
 * <domaine du portail>/<identifiant>.
 */
function portail_shop_url(string $slug, array $config): string
{
    global $portailLocal;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($portailLocal) {
        return "$scheme://$slug." . tenant_base_host();
    }
    $url = rtrim((string) ($config['site_url'] ?? ''), '/');
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $placeholder = $host === '' || $host === 'localhost' || $host === '127.0.0.1'
        || str_ends_with($host, '.test') || str_ends_with($host, '.example.com');
    return $placeholder ? "$scheme://" . tenant_base_host() . "/$slug" : $url;
}

/** Adresse lisible d'un commerce (sans https://), pour les liens et libellés. */
function portail_shop_label(string $slug, array $config): string
{
    return preg_replace('#^https?://#', '', portail_shop_url($slug, $config));
}

/** Forme de l'adresse d'un nouveau commerce, pour les textes d'aide : « <identifiant>.domaine » ou « domaine/<identifiant> ». */
function portail_address_pattern(): string
{
    global $portailLocal;
    return $portailLocal ? '<identifiant>.' . tenant_base_host() : tenant_base_host() . '/<identifiant>';
}

/** Raison pour laquelle un commerce ne peut pas être supprimé depuis le portail, ou null s'il le peut. */
function tenant_delete_blocker(string $slug): ?string
{
    if ($slug === TENANT_DEFAULT) {
        return "Le Petit Chalet est la boutique en ligne historique (base brocante.db, clés à la racine) : il ne se supprime pas depuis le portail.";
    }
    if ($slug === '' || $slug[0] === '_') {
        return "Ce dossier est un modèle, pas un commerce.";
    }
    return null;
}

/**
 * Supprime un commerce en déplaçant ses fichiers dans data/corbeille/<slug>-<date>/
 * (rien n'est effacé : pour le restaurer, remettre les dossiers à leur place).
 * Déplacés : tenants/<slug>, assets/tenants/<slug>, sa base SQLite et ses clés
 * (.secrets/<slug>/). Les photos de uploads/ ne sont pas touchées (dossier
 * partagé entre commerces). Le dossier du commerce est déplacé en dernier : si
 * une étape échoue, le commerce reste listé et on peut réessayer.
 * Retourne le dossier de la corbeille, relatif à la racine du projet.
 */
function delete_tenant(string $slug): string
{
    if (($why = tenant_delete_blocker($slug)) !== null) {
        throw new InvalidArgumentException($why);
    }
    $shop = tenant_load($slug);
    $root = realpath(PORTAIL_ROOT);
    $rel = "data/corbeille/$slug-" . date('Ymd-His');
    $trash = "$root/$rel";
    if (!is_dir($trash) && !mkdir($trash, 0775, true)) {
        throw new RuntimeException('Impossible de créer la corbeille (data/corbeille/) : droits d\'écriture manquants.');
    }

    // Chemins déclarés par le tenant.php : jamais déplacés s'ils sortent de leur zone
    // (une base ou des clés d'un autre commerce, par exemple), pour qu'une
    // configuration erronée ne puisse pas emporter autre chose que ce commerce.
    $inside = static function (string $path, string $zone) use ($root): bool {
        $real = realpath($path);
        $zoneReal = realpath("$root/$zone");
        return $real !== false && $zoneReal !== false && str_starts_with($real, $zoneReal . '/') && !str_contains($real, '/corbeille/');
    };
    $moves = [];
    $db = tenant_file($shop, 'db_file');
    if ($inside($db, 'data')) {
        foreach (['', '-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($db . $suffix)) $moves[] = [$db . $suffix, "$trash/" . basename($db) . $suffix];
        }
    }
    $secrets = tenant_file($shop, 'secrets_dir');
    if ($inside($secrets, '.secrets')) {
        $moves[] = [$secrets, "$trash/secrets"];
    }
    if (is_dir("$root/assets/tenants/$slug")) {
        $moves[] = ["$root/assets/tenants/$slug", "$trash/assets"];
    }
    $moves[] = ["$root/tenants/$slug", "$trash/tenant"];

    foreach ($moves as [$from, $to]) {
        if (!rename($from, $to)) {
            throw new RuntimeException('Suppression interrompue : « ' . basename($from) . ' » n\'a pas pu être déplacé. Le commerce reste en place, réessayez.');
        }
    }

    // Si ce commerce était celui que le site affiche par défaut (.tenant), retour au Petit Chalet.
    $tenantFile = "$root/.tenant";
    if (is_file($tenantFile) && trim((string) file_get_contents($tenantFile)) === $slug) {
        set_active_slug(TENANT_DEFAULT);
    }
    return $rel;
}

/** Compte du portail en ligne : ['user' => …, 'password_hash' => …] ou null. */
function portail_account(): ?array
{
    $file = PORTAIL_ROOT . '/.secrets/portail.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) && !empty($data['user']) && !empty($data['password_hash']) ? $data : null;
}

if (!$portailLocal && empty($_SESSION['portail_user'])) {
    portail_login_page();
}

/** Page de connexion du portail en ligne (termine la requête). */
function portail_login_page(): never
{
    $account = portail_account();
    if (!$account) {
        http_response_code(403);
        exit('Portail fermé : aucun compte configuré (.secrets/portail.json, créé par ./deploy-brocante.sh --portail).');
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'portail_login') {
        $userOk = hash_equals(mb_strtolower($account['user']), mb_strtolower(trim((string) ($_POST['username'] ?? ''))));
        if ($userOk && password_verify((string) ($_POST['password'] ?? ''), $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['portail_user'] = $account['user'];
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?? '/portail/'));
            exit;
        }
        sleep(1); // freine les essais en série
        $error = 'Identifiant ou mot de passe incorrect.';
    }
    http_response_code($error ? 401 : 200);
    ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Connexion — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
</head>
<body>
<div class="app" style="max-width:380px;padding-top:14vh;">
  <form class="send" method="post" autocomplete="on">
    <h2>Portail des commerces</h2>
    <p class="hint">Connectez-vous pour gérer les commerces.</p>
    <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <input type="hidden" name="action" value="portail_login">
    <label class="field">Identifiant<input name="username" autocomplete="username" autocapitalize="none" required autofocus></label>
    <label class="field">Mot de passe
      <span class="password-field">
        <input type="password" name="password" id="pp" autocomplete="current-password" required>
        <button type="button" class="password-toggle" onclick="var i=document.getElementById('pp');i.type=i.type==='password'?'text':'password';this.textContent=i.type==='password'?'Afficher':'Masquer';">Afficher</button>
      </span>
    </label>
    <div class="send-actions"><button class="btn btn-primary" type="submit">Se connecter</button></div>
  </form>
</div>
</body>
</html>
<?php
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['portail_csrf'])) {
        $_SESSION['portail_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['portail_csrf'];
}

function csrf_check(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Formulaire expiré : rechargez la page et recommencez.');
    }
}

function portail_flash(?string $message = null, string $kind = 'ok'): ?array
{
    if ($message !== null) {
        $_SESSION['portail_flash'] = ['message' => $message, 'kind' => $kind];
        return null;
    }
    $flash = $_SESSION['portail_flash'] ?? null;
    unset($_SESSION['portail_flash']);
    return $flash;
}

/** Commerce affiché par le site (variable TENANT, fichier .tenant ou petit-chalet). */
function active_slug(): string
{
    return tenant_slug();
}

/** Change le commerce affiché en écrivant (ou supprimant) le fichier .tenant. */
function set_active_slug(string $slug): void
{
    $file = PORTAIL_ROOT . '/.tenant';
    if ($slug === TENANT_DEFAULT) {
        if (is_file($file)) unlink($file);
    } else {
        file_put_contents($file, $slug . "\n");
    }
}

/**
 * Apparence d'un commerce pour le portail : celle réglée dans son
 * administration (table settings de sa base), sinon celle de son tenant.php.
 * Retourne ['colors' => [bg, ink, accent, accent-2], 'fonts' => [display, body], 'custom' => bool].
 */
function portail_appearance(array $shop): array
{
    $saved = null;
    $dbFile = tenant_file($shop, 'db_file');
    if (is_file($dbFile)) {
        try {
            $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $value = $pdo->query("SELECT value FROM settings WHERE name = 'appearance'")->fetchColumn();
            $saved = $value ? json_decode($value, true) : null;
        } catch (Throwable $e) {
            $saved = null; // table settings absente : apparence jamais modifiée
        }
    }
    $colors = [];
    foreach (APPEARANCE_BASE as $key => $base) {
        $colors[$key] = appearance_color((string) ($saved['colors'][$key] ?? $shop['colors'][$key] ?? ''), $base);
    }
    return [
        'colors' => $colors,
        'fonts' => [
            'display' => $saved['fonts']['display'] ?? $shop['fonts']['display'] ?? APPEARANCE_BASE_FONTS['display'],
            'body' => $saved['fonts']['body'] ?? $shop['fonts']['body'] ?? APPEARANCE_BASE_FONTS['body'],
        ],
        'custom' => (bool) $saved,
    ];
}

/** Contrôle un identifiant et un mot de passe d'administration ; retourne l'identifiant normalisé. */
function validate_admin_access(string $user, string $password): string
{
    $user = mb_strtolower(trim($user));
    if (!preg_match('/^[a-z0-9._@-]{3,40}$/', $user)) {
        throw new InvalidArgumentException("Identifiant d'administration : 3 à 40 caractères parmi lettres minuscules, chiffres, point, tiret, @.");
    }
    if (mb_strlen(trim($password)) < 6) {
        throw new InvalidArgumentException("Choisissez un mot de passe d'administration d'au moins 6 caractères.");
    }
    return $user;
}

/**
 * Remplace l'identifiant et le mot de passe d'administration dans
 * tenants/<slug>/tenant.php (mot de passe haché). Retourne l'identifiant.
 */
function set_tenant_admin_access(string $slug, string $user, string $password): string
{
    $user = validate_admin_access($user, $password);
    $file = PORTAIL_ROOT . "/tenants/$slug/tenant.php";
    $php = file_get_contents($file);

    $userLine = "'admin_user' => " . var_export($user, true) . ',';
    $passwordLine = "'admin_password' => " . var_export(password_hash(trim($password), PASSWORD_DEFAULT), true) . ',';
    $valuePattern = "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";

    $php = preg_replace("/^(\\s*)'admin_user'\\s*=>\\s*$valuePattern\\s*,/m", '$1' . str_replace('$', '\\$', $userLine), $php, 1, $hasUser);
    $php = preg_replace("/^(\\s*)'admin_password'\\s*=>\\s*$valuePattern\\s*,/m", '$1' . str_replace('$', '\\$', $passwordLine), $php, 1, $hasPassword);
    if (!$hasPassword) {
        // Pas de ligne admin_password : on ajoute le compte juste après « return [ ».
        $php = preg_replace('/return\s*\[\s*\n/', "\$0    $userLine\n    " . str_replace('$', '\\$', $passwordLine) . "\n", $php, 1, $added);
        if (!$added) throw new RuntimeException("Impossible de modifier $file : ajoutez-y admin_user et admin_password à la main.");
    } elseif (!$hasUser) {
        $php = preg_replace("/^(\\s*)('admin_password'\\s*=>)/m", '$1' . $userLine . "\n" . '$1$2', $php, 1);
    }

    $tmp = "$file.tmp";
    file_put_contents($tmp, $php);
    rename($tmp, $file);
    if (function_exists('opcache_invalidate')) opcache_invalidate($file, true);

    $saved = tenant_load($slug);
    if ($saved['admin_user'] !== $user || !password_verify(trim($password), $saved['admin_password'])) {
        throw new RuntimeException("La modification de $file n'a pas été enregistrée correctement.");
    }
    return $user;
}

function slugify_portail(string $text): string
{
    if (class_exists('Normalizer')) {
        $text = preg_replace('/\p{Mn}/u', '', Normalizer::normalize($text, Normalizer::FORM_D));
    } else {
        $text = strtr($text, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'œ' => 'oe', 'É' => 'E', 'À' => 'A']);
    }
    $text = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return substr($text, 0, 40) ?: 'mon-commerce';
}

/** Valeur PHP lisible : tableaux courts, indentation de 4 espaces. */
function php_export($value, int $level = 1): string
{
    if (!is_array($value)) {
        return var_export($value, true);
    }
    if (!$value) {
        return '[]';
    }
    $pad = str_repeat('    ', $level);
    $isList = array_is_list($value);
    $lines = [];
    foreach ($value as $k => $v) {
        $key = $isList ? '' : var_export($k, true) . ' => ';
        // Les petits tableaux associatifs d'une liste (catégories) tiennent sur une ligne.
        if ($isList && is_array($v) && !array_is_list($v) && !array_filter($v, 'is_array')) {
            $parts = [];
            foreach ($v as $kk => $vv) {
                $parts[] = var_export($kk, true) . ' => ' . var_export($vv, true);
            }
            $lines[] = $pad . '[' . implode(', ', $parts) . '],';
        } else {
            $lines[] = $pad . $key . php_export($v, $level + 1) . ',';
        }
    }
    return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $level - 1) . ']';
}

function valid_color(string $c, string $fallback): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $fallback;
}

const PORTAIL_ICONS = [
    'ic-vase' => 'Vase', 'ic-pitcher' => 'Pichet', 'ic-bowls' => 'Bols', 'ic-pot' => 'Pot',
    'ic-basket' => 'Panier', 'ic-stool' => 'Tabouret', 'ic-mirror' => 'Miroir', 'ic-plaid' => 'Plaid',
    'ic-cushion' => 'Coussin', 'ic-candle' => 'Bougie', 'ic-photophore' => 'Photophore', 'ic-pendant' => 'Suspension',
];

/**
 * Crée tenants/<slug>/ (tenant.php + seed-data.json), le logo éventuel et la
 * base du commerce à partir du formulaire du portail. Retourne le slug.
 */
function create_tenant_from_form(array $f, ?array $logoUpload): string
{
    $name = trim((string) ($f['name'] ?? ''));
    if ($name === '') throw new InvalidArgumentException('Donnez un nom au commerce.');
    $slugInput = trim((string) ($f['slug'] ?? ''));
    $slug = slugify_portail($slugInput !== '' ? $slugInput : $name);
    if (is_dir(PORTAIL_ROOT . "/tenants/$slug")) {
        throw new InvalidArgumentException("Un commerce « $slug » existe déjà : choisissez un autre identifiant.");
    }

    $categories = [];
    foreach ($f['cat'] ?? [] as $i => $label) {
        $label = trim((string) $label);
        if ($label === '') continue;
        $icon = $f['cat_icon'][$i] ?? 'ic-vase';
        $categories[$i] = ['key' => slugify_portail($label), 'label' => $label, 'icon' => isset(PORTAIL_ICONS[$icon]) ? $icon : 'ic-vase'];
    }
    if (!$categories) throw new InvalidArgumentException('Ajoutez au moins une catégorie.');
    $adminUser = mb_strtolower(trim((string) ($f['admin_user'] ?? '')));
    if (!preg_match('/^[a-z0-9._@-]{3,40}$/', $adminUser)) {
        throw new InvalidArgumentException("Identifiant d'administration : 3 à 40 caractères parmi lettres minuscules, chiffres, point, tiret, @.");
    }
    if (mb_strlen(trim((string) ($f['admin_password'] ?? ''))) < 6) {
        throw new InvalidArgumentException("Choisissez un mot de passe d'administration d'au moins 6 caractères.");
    }

    $item1 = trim((string) ($f['item1'] ?? '')) ?: 'article';
    $item2 = trim((string) ($f['item2'] ?? '')) ?: 'articles';
    $accent = valid_color((string) ($f['accent'] ?? ''), '#1e5f8c');
    $accent2 = valid_color((string) ($f['accent2'] ?? ''), '#6a6f3a');
    $bg = valid_color((string) ($f['bg'] ?? ''), '#f6f5f1');
    $ink = valid_color((string) ($f['ink'] ?? ''), '#2b2620');
    $palette = appearance_derive(['bg' => $bg, 'ink' => $ink, 'accent' => $accent, 'accent-2' => $accent2]);

    // Logo : fichier envoyé, sinon logo texte au nom du commerce, dans assets/tenants/<slug>/.
    $logoDir = PORTAIL_ROOT . "/assets/tenants/$slug";
    if (!is_dir($logoDir)) mkdir($logoDir, 0775, true);
    if ($logoUpload && ($logoUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'][mime_content_type($logoUpload['tmp_name'])] ?? null;
        if (!$ext) throw new InvalidArgumentException('Logo : formats acceptés PNG, JPG, WebP ou SVG.');
        move_uploaded_file($logoUpload['tmp_name'], "$logoDir/logo.$ext");
        $logo = "assets/tenants/$slug/logo.$ext";
    } else {
        $width = max(120, (int) ceil(mb_strlen($name) * 13.5) + 16);
        file_put_contents("$logoDir/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' 48" width="' . $width . '" height="48">'
            . '<text x="8" y="32" font-family="Georgia, \'Times New Roman\', serif" font-size="24" font-weight="600" fill="' . $accent . '">'
            . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</text></svg>' . "\n");
        $logo = "assets/tenants/$slug/logo.svg";
    }

    $config = [
        'name' => $name,
        'tagline' => trim((string) ($f['tagline'] ?? '')),
        'site_url' => trim((string) ($f['url'] ?? '')) ?: "https://$slug.example.com",
        'admin_user' => $adminUser,
        // Haché : le mot de passe n'est lisible nulle part. Pour le changer,
        // remplacer cette valeur par le nouveau mot de passe, en clair.
        'admin_password' => password_hash(trim((string) $f['admin_password']), PASSWORD_DEFAULT),
        'logo' => $logo,
        'logo_macaron' => $logo,
        // Palette complète (clair et sombre) calculée comme dans Administration → Apparence.
        'colors' => $palette['light'],
        'colors_dark' => $palette['dark'],
        'fonts' => [
            'display' => isset(APPEARANCE_FONTS['display'][$f['font_display'] ?? '']) ? $f['font_display'] : APPEARANCE_BASE_FONTS['display'],
            'body' => isset(APPEARANCE_FONTS['body'][$f['font_body'] ?? '']) ? $f['font_body'] : APPEARANCE_BASE_FONTS['body'],
        ],
        'categories' => array_values($categories),
        'texts' => [
            'categories_eyebrow' => 'Explorer par catégorie',
            'categories_title' => trim((string) ($f['cat_title'] ?? '')) ?: 'Nos catégories',
            'item_singular' => $item1,
            'item_plural' => $item2,
            'follow_eyebrow' => 'En images',
            'follow_title' => 'Suivez-nous au quotidien',
            'newsletter_title' => trim((string) ($f['nl_title'] ?? '')) ?: 'Les nouveautés, avant tout le monde',
            'newsletter_text' => trim((string) ($f['nl_text'] ?? '')) ?: 'Un e-mail de temps en temps, quand il y a du nouveau. Pas plus.',
            'empty_category' => "Aucun $item1 dans cette catégorie pour le moment — repassez bientôt.",
        ],
        // Contexte donné à l'IA pour décrire les photos : fourni par la génération
        // du formulaire (portail/generer.php), sinon déduit du nom et des catégories.
        'ai' => [
            'shop' => mb_substr(trim((string) ($f['ai_shop'] ?? '')), 0, 120) ?: "la boutique en ligne de $name",
            'item' => mb_substr(trim((string) ($f['ai_item'] ?? '')), 0, 80) ?: "un $item1",
            'examples' => mb_substr(trim((string) ($f['ai_examples'] ?? '')), 0, 200)
                ?: implode(', ', array_map(fn ($c) => mb_strtolower($c['label']), $categories)),
        ],
    ];

    $products = [];
    foreach ($f['p_name'] ?? [] as $i => $pname) {
        $pname = trim((string) $pname);
        if ($pname === '') continue;
        $cat = $categories[(int) ($f['p_cat'][$i] ?? -1)] ?? reset($categories);
        $products[] = [
            'ref' => sprintf('%03d', count($products) + 1),
            'name' => $pname,
            'cat' => $cat['key'],
            'desc' => trim((string) ($f['p_desc'][$i] ?? '')),
            'price' => trim((string) ($f['p_price'][$i] ?? '')),
            'badge' => trim((string) ($f['p_badge'][$i] ?? '')),
            'icon' => $cat['icon'],
        ];
    }
    $principles = [];
    for ($n = 0; $n < 3; $n++) {
        $principles[] = ['title' => trim((string) ($f["pr{$n}"] ?? '')), 'text' => trim((string) ($f["pr{$n}t"] ?? ''))];
    }
    $seed = [
        'brand' => ['name' => $name, 'tagline' => $config['tagline']],
        'hero' => [
            'eyebrow' => trim((string) ($f['hero_eyebrow'] ?? '')),
            'title' => trim((string) ($f['hero_title'] ?? '')),
            'subtitle' => trim((string) ($f['hero_sub'] ?? '')),
            'photo' => '',
        ],
        'story' => [
            'eyebrow' => 'Notre histoire',
            'title' => trim((string) ($f['story_title'] ?? '')),
            'text' => trim((string) ($f['story_text'] ?? '')),
            'photo' => '',
            'photoCaption' => '',
            'principles' => $principles,
        ],
        'contact' => [
            'address' => trim((string) ($f['address'] ?? '')),
            'hours' => trim((string) ($f['hours'] ?? '')),
            'delivery' => trim((string) ($f['delivery'] ?? '')),
        ],
        'shipping' => [
            'fee' => trim((string) ($f['fee'] ?? '')) ?: '6,90 €',
            'pickupLabel' => trim((string) ($f['pickup'] ?? '')) ?: 'Retrait en boutique',
            'shippingLabel' => 'Envoi postal',
        ],
        'featuredRefs' => array_slice(array_column($products, 'ref'), 0, 3),
        'products' => $products,
    ];

    $dir = PORTAIL_ROOT . "/tenants/$slug";
    mkdir($dir, 0775, true);
    file_put_contents("$dir/tenant.php", "<?php\n\n// Configuration du commerce : " . str_replace(["\n", '?>'], ' ', $name)
        . " (créée depuis le portail local).\n\nreturn " . php_export($config) . ";\n");
    file_put_contents("$dir/seed-data.json", json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

    seed_tenant(tenant_load($slug));
    return $slug;
}

// ── Génération du contenu d'un nouveau commerce par l'IA (Gemini) ──────────

/** Clé Gemini du portail : variable d'environnement, sinon .secrets/gemini.key à la racine. */
function portail_gemini_key(): string
{
    $env = (string) getenv('GEMINI_API_KEY');
    if ($env !== '') return $env;
    $file = PORTAIL_ROOT . '/.secrets/gemini.key';
    return is_file($file) ? trim((string) file_get_contents($file)) : '';
}

/** Enregistre la clé Gemini du portail (.secrets/gemini.key, lisible par le seul propriétaire). */
function portail_gemini_key_save(string $key): void
{
    $dir = PORTAIL_ROOT . '/.secrets';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents("$dir/gemini.key", trim($key));
    chmod("$dir/gemini.key", 0600);
}

/**
 * Appelle Gemini (texte, réponse JSON) et rend le tableau décodé. Un seul
 * essai, 50 s maximum : la page doit répondre avant la limite de durée des
 * hébergements mutualisés (60 s) — l'utilisateur peut simplement relancer.
 * Lève RuntimeException avec un message affichable.
 */
function portail_gemini_json(string $prompt): array
{
    $key = portail_gemini_key();
    if ($key === '') throw new RuntimeException('no_key');
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $key);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature' => 0.8,
                // Pas de « réflexion » préalable : le texte à produire est simple et doit arriver vite.
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ]),
        CURLOPT_TIMEOUT => 50,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $status >= 400) {
        error_log('portail_gemini_json: HTTP ' . $status . ' ' . substr((string) $body, 0, 300));
        throw new RuntimeException($status === 429 ? "Le service d'IA est saturé, réessayez dans un instant."
            : ($status === 400 || $status === 403 ? 'La clé Gemini est refusée : vérifiez-la.' : "Le service d'IA n'a pas répondu, réessayez."));
    }
    $text = (string) (json_decode($body, true)['candidates'][0]['content']['parts'][0]['text'] ?? '');
    $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)));
    $data = json_decode($text, true);
    if (!is_array($data)) throw new RuntimeException("La réponse de l'IA est inexploitable, réessayez.");
    return $data;
}

/** Prompt de génération : le JSON demandé suit exactement les champs du formulaire de création. */
function portail_shop_content_prompt(string $description, string $name): string
{
    $icons = implode(', ', array_keys(PORTAIL_ICONS));
    $displayFonts = implode(', ', array_keys(APPEARANCE_FONTS['display']));
    $bodyFonts = implode(', ', array_keys(APPEARANCE_FONTS['body']));
    $nameLine = $name !== ''
        ? "Le commerce s'appelle « $name » (garde ce nom, ne le change pas)."
        : "Le commerce n'a pas encore de nom : propose-en un court et mémorable dans le champ \"name\".";
    return <<<PROMPT
Tu rédiges le contenu de départ de la boutique en ligne d'un petit commerce, en français, sur un ton chaleureux, concret et honnête (pas de superlatifs creux, pas de jargon marketing).
$nameLine
Thématique et indications du commerçant : « $description »

Réponds UNIQUEMENT avec un objet JSON strict (sans markdown) de cette forme exacte, en respectant les longueurs maximales :
{
  "name": "nom du commerce (60 car. max)",
  "tagline": "slogan (70 car. max)",
  "item1": "un article au singulier, minuscules (ex. livre)",
  "item2": "des articles au pluriel, minuscules (ex. livres)",
  "cat_title": "titre de la section catégories (80 car. max)",
  "cats": [{"label": "catégorie (40 car. max)", "icon": "UNE valeur parmi : $icons"}],
  "hero_eyebrow": "surtitre court (60 car. max)",
  "hero_title": "accroche principale (120 car. max)",
  "hero_sub": "présentation en 1 ou 2 phrases (400 car. max)",
  "story_title": "titre « Notre histoire » (120 car. max)",
  "story_text": "histoire du commerce en 2 à 4 phrases (900 car. max), sans date ni lieu précis inventés",
  "pr": [{"title": "engagement (60 car. max)", "text": "en une phrase (160 car. max)"}],
  "nl_title": "accroche newsletter (100 car. max)",
  "nl_text": "promesse newsletter (200 car. max)",
  "pickup": "libellé du retrait (60 car. max)",
  "delivery": "phrase sur la livraison/retrait (160 car. max)",
  "products": [{"name": "nom (80 car. max)", "price": "prix fixe au format 12 € ou 6,50 €", "cat": 0, "desc": "description honnête (240 car. max)", "badge": "étiquette courte ou chaîne vide"}],
  "colors": {"bg": "#rrggbb", "ink": "#rrggbb", "accent": "#rrggbb", "accent2": "#rrggbb"},
  "font_display": "UNE valeur parmi : $displayFonts",
  "font_body": "UNE valeur parmi : $bodyFonts",
  "ai_shop": "ce qu'est le commerce, commençant par « une » ou « un » (ex. une librairie d'occasion en ligne)",
  "ai_item": "un article typique, commençant par « un » ou « une » (ex. un livre d'occasion)",
  "ai_examples": "6 à 10 exemples d'articles vendus, séparés par des virgules"
}
Contraintes : 4 à 6 catégories ; exactement 3 engagements ("pr") ; 5 ou 6 produits crédibles pour cette thématique, "cat" étant l'indice (à partir de 0) de leur catégorie dans "cats" ; prix réalistes pour ce type de commerce ; palette cohérente avec la thématique et lisible (fond clair, texte foncé, couleurs d'accent assez soutenues pour un bouton) ; n'invente ni adresse, ni téléphone, ni horaires, ni marque réelle, ni récompense.
PROMPT;
}

/**
 * Nettoie la réponse de l'IA : textes tronqués aux longueurs du formulaire,
 * icônes/polices restreintes aux listes autorisées, palette ignorée si elle
 * n'est pas lisible. Rend les valeurs prêtes à remplir le formulaire.
 */
function portail_shop_content_normalize(array $raw, string $fixedName): array
{
    // Au-delà de la longueur permise, coupe à la fin d'un mot plutôt qu'en plein milieu.
    $text = static function ($v, int $max): string {
        $s = trim(preg_replace('/\s+/u', ' ', (string) ($v ?? '')));
        if (mb_strlen($s) <= $max) return $s;
        $cut = mb_substr($s, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space !== false && $space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, " ,;:-—");
    };
    $out = [
        'name' => $fixedName !== '' ? $fixedName : $text($raw['name'] ?? '', 60),
        'tagline' => $text($raw['tagline'] ?? '', 80),
        'item1' => $text($raw['item1'] ?? '', 30),
        'item2' => $text($raw['item2'] ?? '', 30),
        'cat_title' => $text($raw['cat_title'] ?? '', 80),
        'hero_eyebrow' => $text($raw['hero_eyebrow'] ?? '', 60),
        'hero_title' => $text($raw['hero_title'] ?? '', 120),
        'hero_sub' => $text($raw['hero_sub'] ?? '', 400),
        'story_title' => $text($raw['story_title'] ?? '', 120),
        'story_text' => $text($raw['story_text'] ?? '', 900),
        'nl_title' => $text($raw['nl_title'] ?? '', 100),
        'nl_text' => $text($raw['nl_text'] ?? '', 200),
        'pickup' => $text($raw['pickup'] ?? '', 60),
        'delivery' => $text($raw['delivery'] ?? '', 160),
        'ai_shop' => $text($raw['ai_shop'] ?? '', 120),
        'ai_item' => $text($raw['ai_item'] ?? '', 80),
        'ai_examples' => $text($raw['ai_examples'] ?? '', 200),
        'cats' => [], 'pr' => [], 'products' => [],
    ];
    foreach (array_slice((array) ($raw['cats'] ?? []), 0, 6) as $cat) {
        $label = $text($cat['label'] ?? '', 40);
        if ($label === '') continue;
        $icon = (string) ($cat['icon'] ?? '');
        $out['cats'][] = ['label' => $label, 'icon' => isset(PORTAIL_ICONS[$icon]) ? $icon : 'ic-vase'];
    }
    foreach (array_slice((array) ($raw['pr'] ?? []), 0, 3) as $pr) {
        $out['pr'][] = ['title' => $text($pr['title'] ?? '', 60), 'text' => $text($pr['text'] ?? '', 160)];
    }
    foreach (array_slice((array) ($raw['products'] ?? []), 0, 6) as $product) {
        $name = $text($product['name'] ?? '', 80);
        if ($name === '' || !$out['cats']) continue;
        $cat = (int) ($product['cat'] ?? 0);
        $out['products'][] = [
            'name' => $name,
            'price' => $text($product['price'] ?? '', 20),
            'cat' => $cat >= 0 && $cat < count($out['cats']) ? $cat : 0,
            'desc' => $text($product['desc'] ?? '', 240),
            'badge' => $text($product['badge'] ?? '', 24),
        ];
    }
    $displayFont = (string) ($raw['font_display'] ?? '');
    $bodyFont = (string) ($raw['font_body'] ?? '');
    if (isset(APPEARANCE_FONTS['display'][$displayFont])) $out['font_display'] = $displayFont;
    if (isset(APPEARANCE_FONTS['body'][$bodyFont])) $out['font_body'] = $bodyFont;

    // Palette : couleurs valides exigées ; un texte ou un accent trop pâle est
    // assombri jusqu'à être lisible plutôt que d'abandonner toute la palette.
    // Un fond sombre est refusé (le thème sombre est calculé à part).
    $c = (array) ($raw['colors'] ?? []);
    $colors = [];
    foreach (['bg', 'ink', 'accent', 'accent2'] as $k) {
        $colors[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($c[$k] ?? '')) ? strtolower($c[$k]) : null;
    }
    if (!in_array(null, $colors, true) && appearance_luminance($colors['bg']) >= 0.6) {
        $ratio = static function (string $a, string $b): float {
            $x = appearance_luminance($a) + 0.05;
            $y = appearance_luminance($b) + 0.05;
            return max($x, $y) / min($x, $y);
        };
        $darken = static function (string $color, string $bg, float $minRatio) use ($ratio): string {
            for ($i = 0; $i < 14 && $ratio($color, $bg) < $minRatio; $i++) {
                $color = appearance_mix($color, '#000000', 0.1);
            }
            return $color;
        };
        $colors['ink'] = $darken($colors['ink'], $colors['bg'], 7.0);
        $colors['accent'] = $darken($colors['accent'], $colors['bg'], 3.0);
        $colors['accent2'] = $darken($colors['accent2'], $colors['bg'], 3.0);
        $out['colors'] = $colors;
    }
    return $out;
}
