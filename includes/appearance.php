<?php

// Apparence personnalisable d'un commerce : palette de couleurs et
// typographie. Réglée depuis Administration → Apparence et enregistrée dans
// la base du commerce (table settings) ; à défaut, les valeurs de son
// tenant.php (colors, fonts), puis celles de assets/style.css.

/** Couleurs de base de assets/style.css (thème clair). */
const APPEARANCE_BASE = [
    'bg' => '#f4eee1',
    'ink' => '#2e2418',
    'accent' => '#b5502e',
    'accent-2' => '#46647a',
];

const APPEARANCE_BASE_FONTS = ['display' => 'Fraunces', 'body' => 'Archivo'];

/**
 * Polices proposées (Google Fonts) : nom => [paramètre css2, pile de repli, style].
 * Les titres utilisent « display », les textes courants « body ».
 */
const APPEARANCE_FONTS = [
    'display' => [
        'Fraunces' => ['Fraunces:opsz,wght@9..144,400;9..144,500;9..144,600;9..144,700', 'ui-serif, Georgia, serif', 'Serif chaleureuse'],
        'Playfair Display' => ['Playfair+Display:wght@400;500;600;700', 'Georgia, serif', 'Serif élégante'],
        'DM Serif Display' => ['DM+Serif+Display', 'Georgia, serif', 'Serif contrastée'],
        'Cormorant Garamond' => ['Cormorant+Garamond:wght@500;600;700', 'Garamond, Georgia, serif', 'Serif classique'],
        'Libre Baskerville' => ['Libre+Baskerville:wght@400;700', 'Baskerville, Georgia, serif', 'Serif livresque'],
        'Abril Fatface' => ['Abril+Fatface', 'Georgia, serif', 'Affiche'],
        'Bricolage Grotesque' => ['Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif atelier'],
        'Syne' => ['Syne:wght@500;600;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif graphique'],
        'Josefin Sans' => ['Josefin+Sans:wght@400;600;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif rétro'],
        'Pacifico' => ['Pacifico', 'cursive', 'Manuscrite'],
    ],
    'body' => [
        'Archivo' => ['Archivo:wght@400;500;600;700', 'ui-sans-serif, system-ui, -apple-system, sans-serif', 'Sans-serif nette'],
        'Work Sans' => ['Work+Sans:wght@400;500;600;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif douce'],
        'DM Sans' => ['DM+Sans:wght@400;500;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif moderne'],
        'Karla' => ['Karla:wght@400;500;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif compacte'],
        'Lato' => ['Lato:wght@400;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif ronde'],
        'Nunito' => ['Nunito:wght@400;600;700', 'ui-sans-serif, system-ui, sans-serif', 'Sans-serif arrondie'],
        'Lora' => ['Lora:wght@400;500;600;700', 'Georgia, serif', 'Serif de lecture'],
        'Source Serif 4' => ['Source+Serif+4:opsz,wght@8..60,400;8..60,600;8..60,700', 'Georgia, serif', 'Serif sobre'],
    ],
];

/** Palettes toutes prêtes, toutes sur fond clair : [fond, texte, principale, secondaire]. */
const APPEARANCE_PALETTES = [
    'brocante' => ['Brocante', '#f4eee1', '#2e2418', '#b5502e', '#46647a'],
    'foret' => ['Forêt', '#f3f1ea', '#1f2a22', '#2f6b4f', '#8a5a2b'],
    'ocean' => ['Océan', '#f2f5f6', '#16252e', '#1e5f8c', '#c7743a'],
    'ardoise' => ['Ardoise', '#f1f1ef', '#1d1f22', '#3b4a5a', '#b08a3e'],
    'poudre' => ['Rose poudré', '#faf1ee', '#3a2327', '#b24a62', '#5f7a6b'],
    'fournil' => ['Fournil', '#faf5ec', '#2b2118', '#b0622b', '#5b7a4a'],
    'nuit' => ['Encre', '#f6f4ee', '#121417', '#7a3cb4', '#d08a1e'],
    'olive' => ['Olive', '#f5f4e8', '#2c2f24', '#6b7f3a', '#a1552f'],
    'lavande' => ['Lavande', '#f6f3fa', '#2f2740', '#6c5b9e', '#b07d55'],
    'citron' => ['Citron vert', '#f8f7e9', '#2a2d1f', '#8a9a2e', '#d0712c'],
    'brique' => ['Brique & lin', '#f8efe9', '#33231d', '#a34a2a', '#5d6f56'],
    'menthe' => ['Menthe', '#eef7f2', '#1e3029', '#2f7d63', '#c07a3d'],
    'sable' => ['Sable', '#f7f1e5', '#2b2620', '#8a6a3b', '#3f6a6a'],
    'bleuet' => ['Bleuet', '#eff3f9', '#1e2a3a', '#3a6ea8', '#c25b3a'],
    'prune' => ['Prune', '#f7f1f4', '#322430', '#7d3d5c', '#4a6b5a'],
];

