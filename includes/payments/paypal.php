<?php

// PayPal (Orders v2) : compte marchand propre à la boutique. OAuth client
// credentials, création de commande, capture au retour et remboursement de la
// capture. Réglages dans .secrets/paypal.json (client_id, secret, mode).

function paypal_config(): ?array
{
    $config = payment_config('paypal');
    if (!$config) return null;
    $clientId = trim((string) ($config['client_id'] ?? ''));
    $secret = trim((string) ($config['secret'] ?? ''));
    $mode = (string) ($config['mode'] ?? 'sandbox');
    if ($clientId === '' || $secret === '' || !in_array($mode, ['sandbox', 'live'], true)) return null;
    return [
        'client_id' => $clientId,
        'secret' => $secret,
        'mode' => $mode,
        'api_base' => rtrim(trim((string) ($config['api_base'] ?? '')), '/'),
    ];
}

function paypal_configured(): bool
{
    return paypal_config() !== null;
}

function paypal_api_base(array $config): string
{
    if (($config['api_base'] ?? '') !== '') return $config['api_base'];
    return ($config['mode'] ?? 'sandbox') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypal_token(array $config): string
{
    static $tokens = [];
    $cacheKey = $config['client_id'] . '|' . $config['mode'] . '|' . paypal_api_base($config);
    if (!empty($tokens[$cacheKey]) && $tokens[$cacheKey][1] > time() + 30) return $tokens[$cacheKey][0];

    $ch = curl_init(paypal_api_base($config) . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_USERPWD => $config['client_id'] . ':' . $config['secret'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('Erreur réseau PayPal : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string) $body, true) ?: [];
    if ($status >= 400 || empty($data['access_token'])) {
        $reason = (string) ($data['error_description'] ?? $data['error'] ?? ('HTTP ' . $status));
        throw new RuntimeException('PayPal a refusé les identifiants : ' . $reason);
    }
    $tokens[$cacheKey] = [(string) $data['access_token'], time() + max(60, (int) ($data['expires_in'] ?? 300))];
    return (string) $data['access_token'];
}

/** Appel JSON à l'API PayPal ; lève une exception lisible en cas de refus. */
function paypal_request(array $config, string $method, string $path, ?array $body = null, array $extraHeaders = [], ?string $rawBody = null): array
{
    $ch = curl_init(paypal_api_base($config) . $path);
    $headers = array_merge([
        'Content-Type: application/json',
        'Authorization: Bearer ' . paypal_token($config),
    ], $extraHeaders);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = $rawBody ?? json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Erreur réseau PayPal : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) $data = [];
    if ($status >= 400) {
        $message = (string) ($data['message'] ?? $data['error_description'] ?? ('Erreur PayPal HTTP ' . $status));
        $issue = (string) ($data['details'][0]['issue'] ?? '');
        $detail = (string) ($data['details'][0]['description'] ?? '');
        if ($detail !== '' && $detail !== $message) $message .= ' — ' . $detail;
        if ($issue !== '') $message .= ' (' . $issue . ')';
        throw new RuntimeException($message);
    }
    return $data;
}

function paypal_create_order(array $config, int $amountCents, string $reference, string $returnUrl, string $cancelUrl, string $brandName): array
{
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $reference,
            'invoice_id' => $reference,
            'description' => mb_substr('Commande ' . $reference, 0, 127),
            'amount' => ['currency_code' => 'EUR', 'value' => payment_amount_value($amountCents)],
        ]],
        'application_context' => [
            'brand_name' => mb_substr($brandName, 0, 127),
            'locale' => 'fr-FR',
            'landing_page' => 'NO_PREFERENCE',
            'shipping_preference' => 'NO_SHIPPING',
            'user_action' => 'PAY_NOW',
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ],
    ];
    return paypal_request($config, 'POST', '/v2/checkout/orders', $body, ['PayPal-Request-Id: create-' . $reference]);
}

/** Lien d'approbation PayPal (rel « approve » ou « payer-action »). */
function paypal_approve_url(array $orderData): string
{
    foreach ((array) ($orderData['links'] ?? []) as $link) {
        if (in_array((string) ($link['rel'] ?? ''), ['approve', 'payer-action'], true) && !empty($link['href'])) {
            return (string) $link['href'];
        }
    }
    return '';
}

function paypal_capture_order(array $config, string $paypalOrderId, string $reference): array
{
    return paypal_request(
        $config,
        'POST',
        '/v2/checkout/orders/' . rawurlencode($paypalOrderId) . '/capture',
        null,
        ['PayPal-Request-Id: capture-' . $reference],
        '{}'
    );
}

function paypal_get_order(array $config, string $paypalOrderId): array
{
    return paypal_request($config, 'GET', '/v2/checkout/orders/' . rawurlencode($paypalOrderId));
}

/** Capture, montant et payeur extraits d'une commande PayPal. */
function paypal_capture_data(array $orderData): array
{
    $unit = (array) (($orderData['purchase_units'] ?? [])[0] ?? []);
    $capture = (array) (($unit['payments']['captures'] ?? [])[0] ?? []);
    $amount = (array) ($capture['amount'] ?? $unit['amount'] ?? []);
    return [
        'order_status' => (string) ($orderData['status'] ?? ''),
        'capture_id' => (string) ($capture['id'] ?? ''),
        'capture_status' => (string) ($capture['status'] ?? ''),
        'amount_cents' => paypal_value_cents((string) ($amount['value'] ?? '')),
        'currency' => strtoupper((string) ($amount['currency_code'] ?? '')),
        'email' => (string) ($orderData['payer']['email_address'] ?? ''),
        'name' => trim((string) (($orderData['payer']['name']['given_name'] ?? '') . ' ' . ($orderData['payer']['name']['surname'] ?? ''))),
    ];
}

function paypal_value_cents(string $value): ?int
{
    if (!preg_match('/^([0-9]+)(?:\.([0-9]{1,2}))?$/', trim($value), $m)) return null;
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
}

function paypal_is_paid(array $orderData): bool
{
    $data = paypal_capture_data($orderData);
    return $data['order_status'] === 'COMPLETED' && $data['capture_status'] === 'COMPLETED';
}

/** Rembourse une capture PayPal (totale ou partielle). Retourne l'identifiant du remboursement. */
function paypal_refund(array $order, int $cents, array $config): string
{
    $captureId = trim((string) ($order['provider_payment_id'] ?? ''));
    if ($captureId === '') throw new RuntimeException('Capture PayPal absente : remboursement impossible automatiquement.');

    $reference = trim((string) ($order['payment_reference'] ?? ''));
    $requestId = 'refund-' . (int) $order['id'] . '-' . $cents . '-' . (int) ($order['refunded_cents'] ?? 0);
    $body = ['amount' => ['value' => payment_amount_value($cents), 'currency_code' => 'EUR']];
    $refund = paypal_request(
        $config,
        'POST',
        '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
        $body,
        ['PayPal-Request-Id: ' . $requestId]
    );
    $status = strtoupper((string) ($refund['status'] ?? ''));
    if (!in_array($status, ['COMPLETED', 'PENDING'], true)) {
        throw new RuntimeException('PayPal n’a pas accepté le remboursement (statut : ' . ($status !== '' ? $status : 'inconnu') . ').');
    }
    return trim((string) ($refund['id'] ?? '')) ?: ($reference !== '' ? 'paypal-refund-' . $reference : 'paypal-refund-' . (int) $order['id'] . '-' . time());
}
