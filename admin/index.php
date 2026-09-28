<?php
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$content = get_content();
$mediaPhotos = get_media_items('photo');
$flash = flash_get();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Réglages du site — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .media-pick-backdrop {
    position: fixed; inset: 0; background: rgba(20,15,8,0.7);
    display: none; align-items: center; justify-content: center; z-index: 100;
  }
  .media-pick-backdrop.is-open { display: flex; }
  .media-pick-box {
    background: var(--bg); border: 1px solid var(--line); padding: 20px;
    max-width: 92vw; max-height: 85vh; width: 720px; display: flex; flex-direction: column; gap: 14px;
  }
  .media-pick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; overflow-y: auto; max-height: 60vh; }
  .media-pick-item { border: 1px solid var(--line); background: var(--surface); cursor: pointer; padding: 0; }
  .media-pick-item img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; }
  .media-pick-item span { display: block; font-size: 0.68rem; padding: 4px 6px; color: var(--ink-soft); }
  .media-pick-item:hover { outline: 2px solid var(--accent); }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'reglages'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 20px;">Réglages du site</h1>

    <a class="btn btn-primary" href="/slideshow.php" target="_blank" style="margin-bottom:24px;display:inline-flex;">Lancer le diaporama boutique</a>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="admin-shell">

      <form class="admin-block" method="post" action="/admin/save-content.php" enctype="multipart/form-data">
        <h2>Identité du site</h2>
        <p class="hint">Le nom de la boutique, affiché dans l'en-tête, le pied de page et les titres d'onglet.</p>
        <div class="field-row-2">
          <div class="field"><label>Nom du site</label><input type="text" name="site_name" value="<?= h($content['site_name']) ?>"></div>
          <div class="field"><label>Signature</label><input type="text" name="site_tagline" value="<?= h($content['site_tagline']) ?>"></div>
        </div>

        <h2 style="margin-top:36px;">Page d'accueil</h2>
        <p class="hint">Le texte d'introduction et la photo mise en avant en haut du site.</p>
        <div class="field"><label>Eyebrow</label><input type="text" name="hero_eyebrow" value="<?= h($content['hero_eyebrow']) ?>"></div>
        <div class="field"><label>Titre</label><input type="text" name="hero_title" value="<?= h($content['hero_title']) ?>"></div>
        <div class="field"><label>Sous-titre</label><textarea name="hero_subtitle"><?= h($content['hero_subtitle']) ?></textarea></div>

        <div class="field"><label>Photo de couverture (repli si le diaporama est vide)</label></div>
        <p class="hint" style="margin:-4px 0 10px;">Le hero affiche désormais un diaporama de plusieurs photos en pile de polaroïds, mélangées avec les pièces en promo — <a href="/admin/hero.php" style="color:var(--accent);">gérez-le ici →</a>. Cette photo unique ne sert que si le diaporama est vide.</p>
        <div class="admin-photo-row">
          <div class="admin-photo-preview" data-preview="hero">
            <?php if (!empty($content['hero_photo'])): ?>
              <img src="/<?= h($content['hero_photo']) ?>" alt="">
            <?php else: ?>
              <svg viewBox="0 0 64 64"><use href="#ic-vase"/></svg>
            <?php endif; ?>
          </div>
          <label class="btn-small" style="cursor:pointer;">Envoyer une photo<input type="file" name="hero_photo" accept="image/*" style="display:none" data-photo-file="hero" onchange="this.form.querySelector('[data-hero-name]').textContent=this.files[0]?.name||''"></label>
          <button type="button" class="btn-small" data-media-pick="hero">Choisir dans la médiathèque</button>
          <input type="hidden" name="hero_photo_media" data-media-value="hero">
          <span data-hero-name style="font-size:0.8rem;color:var(--ink-soft);"></span>
        </div>

        <h2 style="margin-top:36px;">Notre histoire</h2>
        <p class="hint">Le récit affiché sur la page d'accueil, et les trois points qui le suivent.</p>
        <div class="field"><label>Eyebrow</label><input type="text" name="story_eyebrow" value="<?= h($content['story_eyebrow']) ?>"></div>
        <div class="field"><label>Titre</label><input type="text" name="story_title" value="<?= h($content['story_title']) ?>"></div>
        <div class="field"><label>Texte</label><textarea name="story_text" style="min-height:120px;"><?= h($content['story_text']) ?></textarea></div>

        <div class="field"><label>Photo d'illustration</label></div>
        <div class="admin-photo-row">
          <div class="admin-photo-preview" data-preview="story">
            <?php if (!empty($content['story_photo'])): ?>
              <img src="/<?= h($content['story_photo']) ?>" alt="">
            <?php else: ?>
              <svg viewBox="0 0 64 64"><use href="#ic-vase"/></svg>
            <?php endif; ?>
          </div>
          <label class="btn-small" style="cursor:pointer;">Envoyer une photo<input type="file" name="story_photo" accept="image/*" style="display:none" data-photo-file="story" onchange="this.form.querySelector('[data-story-name]').textContent=this.files[0]?.name||''"></label>
          <button type="button" class="btn-small" data-media-pick="story">Choisir dans la médiathèque</button>
          <input type="hidden" name="story_photo_media" data-media-value="story">
          <span data-story-name style="font-size:0.8rem;color:var(--ink-soft);"></span>
        </div>
        <div class="field"><label>Légende de la photo</label><input type="text" name="story_photo_caption" value="<?= h($content['story_photo_caption']) ?>"></div>

        <div class="field-row-2">
          <?php for ($i = 1; $i <= 3; $i++): ?>
            <div>
              <div class="field"><label>Point <?= $i ?> — titre</label><input type="text" name="principle<?= $i ?>_title" value="<?= h($content["principle{$i}_title"]) ?>"></div>
              <div class="field"><label>Point <?= $i ?> — texte</label><textarea name="principle<?= $i ?>_text"><?= h($content["principle{$i}_text"]) ?></textarea></div>
            </div>
          <?php endfor; ?>
        </div>

        <h2 style="margin-top:36px;">Visiter l'atelier</h2>
        <p class="hint">Coordonnées affichées en bas de la page d'accueil.</p>
        <div class="field"><label>Adresse</label><input type="text" name="contact_address" value="<?= h($content['contact_address']) ?>"></div>
        <div class="field"><label>Horaires</label><input type="text" name="contact_hours" value="<?= h($content['contact_hours']) ?>"></div>
        <div class="field"><label>Livraison</label><input type="text" name="contact_delivery" value="<?= h($content['contact_delivery']) ?>"></div>

        <h2 style="margin-top:36px;">Frais de port</h2>
        <p class="hint">Les deux modes de livraison proposés au client au moment du paiement Stripe.</p>
        <div class="field-row-3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
          <div class="field"><label>Libellé retrait (gratuit)</label><input type="text" name="pickup_label" value="<?= h($content['pickup_label']) ?>"></div>
          <div class="field"><label>Libellé envoi</label><input type="text" name="shipping_label" value="<?= h($content['shipping_label']) ?>"></div>
          <div class="field"><label>Tarif d'envoi</label><input type="text" name="shipping_fee" value="<?= h($content['shipping_fee']) ?>"></div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Enregistrer les contenus</button>
      </form>

      <form class="admin-block" method="post" action="/admin/save-stripe-keys.php" id="stripe-settings">
        <h2>Réglages Stripe</h2>
        <p class="hint">Clés de <a href="https://dashboard.stripe.com/test/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/test/apikeys</a> (mode test : <code>sk_test_...</code> / <code>pk_test_...</code>). Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field-row-2">
          <div class="field">
            <label>Clé secrète (sk_...)</label>
            <input type="password" name="stripe_secret" autocomplete="off" placeholder="<?= STRIPE_SECRET_KEY ? 'Déjà enregistrée — ' . h(substr(STRIPE_SECRET_KEY, 0, 7)) . '…' . h(substr(STRIPE_SECRET_KEY, -4)) : 'sk_test_...' ?>">
          </div>
          <div class="field">
            <label>Clé publiable (pk_...)</label>
            <input type="text" name="stripe_publishable" autocomplete="off" placeholder="<?= STRIPE_PUBLISHABLE_KEY ? 'Déjà enregistrée — ' . h(substr(STRIPE_PUBLISHABLE_KEY, 0, 7)) . '…' . h(substr(STRIPE_PUBLISHABLE_KEY, -4)) : 'pk_test_...' ?>">
          </div>
        </div>
        <p class="publish-status" data-kind="<?= stripe_configured() ? '' : 'error' ?>" style="margin-top:10px;">
          <?= stripe_configured() ? 'Paiement Stripe configuré et actif.' : 'Aucune clé secrète valide enregistrée — le paiement est désactivé sur le site.' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer les clés</button>
      </form>

      <form class="admin-block" method="post" action="/admin/save-gemini-key.php" id="gemini-settings">
        <h2>Réglages IA (Gemini)</h2>
        <p class="hint">Clé depuis <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">aistudio.google.com/apikey</a> — utilisée pour les générations de photos (angle, ambiance, netteté...) et les suggestions dans la médiathèque. Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field">
          <label>Clé API Gemini</label>
          <input type="password" name="gemini_key" autocomplete="off" placeholder="<?= GEMINI_API_KEY ? 'Déjà enregistrée — ' . h(substr(GEMINI_API_KEY, 0, 6)) . '…' . h(substr(GEMINI_API_KEY, -4)) : 'AIza...' ?>">
        </div>
        <p class="publish-status" data-kind="<?= GEMINI_API_KEY ? '' : 'error' ?>" style="margin-top:10px;">
          <?= GEMINI_API_KEY ? 'Clé Gemini configurée — les générations IA sont actives.' : 'Aucune clé enregistrée — les générations et suggestions IA sont désactivées.' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer la clé</button>
      </form>

      <form class="admin-block" method="post" action="/admin/save-fal-key.php" id="fal-settings">
        <h2>Réglages IA (fal.ai — détourage)</h2>
        <p class="hint">Clé depuis <a href="https://fal.ai/dashboard/keys" target="_blank" rel="noopener">fal.ai/dashboard/keys</a> — utilisée pour le détourage à vrai fond transparent dans la galerie photo, quand rembg local n'est pas disponible (hébergement mutualisé). Un champ laissé vide conserve la clé déjà enregistrée.</p>
        <div class="field">
          <label>Clé API fal.ai</label>
          <input type="password" name="fal_key" autocomplete="off" placeholder="<?= FAL_API_KEY ? 'Déjà enregistrée — ' . h(substr(FAL_API_KEY, 0, 6)) . '…' . h(substr(FAL_API_KEY, -4)) : 'key_...' ?>">
        </div>
        <p class="publish-status" data-kind="<?= FAL_API_KEY ? '' : 'error' ?>" style="margin-top:10px;">
          <?= FAL_API_KEY ? 'Clé fal.ai configurée — le détourage à fond transparent est actif.' : 'Aucune clé enregistrée — le détourage utilisera un repli fond blanc (Gemini) si disponible, sinon sera désactivé.' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer la clé</button>
      </form>

    </div>
  </div>
