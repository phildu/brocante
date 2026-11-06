<?php

// Revolut Merchant : compte Revolut Business du commerçant, page de paiement
// hébergée (Hosted Checkout). La commande est créée ici, le client paie sur
// Revolut, puis le retour navigateur ou le webhook signé confirme le paiement.
// Réglages dans .secrets/revolut.json (api_key, webhook_secret, mode).
//
// Vérifié dans la documentation Revolut (2026-08-17) : base
// https://merchant.revolut.com/api (bac à sable https://sandbox-merchant.revolut.com/api),
// POST /orders, GET /orders/{id}, POST /orders/{id}/refund, états pending,
// processing, authorised, completed, cancelled, failed. Webhook : en-têtes
// Revolut-Signature (« v1=<hex> ») et Revolut-Request-Timestamp (millisecondes),
// HMAC-SHA256 sur « v1.<timestamp>.<corps brut> ». Rien n'est testé ici contre
// un vrai compte Revolut.

const REVOLUT_API_VERSION = '2026-08-17';

function revolut_config(): ?array
{
    $config = payment_config('revolut');
    if (!$config) return null;
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    $mode = (string) ($config['mode'] ?? 'sandbox');
    if ($apiKey === '' || !in_array($mode, ['sandbox', 'production'], true)) return null;
    return [
        'api_key' => $apiKey,
        'webhook_secret' => trim((string) ($config['webhook_secret'] ?? '')),
        'mode' => $mode,
        'api_base' => rtrim(trim((string) ($config['api_base'] ?? '')), '/'),
        'api_version' => trim((string) ($config['api_version'] ?? '')) ?: REVOLUT_API_VERSION,
    ];
}

function revolut_configured(): bool
{
    return revolut_config() !== null;
}

function revolut_api_base(array $config): string
{
    if (($config['api_base'] ?? '') !== '') return $config['api_base'];
    return ($config['mode'] ?? 'sandbox') === 'production'
        ? 'https://merchant.revolut.com/api'
        : 'https://sandbox-merchant.revolut.com/api';
}

/** Appel JSON à l'API Revolut ; lève une exception lisible en cas de refus. */
function revolut_request(array $config, string $method, string $path, ?array $body = null, array $extraHeaders = []): array
{
    $ch = curl_init(revolut_api_base($config) . $path);
    $headers = array_merge([
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key'],
        'Revolut-Api-Version: ' . $config['api_version'],
    ], $extraHeaders);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Erreur réseau Revolut : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string) $raw, true) ?: [];
    if ($status >= 400) {
        $reason = (string) ($data['message'] ?? $data['error'] ?? $data['code'] ?? ('HTTP ' . $status));
        throw new RuntimeException('Revolut a refusé la demande : ' . $reason);
    }
    return $data;
}

/**
 * Crée une commande Revolut et renvoie le tableau de l'API (id, token,
 * checkout_url, state…). Le montant est en centimes, comme partout dans Brocs.
 */
function revolut_create_order(array $config, int $cents, string $reference, string $returnUrl, string $email, string $shopName): array
{
    $body = [
        'amount' => max(0, $cents),
        'currency' => 'EUR',
        'description' => mb_substr('Commande ' . $reference . ' — ' . $shopName, 0, 255),
        'merchant_order_data' => ['reference' => $reference],
        'redirect_url' => $returnUrl,
    ];
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $body['customer'] = ['email' => $email];
    return revolut_request($config, 'POST', '/orders', $body);
}

function revolut_get_order(array $config, string $orderId): array
{
    if ($orderId === '') throw new RuntimeException('Commande Revolut inconnue.');
    return revolut_request($config, 'GET', '/orders/' . rawurlencode($orderId));
}

/** Adresse de paiement hébergée (renvoyée par l'API, sinon reconstruite depuis le jeton). */
function revolut_checkout_url(array $orderData): string
{
    $url = trim((string) ($orderData['checkout_url'] ?? ''));
    if ($url !== '') return $url;
    $token = trim((string) ($orderData['token'] ?? ''));
    return $token !== '' ? 'https://checkout.revolut.com/payment-link/' . rawurlencode($token) : '';
}

