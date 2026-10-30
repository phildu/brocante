<?php

require_once __DIR__ . '/recto-verso.php'; // shop_setting() / shop_setting_set() (table settings)

// Horaires d'ouverture, fermetures exceptionnelles et congés d'une boutique.
//
// Tout est stocké dans la table settings, en JSON : aucune colonne à ajouter.
//   opening_hours     : {"mon":{"closed":false,"ranges":[["09:00","12:30"],["14:00","19:00"]]}, …}
//   opening_closures  : [{"from":"2026-11-03","to":"2026-11-10","type":"vacation","label":"Congés d'automne"}, …]
//   opening_notice    : message libre affiché aux visiteurs (facultatif)
//
// Tant qu'aucun horaire structuré n'est enregistré, le site garde le texte
// libre « Horaires » du contenu (aucun changement pour les boutiques existantes).

const SHOP_HOURS_DAYS = [
    'mon' => 'Lundi',
    'tue' => 'Mardi',
    'wed' => 'Mercredi',
    'thu' => 'Jeudi',
    'fri' => 'Vendredi',
    'sat' => 'Samedi',
    'sun' => 'Dimanche',
];

const SHOP_HOURS_DAY_SHORT = [
    'mon' => 'Lun', 'tue' => 'Mar', 'wed' => 'Mer', 'thu' => 'Jeu', 'fri' => 'Ven', 'sat' => 'Sam', 'sun' => 'Dim',
];

const SHOP_HOURS_CLOSURE_TYPES = [
    'vacation' => 'Congés',
    'closure' => 'Fermeture exceptionnelle',
    'holiday' => 'Jour férié',
];

const SHOP_HOURS_MONTHS_FR = [
    1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
    'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
];

function shop_hours_timezone(): DateTimeZone
{
    static $tz = null;
    return $tz ??= new DateTimeZone('Europe/Paris');
}

function shop_hours_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', shop_hours_timezone());
}

/** « 9:00 », « 09h30 » ou « 9 h 30 » → « 09:30 » ; '' si invalide. */
function shop_hours_norm_time($value): string
{
    if (!is_string($value)) return '';
    if (!preg_match('/^\s*(\d{1,2})\s*[:hH]\s*(\d{1,2})?\s*$/', $value, $m)) return '';
    $h = (int) $m[1];
    $i = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
    if ($h < 0 || $h > 23 || $i < 0 || $i > 59) return '';
    return sprintf('%02d:%02d', $h, $i);
}

/** Heure lisible : « 9 h », « 12 h 30 ». */
function shop_hours_format_time(string $time): string
{
    [$h, $i] = array_map('intval', explode(':', $time));
    return $i === 0 ? $h . ' h' : $h . ' h ' . sprintf('%02d', $i);
}

/** « 9 h – 12 h 30 ». */
function shop_hours_format_range(array $range): string
{
    return shop_hours_format_time($range['from']) . ' – ' . shop_hours_format_time($range['to']);
}

/** Date en français : « mardi 3 novembre » (année ajoutée si elle diffère d'aujourd'hui). */
function shop_hours_date_label(DateTimeImmutable $date, bool $withWeekday = true): string
{
    $days = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    $label = SHOP_HOURS_MONTHS_FR[(int) $date->format('n')] ?? $date->format('F');
    $out = ($withWeekday ? $days[(int) $date->format('N') - 1] . ' ' : '') . (int) $date->format('j') . ' ' . $label;
    if ($date->format('Y') !== shop_hours_now()->format('Y')) $out .= ' ' . $date->format('Y');
    return $out;
}

/** Normalise une grille hebdomadaire reçue du formulaire ou du JSON. */
function shop_hours_normalize_schedule(array $raw): array
{
    $out = [];
    foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
        $row = $raw[$day] ?? [];
        if (!is_array($row)) $row = [];
        $closed = !empty($row['closed']);
        $pairs = [];
        if (isset($row['ranges']) && is_array($row['ranges'])) {
            foreach ($row['ranges'] as $range) {
                if (is_array($range)) $pairs[] = [$range['from'] ?? '', $range['to'] ?? ''];
            }
        } else {
            $froms = $row['from'] ?? [];
            $tos = $row['to'] ?? [];
            if (!is_array($froms)) $froms = [$froms];
            if (!is_array($tos)) $tos = [$tos];
            foreach ($froms as $i => $from) $pairs[] = [$from, $tos[$i] ?? ''];
        }
        $ranges = [];
        foreach ($pairs as [$from, $to]) {
            if (count($ranges) >= 4) break;
            $from = shop_hours_norm_time($from);
            $to = shop_hours_norm_time($to);
            if ($from === '' || $to === '' || $from >= $to) continue;
            $ranges[] = ['from' => $from, 'to' => $to];
        }
        $out[$day] = ['closed' => $closed || !$ranges, 'ranges' => $closed ? [] : $ranges];
    }
    return $out;
}

