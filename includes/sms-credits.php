<?php

// Crédits SMS par boutique : barème (prix facturé au SMS, coût réel estimé), packs
// de crédit à racheter, solde de chaque boutique et journal des envois.
//
// Une boutique SANS fiche de crédit n'est pas limitée : c'est l'état par défaut,
// pour ne pas couper les envois existants. Dès que l'exploitant lui attribue un
// crédit (offert) ou qu'elle en achète un, chaque SMS envoyé le consomme (un SMS
// long compte plusieurs segments) et les envois s'arrêtent à zéro.
//
// Fichiers data/saas/ (jamais servis) : sms-costs.json (barème et packs réglés
// dans le portail), sms-credits.json (achats et envois de chaque boutique).
// Ce fichier est autonome : le portail, l'administration et le webhook l'utilisent.

/** Prix par défaut facturé à la boutique, en centimes par SMS. */
const SMS_DEFAULT_UNIT_CENTS = 9;
/** Coût réel par défaut estimé chez OVH, en centimes par SMS (sert à la marge affichée). */
const SMS_DEFAULT_COST_CENTS = 4;
/** Packs proposés par défaut : [clé, nom, nombre de SMS, prix payé en centimes]. */
const SMS_DEFAULT_PACKS = [['decouverte', 'Découverte', 100, 900], ['standard', 'Standard', 500, 3900], ['pro', 'Pro', 1000, 6900]];
/** Envois conservés par boutique dans le journal (les plus anciens sont retirés). */
const SMS_CREDIT_KEEP_SENDS = 300;

/** Dossier des réglages (SAAS_SMS_DIR permet aux tests d'écrire ailleurs). */
function saas_sms_dir(): string
{
    $custom = trim((string) getenv('SAAS_SMS_DIR'));
    $dir = $custom !== '' ? $custom : dirname(__DIR__) . '/data/saas';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function saas_sms_read(string $name): array
{
    $file = saas_sms_dir() . '/' . $name;
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($d) ? $d : [];
}

/** Écrit un fichier sous verrou (lecture-modification-écriture). */
function saas_sms_update(string $name, callable $mutate): bool
{
    $file = saas_sms_dir() . '/' . $name;
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
    if ($ok) @chmod($file, 0640);
    return $ok;
}

/** Identifiant de boutique accepté pour les crédits. */
function sms_credit_valid_slug(string $slug): bool
{
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug) === 1;
}

// ── Barème et packs ─────────────────────────────────────────────────────────

/** Barème : ['unit_cents' => prix facturé au SMS, 'cost_cents' => coût réel estimé]. */
function saas_sms_pricing(): array
{
    $saved = saas_sms_read('sms-costs.json')['pricing'] ?? [];
    $out = ['unit_cents' => SMS_DEFAULT_UNIT_CENTS, 'cost_cents' => SMS_DEFAULT_COST_CENTS];
    foreach ($out as $k => $default) {
        $v = $saved[$k] ?? null;
        if (is_numeric($v)) $out[$k] = max(0, min(500, (int) round((float) $v)));
    }
    return $out;
}

function saas_sms_pricing_save(array $values): bool
{
    $clean = [];
    foreach (['unit_cents' => SMS_DEFAULT_UNIT_CENTS, 'cost_cents' => SMS_DEFAULT_COST_CENTS] as $k => $default) {
        $v = (int) round((float) str_replace(',', '.', (string) ($values[$k] ?? $default)));
        $clean[$k] = $v >= 0 && $v <= 500 ? $v : $default;
    }
    return saas_sms_update('sms-costs.json', static function (array $c) use ($clean): array { $c['pricing'] = $clean; return $c; });
}

