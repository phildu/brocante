<?php
// Générateur public de dossier de subvention (/dossier/) : formulaire → documents A4 prêts à imprimer + archive ZIP.
// Aucune donnée n'est enregistrée : tout est calculé à la volée à partir du formulaire, rien n'est écrit sur le disque.

const DDOC_MAX_TEXT = 240;

function ddoc_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Texte libre : balises retirées, espaces normalisés, longueur bornée. */
function ddoc_text($v, int $max = DDOC_MAX_TEXT): string
{
    $s = preg_replace('/\s+/u', ' ', strip_tags((string) $v));
    return mb_substr(trim((string) $s), 0, $max);
}

/** Montant saisi (espaces, espaces fines, virgule décimale tolérés). */
function ddoc_num($v, float $default = 0): float
{
    $s = str_replace([' ', "\u{00a0}", "\u{202f}", ','], ['', '', '', '.'], (string) $v);
    $s = preg_replace('/[^0-9.]/', '', $s);
    if ($s === '' || !is_numeric($s)) return $default;
    return max(0.0, min(1000000.0, (float) $s));
}

function ddoc_month_name(int $m): string
{
    $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return $months[$m] ?? '';
}

/** Date ISO (champ date) → « 31 octobre 2026 ». */
function ddoc_date_fr(string $iso, string $fallback = ''): string
{
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        return (int) $m[3] . ' ' . ddoc_month_name((int) $m[2]) . ' ' . $m[1];
    }
    return $fallback;
}

function ddoc_today_fr(): string
{
    return (int) date('j') . ' ' . ddoc_month_name((int) date('n')) . ' ' . date('Y');
}

function ddoc_eur(float $n, int $dec = 0): string
{
    if ($dec > 0) {
        $s = number_format($n, $dec, ',', ' ');
        $s = rtrim(rtrim($s, '0'), ',');
    } else {
        $s = number_format($n, 0, ',', ' ');
    }
    return $s . ' €';
}

/** Grille de mise en place d'un déploiement collectif, par nombre d'adhérents. */
function ddoc_setup_grid(): array
{
    return [
        's' => ['label' => "Jusqu'à 30 adhérents", 'setup' => 1900.0],
        'm' => ['label' => 'De 31 à 80 adhérents', 'setup' => 3900.0],
        'l' => ['label' => 'Plus de 80 adhérents', 'setup' => 5900.0],
    ];
}

function ddoc_taille_for(int $members): string
{
    return $members <= 30 ? 's' : ($members <= 80 ? 'm' : 'l');
}

/** Valeurs par défaut : la grille et l'offre collective décrites dans le dossier de prescription. */
function ddoc_defaults(): array
{
    return [
        'brand' => 'Brocs',
        'assoc_name' => '', 'assoc_address' => '', 'assoc_cp' => '', 'assoc_city' => '',
        'siret' => '', 'rep_name' => '', 'rep_role' => 'Président',
        'letter_date' => date('Y-m-d'),
        'project_name' => '', 'territory' => '', 'launch' => '',
        'members' => '20', 'shops' => '20',
        'taille' => 's', 'setup' => '1900', 'rate' => '40', 'sub_monthly' => '25',
        'vendor_name' => '', 'vendor_phone' => '', 'vendor_email' => '',
        'include_reponse' => '1', 'include_grille' => '1', 'include_pieces' => '1',
    ];
}

