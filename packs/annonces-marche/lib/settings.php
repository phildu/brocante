<?php
// Réglages du pack Annonces places de marché (clé / valeur dans am_settings).
// Utilisable hors PLUGIN_RUNTIME (webhook Stripe).
require_once dirname(__DIR__, 3) . '/includes/addons.php';

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
    $shop = function_exists('tenant_slug') ? tenant_slug() : '';
    $info = addon_info('annonces-marche', $shop);
    $cents = (int) ($info['monthly_cents'] ?? 0);
    return $cents > 0 ? min(99900, $cents) : AM_DEFAULT_MONTHLY_CENTS;
}

function am_addon_name(): string
{
    $shop = function_exists('tenant_slug') ? tenant_slug() : '';
    $info = addon_info('annonces-marche', $shop);
    $name = trim((string) ($info['name'] ?? ''));
    return $name !== '' ? $name : 'Annonces Leboncoin & Vinted';
}

/** Reprend une ancienne souscription avant le premier affichage du panneau commun. */
function am_sync_legacy_entitlement(): void
{
    if (am_setting('legacy_billing_migrated') === '1') return;
    $status = am_setting('subscription_status', 'inactive');
    $trialUntil = (int) am_setting('trial_until', '0');
    if ($status === 'trial' && $trialUntil <= time()) return;
    if (!in_array($status, ['active', 'complimentary', 'trial'], true)) return;

    $billingMode = $status === 'trial' ? '' : ($status === 'active' ? 'monthly' : '');
    addon_save('annonces-marche', [
        'status' => $status,
        'billing_mode' => $billingMode,
        'trial_until' => $trialUntil,
        'trial_used' => am_setting('trial_used') === '1' ? 1 : 0,
        'stripe_subscription_id' => am_setting('stripe_subscription_id'),
        'stripe_customer_id' => am_setting('stripe_customer_id'),
        'stripe_session_id' => am_setting('stripe_session_id'),
        'subscribed_at' => am_setting('subscribed_at'),
        'canceled_at' => am_setting('canceled_at'),
    ]);
    am_setting_set('legacy_billing_migrated', '1');
}

/**
 * L'option est-elle utilisable maintenant ?
 * active (abonnement Stripe), complimentary (offert), trial (essai non expiré).
 */
function am_is_entitled(): bool
{
    am_sync_legacy_entitlement();
    return addon_is_entitled('annonces-marche');
}

/** État lisible pour l'administration. */
function am_entitlement(): array
{
    am_sync_legacy_entitlement();
    $entitlement = addon_entitlement('annonces-marche');
    return array_replace($entitlement, [
        'addon_name' => $entitlement['name'],
        'has_token' => am_setting('token_hash') !== '',
        'token_prefix' => am_setting('token_prefix'),
    ]);
}
