<?php

// Demandes d'emplacement des exposants (vides-greniers, marchés aux puces) : un exposant choisit un emplacement libre sur le plan public de la
// galerie, avec son tarif (zone de l'emplacement) ; l'administrateur de la galerie accepte ou refuse. Les demandes vivent dans
// data/galeries/<id>.slots.json (jamais déployé) : ['slots' => [<id de l'emplacement> => demande], 'hits' => [<empreinte IP> => [horodatages]]].
//
// Chaque exposant est un client de trois types possibles (particulier, association, entreprise) avec les mentions du registre des vendeurs
// (voir includes/client-types.php). La copie de la pièce d'identité est facultative (la CNIL juge sa conservation excessive) : elle est
// stockée hors du dossier public (data/galeries/cni), avec accord explicite, accès réservé à l'organisateur et suppression programmée.
// Le registre lui-même (nom, prénoms, fonction, domicile, références de la pièce) est conservé cinq ans après sa clôture (code pénal).

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-map.php';
require_once __DIR__ . '/gallery-types.php';
require_once __DIR__ . '/gallery-modules.php';
require_once __DIR__ . '/gallery-event.php';
require_once __DIR__ . '/client-types.php';

const GB_KINDS = CT_TYPES;
const GB_MAX_PER_IP_HOUR = 6;
const GB_CNI_MAX_BYTES = 8 * 1024 * 1024;
const GB_CNI_MAX_DIM = 1800;
const GB_CNI_RETENTION_DAYS = 30;          // copie supprimée 30 jours après le dépôt ; les références du registre restent
const GB_CNI_SIDES = ['recto' => 'Recto', 'verso' => 'Verso'];
const GB_AUDIT_LABELS = [
    'request' => 'Demande déposée', 'accept' => 'Demande acceptée', 'refuse' => 'Demande refusée', 'release' => 'Emplacement libéré',
    'cancel' => 'Demande annulée', 'client_update' => 'Fiche exposant modifiée',
    'waitlist_claim' => 'Place voisine acceptée (file d\'attente)', 'no_show' => 'Absence constatée le jour même',
    'cni_upload' => 'Copie de pièce déposée', 'cni_verified' => 'Pièce vérifiée', 'cni_rejected' => 'Pièce refusée',
    'cni_delete' => 'Copie supprimée', 'cni_purged' => 'Copie purgée automatiquement', 'cni_view' => 'Copie consultée',
    'event_cancel' => 'Événement annulé', 'event_reopen' => 'Événement réactivé', 'event_notice' => 'Avis d’annulation envoyé',
    'refund_due' => 'Remboursement à effectuer', 'refund_paid' => 'Remboursement effectué', 'refund_waived' => 'Remboursement renoncé',
    'refund_adjust' => 'Montant du remboursement ajusté',
    'closure_alert' => 'Alerte de fermeture envoyée aux visiteurs',
];

function gb_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.slots.json';
}

