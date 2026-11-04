<?php
// Recherche de l'administration boutique : palette ⌘K (voir includes/admin-nav.php).
// Deux matières : le texte lisible des pages du back-office (indexé puis mis en
// cache dans data/search/admin-pages.json) et les fiches gérées par la boutique
// (pièces, commandes, médias, comptes…), relues en base à chaque recherche pour
// rester à jour. L'endpoint est admin/recherche.php.
//
// Les fonctions de texte reprennent celles du portail (portail/_search.php) :
// les deux moteurs restent indépendants, chacun dans son contexte.

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-menu.php';

// Fichier de fonctions : il n'est jamais servi directement.
if (basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === '_search.php') {
    http_response_code(404);
    exit;
}

const SHOP_SEARCH_CACHE = __DIR__ . '/../data/search/admin-pages.json';

/** Normalise pour comparer : minuscules, sans accents, un seul espace. */
function shop_search_norm(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ]);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? '';
    return trim($text);
}

/** Mots trop courants (français et vocabulaire de code) : ils ne discriminent rien. */
function shop_search_stopwords(): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(explode(' ', 'a au aux avec ce ces cet cette dans de des du elle elles en et eux il ils je la le les leur leurs lui ma mais me mes moi mon ne nos notre nous on ou par pas pour qu que quel quelle quels quelles qui sa se ses si son sont sur ta te tes toi ton tu un une vos votre vous y d l j n s t c m plus comme donc alors encore ici cela ceci celui celle ceux celles dont entre vers chez sans sous avant apres pendant depuis lors afin tout tous toute toutes tres meme aussi etre ete avoir fait faire peut doit sera seront est php html css js script style div span class href src alt title target rel input form method action type name value placeholder label option select textarea meta link head body main section article header footer nav table tr td th img svg path circle rect line fill stroke width height view box true false null array string int integer float bool boolean function return echo print foreach while if else elseif endif endforeach isset empty count strlen substr strpos explode implode preg_match preg_replace file_get_contents file_put_contents json encode decode date time new this self static public private protected const use require include namespace global throw try catch finally class extends implements instance void mixed never post get request session server globals'));
    }
    return $stop;
}

/** Mots indexables d'un texte (normalisés, dédupliqués). */
function shop_search_keywords(string $text): array
{
    $stop = shop_search_stopwords();
    $out = [];
    foreach (preg_split('/\s+/', shop_search_norm($text)) ?: [] as $word) {
        if (mb_strlen($word) < 3 || ctype_digit($word) || isset($stop[$word])) continue;
        $out[$word] = true;
        if (count($out) >= 600) break;
    }
    return array_keys($out);
}

/** Déséchappe une chaîne PHP lue dans le source. */
function shop_search_unquote(string $value): string
{
    return trim(str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $value));
}

/** Texte lisible d'une page : HTML hors scripts/styles + chaînes PHP. */
function shop_search_readable(string $src): string
{
    $src = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $src) ?? $src;
    $src = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $src) ?? $src;
    $text = preg_replace_callback('/<\?(?:php|=)?(.*?)(?:\?>|$)/is', static function (array $m): string {
        preg_match_all('/\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $strings);
        return ' ' . implode(' ', array_merge($strings[1] ?? [], $strings[2] ?? [])) . ' ';
    }, $src) ?? '';
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

/** Phrases courtes servant d'aperçu dans les résultats. */
function shop_search_excerpts(string $text): array
{
    $chunks = preg_split('/(?<=[.!?…;:])\s+|\s+[|·•—–]\s+|\s{3,}/u', $text) ?: [];
    $out = [];
    foreach ($chunks as $chunk) {
        $chunk = trim(preg_replace('/\s+/u', ' ', $chunk) ?? '');
        $chunk = trim($chunk, " \t\n\r\0\x0B\"'«»");
        $length = mb_strlen($chunk);
        if ($length < 24 || $length > 220) continue;
        if (preg_match('/[<>{}$]|=>|::|\\\\/', $chunk)) continue;
        if (count(preg_split('/\s+/u', $chunk) ?: []) < 4) continue;
        $key = shop_search_norm($chunk);
        if ($key === '' || isset($out[$key])) continue;
        $out[$key] = $chunk;
        if (count($out) >= 160) break;
    }
    return array_values($out);
}

/** Pages du back-office : écran avec la barre partagée, ou cible du menu latéral. */
function shop_search_page_files(): array
{
    $navFiles = [];
    foreach (admin_menu_core_items() as $item) {
        $path = (string) (parse_url((string) $item[0], PHP_URL_PATH) ?: '');
        if (str_starts_with($path, '/admin/')) $navFiles[basename($path)] = true;
    }

    $out = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_') continue;
        if (str_ends_with($base, '-action.php') || str_starts_with($base, 'save-')) continue;
        $src = @file_get_contents($file);
        if (!is_string($src) || $src === '') continue;
        if (!isset($navFiles[$base]) && !str_contains($src, 'admin-nav.php')) continue;
        // plugin.php n'est qu'un routeur : le contenu des pages de plugins vit
        // dans packs/ et ces pages restent trouvables par leur entrée de menu.
        if ($base === 'recherche.php' || $base === 'plugin.php') continue;
        $out[] = ['file' => $file, 'rel' => $base, 'src' => $src];
    }
    usort($out, static fn (array $a, array $b): int => strcmp($a['rel'], $b['rel']));
    return $out;
}

