<?php

// Modèles de design des boutiques : une palette, une paire de polices et une feuille de style (assets/templates/<clé>.css).
// Le modèle d'un commerce est la clé « template » de son tenants/<slug>/tenant.php (« brocante » par défaut = le design historique, sans
// feuille supplémentaire). La palette et les polices du modèle sont posées à la création de la boutique ; la feuille, elle, s'applique
// toujours (tenant_head_html). Un visiteur peut prévisualiser un modèle sur n'importe quelle boutique avec ?modele=<clé> (rien n'est enregistré).

// Types de hero : le modèle en choisit un par défaut, le commerçant peut le remplacer dans Apparence (Apparence → Hero).
const SHOP_HEROES = [
    'polaroid' => ['label' => 'Pile de polaroïds', 'tagline' => 'Photos, promo et coups de cœur en cartes empilées qui tournent.'],
    'plein' => ['label' => 'Image plein écran', 'tagline' => 'Une grande image d\'ambiance, titre et boutons par-dessus.'],
    'scinde' => ['label' => 'Scindé', 'tagline' => 'Texte à gauche, grande image à droite, légende en médaillon.'],
    'carrousel' => ['label' => 'Carrousel', 'tagline' => 'Plusieurs slides qui défilent horizontalement, flèches et repères.'],
    'mosaique' => ['label' => 'Mosaïque', 'tagline' => 'Texte et trois images en composition asymétrique.'],
];

