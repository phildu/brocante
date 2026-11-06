<?php

// Annulation d'un événement (vide-grenier, marché aux puces) et remboursement des exposants selon les conditions de vente.
//
// Les conditions sont saisies dans l'assistant, onglet « Règlements » (data/galeries/<id>.assistant.json, clé « rules »).
// L'annulation elle-même est écrite dans l'événement (clé « cancelled » de data/galeries/<id>.json) : elle ferme les demandes
// d'emplacement, s'affiche sur la page publique et dans l'espace exposant, et déclenche un e-mail d'alerte.
//
// Le paiement des emplacements n'est pas encaissé par la plateforme : le remboursement est un registre tenu par l'organisateur.
// Chaque demande active reçoit une ligne « refund » dans data/galeries/<id>.slots.json :
//   status       due (à rembourser), paid (remboursé), waived (sans remboursement), none (rien à rembourser)
//   pct          pourcentage appliqué au tarif enregistré
//   amount_cents montant calculé, price_cents tarif de référence, cause, at, paid_at, by, note, notified
// Une demande encore en attente n'a rien payé : elle est prévenue de l'annulation sans remboursement à effectuer.

require_once __DIR__ . '/gallery-admin.php';
require_once __DIR__ . '/gallery-booking.php';
require_once __DIR__ . '/gallery-event.php';

const GCN_REFUND_STATUSES = [
    'due' => 'À rembourser',
    'paid' => 'Remboursé',
    'waived' => 'Sans remboursement',
    'none' => 'Rien à rembourser',
];

/** Conditions de vente par défaut : remboursement intégral si l'organisateur annule, dégressif si l'exposant se désiste. */
function gcn_default_rules(): array
{
    return [
        'organizer_pct' => 100,
        'force_majeure_pct' => 100,
        'full_days' => 30,
        'partial_days' => 7,
        'partial_pct' => 50,
        'late_pct' => 0,
        'refund_delay_days' => 30,
        'refund_method' => 'Virement bancaire ou chèque',
        'custom' => '',
        'saved' => 0,
    ];
}

function gcn_rules(array $r): array
{
    $d = gcn_default_rules();
    $int = static function (string $k, int $min, int $max) use ($r, $d): int {
        return isset($r[$k]) && is_numeric($r[$k]) ? max($min, min($max, (int) $r[$k])) : $d[$k];
    };
    $rules = [
        'organizer_pct' => $int('organizer_pct', 0, 100),
        'force_majeure_pct' => $int('force_majeure_pct', 0, 100),
        'full_days' => $int('full_days', 0, 180),
        'partial_days' => $int('partial_days', 0, 180),
        'partial_pct' => $int('partial_pct', 0, 100),
        'late_pct' => $int('late_pct', 0, 100),
        'refund_delay_days' => $int('refund_delay_days', 0, 180),
        'refund_method' => mb_substr(trim((string) ($r['refund_method'] ?? $d['refund_method'])), 0, 120) ?: $d['refund_method'],
        'custom' => mb_substr(trim((string) ($r['custom'] ?? '')), 0, 800),
        'saved' => (int) ($r['saved'] ?? 0),
    ];
    if ($rules['partial_days'] > $rules['full_days']) $rules['partial_days'] = $rules['full_days'];
    return $rules;
}

/** Conditions enregistrées dans l'assistant de la galerie (valeurs par défaut si l'onglet n'a jamais été rempli). */
function gcn_rules_from_gallery(array $gallery): array
{
    $assistant = ga_read((string) $gallery['slug'], 'assistant', []);
    return gcn_rules((array) ($assistant['rules'] ?? []));
}

/** Pourcentage de remboursement selon la cause de l'annulation décidée par l'organisateur. */
function gcn_cause_pct(array $rules, string $cause): int
{
    return in_array($cause, ['meteo', 'force_majeure'], true) ? (int) $rules['force_majeure_pct'] : (int) $rules['organizer_pct'];
}

/** Pourcentage de remboursement quand l'exposant se désiste $daysBefore jours avant la manifestation. */
function gcn_withdrawal_pct(array $rules, int $daysBefore): int
{
    if ($daysBefore >= (int) $rules['full_days']) return 100;
    if ($daysBefore >= (int) $rules['partial_days']) return (int) $rules['partial_pct'];
    return (int) $rules['late_pct'];
}

