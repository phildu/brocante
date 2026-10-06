<?php
// Clés API partagées de la période de test (voir includes/shared-secrets.php) : une clé saisie ici sert à tous les commerces de ce
// déploiement qui n'ont pas la leur ; « Mise en production » les efface toutes. Réservé à l'exploitant (connexion au portail).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/shared-secrets.php';

$shops = tenant_list();
$patterns = [
    'gemini.key' => ['/^[A-Za-z0-9._-]{20,200}$/', 'Cette clé ne ressemble pas à une clé Gemini.'],
    'fal.key' => ['/^[A-Za-z0-9._:-]{10,250}$/', 'Cette clé ne ressemble pas à une clé fal.ai.'],
    'siliconflow.key' => ['/^[A-Za-z0-9._-]{16,200}$/', 'Cette clé ne ressemble pas à une clé SiliconFlow.'],
];

/** Fichier de clé propre d'un commerce. */
$ownFile = static fn (array $shop, string $name): string => tenant_file($shop, 'secrets_dir') . '/' . $name;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $name = (string) ($_POST['name'] ?? '');
    $known = isset(SHARED_SECRET_FILES[$name]);
    switch ($action) {
        case 'save':
            if (!$known) { portail_flash('Clé inconnue.', 'error'); break; }
            if ($name === 'modal-video.json') {
                $url = rtrim(trim((string) ($_POST['modal_url'] ?? '')), '/');
                $token = trim((string) ($_POST['modal_token'] ?? ''));
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                if (!str_starts_with($url, 'https://') || !str_ends_with($host, '.modal.run')) { portail_flash("Adresse non valide : elle doit finir par .modal.run (affichée par « modal deploy »).", 'error'); break; }
                if (!preg_match('/^[A-Za-z0-9._~+\/=-]{8,200}$/', $token)) { portail_flash('Jeton non valide : 8 à 200 caractères, sans espace.', 'error'); break; }
                $ok = shared_secret_save($name, json_encode(['url' => $url, 'token' => $token]));
            } else {
                $key = trim((string) ($_POST['key'] ?? ''));
                if (!preg_match($patterns[$name][0], $key)) { portail_flash($patterns[$name][1], 'error'); break; }
                $ok = shared_secret_save($name, $key);
            }
            portail_flash($ok ? 'Clé partagée enregistrée : tous les commerces sans clé propre l\'utilisent.' : 'Impossible d\'écrire la clé (droits du dossier .secrets).', $ok ? 'ok' : 'error');
            break;
        case 'share': // reprend la clé d'un commerce comme clé partagée
            $slug = (string) ($_POST['slug'] ?? '');
            $file = isset($shops[$slug]) && $known ? $ownFile($shops[$slug], $name) : '';
            if ($file === '' || !is_file($file)) { portail_flash('Clé introuvable pour ce commerce.', 'error'); break; }
            $ok = shared_secret_save($name, (string) file_get_contents($file));
            portail_flash($ok ? 'La clé de « ' . $shops[$slug]['name'] . ' » est maintenant partagée avec tous les commerces sans clé propre.' : 'Impossible d\'écrire la clé partagée.', $ok ? 'ok' : 'error');
            break;
        case 'delete':
            if ($known) { shared_secret_delete($name); portail_flash('Clé partagée supprimée.'); }
            break;
        case 'production':
            if (trim((string) ($_POST['confirm'] ?? '')) !== 'PRODUCTION') { portail_flash('Pour confirmer, tapez PRODUCTION en capitales.', 'error'); break; }
            $n = shared_secrets_clear();
            portail_flash($n ? "Mise en production : $n clé(s) partagée(s) supprimée(s). Chaque commerce n'utilise plus que ses propres clés." : 'Il n\'y avait aucune clé partagée.');
            break;
    }
    header('Location: /portail/cles.php');
    exit;
}

