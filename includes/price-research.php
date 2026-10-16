<?php

// Recherche du prix du marché pour une pièce : le MÊME article (ou très proche), neuf et d'occasion, cherché sur le web par
// Gemini (outil « Google Search » : recherche réelle, avec ses sources), croisé avec les pièces similaires déjà
// enregistrées dans le catalogue. Le résultat est une aide à la décision — fourchettes, sources, prix conseillé — que le
// vendeur applique ou non au champ Prix.

/**
 * Appel Gemini avec recherche web. Retourne ['text' => réponse, 'sources' => [['title' => …, 'uri' => …], …],
 * 'queries' => [recherches effectuées]] ou null en cas d'échec. Les photos (chemins absolus) servent à reconnaître marque et modèle.
 */
function gemini_search_text(string $prompt, array $imagePaths = []): ?array
{
    if (!GEMINI_API_KEY || ai_gate() !== null) return null;
    $parts = [['text' => $prompt]];
    foreach (array_slice($imagePaths, 0, 2) as $path) {
        $data = @file_get_contents($path);
        if ($data === false) continue;
        $parts[] = ['inlineData' => ['mimeType' => @getimagesize($path)['mime'] ?? 'image/jpeg', 'data' => base64_encode($data)]];
    }
    $payload = ['contents' => [['parts' => $parts]], 'tools' => [['google_search' => new stdClass()]]];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 55, // sous le délai habituel des serveurs web (60 s)
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body !== false && $status < 400) {
            $data = json_decode($body, true);
            $candidate = $data['candidates'][0] ?? [];
            $text = '';
            foreach ($candidate['content']['parts'] ?? [] as $part) $text .= $part['text'] ?? '';
            if (trim($text) !== '') {
                $sources = [];
                foreach ($candidate['groundingMetadata']['groundingChunks'] ?? [] as $chunk) {
                    $uri = (string) ($chunk['web']['uri'] ?? '');
                    if ($uri !== '' && !isset($sources[$uri])) $sources[$uri] = ['title' => (string) ($chunk['web']['title'] ?? ''), 'uri' => $uri];
                }
                $queries = array_values(array_filter((array) ($candidate['groundingMetadata']['webSearchQueries'] ?? [])));
                ai_usage_log_search($data['usageMetadata'] ?? [], (bool) $queries);
                return ['text' => $text, 'sources' => array_values($sources), 'queries' => array_values(array_filter((array) ($candidate['groundingMetadata']['webSearchQueries'] ?? [])))];
            }
            error_log('gemini_search_text attempt ' . $attempt . ': HTTP ' . $status . ' sans texte');
        } else {
            error_log('gemini_search_text attempt ' . $attempt . ': HTTP ' . $status . ', ' . substr((string) $body, 0, 300));
        }
        if ($attempt === 0) sleep(2);
    }
    return null;
}

/** Montant en euros d'une valeur renvoyée par l'IA (« 35 », « 35,50 € », 35.5), ou null. */
function price_research_euros($v): ?float
{
    if (is_int($v) || is_float($v)) return $v > 0 ? round((float) $v, 2) : null;
    if (!is_string($v) || !preg_match('/\d[\d\s]*(?:[.,]\d{1,2})?/', $v, $m)) return null;
    $n = (float) str_replace([' ', ','], ['', '.'], $m[0]);
    return $n > 0 && $n < 1000000 ? round($n, 2) : null;
}

/** Fourchette {min, max, median} propre (min ≤ médiane ≤ max) à partir de la réponse de l'IA, ou null. */
function price_research_range($block): ?array
{
    if (!is_array($block)) return null;
    $min = price_research_euros($block['min'] ?? null);
    $max = price_research_euros($block['max'] ?? null);
    $median = price_research_euros($block['median'] ?? null);
    $values = array_values(array_filter([$min, $median, $max], static fn ($x) => $x !== null));
    if (!$values) return null;
    sort($values);
    return ['min' => $values[0], 'max' => end($values), 'median' => $median ?? $values[intdiv(count($values), 2)]];
}

/** « 35 € » ou « 35,50 € » : un montant écrit pour le champ Prix. */
function price_research_format(float $euros): string
{
    return (fmod($euros, 1.0) === 0.0 ? (string) (int) $euros : str_replace('.', ',', number_format($euros, 2, '.', ''))) . ' €';
}

