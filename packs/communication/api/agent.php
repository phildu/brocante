<?php
// Agent de rendu de la Vidéo IA (script Remotion sur une machine de l'équipe) : il interroge la boutique avec son jeton (en-tête
// X-Worker-Token, créé par l'administrateur dans la fenêtre Vidéo IA, rangé haché) et renvoie le MP4 rendu. Seules les deux routes
// de l'agent d'electroboy80 sont ouvertes ici : GET /video-jobs/next et POST /video-jobs/{id}/result ; le jeton est vérifié par
// eb80/api/video-jobs.php (vjRequireWorker). Entrée : plugin-agent.php (/api/video-jobs/…), sans session.
defined('PLUGIN_RUNTIME') || exit;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ok = ($agentPath === '/video-jobs/next' && $method === 'GET') || (preg_match('#^/video-jobs/\\d+/result$#', $agentPath) && $method === 'POST');
if (!$ok) plugin_json(['error' => 'Adresse inconnue.'], 404);
$_SERVER['EB80_API_PATH'] = $agentPath;
$_GET = [];
$GLOBALS['eb80_session'] = null; // aucune session : les routes de l'administration répondraient 401
require __DIR__ . '/../eb80/api/video-jobs.php';
exit;