function gcn_refund_amount(?int $priceCents, int $pct): int
{
    if ($priceCents === null || $priceCents <= 0 || $pct <= 0) return 0;
    return (int) round($priceCents * max(0, min(100, $pct)) / 100);
}

/** Conditions lisibles : libellé => valeur (assistant, règlement imprimé, récapitulatif). */
function gcn_rules_lines(array $rules): array
{
    $pct = static fn (int $p): string => $p >= 100 ? 'Remboursement intégral (100 %)' : ($p <= 0 ? 'Aucun remboursement' : 'Remboursement partiel (' . $p . ' %)');
    $lines = [
        'Annulation par l’organisateur' => $pct((int) $rules['organizer_pct']),
        'Annulation pour météo ou force majeure' => $pct((int) $rules['force_majeure_pct']),
        'Désistement de l’exposant à plus de ' . (int) $rules['full_days'] . ' jours' => 'Remboursement intégral',
        'Désistement entre ' . (int) $rules['partial_days'] . ' et ' . (int) $rules['full_days'] . ' jours' => $pct((int) $rules['partial_pct']),
        'Désistement à moins de ' . (int) $rules['partial_days'] . ' jours' => $pct((int) $rules['late_pct']),
        'Délai de remboursement' => (int) $rules['refund_delay_days'] . ' jours après l’annonce',
        'Moyen de remboursement' => (string) $rules['refund_method'],
    ];
    if (trim((string) $rules['custom']) !== '') $lines['Condition particulière'] = (string) $rules['custom'];
    return $lines;
}

function gcn_refund_label(?array $refund): string
{
    $status = (string) ($refund['status'] ?? '');
    return GCN_REFUND_STATUSES[$status] ?? 'Rien à rembourser';
}

function gcn_row_refund(array $row): array
{
    $r = is_array($row['refund'] ?? null) ? $row['refund'] : [];
    return [
        'status' => isset(GCN_REFUND_STATUSES[(string) ($r['status'] ?? '')]) ? (string) $r['status'] : 'none',
        'pct' => max(0, min(100, (int) ($r['pct'] ?? 0))),
        'amount_cents' => max(0, (int) ($r['amount_cents'] ?? 0)),
        'price_cents' => max(0, (int) ($r['price_cents'] ?? ($row['price_cents'] ?? 0))),
        'cause' => (string) ($r['cause'] ?? ''),
        'at' => (int) ($r['at'] ?? 0),
        'paid_at' => (int) ($r['paid_at'] ?? 0),
        'by' => (string) ($r['by'] ?? ''),
        'note' => (string) ($r['note'] ?? ''),
        'notified' => (int) ($r['notified'] ?? 0),
    ];
}

/** Demandes rattachées à l'événement (places ev1…evN), avec leur remboursement normalisé. */
function gcn_rows(array $gallery): array
{
    $rows = array_values(array_filter(gb_rows($gallery), static function (array $r): bool {
        if (!preg_match('/^ev[0-9]{1,4}$/', (string) $r['slot_id'])) return false;
        return is_array($r['refund'] ?? null) || in_array((string) ($r['status'] ?? ''), ['taken', 'pending'], true);
    }));
    foreach ($rows as &$row) $row['refund'] = gcn_row_refund($row);
    unset($row);
    usort($rows, static fn ($a, $b) => strnatcmp((string) $a['slot_id'], (string) $b['slot_id']));
    return $rows;
}

/** Coordonnées de l'organisateur pour l'e-mail d'alerte (assistant, puis comptes de la galerie). */
function gcn_contact(array $gallery): array
{
    $p = (array) (ga_read((string) $gallery['slug'], 'assistant', [])['profile'] ?? []);
    $email = trim((string) ($p['email'] ?? ''));
    if ($email === '' && !empty($gallery['admins'][0]['email'])) $email = (string) $gallery['admins'][0]['email'];
    return [
        'organizer' => trim((string) ($p['organizer'] ?? '')) ?: (string) $gallery['name'],
        'contact' => trim((string) ($p['contact'] ?? '')),
        'email' => $email,
        'phone' => trim((string) ($p['phone'] ?? '')),
    ];
}

