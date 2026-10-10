<?php
// Galeries commerciales (voir includes/galleries.php) : création, choix des commerces membres, commerces distants, publication.
// Page publique : /galerie/<identifiant>/ ; cette page-ci est réservée à l'exploitant (connexion au portail en ligne).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/galleries.php';

$shops = tenant_list();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slug = strtolower((string) ($_POST['slug'] ?? ''));
    $back = '/portail/galeries.php' . ($slug !== '' && $action !== 'delete' && $action !== 'create' ? '?g=' . urlencode($slug) : '');
    $gallery = $slug !== '' ? gallery_load($slug) : null;

    switch ($action) {
        case 'create':
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = strtolower(trim((string) ($_POST['slug'] ?? ''))) ?: gallery_slugify($name);
            if ($name === '') { portail_flash('Donnez un nom à la galerie.', 'error'); break; }
            if (!gallery_slug_valid($slug)) { portail_flash('Identifiant non valide : 2 à 40 caractères (lettres minuscules, chiffres, tirets).', 'error'); break; }
            if (gallery_load($slug) || in_array($slug, ['index', 'admin', 'assets', 'portail'], true)) { portail_flash('Une galerie « ' . $slug . ' » existe déjà : choisissez un autre identifiant.', 'error'); break; }
            $ok = gallery_save(['slug' => $slug, 'name' => $name, 'tagline' => (string) ($_POST['tagline'] ?? ''), 'accent' => (string) ($_POST['accent'] ?? ''), 'published' => false, 'created' => time(), 'members' => []]);
            portail_flash($ok ? 'Galerie créée : ajoutez maintenant ses commerces.' : "Impossible d'écrire dans data/galeries (droits du dossier).", $ok ? 'ok' : 'error');
            $back = $ok ? '/portail/galeries.php?g=' . urlencode($slug) : '/portail/galeries.php';
            break;

        case 'save':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            // Commerces de ce serveur cochés, dans l'ordre de la liste ; « à la une » = case étoile.
            $checked = (array) ($_POST['member'] ?? []);
            $featured = (array) ($_POST['featured'] ?? []);
            $local = [];
            foreach (array_keys($shops) as $shopSlug) {
                if (in_array($shopSlug, $checked, true)) $local[] = ['type' => 'local', 'slug' => $shopSlug, 'featured' => in_array($shopSlug, $featured, true)];
            }
            // Les commerces d'ailleurs restent ; leur case « à la une » est repérée par leur position dans la galerie.
            $remoteFeatured = (array) ($_POST['remote_featured'] ?? []);
            $members = [];
            foreach ($gallery['members'] as $k => $m) {
                if ($m['type'] !== 'remote') continue;
                $m['featured'] = in_array((string) $k, $remoteFeatured, true);
                $members[] = $m;
            }
            $gallery = array_merge($gallery, [
                'name' => trim((string) ($_POST['name'] ?? '')) ?: $gallery['name'],
                'tagline' => (string) ($_POST['tagline'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'accent' => (string) ($_POST['accent'] ?? ''),
                'published' => !empty($_POST['published']),
                'members' => array_merge($local, $members),
            ]);
            $ok = gallery_save($gallery);
            portail_flash($ok ? 'Galerie enregistrée.' : "Impossible d'écrire la galerie (droits du dossier data/galeries).", $ok ? 'ok' : 'error');
            break;

        case 'add_remote':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $url = rtrim(trim((string) ($_POST['url'] ?? '')), '/');
            $name = trim((string) ($_POST['name'] ?? ''));
            if (!gallery_remote_url_valid($url)) { portail_flash("Adresse non valide : une adresse http(s) publique, par exemple https://brocante.arrimage.com", 'error'); break; }
            foreach ($gallery['members'] as $m) if ($m['type'] === 'remote' && $m['url'] === $url) { portail_flash('Ce commerce est déjà dans la galerie.', 'error'); break 2; }
            $feed = catalogue_remote($url);
            if (!$feed) { portail_flash("Cette adresse ne répond pas avec un catalogue (" . $url . "/catalogue.php) : le commerce doit être à jour de la plateforme.", 'error'); break; }
            $gallery['members'][] = ['type' => 'remote', 'name' => $name !== '' ? $name : (string) ($feed['shop']['name'] ?? $url), 'url' => $url, 'featured' => false];
            gallery_save($gallery);
            portail_flash('« ' . ($feed['shop']['name'] ?? $url) . ' » ajouté : ' . count($feed['products']) . ' pièce(s) en ligne.');
            break;

        case 'remove_remote':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $i = (int) ($_POST['index'] ?? -1);
            $gallery['members'] = array_values(array_filter($gallery['members'], static fn ($m, $k) => !($m['type'] === 'remote' && $k === $i), ARRAY_FILTER_USE_BOTH));
            gallery_save($gallery);
            portail_flash('Commerce retiré de la galerie.');
            break;

        case 'refresh':
            if ($gallery) { gallery_cache_clear($slug); portail_flash('Catalogue actualisé : la galerie relit les commerces à la prochaine visite.'); }
            break;

        case 'delete':
            if ($gallery && trim((string) ($_POST['confirm'] ?? '')) === $slug) { gallery_delete($slug); portail_flash('Galerie « ' . $gallery['name'] . ' » supprimée (les commerces ne sont pas touchés).'); }
            else portail_flash("Pour confirmer, tapez l'identifiant de la galerie.", 'error');
            break;
    }
    header('Location: ' . $back);
    exit;
}

$flash = portail_flash();
$galleries = gallery_list();
$edit = isset($_GET['g']) ? gallery_load(strtolower((string) $_GET['g'])) : null;
$confirmDelete = $edit && ($_GET['supprimer'] ?? '') === '1';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Galeries commerciales — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .gwrap { display: grid; gap: 16px; max-width: 920px; margin: 0 auto; padding: 0 16px 48px; }
  .gcard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; padding: 16px; display: grid; gap: 10px; }
  .gcard h2 { margin: 0; font-size: 1.05rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .gcard input[type=text], .gcard input[type=url], .gcard textarea, .gcard input[type=search] { flex: 1; min-width: 200px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; }
  .gcard textarea { width: 100%; min-height: 76px; }
  .gcard label.f { display: grid; gap: 4px; font-size: .8rem; font-weight: 600; flex: 1; min-width: 200px; }
  .member { display: flex; align-items: center; gap: 12px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 8px; }
  .member .name { flex: 1; } .member small { opacity: .7; }
  .swatch-in { width: 44px; height: 34px; padding: 0; border: 1px solid var(--line); border-radius: 6px; background: none; }
  .gl { display: flex; justify-content: space-between; gap: 12px; align-items: center; padding: 10px 0; border-top: 1px solid var(--line); flex-wrap: wrap; }
  .gl:first-child { border-top: 0; }
  code.url { font-family: 'IBM Plex Mono', monospace; font-size: .82rem; word-break: break-all; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <h1>Galeries commerciales</h1>
      <p>Regrouper plusieurs commerces sur une page commune : une rue, un village, un groupe d'amis…</p>
    </div>
    <span style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($edit): ?><a class="btn" href="/portail/galeries.php">← Toutes les galeries</a><?php endif; ?>
      <a class="btn" href="/portail/">Portail des commerces</a>
    </span>
  </header>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="gwrap">
  <?php if (!$edit): ?>
    <section class="gcard">
      <h2>Mes galeries</h2>
      <?php if (!$galleries): ?><p class="meta">Aucune galerie pour le moment : créez la première ci-dessous.</p><?php endif; ?>
      <?php foreach ($galleries as $g): $url = $origin . '/galerie/' . $g['slug'] . '/'; ?>
        <div class="gl">
          <div>
            <strong><?= e($g['name']) ?></strong>
            <span class="pill <?= $g['published'] ? 'done' : 'sent' ?>"><?= $g['published'] ? 'Publiée' : 'Brouillon' ?></span><br>
            <small class="meta"><?= count($g['members']) ?> commerce<?= count($g['members']) > 1 ? 's' : '' ?> · <code class="url"><?= e($url) ?></code></small>
          </div>
          <span class="row"><a class="btn small btn-primary" href="/portail/galeries.php?g=<?= e($g['slug']) ?>">Gérer</a>
            <a class="btn small" href="<?= e($url . ($g['published'] ? '' : '?apercu=' . gallery_preview_token($g))) ?>" target="_blank" rel="noopener"><?= $g['published'] ? 'Voir' : 'Aperçu' ?></a></span>
        </div>
      <?php endforeach; ?>
    </section>

    <form class="gcard" method="post" autocomplete="off">
      <h2>Nouvelle galerie</h2>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create">
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="80" placeholder="La Rue des Arts"></label>
        <label class="f">Identifiant (adresse) <input type="text" name="slug" maxlength="40" pattern="[a-z0-9][a-z0-9\-]{1,39}" placeholder="rue-des-arts (laisser vide : automatique)"></label>
      </div>
      <div class="row">
        <label class="f">Slogan<input type="text" name="tagline" maxlength="160" placeholder="Les commerçants de la rue, réunis en ligne"></label>
        <label class="f" style="flex:none;min-width:0;">Couleur<input class="swatch-in" type="color" name="accent" value="#b5502e"></label>
      </div>
      <p><button class="btn btn-primary" type="submit">Créer la galerie</button></p>
    </form>

  <?php else: $g = $edit; $url = $origin . '/galerie/' . $g['slug'] . '/'; $remoteIndex = []; foreach ($g['members'] as $i => $m) if ($m['type'] === 'remote') $remoteIndex[$i] = $m; ?>
    <section class="gcard">
      <h2><?= e($g['name']) ?> <span class="pill <?= $g['published'] ? 'done' : 'sent' ?>"><?= $g['published'] ? 'Publiée' : 'Brouillon' ?></span></h2>
      <p class="meta">Adresse publique : <a href="<?= e($url) ?><?= $g['published'] ? '' : '?apercu=' . e(gallery_preview_token($g)) ?>" target="_blank" rel="noopener"><code class="url"><?= e($url) ?></code></a>
        <?= $g['published'] ? '' : ' — non publiée : seul ce lien d\'aperçu l\'ouvre.' ?></p>
      <form method="post" class="row"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
        <button class="btn small" type="submit" name="action" value="refresh">Actualiser le catalogue maintenant</button>
        <a class="btn small danger" href="/portail/galeries.php?g=<?= e($g['slug']) ?>&amp;supprimer=1">Supprimer la galerie</a>
        <span class="meta">Le catalogue est relu toutes les 10 minutes.</span></form>
      <?php if ($confirmDelete): ?>
        <form class="confirm danger-zone" method="post" autocomplete="off">
          <span>Supprimer la galerie <strong><?= e($g['name']) ?></strong> ? Les commerces et leurs pièces ne sont pas touchés.</span>
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
          <label class="field">Pour confirmer, tapez l'identifiant <code><?= e($g['slug']) ?></code><input name="confirm" required autocapitalize="none" autocomplete="off" spellcheck="false" placeholder="<?= e($g['slug']) ?>"></label>
          <span class="actions" style="margin:0;"><button class="btn small danger-solid" type="submit">Supprimer définitivement</button> <a class="btn small" href="/portail/galeries.php?g=<?= e($g['slug']) ?>">Annuler</a></span>
        </form>
      <?php endif; ?>
    </section>

    <form class="gcard" method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <h2>Présentation</h2>
      <div class="row">
        <label class="f">Nom<input type="text" name="name" required maxlength="80" value="<?= e($g['name']) ?>"></label>
        <label class="f" style="flex:none;min-width:0;">Couleur<input class="swatch-in" type="color" name="accent" value="<?= e($g['accent']) ?>"></label>
      </div>
      <label class="f">Slogan<input type="text" name="tagline" maxlength="160" value="<?= e($g['tagline']) ?>"></label>
      <label class="f">Présentation (facultative)<textarea name="description" maxlength="1200"><?= e($g['description']) ?></textarea></label>

      <h2 style="margin-top:8px;">Commerces de ce serveur</h2>
      <p class="meta">Cochez ceux qui font partie de la galerie ; ★ les met en avant dans l'annuaire. Seules les pièces visibles et en stock sont présentées.</p>
      <?php $inGallery = []; foreach ($g['members'] as $m) if ($m['type'] === 'local') $inGallery[$m['slug']] = $m; ?>
      <?php foreach ($shops as $slug => $shop): $on = isset($inGallery[$slug]); ?>
        <div class="member">
          <input type="checkbox" id="m-<?= e($slug) ?>" name="member[]" value="<?= e($slug) ?>"<?= $on ? ' checked' : '' ?>>
          <label class="name" for="m-<?= e($slug) ?>"><strong><?= e($shop['name']) ?></strong> <small>— <?= e($slug) ?><?= $shop['tagline'] !== '' ? ' · ' . e($shop['tagline']) : '' ?></small></label>
          <label><input type="checkbox" name="featured[]" value="<?= e($slug) ?>"<?= $on && $inGallery[$slug]['featured'] ? ' checked' : '' ?>> ★ à la une</label>
        </div>
      <?php endforeach; ?>

      <?php if ($remoteIndex): ?>
        <h2 style="margin-top:8px;">Commerces d'ailleurs</h2>
        <?php foreach ($remoteIndex as $i => $m): ?>
          <div class="member"><span class="name"><strong><?= e($m['name'] ?: $m['url']) ?></strong> <small><code class="url"><?= e($m['url']) ?></code></small></span>
            <label><input type="checkbox" name="remote_featured[]" value="<?= (int) $i ?>"<?= $m['featured'] ? ' checked' : '' ?>> ★ à la une</label>
            <button class="btn small danger" type="submit" form="rm-<?= (int) $i ?>">Retirer</button></div>
        <?php endforeach; ?>
      <?php endif; ?>

      <h2 style="margin-top:8px;">Publication</h2>
      <label class="member"><input type="checkbox" name="published" value="1"<?= $g['published'] ? ' checked' : '' ?>> <span class="name"><strong>Publier la galerie</strong> <small>— visible de tous à l'adresse publique et dans la liste des galeries</small></span></label>
      <p><button class="btn btn-primary" type="submit">Enregistrer</button></p>
    </form>
    <?php foreach ($remoteIndex as $i => $m): ?>
      <form id="rm-<?= (int) $i ?>" method="post" hidden><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove_remote"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>"><input type="hidden" name="index" value="<?= (int) $i ?>"></form>
    <?php endforeach; ?>

    <form class="gcard" method="post" autocomplete="off">
      <h2>Ajouter un commerce d'ailleurs</h2>
      <p class="meta">Un commerce hébergé sur un autre serveur (par exemple le Petit Chalet) : indiquez l'adresse de sa boutique. Elle doit servir son catalogue (<code>/catalogue.php</code>, fourni par la plateforme).</p>
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_remote"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <div class="row">
        <label class="f">Adresse de la boutique<input type="url" name="url" required placeholder="https://brocante.arrimage.com"></label>
        <label class="f">Nom (facultatif)<input type="text" name="name" maxlength="80" placeholder="repris du catalogue si vide"></label>
      </div>
      <p><button class="btn" type="submit">Tester et ajouter</button></p>
    </form>
  <?php endif; ?>
  </div>
</div>
</body>
</html>
