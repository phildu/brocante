<?php

// Statut commercial d'une boutique, réglé depuis le portail : publiée, en test (visible seulement par son lien d'aperçu)
// ou en pause. Un geste commercial peut y être attaché pour le suivi de la facturation : gratuité d'accès (totale ou
// jusqu'à une date), avoir ou remise discrétionnaire. Stockage : data/shop-status/<identifiant>.json (jamais envoyé
// par le déploiement), pour ne pas toucher au tenant.php.

const SHOP_STATE_STATUSES = ['published' => 'Publiée', 'test' => 'En test', 'paused' => 'En pause'];
const SHOP_GESTURE_KINDS = ['forever', 'until', 'credit', 'discount'];
const SHOP_GESTURE_KIND_LABELS = [
    'forever' => 'Gratuité totale',
    'until' => 'Gratuit jusqu’à une date',
    'credit' => 'Avoir commercial',
    'discount' => 'Remise commerciale',
];

function shop_state_slug_valid(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $slug);
}

function shop_state_dir(): string
{
    return dirname(__DIR__) . '/data/shop-status';
}

function shop_state_file(string $slug): string
{
    return shop_state_dir() . '/' . $slug . '.json';
}

function shop_state_defaults(): array
{
    return ['status' => 'published', 'gesture' => null, 'updated' => 0];
}

function shop_gesture_normalize($gesture): ?array
{
    if (!is_array($gesture) || !in_array($gesture['kind'] ?? '', SHOP_GESTURE_KINDS, true)) return null;
    $kind = (string) $gesture['kind'];
    $until = max(0, (int) ($gesture['until'] ?? 0));
    return [
        'kind' => $kind,
        'until' => in_array($kind, ['until', 'credit', 'discount'], true) ? $until : 0,
        'amount_cents' => $kind === 'credit' ? max(0, min(1000000, (int) ($gesture['amount_cents'] ?? 0))) : 0,
        'percent' => $kind === 'discount' ? max(0, min(100, (int) ($gesture['percent'] ?? 0))) : 0,
        'note' => mb_substr(trim((string) ($gesture['note'] ?? '')), 0, 300),
        'created' => (int) ($gesture['created'] ?? time()),
    ];
}

function shop_state_normalize(array $data): array
{
    return [
        'status' => in_array($data['status'] ?? '', array_keys(SHOP_STATE_STATUSES), true) ? (string) $data['status'] : 'published',
        'gesture' => shop_gesture_normalize($data['gesture'] ?? null),
        'updated' => (int) ($data['updated'] ?? 0),
    ];
}

/** Statut enregistré d'une boutique ; « publiée » par défaut. */
function shop_state_load(string $slug): array
{
    if (!shop_state_slug_valid($slug)) return shop_state_defaults();
    if (!isset($GLOBALS['brocs_shop_state_cache'][$slug])) {
        $file = shop_state_file($slug);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $GLOBALS['brocs_shop_state_cache'][$slug] = shop_state_normalize(is_array($data) ? $data : []);
    }
    return $GLOBALS['brocs_shop_state_cache'][$slug];
}

function shop_state_save(string $slug, array $state): bool
{
    if (!shop_state_slug_valid($slug)) return false;
    $dir = shop_state_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    $state = shop_state_normalize($state);
    $state['updated'] = time();
    $ok = @file_put_contents(shop_state_file($slug), json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    if ($ok) $GLOBALS['brocs_shop_state_cache'][$slug] = $state;
    return $ok;
}

/** Libellé du statut : Publiée, En test, En pause. */
function shop_state_label(array $state): string
{
    return SHOP_STATE_STATUSES[$state['status'] ?? 'published'] ?? 'Publiée';
}

function shop_state_is_public(array $state): bool
{
    return ($state['status'] ?? 'published') === 'published';
}

/** Le geste ouvre-t-il l'accès sans facturation à la date du jour ? (Les avoirs et remises ne changent pas l'accès.) */
function shop_gesture_active(array $state): bool
{
    $g = $state['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return false;
    return $g['kind'] === 'forever' || ($g['kind'] === 'until' && (int) ($g['until'] ?? 0) > time());
}

/** Le geste est-il encore en cours (accès ou avantage de facturation) ? */
function shop_gesture_current(array $state): bool
{
    $g = $state['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return false;
    if ($g['kind'] === 'forever') return true;
    $until = (int) ($g['until'] ?? 0);
    return $until <= 0 || $until > time();
}

/** Le geste porte-t-il sur la facturation (avoir ou remise) plutôt que sur l'accès ? */
function shop_gesture_is_billing(array $state): bool
{
    return in_array((string) ($state['gesture']['kind'] ?? ''), ['credit', 'discount'], true);
}

/** Période d'un avoir ou d'une remise, ou '' si aucune date. */
function shop_gesture_period(int $until): string
{
    if ($until <= 0) return '';
    return ($until > time() ? ' — valable jusqu’au ' : ' — expiré le ') . date('d/m/Y', $until);
}

/** « Gratuité totale », « Avoir commercial de 19,90 € », « Remise commerciale de 20 % », ou ''. */
function shop_gesture_label(array $state): string
{
    $g = $state['gesture'] ?? null;
    if (!is_array($g) || empty($g['kind'])) return '';
    if ($g['kind'] === 'forever') return 'Gratuité totale';
    if ($g['kind'] === 'until') {
        $until = (int) ($g['until'] ?? 0);
        if ($until <= 0) return '';
        return ($until > time() ? 'Gratuit jusqu’au ' : 'Gratuité terminée le ') . date('d/m/Y', $until);
    }
    if ($g['kind'] === 'credit') {
        $amount = number_format(((int) ($g['amount_cents'] ?? 0)) / 100, 2, ',', ' ');
        return 'Avoir commercial de ' . $amount . ' €' . shop_gesture_period((int) ($g['until'] ?? 0));
    }
    if ($g['kind'] === 'discount') {
        return 'Remise commerciale de ' . (int) ($g['percent'] ?? 0) . ' %' . shop_gesture_period((int) ($g['until'] ?? 0));
    }
    return '';
}

/** Jeton du lien d'aperçu d'une boutique en test ou en pause (?apercu=…), propre à l'installation. */
function shop_preview_token(string $slug): string
{
    if (!shop_state_slug_valid($slug)) return '';
    $dir = shop_state_dir();
    $file = $dir . '/.salt';
    if (!is_file($file)) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return '';
        @file_put_contents($file, bin2hex(random_bytes(16)), LOCK_EX);
    }
    $salt = (string) @file_get_contents($file);
    return $salt !== '' ? substr(hash_hmac('sha256', $slug, $salt), 0, 24) : '';
}

function shop_preview_allowed(string $slug, ?string $token): bool
{
    $token = trim((string) $token);
    $expected = shop_preview_token($slug);
    return $token !== '' && $expected !== '' && hash_equals($expected, $token);
}

/** Adresse d'aperçu d'une boutique non publiée. */
function shop_preview_url(string $baseUrl, string $slug): string
{
    $token = shop_preview_token($slug);
    return $token !== '' ? rtrim($baseUrl, '/') . '/?apercu=' . rawurlencode($token) : rtrim($baseUrl, '/') . '/';
}
