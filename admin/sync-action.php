<?php
// Actions de la page « Envoyer en préprod » (admin/sync.php).
//   target  → enregistre l'adresse du site distant et sa clé (formulaire)
//   receive → génère / révoque la clé de réception de CE site (formulaire)
//   status  → état du site distant (JSON)
//   push    → envoie une pièce (JSON)

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sync.php';

$action = (string) ($_POST['action'] ?? '');
$isJson = in_array($action, ['status', 'push'], true);

if (!admin_access_ok()) {
    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.']);
        exit;
    }
    require_admin();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/sync.php');
    exit;
}

function sync_json(array $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

switch ($action) {
    case 'target': {
        $url = trim((string) ($_POST['url'] ?? ''));
        $key = trim((string) ($_POST['key'] ?? ''));
        if ($key === '' && ($current = sync_target())) $key = $current['key']; // clé inchangée si laissée vide
        if (!preg_match('#^https?://[^\s/]+#i', $url)) {
            flash_set('Adresse invalide : elle doit commencer par https:// (ex. https://preprod.mon-site.fr).', 'error');
        } elseif ($key === '') {
            flash_set('Collez la clé de réception générée sur le site distant.', 'error');
        } else {
            // On ne garde que l'adresse du site (sans /admin/… collé par erreur).
            $url = preg_replace('#/admin(/.*)?$#', '', rtrim($url, '/'));
            sync_save_target($url, $key);
            flash_set('Destination enregistrée.');
        }
        header('Location: /admin/sync.php');
        exit;
    }

    case 'receive': {
        if (!empty($_POST['revoke'])) {
            @unlink(sync_receive_key_file());
            flash_set('Réception désactivée : ce site n\'accepte plus d\'envois.');
        } else {
            $_SESSION['sync_new_key'] = sync_generate_receive_key();
            flash_set('Nouvelle clé générée. Copiez-la maintenant : elle ne sera plus affichée.');
        }
        header('Location: /admin/sync.php#recevoir');
        exit;
    }

    case 'status': {
        $target = sync_target();
        if (!$target) sync_json(['ok' => false, 'error' => 'Aucune destination enregistrée.']);
        sync_json(sync_remote_call($target, ['action' => 'status']));
    }

    case 'push': {
        $target = sync_target();
        if (!$target) sync_json(['ok' => false, 'error' => 'Aucune destination enregistrée.']);
        @set_time_limit(0);
        session_write_close(); // ne bloque pas les autres onglets pendant l'envoi
        sync_json(sync_push_product($target, (string) ($_POST['ref'] ?? ''), (string) ($_POST['mode'] ?? 'update')));
    }
}

header('Location: /admin/sync.php');
