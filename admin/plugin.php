<?php
// Page d'administration d'un plugin : /admin/plugin.php?p=<plugin>&v=<page> (voir includes/plugins.php).
// Le plugin doit être actif et à jour dans CETTE boutique, et la page ouverte au rôle du compte connecté.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plugins.php';
require_admin();

$content = get_content();
$role = admin_role();
$plugin = plugin_manifest((string) ($_GET['p'] ?? ''));
$status = $plugin ? plugin_shop_status($plugin['id']) : 'absent';
$pluginPage = $plugin ? ($plugin['pages'][(string) ($_GET['v'] ?? '')] ?? reset($plugin['pages']) ?: null) : null;

if (!$plugin || !$pluginPage || !in_array($status, ['ready', 'update', 'newer'], true)) {
    http_response_code(404);
    $problem = "Ce module n'est pas activé pour votre boutique.";
} elseif (!plugin_role_allowed($pluginPage['roles'], $role)) {
    admin_forbidden($role);
} elseif ($status !== 'ready') {
    http_response_code(503);
    $problem = $status === 'update'
        ? 'Ce module vient d\'être mis à jour : il reprendra dès que la plateforme aura appliqué la mise à jour à votre boutique.'
        : 'Ce module est momentanément indisponible (version de la boutique plus récente que celle du site).';
}
$title = isset($problem) ? 'Module indisponible' : $pluginPage['label'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($title) ?> — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = $plugin && $pluginPage ? 'plugin:' . $plugin['id'] . ':' . $pluginPage['id'] : ''; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
<?php if (isset($problem)): ?>
    <p class="eyebrow">Module</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;"><?= h($title) ?></h1>
    <p class="hint"><?= h($problem) ?></p>
<?php else:
    // Variables offertes à la page du plugin : $plugin (manifeste), $pluginPage, $role, et les adresses utiles.
    define('PLUGIN_RUNTIME', true);
    $pluginApi = static fn (string $route): string => plugin_api_url($plugin['id'], $route);
    $pluginAsset = static fn (string $file): string => plugin_asset_url($plugin, $file);
    $pluginCsrf = admin_csrf_token();
    (static function () use ($plugin, $pluginPage, $role, $content, $pluginApi, $pluginAsset, $pluginCsrf): void {
        include $plugin['dir'] . '/' . $pluginPage['file'];
    })();
endif; ?>
  </div>
</section>
</main>
</body>
</html>