/** Packs valides (nom, SMS et prix renseignés), clés uniques : [['key', 'name', 'sms', 'price_cents'], …]. */
function saas_sms_packs_normalize(array $rows): array
{
    $out = [];
    foreach ($rows as $p) {
        $sms = (int) ($p['sms'] ?? 0);
        $price = (int) ($p['price_cents'] ?? 0);
        $name = mb_substr(trim((string) ($p['name'] ?? '')), 0, 40);
        if ($sms <= 0 || $price <= 0 || $name === '') continue;
        $key = preg_match('/^[a-z0-9-]{2,30}$/', (string) ($p['key'] ?? '')) ? (string) $p['key'] : preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: 'pack'));
        $out[] = ['key' => trim($key, '-') ?: 'pack', 'name' => $name, 'sms' => min(1000000, $sms), 'price_cents' => min(10000000, $price)];
    }
    $seen = [];
    foreach ($out as &$p) {
        $base = $p['key'];
        $i = 2;
        while (isset($seen[$p['key']])) $p['key'] = $base . '-' . $i++;
        $seen[$p['key']] = true;
    }
    unset($p);
    return $out;
}

/** Packs de crédit SMS à racheter. */
function saas_sms_packs(): array
{
    $saved = saas_sms_read('sms-costs.json')['packs'] ?? null;
    return saas_sms_packs_normalize(is_array($saved) ? $saved : array_map(static fn ($p) => ['key' => $p[0], 'name' => $p[1], 'sms' => $p[2], 'price_cents' => $p[3]], SMS_DEFAULT_PACKS));
}

function saas_sms_packs_save(array $packs): bool
{
    $packs = saas_sms_packs_normalize($packs);
    return saas_sms_update('sms-costs.json', static function (array $c) use ($packs): array { $c['packs'] = $packs; return $c; });
}

function saas_sms_pack(string $key): ?array
{
    foreach (saas_sms_packs() as $p) if ($p['key'] === $key) return $p;
    return null;
}

// ── Solde d'une boutique ────────────────────────────────────────────────────

/**
 * Crédit SMS d'une boutique : ['enabled', 'purchased', 'used', 'left', 'purchases', 'sends'].
 * enabled = false : la boutique n'a pas de fiche, ses SMS ne sont pas décomptés.
 */
function sms_credit_account(string $slug): array
{
    $c = saas_sms_read('sms-credits.json')[$slug] ?? null;
    $out = ['enabled' => is_array($c), 'purchased' => 0, 'used' => 0, 'left' => 0, 'purchases' => [], 'sends' => []];
    if (!is_array($c)) return $out;
    $out['purchases'] = array_values(array_filter((array) ($c['purchases'] ?? []), 'is_array'));
    $out['sends'] = array_values(array_filter((array) ($c['sends'] ?? []), 'is_array'));
    $out['purchased'] = (int) array_sum(array_map(static fn ($p) => (int) ($p['sms'] ?? 0), $out['purchases']));
    $out['used'] = (int) array_sum(array_map(static fn ($s) => (int) ($s['segments'] ?? 0), $out['sends']));
    $out['left'] = max(0, $out['purchased'] - $out['used']);
    return $out;
}

/**
 * Ajoute un crédit (rachat Stripe ou crédit offert). Idempotent : une même session
 * de paiement n'est jamais créditée deux fois. Retourne 'added', 'already' ou 'error'.
 */
function sms_credit_add(string $slug, int $sms, array $meta = []): string
{
    if (!sms_credit_valid_slug($slug) || $sms <= 0) return 'error';
    $state = 'added';
    $ok = saas_sms_update('sms-credits.json', static function (array $all) use ($slug, $sms, $meta, &$state): array {
        $shop = is_array($all[$slug] ?? null) ? $all[$slug] : [];
        $list = (array) ($shop['purchases'] ?? []);
        $session = (string) ($meta['session'] ?? '');
        foreach ($list as $p) {
            if ($session !== '' && ($p['session'] ?? '') === $session) { $state = 'already'; return $all; }
        }
        $list[] = ['at' => time(), 'sms' => $sms, 'price_cents' => (int) ($meta['price_cents'] ?? 0),
            'pack' => (string) ($meta['pack'] ?? ''), 'session' => $session, 'source' => (string) ($meta['source'] ?? 'stripe')];
        $all[$slug] = ['purchases' => $list, 'sends' => (array) ($shop['sends'] ?? [])];
        return $all;
    });
    return $ok ? $state : 'error';
}

