<?php
// Publication des retours dans Notion : jeton d'intégration et identifiant de la
// base « Suivi des jalons ». Réservé à l'exploitant du portail.
header('Cache-Control: no-store');
require_once PORTAIL_ROOT . '/includes/notion-todo.php';

if (defined('GA_LIMITED')) {
    http_response_code(403);
    exit('Accès réservé à l’exploitant du portail.');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save') {
            $token = trim((string) ($_POST['token'] ?? ''));
            $database = trim((string) ($_POST['database_id'] ?? ''));
            $current = notion_todo_config();
            if ($token === '' && $current) {
                $token = $current['token'];
            }
            if (notion_todo_config_save($token, $database)) {
                portail_flash('Connexion Notion enregistrée : les nouveaux retours seront publiés dans « Suivi des jalons ».');
            } else {
                portail_flash("Impossible d'écrire .secrets/notion.json (droits du dossier .secrets).", 'error');
            }
        } elseif ($action === 'test') {
            $result = notion_todo_test();
            portail_flash($result['message'], $result['ok'] ? 'ok' : 'error');
        } elseif ($action === 'delete') {
            notion_todo_config_delete();
            portail_flash("Jeton supprimé : l'envoi automatique vers Notion est désactivé.");
        } elseif ($action === 'flush') {
            $n = notion_todo_flush(null, null, 10);
            portail_flash($n ? "$n retour(s) publié(s) depuis la file d'attente." : "Aucun retour n'a pu être publié ; voir la dernière erreur ci-dessous.", $n ? 'ok' : 'error');
        } elseif ($action === 'clear') {
            notion_todo_pending_save([]);
            portail_flash("File d'attente vidée. Les retours concernés ne seront pas publiés dans Notion.");
        } elseif ($action === 'retry') {
            $ref = (string) ($_POST['ref'] ?? '');
            $kept = [];
            $published = 0;
            $failed = 0;
            foreach (notion_todo_pending_load() as $item) {
                if ((string) ($item['record']['id'] ?? '') !== $ref) {
                    $kept[] = $item;
                    continue;
                }
                try {
                    if (notion_todo_push((array) $item['record'], null, (array) notion_todo_config()) !== '') {
                        $published++;
                    }
                } catch (Throwable $e) {
                    $item['attempts'] = (int) ($item['attempts'] ?? 0) + 1;
                    $item['last_error'] = mb_substr($e->getMessage(), 0, 300);
                    $kept[] = $item;
                    $failed++;
                }
            }
            notion_todo_pending_save($kept);
            portail_flash($published ? 'Retour publié dans Notion.' : ($failed ? 'Échec de la publication : ' . $kept[count($kept) - 1]['last_error'] : 'Retour introuvable dans la file.'), $published ? 'ok' : 'error');
        } else {
            throw new InvalidArgumentException('Action inconnue.');
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
    if ($error === '') {
        header('Location: /portail/api.php?s=notion', true, 303);
        exit;
    }
}

