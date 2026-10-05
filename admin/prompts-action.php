<?php
// Prompts enregistrés (« Mots-clés / précisions ») : liste, ajout, suppression. Réponses JSON.
//   list   → tous les prompts du commerce
//   save   → kind + text
//   delete → id
//   preview → kind (+ text, angle) : le prompt réellement envoyé à l'IA, en blocs
//   suggest → kind + ref (+ photo_id) : idées de décors / situations d'après la photo de la pièce (IA)
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
    case 'preview':
        $parts = prompt_preview_parts((string) ($_POST['kind'] ?? ''), mb_substr((string) ($_POST['text'] ?? ''), 0, SAVED_PROMPT_MAX_LENGTH), (string) ($_POST['angle'] ?? 'auto'));
        if ($parts === null) prompts_reply(['ok' => false, 'error' => 'Type de prompt inconnu.'], 400);
        prompts_reply(['ok' => true, 'parts' => $parts]);
    case 'suggest':
        if (!GEMINI_API_KEY) prompts_reply(['ok' => false, 'error' => 'Clé Gemini non configurée (Réglages du site).']);
        $product = get_product((string) ($_POST['ref'] ?? ''));
        if (!$product) prompts_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);
        $root = realpath(__DIR__ . '/..');
        $photoId = (int) ($_POST['photo_id'] ?? 0);
        $path = null;
        if ($photoId) {
            $stmt = db()->prepare("SELECT path FROM product_photos WHERE id = ? AND product_ref = ? AND type = 'photo'");
            $stmt->execute([$photoId, $product['ref']]);
            $path = $stmt->fetchColumn() ?: null;
        }
        $path ??= product_source_photos($product['ref'])[0]['path'] ?? ($product['photo'] ?: null);
        if (!$path || !is_file($root . '/' . $path)) prompts_reply(['ok' => false, 'error' => "Cette pièce n'a pas de photo à analyser."]);
        session_write_close();
        @set_time_limit(90);
        $ideas = prompt_suggestions_from_photo($root . '/' . $path, (string) ($_POST['kind'] ?? 'ambiance'));
        if (!$ideas) prompts_reply(['ok' => false, 'error' => "L'IA n'a pas pu proposer d'idées (service surchargé ?) : réessayez."]);
        prompts_reply(['ok' => true, 'ideas' => $ideas]);
    default:
        prompts_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
}
prompts_reply($result + ['prompts' => saved_prompts_list()]);
