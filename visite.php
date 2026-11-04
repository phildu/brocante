<?php

// Visite virtuelle : les photos validées du reportage boutique, réunies sur une
// page publique et parcourables par lieu. Le reportage les publie déjà sur le
// hero, « Notre histoire », le diaporama et les bandeaux ; cette page leur donne
// un seul endroit à montrer, partager et retrouver.

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/shop-shooting.php';

$allGroups = shop_shooting_visit_groups();
$totalPhotos = 0;
$heroPhoto = null;
foreach ($allGroups as $group) {
    $totalPhotos += count($group['photos']);
    if ($heroPhoto === null && !empty($group['photos'])) $heroPhoto = $group['photos'][0];
}

// Filtre par lieu : un lien simple, lisible sans JavaScript et partageable.
$placeKeys = array_column($allGroups, 'key');
$place = (string) ($_GET['lieu'] ?? '');
if (!in_array($place, $placeKeys, true)) $place = '';
$groups = $place === '' ? $allGroups : array_values(array_filter(
    $allGroups,
    static fn (array $group): bool => $group['key'] === $place
));

$content = get_content();
$siteName = trim((string) ($content['site_name'] ?? '')) ?: (string) tenant('name');
$contactAddress = trim((string) ($content['contact_address'] ?? ''));
$contactHours = trim((string) ($content['contact_hours'] ?? ''));

$activeNav = 'visite';
$pageTitle = 'Visite virtuelle';
$ogDescription = $totalPhotos > 0
    ? 'Parcourez ' . $siteName . ' en ' . $totalPhotos . ' photo' . ($totalPhotos > 1 ? 's' : '') . ' prises sur place.'
    : 'Découvrez ' . $siteName . ' en photos avant de venir sur place.';
if ($heroPhoto !== null) $ogImage = $heroPhoto['path'];
$pageStyles = ['/assets/visite.css?v=' . (int) @filemtime(__DIR__ . '/assets/visite.css')];
$pageScripts = ['/assets/visite.js?v=' . (int) @filemtime(__DIR__ . '/assets/visite.js')];

include __DIR__ . '/includes/header.php';
?>

<section class="vt-hero">
  <div class="wrap vt-hero-grid">
    <div class="vt-hero-copy">
      <p class="eyebrow">Visite virtuelle</p>
      <h1>Entrez dans <?= h($siteName) ?></h1>
      <?php if ($totalPhotos > 0): ?>
        <p class="lede"><?= $totalPhotos ?> photo<?= $totalPhotos > 1 ? 's' : '' ?> du lieu, prises sur place : la vitrine, les rayons, le comptoir et les détails qui donnent envie de pousser la porte.</p>
        <div class="vt-hero-actions">
          <a class="btn btn-primary" href="#visite-photos">Commencer la visite</a>
          <a class="btn btn-ghost" href="/index.php#contact">Venir sur place</a>
        </div>
        <p class="share-row"><?= share_page_button('Visite virtuelle — ' . $siteName, 'Parcourez la boutique en photos.') ?></p>
      <?php else: ?>
        <p class="lede">Le reportage photo de la boutique est en préparation. En attendant, retrouvez les objets en ligne et l’adresse pour venir nous voir.</p>
        <div class="vt-hero-actions">
          <a class="btn btn-primary" href="/boutique.php">Voir la boutique</a>
          <a class="btn btn-ghost" href="/index.php#contact">Nous trouver</a>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($heroPhoto !== null): ?>
      <figure class="vt-hero-photo">
        <img src="<?= h($heroPhoto['url']) ?>" alt="<?= h($heroPhoto['shot'] . ($heroPhoto['caption'] !== '' ? ' — ' . $heroPhoto['caption'] : '')) ?>" fetchpriority="high">
        <figcaption>
          <span><?= h($heroPhoto['shot']) ?></span>
          <?php if ($heroPhoto['caption'] !== ''): ?><small><?= h($heroPhoto['caption']) ?></small><?php endif; ?>
        </figcaption>
      </figure>
    <?php else: ?>
      <div class="vt-hero-empty" aria-hidden="true">
        <span>📷</span>
        <p>La visite se prépare</p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($groups): ?>