/**
 * Marketplace d'Emmaüs (Label Emmaüs, label-emmaus.co : les articles d'occasion des communautés, comités d'amis et ressourceries) : annonces
 * réelles correspondant à l'article, avec leur prix. Page de résultats du catalogue lue côté serveur (mise en cache un jour : une seule requête
 * par recherche). Retourne ['query', 'n', 'median', 'min', 'max', 'items' => [['title', 'price', 'seller', 'url']], 'url'] ou null (rien trouvé, site injoignable).
 */
function price_research_emmaus(string $name, string $nature = ''): ?array
{
    require_once __DIR__ . '/barcode.php';
    // Mots utiles du nom : sans ponctuation ni mots vides, les cinq premiers, puis de moins en moins si rien ne sort.
    $stop = ['le', 'la', 'les', 'un', 'une', 'des', 'de', 'du', 'd', 'l', 'et', 'en', 'au', 'aux', 'à', 'a', 'avec', 'sans', 'pour', 'sur', 'dans', 'par', 'vintage', 'ancien', 'ancienne', 'années', 'annees', 'style', 'esprit', 'type', 'façon', 'facon', 'très', 'tres', 'bon', 'etat', 'état'];
    $words = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)), static fn ($w) => $w !== '' && mb_strlen($w) > 1 && !in_array($w, $stop, true) && !ctype_digit($w)));
    if (!$words) return null;
    // Le moteur du site cherche TOUS les mots : on retire les derniers jusqu'à obtenir assez d'annonces (jusqu'à un seul mot, recherche large).
    $best = null;
    foreach (array_unique([min(5, count($words)), min(3, count($words)), min(2, count($words)), 1]) as $take) {
        $found = price_research_emmaus_search(implode(' ', array_slice($words, 0, $take)));
        if ($found && $found['n'] >= 3) return $found;
        if ($found && (!$best || $found['n'] > $best['n'])) $best = $found;
    }
    return $best;
}

/** Une recherche sur le marketplace d'Emmaüs : les 12 premiers résultats (les plus pertinents), prix et vendeur. */
function price_research_emmaus_search(string $query): ?array
{
    $url = 'https://www.label-emmaus.co/fr/catalogue/?q=' . rawurlencode($query);
    $cache = barcode_cache_file('emmaus|' . $query);
    $page = null;
    if (is_file($cache) && time() - filemtime($cache) < 86400) {
        $page = json_decode((string) file_get_contents($cache), true);
    } else {
        $r = barcode_http($url, [], 12, 3000000);
        if ($r['status'] !== 200 || $r['body'] === '') return null;
        $items = [];
        foreach (array_slice(explode('product-description-wrapper', $r['body']), 1) as $block) {
            $block = substr($block, 0, 3000);
            if (!preg_match('#href="(/fr/[^"]+)"#', $block, $href) || !preg_match('#title="([^"]*)"#', $block, $title)) continue;
            // Le prix affiché en gros est le prix de vente ; le prix barré, s'il y en a un, est l'ancien.
            if (!preg_match('#text-success[^>]*>\s*([\d\s.,]+?)(?:&nbsp;|\s)*€#u', $block, $price) && !preg_match('#([\d\s]+(?:,\d{1,2})?)(?:&nbsp;|\s)*€#u', $block, $price)) continue;
            $cents = price_to_cents(trim(str_replace(["\xc2\xa0", ' '], '', $price[1])) . ' €');
            if (!$cents) continue;
            $seller = preg_match('#product-merchant-info">(.*?)</p>#s', $block, $m) ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1]))) : '';
            $items[] = ['title' => mb_substr(html_entity_decode((string) $title[1], ENT_QUOTES, 'UTF-8'), 0, 90), 'price' => $cents / 100, 'seller' => mb_substr($seller, 0, 60), 'url' => 'https://www.label-emmaus.co' . $href[1]];
            if (count($items) >= 12) break;
        }
        $page = ['items' => $items];
        $dir = dirname($cache);
        if (is_dir($dir) || @mkdir($dir, 0755, true)) @file_put_contents($cache, json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $items = $page['items'] ?? [];
    if (!$items) return null;
    $prices = array_column($items, 'price');
    sort($prices);
    return ['query' => $query, 'n' => count($prices), 'median' => round(comparable_quantile($prices, 0.5), 2), 'min' => $prices[0], 'max' => end($prices), 'items' => array_slice($items, 0, 6), 'url' => $url];
}

