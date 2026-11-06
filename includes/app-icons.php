<?php
// Icônes des applications installables (boutique, Studio, Vente, administration / espace client / caisse d'une galerie).
//
// Chaque application a une icône « de base » (un pictogramme blanc sur la couleur d'accent) ou celle du LOGO de la boutique ou de la galerie
// (logo centré dans la zone sûre des icônes « maskable »). Le choix se règle app par app :
//   boutique : Administration → Réglages du site → Boutique installable  (réglage app_icon_<app>)
//   galerie  : Portail → Galeries → Noms et icônes des applications       (clé app_icons de la galerie)
// Sans réglage, chaque application garde l'icône qu'elle avait avant ce réglage (voir app_icon_default_mode).
// Les générateurs (studio-icon.php, vente-icon.php, pwa-icon.php, galerie/icon.php) ne font que dessiner : tout est ici.

const APP_ICON_MODES = ['basic' => 'Icône de base', 'logo' => 'Logo'];
const APP_ICON_SIZES = [16, 32, 48, 120, 152, 167, 180, 192, 512];

/** Pictogramme de base de chaque application. */
function app_icon_glyph(string $app): string
{
    return match ($app) {
        'article' => 'camera',
        'vente' => 'register',
        'gallery_admin' => 'dashboard',
        'gallery_visitor' => 'star',
        'gallery_caisse' => 'cup',
        default => 'bag',
    };
}

/** Icône d'une application tant qu'aucun choix n'est enregistré : celle d'avant (le logo pour la boutique et la galerie, un pictogramme pour le reste). */
function app_icon_default_mode(string $app): string
{
    return in_array($app, ['shop', 'gallery_admin', 'gallery_visitor'], true) ? 'logo' : 'basic';
}

/** Mode valable (basic | logo) ; une valeur vide ou inconnue donne le mode par défaut de l'application. */
function app_icon_mode_clean($value, string $app): string
{
    $v = is_string($value) ? strtolower(trim($value)) : '';
    return isset(APP_ICON_MODES[$v]) ? $v : app_icon_default_mode($app);
}

/** Mode forcé par ?mode= (aperçus des pages de réglage) ; sinon le mode enregistré. */
function app_icon_request_mode(string $app, string $stored): string
{
    $q = strtolower((string) ($_GET['mode'] ?? ''));
    return isset(APP_ICON_MODES[$q]) ? $q : $stored;
}

/** Taille demandée (?s=), parmi les tailles prévues. */
function app_icon_request_size(): int
{
    $size = (int) ($_GET['s'] ?? 192);
    return in_array($size, APP_ICON_SIZES, true) ? $size : 192;
}

function app_icon_font(): ?string
{
    foreach (['/System/Library/Fonts/Supplemental/Arial Bold.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f)) return $f;
    }
    return null;
}

/** Initiale d'un nom (première lettre ou chiffre), en majuscule. */
function app_icon_initial(string $name): string
{
    return mb_strtoupper(mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($name)), 0, 1));
}

function app_icon_rgb(\GdImage $img, string $hex)
{
    [$r, $g, $b] = sscanf(preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? $hex : '#b0622b', '#%02x%02x%02x');
    return imagecolorallocate($img, $r, $g, $b);
}

