<?php

// Modèles de design des boutiques : une palette, une paire de polices et une feuille de style (assets/templates/<clé>.css).
// Le modèle d'un commerce est la clé « template » de son tenants/<slug>/tenant.php (« brocante » par défaut = le design historique, sans
// feuille supplémentaire). La palette et les polices du modèle sont posées à la création de la boutique ; la feuille, elle, s'applique
// toujours (tenant_head_html). Un visiteur peut prévisualiser un modèle sur n'importe quelle boutique avec ?modele=<clé> (rien n'est enregistré).

const SHOP_TEMPLATES = [
    'brocante' => [
        'label' => 'Brocante', 'tagline' => 'Carnet de chineur : papier chaud, filets fins, machine à écrire.',
        'colors' => ['bg' => '#f4eee1', 'ink' => '#2e2418', 'accent' => '#b5502e', 'accent-2' => '#46647a'],
        'fonts' => ['display' => 'Fraunces', 'body' => 'Archivo'],
    ],
    'atelier' => [
        'label' => 'Atelier', 'tagline' => 'Moderne et doux : coins arrondis, ombres légères, boutons en pilule.',
        'colors' => ['bg' => '#f6f4ef', 'ink' => '#1f2937', 'accent' => '#0f766e', 'accent-2' => '#d97706'],
        'fonts' => ['display' => 'Bricolage Grotesque', 'body' => 'DM Sans'],
    ],
    'galerie' => [
        'label' => 'Galerie', 'tagline' => 'Éditorial et épuré : grands titres, blanc généreux, noir et or.',
        'colors' => ['bg' => '#faf9f6', 'ink' => '#141414', 'accent' => '#8a6d3b', 'accent-2' => '#3b4a5a'],
        'fonts' => ['display' => 'Playfair Display', 'body' => 'Karla'],
    ],
    'librairie' => [
        'label' => 'Librairie', 'layout' => 'librairie', 'sector' => 'Livres, BD, disques, papeterie',
        'tagline' => 'Pensée pour les livres : recherche en vedette, rayons, filtres latéraux, fiche avec grand résumé.',
        'colors' => ['bg' => '#f7f3ea', 'ink' => '#1d2630', 'accent' => '#7a1f2b', 'accent-2' => '#2f5d50'],
        'fonts' => ['display' => 'Libre Baskerville', 'body' => 'Source Serif 4'],
    ],
    'mode' => [
        'label' => 'Mode', 'layout' => 'mode', 'sector' => 'Vêtements, accessoires, friperie',
        'tagline' => 'Pensée pour les vêtements : grandes images, lookbook, grille serrée, fiche à images empilées.',
        'colors' => ['bg' => '#fbfaf8', 'ink' => '#141414', 'accent' => '#161616', 'accent-2' => '#a14b2f'],
        'fonts' => ['display' => 'Josefin Sans', 'body' => 'Work Sans'],
    ],
];

const SHOP_TEMPLATE_DEFAULT = 'brocante';

function shop_template_valid(?string $key): bool
{
    return $key !== null && isset(SHOP_TEMPLATES[$key]);
}

/** Mise en page d'un modèle : « classic » (pages d'origine) ou celle d'un vrai modèle (dossier views/<layout>/). */
function shop_template_layout(string $key): string
{
    return SHOP_TEMPLATES[$key]['layout'] ?? 'classic';
}

/**
 * Modèle demandé en aperçu, ou ''. ?modele=<clé> lance l'aperçu, qui reste actif pendant la visite (pour parcourir toute la boutique avec ce
 * modèle) ; ?modele=0 le quitte. Jamais dans l'administration, et rien n'est enregistré.
 */
