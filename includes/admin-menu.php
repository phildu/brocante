<?php
/**
 * Barre latérale d'administration : catégories, ordre des catégories et des
 * entrées, pages de plugins comprises. Le classement est commun à toutes les
 * boutiques : il se règle dans le portail (portail/menu.php) et vit dans
 * data/admin-menu.json, à la racine du projet. Chaque boutique n'affiche que
 * les entrées de son rôle et de ses plugins actifs ; une entrée non classée
 * rejoint sa catégorie de départ.
 */

require_once __DIR__ . '/vocabulary.php';   // mot unique pour les « extensions » : ext_t()

function admin_menu_ensure_schema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_nav_groups (
  group_key TEXT PRIMARY KEY,
  label TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
)
SQL);
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_nav_items (
  item_key TEXT PRIMARY KEY,
  group_key TEXT NOT NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
)
SQL);
}

/** Fichier partagé par le portail et toutes les boutiques (hors racine web). */
function admin_menu_global_file(): string
{
    return dirname(__DIR__) . '/data/admin-menu.json';
}

/** Catégories de départ : clé stable => libellé (l'ordre est celui du tableau). */
function admin_menu_default_groups(): array
{
    return [
        'pilotage' => 'Pilotage',
        'catalogue' => 'Catalogue',
        'ventes' => 'Ventes',
        'contenus' => 'Contenus',
        'boutique' => 'Boutique',
        'reglages' => 'Réglages',
        'extensions' => ext_t('{Exts}'),
    ];
}

/** Ordre de départ des entrées, catégorie par catégorie. */
function admin_menu_default_order(): array
{
    return [
        'pilotage' => ['assistant', 'dashboard', 'rentabilite', 'emails'],
        'catalogue' => ['catalogue', 'univers', 'etiquettes', 'prise', 'batch'],
        'ventes' => ['commandes', 'offres', 'promos', 'comptes', 'remises', 'bareme-etat', 'shipping'],
        'contenus' => ['hero', 'slideshow', 'banners', 'media', 'legal'],
        'boutique' => ['presence', 'galeries', 'apparence', 'social', 'domaine'],
        'reglages' => ['ia', 'sms', 'plugins', 'menu', 'sync', 'reglages-general', 'reglages-pages', 'reglages-paiement', 'profil'],
        'extensions' => [],
    ];
}

/** Catégorie de départ d'une entrée de la plateforme. */
function admin_menu_default_group(string $key): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (admin_menu_default_order() as $group => $keys) {
            foreach ($keys as $k) $map[$k] = $group;
        }
    }
    return $map[$key] ?? 'extensions';
}

/** Une page de plugin rejoint la catégorie de l'entrée qu'elle remplace, sinon « Extensions ». */
function admin_menu_group_for_item(string $key, array $items): string
{
    if (str_starts_with($key, 'plugin:')) {
        $replaces = (string) ($items[$key][3] ?? '');
        if ($replaces !== '') return admin_menu_default_group($replaces);
    }
    return admin_menu_default_group($key);
}

/** Rang de départ d'une entrée, utilisé tant qu'aucun classement n'est enregistré. */
function admin_menu_default_rank(string $key, array $items): float
{
    static $ranks = null;
    if ($ranks === null) {
        $ranks = [];
        $i = 0;
        foreach (admin_menu_default_order() as $keys) {
            foreach ($keys as $k) $ranks[$k] = (float) $i++;
        }
    }
    if (isset($ranks[$key])) return $ranks[$key];
    $replaces = (string) ($items[$key][3] ?? '');
    if ($replaces !== '' && isset($ranks[$replaces])) return $ranks[$replaces] + 0.5;
    return 1000.0;
}