function gb_read(string $slug): array
{
    $d = json_decode((string) @file_get_contents(gb_file($slug)), true);
    $d = is_array($d) ? $d : [];
    return ['slots' => is_array($d['slots'] ?? null) ? $d['slots'] : [], 'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : []];
}

/** Lit, modifie et réécrit le fichier des demandes sous verrou (deux exposants simultanés ne s'écrasent pas). $fn reçoit les données par référence. */
function gb_update(string $slug, callable $fn): mixed
{
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Dossier des galeries inaccessible.');
    $h = fopen(gb_file($slug), 'c+');
    if (!$h) throw new RuntimeException('Fichier des demandes inaccessible.');
    flock($h, LOCK_EX);
    $raw = stream_get_contents($h);
    $d = json_decode((string) $raw, true);
    $d = is_array($d) ? $d : [];
    $d = ['slots' => is_array($d['slots'] ?? null) ? $d['slots'] : [], 'hits' => is_array($d['hits'] ?? null) ? $d['hits'] : []];
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

/** Réglages de réservation actifs : le plan (module carte) ou l'événement (module organisateur). */
function gb_booking(array $gallery): array
{
    $map = is_array($gallery['map']['booking'] ?? null) ? $gallery['map']['booking'] : [];
    $event = gallery_event($gallery);
    $exposants = function_exists('gt_feature') ? gt_feature($gallery, 'exposants') : true;
    if ($event && ge_cancelled($event)) {
        return ['enabled' => false, 'source' => '', 'hold_days' => 7, 'intro' => '', 'event' => $event, 'cancelled' => true];
    }
    $eventOn = $exposants && $event && !empty($event['published']) && !empty($event['booking']['enabled'])
        && gm_enabled($gallery, 'organisateur') && ge_place_count($event) > 0;
    $mapOn = $exposants && gm_enabled($gallery, 'carte') && !empty($map['enabled']);
    if ($eventOn) return ['enabled' => true, 'source' => 'event', 'hold_days' => max(1, min(60, (int) $event['booking']['hold_days'])),
        'intro' => (string) $event['booking']['intro'], 'event' => $event];
    if ($mapOn) return ['enabled' => true, 'source' => 'map', 'hold_days' => max(1, min(60, (int) ($map['hold_days'] ?? 7))),
        'intro' => (string) ($map['intro'] ?? ''), 'event' => $event];
    return ['enabled' => false, 'source' => '', 'hold_days' => 7, 'intro' => '', 'event' => $event];
}

function gb_hold_seconds(array $gallery): int
{
    return (int) gb_booking($gallery)['hold_days'] * 86400;
}

/** Une demande en attente depuis plus longtemps que la durée de blocage ne retient plus l'emplacement. */
function gb_active(array $entry, int $hold): bool
{
    return ($entry['status'] ?? '') === 'taken' || (($entry['status'] ?? '') === 'pending' && (int) ($entry['requested'] ?? 0) + $hold > time());
}

/** État de chaque emplacement : free, pending (demande en cours), taken (attribué). */
function gb_states(array $gallery): array
{
    $hold = gb_hold_seconds($gallery);
    $out = [];
    foreach (gb_read($gallery['slug'])['slots'] as $id => $e) if (gb_active($e, $hold)) $out[(string) $id] = $e['status'];
    return $out;
}

function gb_slot(array $gallery, string $slotId): ?array
{
    foreach ($gallery['map']['items'] ?? [] as $it) if ($it['id'] === $slotId && $it['role'] === 'slot') return $it;
    $event = gallery_event($gallery);
    return $event ? ge_slot($event, $slotId) : null;
}

function gb_zone_label(array $gallery, array $slot): string
{
    if (($slot['kind'] ?? '') === 'event' || ($slot['zone'] ?? '') === '') return '';
    foreach ($gallery['map']['items'] ?? [] as $it) {
        if ($it['role'] === 'zone' && $it['id'] === $slot['zone']) return gallery_zone_name($it, function_exists('gt_locations') ? gt_locations($gallery) : null);
    }
    return '';
}

function gb_slot_name(array $gallery, array $slot): string
{
    $word = function_exists('gt_word') ? (gt_word($gallery, 'place') ?: 'emplacement') : 'emplacement';
    if (($slot['kind'] ?? '') === 'event') return ge_slot_name($slot, ucfirst($word));
    $zone = gb_zone_label($gallery, $slot);
    return trim(($slot['num'] !== '' ? ucfirst($word) . ' ' . $slot['num'] : ucfirst($word)) . ($slot['label'] !== '' ? ' (' . $slot['label'] . ')' : '') . ($zone !== '' ? ' — ' . $zone : ''));
}

/** Tarif d'un emplacement : zone du plan, ou place numérotée de l'événement. */
function gb_slot_price(array $gallery, array $slot, bool $pro = false): ?int
{
    if (($slot['kind'] ?? '') === 'event') {
        $eventPro = (int) ($slot['price_pro_cents'] ?? 0);
        return $pro && $eventPro > 0 ? $eventPro : (($slot['price_cents'] ?? null) !== null ? (int) $slot['price_cents'] : null);
    }
    return gallery_map_slot_price($gallery['map'], $slot, $pro);
}

function gb_eur(?int $cents): string
{
    return $cents === null ? 'tarif à définir' : number_format($cents / 100, 2, ',', ' ') . ' €';
}

function gb_kind_label(string $kind): string
{
    return ct_label($kind);
}

/** Libellé de l'état de la copie de pièce (aucune copie, déposée, vérifiée, refusée…). */
function gb_cni_status_label(?array $cni): string
{
    $status = (string) ($cni['status'] ?? '');
    return match ($status) {
        'pending' => 'Copie à vérifier',
        'verified' => 'Pièce vérifiée',
        'rejected' => 'Pièce refusée',
        'deleted' => 'Copie supprimée',
        'purged' => 'Copie purgée',
        default => 'Sans copie',
    };
}

/** Vrai si la fiche contient toutes les mentions du registre. */
function gb_register_ok(array $row): bool
{
    return empty($row['register_missing']);
}

/** Une demande (avec son type et sa fiche client normalisés) ou null. */
function gb_row(array $gallery, string $slotId): ?array
{
    $efound = null;
    foreach (gb_read($gallery['slug'])['slots'] as $id => $e) {
        if ((string) $id === $slotId) { $efound = $e; break; }
    }
    if (!$efound) return null;
    return gb_row_normalize((string) $slotId, $efound);
}

function gb_row_normalize(string $slotId, array $e): array
{
    $kind = ct_kind((string) ($e['kind'] ?? 'particulier'));
    $client = is_array($e['client'] ?? null) ? ct_input($e['client']) : null;
    $missing = $client ? ct_missing($client, $kind) : ["fiche d'identité (demande antérieure au formulaire)"];
    return $e + ['slot_id' => $slotId, 'kind' => $kind, 'client' => $client, 'register_missing' => $missing];
}

/** Demande par son jeton personnel (lien reçu par e-mail), ou null. */
function gb_by_token(string $slug, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    foreach (gb_read($slug)['slots'] as $id => $e) {
        if (hash_equals((string) ($e['token'] ?? ''), $token)) return gb_row_normalize((string) $id, $e);
    }
    return null;
}

/** Code du pass de confirmation : distinct du jeton d'annulation, impossible à deviner, stable pour une demande. */
function gb_pass_code(string $slug, string $token): string
{
    return preg_match('/^[a-f0-9]{32}$/', $token) ? ga_token($slug, 'pass', $token) : '';
}

/** Demande par le code du pass (QR présenté aux organisateurs ou à la sécurité), ou null. */
function gb_by_pass(string $slug, string $pass): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $pass)) return null;
    foreach (gb_read($slug)['slots'] as $id => $e) {
        $token = (string) ($e['token'] ?? '');
        if ($token !== '' && hash_equals(gb_pass_code($slug, $token), $pass)) return gb_row_normalize((string) $id, $e);
    }
    return null;
}