/** Récapitulatif : combien d'exposants, combien à rembourser, combien déjà remboursé. */
function gcn_summary(array $gallery): array
{
    $event = gallery_event($gallery);
    $out = ['rows' => [], 'taken' => 0, 'pending' => 0, 'due' => 0, 'paid' => 0, 'waived' => 0, 'none' => 0,
        'due_cents' => 0, 'paid_cents' => 0, 'total_cents' => 0, 'cancelled' => ge_cancelled($event)];
    if (!$event) return $out;
    foreach (gcn_rows($gallery) as $row) {
        $out['rows'][] = $row;
        if (($row['status'] ?? '') === 'taken') $out['taken']++;
        elseif (($row['status'] ?? '') === 'pending') $out['pending']++;
        $ref = $row['refund'];
        $out[$ref['status']] = ($out[$ref['status']] ?? 0) + 1;
        $out['total_cents'] += $ref['amount_cents'];
        if ($ref['status'] === 'due') $out['due_cents'] += $ref['amount_cents'];
        if ($ref['status'] === 'paid') $out['paid_cents'] += $ref['amount_cents'];
    }
    return $out;
}

/** Annule l'événement, prépare le registre des remboursements et prévient les exposants. -> [erreur, résumé]. */
function gcn_cancel(array $gallery, array $in, string $actor = ''): array
{
    $event = gallery_event($gallery);
    if (!$event) return ['Événement introuvable.', []];
    if (ge_cancelled($event)) return ["L'événement est déjà annulé.", gcn_summary($gallery)];
    $rules = gcn_rules_from_gallery($gallery);
    $cause = isset(GE_CANCEL_CAUSES[(string) ($in['cause'] ?? '')]) ? (string) $in['cause'] : 'organisateur';
    $mode = in_array($in['refund_mode'] ?? 'rules', ['rules', 'full', 'none', 'partial'], true) ? (string) $in['refund_mode'] : 'rules';
    $pct = match ($mode) {
        'full' => 100,
        'none' => 0,
        'partial' => max(0, min(100, (int) ($in['refund_pct'] ?? $rules['partial_pct']))),
        default => gcn_cause_pct($rules, $cause),
    };
    $cancel = [
        'at' => time(),
        'reason' => mb_substr(trim((string) ($in['reason'] ?? '')), 0, 400),
        'cause' => $cause,
        'refund_pct' => $pct,
        'refund_delay_days' => max(0, min(180, (int) ($in['refund_delay_days'] ?? $rules['refund_delay_days']))),
        'refund_method' => mb_substr(trim((string) ($in['refund_method'] ?? '')), 0, 120) ?: (string) $rules['refund_method'],
        'by' => mb_substr(trim($actor), 0, 120),
        'notified' => 0,
    ];
    $gallery['event']['cancelled'] = $cancel;
    if (!gallery_save($gallery)) return ["Impossible d'enregistrer l'annulation (droits du dossier data).", []];

    $now = time();
    $counts = ['taken' => 0, 'pending' => 0, 'due' => 0, 'none' => 0, 'due_cents' => 0];
    try {
        gb_update((string) $gallery['slug'], static function (array &$d) use ($pct, $cause, $now, &$counts) {
            foreach ($d['slots'] as $id => &$e) {
                if (!preg_match('/^ev[0-9]{1,4}$/', (string) $id)) continue;
                $status = (string) ($e['status'] ?? '');
                if (!in_array($status, ['taken', 'pending'], true)) continue;
                $price = isset($e['price_cents']) && $e['price_cents'] !== null ? max(0, (int) $e['price_cents']) : 0;
                $rowPct = $status === 'taken' && $price > 0 ? $pct : 0;
                $amount = $status === 'taken' ? gcn_refund_amount($price, $rowPct) : 0;
                $refundStatus = $status === 'taken' ? ($amount > 0 ? 'due' : 'none') : 'none';
                $e['refund'] = ['status' => $refundStatus, 'pct' => $rowPct, 'amount_cents' => $amount, 'price_cents' => $price,
                    'cause' => $cause, 'at' => $now, 'paid_at' => 0, 'by' => '', 'note' => '', 'notified' => 0];
                $counts[$status === 'taken' ? 'taken' : 'pending']++;
                $counts[$refundStatus === 'due' ? 'due' : 'none']++;
                if ($refundStatus === 'due') $counts['due_cents'] += $amount;
            }
            unset($e);
            return $counts;
        });
    } catch (Throwable $e) {
        // L'événement est déjà annulé : le registre pourra être repris depuis la page.
    }
    gb_audit((string) $gallery['slug'], 'event_cancel', ['cause' => $cause, 'pct' => $pct, 'by' => $cancel['by'],
        'taken' => $counts['taken'], 'pending' => $counts['pending'], 'due' => $counts['due_cents']]);

    $notified = !empty($in['notify']) ? gcn_notify($gallery) : 0;
    return [null, ['event' => $cancel, 'ledger' => $counts, 'notified' => $notified, 'summary' => gcn_summary($gallery)]];
}

