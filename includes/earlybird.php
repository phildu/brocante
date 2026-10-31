<?php

// Pionniers (early bird) : tarifs préférentiels par palier de rang pour amener une galerie ou la plateforme
// à son seuil critique de structures. Une campagne par galerie (clé = identifiant de galerie) ou « default ».
//
// Le rang et la remise sont FIGÉS dans la demande au moment du choix de la formule (request['earlybird']) :
// Stripe encaisse le prix remisé, et un changement de palier ne modifie jamais une demande déjà commencée.
// Les places se comptent sur les demandes actives (brouillon et paiement en attente : 24 h, comme l'inscription).
// Réglages : data/saas/earlybird.json (jamais envoyé par le déploiement).

require_once __DIR__ . '/saas.php';

function eb_escape($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function eb_file(): string
{
    return saas_data_dir() . '/saas/earlybird.json';
}

/** Campagne par défaut : trois paliers de rang, désactivée tant que l'exploitant ne l'active pas. */
function eb_default_campaign(): array
{
    return [
        'enabled' => false,
        'show_public' => true,
        'title' => 'Pionniers',
        'pitch' => "Les premières structures à nous rejoindre gardent un tarif préférentiel et un badge pionnier. L'offre s'arrête au seuil critique.",
        'target' => 30,
        'baseline' => 0,
        'ends_at' => '',
        'lock_months' => 12,
        'tiers' => [
            ['up_to' => 10, 'label' => 'Fondateur', 'discount_pct' => 40, 'perk' => "Tarif fondateur, badge et accompagnement au lancement."],
            ['up_to' => 20, 'label' => 'Pionnier', 'discount_pct' => 25, 'perk' => "Badge pionnier et accès prioritaire aux nouveautés."],
            ['up_to' => null, 'label' => 'Early bird', 'discount_pct' => 15, 'perk' => "Tarif early bird jusqu'au seuil critique."],
        ],
    ];
}

/** Campagne assainie : bornes, paliers triés, palier illimité en dernier. */
function eb_campaign_normalize(array $c): array
{
    $default = eb_default_campaign();
    $text = static fn ($v, int $max): string => mb_substr(trim((string) ($v ?? '')), 0, $max);
    $tiers = [];
    foreach ((array) ($c['tiers'] ?? []) as $t) {
        if (!is_array($t)) continue;
        $raw = $t['up_to'] ?? null;
        $up = ($raw === null || $raw === '') ? null : max(1, min(100000, (int) $raw));
        $pct = max(0, min(90, (int) ($t['discount_pct'] ?? 0)));
        $label = $text($t['label'] ?? '', 40);
        if ($label === '' && $pct === 0) continue;
        $tiers[] = [
            'up_to' => $up,
            'label' => $label !== '' ? $label : 'Pionnier',
            'discount_pct' => $pct,
            'perk' => $text($t['perk'] ?? '', 160),
        ];
    }
    usort($tiers, static fn (array $a, array $b): int => ($a['up_to'] ?? PHP_INT_MAX) <=> ($b['up_to'] ?? PHP_INT_MAX));
    // Un seul palier sans limite, et toujours en dernier.
    $unlimited = null;
    $capped = [];
    foreach ($tiers as $t) {
        if ($t['up_to'] === null) { if ($unlimited === null) $unlimited = $t; }
        else $capped[] = $t;
    }
    $tiers = $unlimited !== null ? array_merge($capped, [$unlimited]) : $capped;
    $ends = $text($c['ends_at'] ?? '', 10);
    return [
        'enabled' => !empty($c['enabled']),
        'show_public' => !array_key_exists('show_public', $c) || !empty($c['show_public']),
        'title' => $text($c['title'] ?? '', 40) ?: $default['title'],
        'pitch' => $text($c['pitch'] ?? '', 600) ?: $default['pitch'],
        'target' => max(1, min(100000, (int) ($c['target'] ?? $default['target']))),
        'baseline' => max(0, min(100000, (int) ($c['baseline'] ?? 0))),
        'ends_at' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) ? $ends : '',
        'lock_months' => max(0, min(120, (int) ($c['lock_months'] ?? $default['lock_months']))),
        'tiers' => $tiers,
    ];
}

