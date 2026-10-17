<?php
// Médiathèque du pack Communication : remplace l'entrée « Médiathèque » du menu (la page classique reste accessible).
// L'interface est construite par assets/mediatheque.js, avec les outils communs de assets/picker.js (window.ComMedia).
defined('PLUGIN_RUNTIME') || exit;
?>
<link rel="stylesheet" href="<?= h($pluginAsset('mediatheque.css')) ?>">
<p class="eyebrow">Pack Communication</p>
<h1 style="font-size:1.8rem;margin:12px 0 12px;">Médiathèque</h1>
<p class="hint">
  Toutes les images et vidéos de la boutique : vos envois et les photos des fiches produit (celles-ci se gèrent dans la galerie
  de chaque pièce). Donnez-leur un titre, un texte alternatif pour l'accessibilité et le référencement, un crédit, des
  mots-clés et un dossier. Une version web allégée est créée pour chaque image.
  <a href="/admin/media.php">Vue classique</a>
</p>

<div class="com-media" id="com-media">
  <div class="com-drop" id="com-drop">
    <p><strong>Glissez ici des photos ou des vidéos</strong>, ou
      <button type="button" class="btn-small" id="com-choose">choisissez des fichiers</button></p>
    <p class="com-small" id="com-limits"></p>
    <input type="file" id="com-files" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm" hidden>
  </div>
  <ul class="com-uploads" id="com-uploads"></ul>

  <div class="com-adds">
    <form id="com-import" class="com-inline">
      <input type="url" name="url" placeholder="Importer une image ou une vidéo depuis une adresse web" required aria-label="Adresse du fichier à importer">
      <button type="submit" class="btn-small">Importer</button>
    </form>
    <form id="com-link" class="com-inline">
      <input type="url" name="url" placeholder="Adresse d'une vidéo YouTube ou Vimeo" required aria-label="Adresse YouTube ou Vimeo">
      <button type="submit" class="btn-small">Ajouter la vidéo en ligne</button>
    </form>
  </div>

  <div class="com-toolbar">
    <input type="search" id="com-q" placeholder="Rechercher (titre, mots-clés, crédit, produit…)" aria-label="Rechercher">
    <select id="com-type" aria-label="Type">
      <option value="">Tout</option><option value="photo">Photos</option><option value="video">Vidéos</option><option value="link">Vidéos en ligne</option>
    </select>
    <select id="com-group" aria-label="Classer par">
      <option value="folder">Par dossier</option><option value="product">Par produit</option><option value="source">Par source</option><option value="month">Par mois</option>
    </select>
    <select id="com-sort" aria-label="Trier">
      <option value="recent">Plus récents</option><option value="old">Plus anciens</option><option value="name">Nom</option><option value="size">Poids</option><option value="ratio">Format (large → haut)</option>
    </select>
    <button type="button" class="btn-small" id="com-fold">Tout replier</button>
  </div>
  <p class="com-stats" id="com-stats" role="status"></p>
  <p class="com-web" id="com-web-row" hidden><button type="button" class="btn-small" id="com-web-all"></button></p>
  <div id="com-groups"></div>
</div>

<datalist id="com-folders"></datalist>

<dialog class="com-edit" id="com-edit">
  <form method="dialog" class="com-edit-form" id="com-edit-form">
    <div class="com-edit-preview" id="com-edit-preview"></div>
    <div class="com-edit-fields">
      <p class="eyebrow" id="com-edit-kind"></p>
      <label>Titre<input type="text" name="title" maxlength="200"></label>
      <label>Texte alternatif <small>(décrit l'image à qui ne la voit pas)</small><textarea name="alt" maxlength="300" rows="2"></textarea></label>
      <label>Crédit <small>(photographe, source)</small><input type="text" name="credit" maxlength="150"></label>
      <label>Mots-clés <small>(séparés par des virgules)</small><input type="text" name="tags" maxlength="300"></label>
      <label>Dossier<input type="text" name="folder" maxlength="60" list="com-folders"></label>
      <label class="com-check"><input type="checkbox" name="in_gallery"> Montrer dans la galerie publique</label>
      <dl class="com-info" id="com-edit-info"></dl>
      <p class="com-edit-status" id="com-edit-status" role="status"></p>
      <div class="com-edit-actions">
        <button type="button" class="btn btn-primary" data-act="save">Enregistrer</button>
        <button type="button" class="btn-small" data-act="ai">Proposer avec l'IA</button>
        <button type="button" class="btn-small" data-act="web">Créer la version web</button>
        <button type="button" class="btn-small" data-act="copy">Copier l'adresse</button>
        <button type="button" class="btn-small com-danger" data-act="delete">Retirer de la médiathèque</button>
        <button type="button" class="btn-small" data-act="close">Fermer</button>
      </div>
    </div>
  </form>
</dialog>
<script src="<?= h($pluginAsset('mediatheque.js')) ?>" defer></script>
