<?php

// File d'attente des exposants (vide-greniers, marchés aux puces) et alertes de voisinage.
//
// 1. File d'attente : un exposant s'inscrit (nom, e-mail, téléphone, nombre de places souhaitées). Dès qu'une place est libérée
//    (désistement, demande refusée ou expirée, absence constatée le jour même), les premiers inscrits reçoivent un e-mail et
//    réservent la place par le formulaire habituel, premier arrivé premier servi.
// 2. Places voisines : quand une place reste vide à côté d'un stand attribué, son exposant reçoit une offre à prix réduit pour
//    l'agrandir (pourcentage réglé par l'organisateur). L'offre se réclame en un clic : la place voisine est retenue au nom de
//    l'exposant, avec sa fiche et ses références de registre déjà connues, puis l'organisateur valide comme une demande normale.
//
// Données dans data/galeries/<id>.waitlist.json (jamais déployé) : réglages, inscriptions, offres et journal.
// Les places elles-mêmes restent dans <id>.slots.json (includes/gallery-booking.php) : aucune structure existante n'est modifiée.

require_once __DIR__ . '/gallery-booking.php';

const GWL_MAX_PER_IP_HOUR = 8;
const GWL_MAX_ENTRIES = 500;
const GWL_MAX_OFFERS = 500;
const GWL_OFFER_DAYS = 7;
const GWL_KEEP_DAYS = 180;

const GWL_STATUS_LABELS = [
    'waiting' => 'En attente', 'notified' => 'Prévenu', 'removed' => 'Retiré', 'joined' => 'Place obtenue',
];
const GWL_OFFER_LABELS = [
    'open' => 'Offre ouverte', 'claimed' => 'Acceptée', 'expired' => 'Expirée', 'gone' => 'Place prise ailleurs',
];

function gwl_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.waitlist.json';
}

function gwl_read(string $slug): array
{
    $d = gallery_slug_valid($slug) ? json_decode((string) @file_get_contents(gwl_file($slug)), true) : null;
    $d = is_array($d) ? $d : [];
    return [
        'settings' => is_array($d['settings'] ?? null) ? $d['settings'] : [],
        'entries' => is_array($d['entries'] ?? null) ? $d['entries'] : [],
        'offers' => is_array($d['offers'] ?? null) ? $d['offers'] : [],
        'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : [],
        'events' => is_array($d['events'] ?? null) ? $d['events'] : [],
    ];
}

