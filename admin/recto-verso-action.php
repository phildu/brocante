<?php
// Recto / verso automatique d'une pièce (galerie). Réponses JSON ; une étape par appel.
//   plan     → ref : photos de départ, celles déjà détourées
//   cutout   → ref + photo_id : détoure cette photo de départ (fal.ai, sinon Gemini)
//   generate → ref + side (recto|verso) [+ keywords] : envoie tous les détourages à l'IA
// Voir includes/recto-verso.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rv_reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!admin_access_ok()) rv_reply(['ok' => false, 'error' => 'Session expirée : reconnectez-vous.'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rv_reply(['ok' => false, 'error' => 'Méthode non autorisée.'], 405);
if (!hash_equals(admin_csrf_token(), (string) ($_POST['csrf'] ?? ''))) rv_reply(['ok' => false, 'error' => 'Page expirée : rechargez-la et recommencez.'], 400);
if (in_array((string) ($_POST['action'] ?? ''), ['cutout', 'generate'], true) && ($gate = ai_gate())) rv_reply(['ok' => false, 'quota' => true, 'error' => $gate]);
session_write_close();
@set_time_limit(150);

$ref = (string) ($_POST['ref'] ?? '');
$product = get_product($ref);
if (!$product) rv_reply(['ok' => false, 'error' => 'Pièce introuvable.'], 404);
ai_usage_context($ref);

switch ((string) ($_POST['action'] ?? '')) {
    case 'plan': {
        $sources = array_map(static function (array $s) use ($ref): array {
            $cut = recto_verso_cutout_of($ref, (int) $s['id']);
            return ['id' => (int) $s['id'], 'label' => $s['label'], 'done' => (bool) $cut];
        }, recto_verso_sources($ref));
        rv_reply(['ok' => true, 'sources' => $sources, 'ai' => (bool) GEMINI_API_KEY]);
    }
    case 'cutout': {
        $r = recto_verso_make_cutout($ref, (int) ($_POST['photo_id'] ?? 0));
        rv_reply($r['ok'] ? ['ok' => true, 'path' => $r['cutout']['path'], 'already' => !empty($r['already'])] : $r);
    }
    case 'generate': {
        $side = ($_POST['side'] ?? '') === 'verso' ? 'verso' : 'recto';
        rv_reply(recto_verso_generate($ref, $side, trim(mb_substr((string) ($_POST['keywords'] ?? ''), 0, 300))));
    }
}
rv_reply(['ok' => false, 'error' => 'Action inconnue.'], 400);
