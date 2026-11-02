<?php

// Moyens de paiement de la boutique : Stripe (flux historique), Monetico et
// PayPal (comptes propres du commerçant). Ce fichier ne remplace pas Stripe :
// il ajoute un registre de fournisseurs, la lecture des réglages et la
// finalisation commune d'une commande payée hors Stripe.

require_once __DIR__ . '/payments/monetico.php';
require_once __DIR__ . '/payments/paypal.php';

/** Fournisseur d'une commande ; les commandes antérieures (sans colonne) sont Stripe. */
function payment_provider_of(array $order): string
{
    $provider = (string) ($order['payment_provider'] ?? '');
    if ($provider !== '') return $provider;
    $session = (string) ($order['stripe_session_id'] ?? '');
    if (str_starts_with($session, 'monetico:')) return 'monetico';
    if (str_starts_with($session, 'paypal:')) return 'paypal';
    return 'stripe';
}

function payment_provider_label(string $provider): string
{
    return [
        'stripe' => 'Stripe',
        'monetico' => 'Monetico',
        'paypal' => 'PayPal',
    ][$provider] ?? $provider;
}

/** Stripe est-il utilisable pour cette boutique (clés propres ou compte Connect) ? */
function stripe_checkout_ready(): bool
{
    return stripe_configured() || connect_shop() !== null;
}

/**
 * Moyens de paiement configurés pour la boutique, avant filtrage par les
 * réglages (sert à l'écran d'administration pour proposer les cases à cocher).
 */
function shop_payment_methods_available(): array
{
    $all = [];
    if (stripe_checkout_ready()) $all['stripe'] = 'Carte bancaire (Stripe)';
    if (monetico_configured()) $all['monetico'] = 'Carte bancaire (Monetico)';
    if (paypal_configured()) $all['paypal'] = 'PayPal';
    return $all;
}

/**
 * Moyens de paiement proposés au client, dans l'ordre d'affichage.
 * Un moyen n'apparaît que s'il est configuré ET autorisé dans les réglages
 * (settings.payment_methods, liste séparée par des virgules ; absente = tous).
 */
function shop_payment_methods(): array
{
    $all = shop_payment_methods_available();

    $enabled = function_exists('shop_setting') ? trim(shop_setting('payment_methods', '')) : '';
    if ($enabled === '') return $all;

    $keep = array_filter(array_map('trim', explode(',', $enabled)), static fn ($key) => isset($all[$key]));
    return array_intersect_key($all, array_flip($keep));
}

function payment_method_available(string $provider): bool
{
    return isset(shop_payment_methods()[$provider]);
}

/** Référence marchande courte (11 caractères), unique dans la base. */
function payment_reference(?PDO $pdo = null): string
{
    $pdo ??= db();
    for ($try = 0; $try < 10; $try++) {
        $reference = 'B' . strtoupper(bin2hex(random_bytes(5)));
        $st = $pdo->prepare("SELECT 1 FROM orders WHERE payment_reference = ? OR stripe_session_id IN (?, ?) LIMIT 1");
        $st->execute([$reference, 'monetico:' . $reference, 'paypal:' . $reference]);
        if (!$st->fetchColumn()) return $reference;
    }
    throw new RuntimeException('Impossible de générer une référence de commande.');
}

/**
 * Jeton du lien de retour : permet d'afficher la commande sans dépendre du
 * cookie de session (Monetico revient par un POST depuis un autre domaine,
 * où le cookie SameSite=Lax n'est pas envoyé).
 */
function payment_return_secret(string $provider): string
{
    if ($provider === 'monetico') {
        $config = monetico_config();
        return (string) ($config['key'] ?? '');
    }
    if ($provider === 'paypal') {
        $config = paypal_config();
        return (string) ($config['secret'] ?? '');
    }
    return '';
}

function payment_return_token(int $orderId, string $provider): string
{
    $secret = payment_return_secret($provider);
    if ($secret === '') return '';
    return hash_hmac('sha256', 'order:' . $orderId . ':' . $provider, $secret);
}

function payment_return_token_valid(int $orderId, string $provider, string $token): bool
{
    $expected = payment_return_token($orderId, $provider);
    return $expected !== '' && $token !== '' && hash_equals($expected, $token);
}

/** Commande retrouvée par l'identifiant de paiement du fournisseur. */
function order_by_provider_checkout(string $provider, string $checkoutId, ?PDO $pdo = null): ?array
{
    if ($checkoutId === '') return null;
    $st = ($pdo ?? db())->prepare('SELECT * FROM orders WHERE payment_provider = ? AND provider_checkout_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$provider, $checkoutId]);
    return $st->fetch() ?: null;
}

/** Fichier de réglages d'un fournisseur (client ID, secret, mode), jamais servi par le web. */
function payment_config_file(string $provider): string
{
    return rtrim(SECRETS_DIR, '/') . '/' . $provider . '.json';
}

function payment_config(string $provider): ?array
{
    $file = payment_config_file($provider);
    if (!is_file($file)) return null;
    $config = json_decode((string) file_get_contents($file), true);
    return is_array($config) ? $config : null;
}

function payment_config_save(string $provider, array $config): bool
{
    $dir = rtrim(SECRETS_DIR, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return false;
    $file = payment_config_file($provider);
    if (@file_put_contents($file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) return false;
    @chmod($file, 0600);
    return true;
}

/** Montant au format attendu par les API (« 62.73 »), sans séparateur de milliers. */
function payment_amount_value(int $cents): string
{
    return number_format(max(0, $cents) / 100, 2, '.', '');
}

/**
 * Passe une commande en « payée » d'après une confirmation Monetico ou PayPal.
 * Le passage est atomique (… AND status <> 'paid') : le retour navigateur et
 * l'IPN (ou un second appel) ne décrémentent le stock qu'une seule fois.
 */
function order_finalize_external(array $order, string $provider, string $paymentId, string $authorization = '', string $detail = '', string $paidAt = ''): bool
{
    $orderId = (int) $order['id'];
    $upd = db()->prepare("UPDATE orders SET status = 'paid',
            payment_provider = ?, provider_payment_id = COALESCE(NULLIF(?, ''), provider_payment_id),
            payment_authorization = COALESCE(NULLIF(?, ''), payment_authorization),
            payment_detail = COALESCE(NULLIF(?, ''), payment_detail),
            payment_date = COALESCE(NULLIF(?, ''), payment_date)
        WHERE id = ? AND status <> 'paid'");
    $upd->execute([
        $provider,
        $paymentId,
        $authorization,
        $detail,
        $paidAt !== '' ? $paidAt : (new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')))->format('c'),
        $orderId,
    ]);
    if ($upd->rowCount() === 0) return false;

    decrement_stock_for_order(json_decode((string) $order['items'], true) ?: []);
    order_prepare_after_payment($order, []);
    order_event($orderId, 'paiement', 'Paiement confirmé (' . format_cents((int) $order['amount_total']) . ') — ' . payment_provider_label($provider), $provider, 'paid');

    account_upsert_contact(
        (string) ($order['email'] ?? ''),
        '',
        'client',
        'commande',
        (string) ($order['phone'] ?? '')
    );
    try {
        order_send_confirmation($orderId);
    } catch (Throwable $e) {
        error_log('confirmation commande ' . $provider . ' : ' . $e->getMessage());
    }
    try {
        require_once __DIR__ . '/plugins.php';
        plugin_fire('order_paid', ['order_id' => $orderId]);
    } catch (Throwable $e) {
        error_log('order_paid ' . $provider . ' : ' . $e->getMessage());
    }
    return true;
}