/** Lit, modifie et réécrit le fichier de la file d'attente sous verrou. $fn reçoit les données par référence. */
function gwl_update(string $slug, callable $fn): mixed
{
    if (!gallery_slug_valid($slug)) return null;
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Dossier des galeries inaccessible.');
    $h = fopen(gwl_file($slug), 'c+');
    if (!$h) throw new RuntimeException('Fichier de la file d\'attente inaccessible.');
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $d = json_decode((string) $raw, true);
    $d = is_array($d) ? $d : [];
    $d = [
        'settings' => is_array($d['settings'] ?? null) ? $d['settings'] : [],
        'entries' => is_array($d['entries'] ?? null) ? $d['entries'] : [],
        'offers' => is_array($d['offers'] ?? null) ? $d['offers'] : [],
        'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : [],
        'events' => is_array($d['events'] ?? null) ? $d['events'] : [],
    ];
    try {
        $r = $fn($d);
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($h);
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
    return $r;
}

function gwl_log(array &$d, string $action, array $details = []): void
{
    $d['events'][] = ['at' => time(), 'action' => $action] + $details;
    if (count($d['events']) > 200) $d['events'] = array_slice($d['events'], -200);
}

/** Réglages du module, valeurs sûres. */
function gwl_settings(array $gallery): array
{
    $s = gwl_read($gallery['slug'])['settings'];
    return [
        'notify_limit' => max(1, min(50, (int) ($s['notify_limit'] ?? 5))),
        'cooldown_hours' => max(1, min(168, (int) ($s['cooldown_hours'] ?? 6))),
        'max_notifications' => max(1, min(10, (int) ($s['max_notifications'] ?? 5))),
        'discount_pct' => max(0, min(90, (int) ($s['discount_pct'] ?? 30))),
        'neighbor_enabled' => !array_key_exists('neighbor_enabled', $s) ? true : !empty($s['neighbor_enabled']),
        'neighbor_radius_m' => max(2, min(200, (float) ($s['neighbor_radius_m'] ?? 15))),
        'public_intro' => mb_substr(trim((string) ($s['public_intro'] ?? '')), 0, 300)
            ?: "Toutes les places ne sont pas disponibles ? Inscrivez-vous : vous serez prévenu par e-mail dès qu'une place se libère.",
    ];
}

/** Le module est-il utilisable ? (activé pour la galerie, exposants et réservations en ligne ouverts) */
function gwl_available(array $gallery): bool
{
    if (!function_exists('gm_enabled') || !gm_enabled($gallery, 'attente')) return false;
    if (!function_exists('gt_feature') || !gt_feature($gallery, 'exposants')) return false;
    return !empty(gb_booking($gallery)['enabled']);
}

/** Clé de l'événement courant : une file d'attente et des offres par événement (la date et le titre changent d'une édition à l'autre). */
function gwl_event_key(array $gallery): string
{
    $e = gallery_event($gallery);
    if (!$e) return 'map';
    return substr(hash('sha256', (string) ($e['date'] ?? '') . '|' . (string) ($e['title'] ?? '')), 0, 12);
}

/** Toutes les places de la source de réservation active (événement numéroté ou plan), par identifiant. */
function gwl_all_slots(array $gallery): array
{
    $b = gb_booking($gallery);
    $out = [];
    if (($b['source'] ?? '') === 'event' && !empty($b['event'])) {
        foreach (ge_places($b['event']) as $p) $out[$p['id']] = $p;
        return $out;
    }
    foreach ($gallery['map']['items'] ?? [] as $it) {
        if (($it['role'] ?? '') === 'slot') $out[(string) $it['id']] = $it;
    }
    return $out;
}

/** Places libres (ni attribuées, ni demandées, ni tenues par un commerce), avec leur nom et leur tarif. */
function gwl_free_slots(array $gallery, ?array $all = null, ?array $states = null): array
{
    if (!gwl_available($gallery)) return [];
    $all = $all ?? gwl_all_slots($gallery);
    $states = $states ?? gb_states($gallery);
    $out = [];
    foreach ($all as $id => $slot) {
        $id = (string) $id;
        if (($slot['shop'] ?? '') !== '' || isset($states[$id])) continue;
        $out[$id] = [
            'id' => $id,
            'name' => gb_slot_name($gallery, $slot),
            'price_cents' => gb_slot_price($gallery, $slot),
            'zone' => (string) ($slot['zone'] ?? ''),
        ];
    }
    return $out;
}

function gwl_slot_name_by_id(array $gallery, string $id, ?array $all = null): string
{
    $all = $all ?? gwl_all_slots($gallery);
    return isset($all[$id]) ? gb_slot_name($gallery, $all[$id]) : 'Place ' . $id;
}

function gwl_distance_m(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $kLat = 111320.0;
    $kLng = 111320.0 * cos(deg2rad($lat1));
    return hypot(($lng2 - $lng1) * $kLng, ($lat2 - $lat1) * $kLat);
}

/**
 * Places voisines d'une place, sans filtrer sur leur disponibilité.
 * Événement : les places numérotées juste avant et juste après. Plan : les places de la même zone dont le centre est à moins de
 * « neighbor_radius_m » mètres (les deux plus proches).
 */
function gwl_adjacent(array $gallery, string $slotId, ?array $all = null, ?array $settings = null): array
{
    $all = $all ?? gwl_all_slots($gallery);
    $src = $all[$slotId] ?? null;
    if (!$src) return [];
    $found = [];
    if (($src['kind'] ?? '') === 'event') {
        $n = (int) ($src['num'] ?? 0);
        foreach ([$n - 1, $n + 1] as $k) {
            $id = 'ev' . $k;
            if ($k >= 1 && isset($all[$id])) $found[$id] = 0.0;
        }
        return array_keys($found);
    }
    $settings = $settings ?? gwl_settings($gallery);
    $radius = (float) $settings['neighbor_radius_m'];
    if (count($src['pts'] ?? []) < 3) return [];
    [$srcLat, $srcLng] = gallery_map_centroid($src['pts']);
    foreach ($all as $id => $it) {
        $id = (string) $id;
        if ($id === $slotId || count($it['pts'] ?? []) < 3) continue;
        if (($src['zone'] ?? '') !== '' && ($it['zone'] ?? '') !== '' && $src['zone'] !== $it['zone']) continue;
        [$lat, $lng] = gallery_map_centroid($it['pts']);
        $d = gwl_distance_m($srcLat, $srcLng, $lat, $lng);
        if ($d <= $radius) $found[$id] = $d;
    }
    asort($found);
    return array_keys(array_slice($found, 0, 2, true));
}

/** Places voisines encore libres (voisines de gwl_adjacent, restreintes aux places disponibles). */
function gwl_neighbors(array $gallery, string $slotId, ?array $freeIds = null, ?array $all = null, ?array $settings = null): array
{
    $freeIds = $freeIds ?? array_keys(gwl_free_slots($gallery, $all));
    $free = array_flip(array_map('strval', $freeIds));
    return array_values(array_filter(gwl_adjacent($gallery, $slotId, $all, $settings), static fn ($id) => isset($free[$id])));
}

function gwl_offer_price(?int $baseCents, int $discountPct): ?int
{
    if ($baseCents === null) return null;
    return max(0, (int) round($baseCents * (100 - max(0, min(90, $discountPct))) / 100));
}

function gwl_eur(?int $cents): string
{
    return $cents === null ? 'tarif à confirmer' : number_format($cents / 100, 2, ',', ' ') . ' €';
}

function gwl_public_url(string $slug, array $query = []): string
{
    return ga_public_url('/galerie/attente.php?' . http_build_query(['g' => $slug] + $query));
}

/** Inscription à la file d'attente. -> [inscription, erreur]. */
function gwl_join(array $gallery, array $in, string $ip): array
{
    if (!gwl_available($gallery)) return [null, "La file d'attente n'est pas ouverte pour cette galerie."];
    if (function_exists('go_is_live') && !go_is_live($gallery)) return [null, 'Les inscriptions sont fermées pour le moment.'];
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') $name = mb_substr((string) strstr($email, '@', true) ?: 'Exposant', 0, 80);
    $kind = ct_kind((string) ($in['kind'] ?? 'particulier'));
    if (empty($in['consent'])) return [null, "Cochez la case d'accord pour rejoindre la file d'attente."];
    $places = max(1, min(4, (int) ($in['places'] ?? 1)));
    $zone = mb_substr(trim((string) ($in['zone'] ?? '')), 0, 60);
    $phone = mb_substr(preg_replace('/[^0-9+ ().-]/', '', (string) ($in['phone'] ?? '')), 0, 30);
    $message = mb_substr(trim((string) ($in['message'] ?? '')), 0, 400);
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $ip . '|gwl|' . $gallery['slug']);
    $event = gwl_event_key($gallery);
    $entry = gwl_update($gallery['slug'], static function (array &$d) use ($token, $email, $name, $phone, $kind, $places, $zone, $message, $hash, $event) {
        $now = time();
        foreach ($d['hits'] as $h => $times) {
            $d['hits'][$h] = array_values(array_filter((array) $times, static fn ($t) => (int) $t > $now - 3600));
            if (!$d['hits'][$h]) unset($d['hits'][$h]);
        }
        if (count($d['hits'][$hash] ?? []) >= GWL_MAX_PER_IP_HOUR) return 'rate';
        foreach ($d['entries'] as $e) {
            if (($e['event'] ?? '') === $event && ($e['email'] ?? '') === $email
                && in_array($e['status'] ?? '', ['waiting', 'notified'], true)) return 'dup';
        }
        $active = 0;
        foreach ($d['entries'] as $e) if (($e['event'] ?? '') === $event && in_array($e['status'] ?? '', ['waiting', 'notified'], true)) $active++;
        if ($active >= GWL_MAX_ENTRIES) return 'full';
        $d['hits'][$hash][] = $now;
        $d['entries'][$token] = [
            'event' => $event, 'name' => $name, 'email' => $email, 'phone' => $phone, 'kind' => $kind,
            'places' => $places, 'zone' => $zone, 'message' => $message, 'created' => $now, 'consent_at' => $now,
            'status' => 'waiting', 'notified_at' => 0, 'last_notified' => 0, 'notify_count' => 0,
        ];
        gwl_log($d, 'join', ['email' => $email, 'places' => $places]);
        return $d['entries'][$token];
    });
    if ($entry === 'rate') return [null, 'Trop d\'inscriptions depuis votre connexion : réessayez dans une heure.'];
    if ($entry === 'dup') return [null, 'Cette adresse est déjà inscrite à la file d\'attente.'];
    if ($entry === 'full') return [null, "La file d'attente est complète : contactez directement l'organisateur."];
    if (!is_array($entry)) return [null, "L'inscription n'a pas pu être enregistrée. Réessayez."];
    $manage = gwl_public_url($gallery['slug'], ['t' => $token]);
    $free = gwl_free_slots($gallery);
    $freeText = $free ? "Places libres en ce moment : " . implode(', ', array_map(static fn ($s) => $s['name'], array_slice($free, 0, 8))) . ".\n\n" : '';
    ga_mail($email, 'File d\'attente — ' . $gallery['name'],
        "Bonjour {$name},\n\nVotre inscription à la file d'attente de « {$gallery['name']} » est enregistrée.\n"
        . "Vous serez prévenu par e-mail dès qu'une place se libère (désistement ou absence le jour même).\n\n"
        . $freeText
        . "L'inscription ne réserve pas de place : la place revient au premier exposant qui la demande sur la page de la galerie.\n\n"
        . "Suivre ou annuler votre inscription : $manage\n\n{$gallery['name']}\n");
    return [$entry + ['token' => $token], null];
}