/** Réactive un événement annulé par erreur : les lignes de remboursement non versées sont retirées. */
function gcn_reopen(array $gallery, string $actor = ''): ?string
{
    $event = gallery_event($gallery);
    if (!$event || !ge_cancelled($event)) return "Cet événement n'est pas annulé.";
    unset($gallery['event']['cancelled']);
    if (!gallery_save($gallery)) return "Impossible d'enregistrer la réactivation (droits du dossier data).";
    try {
        gb_update((string) $gallery['slug'], static function (array &$d) {
            foreach ($d['slots'] as $id => &$e) {
                if (preg_match('/^ev[0-9]{1,4}$/', (string) $id)) unset($e['refund']);
            }
            unset($e);
            return true;
        });
    } catch (Throwable $e) {
        return "L'événement est réactivé, mais le registre des remboursements n'a pas pu être nettoyé.";
    }
    gb_audit((string) $gallery['slug'], 'event_reopen', ['by' => $actor]);
    return null;
}

/** Change l'état d'une ligne de remboursement (à rembourser, remboursé, sans remboursement…). */
function gcn_mark_refund(string $slug, string $slotId, string $status, string $actor = '', string $note = ''): ?string
{
    if (!isset(GCN_REFUND_STATUSES[$status])) return 'Statut de remboursement invalide.';
    $gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
    if (!$gallery || !ge_cancelled(gallery_event($gallery))) return "Aucun événement annulé à mettre à jour pour cette galerie.";
    $found = gb_update($slug, static function (array &$d) use ($slotId, $status, $actor, $note) {
        $e = $d['slots'][$slotId] ?? null;
        if (!is_array($e) || !is_array($e['refund'] ?? null)) return false;
        $d['slots'][$slotId]['refund']['status'] = $status;
        $d['slots'][$slotId]['refund']['paid_at'] = $status === 'paid' ? time() : 0;
        $d['slots'][$slotId]['refund']['by'] = mb_substr(trim($actor), 0, 120);
        $d['slots'][$slotId]['refund']['note'] = mb_substr(trim($note), 0, 300);
        return true;
    });
    if (!$found) return "Aucune ligne de remboursement pour cet emplacement.";
    $action = match ($status) { 'paid' => 'refund_paid', 'waived' => 'refund_waived', 'due' => 'refund_due', default => 'refund_due' };
    gb_audit($slug, $action, ['slot' => $slotId, 'by' => $actor, 'note' => $note]);
    return null;
}

