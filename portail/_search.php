<?php
// Recherche globale du portail : indexe le texte lisible des pages
// d'administration et les fiches gérées (boutiques, galeries, équipe,
// plugins, inscriptions, tâches) pour la palette ⌘K (voir admin.js).
// L'index des pages est mis en cache dans data/portail/search-index.json
// et reconstruit dès qu'une page change.

require_once __DIR__ . '/_bootstrap.php';

const PORTAIL_SEARCH_CACHE = PORTAIL_ROOT . '/data/portail/search-index.json';

/** Normalise pour comparer : minuscules, sans accents, un seul espace. */
function portail_search_norm(string $text): string
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

/** Mots trop courants (français et vocabulaire de code) : ils ne discriminent pas les pages. */
function portail_search_stopwords(): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_flip(explode(' ', 'a au aux avec ce ces cet cette dans de des du elle elles en et eux il ils je la le les leur leurs lui ma mais me mes moi mon ne nos notre nous on ou par pas pour qu que quel quelle quels quelles qui sa se ses si son sont sur ta te tes toi ton tu un une vos votre vous y d l j n s t c m plus comme donc alors encore ici cela ceci celui celle ceux celles dont entre vers chez sans sous avant apres pendant depuis lors afin tout tous toute toutes tres meme aussi etre ete avoir fait faire peut doit sera seront est php html css js script style div span class href src alt title target rel input form method action type name value placeholder label option select textarea meta link head body main section article header footer nav table tr td th img svg path circle rect line fill stroke width height view box true false null array string int integer float bool boolean function return echo print foreach while if else elseif endif endforeach isset empty count strlen substr strpos explode implode preg_match preg_replace file_get_contents file_put_contents json encode decode date time new this self static public private protected const use require include namespace global throw try catch finally class extends implements instance void mixed never post get request session server globals'));
    }
    return $stop;
}

/** Mots indexables d'un texte (normalisés, dédupliqués). */
function portail_search_keywords(string $text): array
{
    $stop = portail_search_stopwords();
    $out = [];
    foreach (preg_split('/\s+/', portail_search_norm($text)) ?: [] as $word) {
        if (mb_strlen($word) < 3 || ctype_digit($word) || isset($stop[$word])) continue;
        $out[$word] = true;
        if (count($out) >= 600) break;
    }
    return array_keys($out);
}

/** Déséchappe une chaîne PHP lue dans le source. */
function portail_search_unquote(string $value): string
{
    return trim(str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $value));
}

/** Valeur d'une clé littérale d'un appel `portail_page_start([...])`. */
function portail_search_literal(string $src, string $key): string
{
    $re = "/['\"]" . preg_quote($key, '/') . "['\"]\s*=>\s*(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/s";
    if (!preg_match($re, $src, $m)) return '';
    $value = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
    return portail_search_unquote($value);
}

/** Texte lisible d'une page : HTML hors scripts/styles + chaînes PHP. */
function portail_search_readable(string $src): string
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
function portail_search_excerpts(string $text): array
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
        $key = portail_search_norm($chunk);
        if ($key === '' || isset($out[$key])) continue;
        $out[$key] = $chunk;
        if (count($out) >= 160) break;
    }
    return array_values($out);
}

/** Pages candidates : fichiers du portail qui affichent une page d'administration. */
function portail_search_page_files(): array
{
    global $portailLocal;
    $root = __DIR__;
    $files = [];
    foreach (glob($root . '/*.php') ?: [] as $file) $files[] = $file;
    foreach (['recrutement', 'todo', 'vente'] as $dir) {
        foreach (glob($root . '/' . $dir . '/*.php') ?: [] as $file) $files[] = $file;
    }
    $files = array_values(array_unique($files));
    sort($files);

    // Les modules à mise en page propre (vente) ont leurs pages, mais aussi
    // leurs endpoints et pages publiques : ces fichiers ne sont pas des pages.
    $excluded = ['action.php', 'api.php', 'connexion.php', 'deconnexion.php', 'logout.php', 'postuler.php'];
    $out = [];
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '' || $base[0] === '_') continue;
        $rel = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');
        $inModule = str_contains($rel, '/');
        if ($inModule && (in_array($base, $excluded, true) || str_ends_with($base, '-action.php'))) continue;
        if ($rel === 'journal-local.php' && empty($portailLocal)) continue;
        $src = @file_get_contents($file);
        if (!is_string($src) || $src === '') continue;
        $isPage = str_contains($src, 'portail_page_start(') || str_contains($src, 'ga_head(');
        if (!$isPage && $inModule) $isPage = str_contains($src, 'fv_layout_start(');
        if (!$isPage) continue;
        $out[] = ['file' => $file, 'rel' => $rel, 'src' => $src];
    }
    return $out;
}