/** Pictogrammes disponibles (nom => tracé SVG 24×24). */
function admin_menu_icons(): array
{
    return [
        'user' => '<circle cx="12" cy="8" r="3.5"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17.5" cy="9" r="2.5"/><path d="M16 14.2A5.5 5.5 0 0 1 21.5 20"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'gauge' => '<path d="M3.5 18a8.5 8.5 0 1 1 17 0"/><path d="m12 14 4-4"/><circle cx="12" cy="15" r="1.5"/>',
        'at' => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.5 7.1"/>',
        'upload' => '<path d="M12 15V3M7 8l5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'receipt' => '<path d="M6 2h12v20l-3-2-3 2-3-2-3 2z"/><path d="M9 7h6M9 11h6M9 15h4"/>',
        'image' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
        'play' => '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="m10 8 5 3-5 3z"/><path d="M8 21h8"/>',
        'banner' => '<rect x="3" y="4" width="18" height="7" rx="1"/><path d="M3 15h18M3 19h12"/>',
        'folder' => '<path d="M3 6a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'truck' => '<path d="M3 6h11v10H3zM14 9h4l3 3v4h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
        'palette' => '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.8-.9 1.5-1.9-.3-1 .4-2.1 1.5-2.1H18a3 3 0 0 0 3-3c0-6-4-11-9-11z"/><circle cx="7.5" cy="11" r="1"/><circle cx="10.5" cy="7" r="1"/><circle cx="15" cy="7.5" r="1"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
        'external' => '<path d="M14 4h6v6M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'camera' => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>',
        'cloud' => '<path d="M7 18a4.5 4.5 0 0 1-.6-8.96A6 6 0 0 1 18 8.5a4.5 4.5 0 0 1-.5 9.5z"/><path d="M12 16v-5M9.5 13.5 12 11l2.5 2.5"/>',
        'collapse' => '<path d="m15 18-6-6 6-6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'message' => '<path d="M4 5h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9l-5 4V6a1 1 0 0 1 1-1z"/><path d="M8 9h8M8 13h5"/>',
        'puzzle' => '<path d="M10 3h4v2.5a1.5 1.5 0 0 0 3 0V3h4v7h-2.5a1.5 1.5 0 0 0 0 3H21v8h-7v-2.5a1.5 1.5 0 0 0-3 0V21H3v-8h2.5a1.5 1.5 0 0 0 0-3H3V3z"/>',
    ];
}

/** SVG d'une icône du menu ; une icône inconnue prend la pièce de puzzle. */
function admin_menu_icon_svg(string $name): string
{
    $icons = admin_menu_icons();
    return '<svg class="admin-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($icons[$name] ?? $icons['puzzle']) . '</svg>';
}

/**
 * Toutes les entrées de la plateforme (adresse, libellé, icône), sans filtre
 * de rôle ni page de plugin : matière commune de la boutique et du portail.
 */
function admin_menu_core_items(): array
{
    return [
        'dashboard' => ['/admin/dashboard.php', 'Tableau de bord', 'gauge'],
        'emails' => ['/admin/emails.php', 'Ouvertures d’e-mails', 'at'],
        'catalogue' => ['/admin/catalog.php', 'Catalogue', 'grid'],
        'univers' => ['/admin/universes.php', 'Univers', 'folder'],
        'etiquettes' => ['/admin/labels.php', 'Étiquettes (impression)', 'receipt'],
        'prise' => ['/admin/quick-add.php', 'Nouvelle pièce (photo)', 'camera'],
        'assistant' => ['/admin/shooting-assistant.php', 'Studio photo/vidéo', 'camera'],
        'batch' => ['/admin/batch-import.php', 'Import par lot', 'upload'],
        'promos' => ['/admin/promos.php', 'Mises en avant (promos)', 'banner'],
        'offres' => ['/admin/offers.php', 'Offres de prix', 'receipt'],
        'bareme-etat' => ['/admin/condition-pricing.php', 'Barème des états', 'receipt'],
        'presence' => ['/admin/presence.php', 'Boutique physique / en ligne', 'grid'],
        'galeries' => ['/admin/ma-galerie.php', 'Mes galeries', 'users'],
        'commandes' => ['/admin/orders.php', 'Commandes', 'receipt'],
        // Page économique de la boutique (chiffre d'affaires, marge, frais, fréquentation), ligne « main » : voir admin/rentabilite.php.
        // Buvette, Invendus & dons et Comptage de foule ne sont pas des plugins de boutique : ce sont des extensions de galerie (includes/gallery-modules.php).
        'rentabilite' => ['/admin/rentabilite.php', 'Rentabilité', 'gauge'],
        'comptes' => ['/admin/accounts.php', 'Comptes', 'users'],
        'social' => ['/admin/social.php', 'API & réseaux', 'user'],
        'ia' => ['/admin/ai-usage.php', 'Consommation IA', 'receipt'],
        'sms' => ['/admin/sms-credits.php', 'Crédits SMS', 'message'],
        'hero' => ['/admin/hero.php', 'Diaporama hero', 'image'],
        'slideshow' => ['/admin/slideshow.php', 'Diaporama boutique', 'play'],
        'banners' => ['/admin/banners.php', 'Bandeaux de page', 'banner'],
        'media' => ['/admin/media.php', 'Médiathèque', 'folder'],
        'shipping' => ['/admin/shipping.php', 'Frais de port', 'truck'],
        'remises' => ['/admin/discounts.php', 'Remises du panier', 'receipt'],
        'legal' => ['/admin/legal.php', 'Pages légales (CGV…)', 'receipt'],
        'apparence' => ['/admin/appearance.php', 'Apparence', 'palette'],
        'domaine' => ['/admin/domaine.php', 'Nom de domaine', 'cloud'],
        'plugins' => ['/admin/plugins.php', ext_t('{Exts}'), 'puzzle'],
        'menu' => ['/admin/menu.php', 'Menu latéral', 'grid'],
        'sync' => ['/admin/sync.php', 'Envoyer en préprod', 'cloud'],
        'reglages-general' => ['/admin/index.php?s=general', 'Réglages · Général', 'gear'],
        'reglages-pages' => ['/admin/index.php?s=pages', 'Réglages · Contenu des pages', 'gear'],
        'reglages-paiement' => ['/admin/index.php?s=paiement', 'Réglages · Paiement & livraison', 'gear'],
        'profil' => ['/admin/profile.php', 'Mon profil', 'user'],
    ];
}