/** Dessine le pictogramme (tout reste dans les 80 % centraux : zone sûre des icônes « maskable »). $fg : couleur du trait, $bg : fond (pour les évidements). */
function app_icon_draw_glyph(\GdImage $img, string $glyph, int $size, int $fg, int $bg): void
{
    $u = $size / 100;
    $rect = static fn (float $x1, float $y1, float $x2, float $y2, int $c) => imagefilledrectangle($img, (int) ($x1 * $u), (int) ($y1 * $u), (int) ($x2 * $u), (int) ($y2 * $u), $c);
    $disc = static fn (float $x, float $y, float $d, int $c) => imagefilledellipse($img, (int) ($x * $u), (int) ($y * $u), (int) ($d * $u), (int) ($d * $u), $c);
    switch ($glyph) {
        case 'camera':
            $rect(24, 36, 76, 66, $fg);
            $rect(38, 30, 62, 37, $fg);
            $disc(50, 51, 22, $bg);
            $disc(50, 51, 14, $fg);
            break;
        case 'register':
            $rect(41, 24, 59, 42, $fg);
            $rect(24, 40, 76, 72, $fg);
            $rect(31, 46, 69, 56, $bg);
            $rect(31, 63, 69, 65, $bg);
            break;
        case 'dashboard':
            foreach ([[26, 26], [54, 26], [26, 54], [54, 54]] as [$x, $y]) $rect($x, $y, $x + 20, $y + 20, $fg);
            break;
        case 'star':
            $pts = [];
            for ($i = 0; $i < 10; $i++) {
                $r = $i % 2 === 0 ? 27 : 11.5;
                $a = -M_PI / 2 + $i * M_PI / 5;
                $pts[] = (int) ((50 + $r * cos($a)) * $u);
                $pts[] = (int) ((52 + $r * sin($a)) * $u);
            }
            if (PHP_VERSION_ID >= 80100) imagefilledpolygon($img, $pts, $fg); else imagefilledpolygon($img, $pts, 10, $fg);
            break;
        case 'cup':
            $rect(28, 40, 62, 72, $fg);                       // gobelet
            $disc(66, 54, 22, $fg);                           // anse
            $disc(66, 54, 11, $bg);
            $rect(28, 40, 62, 46, $bg);                       // liseré
            $rect(33, 26, 36, 35, $fg); $rect(44, 22, 47, 35, $fg); $rect(55, 26, 58, 35, $fg);   // vapeur
            break;
        default: // bag
            $rect(26, 40, 74, 74, $fg);
            $rect(26, 40, 74, 46, $bg);
            imagesetthickness($img, max(2, (int) (4 * $u)));
            imagearc($img, (int) (50 * $u), (int) (40 * $u), (int) (26 * $u), (int) (30 * $u), 180, 360, $fg);
            imagesetthickness($img, 1);
    }
}

/**
 * Dessine l'icône. $style : accent (couleur d'accent), bg (fond en mode logo), logo (chemin d'un fichier image ou null), initial (lettre de repli),
 * fallback : 'square' (initiale blanche sur fond d'accent) ou 'disc' (disque d'accent portant l'initiale, sur le fond).
 */
function app_icon_render(string $app, int $size, string $mode, array $style): \GdImage
{
    $img = imagecreatetruecolor($size, $size);
    imageantialias($img, true);
    $white = imagecolorallocate($img, 255, 255, 255);
    $accent = (string) ($style['accent'] ?? '#b0622b');
    $logo = null;
    $file = $style['logo'] ?? null;
    if ($mode === 'logo' && is_string($file) && is_file($file) && filesize($file) < 8 * 1024 * 1024) $logo = @imagecreatefromstring((string) file_get_contents($file)) ?: null;

    if ($mode === 'logo') {
        imagefill($img, 0, 0, app_icon_rgb($img, (string) ($style['bg'] ?? $accent)));
        if ($logo) {
            $box = (int) round($size * 0.74);                 // 80 % centraux : zone sûre « maskable »
            $w = imagesx($logo);
            $h = imagesy($logo);
            $k = min($box / $w, $box / $h);
            $dw = max(1, (int) round($w * $k));
            $dh = max(1, (int) round($h * $k));
            imagealphablending($img, true);
            imagecopyresampled($img, $logo, (int) (($size - $dw) / 2), (int) (($size - $dh) / 2), 0, 0, $dw, $dh, $w, $h);
            return $img;
        }
        // Pas de logo lisible : l'initiale du nom, comme avant.
        $disc = ($style['fallback'] ?? 'square') === 'disc';
        $accentCol = app_icon_rgb($img, $accent);
        if (!$disc) imagefill($img, 0, 0, $accentCol);
        else imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.7), (int) ($size * 0.7), $accentCol);
        $letter = (string) ($style['initial'] ?? '');
        $font = app_icon_font();
        if ($font && function_exists('imagettftext') && $letter !== '') {
            $fs = $size * ($disc ? 0.34 : 0.42);
            $bb = imagettfbbox($fs, 0, $font, $letter);
            imagettftext($img, $fs, 0, (int) (($size - ($bb[2] - $bb[0])) / 2 - $bb[0]), (int) (($size - ($bb[1] - $bb[7])) / 2 - $bb[7]), $white, $font, $letter);
        } else {
            imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * ($disc ? 0.22 : 0.3)), (int) ($size * ($disc ? 0.22 : 0.3)), $white);
        }
        return $img;
    }

    // Icône de base : pictogramme blanc sur la couleur d'accent.
    $bg = app_icon_rgb($img, $accent);
    imagefill($img, 0, 0, $bg);
    app_icon_draw_glyph($img, app_icon_glyph($app), $size, $white, $bg);
    return $img;
}