/** Thème clair/sombre : « auto » suit le réglage de l'appareil. */
const APPEARANCE_THEMES = ['auto' => 'Automatique (appareil)', 'light' => 'Clair', 'dark' => 'Sombre'];

function appearance_theme_valid(?string $key): bool
{
    return $key !== null && isset(APPEARANCE_THEMES[$key]);
}

function appearance_theme(): string
{
    $saved = appearance_saved();
    $theme = (string) ($saved['theme'] ?? 'auto');
    return appearance_theme_valid($theme) ? $theme : 'auto';
}

function appearance_color(string $value, string $fallback): string
{
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : $fallback;
}

/** Mélange deux couleurs #rrggbb (t = 0 → a, t = 1 → b). */
function appearance_mix(string $a, string $b, float $t): string
{
    $ca = sscanf($a, '#%02x%02x%02x');
    $cb = sscanf($b, '#%02x%02x%02x');
    $out = '#';
    for ($i = 0; $i < 3; $i++) {
        $out .= sprintf('%02x', (int) round($ca[$i] + ($cb[$i] - $ca[$i]) * $t));
    }
    return $out;
}

/** Luminance relative (WCAG) d'une couleur #rrggbb. */
function appearance_luminance(string $hex): float
{
    $c = array_map(static function ($v) {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, sscanf($hex, '#%02x%02x%02x'));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}

/** Texte lisible (clair ou foncé) sur une couleur de fond. */
function appearance_on(string $hex): string
{
    return appearance_luminance($hex) > 0.4 ? '#1d1a16' : '#fffaf2';
}

/**
 * Toutes les variables CSS du site à partir de 4 couleurs, pour le thème
 * clair et le thème sombre. Retourne ['light' => [...], 'dark' => [...]].
 */
function appearance_derive(array $c): array
{
    $bg = appearance_color($c['bg'] ?? '', APPEARANCE_BASE['bg']);
    $ink = appearance_color($c['ink'] ?? '', APPEARANCE_BASE['ink']);
    $accent = appearance_color($c['accent'] ?? '', APPEARANCE_BASE['accent']);
    $accent2 = appearance_color($c['accent-2'] ?? '', APPEARANCE_BASE['accent-2']);

    $darkBg = appearance_mix($ink, '#000000', 0.35);
    $darkInk = appearance_mix($bg, '#ffffff', 0.15);
    $darkAccent = appearance_mix($accent, '#ffffff', 0.3);
    $darkAccent2 = appearance_mix($accent2, '#ffffff', 0.35);

    return [
        'light' => [
            'bg' => $bg,
            'surface' => appearance_mix($bg, $ink, 0.06),
            'surface-2' => appearance_mix($bg, $ink, 0.12),
            'ink' => $ink,
            'ink-soft' => appearance_mix($ink, $bg, 0.38),
            'accent' => $accent,
            'accent-ink' => appearance_on($accent),
            'accent-2' => $accent2,
            'sage' => $accent2,
            'line' => appearance_mix($bg, $ink, 0.25),
        ],
        'dark' => [
            'bg' => $darkBg,
            'surface' => appearance_mix($darkBg, $darkInk, 0.06),
            'surface-2' => appearance_mix($darkBg, $darkInk, 0.11),
            'ink' => $darkInk,
            'ink-soft' => appearance_mix($darkInk, $darkBg, 0.3),
            'accent' => $darkAccent,
            'accent-ink' => appearance_on($darkAccent),
            'accent-2' => $darkAccent2,
            'sage' => $darkAccent2,
            'line' => appearance_mix($darkBg, $darkInk, 0.22),
        ],
    ];
}

/**
 * Feuille de style des polices du catalogue, hébergée en local (aucun appel à Google Fonts :
 * pas d'IP transmise à un tiers). Les @font-face ne téléchargent que les polices réellement
 * utilisées par la page ; les paramètres sont conservés pour ne pas changer les appelants.
 * Pour régénérer les fichiers : python3 scripts/fetch-fonts.py
 */
function appearance_fonts_url(string $display = '', string $body = ''): string
{
    return '/assets/fonts.css';
}

/** Valeur CSS font-family d'une police du catalogue. */
function appearance_font_stack(string $role, string $name): string
{
    $font = APPEARANCE_FONTS[$role][$name] ?? null;
    return $font ? '"' . $name . '", ' . $font[1] : '';
}

/* ---------- enregistrement dans la base du commerce ---------- */

function appearance_table(): void
{
    db()->exec('CREATE TABLE IF NOT EXISTS settings (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
}

/** Apparence enregistrée depuis l'administration, ou null. */
function appearance_saved(): ?array
{
    static $saved = false;
    if ($saved === false) {
        $saved = null;
        try {
            appearance_table();
            $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'appearance'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            $saved = $value ? (json_decode($value, true) ?: null) : null;
        } catch (Throwable $e) {
            $saved = null;
        }
    }
    return $saved;
}

function appearance_save(array $colors, string $display, string $body, ?string $theme = null, ?string $hero = null): void
{
    $saved = appearance_saved();
    $data = [
        'colors' => [
            'bg' => appearance_color($colors['bg'] ?? '', APPEARANCE_BASE['bg']),
            'ink' => appearance_color($colors['ink'] ?? '', APPEARANCE_BASE['ink']),
            'accent' => appearance_color($colors['accent'] ?? '', APPEARANCE_BASE['accent']),
            'accent-2' => appearance_color($colors['accent-2'] ?? '', APPEARANCE_BASE['accent-2']),
        ],
        'fonts' => [
            'display' => isset(APPEARANCE_FONTS['display'][$display]) ? $display : APPEARANCE_BASE_FONTS['display'],
            'body' => isset(APPEARANCE_FONTS['body'][$body]) ? $body : APPEARANCE_BASE_FONTS['body'],
        ],
    ];
    // Le modèle de mise en page choisi (Apparence → Modèle) survit à un changement de palette ou de polices.
    $template = (string) ($saved['template'] ?? '');
    if (shop_template_valid($template)) {
        $data['template'] = $template;
        // Une palette personnalisée n'a pas de variante sombre dessinée : elle est recalculée automatiquement.
        if (!empty(SHOP_TEMPLATES[$template]['dark']) && $data['colors'] === SHOP_TEMPLATES[$template]['colors']) {
            $data['dark'] = SHOP_TEMPLATES[$template]['dark'];
        }
    }
    $data['theme'] = appearance_theme_valid($theme) ? $theme : (string) ($saved['theme'] ?? 'auto');
    $hero = $hero !== null ? $hero : (string) ($saved['hero'] ?? '');
    if ($hero === '' || shop_hero_valid($hero)) $data['hero'] = $hero;
    appearance_table();
    db()->prepare("INSERT INTO settings (name, value) VALUES ('appearance', ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value")
        ->execute([json_encode($data, JSON_UNESCAPED_SLASHES)]);
}

/**
 * Choisit le modèle de mise en page du commerce (voir includes/templates.php). $withStyle : adopte aussi la palette et les polices du modèle ;
 * sinon l'apparence actuelle (palette, polices) est conservée telle quelle.
 */
function appearance_save_template(string $key, bool $withStyle): void
{
    if (!shop_template_valid($key)) throw new InvalidArgumentException('Modèle inconnu.');
    $cur = appearance_current();
    $colors = $withStyle ? SHOP_TEMPLATES[$key]['colors'] : $cur['colors'];
    $fonts = $withStyle ? SHOP_TEMPLATES[$key]['fonts'] : $cur['fonts'];
    $data = ['colors' => $colors, 'fonts' => $fonts, 'template' => $key, 'theme' => $cur['theme']];
    if ($withStyle && !empty(SHOP_TEMPLATES[$key]['dark'])) $data['dark'] = SHOP_TEMPLATES[$key]['dark'];
    $data['hero'] = shop_template_hero($key);
    appearance_table();
    db()->prepare("INSERT INTO settings (name, value) VALUES ('appearance', ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value")
        ->execute([json_encode($data, JSON_UNESCAPED_SLASHES)]);
}

function appearance_reset(): void
{
    appearance_table();
    db()->exec("DELETE FROM settings WHERE name = 'appearance'");
}

/**
 * Apparence en vigueur : ['colors' => 4 couleurs, 'fonts' => [display, body],
 * 'custom' => réglée depuis l'administration ?].
 */
function appearance_current(): array
{
    $saved = appearance_saved();
    $tenantColors = tenant('colors', []);
    $tenantFonts = tenant('fonts', []);
    $colors = [];
    foreach (APPEARANCE_BASE as $key => $base) {
        $colors[$key] = appearance_color((string) ($saved['colors'][$key] ?? $tenantColors[$key] ?? ''), $base);
    }
    $theme = (string) ($saved['theme'] ?? 'auto');
    $hero = (string) ($saved['hero'] ?? '');
    return [
        'colors' => $colors,
        'fonts' => [
            'display' => $saved['fonts']['display'] ?? $tenantFonts['display'] ?? APPEARANCE_BASE_FONTS['display'],
            'body' => $saved['fonts']['body'] ?? $tenantFonts['body'] ?? APPEARANCE_BASE_FONTS['body'],
        ],
        'theme' => appearance_theme_valid($theme) ? $theme : 'auto',
        'hero' => shop_hero_valid($hero) ? $hero : '',
        'template' => shop_template_valid((string) ($saved['template'] ?? '')) ? (string) $saved['template'] : '',
        'dark' => is_array($saved['dark'] ?? null) ? $saved['dark'] : null,
        'custom' => $saved !== null,
    ];
}

/* ---------- logos ---------- */

/**
 * Déclinaisons du logo : clé => [titre, usage, format conseillé].
 * Enregistrées dans la base du commerce (settings « logos »), sinon valeurs
 * du tenant.php (logo, logo_macaron).
 */
const LOGO_VARIANTS = [
    'horizontal' => ['Horizontal', "En-tête du site et barre d'administration", 'Environ 4 × 1, par ex. 800 × 200 px'],
    'vertical' => ['Vertical', "Écran d'accueil sur mobile", 'Environ 3 × 4, par ex. 600 × 800 px'],
    'square' => ['Carré', "Macaron de l'accueil, menu replié, icône d'onglet", '1 × 1, par ex. 512 × 512 px'],
];

/** Logos réglés dans l'administration : ['horizontal' => 'uploads/…', …]. */
function logos_saved(): array
{
    static $logos = null;
    if ($logos === null) {
        $logos = [];
        try {
            appearance_table();
            $stmt = db()->prepare("SELECT value FROM settings WHERE name = 'logos'");
            $stmt->execute();
            $logos = json_decode((string) $stmt->fetchColumn(), true) ?: [];
        } catch (Throwable $e) {
            $logos = [];
        }
    }
    return $logos;
}

/** Chemin (relatif à la racine web) du logo à utiliser pour une déclinaison. */
function logo_url(string $variant): string
{
    $saved = logos_saved();
    $horizontal = $saved['horizontal'] ?? (string) tenant('logo');
    $square = $saved['square'] ?? (string) tenant('logo_macaron');
    return match ($variant) {
        'square' => $square ?: $horizontal,
        'vertical' => $saved['vertical'] ?? ($square ?: $horizontal),
        default => $horizontal,
    };
}

/** Balise <link rel="icon"> avec le logo carré. */
function logo_favicon_html(): string
{
    $url = logo_url('square');
    return $url !== '' ? '<link rel="icon" href="/' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . "\n" : '';
}

/**
 * Enregistre un logo envoyé ($_FILES[$field]) pour une déclinaison.
 * Formats : PNG, JPG, WebP, SVG (sans script). Retourne le chemin enregistré.
 */
function logo_store_upload(string $variant, array $file): string
{
    if (!isset(LOGO_VARIANTS[$variant])) {
        throw new InvalidArgumentException('Déclinaison de logo inconnue.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("L'envoi du fichier a échoué, réessayez.");
    }
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new InvalidArgumentException('Logo trop lourd : 3 Mo maximum.');
    }
    $mime = mime_content_type($file['tmp_name']) ?: '';
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg', 'image/svg' => 'svg'][$mime] ?? null;
    if (!$ext && str_ends_with(strtolower((string) ($file['name'] ?? '')), '.svg') && str_contains((string) file_get_contents($file['tmp_name'], false, null, 0, 2000), '<svg')) {
        $ext = 'svg';
    }
    if (!$ext) {
        throw new InvalidArgumentException('Formats acceptés : PNG, JPG, WebP ou SVG.');
    }
    if ($ext === 'svg') {
        $svg = (string) file_get_contents($file['tmp_name']);
        if (preg_match('/<script|<foreignObject|\son\w+\s*=|javascript:|<!ENTITY/i', $svg)) {
            throw new InvalidArgumentException('Ce SVG contient du code actif : exportez-le à nouveau sans script, ou utilisez un PNG.');
        }
    }
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'logo-' . tenant_slug() . '-' . $variant . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        throw new RuntimeException("Impossible d'enregistrer le fichier dans uploads/.");
    }
    return 'uploads/' . $name;
}

/**
 * Reprend un visuel de la médiathèque comme logo d'une déclinaison. Le fichier
 * est copié dans uploads/logo-… : le logo garde ainsi son propre cycle de vie,
 * indépendant de la médiathèque.
 */
function logo_store_media(string $variant, string $relPath): string
{
    if (!isset(LOGO_VARIANTS[$variant])) {
        throw new InvalidArgumentException('Déclinaison de logo inconnue.');
    }
    $relPath = ltrim(trim($relPath), '/');
    $stmt = db()->prepare('SELECT type FROM media_library WHERE path = ? LIMIT 1');
    $stmt->execute([$relPath]);
    $type = $stmt->fetchColumn();
    if ($type === false) {
        throw new InvalidArgumentException('Ce visuel ne figure pas dans la médiathèque : rechargez la page et réessayez.');
    }
    if ((string) $type !== 'photo') {
        throw new InvalidArgumentException('Choisissez une image : une vidéo ne peut pas servir de logo.');
    }
    $abs = dirname(__DIR__) . '/' . $relPath;
    if (!preg_match('#^uploads/[A-Za-z0-9/_.-]+\.(png|jpe?g|webp)$#i', $relPath) || !is_file($abs)) {
        throw new InvalidArgumentException('Le fichier de ce visuel est introuvable.');
    }
    if (filesize($abs) > 3 * 1024 * 1024) {
        throw new InvalidArgumentException('Visuel trop lourd pour un logo : 3 Mo maximum.');
    }
    $info = @getimagesize($abs);
    $ext = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) {
        throw new InvalidArgumentException('Formats acceptés depuis la médiathèque : PNG, JPG ou WebP.');
    }
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = 'logo-' . tenant_slug() . '-' . $variant . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!@copy($abs, "$dir/$name")) {
        throw new RuntimeException("Impossible de copier ce visuel dans uploads/.");
    }
    return 'uploads/' . $name;
}