function shop_template_preview(): string
{
    if (str_contains((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/') || PHP_SAPI === 'cli') return '';
    if (session_status() === PHP_SESSION_NONE) require_once __DIR__ . '/session.php';
    if (isset($_GET['modele'])) {
        $key = strtolower((string) $_GET['modele']);
        if (shop_template_valid($key)) $_SESSION['modele_apercu'] = $key;
        else unset($_SESSION['modele_apercu']);
    }
    $key = (string) ($_SESSION['modele_apercu'] ?? '');
    return shop_template_valid($key) ? $key : '';
}

/** Modèle actif sur cette page : l'aperçu demandé, sinon celui choisi dans l'administration (Apparence), sinon celui du tenant.php. */
function shop_template_active(): string
{
    static $key = null;
    if ($key === null) {
        $key = shop_template_preview();
        if ($key === '' && function_exists('appearance_saved')) {
            try { $saved = appearance_saved(); } catch (Throwable $e) { $saved = null; }
            $key = (string) ($saved['template'] ?? '');
        }
        if (!shop_template_valid($key)) $key = (string) tenant('template', '');
        if (!shop_template_valid($key)) $key = SHOP_TEMPLATE_DEFAULT;
    }
    return $key;
}

function shop_layout(): string
{
    return shop_template_layout(shop_template_active());
}

/** Fichier de la vue $name (header, footer, home, shop, product, card) du modèle actif, ou null pour garder la page d'origine. */
function shop_view(string $name): ?string
{
    $layout = shop_layout();
    if ($layout === 'classic' || !preg_match('/^[a-z-]+$/', $layout)) return null;
    $file = dirname(__DIR__) . "/views/$layout/$name.php";
    return is_file($file) ? $file : null;
}

/** Feuille de style d'un modèle (vide pour « brocante », dont le design est celui de assets/style.css). */
function shop_template_css(string $key): string
{
    if (!shop_template_valid($key) || $key === SHOP_TEMPLATE_DEFAULT || shop_template_layout($key) !== 'classic') return '';
    $file = dirname(__DIR__) . '/assets/templates/' . $key . '.css';
    return is_file($file) ? trim(preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($file))) : '';
}

/** Feuille d'un modèle à vraie mise en page, chargée par <link> (avec la date du fichier pour renouveler le cache) ; '' pour un modèle classique. */
function shop_template_link(string $key): string
{
    if (!shop_template_valid($key) || shop_template_layout($key) === 'classic') return '';
    $file = dirname(__DIR__) . '/assets/templates/' . $key . '.css';
    return is_file($file) ? '<link rel="stylesheet" href="/assets/templates/' . $key . '.css?v=' . filemtime($file) . '" id="template-' . $key . '">' : '';
}

// ── Aides communes aux vues des modèles à mise en page propre (views/<layout>/) ─────────────────────────────────────────────────────

/** Image de couverture d'une pièce (chemin relatif à la racine web), ou null : la photo de couverture, sinon la première image fixe de la galerie. */
function shop_cover(array $p, ?array $gallery = null): ?string
{
    if (!empty($p['photo'])) return (string) $p['photo'];
    foreach ($gallery ?? product_gallery($p) as $g) {
        if ($g['type'] !== 'video' && $g['only'] !== 'mobile') return (string) $g['src'];
    }
    return null;
}

/** Seconde image fixe de la galerie, différente de la couverture (affichée au survol dans la mode), ou null. */
function shop_second_image(array $p, ?array $gallery = null): ?string
{
    $cover = shop_cover($p, $gallery);
    foreach ($gallery ?? product_gallery($p) as $g) {
        if ($g['type'] !== 'video' && $g['only'] !== 'mobile' && $g['src'] !== $cover) return (string) $g['src'];
    }
    return null;
}

/** Formulaire « ajouter au panier » (ou lien « nous contacter » pour un prix sur devis). $class : classes du bouton. */
function shop_add_form(array $p, string $label = 'Ajouter au panier', string $class = 'btn btn-primary'): string
{
    if (!in_stock($p)) return '<span class="badge sold">Vendue</span>';
    if (!is_fixed_price(effective_price($p))) return '<a class="' . h($class) . '" href="/index.php#contact">Nous contacter</a>';
    return '<form method="post" action="/cart-add.php" class="shop-add">'
        . '<input type="hidden" name="ref" value="' . h($p['ref']) . '">'
        . '<input type="hidden" name="redirect" value="' . h($_SERVER['REQUEST_URI'] ?? '/boutique.php') . '">'
        . '<button class="' . h($class) . '" type="submit">' . h($label) . '</button></form>';
}

/** Début d'un texte, coupé proprement à la fin d'un mot. */
function shop_excerpt(string $text, int $max = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if (mb_strlen($text) <= $max) return $text;
    return rtrim(mb_substr($text, 0, mb_strrpos(mb_substr($text, 0, $max), ' ') ?: $max), " ,;:.") . '…';
}

/** Adresse de la page courante avec des paramètres modifiés (null ou '' : retire le paramètre). */
function shop_url_with(array $changes, ?string $path = null): string
{
    $q = $_GET;
    unset($q['modele']);
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]); else $q[$k] = $v;
    }
    return ($path ?? (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)) . ($q ? '?' . http_build_query($q) : '');
}

/** Prix d'une pièce en centimes (promo comprise), ou null pour un prix sur devis : sert au tri. */
function shop_price_cents(array $p): ?int
{
    return price_to_cents(effective_price($p));
}

/** Pièces triées : « prix-asc », « prix-desc » ; tout autre ordre garde celui du catalogue (nouveautés d'abord). */
function shop_sort_products(array $products, string $sort): array
{
    if ($sort !== 'prix-asc' && $sort !== 'prix-desc') return $products;
    usort($products, static function (array $a, array $b) use ($sort): int {
        $pa = shop_price_cents($a) ?? PHP_INT_MAX;
        $pb = shop_price_cents($b) ?? PHP_INT_MAX;
        return $sort === 'prix-asc' ? $pa <=> $pb : $pb <=> $pa;
    });
    return $products;
}

