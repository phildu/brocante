<?php

// IA et formules de la plateforme : barème des coûts, quota d'IA GRATUITE inclus dans chaque formule (avec son équivalent estimé en textes, images et vidéos),
// quota affecté à chaque boutique, et crédit IA que la boutique peut racheter quand elle dépasse son quota.
//
// Le quota est un budget MENSUEL exprimé en euros de consommation réelle de l'IA (coûts du barème ci-dessous) : la boutique l'utilise comme elle veut, en textes,
// images ou vidéos (un seul budget partagé). Au-delà, son crédit racheté est entamé ; sans crédit, les générations IA sont suspendues jusqu'au mois suivant
// ou jusqu'à un rachat (voir ai_quota_state() / ai_gate() dans includes/ai-usage.php). Une boutique sans quota affecté n'est pas limitée (comportement d'avant).
//
// Fichiers (data/saas/, jamais servis) : ai-costs.json (barème et packs, réglés dans le portail), ai-policy.json (quota de chaque boutique),
// ai-credits.json (crédit acheté de chaque boutique). Ce fichier est autonome : portail, inscription et boutiques l'utilisent.

require_once __DIR__ . '/ai-usage.php';

/** Durée de la vidéo de référence des estimations (Veo « Fast »). */
const SAAS_AI_VIDEO_SECONDS = 6;
/** Prix par défaut de la rédaction d'une fiche (un appel de texte avec vision), en dollars. */
const SAAS_AI_TEXT_CALL_USD = 0.004;
/** Packs de crédit proposés par défaut : [clé, nom, crédit IA offert en euros de consommation, prix payé en centimes]. */
const SAAS_AI_DEFAULT_PACKS = [['petit', 'Petit pack', 3.0, 500], ['moyen', 'Pack moyen', 7.0, 1000], ['grand', 'Grand pack', 20.0, 2500]];