/** Adresse de l'application personnelle de l'exposant (sans ou avec le lien direct d'annulation). */
function gb_app_url(array $gallery, array $row, bool $cancel = false): string
{
    $token = (string) ($row['token'] ?? '');
    return $token === '' ? '' : ga_public_url('/galerie/exposant.php?g=' . rawurlencode((string) $gallery['slug']) . '&t=' . $token . ($cancel ? '&annuler=1' : ''));
}

/** Adresse du QR code de confirmation (vérification par un agent), ou ''. */
function gb_pass_url(array $gallery, array $row): string
{
    $pass = gb_pass_code((string) $gallery['slug'], (string) ($row['token'] ?? ''));
    return $pass === '' ? '' : ga_public_url('/galerie/exposant.php?g=' . rawurlencode((string) $gallery['slug']) . '&p=' . $pass);
}

/** Nouvelle demande d'un exposant. -> [demande, erreur]. Les copies CNI éventuelles sont validées avant l'enregistrement. */
function gb_request(array $gallery, string $slotId, array $in, string $ip, array $files = []): array
{
    $b = gb_booking($gallery);
    if (!empty($b['cancelled'])) return [null, "L'événement est annulé : les demandes d'emplacement sont fermées. Les exposants déjà inscrits sont prévenus du remboursement."];
    if (!$b['enabled'] || !go_is_live($gallery)) return [null, "Les demandes d'emplacement ne sont pas ouvertes."];
    $slot = gb_slot($gallery, $slotId);
    if (!$slot || $slot['shop'] !== '') return [null, "Cet emplacement n'est pas disponible."];
    $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return [null, 'Adresse e-mail invalide.'];
    $kind = ct_kind((string) ($in['kind'] ?? 'particulier'));
    $client = ct_input($in);
    $clientErr = ct_validate($client, $kind);
    if ($clientErr) return [null, $clientErr];
    if (empty($in['consent'])) return [null, "Cochez la case : c'est votre accord à l'enregistrement de vos coordonnées pour cette demande."];
    $filesErr = gb_cni_files_error($files);
    if ($filesErr) return [null, $filesErr];
    $hasCni = gb_cni_has_files($files);
    if ($hasCni && empty($client['cni_consent'])) return [null, "Cochez l'accord spécifique pour déposer une copie de votre pièce d'identité, ou retirez la photo."];
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') $name = ct_name($client, $kind);
    $hold = gb_hold_seconds($gallery);
    $price = gb_slot_price($gallery, $slot, $kind === 'entreprise');
    $token = bin2hex(random_bytes(16));
    $hash = hash('sha256', $ip . '|gb|' . $gallery['slug']);
    $entry = gb_update($gallery['slug'], static function (array &$d) use ($slotId, $hold, $hash, $name, $email, $kind, $in, $price, $client, $token) {
        $now = time();
        foreach ($d['hits'] as $h => $times) {
            $d['hits'][$h] = array_values(array_filter((array) $times, static fn ($t) => $t > $now - 3600));
            if (!$d['hits'][$h]) unset($d['hits'][$h]);
        }
        if (count($d['hits'][$hash] ?? []) >= GB_MAX_PER_IP_HOUR) return 'rate';
        if (isset($d['slots'][$slotId]) && gb_active($d['slots'][$slotId], $hold)) return 'busy';
        $pendingMine = 0;
        foreach ($d['slots'] as $e) if (gb_active($e, $hold) && ($e['email'] ?? '') === $email && ($e['status'] ?? '') === 'pending' && ++$pendingMine >= 2) return 'many';
        $d['hits'][$hash][] = $now;
        return $d['slots'][$slotId] = ['status' => 'pending', 'name' => $name, 'email' => $email, 'phone' => mb_substr(preg_replace('/[^0-9+ ().-]/', '', (string) ($in['phone'] ?? '')), 0, 30),
            'kind' => $kind, 'client' => $client, 'message' => mb_substr(trim((string) ($in['message'] ?? '')), 0, 400), 'price_cents' => $price,
            'token' => $token, 'requested' => $now, 'decided' => 0, 'consent_at' => $now, 'cni' => null];
    });
    if ($entry === 'rate') return [null, 'Trop de demandes depuis votre connexion : réessayez dans une heure.'];
    if ($entry === 'busy') return [null, "Cet emplacement vient d'être demandé par quelqu'un d'autre : choisissez-en un autre."];
    if ($entry === 'many') return [null, 'Vous avez déjà deux demandes en attente : attendez la réponse de la galerie.'];
    $entry = gb_row_normalize($slotId, $entry);
    if ($hasCni) {
        $cniErr = gb_cni_attach($gallery['slug'], $slotId, $token, $files);
        if ($cniErr) {
            gb_cancel($gallery['slug'], $token);
            return [null, $cniErr];
        }
        $entry = gb_row($gallery, $slotId) ?? $entry;
    }
    gb_audit($gallery['slug'], 'request', ['slot' => $slotId, 'kind' => $kind, 'email' => $email, 'cni' => $hasCni ? 'deposited' : 'none']);
    $label = gb_slot_name($gallery, $slot);
    $app = gb_app_url($gallery, $entry);
    $cancel = gb_app_url($gallery, $entry, true);
    $doc = ct_label($kind) . ($kind === 'particulier' ? '' : ' · représentant : ' . trim($client['rep_firstname'] . ' ' . $client['rep_lastname'])) . ' · pièce : ' . (CT_ID_DOC_TYPES[$client['id_doc_type']] ?? '') . ' n° ' . $client['id_doc_number'];
    ga_mail($email, 'Votre demande d\'emplacement — ' . $gallery['name'],
        "Bonjour {$name},\n\nNous avons bien reçu votre demande pour : $label.\nTarif : " . gb_eur($price) . " (" . ct_label($kind) . ").\nProfil enregistré au registre des vendeurs : $doc.\n" . ($hasCni ? "La copie de votre pièce a été reçue : elle sert à la vérification et sera supprimée le " . date('d/m/Y', time() + GB_CNI_RETENTION_DAYS * 86400) . ".\n" : '') . "\nL'organisateur vous répond par e-mail. L'emplacement vous est retenu " . (int) ($hold / 86400) . " jour(s) en attendant.\nVotre espace exposant (emplacement, plan et pass) : $app\nPour annuler votre demande : $cancel\n\n{$gallery['name']}\n");
    foreach ($gallery['admins'] as $a) {
        ga_mail($a['email'], 'Nouvelle demande d\'emplacement — ' . $gallery['name'],
            "$name ($email" . ($entry['phone'] !== '' ? ', ' . $entry['phone'] : '') . ") demande : $label.\nTarif : " . gb_eur($price) . " (" . ct_label($kind) . ").\n"
            . ($entry['register_missing'] ? "À compléter pour le registre : " . implode(', ', $entry['register_missing']) . ".\n" : '')
            . ($hasCni ? "Copie de pièce déposée : à vérifier dans la fiche exposant.\n" : "Aucune copie de pièce déposée.\n")
            . ($entry['message'] !== '' ? "Message : {$entry['message']}\n" : '') . "\nÀ traiter dans le portail, page « Exposants » de la galerie.\n");
    }
    return [$entry, null];
}