$config = notion_todo_config();
$state = notion_todo_state();
$pending = notion_todo_pending_load();
$databaseId = $config['database_id'] ?? '19cde2b8-eea4-49d7-a883-a24a8cef20db';
$databaseUrl = 'https://app.notion.com/p/' . str_replace('-', '', $databaseId);
?>
<style>
  .nt-wrap { display:grid; gap:16px; max-width:860px; margin:0 auto; padding:0 16px 48px; }
  .nt-card { border:1px solid var(--line); background:var(--panel); border-radius:10px; padding:16px; display:grid; gap:12px; }
  .nt-card h2 { margin:0; font-size:1.05rem; }
  .nt-fields { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:12px; }
  .nt-fields label { display:grid; gap:5px; font-size:.84rem; font-weight:600; }
  .nt-fields input { width:100%; min-width:0; padding:9px 10px; border:1px solid var(--line); border-radius:8px; background:var(--bg); color:inherit; font:inherit; }
  .nt-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
  .nt-error { padding:12px; border:1px solid #b3261e; border-radius:8px; color:#b3261e; }
  .nt-row { display:flex; gap:10px; align-items:baseline; justify-content:space-between; border-top:1px solid var(--line); padding-top:8px; }
  .nt-row code { overflow-wrap:anywhere; }
  .nt-ok { color:#2e7d4f; }
  .nt-ko { color:#b3261e; }
  code { overflow-wrap:anywhere; }
</style>
  <?php if ($error !== ''): ?><p class="nt-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <main class="nt-wrap">
    <section class="nt-card">
      <h2>État de l'envoi vers Notion</h2>
      <p>
        <span class="pill <?= $config ? 'done' : 'sent' ?>"><?= $config ? 'Activé' : 'Désactivé' ?></span>
        <?php if ($config): ?> <span class="meta">Jeton <?= e(notion_todo_token_preview()) ?> · base <a href="<?= e($databaseUrl) ?>" target="_blank" rel="noopener"><?= e($databaseId) ?> ↗</a></span><?php endif; ?>
      </p>
      <?php if (!empty($state['last_ok_at'])): ?>
        <p class="meta nt-ok">Dernière publication réussie : <?= e((string) $state['last_ok_at']) ?> (<?= e((string) ($state['last_ref'] ?? '')) ?>).</p>
      <?php endif; ?>
      <?php if (!empty($state['last_error'])): ?>
        <p class="meta nt-ko">Dernière erreur (<?= e((string) ($state['last_error_at'] ?? '')) ?>, <?= e((string) ($state['last_ref'] ?? '')) ?>) : <?= e((string) $state['last_error']) ?></p>
      <?php endif; ?>
      <p class="meta">Chaque nouveau retour (15 caractères minimum) devient un jalon « À faire » avec la page complète. Le domaine et la priorité sont déduits (Technique par défaut, P0 bloquant, P1 gênant, P2 sinon). Les doublons sont évités en recherchant la référence <code>fb…</code> dans « Critère de done ». Un échec Notion ne bloque jamais l'envoi du retour : il est mis en file et réessayé.</p>
    </section>

    <form class="nt-card" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="save">
      <h2>Jeton d'intégration Notion</h2>
      <div class="nt-fields">
        <label>Jeton interne (ntn_…)
          <input type="password" name="token" autocomplete="new-password" placeholder="<?= $config ? 'Déjà enregistré — laisser vide pour conserver' : "Coller le jeton de l’intégration" ?>">
        </label>
        <label>Base « Suivi des jalons »
          <input type="text" name="database_id" value="<?= e($databaseId) ?>" spellcheck="false">
        </label>
      </div>
      <div class="nt-actions"><button class="btn btn-primary" type="submit">Enregistrer</button></div>
    </form>

    <form class="nt-card" method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="test">
      <h2>Vérifier la connexion</h2>
      <p class="meta">Lit le titre et les propriétés de la base sans rien écrire. Enregistre d'abord les changements.</p>
      <div class="nt-actions"><button class="btn" type="submit"<?= $config ? '' : ' disabled' ?>>Tester la connexion Notion</button></div>
    </form>

    <section class="nt-card">
      <h2>File d'attente (<?= count($pending) ?>)</h2>
      <?php if (!$pending): ?>
        <p class="meta">Aucun retour en attente : tout ce qui a été reçu a été publié.</p>
      <?php else: ?>
        <?php foreach (array_slice($pending, 0, 10) as $item): $ref = (string) ($item['record']['id'] ?? ''); ?>
          <div class="nt-row">
            <div>
              <code><?= e($ref) ?></code>
              <div class="meta"><?= e((string) ($item['record']['message'] ?? '')) ?></div>
              <div class="meta">Depuis <?= e((string) ($item['first_at'] ?? '')) ?> · <?= (int) ($item['attempts'] ?? 0) ?> tentative(s)<?= !empty($item['last_error']) ? ' · ' . e((string) $item['last_error']) : '' ?></div>
            </div>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="retry">
              <input type="hidden" name="ref" value="<?= e($ref) ?>">
              <button class="btn small" type="submit"<?= $config ? '' : ' disabled' ?>>Réessayer</button>
            </form>
          </div>
        <?php endforeach; ?>
        <div class="nt-actions">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="flush">
            <button class="btn small" type="submit"<?= $config ? '' : ' disabled' ?>>Tout réessayer</button>
          </form>
          <form method="post" onsubmit="return confirm('Vider la file sans publier ces retours ?');">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="clear">
            <button class="btn small danger" type="submit">Vider la file</button>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <section class="nt-card">
      <h2>Créer l'intégration Notion</h2>
      <ol>
        <li>Ouvre <a href="https://www.notion.so/my-integrations" target="_blank" rel="noopener">notion.so/my-integrations ↗</a> et crée une intégration interne, par exemple « Brocs — pilotage ».</li>
        <li>Capacités : <strong>Read content</strong> et <strong>Insert content</strong> suffisent. Aucun accès aux utilisateurs n'est nécessaire.</li>
        <li>Ouvre la base <a href="<?= e($databaseUrl) ?>" target="_blank" rel="noopener">« Suivi des jalons » ↗</a>, puis ⋯ → <strong>Connections</strong> → connecte l'intégration. Sans cette étape, Notion répond « Could not find database ».</li>
        <li>Copie le <strong>Internal Integration Secret</strong> (<code>ntn_…</code>) et colle-le ci-dessus, puis teste la connexion.</li>
      </ol>
      <p class="meta">Le jeton est stocké dans <code>.secrets/notion.json</code> (0600, jamais déployé ni servi en HTTP). Il n'est jamais réaffiché. Le supprimer ci-dessous désactive l'envoi mais conserve les jalons déjà créés.</p>
    </section>

    <?php if ($config): ?>
      <form class="nt-card" method="post" onsubmit="return confirm('Supprimer le jeton Notion ? L'envoi automatique sera désactivé.');">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="delete">
        <h2>Désactiver l'envoi</h2>
        <div><button class="btn small danger" type="submit">Supprimer le jeton Notion</button></div>
      </form>
    <?php endif; ?>
  </main>