/** Grille hebdomadaire enregistrée, ou null tant qu'aucun horaire structuré n'existe. */
function shop_hours_schedule(): ?array
{
    static $loaded = false, $schedule = null;
    if ($loaded) return $schedule;
    $loaded = true;
    $raw = shop_setting('opening_hours', '');
    if (trim($raw) === '') return $schedule = null;
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) return $schedule = null;
    return $schedule = shop_hours_normalize_schedule($decoded);
}

function shop_hours_save_schedule(array $schedule): void
{
    shop_setting_set('opening_hours', json_encode(shop_hours_normalize_schedule($schedule), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Grille proposée dans l'éditeur tant que rien n'est enregistré. */
function shop_hours_editor_schedule(): array
{
    $saved = shop_hours_schedule();
    if ($saved !== null) return $saved;
    $default = [];
    foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
        $closed = $day === 'sun';
        $default[$day] = ['closed' => $closed, 'ranges' => $closed ? [] : [['from' => '10:00', 'to' => '19:00']]];
    }
    return $default;
}

/** Fermetures et congés enregistrés (triés par date de début). */
function shop_hours_closures(): array
{
    $raw = shop_setting('opening_closures', '');
    $decoded = trim($raw) === '' ? [] : json_decode($raw, true);
    if (!is_array($decoded)) return [];
    $out = [];
    foreach ($decoded as $row) {
        if (!is_array($row)) continue;
        $from = trim((string) ($row['from'] ?? ''));
        $to = trim((string) ($row['to'] ?? '')) ?: $from;
        if (!shop_hours_valid_date($from) || !shop_hours_valid_date($to) || $to < $from) continue;
        $type = (string) ($row['type'] ?? 'closure');
        if (!isset(SHOP_HOURS_CLOSURE_TYPES[$type])) $type = 'closure';
        $out[] = [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 120),
        ];
    }
    usort($out, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    return $out;
}

function shop_hours_save_closures(array $closures): void
{
    $clean = [];
    foreach (array_slice($closures, 0, 50) as $row) {
        if (!is_array($row)) continue;
        $from = trim((string) ($row['from'] ?? ''));
        $to = trim((string) ($row['to'] ?? '')) ?: $from;
        if (!shop_hours_valid_date($from) || !shop_hours_valid_date($to) || $to < $from) continue;
        $type = (string) ($row['type'] ?? 'closure');
        if (!isset(SHOP_HOURS_CLOSURE_TYPES[$type])) $type = 'closure';
        $clean[] = [
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'label' => mb_substr(trim((string) ($row['label'] ?? '')), 0, 120),
        ];
    }
    usort($clean, static fn ($a, $b) => strcmp($a['from'], $b['from']));
    shop_setting_set('opening_closures', json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function shop_hours_valid_date(string $date): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/** Fermeture couvrant une date (Y-m-d), ou null. */
function shop_hours_closure_on(string $date, ?array $closures = null): ?array
{
    foreach ($closures ?? shop_hours_closures() as $c) {
        if ($date >= $c['from'] && $date <= $c['to']) return $c;
    }
    return null;
}

/** Créneaux d'un jour (vide si fermeture ou jour fermé). */
function shop_hours_ranges_on(DateTimeImmutable $date, ?array $schedule = null, ?array $closures = null): array
{
    $schedule ??= shop_hours_schedule();
    if ($schedule === null) return [];
    $date = $date->setTime(0, 0);
    if (shop_hours_closure_on($date->format('Y-m-d'), $closures)) return [];
    $key = array_keys(SHOP_HOURS_DAYS)[(int) $date->format('N') - 1];
    return $schedule[$key]['ranges'] ?? [];
}

/** Prochaine ouverture après $from : ['date' => DateTimeImmutable, 'time' => 'HH:MM'] ou null. */
function shop_hours_next_open(DateTimeImmutable $from, ?array $closures = null): ?array
{
    $schedule = shop_hours_schedule();
    if ($schedule === null) return null;
    $closures ??= shop_hours_closures();
    $base = $from->setTime(0, 0);
    for ($offset = 0; $offset <= 60; $offset++) {
        $day = $base->modify('+' . $offset . ' days');
        $ymd = $day->format('Y-m-d');
        $closure = shop_hours_closure_on($ymd, $closures);
        if ($closure) {
            $offset = max($offset, $offset + (int) $day->diff(new DateTimeImmutable($closure['to'] . ' 00:00', shop_hours_timezone()))->days);
            continue;
        }
        $key = array_keys(SHOP_HOURS_DAYS)[(int) $day->format('N') - 1];
        foreach ($schedule[$key]['ranges'] ?? [] as $range) {
            $start = new DateTimeImmutable($ymd . ' ' . $range['from'], shop_hours_timezone());
            if ($start > $from) return ['date' => $day, 'time' => $range['from']];
        }
    }
    return null;
}

/** État calculé à l'instant donné. */
function shop_hours_status(?DateTimeImmutable $now = null): array
{
    $now ??= shop_hours_now();
    $schedule = shop_hours_schedule();
    $base = [
        'configured' => $schedule !== null,
        'open' => false,
        'label' => '',
        'ranges' => [],
        'closure' => null,
        'closure_active' => false,
        'next_open' => null,
        'reopen_label' => '',
        'date' => $now->format('Y-m-d'),
        'weekday' => SHOP_HOURS_DAYS[array_keys(SHOP_HOURS_DAYS)[(int) $now->format('N') - 1]],
    ];
    if ($schedule === null) return $base;

    $closures = shop_hours_closures();
    $ymd = $now->format('Y-m-d');
    $closure = shop_hours_closure_on($ymd, $closures);
    $ranges = $closure ? [] : shop_hours_ranges_on($now, $schedule, $closures);
    $base['closure'] = $closure;
    $base['closure_active'] = $closure !== null;
    $base['ranges'] = $ranges;

    $next = null;
    if ($closure) {
        $type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture';
        $base['label'] = ($closure['label'] !== '' ? $closure['label'] : $type) . ' — fermé jusqu’au ' . shop_hours_date_label(new DateTimeImmutable($closure['to'] . ' 00:00', shop_hours_timezone()), false) . ' inclus';
    } elseif (!$ranges) {
        $base['label'] = 'Fermé aujourd’hui';
    } else {
        $minutes = (int) $now->format('G') * 60 + (int) $now->format('i');
        foreach ($ranges as $range) {
            [$fh, $fm] = array_map('intval', explode(':', $range['from']));
            [$th, $tm] = array_map('intval', explode(':', $range['to']));
            $start = $fh * 60 + $fm;
            $end = $th * 60 + $tm;
            if ($minutes >= $start && $minutes < $end) {
                $base['open'] = true;
                $base['label'] = 'Ouvert jusqu’à ' . shop_hours_format_time($range['to']);
                break;
            }
        }
        if (!$base['open']) {
            $nextToday = null;
            foreach ($ranges as $range) {
                [$fh, $fm] = array_map('intval', explode(':', $range['from']));
                if ($fh * 60 + $fm > $minutes) { $nextToday = $range['from']; break; }
            }
            $base['label'] = $nextToday !== null
                ? 'Fermé · ouvre à ' . shop_hours_format_time($nextToday)
                : 'Fermé ce soir';
        }
    }

    if (!$base['open']) {
        $next = shop_hours_next_open($now, $closures);
        if ($next) {
            $base['next_open'] = $next;
            $base['reopen_label'] = 'Réouverture ' . shop_hours_date_label($next['date']) . ' à ' . shop_hours_format_time($next['time']);
        }
    }
    return $base;
}

/** Résumé lisible pour les visiteurs (vide tant que les horaires ne sont pas structurés). */
function shop_hours_summary_text(): string
{
    $schedule = shop_hours_schedule();
    if ($schedule === null) return '';

    $groups = [];
    foreach (array_keys(SHOP_HOURS_DAYS) as $day) {
        $dayData = $schedule[$day];
        $signature = $dayData['closed'] ? 'closed' : implode('|', array_map(static fn ($r) => $r['from'] . '-' . $r['to'], $dayData['ranges']));
        $last = count($groups) - 1;
        if ($last >= 0 && $groups[$last]['signature'] === $signature) {
            $groups[$last]['days'][] = $day;
        } else {
            $groups[] = ['signature' => $signature, 'days' => [$day], 'data' => $dayData];
        }
    }

    $all = count($groups) === 1 && count($groups[0]['days']) === 7;
    $parts = [];
    foreach ($groups as $group) {
        $days = $group['days'];
        if ($all) {
            $name = 'Tous les jours';
        } elseif (count($days) === 1) {
            $name = SHOP_HOURS_DAY_SHORT[$days[0]];
        } else {
            $name = SHOP_HOURS_DAY_SHORT[$days[0]] . '–' . SHOP_HOURS_DAY_SHORT[end($days)];
        }
        if ($group['data']['closed'] || !$group['data']['ranges']) {
            $parts[] = $name . ' : fermé';
        } else {
            $parts[] = $name . ' : ' . implode(' et ', array_map('shop_hours_format_range', $group['data']['ranges']));
        }
    }
    return implode(' · ', $parts);
}

/** Message à afficher en haut du site (ou null). */
function shop_hours_announcement(?DateTimeImmutable $now = null): ?array
{
    $now ??= shop_hours_now();
    $status = shop_hours_status($now);

    $custom = trim(shop_setting('opening_notice', ''));
    if ($custom !== '') return ['kind' => 'info', 'text' => $custom];

    if (!$status['configured']) return null;

    if ($status['closure_active'] && $status['closure']) {
        $text = $status['label'];
        if ($status['reopen_label'] !== '') $text .= ' · ' . $status['reopen_label'];
        return ['kind' => 'warning', 'text' => $text];
    }

    $limit = $now->modify('+7 days')->format('Y-m-d');
    foreach (shop_hours_closures() as $closure) {
        if ($closure['from'] > $now->format('Y-m-d') && $closure['from'] <= $limit) {
            $type = SHOP_HOURS_CLOSURE_TYPES[$closure['type']] ?? 'Fermeture';
            $label = $closure['label'] !== '' ? $closure['label'] : $type;
            $range = $closure['from'] === $closure['to']
                ? shop_hours_date_label(new DateTimeImmutable($closure['from'] . ' 00:00', shop_hours_timezone()), false)
                : 'du ' . shop_hours_date_label(new DateTimeImmutable($closure['from'] . ' 00:00', shop_hours_timezone()), false) . ' au ' . shop_hours_date_label(new DateTimeImmutable($closure['to'] . ' 00:00', shop_hours_timezone()), false);
            return ['kind' => 'info', 'text' => 'À venir : ' . $label . ' ' . $range . '.'];
        }
    }

    if (!$status['open'] && !$status['ranges']) {
        $text = $status['label'];
        if ($status['reopen_label'] !== '') $text .= ' · ' . $status['reopen_label'];
        return ['kind' => 'info', 'text' => $text];
    }
    return null;
}

/** Bandeau d'information public ; '' quand il n'y a rien à annoncer. */
function shop_hours_notice_html(): string
{
    $notice = shop_hours_announcement();
    if (!$notice) return '';
    $key = substr(md5($notice['text']), 0, 12);
    $kind = $notice['kind'] === 'warning' ? 'warning' : 'info';
    return '<div class="shop-notice shop-notice-' . $kind . '" data-notice-key="' . h($key) . '" role="status">'
        . '<div class="wrap"><p>' . h($notice['text']) . '</p>'
        . '<button type="button" class="shop-notice-close" aria-label="Masquer cette annonce">×</button></div></div>'
        . '<script>(function(){var el=document.querySelector(\'.shop-notice[data-notice-key="' . $key . '"]\');if(!el)return;'
        . 'try{if(sessionStorage.getItem(\'shopNoticeDismissed\')===\'' . $key . '\'){el.remove();return;}}catch(e){}'
        . 'el.querySelector(\'.shop-notice-close\').addEventListener(\'click\',function(){el.remove();'
        . 'try{sessionStorage.setItem(\'shopNoticeDismissed\',\'' . $key . '\');}catch(e){}});})();</script>';
}

/** Adresse publique : celle de la définition globale (identité du vendeur), sinon celle du contenu. */
function shop_public_address(?array $content = null): string
{
    $legal = trim(shop_setting('legal_address', ''));
    if ($legal !== '') return $legal;
    if ($content === null) {
        try {
            $content = db()->query('SELECT contact_address FROM content WHERE id = 1')->fetch() ?: [];
        } catch (Throwable $e) {
            $content = [];
        }
    }
    return trim((string) ($content['contact_address'] ?? ''));
}

/** Note libre de pied de page (facultative). */
function shop_footer_note(): string
{
    return trim(shop_setting('footer_note', ''));
}
