<?php
// API du module Rendez-vous : toutes les écritures de l'administration (création, créneaux, réponses, confirmation).
// La session, le rôle et le jeton CSRF sont vérifiés par /admin/plugin-api.php avant d'arriver ici.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rdv.php';

$in = plugin_input();
$action = (string) ($in['action'] ?? '');
$pollId = (int) ($in['poll_id'] ?? 0);

switch ($action) {
    case 'create':
        [$id, $errors, $poll] = rdv_create_poll($in);
        if ($errors) plugin_json(['error' => implode(' ', $errors), 'errors' => $errors], 422);
        rdv_flash_set('Consultation créée. Partagez son lien public pour recueillir les disponibilités.');
        plugin_json(['ok' => true, 'id' => $id, 'manage' => rdv_admin_url((int) $id), 'public' => rdv_public_path((string) $poll['token'])]);

    case 'update':
        if (!rdv_poll($pollId)) plugin_json(['error' => 'Cette consultation n\'existe plus.'], 404);
        $errors = rdv_update_poll($pollId, $in);
        if ($errors) plugin_json(['error' => implode(' ', $errors), 'errors' => $errors], 422);
        rdv_flash_set('Annonce mise à jour.');
        plugin_json(['ok' => true]);

    case 'add_slots':
        $poll = rdv_poll($pollId);
        if (!$poll) plugin_json(['error' => 'Cette consultation n\'existe plus.'], 404);
        if (in_array((string) $poll['status'], ['confirmed', 'cancelled'], true)) {
            plugin_json(['error' => 'Réouvrez la consultation avant d\'ajouter des créneaux.'], 422);
        }
        [$added, $errors] = rdv_add_slots($pollId, (string) ($in['slots'] ?? ''));
        if (!$added) plugin_json(['error' => implode(' ', $errors) ?: 'Aucun créneau ajouté.', 'errors' => $errors], 422);
        rdv_flash_set($added . ' créneau' . ($added > 1 ? 'x' : '') . ' ajouté' . ($added > 1 ? 's' : '') . '.'
            . ($errors ? ' ' . implode(' ', $errors) : ''));
        plugin_json(['ok' => true, 'added' => $added, 'errors' => $errors]);

    case 'delete_slot':
        if (!rdv_slot((int) ($in['slot_id'] ?? 0))) plugin_json(['error' => 'Ce créneau n\'existe plus.'], 404);
        rdv_delete_slot((int) $in['slot_id']);
        rdv_flash_set('Créneau supprimé.');
        plugin_json(['ok' => true]);

    case 'delete_poll':
        if (!rdv_poll($pollId)) plugin_json(['error' => 'Cette consultation n\'existe plus.'], 404);
        rdv_delete_poll($pollId);
        rdv_flash_set('Consultation supprimée avec ses réponses.');
        plugin_json(['ok' => true]);

    case 'status':
        $errors = rdv_set_status($pollId, (string) ($in['status'] ?? ''));
        if ($errors) plugin_json(['error' => implode(' ', $errors)], 422);
        $poll = rdv_poll($pollId);
        rdv_flash_set('Consultation ' . mb_strtolower(rdv_status_label((string) $poll['status'])) . '.');
        plugin_json(['ok' => true]);

    case 'confirm':
        $slotId = (int) ($in['slot_id'] ?? 0);
        $errors = rdv_confirm_slot($pollId, $slotId);
        if ($errors) plugin_json(['error' => implode(' ', $errors)], 422);
        $message = 'Créneau confirmé.';
        if (!empty($in['notify'])) {
            $poll = rdv_poll($pollId);
            $result = rdv_notify_confirmation((array) $poll);
            if (isset($result['error'])) {
                $message .= ' ' . $result['error'];
            } elseif ($result['sent'] === 0 && $result['failed'] === 0) {
                $message .= ' Aucun participant à prévenir pour l\'instant.';
            } else {
                $message .= ' ' . $result['sent'] . ' participant' . ($result['sent'] > 1 ? 's' : '') . ' prévenu' . ($result['sent'] > 1 ? 's' : '')
                    . ($result['failed'] ? ' · ' . $result['failed'] . ' envoi(s) en échec' : '') . '.';
            }
        }
        rdv_flash_set($message, empty($in['notify']) ? 'ok' : 'ok');
        plugin_json(['ok' => true]);

    case 'notify':
        $poll = rdv_poll($pollId);
        if (!$poll) plugin_json(['error' => 'Cette consultation n\'existe plus.'], 404);
        $result = rdv_notify_confirmation($poll);
        if (isset($result['error'])) plugin_json(['error' => $result['error']], 422);
        if ($result['sent'] === 0 && $result['failed'] === 0) {
            rdv_flash_set('Aucun participant à prévenir pour l\'instant.', 'warn');
        } else {
            rdv_flash_set($result['sent'] . ' participant' . ($result['sent'] > 1 ? 's' : '') . ' prévenu' . ($result['sent'] > 1 ? 's' : '')
                . ($result['failed'] ? ' · ' . $result['failed'] . ' envoi(s) en échec' : '') . '.', $result['failed'] ? 'warn' : 'ok');
        }
        plugin_json(['ok' => true] + $result);

    case 'delete_answer':
        $answer = rdv_answer((int) ($in['answer_id'] ?? 0));
        if (!$answer || (int) $answer['poll_id'] !== $pollId) plugin_json(['error' => 'Cette réponse n\'existe plus.'], 404);
        rdv_delete_answer((int) $answer['id']);
        rdv_flash_set('Réponse supprimée.');
        plugin_json(['ok' => true]);

    default:
        plugin_json(['error' => 'Action inconnue.'], 422);
}
