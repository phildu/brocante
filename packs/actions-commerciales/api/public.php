<?php
// Route publique des annonces : /api/braderie/stop/<jeton> (désinscription depuis un e-mail d'opération).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';

$page = static function (string $title, string $text): never {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    $h = static fn ($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $h($title) . '</title>'
        . '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:560px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>' . $h($title) . '</h1><p>' . $h($text) . '</p><p><a href="' . $h(shop_abs_url('/boutique.php')) . '">Retour à la boutique</a></p></body></html>';
    exit;
};

if (preg_match('#^/braderie/stop/([a-f0-9]{32})$#', $agentPath, $m)) {
    db()->prepare("UPDATE aco_contacts SET state = 'stopped', stopped_at = CURRENT_TIMESTAMP WHERE token = ? AND state = 'active'")
        ->execute([$m[1]]);
    $st = db()->prepare('SELECT state FROM aco_contacts WHERE token = ?');
    $st->execute([$m[1]]);
    if ($st->fetchColumn() === 'stopped') {
        $page('Annonces désactivées', "C'est noté : vous ne recevrez plus les annonces de cette boutique. Vous pouvez toujours suivre ses nouveautés sur le site.");
    }
    $page('Lien invalide', "Ce lien de désinscription n'est pas valide.");
}
plugin_json(['error' => 'Adresse inconnue.'], 404);