/**
 * Agrandit ×2 le fichier du logo enregistré (jusqu'à 4 096 px de côté), sans
 * IA ni crédit : les pixels sont interpolés, le piqué d'origine est conservé.
 * -> ['path', 'width', 'height', 'from_width', 'from_height'].
 */
function logo_enlarge(string $variant): array
{
    if (!isset(LOGO_VARIANTS[$variant])) {
        throw new InvalidArgumentException('Déclinaison de logo inconnue.');
    }
    $src = logo_url($variant);
    $abs = dirname(__DIR__) . '/' . ltrim($src, '/');
    if ($src === '' || !is_file($abs)) {
        throw new RuntimeException('Aucun logo à agrandir pour cette déclinaison.');
    }
    if (str_ends_with(strtolower($abs), '.svg')) {
        throw new RuntimeException('Un logo SVG est vectoriel : il s’agrandit déjà sans perte. Gardez-le tel quel, ou importez un PNG/JPG pour créer une version agrandie.');
    }
    $info = @getimagesize($abs);
    if (!$info || !in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Ce logo n’est pas une image PNG, JPG ou WebP : il ne peut pas être agrandi ici.');
    }
    $img = @imagecreatefromstring((string) @file_get_contents($abs));
    if (!$img) {
        throw new RuntimeException('Ce logo n’a pas pu être lu : réimportez-le, ou utilisez « Améliorer le logo » avec l’IA.');
    }
    $w = imagesx($img);
    $h = imagesy($img);
    if (max($w, $h) >= 4096) {
        throw new RuntimeException('Ce logo mesure déjà ' . $w . ' × ' . $h . ' px : il est assez grand pour l’impression.');
    }
    $scale = min(2, 4096 / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = 'uploads/logo-' . tenant_slug() . '-' . $variant . '-' . bin2hex(random_bytes(4)) . '.png';
    if (!imagepng($dst, dirname(__DIR__) . '/' . $path, 9)) {
        throw new RuntimeException('Impossible d’enregistrer le logo agrandi.');
    }
    $logos = logos_saved();
    $logos[$variant] = $path;
    logos_save($logos);
    return ['path' => $path, 'width' => $nw, 'height' => $nh, 'from_width' => $w, 'from_height' => $h];
}

/** Enregistre la liste des logos (chemins ou null pour revenir au logo par défaut). */
function logos_save(array $logos): void
{
    $clean = [];
    foreach (LOGO_VARIANTS as $variant => $_) {
        if (!empty($logos[$variant]) && preg_match('#^uploads/logo-[a-z0-9_-]+\.(png|jpg|webp|svg)$#', $logos[$variant])) {
            $clean[$variant] = $logos[$variant];
        }
    }
    appearance_table();
    db()->prepare("INSERT INTO settings (name, value) VALUES ('logos', ?)
                   ON CONFLICT(name) DO UPDATE SET value = excluded.value")
        ->execute([json_encode($clean, JSON_UNESCAPED_SLASHES)]);
}

/** Convertit une image générée en PNG à fond transparent (le blanc est détouré). -> octets PNG. */
function logo_transparent_png_bytes(string $bytes, int $maxDim = 1024): ?string
{
    $src = @imagecreatefromstring($bytes);
    if (!$src) return null;
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $maxDim / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $img = imagecreatetruecolor($nw, $nh);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagecopyresampled($img, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    // Le modèle est prié de poser le visuel sur un blanc pur : ce blanc (et lui
    // seul) devient transparent. Les teintes claires mais colorées de la palette
    // sont conservées grâce au test de saturation.
    for ($y = 0; $y < $nh; $y++) {
        for ($x = 0; $x < $nw; $x++) {
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 255;
            $g = ($c >> 8) & 255;
            $b = $c & 255;
            $fromWhite = max(255 - $r, 255 - $g, 255 - $b);
            if ($fromWhite >= 36) continue;
            if (max($r, $g, $b) - min($r, $g, $b) >= 16) continue;
            $alpha = $fromWhite <= 12 ? 127 : (int) round(127 * (1 - ($fromWhite - 12) / 24));
            imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $r, $g, $b, $alpha));
        }
    }

    ob_start();
    $ok = imagepng($img, null, 9);
    $png = (string) ob_get_clean();
    return $ok && $png !== '' ? $png : null;
}

/** Enregistre une image générée en PNG transparent : le fond blanc devient transparent. -> chemin. */
function logo_save_transparent_png(string $bytes, string $variant, int $maxDim = 1000): ?string
{
    if (!isset(LOGO_VARIANTS[$variant])) return null;
    $png = logo_transparent_png_bytes($bytes, $maxDim);
    if ($png === null) return null;
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = 'uploads/logo-' . tenant_slug() . '-' . $variant . '-' . bin2hex(random_bytes(4)) . '.png';
    return @file_put_contents(dirname(__DIR__) . '/' . $name, $png, LOCK_EX) !== false ? $name : null;
}

/** Consigne de palette pour les logos générés : couleurs du site, fond blanc à détourer. */
function logo_palette_prompt(): string
{
    $c = appearance_current()['colors'];
    return 'Palette imposée, celle du site : couleur principale ' . $c['accent'] . ', secondaire ' . $c['accent-2'] . ', texte ' . $c['ink'] . '. '
        . 'Fond blanc pur (#ffffff), sans damier, sans ombre, sans cadre : il sera rendu transparent. '
        . 'Use only the site palette (accent ' . $c['accent'] . ', secondary ' . $c['accent-2'] . ', text ' . $c['ink'] . ') on a pure white background '
        . '(#ffffff), with no checkerboard, no shadow and no frame — the background will be made transparent.';
}

/* ---------- médiathèque partagée des symboles (tous les sites) ---------- */

const LOGO_LIBRARY_MAX = 120;

function logo_library_dir(): string
{
    return dirname(__DIR__) . '/uploads/logo-library';
}

function logo_library_index_file(): string
{
    return dirname(__DIR__) . '/data/logo-library.json';
}

/**
 * Symboles de la médiathèque, du plus récent au plus ancien. Par défaut, seuls
 * les symboles encore libres : un logo ne peut servir qu'à une seule boutique.
 * $includeUsed pour relire tout l'index (écriture de l'index, plafond).
 */
function logo_library_list(bool $includeUsed = false): array
{
    $file = logo_library_index_file();
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) return [];
    return array_values(array_filter($data, static fn ($item): bool => is_array($item)
        && preg_match('/^symbol-[a-f0-9]{8}\.png$/', (string) ($item['file'] ?? ''))
        && ($includeUsed || empty($item['used']))
        && is_file(logo_library_dir() . '/' . $item['file'])));
}

