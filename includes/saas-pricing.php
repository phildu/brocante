<?php

// Tarifs de la plateforme : une grille par défaut et, au choix, une grille propre à chaque galerie commerciale (portail → Tarifs).
//
// Une grille propose des OFFRES de deux sortes, qu'on peut combiner (alternative) :
//   - des formules (« plan ») : Découverte, Standard…, avec ou sans limite d'articles ;
//   - des tranches (« tier ») : jusqu'à N articles, un prix par tranche (la dernière peut être illimitée).
// Chaque offre a un prix MENSUEL et un prix ANNUEL (laissé vide : calculé avec la remise annuelle de la grille ; la facturation annuelle peut être coupée).
// Un prix vide = « sur devis » (jamais payé en ligne). Un prix à 0 = gratuit.
//
// Une galerie sans grille propre hérite de la grille par défaut. Le client choisit sa galerie dès l'étape 1 de l'inscription (/inscription/?galerie=…),
// car c'est elle qui détermine les prix ; l'offre choisie est « figée » dans la demande (request['offer']) : un changement de tarif ne modifie pas
// ce qui a été vendu. Stockage : data/saas/tarifs.json.

const SAAS_BILLING = ['month' => ['mois', 'mensuel'], 'year' => ['an', 'annuel']];

function saas_pricing_file(): string
{
    return saas_data_dir() . '/saas/tarifs.json';
}

/** Une offre : valeurs sûres, prix en centimes (null = sur devis). */
function saas_offer_normalize(array $o, string $model): ?array
{
    $max = isset($o['max_items']) && $o['max_items'] !== '' && $o['max_items'] !== null ? max(1, min(1000000, (int) $o['max_items'])) : null;
    $name = mb_substr(trim((string) ($o['name'] ?? '')), 0, 40);
    if ($model === 'plan' && $name === '') return null;
    if ($model === 'tier' && $name === '') $name = $max === null ? 'Articles illimités' : "Jusqu'à " . number_format($max, 0, ',', ' ') . ' articles';
    $cents = static fn ($v): ?int => ($v === null || $v === '' || !is_numeric($v)) ? null : max(0, min(10000000, (int) $v));
    $key = (string) ($o['key'] ?? '');
    if (!preg_match('/^[a-z0-9-]{2,30}$/', $key)) $key = $model === 'tier' ? ($max === null ? 't-illimite' : 't-' . $max) : saas_slug($name);
    return [
        'key' => $key, 'model' => $model, 'name' => $name, 'max_items' => $max,
        'monthly' => $cents($o['monthly'] ?? null), 'yearly' => $cents($o['yearly'] ?? null),
        'featured' => !empty($o['featured']),
        'features' => array_slice(array_values(array_filter(array_map(static fn ($f) => mb_substr(trim((string) $f), 0, 120), (array) ($o['features'] ?? [])))), 0, 10),
    ];
}

/** Grille normalisée : discount_pct, yearly (facturation annuelle proposée), plans[], tiers[] (tranches triées, illimitée en dernier). */
function saas_grid_normalize(array $g): array
{
    $plans = [];
    foreach ((array) ($g['plans'] ?? []) as $o) if (is_array($o) && ($n = saas_offer_normalize($o, 'plan'))) $plans[] = $n;
    $tiers = [];
    foreach ((array) ($g['tiers'] ?? []) as $o) if (is_array($o) && ($n = saas_offer_normalize($o, 'tier'))) $tiers[] = $n;
    usort($tiers, static fn ($a, $b) => ($a['max_items'] ?? PHP_INT_MAX) <=> ($b['max_items'] ?? PHP_INT_MAX));
    // Clés uniques sur toute la grille.
    $seen = [];
    foreach ([&$plans, &$tiers] as &$list) foreach ($list as &$o) {
        $base = $o['key']; $i = 2;
        while (isset($seen[$o['key']])) $o['key'] = $base . '-' . $i++;
        $seen[$o['key']] = true;
    }
    unset($list, $o);
    return [
        'discount_pct' => max(0, min(90, (int) ($g['discount_pct'] ?? 0))),
        'yearly' => !empty($g['yearly']),
        'plans' => $plans, 'tiers' => $tiers,
    ];
}

