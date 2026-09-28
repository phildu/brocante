<?php
require __DIR__ . '/_bootstrap.php';

$error = null;
$f = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $slug = create_tenant_from_form($f, $_FILES['logo'] ?? null);
        portail_flash('Commerce « ' . trim($f['name']) . " » créé dans tenants/$slug, à l'adresse $slug." . tenant_base_host() . '.');
        header('Location: /portail/?voir=' . rawurlencode($slug));
        exit;
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

// Valeurs de départ : un exemple rempli (boulangerie), remplaçable par les boutons « Partir de ».
$defaults = [
    'name' => 'Boulangerie Martin', 'tagline' => 'Pains au levain & viennoiseries maison', 'url' => 'https://www.boulangerie-martin.fr',
    'admin_user' => 'admin', 'accent' => '#b0622b', 'accent2' => '#5b7a4a', 'bg' => '#faf5ec',
    'item1' => 'produit', 'item2' => 'produits', 'cat_title' => 'Du four à votre table',
    'cat' => ['Pains', 'Viennoiseries', 'Pâtisseries', 'Épicerie fine', '', ''],
    'cat_icon' => ['ic-basket', 'ic-bowls', 'ic-candle', 'ic-pot', 'ic-vase', 'ic-vase'],
    'hero_eyebrow' => 'Cuit ce matin', 'hero_title' => 'Le bon pain, comme au fournil.',
    'hero_sub' => 'Farines locales, levain entretenu depuis quinze ans et cuisson au feu de bois : à réserver en ligne, à retirer tout chaud.',
    'story_title' => 'Une histoire de farine et de patience',
    'story_text' => 'Installés depuis 2009, nous pétrissons chaque nuit des pains à longue fermentation et des viennoiseries au beurre AOP.',
    'pr0' => 'Levain naturel', 'pr0t' => 'Fermentation lente, sans levure ajoutée.',
    'pr1' => 'Farines locales', 'pr1t' => 'Blés de moulins situés à moins de 50 km.',
    'pr2' => 'Fait maison', 'pr2t' => 'Tout est préparé sur place, chaque nuit.',
    'nl_title' => 'Les fournées spéciales, en avant-première', 'nl_text' => 'Un e-mail par semaine avec les pains du week-end. Pas plus.',
    'address' => '8 rue des Fours, 69004 Lyon', 'hours' => 'Du mardi au dimanche, 6 h 30 – 19 h 30',
    'delivery' => 'Retrait en boutique, livraison à vélo dans le quartier', 'pickup' => 'Retrait en boutique', 'fee' => '3,50 €',
    'p_name' => ['Tourte de seigle', 'Baguette tradition', 'Croissant au beurre', 'Tarte aux pralines', 'Confiture d\'abricot', ''],
    'p_price' => ['6,80 €', '1,30 €', '1,40 €', '18 €', '7,50 €', ''],
    'p_cat' => ['0', '0', '1', '2', '3', '0'],
    'p_desc' => ['Mie dense, croûte épaisse, se garde une semaine.', 'Farine Label Rouge, pétrie et façonnée à la main.', 'Beurre AOP Charentes-Poitou, feuilletage en trois jours.', 'Pour 6 personnes, pralines roses de Saint-Genix.', 'Abricots de la Drôme, cuits en chaudron.', ''],
    'p_badge' => ['Signature', '', 'Coup de cœur', '', 'Nouveau', ''],
];
$v = static function (string $key, ?int $i = null) use ($f, $defaults) {
    $src = $f ?: $defaults;
    $val = $src[$key] ?? '';
    return $i === null ? (string) $val : (string) ($val[$i] ?? '');
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nouveau commerce — Portail</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  .new-layout { display: grid; grid-template-columns: minmax(0, 520px) minmax(0, 1fr); gap: 18px; align-items: start; }
  @media (max-width: 1050px) { .new-layout { grid-template-columns: 1fr; } }
  .new-wrap { width: 100%; display: flex; flex-direction: column; gap: 12px; }

  .palettes { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 6px; }
  .palette { border: 1px solid var(--line); background: var(--panel-2); border-radius: 8px; padding: 6px; cursor: pointer; font-size: 0.78rem; text-align: left; display: flex; flex-direction: column; gap: 5px; }
  .palette[aria-pressed="true"] { outline: 2px solid var(--accent); outline-offset: 1px; }
  .swatches { display: flex; height: 18px; border-radius: 4px; overflow: hidden; }
  .swatches span { flex: 1; }
  .colors.four { grid-template-columns: repeat(4, 1fr); }
  @media (max-width: 560px) { .colors.four { grid-template-columns: repeat(2, 1fr); } }
  #contrast[data-kind="warn"] { color: var(--warn); }

  .pv-col { position: sticky; top: calc(env(safe-area-inset-top, 0px) + 12px); display: flex; flex-direction: column; gap: 10px; }
  @media (max-width: 1050px) { .pv-col { position: static; } }
  .pv-stage { background: var(--panel-2); border: 1px solid var(--line); border-radius: var(--radius); padding: 12px; display: flex; justify-content: center; max-height: calc(100dvh - 90px); overflow: auto; }
  .pv-stage.mobile .pv { width: 380px; max-width: 100%; }

  /* Maquette de la boutique : mêmes variables que assets/style.css. */
  .pv { width: 100%; background: var(--s-bg); color: var(--s-ink); font-family: var(--s-font-body); border: 1px solid var(--s-line); container-type: inline-size; }
  .pv-bar { display: flex; justify-content: space-between; align-items: center; gap: 10px 18px; padding: 14px 18px; border-bottom: 1px solid var(--s-line); flex-wrap: wrap; }
  .pv-brand { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; min-width: 0; }
  .pv-name { font-family: var(--s-font-display); font-weight: 600; font-size: 1.25rem; color: var(--s-accent); }
  .pv-tag { font-family: "Special Elite", "Courier New", monospace; font-size: 0.72rem; color: var(--s-ink-soft); }
  .pv-nav { display: flex; gap: 14px; align-items: center; font-size: 0.85rem; color: var(--s-ink-soft); flex-wrap: wrap; }
  .pv-nav .is-current { color: var(--s-ink); border-bottom: 2px solid var(--s-accent); padding-bottom: 2px; }
  .pv-cta { border: 1px solid var(--s-accent); color: var(--s-accent); padding: 4px 10px; font-weight: 600; }
  .pv-hero { padding: 34px 18px 26px; display: grid; gap: 12px; }
  .pv-eyebrow { margin: 0; font-family: "Special Elite", "Courier New", monospace; font-size: 0.72rem; letter-spacing: 0.14em; text-transform: uppercase; color: var(--s-accent); }
  .pv-title { margin: 0; font-family: var(--s-font-display); font-size: clamp(1.6rem, 5cqi, 2.6rem); line-height: 1.08; color: var(--s-ink); text-wrap: balance; }
  .pv-lede { margin: 0; color: var(--s-ink-soft); max-width: 56ch; }
  .pv-ctas { display: flex; gap: 10px; flex-wrap: wrap; }
  .pv-btn { padding: 10px 18px; font-weight: 700; font-size: 0.9rem; border: 1px solid var(--s-line); }
  .pv-btn.primary { background: var(--s-accent); border-color: var(--s-accent); color: var(--s-accent-ink); }
  .pv-btn.ghost { color: var(--s-ink); }
  .pv-section { padding: 18px; display: grid; gap: 10px; border-top: 1px solid var(--s-line); }
  .pv-h3 { margin: 0; font-family: var(--s-font-display); font-size: 1.35rem; color: var(--s-ink); }
  .pv-cats { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; }
  .pv-cat { background: var(--s-surface); border: 1px solid var(--s-line); padding: 12px 10px; display: grid; gap: 4px; justify-items: start; }
  .pv-cat svg { width: 30px; height: 30px; color: var(--s-accent-2); }
  .pv-cat b { font-family: var(--s-font-display); font-weight: 600; font-size: 0.95rem; color: var(--s-ink); }
  .pv-cat small { font-size: 0.72rem; color: var(--s-ink-soft); }
  .pv-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
  .pv-card { background: var(--s-surface); border: 1px solid var(--s-line); padding: 10px; display: flex; flex-direction: column; gap: 6px; }
  .pv-card .pic { aspect-ratio: 4 / 3; background: var(--s-surface-2); display: grid; place-items: center; color: var(--s-accent); }
  .pv-card .pic svg { width: 40px; height: 40px; }
  .pv-card .ref { font-family: "Special Elite", "Courier New", monospace; font-size: 0.66rem; color: var(--s-ink-soft); }
  .pv-card b { font-family: var(--s-font-display); font-size: 0.98rem; color: var(--s-ink); }
  .pv-card .desc { font-size: 0.78rem; color: var(--s-ink-soft); }
  .pv-card .foot { display: flex; justify-content: space-between; align-items: center; gap: 6px; margin-top: auto; }
  .pv-card .price { font-family: "Special Elite", "Courier New", monospace; color: var(--s-ink); }
  .pv-card .add { border: 1px solid var(--s-ink); color: var(--s-ink); padding: 3px 9px; font-size: 0.78rem; font-weight: 700; }
  .pv-card .badge { align-self: flex-start; font-family: "Special Elite", "Courier New", monospace; font-size: 0.62rem; letter-spacing: 0.06em; text-transform: uppercase; border: 1px solid var(--s-line); padding: 2px 6px; color: var(--s-ink-soft); }
  .pv-foot { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; padding: 14px 18px; border-top: 1px solid var(--s-line); background: var(--s-surface); font-size: 0.78rem; color: var(--s-ink-soft); }
  .pv-foot span:first-child { font-family: var(--s-font-display); font-weight: 600; color: var(--s-ink); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <h1>Nouveau commerce</h1>
      <p><a href="/portail/" style="color:inherit;">← Retour au portail</a></p>
    </div>
  </header>

  <div class="new-layout">
  <form class="new-wrap form-col" method="post" enctype="multipart/form-data" id="builder">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="presets">
      <span>Partir de :</span>
      <button type="button" class="chip" data-preset="boulangerie">Boulangerie</button>
      <button type="button" class="chip" data-preset="librairie">Librairie</button>
      <button type="button" class="chip" data-preset="vide">Page blanche</button>
    </div>

    <details class="box" open>
      <summary>Identité</summary>
      <div class="box-body">
        <label class="field">Nom du commerce<input name="name" id="f-name" required maxlength="60" value="<?= e($v('name')) ?>"></label>
        <label class="field">Identifiant — adresse &lt;identifiant&gt;.<?= e(tenant_base_host()) ?> (facultatif)<input name="slug" id="f-slug" maxlength="40" pattern="[a-z0-9][a-z0-9_-]*" placeholder="calculé à partir du nom, ex. naty" value="<?= e($v('slug')) ?>"></label>
        <label class="field">Slogan<input name="tagline" id="f-tagline" maxlength="80" value="<?= e($v('tagline')) ?>"></label>
        <label class="field">Logo (PNG, JPG, WebP ou SVG — facultatif)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"></label>
        <label class="field">Adresse du site<input name="url" id="f-url" value="<?= e($v('url')) ?>" placeholder="https://www.mon-commerce.fr"></label>
        <div class="two">
          <label class="field">Identifiant de l'administration<input name="admin_user" id="f-admin-user" required pattern="[A-Za-z0-9._@\-]{3,40}" maxlength="40" autocomplete="off" autocapitalize="none" value="<?= e($v('admin_user')) ?>" placeholder="ex. naty"></label>
          <label class="field">Mot de passe de l'administration
            <span class="password-field">
              <input type="password" name="admin_password" id="f-admin" required minlength="6" maxlength="60" autocomplete="new-password" placeholder="6 caractères minimum">
              <button type="button" class="password-toggle" id="f-admin-toggle" aria-controls="f-admin" aria-pressed="false">Afficher</button>
            </span>
          </label>
        </div>
        <p class="hint">Notez-les : le mot de passe est enregistré sous forme chiffrée et ne pourra pas être relu.</p>
      </div>
    </details>

    <details class="box" open>
      <summary>Apparence <small>aperçu à droite</small></summary>
      <div class="box-body">
        <div class="palettes" role="group" aria-label="Palettes toutes prêtes">
          <?php foreach (APPEARANCE_PALETTES as $key => [$label, $pBg, $pInk, $pAccent, $pAccent2]): ?>
            <button type="button" class="palette" aria-pressed="false"
                    data-colors="<?= e(json_encode(['bg' => $pBg, 'ink' => $pInk, 'accent' => $pAccent, 'accent2' => $pAccent2])) ?>">
              <span class="swatches"><span style="background:<?= e($pBg) ?>"></span><span style="background:<?= e($pAccent) ?>"></span><span style="background:<?= e($pAccent2) ?>"></span><span style="background:<?= e($pInk) ?>"></span></span>
              <?= e($label) ?>
            </button>
          <?php endforeach; ?>
        </div>
        <div class="colors four">
          <label class="field">Fond<input type="color" name="bg" id="f-bg" value="<?= e($v('bg')) ?>"></label>
          <label class="field">Texte<input type="color" name="ink" id="f-ink" value="<?= e($v('ink') ?: '#2b2620') ?>"></label>
          <label class="field">Principale<input type="color" name="accent" id="f-accent" value="<?= e($v('accent')) ?>"></label>
          <label class="field">Secondaire<input type="color" name="accent2" id="f-accent2" value="<?= e($v('accent2')) ?>"></label>
        </div>
        <p class="slug" id="contrast" role="status"></p>
        <div class="two">
          <label class="field">Police des titres
            <select name="font_display" id="f-font-display">
              <?php foreach (APPEARANCE_FONTS['display'] as $font => [, , $style]): ?>
                <option value="<?= e($font) ?>"<?= ($v('font_display') ?: APPEARANCE_BASE_FONTS['display']) === $font ? ' selected' : '' ?>><?= e("$font — $style") ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label class="field">Police des textes
            <select name="font_body" id="f-font-body">
              <?php foreach (APPEARANCE_FONTS['body'] as $font => [, , $style]): ?>
                <option value="<?= e($font) ?>"<?= ($v('font_body') ?: APPEARANCE_BASE_FONTS['body']) === $font ? ' selected' : '' ?>><?= e("$font — $style") ?></option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>
        <p class="hint">Couleurs et polices restent modifiables ensuite dans l'administration du commerce, page « Apparence ».</p>
      </div>
    </details>

    <details class="box" open>
      <summary>Catégories <small>jusqu'à 6</small></summary>
      <div class="box-body">
        <div class="two">
          <label class="field">Un article<input name="item1" id="f-item1" maxlength="30" value="<?= e($v('item1')) ?>"></label>
          <label class="field">Des articles<input name="item2" id="f-item2" maxlength="30" value="<?= e($v('item2')) ?>"></label>
        </div>
        <label class="field">Titre de la section catégories<input name="cat_title" id="f-cattitle" maxlength="80" value="<?= e($v('cat_title')) ?>"></label>
        <div class="rows">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <div class="row">
              <input name="cat[<?= $i ?>]" id="c<?= $i ?>" maxlength="40" placeholder="Catégorie <?= $i + 1 ?> (vide = aucune)" aria-label="Catégorie <?= $i + 1 ?>" value="<?= e($v('cat', $i)) ?>">
              <select name="cat_icon[<?= $i ?>]" id="ci<?= $i ?>" aria-label="Icône de la catégorie <?= $i + 1 ?>">
                <?php foreach (PORTAIL_ICONS as $icon => $label): ?>
                  <option value="<?= e($icon) ?>"<?= $v('cat_icon', $i) === $icon ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </details>

    <details class="box">
      <summary>Page d'accueil</summary>
      <div class="box-body">
        <label class="field">Surtitre<input name="hero_eyebrow" id="f-hero-eyebrow" maxlength="60" value="<?= e($v('hero_eyebrow')) ?>"></label>
        <label class="field">Accroche<input name="hero_title" id="f-hero-title" maxlength="120" value="<?= e($v('hero_title')) ?>"></label>
        <label class="field">Présentation<textarea name="hero_sub" id="f-hero-sub" maxlength="400"><?= e($v('hero_sub')) ?></textarea></label>
        <label class="field">Titre « Notre histoire »<input name="story_title" id="f-story-title" maxlength="120" value="<?= e($v('story_title')) ?>"></label>
        <label class="field">Texte « Notre histoire »<textarea name="story_text" id="f-story-text" maxlength="900"><?= e($v('story_text')) ?></textarea></label>
        <div class="two">
          <?php for ($n = 0; $n < 3; $n++): ?>
            <label class="field">Engagement <?= $n + 1 ?><input name="pr<?= $n ?>" id="f-pr<?= $n ?>" maxlength="60" value="<?= e($v("pr$n")) ?>"></label>
            <label class="field">En une phrase<input name="pr<?= $n ?>t" id="f-pr<?= $n ?>t" maxlength="160" value="<?= e($v("pr{$n}t")) ?>"></label>
          <?php endfor; ?>
        </div>
        <label class="field">Newsletter, accroche<input name="nl_title" id="f-nl-title" maxlength="100" value="<?= e($v('nl_title')) ?>"></label>
        <label class="field">Newsletter, promesse<input name="nl_text" id="f-nl-text" maxlength="200" value="<?= e($v('nl_text')) ?>"></label>
      </div>
    </details>

    <details class="box">
      <summary>Contact et livraison</summary>
      <div class="box-body">
        <label class="field">Adresse<input name="address" id="f-address" maxlength="160" value="<?= e($v('address')) ?>"></label>
        <label class="field">Horaires<input name="hours" id="f-hours" maxlength="160" value="<?= e($v('hours')) ?>"></label>
        <label class="field">Livraison<input name="delivery" id="f-delivery" maxlength="160" value="<?= e($v('delivery')) ?>"></label>
        <div class="two">
          <label class="field">Retrait<input name="pickup" id="f-pickup" maxlength="60" value="<?= e($v('pickup')) ?>"></label>
          <label class="field">Frais d'envoi<input name="fee" id="f-fee" maxlength="20" value="<?= e($v('fee')) ?>"></label>
        </div>
      </div>
    </details>

    <details class="box">
      <summary>Premiers produits <small>jusqu'à 6, modifiables ensuite dans l'administration</small></summary>
      <div class="box-body">
        <div class="rows">
          <?php for ($j = 0; $j < 6; $j++): ?>
            <div class="product">
              <span class="num">Produit <?= $j + 1 ?></span>
              <input name="p_name[<?= $j ?>]" id="p<?= $j ?>-name" maxlength="80" placeholder="Nom (vide = aucun)" aria-label="Nom du produit <?= $j + 1 ?>" value="<?= e($v('p_name', $j)) ?>">
              <input name="p_price[<?= $j ?>]" id="p<?= $j ?>-price" maxlength="20" placeholder="Prix" aria-label="Prix du produit <?= $j + 1 ?>" value="<?= e($v('p_price', $j)) ?>">
              <select name="p_cat[<?= $j ?>]" id="p<?= $j ?>-cat" aria-label="Catégorie du produit <?= $j + 1 ?>">
                <?php for ($k = 0; $k < 6; $k++): ?>
                  <option value="<?= $k ?>"<?= $v('p_cat', $j) === (string) $k ? ' selected' : '' ?>>Catégorie <?= $k + 1 ?></option>
                <?php endfor; ?>
              </select>
              <input name="p_badge[<?= $j ?>]" id="p<?= $j ?>-badge" maxlength="24" placeholder="Étiquette" aria-label="Étiquette du produit <?= $j + 1 ?>" value="<?= e($v('p_badge', $j)) ?>">
              <input class="full" name="p_desc[<?= $j ?>]" id="p<?= $j ?>-desc" maxlength="240" placeholder="Description" aria-label="Description du produit <?= $j + 1 ?>" value="<?= e($v('p_desc', $j)) ?>">
            </div>
          <?php endfor; ?>
        </div>
      </div>
    </details>

    <div class="send">
      <h2>Créer le commerce</h2>
      <p class="hint">Crée <code>tenants/&lt;identifiant&gt;/</code> (configuration et contenu de départ), sa base de données et son logo, puis l'ouvre à son adresse <code>&lt;identifiant&gt;.<?= e(tenant_base_host()) ?></code>. Pensez à ajouter le nouveau dossier à git pour le conserver.</p>
      <div class="send-actions"><button class="btn btn-primary" type="submit">Créer et afficher</button></div>
    </div>
  </form>

  <aside class="pv-col" aria-label="Aperçu de l'apparence">
    <div class="toolbar">
      <strong>Aperçu</strong>
      <div class="seg" id="pv-mode" role="group" aria-label="Thème de l'aperçu">
        <button type="button" data-mode="light" aria-pressed="true">Clair</button>
        <button type="button" data-mode="dark" aria-pressed="false">Sombre</button>
      </div>
      <div class="seg" id="pv-device" role="group" aria-label="Format de l'aperçu">
        <button type="button" data-device="desktop" aria-pressed="true">Ordinateur</button>
        <button type="button" data-device="mobile" aria-pressed="false">Mobile</button>
      </div>
    </div>
    <div class="pv-stage" id="pv-stage">
      <div class="pv" id="pv">
        <div class="pv-bar">
          <div class="pv-brand"><span class="pv-name" data-bind="name"></span><span class="pv-tag" data-bind="tagline"></span></div>
          <nav class="pv-nav"><span class="is-current">Accueil</span><span>La boutique</span><span>Contact</span><span>Panier</span><span class="pv-cta">Connexion</span></nav>
        </div>
        <div class="pv-hero">
          <p class="pv-eyebrow" data-bind="hero_eyebrow"></p>
          <h2 class="pv-title" data-bind="hero_title"></h2>
          <p class="pv-lede" data-bind="hero_sub"></p>
          <div class="pv-ctas"><span class="pv-btn primary">Voir la boutique</span><span class="pv-btn ghost">Notre histoire</span></div>
        </div>
        <div class="pv-section">
          <p class="pv-eyebrow">Explorer par catégorie</p>
          <h3 class="pv-h3" data-bind="cat_title"></h3>
          <div class="pv-cats" id="pv-cats"></div>
        </div>
        <div class="pv-section">
          <p class="pv-eyebrow">Cette semaine</p>
          <h3 class="pv-h3">Coups de cœur du moment</h3>
          <div class="pv-cards" id="pv-cards"></div>
        </div>
        <div class="pv-foot"><span data-bind="name"></span><span data-bind="address"></span></div>
      </div>
    </div>
  </aside>
  </div>
</div>
<script>
(function () {
  // Libellés des catégories dans les listes « catégorie du produit ».
  function refreshCats() {
    for (var j = 0; j < 6; j++) {
      var sel = document.getElementById('p' + j + '-cat');
      for (var k = 0; k < 6; k++) {
        var label = document.getElementById('c' + k).value.trim();
        sel.options[k].textContent = label || '(catégorie ' + (k + 1) + ' vide)';
        sel.options[k].disabled = !label;
      }
    }
  }
  document.getElementById('builder').addEventListener('input', function (e) {
    if (/^c\d$/.test(e.target.id)) refreshCats();
  });

  // Afficher / masquer le mot de passe saisi.
  var pwd = document.getElementById('f-admin');
  var pwdToggle = document.getElementById('f-admin-toggle');
  pwdToggle.addEventListener('click', function () {
    var show = pwd.type === 'password';
    pwd.type = show ? 'text' : 'password';
    pwdToggle.textContent = show ? 'Masquer' : 'Afficher';
    pwdToggle.setAttribute('aria-pressed', String(show));
    pwd.focus();
  });
  refreshCats();

  var PRESETS = {
    librairie: {
      'f-name': 'Librairie des Quais', 'f-tagline': "Livres anciens & d'occasion", 'f-url': 'https://librairie-des-quais.example.com',
      'f-accent': '#2f6b4f', 'f-accent2': '#7a4a2a', 'f-bg': '#f3f1ea', 'f-item1': 'livre', 'f-item2': 'livres', 'f-cattitle': 'Quatre rayons, mille histoires',
      cats: [['Romans', 'ic-cushion'], ['Beaux livres', 'ic-mirror'], ['Jeunesse', 'ic-basket'], ['Livres anciens', 'ic-candle']],
      'f-hero-eyebrow': 'Arrivages de la semaine', 'f-hero-title': 'Des livres qui ont déjà voyagé.',
      'f-hero-sub': 'Romans, beaux livres et éditions anciennes, choisis un à un sur les quais et dans les greniers.',
      'f-story-title': "Une boutique au bord de l'eau", 'f-story-text': 'Depuis vingt ans, nous rachetons des bibliothèques entières pour offrir une seconde vie aux livres.',
      'f-pr0': 'Vérifié page à page', 'f-pr0t': 'Chaque livre est contrôlé avant la mise en vente.',
      'f-pr1': 'Décrit honnêtement', 'f-pr1t': "L'état est précisé, défauts compris.",
      'f-pr2': 'Emballé avec soin', 'f-pr2t': 'Envoi protégé, partout en France.',
      'f-nl-title': 'Les nouveaux arrivages, avant tout le monde', 'f-nl-text': 'Un e-mail par mois avec nos plus belles trouvailles. Pas plus.',
      'f-address': '12 quai Romain Rolland, 69005 Lyon', 'f-hours': 'Du mardi au samedi, 10 h – 19 h', 'f-delivery': 'Retrait en boutique ou envoi soigné partout en France',
      'f-pickup': 'Retrait en boutique', 'f-fee': '4,90 €',
      products: [['Les Misérables, édition 1950', '35 €', 3, 'Deux volumes reliés, bon état général.', 'Rare'], ['Le Petit Prince', '6,50 €', 2, 'Édition Folio, quelques annotations au crayon.', ''], ['Atlas des vins de France', '22 €', 1, 'Beau livre illustré, grand format, comme neuf.', 'Coup de cœur'], ['Madame Bovary', '3 €', 0, 'Livre de poche, couverture légèrement pliée.', '']]
    },
    vide: {
      'f-name': '', 'f-tagline': '', 'f-url': '', 'f-accent': '#1e5f8c', 'f-accent2': '#6a6f3a', 'f-bg': '#f6f5f1',
      'f-item1': 'article', 'f-item2': 'articles', 'f-cattitle': 'Nos catégories', cats: [['', 'ic-vase']],
      'f-hero-eyebrow': '', 'f-hero-title': '', 'f-hero-sub': '', 'f-story-title': '', 'f-story-text': '',
      'f-pr0': '', 'f-pr0t': '', 'f-pr1': '', 'f-pr1t': '', 'f-pr2': '', 'f-pr2t': '', 'f-nl-title': '', 'f-nl-text': '',
      'f-address': '', 'f-hours': '', 'f-delivery': '', 'f-pickup': 'Retrait en boutique', 'f-fee': '6,90 €', products: []
    }
  };
  var initial = {};
  document.querySelectorAll('#builder input:not([type=file]):not([type=hidden]), #builder textarea, #builder select').forEach(function (el) { initial[el.id] = el.value; });

  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = PRESETS[b.dataset.preset];
      if (!p) { Object.keys(initial).forEach(function (id) { document.getElementById(id).value = initial[id]; }); refreshCats(); return; }
      Object.keys(p).forEach(function (id) { var el = document.getElementById(id); if (el) el.value = p[id]; });
      for (var i = 0; i < 6; i++) {
        document.getElementById('c' + i).value = p.cats[i] ? p.cats[i][0] : '';
        document.getElementById('ci' + i).value = p.cats[i] ? p.cats[i][1] : 'ic-vase';
      }
      for (var j = 0; j < 6; j++) {
        var pr = p.products[j] || ['', '', 0, '', ''];
        document.getElementById('p' + j + '-name').value = pr[0];
        document.getElementById('p' + j + '-price').value = pr[1];
        document.getElementById('p' + j + '-cat').value = String(pr[2]);
        document.getElementById('p' + j + '-desc').value = pr[3];
        document.getElementById('p' + j + '-badge').value = pr[4];
      }
      document.getElementById('f-slug').value = '';
      refreshCats();
    });
  });
})();

