<?php
// Prompts enregistrés (« Mots-clés / précisions ») : liste, ajout, suppression. Réponses JSON.
//   list   → tous les prompts du commerce
//   save   → kind + text
//   delete → id
// Ouvert aux rôles qui utilisent la génération IA (voir ACCOUNT_COMMUNITY_MANAGER_PAGES).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function prompts_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) {
    prompts_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    prompts_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}

$action = (string) ($_POST['action'] ?? 'list');
$result = ['ok' => true];
switch ($action) {
    case 'list':
        break;
    case 'save':
        $result = saved_prompt_add((string) ($_POST['kind'] ?? ''), (string) ($_POST['text'] ?? ''));
        break;
    case 'delete':
        saved_prompt_delete((int) ($_POST['id'] ?? 0));
        break;
    default:
        prompts_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
}
prompts_reply($result + ['prompts' => saved_prompts_list()]);
