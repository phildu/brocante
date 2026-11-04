<?php

// Virement SEPA (service européen de virements automatiques) : pas de
// prestataire et pas de clé. Le commerçant publie son IBAN (numéro de compte
// bancaire international), le client vire le montant avec une référence
// unique, puis le commerçant marque le virement reçu dans l'administration.
// Les coordonnées bancaires sont des réglages de boutique (table settings),
// jamais servies à un visiteur qui n'a pas le lien signé de sa commande.

function sepa_config(): array
{
    return [
        'holder' => trim(shop_setting('sepa_holder', '')),
        'iban' => sepa_iban_normalize(shop_setting('sepa_iban', '')),
        'bic' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', shop_setting('sepa_bic', '')) ?? ''),
        'bank' => trim(shop_setting('sepa_bank', '')),
        'delay_days' => max(1, min(30, (int) shop_setting('sepa_delay_days', '7'))),
        'note' => trim(shop_setting('sepa_instructions', '')),
    ];
}

function sepa_configured(): bool
{
    $config = sepa_config();
    return trim((string) $config['holder']) !== '' && sepa_iban_valid((string) $config['iban']);
}

/** IBAN sans espaces ni minuscules (« FR76 3000 … » → « FR763000… »). */
function sepa_iban_normalize(string $iban): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? '');
}

/**
 * IBAN valide : 15 à 34 caractères, deux lettres de pays, deux chiffres de
 * clé, puis un contrôle modulo 97 (norme ISO 13616). Refuse une faute de
 * frappe comme un faux numéro.
 */
function sepa_iban_valid(string $iban): bool
{
    $iban = sepa_iban_normalize($iban);
    if (strlen($iban) < 15 || strlen($iban) > 34) return false;
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) return false;
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
    }
    $remainder = 0;
    foreach (str_split($numeric) as $digit) {
        $remainder = ($remainder * 10 + (int) $digit) % 97;
    }
    return $remainder === 1;
}

/** IBAN lisible par groupes de quatre caractères. */
function sepa_iban_display(string $iban): string
{
    return trim(chunk_split(sepa_iban_normalize($iban), 4, ' '));
}

function sepa_bic_valid(string $bic): bool
{
    return $bic === '' || preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper($bic)) === 1;
}

/** Référence à indiquer dans le libellé du virement (celle de la commande). */
function sepa_reference(array $order): string
{
    $reference = trim((string) ($order['payment_reference'] ?? ''));
    return $reference !== '' ? $reference : 'B' . (int) $order['id'];
}

/** Date limite du virement : celle notée sur la commande, sinon création + délai réglé. */
function sepa_deadline(array $order, ?int $days = null): DateTimeImmutable
{
    $stored = trim((string) ($order['payment_deadline'] ?? ''));
    if ($stored !== '') {
        return (new DateTimeImmutable($stored))->setTimezone(new DateTimeZone('Europe/Paris'));
    }
    $days ??= sepa_config()['delay_days'];
    $created = new DateTimeImmutable((string) ($order['created_at'] ?? 'now'), new DateTimeZone('UTC'));
    return $created->modify('+' . max(1, $days) . ' days')->setTimezone(new DateTimeZone('Europe/Paris'));
}

/** Coordonnées et montant à afficher sur la page et dans l'e-mail. */
function sepa_instructions(array $order): array
{
    $config = sepa_config();
    return [
        'holder' => $config['holder'],
        'bank' => $config['bank'],
        'iban' => sepa_iban_display($config['iban']),
        'bic' => $config['bic'],
        'amount_cents' => (int) $order['amount_total'],
        'reference' => sepa_reference($order),
        'deadline' => sepa_deadline($order, $config['delay_days']),
        'delay_days' => $config['delay_days'],
        'note' => $config['note'],
        'order_id' => (int) $order['id'],
    ];
}

function sepa_instructions_url(int $orderId): string
{
    $token = payment_return_token($orderId, 'sepa');
    if ($token === '') return '';
    return rtrim(tenant_site_url(), '/') . '/sepa-instructions.php?order=' . $orderId . '&sig=' . rawurlencode($token);
}

/** E-mail : coordonnées bancaires, montant exact et référence unique. */
function order_send_transfer_instructions(int $orderId, bool $force = false): bool
{
    $order = order_get($orderId);
    if (!$order || !sepa_configured()) return false;
    [$name] = order_mail_identity($order);
    $info = sepa_instructions($order);
    $url = sepa_instructions_url($orderId);
    $body = "Bonjour,\n\n"
        . "Votre commande n° " . (int) $order['id'] . " sur $name est réservée pendant " . $info['delay_days'] . " jours.\n\n"
        . order_mail_body_lines($order) . "\n\n"
        . 'Montant à virer : ' . format_cents($info['amount_cents'])
        . ((int) $order['shipping_cents'] > 0 ? ' (dont ' . format_cents((int) $order['shipping_cents']) . ' de livraison)' : '') . "\n"
        . (function_exists('order_gift_summary') && order_gift_summary($order) !== '' ? order_gift_summary($order) . "\n" : '')
        . "Titulaire : " . $info['holder'] . "\n"
        . "IBAN : " . $info['iban'] . "\n"
        . ($info['bic'] !== '' ? 'BIC : ' . $info['bic'] . "\n" : '')
        . ($info['bank'] !== '' ? 'Banque : ' . $info['bank'] . "\n" : '')
        . "Référence à indiquer : " . $info['reference'] . "\n\n"
        . "La commande sera préparée dès réception du virement. Sans virement avant le "
        . $info['deadline']->format('d/m/Y') . ", elle sera annulée et les pièces remises en vente.\n"
        . ($info['note'] !== '' ? "\n" . $info['note'] . "\n" : '')
        . ($url !== '' ? "\nRappel des coordonnées : " . $url . "\n" : '')
        . "\nMerci d'indiquer la référence " . $info['reference'] . " dans le libellé du virement, sinon nous ne pourrons pas rattacher le paiement à votre commande."
        . order_mail_signature($order);
    return order_send_email($orderId, 'virement', "Virement à effectuer — commande n° " . (int) $order['id'] . " — $name", $body, $force);
}

function order_send_transfer_expired(int $orderId): bool
{
    $order = order_get($orderId);
    if (!$order) return false;
    [$name] = order_mail_identity($order);
    $body = "Bonjour,\n\n"
        . "Nous n'avons pas reçu le virement de votre commande n° " . (int) $order['id'] . " dans le délai indiqué.\n\n"
        . "La commande est annulée et les pièces ont été remises en vente. Si vous avez viré le montant entre-temps, répondez à ce message avec la date du virement : nous le retrouverons."
        . order_mail_signature($order);
    return order_send_email($orderId, 'virement_annule', "Commande n° " . (int) $order['id'] . " annulée — virement non reçu — $name", $body);
}