/**
 * Entrées proposées à ce rôle : pages de la plateforme puis pages des plugins
 * actifs, dans leur ordre de déclaration. C'est la matière du menu de la
 * boutique, avant application de la configuration enregistrée.
 */
function admin_menu_items(string $role): array
{
    $items = admin_menu_core_items();
    // Un seul écran « Shooting » : le parcours des pièces (assistant) ou celui
    // du lieu (boutique, stand). Les deux se rejoignent par des onglets ; le
    // photographe, qui n'a pas les pièces, arrive directement sur le lieu.
    if (!admin_role_can_access($role, '/admin/shooting-assistant.php')) {
        $items['assistant'] = ['/admin/shop-shooting.php', 'Studio photo/vidéo', 'camera'];
    }
    // Offres de prix à traiter, en regard du menu.
    try {
        if ($pendingOffers = (int) db()->query("SELECT COUNT(*) FROM price_offers WHERE status = 'new'")->fetchColumn()) {
            $items['offres'][1] .= ' (' . $pendingOffers . ')';
        }
    } catch (Throwable $e) {
    }
    // Photos du lieu envoyées par un photographe et encore à valider (table créée
    // au premier envoi : avant cela, la requête échoue et le compteur reste absent).
    try {
        if ($role === 'admin' && ($waitingShoots = (int) db()->query("SELECT COUNT(*) FROM shooting_requests WHERE status = 'pending'")->fetchColumn())) {
            $items['assistant'][1] .= ' (' . $waitingShoots . ')';
        }
    } catch (Throwable $e) {
    }
    // Chaque rôle ne voit que les pages qu'il peut ouvrir.
    $items = array_filter($items, static fn (array $item): bool => admin_role_can_access($role, strtok($item[0], '?')));
    // IA facultative : une boutique dont l'IA est désactivée dans le portail ne voit pas « Consommation IA ».
    if (function_exists('ai_shop_enabled') && !ai_shop_enabled()) unset($items['ia']);
    // Pages des plugins actifs pour cette boutique, après la médiathèque. Une page
    // qui remplace une entrée de la plateforme prend sa place dans le menu.
    require_once __DIR__ . '/plugins.php';
    if ($pluginItems = plugin_nav_items($role)) {
        $navAt = array_search('media', array_keys($items), true);
        $navAt = $navAt === false ? count($items) : $navAt + 1;
        $items = array_slice($items, 0, $navAt, true) + $pluginItems + array_slice($items, $navAt, null, true);
        $items = array_diff_key($items, array_flip(plugin_nav_replaced($role)));
    }
    // Items créés dans le portail : visibles selon le rôle, comme les pages de la
    // plateforme (une adresse inconnue de la liste reste réservée aux administrateurs).
    $config = admin_menu_config();
    foreach ($config['custom'] as $key => $customItem) {
        $href = (string) $customItem['href'];
        if (!admin_role_can_access($role, strtok($href, '?'))) continue;
        $items[$key] = [$href, (string) $customItem['label'], (string) $customItem['icon']];
    }
    // Entrées masquées dans le portail : retirées du menu de toutes les boutiques.
    if ($config['hidden']) {
        $items = array_diff_key($items, array_flip($config['hidden']));
    }
    return $items;
}