function gwl_leave(string $slug, string $token): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    return (bool) gwl_update($slug, static function (array &$d) use ($token) {
        if (!isset($d['entries'][$token])) return false;
        if (!in_array($d['entries'][$token]['status'] ?? '', ['waiting', 'notified'], true)) return false;
        $d['entries'][$token]['status'] = 'removed';
        $d['entries'][$token]['removed_at'] = time();
        gwl_log($d, 'leave', ['email' => (string) ($d['entries'][$token]['email'] ?? '')]);
        return true;
    });
}

function gwl_entry_by_token(array $gallery, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $e = gwl_read($gallery['slug'])['entries'][$token] ?? null;
    if (!is_array($e) || ($e['event'] ?? '') !== gwl_event_key($gallery)) return null;
    return $e + ['token' => $token];
}

/** Inscriptions actives de l'événement courant (les plus anciennes d'abord). */
function gwl_waiting(array $gallery): array
{
    $event = gwl_event_key($gallery);
    $out = [];
    foreach (gwl_read($gallery['slug'])['entries'] as $token => $e) {
        if (($e['event'] ?? '') !== $event || !in_array($e['status'] ?? '', ['waiting', 'notified'], true)) continue;
        $out[(string) $token] = $e + ['token' => (string) $token];
    }
    uasort($out, static fn ($a, $b) => ((int) ($a['created'] ?? 0)) <=> ((int) ($b['created'] ?? 0)));
    return $out;
}