/** Motifs de navigation : page relative → groupe et libellé du menu. */
function portail_search_nav_index(): array
{
    $index = [];
    foreach (portail_nav_groups() as $group) {
        foreach ($group['items'] as $item) {
            $patterns = $item['match'] ?? [basename((string) parse_url($item['href'], PHP_URL_PATH))];
            foreach ($patterns as $pattern) {
                $index[] = [
                    'pattern' => (string) $pattern,
                    'group' => $group['label'],
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                ];
            }
        }
    }
    return $index;
}

function portail_search_nav_match(string $rel, array $navIndex): ?array
{
    foreach ($navIndex as $nav) {
        if (fnmatch($nav['pattern'], $rel)) return $nav;
    }
    return null;
}

/** Libellés du module Forces de vente (certaines pages passent un titre dynamique). */
function portail_search_vente_labels(): array
{
    static $labels = null;
    if ($labels !== null) return $labels;
    $labels = [];
    $file = __DIR__ . '/vente/_bootstrap.php';
    if (is_file($file)) {
        $src = (string) @file_get_contents($file);
        if (preg_match_all("/\[\s*'([a-z0-9-]+\.php)'\s*,\s*'((?:[^'\\\\]|\\\\.)*)'\s*,/i", $src, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) $labels[$row[1]] = portail_search_unquote($row[2]);
        }
    }
    return $labels;
}

/** Construit une entrée d'index pour une page. */
function portail_search_build_page(array $page, array $navIndex): ?array
{
    $src = $page['src'];
    $rel = $page['rel'];
    $nav = portail_search_nav_match($rel, $navIndex);
    // Les métadonnées utiles sont celles de l'appel portail_page_start(), pas
    // les « title » des tableaux de données de la page.
    $header = '';
    $headerPos = strpos($src, 'portail_page_start(');
    if ($headerPos !== false) $header = substr($src, $headerPos, 3000);

    $label = '';
    if (preg_match('/ga_head\s*\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/', $src, $m)) {
        $label = portail_search_unquote(($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? ''));
    }
    if ($label === '') $label = portail_search_literal($header, 'title');
    if ($label === '' && preg_match("/['\"]title['\"]\s*=>\s*([A-Z][A-Z0-9_]+)/", $header, $m)) {
        if (preg_match('/const\s+' . preg_quote($m[1], '/') . "\s*=\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $c)) {
            $label = portail_search_unquote($c[1]);
        }
    }
    if ($label === '') {
        $fvPos = strpos($src, 'fv_layout_start(');
        if ($fvPos !== false) {
            $fvHeader = substr($src, $fvPos, 500);
            if (preg_match('/fv_layout_start\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $fvHeader, $m)) {
                $label = portail_search_unquote($m[1]);
            }
        }
    }
    if ($label === '' && str_starts_with($rel, 'vente/')) {
        $label = (string) (portail_search_vente_labels()[basename($rel)] ?? '');
    }
    if ($label === '' && preg_match('#<title>([^<]+)</title>#i', $src, $m)) {
        $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!str_contains($title, '<?') && !str_contains($title, '?>')) {
            $title = trim(preg_replace('/\s+[—–|·-]\s+Portail.*$/u', '', $title) ?? $title);
            if ($title !== '') $label = $title;
        }
    }
    if ($label === '' && $nav) $label = (string) $nav['label'];
    if ($label === '') {
        $label = ucfirst(str_replace(['-', '_'], ' ', pathinfo($rel, PATHINFO_FILENAME)));
        if ($label === 'Index' && $nav) $label = (string) $nav['label'];
    }
    $label = rtrim($label, " —-–·");

    $desc = portail_search_literal($header, 'desc');
    $group = $nav ? (string) $nav['group'] : portail_search_literal($header, 'eyebrow');
    if (str_starts_with($rel, 'vente/')) $group = 'Forces de vente';
    if ($group === '') $group = 'Pages';

    $text = portail_search_readable($src);
    $excerpts = portail_search_excerpts($text);
    $terms = portail_search_keywords($label . ' ' . $desc . ' ' . $group . ' ' . implode(' ', $excerpts));
    if (!$terms) return null;

    return [
        'href' => $rel === 'index.php' ? '/portail/' : '/portail/' . $rel,
        'label' => $label,
        'group' => $group,
        'terms' => implode(' ', $terms),
        'excerpts' => $excerpts,
    ];
}

/** Index des pages, mis en cache tant que les fichiers ne changent pas. */
function portail_search_page_index(): array
{
    static $memory = null;
    if ($memory !== null) return $memory;

    $files = portail_search_page_files();
    $signature = [];
    foreach ([__FILE__, __DIR__ . '/_chrome.php'] as $extra) {
        $signature[] = basename($extra) . ':' . (int) @filesize($extra) . ':' . (int) @filemtime($extra);
    }
    foreach ($files as $page) {
        $signature[] = $page['rel'] . ':' . (int) @filesize($page['file']) . ':' . (int) @filemtime($page['file']);
    }
    $sig = md5(implode('|', $signature));

    if (is_file(PORTAIL_SEARCH_CACHE)) {
        $cached = json_decode((string) @file_get_contents(PORTAIL_SEARCH_CACHE), true);
        if (is_array($cached) && ($cached['signature'] ?? '') === $sig && !empty($cached['pages'])) {
            return $memory = $cached;
        }
    }

    $navIndex = portail_search_nav_index();
    $pages = [];
    foreach ($files as $page) {
        $entry = portail_search_build_page($page, $navIndex);
        if ($entry !== null) $pages[] = $entry;
    }
    $index = ['signature' => $sig, 'built' => time(), 'pages' => $pages];

    $dir = dirname(PORTAIL_SEARCH_CACHE);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json !== false && is_dir($dir) && is_writable($dir)) {
        $tmp = PORTAIL_SEARCH_CACHE . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, PORTAIL_SEARCH_CACHE);
        } else {
            @unlink($tmp);
        }
    }
    return $memory = $index;
}

