<?php
require __DIR__ . '/_bootstrap.php';

$error = null;
$f = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $slug = create_tenant_from_form($f, $_FILES['logo'] ?? null);
        set_active_slug($slug);
        portail_flash('Commerce « ' . trim($f['name']) . " » créé dans tenants/$slug et affiché par le site.");
        header('Location: /portail/');
        exit;
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
    }
}

// Valeurs de départ : un exemple rempli (boulangerie), remplaçable par les boutons « Partir de ».
$defaults = [
    'name' => 'Boulangerie Martin', 'tagline' => 'Pains au levain & viennoiseries maison', 'url' => 'https://www.boulangerie-martin.fr',
    'admin_password' => '', 'accent' => '#b0622b', 'accent2' => '#5b7a4a', 'bg' => '#faf5ec',
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
  .new-wrap { max-width: 760px; width: 100%; display: flex; flex-direction: column; gap: 12px; }
</style>
</head>
<body>
<div class="app">
  <header class="topbar">
    <div class="brand">
      <h1>Nouveau commerce</h1>
      <p><a href="/portail/" style="color:inherit;">← Retour au portail</a></p>
    </div>
  </header>

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
        <label class="field">Identifiant (dossier tenants/, facultatif)<input name="slug" id="f-slug" maxlength="40" pattern="[a-z0-9][a-z0-9_-]*" placeholder="calculé à partir du nom" value="<?= e($v('slug')) ?>"></label>
        <label class="field">Slogan<input name="tagline" id="f-tagline" maxlength="80" value="<?= e($v('tagline')) ?>"></label>
        <label class="field">Logo (PNG, JPG, WebP ou SVG — facultatif)<input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml"></label>
        <div class="colors">
          <label class="field">Couleur principale<input type="color" name="accent" id="f-accent" value="<?= e($v('accent')) ?>"></label>
          <label class="field">Couleur secondaire<input type="color" name="accent2" id="f-accent2" value="<?= e($v('accent2')) ?>"></label>
          <label class="field">Fond<input type="color" name="bg" id="f-bg" value="<?= e($v('bg')) ?>"></label>
        </div>
        <div class="two">
          <label class="field">Adresse du site<input name="url" id="f-url" value="<?= e($v('url')) ?>" placeholder="https://www.mon-commerce.fr"></label>
          <label class="field">Mot de passe admin<input name="admin_password" id="f-admin" value="<?= e($v('admin_password')) ?>" placeholder="vide = admin fermée"></label>
        </div>
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
      <p class="hint">Crée <code>tenants/&lt;identifiant&gt;/</code> (configuration et contenu de départ), sa base de données et son logo, puis l'affiche sur ce site. Pensez à ajouter le nouveau dossier à git pour le conserver.</p>
      <div class="send-actions"><button class="btn btn-primary" type="submit">Créer et afficher</button></div>
    </div>
  </form>
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
</script>
</body>
</html>