/** Réponse de l'organisateur : accept (attribué), refuse ou release (emplacement de nouveau libre). -> erreur ou null. */
function gb_decide(array $gallery, string $slotId, string $action, string $actor = ''): ?string
{
    if (!in_array($action, ['accept', 'refuse', 'release'], true)) return 'Action invalide.';
    $event = gallery_event($gallery);
    if ($event && ge_cancelled($event) && preg_match('/^ev[0-9]{1,4}$/', $slotId)) {
        return "L'événement est annulé : les demandes ne peuvent plus être modifiées. Suivez les remboursements depuis la page de l'événement.";
    }
    $slot = gb_slot($gallery, $slotId);
    $entry = gb_update($gallery['slug'], static function (array &$d) use ($slotId, $action) {
        $e = $d['slots'][$slotId] ?? null;
        if (!$e) return null;
        $copy = $e;
        if ($action === 'accept') $d['slots'][$slotId]['status'] = 'taken';
        else unset($d['slots'][$slotId]);
        if ($action === 'accept') $d['slots'][$slotId]['decided'] = time();
        return $copy;
    });
    if (!$entry) return 'Demande introuvable.';
    gb_audit($gallery['slug'], $action, ['slot' => $slotId, 'email' => (string) ($entry['email'] ?? ''), 'by' => $actor]);
    $label = $slot ? gb_slot_name($gallery, $slot) : 'votre emplacement';
    $app = gb_app_url($gallery, $entry);
    $msg = match ($action) {
        'accept' => "Bonne nouvelle : votre demande est acceptée.\n$label vous est attribué. Tarif : " . gb_eur($entry['price_cents']) . ".\nL'organisateur vous précisera le règlement et les modalités d'installation.",
        'refuse' => "Votre demande pour $label n'a pas pu être retenue. L'emplacement est de nouveau libre : vous pouvez en choisir un autre sur le plan.",
        default => "$label n'est plus attribué à votre nom : il est de nouveau libre.",
    };
    if ($app !== '') {
        $msg .= $action === 'accept'
            ? "\nVotre QR code de confirmation, à présenter aux organisateurs ou à la sécurité du site, se trouve dans votre espace exposant : $app"
            : "\nVotre espace exposant : $app";
    }
    ga_mail($entry['email'], 'Votre emplacement — ' . $gallery['name'], "Bonjour {$entry['name']},\n\n$msg\n\n{$gallery['name']}\n");
    return null;
}

