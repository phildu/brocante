<?php
// Galeries commerciales (voir includes/galleries.php) : création, choix des commerces membres, commerces distants, publication.
// Page publique : /galerie/<identifiant>/ ; cette page-ci est réservée à l'exploitant (connexion au portail en ligne).
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/saas-events.php';
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
                'published' => !empty($_POST['published']),
                'members' => array_merge($local, $members),
            ]);
            $ok = gallery_save($gallery);
            portail_flash($ok ? 'Galerie enregistrée.' : "Impossible d'écrire la galerie (droits du dossier data/galeries).", $ok ? 'ok' : 'error');
            break;

        case 'style':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            $old = $gallery['style'];
            $new = $old;
            foreach (['bg', 'ink', 'hero_color', 'banner_overlay', 'banner_pos', 'hero_height', 'hero_align', 'font_display', 'font_body', 'radius', 'card_ratio', 'density', 'per_page',
                'default_sort', 'dark_mode', 'cta_label', 'shops_title', 'shops_lede', 'pieces_title', 'footer_text'] as $k) {
                if (isset($_POST[$k])) $new[$k] = $_POST[$k];
            }
            // Les couleurs de fond, de texte et d'en-tête ne sont enregistrées que si leur case « personnaliser » est cochée (sinon : teintes d'origine).
            foreach (['bg' => 'use_bg', 'ink' => 'use_ink', 'hero_color' => 'use_hero'] as $k => $flag) if (empty($_POST[$flag])) $new[$k] = '';
            $new['show_stats'] = !empty($_POST['show_stats']);
            $new['show_cta'] = !empty($_POST['show_cta']);
            $errors = [];
            foreach (['banner' => [2000, 'banner'], 'logo' => [500, 'logo']] as $field => [$maxW, $kind]) {
                if (!empty($_POST['remove_' . $field])) { gallery_image_delete($old[$field]); $new[$field] = ''; }
                $file = $_FILES[$field] ?? null;
                if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    try {
                        $path = gallery_image_store($file, $slug, $kind, $maxW);
                        if ($old[$field] !== '' && $old[$field] !== $new[$field]) gallery_image_delete($old[$field]);
                        elseif ($new[$field] !== '') gallery_image_delete($new[$field]);
                        $new[$field] = $path;
                    } catch (InvalidArgumentException $ex) {
                        $errors[] = ($field === 'banner' ? 'Bannière' : 'Logo') . ' : ' . $ex->getMessage();
                    }
                }
            }
            $gallery['accent'] = (string) ($_POST['accent'] ?? $gallery['accent']);
            $gallery['style'] = $new;
            $ok = gallery_save($gallery);
            portail_flash($errors ? implode(' ', $errors) . ($ok ? ' Le reste est enregistré.' : '') : ($ok ? "Apparence enregistrée : elle s'applique à la page publique." : "Impossible d'écrire la galerie (droits du dossier data/galeries)."), $errors || !$ok ? 'error' : 'ok');
            break;

        case 'style_reset':
            if (!$gallery) { portail_flash('Galerie introuvable.', 'error'); break; }
            foreach (['banner', 'logo'] as $f) gallery_image_delete($gallery['style'][$f]);
            $gallery['style'] = gallery_style_normalize([]);
            $gallery['accent'] = '#b5502e';
            gallery_save($gallery);
            portail_flash("Apparence d'origine rétablie.");
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
    saas_audit_flash('Galeries');
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
  .style-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 18px; align-items: start; } @media (max-width: 900px) { .style-layout { grid-template-columns: 1fr; } }
  .style-fields fieldset { border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; margin: 0 0 12px; display: grid; gap: 10px; min-width: 0; } .style-fields legend { font-weight: 700; padding: 0 6px; }
  .style-fields select, .style-fields input[type=file] { padding: 7px 8px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: inherit; font: inherit; width: 100%; max-width: 100%; }
  .style-fields label.sw { flex: none; min-width: 0; } .presets { display: flex; flex-wrap: wrap; gap: 6px; }
  .preset { display: inline-flex; align-items: center; gap: 4px; border: 1px solid var(--line); background: var(--panel-2); border-radius: 8px; padding: 4px 8px 4px 5px; cursor: pointer; font: inherit; font-size: .78rem; color: inherit; }
  .preset i { width: 14px; height: 14px; border-radius: 50%; border: 1px solid rgba(0,0,0,.15); display: inline-block; } .preset:hover { border-color: var(--accent); }
  .thumb { width: 84px; height: 48px; border-radius: 6px; border: 1px solid var(--line); background-size: cover; background-position: center; display: inline-block; } .thumb.logo { background-size: contain; background-repeat: no-repeat; background-color: #fff; }
  .style-preview { position: sticky; top: 12px; } .pv { border: 1px solid var(--line); border-radius: 10px; overflow: hidden; background: var(--bg); color: var(--ink); font-family: var(--f-body, inherit); }
  .pv-hero { position: relative; background: var(--hero-bg); color: var(--hero-ink); padding: 22px 16px; overflow: hidden; } .pv-hero.h-compact { padding: 12px 16px; } .pv-hero.h-tall { padding: 44px 16px 24px; }
  .pv-media, .pv-shade { position: absolute; inset: 0; background-size: cover; } .pv-in { position: relative; } .pv-hero.a-center { text-align: center; } .pv-hero.a-center .pv-in img { margin-inline: auto; }
  .pv-in img { max-height: 36px; max-width: 120px; margin-bottom: 8px; display: block; } .pv-eyebrow { margin: 0 0 4px; font-size: .62rem; letter-spacing: .14em; text-transform: uppercase; opacity: .8; font-weight: 700; }
  .pv-in h3 { margin: 0; font-family: var(--f-display, inherit); font-size: 1.4rem; line-height: 1.05; color: inherit; } .pv-tag { margin: 6px 0 10px; font-size: .78rem; opacity: .92; }
  .pv-btn { display: inline-block; background: var(--hero-ink); color: var(--hero-bg); font-weight: 700; font-size: .72rem; padding: 6px 10px; border-radius: var(--r); } .pv-btn[hidden] { display: none; }
  .pv-body { padding: 12px 14px 14px; } .pv-h { margin: 0 0 8px; font-family: var(--f-display, inherit); font-weight: 700; } .pv-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; } .pv-cards i { aspect-ratio: 4 / 5; background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); }
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

    <?php
      $st = $g['style'];
      $fontUrls = [];
      foreach (APPEARANCE_FONTS as $role => $list) foreach ($list as $name => [$param]) $fontUrls[$name] = 'https://fonts.googleapis.com/css2?family=' . $param . '&display=swap';
      $sel = static fn (string $key, string $value): string => implode('', array_map(static fn ($k, $l) => '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($l) . '</option>', array_keys(GALLERY_STYLE_CHOICES[$key]), GALLERY_STYLE_CHOICES[$key]));
      $fontSel = static fn (string $role, string $value): string => '<option value="">Par défaut</option>' . implode('', array_map(static fn ($n, $f) => '<option value="' . e($n) . '"' . ($n === $value ? ' selected' : '') . '>' . e($n) . ' — ' . e($f[2]) . '</option>', array_keys(APPEARANCE_FONTS[$role]), APPEARANCE_FONTS[$role]));
    ?>
    <form class="gcard" method="post" enctype="multipart/form-data" autocomplete="off" id="style-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="style"><input type="hidden" name="slug" value="<?= e($g['slug']) ?>">
      <h2>Apparence de la page publique</h2>
      <p class="meta">Palette, bannière, logo, polices, cartes et textes de <a href="<?= e($url . ($g['published'] ? '' : '?apercu=' . gallery_preview_token($g))) ?>" target="_blank" rel="noopener">la page de la galerie</a>. L'aperçu ci-contre se met à jour pendant la saisie ; rien ne change en ligne avant « Enregistrer l'apparence ».</p>
      <div class="style-layout">
        <div class="style-fields">
          <fieldset><legend>Palette</legend>
            <div class="presets" role="group" aria-label="Palettes toutes prêtes">
              <?php foreach (APPEARANCE_PALETTES as $key => [$label, $pbg, $pink, $pacc, $pacc2]): ?>
                <button type="button" class="preset" data-bg="<?= e($pbg) ?>" data-ink="<?= e($pink) ?>" data-accent="<?= e($pacc) ?>" title="<?= e($label) ?>"><i style="background:<?= e($pbg) ?>"></i><i style="background:<?= e($pink) ?>"></i><i style="background:<?= e($pacc) ?>"></i><span><?= e($label) ?></span></button>
              <?php endforeach; ?>
            </div>
            <div class="row"><label class="f sw">Couleur principale<input class="swatch-in" type="color" name="accent" value="<?= e($g['accent']) ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_bg" value="1"<?= $st['bg'] !== '' ? ' checked' : '' ?>> Fond</span><input class="swatch-in" type="color" name="bg" value="<?= e($st['bg'] ?: '#f7f3ec') ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_ink" value="1"<?= $st['ink'] !== '' ? ' checked' : '' ?>> Texte</span><input class="swatch-in" type="color" name="ink" value="<?= e($st['ink'] ?: '#221f1a') ?>"></label>
              <label class="f sw"><span><input type="checkbox" name="use_hero" value="1"<?= $st['hero_color'] !== '' ? ' checked' : '' ?>> En-tête</span><input class="swatch-in" type="color" name="hero_color" value="<?= e($st['hero_color'] ?: $g['accent']) ?>"></label>
              <label class="f">Mode sombre<select name="dark_mode"><?= $sel('dark_mode', $st['dark_mode']) ?></select></label></div>
            <p class="meta">Cases décochées : les teintes d'origine (fond crème, texte brun) sont gardées ; l'en-tête reprend la couleur principale. Les nuances intermédiaires et le thème sombre sont calculés.</p>
          </fieldset>

          <fieldset><legend>Bannière et logo</legend>
            <div class="row"><label class="f">Image de bannière <small>(JPEG, PNG ou WebP, 12 Mo max, large de préférence)</small><input type="file" name="banner" accept="image/jpeg,image/png,image/webp"></label>
              <?php if ($st['banner'] !== ''): ?><span class="thumb" style="background-image:url('/<?= e($st['banner']) ?>')"></span><label><input type="checkbox" name="remove_banner" value="1"> Retirer</label><?php endif; ?></div>
            <div class="row">
              <label class="f">Assombrissement <output id="ov-out"><?= (int) $st['banner_overlay'] ?> %</output><input type="range" name="banner_overlay" min="0" max="80" step="5" value="<?= (int) $st['banner_overlay'] ?>"></label>
              <label class="f">Cadrage<select name="banner_pos"><?= $sel('banner_pos', $st['banner_pos']) ?></select></label>
              <label class="f">Hauteur<select name="hero_height"><?= $sel('hero_height', $st['hero_height']) ?></select></label>
              <label class="f">Alignement<select name="hero_align"><?= $sel('hero_align', $st['hero_align']) ?></select></label></div>
            <div class="row"><label class="f">Logo <small>(PNG transparent conseillé)</small><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
              <?php if ($st['logo'] !== ''): ?><span class="thumb logo" style="background-image:url('/<?= e($st['logo']) ?>')"></span><label><input type="checkbox" name="remove_logo" value="1"> Retirer</label><?php endif; ?></div>
          </fieldset>

          <fieldset><legend>Polices</legend>
            <div class="row"><label class="f">Titres<select name="font_display"><?= $fontSel('display', $st['font_display']) ?></select></label>
              <label class="f">Textes<select name="font_body"><?= $fontSel('body', $st['font_body']) ?></select></label></div>
          </fieldset>

          <fieldset><legend>Cartes et grille</legend>
            <div class="row"><label class="f">Coins<select name="radius"><?= $sel('radius', $st['radius']) ?></select></label>
              <label class="f">Format des photos<select name="card_ratio"><?= $sel('card_ratio', $st['card_ratio']) ?></select></label>
              <label class="f">Grille<select name="density"><?= $sel('density', $st['density']) ?></select></label></div>
            <div class="row"><label class="f">Pièces par page<select name="per_page"><?= $sel('per_page', (string) $st['per_page']) ?></select></label>
              <label class="f">Tri par défaut<select name="default_sort"><?= $sel('default_sort', $st['default_sort']) ?></select></label></div>
          </fieldset>

          <fieldset><legend>Textes et blocs</legend>
            <div class="row"><label><input type="checkbox" name="show_stats" value="1"<?= $st['show_stats'] ? ' checked' : '' ?>> Chiffres sous le titre (commerces, pièces, univers)</label>
              <label><input type="checkbox" name="show_cta" value="1"<?= $st['show_cta'] ? ' checked' : '' ?>> Appel « Créer ma boutique » (bouton, tuile, bandeau)</label></div>
            <label class="f">Texte du bouton<input type="text" name="cta_label" maxlength="50" value="<?= e($st['cta_label']) ?>" placeholder="Créer ma boutique dans cette galerie"></label>
            <div class="row"><label class="f">Titre des commerces<input type="text" name="shops_title" maxlength="60" value="<?= e($st['shops_title']) ?>" placeholder="Les commerces"></label>
              <label class="f">Titre des pièces<input type="text" name="pieces_title" maxlength="60" value="<?= e($st['pieces_title']) ?>" placeholder="Toutes les pièces"></label></div>
            <label class="f">Phrase sous « Les commerces »<input type="text" name="shops_lede" maxlength="200" value="<?= e($st['shops_lede']) ?>" placeholder="Chaque commerce a sa boutique, son panier et son paiement…"></label>
            <label class="f">Texte de bas de page<input type="text" name="footer_text" maxlength="300" value="<?= e($st['footer_text']) ?>" placeholder="Les pièces sont vendues et expédiées par les commerces eux-mêmes."></label>
          </fieldset>
          <p class="row"><button class="btn btn-primary" type="submit">Enregistrer l'apparence</button>
            <button class="btn small danger" type="submit" name="action" value="style_reset" formnovalidate onclick="return confirm('Rétablir l\'apparence d\'origine (palette, bannière, logo, textes) ?');">Rétablir l'apparence d'origine</button></p>
        </div>

        <aside class="style-preview" aria-label="Aperçu">
          <div class="pv" id="pv">
            <div class="pv-hero" id="pv-hero"><div class="pv-media" id="pv-media"></div><div class="pv-shade" id="pv-shade"></div>
              <div class="pv-in"><img id="pv-logo" alt="" hidden><p class="pv-eyebrow">Galerie commerciale</p><h3 id="pv-title"><?= e($g['name']) ?></h3><p class="pv-tag"><?= e($g['tagline'] ?: 'Votre slogan') ?></p>
                <span class="pv-btn">Créer ma boutique</span></div></div>
            <div class="pv-body"><p class="pv-h" id="pv-h">Les commerces</p>
              <div class="pv-cards"><i></i><i></i><i></i></div></div>
          </div>
          <p class="meta">Aperçu simplifié : la page réelle s'ouvre avec le lien ci-dessus.</p>
        </aside>
      </div>
    </form>
    <script>
    (function () {
      var f = document.getElementById('style-form'), pv = document.getElementById('pv');
      var fonts = <?= json_encode($fontUrls, JSON_UNESCAPED_SLASHES) ?>, stacks = <?= json_encode(['display' => array_map(static fn ($f) => $f[1], APPEARANCE_FONTS['display']), 'body' => array_map(static fn ($f) => $f[1], APPEARANCE_FONTS['body'])], JSON_UNESCAPED_SLASHES) ?>;
      var current = { banner: <?= json_encode($st['banner'] !== '' ? '/' . $st['banner'] : '') ?>, logo: <?= json_encode($st['logo'] !== '' ? '/' . $st['logo'] : '') ?> };
      function hex(v) { return [1, 3, 5].map(function (i) { return parseInt(v.substr(i, 2), 16); }); }
      function lum(c) { var a = hex(c).map(function (v) { v /= 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * a[0] + .7152 * a[1] + .0722 * a[2]; }
      function on(c) { return lum(c) > .4 ? '#1d1a16' : '#fffaf2'; }
      function mix(a, b, t) { var x = hex(a), y = hex(b); return '#' + x.map(function (v, i) { return ('0' + Math.round(v + (y[i] - v) * t).toString(16)).slice(-2); }).join(''); }
      function val(n) { return f.elements[n]; }
      function render() {
        var accent = val('accent').value, useBg = val('use_bg').checked, useInk = val('use_ink').checked, useHero = val('use_hero').checked;
        var bg = useBg ? val('bg').value : '#f7f3ec', ink = useInk ? val('ink').value : '#221f1a', hero = useHero ? val('hero_color').value : accent;
        var banner = val('remove_banner') && val('remove_banner').checked ? '' : (val('banner').files[0] ? URL.createObjectURL(val('banner').files[0]) : current.banner);
        var logo = val('remove_logo') && val('remove_logo').checked ? '' : (val('logo').files[0] ? URL.createObjectURL(val('logo').files[0]) : current.logo);
        var s = pv.style;
        s.setProperty('--bg', bg); s.setProperty('--ink', ink); s.setProperty('--accent', accent); s.setProperty('--accent-ink', on(accent));
        s.setProperty('--surface', useBg || useInk ? mix(bg, ink, .06) : '#ffffff'); s.setProperty('--line', mix(bg, ink, .25));
        s.setProperty('--hero-bg', hero); s.setProperty('--hero-ink', banner ? '#ffffff' : on(hero));
        s.setProperty('--r', { square: '3px', soft: '10px', round: '18px' }[val('radius').value]);
        var media = document.getElementById('pv-media'), shade = document.getElementById('pv-shade'), el = document.getElementById('pv-hero');
        media.style.backgroundImage = banner ? 'url(' + banner + ')' : 'none'; media.style.backgroundPosition = 'center ' + val('banner_pos').value;
        shade.style.background = banner ? 'rgba(0,0,0,' + (val('banner_overlay').value / 100) + ')' : 'none';
        el.className = 'pv-hero h-' + val('hero_height').value + ' a-' + val('hero_align').value;
        var img = document.getElementById('pv-logo'); img.hidden = !logo; if (logo) img.src = logo;
        document.getElementById('ov-out').textContent = val('banner_overlay').value + ' %';
        ['display', 'body'].forEach(function (role) {
          var name = val('font_' + role).value;
          if (name && !document.getElementById('pvf-' + name)) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.id = 'pvf-' + name; l.href = fonts[name]; document.head.appendChild(l); }
          s.setProperty('--f-' + role, name ? '"' + name + '", ' + stacks[role][name] : 'inherit');
        });
        document.getElementById('pv-h').textContent = val('shops_title').value || 'Les commerces';
        var btn = pv.querySelector('.pv-btn'); btn.textContent = val('cta_label').value || 'Créer ma boutique'; btn.hidden = !val('show_cta').checked;
      }
      f.addEventListener('input', render); f.addEventListener('change', render);
      f.querySelectorAll('.preset').forEach(function (b) { b.addEventListener('click', function () {
        val('accent').value = b.dataset.accent; val('bg').value = b.dataset.bg; val('ink').value = b.dataset.ink; val('use_bg').checked = true; val('use_ink').checked = true; render();
      }); });
      render();
    })();
    </script>

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
