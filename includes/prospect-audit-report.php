<?php

// Rendu du rapport d'audit des prospects : Markdown pour la lecture, CSV pour le
// tableur, JSON pour le retraitement et HTML pour le classeur d'appels interactif.
// Les quatre formats partent du même tableau produit par scripts/audit-prospects.php.

function pa_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pa_score_class(mixed $score): string
{
    if ($score === null) return 'unknown';
    $score = (int) $score;
    if ($score >= 80) return 'high';
    if ($score >= 60) return 'mid';
    if ($score >= 40) return 'low';
    return 'bad';
}

function pa_score_bar(mixed $score): string
{
    if ($score === null) return '<span class="score unknown">—</span>';
    $score = max(0, min(100, (int) $score));
    return '<span class="score ' . pa_score_class($score) . '"><span class="bar"><i style="width:' . $score . '%"></i></span><b>' . $score . '</b></span>';
}

function pa_social_label(array $platform): string
{
    $bits = [];
    if (!empty($platform['followers'])) $bits[] = number_format((int) $platform['followers'], 0, ',', ' ') . ' abonnés';
    if (($platform['last_post_days'] ?? null) !== null) {
        $days = (int) $platform['last_post_days'];
        $bits[] = $days === 0 ? 'publication aujourd’hui' : ($days === 1 ? 'publication hier' : 'dernier post il y a ' . $days . ' j');
    }
    if (!empty($platform['rating'])) $bits[] = number_format((float) $platform['rating'], 1, ',', ' ') . '/5';
    if (!empty($platform['reviews'])) $bits[] = number_format((int) $platform['reviews'], 0, ',', ' ') . ' avis';
    return implode(' · ', $bits);
}