/**
 * Adresse d'un item créé dans le portail : page interne uniquement, pour que
 * le filtre de rôle et le préfixe de boutique s'appliquent comme aux autres.
 */
function admin_menu_href_ok(string $href): bool
{
    return $href !== ''
        && mb_strlen($href) <= 200
        && preg_match('#^/[^\s\\\\\x00-\x1f]*$#', $href) === 1
        && !str_starts_with($href, '//');
}

/**
 * Configuration commune enregistrée par le portail :
 * ['groups' => clé => libellé, 'items' => entrée => catégorie,
 *  'hidden' => [entrée…], 'custom' => entrée => [libellé, adresse, icône]].
 * Fichier absent ou illisible : catégories et ordre de départ.
 */
function admin_menu_global_config(): ?array
{
    static $loaded = false, $config = null;
    if ($loaded) return $config;
    $loaded = true;
    $file = admin_menu_global_file();
    if (!is_file($file)) return null;
    $raw = json_decode((string) @file_get_contents($file), true);
    if (!is_array($raw) || !is_array($raw['groups'] ?? null) || !is_array($raw['items'] ?? null)) {
        error_log('Menu latéral : configuration illisible (' . $file . '), valeurs de départ appliquées.');
        return null;
    }
    $groups = [];
    foreach ($raw['groups'] as $row) {
        $key = (string) ($row['key'] ?? '');
        $label = trim((string) ($row['label'] ?? ''));
        if (!preg_match('/^[a-z0-9_-]{1,40}$/', $key) || $label === '' || isset($groups[$key])) continue;
        $groups[$key] = mb_substr($label, 0, 40);
    }
    if (!$groups) return null;
    $items = [];
    foreach ($raw['items'] as $row) {
        $key = (string) ($row['key'] ?? '');
        $group = (string) ($row['group'] ?? '');
        if ($key === '' || $key === '0' || !isset($groups[$group]) || isset($items[$key])) continue;
        $items[$key] = $group;
    }
    // Fichiers antérieurs (version 1) : pas d'entrées masquées ni créées.
    $hidden = [];
    foreach ((array) ($raw['hidden'] ?? []) as $key) {
        if (is_string($key) && $key !== '' && !in_array($key, $hidden, true)) $hidden[] = $key;
    }
    $custom = [];
    foreach ((array) ($raw['custom'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $key = (string) ($row['key'] ?? '');
        $label = trim((string) ($row['label'] ?? ''));
        $href = (string) ($row['href'] ?? '');
        $icon = (string) ($row['icon'] ?? '');
        if (!preg_match('/^custom:[a-z0-9_-]{1,48}$/', $key) || $label === '' || isset($custom[$key])) continue;
        if (!admin_menu_href_ok($href)) continue;
        if (!isset(admin_menu_icons()[$icon])) $icon = 'grid';
        $custom[$key] = ['label' => mb_substr($label, 0, 40), 'href' => $href, 'icon' => $icon];
    }
    return $config = ['groups' => $groups, 'items' => $items, 'hidden' => $hidden, 'custom' => $custom];
}

/** Enregistre la configuration commune (ordre des tableaux conservé). */
function admin_menu_global_save(array $groups, array $items, array $hidden = [], array $custom = []): void
{
    $file = admin_menu_global_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer $dir : droits d'écriture manquants.");
    }
    $customRows = [];
    foreach ($custom as $key => $row) {
        $customRows[] = [
            'key' => (string) $key,
            'label' => (string) ($row['label'] ?? ''),
            'href' => (string) ($row['href'] ?? ''),
            'icon' => (string) ($row['icon'] ?? ''),
        ];
    }
    $json = json_encode([
        'version' => 2,
        'updated_at' => gmdate('c'),
        'groups' => array_values($groups),
        'items' => array_values($items),
        'hidden' => array_values(array_map('strval', $hidden)),
        'custom' => $customRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Configuration illisible : rien n\'a été enregistré.');
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Impossible d'écrire $file : droits d'écriture manquants sur data/.");
    }
    @chmod($file, 0644);
}

/** Revient aux catégories et à l'ordre de départ pour toutes les boutiques. */
function admin_menu_global_reset(): void
{
    $file = admin_menu_global_file();
    if (is_file($file) && !@unlink($file)) {
        throw new RuntimeException("Impossible d'effacer $file : droits d'écriture manquants sur data/.");
    }
}

/** Configuration appliquée : celle du portail, sinon les valeurs de départ. */
function admin_menu_config(): array
{
    return admin_menu_global_config() ?? ['groups' => [], 'items' => [], 'hidden' => [], 'custom' => []];
}

/**
 * Catégories et entrées prêtes à afficher, dans l'ordre enregistré. Une entrée
 * sans classement (nouvelle page, plugin activé depuis) rejoint sa catégorie de
 * départ, après les entrées classées ; une catégorie vide reste renvoyée pour
 * l'écran de gestion mais n'est pas affichée dans la barre.
 */
function admin_menu_layout(array $items, ?array $config = null): array
{
    $config = $config ?? admin_menu_config();
    $groups = $config['groups'];
    $configured = $groups !== [];
    if (!$configured) $groups = admin_menu_default_groups();
    $firstGroup = (string) array_key_first($groups);

    $groupOf = [];
    foreach ($items as $key => $item) {
        $group = (string) ($config['items'][$key] ?? '');
        if ($group === '' || !isset($groups[$group])) {
            $group = admin_menu_group_for_item((string) $key, $items);
            if (!isset($groups[$group])) $group = isset($groups['reglages']) ? 'reglages' : $firstGroup;
        }
        $groupOf[$key] = $group;
    }

    $rank = [];
    $i = 0;
    foreach ($config['items'] as $key => $unused) $rank[$key] = $i++;
    $natural = array_flip(array_keys($items));
    $position = static function (string $key) use ($rank, $items, $configured): float {
        if (isset($rank[$key])) return (float) $rank[$key];
        return ($configured ? 100000.0 : 0.0) + admin_menu_default_rank($key, $items);
    };

    $layout = [];
    foreach ($groups as $groupKey => $label) {
        $layout[$groupKey] = ['key' => (string) $groupKey, 'label' => (string) $label, 'items' => []];
    }
    foreach ($items as $key => $item) $layout[$groupOf[$key]]['items'][$key] = $item;
    foreach ($layout as &$groupRow) {
        uksort($groupRow['items'], static function ($a, $b) use ($position, $natural): int {
            $cmp = $position((string) $a) <=> $position((string) $b);
            return $cmp !== 0 ? $cmp : ($natural[$a] <=> $natural[$b]);
        });
    }
    unset($groupRow);
    return array_values($layout);
}

/** Clé stable pour une nouvelle catégorie, dérivée de son nom. */
function admin_menu_group_key(string $label, array $used): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
    $base = strtolower($ascii !== false ? $ascii : $label);
    $base = strtr($base, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae']);
    $base = str_replace(["'", '’', '`'], '', $base);
    $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-');
    if ($base === '') $base = 'categorie';
    $base = substr($base, 0, 32);
    $key = $base;
    for ($n = 2; in_array($key, $used, true); $n++) $key = $base . '-' . $n;
    return $key;
}

/** Clé stable d'un item créé dans le portail, dérivée de son libellé. */
function admin_menu_custom_key(string $label, array $used): string
{
    $base = 'custom:' . admin_menu_group_key($label, []);
    $key = $base;
    for ($n = 2; in_array($key, $used, true); $n++) $key = $base . '-' . $n;
    return $key;
}