/** Page du back-office → catégorie et libellé du menu. */
function shop_search_nav_index(): array
{
    $index = [];
    $collect = static function (array $layout) use (&$index): void {
        foreach ($layout as $group) {
            foreach ($group['items'] as $item) {
                $base = basename((string) (parse_url((string) $item[0], PHP_URL_PATH) ?: ''));
                if ($base === '') continue;
                $index[$base] = ['group' => (string) $group['label'], 'label' => (string) $item[1]];
            }
        }
    };
    // Repli sur le menu d'origine : une page écartée du menu de la boutique
    // (entrée remplacée par un plugin, page masquée dans le portail) garde sa
    // catégorie au lieu de tomber dans « Pages ».
    $collect(admin_menu_layout(admin_menu_core_items()));
    $collect(admin_menu_layout(admin_menu_items('admin')));
    return $index;
}

/** Titre de la page : balise <title> (le nom du site, dynamique, est écarté), sinon <h1>. */
function shop_search_page_title(string $src): string
{
    if (preg_match('#<title>(.*?)</title>#is', $src, $m)) {
        $title = preg_replace('/<\?.*$/s', '', $m[1]) ?? '';
        $title = html_entity_decode(trim($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('/\s*[—–|·-]\s*$/u', '', $title) ?? $title);
        if ($title !== '' && !str_contains($title, '$')) return $title;
    }
    if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $src, $m)) {
        $title = trim(strip_tags(preg_replace('/<\?.*?\?>/s', ' ', $m[1]) ?? ''));
        $title = html_entity_decode(trim(preg_replace('/\s+/u', ' ', $title) ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($title !== '' && !str_contains($title, '$') && mb_strlen($title) < 70) return $title;
    }
    return '';
}

/** Construit une entrée d'index pour une page. */
function shop_search_build_page(array $page, array $navIndex): ?array
{
    $rel = $page['rel'];
    $nav = $navIndex[$rel] ?? null;
    $label = shop_search_page_title($page['src']);
    if ($label === '' && $nav) $label = (string) $nav['label'];
    if ($label === '') {
        $label = ucfirst(str_replace(['-', '_'], ' ', pathinfo($rel, PATHINFO_FILENAME)));
        if ($nav) $label = (string) $nav['label'];
    }
    $group = $nav ? (string) $nav['group'] : 'Pages';

    $text = shop_search_readable($page['src']);
    $excerpts = shop_search_excerpts($text);
    $terms = shop_search_keywords($label . ' ' . $group . ' ' . implode(' ', $excerpts));
    if (!$terms) return null;

    return [
        'file' => $rel,
        'href' => '/admin/' . $rel,
        'label' => $label,
        'group' => $group,
        'terms' => implode(' ', $terms),
        'excerpts' => $excerpts,
    ];
}

/** Index des pages, mis en cache tant que les fichiers et le menu ne changent pas. */
function shop_search_page_index(): array
{
    static $memory = null;
    if ($memory !== null) return $memory;

    $files = shop_search_page_files();
    $root = dirname(__DIR__);
    $signature = [
        'engine:' . (int) @filesize(__FILE__) . ':' . (int) @filemtime(__FILE__),
        'menu:' . (int) @filesize($root . '/includes/admin-menu.php') . ':' . (int) @filemtime($root . '/includes/admin-menu.php'),
        'config:' . (int) @filesize($root . '/data/admin-menu.json') . ':' . (int) @filemtime($root . '/data/admin-menu.json'),
    ];
    foreach ($files as $page) {
        $signature[] = $page['rel'] . ':' . (int) @filesize($page['file']) . ':' . (int) @filemtime($page['file']);
    }
    $sig = md5(implode('|', $signature));

    if (is_file(SHOP_SEARCH_CACHE)) {
        $cached = json_decode((string) @file_get_contents(SHOP_SEARCH_CACHE), true);
        if (is_array($cached) && ($cached['signature'] ?? '') === $sig && !empty($cached['pages'])) {
            return $memory = $cached;
        }
    }

    $navIndex = shop_search_nav_index();
    $pages = [];
    foreach ($files as $page) {
        $entry = shop_search_build_page($page, $navIndex);
        if ($entry !== null) $pages[] = $entry;
    }
    $index = ['signature' => $sig, 'built' => time(), 'pages' => $pages];

    $dir = dirname(SHOP_SEARCH_CACHE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && is_dir($dir) && is_writable($dir)) {
        $tmp = SHOP_SEARCH_CACHE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, SHOP_SEARCH_CACHE);
        } else {
            @unlink($tmp);
        }
    }
    return $memory = $index;
}