/** Chemin absolu d'un symbole de la médiathèque (null si nom invalide ou fichier absent). */
function logo_library_abs(string $file): ?string
{
    if (!preg_match('/^symbol-[a-f0-9]{8}\.png$/', $file)) return null;
    $abs = logo_library_dir() . '/' . $file;
    return is_file($abs) ? $abs : null;
}

/**
 * Réserve un symbole pour la boutique courante : un logo ne peut être utilisé
 * qu'une seule fois. -> false si le symbole est déjà pris ou introuvable.
 */
function logo_library_claim(string $file): bool
{
    $list = logo_library_list(true);
    $claimed = false;
    foreach ($list as &$item) {
        if ((string) ($item['file'] ?? '') !== $file) continue;
        if (!empty($item['used'])) break;
        $item['used'] = true;
        $item['used_by'] = tenant_slug();
        $item['used_at'] = gmdate('c');
        $claimed = true;
        break;
    }
    unset($item);
    if (!$claimed) return false;
    @file_put_contents(logo_library_index_file(), json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return true;
}

/** Range un symbole (PNG transparent) dans la médiathèque commune. -> nom de fichier. */
function logo_library_store(string $pngBytes, string $prompt): ?string
{
    $dir = logo_library_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    $file = 'symbol-' . bin2hex(random_bytes(4)) . '.png';
    if (@file_put_contents($dir . '/' . $file, $pngBytes, LOCK_EX) === false) return null;

    $list = logo_library_list(true);
    array_unshift($list, ['file' => $file, 'prompt' => mb_substr($prompt, 0, 500), 'site' => tenant_slug(), 'at' => gmdate('c')]);
    while (count($list) > LOGO_LIBRARY_MAX) {
        $old = array_pop($list);
        if ($old) @unlink($dir . '/' . (string) ($old['file'] ?? ''));
    }
    $indexDir = dirname(__DIR__) . '/data';
    if (!is_dir($indexDir)) @mkdir($indexDir, 0775, true);
    @file_put_contents(logo_library_index_file(), json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $file;
}

/* ---------- composition symbole + nom ---------- */

/** Police qui écrit le nom à part du symbole. */
function logo_font_path(): string
{
    return dirname(__DIR__) . '/assets/fonts/Poppins-SemiBold.ttf';
}

/** Largeur et hauteur d'une ligne de texte. -> [largeur, hauteur] */
function logo_text_size(int $size, string $text, string $font): array
{
    $bb = imagettfbbox($size, 0, $font, $text);
    return [abs($bb[2] - $bb[0]), abs($bb[1] - $bb[7])];
}

/**
 * Compose un logo à partir du symbole (graphique seul) et du nom de la
 * boutique écrit en typographie : les trois déclinaisons partagent ainsi le
 * même symbole, et le nom reste modifiable sans régénérer l'image.
 * -> chemin du PNG transparent.
 */
function logo_compose_symbol(string $symbolAbs, string $variant): string
{
    if (!isset(LOGO_VARIANTS[$variant])) throw new RuntimeException('Déclinaison de logo inconnue.');
    $bytes = @file_get_contents($symbolAbs);
    $src = $bytes !== false ? @imagecreatefromstring($bytes) : null;
    if (!$src) throw new RuntimeException('Symbole illisible.');
    $cropped = @imagecropauto($src, IMG_CROP_TRANSPARENT);
    if ($cropped !== false) $src = $cropped;

    [$W, $H] = ['horizontal' => [1600, 400], 'vertical' => [900, 1200], 'square' => [1000, 1000]][$variant];
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, false);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));

    $ink = sscanf(appearance_current()['colors']['ink'], '#%02x%02x%02x');
    $inkColor = imagecolorallocate($img, (int) $ink[0], (int) $ink[1], (int) $ink[2]);
    $font = logo_font_path();
    $name = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name');
    $withText = $variant !== 'square' && $name !== '' && function_exists('imagettftext') && is_file($font);
    $sw = imagesx($src);
    $sh = imagesy($src);

    if ($variant === 'square') {
        $k = min(($W - 100) / $sw, ($H - 100) / $sh);
        $dw = max(1, (int) round($sw * $k));
        $dh = max(1, (int) round($sh * $k));
        imagecopyresampled($img, $src, (int) (($W - $dw) / 2), (int) (($H - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
    } elseif ($variant === 'horizontal') {
        $k = min(($H - 80) / $sh, (int) ($W * 0.42) / $sw);
        $dw = max(1, (int) round($sw * $k));
        $dh = max(1, (int) round($sh * $k));
        $sx = 40;
        imagecopyresampled($img, $src, $sx, (int) (($H - $dh) / 2), 0, 0, $dw, $dh, $sw, $sh);
        if ($withText) {
            $avail = $W - $sx - $dw - 56 - 40;
            $size = 130;
            while ($size > 36 && logo_text_size($size, $name, $font)[0] > $avail) $size -= 4;
            $bb = imagettfbbox($size, 0, $font, $name);
            $th = abs($bb[1] - $bb[7]);
            imagealphablending($img, true);
            imagettftext($img, $size, 0, (int) ($sx + $dw + 56 - $bb[0]), (int) (($H - $th) / 2 - $bb[7]), $inkColor, $font, $name);
        }
    } else {
        $k = min(560 / $sw, 560 / $sh);
        $dw = max(1, (int) round($sw * $k));
        $dh = max(1, (int) round($sh * $k));
        $sy = 90;
        $sx = (int) (($W - $dw) / 2);
        imagecopyresampled($img, $src, $sx, $sy, 0, 0, $dw, $dh, $sw, $sh);
        if ($withText) {
            $avail = $W - 120;
            $size = 92;
            $lines = [$name];
            if (logo_text_size($size, $name, $font)[0] > $avail) {
                $words = preg_split('/\s+/u', $name) ?: [$name];
                if (count($words) > 1) {
                    $best = null;
                    for ($i = 1; $i < count($words); $i++) {
                        $a = implode(' ', array_slice($words, 0, $i));
                        $b = implode(' ', array_slice($words, $i));
                        $m = max(logo_text_size($size, $a, $font)[0], logo_text_size($size, $b, $font)[0]);
                        if ($best === null || $m < $best[0]) $best = [$m, $a, $b];
                    }
                    $lines = [$best[1], $best[2]];
                }
                while ($size > 36 && max(logo_text_size($size, $lines[0], $font)[0], logo_text_size($size, (string) ($lines[1] ?? ''), $font)[0]) > $avail) $size -= 4;
            }
            $lineH = (int) round($size * 1.2);
            $blockH = $lineH * count($lines);
            $areaTop = $sy + $dh + 40;
            $top = (int) ($areaTop + max(0, (($H - 70) - $areaTop - $blockH) / 2));
            imagealphablending($img, true);
            foreach ($lines as $i => $line) {
                $bb = imagettfbbox($size, 0, $font, $line);
                $lw = abs($bb[2] - $bb[0]);
                $lh = abs($bb[1] - $bb[7]);
                $x = (int) (($W - $lw) / 2 - $bb[0]);
                $y = (int) ($top + $i * $lineH + ($lineH - $lh) / 2 - $bb[7]);
                imagettftext($img, $size, 0, $x, $y, $inkColor, $font, $line);
            }
        }
    }

    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = 'uploads/logo-' . tenant_slug() . '-' . $variant . '-' . bin2hex(random_bytes(4)) . '.png';
    if (!imagepng($img, dirname(__DIR__) . '/' . $file, 9)) throw new RuntimeException('Impossible d’enregistrer le logo composé.');
    return $file;
}

/** Compose et enregistre les trois déclinaisons à partir d'un symbole. -> ['paths', 'failed'] */
function logo_compose_all(string $symbolAbs): array
{
    $paths = [];
    $failed = [];
    $logos = logos_saved();
    foreach (array_keys(LOGO_VARIANTS) as $variant) {
        try {
            $path = logo_compose_symbol($symbolAbs, $variant);
            $logos[$variant] = $path;
            $paths[$variant] = $path;
        } catch (Throwable $e) {
            $failed[] = LOGO_VARIANTS[$variant][0];
        }
    }
    if ($paths) logos_save($logos);
    return ['paths' => $paths, 'failed' => $failed];
}

/** Améliore le logo enregistré d'une déclinaison (image → image) et le remplace. -> chemin. */
function logo_improve_with_ai(string $variant, string $prompt): string
{
    if (!isset(LOGO_VARIANTS[$variant])) throw new RuntimeException('Déclinaison de logo inconnue.');
    if (GEMINI_API_KEY === '') throw new RuntimeException('La clé Gemini n’est pas configurée.');
    if ($gate = ai_gate()) throw new RuntimeException($gate);

    $src = logo_url($variant);
    $abs = dirname(__DIR__) . '/' . ltrim($src, '/');
    if ($src === '' || !is_file($abs)) {
        throw new RuntimeException('Aucun logo à améliorer pour cette déclinaison : importez un fichier ou générez un logo.');
    }
    if (str_ends_with(strtolower($abs), '.svg')) {
        throw new RuntimeException('Ce logo est un SVG : l’amélioration IA demande une image PNG ou JPG. Importez une version PNG, ou générez un nouveau logo.');
    }

    $instruction = trim(strip_tags($prompt));
    $bytes = gemini_generate_image($abs, 'Améliore ce logo de boutique sans changer son identité : garde la même composition et le même symbole. '
        . 'Supprime tout slogan, baseline ou ligne de texte secondaire : ne garde que le nom de la boutique, exactement tel qu’écrit. '
        . 'Recadre pour que le logo remplisse le cadre : symbole et nom occupent au moins 80 % de la zone, avec une petite marge régulière de 4 à 6 % seulement, sans grande zone blanche. '
        . 'Rends-le plus net, plus équilibré et plus lisible, avec des aplats propres. '
        . logo_palette_prompt() . ' '
        . ($instruction !== '' ? 'Consigne du commerçant : ' . mb_substr($instruction, 0, 300) . '. ' : '')
        . 'Style vectoriel plat, centré. Pas de maquette, pas de photo, pas de filigrane. '
        . 'Flat vector-style logo filling the frame with only a small even margin; render only the shop name as text, with no slogan or tagline.',
        1,
        ['horizontal' => '16:9', 'vertical' => '3:4', 'square' => '1:1'][$variant]);
    if ($bytes === null) {
        throw new RuntimeException('L’amélioration a échoué (clé refusée, crédit épuisé ou quota) : rien n’a été débité. Vérifiez la clé Gemini dans les réglages.');
    }

    $path = logo_save_transparent_png($bytes, $variant);
    if ($path === null) throw new RuntimeException('Impossible d’enregistrer le logo amélioré.');
    $logos = logos_saved();
    $logos[$variant] = $path;
    logos_save($logos);
    return $path;
}
