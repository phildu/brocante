<?php
// Visite guidée d'une galerie (marché aux puces) : un parcours pas à pas, une étape par hall ou bâtiment du plan avec ses marchands.
//   GET ?g=<galerie>[&etape=<n>]   n = 1 pour l'accueil, puis une étape par secteur du plan, puis la conclusion.
// Les étapes sont bâties par includes/gallery-visit.php ; l'extension « Visite guidée » doit être ouverte pour la galerie (module « visite »).
require_once __DIR__ . '/../includes/apps.php';
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-types.php';
require_once __DIR__ . '/../includes/gallery-modules.php';
require_once __DIR__ . '/../includes/gallery-visit.php';
require_once __DIR__ . '/../includes/gallery-loyalty.php';

$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$slug = strtolower((string) ($_GET['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$origin = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

$shops = [];
$steps = [];
if ($gallery && gv_enabled($gallery) && gv_load($slug)['show']) {
    $shops = gallery_catalogue($gallery, $origin, false, true)['shops'];
    uasort($shops, static fn (array $a, array $b): int => [$b['featured'], $a['order']] <=> [$a['featured'], $b['order']]);
    $steps = gv_steps($gallery, $shops, gv_load($slug));
}

header('X-Robots-Tag: noindex');
header('Content-Type: text/html; charset=utf-8');
if (!$steps) {
    http_response_code(404);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Visite guidée</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#222}</style></head><body><h1>Visite guidée</h1><p>La visite guidée n\'est pas ouverte pour le moment.</p><p><a href="' . $h($gallery ? gallery_public_url($gallery, $origin) : '/galerie/') . '">Retour</a></p></body></html>';
    exit;
}

$vocab = gt_of($gallery)['vocab'];
$galleryUrl = gallery_public_url($gallery, $origin);
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($gallery['accent'] ?? '')) ? $gallery['accent'] : '#2f6b4f';
$inkOn = static fn (string $hex): string => (preg_match('/^#[0-9a-fA-F]{6}$/', $hex) && (0.299 * hexdec(substr($hex, 1, 2)) + 0.587 * hexdec(substr($hex, 3, 2)) + 0.114 * hexdec(substr($hex, 5, 2))) > 160) ? '#1f1f1f' : '#ffffff';
$total = count($steps);
$n = max(1, min($total, (int) ($_GET['etape'] ?? 1)));
$step = $steps[$n - 1];
$stepUrl = static fn (int $i): string => '/galerie/visite.php?g=' . rawurlencode($slug) . ($i > 1 ? '&etape=' . $i : '');
$color = preg_match('/^#[0-9a-fA-F]{6}$/', $step['color']) ? $step['color'] : $accent;
$sectorUrl = $step['sector'] ? $galleryUrl . (str_contains($galleryUrl, '?') ? '&' : '?') . 'secteur=' . rawurlencode($step['sector']['slug']) . '#contenu' : '';
$members = (string) ($vocab['members'] ?? 'marchands');
$pageTitle = ($n === 1 ? 'Visite guidée' : $step['title']) . ' — ' . $gallery['name'];
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= $h($accent) ?>">
<title><?= $h($pageTitle) ?></title>
<style>
  :root { --a: <?= $h($accent) ?>; --ai: <?= $h($inkOn($accent)) ?>; --c: <?= $h($color) ?>; --ci: <?= $h($inkOn($color)) ?>; --line: #ddd; --soft: #666; }
  * { box-sizing: border-box; } body { margin: 0; font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1f1f; background: #f6f4ee; }
  header.top { background: var(--a); color: var(--ai); padding: 16px; } header.top .in, main { max-width: 680px; margin: 0 auto; }
  header.top a { color: inherit; font-size: .85rem; opacity: .9; } header.top p { margin: 6px 0 0; font-size: .8rem; letter-spacing: .08em; text-transform: uppercase; opacity: .85; }
  main { padding: 16px 16px 40px; display: grid; gap: 16px; }
  .dots { display: flex; gap: 6px; flex-wrap: wrap; margin: 0; padding: 0; list-style: none; } .dots a { display: block; width: 28px; height: 8px; border-radius: 99px; background: #d9d4c7; }
  .dots a[aria-current="step"] { background: var(--a); } .dots a.done { background: color-mix(in srgb, var(--a) 45%, #d9d4c7); }
  .stop { background: #fff; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
  .stop .band { background: var(--c); color: var(--ci); padding: 22px 20px 18px; } .stop .band small { display: block; font-size: .78rem; letter-spacing: .1em; text-transform: uppercase; opacity: .85; }
  .stop .band h1 { margin: 6px 0 0; font-size: clamp(1.5rem, 5vw, 2.1rem); line-height: 1.1; text-wrap: balance; }
  .stop .body { padding: 18px 20px 22px; display: grid; gap: 14px; } .stop .body p { margin: 0; }
  .shops { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .shops li { display: flex; gap: 12px; align-items: center; border: 1px solid #e6e1d6; border-radius: 12px; padding: 10px 12px; background: #fcfbf8; }
  .avatar { position: relative; width: 44px; height: 44px; flex: none; border-radius: 50%; display: grid; place-items: center; font-weight: 700; background: var(--c); color: var(--ci); overflow: hidden; }
  .avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; background: #fff; }
  .shops b { display: block; } .shops span.t { color: var(--soft); font-size: .88rem; } .shops a { color: inherit; text-decoration: none; } .shops a.go { margin-left: auto; color: var(--a); font-weight: 600; font-size: .88rem; white-space: nowrap; }
  .nav { display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
  .btn { font: inherit; font-weight: 600; padding: 11px 18px; border-radius: 12px; border: 1px solid var(--a); background: #fff; color: var(--a); text-decoration: none; display: inline-block; }
  .btn.primary { background: var(--a); color: var(--ai); } .btn[aria-disabled="true"] { opacity: .35; pointer-events: none; }
  .soft { color: var(--soft); font-size: .88rem; } .acts { display: flex; gap: 10px; flex-wrap: wrap; }
</style>
</head>
<body>
<header class="top"><div class="in">
  <a href="<?= $h($galleryUrl) ?>">← <?= $h($gallery['name']) ?></a>
  <p>Visite guidée · étape <?= (int) $n ?> sur <?= (int) $total ?></p>
</div></header>
<main>
  <ol class="dots" aria-label="Étapes de la visite">
    <?php for ($i = 1; $i <= $total; $i++): ?><li><a href="<?= $h($stepUrl($i)) ?>" title="<?= $h($steps[$i - 1]['title']) ?>"<?= $i === $n ? ' aria-current="step"' : ($i < $n ? ' class="done"' : '') ?>><span hidden>Étape <?= $i ?></span></a></li><?php endfor; ?>
  </ol>

  <article class="stop">
    <div class="band">
      <small><?= $h(['accueil' => 'Bienvenue', 'secteur' => 'Hall · bâtiment', 'marchand' => ucfirst($vocab['member'] ?? 'marchand'), 'ailleurs' => 'À découvrir aussi', 'fin' => 'Fin de la visite'][$step['kind']] ?? '') ?></small>
      <h1><?= $h($step['title']) ?></h1>
    </div>
    <div class="body">
      <?php if ($step['text'] !== ''): ?><p><?= nl2br($h($step['text'])) ?></p><?php endif; ?>
      <?php if ($step['kind'] === 'accueil'): ?>
        <p class="soft"><?= count($shops) ?> <?= $h(count($shops) > 1 ? $members : ($vocab['member'] ?? 'marchand')) ?> à rencontrer. Avancez à votre rythme, étape par étape.</p>
      <?php endif; ?>
      <?php if ($step['shops']): ?>
        <ul class="shops">
          <?php foreach ($step['shops'] as $key => $s): $initial = mb_strtoupper(mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', (string) $s['name']), 0, 1)); ?>
          <li>
            <span class="avatar" aria-hidden="true"><?= $h($initial) ?><?php if (($s['logo'] ?? '') !== ''): ?><img src="<?= $h($s['logo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
            <span><b><?= $h($s['name']) ?></b><?php if (($s['tagline'] ?? '') !== ''): ?><span class="t"><?= $h($s['tagline']) ?></span><?php endif; ?></span>
            <?php if (($s['url'] ?? '') !== ''): ?><a class="go" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener">Sa boutique ↗</a><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($sectorUrl !== ''): ?><p><a href="<?= $h($sectorUrl) ?>">Voir les pièces de ce hall →</a></p><?php endif; ?>
      <?php if ($step['kind'] === 'fin'): ?>
        <div class="acts">
          <a class="btn primary" href="<?= $h($galleryUrl) ?>#contenu">Voir tous les <?= $h($members) ?></a>
          <?php if (!empty($gallery['map']['items'])): ?><a class="btn" href="<?= $h($galleryUrl) ?>#plan-commerces">Ouvrir le plan</a><?php endif; ?>
          <?php if (gl_open($gallery)): ?><a class="btn" href="/galerie/visiteur.php?g=<?= $h(rawurlencode($slug)) ?>">📱 Application visiteurs</a><?php endif; ?>
          <a class="btn" href="<?= $h($stepUrl(1)) ?>">Refaire la visite</a>
        </div>
      <?php endif; ?>
    </div>
  </article>

  <nav class="nav" aria-label="Navigation de la visite">
    <a class="btn"<?= $n > 1 ? ' href="' . $h($stepUrl($n - 1)) . '" rel="prev"' : ' aria-disabled="true"' ?>>← Précédent</a>
    <?php if ($n < $total): ?><a class="btn primary" href="<?= $h($stepUrl($n + 1)) ?>" rel="next"><?= $n === 1 ? 'Commencer la visite' : 'Étape suivante' ?> →</a><?php endif; ?>
  </nav>
</main>
</body>
</html>
