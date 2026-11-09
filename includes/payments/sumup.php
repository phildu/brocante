<?php

// SumUp : compte SumUp du commerçant, page de paiement hébergée (Hosted
// Checkout). La commande est créée ici, le client paie sur la page SumUp
// (carte, Apple Pay, Google Pay), puis le retour navigateur ou le webhook
// confirme le paiement. Réglages dans .secrets/sumup.json (api_key,
// merchant_code).
//
// Vérifié dans la documentation SumUp (2026-11-09) : base https://api.sumup.com,
// POST /v0.1/checkouts (hosted_checkout.enabled, redirect_url, return_url),
// GET /v0.1/checkouts/{id} (status PENDING / PAID / FAILED / EXPIRED,
// transactions[]), POST /v1.0/merchants/{code}/payments/{transaction_id}/refunds.
// Montants en unités principales (12.00 = 12 euros), pas en centimes. Le webhook
// (return_url) n'est pas signé : il envoie {event_type, id} et l'application doit
// relire le checkout auprès de l'API. Le bac à sable utilise la même adresse
// d'API avec la clé et le code marchand d'un compte bac à sable. Rien n'est testé
// ici contre un vrai compte SumUp.

const SUMUP_API_BASE = 'https://api.sumup.com';

function sumup_config(): ?array
{
    $config = payment_config('sumup');
    if (!$config) return null;
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    $merchant = strtoupper(trim((string) ($config['merchant_code'] ?? '')));
    if ($apiKey === '' || !preg_match('/^[A-Z0-9]{4,16}$/', $merchant)) return null;
    return [
        'api_key' => $apiKey,
        'merchant_code' => $merchant,
        'api_base' => rtrim(trim((string) ($config['api_base'] ?? '')), '/'),
    ];
}

function sumup_configured(): bool
{
    return sumup_config() !== null;
}

function sumup_api_base(array $config): string
{
    return ($config['api_base'] ?? '') !== '' ? $config['api_base'] : SUMUP_API_BASE;
}

/** Appel JSON à l'API SumUp ; lève une exception lisible en cas de refus. */
function sumup_request(array $config, string $method, string $path, ?array $body = null): array
{
    $ch = curl_init(sumup_api_base($config) . $path);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $config['api_key'],
        ],
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Erreur réseau SumUp : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string) $raw, true);
    $data = is_array($data) ? $data : [];
    if ($status >= 400) {
        $reason = (string) ($data['message'] ?? $data['error_message'] ?? $data['detail'] ?? $data['title'] ?? $data['error_code'] ?? ('HTTP ' . $status));
        throw new RuntimeException('SumUp a refusé la demande : ' . $reason);
    }
    return $data;
}

/** Montant en unités principales pour l'API SumUp (6273 centimes → 62.73). */
function sumup_amount_major(int $cents): float
{
    return round(max(0, $cents) / 100, 2);
}

/** Montant renvoyé par SumUp (unités principales) converti en centimes. */
function sumup_cents($amount): int
{
    return (int) round(((float) $amount) * 100);
}

/**
 * Crée un checkout SumUp à page hébergée et renvoie le tableau de l'API
 * (id, hosted_checkout_url, status…). $webhookUrl reçoit les changements d'état.
 */
function sumup_create_checkout(array $config, int $cents, string $reference, string $redirectUrl, string $webhookUrl, string $shopName): array
{
    $body = [
        'checkout_reference' => mb_substr($reference, 0, 64),
        'amount' => sumup_amount_major($cents),
        'currency' => 'EUR',
        'merchant_code' => $config['merchant_code'],
        'description' => mb_substr('Commande ' . $reference . ' — ' . $shopName, 0, 255),
        'redirect_url' => $redirectUrl,
        'return_url' => $webhookUrl,
        'hosted_checkout' => ['enabled' => true],
    ];
    return sumup_request($config, 'POST', '/v0.1/checkouts', $body);
}

function sumup_get_checkout(array $config, string $checkoutId): array
{
    if ($checkoutId === '') throw new RuntimeException('Paiement SumUp inconnu.');
    return sumup_request($config, 'GET', '/v0.1/checkouts/' . rawurlencode($checkoutId));
}

/** Page de paiement hébergée ; n'accepte qu'une adresse https chez SumUp. */
function sumup_checkout_url(array $data): string
{
    $url = trim((string) ($data['hosted_checkout_url'] ?? ''));
    $parts = parse_url($url);
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || ($host !== 'sumup.com' && !str_ends_with($host, '.sumup.com'))) return '';
    return $url;
}

function sumup_state(array $data): string
{
    return strtolower((string) ($data['status'] ?? ''));
}

function sumup_is_paid(array $data): bool
{
    return sumup_state($data) === 'paid';
}

/** Montant du checkout en centimes (-1 si absent). */
function sumup_checkout_cents(array $data): int
{
    return isset($data['amount']) ? sumup_cents($data['amount']) : -1;
}

/** Transaction réussie rattachée au checkout (la dernière si plusieurs essais). */
function sumup_paid_transaction(array $data): ?array
{
    $found = null;
    foreach ((array) ($data['transactions'] ?? []) as $transaction) {
        if (is_array($transaction) && strtoupper((string) ($transaction['status'] ?? '')) === 'SUCCESSFUL') $found = $transaction;
    }
    return $found;
}

/** Identifiant de la transaction SumUp (celui qu'il faut pour rembourser), sinon du checkout. */
function sumup_payment_id(array $data): string
{
    $transaction = sumup_paid_transaction($data);
    if ($transaction && !empty($transaction['id'])) return (string) $transaction['id'];
    return (string) ($data['id'] ?? '');
}

/** Libellé lisible pour la fiche commande (carte, Apple Pay, Google Pay…). */
function sumup_payment_detail(array $data): string
{
    $transaction = sumup_paid_transaction($data);
    $entry = $transaction ? strtoupper((string) ($transaction['entry_mode'] ?? '')) : '';
    $label = [
        'APPLE_PAY' => 'Apple Pay',
        'GOOGLE_PAY' => 'Google Pay',
        'PAYPAL' => 'PayPal',
    ][$entry] ?? 'Carte';
    $code = $transaction ? trim((string) ($transaction['transaction_code'] ?? '')) : '';
    return 'SumUp — ' . $label . ($code !== '' ? ' · ' . $code : '');
}

/**
 * Rembourse tout ou partie d'un paiement SumUp (transaction réussie). Le
 * montant est en unités principales ; l'API renvoie une réponse vide en cas de
 * succès, l'identifiant de remboursement est donc dérivé de la transaction.
 */
function sumup_refund(array $order, int $cents, array $config): string
{
    $transactionId = trim((string) ($order['provider_payment_id'] ?? ''));
    if ($transactionId === '') throw new RuntimeException('Transaction SumUp introuvable pour cette commande.');
    $path = '/v1.0/merchants/' . rawurlencode($config['merchant_code']) . '/payments/' . rawurlencode($transactionId) . '/refunds';
    $refund = sumup_request($config, 'POST', $path, ['amount' => sumup_amount_major(max(1, $cents))]);
    return (string) ($refund['id'] ?? ('sumup-refund-' . substr($transactionId, 0, 8) . '-' . $cents));
}
