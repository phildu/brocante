<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/key-check.php';
require_once __DIR__ . '/../includes/sms.php';
require_admin();

$content = get_content();
$mediaPhotos = get_media_items('photo');
$flash = flash_get();
$settingsTabs = ['general' => 'Général', 'pages' => 'Contenu des pages', 'paiement' => 'Paiement & livraison'];
$tab = isset($settingsTabs[$_GET['s'] ?? '']) ? (string) $_GET['s'] : 'general';
$pagesSubtabs = ['accueil' => 'Page d’accueil', 'sections' => 'Sections', 'footer' => 'Pied de page', 'horaires' => 'Horaires & fermetures'];
$pagesSub = isset($pagesSubtabs[$_GET['p'] ?? '']) ? (string) $_GET['p'] : 'accueil';
$hoursSchedule = shop_hours_editor_schedule();
$hoursClosures = shop_hours_closures();
$hoursSummary = shop_hours_summary_text();
$hoursStatus = shop_hours_status();
$publicAddress = shop_public_address($content);
$footerNote = shop_footer_note();
$visitTitle = shop_presence_mode() === 'online' ? 'La boutique' : "Visiter l'atelier";
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
  [data-subpanel][hidden] { display: none !important; }
  .pages-subtabs { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 20px; border-bottom: 1px solid var(--line); }
  .pages-subtabs a { padding: 9px 13px; font-size: 0.85rem; color: var(--ink-soft); text-decoration: none; border: 1px solid transparent; border-bottom: 0; border-radius: 8px 8px 0 0; }
  .pages-subtabs a:hover { color: var(--ink); }
  .pages-subtabs a[aria-current="page"] { background: var(--surface); border-color: var(--line); color: var(--ink); font-weight: 600; margin-bottom: -1px; }
  .hours-week { display: flex; flex-direction: column; gap: 10px; }
  .hours-day { display: grid; grid-template-columns: 110px 86px 1fr auto; gap: 10px; align-items: center; }
  .hours-day-name { font-weight: 600; }
  .hours-closed { display: inline-flex; align-items: center; gap: 6px; font-size: 0.85rem; }
  .hours-ranges { display: flex; flex-wrap: wrap; gap: 8px; }
  .hours-range { display: inline-flex; align-items: center; gap: 6px; }
  .hours-range input[type="time"] { width: 112px; }
  .closure-row { display: grid; grid-template-columns: repeat(4, minmax(110px, 1fr)) auto; gap: 8px; align-items: end; margin-bottom: 8px; }
  .closure-row label { display: flex; flex-direction: column; gap: 4px; font-size: 0.78rem; color: var(--ink-soft); }
  .footer-recap { margin: 16px 0 6px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); }
  .footer-recap p { margin: 4px 0; font-size: 0.9rem; }
  @media (max-width: 780px) {
    .hours-day, .closure-row { grid-template-columns: 1fr; align-items: stretch; }
  }
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
        <nav class="pages-subtabs" aria-label="Contenu des pages">
          <?php foreach ($pagesSubtabs as $key => $label): ?>
            <a href="/admin/index.php?s=pages&amp;p=<?= h($key) ?>"<?= $pagesSub === $key ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>

        <div data-subpanel="accueil"<?= $pagesSub !== 'accueil' ? ' hidden' : '' ?>>
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
        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>

        <div data-subpanel="sections"<?= $pagesSub !== 'sections' ? ' hidden' : '' ?>>
        <h2>Notre histoire</h2>
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

        <h2 style="margin-top:36px;"><?= h($visitTitle) ?></h2>
        <p class="hint">Coordonnées affichées en bas de la page d'accueil et dans le pied de page.</p>
        <div class="field">
          <label>Adresse</label>
          <input type="text" value="<?= h($publicAddress) ?>" readonly>
          <p class="hint" style="margin:4px 0 0;">Reprise de la <a href="/admin/legal.php#legal-identity" style="color:var(--accent);">définition globale de la boutique</a> (Pages légales → Identité du vendeur). Pour la modifier, changez-y l'adresse du vendeur.</p>
        </div>
        <div class="field">
          <label>Horaires</label>
          <input type="text" value="<?= h($hoursSummary !== '' ? $hoursSummary : $content['contact_hours']) ?>" readonly>
          <p class="hint" style="margin:4px 0 0;"><?= $hoursSummary !== '' ? 'Calculés depuis la grille d’horaires.' : 'Texte libre actuel — aucun horaire structuré enregistré.' ?> <a href="/admin/index.php?s=pages&amp;p=horaires" style="color:var(--accent);">Gérer les horaires →</a></p>
        </div>
        <div class="field"><label>Livraison</label><input type="text" name="contact_delivery" value="<?= h($content['contact_delivery']) ?>"></div>

        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>

        <div data-subpanel="footer"<?= $pagesSub !== 'footer' ? ' hidden' : '' ?>>
        <h2>Pied de page</h2>
        <p class="hint">Le pied de page reprend automatiquement les coordonnées ci-dessous. Ajoutez-y une phrase si vous le souhaitez.</p>
        <div class="field"><label>Note de pied de page (facultatif)</label><input type="text" name="footer_note" maxlength="200" value="<?= h($footerNote) ?>" placeholder="ex. Fermeture annuelle du 3 au 10 novembre."></div>
        <div class="footer-recap">
          <p><strong>Adresse</strong> <?= h($publicAddress !== '' ? $publicAddress : '—') ?></p>
          <p><strong>Horaires</strong> <?= h($hoursSummary !== '' ? $hoursSummary : $content['contact_hours']) ?></p>
          <p><strong>Livraison</strong> <?= h($content['contact_delivery']) ?></p>
        </div>
        <p class="hint">L'adresse se règle dans la <a href="/admin/legal.php#legal-identity" style="color:var(--accent);">définition globale de la boutique</a> ; les horaires dans <a href="/admin/index.php?s=pages&amp;p=horaires" style="color:var(--accent);">Horaires &amp; fermetures</a>.</p>
        <button type="submit" class="btn btn-primary" style="margin-top:18px;">Enregistrer les contenus</button>
        </div>
        </div>

        <div data-panel="paiement" id="shipping-settings">
        <h2>Frais de port</h2>
        <p class="hint">Les deux modes de livraison proposés au client au moment du paiement, quel que soit le moyen choisi.</p>
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

      <div class="admin-block" data-panel="pages" data-subpanel="horaires"<?= $pagesSub !== 'horaires' ? ' hidden' : '' ?>>
        <form method="post" action="/admin/save-hours.php">
          <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
          <h2>Horaires d’ouverture</h2>
          <p class="hint">Un créneau par plage horaire : ajoutez-en un second pour une coupure le midi. Cochez « Fermé » pour un jour sans ouverture. Ces horaires remplacent le texte libre affiché sur le site.</p>
          <div class="hours-week">
            <?php foreach (SHOP_HOURS_DAYS as $dayKey => $dayLabel): $day = $hoursSchedule[$dayKey] ?? ['closed' => true, 'ranges' => []]; $ranges = $day['ranges'] ?: [['from' => '', 'to' => '']]; ?>
              <div class="hours-day" data-day-row>
                <span class="hours-day-name"><?= h($dayLabel) ?></span>
                <label class="hours-closed"><input type="checkbox" name="hours[<?= h($dayKey) ?>][closed]" value="1"<?= $day['closed'] ? ' checked' : '' ?>> Fermé</label>
                <div class="hours-ranges" data-ranges>
                  <?php foreach ($ranges as $range): ?>
                    <span class="hours-range">
                      <input type="time" name="hours[<?= h($dayKey) ?>][from][]" value="<?= h($range['from']) ?>" aria-label="Ouverture, <?= h($dayLabel) ?>">
                      <span aria-hidden="true">–</span>
                      <input type="time" name="hours[<?= h($dayKey) ?>][to][]" value="<?= h($range['to']) ?>" aria-label="Fermeture, <?= h($dayLabel) ?>">
                      <button type="button" class="btn-small" data-remove-range aria-label="Retirer ce créneau">×</button>
                    </span>
                  <?php endforeach; ?>
                </div>
                <button type="button" class="btn-small" data-add-range>+ Créneau</button>
              </div>
            <?php endforeach; ?>
          </div>

          <h2 style="margin-top:32px;">Fermetures exceptionnelles, jours fériés et vacances</h2>
          <p class="hint">Chaque période fermée est annoncée automatiquement en haut du site (avec la réouverture) et reprise dans les coordonnées. Laissez « Au » vide pour une fermeture d’un seul jour.</p>
          <div data-closures>
            <?php if (!$hoursClosures): ?><p class="hint" data-closures-empty>Aucune fermeture enregistrée.</p><?php endif; ?>
            <?php foreach ($hoursClosures as $i => $closure): ?>
              <div class="closure-row" data-closure-row>
                <label>Du<input type="date" name="closures[<?= $i ?>][from]" value="<?= h($closure['from']) ?>"></label>
                <label>Au<input type="date" name="closures[<?= $i ?>][to]" value="<?= h($closure['to']) ?>"></label>
                <label>Type
                  <select name="closures[<?= $i ?>][type]">
                    <?php foreach (SHOP_HOURS_CLOSURE_TYPES as $typeKey => $typeLabel): ?><option value="<?= h($typeKey) ?>"<?= $closure['type'] === $typeKey ? ' selected' : '' ?>><?= h($typeLabel) ?></option><?php endforeach; ?>
                  </select>
                </label>
                <label>Motif<input type="text" name="closures[<?= $i ?>][label]" maxlength="120" value="<?= h($closure['label']) ?>" placeholder="ex. Congés d’automne"></label>
                <button type="button" class="btn-small" data-remove-closure aria-label="Retirer cette fermeture">×</button>
              </div>
            <?php endforeach; ?>
          </div>
          <button type="button" class="btn-small" id="add-closure">+ Ajouter une fermeture</button>

          <h2 style="margin-top:32px;">Message aux visiteurs</h2>
          <div class="field">
            <label>Message libre (facultatif)</label>
            <textarea name="opening_notice" maxlength="300" placeholder="ex. Fermeture annuelle du 3 au 10 novembre, réouverture le 12 à 10 h."><?= h(shop_setting('opening_notice', '')) ?></textarea>
            <p class="hint" style="margin:4px 0 0;">S’il est rempli, il remplace le message automatique du bandeau. Laissez vide pour laisser le site annoncer les fermetures et le statut du jour.</p>
          </div>

          <?php $previewNotice = shop_hours_announcement(); ?>
          <p class="hint">Aperçu public : <?= h($hoursSummary !== '' ? $hoursSummary : 'aucun horaire structuré — le texte libre actuel reste affiché') ?>.</p>
          <p class="hint">Statut maintenant : <?= h($hoursStatus['label'] !== '' ? $hoursStatus['label'] : '—') ?><?= $previewNotice ? ' · Bandeau : ' . h($previewNotice['text']) : '' ?></p>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
            <button type="submit" class="btn btn-primary">Enregistrer les horaires</button>
            <?php if ($hoursSummary !== ''): ?>
              <button type="submit" name="reset_hours" value="1" class="btn" onclick="return confirm('Retirer la grille d’horaires et revenir au texte libre ? Les fermetures et le message seront effacés.');">Revenir au texte libre</button>
            <?php endif; ?>
          </div>
        </form>

        <template id="closure-template">
          <div class="closure-row" data-closure-row>
            <label>Du<input type="date" name="closures[__i__][from]"></label>
            <label>Au<input type="date" name="closures[__i__][to]"></label>
            <label>Type
              <select name="closures[__i__][type]">
                <?php foreach (SHOP_HOURS_CLOSURE_TYPES as $typeKey => $typeLabel): ?><option value="<?= h($typeKey) ?>"><?= h($typeLabel) ?></option><?php endforeach; ?>
              </select>
            </label>
            <label>Motif<input type="text" name="closures[__i__][label]" maxlength="120" placeholder="ex. Congés d’automne"></label>
            <button type="button" class="btn-small" data-remove-closure aria-label="Retirer cette fermeture">×</button>
          </div>
        </template>

        <script>
        (function () {
          document.querySelectorAll('[data-add-range]').forEach(function (btn) {
            btn.addEventListener('click', function () {
              var list = btn.closest('[data-day-row]').querySelector('[data-ranges]');
              var first = list.querySelector('.hours-range');
              if (!first) return;
              var clone = first.cloneNode(true);
              clone.querySelectorAll('input').forEach(function (input) { input.value = ''; });
              list.appendChild(clone);
            });
          });
          document.addEventListener('click', function (e) {
            var remove = e.target.closest('[data-remove-range]');
            if (!remove) return;
            var range = remove.closest('.hours-range');
            var list = range.closest('[data-ranges]');
            if (list.querySelectorAll('.hours-range').length > 1) range.remove();
            else range.querySelectorAll('input').forEach(function (input) { input.value = ''; });
          });

          var list = document.querySelector('[data-closures]');
          var index = <?= (int) count($hoursClosures) ?>;
          var add = document.getElementById('add-closure');
          if (add && list) {
            add.addEventListener('click', function () {
              var tpl = document.getElementById('closure-template');
              list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__i__/g, String(index++)));
              var empty = list.querySelector('[data-closures-empty]');
              if (empty) empty.remove();
            });
          }
          document.addEventListener('click', function (e) {
            var remove = e.target.closest('[data-remove-closure]');
            if (!remove) return;
            var row = remove.closest('[data-closure-row]');
            if (row) row.remove();
          });
        })();
        </script>
      </div>

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
        <?php
        $webhookFile = SECRETS_DIR . '/stripe_webhook.key';
        $webhookSet = is_file($webhookFile) && str_starts_with(trim((string) file_get_contents($webhookFile)), 'whsec_');
        $webhookUrl = rtrim(tenant_site_url(), '/') . '/stripe-webhook.php';
        ?>
        <div class="field" style="margin-top:12px;">
          <label>Secret de signature du webhook (whsec_...)</label>
          <input type="password" name="stripe_webhook" autocomplete="off" placeholder="<?= $webhookSet ? 'Déjà enregistré' : 'whsec_...' ?>">
        </div>
        <p class="hint" style="margin-top:6px;">
          Dans Stripe → Développeurs → Webhooks, ajoutez l'adresse <code><?= h($webhookUrl) ?></code> et copiez son secret de signature ici.
          Événements à écouter : <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>,
          <code>checkout.session.async_payment_failed</code>, <code>checkout.session.expired</code>, <code>charge.refunded</code>,
          <code>charge.dispute.created</code> (et <code>updated</code> / <code>closed</code>).
          <?= $webhookSet ? 'Webhook actif : les commandes payées sont confirmées même si le client ferme l’onglet.' : 'Sans ce secret, une commande peut rester « en attente » si le client ne revient pas sur le site.' ?>
        </p>
        <p class="publish-status" data-kind="<?= stripe_checkout_ready() ? '' : 'error' ?>" style="margin-top:10px;">
          <?= stripe_checkout_ready() ? 'Paiement Stripe configuré et actif.' : 'Aucune clé secrète Stripe valide — Stripe est désactivé (les autres moyens de paiement restent disponibles s’ils sont réglés).' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer les clés</button>
      <?php if (STRIPE_SECRET_KEY !== ''): ?>
        <?= keycheck_status_html('stripe') ?>
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <button type="submit" class="btn" style="margin-top:6px;" formaction="/admin/key-check.php" name="service" value="stripe" formnovalidate>Tester la clé Stripe</button>
        <?php endif; ?>
      </form>

      <?php
      $moneticoConfig = monetico_config() ?? payment_config('monetico') ?? [];
      $paypalConfig = paypal_config() ?? payment_config('paypal') ?? [];
      $availableMethods = shop_payment_methods_available();
      $enabledSetting = trim(shop_setting('payment_methods', ''));
      $enabledMethods = $enabledSetting === '' ? array_keys($availableMethods) : array_filter(array_map('trim', explode(',', $enabledSetting)));
      $moneticoKeySet = trim((string) ($moneticoConfig['key'] ?? '')) !== '';
      $paypalSecretSet = trim((string) ($paypalConfig['secret'] ?? '')) !== '';
      ?>
      <form class="admin-block" method="post" action="/admin/save-payment-keys.php" id="payment-settings" data-panel="paiement">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>Autres moyens de paiement</h2>
        <p class="hint">Stripe reste le moyen par défaut. Monetico (CIC / Crédit Mutuel / OBC) et PayPal servent de solution de secours si le compte Stripe est suspendu ou refuse un dossier. Chaque fournisseur utilise le compte du commerçant : les fonds sont versés directement sur son compte bancaire ou PayPal, sans commission de la plateforme.</p>

        <h3 style="margin-top:24px;">Monetico Paiement</h3>
        <p class="hint">Identifiants remis par votre banque. La clé de sécurité fait 40 caractères hexadécimaux. En mode test, utilisez les identifiants de test fournis par la banque.</p>
        <div class="field-row-2">
          <div class="field">
            <label>Numéro de TPE (7 chiffres)</label>
            <input type="text" name="monetico_tpe" inputmode="numeric" pattern="[0-9]{7}" maxlength="7" value="<?= h((string) ($moneticoConfig['tpe'] ?? '')) ?>" placeholder="1234567">
          </div>
          <div class="field">
            <label>Société (societe)</label>
            <input type="text" name="monetico_societe" maxlength="8" value="<?= h((string) ($moneticoConfig['societe'] ?? '')) ?>" placeholder="ex. BROC01">
          </div>
        </div>
        <div class="field-row-2" style="margin-top:12px;">
          <div class="field">
            <label>Clé de sécurité (40 caractères hexadécimaux)</label>
            <input type="password" name="monetico_key" autocomplete="off" placeholder="<?= $moneticoKeySet ? 'Déjà enregistrée' : 'ex. 0123456789ABCDEF…' ?>">
          </div>
          <div class="field">
            <label>Mode</label>
            <select name="monetico_mode">
              <option value="test"<?= ($moneticoConfig['mode'] ?? 'test') === 'test' ? ' selected' : '' ?>>Test</option>
              <option value="production"<?= ($moneticoConfig['mode'] ?? '') === 'production' ? ' selected' : '' ?>>Production</option>
            </select>
          </div>
        </div>
        <p class="hint" style="margin-top:8px;">
          URL de confirmation à déclarer dans le back-office Monetico (champ « URL de confirmation CGI2 ») :
          <code><?= h(rtrim(tenant_site_url(), '/') . '/monetico-ipn.php') ?></code>.
          Le remboursement automatique exige que votre banque active l’option « re-crédit » sur le contrat Monetico ; sinon, remboursez depuis le back-office de la banque.
        </p>

        <h3 style="margin-top:24px;">PayPal</h3>
        <p class="hint">Identifiants d’une application REST créée sur <a href="https://developer.paypal.com/dashboard/applications" target="_blank" rel="noopener">developer.paypal.com</a>. L’argent est capturé au retour de l’acheteur ; une commande approuvée mais non capturée peut être confirmée depuis la fiche commande.</p>
        <div class="field-row-2">
          <div class="field">
            <label>Client ID</label>
            <input type="text" name="paypal_client_id" autocomplete="off" value="<?= h((string) ($paypalConfig['client_id'] ?? '')) ?>" placeholder="AY…">
          </div>
          <div class="field">
            <label>Secret</label>
            <input type="password" name="paypal_secret" autocomplete="off" placeholder="<?= $paypalSecretSet ? 'Déjà enregistré' : 'EJ…' ?>">
          </div>
        </div>
        <div class="field" style="margin-top:12px;max-width:240px;">
          <label>Mode</label>
          <select name="paypal_mode">
            <option value="sandbox"<?= ($paypalConfig['mode'] ?? 'sandbox') === 'sandbox' ? ' selected' : '' ?>>Bac à sable (sandbox)</option>
            <option value="live"<?= ($paypalConfig['mode'] ?? '') === 'live' ? ' selected' : '' ?>>Production (live)</option>
          </select>
        </div>

        <h3 style="margin-top:24px;">Moyens proposés au client</h3>
        <?php if (!$availableMethods): ?>
          <p class="hint">Aucun moyen de paiement n’est encore configuré. Renseignez les identifiants ci-dessus (ou les clés Stripe) pour activer le paiement.</p>
        <?php else: ?>
          <div style="display:flex;gap:18px;flex-wrap:wrap;">
            <?php foreach ($availableMethods as $methodKey => $methodLabel): ?>
              <label style="font-size:0.9rem;display:inline-flex;align-items:center;gap:6px;">
                <input type="checkbox" name="payment_methods[]" value="<?= h($methodKey) ?>"<?= in_array($methodKey, $enabledMethods, true) ? ' checked' : '' ?>>
                <?= h($methodLabel) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <p class="hint" style="margin-top:6px;">Décochez un moyen pour le masquer sans effacer ses identifiants. Si tous sont décochés, le paiement est désactivé sur la boutique.</p>
        <?php endif; ?>

        <p class="publish-status" data-kind="<?= shop_checkout_ready() ? '' : 'error' ?>" style="margin-top:10px;">
          <?= shop_checkout_ready() ? 'Moyens de paiement actifs : ' . h(implode(', ', shop_payment_methods())) . '.' : 'Aucun moyen de paiement actif — le paiement est désactivé sur le site.' ?>
        </p>
        <button type="submit" class="btn btn-primary" style="margin-top:6px;">Enregistrer les moyens de paiement</button>
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

      <?php
        $smsProv = sms_provider_name();
        $smsOvh = sms_ovh_config();
        $smsTw = sms_twilio_config();
        $smsChosen = strtolower(sms_setting('sms_provider', 'SMS_PROVIDER'));
        $smsFrom = $smsProv === 'ovh' ? $smsOvh['from'] : ($smsProv === 'twilio' ? $smsTw['from'] : '');
      ?>
      <form class="admin-block" method="post" action="/admin/save-sms.php" id="sms-settings" data-panel="general">
        <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
        <h2>SMS : OVH ou Twilio</h2>
        <p class="hint">Les numéros reçoivent le code de connexion, les annonces des actions commerciales et les alertes de l'assistance vendeur. Choisissez le fournisseur et saisissez ses identifiants ; un mot de passe ou un jeton laissé vide conserve la valeur enregistrée. Les autres champs se vident quand vous les videz.</p>
        <div class="field">
          <label>Fournisseur</label>
          <select name="sms_provider">
            <option value=""<?= $smsChosen === '' ? ' selected' : '' ?>>Automatique — le premier des deux configuré</option>
            <option value="ovh"<?= $smsChosen === 'ovh' ? ' selected' : '' ?>>OVH</option>
            <option value="twilio"<?= $smsChosen === 'twilio' ? ' selected' : '' ?>>Twilio</option>
          </select>
        </div>
        <p class="publish-status" data-kind="<?= $smsProv !== '' ? '' : 'error' ?>">
          <?= $smsProv !== ''
            ? 'Prêt : ' . h(sms_provider_label()) . ' — expéditeur « ' . h($smsFrom) . ' ».'
            : 'Aucun fournisseur complet : les envois de SMS restent bloqués (en local, les messages sont écrits dans data/sms-outbox.log).' ?>
        </p>
        <h3 style="margin-top:18px;">OVH</h3>
        <p class="hint">Identifiants OVH (espace client → Télécom → SMS) : le compte <code>sms-xxxxx-1</code>, l'utilisateur SMS et son mot de passe, puis l'expéditeur déclaré — 11 caractères alphanumériques au plus, affiché sur le téléphone du destinataire. L'adresse IP du serveur doit être autorisée sur le compte SMS, sinon OVH répond « unauthorized IP ».</p>
        <div class="field-row-2">
          <div class="field">
            <label>Compte SMS</label>
            <input type="text" name="ovh_sms_account" value="<?= h($smsOvh['account']) ?>" autocomplete="off" placeholder="sms-xxxxx-1">
          </div>
          <div class="field">
            <label>Identifiant</label>
            <input type="text" name="ovh_sms_login" value="<?= h($smsOvh['login']) ?>" autocomplete="off">
          </div>
        </div>
        <div class="field-row-2">
          <div class="field">
            <label>Mot de passe</label>
            <input type="password" name="ovh_sms_password" autocomplete="new-password" placeholder="<?= $smsOvh['password'] !== '' ? 'Déjà enregistré' : '' ?>">
          </div>
          <div class="field">
            <label>Expéditeur affiché</label>
            <input type="text" name="ovh_sms_from" value="<?= h($smsOvh['from']) ?>" maxlength="11" autocomplete="off" placeholder="Ma boutique">
          </div>
        </div>
        <h3 style="margin-top:18px;">Twilio</h3>
        <div class="field-row-2">
          <div class="field">
            <label>Account SID</label>
            <input type="text" name="twilio_sid" value="<?= h($smsTw['sid']) ?>" autocomplete="off" placeholder="AC...">
          </div>
          <div class="field">
            <label>Numéro expéditeur</label>
            <input type="text" name="twilio_from" value="<?= h($smsTw['from']) ?>" autocomplete="off" placeholder="+33...">
          </div>
        </div>
        <div class="field">
          <label>Auth token</label>
          <input type="password" name="twilio_token" autocomplete="new-password" placeholder="<?= $smsTw['token'] !== '' ? 'Déjà enregistré' : '' ?>">
        </div>
        <button type="submit" class="btn btn-primary" style="margin-top:10px;">Enregistrer les réglages SMS</button>
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
  var currentSub = <?= json_encode($pagesSub) ?>;
  var panels = document.querySelectorAll('[data-panel]');
  panels.forEach(function (p) {
    var show = p.dataset.panel === current;
    if (show && p.hasAttribute('data-subpanel')) show = p.dataset.subpanel === currentSub;
    p.hidden = !show;
  });
  var target = location.hash ? document.getElementById(location.hash.slice(1)) : null;
  var owner = target && target.closest('[data-panel]');
  if (owner && owner.dataset.panel !== current) { location.replace('/admin/index.php?s=' + owner.dataset.panel + location.hash); return; }
  if (owner && owner.hasAttribute('data-subpanel') && owner.dataset.subpanel !== currentSub) { location.replace('/admin/index.php?s=pages&p=' + owner.dataset.subpanel + location.hash); return; }
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