function saas_ai_dir(): string
{
    $dir = dirname(__DIR__) . '/data/saas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function saas_ai_read(string $name): array
{
    $file = saas_ai_dir() . '/' . $name;
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($d) ? $d : [];
}

/** Écrit un fichier de réglage sous verrou (lecture-modification-écriture : $mutate reçoit l'état actuel et retourne le nouveau). */
function saas_ai_update(string $name, callable $mutate): bool
{
    $file = saas_ai_dir() . '/' . $name;
    $fh = @fopen($file, 'c+');
    if (!$fh) return false;
    flock($fh, LOCK_EX);
    $cur = json_decode((string) stream_get_contents($fh), true);
    $new = $mutate(is_array($cur) ? $cur : []);
    ftruncate($fh, 0);
    rewind($fh);
    $ok = fwrite($fh, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

// ── Barème ───────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Barème de la plateforme : celui de includes/ai-usage.php, complété du prix d'une rédaction de fiche, et ajusté dans le portail (ai-costs.json). */
function saas_ai_pricing(): array
{
    $saved = saas_ai_read('ai-costs.json')['pricing'] ?? [];
    $defaults = AI_PRICING_DEFAULTS + ['text_call' => SAAS_AI_TEXT_CALL_USD];
    return array_merge($defaults, array_intersect_key(array_map('floatval', (array) $saved), $defaults));
}

function saas_ai_pricing_save(array $values): bool
{
    $defaults = AI_PRICING_DEFAULTS + ['text_call' => SAAS_AI_TEXT_CALL_USD];
    $clean = [];
    foreach ($defaults as $k => $d) {
        $v = (float) str_replace(',', '.', (string) ($values[$k] ?? $d));
        $clean[$k] = $v >= 0 && $v < 1000 ? $v : $d;
    }
    return saas_ai_update('ai-costs.json', static function (array $c) use ($clean): array { $c['pricing'] = $clean; return $c; });
}

/** Coût unitaire en euros : text (rédaction d'une fiche), image, search (recherche de prix), cutout, video (6 s), piece (fiche complète : 3 images + 1 texte, au plus cher). */
function saas_ai_unit_costs(): array
{
    $p = saas_ai_pricing();
    $r = $p['usd_eur'];
    $text = $p['text_call'] * $r;
    return [
        'text' => $text, 'image' => $p['image'] * $r, 'search' => ($p['search'] + $p['text_call'] * 2) * $r, 'cutout' => $p['cutout'] * $r,
        'video' => $p['veo_fast'] * SAAS_AI_VIDEO_SECONDS * $r, 'piece' => (3 * $p['image'] + $p['text_call']) * $r,
    ];
}

/** Ce que vaut un budget : nombre de rédactions de fiche, d'images, de vidéos de 6 s et de fiches complètes (détourage + 2 visuels + texte), au choix. */
function saas_ai_estimate(float $eur): array
{
    $u = saas_ai_unit_costs();
    return ['texts' => (int) floor($eur / max(0.0001, $u['text'])), 'images' => (int) floor($eur / max(0.0001, $u['image'])),
        'videos' => (int) floor($eur / max(0.0001, $u['video'])), 'pieces' => (int) floor($eur / max(0.0001, $u['piece']))];
}

/** « ≈ 750 textes · ≈ 130 images · ≈ 8 vidéos de 6 s » : l'équivalent d'un budget, à choisir entre texte, image et vidéo. */
function saas_ai_estimate_text(float $eur): string
{
    $e = saas_ai_estimate($eur);
    $n = static fn (int $v): string => number_format($v, 0, ',', ' ');
    $plural = static fn (int $v, string $one, string $many): string => $v > 1 ? $many : $one;
    return '≈ ' . $n($e['texts']) . ' ' . $plural($e['texts'], 'texte', 'textes') . ' · ≈ ' . $n($e['images']) . ' ' . $plural($e['images'], 'image', 'images')
        . ' · ≈ ' . $n($e['videos']) . ' ' . $plural($e['videos'], 'vidéo', 'vidéos') . ' de ' . SAAS_AI_VIDEO_SECONDS . ' s';
}

/** Ligne affichée sur une offre : « IA incluse chaque mois, au choix : ≈ … » ; chaîne vide si l'offre n'a pas de quota ; « IA à la carte » si le quota est nul. */
function saas_ai_offer_line(?float $quotaEur): string
{
    if ($quotaEur === null) return '';
    if ($quotaEur <= 0) return 'IA à la carte : crédit à acheter selon vos besoins';
    return 'IA incluse chaque mois, au choix : ' . saas_ai_estimate_text($quotaEur);
}

// ── Packs de crédit ──────────────────────────────────────────────────────────────────────────────────────────────────────

/** Packs valides (nom, crédit et prix renseignés), clés uniques : [['key', 'name', 'credit_eur', 'price_cents'], …]. */
function saas_ai_packs_normalize(array $rows): array
{
    $out = [];
    foreach ($rows as $p) {
        $credit = round((float) ($p['credit_eur'] ?? 0), 2);
        $price = (int) ($p['price_cents'] ?? 0);
        $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 40);
        if ($credit <= 0 || $price <= 0 || $name === '') continue;
        $key = preg_match('/^[a-z0-9-]{2,30}$/', (string) ($p['key'] ?? '')) ? (string) $p['key'] : preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'pack'));
        $out[] = ['key' => trim($key, '-') ?: 'pack', 'name' => $name, 'credit_eur' => $credit, 'price_cents' => min(10000000, $price)];
    }
    $seen = [];
    foreach ($out as &$p) { $base = $p['key']; $i = 2; while (isset($seen[$p['key']])) $p['key'] = $base . '-' . $i++; $seen[$p['key']] = true; }
    return $out;
}

/** Packs de crédit à racheter. */
function saas_ai_packs(): array
{
    $saved = saas_ai_read('ai-costs.json')['packs'] ?? null;
    return saas_ai_packs_normalize(is_array($saved) ? $saved : array_map(static fn ($p) => ['key' => $p[0], 'name' => $p[1], 'credit_eur' => $p[2], 'price_cents' => $p[3]], SAAS_AI_DEFAULT_PACKS));
}

function saas_ai_packs_save(array $packs): bool
{
    $packs = saas_ai_packs_normalize($packs);
    return saas_ai_update('ai-costs.json', static function (array $c) use ($packs): array { $c['packs'] = $packs; return $c; });
}

function saas_ai_pack(string $key): ?array
{
    foreach (saas_ai_packs() as $p) if ($p['key'] === $key) return $p;
    return null;
}