/** Grille issue des anciennes formules (saas.json), tant que le portail n'a pas enregistré de tarifs. */
function saas_pricing_from_legacy(): array
{
    $file = saas_data_dir() . '/saas.json';
    $saved = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    $plans = [];
    foreach ((array) ($saved['plans'] ?? saas_default_plans()) as $p) {
        if (!is_array($p)) continue;
        $plans[] = ['key' => $p['key'] ?? '', 'name' => $p['name'] ?? '', 'monthly' => gallery_price_cents((string) ($p['price'] ?? '')),
            'featured' => !empty($p['featured']), 'features' => $p['features'] ?? []];
    }
    return ['discount_pct' => 0, 'yearly' => false, 'plans' => $plans ?: array_map(static fn ($p) => ['key' => $p['key'], 'name' => $p['name'], 'monthly' => gallery_price_cents($p['price']), 'featured' => $p['featured'], 'features' => $p['features']], saas_default_plans()), 'tiers' => []];
}

/** Tous les tarifs : ['default' => grille, 'galleries' => [identifiant => grille propre]]. */
function saas_pricing(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $file = saas_pricing_file();
    $saved = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($saved)) return $cache = ['default' => saas_grid_normalize(saas_pricing_from_legacy()), 'galleries' => [], 'saved' => false];
    $galleries = [];
    foreach ((array) ($saved['galleries'] ?? []) as $slug => $g) {
        if (is_array($g) && preg_match('/^[a-z0-9][a-z0-9-]*$/', (string) $slug)) $galleries[(string) $slug] = saas_grid_normalize($g);
    }
    return $cache = ['default' => saas_grid_normalize((array) ($saved['default'] ?? [])), 'galleries' => $galleries, 'saved' => true];
}

