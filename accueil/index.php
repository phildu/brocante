<?php
// Nom de domaine propre d'une galerie : le domaine pointe vers ce serveur, sa page publique est servie à la racine.
if (!empty($_SERVER['HTTP_HOST'])) {
    require_once __DIR__ . '/../includes/galleries.php';
    $hostGallery = gallery_from_host((string) $_SERVER['HTTP_HOST']);
    if ($hostGallery) {
        $_GET['g'] = (string) $hostGallery['slug'];
        require __DIR__ . '/../galerie/index.php';
        exit;
    }
}
// Page d'accueil de la plateforme (racine du domaine du portail) : quatre parcours (vendre, organiser, exposer, découvrir), les types de galerie
// comparés, les options à la carte, les formules d'une boutique, les galeries, les commerces à la une.
require_once __DIR__ . '/../includes/vocabulary.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/gallery-types.php';
require_once __DIR__ . '/../includes/gallery-booking.php';
require_once __DIR__ . '/../includes/addons.php';
require_once __DIR__ . '/../includes/banner-slides.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page

$cfg = saas_config();
$home = $cfg['home'];
$homeBanners = saas_home_banners($cfg);
$nameToken = static fn (string $s): string => str_replace(['{nom}', '{name}'], $cfg['name'], $s);
$homeEyebrow = $home['eyebrow'] !== '' ? $nameToken($home['eyebrow']) : 'Boutiques · galeries · marchés aux puces · vides-greniers';
$homeTitle = $home['title'] !== '' ? $nameToken($home['title']) : 'Vendez, réunissez, organisez avec ' . $cfg['name'];
$homeLede = $home['lede'] !== '' ? $nameToken($home['lede'])
    : $cfg['tagline'] . " Une boutique en ligne pour vendre, ou un lieu à gérer : galerie marchande, marché aux puces, vide-grenier, marché de Noël, marché des producteurs, quartier. Photos et fiches préparées par l'IA, paiement par carte, plan interactif.";
$shops = saas_directory();
$pieces = array_sum(array_column($shops, 'count'));
$live = array_values(array_filter(gallery_list(), static fn (array $g): bool => go_is_live($g)));
$galleries = array_slice($live, 0, 6);
$featured = array_slice(array_values(array_filter($shops, static fn (array $s): bool => $s['count'] > 0)), 0, 6);
$types = gt_active();
$e = static fn ($v): string => saas_e((string) $v);

// Galeries qui recrutent des exposants : fonctionnalité du type active et demandes ouvertes sur le plan.
$recruiting = array_values(array_filter($live, static fn (array $g): bool => gt_feature($g, 'exposants') && !empty($g['map']['booking']['enabled'])
    && array_filter($g['map']['items'] ?? [], static fn ($i) => $i['role'] === 'slot' && $i['shop'] === '')));
$recruiting = array_slice($recruiting, 0, 6);

/** Prix mensuel le plus bas parmi les offres payantes d'un type (centimes) ou null. */
$fromPrice = static function (array $t): ?int {
    $min = null;
    foreach ($t['offers'] as $o) if ($o['active'] && $o['month_cents'] > 0 && ($min === null || $o['month_cents'] < $min)) $min = $o['month_cents'];
    return $min;
};
$eur = static fn (int $c): string => rtrim(rtrim(number_format($c / 100, 2, ',', ' '), '0'), ',') . ' €';
$addons = addon_defaults();

// Campagne pionniers de la boutique seule : le palier du prochain inscrit remise les prix affichés tant qu'elle est ouverte.
$ebCampaign = function_exists('eb_campaign_for_signup') ? eb_campaign_for_signup('') : null;
$ebTier = $ebCampaign && $ebCampaign['progress']['open'] ? eb_tier_at($ebCampaign, $ebCampaign['progress']['next_rank']) : null;
$homeGrid = $ebTier ? eb_discounted_grid(saas_grid(''), $ebTier) : saas_grid('');

