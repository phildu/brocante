<?php
// Page d'accueil de la plateforme (racine du domaine du portail) : présentation, formules, galeries et commerces à la une.
require_once __DIR__ . '/../includes/saas.php';

$cfg = saas_config();
$shops = saas_directory();
$pieces = array_sum(array_column($shops, 'count'));
$galleries = array_slice(array_filter(gallery_list(), static fn (array $g): bool => $g['published']), 0, 3);
$featured = array_slice(array_values(array_filter($shops, static fn (array $s): bool => $s['count'] > 0)), 0, 6);

saas_page_start($cfg['name'] . ' — ' . $cfg['tagline'], 'Créez votre boutique en ligne en quelques minutes, seule ou réunie avec d\'autres commerçants dans une galerie : photos, fiches et vidéos par l\'IA, paiement par carte.', 'accueil');
?>
<section class="hero"><div class="wrap">
  <p class="eyebrow">Pour les commerçants, brocanteurs et créateurs</p>
  <h1>Vendez en ligne avec <?= saas_e($cfg['name']) ?></h1>
  <p class="lede"><?= saas_e($cfg['tagline']) ?> Photographiez vos pièces avec votre téléphone : l'IA prépare photos, descriptions et vidéos.</p>
  <div class="actions">
    <a class="btn light" href="/inscription/">Créer ma boutique</a>
    <a class="btn ghost" href="#formules">Voir les formules</a>
  </div>
  <ul class="stats">
    <li><strong><?= count($shops) ?></strong>commerce<?= count($shops) > 1 ? 's' : '' ?></li>
    <li><strong><?= (int) $pieces ?></strong>pièce<?= $pieces > 1 ? 's' : '' ?> en ligne</li>
    <li><strong><?= count(array_filter(gallery_list(), static fn ($g) => $g['published'])) ?></strong>galerie<?= count($galleries) > 1 ? 's' : '' ?></li>
  </ul>
</div></section>

<section class="block" id="avantages"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Tout est prévu</p><h2>Une boutique complète, sans la complexité</h2>
    <p class="lede">Vous vendez, la plateforme s'occupe du reste : vitrine, photos, paiement, livraison, et la visibilité d'une communauté de commerçants.</p></div>
  <div class="features">
    <article class="feature"><div class="ico" aria-hidden="true">🏪</div><h3>Une boutique à votre image</h3><p>Votre logo, vos couleurs, vos polices et votre adresse. Accueil, catalogue, panier, commandes et administration sont fournis.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">✨</div><h3>Photos et fiches par l'IA</h3><p>Détourage, mises en situation, petites vidéos, titre, description, matières et prix suggérés : l'IA prépare, vous validez.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">📱</div><h3>Le Studio sur smartphone</h3><p>Prenez vos pièces en photo une par une ou par lots depuis votre téléphone : elles arrivent déjà prêtes à vendre.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">🖼️</div><h3>Galeries commerciales</h3><p>Réunissez-vous avec les commerçants de votre rue, de votre village ou de votre groupe d'amis sur une page commune.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">💳</div><h3>Paiement direct</h3><p>Vos clients paient par carte (Stripe) et l'argent arrive sur votre propre compte, avec retrait en boutique ou envoi postal.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">🧭</div><h3>Visible dans l'annuaire</h3><p>Votre boutique apparaît dans l'annuaire de la plateforme : vos clients vous trouvent, vos voisins aussi.</p></article>
  </div>
</div></section>

<section class="block"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Comment ça marche</p><h2>En ligne en trois étapes</h2></div>
  <ol class="steps">
    <li><strong>Vous choisissez votre formule</strong><span>Votre e-mail et la formule qui vous convient, puis le paiement en ligne si elle est payante.</span></li>
    <li><strong>Vous créez votre boutique</strong><span>Son nom, son design et votre compte : elle est en ligne dès que vous validez, et vous arrivez directement dessus.</span></li>
    <li><strong>Vous ajoutez vos pièces</strong><span>Photos depuis le téléphone, fiches préparées par l'IA : vous vendez dès la première pièce.</span></li>
  </ol>
</div></section>