/**
 * Prévient par e-mail les premiers inscrits que des places sont libres.
 * $slotIds vide = toutes les places libres. -> ['notified' => n, 'slots' => n].
 */
function gwl_notify_waitlist(array $gallery, array $slotIds = [], string $actor = ''): array
{
    if (!gwl_available($gallery)) return ['notified' => 0, 'slots' => 0];
    $all = gwl_all_slots($gallery);
    $free = gwl_free_slots($gallery, $all);
    $slotIds = $slotIds ? array_values(array_intersect(array_map('strval', $slotIds), array_keys($free))) : array_keys($free);
    if (!$slotIds) return ['notified' => 0, 'slots' => 0];
    $picked = array_intersect_key($free, array_flip($slotIds));
    $s = gwl_settings($gallery);
    $event = gwl_event_key($gallery);
    $cooldown = $s['cooldown_hours'] * 3600;
    $now = time();
    $chosen = gwl_update($gallery['slug'], static function (array &$d) use ($event, $s, $cooldown, $now, $slotIds, $actor) {
        $entries = $d['entries'];
        uasort($entries, static fn ($a, $b) => ((int) ($a['created'] ?? 0)) <=> ((int) ($b['created'] ?? 0)));
        $out = [];
        foreach ($entries as $token => $e) {
            if (($e['event'] ?? '') !== $event || !in_array($e['status'] ?? '', ['waiting', 'notified'], true)) continue;
            if ((int) ($e['notify_count'] ?? 0) >= $s['max_notifications']) continue;
            if ((int) ($e['last_notified'] ?? 0) + $cooldown > $now) continue;
            $out[(string) $token] = $e;
            if (count($out) >= $s['notify_limit']) break;
        }
        foreach ($out as $token => $e) {
            $d['entries'][$token]['status'] = 'notified';
            $d['entries'][$token]['notified_at'] = $now;
            $d['entries'][$token]['last_notified'] = $now;
            $d['entries'][$token]['notify_count'] = (int) ($e['notify_count'] ?? 0) + 1;
        }
        if ($out) gwl_log($d, 'notify', ['count' => count($out), 'slots' => $slotIds, 'by' => $actor]);
        return $out;
    });
    if (!$chosen) return ['notified' => 0, 'slots' => count($slotIds)];
    $lines = [];
    foreach ($picked as $slot) $lines[] = '- ' . $slot['name'] . ' (' . gwl_eur($slot['price_cents']) . ')';
    $list = implode("\n", $lines);
    $ev = gallery_event($gallery);
    $when = $ev ? trim(ge_date_text($ev) . ' · ' . ge_place_text($ev), ' ·') : '';
    foreach ($chosen as $token => $e) {
        $manage = gwl_public_url($gallery['slug'], ['t' => $token]);
        ga_mail((string) $e['email'], 'Une place s\'est libérée — ' . $gallery['name'],
            "Bonjour {$e['name']},\n\nUne place s'est libérée pour « {$gallery['name']} »" . ($when !== '' ? " ($when)" : '') . " :\n\n$list\n\n"
            . "Elle revient au premier exposant qui la demande :\n" . gwl_public_url($gallery['slug']) . "\n\n"
            . "Suivre ou annuler votre inscription : $manage\n\n{$gallery['name']}\n");
    }
    return ['notified' => count($chosen), 'slots' => count($slotIds)];
}