/** Texte de l'e-mail d'alerte envoyé à un exposant. */
function gcn_notice_body(array $gallery, array $event, array $row, array $cancel): string
{
    $contact = gcn_contact($gallery);
    $ref = gcn_row_refund($row);
    $taken = ($row['status'] ?? '') === 'taken';
    $status = $ref['status'];
    $price = (int) $ref['price_cents'];
    if ($status === 'paid') {
        $refund = 'Le remboursement de ' . gb_eur($ref['amount_cents']) . ' a été effectué'
            . ($ref['paid_at'] > 0 ? ' le ' . date('d/m/Y', $ref['paid_at']) : '') . '.'
            . ($ref['note'] !== '' ? ' ' . $ref['note'] : '');
    } elseif ($status === 'due') {
        $refund = 'Un remboursement de ' . gb_eur($ref['amount_cents']) . ' vous sera versé sous '
            . (int) $cancel['refund_delay_days'] . ' jours, par ' . $cancel['refund_method'] . '.';
        if ($price > 0 && (int) $ref['pct'] < 100) $refund .= ' Il correspond à ' . (int) $ref['pct'] . ' % du tarif de ' . gb_eur($price) . '.';
    } elseif ($status === 'waived') {
        $refund = "Aucun remboursement n'est prévu à ce titre." . ($ref['note'] !== '' ? ' ' . $ref['note'] : '');
    } elseif (!$taken) {
        $refund = "Votre demande était encore en attente : aucun paiement n'a été enregistré, vous n'avez rien à payer et aucun remboursement n'est nécessaire."
            . " Si vous avez réglé sur place, contactez l'organisateur.";
    } elseif ($price <= 0) {
        $refund = "Aucun tarif n'était enregistré pour votre emplacement : aucun remboursement n'est à effectuer.";
    } else {
        $refund = "Aucun remboursement n'est à effectuer selon les conditions de vente de cet événement.";
    }
    $date = ge_date_text($event);
    $place = ge_place_text($event);
    $app = gb_app_url($gallery, $row);
    $lines = [
        'Bonjour ' . ($row['name'] ?? '') . ',',
        '',
        'L’organisateur de « ' . $gallery['name'] . ' » vous informe de l’annulation de ' . $event['title']
            . ($date !== '' ? ' prévu ' . $date : '') . ($place !== '' ? ' à ' . $place : '') . '.',
        'Motif : ' . ge_cancel_reason($event) . '.',
        '',
        'Votre emplacement : ' . (($row['slot'] ?? '') !== '' ? $row['slot'] : 'emplacement retiré du plan') . '.',
        'Remboursement : ' . $refund,
        '',
        'Nous vous prions de nous excuser pour la gêne occasionnée.',
    ];
    if ($app !== '') $lines[] = 'Votre espace exposant reste accessible pour consulter votre dossier : ' . $app;
    $lines[] = '';
    $lines[] = $contact['organizer'] . ($contact['contact'] !== '' ? ' — ' . $contact['contact'] : '');
    if ($contact['email'] !== '') $lines[] = 'E-mail : ' . $contact['email'];
    if ($contact['phone'] !== '') $lines[] = 'Téléphone : ' . $contact['phone'];
    return implode("\n", $lines) . "\n";
}

/**
 * Envoie l'alerte d'annulation aux exposants de l'événement (demandes attribuées et en attente), puis le récapitulatif
 * aux administrateurs. $force renvoie l'alerte à tous, même à ceux déjà prévenus. -> nombre d'exposants prévenus.
 */
function gcn_notify(array $gallery, bool $force = false): int
{
    $event = gallery_event($gallery);
    if (!$event || !ge_cancelled($event)) return 0;
    $cancel = (array) $event['cancelled'];
    $sent = [];
    foreach (gcn_rows($gallery) as $row) {
        if (!in_array((string) ($row['status'] ?? ''), ['taken', 'pending'], true)) continue;
        $email = trim((string) ($row['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (!$force && (int) ($row['refund']['notified'] ?? 0) > 0) continue;
        if (!ga_mail($email, 'Événement annulé — ' . $gallery['name'], gcn_notice_body($gallery, $event, $row, $cancel))) continue;
        $sent[] = (string) $row['slot_id'];
    }
    if ($sent) {
        $now = time();
        try {
            gb_update((string) $gallery['slug'], static function (array &$d) use ($sent, $now) {
                foreach ($sent as $id) {
                    if (isset($d['slots'][$id]['refund']) && is_array($d['slots'][$id]['refund'])) $d['slots'][$id]['refund']['notified'] = $now;
                }
                return count($sent);
            });
        } catch (Throwable $e) {
            // L'alerte est partie : seule la trace de l'envoi manque.
        }
    }
    $count = $sent ? count($sent) : 0;
    $gallery['event']['cancelled']['notified'] = time();
    gallery_save($gallery);
    if ($count > 0) gb_audit((string) $gallery['slug'], 'event_notice', ['sent' => $count, 'by' => (string) ($cancel['by'] ?? '')]);

    $link = ga_public_url('/portail/galerie-evenement.php?g=' . rawurlencode((string) $gallery['slug']));
    foreach ((array) ($gallery['admins'] ?? []) as $admin) {
        $to = trim((string) ($admin['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) continue;
        ga_mail($to, 'Annulation : récapitulatif des remboursements — ' . $gallery['name'],
            "Bonjour,\n\nL'événement « {$event['title']} » est annulé (" . ge_cancel_reason($event) . ").\n"
            . $count . " exposant(s) viennent d'être prévenus par e-mail.\n"
            . 'Condition appliquée : ' . (int) $cancel['refund_pct'] . " % du tarif, sous " . (int) $cancel['refund_delay_days'] . ' jours (' . $cancel['refund_method'] . ").\n\n"
            . "Suivez les remboursements ligne par ligne : $link\n\n{$gallery['name']}\n");
    }
    return $count;
}
