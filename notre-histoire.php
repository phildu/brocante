<?php

// « Notre histoire » sur sa propre page : le récit complet de la maison, avec
// sa photo, ses trois repères et un partage direct. Le contenu se saisit dans
// Administration → Contenus (section « Notre histoire ») ; l'accueil en garde
// une section, cette page en est l'adresse à part entière.

require_once __DIR__ . '/includes/functions.php';

$content = get_content();

$storyTitle = trim((string) ($content['story_title'] ?? ''));
if ($storyTitle === '') $storyTitle = 'Notre histoire';
$storyText = trim((string) ($content['story_text'] ?? ''));
$storyPhoto = trim((string) ($content['story_photo'] ?? ''));
if ($storyPhoto === '') {
    $slides = hero_slides_list();
    $storyPhoto = trim((string) ($slides[0]['path'] ?? ''));
}
$storyCaption = trim((string) ($content['story_photo_caption'] ?? ''));
$storyEyebrow = trim((string) ($content['story_eyebrow'] ?? '')) ?: 'Notre histoire';

$paragraphs = [];
foreach (preg_split('/\R{2,}/u', $storyText) ?: [] as $chunk) {
    $chunk = trim($chunk);
    if ($chunk !== '') $paragraphs[] = $chunk;
}

$principles = [];
for ($i = 1; $i <= 3; $i++) {
    $title = trim((string) ($content["principle{$i}_title"] ?? ''));
    if ($title === '') continue;
    $principles[] = [
        'num' => '0' . $i,
        'title' => $title,
        'text' => trim((string) ($content["principle{$i}_text"] ?? '')),
    ];
}

$siteName = trim((string) ($content['site_name'] ?? '')) ?: (string) tenant('name');
$banner = get_page_banner('histoire');

$activeNav = 'histoire';
$pageTitle = 'Notre histoire';
$ogDescription = $storyText !== '' ? shop_excerpt($storyText, 180) : 'L’histoire de ' . $siteName . '.';
if ($storyPhoto !== '') $ogImage = $storyPhoto;
$pageStyles = ['/assets/notre-histoire.css?v=' . (int) @filemtime(__DIR__ . '/assets/notre-histoire.css')];

include __DIR__ . '/includes/header.php';
?>

<section class="nh-hero<?= $banner ? ' banner-zone has-page-banner' : '' ?>">
  <?php if ($banner): ?><?php render_page_banner('histoire'); ?><?php endif; ?>
  <div class="wrap nh-hero-in">
    <p class="eyebrow"><?= h($storyEyebrow) ?></p>
    <h1><?= h($storyTitle) ?></h1>
    <div class="nh-hero-actions">
      <a class="btn btn-primary" href="/boutique.php">Découvrir la boutique</a>
      <a class="btn btn-ghost" href="/nous-trouver.php">Venir nous voir</a>
    </div>
    <p class="share-row"><?= share_page_button('Notre histoire — ' . $siteName, $ogDescription) ?></p>
  </div>
</section>

<section class="nh-story">
  <div class="wrap nh-story-grid<?= $storyPhoto === '' ? ' is-plain' : '' ?>">
    <div class="nh-story-copy">
      <?php if ($paragraphs): ?>
        <?php foreach ($paragraphs as $p): ?><p><?= nl2br(h($p)) ?></p><?php endforeach; ?>
      <?php else: ?>
        <p>L’histoire de <?= h($siteName) ?> s’écrit encore. Passez nous voir ou écrivez-nous : nous aurons plaisir à vous la raconter.</p>
      <?php endif; ?>
      <p class="nh-sign"><?= h($siteName) ?></p>
    </div>
    <?php if ($storyPhoto !== ''): ?>
      <figure class="nh-figure">
        <img src="/<?= h($storyPhoto) ?>" alt="<?= h($storyCaption !== '' ? $storyCaption : 'L’atelier et la boutique ' . $siteName) ?>" loading="lazy">
        <?php if ($storyCaption !== ''): ?><figcaption><?= h($storyCaption) ?></figcaption><?php endif; ?>
      </figure>
    <?php endif; ?>
  </div>
</section>

<?php if ($principles): ?>
<section class="nh-values">
  <div class="wrap">
    <header class="nh-values-head">
      <p class="eyebrow">Ce qui nous guide</p>
      <h2>Trois repères, au quotidien</h2>
    </header>
    <div class="nh-values-grid">
      <?php foreach ($principles as $p): ?>
        <article class="nh-value">
          <span class="num"><?= h($p['num']) ?></span>
          <h3><?= h($p['title']) ?></h3>
          <?php if ($p['text'] !== ''): ?><p><?= h($p['text']) ?></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="nh-cta">
  <div class="wrap nh-cta-in">
    <h2>La suite se passe dans la boutique.</h2>
    <div class="nh-cta-actions">
      <a class="btn btn-primary" href="/boutique.php">Voir les pièces</a>
      <a class="btn btn-ghost" href="/visite.php">Visite virtuelle</a>
      <a class="btn btn-ghost" href="/nous-trouver.php">Nous trouver</a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
