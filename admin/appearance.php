<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$flash = flash_get();
$current = appearance_current();
$colors = $current['colors'];
$fonts = $current['fonts'];

// Données pour l'aperçu en direct (même calcul qu'appearance_derive, côté navigateur).
$fontCatalog = [];
foreach (APPEARANCE_FONTS as $role => $list) {
    foreach ($list as $name => [$param, $fallback]) {
        $fontCatalog[$role][$name] = ['url' => 'https://fonts.googleapis.com/css2?family=' . $param . '&display=swap', 'stack' => '"' . $name . '", ' . $fallback];
    }
}
$colorFields = [
    'bg' => ['Fond', 'Couleur du fond des pages'],
    'ink' => ['Texte', 'Titres et textes'],
    'accent' => ['Principale', 'Boutons, liens, prix'],
    'accent-2' => ['Secondaire', 'Pictogrammes, détails'],
];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Apparence — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .apx-grid { display: grid; grid-template-columns: minmax(0, 400px) minmax(0, 1fr); gap: 28px; align-items: start; margin-top: 20px; }
  @media (max-width: 960px) { .apx-grid { grid-template-columns: 1fr; } }
  .apx-form { display: flex; flex-direction: column; gap: 22px; }
  .apx-block { background: var(--surface); border: 1px solid var(--line); padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; }
  .apx-block h2 { font-size: 1.1rem; margin: 0; }
  .apx-palettes { display: grid; grid-template-columns: repeat(auto-fill, minmax(104px, 1fr)); gap: 8px; }
  .apx-palette { border: 1px solid var(--line); background: var(--bg); padding: 8px; cursor: pointer; text-align: left; font-family: var(--font-body); font-size: 0.8rem; color: var(--ink); display: flex; flex-direction: column; gap: 6px; }
  .apx-palette[aria-pressed="true"] { outline: 2px solid var(--accent); outline-offset: 1px; }
  .apx-swatches { display: flex; height: 22px; }
  .apx-swatches span { flex: 1; }
  .apx-colors { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
  .apx-color { display: grid; grid-template-columns: 44px 1fr; gap: 2px 10px; align-items: center; font-size: 0.85rem; }
  .apx-color input[type="color"] { grid-row: span 2; width: 44px; height: 44px; padding: 2px; border: 1px solid var(--line); background: var(--bg); cursor: pointer; }
  .apx-color small { color: var(--ink-soft); font-size: 0.75rem; }
  .apx-font label { display: flex; flex-direction: column; gap: 6px; font-size: 0.85rem; color: var(--ink-soft); }
  .apx-font select { background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font-family: var(--font-body); font-size: 0.95rem; }
  .apx-sample { font-size: 1.5rem; line-height: 1.2; color: var(--ink); }
  .apx-sample.body { font-size: 0.95rem; line-height: 1.5; }
  .apx-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .apx-contrast { font-size: 0.8rem; font-family: var(--font-mono); color: var(--ink-soft); }
  .apx-contrast[data-kind="warn"] { color: #b3261e; }

  .apx-preview-wrap { position: sticky; top: 16px; display: flex; flex-direction: column; gap: 10px; }
  .apx-preview-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; font-size: 0.85rem; color: var(--ink-soft); }
  .apx-mode { display: inline-flex; border: 1px solid var(--line); }
  .apx-mode button { background: var(--bg); border: 0; padding: 6px 12px; cursor: pointer; font-family: var(--font-body); color: var(--ink); }
  .apx-mode button[aria-pressed="true"] { background: var(--ink); color: var(--bg); }
  /* L'aperçu reprend les classes du site, avec ses propres variables. */
  .apx-preview { background: var(--bg); color: var(--ink); font-family: var(--font-body); border: 1px solid var(--line); overflow: hidden; }
  .apx-preview .apx-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 20px; border-bottom: 1px solid var(--line); flex-wrap: wrap; }
  .apx-preview .apx-name { font-family: var(--font-display); font-weight: 600; font-size: 1.2rem; color: var(--accent); }
  .apx-preview .apx-nav { display: flex; gap: 14px; align-items: center; font-size: 0.85rem; color: var(--ink-soft); flex-wrap: wrap; }
  .apx-preview .apx-nav .cta { border: 1px solid var(--accent); color: var(--accent); padding: 5px 10px; font-weight: 600; }
  .apx-preview .apx-hero { padding: 30px 20px 24px; display: grid; gap: 14px; }
  .apx-preview .apx-hero h3 { font-family: var(--font-display); font-size: clamp(1.6rem, 3vw, 2.3rem); line-height: 1.1; margin: 0; color: var(--ink); text-wrap: balance; }
  .apx-preview .apx-hero p { margin: 0; color: var(--ink-soft); max-width: 52ch; }
  .apx-preview .apx-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; padding: 0 20px 24px; }
  .apx-preview .apx-card { background: var(--surface); border: 1px solid var(--line); padding: 12px; display: flex; flex-direction: column; gap: 8px; }
  .apx-preview .apx-card .pic { aspect-ratio: 4 / 3; background: var(--surface-2); display: grid; place-items: center; color: var(--accent-2); }
  .apx-preview .apx-card .pic svg { width: 44px; height: 44px; }
  .apx-preview .apx-card .ref { font-family: var(--font-mono); font-size: 0.7rem; color: var(--ink-soft); }
  .apx-preview .apx-card h4 { font-family: var(--font-display); font-size: 1rem; margin: 0; color: var(--ink); }
  .apx-preview .apx-card .foot { display: flex; justify-content: space-between; align-items: center; }
  .apx-preview .apx-card .price { font-family: var(--font-mono); color: var(--ink); }
  .apx-preview .apx-card .add { border: 1px solid var(--ink); background: none; color: var(--ink); padding: 4px 10px; font-weight: 600; font-family: var(--font-body); }
  .apx-preview .apx-badge { align-self: flex-start; font-family: var(--font-mono); font-size: 0.65rem; letter-spacing: 0.06em; text-transform: uppercase; border: 1px solid var(--line); padding: 2px 6px; color: var(--ink-soft); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'apparence'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Identité visuelle</p>
    <h1 style="font-size:1.8rem;margin:12px 0 12px;">Apparence</h1>
    <p class="hint">Choisissez une palette toute prête ou vos propres couleurs, puis les polices des titres et des textes. L'aperçu se met à jour en direct ; rien ne change sur le site avant « Enregistrer ». Les nuances intermédiaires (fonds de cartes, filets, thème sombre) sont calculées automatiquement.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="apx-grid">
      <form class="apx-form" method="post" action="/admin/appearance-action.php" id="apx-form">
        <input type="hidden" name="action" value="save">

        <div class="apx-block">
          <h2>Palette</h2>
          <div class="apx-palettes" role="group" aria-label="Palettes toutes prêtes">
            <?php foreach (APPEARANCE_PALETTES as $key => [$label, $bg, $ink, $accent, $accent2]): ?>
              <button type="button" class="apx-palette" data-colors="<?= h(json_encode(['bg' => $bg, 'ink' => $ink, 'accent' => $accent, 'accent-2' => $accent2])) ?>" aria-pressed="false">
                <span class="apx-swatches"><span style="background:<?= h($bg) ?>"></span><span style="background:<?= h($accent) ?>"></span><span style="background:<?= h($accent2) ?>"></span><span style="background:<?= h($ink) ?>"></span></span>
                <?= h($label) ?>
              </button>
            <?php endforeach; ?>
          </div>
          <div class="apx-colors">
            <?php foreach ($colorFields as $key => [$label, $help]): ?>
              <label class="apx-color">
                <input type="color" name="colors[<?= h($key) ?>]" id="c-<?= h($key) ?>" value="<?= h($colors[$key]) ?>">
                <span><?= h($label) ?></span>
                <small><?= h($help) ?></small>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="apx-contrast" id="apx-contrast" role="status"></p>
        </div>

        <div class="apx-block apx-font">
          <h2>Typographie</h2>
          <label>Titres
            <select name="font_display" id="f-display">
              <?php foreach (APPEARANCE_FONTS['display'] as $name => [, , $style]): ?>
                <option value="<?= h($name) ?>"<?= $fonts['display'] === $name ? ' selected' : '' ?>><?= h("$name — $style") ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="apx-sample" id="s-display"><?= h($content['site_name'] ?: tenant('name')) ?></p>
          <label>Textes
            <select name="font_body" id="f-body">
              <?php foreach (APPEARANCE_FONTS['body'] as $name => [, , $style]): ?>
                <option value="<?= h($name) ?>"<?= $fonts['body'] === $name ? ' selected' : '' ?>><?= h("$name — $style") ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <p class="apx-sample body" id="s-body">Chaque pièce est choisie avec soin, décrite honnêtement et emballée pour voyager sans encombre.</p>
        </div>

        <div class="apx-actions">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <a class="btn btn-ghost" href="/index.php" target="_blank">Voir le site</a>
        </div>
      </form>

      <div class="apx-preview-wrap">
        <div class="apx-preview-bar">
          <span>Aperçu</span>
          <span class="apx-mode" role="group" aria-label="Thème de l'aperçu">
            <button type="button" data-mode="light" aria-pressed="true">Clair</button>
            <button type="button" data-mode="dark" aria-pressed="false">Sombre</button>
          </span>
        </div>
        <div class="apx-preview" id="apx-preview">
          <div class="apx-bar">
            <span class="apx-name"><?= h($content['site_name'] ?: tenant('name')) ?></span>
            <span class="apx-nav"><span>Accueil</span><span>La boutique</span><span>Panier</span><span class="cta">Connexion</span></span>
          </div>
          <div class="apx-hero">
            <p class="eyebrow" style="margin:0;"><?= h($content['hero_eyebrow'] ?: 'Nouveautés') ?></p>
            <h3><?= h($content['hero_title'] ?: tenant('tagline')) ?></h3>
            <p><?= h(mb_strimwidth((string) ($content['hero_subtitle'] ?: 'Une sélection renouvelée chaque semaine.'), 0, 180, '…')) ?></p>
            <div class="hero-ctas" style="display:flex;gap:10px;flex-wrap:wrap;">
              <span class="btn btn-primary">Voir la boutique</span>
              <span class="btn btn-ghost">Notre histoire</span>
            </div>
          </div>
          <div class="apx-cards">
            <?php foreach (array_slice(get_products(), 0, 3) ?: [['ref' => '001', 'name' => 'Premier produit', 'price' => '20 €', 'cat' => '']] as $p): ?>
              <div class="apx-card">
                <div class="pic"><svg viewBox="0 0 64 64"><use href="#<?= h(category_icon((string) $p['cat'])) ?>"/></svg></div>
                <span class="ref">Réf. N°<?= h($p['ref']) ?></span>
                <h4><?= h($p['name']) ?></h4>
                <span class="apx-badge">Coup de cœur</span>
                <div class="foot"><span class="price"><?= h(effective_price($p)) ?></span><span class="add">Ajouter</span></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <?php if ($current['custom']): ?>
      <form method="post" action="/admin/appearance-action.php" style="margin-top:28px;">
        <input type="hidden" name="action" value="reset">
        <p class="hint">Apparence personnalisée active. <button class="btn btn-ghost" type="submit" style="margin-left:8px;">Revenir à l'apparence d'origine</button></p>
      </form>
    <?php endif; ?>
  </div>
</section>
</main>
<script>
(function () {
  var FONTS = <?= json_encode($fontCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var preview = document.getElementById('apx-preview');
  var mode = 'light';
  var keys = ['bg', 'ink', 'accent', 'accent-2'];

  function rgb(h) { return [1, 3, 5].map(function (i) { return parseInt(h.substr(i, 2), 16); }); }
  function hex(c) { return '#' + c.map(function (v) { return Math.round(v).toString(16).padStart(2, '0'); }).join(''); }
  function mix(a, b, t) { var x = rgb(a), y = rgb(b); return hex(x.map(function (v, i) { return v + (y[i] - v) * t; })); }
  function lum(h) {
    var c = rgb(h).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function on(h) { return lum(h) > 0.4 ? '#1d1a16' : '#fffaf2'; }
  function ratio(a, b) { var x = lum(a) + 0.05, y = lum(b) + 0.05; return Math.max(x, y) / Math.min(x, y); }

  // Même calcul qu'appearance_derive() en PHP.
  function derive(c) {
    var dBg = mix(c.ink, '#000000', 0.35), dInk = mix(c.bg, '#ffffff', 0.15);
    var dA = mix(c.accent, '#ffffff', 0.3), dA2 = mix(c['accent-2'], '#ffffff', 0.35);
    return {
      light: { bg: c.bg, surface: mix(c.bg, c.ink, 0.06), 'surface-2': mix(c.bg, c.ink, 0.12), ink: c.ink,
        'ink-soft': mix(c.ink, c.bg, 0.38), accent: c.accent, 'accent-ink': on(c.accent), 'accent-2': c['accent-2'],
        sage: c['accent-2'], line: mix(c.bg, c.ink, 0.25) },
      dark: { bg: dBg, surface: mix(dBg, dInk, 0.06), 'surface-2': mix(dBg, dInk, 0.11), ink: dInk,
        'ink-soft': mix(dInk, dBg, 0.3), accent: dA, 'accent-ink': on(dA), 'accent-2': dA2, sage: dA2, line: mix(dBg, dInk, 0.22) }
    };
  }

  function loadFont(role, name) {
    var f = FONTS[role][name]; if (!f) return '';
    var id = 'apx-font-' + name.replace(/\W+/g, '-');
    if (!document.getElementById(id)) {
      var link = document.createElement('link'); link.id = id; link.rel = 'stylesheet'; link.href = f.url;
      document.head.appendChild(link);
    }
    return f.stack;
  }

  function current() {
    var c = {};
    keys.forEach(function (k) { c[k] = document.getElementById('c-' + k).value; });
    return c;
  }

  function render() {
    var c = current(), vars = derive(c)[mode];
    Object.keys(vars).forEach(function (k) { preview.style.setProperty('--' + k, vars[k]); });
    var display = loadFont('display', document.getElementById('f-display').value);
    var body = loadFont('body', document.getElementById('f-body').value);
    preview.style.setProperty('--font-display', display);
    preview.style.setProperty('--font-body', body);
    document.getElementById('s-display').style.fontFamily = display;
    document.getElementById('s-body').style.fontFamily = body;

    // Lisibilité : contraste du texte sur le fond et du texte des boutons.
    var r1 = ratio(c.ink, c.bg), r2 = ratio(on(c.accent), c.accent), note = document.getElementById('apx-contrast');
    var ok = r1 >= 4.5 && r2 >= 3;
    note.dataset.kind = ok ? '' : 'warn';
    note.textContent = 'Contraste texte/fond ' + r1.toFixed(1) + ':1 · boutons ' + r2.toFixed(1) + ':1'
      + (ok ? ' — lisible' : ' — peu lisible, foncez le texte ou éclaircissez le fond');

    document.querySelectorAll('.apx-palette').forEach(function (b) {
      var p = JSON.parse(b.dataset.colors);
      b.setAttribute('aria-pressed', String(keys.every(function (k) { return p[k].toLowerCase() === c[k].toLowerCase(); })));
    });
  }

  document.querySelectorAll('.apx-palette').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.dataset.colors);
      keys.forEach(function (k) { document.getElementById('c-' + k).value = p[k]; });
      render();
    });
  });
  document.getElementById('apx-form').addEventListener('input', render);
  document.getElementById('apx-form').addEventListener('change', render);
  document.querySelectorAll('.apx-mode button').forEach(function (b) {
    b.addEventListener('click', function () {
      mode = b.dataset.mode;
      document.querySelectorAll('.apx-mode button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
      render();
    });
  });
  render();
})();
</script>
</body>
</html>