/** Offres de voisinage actives (événement courant), par jeton. */
function gwl_offers(array $gallery, ?string $status = null): array
{
    $event = gwl_event_key($gallery);
    $out = [];
    foreach (gwl_read($gallery['slug'])['offers'] as $token => $o) {
        if (($o['event'] ?? '') !== $event) continue;
        if ($status !== null && ($o['status'] ?? '') !== $status) continue;
        $out[(string) $token] = $o + ['token' => (string) $token];
    }
    uasort($out, static fn ($a, $b) => ((int) ($b['created'] ?? 0)) <=> ((int) ($a['created'] ?? 0)));
    return $out;
}

function gwl_offer_by_token(array $gallery, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    $o = gwl_read($gallery['slug'])['offers'][$token] ?? null;
    if (!is_array($o) || ($o['event'] ?? '') !== gwl_event_key($gallery)) return null;
    return $o + ['token' => $token];
}

/** Étiquette une offre expirée ou dont la place a été prise ailleurs. */
function gwl_expire(array $gallery): void
{
    if (!gallery_slug_valid($gallery['slug'])) return;
    $free = array_flip(array_keys(gwl_free_slots($gallery)));
    $event = gwl_event_key($gallery);
    $now = time();
    gwl_update($gallery['slug'], static function (array &$d) use ($free, $event, $now) {
        $changed = false;
        foreach ($d['offers'] as &$o) {
            if (($o['status'] ?? '') !== 'open') continue;
            if (($o['event'] ?? '') !== $event || (int) ($o['expires'] ?? 0) < $now) { $o['status'] = 'expired'; $changed = true; }
            elseif (!isset($free[(string) ($o['slot'] ?? '')])) { $o['status'] = 'gone'; $changed = true; }
        }
        unset($o);
        if ($changed) gwl_log($d, 'offers_closed', []);
        return $changed;
    });
}

/**
 * Cherche les places vides à côté des stands attribués et envoie une offre à prix réduit.
 * $onlySlotId : ne traiter que les voisins d'une place précise (celle qui vient d'être libérée).
 * -> ['offers' => n].
 */