/** Envoie l'image en PNG (cache court : un changement de réglage se voit vite ; les adresses des manifestes portent un numéro de version). */
function app_icon_send(\GdImage $img): never
{
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    imagepng($img);
    exit;
}

/** Numéro de version d'une icône : change quand le choix, le logo ou la couleur change (les navigateurs recharge alors l'icône). */
function app_icon_version(string $mode, ?string $logoFile, string $accent, string $bg = ''): string
{
    $mt = ($mode === 'logo' && $logoFile && is_file($logoFile)) ? (int) filemtime($logoFile) : 0;
    return substr(md5($mode . '|' . $mt . '|' . $accent . '|' . $bg), 0, 8);
}

/* ====================================================================== */
/* Boutique                                                               */
/* ====================================================================== */

/** Style de la boutique : couleurs du thème, logo carré, initiale du nom. Charge studio-theme.php si besoin. */
function shop_icon_style(): array
{
    require_once __DIR__ . '/studio-theme.php';
    $theme = studio_theme_colors();
    $root = realpath(dirname(__DIR__));
    $rel = ltrim((string) logo_url('square'), '/');
    $file = $rel !== '' ? realpath(dirname(__DIR__) . '/' . $rel) : false;
    $name = trim((string) (get_content()['site_name'] ?? '')) ?: (string) tenant('name');
    return [
        'accent' => $theme['accent'], 'bg' => $theme['bg'], 'fallback' => 'disc', 'initial' => app_icon_initial($name),
        'logo' => ($file && $root && str_starts_with($file, $root) && is_file($file)) ? $file : null,
    ];
}

/** Choix enregistré pour une application de la boutique (réglage app_icon_<app>). */
function shop_app_icon_mode(string $app): string
{
    return app_icon_mode_clean(function_exists('shop_setting') ? (string) shop_setting('app_icon_' . $app, '') : '', $app);
}

/** Génère et envoie l'icône d'une application de la boutique (appelé par studio-icon.php, vente-icon.php, pwa-icon.php). */
function shop_app_icon_serve(string $app): never
{
    $style = shop_icon_style();
    // Le Studio et Vente dessinaient déjà un pictogramme sur la couleur d'accent : en mode logo, le fond reste celui du thème, comme la boutique.
    app_icon_send(app_icon_render($app, app_icon_request_size(), app_icon_request_mode($app, shop_app_icon_mode($app)), $style));
}

/** Adresse de l'icône d'une application de la boutique, avec son numéro de version. $base : préfixe du commerce (tenant_base_path()). */
function shop_app_icon_url(string $app, int $size, string $base = '', ?string $mode = null): string
{
    $file = match ($app) { 'article' => '/studio-icon.php', 'vente' => '/vente-icon.php', default => '/pwa-icon.php' };
    $style = shop_icon_style();
    $mode = $mode ?? shop_app_icon_mode($app);
    return rtrim($base, '/') . $file . '?s=' . $size . '&v=' . app_icon_version($mode, $style['logo'], $style['accent'], $style['bg']) . ($mode !== shop_app_icon_mode($app) ? '&mode=' . $mode : '');
}

/* ====================================================================== */
/* Galerie                                                                */
/* ====================================================================== */

/** Choix enregistrés d'une galerie : ['admin' => basic|logo, 'visitor' => …, 'caisse' => …] (valeur par défaut si absent). */
function gallery_app_icons_normalize(array $icons): array
{
    return [
        'admin' => app_icon_mode_clean($icons['admin'] ?? '', 'gallery_admin'),
        'visitor' => app_icon_mode_clean($icons['visitor'] ?? '', 'gallery_visitor'),
        'caisse' => app_icon_mode_clean($icons['caisse'] ?? '', 'gallery_caisse'),
    ];
}

function gallery_app_icon_mode(array $gallery, string $app): string
{
    $key = match ($app) { 'gallery_admin' => 'admin', 'gallery_visitor' => 'visitor', 'gallery_caisse' => 'caisse', default => $app };
    return app_icon_mode_clean($gallery['app_icons'][$key] ?? '', $app);
}