const SHOP_TEMPLATES = [
    'brocante' => [
        'label' => 'Brocante', 'tagline' => 'Carnet de chineur : papier chaud, filets fins, machine à écrire.',
        'colors' => ['bg' => '#f4eee1', 'ink' => '#2e2418', 'accent' => '#b5502e', 'accent-2' => '#46647a'],
        'fonts' => ['display' => 'Fraunces', 'body' => 'Archivo'],
        'hero' => 'polaroid',
    ],
    'atelier' => [
        'label' => 'Atelier', 'tagline' => 'Moderne et doux : coins arrondis, ombres légères, boutons en pilule.',
        'colors' => ['bg' => '#f6f4ef', 'ink' => '#1f2937', 'accent' => '#0f766e', 'accent-2' => '#d97706'],
        'fonts' => ['display' => 'Bricolage Grotesque', 'body' => 'DM Sans'],
        'hero' => 'polaroid',
    ],
    'galerie' => [
        'label' => 'Galerie', 'tagline' => 'Éditorial et épuré : grands titres, blanc généreux, noir et or.',
        'colors' => ['bg' => '#faf9f6', 'ink' => '#141414', 'accent' => '#8a6d3b', 'accent-2' => '#3b4a5a'],
        'fonts' => ['display' => 'Playfair Display', 'body' => 'Karla'],
        'hero' => 'polaroid',
    ],
    'jardin' => [
        'label' => 'Jardin', 'layout' => 'classic', 'sector' => 'Plantes, décoration, artisanat',
        'tagline' => 'Botanique et lumineux : verts tendres, courbes douces, photos en arche.',
        'colors' => ['bg' => '#f3f5ee', 'ink' => '#28332a', 'accent' => '#5c7a57', 'accent-2' => '#b07b4f'],
        'dark' => ['bg' => '#1c241e', 'ink' => '#eef2e6', 'accent' => '#9ab48a', 'accent-2' => '#d2a273'],
        'fonts' => ['display' => 'Cormorant Garamond', 'body' => 'Karla'],
        'hero' => 'mosaique',
    ],
    'comptoir' => [
        'label' => 'Comptoir', 'layout' => 'classic', 'sector' => 'Épicerie, vinyles, brocante rétro',
        'tagline' => 'Rétro et convivial : affiches, filets épais, couleurs de bistrot.',
        'colors' => ['bg' => '#fbf2e5', 'ink' => '#2c1e18', 'accent' => '#9b2c2c', 'accent-2' => '#c98a2e'],
        'dark' => ['bg' => '#241715', 'ink' => '#f7e9d8', 'accent' => '#e07a5f', 'accent-2' => '#e9b44c'],
        'fonts' => ['display' => 'Abril Fatface', 'body' => 'Lato'],
        'hero' => 'carrousel',
    ],
    'epure' => [
        'label' => 'Épure', 'layout' => 'classic', 'sector' => 'Design, mobilier, objets graphiques',
        'tagline' => 'Suisse et net : grands blancs, grille stricte, un bleu franc pour accent.',
        'colors' => ['bg' => '#f7f6f2', 'ink' => '#121212', 'accent' => '#1f4fd8', 'accent-2' => '#d94f2b'],
        'dark' => ['bg' => '#111214', 'ink' => '#f2f1ec', 'accent' => '#7ea0ff', 'accent-2' => '#ff8a65'],
        'fonts' => ['display' => 'Syne', 'body' => 'DM Sans'],
        'hero' => 'plein',
    ],
    'terracotta' => [
        'label' => 'Terracotta', 'layout' => 'classic', 'sector' => 'Céramique, déco, art de vivre',
        'tagline' => 'Solaire et artisanal : terres cuites, arches, ombres douces.',
        'colors' => ['bg' => '#fbf1e8', 'ink' => '#33211b', 'accent' => '#b4562f', 'accent-2' => '#6d7f52'],
        'dark' => ['bg' => '#261a16', 'ink' => '#f6e7dc', 'accent' => '#e08a5f', 'accent-2' => '#a3b18a'],
        'fonts' => ['display' => 'DM Serif Display', 'body' => 'Nunito'],
        'hero' => 'scinde',
    ],
    'marine' => [
        'label' => 'Marine', 'layout' => 'classic', 'sector' => 'Vintage, sport, objets de voyage',
        'tagline' => 'Fraîcheur côtière : bleus profonds, lignes franches, cartes arrondies.',
        'colors' => ['bg' => '#f2f5f8', 'ink' => '#16283a', 'accent' => '#1d5d8f', 'accent-2' => '#e2683c'],
        'dark' => ['bg' => '#101a24', 'ink' => '#e6eef7', 'accent' => '#6aa9d8', 'accent-2' => '#f08a5d'],
        'fonts' => ['display' => 'Bricolage Grotesque', 'body' => 'Work Sans'],
        'hero' => 'carrousel',
    ],
    'carnet' => [
        'label' => 'Carnet', 'layout' => 'classic', 'sector' => 'Papeterie, livres, collections',
        'tagline' => 'Papier et encre : fond ligné, marges rouges, esprit carnet de chine.',
        'colors' => ['bg' => '#f7f4e9', 'ink' => '#26324a', 'accent' => '#a33a2f', 'accent-2' => '#3f6a5a'],
        'dark' => ['bg' => '#1c2230', 'ink' => '#f0ead9', 'accent' => '#d9735f', 'accent-2' => '#7fa89a'],
        'fonts' => ['display' => 'Libre Baskerville', 'body' => 'Lora'],
        'hero' => 'polaroid',
    ],
    'vitrine' => [
        'label' => 'Vitrine', 'layout' => 'vitrine', 'sector' => 'Galerie, mobilier, pièces de collection',
        'tagline' => 'Rail vertical, index typographique et diptyques : une galerie qui met chaque pièce en scène.',
        'colors' => ['bg' => '#f4f1ea', 'ink' => '#171614', 'accent' => '#8a2f2b', 'accent-2' => '#33544a'],
        'dark' => ['bg' => '#141312', 'ink' => '#f1ece2', 'accent' => '#cf7a6f', 'accent-2' => '#86a89a'],
        'fonts' => ['display' => 'Syne', 'body' => 'Karla'],
        'hero' => '',
    ],
    'marche' => [
        'label' => 'Marché', 'layout' => 'marche', 'sector' => 'Épicerie, brocante du quotidien, marché',
        'tagline' => 'Bandeau défilant, rayons en pastilles et mosaïque dense : l\'esprit d\'un marché couvert.',
        'colors' => ['bg' => '#fdf6e8', 'ink' => '#241c12', 'accent' => '#b8400f', 'accent-2' => '#1f6f43'],
        'dark' => ['bg' => '#17130f', 'ink' => '#f6ead6', 'accent' => '#e98a5c', 'accent-2' => '#7fb08f'],
        'fonts' => ['display' => 'Bricolage Grotesque', 'body' => 'Nunito'],
        'hero' => '',
    ],
    'feuille' => [
        'label' => 'Feuille', 'layout' => 'feuille', 'sector' => 'Papeterie, plantes, maison, récits',
        'tagline' => 'Une page qui se lit : chapitres numérotés, grandes images, menu plein écran.',
        'colors' => ['bg' => '#f4f1e7', 'ink' => '#22251e', 'accent' => '#3d5c3a', 'accent-2' => '#a4632a'],
        'dark' => ['bg' => '#161913', 'ink' => '#eee9da', 'accent' => '#97b48c', 'accent-2' => '#d9a06b'],
        'fonts' => ['display' => 'Cormorant Garamond', 'body' => 'Work Sans'],
        'hero' => '',
    ],
    'librairie' => [
        'label' => 'Librairie', 'layout' => 'librairie', 'sector' => 'Livres, BD, disques, papeterie',
        'tagline' => 'Pensée pour les livres : recherche en vedette, rayons, filtres latéraux, fiche avec grand résumé.',
        'colors' => ['bg' => '#f7f3ea', 'ink' => '#1d2630', 'accent' => '#7a1f2b', 'accent-2' => '#2f5d50'],
        'fonts' => ['display' => 'Libre Baskerville', 'body' => 'Source Serif 4'],
        'hero' => '',
    ],
    'mode' => [
        'label' => 'Mode', 'layout' => 'mode', 'sector' => 'Vêtements, accessoires, friperie',
        'tagline' => 'Pensée pour les vêtements : grandes images, lookbook, grille serrée, fiche à images empilées.',
        'colors' => ['bg' => '#fbfaf8', 'ink' => '#141414', 'accent' => '#161616', 'accent-2' => '#a14b2f'],
        'fonts' => ['display' => 'Josefin Sans', 'body' => 'Work Sans'],
        'hero' => '',
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

function shop_hero_valid(?string $key): bool
{
    return $key !== null && isset(SHOP_HEROES[$key]);
}

/** Hero par défaut d'un modèle ; '' pour les modèles à mise en page propre, qui gardent leur hero signature. */
function shop_template_hero(string $key): string
{
    $hero = (string) (SHOP_TEMPLATES[$key]['hero'] ?? '');
    return shop_hero_valid($hero) ? $hero : '';
}

/** Hero actif : la surcharge enregistrée dans Apparence, sinon celui du modèle. */
function shop_hero_type(): string
{
    $tpl = shop_template_active();
    if (shop_template_preview() !== '') return shop_template_hero($tpl);
    if (shop_template_layout($tpl) !== 'classic') return '';
    $saved = function_exists('appearance_saved') ? appearance_saved() : null;
    $hero = (string) ($saved['hero'] ?? '');
    return shop_hero_valid($hero) ? $hero : (shop_template_hero($tpl) ?: 'polaroid');
}

/** Surcharge de hero enregistrée ('' = suivre le hero du modèle). */
function shop_hero_override(): string
{
    $saved = function_exists('appearance_saved') ? appearance_saved() : null;
    $hero = (string) ($saved['hero'] ?? '');
    return shop_hero_valid($hero) ? $hero : '';
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
    if (!shop_sales_enabled()) return '<a class="' . h($class) . '" href="/index.php#contact">Nous contacter</a>';
    if (!in_stock($p)) return '<span class="badge sold">Vendue</span>';
    if (!is_fixed_price(effective_price($p))) return '<a class="' . h($class) . '" href="/index.php#contact">Nous contacter</a>';
    return '<form method="post" action="/cart-add.php" class="shop-add">' . cart_csrf_field()
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
        // Rail vertical à gauche, diptyque d'images décalé, index des rayons en lignes numérotées.
        'vitrine' => $open
            . "<rect x=\"1\" y=\"1\" width=\"10\" height=\"98\" $ink $faint/><rect x=\"3\" y=\"4\" width=\"6\" height=\"6\" $acc/>"
            . "<g $ink $faint><rect x=\"4\" y=\"26\" width=\"4\" height=\"2\"/><rect x=\"4\" y=\"33\" width=\"4\" height=\"2\"/><rect x=\"4\" y=\"40\" width=\"4\" height=\"2\"/><rect x=\"4\" y=\"47\" width=\"4\" height=\"2\"/></g>"
            . "<g $ink $faint><rect x=\"16\" y=\"9\" width=\"30\" height=\"2\"/><rect x=\"16\" y=\"15\" width=\"44\" height=\"6\"/><rect x=\"16\" y=\"25\" width=\"34\" height=\"6\"/><rect x=\"16\" y=\"36\" width=\"24\" height=\"3\"/></g>"
            . "<rect x=\"76\" y=\"6\" width=\"46\" height=\"40\" $ink $faint/><rect x=\"96\" y=\"22\" width=\"40\" height=\"30\" $acc/>"
            . "<g $ink $faint><rect x=\"16\" y=\"52\" width=\"120\" height=\"1\"/><rect x=\"16\" y=\"58\" width=\"16\" height=\"2\"/><rect x=\"38\" y=\"58\" width=\"52\" height=\"3\"/><rect x=\"126\" y=\"58\" width=\"10\" height=\"3\"/><rect x=\"16\" y=\"66\" width=\"120\" height=\"1\"/><rect x=\"16\" y=\"72\" width=\"16\" height=\"2\"/><rect x=\"38\" y=\"72\" width=\"44\" height=\"3\"/><rect x=\"126\" y=\"72\" width=\"10\" height=\"3\"/><rect x=\"16\" y=\"80\" width=\"120\" height=\"1\"/><rect x=\"16\" y=\"86\" width=\"16\" height=\"2\"/><rect x=\"38\" y=\"86\" width=\"58\" height=\"3\"/><rect x=\"126\" y=\"86\" width=\"10\" height=\"3\"/></g></svg>",
        // Bandeau défilant, barre de recherche et pastilles de rayons, collage d'images et mosaïque dense.
        'marche' => $open
            . "<rect width=\"160\" height=\"3\" $acc/><rect x=\"8\" y=\"8\" width=\"24\" height=\"7\" $ink/><rect x=\"42\" y=\"8\" width=\"60\" height=\"7\" fill=\"none\" stroke=\"var(--p-ink)\" stroke-width=\"1.2\"/><rect x=\"108\" y=\"8\" width=\"10\" height=\"7\" $ink $faint/><rect x=\"124\" y=\"8\" width=\"10\" height=\"7\" $ink/>"
            . "<g $acc><rect x=\"8\" y=\"20\" width=\"18\" height=\"5\"/><rect x=\"30\" y=\"20\" width=\"18\" height=\"5\" fill-opacity=\".5\"/><rect x=\"52\" y=\"20\" width=\"18\" height=\"5\" fill-opacity=\".5\"/><rect x=\"74\" y=\"20\" width=\"18\" height=\"5\" fill-opacity=\".5\"/></g>"
            . "<rect x=\"8\" y=\"29\" width=\"66\" height=\"30\" $ink/><g fill=\"var(--p-bg)\"><rect x=\"12\" y=\"34\" width=\"34\" height=\"4\"/><rect x=\"12\" y=\"41\" width=\"26\" height=\"4\"/></g>"
            . "<g><rect x=\"78\" y=\"29\" width=\"38\" height=\"14\" $ink $faint/><rect x=\"119\" y=\"29\" width=\"33\" height=\"14\" $acc/><rect x=\"78\" y=\"45\" width=\"16\" height=\"14\" $acc/><rect x=\"97\" y=\"45\" width=\"19\" height=\"14\" $ink $faint/><rect x=\"119\" y=\"45\" width=\"33\" height=\"14\" $ink $faint/></g>"
            . "<g $ink $faint><rect x=\"8\" y=\"64\" width=\"30\" height=\"32\"/><rect x=\"41\" y=\"64\" width=\"30\" height=\"32\"/><rect x=\"72\" y=\"64\" width=\"30\" height=\"32\"/><rect x=\"105\" y=\"64\" width=\"18\" height=\"32\"/><rect x=\"126\" y=\"64\" width=\"26\" height=\"32\" $acc/></g></svg>",
        // Bandeau minimal et menu plein écran, sommaire des rayons, planche d'ouverture, chapitres numérotés et entrefilets.
        'feuille' => $open
            . "<rect x=\"8\" y=\"7\" width=\"26\" height=\"4\" $ink/><rect x=\"8\" y=\"13\" width=\"18\" height=\"2\" $ink $faint/><g $ink><rect x=\"140\" y=\"7\" width=\"12\" height=\"1.6\"/><rect x=\"140\" y=\"11\" width=\"12\" height=\"1.6\"/><rect x=\"140\" y=\"15\" width=\"12\" height=\"1.6\"/></g>"
            . "<g $ink $faint><rect x=\"8\" y=\"24\" width=\"24\" height=\"2\"/><rect x=\"8\" y=\"30\" width=\"64\" height=\"6\"/><rect x=\"8\" y=\"39\" width=\"48\" height=\"6\"/><rect x=\"8\" y=\"50\" width=\"40\" height=\"2\"/></g>"
            . "<g $ink $faint><rect x=\"104\" y=\"24\" width=\"48\" height=\"2\"/><rect x=\"104\" y=\"30\" width=\"48\" height=\"1\"/><rect x=\"104\" y=\"35\" width=\"36\" height=\"2\"/><rect x=\"104\" y=\"41\" width=\"42\" height=\"2\"/><rect x=\"104\" y=\"47\" width=\"30\" height=\"2\"/></g>"
            . "<rect x=\"8\" y=\"58\" width=\"144\" height=\"20\" $acc/>"
            . "<g $ink $faint><rect x=\"8\" y=\"83\" width=\"16\" height=\"2\"/><rect x=\"28\" y=\"82\" width=\"40\" height=\"3\"/><rect x=\"8\" y=\"89\" width=\"30\" height=\"1\"/><rect x=\"8\" y=\"93\" width=\"26\" height=\"1\"/><rect x=\"90\" y=\"83\" width=\"18\" height=\"2\"/><rect x=\"112\" y=\"82\" width=\"40\" height=\"3\"/><rect x=\"90\" y=\"89\" width=\"28\" height=\"1\"/><rect x=\"90\" y=\"93\" width=\"24\" height=\"1\"/></g></svg>",
        default => '',
    };
}