$flash = portail_flash();
$sharedCount = count(array_filter(array_keys(SHARED_SECRET_FILES), 'shared_secret_exists'));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Clés partagées — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .keys { display: grid; gap: 16px; max-width: 880px; margin: 0 auto; padding: 0 16px 48px; }
  .key-card { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .key-card h2 { margin: 0; font-size: 1.05rem; }
  .key-card .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .key-card input[type=text], .key-card input[type=password] { flex: 1; min-width: 220px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .key-card .used { font-size: 0.85rem; opacity: 0.85; }
  .prod { border-color: var(--warn); }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <h1>Clés API partagées (période de test)</h1>
      <p>Une même clé pour tous les commerces qui n'ont pas la leur — à effacer à la mise en production</p>
    </div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;"><a class="btn" href="/portail/">← Portail des commerces</a></span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="keys">
    <p class="used">Une clé saisie dans les réglages d'un commerce reste <strong>prioritaire</strong> sur la clé partagée. Seul l'exploitant (ce portail) peut modifier les clés partagées. Les clés de paiement Stripe ne sont jamais partagées. Elles ne couvrent que ce déploiement (un commerce hébergé dans un autre dossier, comme le Petit Chalet, a ses propres clés).</p>

    <?php foreach (SHARED_SECRET_FILES as $name => $label): $present = shared_secret_exists($name); ?>
      <section class="key-card" id="k-<?= e(preg_replace('/\W/', '-', $name)) ?>">
        <h2><?= e($label) ?> <span class="pill <?= $present ? 'done' : 'sent' ?>"><?= $present ? 'Partagée : ' . e(secret_preview(shared_secrets_dir() . '/' . $name)) : 'Non partagée' ?></span></h2>

        <form method="post" class="row" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save"><input type="hidden" name="name" value="<?= e($name) ?>">
          <?php if ($name === 'modal-video.json'): ?>
            <input type="text" name="modal_url" placeholder="https://votre-compte--boutique-ltx-video-web.modal.run" aria-label="Adresse du service Modal">
            <input type="password" name="modal_token" placeholder="Jeton du service" aria-label="Jeton">
          <?php else: ?>
            <input type="password" name="key" placeholder="<?= $present ? 'Remplacer la clé partagée…' : 'Coller la clé…' ?>" aria-label="Clé <?= e($label) ?>">
          <?php endif; ?>
          <button class="btn btn-primary small" type="submit"><?= $present ? 'Remplacer' : 'Partager cette clé' ?></button>
          <?php if ($present): ?><button class="btn small" type="submit" name="action" value="delete" formnovalidate onclick="return confirm('Supprimer cette clé partagée ?');">Supprimer</button><?php endif; ?>
        </form>

        <?php
          $owners = []; $users = [];
          foreach ($shops as $slug => $shop) { if (is_file($ownFile($shop, $name))) $owners[$slug] = $shop; else $users[] = $shop['name']; }
        ?>
        <?php if ($owners): ?>
          <form method="post" class="row">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="share"><input type="hidden" name="name" value="<?= e($name) ?>">
            <span class="used">Reprendre la clé d'un commerce :</span>
            <select name="slug" aria-label="Commerce dont reprendre la clé">
              <?php foreach ($owners as $slug => $shop): ?><option value="<?= e($slug) ?>"><?= e($shop['name']) ?> (<?= e(secret_preview($ownFile($shop, $name))) ?>)</option><?php endforeach; ?>
            </select>
            <button class="btn small" type="submit">La partager avec tous</button>
          </form>
        <?php endif; ?>
        <p class="used">
          <?php if ($present): ?>Utilisée par les commerces sans clé propre : <strong><?= $users ? e(implode(', ', $users)) : 'aucun (tous ont la leur)' ?></strong>.<?php else: ?>Commerces avec leur propre clé : <?= $owners ? e(implode(', ', array_map(fn ($s) => $s['name'], $owners))) : 'aucun' ?>.<?php endif; ?>
        </p>
      </section>
    <?php endforeach; ?>

    <section class="key-card prod">
      <h2>Mise en production</h2>
      <p class="used">Supprime d'un coup <strong>toutes</strong> les clés partagées (<?= (int) $sharedCount ?> actuellement). Les commerces qui n'ont pas leur propre clé perdront alors les fonctions d'IA correspondantes (génération d'images, vidéos, détourage) jusqu'à ce qu'ils en saisissent une dans leurs réglages. Les clés propres des commerces ne sont pas touchées.</p>
      <form method="post" class="row" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="production">
        <input type="text" name="confirm" placeholder="Tapez PRODUCTION pour confirmer" autocapitalize="characters" spellcheck="false" aria-label="Confirmation">
        <button class="btn small" type="submit" <?= $sharedCount ? '' : 'disabled' ?>>Supprimer toutes les clés partagées</button>
      </form>
    </section>
  </div>
</div>
</body>
</html>