/** Aperçu lisible : on préfère les phrases sans reste de code (chemins, variables). */
function shop_search_clean_excerpt(string $excerpt): bool
{
    if (str_contains($excerpt, '_')) return false;
    if (preg_match('/\.php\b|[?&][a-z]+=|<\?|=>|::|\\\\|[{}]|\$/', $excerpt)) return false;
    // Fragments d'interface collés (« vue liste liste produit n s ») : plusieurs
    // mots d'une lettre trahissent un bout de code plutôt qu'une phrase.
    return preg_match_all('/(?:^|\s)[a-z](?=\s|$)/', $excerpt) < 2;
}

/** Qualité d'un aperçu : phrases longues et mots pleins devant les énumérations. */
function shop_search_excerpt_score(string $excerpt): int
{
    $score = min(20, (int) floor(mb_strlen($excerpt) / 10));
    foreach (preg_split('/\s+/u', $excerpt) ?: [] as $word) {
        $length = mb_strlen($word);
        if ($length === 1) $score -= 6;
        elseif ($length >= 4) $score += 2;
    }
    return $score;
}

/** Aperçu d'une page autour d'un mot trouvé, le plus lisible des candidats. */
function shop_search_snippet(array $page, array $words, string $needle): string
{
    $excerpts = (array) ($page['excerpts'] ?? []);
    $passes = [array_values(array_filter($excerpts, 'shop_search_clean_excerpt')), $excerpts];
    foreach ($passes as $pass) {
        $best = '';
        $bestScore = PHP_INT_MIN;
        foreach ($pass as $excerpt) {
            if (str_contains($excerpt, '_')) continue;
            $norm = shop_search_norm($excerpt);
            $position = $needle !== '' ? strpos($norm, $needle) : false;
            if ($position === false) {
                foreach ($words as $word) {
                    $position = strpos($norm, $word);
                    if ($position !== false) break;
                }
            }
            if ($position === false) continue;
            $score = shop_search_excerpt_score($excerpt);
            if ($score <= $bestScore) continue;
            $bestScore = $score;
            $start = max(0, (int) $position - 45);
            $snippet = mb_substr($excerpt, $start, 150);
            if ($start > 0) {
                $space = mb_strpos($snippet, ' ');
                if ($space !== false && $space < 15) $snippet = mb_substr($snippet, $space + 1);
                $snippet = '…' . $snippet;
            }
            if (mb_strlen($excerpt) > $start + 150) {
                $space = mb_strrpos($snippet, ' ');
                if ($space !== false && mb_strlen($snippet) - $space < 20) $snippet = mb_substr($snippet, 0, $space);
                $snippet .= '…';
            }
            $best = $snippet;
        }
        if ($best !== '') return $best;
    }
    return '';
}