function gwl_scan_neighbors(array $gallery, ?string $onlySlotId = null, string $actor = ''): array
{
    if (!gwl_available($gallery)) return ['offers' => 0];
    $s = gwl_settings($gallery);
    if (!$s['neighbor_enabled']) return ['offers' => 0];
    gwl_expire($gallery);
    $all = gwl_all_slots($gallery);
    $states = gb_states($gallery);
    $free = gwl_free_slots($gallery, $all, $states);
    if (!$free) return ['offers' => 0];
    $taken = [];
    foreach (gb_rows($gallery) as $r) if (($r['status'] ?? '') === 'taken') $taken[(string) $r['slot_id']] = $r;
    if (!$taken) return ['offers' => 0];
    $candidates = [];
    $slotsToScan = $onlySlotId !== null ? array_intersect_key($free, [$onlySlotId => 1]) : $free;
    foreach (array_keys($slotsToScan) as $slotId) {
        foreach (gwl_adjacent($gallery, (string) $slotId, $all, $s) as $nbId) {
            if (!isset($taken[$nbId])) continue;
            $holder = $taken[$nbId];
            $email = mb_strtolower((string) ($holder['email'] ?? ''));
            if ($email === '') continue;
            $candidates[$email . '|' . $slotId] = ['slot' => (string) $slotId, 'from' => (string) $nbId, 'holder' => $holder, 'email' => $email];
        }
    }
    if (!$candidates) return ['offers' => 0];
    $event = gwl_event_key($gallery);
    $now = time();
    $priced = [];
    foreach ($candidates as $c) {
        $base = gb_slot_price($gallery, $all[$c['slot']] ?? [], ($c['holder']['kind'] ?? '') === 'entreprise');
        $priced[$c['email'] . '|' . $c['slot']] = ['base' => $base, 'price' => gwl_offer_price($base, $s['discount_pct'])];
    }
    $created = gwl_update($gallery['slug'], static function (array &$d) use ($candidates, $priced, $s, $event, $now, $actor) {
        if (count($d['offers']) >= GWL_MAX_OFFERS) return [];
        $out = [];
        $perHolder = [];
        $existing = [];
        foreach ($d['offers'] as $o) {
            if (($o['event'] ?? '') !== $event) continue;
            $existing[$o['email'] . '|' . $o['slot']] = $o;
        }
        foreach ($candidates as $c) {
            $key = $c['email'] . '|' . $c['slot'];
            $o = $existing[$key] ?? null;
            if ($o && ($o['status'] ?? '') === 'open') continue;
            if ($o && (int) ($o['created'] ?? 0) > $now - 86400) continue;
            if (($perHolder[$c['email']] ?? 0) >= 2) continue;
            $perHolder[$c['email']] = ($perHolder[$c['email']] ?? 0) + 1;
            $token = bin2hex(random_bytes(16));
            $d['offers'][$token] = [
                'event' => $event, 'slot' => $c['slot'], 'from_slot' => $c['from'], 'email' => $c['email'],
                'name' => (string) ($c['holder']['name'] ?? ''), 'kind' => (string) ($c['holder']['kind'] ?? 'particulier'),
                'base_cents' => $priced[$key]['base'], 'price_cents' => $priced[$key]['price'], 'discount_pct' => (int) $s['discount_pct'],
                'created' => $now, 'expires' => $now + GWL_OFFER_DAYS * 86400, 'status' => 'open', 'claimed_at' => 0, 'claim_token' => '',
            ];
            $out[$token] = $c + $priced[$key];
        }
        if ($out) gwl_log($d, 'neighbor_offers', ['count' => count($out), 'by' => $actor]);
        return $out;
    });
    if (!$created) return ['offers' => 0];
    foreach ($created as $token => $c) {
        $discount = (int) $s['discount_pct'];
        $fromName = gwl_slot_name_by_id($gallery, $c['from'], $all);
        $freeName = gwl_slot_name_by_id($gallery, $c['slot'], $all);
        $claim = gwl_public_url($gallery['slug'], ['o' => $token]);
        ga_mail($c['email'], 'Une place à côté de votre stand est libre — ' . $gallery['name'],
            "Bonjour " . ($c['holder']['name'] ?? '') . ",\n\nLa place voisine de votre stand ($fromName) est libre : $freeName.\n"
            . ($c['price'] !== null ? "Elle vous est proposée à " . gwl_eur($c['price']) . ($discount > 0 ? " (soit -$discount % sur le tarif de " . gwl_eur($c['base']) . ")" : '') . ".\n" : "Elle vous est proposée en priorité ; l'organisateur vous confirmera le tarif.\n")
            . "Elle s'ajoute à votre emplacement actuel : vous gagnez de la place pour votre stand.\n\n"
            . "Accepter cette place : $claim\n\n"
            . "L'organisateur valide ensuite la demande, comme pour toute réservation.\n\n{$gallery['name']}\n");
    }
    return ['offers' => count($created)];
}