/** Annulation par l'exposant lui-même (lien de son e-mail). $removedSlotId reçoit l'emplacement libéré. */
function gb_cancel(string $slug, string $token, ?string &$removedSlotId = null): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return false;
    $gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
    if ($gallery && ge_cancelled(gallery_event($gallery))) return false;   // l'annulation de l'événement fige le registre des remboursements
    $removed = null;
    $removedId = '';
    $ok = (bool) gb_update($slug, static function (array &$d) use ($token, &$removed, &$removedId) {
        foreach ($d['slots'] as $id => $e) {
            if (hash_equals((string) ($e['token'] ?? ''), $token)) { $removed = $e; $removedId = (string) $id; unset($d['slots'][$id]); return true; }
        }
        return false;
    });
    if ($ok && $removed) {
        foreach (array_keys(GB_CNI_SIDES) as $side) {
            $f = (string) ($removed['cni'][$side] ?? '');
            if ($f !== '') @unlink(gb_cni_dir($slug) . '/' . basename($f));
        }
        gb_audit($slug, 'cancel', ['slot' => $removedId, 'email' => (string) ($removed['email'] ?? '')]);
    }
    if ($ok) $removedSlotId = $removedId;
    return $ok;
}

/** Demandes pour l'administration : une ligne par emplacement avec son nom, sa zone et son état. */
function gb_rows(array $gallery): array
{
    gb_cni_purge($gallery['slug']);
    $hold = gb_hold_seconds($gallery);
    $rows = [];
    foreach (gb_read($gallery['slug'])['slots'] as $id => $e) {
        $slot = gb_slot($gallery, (string) $id);
        $expired = ($e['status'] ?? '') === 'pending' && !gb_active($e, $hold);
        $row = gb_row_normalize((string) $id, $e);
        $rows[] = $row + ['slot' => $slot ? gb_slot_name($gallery, $slot) : '(emplacement supprimé du plan)', 'expired' => $expired];
    }
    usort($rows, static fn ($a, $b) => $b['requested'] <=> $a['requested']);
    return $rows;
}

