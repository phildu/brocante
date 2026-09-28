<?php

// Portail local de la marque blanche : liste des commerces, choix du commerce
// affiché, création d'un commerce. Il écrit dans le dossier du projet, il
// n'est donc accessible que depuis la machine elle-même (Herd, php -S) et
// n'est jamais déployé (exclu dans deploy-brocante.sh).

$host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    && ($host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.test'));
if (!$local) {
    http_response_code(403);
    exit('Portail disponible uniquement en local (Herd ou php -S).');
}

session_start();
require_once __DIR__ . '/../includes/tenant.php';
require_once __DIR__ . '/../includes/seed.php';

const PORTAIL_ROOT = __DIR__ . '/..';

function e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
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
        'ai' => [
            'shop' => "la boutique en ligne de $name",
            'item' => "un $item1",
            'examples' => implode(', ', array_map(fn ($c) => mb_strtolower($c['label']), $categories)),
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