/** Rapport Markdown : synthèse, ordre d'appel, puis une fiche compacte par prospect. */
function pa_report_markdown(array $report): string
{
    $s = $report['stats'];
    $out = "# Audit des sites et réseaux sociaux des prospects\n\n";
    $out .= 'Généré le ' . $report['generated_at'] . ' · source : ' . $report['source'] . ' · ' . $s['count'] . " prospect(s)\n\n";
    $out .= "## Synthèse\n\n";
    $out .= '- **' . $s['priorite_1'] . "** prospect(s) à contacter cette semaine (priorité 1)\n";
    $out .= '- **' . $s['no_site'] . "** sans site web, **" . $s['inaccessible'] . "** avec un site injoignable\n";
    $out .= '- **' . $s['not_mobile'] . "** site(s) non adapté(s) au mobile, **" . $s['no_https'] . "** sans HTTPS\n";
    $out .= '- **' . $s['no_social'] . "** sans page sociale détectée, **" . $s['social_unverified'] . "** avec au moins une page sociale non vérifiable sans connexion\n";
    $out .= '- Score web moyen : **' . $s['avg_web'] . "/100** · score réseaux moyen : **" . ($s['avg_social'] === null ? '—' : $s['avg_social'] . '/100') . "** · score global moyen : **" . $s['avg_global'] . "/100**\n\n";
    $out .= "## Ordre de prospection\n\n";
    $out .= "| # | Prospect | Ville | Contact | Site | Web | Réseaux | Global | Priorité | Angle |\n";
    $out .= "|---|---|---|---|---|---|---|---|---|---|\n";
    foreach ($report['prospects'] as $p) {
        $out .= '| ' . $p['rank'] . ' | ' . pa_md($p['name']) . ' | ' . pa_md($p['city']) . ' | ' . pa_md($p['contact']) . ' | '
            . pa_md(pa_site_state_label($p['site'])) . ' | ' . $p['scores']['web'] . ' | '
            . ($p['scores']['social'] === null ? '—' : $p['scores']['social']) . ' | ' . $p['scores']['global'] . ' | '
            . '**' . $p['scores']['priority'] . '** · ' . pa_md($p['scores']['tier_label']) . ' | ' . pa_md($p['scores']['angle']) . " |\n";
    }
    $out .= "\n## Fiches\n\n";
    foreach ($report['prospects'] as $p) {
        $out .= '### ' . $p['rank'] . '. ' . $p['name'] . ' — priorité ' . $p['scores']['priority'] . "/100\n\n";
        $out .= '- **Ville** : ' . ($p['city'] !== '' ? $p['city'] : '—') . ' · **Contact** : ' . ($p['contact'] !== '' ? $p['contact'] : '—')
            . ' · **Téléphone** : ' . ($p['phone'] !== '' ? $p['phone'] : '—') . ' · **E-mail** : ' . ($p['email'] !== '' ? $p['email'] : '—') . "\n";
        $out .= '- **Site** : ' . ($p['website'] !== '' ? $p['website'] : 'aucun') . ' · ' . pa_site_state_label($p['site']) . "\n";
        $out .= '- **Scores** : web ' . $p['scores']['web'] . '/100 · réseaux ' . ($p['scores']['social'] === null ? 'non renseignés' : $p['scores']['social'] . '/100') . ' · global ' . $p['scores']['global'] . "/100\n";
        $socialBits = [];
        foreach ((array) ($p['social']['platforms'] ?? []) as $platform) {
            if (empty($platform['present'])) continue;
            $socialBits[] = $platform['label'] . (($label = pa_social_label($platform)) !== '' ? ' (' . $label . ')' : '') . (empty($platform['blocked']) ? '' : ' — non vérifiée');
        }
        $out .= '- **Réseaux** : ' . ($socialBits ? implode(' · ', $socialBits) : (($p['social']['checked'] ?? false) ? 'aucun trouvé' : 'non renseignés')) . "\n";
        $out .= '- **Angle** : ' . $p['scores']['angle'] . "\n";
        $critical = array_slice((array) $p['scores']['gaps'], 0, 3);
        if ($critical) {
            $out .= '- **Points à vérifier** : ' . implode(' ; ', array_map(static fn (array $g): string => $g['label'] . ' (' . $g['detail'] . ')', $critical)) . "\n";
        }
        $out .= "\n";
    }
    $out .= "## Méthode et limites\n\n";
    $out .= "Le score web (60 %) additionne disponibilité et sécurité, adaptation mobile, performance, contenu et référencement, fraîcheur, conversion et accessibilité de base. ";
    $out .= "Le score réseaux sociaux (40 %) additionne couverture, complétude des profils, audience et activité. La fiche Google est comptée comme un canal local. ";
    $out .= "La priorité = (72 % du besoin + 28 % de la valeur du commerce) × joignabilité : un commerce joignable avec un site faible passe avant un commerce déjà bien équipé. ";
    $out .= "Facebook, Instagram et TikTok renvoient souvent un mur de connexion : ces pages sont marquées non vérifiées et les abonnés ou la date du dernier post peuvent être saisis à la main dans le CSV d'entrée. ";
    $out .= "Les mesures de performance ne portent que sur le HTML de la page d'accueil (pas sur les images et scripts externes).\n";
    return $out;
}

function pa_md(string $value): string
{
    return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $value);
}

function pa_site_state_label(array $site): string
{
    return match ((string) ($site['state'] ?? 'absent')) {
        'ok' => 'en ligne',
        'absent' => 'aucun site',
        'inaccessible' => 'injoignable',
        'erreur' => 'page en erreur',
        'non_html' => 'contenu non HTML',
        default => (string) ($site['state'] ?? '—'),
    };
}

