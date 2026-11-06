<?php
// Administration → Accès MCP : créer / révoquer le jeton du serveur MCP (/mcp.php) et consulter son journal. Réservé au rôle admin.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mcp.php';
require_admin();

$newToken = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    shop_require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $newToken = mcp_token_create();   // affiché une seule fois ci-dessous, jamais stocké en clair
        mcp_audit('token', null, 'jeton créé');
    } elseif ($action === 'revoke') {
        mcp_token_revoke();
        mcp_audit('token', null, 'jeton révoqué');
        flash_set('Accès MCP désactivé : le jeton ne fonctionne plus.');
        header('Location: /admin/mcp.php');
        exit;
    }
}

mcp_ensure_schema();
$content = get_content();
$flash = flash_get();
$active = mcp_token_active();
$created = shop_setting('mcp_token_created', '');
$log = db()->query("SELECT at, ip, tool, outcome FROM mcp_audit ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$endpoint = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/mcp.php';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Accès MCP — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .mcp-token { display:block; margin:12px 0; padding:12px; background:var(--bg); border:1px solid var(--line); font-family:monospace; word-break:break-all; user-select:all; }
  .mcp-cmd { white-space:pre-wrap; word-break:break-all; background:var(--bg); border:1px solid var(--line); padding:12px; font-family:monospace; font-size:0.85rem; }
  table.mcp-log { width:100%; border-collapse:collapse; font-size:0.85rem; margin-top:12px; }
  table.mcp-log th, table.mcp-log td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'mcp'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Réglages</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Accès MCP (assistant IA)</h1>
    <p class="hint">
      Permet à un assistant (Claude Code…) de lire et modifier les données de ce site : produits, textes, réglages, remises ; commandes en lecture seule.
      Chaque écriture est précédée d'une <strong>sauvegarde de la base</strong> et <strong>journalisée</strong> ; rien n'est supprimé définitivement.
      Le jeton donne tous ces droits : ne le partagez pas, révoquez-le s'il a fuité.
    </p>

    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p><?php endif; ?>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Jeton</h2>
      <?php if ($newToken): ?>
        <p><strong>Copiez ce jeton maintenant : il ne sera plus jamais affiché.</strong> L'ancien jeton, s'il existait, ne fonctionne plus.</p>
        <code class="mcp-token"><?= h($newToken) ?></code>
        <p>Commande à lancer sur votre ordinateur :</p>
        <div class="mcp-cmd">claude mcp add --transport http brocante <?= h($endpoint) ?> --header "Authorization: Bearer <?= h($newToken) ?>"</div>
      <?php elseif ($active): ?>
        <p>Accès <strong>actif</strong><?= $created !== '' ? ' (jeton créé le ' . h($created) . ' UTC)' : '' ?>. Adresse : <code><?= h($endpoint) ?></code></p>
      <?php else: ?>
        <p>Accès <strong>désactivé</strong> : aucun jeton.</p>
      <?php endif; ?>
      <form method="post" style="display:inline-block;margin-right:10px;">
        <?= shop_csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <button type="submit" class="btn" <?= $active ? 'onclick="return confirm(\'Remplacer le jeton ? L\\\'ancien cessera de fonctionner.\')"' : '' ?>><?= $active ? 'Nouveau jeton' : 'Créer un jeton' ?></button>
      </form>
      <?php if ($active): ?>
      <form method="post" style="display:inline-block;" onsubmit="return confirm('Désactiver l\'accès MCP ?');">
        <?= shop_csrf_input() ?>
        <input type="hidden" name="action" value="revoke">
        <button type="submit" class="btn btn-ghost">Révoquer</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="admin-block" style="margin-top:20px;">
      <h2 style="font-size:1.1rem;">Journal (30 derniers événements)</h2>
      <?php if (!$log): ?><p class="empty-state">Aucune activité.</p><?php else: ?>
      <table class="mcp-log"><thead><tr><th>Date (UTC)</th><th>IP</th><th>Outil</th><th>Résultat</th></tr></thead><tbody>
        <?php foreach ($log as $r): ?><tr><td><?= h($r['at']) ?></td><td><?= h($r['ip']) ?></td><td><?= h($r['tool']) ?></td><td><?= h($r['outcome']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
  </div>
</section>
</main>
</body>
</html>
