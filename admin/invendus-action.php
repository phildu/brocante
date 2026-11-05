<?php
/**
 * Actions de l'espace admin du module « Invendus & dons » (POST uniquement).
 * Contrairement à certaines actions plus anciennes du site, chacune est
 * protégée par un jeton CSRF en plus de la session admin.
 */
session_start();
require_once __DIR__ . '/../includes/invendus.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/invendus.php');
    exit;
}
require_csrf();
unsold_scalar_post(['ids']);

$action = unsold_param($_POST, 'action');
$return = unsold_safe_return(unsold_param($_POST, 'return'), '/admin/invendus.php');
$ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
$eventId = (int) unsold_param($_POST, 'event_id');

/** Redirige et termine. */
$go = function (?string $to = null) use (&$return): void {
    header('Location: ' . ($to ?? $return));
    exit;
};
$ok = fn(string $m) => flash_set($m, 'ok');
$ko = fn(string $m) => flash_set($m, 'error');
$plural = fn(int $n, string $one, string $many) => $n . ' ' . ($n > 1 ? $many : $one);

switch ($action) {

    /* ---------- Éditions ---------- */

    case 'event_save': {
        $id = (int) unsold_param($_POST, 'id') ?: null;
        [$data, $errors] = unsold_event_from_post($_POST);
        if ($errors) {
            $ko(implode(' ', $errors));
            $go();
        }
        if ($id && !unsold_event_get($id)) {
            $ko('Édition introuvable.');
            $go('/admin/invendus.php');
        }
        $newId = unsold_event_save($id, $data);
        $ok($id ? 'Édition enregistrée.' : 'Édition créée. Ajoutez maintenant les structures de don et publiez-la quand vous êtes prêts.');
        $go('/admin/invendus-event.php?id=' . $newId);
        break;
    }

    case 'event_open_now':
    case 'event_end_now':
    case 'event_close':
    case 'event_reopen': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        if ($action === 'event_open_now') {
            unsold_event_open_now($eventId);
            $ok('Le point de collecte est ouvert maintenant.');
        } elseif ($action === 'event_end_now') {
            unsold_event_end_now($eventId);
            $ok('Le point de collecte est fermé. Vous pouvez remettre le reliquat à une donnerie.');
        } elseif ($action === 'event_close') {
            db()->prepare("UPDATE unsold_events SET status = 'closed' WHERE id = ?")->execute([$eventId]);
            $ok('Édition clôturée.');
        } else {
            db()->prepare("UPDATE unsold_events SET status = 'published' WHERE id = ?")->execute([$eventId]);
            $ok('Édition rouverte.');
        }
        $go();
        break;
    }

    case 'event_delete': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        if (!in_array($e['status'], ['draft', 'closed'], true)) {
            $ko('Passez d\'abord l\'édition en « Brouillon » ou « Clôturée » avant de la supprimer.');
            $go();
        }
        unsold_event_delete($eventId);
        $ok('Édition « ' . $e['name'] . ' » supprimée avec toutes ses propositions.');
        $go('/admin/invendus.php');
        break;
    }

    /* ---------- Propositions ---------- */

    case 'offer_add': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        [$data, $errors] = unsold_offer_from_post($_POST, false, false);
        if ($errors) {
            $ko(implode(' ', $errors));
            $go();
        }
        $photo = null;
        try {
            $photo = unsold_store_photo('photo');
        } catch (RuntimeException $ex) {
            $ko($ex->getMessage());
            $go();
        }
        unsold_offer_create($eventId, $data, null, $photo, 'admin');
        $ok('Proposition ajoutée : ' . $data['title']);
        $go();
        break;
    }

    case 'offer_update': {
        $o = unsold_offer_get((int) unsold_param($_POST, 'id'));
        if (!$o) { $ko('Proposition introuvable.'); $go('/admin/invendus.php'); }
        [$data, $errors] = unsold_offer_from_post($_POST, false, false);
        if ($errors) {
            $ko(implode(' ', $errors));
            $go();
        }
        try {
            $newPhoto = unsold_store_photo('photo');
        } catch (RuntimeException $ex) {
            $ko($ex->getMessage());
            $go();
        }
        $photo = $o['photo'];
        if ($newPhoto) {
            unsold_delete_photo($o['photo']);
            $photo = $newPhoto;
        } elseif (!empty($_POST['remove_photo'])) {
            unsold_delete_photo($o['photo']);
            $photo = null;
        }
        db()->prepare('UPDATE unsold_offers SET donor_name = ?, donor_email = ?, donor_phone = ?, stand_ref = ?, title = ?,
            description = ?, category = ?, quantity = ?, size = ?, handover = ?, pickup_note = ?, photo = ?, admin_note = ?,
            updated_at = ? WHERE id = ?')
            ->execute([
                $data['donor_name'], $data['donor_email'], $data['donor_phone'], $data['stand_ref'], $data['title'],
                $data['description'], $data['category'], $data['quantity'], $data['size'], $data['handover'],
                $data['pickup_note'], $photo, unsold_text(unsold_param($_POST, 'admin_note'), 500), unsold_now_str(), $o['id'],
            ]);
        $ok('Proposition enregistrée.');
        $go();
        break;
    }

    case 'offer_status': {
        $o = unsold_offer_get((int) unsold_param($_POST, 'id'));
        $to = unsold_param($_POST, 'to');
        if (!$o) { $ko('Proposition introuvable.'); $go('/admin/invendus.php'); }
        if ($to === 'donated') {
            $ko('Pour remettre des objets à une donnerie, utilisez l\'onglet « Reliquat » : il produit le bordereau.');
        } elseif (unsold_transition((int) $o['id'], $to, 'admin', unsold_line(unsold_param($_POST, 'note'), 150))) {
            $ok('Statut mis à jour : ' . (unsold_statuses()[$to] ?? $to) . '.');
        } else {
            $ko('Ce changement de statut n\'est pas possible depuis l\'état actuel.');
        }
        $go();
        break;
    }

    case 'offer_delete': {
        $o = unsold_offer_get((int) unsold_param($_POST, 'id'));
        if ($o) {
            unsold_delete_offer((int) $o['id']);
            $ok('Proposition supprimée.');
            $go('/admin/invendus-event.php?id=' . (int) $o['event_id'] . '&tab=propositions');
        }
        $go('/admin/invendus.php');
        break;
    }

    case 'offer_bulk': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        if (!$ids) { $ko('Cochez d\'abord au moins une proposition.'); $go(); }
        $bulk = unsold_param($_POST, 'bulk');
        $n = 0;
        $ownIds = [];
        foreach ($ids as $id) {
            $o = unsold_offer_get($id);
            if ($o && (int) $o['event_id'] === $eventId) $ownIds[] = $id; // jamais hors de l'édition
        }
        switch ($bulk) {
            case 'confirm':
                foreach ($ownIds as $id) if (unsold_confirm_offer($id, 'admin')) $n++;
                $ok($plural($n, 'proposition confirmée', 'propositions confirmées') . ' comme invendue' . ($n > 1 ? 's' : '') . '.');
                break;
            case 'unconfirm':
                foreach ($ownIds as $id) if (unsold_unconfirm_offer($id)) $n++;
                $ok($plural($n, 'confirmation annulée', 'confirmations annulées') . '.');
                break;
            case 'to_point':
                $n = unsold_transition_many($ownIds, $eventId, 'at_point', 'admin', 'Reçu au point (admin)');
                $ok($plural($n, 'objet reçu', 'objets reçus') . ' au point de collecte.');
                break;
            case 'withdraw':
                $n = unsold_transition_many($ownIds, $eventId, 'withdrawn', 'admin', 'Retiré (admin)');
                $ok($plural($n, 'proposition retirée', 'propositions retirées') . '.');
                break;
            case 'restore':
                $n = unsold_transition_many($ownIds, $eventId, 'proposed', 'admin', 'Restauré (admin)');
                $ok($plural($n, 'proposition restaurée', 'propositions restaurées') . '.');
                break;
            case 'assign':
                $tourId = (int) unsold_param($_POST, 'tour_id');
                $t = unsold_tour_get($tourId);
                if (!$t || (int) $t['event_id'] !== $eventId) { $ko('Choisissez une tournée.'); break; }
                $n = unsold_tour_assign($tourId, $ownIds);
                $ok($plural($n, 'proposition affectée', 'propositions affectées') . ' à la tournée de ' . $t['volunteer_name'] . '.');
                break;
            case 'unassign':
                $n = unsold_tour_unassign($ownIds, $eventId);
                $ok($plural($n, 'proposition détachée', 'propositions détachées') . ' de sa tournée.');
                break;
            case 'delete':
                foreach ($ownIds as $id) { unsold_delete_offer($id); $n++; }
                $ok($plural($n, 'proposition supprimée', 'propositions supprimées') . '.');
                break;
            default:
                $ko('Choisissez une action à appliquer.');
        }
        $go();
        break;
    }

    /* ---------- Tournées ---------- */

    case 'tour_save': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        [$data, $errors] = unsold_tour_from_post($_POST);
        if ($errors) { $ko(implode(' ', $errors)); $go(); }
        $tourId = (int) unsold_param($_POST, 'id');
        if ($tourId) {
            $t = unsold_tour_get($tourId);
            if (!$t || (int) $t['event_id'] !== $eventId) { $ko('Tournée introuvable.'); $go(); }
            unsold_tour_update($tourId, $data);
            $ok('Tournée mise à jour.');
        } else {
            unsold_tour_create($eventId, $data);
            $ok('Tournée créée. Envoyez son lien au bénévole, puis affectez-lui des ramassages.');
        }
        $go();
        break;
    }

    case 'tour_start':
    case 'tour_finish':
    case 'tour_reopen':
    case 'tour_delete': {
        $t = unsold_tour_get((int) unsold_param($_POST, 'id'));
        if (!$t) { $ko('Tournée introuvable.'); $go(); }
        $tid = (int) $t['id'];
        if ($action === 'tour_start') {
            $ok(unsold_tour_start($tid) ? 'Tournée démarrée.' : 'Cette tournée est déjà démarrée.');
        } elseif ($action === 'tour_finish') {
            $res = unsold_tour_finish($tid, 'admin');
            if ($res) {
                $ok($plural($res['moved'], 'objet déposé', 'objets déposés') . ' au point de collecte'
                    . ($res['released'] ? ' · ' . $plural($res['released'], 'arrêt non visité rendu', 'arrêts non visités rendus') . ' au lot « à affecter »' : '') . '.');
            } else {
                $ko('Cette tournée est déjà terminée.');
            }
        } elseif ($action === 'tour_reopen') {
            $ok(unsold_tour_reopen($tid) ? 'Tournée rouverte.' : 'Cette tournée n\'est pas terminée.');
        } else {
            $ok(unsold_tour_delete($tid) ? 'Tournée supprimée ; ses propositions sont de nouveau à affecter.' : 'Une tournée terminée ne peut pas être supprimée.');
        }
        $go();
        break;
    }

    /* ---------- Reliquat → donnerie ---------- */

    case 'donation_create': {
        $e = unsold_event_get($eventId);
        if (!$e) { $ko('Édition introuvable.'); $go('/admin/invendus.php'); }
        $recipientId = (int) unsold_param($_POST, 'recipient_id');
        $r = unsold_recipient_get($recipientId);
        if (!$r || !$r['is_active']) { $ko('Choisissez la structure qui reçoit les objets.'); $go(); }
        if (!$ids) { $ko('Cochez les objets à remettre.'); $go(); }
        $donationId = unsold_donation_create($eventId, $recipientId, $ids, unsold_param($_POST, 'handed_by'), unsold_param($_POST, 'note'));
        if (!$donationId) { $ko('Aucun objet n\'a pu être remis (déjà traités ?).'); $go(); }
        $ok('Remise enregistrée pour ' . $r['name'] . '. Imprimez le bordereau ci-dessous.');
        $go('/admin/invendus-bordereau.php?id=' . $donationId);
        break;
    }

    /* ---------- Structures (donneries) ---------- */

    case 'recipient_save': {
        $id = (int) unsold_param($_POST, 'id') ?: null;
        [$data, $errors] = unsold_recipient_from_post($_POST);
        if ($errors) { $ko(implode(' ', $errors)); $go('/admin/invendus-structures.php'); }
        if ($id && !unsold_recipient_get($id)) { $ko('Structure introuvable.'); $go('/admin/invendus-structures.php'); }
        unsold_recipient_save($id, $data);
        $ok($id ? 'Structure enregistrée.' : 'Structure ajoutée.');
        $go('/admin/invendus-structures.php');
        break;
    }

    case 'recipient_toggle': {
        db()->prepare('UPDATE unsold_recipients SET is_active = 1 - is_active WHERE id = ?')->execute([(int) unsold_param($_POST, 'id')]);
        $go('/admin/invendus-structures.php');
        break;
    }

    case 'recipient_delete': {
        $res = unsold_recipient_delete((int) unsold_param($_POST, 'id'));
        $ok($res === 'deleted' ? 'Structure supprimée.' : 'Cette structure a déjà reçu des dons : elle est désactivée (l\'historique reste lisible).');
        $go('/admin/invendus-structures.php');
        break;
    }

    default:
        $ko('Action inconnue.');
        $go();
}
