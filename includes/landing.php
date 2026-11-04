<?php
// Pages d'atterrissage par type de galerie : /vide-greniers/, /marche-aux-puces/, /galerie-marchande/, /quartier-marchand/,
// /marche-de-noel/, /marche-producteurs/. Un message par acheteur (organisateur, placier, galerie, commune) ; les tarifs et le
// nombre de membres sont lus dans le type (includes/gallery-types.php) pour ne jamais afficher un prix périmé. Le gabarit est
// partagé, le texte vient de chaque page.

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/galleries.php';
require_once __DIR__ . '/gallery-types.php';
require_once __DIR__ . '/marges.php';

function lp_e($v): string
{
    return saas_e((string) ($v ?? ''));
}

/** Galeries publiées d'un type, limitées : ['identifiant' => galerie]. */
function lp_live(string $typeKey, int $limit = 6): array
{
    $out = [];
    foreach (gallery_list() as $slug => $g) {
        if (gt_of($g)['key'] !== $typeKey || !go_is_live($g)) continue;
        $out[$slug] = $g;
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Gabarit commun. $p : type, path, accent, eyebrow, h1, lede, audience, pains, features, steps, roi, faq. */
function lp_render(array $p): void
{
    $type = gt_type((string) $p['type']);
    $V = $type['vocab'];
    $create = '/galerie/creer.php?type=' . rawurlencode($type['key']);
    $offers = array_values(array_filter($type['offers'], static fn (array $o): bool => !empty($o['active'])));
    $margePct = marge_pct($type['key']);
    $margeOffer = null;
    foreach ($offers as $candidate) {
        if (marge_monthly_cents($candidate) !== null) { $margeOffer = $candidate; break; }
    }
    $roi = array_values((array) ($p['roi'] ?? []));
    $margeMonthly = $margeOffer ? marge_monthly_cents($margeOffer) : null;
    $breakEven = marge_break_even_cents($margeMonthly, $margePct);
    if ($margeOffer && $breakEven) {
        $roi[] = [saas_money($breakEven), 'de ventes par mois suffisent à couvrir l\'offre ' . $margeOffer['name']
            . ' (' . saas_money((int) $margeMonthly) . ' / mois, à ' . $margePct . ' % de marge moyenne)'];
    }
    $live = lp_live($type['key']);
    $members = 0;
    foreach ($live as $g) $members += count($g['members'] ?? []);
    $accent = (string) ($p['accent'] ?? '#b5502e');
    $ink = saas_ink_on($accent);
    $siblings = [];
    foreach (gt_types() as $siblingKey => $siblingType) {
        $siblingMeta = gt_landing_meta((string) $siblingKey);
        if ($siblingMeta) $siblings[$siblingKey] = [$siblingMeta['path'], $siblingType['name'], $siblingType['emoji']];
    }
    $eur = static fn (int $cents): string => saas_money($cents);

    saas_page_start($p['title'], $p['description']);
    ?>
    <style>
      .lp { --accent: <?= lp_e($accent) ?>; --accent-ink: <?= lp_e($ink) ?>; }
      .lp-nav { position: sticky; top: 61px; z-index: 9; background: color-mix(in srgb, var(--bg) 92%, transparent); backdrop-filter: blur(10px); border-bottom: 1px solid var(--line); }
      .lp-nav .wrap { display: flex; gap: 6px; overflow-x: auto; padding: 8px 0; scrollbar-width: none; }
      .lp-nav .wrap::-webkit-scrollbar { display: none; }
      .lp-nav a { flex: none; text-decoration: none; padding: 7px 14px; border-radius: 999px; font-weight: 600; font-size: .88rem; border: 1px solid var(--line); background: var(--surface); }
      .lp-nav a:hover { border-color: var(--accent); color: var(--accent); }
      .lp-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
      .lp-card { background: var(--surface); border: 1px solid var(--line); border-left: 5px solid var(--accent); border-radius: var(--radius); padding: 20px; }
      .lp-card h3 { margin: 0 0 6px; font-size: 1.05rem; } .lp-card p { margin: 0; color: var(--ink-soft); font-size: .94rem; }
      .lp-card .who { font-size: .8rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--accent); }
      .lp-pains { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 14px; padding: 0; margin: 0; list-style: none; }
      .lp-pains li { position: relative; padding: 16px 18px 16px 44px; background: var(--surface-2); border-radius: var(--radius); }
      .lp-pains li::before { content: "✕"; position: absolute; left: 16px; top: 16px; font-weight: 700; color: var(--danger); }
      .lp-pains b { display: block; margin-bottom: 2px; } .lp-pains span { color: var(--ink-soft); font-size: .93rem; }
      .lp-roi { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin: 0 0 18px; padding: 0; list-style: none; }
      .lp-roi li { background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; }
      .lp-roi strong { display: block; font-family: var(--display); font-size: 1.9rem; line-height: 1.1; color: var(--accent); }
      .lp-roi span { color: var(--ink-soft); font-size: .9rem; }
      .lp-breakeven { margin: 0 0 12px; font-size: .86rem; color: var(--ink-soft); }
      .lp-breakeven strong { color: var(--accent); }
      .lp-faq { display: grid; gap: 10px; }
      .lp-faq details { background: var(--surface); border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; }
      .lp-faq summary { cursor: pointer; font-weight: 600; }
      .lp-faq summary::marker { color: var(--accent); }
      .lp-faq p { margin: 10px 0 0; color: var(--ink-soft); }
      .lp-live { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; }
      .lp-live a { display: grid; gap: 2px; text-decoration: none; background: var(--surface); border: 1px solid var(--line); border-radius: var(--radius); padding: 18px; }
      .lp-live a:hover { border-color: var(--accent); }
      .lp-live b { font-family: var(--display); font-size: 1.1rem; } .lp-live span { color: var(--ink-soft); font-size: .88rem; }
      .lp-note { font-size: .86rem; color: var(--ink-soft); margin: 14px 0 0; }
      .lp-siblings { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; margin-top: 18px; }
      .lp-siblings a { text-decoration: none; border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; background: var(--surface); display: grid; gap: 2px; }
      .lp-siblings a:hover { border-color: var(--accent); }
      .lp-siblings a[aria-current] { border-color: var(--accent); box-shadow: var(--shadow); }
      .lp-siblings b { font-family: var(--display); } .lp-siblings span { color: var(--ink-soft); font-size: .86rem; }
      @media (max-width: 700px) { .lp-nav { position: static; } }
    </style>
    <div class="lp">
      <section class="hero">
        <div class="wrap">
          <p class="eyebrow"><?= lp_e($p['eyebrow']) ?></p>
          <h1><?= lp_e($p['h1']) ?></h1>
          <p class="lede"><?= lp_e($p['lede']) ?></p>
          <div class="actions">
            <a class="btn light" href="<?= lp_e($create) ?>"><?= lp_e($V['create_cta']) ?></a>
            <a class="btn ghost" href="#tarifs">Voir les tarifs</a>
            <?php if ($live): ?><a class="btn ghost" href="#exemples"><?= lp_e($type['name']) ?> déjà en ligne</a><?php endif; ?>
          </div>
          <ul class="stats">
            <li><strong><?= count($live) ?></strong> <?= lp_e($V['gallery']) ?><?= count($live) > 1 ? 's' : '' ?> en ligne</li>
            <?php if ($members): ?><li><strong><?= $members ?></strong> <?= lp_e($V['members']) ?> réunis</li><?php endif; ?>
            <li><strong>0 %</strong> de commission sur les ventes</li>
          </ul>
        </div>
      </section>

      <nav class="lp-nav" aria-label="Sur cette page"><div class="wrap">
        <a href="#public">À qui ça sert</a><a href="#probleme">Ce qui coince</a><a href="#outils">Les outils</a><a href="#etapes">Comment ça marche</a><a href="#tarifs">Tarifs</a><a href="#questions">Questions</a>
      </div></nav>

      <section class="block" id="public"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">À qui ça sert</p><h2><?= lp_e($p['audience_title']) ?></h2><p class="lede"><?= lp_e($p['audience_lede']) ?></p></div>
        <div class="lp-cards">
          <?php foreach ($p['audience'] as [$who, $title, $text]): ?>
            <article class="lp-card"><p class="who"><?= lp_e($who) ?></p><h3><?= lp_e($title) ?></h3><p><?= lp_e($text) ?></p></article>
          <?php endforeach; ?>
        </div>
      </div></section>

      <section class="block" id="probleme"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Ce qui coince aujourd'hui</p><h2><?= lp_e($p['pain_title']) ?></h2></div>
        <ul class="lp-pains">
          <?php foreach ($p['pains'] as [$title, $text]): ?>
            <li><b><?= lp_e($title) ?></b><span><?= lp_e($text) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div></section>

      <section class="block" id="outils"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Les outils</p><h2><?= lp_e($p['tools_title']) ?></h2><p class="lede"><?= lp_e($p['tools_lede']) ?></p></div>
        <div class="features">
          <?php foreach ($p['features'] as [$ico, $title, $text]): ?>
            <article class="feature"><div class="ico" aria-hidden="true"><?= $ico ?></div><h3><?= lp_e($title) ?></h3><p><?= lp_e($text) ?></p></article>
          <?php endforeach; ?>
        </div>
      </div></section>

      <section class="block" id="etapes"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Comment ça marche</p><h2><?= lp_e($p['steps_title']) ?></h2></div>
        <ol class="steps">
          <?php foreach ($p['steps'] as [$title, $text]): ?>
            <li><strong><?= lp_e($title) ?></strong><span><?= lp_e($text) ?></span></li>
          <?php endforeach; ?>
        </ol>
      </div></section>

      <section class="block" id="tarifs"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Tarifs</p><h2>Un abonnement, quel que soit le nombre de visiteurs</h2>
          <p class="lede">Le prix ne dépend ni du nombre de places vendues, ni du chiffre d'affaires. Vous changez d'offre ou vous arrêtez depuis votre administration.</p></div>
        <div class="plans">
          <?php foreach ($offers as $i => $o):
              $free = (int) $o['month_cents'] === 0;
              $featured = $o['key'] === 'essentiel' || ($i === 1 && count($offers) > 1); ?>
            <article class="plan<?= $featured ? ' is-featured' : '' ?>">
              <?php if ($featured): ?><span class="tag">Le plus choisi</span><?php endif; ?>
              <h3><?= lp_e($o['name']) ?></h3>
              <p class="lede" style="font-size:.92rem"><?= lp_e($o['tagline']) ?></p>
              <p class="price"><?= $free ? 'Gratuit' : lp_e($eur((int) $o['month_cents'])) . ' <small>/ mois</small>' ?></p>
              <?php if (!$free && (int) $o['year_cents'] > 0): ?><p class="lede" style="margin:-8px 0 12px;font-size:.9rem">ou <?= lp_e($eur((int) $o['year_cents'])) ?> / an</p><?php endif; ?>
              <?php if ($free && (int) $o['trial_days'] > 0): ?><p class="lede" style="margin:-8px 0 12px;font-size:.9rem"><?= (int) $o['trial_days'] ?> jours d'essai, sans carte bancaire</p><?php endif; ?>
              <?php if (($planBreakEven = marge_break_even_cents(marge_monthly_cents($o), $margePct)) !== null): ?>
                <p class="lp-breakeven">≈ <strong><?= lp_e($eur($planBreakEven)) ?></strong> de ventes / mois à <?= (int) $margePct ?> % de marge</p>
              <?php endif; ?>
              <ul>
                <li>Jusqu'à <?= (int) $o['max_members'] ?> <?= lp_e($V['members']) ?></li>
                <li>Plan et <?= lp_e($V['places']) ?> sur la page publique</li>
                <li><?= lp_e($p['plan_bullet']) ?></li>
                <li>Sans commission sur les ventes</li>
              </ul>
              <a class="btn<?= $featured ? '' : ' ghost' ?>" href="<?= lp_e($create . '&offre=' . $o['key']) ?>">Choisir <?= lp_e($o['name']) ?></a>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="lp-note">TVA non applicable, article 293 B du CGI. Le prix affiché est celui de la galerie ; les vendeurs qui ont leur propre boutique gardent leur abonnement et encaissent leurs ventes. Le chiffre d'affaires de rentabilité est calculé avec le taux de marge moyen du type de commerce (<?= (int) $margePct ?> %) : il donne un ordre de grandeur, pas une garantie de résultat.</p>
      </div></section>

      <section class="block" id="economie"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Le calcul</p><h2><?= lp_e($p['roi_title']) ?></h2></div>
        <ul class="lp-roi">
          <?php foreach ($roi as [$value, $label]): ?><li><strong><?= lp_e($value) ?></strong><span><?= lp_e($label) ?></span></li><?php endforeach; ?>
        </ul>
        <p class="lede"><?= lp_e($p['roi_text']) ?></p>
        <?php if ($breakEven): ?><p class="lp-note">Chiffre d'affaires de rentabilité = prix de la formule ÷ taux de marge moyen (<?= (int) $margePct ?> % pour ce type). Exemple : <?= lp_e($eur((int) $margeMonthly)) ?> ÷ <?= (int) $margePct ?> % = <?= lp_e($eur($breakEven)) ?> de ventes par mois. La marge est la part du prix de vente qui reste après l'achat des pièces : une pièce achetée 20 € et revendue 50 € laisse 60 % de marge.</p><?php endif; ?>
      </div></section>

      <section class="block" id="exemples"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Déjà en ligne</p><h2><?= lp_e($p['live_title']) ?></h2></div>
        <?php if ($live): ?>
          <div class="lp-live">
            <?php foreach ($live as $slug => $g): $n = count($g['members'] ?? []); ?>
              <a href="/galerie/<?= lp_e($slug) ?>/"><b><?= lp_e($g['name'] ?? $slug) ?></b><span><?= $n ?> <?= lp_e($n > 1 ? $V['members'] : $V['member']) ?><?= !empty($g['city']) ? ' · ' . lp_e($g['city']) : '' ?></span></a>
            <?php endforeach; ?>
          </div>
          <p style="margin-top:18px"><a href="/galerie/">Toutes les galeries et marchés →</a></p>
        <?php else: ?>
          <p class="lede">Aucun <?= lp_e($V['gallery']) ?> de ce type n'est encore publié sur la plateforme. Le vôtre peut être le premier : l'ouverture prend une soirée.</p>
        <?php endif; ?>
      </div></section>

      <section class="block" id="questions"><div class="wrap">
        <div class="sec-head"><p class="eyebrow">Questions fréquentes</p><h2>Ce qu'on nous demande avant de commencer</h2></div>
        <div class="lp-faq">
          <?php foreach ($p['faq'] as [$q, $a]): ?>
            <details><summary><?= lp_e($q) ?></summary><p><?= lp_e($a) ?></p></details>
          <?php endforeach; ?>
        </div>
      </div></section>

      <section class="block"><div class="wrap">
        <div class="cta-band">
          <h2><?= lp_e($p['cta_title']) ?></h2>
          <p class="lede" style="margin:10px auto 22px;max-width:56ch"><?= lp_e($p['cta_text']) ?></p>
          <p><a class="btn" href="<?= lp_e($create) ?>"><?= lp_e($V['create_cta']) ?></a> <a class="btn ghost" href="/galerie/">Voir les galeries</a></p>
        </div>
        <div class="lp-siblings">
          <?php foreach ($siblings as $key => [$href, $label, $ico]): ?>
            <a href="<?= lp_e($href) ?>"<?= $key === $type['key'] ? ' aria-current="page"' : '' ?>><span aria-hidden="true"><?= $ico ?></span><b><?= lp_e($label) ?></b><span><?= $key === $type['key'] ? 'Vous êtes ici' : 'Découvrir' ?></span></a>
          <?php endforeach; ?>
        </div>
      </div></section>
    </div>
    <?php
    $faq = array_map(static fn (array $qa): array => [
        '@type' => 'Question',
        'name' => $qa[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $qa[1]],
    ], $p['faq']);
    $json = json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            ['@type' => 'BreadcrumbList', 'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Accueil', 'item' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'brocs.arrimage.com') . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $p['eyebrow'], 'item' => 'https://' . ($_SERVER['HTTP_HOST'] ?? 'brocs.arrimage.com') . $p['path']],
            ]],
            ['@type' => 'FAQPage', 'mainEntity' => $faq],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo '<script type="application/ld+json">' . $json . '</script>';
    saas_page_end();
}
