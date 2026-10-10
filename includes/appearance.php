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

/** Palettes toutes prêtes : [fond, texte, principale, secondaire]. */
const APPEARANCE_PALETTES = [
    'brocante' => ['Brocante', '#f4eee1', '#2e2418', '#b5502e', '#46647a'],
    'foret' => ['Forêt', '#f3f1ea', '#1f2a22', '#2f6b4f', '#8a5a2b'],
    'ocean' => ['Océan', '#f2f5f6', '#16252e', '#1e5f8c', '#c7743a'],
    'ardoise' => ['Ardoise', '#f1f1ef', '#1d1f22', '#3b4a5a', '#b08a3e'],
    'poudre' => ['Rose poudré', '#faf1ee', '#3a2327', '#b24a62', '#5f7a6b'],
    'fournil' => ['Fournil', '#faf5ec', '#2b2118', '#b0622b', '#5b7a4a'],
    'nuit' => ['Encre', '#f6f4ee', '#121417', '#7a3cb4', '#d08a1e'],
];

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

/** Adresse Google Fonts pour une paire de polices (plus la police machine à écrire du site). */
function appearance_fonts_url(string $display, string $body): string
{
    $families = [
        APPEARANCE_FONTS['display'][$display][0] ?? APPEARANCE_FONTS['display']['Fraunces'][0],
        APPEARANCE_FONTS['body'][$body][0] ?? APPEARANCE_FONTS['body']['Archivo'][0],
        'Special+Elite',
    ];
    return 'https://fonts.googleapis.com/css2?family=' . implode('&family=', array_unique($families)) . '&display=swap';
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

function appearance_save(array $colors, string $display, string $body): void
{
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
    if (!empty(appearance_saved()['template'])) $data['template'] = appearance_saved()['template'];
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
    $data = ['colors' => $colors, 'fonts' => $fonts, 'template' => $key];
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
    return [
        'colors' => $colors,
        'fonts' => [
            'display' => $saved['fonts']['display'] ?? $tenantFonts['display'] ?? APPEARANCE_BASE_FONTS['display'],
            'body' => $saved['fonts']['body'] ?? $tenantFonts['body'] ?? APPEARANCE_BASE_FONTS['body'],
        ],
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
