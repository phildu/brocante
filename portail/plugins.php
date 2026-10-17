<?php
// Plugins de la plateforme (packs/<id>/, voir includes/plugins.php) : catalogue, situation dans chaque boutique de ce serveur,
// activer / désactiver / mettre à jour une boutique, ou mettre à jour d'un clic toutes celles qui l'ont. Chaque action est
// journalisée (portail → Activité) ; une mise à jour sauvegarde d'abord la base de la boutique dans data/backups/.
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/plugins.php';

$shops = tenant_list();
$catalog = plugin_catalog();

/** Situation d'un plugin dans une boutique : ['status', 'row', 'error']. */
function plugins_shop_situation(string $slug, array $m): array
{
    try {
        [$pdo] = plugin_open_tenant_db($slug);
        $row = plugin_states($pdo)[$m['id']] ?? null;
        return ['status' => plugin_status($row, $m), 'row' => $row, 'error' => null];
    } catch (Throwable $e) {
        return ['status' => 'nobase', 'row' => null, 'error' => $e->getMessage()];
    }
}

/** Exécute une action sur une boutique ; renvoie le message (succès) ou lève une exception. */
function plugins_apply(string $action, string $slug, array $m): string
{
    [$pdo, $file] = plugin_open_tenant_db($slug);
    $label = '« ' . $m['name'] . ' » pour « ' . $slug . ' »';
    if ($action === 'disable') {
        plugin_disable($pdo, $m['id']);
        saas_event('plugin_disabled', ['slug' => $slug, 'note' => $m['name'] . ' (données conservées)']);
        return "Plugin $label désactivé (ses données sont conservées).";
    }
    $r = $action === 'enable' ? plugin_enable($pdo, $file, $m) : plugin_install($pdo, $file, $m);
    $what = $r['from'] === $r['to'] ? 'v' . $r['to'] : ($r['from'] !== '' ? 'v' . $r['from'] . ' → v' . $r['to'] : 'v' . $r['to']);
    $note = $m['name'] . ' ' . $what . ($r['applied'] ? ' (' . $r['applied'] . ' migration' . ($r['applied'] > 1 ? 's' : '') . ', sauvegarde ' . basename((string) $r['backup']) . ')' : '');
    saas_event($action === 'enable' ? 'plugin_enabled' : 'plugin_updated', ['slug' => $slug, 'note' => $note]);
    return ($action === 'enable' ? 'Plugin activé : ' : 'Plugin mis à jour : ') . $label . ', ' . $what . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $m = $catalog[(string) ($_POST['plugin'] ?? '')] ?? null;
    $slug = (string) ($_POST['slug'] ?? '');
    if (!$m) {
        portail_flash('Plugin introuvable.', 'error');
    } elseif (in_array($action, ['enable', 'disable', 'update'], true)) {
        if (!isset($shops[$slug])) {
            portail_flash('Boutique introuvable.', 'error');
        } else {
            try {
                portail_flash(plugins_apply($action, $slug, $m));
            } catch (Throwable $e) {
                saas_event('plugin_failed', ['slug' => $slug, 'note' => $m['name'] . ' (' . $action . ') : ' . $e->getMessage()]);
                portail_flash('Échec pour « ' . $slug . ' » : ' . $e->getMessage() . '. La base n\'a pas été modifiée.', 'error');
            }
        }
    } elseif ($action === 'update_all') {
        $ok = [];
        $failed = [];
        foreach ($shops as $s => $cfg) {
            if (plugins_shop_situation($s, $m)['status'] !== 'update') continue;
            try {
                plugins_apply('update', $s, $m);
                $ok[] = $s;
            } catch (Throwable $e) {
                saas_event('plugin_failed', ['slug' => $s, 'note' => $m['name'] . ' (mise à jour) : ' . $e->getMessage()]);
                $failed[] = $s . ' (' . $e->getMessage() . ')';
            }
        }
        portail_flash($ok || $failed
            ? '« ' . $m['name'] . ' » v' . $m['version'] . ' : ' . count($ok) . ' boutique(s) mise(s) à jour' . ($failed ? ' ; échec : ' . implode(', ', $failed) : '') . '.'
            : 'Aucune boutique à mettre à jour.', $failed ? 'error' : 'ok');
    } else {
        portail_flash('Action inconnue.', 'error');
    }
    header('Location: /portail/plugins.php' . ($m ? '#plugin-' . $m['id'] : ''));
    exit;
}
$flash = portail_flash();
$tags = ['absent' => '', 'disabled' => 'warn', 'ready' => 'ok', 'update' => 'warn', 'newer' => 'bad', 'nobase' => ''];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Plugins — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1100px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 12px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.05rem; } .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; font-family: 'IBM Plex Mono', monospace; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; }
  table.t { width: 100%; border-collapse: collapse; font-size: .88rem; } .t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 5px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } .t tr:last-child td { border-bottom: 0; }
  .scroll { overflow-x: auto; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; border: 1px solid var(--line); } .tag.bad { color: #b3261e; border-color: currentColor; } .tag.warn { color: #9a6700; border-color: currentColor; } .tag.ok { color: var(--ok); }
  .acts { display: flex; gap: 6px; flex-wrap: wrap; } .acts form { margin: 0; }
  @media (max-width: 640px) {
    .t thead { display: none; } .t tr { display: grid; gap: 6px; padding: 10px 0; border-bottom: 1px solid var(--line); } .t tr:last-child { border-bottom: 0; } .t td { border: 0; padding: 0; }
  }
  .head { display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand"><h1>Plugins</h1><p>Modules ajoutés aux boutiques : un seul code pour toutes, activé boutique par boutique</p></div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">← Portail des commerces</a><a class="btn" href="/portail/activite.php">Activité</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <p class="hint">Le code d'un plugin arrive avec le déploiement (dossier <code>packs/</code>). Ses données vivent dans la base de chaque boutique.
      <strong>Désactiver</strong> retire son menu et ses pages, sans rien effacer. Quand une nouvelle version est déployée, le plugin reste en pause
      dans les boutiques qui l'ont jusqu'à <strong>Mettre à jour</strong> : la base de la boutique est d'abord sauvegardée dans <code>data/backups/</code>.</p>

    <?php if (!$catalog): ?>
      <section class="ocard"><p class="hint">Aucun plugin dans <code>packs/</code>.</p></section>
    <?php endif; ?>

    <?php foreach ($catalog as $id => $m):
        $rows = [];
        foreach ($shops as $slug => $cfg) $rows[$slug] = plugins_shop_situation($slug, $m);
        $toUpdate = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'update'));
        $active = count(array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['ready', 'update', 'newer'], true)));
    ?>
      <section class="ocard" id="plugin-<?= e($id) ?>">
        <div class="head">
          <h2><?= e($m['name']) ?> <small><?= e($id) ?> · v<?= e($m['version']) ?> · <?= count($m['migrations']) ?> migration(s)</small></h2>
          <?php if ($toUpdate): ?>
            <form method="post" action="/portail/plugins.php">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="update_all">
              <input type="hidden" name="plugin" value="<?= e($id) ?>">
              <button type="submit" class="btn btn-primary">Mettre à jour les <?= $toUpdate ?> boutique(s) en retard</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($m['description'] !== ''): ?><p class="hint"><?= e($m['description']) ?></p><?php endif; ?>
        <p class="hint">Actif dans <?= $active ?> boutique(s) sur <?= count($shops) ?>.
          Pages : <?= e(implode(', ', array_map(static fn (array $p): string => $p['label'] . ' (' . implode(', ', array_map(static fn (string $r): string => $r === 'admin' ? 'administrateur' : 'community manager', $p['roles'])) . ')', $m['pages'])) ?: 'aucune') ?>.</p>
        <div class="scroll">
          <table class="t">
            <thead><tr><th>Boutique</th><th>Situation</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $slug => $r): ?>
              <tr>
                <td><?= e((string) ($shops[$slug]['name'] ?? $slug)) ?> <span class="hint"><?= e($slug) ?></span></td>
                <td><span class="tag <?= e($tags[$r['status']] ?? '') ?>"><?= e($r['status'] === 'nobase' ? 'Base pas encore créée' : plugin_status_label($r['status'], $r['row'], $m)) ?></span></td>
                <td>
                  <div class="acts">
                  <?php
                    $buttons = match ($r['status']) {
                        'absent', 'disabled' => ['enable' => 'Activer'],
                        'ready' => ['disable' => 'Désactiver'],
                        'update' => ['update' => 'Mettre à jour', 'disable' => 'Désactiver'],
                        'newer' => ['disable' => 'Désactiver'],
                        default => [],
                    };
                    foreach ($buttons as $act => $text): ?>
                    <form method="post" action="/portail/plugins.php">
                      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="<?= e($act) ?>">
                      <input type="hidden" name="plugin" value="<?= e($id) ?>">
                      <input type="hidden" name="slug" value="<?= e($slug) ?>">
                      <button type="submit" class="btn small<?= $act !== 'disable' ? ' btn-primary' : '' ?>"><?= e($text) ?></button>
                    </form>
                  <?php endforeach; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
</body>
</html>