</section>
</main>

<div class="media-pick-backdrop" id="media-pick-backdrop">
  <div class="media-pick-box">
    <p class="eyebrow" style="margin:0;">Choisir une photo dans la médiathèque</p>
    <?php if (!$mediaPhotos): ?>
      <p class="empty-state">La médiathèque est vide pour l'instant. <a href="/admin/media.php">Ajouter des photos →</a></p>
    <?php else: ?>
      <div class="media-pick-grid">
        <?php foreach ($mediaPhotos as $m): ?>
          <button type="button" class="media-pick-item" data-media-path="<?= h($m['path']) ?>">
            <img src="/<?= h($m['path']) ?>" alt="<?= h($m['label']) ?>">
            <span><?= h($m['label']) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;justify-content:flex-end;">
      <a class="btn-small" href="/admin/media.php" target="_blank">Gérer la médiathèque →</a>
      <button type="button" class="btn-small" id="media-pick-cancel">Annuler</button>
    </div>
  </div>
</div>

<script>
(function () {
  var backdrop = document.getElementById('media-pick-backdrop');
  var currentTarget = null;

  document.querySelectorAll('[data-media-pick]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      currentTarget = btn.dataset.mediaPick;
      backdrop.classList.add('is-open');
    });
  });

  document.getElementById('media-pick-cancel').addEventListener('click', function () {
    backdrop.classList.remove('is-open');
  });

  document.querySelectorAll('[data-media-path]').forEach(function (item) {
    item.addEventListener('click', function () {
      if (!currentTarget) return;
      var path = item.dataset.mediaPath;
      document.querySelector('[data-media-value="' + currentTarget + '"]').value = path;
      // une sélection médiathèque prime sur un fichier local resté choisi par erreur
      var fileInput = document.querySelector('[data-photo-file="' + currentTarget + '"]');
      fileInput.value = '';
      var nameEl = fileInput.closest('.admin-photo-row').querySelector('[data-' + currentTarget + '-name]');
      if (nameEl) nameEl.textContent = 'Médiathèque : ' + (item.querySelector('span').textContent || '');
      var preview = document.querySelector('[data-preview="' + currentTarget + '"]');
      preview.innerHTML = '<img src="/' + path + '" alt="">';
      backdrop.classList.remove('is-open');
    });
  });
})();
</script>
</body>
</html>
