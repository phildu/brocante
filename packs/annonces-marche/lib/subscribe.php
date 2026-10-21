<?php
// Abonnement Stripe de l'option Annonces (compte plateforme). Aussi utilisé par le webhook.

require_once __DIR__ . '/settings.php';

/**
 * Applique une session Checkout payée (metadata.type = marketplace_addon) sur la boutique indiquée.
 * Ouvre la base du commerce si on n'est pas déjà dans son contexte (webhook).
 * Retourne ['state' => added|already|unpaid|invalid, 'slug' => …].
 */
function am_apply_checkout_session(array $session): array
{
    $m = (array) ($session['metadata'] ?? []);
    if (($m['type'] ?? '') !== 'marketplace_addon') return ['state' => 'invalid'];
    $slug = (string) ($m['shop'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,60}$/', $slug)) return ['state' => 'invalid'];
    if (($session['payment_status'] ?? '') !== 'paid' && ($session['status'] ?? '') !== 'complete') {
        // Abonnement : souvent payment_status paid dès le premier paiement.
        if (($session['payment_status'] ?? '') !== 'paid') return ['state' => 'unpaid', 'slug' => $slug];
    }

    $subId = (string) ($session['subscription'] ?? '');
    $cusId = (string) ($session['customer'] ?? '');
    $sessionId = (string) ($session['id'] ?? '');

    return am_with_shop($slug, static function () use ($subId, $cusId, $sessionId, $slug): array {
        if (am_setting('subscription_status') === 'active' && am_setting('stripe_session_id') === $sessionId && $sessionId !== '') {
            return ['state' => 'already', 'slug' => $slug];
        }
        if (am_setting('stripe_session_id') === $sessionId && $sessionId !== '' && am_setting('subscription_status') === 'active') {
            return ['state' => 'already', 'slug' => $slug];
        }
        am_setting_set('subscription_status', 'active');
        am_setting_set('stripe_subscription_id', $subId);
        am_setting_set('stripe_customer_id', $cusId);
        am_setting_set('stripe_session_id', $sessionId);
        am_setting_set('subscribed_at', gmdate('c'));
        am_setting_set('canceled_at', '');
        return ['state' => 'added', 'slug' => $slug];
    });
}

/** Marque l'abonnement résilié pour une boutique (webhook customer.subscription.deleted). */
function am_cancel_subscription(string $subscriptionId): ?string
{
    if ($subscriptionId === '') return null;
    // Parcourir les boutiques du serveur pour trouver celle qui a cet abonnement.
    if (!function_exists('tenant_list')) {
        require_once dirname(__DIR__, 3) . '/includes/tenant.php';
    }
    if (!function_exists('plugin_open_tenant_db')) {
        require_once dirname(__DIR__, 3) . '/includes/plugins.php';
    }
    foreach (array_keys(tenant_list()) as $slug) {
        try {
            [$pdo] = plugin_open_tenant_db($slug);
            if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='am_settings'")->fetchColumn()) continue;
            $stmt = $pdo->prepare("SELECT value FROM am_settings WHERE key = 'stripe_subscription_id'");
            $stmt->execute();
            if ((string) $stmt->fetchColumn() !== $subscriptionId) continue;
            $pdo->prepare("INSERT INTO am_settings (key, value) VALUES ('subscription_status', 'canceled')
                ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute();
            $pdo->prepare("INSERT INTO am_settings (key, value) VALUES ('canceled_at', ?)
                ON CONFLICT(key) DO UPDATE SET value = excluded.value")->execute([gmdate('c')]);
            return $slug;
        } catch (Throwable $e) {
            continue;
        }
    }
    return null;
}

/**
 * Exécute $fn dans le contexte DB de la boutique $slug (ou la boutique courante si même slug).
 * @template T
 * @param callable(): T $fn
 * @return T|array
 */
function am_with_shop(string $slug, callable $fn): mixed
{
    $current = function_exists('tenant_slug') ? tenant_slug() : '';
    if ($current === $slug && function_exists('db')) {
        return $fn();
    }
    if (!function_exists('plugin_open_tenant_db')) {
        require_once dirname(__DIR__, 3) . '/includes/plugins.php';
    }
    // Contexte webhook : pas de db() boutique. On ouvre la base et on simule am_setting via PDO local.
    [$pdo] = plugin_open_tenant_db($slug);
    if (!$pdo->query("SELECT 1 FROM sqlite_master WHERE type='table' AND name='am_settings'")->fetchColumn()) {
        return ['state' => 'invalid', 'slug' => $slug, 'message' => 'Module non installé dans cette boutique.'];
    }
    // Remplacer temporairement les helpers settings pour écrire dans cette base.
    $prev = $GLOBALS['am_pdo_override'] ?? null;
    $GLOBALS['am_pdo_override'] = $pdo;
    try {
        return $fn();
    } finally {
        if ($prev === null) unset($GLOBALS['am_pdo_override']);
        else $GLOBALS['am_pdo_override'] = $prev;
    }
}