saas_page_start($cfg['name'] . ' — ' . $cfg['tagline'], 'Boutique en ligne, galerie commerciale, marché aux puces, vide-grenier, marché de Noël ou marché des producteurs : créez, organisez, exposez ou découvrez. Photos et fiches par l\'IA, paiement par carte, plan interactif.');
?>
<style>
  .band-nav { position: sticky; top: 61px; z-index: 9; background: color-mix(in srgb, var(--bg) 92%, transparent); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
  .band-nav .wrap { display: flex; gap: 6px; overflow-x: auto; padding: 8px 0; scrollbar-width: none; } .band-nav .wrap::-webkit-scrollbar { display: none; }
  .band-nav a { flex: none; text-decoration: none; padding: 7px 14px; border-radius: 999px; font-weight: 600; font-size: .9rem; border: 1px solid var(--line); background: var(--surface); }
  .band-nav a:hover { border-color: var(--accent); color: var(--accent); }
  .paths { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
  .path { --c: var(--accent); display: grid; gap: 8px; align-content: start; text-decoration: none; background: var(--surface); border: 1px solid var(--line); border-top: 6px solid var(--c); border-radius: var(--radius); padding: 22px; transition: transform .15s, box-shadow .15s; }
  .path:hover { transform: translateY(-3px); box-shadow: var(--shadow); }
  .path .big { font-size: 2rem; line-height: 1; } .path h3 { margin: 0; font-family: var(--display); font-size: 1.3rem; } .path p { margin: 0; color: var(--ink-soft); font-size: .94rem; }
  .path .go { margin-top: 4px; font-weight: 700; color: var(--c); }
  .path.p1 { --c: #b5502e; } .path.p2 { --c: #2f6b4f; } .path.p3 { --c: #3b5fa8; } .path.p4 { --c: #8a5a9e; }
  .sec-tag { display: inline-block; font-size: .75rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; padding: 4px 12px; border-radius: 999px; background: var(--c, var(--accent)); color: #fff; margin-bottom: 10px; }
  .types { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
  .types > .lp-hover { display: flex; } .types > .lp-hover > .type { flex: 1; }
  .type { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 22px; display: grid; gap: 10px; align-content: start; }
  .type .emo { font-size: 2.2rem; line-height: 1; } .type h3 { margin: 0; font-family: var(--display); font-size: 1.3rem; } .type > p { margin: 0; color: var(--ink-soft); }
  .type .price { font-size: .92rem; color: var(--ink-soft); } .type .price b { color: var(--ink); font-size: 1.15rem; }
  .chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 0; margin: 0; list-style: none; } .chips li { font-size: .78rem; background: var(--surface-2); padding: 3px 10px; border-radius: 999px; }
  .type .btn { margin-top: 4px; width: 100%; }
  .cmp { width: 100%; border-collapse: collapse; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden; font-size: .93rem; }
  .cmp th, .cmp td { padding: 10px 12px; border-bottom: 1px solid var(--line); text-align: center; } .cmp th:first-child, .cmp td:first-child { text-align: left; }
  .cmp thead th { background: var(--surface-2); font-family: var(--display); } .cmp tr:last-child td, .cmp tr:last-child th { border-bottom: 0; } .cmp .yes { color: var(--ok); font-weight: 700; } .cmp .no { color: var(--ink-soft); opacity: .5; }
  .scroll-x { overflow-x: auto; }
  .addons { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px; }
  .addon { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; display: grid; gap: 6px; align-content: start; }
  .addon h3 { margin: 0; font-size: 1.02rem; } .addon p { margin: 0; color: var(--ink-soft); font-size: .88rem; display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical; overflow: hidden; }
  .addon .pr { font-weight: 700; font-size: .9rem; } .addon .pr small { font-weight: 500; color: var(--ink-soft); }
  .recruit { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
  .login-band { display: flex; gap: 12px 24px; flex-wrap: wrap; align-items: center; justify-content: space-between; padding: 16px 20px; border: 1px dashed var(--line); border-radius: var(--radius); background: var(--surface); }
  .login-band a { font-weight: 600; }
  .tagt { font-size: .72rem; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: var(--surface-2); margin-left: 6px; vertical-align: middle; }
  .hero .actions .btn.ghost { background: color-mix(in srgb, var(--accent-ink) 10%, transparent); }
  .hero-media { position: absolute; inset: 0; z-index: 0; }
  .hero-media > i { position: absolute; inset: 0; background-size: cover; opacity: 0; transition: opacity 1.2s ease; }
  .hero-media > i.is-on { opacity: 1; }
  @media (prefers-reduced-motion: reduce) { .hero-media > i { transition: none; } }
  .hero-shade { position: absolute; inset: 0; z-index: 1; }
  .hero > .wrap { position: relative; z-index: 2; }
  .hero.has-banner { color: #fff; }
  .hero.has-banner::after { display: none; }
  .hero.has-banner .actions .btn.ghost { color: #fff; border-color: rgba(255, 255, 255, .55); background: rgba(255, 255, 255, .10); }
  .hero.has-banner .actions .btn.ghost:hover { background: rgba(255, 255, 255, .2); }
  .hero.has-banner .actions .btn.light { color: #1c1a17; }
  section[id] { scroll-margin-top: 120px; }
  @media (max-width: 700px) { .band-nav { position: static; } section[id] { scroll-margin-top: 90px; } }
</style>

<section class="hero<?= $homeBanners ? ' has-banner' : '' ?>">
  <?= banner_slides_html($homeBanners, (int) $home['overlay'], 'center', (bool) $home['slideshow']) ?>
  <div class="wrap">
  <p class="eyebrow"><?= $e($homeEyebrow) ?></p>
  <h1><?= $e($homeTitle) ?></h1>
  <p class="lede"><?= $e($homeLede) ?></p>
  <div class="actions">
    <a class="btn light" href="/inscription/"<?= cta_attrs('home_hero_primary') ?>><?= $e(cta_label('home_hero_primary')) ?></a>
    <a class="btn ghost" href="/galerie/creer.php"<?= cta_attrs('home_hero_gallery') ?>><?= $e(cta_label('home_hero_gallery')) ?></a>
    <a class="btn ghost" href="/portail/login.php"<?= cta_attrs('home_hero_login') ?>><?= $e(cta_label('home_hero_login')) ?></a>
    <a class="btn ghost" href="#parcours"<?= cta_attrs('home_hero_help') ?>><?= $e(cta_label('home_hero_help')) ?></a>
  </div>
  <ul class="stats">
    <li><strong><?= count($shops) ?></strong>commerce<?= count($shops) > 1 ? 's' : '' ?></li>
    <li><strong><?= (int) $pieces ?></strong>pièce<?= $pieces > 1 ? 's' : '' ?> en ligne</li>
    <li><strong><?= count($live) ?></strong>galerie<?= count($live) > 1 ? 's' : '' ?> et marché<?= count($live) > 1 ? 's' : '' ?></li>
  </ul>
</div></section>

<nav class="band-nav" aria-label="Sur cette page"><div class="wrap">
  <a href="#parcours">Par où commencer</a><a href="#boutique">Ma boutique</a><a href="#organiser">Galeries, marchés, vides-greniers</a><?php if ($recruiting): ?><a href="#exposer">Trouver un emplacement</a><?php endif; ?><a href="#options">{Exts}</a><a href="#decouvrir">Découvrir</a><a href="#faq">Questions</a>
</div></nav>

<section class="block" id="parcours"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Par où commencer</p><h2>Que souhaitez-vous faire ?</h2>
    <p class="lede">Quatre façons d'utiliser la plateforme : choisissez la vôtre, chacune a ses outils et ses tarifs.</p></div>
  <div class="paths">
    <a class="path p1" href="#boutique"><span class="big" aria-hidden="true">🏪</span><h3>Je vends</h3><p>Ma propre boutique en ligne : vitrine, photos par l'IA, panier, paiement direct, livraison ou retrait.</p><span class="go">Ma boutique →</span></a>
    <a class="path p2" href="#organiser"><span class="big" aria-hidden="true">🏘️</span><h3>J'organise</h3><p>Un lieu qui réunit des vendeurs : galerie marchande, marché aux puces, vide-grenier, marché de Noël, marché des producteurs, quartier marchand.</p><span class="go">Choisir mon type →</span></a>
    <a class="path p3" href="<?= $recruiting ? '#exposer' : '/galerie/' ?>"><span class="big" aria-hidden="true">📍</span><h3>J'expose</h3><p>Je cherche un emplacement : je choisis ma place sur le plan d'un vide-grenier ou d'un marché et je fais ma demande.</p><span class="go">Trouver un emplacement →</span></a>
    <a class="path p4" href="#decouvrir"><span class="big" aria-hidden="true">🧭</span><h3>Je découvre</h3><p>Je cherche une pièce : annuaire des boutiques, galeries commerciales, plans des marchés, points de fidélité.</p><span class="go">Explorer →</span></a>
  </div>
</div></section>

<section class="block" id="boutique" style="--c:#b5502e"><div class="wrap">
  <div class="sec-head"><span class="sec-tag">Je vends</span><h2>Une boutique complète, sans la complexité</h2>
    <p class="lede">Vous vendez, la plateforme s'occupe du reste : vitrine, photos, paiement, livraison, et la visibilité d'une communauté de commerçants.</p></div>
  <div class="features">
    <article class="feature"><div class="ico" aria-hidden="true">🏪</div><h3>Une boutique à votre image</h3><p>Votre logo, vos couleurs, vos polices et votre adresse. Accueil, catalogue, panier, commandes et administration sont fournis.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">✨</div><h3>Photos et fiches par l'IA</h3><p>Détourage, mises en situation, petites vidéos, titre, description, matières et prix suggérés : l'IA prépare, vous validez.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">📱</div><h3>Le Studio sur smartphone</h3><p>Prenez vos pièces en photo depuis votre téléphone, avec la caisse et le scan de QR codes : elles arrivent prêtes à vendre.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">💳</div><h3>Paiement direct</h3><p>Vos clients paient par carte (Stripe) et l'argent arrive sur votre propre compte, avec retrait en boutique ou envoi postal.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">🎁</div><h3>Fidélité et alertes</h3><p>Points et récompenses pour vos clients, alertes chineur, réservations avec arrhes ou acompte : à activer parmi {les exts}, ci-dessous.</p></article>
    <article class="feature"><div class="ico" aria-hidden="true">🧭</div><h3>Visible partout</h3><p>Votre boutique apparaît dans l'annuaire et peut rejoindre une galerie, un marché ou un quartier marchand.</p></article>
  </div>
  <div class="sec-head" style="margin-top:44px"><h2 style="font-size:1.6rem">Les formules de la boutique</h2>
    <p class="lede">Une boutique seule ou réunie avec d'autres : le tarif ne dépend pas du lieu que vous rejoignez.</p></div>
  <?php if ($ebCampaign && $ebCampaign['enabled']): ?><?= eb_widget_html('') ?><?php endif; ?>
  <?= saas_pricing_html($homeGrid, 'home', static fn (array $o, string $billing): string => '/inscription/?formule=' . rawurlencode($o['key']) . ($billing === 'year' ? '&facturation=year' : '')) ?>
  <?php $ownPricing = array_filter($galleries, static fn ($g) => isset(saas_pricing()['galleries'][$g['slug']])); if ($ownPricing): ?>
    <p class="lede" style="margin-top:18px">Certaines galeries ont leurs propres tarifs : <?= implode(', ', array_map(static fn ($g) => '<a href="/inscription/?galerie=' . saas_e($g['slug']) . '">' . saas_e($g['name']) . '</a>', $ownPricing)) ?>.</p>
  <?php endif; ?>
</div></section>

<section class="block" id="organiser" style="--c:#2f6b4f"><div class="wrap">
  <div class="sec-head"><span class="sec-tag">J'organise</span><h2>Galerie, marché, vide-grenier, marché de Noël ou quartier : le bon outil pour chaque lieu</h2>
    <p class="lede">Chaque type a son vocabulaire, ses fonctionnalités et ses tarifs. Test gratuit sans carte bancaire, puis un abonnement selon le nombre de participants.</p></div>
  <div class="types">
    <?php foreach ($types as $tk => $t): $from = $fromPrice($t); $test = $t['offers']['test'] ?? null; ?>
      <div class="lp-hover">
        <article class="type" id="type-<?= $e($tk) ?>">
          <span class="emo" aria-hidden="true"><?= $e($t['emoji']) ?></span>
          <h3><?= $e($t['name']) ?></h3>
          <p><?= $e($t['tagline']) ?></p>
          <ul class="chips" aria-label="Fonctionnalités"><?php foreach (GT_FEATURES as $fk => $fl): if (!empty($t['features'][$fk])): ?><li><?= $e(explode(' (', $fl)[0]) ?></li><?php endif; endforeach; ?></ul>
          <p class="price">Pour des <b><?= $e($t['vocab']['members']) ?></b><br><?= $test && $test['active'] ? 'Test gratuit ' . (int) $test['trial_days'] . ' jours' : '' ?><?= $from ? ($test && $test['active'] ? ' · puis ' : '') . 'dès <b>' . $e($eur($from)) . '</b> / mois' : '' ?></p>
          <a class="btn" href="/galerie/creer.php?type=<?= $e($tk) ?>"<?= cta_attrs('home_type_create', (string) $t['vocab']['create_cta']) ?>><?= $e(cta_label('home_type_create', (string) $t['vocab']['create_cta'])) ?></a>
        </article>
        <?= gt_landing_preview_html((string) $tk, (string) $t['tagline'], 'Aperçu ' . $t['name']) ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (count($types) > 1): ?>
  <div class="sec-head" style="margin-top:40px"><h2 style="font-size:1.5rem">Comparer les types</h2></div>
  <div class="scroll-x"><table class="cmp">
    <thead><tr><th scope="col">Fonctionnalité</th><?php foreach ($types as $t): ?><th scope="col"><?= $e($t['emoji']) ?> <?= $e($t['name']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <tr><th scope="row" style="text-align:left;font-weight:600">Participants</th><?php foreach ($types as $t): ?><td><?= $e(ucfirst($t['vocab']['members'])) ?></td><?php endforeach; ?></tr>
      <?php foreach (GT_FEATURES as $fk => $fl): ?>
        <tr><th scope="row" style="text-align:left;font-weight:500"><?= $e($fl) ?></th><?php foreach ($types as $t): ?><td><?= !empty($t['features'][$fk]) ? '<span class="yes" aria-label="inclus">✓</span>' : '<span class="no" aria-label="non inclus">—</span>' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <tr><th scope="row" style="text-align:left;font-weight:600">À partir de</th><?php foreach ($types as $t): $from = $fromPrice($t); ?><td><b><?= $from ? $e($eur($from)) . ' / mois' : 'Gratuit' ?></b></td><?php endforeach; ?></tr>
    </tbody>
  </table></div>
  <?php endif; ?>
  <div class="cta-band" style="margin-top:32px"><h2>Pas sûr du type ?</h2><p>Commencez par le test gratuit : vous pourrez changer de type et d'offre plus tard, sur simple demande.</p><a class="btn" href="/galerie/creer.php"<?= cta_attrs('home_types_cta') ?>><?= $e(cta_label('home_types_cta')) ?></a></div>
</div></section>

<?php if ($recruiting): ?>
<section class="block" id="exposer" style="--c:#3b5fa8"><div class="wrap">
  <div class="sec-head"><span class="sec-tag">J'expose</span><h2>Ces lieux recherchent des exposants</h2>
    <p class="lede">Ouvrez le plan, choisissez un emplacement libre (son tarif est affiché) et envoyez votre demande : l'organisateur vous répond par e-mail.</p></div>
  <div class="recruit">
    <?php foreach ($recruiting as $g): $t = gt_of($g); $free = count(array_filter($g['map']['items'], static fn ($i) => $i['role'] === 'slot' && $i['shop'] === '')); ?>
      <a class="gcard" href="/galerie/<?= $e($g['slug']) ?>/#plan-commerces"><div class="band" style="background:<?= $e($g['accent']) ?>"></div>
        <div class="in"><h3><?= $e($g['name']) ?></h3><p><?= $e($t['emoji'] . ' ' . $t['name']) ?> · <?= (int) $free ?> emplacement<?= $free > 1 ? 's' : '' ?> sur le plan</p><p style="margin-top:6px;font-weight:700;color:var(--accent)">Choisir mon emplacement →</p></div></a>
    <?php endforeach; ?>
  </div>
</div></section>
<?php endif; ?>

<section class="block" id="options"><div class="wrap">
  <div class="sec-head"><span class="sec-tag" style="--c:#8a5a9e">{Exts}</span><h2>Des compléments à la carte</h2>
    <p class="lede">Activez ce dont vous avez besoin, boutique par boutique. Chaque {ext} s'essaie gratuitement avant de s'abonner.</p></div>
  <div class="addons">
    <?php foreach ($addons as $id => $a): ?>
      <article class="addon"><h3><?= $e($a['name']) ?></h3><p><?= $e($a['description']) ?></p>
        <span class="pr"><?= $e($eur((int) $a['monthly_cents'])) ?> <small>/ mois<?= (int) $a['trial_days'] > 0 ? ' · essai ' . (int) $a['trial_days'] . ' jours' : '' ?></small></span></article>
    <?php endforeach; ?>
  </div>
</div></section>

<section class="block"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Comment ça marche</p><h2>En ligne en trois étapes</h2></div>
  <ol class="steps">
    <li><strong>Vous choisissez votre offre</strong><span>Boutique, galerie, marché ou vide-grenier : votre e-mail, l'offre qui vous convient, puis le paiement en ligne si elle est payante.</span></li>
    <li><strong>Vous créez et personnalisez</strong><span>Son nom, son design, son plan : c'est en ligne dès que vous validez, et vous arrivez directement dessus.</span></li>
    <li><strong>Vous accueillez vos clients</strong><span>Pièces, exposants, commerçants, points de fidélité : vous vendez dès la première pièce.</span></li>
  </ol>
</div></section>

<?php if ($galleries || $featured): ?>
<section class="block" id="decouvrir" style="--c:#8a5a9e"><div class="wrap">
  <div class="sec-head"><span class="sec-tag">Je découvre</span><h2>Des lieux et des boutiques à visiter</h2></div>
  <?php if ($galleries): ?>
  <div class="galleries">
    <?php foreach ($galleries as $g): $t = gt_of($g); ?>
      <a class="gcard" href="/galerie/<?= $e($g['slug']) ?>/"><div class="band" style="background:<?= $e($g['accent']) ?>"></div>
        <div class="in"><h3><?= $e($g['name']) ?><span class="tagt"><?= $e($t['emoji'] . ' ' . $t['name']) ?></span></h3><p><?= $e($g['tagline'] !== '' ? $g['tagline'] : count($g['members']) . ' ' . (count($g['members']) > 1 ? $t['vocab']['members'] : $t['vocab']['member'])) ?></p></div></a>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:14px"><a href="/galerie/">Toutes les galeries et marchés →</a></p>
  <?php endif; ?>
  <?php if ($featured): ?>
  <div class="shops" style="margin-top:28px">
    <?php foreach ($featured as $s): $initial = mb_strtoupper(mb_substr(preg_replace('/^[^\p{L}\p{N}]+/u', '', $s['name']), 0, 1)); ?>
      <a class="shop" href="<?= $e($s['url']) ?>">
        <span class="top">
          <span class="avatar"<?= $s['accent'] !== '' ? ' style="color:' . $e(saas_ink_on($s['accent'])) . ';background:' . $e($s['accent']) . '"' : '' ?>><?= $e($initial) ?><?php if ($s['logo'] !== ''): ?><img src="<?= $e($s['logo']) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
          <span><h3><?= $e($s['name']) ?></h3><p><?= $e($s['tagline']) ?><?= $s['tagline'] !== '' ? ' · ' : '' ?><?= (int) $s['count'] ?> pièce<?= $s['count'] > 1 ? 's' : '' ?></p></span>
        </span>
        <span class="more">Visiter la boutique →</span>
      </a>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:18px"><a href="/annuaire/">Voir tout l'annuaire →</a></p>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<section class="block" id="faq"><div class="wrap">
  <div class="sec-head"><p class="eyebrow">Questions fréquentes</p><h2>Vous vous demandez…</h2></div>
  <div class="faq">
    <details><summary>Quelle différence entre une boutique et une galerie ?</summary><p>Une boutique est votre commerce, avec son panier et son paiement. Une galerie (ou un marché, un vide-grenier, un quartier marchand) réunit plusieurs boutiques ou exposants sur une page commune : chacun garde son panier, la galerie présente et renvoie vers lui.</p></details>
    <details><summary>Quel type de galerie choisir ?</summary><p>Galerie marchande pour des commerces établis ; marché aux puces pour des marchands sur des emplacements numérotés avec tarifs par zone ; vide-grenier pour des exposants qui demandent leur place sur le plan ; marché de Noël pour des chalets numérotés le temps des fêtes ; marché des producteurs et créateurs pour les producteurs locaux, artisans et créateurs ; quartier marchand pour les commerçants d'une rue, d'un quartier ou d'un village. Le tableau ci-dessus les compare.</p></details>
    <details><summary>Faut-il des compétences techniques ?</summary><p>Non. Vous photographiez vos pièces avec votre téléphone, l'IA propose le titre, la description et le prix, et vous validez. Pour un plan, vous dessinez sur une carte et la plateforme calcule surfaces et tarifs.</p></details>
    <details><summary>Comment les exposants réservent-ils un emplacement ?</summary><p>L'exposant demande son emplacement sur le plan ; vous acceptez ou refusez en un clic, il est prévenu par e-mail. Le règlement de l'emplacement se fait selon vos modalités.</p></details>
    <details><summary>Comment mes clients me paient-ils ?</summary><p>Par carte bancaire, directement sur votre compte Stripe. Chaque commerce garde son panier et ses commandes ; la galerie ne fait que présenter.</p></details>
    <details><summary>Combien de temps pour être en ligne ?</summary><p>Quelques minutes : une fois l'offre choisie (et payée, le cas échéant), vous remplissez le nom, le design et votre mot de passe.</p></details>
  </div>
</div></section>

<div class="wrap">
  <div class="login-band" id="connexion"><strong>Déjà client ?</strong>
    <span>Ma boutique : <a href="/annuaire/">la retrouver dans l'annuaire</a>, puis « Administration » en bas de sa page</span>
    <span>Ma galerie ou mon marché : <a href="/galerie/admin.php">se connecter</a></span>
    <span>Exploitant du portail : <a href="/portail/login.php">se connecter au portail</a></span></div>
  <div class="cta-band">
    <h2>Prêt à commencer ?</h2>
    <p>Une boutique pour vendre, ou un lieu à organiser : lancez-vous en quelques minutes.</p>
    <a class="btn" href="/inscription/"<?= cta_attrs('home_final_primary') ?>><?= $e(cta_label('home_final_primary')) ?></a> <a class="btn ghost" style="border-color:var(--accent);color:var(--accent)" href="/galerie/creer.php"<?= cta_attrs('home_final_gallery') ?>><?= $e(cta_label('home_final_gallery')) ?></a>
  </div>
</div>
<?= banner_slides_script() ?>
<?= gt_landing_preview_assets() ?>
<?php saas_page_end(); ?>