/** Style d'une galerie : sa couleur principale (fond et accent), son logo (dans uploads/galeries/), l'initiale de son nom. */
function gallery_icon_style(array $gallery): array
{
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? (string) $gallery['accent'] : '#b5502e';
    $root = realpath(dirname(__DIR__));
    $rel = ltrim((string) ($gallery['style']['logo'] ?? ''), '/');
    $file = $rel !== '' ? realpath(dirname(__DIR__) . '/' . $rel) : false;
    $ok = $file && $root && str_starts_with($file, $root . '/uploads/galeries/') && is_file($file);
    return ['accent' => $accent, 'bg' => $accent, 'fallback' => 'square', 'initial' => app_icon_initial((string) ($gallery['name'] ?? '')), 'logo' => $ok ? $file : null];
}

/** Adresse de l'icône d'une application de galerie (gallery_admin | gallery_visitor | gallery_caisse), SANS taille : on y ajoute « &s=192 ». */
function gallery_app_icon_base(array $gallery, string $app, ?string $mode = null): string
{
    $style = gallery_icon_style($gallery);
    $stored = gallery_app_icon_mode($gallery, $app);
    $mode = $mode ?? $stored;
    return '/galerie/icon.php?g=' . rawurlencode((string) ($gallery['slug'] ?? '')) . '&app=' . $app
        . '&v=' . app_icon_version($mode, $style['logo'], $style['accent']) . ($mode !== $stored ? '&mode=' . $mode : '');
}

/** Même adresse, avec la taille. */
function gallery_app_icon_url(array $gallery, string $app, int $size = 192, ?string $mode = null): string
{
    return gallery_app_icon_base($gallery, $app, $mode) . '&s=' . $size;
}

/* ====================================================================== */
/* Formulaire de réglage (boutique : administration ; galerie : portail)   */
/* ====================================================================== */

/**
 * Bloc « nom + icône » d'une application : champ du nom (vide = nom par défaut) et choix de l'icône avec aperçu des deux possibilités.
 * $o : label, name_field, name_value, name_placeholder, icon_field, mode (choix actuel), preview (fn(string $mode): string URL d'aperçu), hint (facultatif).
 */
function app_icon_picker_html(array $o): string
{
    static $css = false;
    $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $out = '';
    if (!$css) {
        $css = true;
        $out .= '<style>.appicon{border:1px solid var(--line,#ddd);border-radius:10px;padding:12px;margin:0 0 12px}.appicon legend{font-weight:700;padding:0 6px}.appicon .ai-name{display:block;margin:0 0 10px}.appicon .ai-name input{display:block;width:100%;max-width:420px;box-sizing:border-box}'
            . '.appicon .ai-choices{display:flex;gap:18px;flex-wrap:wrap}.appicon .ai-choice{display:flex;align-items:center;gap:8px;cursor:pointer}.appicon .ai-choice img{width:64px;height:64px;border-radius:14px;border:1px solid var(--line,#ddd);display:block}'
            . '.appicon .ai-hint{margin:8px 0 0;font-size:.85rem;opacity:.75}</style>';
    }
    $out .= '<fieldset class="appicon"><legend>' . $e($o['label']) . '</legend>'
        . '<label class="ai-name">Nom sous l\'icône<input type="text" name="' . $e($o['name_field']) . '" maxlength="40" value="' . $e($o['name_value']) . '" placeholder="' . $e($o['name_placeholder']) . '"></label>'
        . '<div class="ai-choices" role="radiogroup" aria-label="Icône de ' . $e($o['label']) . '">';
    foreach (APP_ICON_MODES as $mode => $text) {
        $out .= '<label class="ai-choice"><input type="radio" name="' . $e($o['icon_field']) . '" value="' . $e($mode) . '"' . ($o['mode'] === $mode ? ' checked' : '') . '>'
            . '<img src="' . $e(($o['preview'])($mode)) . '" alt="" width="64" height="64" loading="lazy"><span>' . $e($text) . '</span></label>';
    }
    $out .= '</div>' . (($o['hint'] ?? '') !== '' ? '<p class="ai-hint">' . $e($o['hint']) . '</p>' : '') . '</fieldset>';
    return $out;
}
