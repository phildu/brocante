<?php
// Bourse d'échanges publique d'une galerie : /galerie/bourse.php?g=<identifiant>
// Les visiteurs proposent une pièce à la vente ; la galerie publie ses recherches d'achat, auxquelles les particuliers peuvent répondre.
// Données et validation : includes/gallery-bourse.php. Administration : portail/galerie-bourse.php.
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-types.php';
require_once __DIR__ . '/../includes/gallery-bourse.php';

function gbo_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function gbo_ink(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1c1a17' : '#ffffff';
}

$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$enabled = $gallery && gt_feature($gallery, 'bourse') && (!function_exists('go_is_live') || go_is_live($gallery));
$accent = $gallery['accent'] ?? '#b5502e';
$ink = gbo_ink($accent);
$back = '/galerie/' . rawurlencode($slug) . '/';
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enabled) {
    $parent = (int) ($_POST['parent_id'] ?? 0);
    $again = '/galerie/bourse.php?g=' . rawurlencode($slug) . ($parent > 0 ? '&repondre=' . $parent : '') . '#proposer';
    if (trim((string) ($_POST['website'] ?? '')) !== '') {
        $notice = 'Merci ! Votre proposition est transmise à la galerie.';
    } elseif (!gbo_form_valid($slug, $_POST)) {
        $error = 'Formulaire expiré : rechargez la page et renvoyez votre proposition.';
    } elseif (!gbo_rate_check($slug)) {
        $error = 'Vous avez envoyé plusieurs demandes à l’instant : réessayez dans un moment.';
    } else {
        $_POST['kind'] = 'offre';
        if ($parent > 0) $_POST['parent_id'] = $parent;
        [$id, $err] = gbo_add($slug, $_POST, $_FILES['photo'] ?? null);
        if ($err) {
            $error = $err;
        } else {
            gbo_rate_note($slug);
            $notice = 'Merci ! Votre proposition est transmise à la galerie, qui vous répond par e-mail.';
        }
    }
}

$replyTo = (int) ($_GET['repondre'] ?? 0);
$replyItem = null;
if ($enabled && $replyTo > 0) {
    foreach (gbo_public_searches($slug) as $s) if ($s['id'] === $replyTo) $replyItem = $s;
}
$searches = $enabled ? gbo_public_searches($slug) : [];
$retained = $enabled ? gbo_public_offers($slug) : [];