/** Motif LIKE échappé (les % et _ saisis restent littéraux). */
function shop_search_like(string $word): string
{
    return '%' . addcslashes($word, '\\%_') . '%';
}

/**
 * Même expression côté SQLite, sans accents ni majuscules : LIKE ne sait pas
 * comparer « annees » à « années », le repli se fait donc dans la requête.
 */
function shop_search_fold_sql(string $expr): string
{
    $out = 'LOWER(' . $expr . ')';
    foreach ([
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae',
    ] as $from => $to) {
        $out = 'REPLACE(' . $out . ', ' . "'" . $from . "', '" . $to . "')";
    }
    return $out;
}

/** Prix affiché : certaines fiches portent déjà « 35 € », d'autres « 35 ». */
function shop_search_money(string $value): string
{
    $value = trim($value);
    if ($value === '') return '';
    return is_numeric(str_replace(',', '.', $value)) ? $value . ' €' : $value;
}

/** Joint des parties d'une fiche en retirant les vides. */
function shop_search_join(array $parts, string $glue = ' · '): string
{
    $out = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '' && !in_array($part, $out, true)) $out[] = $part;
    }
    return implode($glue, $out);
}

/**
 * Fiches gérées par la boutique : table, colonnes fouillées, page qui les gère.
 * Chaque source donne son libellé, son aperçu et son adresse à partir de la
 * ligne lue ; une table absente (plugin non installé) est simplement ignorée.
 */
