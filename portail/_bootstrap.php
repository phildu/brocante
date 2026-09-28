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

/** Mélange deux couleurs #rrggbb (t = 0 → a, t = 1 → b). */
function mix_color(string $a, string $b, float $t): string
{
    $ca = sscanf($a, '#%02x%02x%02x');
    $cb = sscanf($b, '#%02x%02x%02x');
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t));
    }
    return $out;
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

    $item1 = trim((string) ($f['item1'] ?? '')) ?: 'article';
    $item2 = trim((string) ($f['item2'] ?? '')) ?: 'articles';
    $accent = valid_color((string) ($f['accent'] ?? ''), '#1e5f8c');
    $accent2 = valid_color((string) ($f['accent2'] ?? ''), '#6a6f3a');
    $bg = valid_color((string) ($f['bg'] ?? ''), '#f6f5f1');
    $ink = '#2b2620';

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
        'admin_password' => trim((string) ($f['admin_password'] ?? '')),
        'logo' => $logo,
        'logo_macaron' => $logo,
        'colors' => [
            'bg' => $bg,
            'surface' => mix_color($bg, $ink, 0.06),
            'surface-2' => mix_color($bg, $ink, 0.11),
            'line' => mix_color($bg, $ink, 0.24),
            'accent' => $accent,
            'accent-2' => $accent2,
            'sage' => $accent2,
        ],
        'colors_dark' => [
            'accent' => mix_color($accent, '#ffffff', 0.35),
            'accent-2' => mix_color($accent2, '#ffffff', 0.35),
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