/** L'exposant accepte une place voisine : elle est retenue à son nom, à prix réduit, avec sa fiche déjà connue. */
function gwl_claim_offer(array $gallery, string $token): array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return [null, 'Lien invalide.'];
    if (!gwl_available($gallery)) return [null, "La réservation en ligne n'est pas ouverte."];
    if (function_exists('go_is_live') && !go_is_live($gallery)) return [null, 'Les réservations sont fermées pour le moment.'];
    $offer = gwl_offer_by_token($gallery, $token);
    if (!$offer) return [null, 'Cette offre est introuvable.'];
    if (($offer['status'] ?? '') !== 'open') return [null, 'Cette offre n\'est plus disponible.'];
    if ((int) ($offer['expires'] ?? 0) < time()) {
        gwl_expire($gallery);
        return [null, 'Cette offre a expiré.'];
    }
    $slotId = (string) $offer['slot'];
    $fromId = (string) $offer['from_slot'];
    $newToken = bin2hex(random_bytes(16));
    $all = gwl_all_slots($gallery);
    $fromName = gwl_slot_name_by_id($gallery, $fromId, $all);
    $slotName = gwl_slot_name_by_id($gallery, $slotId, $all);
    $created = gb_update($gallery['slug'], static function (array &$d) use ($gallery, $slotId, $fromId, $token, $newToken, $offer, $fromName, $slotName) {
        $hold = gb_hold_seconds($gallery);
        if (isset($d['slots'][$slotId]) && gb_active($d['slots'][$slotId], $hold)) return 'busy';
        $src = $d['slots'][$fromId] ?? null;
        if (!is_array($src) || ($src['status'] ?? '') !== 'taken') return 'source';
        if (mb_strtolower((string) ($src['email'] ?? '')) !== mb_strtolower((string) $offer['email'])) return 'source';
        $d['slots'][$slotId] = [
            'status' => 'pending', 'name' => (string) ($src['name'] ?? $offer['name']), 'email' => (string) $src['email'],
            'phone' => (string) ($src['phone'] ?? ''), 'kind' => ct_kind((string) ($src['kind'] ?? 'particulier')),
            'client' => is_array($src['client'] ?? null) ? $src['client'] : null,
            'message' => 'Agrandissement : place voisine de ' . $fromName . ' (offre à -' . (int) ($offer['discount_pct'] ?? 0) . ' %).',
            'price_cents' => $offer['price_cents'] ?? null, 'token' => $newToken, 'requested' => time(), 'decided' => 0,
            'consent_at' => time(), 'cni' => null, 'extension_of' => $fromId, 'waitlist_offer' => $token,
        ];
        return $d['slots'][$slotId];
    });
    if ($created === 'busy') return [null, 'Cette place vient d\'être prise : demandez-en une autre à l\'organisateur.'];
    if ($created === 'source') return [null, 'Votre emplacement d\'origine n\'est plus attribué : cette offre est annulée.'];
    if (!is_array($created)) return [null, 'La place n\'a pas pu être retenue. Réessayez.'];
    gwl_update($gallery['slug'], static function (array &$d) use ($token, $newToken) {
        if (isset($d['offers'][$token])) {
            $d['offers'][$token]['status'] = 'claimed';
            $d['offers'][$token]['claimed_at'] = time();
            $d['offers'][$token]['claim_token'] = $newToken;
            gwl_log($d, 'offer_claimed', ['slot' => (string) ($d['offers'][$token]['slot'] ?? ''), 'email' => (string) ($d['offers'][$token]['email'] ?? '')]);
        }
        return true;
    });
    gb_audit($gallery['slug'], 'waitlist_claim', ['slot' => $slotId, 'from' => $fromId, 'email' => (string) $offer['email'], 'price' => $offer['price_cents'] ?? null]);
    $manage = ga_public_url('/galerie/exposant.php?g=' . $gallery['slug'] . '&t=' . $newToken);
    ga_mail((string) $offer['email'], 'Place voisine retenue — ' . $gallery['name'],
        "Bonjour " . ($created['name'] ?? '') . ",\n\n$slotName vient de vous être réservée à " . gwl_eur($offer['price_cents'] ?? null)
        . " (place voisine de $fromName).\nElle s'ajoute à votre emplacement actuel. L'organisateur valide la demande et vous répond par e-mail.\n"
        . "Pour annuler cette demande : $manage\n\n{$gallery['name']}\n");
    foreach ($gallery['admins'] as $a) {
        ga_mail((string) $a['email'], 'Agrandissement demandé — ' . $gallery['name'],
            ($created['name'] ?? 'Un exposant') . " (" . $offer['email'] . ") accepte la place voisine $slotName, à côté de $fromName, à "
            . gwl_eur($offer['price_cents'] ?? null) . ".\nÀ valider dans le portail, page « Exposants ».\n");
    }
    return [gb_row_normalize($slotId, $created), null];
}

