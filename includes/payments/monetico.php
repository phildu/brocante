<?php

// Monetico Paiement (CIC / Crédit Mutuel / OBC) : formulaire bancaire signé
// (MAC) puis confirmation serveur. Le compte et la clé sont ceux du
// commerçant, enregistrés dans .secrets/monetico.json. Aucune clé n'est
// partagée entre boutiques.

/** Réglages Monetico valides, ou null. */
function monetico_config(): ?array
{
    $config = payment_config('monetico');
    if (!$config) return null;
    $tpe = trim((string) ($config['tpe'] ?? ''));
    $key = trim((string) ($config['key'] ?? ''));
    $societe = trim((string) ($config['societe'] ?? ''));
    $mode = (string) ($config['mode'] ?? 'test');
    if (!preg_match('/^[0-9]{7}$/', $tpe)) return null;
    if (!preg_match('/^[0-9A-Fa-f]{40}$/', $key)) return null;
    if ($societe === '' || !in_array($mode, ['test', 'production'], true)) return null;
    return ['tpe' => $tpe, 'key' => strtoupper($key), 'societe' => $societe, 'mode' => $mode];
}

function monetico_configured(): bool
{
    return monetico_config() !== null;
}

function monetico_is_test(array $config): bool
{
    return ($config['mode'] ?? 'test') === 'test';
}

/** Clé de 40 caractères hexadécimaux transformée en clé binaire (algorithme Monetico). */
function monetico_usable_key(string $key): string
{
    $key = strtoupper(trim($key));
    if (!preg_match('/^[0-9A-F]{40}$/', $key)) {
        throw new InvalidArgumentException('La clé Monetico doit contenir 40 caractères hexadécimaux.');
    }
    $hexStrKey = substr($key, 0, 38);
    $hexFinal = substr($key, 38, 2) . '00';
    $cca0 = ord($hexFinal[0]);
    if ($cca0 > 70 && $cca0 < 97) {
        $hexStrKey .= chr($cca0 - 23) . $hexFinal[1];
    } elseif ($hexFinal[1] === 'M') {
        $hexStrKey .= $hexFinal[0] . '0';
    } else {
        $hexStrKey .= substr($hexFinal, 0, 2);
    }
    return pack('H*', $hexStrKey);
}

/** MAC Monetico : champs triés, séparés par « * », HMAC-SHA1 en majuscules. */
function monetico_seal(array $fields, string $key): string
{
    unset($fields['MAC']);
    ksort($fields);
    $query = http_build_query($fields, '', '*');
    $query = urldecode($query);
    return strtoupper(hash_hmac('sha1', $query, monetico_usable_key($key)));
}

function monetico_amount(int $cents): string
{
    return payment_amount_value($cents) . 'EUR';
}

/** Montant Monetico (« 62.73EUR ») en centimes, ou null si illisible. */
function monetico_amount_cents(string $value): ?int
{
    if (!preg_match('/^([0-9]+)(?:[.,]([0-9]{1,2}))?\s*EUR$/i', trim($value), $m)) return null;
    $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
    return $cents;
}

function monetico_payment_url(array $config): string
{
    return 'https://p.monetico-services.com/' . (monetico_is_test($config) ? 'test/' : '') . 'paiement.cgi';
}

function monetico_refund_url(array $config): string
{
    return 'https://p.monetico-services.com/' . (monetico_is_test($config) ? 'test/' : '') . 'recredit_paiement.cgi';
}

/** Date à l'heure de Paris, au format attendu par Monetico. */
function monetico_paris_date(string $format, ?string $timestamp = null): string
{
    $date = new DateTimeImmutable($timestamp !== null && $timestamp !== '' ? $timestamp : 'now', new DateTimeZone('UTC'));
    return $date->setTimezone(new DateTimeZone('Europe/Paris'))->format($format);
}

/**
 * Champs du formulaire de paiement, MAC compris. Le client est envoyé sur la
 * page bancaire Monetico par un POST automatique (voir checkout.php).
 */
function monetico_payment_fields(array $order, array $config, int $amountCents, string $reference, string $email, string $shopName, string $returnBase): array
{
    $fields = [
        'TPE' => $config['tpe'],
        'date' => monetico_paris_date('d/m/Y:H:i:s'),
        'montant' => monetico_amount($amountCents),
        'reference' => $reference,
        'version' => '3.0',
        'url_retour_ok' => $returnBase . '/monetico-return.php?result=ok',
        'url_retour_err' => $returnBase . '/monetico-return.php?result=err',
        'lgue' => 'FR',
        'societe' => $config['societe'],
        'texte-libre' => mb_substr('Commande #' . (int) $order['id'] . ' — ' . $shopName, 0, 150),
        'mail' => $email,
        'contexte_commande' => base64_encode('{}'),
    ];
    $fields['MAC'] = monetico_seal($fields, $config['key']);
    return $fields;
}

/** Échec ou succès d'après le code-retour Monetico. */
function monetico_return_is_paid(string $code): bool
{
    return in_array(strtolower(trim($code)), ['paiement', 'payetest'], true);
}

function monetico_return_is_cancelled(string $code): bool
{
    return str_starts_with(strtolower(trim($code)), 'annulation');
}

/**
 * Vérifie une réponse Monetico (retour navigateur ou IPN) : MAC, TPE,
 * référence, montant et existence de la commande.
 * -> ['ok' => bool, 'paid' => bool, 'message' => string, 'order' => ?array]
 */