<section class="vt-visit" id="visite-photos">
  <div class="wrap">
    <div class="vt-toolbar">
      <div>
        <p class="eyebrow">Le reportage</p>
        <h2>La boutique en images</h2>
      </div>
      <?php if (count($allGroups) > 1): ?>
        <nav class="vt-filters" aria-label="Filtrer les photos par lieu">
          <a href="/visite.php#visite-photos"<?= $place === '' ? ' aria-current="page"' : '' ?>>Tout</a>
          <?php foreach ($allGroups as $group): ?>
            <a href="/visite.php?lieu=<?= h($group['key']) ?>#visite-photos"<?= $place === $group['key'] ? ' aria-current="page"' : '' ?>><?= h($group['icon'] . ' ' . $group['title']) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
    </div>

    <?php foreach ($groups as $group): $groupPhotos = count($group['photos']); ?>
      <section class="vt-group" aria-labelledby="vt-group-<?= h($group['key']) ?>">
        <div class="vt-group-head">
          <span class="vt-group-icon" aria-hidden="true"><?= h($group['icon']) ?></span>
          <div>
            <h2 id="vt-group-<?= h($group['key']) ?>"><?= h($group['title']) ?></h2>
            <?php if ($group['intro'] !== ''): ?><p class="lede"><?= h($group['intro']) ?></p><?php endif; ?>
          </div>
          <span class="vt-group-count"><?= $groupPhotos ?> photo<?= $groupPhotos > 1 ? 's' : '' ?></span>
        </div>

        <div class="vt-gallery">
          <?php foreach ($group['photos'] as $photo): ?>
            <figure class="vt-shot">
              <a class="visit-shot" href="<?= h($photo['url']) ?>" data-title="<?= h($photo['shot']) ?>" data-caption="<?= h($photo['caption']) ?>">
                <img src="<?= h($photo['url']) ?>" alt="<?= h($photo['shot'] . ($photo['caption'] !== '' ? ' — ' . $photo['caption'] : '')) ?>" loading="lazy" decoding="async">
                <span class="vt-zoom" aria-hidden="true">Agrandir</span>
              </a>
              <figcaption>
                <b><?= h($photo['shot']) ?></b>
                <?php if ($photo['caption'] !== ''): ?><span><?= h($photo['caption']) ?></span><?php endif; ?>
              </figcaption>
            </figure>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="vt-cta">
  <div class="wrap vt-cta-in">
    <div>
      <p class="eyebrow">Sur place</p>
      <h2>La visite continue en vrai</h2>
      <p class="lede"><?= $contactAddress !== '' ? h($contactAddress) . ($contactHours !== '' ? ' · ' . h($contactHours) : '') : 'Retrouvez l’adresse, les horaires et l’itinéraire de la boutique.' ?></p>
    </div>
    <div class="vt-cta-actions">
      <a class="btn btn-primary" href="/index.php#contact">Contact et itinéraire</a>
      <a class="btn btn-ghost" href="/boutique.php">Voir les objets</a>
    </div>
  </div>
</section>

<div class="vt-lightbox" id="visit-lightbox" role="dialog" aria-modal="true" aria-labelledby="visit-lightbox-title" hidden>
  <button type="button" class="vt-lb-btn vt-lb-close" data-lb-close aria-label="Fermer la photo">×</button>
  <button type="button" class="vt-lb-btn vt-lb-prev" data-lb-prev aria-label="Photo précédente">‹</button>
  <figure>
    <img id="visit-lightbox-img" src="" alt="">
    <figcaption>
      <span id="visit-lightbox-title"></span>
      <small id="visit-lightbox-caption"></small>
      <span id="visit-lightbox-count" class="vt-lb-count"></span>
    </figcaption>
  </figure>
  <button type="button" class="vt-lb-btn vt-lb-next" data-lb-next aria-label="Photo suivante">›</button>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