http_response_code(!$gallery ? 404 : 200);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $gallery ? gbo_e("Bourse d'échanges — " . $gallery['name']) : 'Galerie introuvable' ?></title>
<style>
  :root { --accent: <?= gbo_e($accent) ?>; --ink-accent: <?= gbo_e($ink) ?>; --bg: #f4f0e8; --ink: #221f1a; --muted: #6b6457; --line: #ddd5c6; --panel: #fff; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
  a { color: var(--accent); }
  header.bar { background: var(--accent); color: var(--ink-accent); padding: 18px 18px 16px; }
  header.bar .wrap { max-width: 760px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; justify-content: space-between; }
  header.bar b { font-size: 1.15rem; } header.bar a { color: inherit; text-decoration: none; font-weight: 600; font-size: .9rem; }
  main { max-width: 760px; margin: 0 auto; padding: 18px; display: grid; gap: 14px; }
  .card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 18px; display: grid; gap: 10px; }
  h1 { font-size: 1.4rem; margin: 0; } h2 { font-size: 1.05rem; margin: 0; } h3 { font-size: .98rem; margin: 0; }
  p { margin: 0; } .muted { color: var(--muted); font-size: .9rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  label.f { display: grid; gap: 4px; font-size: .82rem; font-weight: 600; flex: 1; min-width: 180px; }
  input, select, textarea { font: inherit; padding: 10px; border: 1px solid #cfc6b4; border-radius: 8px; background: #fffdf8; color: var(--ink); width: 100%; }
  input[type=file] { background: transparent; border-style: dashed; }
  textarea { min-height: 90px; resize: vertical; }
  button, .btn { font: inherit; font-weight: 600; padding: 11px 16px; border: 1px solid var(--accent); border-radius: 9px; background: var(--accent); color: var(--ink-accent); cursor: pointer; text-decoration: none; display: inline-block; }
  .btn.ghost { background: transparent; color: var(--accent); }
  .flash { border-radius: 9px; padding: 11px 13px; font-size: .95rem; }
  .flash.ok { background: #e6f4ec; color: #1d6b42; } .flash.err { background: #fbe9e4; color: #a4361b; }
  .item { border: 1px solid var(--line); border-radius: 10px; padding: 13px 14px; display: grid; gap: 6px; background: #faf7f0; }
  .item p { font-size: .9rem; overflow-wrap: anywhere; }
  .meta { display: flex; flex-wrap: wrap; gap: 3px 12px; color: var(--muted); font-size: .78rem; }
  .tag { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: .7rem; letter-spacing: .05em; text-transform: uppercase; border: 1px solid var(--line); color: var(--muted); }
  .item-img { max-width: 160px; border: 1px solid var(--line); border-radius: 8px; }
  .consent { display: flex; gap: 8px; align-items: flex-start; font-size: .82rem; color: var(--muted); }
  .consent input { width: auto; margin-top: 3px; }
  .hp { position: absolute; left: -9999px; height: 1px; width: 1px; overflow: hidden; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; padding: 6px 18px 28px; }
</style>
</head>
<body>
<header class="bar"><div class="wrap"><b><?= $gallery ? gbo_e($gallery['name']) : 'Galerie' ?></b><a href="<?= gbo_e($back) ?>">Voir la galerie →</a></div></header>
<main>
<?php if (!$gallery): ?>
  <section class="card"><h1>Galerie introuvable</h1><p class="muted">Ce lien ne correspond à aucune galerie.</p></section>
<?php elseif (!$enabled): ?>
  <section class="card"><h1>Bourse d’échanges indisponible</h1><p class="muted">Cette galerie ne propose pas de bourse d’échanges pour le moment.</p><p class="row"><a class="btn" href="<?= gbo_e($back) ?>">Voir la galerie</a></p></section>
<?php else: ?>
  <?php if ($error): ?><p class="flash err" role="alert"><?= gbo_e($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="flash ok" role="status"><?= gbo_e($notice) ?></p><?php endif; ?>

  <section class="card">
    <h1>Bourse d’échanges</h1>
    <p>Vous avez une pièce à vendre — un meuble, un service de table, un tableau ? Proposez-la aux exposants de la galerie : votre
      proposition est transmise à l’organisateur, qui vous répond par e-mail. Retrouvez aussi ci-dessous les pièces que la galerie recherche.</p>
    <p class="row"><a class="btn" href="#proposer">Proposer une pièce</a><a class="btn ghost" href="<?= gbo_e($back) ?>">Retour à la galerie</a></p>
  </section>

  <section class="card">
    <h2>Ce que la galerie recherche</h2>
    <?php if ($searches): ?>
      <p class="muted">Une de ces pièces vous concerne ? Répondez à la recherche : votre proposition arrive directement à l’organisateur.</p>
      <?php foreach ($searches as $s): ?>
        <article class="item">
          <div class="row" style="justify-content:space-between;">
            <h3><?= gbo_e($s['title']) ?></h3>
            <?php if ($s['category'] !== ''): ?><span class="tag"><?= gbo_e($s['category']) ?></span><?php endif; ?>
          </div>
          <p style="white-space:pre-wrap;"><?= gbo_e($s['description']) ?></p>
          <p class="meta">
            <?php if ($s['price_cents']): ?><span>Budget jusqu’à <strong><?= gbo_e(gbo_money($s['price_cents'])) ?></strong></span><?php endif; ?>
            <?php if ($s['location'] !== ''): ?><span><?= gbo_e($s['location']) ?></span><?php endif; ?>
            <span>Depuis le <?= gbo_e(gbo_date($s['created_at'])) ?></span>
          </p>
          <p><a class="btn ghost" href="/galerie/bourse.php?g=<?= gbo_e(rawurlencode($slug)) ?>&amp;repondre=<?= (int) $s['id'] ?>#proposer">J’ai cette pièce →</a></p>
        </article>
      <?php endforeach; ?>
    <?php else: ?>
      <p class="muted">Aucune recherche publiée pour le moment. Proposez tout de même votre pièce : l’organisateur l’étudiera.</p>
    <?php endif; ?>
  </section>

  <?php if ($retained): ?>
    <section class="card">
      <h2>Pièces retenues par la galerie</h2>
      <p class="muted">Quelques propositions acceptées, montrées avec l’accord de leur propriétaire.</p>
      <?php foreach ($retained as $o): ?>
        <article class="item">
          <h3><?= gbo_e($o['title']) ?></h3>
          <?php if ($o['photo']): ?><p><img class="item-img" src="/<?= gbo_e($o['photo']) ?>" alt="<?= gbo_e($o['title']) ?>" loading="lazy"></p><?php endif; ?>
          <p style="white-space:pre-wrap;"><?= gbo_e($o['description']) ?></p>
          <p class="meta">
            <?php if ($o['category'] !== ''): ?><span><?= gbo_e($o['category']) ?></span><?php endif; ?>
            <?php if ($o['condition'] !== ''): ?><span><?= gbo_e($o['condition']) ?></span><?php endif; ?>
            <?php if ($o['location'] !== ''): ?><span><?= gbo_e($o['location']) ?></span><?php endif; ?>
          </p>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <section class="card" id="proposer">
    <h2><?= $replyItem ? 'Proposer une pièce pour « ' . gbo_e($replyItem['title']) . ' »' : 'Proposer une pièce à la vente' ?></h2>
    <p class="muted">Quelques lignes et une photo suffisent. Aucun engagement : l’organisateur étudie chaque proposition.</p>
    <form method="post" action="/galerie/bourse.php?g=<?= gbo_e(rawurlencode($slug)) ?>" enctype="multipart/form-data">
      <input type="hidden" name="g" value="<?= gbo_e($slug) ?>">
      <input type="hidden" name="kind" value="offre">
      <?php if ($replyItem): ?><input type="hidden" name="parent_id" value="<?= (int) $replyItem['id'] ?>"><?php endif; ?>
      <?= gbo_form_fields($slug) ?>
      <div class="hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div class="row">
        <label class="f" style="flex:1 1 100%;">La pièce<input type="text" name="title" maxlength="140" required value="<?= gbo_e($replyItem ? 'Réponse à « ' . $replyItem['title'] . ' »' : '') ?>" placeholder="Ex. Commode en chêne, faïence de Gien…"></label>
        <label class="f">Catégorie
          <select name="category">
            <option value="">Choisir…</option>
            <?php foreach (GBO_CATEGORIES as $value => $label): ?><option value="<?= gbo_e($value) ?>"<?= $replyItem && $replyItem['category'] === $value ? ' selected' : '' ?>><?= gbo_e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="f">État
          <select name="condition">
            <option value="">Non précisé</option>
            <?php foreach (GBO_CONDITIONS as $value => $label): ?><option value="<?= gbo_e($value) ?>"><?= gbo_e($label) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <label class="f" style="margin-top:8px;">Description<textarea name="description" maxlength="1600" required placeholder="Dimensions, matière, époque, défauts éventuels, où se trouve la pièce…"></textarea></label>
      <div class="row" style="margin-top:8px;">
        <label class="f">Prix souhaité (facultatif)<input type="text" name="price" inputmode="decimal" placeholder="en euros"></label>
        <label class="f">Lieu (facultatif)<input type="text" name="location" maxlength="80" placeholder="Ville / département"></label>
      </div>
      <label class="f" style="margin-top:8px;">Photo (facultatif, une image)<input type="file" name="photo" accept="image/*"></label>
      <div class="row" style="margin-top:8px;">
        <label class="f">Votre nom<input type="text" name="contact_name" maxlength="80" required autocomplete="name"></label>
        <label class="f">Votre e-mail<input type="email" name="email" maxlength="120" required autocomplete="email"></label>
        <label class="f">Téléphone (facultatif)<input type="tel" name="phone" maxlength="40" autocomplete="tel"></label>
      </div>
      <label class="consent" style="margin-top:10px;"><input type="checkbox" name="consent" value="1" required> J’autorise la transmission de mes coordonnées à la galerie pour répondre à ma proposition.</label>
      <p style="margin-top:12px;"><button class="btn" type="submit">Envoyer ma proposition</button></p>
    </form>
  </section>
<?php endif; ?>
</main>
<footer>Bourse d’échanges gérée par l’organisateur de la galerie. Les coordonnées servent uniquement à répondre aux propositions.</footer>
</body>
</html>
