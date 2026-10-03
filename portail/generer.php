<?php
// Génération par IA du contenu de départ d'un nouveau commerce (formulaire
// portail/nouveau.php). Réponses JSON :
//   action=generate  → {ok: true, content: {...}} ou {ok: false, error, code?}
//   action=save_key  → enregistre la clé Gemini du portail
// Protégé comme tout le portail (connexion en ligne) et par le jeton CSRF.

require __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
}
if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
    reply(['ok' => false, 'error' => 'Formulaire expiré : rechargez la page.'], 400);
}

$action = (string) ($_POST['action'] ?? '');

if ($action === 'save_key') {
    $key = trim((string) ($_POST['key'] ?? ''));
    // Les clés Gemini actuelles (AQ.…) contiennent un point, les anciennes (AIza…) non.
    if (!preg_match('/^[A-Za-z0-9._-]{20,200}$/', $key)) {
        reply(['ok' => false, 'error' => 'Cette clé ne ressemble pas à une clé Gemini.']);
    }
    portail_gemini_key_save($key);
    reply(['ok' => true]);
}

if ($action === 'generate') {
    $description = trim((string) ($_POST['description'] ?? ''));
    if (mb_strlen($description) < 10) {
        reply(['ok' => false, 'error' => 'Décrivez votre commerce en une ou deux phrases (thématique, ce que vous vendez, ton souhaité).']);
    }
    $description = mb_substr($description, 0, 1200);
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 60);

    try {
        $raw = portail_gemini_json(portail_shop_content_prompt($description, $name));
    } catch (RuntimeException $ex) {
        if ($ex->getMessage() === 'no_key') {
            reply(['ok' => false, 'code' => 'no_key', 'error' => "Aucune clé Gemini n'est enregistrée sur ce portail."]);
        }
        reply(['ok' => false, 'error' => $ex->getMessage()]);
    }

    $content = portail_shop_content_normalize($raw, $name);
    if (!$content['cats'] || !$content['hero_title']) {
        reply(['ok' => false, 'error' => "L'IA n'a pas produit un contenu complet, réessayez."]);
    }
    reply(['ok' => true, 'content' => $content]);
}

reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