/**
 * Recherche du prix du marché. $ctx : name, description, materials, etat (libellé), nature (texte), size_text, notes ;
 * $imagePaths : photos pour reconnaître l'article ; $comparables : pièces similaires du catalogue.
 * Retourne ['web' => [...], 'sources' => [...], 'queries' => [...]] ou null.
 */
function price_research(array $ctx, array $imagePaths, array $comparables, ?array $emmaus = null): ?array
{
    $known = [];
    foreach (['name' => 'Nom', 'nature' => 'Nature', 'description' => 'Description', 'brand' => 'Marque', 'colors' => 'Couleurs', 'materials' => 'Matières', 'size_text' => 'Taille', 'etat' => 'État'] as $key => $label) {
        if (trim((string) ($ctx[$key] ?? '')) !== '') $known[] = $label . ' : ' . trim((string) $ctx[$key]);
    }
    $own = [];
    foreach (array_slice($comparables, 0, 5) as $p) {
        if (price_to_cents($p['price'])) $own[] = '« ' . $p['name'] . ' » ' . $p['price'];
    }
    $prompt = "Tu es expert en revente d'articles d'occasion et vintage pour la boutique en ligne « " . (get_content()['site_name'] ?: tenant('name')) . " » (" . tenant('ai.shop') . "). "
        . "Cherche sur le web (Google) le prix du MÊME article, ou le plus proche possible, neuf et d'occasion (sites de seconde main, boutiques de vintage, "
        . "enchères, sites de marques) — en France, en euros. Si la photo montre une marque, un modèle ou une référence, utilise-les pour identifier précisément l'article. "
        . "Article à évaluer — " . ($known ? implode(' ; ', $known) : 'voir la photo') . '. '
        . (trim((string) ($ctx['notes'] ?? '')) !== '' ? 'Précisions du vendeur : ' . trim((string) $ctx['notes']) . '. ' : '')
        . ($own ? 'Pour information, pièces similaires déjà vendues par la boutique : ' . implode(' ; ', $own) . '. ' : '')
        . ($emmaus ? "Annonces réelles d'occasion trouvées sur le marketplace d'Emmaüs (Label Emmaüs) pour « " . $emmaus['query'] . ' » : '
            . implode(' ; ', array_map(static fn (array $i): string => '« ' . $i['title'] . ' » ' . price_research_format((float) $i['price']), array_slice($emmaus['items'], 0, 6)))
            . '. Tiens-en compte comme repères de prix d\'occasion constatés (ce sont des prix demandés, pas des prix de vente conclus), si ces annonces correspondent bien à l\'article. ' : '')
        . "Réponds UNIQUEMENT avec un objet JSON strict, sans texte autour, sans markdown, de cette forme : "
        . '{"identification": "ce que tu as identifié (marque, modèle, époque) ou « non identifié »", '
        . '"new": {"min": nombre, "max": nombre, "median": nombre} ou null si aucun prix neuf trouvé, '
        . '"used": {"min": nombre, "max": nombre, "median": nombre} ou null si aucun prix d\'occasion trouvé, '
        . '"reliability": "haute | moyenne | faible", '
        . '"advice_price": nombre, "advice_text": "une phrase : pourquoi ce prix de vente est conseillé, compte tenu de l\'état annoncé"}. '
        . "Les nombres sont des euros, sans symbole. Le prix conseillé est celui auquel vendre CET exemplaire dans l'état indiqué (jamais le prix du neuf s'il est d'occasion) ; "
        . "si tu ne trouves rien de fiable, mets reliability à « faible » et explique-le dans advice_text au lieu d'inventer.";
    $res = gemini_search_text($prompt, $imagePaths);
    if (!$res) return null;
    $json = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($res['text'])), true);
    if (!is_array($json) && preg_match('/\{.*\}/s', $res['text'], $m)) $json = json_decode($m[0], true);
    if (!is_array($json)) return null;
    $advice = price_research_euros($json['advice_price'] ?? null);
    $reliability = mb_strtolower(trim((string) ($json['reliability'] ?? '')));
    return [
        'web' => [
            'identification' => mb_substr(trim((string) ($json['identification'] ?? '')), 0, 200),
            'new' => price_research_range($json['new'] ?? null),
            'used' => price_research_range($json['used'] ?? null),
            'reliability' => in_array($reliability, ['haute', 'moyenne', 'faible'], true) ? $reliability : 'faible',
            'advice_price' => $advice,
            'advice_text' => mb_substr(trim((string) ($json['advice_text'] ?? '')), 0, 400),
        ],
        'sources' => array_slice($res['sources'], 0, 8),
        'queries' => array_slice($res['queries'], 0, 5),
    ];
}