function saas_pricing_save(array $default, array $galleries): bool
{
    $dir = dirname(saas_pricing_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    $data = ['default' => saas_grid_normalize($default), 'galleries' => array_map('saas_grid_normalize', $galleries)];
    $ok = @file_put_contents(saas_pricing_file(), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    @unlink(saas_data_dir() . '/saas/cache.json');
    return $ok;
}

/** Formules par défaut, dans l'ancienne forme (clé, nom, prix en texte, période, mise en avant, avantages) : lue par l'accueil et les pages qui n'ont pas changé. */
function saas_pricing_legacy_plans(): array
{
    $out = [];
    foreach (saas_pricing()['default']['plans'] as $o) {
        $out[] = ['key' => $o['key'], 'name' => $o['name'], 'price' => $o['monthly'] === null ? 'Sur devis' : saas_money($o['monthly']), 'period' => $o['monthly'] === null ? '' : '/mois',
            'featured' => $o['featured'], 'features' => $o['features']];
    }
    return $out;
}

/** Grille applicable à une galerie ('' ou galerie sans grille propre : la grille par défaut). 'own' : la galerie a ses propres tarifs. */
function saas_grid(string $gallery = ''): array
{
    $p = saas_pricing();
    $g = $gallery !== '' && isset($p['galleries'][$gallery]) ? $p['galleries'][$gallery] + ['own' => true] : $p['default'] + ['own' => false];
    $g['gallery'] = $gallery;
    return $g;
}

/** Toutes les offres d'une grille (formules puis tranches) avec leur prix annuel effectif. */
function saas_grid_offers(array $grid): array
{
    $out = [];
    foreach (array_merge($grid['plans'], $grid['tiers']) as $o) {
        $o['yearly_eff'] = !$grid['yearly'] || $o['monthly'] === null ? null : ($o['yearly'] ?? (int) round($o['monthly'] * 12 * (100 - $grid['discount_pct']) / 100));
        $out[] = $o;
    }
    return $out;
}

function saas_offer(string $gallery, string $key): ?array
{
    foreach (saas_grid_offers(saas_grid($gallery)) as $o) if ($o['key'] === $key) return $o;
    return null;
}

/** Prix d'une offre pour une facturation (month|year) en centimes ; null = sur devis ou facturation indisponible. */
function saas_offer_cents(array $offer, string $billing): ?int
{
    return $billing === 'year' ? ($offer['yearly_eff'] ?? null) : $offer['monthly'];
}

/** « 19,90 € / mois », « 199 € / an », « Gratuit », « Sur devis ». */
function saas_price_text(?int $cents, string $billing = 'month'): string
{
    if ($cents === null) return 'Sur devis';
    if ($cents === 0) return 'Gratuit';
    return saas_money($cents) . ' / ' . SAAS_BILLING[$billing][0];
}

/** Mention « 2 mois offerts » / « −17 % » d'une offre facturée à l'année. */
function saas_offer_saving(array $offer): string
{
    $m = $offer['monthly'];
    $y = $offer['yearly_eff'] ?? null;
    if (!$m || !$y || $y >= $m * 12) return '';
    $pct = (int) round((1 - $y / ($m * 12)) * 100);
    $months = ($m * 12 - $y) / $m;
    return abs($months - round($months)) < 0.08 && round($months) >= 1 ? round($months) . ' mois offert' . (round($months) > 1 ? 's' : '') : '−' . $pct . ' %';
}

/** Libellé de limite d'une offre : « Jusqu'à 50 articles », « Articles illimités », ou '' (formule sans limite déclarée). */
function saas_offer_limit_label(array $offer): string
{
    if ($offer['max_items'] === null) return $offer['model'] === 'tier' ? 'Articles illimités' : '';
    return "Jusqu'à " . number_format($offer['max_items'], 0, ',', ' ') . ' articles';
}

/** Offre figée d'une demande : request['offer'], ou (anciennes demandes) reconstituée d'après la formule d'alors. Clés : key, name, model, billing, cents, max_items, gallery. */
function saas_req_offer(array $req): array
{
    if (!empty($req['offer']) && is_array($req['offer'])) return $req['offer'] + ['billing' => 'month', 'max_items' => null, 'gallery' => '', 'model' => 'plan'];
    $plan = array_column(saas_config()['plans'], null, 'key')[$req['plan'] ?? ''] ?? null;
    return ['key' => (string) ($req['plan'] ?? ''), 'name' => $plan['name'] ?? (string) ($req['plan'] ?? '—'), 'model' => 'plan', 'billing' => 'month',
        'cents' => $plan ? saas_plan_cents($plan) : 0, 'max_items' => null, 'gallery' => (string) ($req['gallery'] ?? '')];
}

/** Équivalent mensuel d'une offre figée (pour le loyer mensuel estimé). */
function saas_offer_monthly_equivalent(array $o): int
{
    return (int) round(($o['cents'] ?? 0) / (($o['billing'] ?? 'month') === 'year' ? 12 : 1));
}

/**
 * Choisit l'offre d'un client : la fige pour la demande. $gallery : galerie choisie ('' = aucune), $key : clé de l'offre, $billing : month|year.
 * Retourne l'offre figée, ou null si la clé ne fait pas partie de la grille.
 */
function saas_offer_snapshot(string $gallery, string $key, string $billing): ?array
{
    $offer = saas_offer($gallery, $key);
    if (!$offer) return null;
    if ($billing !== 'year' || saas_offer_cents($offer, 'year') === null) $billing = 'month';
    return ['key' => $offer['key'], 'name' => $offer['name'], 'model' => $offer['model'], 'billing' => $billing, 'cents' => saas_offer_cents($offer, $billing),
        'max_items' => $offer['max_items'], 'gallery' => $gallery];
}

/** Galeries publiées (identifiant => nom) où l'on peut s'inscrire. */
function saas_signup_galleries(): array
{
    $out = [];
    foreach (gallery_list() as $slug => $g) if ($g['published']) $out[$slug] = $g['name'];
    return $out;
}

/** « À partir de » d'une grille : le plus petit prix mensuel payant (ou « Gratuit » si une offre l'est). */
function saas_grid_from_text(array $grid): string
{
    $prices = array_filter(array_map(static fn ($o) => $o['monthly'], saas_grid_offers($grid)), static fn ($c) => $c !== null);
    if (!$prices) return 'Sur devis';
    $min = min($prices);
    return $min === 0 ? 'Gratuit' : 'dès ' . saas_money($min) . ' / mois';
}

/**
 * Présentation publique d'une grille (accueil, aperçu du portail) : bascule mensuel / annuel et, s'il y a des formules ET des tranches, bascule entre les deux.
 * Tout se règle en CSS (cases cochées) : aucun script. $id : préfixe des identifiants (plusieurs grilles sur une même page).
 * $ctaUrl(offre, facturation) : adresse du bouton de chaque offre.
 */
function saas_pricing_html(array $grid, string $id, callable $ctaUrl): string
{
    $e = 'saas_e';
    $offers = saas_grid_offers($grid);
    if (!$offers) return '<p class="muted">Aucun tarif pour le moment.</p>';
    $both = $grid['plans'] && $grid['tiers'];
    $html = '<div class="pricing" data-grid="' . $e($id) . '">';
    if ($grid['yearly']) {
        $html .= '<div class="pricing-toggle" role="radiogroup" aria-label="Facturation"><input type="radio" name="' . $e($id) . '-b" id="' . $e($id) . '-bm" checked><label for="' . $e($id) . '-bm">Mensuel</label>'
            . '<input type="radio" name="' . $e($id) . '-b" id="' . $e($id) . '-by"><label for="' . $e($id) . '-by">Annuel' . ($grid['discount_pct'] > 0 ? ' <em>−' . (int) $grid['discount_pct'] . ' %</em>' : '') . '</label></div>';
    }
    if ($both) {
        $html .= '<div class="pricing-toggle" role="radiogroup" aria-label="Type de tarif"><input type="radio" name="' . $e($id) . '-m" id="' . $e($id) . '-mp" checked><label for="' . $e($id) . '-mp">Formules</label>'
            . '<input type="radio" name="' . $e($id) . '-m" id="' . $e($id) . '-mt"><label for="' . $e($id) . '-mt">Selon le nombre d\'articles</label></div>';
    }
    $html .= '<div class="plans">';
    foreach ($offers as $o) {
        $monthly = saas_price_text($o['monthly'], 'month');
        $yearly = $o['yearly_eff'] !== null ? saas_price_text($o['yearly_eff'], 'year') : null;
        $saving = saas_offer_saving($o);
        $limit = saas_offer_limit_label($o);
        $html .= '<article class="plan plan-' . $e($o['model']) . ($o['featured'] ? ' is-featured' : '') . '">'
            . ($o['featured'] ? '<span class="tag">La plus choisie</span>' : '')
            . '<h3>' . $e($o['name']) . '</h3>'
            . ($limit !== '' && $o['model'] === 'plan' ? '<p class="plan-limit">' . $e($limit) . '</p>' : '')
            . '<p class="price pm">' . ($o['monthly'] === null ? 'Sur devis' : ($o['monthly'] === 0 ? 'Gratuit' : $e(saas_money($o['monthly'])) . ' <small>/ mois</small>')) . '</p>'
            . ($yearly !== null ? '<p class="price py">' . ($o['yearly_eff'] === 0 ? 'Gratuit' : $e(saas_money($o['yearly_eff'])) . ' <small>/ an</small>') . ($saving !== '' ? ' <span class="save">' . $e($saving) . '</span>' : '') . '</p>' : '')
            . ($o['features'] ? '<ul>' . implode('', array_map(static fn ($f) => '<li>' . saas_e($f) . '</li>', $o['features'])) . '</ul>' : '')
            . '<a class="btn pm' . ($o['featured'] ? '' : ' ghost') . '" href="' . $e($ctaUrl($o, 'month')) . '">Choisir</a>'
            . ($yearly !== null ? '<a class="btn py' . ($o['featured'] ? '' : ' ghost') . '" href="' . $e($ctaUrl($o, 'year')) . '">Choisir (annuel)</a>' : '')
            . '</article>';
    }
    return $html . '</div></div>';
}

/**
 * Sélecteur d'offre de l'inscription (dans le formulaire) : bascule mensuel / annuel, bascule formules / tranches s'il y a les deux, puis une case par offre.
 * Les bascules sont en CSS (cases cochées) ; un petit script coche la première offre du type choisi, pour ne jamais envoyer une offre masquée.
 */
function saas_offer_picker_html(array $grid, string $selected, string $billing): string
{
    $e = 'saas_e';
    $offers = saas_grid_offers($grid);
    $both = $grid['plans'] && $grid['tiers'];
    $selOffer = null;
    foreach ($offers as $o) if ($o['key'] === $selected) $selOffer = $o;
    $selModel = $selOffer['model'] ?? ($grid['plans'] ? 'plan' : 'tier');
    $html = '<div class="picker">';
    if ($grid['yearly']) {
        $html .= '<div class="pricing-toggle" role="radiogroup" aria-label="Facturation"><input type="radio" name="facturation" value="month" id="pk-bm"' . ($billing !== 'year' ? ' checked' : '') . '><label for="pk-bm">Mensuel</label>'
            . '<input type="radio" name="facturation" value="year" id="pk-by"' . ($billing === 'year' ? ' checked' : '') . '><label for="pk-by">Annuel' . ($grid['discount_pct'] > 0 ? ' <em>−' . (int) $grid['discount_pct'] . ' %</em>' : '') . '</label></div>';
    }
    if ($both) {
        $html .= '<div class="pricing-toggle" role="radiogroup" aria-label="Type de tarif"><input type="radio" name="modele" value="plan" id="pk-mp"' . ($selModel === 'plan' ? ' checked' : '') . '><label for="pk-mp">Formules</label>'
            . '<input type="radio" name="modele" value="tier" id="pk-mt"' . ($selModel === 'tier' ? ' checked' : '') . '><label for="pk-mt">Selon le nombre d\'articles</label></div>';
    }
    $html .= '<div class="plan-pick tall">';
    foreach ($offers as $o) {
        $limit = saas_offer_limit_label($o);
        $saving = saas_offer_saving($o);
        $html .= '<label class="offer offer-' . $e($o['model']) . '"><input type="radio" name="plan" value="' . $e($o['key']) . '"' . ($o['key'] === $selected ? ' checked' : '') . '>'
            . '<strong>' . $e($o['name']) . '</strong>'
            . '<span class="op pm">' . $e(saas_price_text($o['monthly'], 'month')) . '</span>'
            . ($o['yearly_eff'] !== null ? '<span class="op py">' . $e(saas_price_text($o['yearly_eff'], 'year')) . ($saving !== '' ? ' <b class="save">' . $e($saving) . '</b>' : '') . '</span>' : ($grid['yearly'] ? '<span class="op py">' . $e(saas_price_text($o['monthly'], 'month')) . '</span>' : ''))
            . ($limit !== '' ? '<span class="lim">' . $e($limit) . '</span>' : '');
        foreach (array_slice($o['features'], 0, 3) as $f) $html .= '<span>✓ ' . $e($f) . '</span>';
        $html .= '</label>';
    }
    $html .= '</div>';
    if ($both) {
        $html .= '<script>(function(){var f=document.currentScript.closest("form");if(!f)return;f.querySelectorAll("input[name=modele]").forEach(function(r){r.addEventListener("change",function(){'
            . 'var l=f.querySelector(".offer-"+r.value+" input");if(l)l.checked=true;});});})();</script>';
    }
    return $html . '</div>';
}

/** Libellé court de l'offre d'une demande : « Standard · annuel · galerie x ». */
function saas_offer_badge(array $req): string
{
    $o = saas_req_offer($req);
    return $o['name'] . ($o['billing'] === 'year' ? ' · annuel' : '') . (($o['gallery'] ?? '') !== '' ? ' · galerie ' . $o['gallery'] : '');
}
