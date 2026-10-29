<?php

// Événement d'une galerie (vide-grenier, marché aux puces) : date et lieu, nombre de places ou mètres linéaires, prix au mètre.
// Les places sont numérotées automatiquement (Place 1 à Place N) : aucun éditeur visuel n'est nécessaire. Le nombre de places est
// déduit des mètres linéaires divisés par la longueur d'une place. Stocké dans la clé « event » de data/galeries/<id>.json.
//
// Les places ne sont pas écrites une par une : ge_places() les produit à la demande, avec un identifiant stable (ev1, ev2…) qui sert
// de clé aux demandes d'emplacement (data/galeries/<id>.slots.json). Réduire le nombre de places sous une place déjà réservée est
// refusé par la page organisateur, pour ne pas perdre le lien avec une demande.

const GE_MODES = ['places' => 'Nombre de places', 'meters' => 'Mètres linéaires'];
const GE_MAX_PLACES = 2000;
const GE_DEFAULT_METERS_PER_PLACE = 3.0;

function gallery_event_normalize(array $e): ?array
{
    $text = static function (string $k, int $max) use ($e): string {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($e[$k] ?? ''))), 0, $max);
    };
    $date = static function ($v): string {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : '';
    };
    $int = static function (string $k, int $min, int $max, int $default = 0) use ($e): int {
        return is_numeric($e[$k] ?? null) ? max($min, min($max, (int) $e[$k])) : $default;
    };
    $num = static function (string $k, float $min, float $max, float $default) use ($e): float {
        return is_numeric($e[$k] ?? null) ? round(max($min, min($max, (float) $e[$k])), 2) : $default;
    };
    $mode = in_array($e['mode'] ?? '', array_keys(GE_MODES), true) ? (string) $e['mode'] : 'places';
    $dateStart = $date($e['date'] ?? '');
    $dateEnd = $date($e['end_date'] ?? '');
    $bk = (array) ($e['booking'] ?? []);
    $event = [
        'title' => $text('title', 80) ?: 'Vide-grenier',
        'date' => $dateStart,
        'end_date' => $dateEnd !== '' && $dateStart !== '' && $dateEnd >= $dateStart ? $dateEnd : '',
        'place' => $text('place', 120),
        'address' => $text('address', 160),
        'postcode' => $text('postcode', 12),
        'city' => $text('city', 80),
        'opening' => $text('opening', 5),
        'closing' => $text('closing', 5),
        'notes' => mb_substr(trim((string) ($e['notes'] ?? '')), 0, 600),
        'published' => !empty($e['published']),
        'mode' => $mode,
        'places_count' => $int('places_count', 0, GE_MAX_PLACES),
        'meters_total' => $num('meters_total', 0, 1000000, 0),
        'meters_per_place' => $num('meters_per_place', 0.5, 100, GE_DEFAULT_METERS_PER_PLACE),
        'price_meter_cents' => $int('price_meter_cents', 0, 1000000),
        'price_cents' => $int('price_cents', 0, 1000000),
        'price_pro_cents' => $int('price_pro_cents', 0, 1000000),
        'booking' => [
            'enabled' => !empty($bk['enabled']),
            'hold_days' => max(1, min(60, (int) ($bk['hold_days'] ?? 7))),
            'intro' => mb_substr(trim((string) ($bk['intro'] ?? '')), 0, 300),
        ],
        'updated' => (int) ($e['updated'] ?? 0),
    ];
    $empty = $event['place'] === '' && $event['address'] === '' && $event['city'] === '' && $event['date'] === ''
        && $event['places_count'] === 0 && $event['meters_total'] <= 0 && $event['price_cents'] === 0 && $event['price_meter_cents'] === 0;
    return $empty ? null : $event;
}

function gallery_event(array $gallery): ?array
{
    return is_array($gallery['event'] ?? null) ? $gallery['event'] : null;
}

function ge_place_count(array $event): int
{
    if (($event['mode'] ?? '') === 'meters') {
        $per = max(0.5, (float) ($event['meters_per_place'] ?? GE_DEFAULT_METERS_PER_PLACE));
        return max(0, min(GE_MAX_PLACES, (int) floor(((float) ($event['meters_total'] ?? 0)) / $per)));
    }
    return max(0, min(GE_MAX_PLACES, (int) ($event['places_count'] ?? 0)));
}