/** Campagnes enregistrées, par clé ('default' ou identifiant de galerie). */
function eb_all(): array
{
    $saved = is_file(eb_file()) ? json_decode((string) file_get_contents(eb_file()), true) : null;
    $out = [];
    foreach ((array) ($saved['campaigns'] ?? []) as $key => $campaign) {
        if (!is_array($campaign)) continue;
        if ($key !== 'default' && !preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', (string) $key)) continue;
        $out[(string) $key] = eb_campaign_normalize($campaign);
    }
    return $out;
}

/** Campagne applicable à une galerie : la sienne si elle existe, sinon celle par défaut. */
function eb_campaign(string $gallery = ''): array
{
    $all = eb_all();
    $key = $gallery !== '' && isset($all[$gallery]) ? $gallery : 'default';
    $campaign = $all[$key] ?? eb_campaign_normalize(['enabled' => false]);
    $campaign['key'] = $key;
    return $campaign;
}

function eb_campaign_exists(string $key): bool
{
    return isset(eb_all()[$key]);
}

function eb_save(string $key, array $campaign): bool
{
    if ($key !== 'default' && !preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $key)) return false;
    $all = eb_all();
    $all[$key] = eb_campaign_normalize($campaign);
    $dir = dirname(eb_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(eb_file(), json_encode(['campaigns' => $all], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/** Retire la campagne propre d'une galerie : elle reprend la campagne par défaut. */
function eb_delete(string $key): bool
{
    if ($key === 'default') return false;
    $all = eb_all();
    unset($all[$key]);
    $dir = dirname(eb_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(eb_file(), json_encode(['campaigns' => $all], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/** Demandes qui occupent une place pionnière dans cette campagne (hors refusées, annulées et brouillons expirés). */
function eb_seats(string $key): array
{
    if (!function_exists('saas_requests')) return [];
    $out = [];
    foreach (saas_requests() as $r) {
        $lock = (array) ($r['earlybird'] ?? []);
        if ((string) ($lock['campaign'] ?? '') !== $key) continue;
        $status = (string) ($r['status'] ?? '');
        if (in_array($status, ['rejected', 'cancelled'], true)) continue;
        if (in_array($status, ['draft', 'awaiting_payment'], true) && function_exists('saas_request_active') && !saas_request_active($r)) continue;
        $out[] = $r;
    }
    usort($out, static fn (array $a, array $b): int => ((int) ($a['earlybird']['rank'] ?? 0)) <=> ((int) ($b['earlybird']['rank'] ?? 0)));
    return $out;
}

/** Progression calculée sur un nombre de structures déjà présentes (fonction pure, testable). */
function eb_progress_counts(array $campaign, int $enrolled, ?int $now = null): array
{
    $target = max(1, (int) ($campaign['target'] ?? 1));
    $enrolled = max(0, $enrolled);
    $closed = $enrolled >= $target;
    $expired = (string) ($campaign['ends_at'] ?? '') !== '' && strtotime((string) $campaign['ends_at'] . ' 23:59:59') < ($now ?? time());
    return [
        'enrolled' => $enrolled,
        'target' => $target,
        'left' => max(0, $target - $enrolled),
        'pct' => (int) round(min(100, $enrolled * 100 / $target)),
        'closed' => $closed,
        'expired' => $expired,
        'open' => !empty($campaign['enabled']) && !$closed && !$expired,
        'next_rank' => $enrolled + 1,
    ];
}

function eb_progress(array $campaign, ?int $now = null): array
{
    $key = (string) ($campaign['key'] ?? 'default');
    $enrolled = max(0, (int) ($campaign['baseline'] ?? 0)) + count(eb_seats($key));
    return eb_progress_counts($campaign, $enrolled, $now);
}

/** Palier applicable au rang donné (le premier dont « jusqu'à » couvre le rang), ou null au-delà du dernier. */
function eb_tier_at(array $campaign, int $rank): ?array
{
    $rank = max(1, $rank);
    foreach ((array) ($campaign['tiers'] ?? []) as $tier) {
        if ($tier['up_to'] === null || $rank <= (int) $tier['up_to']) return $tier;
    }
    return null;
}

/** Prix remisé, arrondi à la dizaine de centimes. */
function eb_discount_cents(int $cents, int $pct): int
{
    if ($cents <= 0 || $pct <= 0) return $cents;
    $pct = max(0, min(90, $pct));
    $out = (int) round($cents * (100 - $pct) / 100);
    return max(0, (int) (round($out / 10) * 10));
}

/** Offre (avec yearly_eff) au tarif pionnier ; les prix d'origine restent dans list_monthly / list_yearly_eff. */
function eb_apply_to_offer(array $offer, ?array $tier): array
{
    if (!$tier || (int) ($tier['discount_pct'] ?? 0) <= 0) return $offer;
    $pct = max(0, min(90, (int) $tier['discount_pct']));
    $offer['list_monthly'] = $offer['monthly'] ?? null;
    $offer['list_yearly_eff'] = $offer['yearly_eff'] ?? null;
    $offer['eb_discount_pct'] = $pct;
    if (($offer['monthly'] ?? null) !== null) $offer['monthly'] = eb_discount_cents((int) $offer['monthly'], $pct);
    if (($offer['yearly_eff'] ?? null) !== null) {
        $offer['yearly_eff'] = eb_discount_cents((int) $offer['yearly_eff'], $pct);
        $offer['yearly'] = $offer['yearly_eff'];   // un prix annuel saisi prime : saas_grid_offers garde cette valeur
    }
    // Offre figée d'une demande : le montant réellement envoyé à Stripe est « cents ».
    if (array_key_exists('cents', $offer) && $offer['cents'] !== null) {
        $offer['list_cents'] = (int) $offer['cents'];
        $offer['cents'] = eb_discount_cents((int) $offer['cents'], $pct);
    }
    return $offer;
}

/** Copie d'une grille avec les prix remisés (mêmes clés, mêmes limites) : sert à l'affichage du sélecteur. */
function eb_discounted_grid(array $grid, ?array $tier): array
{
    if (!$tier || (int) ($tier['discount_pct'] ?? 0) <= 0) return $grid;
    $plans = [];
    $tiers = [];
    foreach (saas_grid_offers($grid) as $offer) {
        $discounted = eb_apply_to_offer($offer, $tier);
        if (($offer['model'] ?? 'plan') === 'tier') $tiers[] = $discounted;
        else $plans[] = $discounted;
    }
    $grid['plans'] = $plans;
    $grid['tiers'] = $tiers;
    $grid['eb'] = ['discount_pct' => (int) $tier['discount_pct'], 'label' => (string) $tier['label']];
    return $grid;
}

/** Campagne + progression, pour l'inscription et les pages publiques. */
function eb_campaign_for_signup(string $gallery = ''): array
{
    $campaign = eb_campaign($gallery);
    $campaign['progress'] = eb_progress($campaign);
    return $campaign;
}

/** Palier du prochain inscrit, ou null si la campagne est fermée. */
function eb_next_tier(string $gallery = ''): ?array
{
    $campaign = eb_campaign_for_signup($gallery);
    return $campaign['progress']['open'] ? eb_tier_at($campaign, $campaign['progress']['next_rank']) : null;
}

/**
 * Offre figée d'une demande, remise pionnière comprise.
 * $existing : verrou déjà accordé à cette demande (request['earlybird']) : il est conservé tel quel,
 * même si la campagne a changé ou s'est fermée. Retourne ['offer' => …, 'earlybird' => array|null].
 */
function eb_lock_offer(string $gallery, array $offer, ?array $existing = null): array
{
    $campaign = eb_campaign_for_signup($gallery);
    $lock = null;
    if ($existing && (string) ($existing['campaign'] ?? '') === (string) $campaign['key'] && (int) ($existing['discount_pct'] ?? 0) > 0) {
        $lock = $existing;
    } elseif ($campaign['progress']['open']) {
        $tier = eb_tier_at($campaign, $campaign['progress']['next_rank']);
        if ($tier && (int) $tier['discount_pct'] > 0) {
            $lock = [
                'campaign' => (string) $campaign['key'],
                'rank' => (int) $campaign['progress']['next_rank'],
                'tier_label' => (string) $tier['label'],
                'discount_pct' => (int) $tier['discount_pct'],
                'perk' => (string) $tier['perk'],
                'title' => (string) $campaign['title'],
                'lock_months' => (int) $campaign['lock_months'],
                'granted' => time(),
            ];
        }
    }
    if ($lock === null) return ['offer' => $offer, 'earlybird' => null];
    return ['offer' => eb_apply_to_offer($offer, $lock), 'earlybird' => $lock];
}

/** Mention courte d'un verrou pionnier : « Pionnier −25 % · rang 12 ». */
function eb_lock_label(?array $lock): string
{
    if (!$lock || (int) ($lock['discount_pct'] ?? 0) <= 0) return '';
    return (string) ($lock['title'] ?? 'Pionnier') . ' −' . (int) $lock['discount_pct'] . ' %' . (!empty($lock['rank']) ? ' · rang ' . (int) $lock['rank'] : '');
}

/**
 * Bandeau public : progression vers le seuil critique et paliers restants.
 * $options : compact (bool), show_cta (bool, défaut true).
 */
function eb_widget_html(string $gallery = '', array $options = []): string
{
    $campaign = eb_campaign_for_signup($gallery);
    if (!$campaign['enabled'] || !$campaign['show_public']) return '';
    $p = $campaign['progress'];
    $next = $p['open'] ? eb_tier_at($campaign, $p['next_rank']) : null;
    $id = 'eb-' . preg_replace('/[^a-z0-9-]/', '', $campaign['key'] !== '' ? $campaign['key'] : 'default');
    $compact = !empty($options['compact']);
    $showCta = !array_key_exists('show_cta', $options) || !empty($options['show_cta']);
    $signup = '/inscription/' . ($gallery !== '' ? '?galerie=' . rawurlencode($gallery) : '');

    $title = $p['closed']
        ? 'Objectif atteint : ' . $p['enrolled'] . ' structures pionnières'
        : ($p['expired'] ? $campaign['title'] . ' — campagne terminée' : $campaign['title'] . ' — plus que ' . $p['left'] . ' place' . ($p['left'] > 1 ? 's' : '') . ' au tarif préférentiel');

    $html = '<style>'
        . '.eb{--eb-accent:var(--accent,#b5502e);--eb-line:var(--line,#e6e0d7);--eb-surface:var(--surface,#fff);--eb-panel:var(--panel,#fbf8f3);'
        . 'margin:0 auto 22px;max-width:1100px;font-family:inherit;color:var(--ink,#1c1a17)}'
        . '.eb-card{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);gap:22px;border:1px solid var(--eb-line);border-left:6px solid var(--eb-accent);'
        . 'border-radius:14px;background:var(--eb-surface);padding:20px 22px;box-shadow:0 8px 28px rgba(28,26,23,.06)}'
        . '@media(max-width:760px){.eb-card{grid-template-columns:1fr;gap:14px}}'
        . '.eb-eyebrow{margin:0 0 4px;font-size:.72rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--eb-accent)}'
        . '.eb h2{margin:0 0 6px;font-size:clamp(1.15rem,2.4vw,1.55rem);line-height:1.2}'
        . '.eb-pitch{margin:0;color:var(--ink-soft,#6b645b);font-size:.93rem;line-height:1.5}'
        . '.eb-meter{margin-top:14px}.eb-meter p{margin:8px 0 0;font-size:.86rem;color:var(--ink-soft,#6b645b)}'
        . '.eb-bar{height:10px;border-radius:999px;background:var(--eb-panel);overflow:hidden;border:1px solid var(--eb-line)}'
        . '.eb-bar span{display:block;height:100%;background:var(--eb-accent);border-radius:999px}'
        . '.eb-side{background:var(--eb-panel);border:1px solid var(--eb-line);border-radius:12px;padding:14px 16px;display:grid;gap:8px;align-content:start}'
        . '.eb-side h3{margin:0;font-size:.95rem}.eb-side p{margin:0;font-size:.85rem;color:var(--ink-soft,#6b645b)}'
        . '.eb-pill{display:inline-block;background:var(--eb-accent);color:#fff;border-radius:999px;padding:2px 9px;font-size:.76rem;font-weight:700}'
        . '.eb-tiers{display:grid;gap:6px;margin:0;padding:0;list-style:none}'
        . '.eb-tiers li{display:flex;justify-content:space-between;gap:10px;font-size:.82rem;border-top:1px dashed var(--eb-line);padding-top:6px}'
        . '.eb-tiers li:first-child{border-top:0;padding-top:0}.eb-tiers b{white-space:nowrap}'
        . '.eb-tiers .muted{color:var(--ink-soft,#6b645b)}'
        . '.eb-cta{display:inline-flex;align-items:center;justify-content:center;gap:8px;text-decoration:none;background:var(--eb-accent);color:#fff;'
        . 'font-weight:700;border-radius:999px;padding:10px 18px;font-size:.9rem;margin-top:4px}'
        . '.eb-cta:hover{filter:brightness(1.06)}.eb-closed{font-weight:700;color:var(--eb-accent);font-size:.88rem}'
        . '</style>';

    $html .= '<section class="eb" aria-labelledby="' . eb_escape($id) . '-title"><div class="eb-card">';
    $html .= '<div><p class="eb-eyebrow">Tarif pionnier · ' . eb_escape($campaign['title']) . '</p>'
        . '<h2 id="' . eb_escape($id) . '-title">' . eb_escape($title) . '</h2>'
        . '<p class="eb-pitch">' . eb_escape($campaign['pitch']) . '</p>'
        . '<div class="eb-meter"><div class="eb-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . (int) $p['target'] . '" aria-valuenow="' . (int) $p['enrolled'] . '"><span style="width:' . (int) $p['pct'] . '%"></span></div>'
        . '<p><b>' . (int) $p['enrolled'] . '</b> / <b>' . (int) $p['target'] . '</b> structures · '
        . ($p['closed'] ? 'seuil critique atteint' : (int) $p['left'] . ' place' . ($p['left'] > 1 ? 's' : '') . ' restante' . ($p['left'] > 1 ? 's' : ''))
        . ($campaign['ends_at'] !== '' && !$p['expired'] ? ' · jusqu\'au ' . eb_escape(date('d/m/Y', strtotime($campaign['ends_at']))) : '')
        . '</p></div></div>';

    $html .= '<div class="eb-side">';
    if ($p['open'] && $next) {
        $html .= '<h3>Votre place : <span class="eb-pill">' . eb_escape($next['label']) . ' −' . (int) $next['discount_pct'] . ' %</span></h3>'
            . ($next['perk'] !== '' ? '<p>' . eb_escape($next['perk']) . '</p>' : '')
            . '<p>Rang ' . (int) $p['next_rank'] . ' · ' . (int) $campaign['lock_months'] . ' mois à ce tarif' . ($campaign['lock_months'] > 1 ? 's' : '') . '.</p>';
    } elseif ($p['closed']) {
        $html .= '<h3>Seuil critique atteint</h3><p>Les tarifs pionniers sont clos. Les structures déjà inscrites gardent leur tarif.</p>';
    } elseif ($p['expired']) {
        $html .= '<h3>Campagne terminée</h3><p>La date limite est passée : les tarifs habituels s\'appliquent.</p>';
    } else {
        $html .= '<h3>Campagne en préparation</h3><p>Les tarifs habituels s\'appliquent pour le moment.</p>';
    }
    if ($campaign['tiers']) {
        $html .= '<ul class="eb-tiers">';
        $prev = 0;
        foreach ($campaign['tiers'] as $tier) {
            $range = $tier['up_to'] === null
                ? ($prev + 1) . ' et +'
                : ($prev === 0 ? '1 à ' . (int) $tier['up_to'] : ($prev + 1) . ' à ' . (int) $tier['up_to']);
            $isNext = $p['open'] && $next && $tier === $next;
            $html .= '<li><span class="' . ($isNext ? '' : 'muted') . '">' . ($isNext ? '<b>' : '') . eb_escape($tier['label']) . ($isNext ? '</b>' : '') . ' · rang ' . eb_escape($range) . '</span><b>−' . (int) $tier['discount_pct'] . ' %</b></li>';
            $prev = $tier['up_to'] ?? $prev;
        }
        $html .= '</ul>';
    }
    if ($showCta && $p['open']) $html .= '<a class="eb-cta" href="' . eb_escape($signup) . '">Rejoindre les pionniers</a>';
    elseif ($p['closed']) $html .= '<p class="eb-closed">Les pionniers sont au complet : merci !</p>';
    $html .= '</div></div></section>';
    return $html;
}
