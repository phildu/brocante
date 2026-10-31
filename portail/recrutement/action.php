<?php
// Écritures du recrutement : besoins, statuts des candidatures, notes
// internes, passage d’un primo aidant vers le module Équipe et réglages.
require __DIR__ . '/../_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/recrutement.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portail/recrutement/');
    exit;
}
csrf_check();

$action = (string) ($_POST['action'] ?? '');
$return = static function (string $vue, string $message, string $kind = 'ok', array $params = []): never {
    portail_flash($message, $kind);
    $query = array_merge(['vue' => $vue], $params);
    header('Location: /portail/recrutement/?' . http_build_query($query));
    exit;
};

try {
    switch ($action) {
        case 'offer_save': {
            $id = (string) ($_POST['id'] ?? '');
            $existing = $id !== '' ? rc_offer_load($id) : null;
            if ($id !== '' && !$existing) $return('postes', 'Besoin introuvable.', 'error');
            [$offer, $errors] = rc_offer_from_input($_POST, $existing);
            if ($errors) {
                $return('postes', implode(' ', $errors), 'error', $existing ? ['edit' => $existing['id']] : ['nouveau' => 1]);
            }
            $saved = rc_offer_save($offer);
            if (!$saved) $return('postes', "Le besoin n'a pas pu être enregistré (droits d'écriture sur data/recrutement).", 'error', $existing ? ['edit' => $existing['id']] : ['nouveau' => 1]);
            rc_event($existing ? 'recruitment_offer_updated' : 'recruitment_offer_created', [
                'note' => $saved['title'] . ' · ' . rc_track_label($saved['track']) . ' · ' . $saved['code'],
            ]);
            $return('postes', ($existing ? 'Besoin « ' . $saved['title'] . ' » mis à jour.' : 'Besoin « ' . $saved['title'] . ' » publié.') . ' Lien public : ' . $saved['code'] . '.');
        }

        case 'offer_status': {
            $offer = rc_offer_load((string) ($_POST['id'] ?? ''));
            if (!$offer) $return('postes', 'Besoin introuvable.', 'error');
            $offer['status'] = rc_choice($_POST['status'] ?? '', RC_OFFER_STATUSES, 'ouvert');
            $saved = rc_offer_save($offer);
            $return('postes', $saved ? '« ' . $saved['title'] . ' » : ' . RC_OFFER_STATUSES[$saved['status']] . '.' : 'Le statut n’a pas pu être enregistré.', $saved ? 'ok' : 'error');
        }

        case 'offer_delete': {
            $offer = rc_offer_load((string) ($_POST['id'] ?? ''));
            if (!$offer) $return('postes', 'Besoin introuvable.', 'error');
            $ok = rc_offer_delete($offer['id']);
            $return('postes', $ok ? 'Besoin supprimé. Les candidatures reçues sont conservées.' : 'Le besoin n’a pas pu être supprimé.', $ok ? 'ok' : 'error');
        }

        case 'application_status': {
            $application = rc_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            $status = rc_choice($_POST['status'] ?? '', RC_STATUSES, 'recue');
            $application['status'] = $status;
            if (in_array($status, RC_FINAL_STATUSES, true)) $application['decided'] = time();
            $saved = rc_application_save($application);
            if (!$saved) $return('candidatures', 'Le statut n’a pas pu être enregistré.', 'error');
            rc_event('recruitment_application_status', [
                'email' => $saved['email'],
                'note' => $saved['name'] . ' · ' . rc_status_label($saved['status'], $saved['track']),
            ]);
            $return('candidatures', ($saved['name'] !== '' ? $saved['name'] : $saved['email']) . ' : ' . rc_status_label($saved['status'], $saved['track']) . '.');
        }

        case 'application_note': {
            $application = rc_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            $text = rc_paragraph($_POST['note'] ?? '', 1500);
            if ($text === '') $return('candidatures', 'La note est vide.', 'error');
            $application['notes'][] = ['ts' => time(), 'text' => $text];
            $saved = rc_application_save($application);
            $return('candidatures', $saved ? 'Note ajoutée à la candidature de ' . ($saved['name'] !== '' ? $saved['name'] : $saved['email']) . '.' : 'La note n’a pas pu être enregistrée.', $saved ? 'ok' : 'error');
        }

        case 'application_delete': {
            $application = rc_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            $ok = rc_application_delete($application['id']);
            $return('candidatures', $ok ? 'Candidature supprimée.' : 'La candidature n’a pas pu être supprimée.', $ok ? 'ok' : 'error');
        }

        case 'application_to_team': {
            $application = rc_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            if ($application['track'] !== 'primo') $return('candidatures', 'Seule la piste primo aidants peut rejoindre le module Équipe.', 'error');
            if ($application['team_sent']) $return('candidatures', 'Cette candidature a déjà été envoyée à l’équipe.', 'error');
            if (!is_file(PORTAIL_ROOT . '/includes/equipe.php')) $return('candidatures', 'Le module Équipe n’est pas déployé sur ce serveur : la candidature ne peut pas encore lui être envoyée.', 'error');
            require_once PORTAIL_ROOT . '/includes/equipe.php';
            if (!function_exists('eq_application_save')) $return('candidatures', 'Le module Équipe est indisponible.', 'error');

            $map = [
                'design' => 'design', 'fonctionnel' => 'dev', 'developpement' => 'dev',
                'tests' => 'dev', 'financement' => 'autre', 'communication' => 'marketing',
                'logistique' => 'autre', 'accueil' => 'autre', 'autre' => 'autre',
            ];
            $domains = [];
            foreach ($application['domains'] as $domain) {
                $mapped = $map[$domain] ?? 'autre';
                if (!in_array($mapped, $domains, true)) $domains[] = $mapped;
            }
            $category = in_array('financement', $application['domains'], true) ? 'partenaire'
                : (in_array('tests', $application['domains'], true) ? 'testeur' : 'conseiller');
            $teamApplication = eq_application_save([
                'name' => $application['name'],
                'first' => $application['first'],
                'last' => $application['last'],
                'email' => $application['email'],
                'phone' => $application['phone'],
                'city' => $application['city'],
                'category' => $category,
                'domains' => $domains,
                'mode' => 'reel',
                'heures' => $application['availability'],
                'dispo' => '',
                'message' => trim("Envoyé depuis le recrutement le " . date('d/m/Y') . ".\n" . $application['motivation']),
                'nature' => 'a_definir',
                'source' => 'recrutement',
                'status' => 'nouvelle',
                'created' => time(),
            ]);
            if (!$teamApplication) $return('candidatures', 'La candidature n’a pas pu être envoyée à l’équipe.', 'error');
            $application['team_sent'] = true;
            $application['team_ref'] = $teamApplication['id'];
            if (in_array($application['status'], ['retenue', 'integree'], true)) {
                $application['status'] = 'integree';
                $application['decided'] = time();
            }
            rc_application_save($application);
            rc_event('recruitment_application_to_team', [
                'email' => $application['email'],
                'note' => $application['name'] . ' · ' . EQ_CATEGORIES[$teamApplication['category']]['label'],
            ]);
            $return('candidatures', ($application['name'] !== '' ? $application['name'] : $application['email']) . ' a été envoyé au module Équipe. Sa candidature y attend une décision.', 'ok');
        }

        case 'settings_save': {
            $ok = rc_settings_save([
                'notify' => $_POST['notify'] ?? '',
                'open' => !empty($_POST['open']),
                'intro' => $_POST['intro'] ?? '',
            ]);
            $return('reglages', $ok ? 'Réglages du recrutement enregistrés.' : "L'adresse de notification n'est pas valide.", $ok ? 'ok' : 'error');
        }

        default:
            $return('synthese', 'Action inconnue.', 'error');
    }
} catch (Throwable $e) {
    portail_flash('L’action a échoué : ' . $e->getMessage(), 'error');
    header('Location: /portail/recrutement/');
    exit;
}
