<?php
// Routes publiques : /api/chineur/abonner (POST), /api/chineur/confirmer/<jeton>, /api/chineur/stop/<jeton>.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ac.php';

if (!addon_is_entitled(AC_MODULE)) plugin_json(['error' => "Le service d'alertes n'est pas disponible pour le moment."], 503);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$page = static function (string $title, string $text): never {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $h = static fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>' . $h($title) . '</h1><p>' . $h($text) . '</p><p><a href="' . $h(shop_abs_url('/boutique.php')) . '">Retour à la boutique</a></p></body></html>';
    exit;
};

if ($agentPath === '/chineur/abonner' && $method === 'POST') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $in = is_array($in) ? $in : $_POST;
    if (!empty($in['website'])) plugin_json(['ok' => true, 'message' => 'Presque fini : un e-mail de confirmation vient de vous être envoyé.']);   // champ piège rempli par un robot
    [$msg, $err] = ac_subscribe((string) ($in['email'] ?? ''), (string) ($in['keywords'] ?? ''), (string) ($in['max_price'] ?? ''), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($err) plugin_json(['error' => $err], 422);
    plugin_json(['ok' => true, 'message' => $msg]);
}
if (preg_match('#^/chineur/confirmer/([a-f0-9]{32})$#', $agentPath, $m)) {
    $st = db()->prepare("UPDATE ac_alerts SET status = 'active', confirmed_at = CURRENT_TIMESTAMP WHERE token = ? AND status = 'pending'");
    $st->execute([$m[1]]);
    $exists = db()->prepare("SELECT status FROM ac_alerts WHERE token = ?");
    $exists->execute([$m[1]]);
    $status = $exists->fetchColumn();
    if ($status === 'active') $page('Alerte activée', "C'est confirmé : vous recevrez un e-mail dès qu'une pièce correspondante sera mise en ligne.");
    $page('Lien invalide', "Ce lien n'est plus valide (alerte supprimée ou introuvable).");
}
if (preg_match('#^/chineur/stop/([a-f0-9]{32})$#', $agentPath, $m)) {
    db()->prepare("UPDATE ac_alerts SET status = 'stopped' WHERE token = ?")->execute([$m[1]]);
    $page('Alerte supprimée', 'Vous ne recevrez plus de message pour cette alerte.');
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
