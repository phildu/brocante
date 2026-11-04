<?php

// Moteur de suggestions de la boutique : « pièces similaires » classées par
// ressemblance (univers, nature, marque, couleurs, matières, mots, prix) et par
// les habitudes du visiteur (vues, recherches, filtres, panier, commandes).
//
// Les habitudes sont tracées sans donnée personnelle : un jeton aléatoire dans un
// cookie propre à la boutique, jamais l'adresse IP ni l'e-mail. La table vit dans
// la base du commerce (recommendation_events), donc chaque boutique garde les
// siennes. Un client connecté retrouve aussi ses commandes payées, même sur un
// autre appareil, par l'e-mail vérifié de son compte.

const REC_EVENT_RETENTION = 15552000; // 180 jours
const REC_COOKIE_LIFETIME = 31536000; // 1 an

function rec_ensure_schema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS recommendation_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      visitor TEXT NOT NULL,
      kind TEXT NOT NULL,
      ref TEXT,
      meta TEXT,
      created_at INTEGER NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rec_visitor ON recommendation_events(visitor, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rec_ref ON recommendation_events(ref, created_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rec_kind ON recommendation_events(kind, created_at)');
}

function rec_ready(): void
{
    static $ready = false;
    if ($ready) return;
    rec_ensure_schema(db());
    $ready = true;
}

/** Robots et outils : leurs visites ne nourrissent pas les habitudes d'un vrai visiteur. */
function rec_is_bot(): bool
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse|gtmetrix/i', $ua);
}

/**
 * Jeton anonyme du visiteur pour cette boutique. Un cookie de première partie
 * (même nom que la session, mais indépendant) le garde d'une visite à l'autre ;
 * seule son empreinte est enregistrée, jamais le jeton en clair.
 */
function rec_visitor_id(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $slug = function_exists('tenant_slug') ? tenant_slug() : 'shop';
    $base = function_exists('tenant_base_path') ? tenant_base_path() : '';
    $name = 'rec_' . substr(hash('sha256', $slug), 0, 10);
    $raw = (string) ($_COOKIE[$name] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $raw)) {
        $raw = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie($name, $raw, [
                'expires' => time() + REC_COOKIE_LIFETIME,
                'path' => $base !== '' ? rtrim($base, '/') . '/' : '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);
        }
        $_COOKIE[$name] = $raw;
    }
    return $id = hash('sha256', $raw . '|' . $slug);
}