/** Rapport CSV (point-virgule, BOM) prêt pour Excel / Numbers. */
function pa_report_csv(array $report): string
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['Rang', 'Prospect', 'Contact', 'Téléphone', 'E-mail', 'Ville', 'Zone', 'Site web', 'État du site',
        'Score web', 'Score réseaux', 'Score global', 'Priorité', 'Palier', 'Confiance réseaux', 'Abonnés connus',
        'Facebook', 'Instagram', 'TikTok', 'YouTube', 'Fiche Google', 'Note Google', 'Avis Google',
        'HTTPS', 'Mobile', 'CMS', 'Dernière année', 'Angle', 'Manques principaux'], ';', '"', '');
    foreach ($report['prospects'] as $p) {
        $platforms = (array) ($p['social']['platforms'] ?? []);
        $google = $platforms['google'] ?? [];
        $gaps = array_map(static fn (array $g): string => $g['label'], array_slice((array) $p['scores']['gaps'], 0, 4));
        fputcsv($handle, [
            $p['rank'], $p['name'], $p['contact'], $p['phone'], $p['email'], $p['city'], $p['zone'], $p['website'],
            pa_site_state_label($p['site']), $p['scores']['web'], $p['scores']['social'] ?? '', $p['scores']['global'],
            $p['scores']['priority'], $p['scores']['tier_label'], $p['scores']['social_confidence'] ?? '',
            $p['social']['followers_total'] ?? '',
            $platforms['facebook']['url'] ?? '', $platforms['instagram']['url'] ?? '', $platforms['tiktok']['url'] ?? '',
            $platforms['youtube']['url'] ?? '', $google['url'] ?? '', $google['rating'] ?? '', $google['reviews'] ?? '',
            empty($p['site']['fetch']) ? '' : (str_starts_with(strtolower((string) $p['site']['fetch']['final_url']), 'https://') ? 'oui' : 'non'),
            empty($p['site']['metrics']['viewport']) ? '' : (str_contains(strtolower((string) $p['site']['metrics']['viewport']), 'width=device-width') ? 'oui' : 'non'),
            $p['site']['metrics']['cms'] ?? '', (int) ($p['site']['metrics']['copyright_year'] ?? 0) ?: '',
            $p['scores']['angle'], implode(' · ', $gaps),
        ], ';', '"', '');
    }
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