function shop_search_sources(): array
{
    return [
        [
            'group' => 'Pièces',
            'table' => 'products',
            'page' => '/admin/catalog.php',
            'select' => 'ref, name, cat, brand, price, promo_price, stock, sold_at, description',
            'hay' => ['ref', 'name', 'cat', 'sous_categorie', 'brand', 'distributor', 'barcode', 'description', 'materials', 'size_text', 'etat', 'nature', 'colors'],
            'limit' => 12,
            'label' => static fn (array $row): string => (string) ($row['name'] !== '' ? $row['name'] : $row['ref']),
            'hint' => static function (array $row): string {
                $price = (string) ($row['promo_price'] ?: $row['price']);
                return shop_search_join([
                    (string) $row['ref'],
                    (string) $row['cat'],
                    shop_search_money($price),
                    $row['sold_at'] ? 'Vendue' : ((int) $row['stock'] > 0 ? 'En stock' : ''),
                ]);
            },
            'href' => static fn (array $row): string => '/admin/catalog.php?q=' . rawurlencode((string) ($row['ref'] ?: $row['name'])),
            'extra' => static fn (array $row): string => (string) $row['description'],
        ],
        [
            'group' => 'Commandes',
            'table' => 'orders',
            'page' => '/admin/orders.php',
            'select' => 'id, email, phone, ship_name, amount_total, status, payment_reference, tracking_number, items, created_at',
            'hay' => ['email', 'phone', 'ship_name', 'payment_reference', 'tracking_number', 'items', 'shipping_address', 'buyer_address', 'pickup_code'],
            'limit' => 10,
            'label' => static function (array $row): string {
                $who = shop_search_join([(string) $row['ship_name'], (string) $row['email']], ' · ');
                return $who !== '' ? $who : 'Commande B' . (int) $row['id'];
            },
            'hint' => static function (array $row): string {
                $status = ['paid' => 'Payée', 'pending' => 'En attente', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée'][(string) $row['status']] ?? (string) $row['status'];
                return shop_search_join([
                    'Commande B' . (int) $row['id'],
                    function_exists('format_cents') ? format_cents((int) $row['amount_total']) : '',
                    $status,
                    $row['created_at'] ? date('d/m/Y', strtotime((string) $row['created_at'])) : '',
                ]);
            },
            'href' => static fn (array $row): string => '/admin/orders.php',
            'extra' => static function (array $row): string {
                $items = json_decode((string) $row['items'], true);
                if (!is_array($items)) return '';
                $names = [];
                foreach ($items as $item) {
                    $names[] = (string) (is_array($item) ? ($item['name'] ?? $item['ref'] ?? '') : $item);
                }
                return implode(' ', $names);
            },
        ],
        [
            'group' => 'Médiathèque',
            'table' => 'media_library',
            'page' => '/admin/media.php',
            'select' => 'id, path, label, tags, origin_ref, origin_name, type, created_at',
            'hay' => ['label', 'path', 'tags', 'origin_ref', 'origin_name'],
            'limit' => 10,
            'label' => static fn (array $row): string => (string) ($row['label'] !== '' ? $row['label'] : basename((string) $row['path'])),
            'hint' => static function (array $row): string {
                return shop_search_join([
                    (string) $row['type'] === 'video' ? 'Vidéo' : 'Image',
                    (string) $row['tags'],
                    (string) $row['origin_ref'] !== '' ? 'Pièce ' . $row['origin_ref'] : '',
                ]);
            },
            'href' => static fn (array $row): string => '/admin/media.php?q=' . rawurlencode((string) ($row['label'] !== '' ? $row['label'] : basename((string) $row['path']))),
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Comptes',
            'table' => 'accounts',
            'page' => '/admin/accounts.php',
            'select' => 'id, role, name, email, username, phone, notes, is_active',
            'hay' => ['name', 'email', 'username', 'phone', 'notes', 'role'],
            'limit' => 8,
            'label' => static fn (array $row): string => (string) ($row['name'] !== '' ? $row['name'] : $row['email']),
            'hint' => static function (array $row): string {
                $role = function_exists('account_role_label') ? account_role_label((string) $row['role']) : (string) $row['role'];
                return shop_search_join([(string) $row['email'], $role, (int) $row['is_active'] === 1 ? '' : 'Désactivé']);
            },
            'href' => static fn (array $row): string => '/admin/accounts.php?q=' . rawurlencode((string) ($row['email'] !== '' ? $row['email'] : $row['name'])),
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Offres de prix',
            'table' => 'price_offers',
            'page' => '/admin/offers.php',
            'select' => 'id, ref, name, email, amount_cents, message, status, created_at',
            'hay' => ['ref', 'name', 'email', 'message'],
            'limit' => 8,
            'label' => static fn (array $row): string => shop_search_join([(string) $row['name'], (string) $row['email']], ' · ') ?: 'Offre #' . (int) $row['id'],
            'hint' => static function (array $row): string {
                return shop_search_join([
                    'Offre sur ' . $row['ref'],
                    function_exists('format_cents') ? format_cents((int) $row['amount_cents']) : '',
                    (string) $row['status'] === 'done' ? 'Traitée' : 'Nouvelle',
                    mb_substr((string) $row['message'], 0, 70),
                ]);
            },
            'href' => static fn (array $row): string => '/admin/offers.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Remises',
            'table' => 'cart_discounts',
            'page' => '/admin/discounts.php',
            'select' => 'id, kind, threshold, type, value, label, is_active',
            'hay' => ['label', 'kind', 'type'],
            'limit' => 8,
            'label' => static fn (array $row): string => (string) ($row['label'] !== '' ? $row['label'] : 'Remise #' . (int) $row['id']),
            'hint' => static fn (array $row): string => shop_search_join([(string) $row['kind'], (string) $row['type'] . ' ' . (string) $row['value'], (int) $row['is_active'] === 1 ? 'Active' : 'Inactive']),
            'href' => static fn (array $row): string => '/admin/discounts.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Livraison',
            'table' => 'shipping_carriers',
            'page' => '/admin/shipping.php',
            'select' => 'id, name, is_active',
            'hay' => ['name'],
            'limit' => 8,
            'label' => static fn (array $row): string => (string) $row['name'],
            'hint' => static fn (array $row): string => 'Transporteur · ' . ((int) $row['is_active'] === 1 ? 'actif' : 'inactif'),
            'href' => static fn (array $row): string => '/admin/shipping.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Shooting',
            'table' => 'shooting_requests',
            'page' => '/admin/shop-shooting.php',
            'select' => 'id, author_name, place, shot, shot_label, caption, status, created_at',
            'hay' => ['author_name', 'place', 'shot', 'shot_label', 'caption'],
            'limit' => 8,
            'label' => static fn (array $row): string => shop_search_join([(string) $row['shot_label'], (string) $row['place']], ' · ') ?: 'Photo #' . (int) $row['id'],
            'hint' => static function (array $row): string {
                $status = ['pending' => 'À valider', 'published' => 'Publiée', 'refused' => 'Refusée'][(string) $row['status']] ?? (string) $row['status'];
                return shop_search_join([(string) $row['author_name'], $status, mb_substr((string) $row['caption'], 0, 70)]);
            },
            'href' => static fn (array $row): string => '/admin/shop-shooting.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Diaporamas',
            'table' => 'hero_slides',
            'page' => '/admin/hero.php',
            'select' => 'id, path, caption',
            'hay' => ['caption', 'path'],
            'limit' => 6,
            'label' => static fn (array $row): string => (string) ($row['caption'] !== '' ? $row['caption'] : basename((string) $row['path'])),
            'hint' => static fn (array $row): string => 'Visuel du hero',
            'href' => static fn (array $row): string => '/admin/hero.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Diaporamas',
            'table' => 'slideshow_slides',
            'page' => '/admin/slideshow.php',
            'select' => 'id, kind, path, product_ref, caption',
            'hay' => ['caption', 'path', 'product_ref'],
            'limit' => 6,
            'label' => static fn (array $row): string => (string) ($row['caption'] !== '' ? $row['caption'] : basename((string) $row['path'])),
            'hint' => static fn (array $row): string => shop_search_join(['Diaporama boutique', (string) $row['product_ref'] !== '' ? 'Pièce ' . $row['product_ref'] : '']),
            'href' => static fn (array $row): string => '/admin/slideshow.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Mises en avant',
            'table' => 'promo_visuals',
            'page' => '/admin/promos.php',
            'select' => 'ref, path, headline, cta, price, promo_price',
            'hay' => ['ref', 'headline', 'cta'],
            'limit' => 6,
            'label' => static fn (array $row): string => (string) ($row['headline'] !== '' ? $row['headline'] : 'Visuel promo ' . $row['ref']),
            'hint' => static fn (array $row): string => shop_search_join([(string) $row['ref'], (string) $row['cta'], shop_search_money((string) $row['promo_price'])]),
            'href' => static fn (array $row): string => '/admin/promos.php',
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'E-mails',
            'table' => 'mail_opens',
            'page' => '/admin/emails.php',
            'select' => 'id, email, subject, source, context, sent_at, first_open_at, open_count',
            'hay' => ['email', 'subject', 'source', 'context'],
            'limit' => 8,
            'label' => static fn (array $row): string => (string) ($row['subject'] !== '' ? $row['subject'] : $row['email']),
            'hint' => static fn (array $row): string => shop_search_join([
                (string) $row['email'],
                (string) $row['context'],
                (int) $row['open_count'] > 0
                    ? 'Ouvert ' . (int) $row['open_count'] . '×' . ($row['first_open_at'] ? ' le ' . date('d/m/Y', strtotime((string) $row['first_open_at'])) : '')
                    : 'Non ouvert',
            ]),
            'href' => static fn (array $row): string => '/admin/emails.php?q=' . rawurlencode((string) $row['email']),
            'extra' => static fn (array $row): string => '',
        ],
        [
            'group' => 'Plugins',
            'table' => 'plugins',
            'page' => '/admin/plugins.php',
            'select' => 'id, enabled, version',
            'hay' => ['id'],
            'limit' => 20,
            'label' => static fn (array $row): string => (string) $row['id'],
            'hint' => static fn (array $row): string => 'Plugin · ' . ((int) $row['enabled'] === 1 ? 'activé' : 'désactivé') . ' · v' . (string) $row['version'],
            'href' => static fn (array $row): string => '/admin/plugins.php',
            'extra' => static fn (array $row): string => '',
        ],
    ];
}

/**
 * Textes du site (table content, une seule ligne) : chaque champ devenu
 * lisible porte son propre résultat, pour retrouver un paragraphe au mot près.
 */
function shop_search_content_fields(): array
{
    return [
        'site_name' => ['Nom du site', '/admin/index.php?s=general'],
        'site_tagline' => ['Signature', '/admin/index.php?s=general'],
        'hero_eyebrow' => ['Accueil · sur-titre', '/admin/index.php?s=pages'],
        'hero_title' => ['Accueil · titre', '/admin/index.php?s=pages'],
        'hero_subtitle' => ['Accueil · sous-titre', '/admin/index.php?s=pages'],
        'story_eyebrow' => ['Histoire · sur-titre', '/admin/index.php?s=pages'],
        'story_title' => ['Histoire · titre', '/admin/index.php?s=pages'],
        'story_text' => ['Histoire · texte', '/admin/index.php?s=pages'],
        'story_photo_caption' => ['Histoire · légende de la photo', '/admin/index.php?s=pages'],
        'principle1_title' => ['Principe 1 · titre', '/admin/index.php?s=pages'],
        'principle1_text' => ['Principe 1 · texte', '/admin/index.php?s=pages'],
        'principle2_title' => ['Principe 2 · titre', '/admin/index.php?s=pages'],
        'principle2_text' => ['Principe 2 · texte', '/admin/index.php?s=pages'],
        'principle3_title' => ['Principe 3 · titre', '/admin/index.php?s=pages'],
        'principle3_text' => ['Principe 3 · texte', '/admin/index.php?s=pages'],
        'contact_address' => ['Contact · adresse', '/admin/index.php?s=general'],
        'contact_hours' => ['Contact · horaires', '/admin/index.php?s=general'],
        'contact_delivery' => ['Contact · livraison', '/admin/index.php?s=general'],
        'pickup_label' => ['Retrait · intitulé', '/admin/index.php?s=paiement'],
        'shipping_label' => ['Expédition · intitulé', '/admin/index.php?s=paiement'],
        'pickup_only_message' => ['Message retrait uniquement', '/admin/index.php?s=paiement'],
    ];
}

/** Fiches correspondant à la recherche, pour un rôle donné. */
function shop_search_data_entries(string $query, string $role): array
{
    $needle = shop_search_norm($query);
    $words = array_values(array_filter(explode(' ', $needle), static fn (string $word): bool => mb_strlen($word) >= 2));
    if (!$words) return [];

    $items = [];
    // Le rôle est vérifié avant chaque source : une fiche n'arrive jamais ici
    // sans que la page qui la gère soit ouverte au compte connecté.
    $add = static function (string $group, string $label, string $hint, string $href) use (&$items): void {
        $label = trim($label);
        if ($label === '' || $href === '') return;
        $items[] = ['group' => $group, 'label' => $label, 'hint' => mb_substr(trim($hint), 0, 160), 'href' => $href];
    };

    foreach (shop_search_sources() as $source) {
        if (!admin_role_can_access($role, (string) $source['page'])) continue;
        $hay = '(' . implode(" || ' ' || ", array_map(static fn (string $col): string => 'COALESCE(' . $col . ", '')", $source['hay'])) . ')';
        $sql = 'SELECT * FROM (SELECT ' . $source['select'] . ', ' . $hay . ' AS _hay, ' . shop_search_fold_sql($hay) . ' AS _fold FROM ' . $source['table'] . ') WHERE ';
        $sql .= implode(' AND ', array_fill(0, count($words), "_fold LIKE ? ESCAPE '\\'"));
        $sql .= ' LIMIT ' . (int) $source['limit'];
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(array_map('shop_search_like', $words));
            $rows = $stmt->fetchAll();
        } catch (Throwable $error) {
            continue; // table absente (plugin non installé) ou colonne manquante
        }
        foreach ($rows as $row) {
            $row = (array) $row;
            $extra = is_callable($source['extra'] ?? null) ? (string) $source['extra']($row) : '';
            $label = (string) $source['label']($row);
            $hint = (string) $source['hint']($row);
            $haystack = shop_search_norm($label . ' ' . $hint . ' ' . $extra . ' ' . (string) ($row['_hay'] ?? ''));
            $matched = true;
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) continue;
            $add((string) $source['group'], $label, $hint, (string) $source['href']($row));
        }
    }

    if (admin_role_can_access($role, '/admin/index.php')) {
        try {
            $row = db()->query('SELECT * FROM content LIMIT 1')->fetch() ?: [];
        } catch (Throwable $error) {
            $row = [];
        }
        foreach (shop_search_content_fields() as $field => [$label, $href]) {
            $value = trim(strip_tags((string) ($row[$field] ?? '')));
            if ($value === '' || in_array($field, ['hero_photo', 'story_photo'], true)) continue;
            $haystack = shop_search_norm($value);
            $matched = true;
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    $matched = false;
                    break;
                }
            }
            if (!$matched) continue;
            $position = strpos($haystack, $words[0]);
            $snippet = $position === false ? mb_substr($value, 0, 140) : mb_substr($value, max(0, $position - 40), 140);
            $add('Textes du site', $label, trim(($position !== false && $position > 40 ? '…' : '') . $snippet), $href);
        }
    }

    return $items;
}