/** Ajoute un événement. $kind : view, cart, order, search ou browse. */
function rec_track_event(string $kind, ?string $ref = null, array $meta = []): void
{
    if (!in_array($kind, ['view', 'cart', 'order', 'search', 'browse'], true) || rec_is_bot()) return;
    $ref = $ref !== null ? mb_substr(trim($ref), 0, 40) : null;
    if ($ref === '') $ref = null;
    try {
        rec_ready();
        $visitor = rec_visitor_id();
        $now = time();
        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        // Un même geste répété (rafraîchissement, retour arrière) ne pèse pas double.
        $window = ['view' => 600, 'cart' => 60, 'search' => 300, 'browse' => 900][$kind] ?? 0;
        if ($window > 0) {
            $stmt = db()->prepare("SELECT 1 FROM recommendation_events WHERE visitor = ? AND kind = ? AND IFNULL(ref, '') = ? AND IFNULL(meta, '') = ? AND created_at > ? LIMIT 1");
            $stmt->execute([$visitor, $kind, (string) $ref, (string) $metaJson, $now - $window]);
            if ($stmt->fetchColumn()) return;
        }

        // Plafond par visiteur : un script ne gonfle pas le profil indéfiniment.
        $stmt = db()->prepare('SELECT COUNT(*) FROM recommendation_events WHERE visitor = ? AND created_at > ?');
        $stmt->execute([$visitor, $now - 60]);
        if ((int) $stmt->fetchColumn() >= 120) return;

        db()->prepare('INSERT INTO recommendation_events (visitor, kind, ref, meta, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$visitor, $kind, $ref, $metaJson, $now]);

        if (random_int(1, 200) === 1) {
            db()->prepare('DELETE FROM recommendation_events WHERE created_at < ?')->execute([$now - REC_EVENT_RETENTION]);
        }
    } catch (Throwable $e) {
        error_log('recommandations : ' . $e->getMessage());
    }
}

function rec_track_product_view(string $ref): void
{
    rec_track_event('view', $ref);
}

function rec_track_cart(string $ref): void
{
    rec_track_event('cart', $ref);
}

function rec_track_search(string $query): void
{
    $query = trim(preg_replace('/\s+/u', ' ', $query));
    if (mb_strlen($query) < 2) return;
    rec_track_event('search', null, ['q' => mb_strtolower(mb_substr($query, 0, 80), 'UTF-8')]);
}

function rec_track_browse(string $cat, string $nature = ''): void
{
    $meta = [];
    if ($cat !== '' && $cat !== 'tous') $meta['cat'] = $cat;
    if ($nature !== '') $meta['nature'] = $nature;
    if ($meta) rec_track_event('browse', null, $meta);
}

/** Une commande payée nourrit les habitudes une seule fois (même si la page est rechargée). */
function rec_track_order(array $order): void
{
    if (rec_is_bot()) return;
    $orderId = (int) ($order['id'] ?? 0);
    $items = json_decode((string) ($order['items'] ?? '[]'), true) ?: [];
    if (!$items) return;
    try {
        rec_ready();
        if ($orderId > 0) {
            $meta = json_encode(['order_id' => $orderId], JSON_UNESCAPED_UNICODE);
            $stmt = db()->prepare("SELECT 1 FROM recommendation_events WHERE visitor = ? AND kind = 'order' AND meta = ? LIMIT 1");
            $stmt->execute([rec_visitor_id(), $meta]);
            if ($stmt->fetchColumn()) return;
        }
        foreach ($items as $item) {
            $ref = trim((string) ($item['ref'] ?? ''));
            if ($ref === '') continue;
            rec_track_event('order', $ref, $orderId > 0 ? ['order_id' => $orderId] : []);
        }
    } catch (Throwable $e) {
        error_log('recommandations commande : ' . $e->getMessage());
    }
}

// ── Analyse des pièces ───────────────────────────────────────────────────────

/** Texte ramené à des mots simples : minuscules, sans accents, sans ponctuation. */
function rec_fold(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = strtr($text, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ò' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ]);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $text));
}

/** Mots significatifs d'un texte (les petits mots grammaticaux sont ignorés). */
function rec_text_tokens(?string $text): array
{
    static $stop = [
        'les', 'des', 'une', 'dans', 'avec', 'pour', 'sur', 'par', 'aux', 'est', 'son', 'ses',
        'qui', 'que', 'pas', 'plus', 'tres', 'joli', 'jolie', 'beau', 'belle', 'bon', 'bien',
        'tout', 'toute', 'tous', 'toutes', 'comme', 'environ', 'article', 'objet', 'piece',
        'pieces', 'style', 'couleur', 'couleurs', 'etat', 'etats', 'vintage', 'ancien',
        'ancienne', 'grand', 'grande', 'petit', 'petite', 'autre', 'autres',
    ];
    preg_match_all('/[a-z0-9]{3,}/', rec_fold((string) $text), $m);
    $words = array_values(array_unique(array_filter($m[0], static fn (string $w): bool => !in_array($w, $stop, true))));
    return $words;
}

/** Valeurs d'une liste « bleu marine, blanc » (couleurs, matières). */
function rec_multi_values(?string $csv): array
{
    $parts = preg_split('/[,;\/|]+/u', (string) $csv) ?: [];
    $out = [];
    foreach ($parts as $part) {
        $v = trim(rec_fold($part));
        if ($v !== '') $out[$v] = true;
    }
    return array_keys($out);
}

/** Nombre de valeurs qui se recoupent, même écrites différemment (« bleu » / « bleu marine »). */
function rec_values_overlap(array $a, array $b): int
{
    $n = 0;
    foreach ($a as $va) {
        foreach ($b as $vb) {
            if ($va === $vb || str_contains($va, $vb) || str_contains($vb, $va)) { $n++; break; }
        }
    }
    return $n;
}

function rec_price_cents(mixed $price): ?int
{
    if (!function_exists('price_to_cents')) return null;
    $cents = price_to_cents((string) $price);
    return $cents !== null && $cents > 0 ? $cents : null;
}

/** Deux prix proches se ressemblent : 1 pour le même prix, 0 au-delà d'un rapport de 3. */
function rec_price_similarity(?int $a, ?int $b): float
{
    if ($a === null || $b === null || $a <= 0 || $b <= 0) return 0.0;
    $ratio = max($a, $b) / min($a, $b);
    return max(0.0, 1.0 - log($ratio) / log(3));
}

/** Fréquence des mots dans le catalogue : un mot rare distingue mieux que « céramique ». */
function rec_token_stats(array $products): array
{
    $df = [];
    foreach ($products as $p) {
        foreach (rec_text_tokens(($p['name'] ?? '') . ' ' . ($p['description'] ?? '')) as $word) {
            $df[$word] = ($df[$word] ?? 0) + 1;
        }
    }
    return ['df' => $df, 'n' => count($products)];
}

/** Ressemblance des textes, de 0 à 4 (cosinus pondéré par la rareté des mots). */
function rec_text_similarity(array $a, array $b, ?array $df = null, int $n = 0): float
{
    $shared = array_values(array_intersect($a, $b));
    if (!$shared) return 0.0;
    $weight = static function (string $word) use ($df, $n): float {
        return $df !== null && $n > 0 ? log(1 + $n / max(1, $df[$word] ?? 1)) : 1.0;
    };
    $num = 0.0;
    foreach ($shared as $word) $num += $weight($word);
    $sumA = 0.0;
    foreach ($a as $word) $sumA += $weight($word);
    $sumB = 0.0;
    foreach ($b as $word) $sumB += $weight($word);
    $den = sqrt($sumA * $sumB);
    return $den > 0 ? 4.0 * min(1.0, $num / $den) : 0.0;
}

/** Ressemblance de deux pièces, de 0 à ~32 : même univers, même type, même marque, matières, prix, mots. */
function rec_similarity_score(array $a, array $b, ?array $df = null, int $n = 0): float
{
    $score = 0.0;
    if (($a['cat'] ?? '') !== '' && ($a['cat'] ?? '') === ($b['cat'] ?? '')) $score += 5.0;
    if (($a['nature'] ?? '') !== '' && ($a['nature'] ?? '') === ($b['nature'] ?? '')) $score += 4.0;
    if (($a['sous_categorie'] ?? '') !== '' && ($a['sous_categorie'] ?? '') === ($b['sous_categorie'] ?? '')) $score += 3.0;

    foreach (['brand' => 3.0, 'distributor' => 1.5] as $field => $bonus) {
        $va = mb_strtolower(trim((string) ($a[$field] ?? '')));
        $vb = mb_strtolower(trim((string) ($b[$field] ?? '')));
        if ($va !== '' && $va === $vb) $score += $bonus;
    }

    $score += min(4.5, rec_values_overlap(rec_multi_values($a['colors'] ?? ''), rec_multi_values($b['colors'] ?? '')) * 1.5);
    $score += min(3.0, rec_values_overlap(rec_multi_values($a['materials'] ?? ''), rec_multi_values($b['materials'] ?? '')) * 1.5);

    $score += rec_text_similarity(
        rec_text_tokens(($a['name'] ?? '') . ' ' . ($a['description'] ?? '')),
        rec_text_tokens(($b['name'] ?? '') . ' ' . ($b['description'] ?? '')),
        $df,
        $n
    );

    $score += 2.5 * rec_price_similarity(rec_price_cents($a['price'] ?? ''), rec_price_cents($b['price'] ?? ''));

    $sizeA = mb_strtolower(trim((string) ($a['size_text'] ?? '')));
    $sizeB = mb_strtolower(trim((string) ($b['size_text'] ?? '')));
    if ($sizeA !== '' && $sizeA === $sizeB) $score += 0.8;
    if (($a['etat'] ?? '') !== '' && ($a['etat'] ?? '') === ($b['etat'] ?? '')) $score += 0.5;

    return $score;
}

// ── Profil d'habitudes ───────────────────────────────────────────────────────

function rec_empty_profile(): array
{
    return [
        'refs' => [], 'viewed' => [], 'cart_refs' => [], 'ordered_refs' => [],
        'cats' => [], 'natures' => [], 'subs' => [], 'brands' => [],
        'colors' => [], 'materials' => [], 'terms' => [],
        'avg_price' => null, 'has_signals' => false, 'sources' => [],
    ];
}

function rec_normalize_weights(array $weights): array
{
    if (!$weights) return [];
    $max = max($weights);
    if ($max <= 0) return [];
    foreach ($weights as $key => $w) $weights[$key] = $w / $max;
    arsort($weights);
    return array_slice($weights, 0, 30, true);
}

/** Tout le catalogue, y compris les pièces vendues ou masquées : leurs attributs restent utiles au profil. */
function rec_catalogue(): array
{
    static $all = null;
    if ($all === null) $all = get_products(null, true);
    return $all;
}

function rec_product_is_visible(array $p): bool
{
    return empty($p['is_hidden']) && ((int) ($p['stock'] ?? 0) > 0 || !empty($p['keep_when_sold']));
}

/** Habitudes du visiteur, décroissantes dans le temps, enrichies du panier et des commandes du compte. */
function rec_profile(): array
{
    static $profile = null;
    if ($profile !== null) return $profile;
    $profile = rec_empty_profile();
    try {
        rec_ready();
        $visitor = rec_visitor_id();
        $now = time();
        $stmt = db()->prepare('SELECT kind, ref, meta, created_at FROM recommendation_events WHERE visitor = ? AND created_at > ? ORDER BY created_at DESC LIMIT 250');
        $stmt->execute([$visitor, $now - REC_EVENT_RETENTION]);

        $refScores = [];
        foreach ($stmt->fetchAll() as $row) {
            $age = max(0, $now - (int) $row['created_at']);
            $decay = exp(-$age / (60 * 86400)); // une habitude de deux mois pèse environ un tiers
            $kind = (string) $row['kind'];
            $meta = json_decode((string) ($row['meta'] ?? ''), true) ?: [];

            $ref = trim((string) ($row['ref'] ?? ''));
            $kindWeights = ['view' => 1.0, 'cart' => 2.5, 'order' => 4.0];
            if ($ref !== '' && isset($kindWeights[$kind])) {
                $weight = $kindWeights[$kind] * $decay;
                $refScores[$ref] = min(8.0, ($refScores[$ref] ?? 0) + $weight);
                if ($kind === 'view') $profile['viewed'][$ref] = true;
                if ($kind === 'cart') $profile['cart_refs'][$ref] = true;
                if ($kind === 'order') $profile['ordered_refs'][$ref] = true;
            } elseif ($kind === 'search' && !empty($meta['q'])) {
                foreach (rec_text_tokens((string) $meta['q']) as $word) {
                    $profile['terms'][$word] = ($profile['terms'][$word] ?? 0) + 0.8 * $decay;
                }
            } elseif ($kind === 'browse') {
                $cat = trim((string) ($meta['cat'] ?? ''));
                $nature = trim((string) ($meta['nature'] ?? ''));
                if ($cat !== '') $profile['cats'][$cat] = ($profile['cats'][$cat] ?? 0) + 0.5 * $decay;
                if ($nature !== '') $profile['natures'][$nature] = ($profile['natures'][$nature] ?? 0) + 0.5 * $decay;
            }
        }

        // Panier en cours : le plus fort signal d'intention avant l'achat.
        if (function_exists('cart_get') && session_status() === PHP_SESSION_ACTIVE) {
            foreach (array_keys(cart_get()) as $ref) {
                $ref = (string) $ref;
                $refScores[$ref] = min(8.0, ($refScores[$ref] ?? 0) + 3.0);
                $profile['cart_refs'][$ref] = true;
            }
        }

        // Client connecté : ses commandes payées complètent l'historique, même sur un autre appareil.
        $customer = $_SESSION['customer'] ?? null;
        if (is_array($customer) && ($customer['tenant'] ?? '') === tenant_slug() && !empty($customer['email'])) {
            $stmt = db()->prepare("SELECT items FROM orders WHERE status = 'paid' AND lower(trim(email)) = ? ORDER BY id DESC LIMIT 20");
            $stmt->execute([mb_strtolower(trim((string) $customer['email']))]);
            foreach ($stmt->fetchAll() as $order) {
                foreach (json_decode((string) ($order['items'] ?? '[]'), true) ?: [] as $item) {
                    $ref = trim((string) ($item['ref'] ?? ''));
                    if ($ref === '') continue;
                    $refScores[$ref] = min(8.0, ($refScores[$ref] ?? 0) + 3.0);
                    $profile['ordered_refs'][$ref] = true;
                }
            }
        }

        arsort($refScores);
        $profile['refs'] = array_slice($refScores, 0, 40, true);

        $byRef = [];
        foreach (rec_catalogue() as $p) $byRef[(string) $p['ref']] = $p;
        $prices = [];
        foreach ($profile['refs'] as $ref => $weight) {
            $p = $byRef[$ref] ?? null;
            if (!$p) continue;
            foreach ([
                'cats' => $p['cat'] ?? '', 'natures' => $p['nature'] ?? '', 'subs' => $p['sous_categorie'] ?? '',
                'brands' => $p['brand'] ?? '',
            ] as $key => $value) {
                $value = mb_strtolower(trim((string) $value));
                if ($value !== '') $profile[$key][$value] = ($profile[$key][$value] ?? 0) + $weight;
            }
            foreach (rec_multi_values($p['colors'] ?? '') as $color) {
                $profile['colors'][$color] = ($profile['colors'][$color] ?? 0) + $weight;
            }
            foreach (rec_multi_values($p['materials'] ?? '') as $material) {
                $profile['materials'][$material] = ($profile['materials'][$material] ?? 0) + $weight;
            }
            $cents = rec_price_cents($p['price'] ?? '');
            if ($cents !== null) $prices[] = [$cents, $weight];
        }

        foreach (['cats', 'natures', 'subs', 'brands', 'colors', 'materials', 'terms'] as $key) {
            $profile[$key] = rec_normalize_weights($profile[$key]);
        }

        if ($prices) {
            $sum = 0.0;
            $weights = 0.0;
            foreach ($prices as [$cents, $weight]) { $sum += $cents * $weight; $weights += $weight; }
            if ($weights > 0) $profile['avg_price'] = (int) round($sum / $weights);
        }

        if ($profile['ordered_refs']) $profile['sources'][] = 'orders';
        if ($profile['cart_refs']) $profile['sources'][] = 'cart';
        if ($profile['viewed']) $profile['sources'][] = 'views';
        if ($profile['terms']) $profile['sources'][] = 'searches';
        if ($profile['cats']) $profile['sources'][] = 'browse';

        $profile['has_signals'] = (bool) ($profile['refs'] || $profile['cats'] || $profile['terms']);
    } catch (Throwable $e) {
        error_log('profil de recommandation : ' . $e->getMessage());
    }
    return $profile;
}

/** Affinité d'une pièce avec les habitudes : 0 quand rien ne se recoupe. */
function rec_affinity_score(array $p, array $profile): float
{
    if (empty($profile['has_signals'])) return 0.0;
    $score = 0.0;
    $score += 4.0 * (float) ($profile['cats'][mb_strtolower((string) ($p['cat'] ?? ''))] ?? 0);
    $score += 5.0 * (float) ($profile['natures'][mb_strtolower((string) ($p['nature'] ?? ''))] ?? 0);
    $score += 4.0 * (float) ($profile['subs'][mb_strtolower((string) ($p['sous_categorie'] ?? ''))] ?? 0);
    $score += 3.0 * (float) ($profile['brands'][mb_strtolower(trim((string) ($p['brand'] ?? '')))] ?? 0);

    $colorScore = 0.0;
    foreach (rec_multi_values($p['colors'] ?? '') as $color) {
        foreach ($profile['colors'] as $known => $weight) {
            if ($color === $known || str_contains($color, $known) || str_contains($known, $color)) {
                $colorScore = max($colorScore, (float) $weight);
                break;
            }
        }
    }
    $score += 2.0 * $colorScore;

    $materialScore = 0.0;
    foreach (rec_multi_values($p['materials'] ?? '') as $material) {
        foreach ($profile['materials'] as $known => $weight) {
            if ($material === $known || str_contains($material, $known) || str_contains($known, $material)) {
                $materialScore = max($materialScore, (float) $weight);
                break;
            }
        }
    }
    $score += 2.0 * $materialScore;

    $tokens = rec_text_tokens(($p['name'] ?? '') . ' ' . ($p['description'] ?? ''));
    $termScore = 0.0;
    foreach ($profile['terms'] as $term => $weight) {
        if (in_array($term, $tokens, true)) $termScore += (float) $weight;
    }
    $score += 3.0 * min(1.0, $termScore);

    $score += 2.0 * rec_price_similarity($profile['avg_price'], rec_price_cents($p['price'] ?? ''));

    return $score;
}

// ── Sélections ───────────────────────────────────────────────────────────────

/** Nombre de vues récentes par pièce, tous visiteurs confondus (repli quand l'habitude manque). */
function rec_popularity_map(): array
{
    rec_ready();
    $stmt = db()->prepare("SELECT ref, COUNT(DISTINCT visitor) AS n FROM recommendation_events WHERE kind = 'view' AND ref IS NOT NULL AND created_at > ? GROUP BY ref");
    $stmt->execute([time() - 30 * 86400]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(string) $row['ref']] = (int) $row['n'];
    return $out;
}

/**
 * Pièces proches de $product, classées par ressemblance puis par habitudes.
 * Le même univers passe en premier ; les autres pièces ne servent qu'à compléter
 * la sélection quand l'univers est trop petit.
 */
function rec_similar_products(array $product, int $limit = 3, array $excludeRefs = []): array
{
    $limit = max(1, $limit);
    $current = (string) ($product['ref'] ?? '');
    $exclude = array_fill_keys(array_map('strval', $excludeRefs), true);
    $all = rec_catalogue();
    $stats = rec_token_stats($all);
    $profile = rec_profile();
    $primary = [];
    $secondary = [];

    foreach ($all as $p) {
        $ref = (string) ($p['ref'] ?? '');
        if ($ref === '' || $ref === $current || isset($exclude[$ref]) || !rec_product_is_visible($p)) continue;
        $sameWorld = ($product['cat'] ?? '') === '' || ($p['cat'] ?? '') === ($product['cat'] ?? '');
        $sameNature = ($product['nature'] ?? '') !== '' && ($p['nature'] ?? '') === ($product['nature'] ?? '');
        $similarity = rec_similarity_score($product, $p, $stats['df'], $stats['n']);
        $score = $similarity + 0.55 * rec_affinity_score($p, $profile);
        if (in_stock($p)) $score += 0.4;
        if (!empty($p['featured'])) $score += 0.3;
        if (isset($profile['viewed'][$ref])) $score -= 1.0; // déjà vue : laisser la place à une découverte
        if (isset($profile['cart_refs'][$ref])) $score -= 10.0;
        $entry = ['product' => $p, 'score' => $score, 'stock' => in_stock($p) ? 1 : 0];
        if ($sameWorld || $sameNature) $primary[] = $entry; else $secondary[] = $entry;
    }

    $sort = static function (array $rows): array {
        usort($rows, static function (array $a, array $b): int {
            $diff = $b['score'] <=> $a['score'];
            if ($diff !== 0) return $diff;
            $diff = $b['stock'] <=> $a['stock'];
            if ($diff !== 0) return $diff;
            return strcmp((string) $a['product']['ref'], (string) $b['product']['ref']);
        });
        return array_column($rows, 'product');
    };

    $out = array_slice($sort($primary), 0, $limit);
    if (count($out) < $limit) {
        $fill = array_slice($sort($secondary), 0, $limit - count($out));
        $out = array_merge($out, $fill);
    }
    return $out;
}

/**
 * « Pour vous » : les pièces qui recoupent le plus les habitudes, avec la
 * popularité récente comme appoint. Sans historique, la sélection du moment.
 */
function rec_for_you_products(int $limit = 4, array $excludeRefs = []): array
{
    $limit = max(1, $limit);
    $exclude = array_fill_keys(array_map('strval', $excludeRefs), true);
    $all = rec_catalogue();
    $profile = rec_profile();
    $popular = rec_popularity_map();
    $maxPopular = $popular ? max($popular) : 0;
    $rows = [];

    foreach ($all as $p) {
        $ref = (string) ($p['ref'] ?? '');
        if ($ref === '' || isset($exclude[$ref]) || isset($profile['cart_refs'][$ref]) || !rec_product_is_visible($p)) continue;
        $affinity = rec_affinity_score($p, $profile);
        if ($profile['has_signals'] && $affinity <= 0.15) continue;
        $score = $affinity;
        if ($maxPopular > 0 && isset($popular[$ref])) $score += 2.0 * ($popular[$ref] / $maxPopular);
        if (!empty($p['featured'])) $score += 0.35;
        if (in_stock($p)) $score += 0.3;
        if (isset($profile['viewed'][$ref])) $score -= 1.0; // laisser remonter les pièces encore inconnues
        $rows[] = ['product' => $p, 'score' => $score];
    }

    usort($rows, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']) ?: strcmp((string) $a['product']['ref'], (string) $b['product']['ref']);
    });
    $out = array_slice(array_column($rows, 'product'), 0, $limit);

    if ($out || $profile['has_signals']) return $out;

    // Premier passage sans historique : pièces les plus consultées, puis mises en avant.
    $fallback = [];
    foreach ($all as $p) {
        $ref = (string) ($p['ref'] ?? '');
        if ($ref === '' || isset($exclude[$ref]) || !rec_product_is_visible($p)) continue;
        $fallback[] = ['product' => $p, 'score' => (($popular[$ref] ?? 0) * 2) + (!empty($p['featured']) ? 1 : 0) + (in_stock($p) ? 0.2 : 0)];
    }
    usort($fallback, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_slice(array_column($fallback, 'product'), 0, $limit);
}

/** Phrase qui explique la sélection « Pour vous » (habitudes utilisées, ou repli). */
function rec_for_you_context(?array $profile = null): array
{
    $profile ??= rec_profile();
    if ($profile['ordered_refs']) {
        return ['eyebrow' => 'Dans le prolongement de vos achats', 'title' => 'Choisi pour vous', 'note' => 'D’après les pièces que vous avez déjà commandées.'];
    }
    if ($profile['cart_refs']) {
        return ['eyebrow' => 'Pour compléter votre panier', 'title' => 'Choisi pour vous', 'note' => 'D’après votre panier en cours et vos dernières visites.'];
    }
    if ($profile['has_signals']) {
        return ['eyebrow' => 'D’après vos visites', 'title' => 'Choisi pour vous', 'note' => 'D’après les pièces que vous avez regardées et vos recherches.'];
    }
    return ['eyebrow' => 'La sélection du moment', 'title' => 'Les pièces à ne pas manquer', 'note' => 'Les pièces les plus consultées de la boutique en ce moment.'];
}

/** Titre de la rangée « pièces similaires » de la fiche produit. */
function rec_related_heading(array $product): array
{
    $profile = rec_profile();
    if ($profile['has_signals']) {
        return ['eyebrow' => 'D’après vos visites', 'title' => 'Des pièces qui devraient vous plaire'];
    }
    return ['eyebrow' => 'Dans le même univers', 'title' => 'Autres pièces en ' . mb_strtolower(category_label((string) ($product['cat'] ?? '')))];
}

/** Bloc « Pour vous » prêt à insérer dans une page (cartes du modèle actif). */
function rec_for_you_section_html(array $excludeRefs = []): string
{
    $products = rec_for_you_products(4, $excludeRefs);
    if (!$products) return '';
    $context = rec_for_you_context();
    $cards = '';
    foreach ($products as $i => $p) $cards .= product_card_html($p, $i);
    return '<section class="tight rec-for-you" aria-labelledby="rec-for-you-title">'
        . '<div class="wrap"><div class="section-head rec-for-you-head">'
        . '<p class="eyebrow">' . h($context['eyebrow']) . '</p>'
        . '<h2 id="rec-for-you-title" style="font-size:1.6rem;">' . h($context['title']) . '</h2>'
        . '<p class="rec-for-you-note">' . h($context['note']) . '</p>'
        . '</div><div class="grid-products rec-for-you-grid">' . $cards . '</div></div></section>';
}