/**
 * Lien vers le prix du NEUF d'un article : une recherche web à part (le résultat porte alors de vraies sources), dont les 3 premières pages marchandes sont ouvertes
 * (leur redirection est suivie aussitôt : l'adresse finale de la page est gardée) ; la première où un prix du neuf plausible est retrouvé est proposée.
 * $range : fourchette du neuf déjà trouvée par la recherche « Marché » ({min, max}) qui borne le prix attendu. Retourne
 * ['url', 'price' (euros ou null), 'verified' (prix retrouvé dans la page), 'host'] ou null. Rien n'est enregistré : le vendeur applique le lien lui-même.
 */
function price_research_new_link(array $ctx, ?array $range): ?array
{
    require_once __DIR__ . '/barcode.php';
    $what = trim(($ctx['brand'] ?? '') . ' ' . ($ctx['name'] ?? ''));
    if ($what === '') return null;
    $prompt = 'Cherche sur le web le prix du NEUF de cet article : « ' . $what . ' »'
        . (trim((string) ($ctx['size_text'] ?? '')) !== '' ? ' (' . $ctx['size_text'] . ')' : '') . '. Réponds en une phrase : le prix du neuf en euros et le nom du site marchand où tu l\'as vu (boutique de la marque ou grand revendeur).';
    // Une réponse sans sources arrive parfois : un second essai suffit presque toujours.
    $res = null;
    for ($try = 0; $try < 2 && empty($res['sources']); $try++) $res = gemini_search_text($prompt, []);
    if (!$res || empty($res['sources'])) return null;
    $lo = $range ? max(1.0, (float) $range['min'] * 0.8) : 1.0;
    $hi = $range ? (float) $range['max'] * 1.25 : 100000.0;
    $mid = $range ? ((float) $range['min'] + (float) $range['max']) / 2 : null;
    $first = null;
    foreach (array_slice($res['sources'], 0, 3) as $src) {
        $r = barcode_http((string) $src['uri'], [], 6, 1500000);
        if ($r['status'] !== 200 || !str_contains(strtolower($r['type']), 'html')) continue;
        $url = pricing_safe_url($r['url']);
        if ($url === '' || str_contains(pricing_url_host($url), 'google.')) continue;
        $text = html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $r['body'])), ENT_QUOTES, 'UTF-8');
        $text = str_replace("\xc2\xa0", ' ', $text);
        $found = [];
        if (preg_match_all('/(\d{1,3}(?:[ .]\d{3})*|\d+)(?:[.,](\d{1,2}))?\s*(?:€|eur\b)|(?:€|eur\b)\s*(\d{1,3}(?:[ .]\d{3})*|\d+)(?:[.,](\d{1,2}))?/iu', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $int = $x[1] !== '' ? $x[1] : ($x[3] ?? '');
                $dec = $x[1] !== '' ? ($x[2] ?? '') : ($x[4] ?? '');
                $v = (float) (str_replace([' ', '.'], '', $int) . ($dec !== '' ? '.' . $dec : ''));
                if ($v >= $lo && $v <= $hi) $found[] = $v;
            }
        }
        $entry = ['url' => $url, 'host' => pricing_url_host($url), 'price' => null, 'verified' => false];
        if ($found) {
            usort($found, static fn ($a, $b) => abs($a - ($mid ?? $a)) <=> abs($b - ($mid ?? $b)));
            return ['price' => $found[0], 'verified' => true] + $entry;
        }
        $first ??= $entry;
    }
    return $first;
}

/** Ce que le catalogue sait : nombre de pièces similaires avec un prix fixe, médiane et fourchette en euros, détail. */
function price_research_catalogue(array $comparables): array
{
    $prices = [];
    $items = [];
    foreach ($comparables as $p) {
        $cents = price_to_cents($p['price']);
        if (!$cents) continue;
        $prices[] = $cents / 100;
        $items[] = ['ref' => $p['ref'], 'name' => $p['name'], 'price' => $p['price']];
    }
    sort($prices);
    return [
        'n' => count($prices),
        'median' => $prices ? round(comparable_quantile($prices, 0.5), 2) : null,
        'min' => $prices ? $prices[0] : null,
        'max' => $prices ? end($prices) : null,
        'items' => array_slice($items, 0, 6),
    ];
}