/** Déclenche l'information de la file d'attente et les offres aux voisins après une libération de place. */
function gwl_on_release(array $gallery, string $slotId, string $actor = ''): array
{
    if (!gwl_available($gallery)) return ['notified' => 0, 'offers' => 0];
    gwl_expire($gallery);
    $n = gwl_notify_waitlist($gallery, [$slotId], $actor);
    $o = gwl_scan_neighbors($gallery, $slotId, $actor);
    return ['notified' => $n['notified'], 'offers' => $o['offers']];
}

/** Absence ou désistement constaté par l'organisateur : la place est libérée puis la file et les voisins sont prévenus. */
function gwl_no_show(array $gallery, string $slotId, string $actor = ''): ?string
{
    if (!gwl_available($gallery)) return "La file d'attente n'est pas active pour cette galerie.";
    $row = gb_row($gallery, $slotId);
    if (!$row || ($row['status'] ?? '') !== 'taken') return "Cet exposant n'est pas attribué à cette place.";
    $err = gb_decide($gallery, $slotId, 'release', $actor);
    if ($err) return $err;
    gb_audit($gallery['slug'], 'no_show', ['slot' => $slotId, 'by' => $actor]);
    gwl_on_release($gallery, $slotId, $actor);
    return null;
}

/** Prévenir la file et chercher toutes les places vides à côté (bouton de l'organisateur). */
function gwl_sweep(array $gallery, string $actor = ''): array
{
    if (!gwl_available($gallery)) return ['notified' => 0, 'offers' => 0, 'free' => 0];
    gwl_expire($gallery);
    $free = gwl_free_slots($gallery);
    $n = $free ? gwl_notify_waitlist($gallery, array_keys($free), $actor) : ['notified' => 0];
    $o = gwl_scan_neighbors($gallery, null, $actor);
    return ['notified' => $n['notified'], 'offers' => $o['offers'], 'free' => count($free)];
}

function gwl_save_settings(array $gallery, array $in, string $actor = ''): ?string
{
    $settings = [
        'notify_limit' => max(1, min(50, (int) ($in['notify_limit'] ?? 5))),
        'cooldown_hours' => max(1, min(168, (int) ($in['cooldown_hours'] ?? 6))),
        'max_notifications' => max(1, min(10, (int) ($in['max_notifications'] ?? 5))),
        'discount_pct' => max(0, min(90, (int) ($in['discount_pct'] ?? 30))),
        'neighbor_enabled' => !empty($in['neighbor_enabled']),
        'neighbor_radius_m' => max(2, min(200, (float) str_replace(',', '.', (string) ($in['neighbor_radius_m'] ?? 15)))),
        'public_intro' => mb_substr(trim((string) ($in['public_intro'] ?? '')), 0, 300),
    ];
    gwl_update($gallery['slug'], static function (array &$d) use ($settings, $actor) {
        $d['settings'] = $settings;
        gwl_log($d, 'settings', ['by' => $actor]);
        return true;
    });
    return null;
}

/** Purge les inscriptions retirées et les offres closes de plus de 180 jours (appelée à l'ouverture de l'administration). */
function gwl_purge(string $slug): void
{
    if (!gallery_slug_valid($slug) || !is_file(gwl_file($slug))) return;
    $limit = time() - GWL_KEEP_DAYS * 86400;
    gwl_update($slug, static function (array &$d) use ($limit) {
        foreach ($d['entries'] as $t => $e) {
            if (in_array($e['status'] ?? '', ['removed', 'joined'], true) && (int) ($e['created'] ?? 0) < $limit) unset($d['entries'][$t]);
        }
        foreach ($d['offers'] as $t => $o) {
            if (!in_array($o['status'] ?? '', ['open'], true) && (int) ($o['created'] ?? 0) < $limit) unset($d['offers'][$t]);
        }
        return true;
    });
}
