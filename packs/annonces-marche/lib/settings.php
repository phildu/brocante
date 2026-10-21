<?php
// Réglages du pack Annonces places de marché (clé / valeur dans am_settings).
// Utilisable hors PLUGIN_RUNTIME (webhook Stripe).

/** Prix mensuel par défaut de l'option (centimes), si la plateforme n'en a pas fixé un. */
const AM_DEFAULT_MONTHLY_CENTS = 990;

/** Durée de l'essai gratuit (jours), une seule fois par boutique. */
const AM_TRIAL_DAYS = 14;

/** PDO de la boutique courante, ou celui imposé par le webhook (voir am_with_shop). */
function am_pdo(): PDO
{
    if (!empty($GLOBALS['am_pdo_override']) && $GLOBALS['am_pdo_override'] instanceof PDO) {
        return $GLOBALS['am_pdo_override'];
    }
    return db();
}

function am_setting(string $key, string $default = ''): string
{
    static $cache = null;
    static $cachePdo = null;
    static $bustSeen = 0;
    $pdo = am_pdo();
    $bust = (int) ($GLOBALS['am_settings_bust'] ?? 0);
    if ($cache === null || $cachePdo !== $pdo || $bust !== $bustSeen) {
        $cache = [];
        $cachePdo = $pdo;
        $bustSeen = $bust;
        try {
            foreach ($pdo->query('SELECT key, value FROM am_settings')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[$row['key']] = (string) $row['value'];
            }
        } catch (Throwable $e) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function am_setting_set(string $key, string $value): void
{
    am_pdo()->prepare('INSERT INTO am_settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$key, $value]);
    $GLOBALS['am_settings_bust'] = ((int) ($GLOBALS['am_settings_bust'] ?? 0)) + 1;
}

/** Prix mensuel (centimes) : data/saas/addons.json, sinon défaut du pack. */
function am_monthly_cents(): int
{
    $file = dirname(__DIR__, 3) . '/data/saas/addons.json';
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $cents = is_array($d) ? (int) ($d['annonces-marche']['monthly_cents'] ?? 0) : 0;
    return $cents > 0 ? min(99900, $cents) : AM_DEFAULT_MONTHLY_CENTS;
}

function am_addon_name(): string
{
    $file = dirname(__DIR__, 3) . '/data/saas/addons.json';
    $d = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $name = is_array($d) ? trim((string) ($d['annonces-marche']['name'] ?? '')) : '';
    return $name !== '' ? $name : 'Annonces Leboncoin & Vinted';
}

/**
 * L'option est-elle utilisable maintenant ?
 * active (abonnement Stripe), complimentary (offert), trial (essai non expiré).
 */
function am_is_entitled(): bool
{
    $status = am_setting('subscription_status', 'inactive');
    if ($status === 'active' || $status === 'complimentary') return true;
    if ($status === 'trial') {
        $until = (int) am_setting('trial_until', '0');
        return $until > time();
    }
    return false;
}

/** État lisible pour l'administration. */
function am_entitlement(): array
{
    $status = am_setting('subscription_status', 'inactive');
    $until = (int) am_setting('trial_until', '0');
    if ($status === 'trial' && $until > 0 && $until <= time()) {
        $status = 'expired';
    }
    $labels = [
        'inactive' => 'Non souscrite',
        'active' => 'Abonnée',
        'complimentary' => 'Accès offert',
        'trial' => 'Essai gratuit',
        'canceled' => 'Résiliée',
        'expired' => 'Essai terminé',
    ];
    return [
        'status' => $status,
        'label' => $labels[$status] ?? $status,
        'entitled' => am_is_entitled(),
        'trial_until' => $status === 'trial' && $until > time() ? gmdate('Y-m-d', $until) : null,
        'stripe_subscription_id' => am_setting('stripe_subscription_id'),
        'monthly_cents' => am_monthly_cents(),
        'monthly_label' => number_format(am_monthly_cents() / 100, 2, ',', ' ') . ' € / mois',
        'addon_name' => am_addon_name(),
        'has_token' => am_setting('token_hash') !== '',
        'token_prefix' => am_setting('token_prefix'),
        'trial_used' => am_setting('trial_used') === '1',
    ];
}