/** La boutique peut-elle dépenser ce nombre de segments ? (sans fiche = pas de limite) */
function sms_credit_allows(string $slug, int $segments = 1): bool
{
    $a = sms_credit_account($slug);
    return !$a['enabled'] || $a['left'] >= max(1, $segments);
}

/**
 * Enregistre un envoi réussi : consomme les segments et journalise (numéro masqué).
 * Sans fiche de crédit, ne fait rien et retourne true.
 */
function sms_credit_spend(string $slug, int $segments, array $meta = []): bool
{
    if (!sms_credit_valid_slug($slug) || $segments <= 0) return false;
    $account = sms_credit_account($slug);
    if (!$account['enabled']) return true;
    $unit = saas_sms_pricing()['unit_cents'];
    return saas_sms_update('sms-credits.json', static function (array $all) use ($slug, $segments, $meta, $unit): array {
        $shop = is_array($all[$slug] ?? null) ? $all[$slug] : [];
        $sends = (array) ($shop['sends'] ?? []);
        $sends[] = ['at' => time(), 'segments' => $segments, 'to' => sms_credit_mask_phone((string) ($meta['to'] ?? '')),
            'price_cents' => $unit * $segments, 'purpose' => mb_substr((string) ($meta['purpose'] ?? ''), 0, 60)];
        if (count($sends) > SMS_CREDIT_KEEP_SENDS) $sends = array_slice($sends, -SMS_CREDIT_KEEP_SENDS);
        $all[$slug] = ['purchases' => (array) ($shop['purchases'] ?? []), 'sends' => $sends];
        return $all;
    });
}

/** Retire la fiche de crédit : la boutique redevient sans limite. */
function sms_credit_reset(string $slug): bool
{
    if (!sms_credit_valid_slug($slug)) return false;
    return saas_sms_update('sms-credits.json', static function (array $all) use ($slug): array {
        unset($all[$slug]);
        return $all;
    });
}

/** « +33612345678 » → « +33 6 •• •• •• 78 » (journal lisible sans conserver le numéro entier). */
function sms_credit_mask_phone(string $raw): string
{
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === null || $digits === '') return '';
    // Numéro saisi en local (« 06… ») : sms.php le normalise quand il est chargé.
    if (function_exists('sms_phone_e164') && ($e164 = sms_phone_e164($raw)) !== null) {
        $digits = (string) preg_replace('/\D/', '', $e164);
    }
    $tail = substr($digits, -2);
    return '+' . substr($digits, 0, 2) . ' •• •• •• ' . $tail;
}

/**
 * Applique une session Stripe Checkout payée de type « sms_credit » (page de retour
 * ou webhook) : vérifie qu'elle est payée et bien du bon type, puis crédite.
 * Retourne ['state' => 'added'|'already'|'unpaid'|'invalid', 'slug', 'sms'].
 */
function sms_credit_apply_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'sms_credit' || !sms_credit_valid_slug((string) ($m['shop'] ?? '')) || (int) ($m['sms'] ?? 0) <= 0) return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => (string) $m['shop']];
    $sms = (int) $m['sms'];
    $state = sms_credit_add((string) $m['shop'], $sms, ['session' => (string) ($session['id'] ?? ''), 'pack' => (string) ($m['pack'] ?? ''), 'price_cents' => (int) ($session['amount_total'] ?? 0), 'source' => 'stripe']);
    return ['state' => $state === 'error' ? 'invalid' : $state, 'slug' => (string) $m['shop'], 'sms' => $sms, 'amount' => (int) ($session['amount_total'] ?? 0), 'pack' => (string) ($m['pack'] ?? '')];
}