// ── Aperçu de l'apparence (même calcul qu'appearance_derive() en PHP) ──
(function () {
  var FONTS = <?= json_encode(array_map(static fn ($list) => array_map(static fn ($font) => [
      'css' => $font[0],
      'fallback' => $font[1],
  ], $list), APPEARANCE_FONTS), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  var pv = document.getElementById('pv');
  var mode = 'light';
  function $(id) { return document.getElementById(id); }
  function val(id) { var el = $(id); return el ? el.value.trim() : ''; }

  function rgb(h) { return [1, 3, 5].map(function (i) { return parseInt(h.substr(i, 2), 16); }); }
  function hex(c) { return '#' + c.map(function (v) { return Math.round(v).toString(16).padStart(2, '0'); }).join(''); }
  function mix(a, b, t) { var x = rgb(a), y = rgb(b); return hex(x.map(function (v, i) { return v + (y[i] - v) * t; })); }
  function lum(h) {
    var c = rgb(h).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function on(h) { return lum(h) > 0.4 ? '#1d1a16' : '#fffaf2'; }
  function ratio(a, b) { var x = lum(a) + 0.05, y = lum(b) + 0.05; return Math.max(x, y) / Math.min(x, y); }
  function derive(bg, ink, a, a2) {
    if (mode === 'dark') {
      var dBg = mix(ink, '#000000', 0.35), dInk = mix(bg, '#ffffff', 0.15);
      var dA = mix(a, '#ffffff', 0.3), dA2 = mix(a2, '#ffffff', 0.35);
      return { bg: dBg, surface: mix(dBg, dInk, 0.06), 'surface-2': mix(dBg, dInk, 0.11), ink: dInk,
        'ink-soft': mix(dInk, dBg, 0.3), accent: dA, 'accent-ink': on(dA), 'accent-2': dA2, line: mix(dBg, dInk, 0.22) };
    }
    return { bg: bg, surface: mix(bg, ink, 0.06), 'surface-2': mix(bg, ink, 0.12), ink: ink,
      'ink-soft': mix(ink, bg, 0.38), accent: a, 'accent-ink': on(a), 'accent-2': a2, line: mix(bg, ink, 0.25) };
  }
  function font(role, name) {
    var f = FONTS[role][name]; if (!f) return '';
    var id = 'pv-font-' + name.replace(/\W+/g, '-');
    if (!document.getElementById(id)) {
      var link = document.createElement('link');
      link.id = id; link.rel = 'stylesheet';
      link.href = 'https://fonts.googleapis.com/css2?family=' + f.css + '&display=swap';
      document.head.appendChild(link);
    }
    return '"' + name + '", ' + f.fallback;
  }
  function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
  function icon(id) {
    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 64 64');
    var use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', '#' + id); svg.appendChild(use); return svg;
  }

  function render() {
    var bg = val('f-bg'), ink = val('f-ink'), a = val('f-accent'), a2 = val('f-accent2');
    var vars = derive(bg, ink, a, a2);
    Object.keys(vars).forEach(function (k) { pv.style.setProperty('--s-' + k, vars[k]); });
    pv.style.setProperty('--s-font-display', font('display', val('f-font-display')));
    pv.style.setProperty('--s-font-body', font('body', val('f-font-body')));

    var texts = {
      name: val('f-name') || 'Mon commerce', tagline: val('f-tagline'),
      hero_eyebrow: val('f-hero-eyebrow'), hero_title: val('f-hero-title') || 'Votre accroche', hero_sub: val('f-hero-sub'),
      cat_title: val('f-cattitle') || 'Nos catégories', address: val('f-address')
    };
    pv.querySelectorAll('[data-bind]').forEach(function (n) { n.textContent = texts[n.dataset.bind] || ''; });

    // Catégories et produits du formulaire.
    var item1 = val('f-item1') || 'article', item2 = val('f-item2') || 'articles';
    var cats = [], counts = {};
    for (var i = 0; i < 6; i++) {
      var label = val('c' + i);
      if (label) cats.push({ i: i, label: label, icon: val('ci' + i) });
    }
    var products = [];
    for (var j = 0; j < 6; j++) {
      var name = val('p' + j + '-name');
      if (!name) continue;
      var ci = val('p' + j + '-cat');
      counts[ci] = (counts[ci] || 0) + 1;
      products.push({ name: name, price: val('p' + j + '-price'), desc: val('p' + j + '-desc'), badge: val('p' + j + '-badge'),
        ref: String(products.length + 1).padStart(3, '0'), icon: val('ci' + ci) || 'ic-vase' });
    }
    var catBox = $('pv-cats'); catBox.textContent = '';
    cats.forEach(function (c) {
      var n = counts[c.i] || 0, tile = el('div', 'pv-cat');
      tile.appendChild(icon(c.icon)); tile.appendChild(el('b', '', c.label)); tile.appendChild(el('small', '', n + ' ' + (n === 1 ? item1 : item2)));
      catBox.appendChild(tile);
    });
    var cardBox = $('pv-cards'); cardBox.textContent = '';
    products.slice(0, 3).forEach(function (p) {
      var card = el('div', 'pv-card'), pic = el('div', 'pic');
      pic.appendChild(icon(p.icon)); card.appendChild(pic);
      card.appendChild(el('span', 'ref', 'Réf. N°' + p.ref));
      card.appendChild(el('b', '', p.name));
      if (p.desc) card.appendChild(el('span', 'desc', p.desc));
      if (p.badge) card.appendChild(el('span', 'badge', p.badge));
      var foot = el('div', 'foot'); foot.appendChild(el('span', 'price', p.price)); foot.appendChild(el('span', 'add', 'Ajouter'));
      card.appendChild(foot); cardBox.appendChild(card);
    });

    // Lisibilité et palette sélectionnée.
    var r1 = ratio(ink, bg), r2 = ratio(on(a), a), note = $('contrast'), ok = r1 >= 4.5 && r2 >= 3;
    note.dataset.kind = ok ? '' : 'warn';
    note.textContent = 'Contraste texte/fond ' + r1.toFixed(1) + ':1 · boutons ' + r2.toFixed(1) + ':1 — ' + (ok ? 'lisible' : 'peu lisible, foncez le texte ou éclaircissez le fond');
    document.querySelectorAll('.palette').forEach(function (b) {
      var p = JSON.parse(b.dataset.colors);
      b.setAttribute('aria-pressed', String(p.bg === bg.toLowerCase() && p.ink === ink.toLowerCase() && p.accent === a.toLowerCase() && p.accent2 === a2.toLowerCase()));
    });
  }

  document.querySelectorAll('.palette').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.dataset.colors);
      $('f-bg').value = p.bg; $('f-ink').value = p.ink; $('f-accent').value = p.accent; $('f-accent2').value = p.accent2;
      render();
    });
  });
  function press(group, btn) { group.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === btn)); }); }
  $('pv-mode').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    mode = b.dataset.mode; press(this, b); render();
  });
  $('pv-device').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    press(this, b); $('pv-stage').classList.toggle('mobile', b.dataset.device === 'mobile');
  });
  $('builder').addEventListener('input', render);
  $('builder').addEventListener('change', render);
  document.querySelectorAll('[data-preset]').forEach(function (b) { b.addEventListener('click', render); });
  render();
})();
</script>
</body>
</html>
