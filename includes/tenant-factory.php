<?php

// Création d'une boutique (tenants/<slug>/ : tenant.php, seed-data.json, logo, base) à partir d'un formulaire.
// Partagé par le portail (portail/nouveau.php, portail/inscriptions.php) et par la génération automatique après paiement
// (inscription/merci.php) : il ne dépend d'aucune connexion au portail.

require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/seed.php';

if (!defined('PORTAIL_ROOT')) {
    define('PORTAIL_ROOT', dirname(__DIR__));
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
    // Modèle de design (includes/templates.php) : sa palette et ses polices sont les valeurs par défaut du formulaire.
    $template = shop_template_valid($f['template'] ?? null) ? (string) $f['template'] : '';
    $tplColors = $template !== '' ? SHOP_TEMPLATES[$template]['colors'] : [];
    $tplFonts = $template !== '' ? SHOP_TEMPLATES[$template]['fonts'] : [];
    $accent = valid_color((string) ($f['accent'] ?? ''), $tplColors['accent'] ?? '#1e5f8c');
    $accent2 = valid_color((string) ($f['accent2'] ?? ''), $tplColors['accent-2'] ?? '#6a6f3a');
    $bg = valid_color((string) ($f['bg'] ?? ''), $tplColors['bg'] ?? '#f6f5f1');
    $ink = valid_color((string) ($f['ink'] ?? ''), $tplColors['ink'] ?? '#2b2620');
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
        // Modèle de design : sa feuille de style s'applique à la boutique (voir tenant_head_html()).
        'template' => $template !== '' ? $template : SHOP_TEMPLATE_DEFAULT,
        'fonts' => [
            'display' => isset(APPEARANCE_FONTS['display'][$f['font_display'] ?? '']) ? $f['font_display'] : ($tplFonts['display'] ?? APPEARANCE_BASE_FONTS['display']),
            'body' => isset(APPEARANCE_FONTS['body'][$f['font_body'] ?? '']) ? $f['font_body'] : ($tplFonts['body'] ?? APPEARANCE_BASE_FONTS['body']),
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