/** Aperçu lisible : on préfère les phrases sans reste de code (chemins, variables). */
function portail_search_clean_excerpt(string $excerpt): bool
{
    if (str_contains($excerpt, '_')) return false;
    return !preg_match('/\.php\b|[?&][a-z]+=|<\?|=>|::|\\\\|[{}]|\$/', $excerpt);
}

/** Aperçu de la page autour du premier mot trouvé. */
function portail_search_snippet(array $page, array $words, string $needle): string
{
    $excerpts = (array) ($page['excerpts'] ?? []);
    $passes = [array_values(array_filter($excerpts, 'portail_search_clean_excerpt')), $excerpts];
    foreach ($passes as $pass) {
        foreach ($pass as $excerpt) {
            if (str_contains($excerpt, '_')) continue;
            $norm = portail_search_norm($excerpt);
            $position = $needle !== '' ? strpos($norm, $needle) : false;
            if ($position === false) {
                foreach ($words as $word) {
                    $position = strpos($norm, $word);
                    if ($position !== false) break;
                }
            }
            if ($position === false) continue;
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
            return $snippet;
        }
    }
    return '';
}

/** Fiches gérées par le portail (boutiques, galeries, équipe, plugins, inscriptions, tâches). */
function portail_search_data_entries(): array
{
    $items = [];
    $add = static function (string $group, string $label, string $hint, string $href, string $extra = '') use (&$items): void {
        $label = trim($label);
        if ($label === '' || $href === '') return;
        $items[] = [
            'group' => $group,
            'label' => $label,
            'hint' => $hint,
            'href' => $href,
            '_search' => portail_search_norm($label . ' ' . $hint . ' ' . $extra),
        ];
    };

    try {
        foreach (tenant_list() as $slug => $shop) {
            $categories = array_map(static fn ($c): string => (string) ($c['label'] ?? ''), (array) ($shop['categories'] ?? []));
            $add(
                'Commerces',
                (string) ($shop['name'] ?? $slug),
                portail_shop_label($slug, $shop) . ' · ' . business_presence_label(portail_presence_mode($shop)),
                '/portail/?voir=' . rawurlencode($slug),
                $slug . ' ' . (string) ($shop['admin_user'] ?? '') . ' ' . implode(' ', $categories)
            );
        }
    } catch (Throwable $error) {
        // Liste des boutiques indisponible : les autres sources restent utilisables.
    }

    try {
        if (is_file(PORTAIL_ROOT . '/includes/galleries.php')) require_once PORTAIL_ROOT . '/includes/galleries.php';
        foreach (gallery_list() as $slug => $gallery) {
            $hint = trim((string) ($gallery['tagline'] ?? ''));
            if ($hint === '') $hint = mb_substr(trim((string) ($gallery['description'] ?? '')), 0, 100);
            $add(
                'Galeries',
                (string) ($gallery['name'] ?? $slug),
                'Galerie' . ($hint !== '' ? ' · ' . $hint : ''),
                '/portail/galeries.php?g=' . rawurlencode($slug),
                $slug . ' ' . (string) ($gallery['domain'] ?? '') . ' ' . (string) ($gallery['type'] ?? '') . ' ' . (string) ($gallery['description'] ?? '')
            );
        }
    } catch (Throwable $error) {
    }

    try {
        if (is_file(PORTAIL_ROOT . '/includes/equipe.php')) require_once PORTAIL_ROOT . '/includes/equipe.php';
        foreach (eq_members() as $member) {
            $role = defined('EQ_CATEGORIES') ? (string) (EQ_CATEGORIES[$member['category']] ?? $member['category']) : (string) $member['category'];
            $hint = 'Équipe · ' . $role;
            if ((string) $member['email'] !== '') $hint .= ' · ' . $member['email'];
            $add(
                'Équipe',
                (string) ($member['name'] !== '' ? $member['name'] : $member['email']),
                $hint,
                '/portail/equipe.php?vue=membres&edit=' . rawurlencode((string) $member['id']) . '#fiche',
                (string) $member['city'] . ' ' . (string) $member['note'] . ' ' . implode(' ', (array) $member['domains'])
            );
        }
    } catch (Throwable $error) {
    }

    try {
        if (is_file(PORTAIL_ROOT . '/includes/plugins.php')) require_once PORTAIL_ROOT . '/includes/plugins.php';
        foreach (plugin_catalog() as $id => $manifest) {
            $description = (string) ($manifest['description'] ?? '');
            $add(
                'Plugins',
                (string) ($manifest['name'] ?? $id),
                'Plugin · ' . mb_substr($description, 0, 110),
                '/portail/plugins.php#plugin-' . rawurlencode((string) $id),
                (string) $id . ' ' . $description
            );
        }
    } catch (Throwable $error) {
    }

    try {
        if (is_file(PORTAIL_ROOT . '/includes/saas.php')) require_once PORTAIL_ROOT . '/includes/saas.php';
        foreach (saas_requests() as $request) {
            $name = trim((string) ($request['shop_name'] ?? ''));
            if ($name === '') $name = trim((string) ($request['preferred_shop_name'] ?? ''));
            if ($name === '') $name = trim((string) ($request['company_name'] ?? ''));
            if ($name === '') $name = trim((string) ($request['first_name'] ?? '') . ' ' . (string) ($request['last_name'] ?? ''));
            if ($name === '') $name = (string) ($request['email'] ?? $request['id'] ?? '');
            $hint = 'Inscription · ' . (string) ($request['email'] ?? '');
            if ((string) ($request['slug'] ?? '') !== '') $hint .= ' · ' . $request['slug'];
            $add(
                'Créations de boutiques',
                $name,
                $hint,
                '/portail/inscriptions.php',
                (string) ($request['city'] ?? '') . ' ' . (string) ($request['phone'] ?? '') . ' ' . (string) ($request['status'] ?? '')
            );
        }
    } catch (Throwable $error) {
    }

    $todoDb = PORTAIL_ROOT . '/data/todo/todo.db';
    if (is_file($todoDb)) {
        try {
            $pdo = new PDO('sqlite:' . $todoDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach ($pdo->query('SELECT id, title, detail, category, status, shop FROM tasks') as $task) {
                $add(
                    'Tâches',
                    (string) $task['title'],
                    ((string) $task['status'] === 'done' ? 'Fait · ' : 'À faire · ') . ((string) $task['detail'] !== '' ? $task['detail'] : (string) $task['category']),
                    '/portail/todo/',
                    (string) $task['detail'] . ' ' . (string) $task['shop'] . ' ' . (string) $task['category']
                );
            }
        } catch (Throwable $error) {
        }
    }

    return $items;
}

/** Résultats de la recherche globale, les plus pertinents d'abord. */
function portail_search_query(string $query, int $limit = 30): array
{
    $needle = portail_search_norm($query);
    $words = array_values(array_filter(explode(' ', $needle), static fn (string $word): bool => mb_strlen($word) >= 2));
    if ($needle === '' || !$words) return [];

    $results = [];
    foreach (portail_search_page_index()['pages'] as $page) {
        $label = portail_search_norm((string) ($page['label'] ?? ''));
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

        $snippet = portail_search_snippet($page, $words, $needle);
        $results[] = [
            'group' => (string) ($page['group'] ?? 'Pages'),
            'label' => (string) ($page['label'] ?? ''),
            'hint' => $snippet !== '' ? $snippet : 'Correspondance dans la page',
            'href' => (string) ($page['href'] ?? ''),
            'score' => $score,
        ];
    }

    foreach (portail_search_data_entries() as $item) {
        $haystack = (string) ($item['_search'] ?? '');
        $matched = true;
        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                $matched = false;
                break;
            }
        }
        if (!$matched) continue;

        $label = portail_search_norm((string) $item['label']);
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