/**
 * Maquette filaire d'un modèle à mise en page propre, pour le choix du modèle (SVG ; couleurs --p-bg, --p-ink, --p-accent posées par la page).
 * '' pour les modèles classiques, qui gardent leur aperçu typographique.
 */
function shop_template_wireframe(string $key): string
{
    $ink = 'fill="var(--p-ink)"';
    $acc = 'fill="var(--p-accent)"';
    $open = '<svg class="tpl-wire" viewBox="0 0 160 100" role="img" aria-label="Maquette de la mise en page">'
        . '<rect width="160" height="100" fill="var(--p-bg)"/><rect x=".5" y=".5" width="159" height="99" fill="none" stroke="var(--p-ink)" stroke-opacity=".25"/>';
    $faint = 'fill-opacity=".22"';
    return match (shop_template_layout($key)) {
        // Bandeau, recherche large au centre, barre des rayons, filtres à gauche, étagère de couvertures en portrait.
        'librairie' => $open
            . "<rect width=\"160\" height=\"6\" $acc/><rect x=\"8\" y=\"12\" width=\"26\" height=\"8\" $ink $faint/><rect x=\"46\" y=\"12\" width=\"68\" height=\"8\" fill=\"none\" stroke=\"var(--p-ink)\" stroke-width=\"1.4\"/><rect x=\"108\" y=\"12\" width=\"14\" height=\"8\" $ink/>"
            . "<rect x=\"8\" y=\"26\" width=\"144\" height=\"1\" $ink $faint/><g $ink $faint><rect x=\"8\" y=\"29\" width=\"20\" height=\"3\"/><rect x=\"34\" y=\"29\" width=\"20\" height=\"3\"/><rect x=\"60\" y=\"29\" width=\"20\" height=\"3\"/></g>"
            . "<g $ink $faint><rect x=\"8\" y=\"42\" width=\"26\" height=\"3\"/><rect x=\"8\" y=\"50\" width=\"22\" height=\"3\"/><rect x=\"8\" y=\"58\" width=\"26\" height=\"3\"/><rect x=\"8\" y=\"66\" width=\"18\" height=\"3\"/><rect x=\"8\" y=\"74\" width=\"24\" height=\"3\"/></g>"
            . "<rect x=\"44\" y=\"38\" width=\"54\" height=\"3\" $ink/><g><rect x=\"44\" y=\"45\" width=\"21\" height=\"32\" $acc/><rect x=\"70\" y=\"45\" width=\"21\" height=\"32\" $ink $faint/><rect x=\"96\" y=\"45\" width=\"21\" height=\"32\" $ink $faint/><rect x=\"122\" y=\"45\" width=\"21\" height=\"32\" $ink $faint/></g>"
            . "<g $ink $faint><rect x=\"44\" y=\"81\" width=\"21\" height=\"2\"/><rect x=\"70\" y=\"81\" width=\"21\" height=\"2\"/><rect x=\"96\" y=\"81\" width=\"21\" height=\"2\"/><rect x=\"122\" y=\"81\" width=\"21\" height=\"2\"/></g></svg>",
        // Image plein cadre sous un en-tête centré, titre géant en bas à gauche, grille serrée de grandes images.
        'mode' => $open
            . "<rect x=\"1\" y=\"1\" width=\"158\" height=\"58\" $ink/><rect x=\"1\" y=\"1\" width=\"158\" height=\"58\" fill=\"var(--p-accent)\" fill-opacity=\".35\"/>"
            . "<g fill=\"var(--p-bg)\"><rect x=\"8\" y=\"7\" width=\"22\" height=\"2\" fill-opacity=\".8\"/><rect x=\"62\" y=\"6\" width=\"36\" height=\"4\"/><rect x=\"128\" y=\"7\" width=\"24\" height=\"2\" fill-opacity=\".8\"/><rect x=\"8\" y=\"36\" width=\"70\" height=\"6\"/><rect x=\"8\" y=\"45\" width=\"46\" height=\"6\"/></g>"
            . "<g><rect x=\"1\" y=\"62\" width=\"38\" height=\"36\" $ink $faint/><rect x=\"41\" y=\"62\" width=\"38\" height=\"36\" $ink $faint/><rect x=\"81\" y=\"62\" width=\"38\" height=\"36\" $ink $faint/><rect x=\"121\" y=\"62\" width=\"38\" height=\"36\" $ink $faint/></g></svg>",
        default => '',
    };
}
