<?php

// Textes et identité d'une période de fermeture, communs aux boutiques (includes/closure-alerts.php, envoi par le module Notifications) et aux
// galeries (includes/gallery-hours.php, envoi par e-mail). Rien ici ne dépend d'un mode d'envoi : uniquement includes/shop-hours.php.

require_once __DIR__ . '/shop-hours.php';

/** Identifiant stable d'une période de fermeture (dates Du/Au) : il survit à un changement de type ou de motif. */
function closure_alert_key(array $closure): string
{
    return substr(sha1((string) ($closure['from'] ?? '') . '|' . (string) ($closure['to'] ?? '')), 0, 12);
}

/** Une fermeture terminée n'a plus à être annoncée. */
function closure_alert_is_upcoming(array $closure, ?DateTimeImmutable $now = null): bool
{
    $now ??= shop_hours_now();
    return (string) $closure['to'] >= $now->format('Y-m-d');
}

/** « le mardi 3 novembre » ou « du lundi 3 au samedi 8 novembre ». */
function closure_alert_range_text(array $closure): string
{
    $tz = shop_hours_timezone();
    $from = new DateTimeImmutable($closure['from'] . ' 00:00', $tz);
    $to = new DateTimeImmutable($closure['to'] . ' 00:00', $tz);
    if ($closure['from'] === $closure['to']) return 'le ' . shop_hours_date_label($from);
    return 'du ' . shop_hours_date_label($from) . ' au ' . shop_hours_date_label($to);
}

/**
 * Message proposé au vendeur (modifiable avant l'envoi), avec les variables du module Notifications : {prenom} {site} {lien_preferences}.
 * @return array{subject: string, body: string, short_body: string}
 */
function closure_alert_message(array $closure): array
{
    $type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture exceptionnelle';
    $range = closure_alert_range_text($closure);
    $label = trim((string) $closure['label']);
    $reopen = '';
    $next = shop_hours_next_open(new DateTimeImmutable($closure['to'] . ' 23:59', shop_hours_timezone()));
    if ($next) $reopen = 'réouverture ' . shop_hours_date_label($next['date']) . ' à ' . shop_hours_format_time($next['time']);

    $body = "Bonjour {prenom},\n\n{site} sera fermé " . $range . ($label !== '' ? ' (' . $label . ')' : '') . '.'
        . ($reopen !== '' ? "\nNous vous accueillons de nouveau : " . $reopen . '.' : '')
        . "\n\nMerci de votre compréhension, et à très bientôt.\n{site}"
        . "\n\n—\nPour gérer vos notifications ou vous désinscrire : {lien_preferences}";
    $short = '{site} : fermé ' . $range . ($label !== '' ? ' (' . $label . ')' : '') . '.' . ($reopen !== '' ? ' ' . ucfirst($reopen) . '.' : '');
    return ['subject' => $type . ' — {site}', 'body' => $body, 'short_body' => $short];
}