function monetico_validate_response(array $post, array $config): array
{
    $mac = strtoupper(trim((string) ($post['MAC'] ?? '')));
    if ($mac === '') return ['ok' => false, 'paid' => false, 'message' => 'MAC absent.', 'order' => null];

    // La documentation Monetico impose de signer tous les champs reçus, sauf MAC.
    $expected = monetico_seal($post, $config['key']);
    if (!hash_equals($expected, $mac)) {
        return ['ok' => false, 'paid' => false, 'message' => 'Signature Monetico invalide.', 'order' => null];
    }
    if (trim((string) ($post['TPE'] ?? '')) !== $config['tpe']) {
        return ['ok' => false, 'paid' => false, 'message' => 'TPE inattendu.', 'order' => null];
    }

    $reference = trim((string) ($post['reference'] ?? ''));
    $order = order_by_provider_checkout('monetico', $reference);
    if (!$order) {
        return ['ok' => false, 'paid' => false, 'message' => 'Commande Monetico introuvable (' . $reference . ').', 'order' => null];
    }
    $amount = monetico_amount_cents((string) ($post['montant'] ?? ''));
    if ($amount === null || $amount !== (int) $order['amount_total']) {
        return ['ok' => false, 'paid' => false, 'message' => 'Montant Monetico différent de la commande.', 'order' => $order];
    }

    $code = (string) ($post['code-retour'] ?? '');
    return [
        'ok' => true,
        'paid' => monetico_return_is_paid($code),
        'cancelled' => monetico_return_is_cancelled($code),
        'code' => $code,
        'message' => 'Code retour : ' . ($code !== '' ? $code : 'inconnu'),
        'order' => $order,
        'authorization' => trim((string) ($post['numauto'] ?? '')),
        'masked_card' => trim((string) ($post['cbmasquee'] ?? '')),
        'brand' => trim((string) ($post['brand'] ?? '')),
        'payment_method' => trim((string) ($post['modepaiement'] ?? '')),
    ];
}

/**
 * Demande un re-crédit Monetico. Retourne l'identifiant du dossier.
 * L'option « re-crédit » doit être activée par le conseiller bancaire.
 */
function monetico_refund(array $order, int $cents, array $config): string
{
    $reference = trim((string) ($order['payment_reference'] ?? ''));
    if ($reference === '') $reference = trim((string) ($order['provider_checkout_id'] ?? ''));
    $authorization = trim((string) ($order['payment_authorization'] ?? ''));
    if ($authorization === '') $authorization = trim((string) ($order['provider_payment_id'] ?? ''));
    if ($reference === '' || $authorization === '') {
        throw new RuntimeException("Autorisation Monetico absente : le re-crédit ne peut pas être demandé automatiquement.");
    }

    $paymentDate = (string) ($order['payment_date'] ?? '');
    $orderDate = (string) ($order['created_at'] ?? '');
    $fields = [
        'TPE' => $config['tpe'],
        'date' => monetico_paris_date('d/m/Y:H:i:s'),
        'date_commande' => monetico_paris_date('d/m/Y', $orderDate !== '' ? $orderDate . ' UTC' : null),
        'date_remise' => monetico_paris_date('d/m/Y', $paymentDate !== '' ? $paymentDate : ($orderDate !== '' ? $orderDate . ' UTC' : null)),
        'num_autorisation' => $authorization,
        'montant' => monetico_amount((int) $order['amount_total']),
        'montant_recredit' => monetico_amount($cents),
        'montant_possible' => monetico_amount(order_remaining_refund($order)),
        'reference' => $reference,
        'lgue' => 'FR',
        'societe' => $config['societe'],
        'version' => '3.0',
    ];
    $fields['MAC'] = monetico_seal($fields, $config['key']);

    $body = monetico_http_post(monetico_refund_url($config), $fields);
    $answer = monetico_parse_answer($body);
    $cdr = trim((string) ($answer['cdr'] ?? ''));
    if ($cdr !== '1') {
        $reason = trim((string) ($answer['lib'] ?? $body));
        throw new RuntimeException('Monetico a refusé le re-crédit' . ($reason !== '' ? ' : ' . mb_substr($reason, 0, 180) : '') . ". Vérifiez que l'option re-crédit est activée sur le contrat.");
    }
    if (isset($answer['reference']) && trim((string) $answer['reference']) !== $reference) {
        throw new RuntimeException('Monetico a répondu sur une autre référence : le remboursement est à vérifier dans le back-office.');
    }
    return trim((string) ($answer['numero_dossier'] ?? '')) ?: 'monetico-' . (int) $order['id'] . '-' . time();
}

function monetico_http_post(string $url, array $fields): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields, '', '&'),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    if ($body === false) throw new RuntimeException('Erreur réseau Monetico : ' . curl_error($ch));
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status >= 400) throw new RuntimeException('Monetico a répondu HTTP ' . $status . '.');
    return (string) $body;
}

/** Réponse Monetico : « clé=valeur » sur une ligne, ou une par ligne. */
function monetico_parse_answer(string $body): array
{
    $body = trim($body);
    if ($body === '') return [];
    parse_str(str_replace(["\r\n", "\n"], '&', $body), $answer);
    return is_array($answer) ? $answer : [];
}
