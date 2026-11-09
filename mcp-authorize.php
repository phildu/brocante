<?php
// Autorisation OAuth du serveur MCP : le propriétaire (rôle admin de ce commerce) approuve l'accès d'un client (Claude…).
// Sans session d'administration, renvoi vers la connexion puis retour ici. Un client ou une adresse de retour inconnus ne reçoivent
// jamais de redirection (page d'erreur). Voir includes/mcp-oauth.php.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mcp-oauth.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$src = $isPost ? $_POST : $_GET;
$p = [];
foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource'] as $k) {
    $p[$k] = is_string($src[$k] ?? null) ? $src[$k] : '';
}

$page = static function (int $status, string $title, string $html): never {
    http_response_code($status);
    $c = get_content();
    ?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title><?= h($title) ?> — <?= h($c['site_name']) ?></title>
<link rel="stylesheet" href="/assets/style.css">
<style>.mcp-card{max-width:520px;margin:12vh auto;padding:28px;border:1px solid var(--line);background:var(--bg-soft,var(--bg))}.mcp-card h1{font-size:1.5rem;margin:0 0 14px}.mcp-actions{display:flex;gap:10px;margin-top:22px}.mcp-actions form{display:inline}</style>
</head><body><main><div class="mcp-card"><?= $html ?></div></main></body></html><?php
    exit;
};

// Contrôles qui n'autorisent AUCUNE redirection : client et adresse de retour doivent correspondre à ceux enregistrés.
$client = mcp_oauth_client($p['client_id']);
if (!$client || !in_array($p['redirect_uri'], $client['redirect_uris'], true)) {
    $page(400, 'Demande invalide', '<h1>Demande invalide</h1><p>Cette application n\'est pas reconnue ou son adresse de retour est refusée. Recommencez depuis Claude.</p>');
}
$back = static function (array $q) use ($p): never {
    $sep = str_contains($p['redirect_uri'], '?') ? '&' : '?';
    if ($p['state'] !== '') $q['state'] = $p['state'];
    header('Location: ' . $p['redirect_uri'] . $sep . http_build_query($q), true, 303);
    exit;
};
if ($p['response_type'] !== 'code') $back(['error' => 'unsupported_response_type']);
if ($p['code_challenge_method'] !== 'S256' || !mcp_oauth_challenge_ok($p['code_challenge'])) {
    $back(['error' => 'invalid_request', 'error_description' => 'PKCE S256 obligatoire.']);
}
if ($p['resource'] !== '' && rtrim($p['resource'], '/') !== mcp_oauth_resource_url()) {
    $back(['error' => 'invalid_target', 'error_description' => 'Ressource inconnue.']);
}

// Connexion : seul le propriétaire du commerce (rôle admin) peut approuver.
$session = admin_session();
if ($session === null) {
    $self = tenant_base_path() . '/mcp-authorize.php?' . http_build_query($p);
    header('Location: ' . tenant_base_path() . '/admin/login.php?next=' . rawurlencode($self), true, 303);
    exit;
}
if (($session['role'] ?? '') !== 'admin') {
    $page(403, 'Accès refusé', '<h1>Accès refusé</h1><p>Seul l\'administrateur du site peut autoriser un accès MCP.</p>');
}

$name = $client['client_name'] !== '' ? $client['client_name'] : 'Application inconnue';
$host = (string) parse_url($p['redirect_uri'], PHP_URL_HOST);

if ($isPost) {
    shop_require_csrf();
    if (($_POST['decision'] ?? '') !== 'allow') {
        mcp_audit('oauth', null, 'refusé par le propriétaire (' . $name . ')');
        $back(['error' => 'access_denied']);
    }
    $code = mcp_oauth_code_issue($client['client_id'], $p['redirect_uri'], $p['code_challenge']);
    mcp_audit('oauth', null, 'autorisation accordée (' . $name . ')');
    $back(['code' => $code]);
}

$c = get_content();
ob_start();
?>
<h1>Autoriser « <?= h($name) ?> » ?</h1>
<p>Cette application demande à <strong>lire et modifier les données de <?= h($c['site_name']) ?></strong> : produits, textes, réglages, remises, et commandes en lecture seule.</p>
<p class="hint">Retour vers : <strong><?= h($host) ?></strong>. Chaque écriture est sauvegardée et journalisée, rien n'est supprimé définitivement. Vous pouvez retirer l'accès à tout moment dans Administration → Accès MCP.</p>
<div class="mcp-actions">
  <?php foreach (['allow' => ['Autoriser', 'btn'], 'deny' => ['Refuser', 'btn btn-ghost']] as $decision => [$label, $cls]): ?>
  <form method="post" action="<?= h(tenant_base_path() . '/mcp-authorize.php') ?>">
    <?= shop_csrf_input() ?>
    <?php foreach ($p as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
    <input type="hidden" name="decision" value="<?= h($decision) ?>">
    <button type="submit" class="<?= h($cls) ?>"><?= h($label) ?></button>
  </form>
  <?php endforeach; ?>
</div>
<?php
$page(200, 'Autoriser un accès MCP', (string) ob_get_clean());