function pa_report_json(array $report): string
{
    return (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Tableau de bord HTML autonome : recherche, filtre par palier, tri, fiches dépliables. */
function pa_report_html(array $report): string
{
    $s = $report['stats'];
    $top = array_slice($report['prospects'], 0, 5);
    $plan = '';
    foreach ($top as $p) {
        $plan .= '<li><span class="plan-rank">' . $p['rank'] . '</span><div><b>' . pa_h($p['name']) . '</b>'
            . '<span>' . pa_h($p['city']) . ($p['city'] !== '' && $p['contact'] !== '' ? ' · ' : '') . pa_h($p['contact']) . '</span>'
            . '<p>' . pa_h($p['scores']['angle']) . '</p></div><span class="prio ' . pa_h($p['scores']['tier']) . '">' . (int) $p['scores']['priority'] . '</span></li>';
    }
    $rows = '';
    foreach ($report['prospects'] as $p) {
        $scores = $p['scores'];
        $detailId = 'detail-' . (int) $p['rank'];
        $rows .= '<tr class="prospect" data-tier="' . pa_h($scores['tier']) . '" data-name="' . pa_h(mb_strtolower($p['name'] . ' ' . $p['city'] . ' ' . $scores['angle'])) . '"'
            . ' data-priority="' . (int) $scores['priority'] . '" data-web="' . (int) $scores['web'] . '" data-social="' . ($scores['social'] === null ? -1 : (int) $scores['social']) . '"'
            . ' aria-expanded="false" aria-controls="' . $detailId . '" tabindex="0">'
            . '<td class="rank">' . (int) $p['rank'] . '</td>'
            . '<td class="who"><b>' . pa_h($p['name']) . '</b><small>' . pa_h($p['city']) . ($p['zone'] !== '' ? ' · ' . pa_h($p['zone']) : '') . '</small></td>'
            . '<td class="contact"><span>' . pa_h($p['phone'] !== '' ? $p['phone'] : $p['email']) . '</span><small>' . pa_h($p['contact']) . '</small></td>'
            . '<td class="site">' . pa_h(pa_site_state_label($p['site'])) . ($p['website'] !== '' ? '<small>' . pa_h(preg_replace('~^https?://(www\.)?~i', '', rtrim($p['website'], '/'))) . '</small>' : '') . '</td>'
            . '<td>' . pa_score_bar($scores['web']) . '</td>'
            . '<td>' . pa_score_bar($scores['social']) . '</td>'
            . '<td>' . pa_score_bar($scores['global']) . '</td>'
            . '<td><span class="prio ' . pa_h($scores['tier']) . '">' . (int) $scores['priority'] . '</span></td>'
            . '<td class="angle">' . pa_h($scores['angle']) . '</td>'
            . '<td class="chevron" aria-hidden="true">›</td></tr>';

        $criteria = '';
        foreach ((array) ($p['site']['criteria'] ?? []) as $criterion) {
            $width = $criterion['max'] > 0 ? round($criterion['points'] / $criterion['max'] * 100) : 0;
            $criteria .= '<div class="crit"><div class="crit-head"><span>' . pa_h($criterion['label']) . '</span><b>' . (int) $criterion['points'] . '/' . (int) $criterion['max'] . '</b></div>'
                . '<span class="bar"><i style="width:' . $width . '%"></i></span>'
                . (!empty($criterion['notes']) ? '<small>' . pa_h(implode(' · ', (array) $criterion['notes'])) . '</small>' : '') . '</div>';
        }
        $socials = '';
        foreach ((array) $p['social']['platforms'] as $platform) {
            if (empty($platform['present'])) continue;
            $label = pa_social_label($platform);
            $socials .= '<div class="platform ' . (empty($platform['blocked']) ? '' : 'blocked') . '">'
                . '<div class="platform-head"><b>' . pa_h($platform['label']) . '</b>' . pa_score_bar($platform['score'] ?? null) . '</div>'
                . ($platform['url'] !== '' ? '<a href="' . pa_h($platform['url']) . '" target="_blank" rel="noopener noreferrer">' . pa_h($platform['url']) . '</a>' : '')
                . '<small>' . pa_h($label !== '' ? $label : (empty($platform['blocked']) ? 'Page non lisible' : 'Connexion requise')) . '</small>'
                . '</div>';
        }
        if ($socials === '') {
            $socials = '<p class="muted">' . (($p['social']['checked'] ?? false) ? 'Aucune page sociale trouvée.' : 'Présence sociale non renseignée : à vérifier à la main.') . '</p>';
        }
        $gaps = '';
        foreach (array_slice((array) $scores['gaps'], 0, 6) as $gap) {
            $gaps .= '<li><b>' . pa_h($gap['label']) . '</b><span>' . pa_h($gap['detail']) . '</span></li>';
        }
        $rows .= '<tr class="detail" id="' . $detailId . '" hidden><td colspan="10"><div class="detail-grid">'
            . '<div><h3>Site web</h3>' . ($p['website'] !== '' ? '<p class="muted">' . pa_h($p['website']) . '</p>' : '') . $criteria . '</div>'
            . '<div><h3>Réseaux sociaux</h3>' . $socials . '</div>'
            . '<div><h3>Angle de prospection</h3><p class="angle-full">' . pa_h($scores['angle']) . '</p>'
            . '<h3>Manques principaux</h3><ul class="gaps">' . $gaps . '</ul>'
            . '<h3>Contact</h3><p class="muted">' . pa_h(trim(($p['contact'] !== '' ? $p['contact'] : '') . ($p['phone'] !== '' ? ' · ' . $p['phone'] : '') . ($p['email'] !== '' ? ' · ' . $p['email'] : ''), ' ·')) . '</p>'
            . ((string) ($p['notes'] ?? '') !== '' ? '<p class="notes">' . nl2br(pa_h($p['notes'])) . '</p>' : '') . '</div>'
            . '</div></td></tr>';
    }

    $html = <<<'HTML'
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit des prospects · sites et réseaux sociaux</title>
<style>
:root{--bg:#f3efe7;--paper:#fffdf9;--ink:#221d17;--muted:#746b5f;--line:#e4dbcd;--accent:#a94f2c;--olive:#5b6b3f;--gold:#b7872f;--good:#41653f;--warn:#b06a20;--bad:#a33b32;--shadow:0 18px 50px rgba(48,36,20,.09)}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.page{max-width:1220px;margin:0 auto;padding:36px 26px 70px}
header.top{display:flex;gap:24px;align-items:flex-end;justify-content:space-between;border-bottom:2px solid var(--ink);padding-bottom:22px;margin-bottom:26px}
.eyebrow{text-transform:uppercase;letter-spacing:.18em;font-size:11px;font-weight:700;color:var(--accent);margin:0 0 8px}
h1{font:600 clamp(30px,4vw,48px)/1.08 Georgia,"Iowan Old Style",serif;margin:0;letter-spacing:-.02em}
.sub{color:var(--muted);margin:9px 0 0;max-width:720px}
.btn{border:1px solid var(--ink);background:var(--ink);color:#fff;padding:10px 16px;border-radius:999px;font-weight:600;cursor:pointer;white-space:nowrap}
.btn:hover{background:var(--accent);border-color:var(--accent)}
.cards{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin:0 0 22px}
.card{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:15px 16px;box-shadow:0 1px 0 rgba(255,255,255,.7) inset}
.card span{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);font-weight:700}
.card b{display:block;font:600 30px/1.1 Georgia,serif;margin-top:8px}
.card small{color:var(--muted);font-size:12px}
.panel{background:var(--paper);border:1px solid var(--line);border-radius:20px;box-shadow:var(--shadow);margin:0 0 22px;overflow:hidden}
.panel-head{display:flex;gap:12px;justify-content:space-between;align-items:flex-start;padding:20px 22px 0}
.panel-head h2{font:600 24px/1.15 Georgia,serif;margin:0}
.panel-head p{margin:5px 0 0;color:var(--muted);font-size:13px}
.plan{list-style:none;margin:0;padding:12px 22px 22px;display:grid;gap:8px}
.plan li{display:grid;grid-template-columns:30px 1fr auto;gap:12px;align-items:start;padding:12px 0;border-top:1px solid var(--line)}
.plan li:first-child{border-top:0}
.plan-rank{font:600 20px/1 Georgia,serif;color:var(--accent)}
.plan b{display:block}
.plan span{font-size:12px;color:var(--muted)}
.plan p{margin:4px 0 0;font-size:13px}
.prio{display:inline-flex;min-width:40px;justify-content:center;align-items:center;border-radius:999px;padding:5px 10px;font-weight:800;font-size:13px;background:#eee7da;color:var(--ink)}
.prio.priorite_1{background:#f4d9cd;color:#7c2e12}
.prio.priorite_2{background:#f1e2bd;color:#77520d}
.prio.priorite_3{background:#e2e6d5;color:#45512d}
.prio.deja_equipe{background:#e8e4dd;color:#6b645a}
.controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center;padding:16px 22px;border-bottom:1px solid var(--line)}
.controls input,.controls select{border:1px solid var(--line);background:#fff;border-radius:10px;padding:10px 12px;font:inherit;color:inherit;min-height:42px}
.controls input{flex:1 1 260px}
.controls label{font-size:12px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.08em}
table{width:100%;border-collapse:collapse}
thead th{text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--muted);padding:12px 10px;border-bottom:1px solid var(--line);white-space:nowrap}
tbody td{padding:13px 10px;border-bottom:1px solid var(--line);vertical-align:top}
tr.prospect{cursor:pointer}
tr.prospect:hover{background:#faf6ee}
tr.prospect.open{background:#fbf4e8}
td.rank{font:600 18px/1 Georgia,serif;color:var(--accent);width:38px}
td.who b,td.site b{display:block}
td small{display:block;color:var(--muted);font-size:11.5px;margin-top:2px;overflow-wrap:anywhere}
td.site small{max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;overflow-wrap:normal}
td.contact span{white-space:nowrap}
td.angle{max-width:310px;font-size:12.5px;color:#4d463d}
.chevron{font-size:22px;color:var(--muted);text-align:right;transition:transform .2s}
tr.prospect.open .chevron{transform:rotate(90deg)}
.score{display:inline-flex;align-items:center;gap:8px;min-width:86px}
.score .bar,.crit .bar{display:block;flex:1;height:6px;background:#eae3d7;border-radius:999px;overflow:hidden}
.score .bar i,.crit .bar i{display:block;height:100%;background:var(--accent);border-radius:999px}
.score b{font-size:12px;min-width:22px;text-align:right}
.score.high .bar i{background:var(--good)}.score.mid .bar i{background:var(--olive)}.score.low .bar i{background:var(--gold)}.score.bad .bar i{background:var(--bad)}
.score.unknown{color:var(--muted);min-width:auto}
tr.detail td{background:#fbf7ef;padding:0;border-bottom:1px solid var(--line)}
.detail-grid{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:26px;padding:24px 26px 28px}
.detail-grid h3{font:600 14px/1.2 Georgia,serif;text-transform:uppercase;letter-spacing:.08em;margin:0 0 12px;color:var(--accent)}
.detail-grid h3:not(:first-child){margin-top:20px}
.crit{margin:0 0 14px}
.crit-head{display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin-bottom:5px}
.crit-head b{color:var(--muted)}
.crit small{display:block;color:var(--muted);font-size:11.5px;margin-top:5px}
.platform{border:1px solid var(--line);border-radius:12px;padding:11px 12px;margin:0 0 9px;background:#fff}
.platform.blocked{border-style:dashed}
.platform-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:4px}
.platform a{font-size:11px;color:var(--accent);overflow-wrap:anywhere}
.platform small{display:block;color:var(--muted);font-size:11.5px;margin-top:4px}
.angle-full{font-size:13.5px;margin:0;padding:12px;background:#fff;border-left:3px solid var(--accent);border-radius:0 8px 8px 0}
ul.gaps{list-style:none;padding:0;margin:0;display:grid;gap:7px}
ul.gaps li{display:grid;gap:2px;font-size:12px}
ul.gaps span{color:var(--muted)}
.notes{white-space:pre-wrap;font-size:12px;color:var(--muted);border-top:1px solid var(--line);padding-top:10px}
.muted{color:var(--muted);font-size:12px}
.method{display:grid;grid-template-columns:1.4fr 1fr;gap:22px;padding:20px 22px 24px;font-size:12.5px;color:#4d463d}
.method p{margin:0 0 8px}
.method b{color:var(--ink)}
footer{margin-top:26px;color:var(--muted);font-size:11.5px;text-align:center}
@media(max-width:980px){.cards{grid-template-columns:repeat(3,1fr)}.detail-grid{grid-template-columns:1fr 1fr}td.angle{display:none}thead th:nth-last-child(2){display:none}}
@media(max-width:680px){.page{padding:22px 12px 50px}.cards{grid-template-columns:repeat(2,1fr)}.detail-grid{grid-template-columns:1fr}.panel-head{flex-direction:column}table{font-size:12px}thead th:nth-child(3),thead th:nth-child(4),tbody td:nth-child(3),tbody td:nth-child(4){display:none}}
@media print{body{background:#fff}.page{max-width:none;padding:0}header.top{border-color:#000}.btn,.controls,.chevron{display:none}tr.detail{display:none!important}.panel{box-shadow:none;border-color:#bbb}td.angle{display:table-cell!important}.cards{grid-template-columns:repeat(6,1fr)}}
</style>
</head>
<body>
<div class="page">
<header class="top">
  <div>
    <p class="eyebrow">Brocs · Prospection</p>
    <h1>Qualité des sites et réseaux sociaux</h1>
    <p class="sub">Classement des prospects par priorité d'appel. Un commerce joignable dont la présence en ligne est faible passe avant un commerce déjà bien équipé.</p>
  </div>
  <button class="btn" onclick="window.print()">Imprimer le rapport</button>
</header>

<section class="cards" aria-label="Chiffres clés">
  <div class="card"><span>Prospects</span><b>__COUNT__</b><small>__SOURCE__</small></div>
  <div class="card"><span>Priorité 1</span><b>__P1__</b><small>à contacter cette semaine</small></div>
  <div class="card"><span>Sans site</span><b>__NOSITE__</b><small>__INACCESS__ injoignable(s)</small></div>
  <div class="card"><span>Non mobile</span><b>__NOMOBILE__</b><small>__NOHTTPS__ sans HTTPS</small></div>
  <div class="card"><span>Score web moyen</span><b>__AVGWEB__</b><small>sur 100</small></div>
  <div class="card"><span>Score global moyen</span><b>__AVGGLOBAL__</b><small>__NOSOCIAL__ sans réseaux</small></div>
</section>

<section class="panel">
  <div class="panel-head"><div><h2>Les 5 appels à passer en premier</h2><p>Classement par priorité calculée, avec l'angle d'approche le plus utile.</p></div></div>
  <ol class="plan">__PLAN__</ol>
</section>

<section class="panel">
  <div class="panel-head"><div><h2>Ordre de prospection</h2><p>Cliquez sur une ligne pour voir le détail du site, des réseaux et les manques à travailler.</p></div></div>
  <div class="controls">
    <input id="search" type="search" placeholder="Rechercher un commerce, une ville, un argument…" aria-label="Rechercher">
    <label for="tier">Palier</label>
    <select id="tier">
      <option value="">Tous</option>
      <option value="priorite_1">Priorité 1</option>
      <option value="priorite_2">Priorité 2</option>
      <option value="priorite_3">Priorité 3</option>
      <option value="deja_equipe">Déjà équipé</option>
    </select>
    <label for="sort">Trier</label>
    <select id="sort">
      <option value="priority">Priorité</option>
      <option value="web">Qualité web</option>
      <option value="social">Qualité réseaux</option>
      <option value="name">Nom</option>
    </select>
  </div>
  <table>
    <thead><tr><th>#</th><th>Prospect</th><th>Contact</th><th>Site</th><th>Web</th><th>Réseaux</th><th>Global</th><th>Priorité</th><th>Angle</th><th></th></tr></thead>
    <tbody id="rows">__ROWS__</tbody>
  </table>
</section>

<section class="panel">
  <div class="panel-head"><div><h2>Méthode et limites</h2><p>Pour lire les scores sans se tromper.</p></div></div>
  <div class="method">
    <div>
      <p><b>Score web (60 %)</b> : disponibilité et sécurité (HTTPS, redirections), adaptation mobile, performance de la page d'accueil, contenu et référencement, fraîcheur, conversion et contact, accessibilité de base.</p>
      <p><b>Score réseaux sociaux (40 %)</b> : couverture des canaux, complétude des profils, audience et activité. La fiche Google est comptée comme un canal local.</p>
      <p><b>Priorité</b> = (72 % du besoin + 28 % de la valeur du commerce) × joignabilité. Sans téléphone ni e-mail, le prospect est moins prioritaire même si son site est faible.</p>
    </div>
    <div>
      <p><b>Limites</b> : Facebook, Instagram et TikTok renvoient souvent un mur de connexion. Ces pages sont marquées « non vérifiées » et comptées avec une confiance basse ; les abonnés et la date du dernier post peuvent être saisis à la main dans le CSV d'entrée.</p>
      <p>La performance ne mesure que le HTML de la page d'accueil, pas le poids des images et scripts externes. Un site très dynamique en JavaScript peut être sous-évalué.</p>
      <p class="muted">Données personnelles : ce rapport contient des coordonnées de prospects. Il ne doit pas être diffusé ni versionné.</p>
    </div>
  </div>
</section>
<footer>Rapport généré le __DATE__ · source __SOURCE__ · moteur __VERSION__</footer>
</div>
<script>
(function(){
  const tbody = document.getElementById('rows');
  const rows = Array.from(tbody.querySelectorAll('tr.prospect'));
  const pairs = new Map();
  rows.forEach(function(row){ pairs.set(row, row.nextElementSibling && row.nextElementSibling.classList.contains('detail') ? row.nextElementSibling : null); });
  const search = document.getElementById('search');
  const tier = document.getElementById('tier');
  const sort = document.getElementById('sort');
  function apply(){
    const q = search.value.trim().toLowerCase();
    rows.forEach(function(row){
      const detail = pairs.get(row);
      const ok = (!q || row.dataset.name.indexOf(q) !== -1) && (!tier.value || row.dataset.tier === tier.value);
      row.hidden = !ok;
      if (detail) detail.hidden = !ok || !row.classList.contains('open');
    });
  }
  function reorder(){
    const key = sort.value;
    const sorted = rows.slice().sort(function(a,b){
      if (key === 'name') return a.dataset.name.localeCompare(b.dataset.name, 'fr');
      const av = key === 'priority' ? +a.dataset.priority : (key === 'web' ? +a.dataset.web : +a.dataset.social);
      const bv = key === 'priority' ? +b.dataset.priority : (key === 'web' ? +b.dataset.web : +b.dataset.social);
      return bv - av || (+a.dataset.priority) - (+b.dataset.priority);
    });
    sorted.forEach(function(row){ tbody.appendChild(row); const detail = pairs.get(row); if (detail) tbody.appendChild(detail); });
  }
  rows.forEach(function(row){
    function toggle(){
      row.classList.toggle('open');
      row.setAttribute('aria-expanded', row.classList.contains('open') ? 'true' : 'false');
      const detail = pairs.get(row);
      if (detail) detail.hidden = !row.classList.contains('open') || row.hidden;
    }
    row.addEventListener('click', function(e){ if (e.target.closest('a')) return; toggle(); });
    row.addEventListener('keydown', function(e){ if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
  });
  search.addEventListener('input', apply);
  tier.addEventListener('change', apply);
  sort.addEventListener('change', reorder);
})();
</script>
</body>
</html>
HTML;

    return strtr($html, [
        '__COUNT__' => (string) $s['count'],
        '__SOURCE__' => pa_h($report['source']),
        '__DATE__' => pa_h($report['generated_at']),
        '__VERSION__' => pa_h(PA_VERSION),
        '__P1__' => (string) $s['priorite_1'],
        '__NOSITE__' => (string) $s['no_site'],
        '__INACCESS__' => (string) $s['inaccessible'],
        '__NOMOBILE__' => (string) $s['not_mobile'],
        '__NOHTTPS__' => (string) $s['no_https'],
        '__AVGWEB__' => (string) $s['avg_web'],
        '__AVGGLOBAL__' => (string) $s['avg_global'],
        '__NOSOCIAL__' => (string) $s['no_social'],
        '__PLAN__' => $plan,
        '__ROWS__' => $rows,
    ]);
}

/** Écrit les quatre formats dans un dossier (créé en 0750) et renvoie leurs chemins. */
function pa_write_report(array $report, string $dir): array
{
    $dir = rtrim($dir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer ' . $dir);
    }
    $base = $dir . '/audit-prospects';
    $files = [];
    foreach ([
        'html' => pa_report_html($report),
        'csv' => pa_report_csv($report),
        'md' => pa_report_markdown($report),
        'json' => pa_report_json($report),
    ] as $ext => $content) {
        $path = $base . '.' . $ext;
        if (file_put_contents($path, $content) === false) throw new RuntimeException('Impossible d’écrire ' . $path);
        $files[$ext] = $path;
    }
    return $files;
}