/** Tarif d'une place : prix à la place, ou prix au mètre linéaire × longueur d'une place. */
function ge_place_price(array $event, bool $pro = false): ?int
{
    $fixed = $pro ? (int) ($event['price_pro_cents'] ?? 0) : (int) ($event['price_cents'] ?? 0);
    if ($fixed > 0) return $fixed;
    if (!$pro && ($event['mode'] ?? '') === 'meters' && (int) ($event['price_meter_cents'] ?? 0) > 0) {
        return (int) round((int) $event['price_meter_cents'] * (float) ($event['meters_per_place'] ?? GE_DEFAULT_METERS_PER_PLACE));
    }
    return null;
}

/** Les places numérotées de l'événement, au format d'un emplacement du plan (identifiant stable ev1…evN). */
function ge_places(array $event): array
{
    $count = ge_place_count($event);
    $price = ge_place_price($event);
    $pro = ge_place_price($event, true);
    $out = [];
    for ($n = 1; $n <= $count; $n++) {
        $out[] = ['id' => 'ev' . $n, 'kind' => 'event', 'num' => (string) $n, 'label' => '', 'zone' => '', 'pts' => [], 'shop' => '',
            'price_cents' => $price, 'price_pro_cents' => $pro];
    }
    return $out;
}

function ge_slot(array $event, string $id): ?array
{
    if (!preg_match('/^ev([0-9]{1,4})$/', $id, $m)) return null;
    $n = (int) $m[1];
    if ($n < 1 || $n > ge_place_count($event)) return null;
    return ['id' => $id, 'kind' => 'event', 'num' => (string) $n, 'label' => '', 'zone' => '', 'pts' => [], 'shop' => '',
        'price_cents' => ge_place_price($event), 'price_pro_cents' => ge_place_price($event, true)];
}

function ge_slot_name(array $slot): string
{
    return 'Place ' . (string) ($slot['num'] ?? '');
}

/** Date « 12 octobre 2026 » et éventuelle plage de dates. */
function ge_date_text(array $event): string
{
    $fmt = static function (string $d): string {
        if ($d === '') return '';
        $t = strtotime($d . ' 12:00:00');
        return $t ? ge_date_fr($t) : $d;
    };
    $a = $fmt((string) ($event['date'] ?? ''));
    $b = $fmt((string) ($event['end_date'] ?? ''));
    if ($a === '') return '';
    return $b !== '' && $b !== $a ? 'du ' . $a . ' au ' . $b : 'le ' . $a;
}

/** Date française sans dépendre de la locale du serveur. */
function ge_date_fr(int $timestamp): string
{
    static $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return (int) date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);
}

/** Lieu complet : « Place du marché, 45000 Orléans ». */
function ge_place_text(array $event): string
{
    $town = trim((string) ($event['postcode'] ?? '') . ' ' . (string) ($event['city'] ?? ''));
    return trim(implode(', ', array_filter([(string) ($event['place'] ?? ''), (string) ($event['address'] ?? ''), $town])));
}

/** Horaire : « 8 h – 18 h », ou ''. */
function ge_hours_text(array $event): string
{
    $a = (string) ($event['opening'] ?? '');
    $b = (string) ($event['closing'] ?? '');
    if ($a === '' && $b === '') return '';
    return ($a !== '' ? $a : '?') . ' – ' . ($b !== '' ? $b : '?');
}

/** Contrôles avant enregistrement. -> erreur ou null. */
function ge_validate(array $event): ?string
{
    if (empty($event['published'])) return null;
    if (($event['date'] ?? '') === '') return 'Indiquez la date de l\'événement avant de le publier.';
    if (ge_place_count($event) < 1) return ($event['mode'] ?? '') === 'meters'
        ? 'Le nombre de mètres ne permet aucune place : vérifiez les mètres linéaires et la longueur d\'une place.'
        : 'Indiquez au moins une place.';
    return null;
}