// ── Quota de chaque boutique ─────────────────────────────────────────────────────────────────────────────────────────────

/** Quota affecté à une boutique : ['quota_eur' => budget mensuel offert, 'since' => 'Y-m' (début du décompte), 'source' => …] ou null (non limitée). */
function saas_ai_policy(string $slug): ?array
{
    $p = saas_ai_read('ai-policy.json')[$slug] ?? null;
    return is_array($p) && isset($p['quota_eur']) ? ['quota_eur' => max(0.0, (float) $p['quota_eur']), 'since' => (string) ($p['since'] ?? gmdate('Y-m')), 'source' => (string) ($p['source'] ?? '')] : null;
}

/** Affecte (ou retire, avec null) le quota mensuel d'une boutique ; le décompte du crédit racheté repart du mois courant. */
function saas_ai_policy_set(string $slug, ?float $quotaEur, string $source = 'exploitant'): bool
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug)) return false;
    return saas_ai_update('ai-policy.json', static function (array $all) use ($slug, $quotaEur, $source): array {
        if ($quotaEur === null) { unset($all[$slug]); return $all; }
        $old = $all[$slug]['since'] ?? gmdate('Y-m');
        $all[$slug] = ['quota_eur' => round(max(0, $quotaEur), 2), 'since' => $old, 'source' => $source, 'updated' => time()];
        return $all;
    });
}

// ── Crédit acheté ────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Crédit racheté par une boutique : ['purchased_eur' => total, 'purchases' => [['at', 'credit_eur', 'price_cents', 'pack', 'session', 'source']]]. */
function saas_ai_credits(string $slug): array
{
    $c = saas_ai_read('ai-credits.json')[$slug] ?? [];
    $purchases = array_values(array_filter((array) ($c['purchases'] ?? []), 'is_array'));
    return ['purchased_eur' => round(array_sum(array_map(static fn ($p) => (float) ($p['credit_eur'] ?? 0), $purchases)), 2), 'purchases' => $purchases];
}

/**
 * Ajoute un crédit (rachat Stripe ou crédit offert). Idempotent : une même session de paiement n'est jamais créditée deux fois.
 * Retourne 'added', 'already' ou 'error'.
 */
function saas_ai_credit_add(string $slug, float $creditEur, array $meta = []): string
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug) || $creditEur <= 0) return 'error';
    $state = 'added';
    $ok = saas_ai_update('ai-credits.json', static function (array $all) use ($slug, $creditEur, $meta, &$state): array {
        $list = $all[$slug]['purchases'] ?? [];
        $session = (string) ($meta['session'] ?? '');
        foreach ($list as $p) if ($session !== '' && ($p['session'] ?? '') === $session) { $state = 'already'; return $all; }
        $list[] = ['at' => time(), 'credit_eur' => round($creditEur, 2), 'price_cents' => (int) ($meta['price_cents'] ?? 0), 'pack' => (string) ($meta['pack'] ?? ''), 'session' => $session, 'source' => (string) ($meta['source'] ?? 'stripe')];
        $all[$slug] = ['purchases' => $list];
        return $all;
    });
    return $ok ? $state : 'error';
}

/**
 * Applique une session Stripe Checkout payée de type « crédit IA » (page de retour OU webhook) : vérifie qu'elle est payée et bien du bon type, puis crédite.
 * Retourne ['state' => 'added'|'already'|'unpaid'|'invalid', 'slug', 'credit_eur'].
 */
function saas_ai_credit_apply_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'ai_credit' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', (string) ($m['shop'] ?? '')) || (float) ($m['credit_eur'] ?? 0) <= 0) return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => (string) $m['shop']];
    $credit = round((float) $m['credit_eur'], 2);
    $state = saas_ai_credit_add((string) $m['shop'], $credit, ['session' => (string) ($session['id'] ?? ''), 'pack' => (string) ($m['pack'] ?? ''), 'price_cents' => (int) ($session['amount_total'] ?? 0), 'source' => 'stripe']);
    return ['state' => $state === 'error' ? 'invalid' : $state, 'slug' => (string) $m['shop'], 'credit_eur' => $credit, 'amount' => (int) ($session['amount_total'] ?? 0), 'pack' => (string) ($m['pack'] ?? '')];
}