function ddoc_parse(array $p): array
{
    $d = ddoc_defaults();
    foreach (['brand', 'assoc_name', 'assoc_address', 'assoc_city', 'rep_name', 'rep_role',
              'project_name', 'territory', 'vendor_name', 'vendor_phone'] as $k) {
        $d[$k] = ddoc_text($p[$k] ?? $d[$k], $k === 'assoc_address' ? 160 : 120);
    }
    $d['assoc_cp'] = mb_substr(preg_replace('/[^0-9A-Za-z -]/', '', (string) ($p['assoc_cp'] ?? '')), 0, 10);
    $d['vendor_email'] = mb_substr(filter_var(trim((string) ($p['vendor_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '', 0, 120);
    $d['siret'] = trim(preg_replace('/[^0-9 ]+/', '', (string) ($p['siret'] ?? '')));
    $d['siret'] = preg_replace('/\s+/', ' ', $d['siret']);
    $d['letter_date'] = ddoc_date_fr((string) ($p['letter_date'] ?? ''), ddoc_today_fr());
    $d['launch_iso'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($p['launch'] ?? '')) ? (string) $p['launch'] : '';
    $d['launch'] = ddoc_date_fr($d['launch_iso']);
    $d['members'] = max(1, min(9999, (int) ddoc_num($p['members'] ?? 20, 20)));
    $d['shops'] = max(0, min(9999, (int) ddoc_num($p['shops'] ?? $d['members'], (float) $d['members'])));
    $grid = ddoc_setup_grid();
    $d['taille'] = isset($grid[(string) ($p['taille'] ?? '')]) ? (string) $p['taille'] : ddoc_taille_for($d['members']);
    $d['setup'] = ddoc_num($p['setup'] ?? '', 0);
    if ($d['setup'] <= 0) $d['setup'] = $grid[$d['taille']]['setup'];
    $d['rate'] = min(100, ddoc_num($p['rate'] ?? 40, 40));
    $d['sub_monthly'] = ddoc_num($p['sub_monthly'] ?? 25, 25);
    foreach (['include_reponse', 'include_grille', 'include_pieces'] as $k) {
        $d[$k] = !empty($p[$k]) && $p[$k] !== '0';
    }
    $d['subsidy'] = round($d['setup'] * $d['rate'] / 100, 2);
    $d['selffund'] = max(0.0, $d['setup'] - $d['subsidy']);
    $d['per_member'] = $d['members'] > 0 ? $d['selffund'] / $d['members'] : 0;
    $d['subs_month'] = $d['sub_monthly'] * $d['shops'];
    $d['subs_year'] = $d['subs_month'] * 12;
    $d['year1'] = $d['setup'] + $d['subs_year'];
    $d['siret_digits'] = preg_replace('/\D/', '', $d['siret']);
    return $d;
}

function ddoc_errors(array $d): array
{
    $e = [];
    if ($d['assoc_name'] === '') $e['assoc_name'] = "Indiquez le nom de l'association.";
    if ($d['assoc_address'] === '') $e['assoc_address'] = "Indiquez l'adresse du siège.";
    if ($d['assoc_cp'] === '' || $d['assoc_city'] === '') $e['assoc_city'] = 'Indiquez le code postal et la ville.';
    if (strlen($d['siret_digits']) !== 14) $e['siret'] = 'Le SIRET doit comporter 14 chiffres.';
    if ($d['rep_name'] === '') $e['rep_name'] = 'Indiquez le nom du signataire.';
    if ($d['project_name'] === '') $e['project_name'] = 'Donnez un nom au projet.';
    if ($d['territory'] === '') $e['territory'] = 'Indiquez le quartier ou la commune.';
    if ($d['setup'] <= 0) $e['setup'] = 'La mise en place doit être supérieure à 0 €.';
    return $e;
}

/** Observations non bloquantes, affichées dans le formulaire. */
function ddoc_warnings(array $d): array
{
    $w = [];
    if ($d['rate'] > 50) $w[] = 'Le taux d\'aide dépasse 50 % : les dispositifs de la Métropole plafonnent en général entre 30 et 50 % du HT éligible.';
    if ($d['rate'] > 0 && $d['rate'] < 30) $w[] = 'Un taux inférieur à 30 % est inhabituel : vérifiez le taux annoncé par le financeur.';
    if ($d['shops'] > $d['members']) $w[] = 'Il y a plus de boutiques que d\'adhérents : un adhérent peut ouvrir plusieurs boutiques, mais vérifiez le chiffre.';
    $grid = ddoc_setup_grid()[$d['taille']]['setup'];
    if (abs($d['setup'] - $grid) > 0.5) {
        $w[] = 'Mise en place saisie : ' . ddoc_eur($d['setup']) . ' pour ' . $d['members'] . ' adhérents, contre ' . ddoc_eur($grid) . ' dans la grille. Vérifiez que le devis correspond à la taille déclarée.';
    }
    if ($d['setup'] > 20000) $w[] = 'Une mise en place supérieure à 20 000 € doit être justifiée ligne par ligne dans le devis.';
    return $w;
}

/* ------------------------------------------------------------------ mise en page */

function ddoc_css(): string
{
    return <<<'CSS'
.ddoc, .ddoc * { box-sizing: border-box; }
.ddoc {
  --paper: #fbf9f4; --ink: #1c1a17; --muted: #6b655c; --line: #ddd6c8;
  --accent: #9a3d21; --accent-soft: #f4e7df; --green: #2f5d4a; --green-soft: #e4ede8;
  background: #e8e4dc; color: var(--ink); font-size: 14px; line-height: 1.5;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  padding: 24px 14px 40px;
}
.ddoc .toolbar { max-width: 210mm; margin: 0 auto 14px; display: flex; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.ddoc .toolbar button, .ddoc .toolbar a {
  font: inherit; font-size: 13px; padding: 8px 14px; border-radius: 6px;
  border: 1px solid var(--ink); background: var(--ink); color: #fff; cursor: pointer; text-decoration: none;
}
.ddoc .toolbar .ghost { background: transparent; color: var(--ink); }
.ddoc .sheet {
  width: 100%; max-width: 210mm; min-height: 297mm; margin: 0 auto 22px; background: var(--paper);
  padding: 16mm 18mm 14mm; box-shadow: 0 10px 30px rgba(28,26,23,.16);
  position: relative; display: flex; flex-direction: column;
}
.ddoc h1 { font-family: "Iowan Old Style", "Palatino Linotype", Palatino, Georgia, serif; font-size: 27px; line-height: 1.15; margin: 0 0 8px; letter-spacing: -.2px; }
.ddoc h2 { font-size: 11px; text-transform: uppercase; letter-spacing: 1.3px; color: var(--accent); margin: 20px 0 8px; font-weight: 700; }
.ddoc h3 { font-family: "Iowan Old Style", Georgia, serif; font-size: 15px; margin: 0 0 4px; }
.ddoc p { margin: 0 0 9px; }
.ddoc .lead { font-size: 14.5px; color: #3a362f; margin-bottom: 12px; }
.ddoc .brandbar { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid var(--ink); padding-bottom: 10px; margin-bottom: 16px; }
.ddoc .wordmark { font-family: "Iowan Old Style", Georgia, serif; font-size: 26px; letter-spacing: 2px; text-transform: uppercase; font-weight: 700; }
.ddoc .brandbar .tag { font-size: 11.5px; color: var(--muted); text-align: right; max-width: 58%; line-height: 1.35; }
.ddoc .meta { font-size: 10.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .8px; margin-bottom: 18px; }
.ddoc .cols { display: flex; gap: 14px; }
.ddoc .cols > div { flex: 1; }
.ddoc .box { border: 1px solid var(--line); border-radius: 6px; padding: 10px 12px; background: #fff; }
.ddoc .box.ok { background: var(--green-soft); border-color: #c9dbd2; }
.ddoc .box.no { background: #f7f4ee; }
.ddoc .box h4 { margin: 0 0 6px; font-size: 10.5px; text-transform: uppercase; letter-spacing: 1px; }
.ddoc .box.ok h4 { color: var(--green); }
.ddoc .box.no h4 { color: var(--muted); }
.ddoc ul, .ddoc ol { margin: 0; padding-left: 16px; }
.ddoc li { margin-bottom: 3px; }
.ddoc .steps { display: flex; gap: 10px; margin: 6px 0 2px; }
.ddoc .step { flex: 1; border-top: 2px solid var(--accent); padding-top: 7px; }
.ddoc .step b { display: block; font-size: 12.5px; margin-bottom: 2px; }
.ddoc .step span { font-size: 11.5px; color: var(--muted); }
.ddoc table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.ddoc th { text-align: left; font-size: 9.5px; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); border-bottom: 1px solid var(--ink); padding: 0 6px 5px; font-weight: 700; }
.ddoc td { padding: 5px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
.ddoc td.num, .ddoc th.num { text-align: right; white-space: nowrap; }
.ddoc tr.total td { border-top: 1.5px solid var(--ink); border-bottom: none; font-weight: 700; }
.ddoc .note { font-size: 10.5px; color: var(--muted); margin-top: 5px; }
.ddoc .fill { background: #fff; border-bottom: 1px solid var(--ink); padding: 0 2px; font-style: italic; color: #7a7267; }
.ddoc .warn { background: var(--accent-soft); border: 1px solid #e3c8bb; border-radius: 6px; padding: 9px 12px; font-size: 11.5px; margin-top: 8px; }
.ddoc .warn b { color: var(--accent); }
.ddoc .footer { margin-top: auto; padding-top: 10px; border-top: 1px solid var(--line); display: flex; justify-content: space-between; font-size: 9.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; }
.ddoc .contact { display: flex; gap: 16px; border-top: 2px solid var(--ink); margin-top: 14px; padding-top: 10px; font-size: 11.5px; }
.ddoc .contact > div { flex: 1; }
.ddoc .contact b { display: block; font-size: 9.5px; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); margin-bottom: 3px; }
.ddoc .cdc p { font-size: 11.5px; margin-bottom: 6px; }
.ddoc .cdc-cols { display: flex; gap: 18px; margin-top: 2px; }
.ddoc .cdc-cols > div { flex: 1; }
.ddoc .checklist { columns: 2; column-gap: 18px; font-size: 11.5px; }
.ddoc .checklist div { margin-bottom: 4px; break-inside: avoid; }
.ddoc .checklist i { display: inline-block; width: 10px; height: 10px; border: 1px solid var(--ink); margin-right: 6px; vertical-align: -1px; }
.ddoc .letter { border: 1px solid var(--line); background: #fff; padding: 14px 16px; font-size: 12.5px; line-height: 1.5; }
.ddoc .letter .lh { display: flex; justify-content: space-between; gap: 24px; margin-bottom: 10px; }
.ddoc .letter .lh > div:last-child { text-align: right; white-space: nowrap; }
.ddoc .letter p { margin: 0 0 6px; }
.ddoc .letter .objet { font-weight: 700; margin: 9px 0; }
.ddoc .letter .sign { margin-top: 12px; }
.ddoc .letter .addr { font-size: 11.5px; color: #3a362f; }
.ddoc table th:nth-child(2) { white-space: nowrap; }
.ddoc .notice { max-width: 210mm; margin: 0 auto 14px; background: #fffaf0; border: 1px solid #e6d5a8; border-left: 4px solid var(--accent); border-radius: 6px; padding: 10px 12px; font-size: 12px; }
.ddoc .notice div + div { margin-top: 3px; }
.ddoc .toc { font-size: 12px; margin: 0 0 0 4px; }
.ddoc .toc li { margin-bottom: 4px; }
.ddoc .banner-title { font-family: "Iowan Old Style", Georgia, serif; font-size: 34px; line-height: 1.1; margin: 6mm 0 4mm; letter-spacing: -.4px; }
.ddoc .cover-table { margin-top: 4mm; }
.ddoc .tagline { color: var(--muted); font-size: 12.5px; }

@media (max-width: 780px) {
  .ddoc .cols, .ddoc .steps, .ddoc .cdc-cols { flex-direction: column; }
  .ddoc .sheet { min-height: 0; padding: 22px 18px; }
  .ddoc .contact { flex-direction: column; gap: 10px; }
}

@media print {
  @page { size: A4; margin: 0; }
  .ddoc { background: #fff !important; padding: 0 !important; font-size: 13px; line-height: 1.48; }
  .ddoc .toolbar, .ddoc .notice { display: none !important; }
  .ddoc .sheet {
    box-shadow: none !important; margin: 0 !important; width: 210mm; max-width: none; min-height: 296mm;
    padding: 11mm 16mm 9mm; break-after: page; page-break-after: always;
  }
  .ddoc .sheet:last-child { break-after: auto; page-break-after: auto; }
  .ddoc .cols, .ddoc .steps, .ddoc .cdc-cols, .ddoc .contact { flex-direction: row; }
  .ddoc .brandbar { padding-bottom: 9px; margin-bottom: 14px; }
  .ddoc .meta { margin-bottom: 15px; }
  .ddoc h1 { font-size: 23px; margin-bottom: 7px; }
  .ddoc h2 { margin: 15px 0 6px; }
  .ddoc .lead { font-size: 13.2px; margin-bottom: 9px; }
  .ddoc td { padding: 4px 6px; }
  .ddoc .box { padding: 9px 11px; }
  .ddoc .box h4 { margin-bottom: 5px; }
  .ddoc li { margin-bottom: 2px; }
  .ddoc .contact { margin-top: 14px; padding-top: 9px; }
  .ddoc .footer { padding-top: 8px; }
  .ddoc .cdc { font-size: 12.4px; }
  .ddoc .cdc h3 { font-size: 13.6px; margin: 6px 0 3px; }
  .ddoc .cdc p { margin-bottom: 4px; }
  .ddoc .cdc .lead { margin-bottom: 7px; }
  .ddoc .checklist { font-size: 11.4px; }
  .ddoc .checklist div { margin-bottom: 2px; }
  .ddoc .letter { font-size: 12px; padding: 12px 14px; }
  .ddoc .letter p { margin-bottom: 5px; }
  .ddoc .box, .ddoc .warn, .ddoc table, .ddoc .step { break-inside: avoid; }
  .ddoc, .ddoc * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
CSS;
}

function ddoc_head(string $title): string
{
    return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . ddoc_e($title) . '</title>
<style>html, body { margin: 0; padding: 0; background: #e8e4dc; }</style>
<style>' . ddoc_css() . '</style>
</head>
<body>
<div class="ddoc">';
}

/** Page autonome : un ou plusieurs feuillets, barre d'impression masquée à l'impression. */
function ddoc_page(string $title, string $body, string $toolbar = ''): string
{
    return ddoc_head($title) . ($toolbar !== '' ? '<div class="toolbar">' . $toolbar . '</div>' : '') . $body . '</div></body></html>';
}

function ddoc_brand(array $d, string $tag): string
{
    return '<div class="brandbar"><div class="wordmark">' . ddoc_e($d['brand']) . '</div><div class="tag">' . $tag . '</div></div>';
}

function ddoc_footer(array $d, string $label, int $page, int $total): string
{
    return '<div class="footer"><span>' . ddoc_e($d['brand']) . ' &middot; ' . ddoc_e($label) . '</span>'
        . '<span>Dossier de subvention</span><span>' . $page . ' / ' . $total . '</span></div>';
}

function ddoc_sheet(array $d, string $inner, string $label, int $page, int $total): string
{
    return '<section class="sheet">' . $inner . ddoc_footer($d, $label, $page, $total) . '</section>';
}

/* ------------------------------------------------------------------ documents */

function ddoc_doc_cover(array $d, array $docs, int $page, int $total): string
{
    $rows = '';
    $rows .= '<tr><td>Mise en place collective HT</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td>Dépense éligible</td></tr>';
    $rows .= '<tr><td>Subvention demandée (' . rtrim(rtrim(number_format($d['rate'], 1, ',', ' '), '0'), ',') . ' %)</td><td class="num">' . ddoc_eur($d['subsidy']) . '</td><td>Sollicitée</td></tr>';
    $rows .= '<tr><td>Autofinancement de l\'association</td><td class="num">' . ddoc_eur($d['selffund']) . '</td><td>Acquis ou à confirmer</td></tr>';
    if ($d['subs_year'] > 0) {
        $rows .= '<tr><td>Abonnements des boutiques (' . ddoc_eur($d['sub_monthly'], 2) . ' par mois, ' . $d['shops'] . ' boutiques)</td><td class="num">' . ddoc_eur($d['subs_year']) . ' / an</td><td>Non éligible, portés par les cotisations</td></tr>';
    }

    $toc = '';
    foreach ($docs as $doc) $toc .= '<li>' . ddoc_e($doc['title']) . '</li>';

    $body = ddoc_brand($d, 'Dossier de subvention<br>' . ddoc_e($d['letter_date']))
        . '<div class="meta">' . ddoc_e($d['assoc_name']) . ' &middot; SIRET ' . ddoc_e($d['siret']) . '</div>'
        . '<div class="banner-title">' . ddoc_e($d['project_name']) . '</div>'
        . '<p class="lead">Dossier de demande de subvention pour un outil numérique mutualisé au bénéfice de '
        . ddoc_e($d['members']) . ' adhérents du territoire de ' . ddoc_e($d['territory']) . '.</p>'
        . '<table class="cover-table"><thead><tr><th>Poste</th><th class="num">Montant</th><th>Statut</th></tr></thead><tbody>'
        . $rows
        . '</tbody></table>'
        . '<p class="note">TVA non applicable, article 293 B du CGI. Les abonnements ne font pas partie des dépenses éligibles : ils sont portés par les cotisations des adhérents.</p>'
        . '<h2>Pièces du dossier</h2><ol class="toc">' . $toc . '</ol>'
        . '<div class="contact"><div style="flex:1.7"><b>Demandeur</b>' . ddoc_e($d['assoc_name']) . '<br>'
        . ddoc_e($d['assoc_address']) . '<br>' . ddoc_e(trim($d['assoc_cp'] . ' ' . $d['assoc_city'])) . '</div>'
        . '<div><b>Représenté par</b>' . ddoc_e($d['rep_name']) . '<br>' . ddoc_e($d['rep_role']) . '</div>'
        . '<div style="flex:.8"><b>Prestataire</b>' . ddoc_e($d['brand']) . '<br>' . ddoc_e($d['vendor_name'] !== '' ? $d['vendor_name'] : 'à compléter') . '</div></div>';

    return ddoc_sheet($d, $body, 'Page de garde', $page, $total);
}

function ddoc_doc_letter(array $d, int $page, int $total): string
{
    $body = ddoc_brand($d, 'Lettre d\'accompagnement<br>côté association')
        . '<div class="meta">Courrier de demande de subvention &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>La lettre d\'accompagnement.</h1>'
        . '<p class="lead">Courrier signé par le représentant de l\'association, joint au dossier. Les montants correspondent à la mise en place et au taux d\'aide indiqués dans le formulaire.</p>'
        . '<div class="letter"><div class="lh">'
        . '<div class="addr">' . ddoc_e($d['assoc_name']) . '<br>' . ddoc_e($d['assoc_address']) . '<br>'
        . ddoc_e(trim($d['assoc_cp'] . ' ' . $d['assoc_city'])) . '<br>SIRET : ' . ddoc_e($d['siret']) . '<br>'
        . 'Représentée par : ' . ddoc_e($d['rep_name']) . '<br>' . ddoc_e($d['rep_role']) . '</div>'
        . '<div class="addr">' . ddoc_e($d['assoc_city']) . ', le ' . ddoc_e($d['letter_date']) . '<br><br>'
        . 'Monsieur le Président<br>Métropole de Lyon<br>Direction de la Transition Économique<br>'
        . '106 rue Servient, BP 31036<br>69399 Lyon Cedex 03</div></div>'
        . '<p class="objet">Objet : demande de subvention pour le projet d\'outil numérique mutualisé ' . ddoc_e($d['project_name']) . '</p>'
        . '<p>Monsieur le Président,</p>'
        . '<p>L\'association ' . ddoc_e($d['assoc_name']) . ', qui regroupe ' . ddoc_e($d['members']) . ' commerçants, artisans et acteurs économiques du quartier '
        . ddoc_e($d['territory']) . ', s\'engage dans la redynamisation et la modernisation du tissu commercial de proximité.</p>'
        . '<p>Face à la mutation des usages et à la concurrence des acteurs du commerce en ligne, notre collectif souhaite franchir un cap en déployant une solution numérique mutualisée : une page commune pour le quartier, une boutique en ligne par commerçant, et un accès unique pour les habitants. Le projet vise à renforcer la visibilité collective du périmètre, à orienter les flux de consommation vers les commerces de proximité et à rendre chaque adhérent autonome sur ses fiches, ses horaires et ses offres.</p>'
        . '<p>Le budget global du projet s\'élève à ' . ddoc_eur($d['setup']) . ' HT, TVA non applicable, article 293 B du CGI. Notre association apporte un autofinancement de '
        . ddoc_eur($d['selffund']) . ' HT, soit ' . ddoc_pct(100 - $d['rate']) . ' du budget. Par la présente, nous sollicitons une subvention d\'investissement à hauteur de '
        . ddoc_eur($d['subsidy']) . ' HT, représentant ' . ddoc_pct($d['rate']) . ' du montant éligible.</p>'
        . '<p>Vous trouverez ci-joint la note de cadrage détaillée, le budget prévisionnel de l\'opération ainsi que les pièces administratives de notre structure.</p>'
        . '<p>Restant à votre entière disposition pour échanger sur ce projet au service de l\'économie de proximité métropolitaine, nous vous prions d\'agréer, Monsieur le Président, l\'expression de notre haute considération.</p>'
        . '<p class="sign">' . ddoc_e($d['rep_name']) . '<br>' . ddoc_e($d['rep_role']) . ' de ' . ddoc_e($d['assoc_name']) . '</p></div>'
        . '<h2>Trois points de vigilance</h2><div class="steps">'
        . '<div class="step"><b>Le montant cité doit être celui du devis</b><span>' . ddoc_eur($d['setup']) . ' HT de mise en place, abonnements exclus. Un courrier qui annonce un autre montant ne correspond plus à la facture.</span></div>'
        . '<div class="step"><b>Pas de montant TTC inventé</b><span>' . ddoc_e($d['brand']) . ' n\'est pas assujetti à la TVA : le devis porte la mention « TVA non applicable, article 293 B du CGI ».</span></div>'
        . '<div class="step"><b>Pas de service annoncé à tort</b><span>N\'écrivez que ce que la plateforme fait réellement : page commune et boutiques en ligne, pas de fidélité partagée entre commerces.</span></div>'
        . '</div>';

    return ddoc_sheet($d, $body, 'Lettre d\'accompagnement', $page, $total);
}

function ddoc_pct(float $v): string
{
    return rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',') . ' %';
}

function ddoc_doc_note(array $d, int $page, int $total): string
{
    $body = ddoc_brand($d, 'Note de cadrage<br>côté association')
        . '<div class="meta">Éléments à recopier dans le dossier de subvention &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>La note de cadrage, côté association.</h1>'
        . '<p class="lead">La note appartient à l\'association : c\'est elle qui la signe et la dépose. Les sections techniques ci-dessous sont fournies par ' . ddoc_e($d['brand']) . ' pour être recopiées telles quelles.</p>'
        . '<h2>Rédigé par l\'association</h2><div class="steps">'
        . '<div class="step"><b>1 &middot; Contexte et enjeux</b><span>Périmètre de ' . ddoc_e($d['territory']) . ', ' . ddoc_e($d['members']) . ' adhérents actifs, fréquentation, ce qui manque aujourd\'hui aux commerçants.</span></div>'
        . '<div class="step"><b>2 &middot; Objectifs et indicateurs</b><span>Ce que le projet doit changer, et la mesure à six mois : adhésions, visites, ventes en ligne.</span></div>'
        . '<div class="step"><b>4 &middot; Gouvernance et pérennité</b><span>Comité de suivi, référent technique, et ce qui reste financé après la subvention.</span></div>'
        . '</div>'
        . '<h2>Fourni par ' . ddoc_e($d['brand']) . '</h2><div class="cdc-cols"><div>'
        . '<h3>3. Descriptif technique</h3>'
        . '<p>Plateforme mutualisée : une page pour le collectif et une boutique par commerçant, accessibles depuis un navigateur et installables sur téléphone. Chaque commerçant gère sa fiche, ses horaires et ses offres depuis une interface en français, sans compétence technique. Paiement par carte traité par Stripe ; ' . ddoc_e($d['brand']) . ' ne stocke aucune donnée bancaire. L\'import initial des fiches et du catalogue est assuré par ' . ddoc_e($d['brand']) . ' à partir des éléments fournis par l\'association ; une formation collective et une session de prise en main sont incluses.</p>'
        . '</div><div><h3>5. Calendrier</h3>'
        . '<p>J+0 : devis signé et accès aux contenus. J+2 : plateforme configurée, nom de domaine rattaché. J+7 : import des fiches et formation des adhérents. J+30 : recette avec les commerçants pilotes.'
        . ($d['launch'] !== '' ? ' Lancement public le ' . ddoc_e($d['launch']) . '.' : ' Lancement public à la date choisie par l\'association.') . '</p>'
        . '<p>Le dépôt du dossier précède la commande : aucune facture n\'est émise avant l\'accord, et aucune dépense n\'est engagée avant le dépôt.</p>'
        . '</div></div>'
        . '<h2>6. Budget : ce qui est éligible</h2><table>'
        . '<thead><tr><th>Poste</th><th class="num">Montant HT</th><th>Éligible</th></tr></thead><tbody>'
        . '<tr><td>Mise en place collective : paramétrage, import des fiches, nom de domaine, formation incluse</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td>Oui, dépense éligible</td></tr>'
        . ($d['subs_year'] > 0 ? '<tr><td>Abonnements des boutiques (' . ddoc_eur($d['sub_monthly'], 2) . ' par mois, ' . $d['shops'] . ' boutiques)</td><td class="num">' . ddoc_eur($d['subs_year']) . ' / an</td><td>Non, portés par les cotisations</td></tr>' : '')
        . '<tr><td>Communication de lancement</td><td class="num">Budget de l\'association</td><td>Non</td></tr>'
        . '<tr><td>Matériel : écrans, tablettes, matériel photo</td><td class="num">—</td><td>Non</td></tr>'
        . '</tbody></table>'
        . '<p class="note">À ' . ddoc_pct($d['rate']) . ', la subvention couvre ' . ddoc_eur($d['subsidy']) . ' sur ' . ddoc_eur($d['setup']) . ' ; le reste, ' . ddoc_eur($d['selffund']) . ', représente ' . ddoc_eur($d['per_member'], 2) . ' par adhérent à ' . ddoc_e($d['members']) . ' membres. Devis détaillé et facture fournis ligne par ligne. TVA non applicable, article 293 B du CGI.</p>'
        . '<div class="warn"><b>À ne pas écrire dans le dossier :</b> « développement sur-mesure » (' . ddoc_e($d['brand']) . ' déploie et paramètre une plateforme existante, la facture doit dire la même chose), une dépense déjà engagée avant le dépôt, ou une subvention couvrant les abonnements et la communication, qui ne sont pas éligibles. Un dossier qui promet autre chose que la facture est repris au contrôle.</div>';

    return ddoc_sheet($d, $body, 'Note de cadrage', $page, $total);
}

function ddoc_doc_budget(array $d, int $page, int $total): string
{
    $rows = '<tr><td>Mise en place collective : paramétrage, import des fiches, nom de domaine, formation</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td>Oui</td><td>Subvention et autofinancement</td></tr>';
    if ($d['subs_year'] > 0) {
        $rows .= '<tr><td>Abonnements des boutiques (' . ddoc_eur($d['sub_monthly'], 2) . ' × ' . $d['shops'] . ' × 12)</td><td class="num">' . ddoc_eur($d['subs_year']) . '</td><td>Non</td><td>Cotisations des adhérents</td></tr>';
    }
    $rows .= '<tr><td>Communication de lancement</td><td class="num">à compléter</td><td>Non</td><td>Association</td></tr>'
        . '<tr><td>Matériel : écrans, tablettes, matériel photo</td><td class="num">—</td><td>Non</td><td>Association</td></tr>';

    $body = ddoc_brand($d, 'Budget prévisionnel<br>et plan de financement')
        . '<div class="meta">Exercice de dépôt &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Budget prévisionnel du projet.</h1>'
        . '<p class="lead">' . ddoc_e($d['project_name']) . ', porté par ' . ddoc_e($d['assoc_name']) . '. Seule la mise en place est éligible ; les abonnements et la communication restent à la charge de l\'association.</p>'
        . '<h2>Dépenses</h2><table><thead><tr><th>Poste</th><th class="num">Montant HT</th><th>Éligible</th><th>Financé par</th></tr></thead><tbody>'
        . $rows
        . '<tr class="total"><td>Total des dépenses éligibles</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td></td><td></td></tr>'
        . '</tbody></table>'
        . '<h2>Plan de financement</h2><table><thead><tr><th>Ressource</th><th class="num">Montant HT</th><th>Part</th><th>Statut</th></tr></thead><tbody>'
        . '<tr><td>Subvention Métropole de Lyon</td><td class="num">' . ddoc_eur($d['subsidy']) . '</td><td>' . ddoc_pct($d['rate']) . '</td><td>Sollicitée</td></tr>'
        . '<tr><td>Fonds propres de l\'association</td><td class="num">' . ddoc_eur($d['selffund']) . '</td><td>' . ddoc_pct(100 - $d['rate']) . '</td><td>Acquis ou à confirmer</td></tr>'
        . '<tr class="total"><td>Total des ressources</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td>100 %</td><td></td></tr>'
        . '</tbody></table>'
        . '<p class="note">Autofinancement rapporté aux adhérents : ' . ddoc_eur($d['per_member'], 2) . ' par adhérent à ' . ddoc_e($d['members']) . ' membres. TVA non applicable, article 293 B du CGI.</p>'
        . '<h2>Ce qui n\'entre pas dans le budget éligible</h2>'
        . '<div class="steps">'
        . '<div class="step"><b>Abonnements</b><span>' . ($d['subs_year'] > 0 ? ddoc_eur($d['subs_year']) . ' par an pour ' . $d['shops'] . ' boutiques. ' : '') . 'Dépense de fonctionnement, exclue des aides à l\'investissement.</span></div>'
        . '<div class="step"><b>Communication</b><span>Affiches, presse, réseaux sociaux : portés par l\'association, hors subvention.</span></div>'
        . '<div class="step"><b>Matériel</b><span>Écrans, tablettes, appareil photo : dépense individuelle, non mutualisable.</span></div>'
        . '</div>'
        . '<div class="warn"><b>Règle de calendrier :</b> aucune dépense ne doit être engagée avant le dépôt du dossier. Une facture antérieure au dépôt est écartée, même si la prestation est utile au projet.</div>';

    return ddoc_sheet($d, $body, 'Budget prévisionnel', $page, $total);
}

function ddoc_doc_reponse(array $d, int $page, int $total): string
{
    $year1 = $d['setup'] + $d['sub_monthly'] * 12;
    $body = ddoc_brand($d, 'Réponse à cahier des charges<br>modèle prérempli')
        . '<div class="meta">Réponse du prestataire &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Réponse à cahier des charges.</h1>'
        . '<p class="lead">Réponse de ' . ddoc_e($d['brand']) . ' au projet de ' . ddoc_e($d['assoc_name']) . '. Les montants reprennent la mise en place et l\'abonnement saisis ; le devis signé prévaut.</p>'
        . '<h3>1. Compréhension du besoin</h3>'
        . '<p>' . ddoc_e($d['assoc_name']) . ' souhaite doter ' . ddoc_e($d['members']) . ' adhérents de ' . ddoc_e($d['territory']) . ' d\'une page commune et d\'une boutique en ligne par commerçant, administrable sans compétence technique.</p>'
        . '<h3>2. Périmètre inclus</h3>'
        . '<p>Plateforme mutualisée hébergée, cinq modèles de boutique au choix, import de ' . ddoc_e($d['shops']) . ' fiches, configuration du nom de domaine, paiement par carte via Stripe, comptes administrateurs pour l\'association, une session de formation collective et une session de prise en main.</p>'
        . '<h3>3. Hors périmètre</h3>'
        . '<p>Production de photos au-delà de l\'import, rédaction des textes commerciaux, achat de matériel, campagnes publicitaires, tenue de comptabilité, maintenance d\'un logiciel tiers.</p>'
        . '<h3>4. Prix</h3><table><thead><tr><th>Poste</th><th class="num">Montant HT</th><th>Nature</th></tr></thead><tbody>'
        . '<tr><td>Mise en place collective</td><td class="num">' . ddoc_eur($d['setup']) . '</td><td>Prestation ponctuelle</td></tr>'
        . '<tr><td>Abonnement par boutique (' . ddoc_eur($d['sub_monthly'], 2) . ' par mois)</td><td class="num">' . ddoc_eur($d['sub_monthly'] * 12) . ' / an</td><td>Récurrent, sans engagement</td></tr>'
        . '<tr class="total"><td>Total année 1, une boutique</td><td class="num">' . ddoc_eur($year1) . '</td><td></td></tr>'
        . '</tbody></table>'
        . '<p class="note">TVA non applicable, article 293 B du CGI. Devis valable 30 jours.</p>'
        . '<div class="cdc-cols"><div>'
        . '<h3>5. Délais et planning</h3><p>J+0 : commande et accès aux contenus. J+2 : plateforme configurée. J+7 : import du catalogue et formation. J+30 : bilan des premières commandes.</p>'
        . '<h3>6. Livrables</h3><p>Plateforme publique, accès administrateur, catalogue importé, domaine rattaché, attestation de formation, procédure de prise en main d\'une page.</p>'
        . '<h3>7. Formation et accompagnement</h3><p>Une session collective et une session de prise en main, sur place ou en visio. Support par e-mail ; support prioritaire selon le palier souscrit.</p>'
        . '</div><div>'
        . '<h3>8. Hébergement et données</h3><p>Hébergement géré par ' . ddoc_e($d['brand']) . ', sans serveur à maintenir côté client. Les paiements sont traités par Stripe ; aucune donnée bancaire n\'est stockée. ' . ddoc_e($d['brand']) . ' agit comme sous-traitant au sens du RGPD ; un accord de sous-traitance peut être joint au dossier.</p>'
        . '<h3>9. Références</h3><p>Deux boutiques en production, citables sur accord écrit de leurs exploitants.</p>'
        . '<h3>10. Conditions</h3><p>Paiement à 30 jours pour les associations. Abonnement résiliable à tout moment ; la mise en place reste due. Données exportables à la résiliation.</p>'
        . '</div></div>'
        . '<div class="contact"><div style="flex:1.4"><b>Prestataire</b>' . ddoc_e($d['brand']) . '</div>'
        . '<div><b>Contact</b>' . ddoc_e($d['vendor_name'] !== '' ? $d['vendor_name'] : 'à compléter') . '</div>'
        . '<div><b>Téléphone</b>' . ddoc_e($d['vendor_phone'] !== '' ? $d['vendor_phone'] : 'à compléter') . '</div>'
        . '<div><b>E-mail</b>' . ddoc_e($d['vendor_email'] !== '' ? $d['vendor_email'] : 'à compléter') . '</div></div>';

    return ddoc_sheet($d, $body, 'Réponse à cahier des charges', $page, $total);
}

function ddoc_doc_grille(array $d, int $page, int $total): string
{
    $body = ddoc_brand($d, 'Grille d\'évaluation<br>du prestataire')
        . '<div class="meta">Comparaison des devis &middot; barème sur 100 points &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Comparer les devis, pas les plaquettes.</h1>'
        . '<p class="lead">La grille se fixe avant l\'appel d\'offres et se remplit à l\'identique pour chaque candidat. Quatre candidats rendent la comparaison opposable ; deux ne la rendent pas.</p>'
        . '<table><thead><tr><th>Critère</th><th class="num">Points</th><th>Ce qui est examiné</th></tr></thead><tbody>'
        . '<tr><td>Conformité fonctionnelle et ergonomie</td><td class="num">30</td><td>Respect du cahier des charges, simplicité du back-office pour les commerçants</td></tr>'
        . '<tr><td>Délais et planning</td><td class="num">20</td><td>Faisabilité du calendrier, jalons de recette et de livraison</td></tr>'
        . '<tr><td>Accompagnement et formation</td><td class="num">20</td><td>Modules de formation, guides d\'utilisation, support</td></tr>'
        . '<tr><td>Compétitivité et transparence</td><td class="num">15</td><td>Prix au regard du marché, séparation du développement et du récurrent</td></tr>'
        . '<tr><td>Références et expertise collective</td><td class="num">15</td><td>Projets comparables, hébergement, sécurité, conformité RGPD</td></tr>'
        . '<tr class="total"><td>Total</td><td class="num">100</td><td></td></tr>'
        . '</tbody></table>'
        . '<h2>Comment la remplir</h2><div class="steps">'
        . '<div class="step"><b>Avant</b><span>Fixer les pondérations, les critères éliminatoires (hébergement en Europe, RGPD, SIRET à jour) et le nombre de devis exigé. Les publier avec le cahier des charges.</span></div>'
        . '<div class="step"><b>Pendant</b><span>Noter chaque critère sur la même échelle, avec une pièce par note : capture datée, extrait de devis, référence vérifiable.</span></div>'
        . '<div class="step"><b>Après</b><span>Conserver la grille remplie et le procès-verbal de choix : c\'est la preuve de mise en concurrence demandée au versement.</span></div>'
        . '</div>'
        . '<h2>Cinq questions à poser pendant la démonstration</h2><div class="checklist">'
        . '<div><i></i>Qui met à jour la fiche quand un horaire change, et en combien de clics ?</div>'
        . '<div><i></i>Si un commerçant quitte le collectif, ses fiches et ses images sont-elles exportables ?</div>'
        . '<div><i></i>Où sont hébergées les données, et le prestataire accepte-t-il un accord de sous-traitance RGPD ?</div>'
        . '<div><i></i>Quel est le coût réel à trois ans, abonnement et options comprises, sans engagement ?</div>'
        . '<div><i></i>Que devient la boutique si le prestataire cesse son activité : reprise, export, nom de domaine ?</div>'
        . '</div>'
        . '<div class="warn"><b>Le piège de la grille :</b> trente points sur la conformité ne se justifient pas avec une plaquette. Demander une démonstration datée et un accès de test ; un prestataire qui refuse l\'un ou l\'autre laisse la note vide, quel que soit son prix.</div>';

    return ddoc_sheet($d, $body, 'Grille d\'évaluation', $page, $total);
}

/** Date FR décalée de N mois (N négatif = avant) ; chaîne vide si la date est invalide. */
function ddoc_shift_months(string $iso, int $months): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return '';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $iso);
    if (!$dt) return '';
    $dt = $dt->modify(($months >= 0 ? '+' : '') . $months . ' months');
    return (int) $dt->format('j') . ' ' . ddoc_month_name((int) $dt->format('n')) . ' ' . $dt->format('Y');
}

function ddoc_doc_calendrier(array $d, int $page, int $total): string
{
    $advice = '';
    if ($d['launch_iso'] !== '') {
        $deposit = ddoc_shift_months($d['launch_iso'], -6);
        if ($deposit !== '') {
            $advice = '<div class="warn"><b>Votre date de lancement : ' . ddoc_e($d['launch']) . '.</b> À six mois du lancement, le dépôt devrait être fait avant le '
                . ddoc_e($deposit) . '. Un dossier déposé plus tard arrive après le vote : la prestation démarre alors que la subvention n\'est pas encore notifiée, ce qui oblige l\'association à avancer la totalité du montant.</div>';
        }
    }

    $body = ddoc_brand($d, 'Calendrier<br>de l\'instruction')
        . '<div class="meta">Métropole de Lyon &middot; délais indicatifs &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Deux saisons entre le dépôt et le virement.</h1>'
        . '<p class="lead">Compter trois à six mois entre le dépôt du dossier et le versement de la subvention : instruction technique, puis inscription à l\'ordre du jour d\'une instance délibérante. C\'est ce délai qui impose de déposer avant de commander.</p>'
        . '<table><thead><tr><th>Étape</th><th class="num">Durée</th><th>Ce qui se joue</th><th>Ce qui peut relancer le délai</th></tr></thead><tbody>'
        . '<tr><td>Accusé de réception et complétude</td><td class="num">1 à 3 sem.</td><td>Le service instructeur contrôle le SIRET, le RIB, les statuts et les devis.</td><td>Un dossier incomplet déclenche une demande de pièces : le délai repart.</td></tr>'
        . '<tr><td>Instruction technique et financière</td><td class="num">1 à 2 mois</td><td>Opportunité économique, nombre d\'adhérents touchés, pertinence du budget, faisabilité du planning.</td><td>Un budget peu lisible ou un calendrier irréaliste allonge l\'instruction.</td></tr>'
        . '<tr><td>Commission et vote</td><td class="num">1 mois</td><td>Avis favorable soumis au Conseil métropolitain (environ tous les deux mois) ou à la Commission permanente.</td><td>Un dossier prêt après la réunion attend la suivante.</td></tr>'
        . '<tr><td>Notification et conventionnement</td><td class="num">3 à 4 sem.</td><td>Arrêté d\'attribution ou convention de subvention adressé à l\'association.</td><td>Une convention non signée bloque la mise en paiement.</td></tr>'
        . '<tr><td>Mise en paiement</td><td class="num">2 à 4 sem.</td><td>Virement exécuté par la paierie sur l\'IBAN du compte Tiers.</td><td>Un RIB différent de celui validé renvoie le dossier au service financier.</td></tr>'
        . '<tr class="total"><td>Du dépôt au virement</td><td class="num">3 à 6 mois</td><td colspan="2">Délai global, hors demande de pièces complémentaires.</td></tr>'
        . '</tbody></table>'
        . '<h2>Les deux calendriers possibles</h2><div class="cdc-cols"><div>'
        . '<h3>Guichet permanent (au fil de l\'eau)</h3>'
        . '<p>La plupart des aides au commerce de proximité et à la modernisation économique. Le dossier est instruit dès sa réception, puis inscrit à la commission permanente suivante, réunie toutes les quatre à six semaines. C\'est le calendrier le plus rapide : il récompense un dossier complet déposé tôt.</p>'
        . '</div><div>'
        . '<h3>Appel à projets (calendrier annuel)</h3>'
        . '<p>Campagnes thématiques, animation territoriale ou transition numérique collective : dépôt de septembre à novembre, instruction de janvier à mars, vote en avril et mai, versement en juin et juillet. Un dossier manqué se reporte d\'un an.</p>'
        . '</div></div>'
        . '<h2>Trois conseils pour tenir le calendrier</h2><div class="steps">'
        . '<div class="step"><b>Déposer avant de commander</b><span>Les dépenses engagées ou facturées avant la date de dépôt sont écartées par les règles de la comptabilité publique, sans exception.</span></div>'
        . '<div class="step"><b>Demander un avis d\'opportunité</b><span>Présenter la note de cadrage en amont aux services de la Métropole ou à la CCI permet d\'être inscrit au bon ordre du jour dès le premier passage.</span></div>'
        . '<div class="step"><b>Valider le compte Tiers d\'abord</b><span>Le RIB et l\'attestation SIRENE se font valider avant le dépôt : une fiche Tiers en attente retarde le seul virement, après le vote.</span></div>'
        . '</div>'
        . $advice;

    return ddoc_sheet($d, $body, 'Calendrier de l\'instruction', $page, $total);
}

function ddoc_doc_compte(array $d, int $page, int $total): string
{
    $ci = '<div><i></i>L\'intitulé de l\'association est strictement identique sur le SIRET, le RIB et les statuts.</div>'
        . '<div><i></i>L\'adresse du siège sur l\'avis SIRENE est celle enregistrée à la banque.</div>'
        . '<div><i></i>Statuts mis à jour, datés et signés par le président.</div>'
        . '<div><i></i>Récépissé de déclaration en préfecture (création ou dernière modification).</div>'
        . '<div><i></i>Avis de situation SIRENE (INSEE) de moins de trois mois.</div>'
        . '<div><i></i>Liste des membres du bureau : nom, prénom, fonction, datée et signée.</div>'
        . '<div><i></i>RIB original au format IBAN/BIC, au nom de l\'association.</div>'
        . '<div><i></i>Bilan et compte de résultat du dernier exercice clos, approuvés en assemblée générale.</div>'
        . '<div><i></i>Budget prévisionnel général de l\'association pour l\'année en cours.</div>'
        . '<div><i></i>Note de cadrage du projet, avec les sections techniques de ' . ddoc_e($d['brand']) . '.</div>'
        . '<div><i></i>Budget prévisionnel du projet et plan de financement.</div>'
        . '<div><i></i>Lettre de demande signée par le président.</div>'
        . '<div><i></i>Devis des prestataires consultés, pour justifier les montants.</div>';

    $body = ddoc_brand($d, 'Compte Tiers<br>et dépôt en ligne')
        . '<div class="meta">subventions.grandlyon.com &middot; préparation du dépôt &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Le compte Tiers d\'abord, les pièces ensuite.</h1>'
        . '<p class="lead">Sans compte Tiers validé, la Métropole ne peut ni instruire le dossier ni effectuer le virement, même si le projet est retenu. La création du compte précède donc la demande, et la validation de la fiche Tiers dépend d\'un RIB et d\'une attestation SIRENE cohérents avec les statuts.</p>'
        . '<h2>Créer le compte Tiers, en cinq étapes</h2>'
        . '<table><thead><tr><th>Étape</th><th>Ce qui se passe</th><th>Ce qu\'il faut avoir sous la main</th></tr></thead><tbody>'
        . '<tr><td>1. Créer l\'Espace Demandeur</td><td>« S\'inscrire » sur le portail : les données légales de la structure sont reprises de la base INSEE.</td><td>SIRET de l\'association, à 14 chiffres.</td></tr>'
        . '<tr><td>2. Renseigner les contacts</td><td>L\'adresse e-mail officielle reçoit les notifications d\'instruction ; le référent du projet est joignable.</td><td>Adresse e-mail de l\'association et coordonnées du référent.</td></tr>'
        . '<tr><td>3. Faire valider la fiche Tiers</td><td>Le service financier contrôle le RIB et l\'attestation SIRENE avant d\'activer le paiement.</td><td>RIB original et attestation SIRENE, mêmes intitulés.</td></tr>'
        . '<tr><td>4. Choisir le guichet</td><td>Sélectionner la thématique de l\'appel à projets : développement économique, commerce de proximité ou transition numérique.</td><td>Le règlement de l\'aide visée.</td></tr>'
        . '<tr><td>5. Déposer la demande</td><td>Le compte étant actif, téléverser les pièces préparées et valider l\'envoi.</td><td>Toutes les pièces en PDF ci-dessous.</td></tr>'
        . '</tbody></table>'
        . '<h2>Avant de vous connecter : la liste de contrôle</h2>'
        . '<div class="checklist">' . $ci . '</div>'
        . '<div class="warn"><b>Deux motifs de blocage fréquents :</b> une attestation SIRENE de plus de trois mois, et un intitulé de RIB qui ne correspond pas au caractère près à celui du SIRET. Le premier se corrige en trois minutes sur le site de l\'INSEE ; le second demande un passage à la banque, donc il se vérifie d\'abord.</div>'
        . '<p class="note">Toutes les pièces se téléversent en PDF lisibles et non protégés, un fichier par pièce, nommé sans accent ni espace.</p>';

    return ddoc_sheet($d, $body, 'Compte Tiers et dépôt', $page, $total);
}

function ddoc_doc_pieces(array $d, int $page, int $total): string
{
    $body = ddoc_brand($d, 'Pièces du dépôt<br>subventions.grandlyon.com')
        . '<div class="meta">Liste de contrôle avant téléversement &middot; ' . ddoc_e($d['letter_date']) . '</div>'
        . '<h1>Le dossier complet, pièce par pièce.</h1>'
        . '<p class="lead">L\'instruction administrative rejette une pièce isolée, pas le projet : une identité qui ne correspond pas entre le SIRET, le RIB et les statuts suffit à bloquer le versement. Cette liste se coche avant le téléversement.</p>'
        . '<h2>Administratif et juridique</h2><table><thead><tr><th>Pièce</th><th>Fournie par</th><th>Point de vigilance</th></tr></thead><tbody>'
        . '<tr><td>Statuts certifiés conformes, datés et signés</td><td>Association</td><td>Version à jour de la dernière assemblée générale</td></tr>'
        . '<tr><td>Récépissé de déclaration en préfecture</td><td>Association</td><td>Récépissé initial ou dernier récépissé de modification</td></tr>'
        . '<tr><td>Avis de situation SIRENE (INSEE)</td><td>Association</td><td>SIRET à 14 chiffres, code NAF/APE à jour, document de moins de 3 mois</td></tr>'
        . '<tr><td>Liste des membres du bureau : nom, prénom, fonction</td><td>Association</td><td>Datée et signée par le président</td></tr>'
        . '<tr><td>RIB original au format IBAN/BIC</td><td>Association</td><td>Intitulé du compte strictement identique à celui du SIRET et des statuts</td></tr>'
        . '</tbody></table>'
        . '<h2>Financier et comptable</h2><table><thead><tr><th>Pièce</th><th>Fournie par</th><th>Point de vigilance</th></tr></thead><tbody>'
        . '<tr><td>Comptes approuvés du dernier exercice clos</td><td>Association</td><td>Bilan et compte de résultat validés en AG, signés</td></tr>'
        . '<tr><td>Rapport moral et d\'activité</td><td>Association</td><td>Actions réellement menées l\'année précédente</td></tr>'
        . '<tr><td>Budget prévisionnel global de l\'association</td><td>Association</td><td>Équilibré en dépenses et en recettes</td></tr>'
        . '</tbody></table>'
        . '<h2>Projet numérique</h2><table><thead><tr><th>Pièce</th><th>Fournie par</th><th>Point de vigilance</th></tr></thead><tbody>'
        . '<tr><td>Budget prévisionnel détaillé et plan de financement</td><td>' . ddoc_e($d['brand']) . ' et association</td><td>Montants HT, part autofinancée et autres aides sollicitées</td></tr>'
        . '<tr><td>Note de cadrage du projet</td><td>Association, sections techniques de ' . ddoc_e($d['brand']) . '</td><td>Contexte, objectifs, bénéficiaires, calendrier</td></tr>'
        . '<tr><td>Devis des prestataires consultés</td><td>' . ddoc_e($d['brand']) . ' et candidats écartés</td><td>Conserver tous les devis : preuve de mise en concurrence</td></tr>'
        . '<tr><td>Attestation de formation et captures livrées</td><td>' . ddoc_e($d['brand']) . '</td><td>Après la session, jamais avant</td></tr>'
        . '<tr><td>Facture conforme et accord de sous-traitance RGPD</td><td>' . ddoc_e($d['brand']) . '</td><td>Facture après la prestation ; le devis signé la précède</td></tr>'
        . '<tr><td>Extrait SIRET du prestataire</td><td>' . ddoc_e($d['brand']) . '</td><td>À joindre même si l\'association porte le dossier</td></tr>'
        . '</tbody></table>'
        . '<div class="warn"><b>Au moment du téléversement :</b> des PDF lisibles et non protégés, un fichier par pièce, nommé sans accent ni espace. Vérifier que l\'intitulé du SIRET, celui du RIB et celui des statuts sont identiques au caractère près : c\'est le motif de rejet le plus fréquent.</div>';

    return ddoc_sheet($d, $body, 'Pièces du dépôt', $page, $total);
}

/* ------------------------------------------------------------------ assemblage */

/** Documents demandés, dans l'ordre d'impression. */
function ddoc_docs(array $d): array
{
    $docs = [
        ['key' => 'lettre', 'title' => 'Lettre d\'accompagnement', 'file' => '01-lettre-accompagnement', 'fn' => 'ddoc_doc_letter'],
        ['key' => 'note', 'title' => 'Note de cadrage', 'file' => '02-note-de-cadrage', 'fn' => 'ddoc_doc_note'],
        ['key' => 'budget', 'title' => 'Budget prévisionnel et plan de financement', 'file' => '03-budget-previsionnel', 'fn' => 'ddoc_doc_budget'],
    ];
    if ($d['include_reponse']) $docs[] = ['key' => 'reponse', 'title' => 'Réponse à cahier des charges', 'file' => '04-reponse-cahier-des-charges', 'fn' => 'ddoc_doc_reponse'];
    if ($d['include_grille']) $docs[] = ['key' => 'grille', 'title' => 'Grille d\'évaluation du prestataire', 'file' => '05-grille-evaluation', 'fn' => 'ddoc_doc_grille'];
    $docs[] = ['key' => 'calendrier', 'title' => 'Calendrier de l\'instruction', 'file' => '06-calendrier-instruction', 'fn' => 'ddoc_doc_calendrier'];
    if ($d['include_pieces']) {
        $docs[] = ['key' => 'compte', 'title' => 'Compte Tiers et dépôt en ligne', 'file' => '07-compte-tiers', 'fn' => 'ddoc_doc_compte'];
        $docs[] = ['key' => 'pieces', 'title' => 'Pièces à joindre au dépôt', 'file' => '08-pieces-a-joindre', 'fn' => 'ddoc_doc_pieces'];
    }
    return $docs;
}

/** Dossier complet : page de garde + documents, numérotés. */
function ddoc_complet(array $d, string $toolbar = '', string $notice = ''): string
{
    $docs = ddoc_docs($d);
    $total = count($docs) + 1;
    $html = ddoc_doc_cover($d, $docs, 1, $total);
    $page = 2;
    foreach ($docs as $doc) {
        $html .= ($doc['fn'])($d, $page, $total);
        $page++;
    }
    return ddoc_page($d['project_name'] . ' — dossier de subvention', $notice . $html, $toolbar);
}

function ddoc_single(array $d, string $key): string
{
    foreach (ddoc_docs($d) as $doc) {
        if ($doc['key'] === $key) {
            return ddoc_page($doc['title'] . ' — ' . $d['project_name'], ($doc['fn'])($d, 1, 1));
        }
    }
    return '';
}

function ddoc_csv(array $d): string
{
    $lines = [['Poste', 'Montant HT', 'Eligible', 'Finance par']];
    $lines[] = ['Mise en place collective (parametrage, import, domaine, formation)', number_format($d['setup'], 2, ',', ''), 'Oui', 'Subvention et autofinancement'];
    if ($d['subs_year'] > 0) {
        $lines[] = ['Abonnements des boutiques (' . $d['shops'] . ' x ' . number_format($d['sub_monthly'], 2, ',', '') . ' EUR/mois x 12)', number_format($d['subs_year'], 2, ',', ''), 'Non', 'Cotisations'];
    }
    $lines[] = ['Communication de lancement', '0', 'Non', 'Association'];
    $lines[] = ['Materiel', '0', 'Non', 'Association'];
    $lines[] = ['Total depenses eligibles', number_format($d['setup'], 2, ',', ''), '', ''];
    $lines[] = ['Subvention demandee (' . number_format($d['rate'], 1, ',', '') . ' %)', number_format($d['subsidy'], 2, ',', ''), '', 'Sollicitee'];
    $lines[] = ['Autofinancement association', number_format($d['selffund'], 2, ',', ''), '', 'Acquis ou a confirmer'];
    $lines[] = ['Autofinancement par adherent (' . $d['members'] . ')', number_format($d['per_member'], 2, ',', ''), '', ''];
    $out = "\xEF\xBB\xBF";
    foreach ($lines as $cols) $out .= implode(';', array_map(static fn ($c) => '"' . str_replace('"', '""', (string) $c) . '"', $cols)) . "\r\n";
    return $out;
}

function ddoc_readme(array $d): string
{
    $brand = $d['brand'];
    return "DOSSIER DE SUBVENTION — {$d['project_name']}
{$d['assoc_name']} — " . ddoc_e($d['letter_date']) . "

CONTENU DE L'ARCHIVE
- dossier-complet.html : page de garde + tous les documents, dans l'ordre de dépôt.
- 01-lettre-accompagnement.html : à signer par {$d['rep_role']} de l'association.
- 02-note-de-cadrage.html : sections techniques déjà rédigées, contexte et gouvernance à compléter.
- 03-budget-previsionnel.html : dépenses, plan de financement, ce qui reste hors subvention.
- 03-budget-previsionnel.csv : mêmes chiffres pour le tableur (séparateur point-virgule, Excel).
- 04-reponse-cahier-des-charges.html : réponse du prestataire, si l'option a été retenue.
- 05-grille-evaluation.html : barème sur 100 points, si l'option a été retenue.
- 06-calendrier-instruction.html : du dépôt au virement, avec la date de dépôt conseillée.
- 07-compte-tiers.html : création du compte Tiers et liste de contrôle avant téléversement.
- 08-pieces-a-joindre.html : pièces administratives, comptables et projet, avec les points de vigilance.

COMMENT OBTENIR DES PDF
1. Ouvrir le fichier HTML dans Chrome, Edge ou Firefox.
2. Imprimer (Ctrl/Cmd + P), destination « Enregistrer au format PDF ».
3. Laisser le format A4, marges par défaut, cocher « Graphiques d'arrière-plan ».
4. Enregistrer. Le fichier est prêt à téléverser sur subventions.grandlyon.com.

À VÉRIFIER AVANT DÉPÔT
- Le SIRET, le RIB et les statuts portent le même intitulé, au caractère près.
- Aucune facture n'est antérieure au dépôt du dossier.
- Les champs marqués « à compléter » sont remplis (communication, contact du prestataire).
- Les montants du courrier, de la note de cadrage et du budget sont identiques.

CHIFFRES DU DOSSIER
Mise en place HT : " . ddoc_eur($d['setup']) . "
Subvention demandée ({$d['rate']} %) : " . ddoc_eur($d['subsidy']) . "
Autofinancement : " . ddoc_eur($d['selffund']) . " (" . ddoc_eur($d['per_member'], 2) . " par adhérent à {$d['members']})
Abonnements annuels (non éligibles) : " . ddoc_eur($d['subs_year']) . "

TVA non applicable, article 293 B du CGI.
Généré par {$brand} — " . ddoc_today_fr() . "
";
}

/** Construit l'archive ZIP dans un fichier temporaire et rend son chemin. */
function ddoc_zip(array $d, string $tmpDir = ''): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension ZIP indisponible sur ce serveur.');
    }
    $path = ($tmpDir !== '' ? rtrim($tmpDir, '/') : sys_get_temp_dir()) . '/dossier-subvention-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de créer l\'archive.');
    }
    $zip->addFromString('dossier-complet.html', ddoc_complet($d));
    foreach (ddoc_docs($d) as $doc) {
        $zip->addFromString($doc['file'] . '.html', ddoc_single($d, $doc['key']));
    }
    $zip->addFromString('03-budget-previsionnel.csv', ddoc_csv($d));
    $zip->addFromString('LISEZ-MOI.txt', ddoc_readme($d));
    $zip->close();
    return $path;
}

function ddoc_slug(string $text): string
{
    $s = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', (string) $s));
    $s = trim($s, '-');
    return $s !== '' ? mb_substr($s, 0, 60) : 'association';
}

/** Champs cachés pour renvoyer le formulaire tel quel (aperçu → ZIP). */
function ddoc_hidden_inputs(array $p): string
{
    $out = '';
    foreach ($p as $k => $v) {
        if (in_array($k, ['action', 'PHPSESSID'], true)) continue;
        $out .= '<input type="hidden" name="' . ddoc_e($k) . '" value="' . ddoc_e(is_array($v) ? '' : (string) $v) . '">';
    }
    return $out;
}