/** Exposants attribués (registre des vendeurs). */
function gb_register(array $gallery): array
{
    return array_values(array_filter(gb_rows($gallery), static fn ($r) => ($r['status'] ?? '') === 'taken'));
}

// ── Fiche exposant : correction par l'organisateur ─────────────────────────

/** Met à jour la fiche légale d'un exposant. -> message d'erreur ou null. */
function gb_client_update(string $slug, string $slotId, array $in, string $actor = ''): ?string
{
    $entry = gb_update($slug, static function (array &$d) use ($slotId) { return $d['slots'][$slotId] ?? null; });
    if (!$entry) return 'Demande introuvable.';
    $kind = ct_kind((string) ($in['kind'] ?? ($entry['kind'] ?? 'particulier')));
    $client = ct_input($in);
    $err = ct_validate($client, $kind);
    if ($err) return $err;
    $email = mb_strtolower(trim((string) ($in['email'] ?? ($entry['email'] ?? ''))));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return 'Adresse e-mail invalide.';
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 80);
    if ($name === '') $name = ct_name($client, $kind);
    $phone = mb_substr(preg_replace('/[^0-9+ ().-]/', '', (string) ($in['phone'] ?? '')), 0, 30);
    gb_update($slug, static function (array &$d) use ($slotId, $kind, $client, $email, $name, $phone) {
        if (!isset($d['slots'][$slotId])) return null;
        $d['slots'][$slotId]['kind'] = $kind;
        $d['slots'][$slotId]['client'] = $client;
        $d['slots'][$slotId]['name'] = $name;
        $d['slots'][$slotId]['email'] = $email;
        $d['slots'][$slotId]['phone'] = $phone;
        return true;
    });
    gb_audit($slug, 'client_update', ['slot' => $slotId, 'kind' => $kind, 'by' => $actor]);
    return null;
}

// ── Copies de pièce d'identité (stockage privé, vérification, purge) ───────

function gb_cni_dir(string $slug): string
{
    return galleries_dir() . '/cni/' . $slug;
}

/** Y a-t-il au moins un fichier envoyé (même invalide) ? */
function gb_cni_has_files(array $files): bool
{
    foreach (array_keys(GB_CNI_SIDES) as $side) {
        $f = $files['cni_' . $side] ?? null;
        if (is_array($f) && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) return true;
    }
    return false;
}