/** Résultats de la recherche, les plus pertinents d'abord. */
function shop_search_query(string $query, string $role, int $limit = 40): array
{
    $needle = shop_search_norm($query);
    $words = array_values(array_filter(explode(' ', $needle), static fn (string $word): bool => mb_strlen($word) >= 2));
    if ($needle === '' || !$words) return [];

    $results = [];
    foreach (shop_search_page_index()['pages'] as $page) {
        if (!admin_role_can_access($role, '/admin/' . (string) $page['file'])) continue;
        $label = shop_search_norm((string) ($page['label'] ?? ''));
        $terms = ' ' . (string) ($page['terms'] ?? '') . ' ';
        $inLabel = $label !== '' && str_contains($label, $needle);
        $hits = 0;
        $allWords = true;
        foreach ($words as $word) {
            $count = substr_count($terms, ' ' . $word);
            if ($count === 0) {
                $allWords = false;
                break;
            }
            $hits += $count;
        }
        if (!$inLabel && !$allWords) continue;

        $score = 0;
        if ($label === $needle) $score += 160;
        elseif (str_starts_with($label, $needle)) $score += 100;
        elseif ($inLabel) $score += 60;
        if ($allWords) $score += 25;
        $score += min(60, $hits * 6);

        $snippet = shop_search_snippet($page, $words, $needle);
        $results[] = [
            'group' => (string) ($page['group'] ?? 'Pages'),
            'label' => (string) ($page['label'] ?? ''),
            'hint' => $snippet !== '' ? $snippet : 'Correspondance dans la page',
            'href' => (string) ($page['href'] ?? ''),
            'score' => $score,
        ];
    }

    foreach (shop_search_data_entries($query, $role) as $item) {
        $label = shop_search_norm((string) $item['label']);
        $score = 40;
        if (str_starts_with($label, $needle)) $score += 80;
        elseif (str_contains($label, $needle)) $score += 40;
        $results[] = [
            'group' => (string) $item['group'],
            'label' => (string) $item['label'],
            'hint' => (string) $item['hint'],
            'href' => (string) $item['href'],
            'score' => $score,
        ];
    }

    usort($results, static fn (array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcasecmp($a['label'], $b['label']));

    $seen = [];
    $out = [];
    foreach ($results as $result) {
        $key = $result['href'] . '|' . $result['label'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        unset($result['score']);
        $out[] = $result;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Entrées de navigation proposées à vide dans la palette (libellé + catégorie). */
function shop_search_palette_items(string $role): array
{
    $items = [];
    foreach (admin_menu_layout(admin_menu_items($role)) as $group) {
        foreach ($group['items'] as $item) {
            $items[] = [
                'group' => (string) $group['label'],
                'label' => (string) $item[1],
                'hint' => '',
                'href' => (string) $item[0],
            ];
        }
    }
    return $items;
}
