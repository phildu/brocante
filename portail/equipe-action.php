<?php
// Écritures de l'équipe du projet (page portail/equipe.php) : fiches membres,
// statuts, suppression, conditions des partenaires, décisions sur les
// candidatures, invitations et réglages du parcours d'inscription.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/equipe.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /portail/equipe.php');
    exit;
}
csrf_check();

$action = (string) ($_POST['action'] ?? '');
$return = static function (string $vue, string $message, string $kind = 'ok', array $params = []): never {
    portail_flash($message, $kind);
    $query = array_merge(['vue' => $vue], $params);
    header('Location: /portail/equipe.php?' . http_build_query($query));
    exit;
};

try {
    switch ($action) {
        case 'member_save': {
            $id = (string) ($_POST['id'] ?? '');
            $existing = $id !== '' ? eq_member_load($id) : null;
            [$member, $errors] = eq_member_from_input([
                'id' => $existing['id'] ?? '',
                'name' => $_POST['name'] ?? '',
                'email' => $_POST['email'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'city' => $_POST['city'] ?? '',
                'category' => $_POST['category'] ?? '',
                'domains' => $_POST['domains'] ?? [],
                'status' => $_POST['status'] ?? 'actif',
                'heures' => $_POST['heures'] ?? '',
                'dispo' => $_POST['dispo'] ?? '',
                'note' => $_POST['note'] ?? '',
                'testeur' => is_array($_POST['testeur'] ?? null) ? $_POST['testeur'] : [],
                'partenaire' => is_array($_POST['partenaire'] ?? null) ? $_POST['partenaire'] : [],
                'source' => $existing['source'] ?? 'portail',
                'invitation' => $existing['invitation'] ?? '',
                'created' => $existing['created'] ?? time(),
            ]);
            if ($errors) $return('membres', implode(' ', $errors), 'error', $existing ? ['edit' => $existing['id']] : ['nouveau' => 1]);
            $saved = eq_member_save($member);
            if (!$saved) $return('membres', "La fiche n'a pas pu être enregistrée (droits d'écriture sur data/equipe).", 'error');
            if (!$existing) eq_event('team_member_added', ['email' => $saved['email'], 'note' => $saved['name'] . ' · ' . EQ_CATEGORIES[$saved['category']]['label']]);
            $return('membres', $existing ? 'Fiche de ' . ($saved['name'] !== '' ? $saved['name'] : $saved['email']) . ' mise à jour.' : ($saved['name'] !== '' ? $saved['name'] : $saved['email']) . ' rejoint l’équipe.');
        }

        case 'member_status': {
            $member = eq_member_load((string) ($_POST['id'] ?? ''));
            if (!$member) $return('membres', 'Membre introuvable.', 'error');
            $member['status'] = (string) ($_POST['status'] ?? '');
            $saved = eq_member_save($member);
            $return('membres', $saved ? 'Statut de ' . ($member['name'] !== '' ? $member['name'] : $member['email']) . ' : ' . EQ_STATUSES[$saved['status']] . '.' : 'Le statut n’a pas pu être enregistré.', $saved ? 'ok' : 'error');
        }

        case 'member_delete': {
            $member = eq_member_load((string) ($_POST['id'] ?? ''));
            if (!$member) $return('membres', 'Membre introuvable.', 'error');
            $ok = eq_member_delete($member['id']);
            $return('membres', $ok ? 'Fiche supprimée.' : 'La fiche n’a pas pu être supprimée.', $ok ? 'ok' : 'error');
        }

        case 'partner_conditions': {
            $member = eq_member_load((string) ($_POST['id'] ?? ''));
            if (!$member) $return('partenaires', 'Partenaire introuvable.', 'error');
            if ($member['category'] !== 'partenaire') $return('partenaires', 'Cette fiche n’est pas un partenaire financier.', 'error');
            $member['partenaire'] = eq_partner_from_input($_POST);
            $saved = eq_member_save($member);
            if ($saved) eq_event('team_partner_conditions', ['email' => $saved['email'], 'note' => $saved['name'] . ' · ' . EQ_PARTNER_STATUSES[$saved['partenaire']['statut']] . ($saved['partenaire']['montant'] !== '' ? ' · ' . $saved['partenaire']['montant'] : '')]);
            $return('partenaires', $saved ? 'Conditions de ' . $saved['name'] . ' enregistrées.' : 'Les conditions n’ont pas pu être enregistrées.', $saved ? 'ok' : 'error');
        }

        case 'application_accept': {
            $application = eq_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            if ($application['status'] !== 'nouvelle') $return('candidatures', 'Cette candidature a déjà été traitée.', 'error');
            $category = isset(EQ_CATEGORIES[(string) ($_POST['category'] ?? '')]) ? (string) $_POST['category'] : $application['category'];
            $partner = $application['category'] === 'partenaire' || $category === 'partenaire'
                ? ['nature' => $application['nature'], 'montant' => $application['montant'], 'echeance' => $application['echeance'], 'statut' => 'a_definir']
                : [];
            [$member, $errors] = eq_member_from_input([
                'name' => $application['name'],
                'email' => $application['email'],
                'phone' => $application['phone'],
                'city' => $application['city'],
                'category' => $category,
                'domains' => $application['domains'],
                'status' => 'actif',
                'source' => 'inscription',
                'invitation' => $application['invitation'],
                'heures' => $application['heures'],
                'dispo' => $application['dispo'],
                'note' => trim("Candidature acceptée le " . date('d/m/Y') . ".\n" . $application['message']),
                'testeur' => ['mode' => $application['mode'], 'outils' => $application['outils']],
                'partenaire' => $partner,
            ]);
            if ($errors) $return('candidatures', implode(' ', $errors), 'error');
            $saved = eq_member_save($member);
            if (!$saved) $return('candidatures', "La fiche n'a pas pu être créée (droits d'écriture sur data/equipe).", 'error');
            eq_application_save(array_merge($application, ['status' => 'acceptee', 'decided' => time(), 'member_id' => $saved['id']]));
            eq_event('team_application_accept', ['email' => $saved['email'], 'note' => $saved['name'] . ' · ' . EQ_CATEGORIES[$saved['category']]['label']]);
            $return('candidatures', ($saved['name'] !== '' ? $saved['name'] : $saved['email']) . ' rejoint l’équipe. Sa fiche est créée dans l’onglet Membres.');
        }

        case 'application_refuse': {
            $application = eq_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            if ($application['status'] !== 'nouvelle') $return('candidatures', 'Cette candidature a déjà été traitée.', 'error');
            $reason = eq_paragraph($_POST['reason'] ?? '', 400);
            eq_application_save(array_merge($application, ['status' => 'refusee', 'decided' => time(), 'reason' => $reason]));
            eq_event('team_application_refuse', ['email' => $application['email'], 'note' => $application['name'] . ($reason !== '' ? ' · ' . $reason : '')]);
            $return('candidatures', 'Candidature de ' . ($application['name'] !== '' ? $application['name'] : $application['email']) . ' refusée.');
        }

        case 'application_delete': {
            $application = eq_application_load((string) ($_POST['id'] ?? ''));
            if (!$application) $return('candidatures', 'Candidature introuvable.', 'error');
            $ok = eq_application_delete($application['id']);
            $return('candidatures', $ok ? 'Candidature retirée de l’historique.' : 'La candidature n’a pas pu être retirée.', $ok ? 'ok' : 'error');
        }

        case 'invitation_create': {
            $invitation = eq_invitation_create($_POST);
            if (!$invitation) $return('invitations', 'Le lien d’invitation n’a pas pu être créé.', 'error');
            eq_event('team_invitation', ['note' => ($invitation['label'] !== '' ? $invitation['label'] : 'sans intitulé') . ' · ' . $invitation['code']]);
            $return('invitations', 'Lien d’inscription créé' . ($invitation['label'] !== '' ? ' pour « ' . $invitation['label'] . ' »' : '') . ' : partagez-le depuis le tableau ci-dessous.');
        }

        case 'invitation_toggle': {
            $code = eq_code_normalize((string) ($_POST['code'] ?? ''));
            $found = null;
            foreach (eq_invitations() as $invitation) {
                if ($invitation['code'] === $code) { $found = $invitation; break; }
            }
            if (!$found) $return('invitations', 'Invitation introuvable.', 'error');
            eq_invitation_save(array_merge($found, ['active' => !$found['active']]));
            $return('invitations', $found['active'] ? 'Invitation désactivée.' : 'Invitation réactivée.');
        }

        case 'invitation_delete': {
            $code = (string) ($_POST['code'] ?? '');
            $ok = eq_invitation_delete($code);
            $return('invitations', $ok ? 'Invitation supprimée.' : 'Invitation introuvable.', $ok ? 'ok' : 'error');
        }

        case 'settings_save': {
            $settings = eq_settings();
            $settings['notify'] = eq_text($_POST['notify'] ?? '', 190);
            if ($settings['notify'] !== '' && !filter_var($settings['notify'], FILTER_VALIDATE_EMAIL)) {
                $return('invitations', "L'adresse de notification n'est pas valide.", 'error');
            }
            $settings['open'] = !empty($_POST['open']);
            $ok = eq_settings_save($settings);
            $return('invitations', $ok ? 'Réglages du parcours d’inscription enregistrés.' : 'Les réglages n’ont pas pu être enregistrés.', $ok ? 'ok' : 'error');
        }

        case 'settings_code': {
            $settings = eq_settings();
            $settings['code'] = strtoupper(bin2hex(random_bytes(4)));
            $ok = eq_settings_save($settings);
            $return('invitations', $ok ? 'Nouveau code d’accès : ' . implode('-', str_split($settings['code'], 4)) . '.' : 'Le code n’a pas pu être changé.', $ok ? 'ok' : 'error');
        }

        default:
            $return('membres', 'Action inconnue.', 'error');
    }
} catch (Throwable $e) {
    portail_flash('L’action a échoué : ' . $e->getMessage(), 'error');
    header('Location: /portail/equipe.php');
    exit;
}
