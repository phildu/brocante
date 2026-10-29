<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/key-check.php';
require_admin();

$content = get_content();
$mediaPhotos = get_media_items('photo');
$flash = flash_get();
$settingsTabs = ['general' => 'Général', 'pages' => 'Contenu des pages', 'paiement' => 'Paiement & livraison'];
$tab = isset($settingsTabs[$_GET['s'] ?? '']) ? (string) $_GET['s'] : 'general';
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
  .media-pick-item img { width: 100%; aspect-ratio: 4/3; object-fit: contain; background: var(--surface-2); display: block; }
  .media-pick-item span { display: block; font-size: 0.68rem; padding: 4px 6px; color: var(--ink-soft); }
  .media-pick-item:hover { outline: 2px solid var(--accent); }
  [data-panel][hidden] { display: none !important; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'reglages-' . $tab; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Espace boutique</p>
    <h1 style="font-size:2rem;margin:12px 0 20px;">Réglages — <?= h($settingsTabs[$tab]) ?></h1>

    <a class="btn btn-primary" href="/slideshow.php" target="_blank" style="margin-bottom:24px;display:inline-flex;">Lancer le diaporama boutique</a>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:24px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <div class="admin-shell">

      <form class="admin-block" method="post" action="/admin/save-content.php" enctype="multipart/form-data">
        <input type="hidden" name="tab" value="<?= h($tab) ?>">
        <div data-panel="general">
        <h2>Identité du site</h2>
        <p class="hint">Le nom de la boutique, affiché dans l'en-tête, le pied de page et les titres d'onglet.</p>
        <div class="field-row-2">
          <div class="field"><label>Nom du site</label><input type="text" name="site_name" value="<?= h($content['site_name']) ?>"></div>
          <div class="field"><label>Signature</label><input type="text" name="site_tagline" value="<?= h($content['site_tagline']) ?>"></div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>

        <div data-panel="pages">
        <h2>Page d'accueil</h2>
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
        <?php require_once __DIR__ . '/../includes/autofeed.php'; ?>
        <div class="admin-photo-row" style="margin-top:8px;flex-wrap:wrap;">
          <input type="text" id="regen-hint" maxlength="200" placeholder="Précisez ce que vous voulez (facultatif)" style="flex:1;min-width:220px;">
          <button type="button" class="btn-small" id="regen-hero" data-cost="<?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>">✨ Régénérer avec l’IA</button>
          <span id="regen-status" role="status" style="font-size:0.8rem;color:var(--ink-soft);"></span>
        </div>
        <p class="hint" style="margin:4px 0 0;">Chaque nouvelle image est décomptée de votre crédit IA (≈ <?= h(ai_format_eur(autofeed_regen_cost_eur())) ?>).</p>
        <script>
        (function () {
          var btn = document.getElementById('regen-hero'), st = document.getElementById('regen-status');
          btn.addEventListener('click', function () {
            if (!confirm('Générer une nouvelle image ? Elle remplacera la photo actuelle et sera décomptée de votre crédit IA (≈ ' + btn.dataset.cost + ').')) return;
            var fd = new FormData();
            fd.append('ajax', '1'); fd.append('target', 'hero'); fd.append('hint', document.getElementById('regen-hint').value);
            btn.disabled = true; st.textContent = 'Génération en cours…';
            fetch('/admin/regenerate-image.php', { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                st.textContent = d.message;
                if (d.ok) document.querySelector('[data-preview="hero"]').innerHTML = '<img src="/' + d.path + '?' + Date.now() + '" alt="">';
              })
              .catch(function () { st.textContent = 'La génération a échoué, rien n’a été débité.'; })
              .then(function () { btn.disabled = false; });
          });
        })();
        </script>

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

        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>

        <div data-panel="paiement" id="shipping-settings">
        <h2>Frais de port</h2>
        <p class="hint">Les deux modes de livraison proposés au client au moment du paiement Stripe.</p>
        <div class="field-row-3" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;">
          <div class="field"><label>Libellé retrait (gratuit)</label><input type="text" name="pickup_label" value="<?= h($content['pickup_label']) ?>"></div>
          <div class="field"><label>Libellé envoi</label><input type="text" name="shipping_label" value="<?= h($content['shipping_label']) ?>"></div>
          <div class="field"><label>Tarif d'envoi</label><input type="text" name="shipping_fee" value="<?= h($content['shipping_fee']) ?>"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 2fr;gap:16px;">
          <div class="field"><label>Pas de livraison sous (€)</label><input type="number" min="0" step="0.01" name="pickup_only_below" value="<?= h((string) ($content['pickup_only_below'] ?? '10')) ?>"></div>
          <div class="field"><label>Message affiché à la place de la livraison</label><input type="text" name="pickup_only_message" value="<?= h((string) ($content['pickup_only_message'] ?? "Retrait à l'adresse du vendeur.")) ?>"></div>
        </div>
        <p class="hint">Les articles à 0 € (dons) et ceux dont le prix est inférieur à ce seuil ne sont proposés qu'en retrait. Si le panier contient au moins un article livrable, la livraison reste proposée. Mettez 0 pour ne l'appliquer qu'aux dons.</p>

        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>
      </form>

      <form class="admin-block" method="post" action="/admin/save-stripe-keys.php" id="stripe-settings" data-panel="paiement">
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
        <p class="publish-status" data-kind="<?= shop_checkout_ready() ? '' : 'error' ?>" style="margin-top:10px;">
          <?= shop_checkout_ready() ? 'Paiement Stripe configuré et actif.' : 'Aucune clé secrète valide enregistrée — le paiement est désactivé sur le site.' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer les clés</button>
      <?php if (STRIPE_SECRET_KEY !== ''): ?>
        <?= keycheck_status_html('stripe') ?>
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="stripe" formnovalidate>Tester la clé Stripe</button>
        <?php endif; ?>
      </form>

      <form class="admin-block" method="post" action="/admin/save-photo-visibility.php" id="photo-visibility-settings" data-panel="general">
        <h2>Photos des pièces créées avec le Studio</h2>
        <p class="hint">Quand une pièce vient d'être traitée (détourage, mise en situation, recto/verso), sa fiche montre les <strong>images générées</strong> ; les photos du shooting restent dans la galerie, masquées (sauf les gros plans : étiquette, texture, détail). Décochez pour garder toutes les photos visibles. Chaque pièce se règle ensuite dans sa galerie.</p>
        <label class="featured-check"><input type="checkbox" name="hide_shooting_photos" value="1"<?= hide_shooting_photos_enabled() ? ' checked' : '' ?>> Masquer par défaut les photos du shooting une fois les images générées</label>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Enregistrer</button>
      </form>

      <form class="admin-block" method="post" action="/admin/save-pwa.php" id="pwa-settings" data-panel="general">
        <h2>Boutique installable (web app)</h2>
        <p class="hint">Les visiteurs sur téléphone se voient proposer, au bon moment (à partir de leur 2<sup>e</sup> page, jamais pendant le paiement), d'<strong>ajouter la boutique à leur écran d'accueil</strong> : un geste, plein écran, avec l'icône et le nom de votre boutique (le logo carré de « Apparence »). Un lien « Installer l'appli » reste en pied de page. Sans boutique d'applications ni téléchargement.</p>
        <label class="featured-check"><input type="checkbox" name="pwa_prompt" value="1"<?= shop_setting('pwa_prompt', '1') !== '0' ? ' checked' : '' ?>> Proposer d'installer la boutique comme web app</label>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Enregistrer</button>
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
  // Anciennes adresses : les clés d'API et d'IA sont maintenant dans « API & réseaux ».
  if (/^#(gemini|fal|modal-video|siliconflow)-settings$/.test(location.hash)) { location.replace('/admin/social.php' + location.hash); return; }
  var current = <?= json_encode($tab) ?>;
  var panels = document.querySelectorAll('[data-panel]');
  panels.forEach(function (p) { p.hidden = p.dataset.panel !== current; });
  var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  var owner = target && target.closest('[data-panel]');
  if (owner && owner.dataset.panel !== current) { location.replace('/admin/index.php?s=' + owner.dataset.panel + location.hash); return; }
  if (owner) target.scrollIntoView();
})();
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
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