function revolut_state(array $orderData): string
{
    return strtolower((string) ($orderData['state'] ?? ''));
}

function revolut_is_paid(array $orderData): bool
{
    return revolut_state($orderData) === 'completed';
}

/** Identifiant du paiement chez Revolut (dernier paiement de la commande, sinon la commande). */
function revolut_payment_id(array $orderData): string
{
    $payments = $orderData['payments'] ?? [];
    if (is_array($payments) && $payments) {
        $last = end($payments);
        if (is_array($last) && !empty($last['id'])) return (string) $last['id'];
    }
    return (string) ($orderData['id'] ?? '');
}

/** Libellé lisible pour la fiche commande (carte, portefeuille…). */
function revolut_payment_detail(array $orderData): string
{
    $payments = $orderData['payments'] ?? [];
    if (!is_array($payments) || !$payments) return 'Revolut';
    $last = end($payments);
    $method = '';
    if (is_array($last)) {
        $method = (string) ($last['payment_method']['type'] ?? $last['payment_method'] ?? $last['type'] ?? '');
    }
    $label = $method !== '' ? ucfirst(str_replace('_', ' ', $method)) : 'Carte';
    return 'Revolut — ' . $label;
}

/**
 * Rembourse tout ou partie d'une commande Revolut (commande à l'état
 * completed uniquement). La clé d'idempotence évite un double remboursement
 * si l'appel est rejoué.
 */
function revolut_refund(array $order, int $cents, array $config): string
{
    $orderId = trim((string) ($order['provider_checkout_id'] ?? ''));
    if ($orderId === '') throw new RuntimeException('Commande Revolut introuvable pour cette commande.');
    $reference = trim((string) ($order['payment_reference'] ?? ''));
    $idempotency = 'refund-' . (int) $order['id'] . '-' . (int) ($order['refunded_cents'] ?? 0) . '-' . $cents;
    $body = [
        'amount' => max(1, $cents),
        'currency' => 'EUR',
        'description' => mb_substr('Remboursement commande ' . ((int) $order['id']) . ($reference !== '' ? ' — ' . $reference : ''), 0, 255),
    ];
    $refund = revolut_request($config, 'POST', '/orders/' . rawurlencode($orderId) . '/refund', $body, [
        'Idempotency-Key: ' . $idempotency,
    ]);
    return (string) ($refund['id'] ?? '');
}

/**
 * Signature du webhook : HMAC-SHA256 de « v1.<timestamp>.<corps brut> » avec le
 * secret du webhook (wsk_…), comparé à l'en-tête Revolut-Signature. Tolérance
 * de cinq minutes pour éviter le rejeu.
 */
function revolut_webhook_valid(string $payload, string $signatureHeader, string $timestamp, string $secret): bool
{
    if ($payload === '' || $signatureHeader === '' || $timestamp === '' || $secret === '') return false;
    $timestampMs = strlen($timestamp) <= 10 ? ((int) $timestamp) * 1000 : (int) $timestamp;
    if ($timestampMs <= 0 || abs((int) round(microtime(true) * 1000) - $timestampMs) > 300000) return false;
    $expected = hash_hmac('sha256', 'v1.' . $timestamp . '.' . $payload, $secret);
    foreach (explode(',', $signatureHeader) as $part) {
        $value = trim($part);
        if (str_starts_with($value, 'v1=')) $value = substr($value, 3);
        if ($value !== '' && hash_equals($expected, strtolower($value))) return true;
    }
    return false;
}

/** Type d'événement et commande, quelle que soit l'enveloppe du webhook. */
function revolut_webhook_parts(array $payload): array
{
    $event = (string) ($payload['event'] ?? $payload['event_type'] ?? $payload['type'] ?? '');
    $order = $payload['order'] ?? $payload;
    return [strtoupper($event), is_array($order) ? $order : []];
}