/** Vérifie les fichiers avant d'enregistrer la demande : type, taille, lisibilité. -> erreur ou null. */
function gb_cni_files_error(array $files): ?string
{
    $recto = 0;
    foreach (array_keys(GB_CNI_SIDES) as $side) {
        $f = $files['cni_' . $side] ?? null;
        if (!is_array($f)) continue;
        $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) return "La photo ({$side}) n'a pas pu être envoyée (fichier trop lourd ou envoi interrompu).";
        if ((int) ($f['size'] ?? 0) > GB_CNI_MAX_BYTES || (int) ($f['size'] ?? 0) <= 0) return 'Chaque photo doit faire moins de 8 Mo.';
        $info = @getimagesize((string) ($f['tmp_name'] ?? ''));
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return 'Les photos doivent être des images JPEG, PNG ou WebP.';
        if ($info[0] < 120 || $info[1] < 120) return 'Photo trop petite : reprenez-la de plus près, en entier.';
        if ($side === 'recto') $recto = 1;
    }
    $verso = $files['cni_verso'] ?? null;
    if (is_array($verso) && (int) ($verso['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && !$recto) return "Ajoutez d'abord le recto de la pièce d'identité.";
    return null;
}

/** Range les photos sous un nom imprévisible, sans métadonnées EXIF (re-encodage JPEG). -> erreur ou null. */
function gb_cni_attach(string $slug, string $slotId, string $token, array $files): ?string
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return 'Référence de demande invalide.';
    $dir = gb_cni_dir($slug);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return "Impossible d'enregistrer la copie de la pièce.";
    $stored = [];
    foreach (array_keys(GB_CNI_SIDES) as $side) {
        $f = $files['cni_' . $side] ?? null;
        if (!is_array($f) || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $dest = $dir . '/' . $token . '-' . $side . '.jpg';
        if (!gb_cni_store_file($f, $dest)) {
            foreach ($stored as $s) @unlink($dir . '/' . $s);
            return "La photo ({$side}) n'a pas pu être enregistrée. Réessayez avec une autre photo.";
        }
        $stored[] = $token . '-' . $side . '.jpg';
    }
    if (!$stored) return null;
    $meta = ['status' => 'pending', 'uploaded' => time(), 'by' => '', 'verified' => 0, 'rejected' => 0, 'reason' => '',
        'purge_after' => time() + GB_CNI_RETENTION_DAYS * 86400, 'consent' => time()];
    foreach (array_keys(GB_CNI_SIDES) as $side) {
        if (in_array($token . '-' . $side . '.jpg', $stored, true)) $meta[$side] = $token . '-' . $side . '.jpg';
    }
    $ok = gb_update($slug, static function (array &$d) use ($slotId, $token, $meta) {
        if (!isset($d['slots'][$slotId]) || !hash_equals((string) ($d['slots'][$slotId]['token'] ?? ''), $token)) return false;
        $d['slots'][$slotId]['cni'] = $meta;
        return true;
    });
    if (!$ok) {
        foreach ($stored as $s) @unlink($dir . '/' . $s);
        return 'Demande introuvable : la copie de la pièce n\'a pas été conservée.';
    }
    gb_audit($slug, 'cni_upload', ['slot' => $slotId, 'sides' => array_keys(array_filter(GB_CNI_SIDES, static fn ($label, $side) => isset($meta[$side]), ARRAY_FILTER_USE_BOTH))]);
    return null;
}

/** Décode, redresse (EXIF), réduit et ré-encode la photo en JPEG : les métadonnées du téléphone ne sont pas conservées. */
function gb_cni_store_file(array $file, string $dest): bool
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return false;
    $src = @imagecreatefromstring((string) @file_get_contents($tmp));
    if (!$src) return false;
    $info = @getimagesize($tmp);
    if ($info && $info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) { $rot = @imagerotate($src, $angle, 0); if ($rot) { imagedestroy($src); $src = $rot; } }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, GB_CNI_MAX_DIM / max(1, $w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = @imagejpeg($dst, $dest, 86);
    imagedestroy($src);
    imagedestroy($dst);
    if ($ok) @chmod($dest, 0600);
    return (bool) $ok;
}

/** Chemin réel d'une copie, ou null (nom contrôlé, fichier présent). */
function gb_cni_path(string $slug, array $entry, string $side): ?string
{
    if (!isset(GB_CNI_SIDES[$side])) return null;
    $name = (string) ($entry['cni'][$side] ?? '');
    if (!preg_match('/^[a-f0-9]{32}-(recto|verso)\.jpg$/', $name) || !str_ends_with($name, '-' . $side . '.jpg')) return null;
    $path = gb_cni_dir($slug) . '/' . $name;
    return is_file($path) ? $path : null;
}

/** Vérification par l'organisateur : pending, verified ou rejected. -> erreur ou null. */
function gb_cni_set_status(string $slug, string $slotId, string $status, string $actor, string $reason = ''): ?string
{
    if (!in_array($status, ['pending', 'verified', 'rejected'], true)) return 'Statut invalide.';
    $found = gb_update($slug, static function (array &$d) use ($slotId, $status, $actor, $reason) {
        $c = $d['slots'][$slotId]['cni'] ?? null;
        if (!is_array($c) || ($c['status'] ?? '') === 'purged' || ($c['status'] ?? '') === 'deleted') return false;
        $d['slots'][$slotId]['cni']['status'] = $status;
        if ($status === 'verified') { $d['slots'][$slotId]['cni']['verified'] = time(); $d['slots'][$slotId]['cni']['by'] = $actor; }
        if ($status === 'rejected') { $d['slots'][$slotId]['cni']['rejected'] = time(); $d['slots'][$slotId]['cni']['by'] = $actor; $d['slots'][$slotId]['cni']['reason'] = mb_substr(trim($reason), 0, 200); }
        return true;
    });
    if (!$found) return 'Aucune copie de pièce à vérifier pour cette demande.';
    gb_audit($slug, $status === 'verified' ? 'cni_verified' : ($status === 'rejected' ? 'cni_rejected' : 'cni_upload'), ['slot' => $slotId, 'by' => $actor, 'reason' => $reason]);
    return null;
}

/** Suppression immédiate de la copie (les références du registre restent). -> erreur ou null. */
function gb_cni_delete(string $slug, string $slotId, string $actor): ?string
{
    $files = [];
    $found = gb_update($slug, static function (array &$d) use ($slotId, &$files) {
        $c = $d['slots'][$slotId]['cni'] ?? null;
        if (!is_array($c)) return false;
        foreach (array_keys(GB_CNI_SIDES) as $side) if (!empty($c[$side])) $files[] = (string) $c[$side];
        $d['slots'][$slotId]['cni']['status'] = 'deleted';
        $d['slots'][$slotId]['cni']['deleted'] = time();
        $d['slots'][$slotId]['cni']['recto'] = '';
        $d['slots'][$slotId]['cni']['verso'] = '';
        return true;
    });
    if (!$found) return 'Aucune copie de pièce à supprimer.';
    foreach ($files as $f) if (preg_match('/^[a-f0-9]{32}-(recto|verso)\.jpg$/', $f)) @unlink(gb_cni_dir($slug) . '/' . $f);
    gb_audit($slug, 'cni_delete', ['slot' => $slotId, 'by' => $actor]);
    return null;
}

/** Supprime les copies arrivées à échéance et les fichiers orphelins (appelée à l'ouverture de l'administration). */
function gb_cni_purge(string $slug): void
{
    if (!is_file(gb_file($slug))) return;
    $files = [];
    try {
        gb_update($slug, static function (array &$d) use (&$files) {
            $now = time();
            foreach ($d['slots'] as &$e) {
                $c = $e['cni'] ?? null;
                if (!is_array($c)) continue;
                $status = (string) ($c['status'] ?? '');
                if ($status === 'deleted' || $status === 'purged') continue;
                if ((int) ($c['purge_after'] ?? 0) > 0 && (int) $c['purge_after'] < $now) {
                    foreach (array_keys(GB_CNI_SIDES) as $side) if (!empty($c[$side])) $files[] = (string) $c[$side];
                    $e['cni']['status'] = 'purged';
                    $e['cni']['purged'] = $now;
                    $e['cni']['recto'] = '';
                    $e['cni']['verso'] = '';
                }
            }
            unset($e);
            return null;
        });
    } catch (Throwable $e) {
        return;
    }
    foreach ($files as $f) if (preg_match('/^[a-f0-9]{32}-(recto|verso)\.jpg$/', $f)) @unlink(gb_cni_dir($slug) . '/' . $f);
    if ($files) gb_audit($slug, 'cni_purged', ['files' => count($files)]);
    $dir = gb_cni_dir($slug);
    foreach (glob($dir . '/*.jpg') ?: [] as $path) {
        if (@filemtime($path) < time() - (GB_CNI_RETENTION_DAYS + 7) * 86400) @unlink($path);
    }
}

// ── Journal d'audit (consultations, vérifications, corrections) ────────────

function gb_audit_file(string $slug): string
{
    return galleries_dir() . '/' . $slug . '.audit.jsonl';
}

function gb_audit(string $slug, string $action, array $details): void
{
    if (!gallery_slug_valid($slug)) return;
    $dir = galleries_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $file = gb_audit_file($slug);
    $prev = '';
    if (is_file($file)) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        if ($lines) { $last = json_decode((string) end($lines), true); $prev = (string) ($last['hash'] ?? ''); }
    }
    $payload = ['at' => gmdate('c'), 'action' => $action] + $details;
    $payload['hash'] = hash('sha256', $prev . '|' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    $size = @filesize($file);
    if ($size !== false && $size > 262144) {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        @file_put_contents($file, implode("\n", array_slice($lines, -1000)) . "\n", LOCK_EX);
    }
}

/** Dernières entrées du journal (les plus récentes d'abord). */
function gb_audit_read(string $slug, int $limit = 60): array
{
    $file = gb_audit_file($slug);
    if (!is_file($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $out = [];
    foreach (array_reverse($lines) as $line) {
        $row = json_decode((string) $line, true);
        if (is_array($row)) $out[] = $row;
        if (count($out) >= $limit) break;
    }
    return $out;
}