<section class="block" id="formules"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Nos formules</p><h2>Choisissez celle qui vous ressemble</h2>
    <p class="lede">Une boutique seule, ou réunie avec d'autres dans une galerie commerciale : à vous de voir.</p></div>
  <?= saas_pricing_html(saas_grid(''), 'home', static fn (array $o, string $billing): string => '/inscription/?formule=' . rawurlencode($o['key']) . ($billing === 'year' ? '&facturation=year' : '')) ?>
  <?php $ownPricing = array_filter($galleries, static fn ($g) => isset(saas_pricing()['galleries'][$g['slug']])); if ($ownPricing): ?>
    <p class="lede" style="margin-top:18px">Certaines galeries commerciales ont leurs propres tarifs : <?= implode(', ', array_map(static fn ($g) => '<a href="/inscription/?galerie=' . saas_e($g['slug']) . '">' . saas_e($g['name']) . '</a> (' . saas_e(saas_grid_from_text(saas_grid($g['slug']))) . ')', $ownPricing)) ?>.</p>
  <?php endif; ?>
</div></section>

<?php if ($galleries): ?>
<section class="block"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Galeries commerciales</p><h2>Des commerçants réunis</h2>
    <p class="lede">Chaque galerie rassemble les pièces de plusieurs commerces, avec leur annuaire, leur recherche et leurs filtres.</p></div>
  <div class="galleries">
    <?php foreach ($galleries as $g): ?>
      <a class="gcard" href="/galerie/<?= saas_e($g['slug']) ?>/"><div class="band" style="background:<?= saas_e($g['accent']) ?>"></div>
        <div class="in"><h3><?= saas_e($g['name']) ?></h3><p><?= saas_e($g['tagline'] !== '' ? $g['tagline'] : count($g['members']) . ' commerce' . (count($g['members']) > 1 ? 's' : '')) ?></p></div></a>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<?php if ($featured): ?>
<section class="block"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Ils vendent déjà</p><h2>Des boutiques à découvrir</h2></div>
  <div class="shops">
    <?php foreach ($featured as $s): $initial = mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $s['name']), 0, 1)); ?>
      <a class="shop" href="<?= saas_e($s['url']) ?>">
        <span class="top">
          <span class="avatar"<?= $s['accent'] !== '' ? ' style="color:' . saas_e(saas_ink_on($s['accent'])) . ';background:' . saas_e($s['accent']) . '"' : '' ?>><?= saas_e($initial) ?><?php if ($s['logo'] !== ''): ?><img src="<?= saas_e($s['logo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
          <span><h3><?= saas_e($s['name']) ?></h3><p><?= saas_e($s['tagline']) ?><?= $s['tagline'] !== '' ? ' · ' : '' ?><?= (int) $s['count'] ?> pièce<?= $s['count'] > 1 ? 's' : '' ?></p></span>
        </span>
        <span class="more">Visiter la boutique →</span>
      </a>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:18px"><a href="/annuaire/">Voir tout l'annuaire →</a></p>
</div></section>
<?php endif; ?>

<section class="block"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Questions fréquentes</p><h2>Vous vous demandez…</h2></div>
  <div class="faq">
    <details><summary>Faut-il des compétences techniques ?</summary><p>Non. Vous photographiez vos pièces avec votre téléphone, l'IA propose le titre, la description et le prix, et vous validez. Nous sommes là pour la prise en main.</p></details>
    <details><summary>Puis-je rejoindre une galerie commerciale ?</summary><p>Oui : indiquez la galerie qui vous intéresse au moment de votre demande. Vous pouvez aussi nous proposer d'en créer une pour votre rue, votre village ou votre groupe d'amis.</p></details>
    <details><summary>Comment mes clients me paient-ils ?</summary><p>Par carte bancaire, directement sur votre compte Stripe. Chaque commerce garde son panier et ses commandes ; la galerie ne fait que présenter les pièces.</p></details>
    <details><summary>Combien de temps pour être en ligne ?</summary><p>Quelques minutes : une fois la formule choisie (et payée, le cas échéant), vous remplissez le nom, le design et votre mot de passe, et votre boutique est créée aussitôt. Nous vous écrivons aussi les liens de votre boutique et de votre administration.</p></details>
  </div>
</div></section>

<div class="wrap"><div class="cta-band">
  <h2>Prêt à commencer ?</h2>
  <p>Choisissez votre formule et lancez votre boutique en quelques minutes.</p>
  <a class="btn" href="/inscription/">Créer ma boutique</a>
</div></div>
<?php saas_page_end(); ?>
