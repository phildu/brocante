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
    if (!GEMINI_API_KEY) return null;
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
 * Recherche du prix du marché. $ctx : name, description, materials, etat (libellé), nature (texte), size_text, notes ;
 * $imagePaths : photos pour reconnaître l'article ; $comparables : pièces similaires du catalogue.
 * Retourne ['web' => [...], 'sources' => [...], 'queries' => [...]] ou null.
 */
function price_research(array $ctx, array $imagePaths, array $comparables): ?array
{
    $known = [];
    foreach (['name' => 'Nom', 'nature' => 'Nature', 'description' => 'Description', 'materials' => 'Matières', 'size_text' => 'Taille', 'etat' => 'État'] as $key => $label) {
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
