<?php
// Plan d'implantation d'une galerie : dessin des commerces (repères, zones, tracés) sur un fond IGN (plan ou orthophoto) ou sans fond,
// avec un catalogue de surfaces, des emplacements numérotés et la division de zones. La localisation de la marchandise se règle dans
// l'espace de la galerie (/portail/galeries.php) et dans celui de la boutique (/admin/ma-galerie.php).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-map.php';

$self = '/portail/galerie-plan.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
if (!gm_enabled($gaGallery, 'carte')) {
    ga_head('Plan des commerces');
    ?><section class="gcard"><h2>Option « Carte interactive » non active</h2>
      <p>Le plan interactif (fond IGN, zones et emplacements) est une option de votre type de galerie. Activez-la depuis vos options pour retrouver l'éditeur de plan ; les exposants continuent de réserver sur la liste numérotée de l'événement.</p>
      <p><a class="btn btn-primary" href="/portail/galerie-modules.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Gérer les options de la galerie</a></p></section><?php
    ga_foot();
    exit;
}
$memberKeys = array_map('gallery_member_key', $gaGallery['members']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['action'] ?? '') === 'bg') {   // import d'un fond de plan : stocké côté serveur, calé et enregistré ensuite avec le plan
        header('Content-Type: application/json; charset=utf-8');
        try {
            $path = gallery_image_store($_FILES['bg'] ?? ['error' => UPLOAD_ERR_NO_FILE], $gaSlug, 'fond', 3000);
            echo json_encode(['ok' => true, 'path' => $path], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
    if ((string) ($_POST['action'] ?? '') === 'stylize') {   // fond stylisé : capture WMS de la vue, retouche Gemini image, sauvé comme fond importé
        header('Content-Type: application/json; charset=utf-8');
        require_once PORTAIL_ROOT . '/includes/gallery-ai-map.php';
        @set_time_limit(90);
        $view = json_decode((string) ($_POST['view'] ?? ''), true);
        try {
            $out = gallery_ai_map_stylize(is_array($view) ? $view : [], (string) ($_POST['base'] ?? 'osm'),
                (string) ($_POST['style'] ?? 'aquarelle'), $gaSlug);
        } catch (Throwable $e) {
            $out = ['ok' => false, 'error' => $e->getMessage()];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ((string) ($_POST['action'] ?? '') === 'export') {   // impression haute résolution : PDF (300 dpi) ou PSD (InDesign/Photoshop)
        require_once PORTAIL_ROOT . '/includes/gallery-plan-export.php';
        @set_time_limit(120);
        $raw = json_decode((string) ($_POST['map'] ?? ''), true);
        $expMap = is_array($raw) ? gallery_map_normalize($raw, $memberKeys, gt_locations($gaGallery)) : $gaGallery['map'];
        $pages = gpx_pages();
        $pageKey = (string) ($_POST['page'] ?? 'a4');
        if (!is_array($expMap) || !isset($pages[$pageKey])) { http_response_code(400); exit; }
        try {
            $img = gpx_render($gaGallery, $expMap, $pageKey);
            $fileSlug = preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $gaSlug));
            if ((string) ($_POST['format'] ?? 'pdf') === 'psd') {
                $bin = gpx_psd($img, GPX_DPI);
                header('Content-Type: image/vnd.adobe.photoshop');
                header('Content-Disposition: attachment; filename="' . $fileSlug . '-plan-' . $pageKey . '.psd"');
            } else {
                [$pxW, $pxH] = $pages[$pageKey]['px'];
                $bin = gpx_pdf(gpx_jpeg($img), $pxW, $pxH, $pages[$pageKey]['pt']);
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $fileSlug . '-plan-' . $pageKey . '.pdf"');
            }
            header('Content-Length: ' . strlen($bin));
            echo $bin;
        } catch (Throwable $e) {
            http_response_code(500);
        }
        exit;
    }
    if ((string) ($_POST['action'] ?? '') === 'discover') {   // analyse du site : zones proposées d'après la BD TOPO IGN
        header('Content-Type: application/json; charset=utf-8');
        require_once PORTAIL_ROOT . '/includes/gallery-discovery.php';
        @set_time_limit(55);
        $territory = json_decode((string) ($_POST['territory'] ?? ''), true);
        try {
            $out = gallery_discover_site(is_array($territory) ? $territory : [], [
                'min_area' => (float) ($_POST['min_area'] ?? 25),
                'roads' => !empty($_POST['roads']), 'parking' => !empty($_POST['parking']), 'services' => !empty($_POST['services']),
            ]);
            if (!empty($_POST['ai']) && !empty($out['ok'])) {   // affinage facultatif : orthophoto + zones détectées envoyées au modèle
                require_once PORTAIL_ROOT . '/includes/gallery-ai-zoning.php';
                $out = gallery_ai_zoning_assist(is_array($territory) ? $territory : [], $out);
            }
        } catch (Throwable $e) {
            $out = ['ok' => false, 'error' => 'Analyse impossible : ' . $e->getMessage()];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ((string) ($_POST['action'] ?? '') === 'save') {
        $oldBg = (string) ($gaGallery['map']['background']['path'] ?? '');
        $raw = json_decode((string) ($_POST['map'] ?? ''), true);
        // Le plan ne porte plus la localisation des marchandises : elle est gérée dans l'espace de la galerie et celui de la boutique.
        // On réinjecte la valeur enregistrée pour que l'enregistrement du plan ne l'efface pas.
        if (is_array($raw)) $raw['places'] = (array) ($gaGallery['map']['places'] ?? []);
        $gaGallery['map'] = is_array($raw) ? gallery_map_normalize($raw, $memberKeys, gt_locations($gaGallery)) : null;
        $newBg = (string) ($gaGallery['map']['background']['path'] ?? '');
        if (gallery_save($gaGallery)) {
            if ($oldBg !== '' && $oldBg !== $newBg) gallery_image_delete($oldBg);   // le fond remplacé (ou retiré) est supprimé du serveur
            portail_flash($gaGallery['map'] ? 'Plan enregistré (' . count($gaGallery['map']['items']) . ' élément' . (count($gaGallery['map']['items']) > 1 ? 's' : '') . ').' : 'Plan vidé.');
        }
        else portail_flash("Impossible d'enregistrer le plan (droits du dossier data).", 'error');
    }
    header('Location: ' . $self);
    exit;
}

// Noms des commerces pour le menu « Commerce lié » (catalogue en cache ; à défaut, identifiant ou nom saisi).
$names = [];
foreach ($gaGallery['members'] as $m) $names[gallery_member_key($m)] = $m['type'] === 'local' ? $m['slug'] : ($m['name'] !== '' ? $m['name'] : $m['url']);
try {
    foreach (gallery_catalogue($gaGallery, $gaOrigin)['shops'] as $k => $s) if (isset($names[$k]) && $s['name'] !== '') $names[$k] = $s['name'];
} catch (Throwable $e) {
}
$map = $gaGallery['map'];
// Adresse du lieu saisie à la création de l'événement : préremplit la recherche d'adresse du plan.
$addrDefault = '';
if (is_array($gaGallery['event'] ?? null)) {
    $ev = $gaGallery['event'];
    $line1 = trim((string) ($ev['address'] ?? '')) ?: trim((string) ($ev['place'] ?? ''));
    $line2 = trim(trim((string) ($ev['postcode'] ?? '')) . ' ' . trim((string) ($ev['city'] ?? '')));
    $addrDefault = trim($line1 . ($line1 !== '' && $line2 !== '' ? ', ' : '') . $line2);
}
// Types de lieux du type de galerie, puis les anciens types de zone (plans déjà dessinés) sans doublon.
$zoneTypes = [];
$locationDefaults = [];
foreach (gt_locations($gaGallery) as $loc) {
    $zoneTypes[(string) $loc['key']] = (string) $loc['label'];
    $locationDefaults[(string) $loc['key']] = ['unit' => (string) $loc['unit'],
        'price' => (int) ($loc['price_cents'] ?? 0) > 0 ? number_format($loc['price_cents'] / 100, 2, ',', '') : ''];
}
foreach (GALLERY_ZONE_TYPES as $zk => $zl) if (!isset($zoneTypes[$zk])) $zoneTypes[$zk] = $zl;
$payload = ['map' => $map, 'shops' => $names, 'zoneTypes' => $zoneTypes, 'locationDefaults' => $locationDefaults, 'features' => gt_of($gaGallery)['features'],
    'usages' => GALLERY_USAGES, 'flows' => GALLERY_FLOWS, 'aiMap' => portail_gemini_key() !== '',
    'tiles' => ['osm' => GALLERY_MAP_TILE_PLAN, 'sat' => GALLERY_MAP_TILE_SAT]];
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$priceUnits = GALLERY_PRICE_UNITS;
$publicUrl = '/galerie/' . $gaGallery['slug'] . '/' . ($gaGallery['published'] ? '' : '?apercu=' . gallery_preview_token($gaGallery)) . '#plan-commerces';
ga_head('Plan des commerces');
?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.css">
    <style>
      #planWrap { position: relative; isolation: isolate; overflow: hidden; height: 64vh; min-height: 400px; border: 1px solid var(--line); border-radius: 10px; background: #e9e5dd; }
      #plan { width: 100%; height: 100%; }
      #plan.rotated { position: absolute; left: 50%; top: 50%; border: 0; border-radius: 0; }
      #planUi { position: absolute; inset: 0; z-index: 800; pointer-events: none; }
      #planUi > .leaflet-control-container { pointer-events: none; }
      #planUi .leaflet-control { pointer-events: auto; }
      #plan.rotated .leaflet-tooltip.plan-label, #plan.rotated .plan-zone { rotate: calc(-1 * var(--bearing, 0deg)); }
      #planCard [hidden] { display: none !important; }
      #fsTitleBar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
      #planTitle { display: flex; align-items: center; gap: 9px; margin: 0; padding-left: 34px; min-width: 0; }
      #planTitle svg { width: 22px; height: 22px; flex: none; margin-left: -34px; color: var(--accent); }
      .plan-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; }
      .btn.icon { width: 38px; height: 38px; padding: 0; flex: none; }
      .btn.icon svg { width: 18px; height: 18px; }
      .btn.icon[aria-pressed="true"] { border-color: var(--accent); color: var(--accent); background: color-mix(in srgb, var(--accent) 10%, var(--panel)); }
      #addrBar { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 6px; align-items: center; margin: 0 0 8px; }
      #addrRow { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-width: 0; }
      #addrBar.collapsed #addrRow { display: none; }
      #addrLoc svg, #addrGo svg { width: 16px; height: 16px; }
      #addrToggle { justify-self: end; }
      #addrToggle svg { width: 16px; height: 16px; flex: none; transition: transform .18s ease; }
      #addrBar.collapsed #addrToggle svg { transform: rotate(-90deg); }
      #addrBar.collapsed #addrToggle { width: auto; max-width: 100%; padding: 0 10px; }
      #addrEcho { display: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .82rem; }
      #addrBar.collapsed #addrEcho { display: inline-block; }
      #addrMsg { flex: 1 1 140px; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      #plan.vertexing { cursor: crosshair; }
      #mapBar { display: grid; gap: 8px; min-width: 0; position: relative; z-index: 30; }
      #mapBar.collapsed > #mapBarBody { display: none !important; }
      #mapBarBody { display: grid; gap: 8px; }
      #advancedTools { border: 1px dashed var(--line); border-radius: 8px; padding: 8px 10px; }
      #advancedTools > summary { cursor: pointer; font-weight: 600; font-size: .85rem; }
      #advancedTools[open] > .row { margin-top: 8px; }
      .addrwrap { position: relative; flex: 1 1 320px; min-width: 0; }
      #addr { width: 100%; }
      #addrList { position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 1500; margin: 0; padding: 4px; list-style: none; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; box-shadow: 0 12px 30px rgba(0,0,0,.18); max-height: 300px; overflow: auto; }
      #addrList li { display: grid; gap: 1px; padding: 7px 9px; border-radius: 7px; cursor: pointer; font-size: .86rem; }
      #addrList li .albl { font-weight: 600; }
      #addrList li .actx { color: var(--muted); font-size: .76rem; }
      #addrList li .atyp { font: 700 .62rem/1.4 var(--font-mono, ui-monospace, monospace); letter-spacing: .05em; text-transform: uppercase; color: var(--accent); }
      #addrList li[aria-selected="true"], #addrList li:hover { background: color-mix(in srgb, var(--accent) 12%, transparent); }
      #splitBox [hidden] { display: none !important; } #splitBox input[type=checkbox] { width: auto; flex: none; }
      .simscen th { text-align: left; font-weight: 600; white-space: nowrap; } .simscen td { padding: 4px; } .simscen input { width: 100%; max-width: 110px; min-width: 0; }
      .simwrap { max-height: 300px; overflow: auto; margin: 6px 0; } #aidTable tr.best td { background: rgba(47,107,79,.12); font-weight: 600; } #aidTable small { font-weight: 400; opacity: .75; }
      .simdet { margin-top: 8px; } .simdet th:first-child { text-align: left; } .simdet tr.tot th, .simdet tr.tot td { border-top: 2px solid var(--line); font-weight: 700; } .simdet .pos { color: #1b7a43; } .simdet .neg { color: #b42318; }
      #plan.nofond { background-color: #f6f3ec; background-image: linear-gradient(rgba(0,0,0,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(0,0,0,.06) 1px, transparent 1px); background-size: 40px 40px; }
      .plan-label { background: rgba(255,255,255,.92); border: 1px solid rgba(0,0,0,.25); border-radius: 4px; padding: 1px 5px; font: 600 11px/1.3 system-ui, sans-serif; color: #222; box-shadow: none; }
      .plan-dot { display: block; width: 18px; height: 18px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.45), 0 2px 5px rgba(0,0,0,.35); }
      .plan-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); align-items: end; }
      .plan-grid[hidden], .plan-sub[hidden] { display: none; } .plan-grid input[type=color], .gcard input[type=color] { width: 100%; height: 38px; padding: 2px; }
      /* Fiche de l'élément : panneau flottant à droite de la carte, pas sous la carte */
      #sel { justify-self: end; width: min(660px, 100%); grid-template-columns: repeat(2, minmax(0, 1fr)); align-content: start; padding: 12px; background: var(--panel); border: 1px solid var(--line); border-radius: 12px; box-shadow: var(--shadow); }
      #sel .selhead { grid-column: 1/-1; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
      #sel .selhead strong { font-size: .95rem; }
      #sel #priceBox, #sel #rotBox, #sel #terrBox, #sel #subBox, #sel #splitBox, #sel #selInfo { grid-column: 1/-1; }
      @media (max-width: 700px) { #sel { grid-template-columns: 1fr; width: 100%; } }
      #selClose { border: 0; background: transparent; color: inherit; font-size: 1rem; line-height: 1; cursor: pointer; padding: 3px 7px; border-radius: 6px; }
      #selClose:hover { background: rgba(0,0,0,.1); }
      .plan-sub { border: 1px dashed var(--line); border-radius: 8px; padding: 10px; display: grid; gap: 8px; }
      .help { border: 1px solid var(--line); border-radius: 10px; padding: 12px 16px; background: var(--panel); }
      .help summary { cursor: pointer; font-weight: 700; } .help ol, .help ul { margin: 8px 0 0; padding-left: 20px; display: grid; gap: 4px; font-size: .9rem; }
      .tip { font-size: .8rem; opacity: .75; margin: 0; }
      .mini { padding: 3px 8px; font-size: .8rem; } table.t input[type=text], table.t input[type=number] { min-width: 0; width: 100%; padding: 5px 7px; }
      .listplan { max-height: 240px; overflow: auto; } .listplan tr { cursor: pointer; } .listplan tr:hover { background: rgba(0,0,0,.05); }
      .zdwrap { max-height: 340px; overflow: auto; margin: 6px 0 10px; border: 1px solid var(--line); border-radius: 8px; }
      .zdwrap thead th { position: sticky; top: 0; z-index: 1; background: var(--panel); }
      .zdactions { position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px; margin: 0; background: var(--panel); border-top: 1px solid var(--line); box-shadow: 0 -6px 14px rgba(0,0,0,.08); }
      .zdwrap td { padding: 3px 4px; } .zdwrap td input[type=text], .zdwrap td select { min-width: 0; width: 100%; padding: 4px 6px; }
      .zdwrap td:first-child, .zdwrap th:first-child { width: 28px; text-align: center; }
      .zdlist { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 6px; font-size: .9rem; }
      .zdlist li { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
      .zdcell { display: flex; align-items: center; gap: 6px; }
      .zdcell input[type=text] { flex: 1; min-width: 0; }
      .zdai { flex: none; font: 700 10px/1 system-ui, sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #1d4ed8; background: rgba(29,78,216,.1); border: 1px solid rgba(29,78,216,.35); border-radius: 4px; padding: 3px 5px; cursor: help; }
      .zdai.extra { color: #7c3aed; background: rgba(124,58,237,.1); border-color: rgba(124,58,237,.35); }
      .plan-arrow { display: block; width: 18px; height: 18px; transform: translate(-50%, -50%); }
      .plan-arrow i { display: block; width: 18px; height: 18px; font: 800 13px/18px system-ui, sans-serif; text-align: center; color: #1d4ed8; background: rgba(255,255,255,.88); border: 1px solid rgba(29,78,216,.45); border-radius: 50%; transform-origin: 50% 50%; }
      #plan.rotated .plan-arrow { rotate: calc(-1 * var(--bearing, 0deg)); }
      body.placing #plan { cursor: crosshair; }
      .plan-zone { display: inline-block; transform: translate(-50%, -100%); white-space: nowrap; font: 700 11px/1.3 system-ui, sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #222; background: rgba(255,255,255,.88); border: 1px solid rgba(0,0,0,.3); border-radius: 4px; padding: 2px 7px; pointer-events: none; }
      .plan-zone small { font-weight: 500; opacity: .7; margin-right: 4px; }
      #plan.hide-labels .plan-zone { opacity: 0; transition: opacity .12s ease; }
      #plan.hide-labels .plan-zone.show { opacity: 1; }
      /* les infobulles Leaflet portent une opacité en style inline : il faut la forcer */
      #plan.hide-labels .plan-label { opacity: 0 !important; transition: opacity .12s ease; }
      #plan.hide-labels .plan-label.show { opacity: .9 !important; }
      /* Plein écran : la carte occupe tout l'écran, les modules de gestion flottent en panneaux repliables */
      #fsLayout, #fsMain { display: grid; gap: 10px; min-width: 0; } #fsSide[hidden] { display: none; }
      #planCard.fs { position: fixed; inset: 0; z-index: 9999; margin: 0; border-radius: 0; border: 0; overflow: hidden; padding: 0; display: block; background: var(--bg); }
      #planCard.fs #fsLayout { display: block; height: 100%; }
      #planCard.fs #fsMain { position: absolute; inset: 0; display: block; }
      #planCard.fs #planWrap { position: absolute; inset: 0; height: auto; min-height: 0; border: 0; border-radius: 0; }
      #planCard.fs #fsTitleBar { position: absolute; top: 0; left: 0; right: 0; z-index: 1300; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 14px 8px 62px; background: linear-gradient(rgba(18,18,18,.62), rgba(18,18,18,0)); pointer-events: none; }
      #planCard.fs #fsTitleBar h2 { margin: 0; color: #fff; font-size: 1.05rem; text-shadow: 0 1px 3px rgba(0,0,0,.55); }
      #planCard.fs #fsTitleBar .btn { pointer-events: auto; }
      #planCard.fs #addrBar { position: absolute; top: 56px; left: 56px; right: 384px; z-index: 1200; width: auto; margin: 0; background: var(--panel); padding: 6px 8px; border-radius: 12px; box-shadow: 0 8px 26px rgba(0,0,0,.22); }
      #planCard.fs #mapBar { position: absolute; top: 112px; left: 56px; right: 384px; z-index: 1200; width: auto; background: var(--panel); padding: 8px 10px; border-radius: 12px; box-shadow: 0 8px 26px rgba(0,0,0,.22); }
      #planCard.fs #fsSide { position: absolute; top: 58px; right: 12px; bottom: 12px; width: 360px; z-index: 1250; overflow: auto; display: grid; grid-auto-rows: max-content; gap: 10px; align-content: start; }
      #planCard.fs #fsSide > .gcard, #planCard.fs #fsSide > #saveBar, #planCard.fs #fsSide > #sel { background: var(--panel); border-radius: 12px; box-shadow: 0 8px 26px rgba(0,0,0,.22); padding: 10px 12px; margin: 0; }
      #planCard.fs #fsSide .gcard { min-width: 0; overflow-x: auto; }
      /* Blocs repliables : les quatre cartes de gestion (hors de #planCard en page normale, dans #fsSide en plein écran) */
      #catCard > h2, #numCard > h2, #bookCard > h2, #zoneCard > h2 { cursor: pointer; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
      #catCard > h2::after, #numCard > h2::after, #bookCard > h2::after, #zoneCard > h2::after { content: '▾'; font-size: 1.35rem; line-height: 1; opacity: .75; flex: none; transition: transform .18s ease; }
      #catCard.collapsed > h2::after, #numCard.collapsed > h2::after, #bookCard.collapsed > h2::after, #zoneCard.collapsed > h2::after { content: '▾'; transform: rotate(-90deg); }
      #catCard.collapsed > :not(h2), #numCard.collapsed > :not(h2), #bookCard.collapsed > :not(h2), #zoneCard.collapsed > :not(h2) { display: none !important; }
      #planCard.fs #fsSide .gcard > h2 { margin: 0; font-size: 1rem; }
      #saveBar #count { margin: 0 0 0 auto; }
      #planCard.fs #saveBar { position: sticky; top: 0; z-index: 3; }
      #planCard.fs #fsSide > #sel { width: auto; max-height: none; grid-template-columns: 1fr; overflow: visible; }
      /* le catalogue de surfaces passe en fiches empilées : plus rien ne déborde sur la carte */
      #fsSide table.t, #fsSide table.t tbody { display: block; width: 100%; } #fsSide table.t thead { display: none; }
      #fsSide #catBody tr { display: grid; grid-template-columns: 1fr 1fr 64px 1fr; gap: 6px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--line); }
      #fsSide #catBody td { display: block; border: 0; padding: 0; min-width: 0; } #fsSide #catBody td:first-child, #fsSide #catBody td:last-child { grid-column: 1 / -1; }
      #fsSide #catBody td:nth-child(2)::before { content: 'Larg. (m)'; display: block; font-size: .7rem; opacity: .7; } #fsSide #catBody td:nth-child(3)::before { content: 'Prof. (m)'; display: block; font-size: .7rem; opacity: .7; }
      #fsSide #catBody td.num { text-align: right; }
      #fsSide .listplan table.t { display: table; } #fsSide .listplan table.t tbody { display: table-row-group; } #fsSide .listplan table.t thead { display: table-header-group; }
      @media (max-width: 900px) {
        #planCard.fs #addrBar { left: 8px; right: 8px; }
        #planCard.fs #mapBar { left: 8px; right: 8px; }
        #planCard.fs #fsSide { left: 8px; right: 8px; top: auto; bottom: 8px; width: auto; max-height: 44vh; }
      }
    </style>

    <details class="help">
      <summary>Aide : dessiner le plan du site pas à pas</summary>
      <ol>
        <li><strong>Cadrez le lieu</strong> : tapez l'adresse dans la recherche puis « Centrer la carte », ou « Ma position » pour utiliser la position de l'appareil (le navigateur demande l'autorisation). Choisissez le fond (plan ou satellite) ou cochez <em>Masquer le fond de carte</em> pour ne garder que votre dessin sur une grille.</li>
        <li><strong>Définissez vos surfaces</strong> dans le catalogue (ex. « Stand 3 × 2 m ») : une fois créées, un clic sur « Poser » dessine l'emplacement aux bonnes dimensions, à l'échelle de la carte (réglez l'angle pour l'aligner sur l'allée).</li>
        <li><strong>Tournez un emplacement</strong> : sélectionnez-le puis ↻ / ↺ (1°, 15°, 90° ou un angle précis), ou prenez l'outil de rotation à gauche de la carte.</li>
        <li><strong>Dessinez à la main</strong> avec les outils à gauche de la carte : repère (entrée, caisse, toilettes), rectangle ou zone libre (halle, cour), tracé (allée). Pour ajuster une forme, sélectionnez-la puis « + Ajouter un point » (sur le bord le plus proche) ou « − Supprimer un point » (sur un coin) dans sa fiche.</li>
        <li><strong>Numérotez</strong> : un numéro par emplacement dans sa fiche, ou « Numéroter tout » pour le faire d'un coup (de gauche à droite et de haut en bas, par exemple). Le compteur « prochain numéro » sert aussi à la pose et à la division.</li>
        <li><strong>Découpez une zone en parcelles</strong> : sélectionnez la zone (rue, hall) à 4 coins, choisissez le nombre de parcelles ou leurs dimensions, cochez « Allée au milieu » pour laisser un couloir entre deux rangées, puis « Découper ». L'aperçu donne la taille des parcelles ; chacune reçoit le numéro suivant.</li>
        <li><strong>Reliez chaque emplacement à un commerce</strong> pour que les visiteurs voient qui s'y trouve. L'adresse de la marchandise (rue, code postal, ville, stand) se règle dans « Présentation et commerces », et côté boutique dans « Mes galeries ».</li>
        <li>Pensez à <strong>Enregistrer le plan</strong> : rien n'est conservé avant.</li>
      </ol>
      <p class="tip" style="margin-top:8px;">Astuce : zoomez fort (jusqu'au niveau du mètre) avant de poser des surfaces ; l'échelle s'affiche en bas à gauche de la carte. Échap annule la pose en cours.</p>
    </details>

    <section class="gcard" id="planCard">
      <div id="addrBar">
        <div id="addrRow">
          <div class="addrwrap">
            <input type="text" id="addr" value="<?= e($addrDefault) ?>" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="addrList" aria-label="Rechercher une adresse, une rue, un lieu" placeholder="Rechercher une adresse, une rue, un lieu…" autocomplete="off">
            <ul id="addrList" role="listbox" aria-label="Suggestions d'adresses" hidden></ul>
          </div>
          <button class="btn mini" type="button" id="addrLoc" title="Centrer le plan sur la position de cet appareil ; le navigateur demande l'autorisation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-5.4 7-11a7 7 0 1 0-14 0c0 5.6 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/></svg>Ma position</button>
          <button class="btn icon" type="button" id="addrGo" title="Centrer la carte sur la recherche" aria-label="Centrer la carte sur la recherche"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="7"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg></button>
          <span class="meta" id="addrMsg" role="status"></span>
        </div>
        <button class="btn icon" type="button" id="addrToggle" aria-pressed="true" aria-expanded="true" aria-controls="addrRow" title="Replier ou déplier la recherche d'adresse" aria-label="Adresse et localisation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><span id="addrEcho"><?= e($addrDefault !== '' ? $addrDefault : 'Adresse du lieu') ?></span></button>
      </div>
      <div class="row" id="fsTitleBar">
        <h2 id="planTitle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4 3 6.5v13L9 17l6 2.5 6-2.5v-13L15 6.5 9 4Z"/><path d="M9 4v13M15 6.5v13"/></svg>Plan d'implantation</h2>
        <div class="plan-actions" role="toolbar" aria-label="Actions du plan">
          <button class="btn icon" type="button" id="mapBarToggle" aria-pressed="true" aria-controls="mapBarBody" title="Afficher ou masquer les outils de la carte (fond, rotation)" aria-label="Outils de la carte"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2.2"/><circle cx="9" cy="17" r="2.2"/></svg></button>
          <button class="btn icon" type="button" id="fsOpen" title="Agrandir le plan en plein écran" aria-label="Plein écran"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg></button>
          <button class="btn btn-primary icon" type="button" id="fsClose" hidden title="Fermer le plein écran" aria-label="Fermer le plein écran"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4v5H4M15 4v5h5M9 20v-5H4M15 20v-5h5"/></svg></button>
        </div>
      </div>
      <div id="fsLayout"><div id="fsSide" hidden></div><div id="fsMain">
      <div id="mapBar">
      <div id="mapBarBody">
      <div class="row">
        <label class="row" style="gap:6px;">Fond de carte
          <select id="baseSel" style="flex:none;min-width:0;"><option value="osm">Plan (IGN)</option><option value="sat">Satellite (IGN)</option></select></label>
        <label class="row" style="gap:6px;" title="N'affiche que votre dessin, sur une grille : utile pour un plan intérieur ou quand le fond gêne la lecture."><input type="checkbox" id="hideBase"> Masquer le fond de carte (plan seul)</label>
        <span class="meta" id="mode" role="status"></span>
      </div>
      <div class="row" id="aiRow">
        <label class="row" style="gap:6px;">Fond stylisé par l'IA
          <select id="aiStyle" style="flex:none;min-width:0;">
            <option value="aquarelle">Aquarelle douce</option>
            <option value="croquis">Croquis d'architecte</option>
            <option value="retro">Affiche rétro</option>
            <option value="bd">BD franco-belge</option>
          </select></label>
        <button class="btn mini" type="button" id="aiGo" title="Capture la vue actuelle du fond IGN, la fait redessiner par l'IA, puis la pose comme fond importé calé à l'échelle. Chaque stylisation consomme un crédit IA.">Capturer et styliser</button>
        <span class="meta" id="aiMsg" role="status" style="flex:1 1 180px;min-width:0;"></span>
      </div>
      <div class="row" id="exportRow">
        <span class="row" style="gap:6px;" title="Exporte le plan tel qu'il est à l'écran, en 300 dpi : PDF prêt à imprimer, ou PSD avec calque d'image calé pour InDesign/Photoshop.">
          Export impression
          <select id="expFmt" style="flex:none;min-width:0;"><option value="pdf">PDF</option><option value="psd">PSD (InDesign/Photoshop)</option></select>
          <select id="expPage" style="flex:none;min-width:0;"><option value="a4">A4 paysage</option><option value="a3">A3 paysage</option></select>
        </span>
        <button class="btn mini" type="button" id="expGo">Exporter</button>
        <span class="meta" id="expMsg" role="status" style="flex:1 1 180px;min-width:0;"></span>
      </div>
      <details id="advancedTools">
        <summary>Fond importé et rotation de la carte</summary>
        <div class="row" id="bgRow">
          <span class="row" style="gap:6px;" title="Importez un plan masse, un plan de cadastre ou une photo : l'image est calée sur la carte à l'échelle, déplaçable, et enregistrée avec le plan.">
            Fond importé
            <input type="file" id="bgFile" accept="image/jpeg,image/png,image/webp" style="flex:none;max-width:230px;min-width:0;">
            <button class="btn mini" type="button" id="bgGo">Importer</button>
            <button class="btn mini danger" type="button" id="bgRemove" hidden>Retirer</button>
          </span>
          <label class="row" style="gap:6px;" title="Cochez, puis glissez l'image à la souris pour la caler sur le fond."><input type="checkbox" id="bgMove"> Déplacer l'image</label>
          <label class="row" style="gap:6px;">Opacité <input type="range" id="bgOpacity" min="0.1" max="1" step="0.05" value="0.7" style="flex:1 1 110px;min-width:90px;max-width:190px;"></label>
          <span class="meta" id="bgMsg" role="status"></span>
        </div>
        <div class="row">
          <label class="row" style="gap:6px;" title="Fait pivoter la carte et le dessin autour du centre : pratique pour aligner une allée ou une rue à l'écran. Le bouton Nord rétablit l'orientation ; la rotation n'est pas enregistrée avec le plan.">
            Rotation du fond
            <input type="range" id="bearing" min="-180" max="180" step="1" value="0" style="flex:1 1 160px;min-width:120px;max-width:280px;">
            <output id="bearingOut" for="bearing" style="min-width:44px;text-align:right;font-variant-numeric:tabular-nums;">0°</output>
            <button class="btn mini" type="button" id="bearingReset">Nord</button>
          </label>
        </div>
      </details>
      </div>
      </div>
      <div id="planWrap"><div id="plan" aria-label="Carte du plan d'implantation"></div><div id="planUi"></div>

      </div>

      <div class="row" id="saveBar">
        <button class="btn btn-primary" type="submit" form="saveForm">Enregistrer le plan</button>
        <button class="btn" type="button" id="undoBtn" disabled title="Annuler la dernière modification du plan (Ctrl+Z)">↩ Annuler</button>
        <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Voir sur la page publique ↗</a>
        <p class="meta" id="count" role="status"></p>
      </div>

      <div class="plan-grid" id="sel" hidden>
        <div class="selhead"><strong>Élément sélectionné</strong><button type="button" id="selClose" title="Fermer la fiche" aria-label="Fermer la fiche">✕</button></div>
        <label class="f">Nom affiché<input type="text" id="selLabel" maxlength="60" placeholder="Brocante de Paul, Entrée…"></label>
        <label class="f" title="Numéro d'emplacement affiché sur le plan (ex. 12 ou A3).">N° d'emplacement<input type="text" id="selNum" maxlength="12" placeholder="12"></label>
        <label class="f" id="natureBox" title="Une zone regroupe des emplacements (rue, hall…) : elle porte un nom, un type et des tarifs. Un territoire cerne un quartier ou plusieurs rues et sert à y découvrir les emplacements potentiels.">Nature<select id="selRole"><option value="slot">Emplacement</option><option value="zone">Zone (rue, hall…)</option><option value="territoire">Territoire (quartier…)</option></select></label>
        <label class="f" id="ztypeBox" hidden>Type de zone<select id="selZtype"><?php foreach ($zoneTypes as $zk => $zl): ?><option value="<?= e($zk) ?>"><?= e($zl) ?></option><?php endforeach; ?></select></label>
        <label class="f" id="usageBox" hidden>Affectation<select id="selUsage"></select></label>
        <label class="f" id="flowBox" hidden>Sens de circulation<select id="selFlow"></select></label>
        <label class="f" id="serviceBox" hidden>Service<input type="text" id="selService" maxlength="40" placeholder="Accueil, restauration, sanitaires…"></label>
        <label class="row" id="indoorBox" hidden style="gap:6px;"><input type="checkbox" id="selIndoor"> À l'intérieur d'un bâtiment</label>
        <label class="f"><?= e(ucfirst(gt_word($gaGallery, 'member'))) ?> lié<select id="selShop"><option value="">— aucun —</option></select></label>
        <label class="f">Couleur<input type="color" id="selColor" value="#b5502e"></label>
        <label class="f">Précision (facultatif)<input type="text" id="selNote" maxlength="200" placeholder="Horaires, accès…"></label>
        <button class="btn danger" type="button" id="selDel">Supprimer cet élément</button>
        <p class="tip" id="selInfo" style="grid-column:1/-1;"></p>
        <div class="plan-sub" id="priceBox" hidden style="grid-column:1/-1;">
          <strong>Tarifs de cette zone</strong>
          <div class="row">
            <label class="f" style="min-width:150px;">Unité<select id="selUnit"><?php foreach ($priceUnits as $uk => $ul): ?><option value="<?= e($uk) ?>"><?= e($ul) ?></option><?php endforeach; ?></select></label>
            <label class="f" style="min-width:130px;">Particulier (€)<input type="text" id="selPrice" inputmode="decimal" placeholder="ex. 12"></label>
            <label class="f" style="min-width:160px;">Entreprise (€, facultatif)<input type="text" id="selPricePro" inputmode="decimal" placeholder="= particulier"></label>
          </div>
          <p class="tip">Les emplacements situés dans cette zone héritent de ce tarif. « Par mètre linéaire » = tarif × façade de l'emplacement (son premier côté) ; « par m² » = tarif × surface.</p>
        </div>
        <div class="plan-sub" id="rotBox" hidden style="grid-column:1/-1;">
          <strong>Tourner cet élément</strong>
          <div class="row">
            <button class="btn mini" type="button" data-rot="-90" title="Un quart de tour à gauche">↺ 90°</button>
            <button class="btn mini" type="button" data-rot="-15">↺ 15°</button>
            <button class="btn mini" type="button" data-rot="-1">↺ 1°</button>
            <button class="btn mini" type="button" data-rot="1">↻ 1°</button>
            <button class="btn mini" type="button" data-rot="15">↻ 15°</button>
            <button class="btn mini" type="button" data-rot="90" title="Un quart de tour à droite">↻ 90°</button>
            <label class="row" style="gap:6px;">Angle <input type="number" id="rotAngle" value="30" step="1" min="-360" max="360" style="flex:none;width:80px;min-width:0;"> °</label>
            <button class="btn mini" type="button" id="rotGo">Tourner de cet angle</button>
          </div>
          <p class="tip">Rotation autour du centre de l'élément ; ↻ tourne vers la droite (sens des aiguilles d'une montre), ↺ vers la gauche. Vous pouvez aussi tourner à la souris avec l'outil de rotation à gauche de la carte.</p>
        </div>
        <div class="plan-sub" id="vertexBox" hidden style="grid-column:1/-1;">
          <strong>Points d'ancrage</strong>
          <div class="row">
            <button class="btn mini" type="button" id="vertexAdd" aria-pressed="false">+ Ajouter un point</button>
            <button class="btn mini" type="button" id="vertexDel" aria-pressed="false">− Supprimer un point</button>
            <button class="btn mini" type="button" id="vertexStop" hidden>Terminer</button>
          </div>
          <p class="tip" id="vertexMsg">Ajoutez un point sur le bord le plus proche ou retirez un coin pour ajuster la forme (3 points minimum).</p>
        </div>
        <div class="plan-sub" id="terrBox" hidden style="grid-column:1/-1;">
          <strong>Découvrir les emplacements potentiels de ce territoire</strong>
          <div class="row">
            <label class="f" style="min-width:170px;" title="Surface du catalogue posée en grille à l'intérieur du territoire.">Surface<select id="terrSurf"></select></label>
            <label class="f" style="min-width:100px;" title="Espace libre laissé entre deux emplacements.">Écart (m)<input type="number" id="terrGap" min="0" max="10" step="0.5" value="1"></label>
            <label class="f" style="min-width:100px;" title="0 = grille alignée nord-sud / est-ouest ; tournez pour suivre l'orientation des rues du territoire.">Angle (°)<input type="number" id="terrAngle" min="-180" max="180" step="5" value="0"></label>
            <label class="row" style="gap:6px;"><input type="checkbox" id="terrGhost" checked> Aperçu sur la carte</label>
            <button class="btn btn-primary" type="button" id="terrApply">Créer les emplacements</button>
            <button class="btn" type="button" id="terrZones" title="Analyse les bâtiments, voiries, parkings et services de ce territoire (BD TOPO IGN) dans l'assistant de zonage.">Analyser les zones du site</button>
          </div>
          <p class="tip" id="terrPreview"></p>
          <p class="tip">Un quadrillage de la surface choisie est testé dans tout le territoire (cellules entièrement à l'intérieur). Création en un clic : chaque emplacement reçoit le numéro suivant si « numéroter automatiquement » est coché ; dessinez ensuite les zones (rues) à l'intérieur pour leur donner un tarif.</p>
        </div>
        <div class="plan-sub" id="subBox" hidden style="grid-column:1/-1;">
          <strong>Diviser en sous-zones</strong>
          <div class="row">
            <label class="f" style="min-width:110px;">Nombre<input type="number" id="subN" min="2" max="20" value="2"></label>
            <label class="f" style="min-width:190px;">Découpe<select id="subAxis"><option value="auto">le long du plus grand côté</option><option value="lat">nord → sud (bandes horizontales)</option><option value="lng">ouest → est (bandes verticales)</option></select></label>
            <button class="btn" type="button" id="subGo">Diviser</button>
          </div>
          <p class="tip">Chaque partie devient une zone distincte et reprend l'affectation, le type, la couleur et l'état intérieur/extérieur de l'ensemble ; l'originale est remplacée. Pour les formes à 4 coins, « Découper en parcelles » reste plus précis.</p>
        </div>
        <div class="plan-sub" id="splitBox" hidden style="grid-column:1/-1;">
          <strong id="splitTitle">Découper en parcelles</strong>
          <div class="row">
            <label class="f" style="min-width:170px;">Découpage<select id="splitMode"><option value="count">Par nombre de parcelles</option><option value="size">Par dimensions (m)</option></select></label>
            <span class="row" id="splitCountBox">
              <label class="f" style="min-width:120px;"><span id="splitColsLbl">Parcelles par rangée</span><input type="number" id="splitCols" min="1" max="60" value="4"></label>
              <label class="f" style="min-width:120px;"><span id="splitRowsLbl">Rangées</span><input type="number" id="splitRows" min="1" max="40" value="1"></label>
            </span>
            <span class="row" id="splitSizeBox" hidden>
              <label class="f" style="min-width:120px;">Largeur d'une parcelle (m)<input type="number" id="splitW" min="0.5" max="100" step="0.1" value="3"></label>
              <label class="f" style="min-width:120px;">Profondeur (m)<input type="number" id="splitD" min="0.5" max="100" step="0.1" value="3"></label>
            </span>
            <label class="f" style="min-width:210px;" title="Les parcelles sont alignées le long de ce côté de la zone ; la largeur d'une parcelle est mesurée dans ce sens.">Parcelles alignées le long du côté<select id="splitAisleDir"><option value="long">le plus long</option><option value="short">le plus court</option></select></label>
          </div>
          <div class="row">
            <label class="row" style="gap:6px;" title="Laisse un couloir au milieu : les parcelles se font face de chaque côté."><input type="checkbox" id="splitAisle"> Allée au milieu</label>
            <span class="row" id="splitAisleBox" hidden>
              <label class="f" style="min-width:130px;">Largeur de l'allée (m)<input type="number" id="splitAisleW" min="0.5" max="30" step="0.5" value="3"></label>
              <label class="row" style="gap:6px;"><input type="checkbox" id="splitAisleDraw" checked> Dessiner l'allée</label>
            </span>
            <label class="row" style="gap:6px;"><input type="checkbox" id="splitGhost"> Aperçu sur la carte</label>
          </div>
          <p class="tip" id="splitPreview"></p>

          <details id="aidBox" open>
            <summary><strong>Aide au calcul et simulation du chiffre d'affaires</strong></summary>
            <div class="row" style="margin-top:8px;">
              <label class="f" style="min-width:120px;">Largeur mini (m)<input type="number" id="aidMin" min="0.5" max="100" step="0.1" value="2"></label>
              <label class="f" style="min-width:120px;">Largeur maxi (m)<input type="number" id="aidMax" min="0.5" max="100" step="0.1" value="4"></label>
              <label class="f" style="min-width:130px;" title="Tarif d'une parcelle pour la simulation : repris de la zone, modifiable pour tester un autre positionnement.">Tarif simulé (€)<input type="text" id="simPrice" inputmode="decimal" placeholder="ex. 12"></label>
              <label class="f" style="min-width:150px;">Unité<select id="simUnit"><?php foreach ($priceUnits as $uk => $ul): ?><option value="<?= e($uk) ?>"><?= e($ul) ?></option><?php endforeach; ?></select></label>
            </div>
            <div class="row">
              <label class="f" style="min-width:150px;" title="Coûts de l'événement entier (location du lieu, assurance, communication…), indépendants du nombre d'exposants.">Coûts fixes (€)<input type="text" id="simFixed" inputmode="decimal" placeholder="0"></label>
              <label class="f" style="min-width:170px;" title="Coût supplémentaire à chaque parcelle occupée (nettoyage, électricité, bénévoles…).">Coût par parcelle occupée (€)<input type="text" id="simVar" inputmode="decimal" placeholder="0"></label>
              <label class="f" style="min-width:150px;" title="Frais proportionnels au chiffre d'affaires (paiement en ligne, commission…).">Frais variables (% du CA)<input type="text" id="simPct" inputmode="decimal" placeholder="0"></label>
            </div>
            <table class="t simscen"><thead><tr><th></th><th>Scénario bas</th><th>Central</th><th>Scénario haut</th></tr></thead><tbody>
              <tr><th>Parcelles occupées (%)</th><td><input type="number" id="simOcc0" min="0" max="100" step="1" value="40"></td><td><input type="number" id="simOcc1" min="0" max="100" step="1" value="65"></td><td><input type="number" id="simOcc2" min="0" max="100" step="1" value="90"></td></tr>
              <tr><th>Écart de prix (%)</th><td><input type="number" id="simAdj0" min="-90" max="500" step="1" value="-10"></td><td><input type="number" id="simAdj1" min="-90" max="500" step="1" value="0"></td><td><input type="number" id="simAdj2" min="-90" max="500" step="1" value="10"></td></tr>
            </tbody></table>
            <p class="tip">Ces hypothèses sont enregistrées avec le plan. Le tarif de simulation n'est repris que de la zone ; l'écart de prix teste un tarif plus bas ou plus haut que celui prévu.</p>
            <p class="tip" id="aidMsg"></p>
            <div class="simwrap"><table class="t" id="aidTable" hidden><thead><tr><th>Par rangée</th><th class="num">Largeur</th><th class="num">Parcelles</th><th class="num">Prix</th><th class="num">€/m²</th><th class="num">CA bas</th><th class="num">CA haut</th><th class="num">Marge centrale<br><small>avant coûts fixes</small></th><th class="num">Seuil</th><th></th></tr></thead><tbody id="aidBody"></tbody></table></div>
            <div id="simDetail"></div>
          </details>

          <div class="row"><button class="btn btn-primary" type="button" id="splitGo">Découper</button></div>
          <p class="tip" id="splitHelp">Une zone (rue, hall…) est conservée et reçoit les parcelles à l'intérieur ; un emplacement est remplacé par ses parcelles. Si « numéroter » est coché plus bas, chaque parcelle reçoit le numéro suivant. Disponible pour les formes à 4 coins (rectangle).</p>
        </div>
      </div>
      </div></div>
    </section>

    <section class="gcard" id="zoneCard">
      <h2>Assistant de zonage du site</h2>
      <p class="meta">Après avoir dessiné un territoire, l'analyse des données IGN (BD TOPO) propose ses zones : bâtiments à l'intérieur desquels se tient l'événement, voiries avec leur sens de circulation, parkings, services et équipements, et les points d'entrée. Cochez ce qui sert au déballage, ajustez l'affectation, puis ajoutez au plan.</p>
      <div class="row">
        <label class="f" style="min-width:240px;">Territoire à analyser<select id="zdTerr"></select></label>
        <label class="f" style="min-width:140px;" title="Les constructions plus petites (abri, kiosque…) ne sont pas proposées.">Surface mini (m²)<input type="number" id="zdMin" min="1" max="5000" step="1" value="25"></label>
      </div>
      <div class="row">
        <label class="row" style="gap:6px;flex:none;white-space:nowrap;"><input type="checkbox" id="zdRoads" checked style="width:auto;flex:none;"> Voirie et sens de circulation</label>
        <label class="row" style="gap:6px;flex:none;white-space:nowrap;"><input type="checkbox" id="zdParking" checked style="width:auto;flex:none;"> Parkings</label>
        <label class="row" style="gap:6px;flex:none;white-space:nowrap;"><input type="checkbox" id="zdServices" checked style="width:auto;flex:none;"> Services et équipements</label>
        <label class="row" style="gap:6px;flex:none;white-space:nowrap;" title="L'orthophoto du territoire est lue par Gemini pour préciser les usages, repérer ce qu'il faut exclure du déballage et voir les installations absentes des données IGN. Sans clé ou crédit IA, l'analyse IGN continue de fonctionner."><input type="checkbox" id="zdAi" checked style="width:auto;flex:none;"> Affiner par l'IA (orthophoto)</label>
        <button class="btn btn-primary" type="button" id="zdGo">Analyser le site</button>
      </div>
      <p class="tip" id="zdMsg" role="status"></p>
      <p class="tip">L'affinage IA fait un appel au modèle par site et conserve son résultat 30 jours : la deuxième analyse du même territoire est immédiate.</p>
      <div id="zdResult" hidden>
        <p class="meta" id="zdSummary"></p>
        <div class="zdwrap">
          <table class="t"><thead><tr><th><input type="checkbox" id="zdAll" checked title="Tout cocher ou décocher"></th><th style="min-width:300px;">Zone proposée</th><th style="min-width:150px;">Affectation</th><th class="num">Surface</th><th title="À l'intérieur d'un bâtiment">Int.</th><th style="width:120px;">Service</th></tr></thead><tbody id="zdBody"></tbody></table>
          <div class="row zdactions">
            <button class="btn btn-primary" type="button" id="zdAdd">Ajouter les zones cochées</button>
            <button class="btn" type="button" id="zdClear">Effacer la proposition</button>
          </div>
        </div>
        <p class="tip">Les zones ajoutées sont des zones du plan : elles portent l'affectation choisie, restent modifiables dans leur fiche et peuvent être découpées en sous-zones. « Exclu du déballage » signale un espace où aucun emplacement ne doit être installé (accès secours, zone technique…). Un emplacement dans une zone de circulation, de service, de parking ou exclue n'hérite d'aucun tarif.</p>
        <div id="zdEntries" hidden>
          <strong>Entrées / sorties détectées</strong>
          <ul class="zdlist" id="zdEntryList"></ul>
        </div>
      </div>
    </section>

    <section class="gcard" id="catCard">
      <h2>Catalogue de surfaces</h2>
      <p class="meta">Les tailles d'emplacement que vous proposez (en mètres). « Poser » dessine l'emplacement à l'échelle sur la carte, à l'endroit où vous cliquez.</p>
      <table class="t"><thead><tr><th>Nom</th><th style="width:90px">Larg. (m)</th><th style="width:90px">Prof. (m)</th><th style="width:70px">Couleur</th><th class="num" style="width:90px">Surface</th><th style="width:170px"></th></tr></thead><tbody id="catBody"></tbody></table>
      <div class="row">
        <button class="btn" type="button" id="catAdd">+ Ajouter une surface</button>
        <label class="row" style="gap:6px;">Angle de pose (°) <input type="number" id="angle" value="0" min="-180" max="180" step="5" style="flex:none;width:80px;min-width:0;" title="0 = côté long à l'horizontale ; tournez pour suivre une allée."></label>
        <button class="btn" type="button" id="placeStop" hidden>Terminer la pose</button>
      </div>
    </section>

    <section class="gcard" id="numCard">
      <h2>Numérotation des emplacements</h2>
      <div class="row">
        <label class="f" style="max-width:150px;">Préfixe (facultatif)<input type="text" id="numPrefix" maxlength="6" placeholder="A-"></label>
        <label class="f" style="max-width:150px;">Prochain numéro<input type="number" id="numNext" min="0" max="9999" value="1"></label>
        <label class="f" style="max-width:260px;">Ordre<select id="numOrder"><option value="rows">Gauche → droite, haut → bas</option><option value="cols">Haut → bas, gauche → droite</option><option value="created">Ordre de création</option></select></label>
      </div>
      <div class="row">
        <label class="row" style="gap:6px;"><input type="checkbox" id="numAuto" checked> Numéroter automatiquement à la pose et à la division</label>
        <label class="row" style="gap:6px;"><input type="checkbox" id="numOnlyEmpty" checked> « Numéroter tout » : seulement ceux sans numéro</label>
        <label class="row" style="gap:6px;" title="Recommence à 1 dans chaque zone ; le numéro de la zone sert de préfixe (ex. RF-1, RF-2)."><input type="checkbox" id="numByZone"> « Numéroter tout » : par zone (le N° de la zone sert de préfixe)</label>
        <button class="btn" type="button" id="numAll">Numéroter tout</button>
      </div>
      <p class="tip">Seules les zones (rectangles, polygones) sont numérotées par « Numéroter tout » ; les repères et tracés gardent un nom libre.</p>
      <div class="listplan" id="listWrap" hidden><table class="t"><thead><tr><th>N°</th><th>Nom</th><th>Zone</th><th>Affectation</th><th>Commerce</th><th class="num">m²</th><th class="num">Tarif</th></tr></thead><tbody id="listBody"></tbody></table></div>
    </section>

    <section class="gcard" id="bookCard">
      <h2>Exposants : choisir son emplacement</h2>
      <p class="meta">Ouvrez la page publique aux exposants : ils choisissent un emplacement libre sur le plan (avec son tarif), envoient leur demande et vous la validez dans « Exposants » (menu de la galerie).</p>
      <div class="row">
        <label class="row" style="gap:6px;"><input type="checkbox" id="bkOn"> Les exposants peuvent demander un emplacement sur la page publique</label>
        <label class="row" style="gap:6px;">Une demande en attente bloque l'emplacement
          <input type="number" id="bkDays" min="1" max="60" value="7" style="flex:none;width:70px;min-width:0;"> jours</label>
      </div>
      <label class="f">Message affiché aux exposants (facultatif)<input type="text" id="bkIntro" maxlength="300" placeholder="Ex. Inscriptions ouvertes jusqu'au 15 mai ; installation dès 6 h."></label>
      <p class="tip">Seuls les emplacements (rectangles, polygones) sont réservables ; ceux déjà liés à un commerce ou attribués ne le sont plus. Les emplacements hors de toute zone n'ont pas de tarif affiché.</p>
    </section>

    <form method="post" id="saveForm" class="row">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><?= $limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">' ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="map" id="mapJson">
      <button class="btn btn-primary" type="submit">Enregistrer le plan</button>
      <p class="meta" style="margin:0;">Fond de carte © IGN (Géoplateforme) : plan ou orthophoto, sans clé d'API. Secours automatique CARTO / Esri si l'IGN ne couvre pas le lieu. Google Maps n'est pas utilisé : il impose une clé d'API facturable et interdit de recopier son fond de carte ailleurs.</p>
    </form>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.min.js"></script>
    <script>
    (function () {
      var data = <?= json_encode($payload, $flags) ?>;
      var saved = data.map || { center: null, zoom: 17, base: 'osm', items: [], catalog: [] };
      var $ = function (id) { return document.getElementById(id); };
      var planEl = $('plan'), planWrap = $('planWrap');
      var map = L.map('plan', { zoomControl: true, maxZoom: 22 }).setView(saved.center || [46.6, 2.4], saved.center ? saved.zoom : 6);
      // Fonds IGN (Géoplateforme, sans clé) ; secours automatique si une tuile manque (lieu hors de France, service indisponible).
      var bases = {
        osm: L.tileLayer(data.tiles.osm, { maxZoom: 22, maxNativeZoom: 19, attribution: 'Plan © <a href="https://www.ign.fr" target="_blank" rel="noopener">IGN</a>' }),
        sat: L.tileLayer(data.tiles.sat, { maxZoom: 22, maxNativeZoom: 19, attribution: 'Imagerie © <a href="https://www.ign.fr" target="_blank" rel="noopener">IGN</a>' })
      };
      var fallbacks = {
        osm: L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}.png', { subdomains: 'abcd', maxZoom: 22, maxNativeZoom: 20, attribution: '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> © <a href="https://carto.com/attributions" target="_blank" rel="noopener">CARTO</a>' }),
        sat: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 22, maxNativeZoom: 19, attribution: 'Imagerie © Esri, Maxar, Earthstar Geographics' })
      };
      Object.keys(bases).forEach(function (k) {
        bases[k].on('tileerror', function () { if (!map.hasLayer(bases[k])) return; map.removeLayer(bases[k]); bases[k] = fallbacks[k]; bases[k].addTo(map); });
      });
      L.control.scale({ metric: true, imperial: false, position: 'bottomleft' }).addTo(map);
      map.pm.setLang('fr');
      map.pm.addControls({ position: 'topleft', drawMarker: true, drawRectangle: true, drawPolygon: true, drawPolyline: true, drawCircle: false, drawCircleMarker: false, drawText: false, cutPolygon: false, rotateMode: true, editMode: true, dragMode: true, removalMode: true });
      // Les contrôles Leaflet (zoom, échelle, outils Geoman) sortent du cadre tourné : ils restent droits et cliquables.
      $('planUi').appendChild(map.getContainer().querySelector('.leaflet-control-container'));

      // --- Fond de carte : plan, satellite ou aucun ---
      var baseKind = saved.base === 'sat' ? 'sat' : 'osm', hidden = saved.base === 'none';
      function applyBase() {
        map.removeLayer(bases.osm); map.removeLayer(bases.sat);
        $('plan').classList.toggle('nofond', hidden);
        if (!hidden) bases[baseKind].addTo(map);
        $('baseSel').value = baseKind; $('baseSel').disabled = hidden; $('hideBase').checked = hidden;
        $('mode').textContent = hidden ? 'Plan seul (fond masqué)' : '';
      }
      $('baseSel').addEventListener('change', function () { baseKind = this.value; applyBase(); });
      $('hideBase').addEventListener('change', function () { hidden = this.checked; applyBase(); });
      applyBase();

      // --- Fond importé (plan masse, cadastre, photo aérienne…) : image calée sur la carte à l'échelle, déplaçable, enregistrée avec le plan ---
      var CSRF = <?= json_encode(csrf_token()) ?>;
      var bg = saved.background && saved.background.path ? { path: saved.background.path, bounds: saved.background.bounds, opacity: saved.background.opacity || 0.7 } : null;
      var bgLayer = null, bgPaneName = 'planBgPane';
      map.createPane(bgPaneName).style.zIndex = 350;   // sous le dessin (overlayPane = 400), au-dessus des tuiles (200)
      function bgBoundsArr(b) { return [[b.getNorth(), b.getWest()], [b.getSouth(), b.getEast()]]; }
      function renderBg() {
        if (bgLayer) { map.removeLayer(bgLayer); bgLayer = null; }
        $('bgRemove').hidden = !bg;
        if (!bg) return;
        $('bgOpacity').value = bg.opacity;
        bgLayer = L.imageOverlay('/' + bg.path, L.latLngBounds(bg.bounds), { pane: bgPaneName, opacity: bg.opacity, interactive: $('bgMove').checked });
        bgLayer.addTo(map);
        if ($('bgMove').checked) bgEnableDrag();
      }
      function bgEnableDrag() {
        if (!bgLayer) return;
        bgLayer.on('mousedown touchstart', function (e) {
          if (!$('bgMove').checked || !bg) return;
          L.DomEvent.stop(e.originalEvent);
          map.dragging.disable();
          var ev0 = e.originalEvent.touches ? e.originalEvent.touches[0] : e.originalEvent;
          var startPt = map.mouseEventToContainerPoint(ev0);
          var b0 = bgLayer.getBounds(), nw0 = map.latLngToContainerPoint(b0.getNorthWest()), se0 = map.latLngToContainerPoint(b0.getSouthEast());
          function mv(ev) { var t = ev.touches ? ev.touches[0] : ev; var pt = map.mouseEventToContainerPoint(t); var d = pt.subtract(startPt); bgLayer.setBounds(L.latLngBounds(map.containerPointToLatLng(nw0.add(d)), map.containerPointToLatLng(se0.add(d)))); }
          function up() { map.off('mousemove touchmove', mv); map.off('mouseup touchend', up); map.dragging.enable(); if (bg) bg.bounds = bgBoundsArr(bgLayer.getBounds()); }
          map.on('mousemove touchmove', mv); map.on('mouseup touchend', up);
        });
      }
      $('bgGo').addEventListener('click', function () {
        var f = $('bgFile').files[0];
        if (!f) { $('bgMsg').textContent = 'Choisissez une image (JPEG, PNG ou WebP, 12 Mo au plus).'; return; }
        $('bgMsg').textContent = 'Envoi…';
        var fd = new FormData();
        fd.append('csrf', CSRF); fd.append('action', 'bg'); fd.append('bg', f);
        fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) { $('bgMsg').textContent = j.error || 'Import impossible.'; return; }
            var img = new Image();
            img.onload = function () {     // calage initial : l'image tient à 90 % de la vue courante, aux bonnes proportions
              var r = img.naturalWidth / img.naturalHeight, s = map.getSize();
              var wPx = s.x * 0.9, hPx = wPx / r;
              if (hPx > s.y * 0.9) { hPx = s.y * 0.9; wPx = hPx * r; }
              var cPt = map.latLngToContainerPoint(map.getCenter());
              var nw = map.containerPointToLatLng(cPt.subtract([wPx / 2, hPx / 2])), se = map.containerPointToLatLng(cPt.add([wPx / 2, hPx / 2]));
              bg = { path: j.path, bounds: [[nw.lat, nw.lng], [se.lat, se.lng]], opacity: parseFloat($('bgOpacity').value) || 0.7 };
              renderBg();
              $('bgMsg').textContent = 'Image calée au centre de la vue : cochez « Déplacer l\'image » pour l\'aligner sur le fond, puis enregistrez le plan.';
            };
            img.onerror = function () { $('bgMsg').textContent = 'Image reçue mais illisible.'; };
            img.src = '/' + j.path;
          })
          .catch(function () { $('bgMsg').textContent = 'Envoi impossible (réseau).' });
      });
      $('bgRemove').addEventListener('click', function () { bg = null; renderBg(); $('bgMsg').textContent = 'Fond retiré du plan (l\'image reste sur le serveur jusqu\'au prochain enregistrement).'; });
      $('bgOpacity').addEventListener('input', function () { if (bg) { bg.opacity = parseFloat(this.value); if (bgLayer) bgLayer.setOpacity(bg.opacity); } });
      $('bgMove').addEventListener('change', renderBg);
      renderBg();

      // --- Fond stylisé par l'IA : la vue courante est capturée (WMS IGN), redessinée par Gemini, puis posée comme fond importé ---
      (function () {
        var btn = $('aiGo'), msg = $('aiMsg'), sel = $('aiStyle');
        if (!data.aiMap) {
          btn.disabled = true; sel.disabled = true;
          msg.textContent = 'Clé IA absente : voir Fournisseurs IA.';
        }
        btn.addEventListener('click', function () {
          if (btn.disabled) return;
          var b = map.getBounds();
          var view = { south: b.getSouth(), west: b.getWest(), north: b.getNorth(), east: b.getEast() };
          var labels = { aquarelle: 'aquarelle douce', croquis: "croquis d'architecte", retro: 'affiche rétro', bd: 'BD franco-belge' };
          btn.disabled = true; sel.disabled = true;
          msg.textContent = 'Capture du fond et stylisation par l\'IA… (20 à 40 s)';
          var fd = new FormData();
          fd.append('csrf', CSRF); fd.append('action', 'stylize');
          fd.append('view', JSON.stringify(view));
          fd.append('base', hidden ? 'osm' : baseKind);
          fd.append('style', sel.value);
          fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
              btn.disabled = false; sel.disabled = false;
              if (!j.ok) { msg.textContent = j.error || 'Stylisation impossible.'; return; }
              bg = { path: j.path, bounds: j.bounds, opacity: 1 };
              $('bgOpacity').value = 1;
              renderBg();
              msg.textContent = 'Fond ' + (labels[j.style] || 'stylisé') + ' posé : enregistrez le plan pour le conserver.';
            })
            .catch(function () { btn.disabled = false; sel.disabled = false; msg.textContent = 'Stylisation indisponible (réseau ou délai dépassé).'; });
        });
      })();

      // --- Rotation du fond de carte ---
      // La carte pivote autour de son centre ; pour qu'aucun coin ne reste vide, le cadre prend la taille de la diagonale.
      // Les clics sont ramenés dans le repère de Leaflet (sinon Leaflet croit la carte droite et le curseur se décale).
      var bearing = 0;
      function rotatedPoint(clientX, clientY) {
        var r = planEl.getBoundingClientRect(), size = map.getSize();
        var dx = clientX - (r.left + r.width / 2), dy = clientY - (r.top + r.height / 2);
        var a = -bearing * Math.PI / 180, c = Math.cos(a), s = Math.sin(a);
        return L.point(size.x / 2 + dx * c - dy * s, size.y / 2 + dx * s + dy * c);
      }
      var baseMousePoint = map.mouseEventToContainerPoint.bind(map);
      // Leaflet 1.9 passe aussi les « Touch » par mouseEventToContainerPoint : un seul correctif suffit.
      map.mouseEventToContainerPoint = function (e) { return bearing ? rotatedPoint(e.clientX, e.clientY) : baseMousePoint(e); };
      function syncGeometry() {
        if (!bearing) {
          planEl.classList.remove('rotated'); planEl.style.width = planEl.style.height = ''; planEl.style.transform = ''; planEl.style.removeProperty('--bearing');
        } else {
          var d = Math.ceil(Math.sqrt(planWrap.clientWidth * planWrap.clientWidth + planWrap.clientHeight * planWrap.clientHeight));
          planEl.classList.add('rotated'); planEl.style.width = planEl.style.height = d + 'px';
          planEl.style.setProperty('--bearing', bearing + 'deg');
          planEl.style.transform = 'translate(-50%, -50%) rotate(' + bearing + 'deg)';
        }
        setTimeout(function () { map.invalidateSize({ pan: false }); }, 30);
      }
      function setBearing(deg) {
        deg = Math.round(deg) % 360; if (deg > 180) deg -= 360; if (deg < -180) deg += 360;
        var rotated = planEl.classList.contains('rotated');
        bearing = deg;
        $('bearingOut').textContent = deg + '°';
        if ($('bearing').value !== String(deg)) $('bearing').value = deg;
        if (!deg) { if (rotated) syncGeometry(); }
        else if (rotated) { planEl.style.setProperty('--bearing', deg + 'deg'); planEl.style.transform = 'translate(-50%, -50%) rotate(' + deg + 'deg)'; }
        else syncGeometry();
      }
      $('bearing').addEventListener('input', function () { setBearing(parseInt(this.value, 10) || 0); });
      $('bearingReset').addEventListener('click', function () { setBearing(0); });

      // --- Éléments du plan ---
      var group = L.featureGroup().addTo(map), selected = null, placing = null, loading = true;
      var catalog = (saved.catalog || []).map(function (c) { return { id: c.id, name: c.name, w: c.w, d: c.d, color: c.color }; });
      var shopSel = $('selShop');
      Object.keys(data.shops).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = data.shops[k]; shopSel.appendChild(o); });
      function fillSelect(sel, map, first) {
        sel.textContent = '';
        if (first) { var o0 = document.createElement('option'); o0.value = ''; o0.textContent = first; sel.appendChild(o0); }
        Object.keys(map).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = map[k]; sel.appendChild(o); });
      }
      fillSelect($('selUsage'), data.usages, '— non précisé —');
      fillSelect($('selFlow'), data.flows, '— non précisé —');
      var UCOL = { vente: '#b5502e', circulation: '#1d4ed8', service: '#0f766e', parking: '#6b7280', exclu: '#b42318', acces: '#7c3aed' };

      function uid() { return Math.random().toString(36).slice(2, 10).padEnd(8, '0'); }
      function text(t) { var n = document.createElement('span'); n.textContent = t; return n; }   // Leaflet interprète une chaîne comme du HTML : on passe un nœud texte
      function dot(color) { return L.divIcon({ className: '', html: '<span class="plan-dot" style="background:' + color + '"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }); }
      function tip(m) { return [m.num, m.label].filter(Boolean).join(' · '); }
      var zoneLabels = L.layerGroup().addTo(map);
      // Trop loin, les noms se chevauchent : ils ne s'affichent qu'au survol de leur zone jusqu'au zoom 19.
      var LABEL_ZOOM = 19;
      function syncLabelZoom() { planEl.classList.toggle('hide-labels', map.getZoom() < LABEL_ZOOM); }
      map.on('zoomend', syncLabelZoom);
      syncLabelZoom();
      function showLabel(layer, on) {
        if (!layer) return;
        if (layer._planLab) layer._planLab.classList.toggle('show', on);
        var tt = layer.getTooltip && layer.getTooltip();
        if (tt && tt._container) tt._container.classList.toggle('show', on);
      }
      function isZone(l) { return l instanceof L.Polygon && l._plan && l._plan.role === 'zone'; }
      function isTerr(l) { return l instanceof L.Polygon && l._plan && l._plan.role === 'territoire'; }
      function look(layer) {
        var m = layer._plan;
        // L'affectation règle le remplissage et le trait ; la couleur reste celle de l'élément (modifiable dans sa fiche).
        var uo = { vente: { fill: 0.3, dash: null, w: 2 }, circulation: { fill: 0.14, dash: null, w: 2 }, service: { fill: 0.14, dash: null, w: 2 },
          parking: { fill: 0.16, dash: '6 4', w: 2 }, exclu: { fill: 0.1, dash: '3 4', w: 2 }, acces: { fill: 0.12, dash: null, w: 2.5 } }[m.usage];
        if (layer instanceof L.Marker) layer.setIcon(dot(m.color));
        else if (isTerr(layer)) layer.setStyle({ color: m.color, weight: 2.5, dashArray: '2 6', fillColor: m.color, fillOpacity: 0.04 });
        else if (uo) layer.setStyle({ color: m.color, weight: uo.w, dashArray: uo.dash, fillColor: m.color, fillOpacity: layer instanceof L.Polygon ? uo.fill : 0 });
        else if (isZone(layer)) layer.setStyle({ color: m.color, weight: 2, dashArray: '8 6', fillColor: m.color, fillOpacity: 0.07 });
        else layer.setStyle({ color: m.color, weight: 2, dashArray: null, fillColor: m.color, fillOpacity: layer instanceof L.Polygon ? 0.3 : 0 });
        layer.unbindTooltip();
        if (!isZone(layer) && !isTerr(layer) && tip(m)) layer.bindTooltip(text(tip(m)), layer instanceof L.Marker ? { permanent: true, direction: 'top', offset: [0, -10], className: 'plan-label' } : { permanent: true, direction: 'center', className: 'plan-label' });
      }
      // Zones et territoires restent sous les emplacements ; la dernière zone dessinée passe devant les précédentes, pour qu'un clic
      // dans un recouvrement sélectionne bien la zone qu'on vient de créer et non celle d'en dessous.
      function restack() {
        var zones = [], terrs = [];
        group.getLayers().forEach(function (l) { if (isTerr(l)) terrs.push(l); else if (isZone(l)) zones.push(l); });
        zones.slice().reverse().forEach(function (l) { l.bringToBack(); });
        terrs.slice().reverse().forEach(function (l) { l.bringToBack(); });
      }
      // Étiquettes des zones (« RUE · Rue des Fleurs ») et territoires (« ⬡ Quartier… ») posées au milieu du bord nord, hors du fichier enregistré
      function refreshZoneLabels() {
        zoneLabels.clearLayers();
        polygons().filter(function (l) { return isZone(l) || isTerr(l); }).forEach(function (z) {
          var b = z.getBounds(), n = document.createElement('span'); n.className = 'plan-zone';
          if (isTerr(z)) { var q = document.createElement('small'); q.textContent = '⬡'; n.appendChild(q); }
          var use = z._plan.usage && z._plan.usage !== 'vente' ? (data.usages[z._plan.usage] || '') : '';
          var zt = use || data.zoneTypes[z._plan.ztype] || '', zl = z._plan.label || (isTerr(z) ? '(territoire sans nom)' : '(zone sans nom)'); if (zt && zl.toLowerCase().indexOf(zt.toLowerCase()) !== 0 && (use || z._plan.ztype !== 'autre')) { var t = document.createElement('small'); t.textContent = zt; n.appendChild(t); } n.appendChild(document.createTextNode(zl));
          z._planLab = n;
          zoneLabels.addLayer(L.marker(z._plan.ztype === 'allee' || z._plan.usage === 'circulation' ? b.getCenter() : [b.getNorth(), b.getCenter().lng], { icon: L.divIcon({ className: '', html: n, iconSize: [0, 0] }), interactive: false, keyboard: false }));
        });
        // Sens de circulation : une flèche au centre des zones de voirie (double sens = flèche à deux têtes)
        polygons().filter(function (l) { return l._plan.usage === 'circulation' && l._plan.flow; }).forEach(function (z) {
          var p = points(z);
          if (p.length < 2) return;
          var a = Math.atan2((p[1][1] - p[0][1]) * Math.cos(p[0][0] * Math.PI / 180), p[1][0] - p[0][0]) * 180 / Math.PI;
          if (z._plan.flow === 'inverse') a += 180;
          var glyph = z._plan.flow === 'double' ? '↕' : '↑';
          zoneLabels.addLayer(L.marker(centroid(z), { icon: L.divIcon({ className: '', html: '<span class="plan-arrow"><i style="transform:rotate(' + a.toFixed(1) + 'deg)">' + glyph + '</i></span>', iconSize: [0, 0] }), interactive: false, keyboard: false }));
        });
      }
      function pointIn(poly, lat, lng) {
        var inside = false;
        for (var i = 0, j = poly.length - 1; i < poly.length; j = i++) {
          var yi = poly[i][0], xi = poly[i][1], yj = poly[j][0], xj = poly[j][1];
          if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / ((yj - yi) || 1e-12) + xi)) inside = !inside;
        }
        return inside;
      }
      // Zone qui contient l'emplacement (la plus petite si elles se recouvrent) ; un territoire ne tarife pas
      function zoneOf(layer) {
        if (!(layer instanceof L.Polygon) || isZone(layer) || isTerr(layer)) return null;
        var c = centroid(layer), best = null, bestArea = Infinity;
        polygons().filter(isZone).forEach(function (z) { var pz = points(z); if (pointIn(pz, c[0], c[1])) { var a = areaM2(pz); if (a < bestArea) { best = z; bestArea = a; } } });
        return best;
      }
      // Territoire qui contient l'élément (plus grande surface), pour la découverte d'emplacements
      function terrOf(layer) {
        var c = centroid(layer), best = null, bestArea = -1;
        polygons().filter(isTerr).forEach(function (t) { var pt = points(t); if (pointIn(pt, c[0], c[1])) { var a = areaM2(pt); if (a > bestArea) { best = t; bestArea = a; } } });
        return best;
      }
      function zoneName(z) { var zt = data.zoneTypes[z._plan.ztype] || '', zl = z._plan.label || '(sans nom)'; return zt && z._plan.ztype !== 'autre' && zl.toLowerCase().indexOf(zt.toLowerCase()) !== 0 ? zt + ' ' + zl : zl; }
      function frontM(pts) { var kLat = 111320, kLng = 111320 * Math.cos(pts[0][0] * Math.PI / 180); return Math.hypot((pts[1][1] - pts[0][1]) * kLng, (pts[1][0] - pts[0][0]) * kLat); }
      function toCents(v) { var n = parseFloat(String(v || '').replace(',', '.')); return isNaN(n) || n < 0 ? 0 : Math.round(n * 100); }
      function eur(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
      function slotPrice(layer, pro) {
        var z = zoneOf(layer); if (!z) return null;
        var m = z._plan, rate = toCents(pro && toCents(m.pricePro) > 0 ? m.pricePro : m.price); if (rate <= 0) return null;
        var p = points(layer);
        return m.unit === 'ml' ? Math.round(rate * frontM(p)) : m.unit === 'm2' ? Math.round(rate * areaM2(p)) : rate;
      }
      function track(layer, meta) {
        meta.id = meta.id || uid(); meta.label = meta.label || ''; meta.num = meta.num || ''; meta.shop = meta.shop || ''; meta.note = meta.note || ''; meta.surface = meta.surface || ''; meta.color = meta.color || '#b5502e'; meta.role = meta.role || 'slot'; meta.ztype = meta.ztype || 'autre'; meta.unit = meta.unit || 'slot'; meta.price = meta.price || ''; meta.pricePro = meta.pricePro || ''; meta.usage = meta.usage || ''; meta.flow = meta.flow || ''; meta.indoor = !!meta.indoor; meta.service = meta.service || '';
        layer._plan = meta; group.addLayer(layer); look(layer); if (!loading) restack();
        layer.on('click', function (e) { if (placing) return; L.DomEvent.stopPropagation(e); if (vertexMode) { vertexApply(e.latlng); return; } select(layer); });
        layer.on('mouseover', function () { showLabel(layer, true); });
        layer.on('mouseout', function () { showLabel(layer, false); });
        return layer;
      }
      function points(layer) {
        if (layer instanceof L.Marker) { var p = layer.getLatLng(); return [[p.lat, p.lng]]; }
        var ll = layer.getLatLngs(); if (Array.isArray(ll[0])) ll = ll[0];
        return ll.map(function (p) { return [p.lat, p.lng]; });
      }
      function kindOf(l) { return l instanceof L.Marker ? 'marker' : l instanceof L.Polygon ? 'polygon' : 'line'; }
      function areaM2(pts) {
        if (pts.length < 3) return 0;
        var kLat = 111320, kLng = 111320 * Math.cos(pts[0][0] * Math.PI / 180), s = 0;
        for (var i = 0; i < pts.length; i++) { var a = pts[i], b = pts[(i + 1) % pts.length]; s += ((a[1] * kLng) * (b[0] * kLat)) - ((b[1] * kLng) * (a[0] * kLat)); }
        return Math.abs(s) / 2;
      }
      function fmtM2(v) { return v >= 100 ? Math.round(v) : (Math.round(v * 10) / 10); }
      function polygons() { return group.getLayers().filter(function (l) { return l instanceof L.Polygon; }); }
      function centroid(l) { var p = points(l), la = 0, ln = 0; p.forEach(function (x) { la += x[0]; ln += x[1]; }); return [la / p.length, ln / p.length]; }

      function count() {
        var n = group.getLayers().length; $('count').textContent = n + ' élément' + (n > 1 ? 's' : '') + ' sur le plan. Pensez à enregistrer.';
        renderList();
      }
      function renderList() {
        var polys = polygons(), body = $('listBody'); body.textContent = '';
        $('listWrap').hidden = !polys.length;
        refreshZoneLabels();
        polys.slice().sort(function (a, b) { return (isZone(b) - isZone(a)) || String(a._plan.num).localeCompare(String(b._plan.num), 'fr', { numeric: true }); }).forEach(function (l) {
          var m = l._plan, tr = document.createElement('tr'), z = zoneOf(l), p = isZone(l) ? null : slotPrice(l, false), pp = isZone(l) ? null : slotPrice(l, true);
          var price = isZone(l) ? (toCents(m.price) ? eur(toCents(m.price)) + ' ' + ({ ml: '/ml', m2: '/m²', slot: '/empl.' })[m.unit] : '') : (p == null ? '' : eur(p) + (pp != null && pp !== p ? ' (pro ' + eur(pp) + ')' : ''));
          [m.num || '—', (isZone(l) ? '▦ ' : isTerr(l) ? '⬡ ' : '') + (m.label || ''), isZone(l) ? (data.zoneTypes[m.ztype] || '') : (z ? zoneName(z) : ''), m.usage && data.usages[m.usage] ? data.usages[m.usage] : '', m.shop && data.shops[m.shop] ? data.shops[m.shop] : '', fmtM2(areaM2(points(l))), price].forEach(function (v, i) { var td = document.createElement('td'); td.textContent = v; if (i >= 5) td.className = 'num'; tr.appendChild(td); });
          tr.addEventListener('click', function () { map.fitBounds(l.getBounds().pad(0.8), { maxZoom: 21 }); select(l); });
          body.appendChild(tr);
        });
        fillTerrList();
      }

      // --- Annulation (Ctrl+Z) : instantanés des éléments du plan ; chaque action en pousse un avant de modifier ---
      var undoStack = [], lastUndoAt = 0, suspendUndo = false;
      function snapshotItems() {
        return group.getLayers().map(function (l) { var m = l._plan; return { id: m.id, kind: kindOf(l), pts: points(l), label: m.label, num: m.num, shop: m.shop, color: m.color, note: m.note, surface: m.surface, role: m.role, ztype: m.ztype, unit: m.unit, price: m.price, pricePro: m.pricePro, usage: m.usage, flow: m.flow, indoor: m.indoor, service: m.service }; });
      }
      function pushUndo(force) {
        if (suspendUndo) return;
        var now = Date.now();      // évite les doublons quand Geoman émet enable + dragstart pour le même geste
        if (!force && now - lastUndoAt < 400) return;
        lastUndoAt = now;
        undoStack.push(JSON.stringify(snapshotItems()));
        if (undoStack.length > 60) undoStack.shift();
        $('undoBtn').disabled = false;
      }
      function doUndo() {
        if (!undoStack.length) return;
        var snap = undoStack.pop();
        group.clearLayers(); select(null); ghost.clearLayers();
        JSON.parse(snap).forEach(function (it) {
          var layer = it.kind === 'marker' ? L.marker(it.pts[0], { icon: dot(it.color) }) : it.kind === 'polygon' ? L.polygon(it.pts) : L.polyline(it.pts);
          track(layer, { id: it.id, label: it.label, num: it.num, shop: it.shop, color: it.color, note: it.note, surface: it.surface, role: it.role, ztype: it.ztype, unit: it.unit, price: it.price, pricePro: it.pricePro, usage: it.usage, flow: it.flow, indoor: it.indoor, service: it.service });
        });
        $('undoBtn').disabled = !undoStack.length;
        lastUndoAt = Date.now();      // Geoman ré-active ses modes globaux juste après un ré-ajout de couche (setTimeout) : cet instantané fantôme est ignoré
        count();
      }
      $('undoBtn').addEventListener('click', doUndo);
      document.addEventListener('keydown', function (e) {
        if (!(e.ctrlKey || e.metaKey) || e.shiftKey || e.key.toLowerCase() !== 'z') return;
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;   // Ctrl+Z reste natif dans les champs
        e.preventDefault(); doUndo();
      });

      (saved.items || []).forEach(function (it) {
        var layer = it.kind === 'marker' ? L.marker(it.pts[0], { icon: dot(it.color) }) : it.kind === 'polygon' ? L.polygon(it.pts) : L.polyline(it.pts);
        track(layer, { id: it.id, label: it.label, num: it.num, shop: it.shop, color: it.color, note: it.note, surface: it.surface, role: it.role || 'slot', ztype: it.ztype, unit: it.unit, price: it.price_cents ? (it.price_cents / 100).toString().replace('.', ',') : '', pricePro: it.price_pro_cents ? (it.price_pro_cents / 100).toString().replace('.', ',') : '', usage: it.usage, flow: it.flow, indoor: it.indoor, service: it.service });
      });
      loading = false; restack();
      if (!saved.center && group.getLayers().length) map.fitBounds(group.getBounds().pad(0.2));
      count();

      // --- Numérotation ---
      function numText() { var n = parseInt($('numNext').value, 10); n = isNaN(n) ? 1 : n; var t = $('numPrefix').value + n; $('numNext').value = n + 1; return t; }
      function autoNum(layer) { if ($('numAuto').checked && layer instanceof L.Polygon && !isZone(layer) && !isTerr(layer) && !layer._plan.num) { layer._plan.num = numText(); look(layer); } }
      $('numAll').addEventListener('click', function () {
        var only = $('numOnlyEmpty').checked, order = $('numOrder').value, byZone = $('numByZone').checked, list = polygons().filter(function (l) { return !isZone(l) && !isTerr(l) && (!only || !l._plan.num); });
        if (!list.length) { $('count').textContent = 'Aucun emplacement à numéroter.'; return; }
        if (!only && !confirm('Renuméroter ' + list.length + ' emplacement(s), y compris ceux qui ont déjà un numéro ?')) return;
        pushUndo();
        if (order !== 'created') {
          var info = list.map(function (l) { var c = centroid(l), p = points(l), la = p.map(function (x) { return x[0]; }), ln = p.map(function (x) { return x[1]; }); return { l: l, lat: c[0], lng: c[1], h: Math.max.apply(null, la) - Math.min.apply(null, la), w: Math.max.apply(null, ln) - Math.min.apply(null, ln) }; });
          var hs = info.map(function (i) { return i.h; }).sort(function (a, b) { return a - b; }), ws = info.map(function (i) { return i.w; }).sort(function (a, b) { return a - b; });
          var tolLat = hs[Math.floor(hs.length / 2)] * 0.5, tolLng = ws[Math.floor(ws.length / 2)] * 0.5;
          var bands = function (key, tol, dir) {   // regroupe en lignes (ou colonnes) puis ordonne dans chaque bande
            var s = info.slice().sort(function (a, b) { return dir * (a[key] - b[key]); }), out = [], cur = [], ref = null;
            s.forEach(function (i) { if (ref === null || Math.abs(i[key] - ref) > tol) { if (cur.length) out.push(cur); cur = []; ref = i[key]; } cur.push(i); });
            if (cur.length) out.push(cur); return out;
          };
          var ordered = [];
          if (order === 'rows') bands('lat', tolLat, -1).forEach(function (row) { row.sort(function (a, b) { return a.lng - b.lng; }).forEach(function (i) { ordered.push(i.l); }); });
          else bands('lng', tolLng, 1).forEach(function (col) { col.sort(function (a, b) { return b.lat - a.lat; }).forEach(function (i) { ordered.push(i.l); }); });
          list = ordered;
        }
        if (byZone) {      // recommence à « prochain numéro » dans chaque zone ; le N° de la zone sert de préfixe
          var start = 1, counters = {};
          list.forEach(function (l) { var z = zoneOf(l), k = z ? z._plan.id : '-'; counters[k] = counters[k] || start; l._plan.num = (z && z._plan.num ? z._plan.num + '-' : $('numPrefix').value) + counters[k]++; look(l); });
        } else list.forEach(function (l) { l._plan.num = numText(); look(l); });
        if (selected) select(selected); count();
      });

      // --- Pose d'une surface du catalogue ---
      function rectAround(c, w, d, angle) {
        var kLat = 111320, kLng = 111320 * Math.cos(c.lat * Math.PI / 180), a = angle * Math.PI / 180, cs = Math.cos(a), sn = Math.sin(a);
        return [[-w / 2, -d / 2], [w / 2, -d / 2], [w / 2, d / 2], [-w / 2, d / 2]].map(function (p) { var x = p[0] * cs - p[1] * sn, y = p[0] * sn + p[1] * cs; return [c.lat + y / kLat, c.lng + x / kLng]; });
      }
      function startPlace(entry) {
        placing = entry; map.pm.disableDraw(); select(null);
        document.body.classList.add('placing'); $('placeStop').hidden = false;
        $('mode').textContent = 'Pose : cliquez sur la carte pour placer « ' + entry.name + ' » (' + entry.w + ' × ' + entry.d + ' m). Échap pour terminer.';
      }
      function stopPlace() { placing = null; document.body.classList.remove('placing'); $('placeStop').hidden = true; $('mode').textContent = hidden ? 'Plan seul (fond masqué)' : ''; }
      $('placeStop').addEventListener('click', stopPlace);
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (placing) { e.__posee = true; stopPlace(); return; }
        if (vertexMode) { e.__posee = true; setVertexMode(null); }
      });
      map.on('click', function (e) {
        if (vertexMode) { vertexApply(e.latlng); return; }
        if (!placing) { select(null); return; }
        var angle = parseFloat($('angle').value) || 0;
        pushUndo(true);
        var layer = track(L.polygon(rectAround(e.latlng, placing.w, placing.d, angle)), { color: placing.color, surface: placing.id, label: '' });
        autoNum(layer); count();
      });

      // --- Catalogue de surfaces ---
      function renderCatalog() {
        var tb = $('catBody'); tb.textContent = '';
        function refreshArea() { [].forEach.call(tb.querySelectorAll('tr[data-idx]'), function (tr) { var c = catalog[+tr.dataset.idx], a = tr.querySelector('.area'); if (c && a) a.textContent = fmtM2(c.w * c.d) + ' m²'; }); }
        if (!catalog.length) { var r0 = document.createElement('tr'), c0 = document.createElement('td'); c0.colSpan = 6; c0.textContent = 'Aucune surface : ajoutez-en une.'; r0.appendChild(c0); tb.appendChild(r0); }
        catalog.forEach(function (c, idx) {
          var tr = document.createElement('tr');
          function cell(inner, cls) { var td = document.createElement('td'); if (cls) td.className = cls; td.appendChild(inner); tr.appendChild(td); return td; }
          function input(type, val, set, attrs) { var i = document.createElement('input'); i.type = type; i.value = val; Object.keys(attrs || {}).forEach(function (k) { i.setAttribute(k, attrs[k]); }); i.addEventListener('input', function () { set(i.value); refreshArea(); }); return i; }
          cell(input('text', c.name, function (v) { c.name = v; }, { maxlength: 40, 'aria-label': 'Nom de la surface' }));
          cell(input('number', c.w, function (v) { c.w = parseFloat(v) || 0; }, { min: 0.5, max: 500, step: 0.1, 'aria-label': 'Largeur en mètres' }));
          cell(input('number', c.d, function (v) { c.d = parseFloat(v) || 0; }, { min: 0.5, max: 500, step: 0.1, 'aria-label': 'Profondeur en mètres' }));
          cell(input('color', c.color, function (v) { c.color = v; }));
          var area = cell(document.createElement('span'), 'num'); area.firstChild.className = 'area'; area.firstChild.textContent = fmtM2(c.w * c.d) + ' m²';
          var acts = document.createElement('div'); acts.className = 'row'; acts.style.gap = '4px';
          var place = document.createElement('button'); place.type = 'button'; place.className = 'btn mini'; place.textContent = 'Poser';
          place.addEventListener('click', function () { if (c.w >= 0.5 && c.d >= 0.5) startPlace(c); });
          var del = document.createElement('button'); del.type = 'button'; del.className = 'btn mini danger'; del.textContent = 'Retirer';
          del.addEventListener('click', function () { catalog.splice(idx, 1); renderCatalog(); });
          acts.appendChild(place); acts.appendChild(del); cell(acts);
          tr.dataset.idx = idx; tb.appendChild(tr);
        });
      }
      $('catAdd').addEventListener('click', function () { catalog.push({ id: uid(), name: 'Stand 3 × 2 m', w: 3, d: 2, color: '#b5502e' }); renderCatalog(); });
      if (!catalog.length) catalog = [{ id: uid(), name: 'Stand 2 × 2 m', w: 2, d: 2, color: '#b5502e' }, { id: uid(), name: 'Stand 3 × 2 m', w: 3, d: 2, color: '#2f6b4f' }, { id: uid(), name: 'Emplacement 4 × 3 m', w: 4, d: 3, color: '#3b5fa8' }];
      renderCatalog();

      // Déplacement, modification des coins, rotation à la souris : zones, appartenance et tarifs sont recalculés
      group.on('pm:edit pm:dragend pm:rotateend', function () { renderList(); if (selected) select(selected); });
      // Instantané avant le geste : Geoman émet pm:enable à l'activation d'un mode et pm:dragstart / pm:rotatestart au début du geste (fenêtre de 400 ms = un seul instantané)
      group.on('pm:enable pm:dragstart pm:rotatestart', function () { pushUndo(); });

      // --- Création au crayon, suppression ---
      map.on('pm:create', function (e) {
        var layer = e.layer;
        if (e.shape === 'Marker') { layer.pm.disable(); map.pm.disableDraw(); }   // un repère à la fois : sans cela l'outil reste actif
        pushUndo(true);
        track(layer, {});
        autoNum(layer); select(layer); count();
      });
      map.on('pm:remove', function (e) {
        var snap = snapshotItems();
        if (e.layer._plan && !snap.some(function (i) { return i.id === e.layer._plan.id; })) {   // Geoman peut déjà l'avoir retiré du groupe
          var m = e.layer._plan;
          snap.push({ id: m.id, kind: kindOf(e.layer), pts: points(e.layer), label: m.label, num: m.num, shop: m.shop, color: m.color, note: m.note, surface: m.surface, role: m.role, ztype: m.ztype, unit: m.unit, price: m.price, pricePro: m.pricePro });
        }
        undoStack.push(JSON.stringify(snap));
        if (undoStack.length > 60) undoStack.shift();
        $('undoBtn').disabled = false;
        if (e.layer === selected) select(null); group.removeLayer(e.layer); count();
      });

      // --- Rotation d'un élément autour de son centre (calculée en mètres : les formes gardent leurs proportions) ---
      function rotateLayer(layer, deg) {
        if (layer instanceof L.Marker) return;
        pushUndo(true);
        var p = points(layer), la = 0, ln = 0; p.forEach(function (x) { la += x[0]; ln += x[1]; }); la /= p.length; ln /= p.length;
        var kLat = 111320, kLng = 111320 * Math.cos(la * Math.PI / 180), a = deg * Math.PI / 180, c = Math.cos(a), s = Math.sin(a);
        var out = p.map(function (x) { var X = (x[1] - ln) * kLng, Y = (x[0] - la) * kLat; return [la + (-X * s + Y * c) / kLat, ln + (X * c + Y * s) / kLng]; });   // sens horaire pour un angle positif
        var editing = layer.pm && layer.pm.enabled && layer.pm.enabled();
        suspendUndo = true;      // pm:enable émis par la réactivation ne doit pas créer un second instantané
        if (editing) layer.pm.disable();
        layer.setLatLngs(out);
        if (editing) layer.pm.enable();
        suspendUndo = false;
        look(layer); renderList(); if (selected === layer) select(layer);
      }
      document.querySelectorAll('#rotBox [data-rot]').forEach(function (b) { b.addEventListener('click', function () { if (selected) rotateLayer(selected, parseFloat(b.dataset.rot)); }); });
      $('rotGo').addEventListener('click', function () { var d = parseFloat($('rotAngle').value); if (selected && !isNaN(d)) rotateLayer(selected, d); });

      // --- Fiche de l'élément sélectionné ---
      function select(layer) {
        if (selected && selected !== layer) showLabel(selected, false);
        selected = layer;
        $('sel').hidden = !layer;
        if (!layer) { setVertexMode(null); ghost.clearLayers(); return; }
        showLabel(layer, true);
        if ($('sel').getBoundingClientRect().top > window.innerHeight) $('sel').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        var m = layer._plan, p = points(layer);
        var poly = layer instanceof L.Polygon;
        $('vertexBox').hidden = !poly;
        if (!poly) setVertexMode(null);
        $('natureBox').hidden = !poly; $('selRole').value = m.role; $('ztypeBox').hidden = !isZone(layer); $('selZtype').value = m.ztype; $('priceBox').hidden = !isZone(layer) || !data.features.tarifs;
        $('usageBox').hidden = false; $('selUsage').value = m.usage || '';
        $('flowBox').hidden = m.usage !== 'circulation'; $('selFlow').value = m.flow || '';
        $('serviceBox').hidden = m.usage !== 'service'; $('selService').value = m.service || '';
        $('indoorBox').hidden = !poly || isTerr(layer); $('selIndoor').checked = !!m.indoor;
        $('subBox').hidden = !(poly && p.length > 4 && !isTerr(layer));
        $('selUnit').value = m.unit; $('selPrice').value = m.price; $('selPricePro').value = m.pricePro;
        $('selLabel').value = m.label; $('selNum').value = m.num; $('selColor').value = m.color; $('selNote').value = m.note;
        shopSel.value = m.shop in data.shops ? m.shop : '';
        var info = '';
        if (layer instanceof L.Polygon) {
          info = 'Surface : ' + fmtM2(areaM2(p)) + ' m²';
          var c = catalog.filter(function (x) { return x.id === m.surface; })[0]; if (c) info += ' (' + c.name + ')';
          if (!isZone(layer) && !isTerr(layer)) { var zz = zoneOf(layer), sp = slotPrice(layer, false), sq = slotPrice(layer, true); info += zz ? ' · Zone : ' + zoneName(zz) : ' · hors de toute zone'; if (sp != null) info += ' · Tarif : ' + eur(sp) + (sq != null && sq !== sp ? ' (entreprise ' + eur(sq) + ')' : ''); }
          if (isTerr(layer)) { var inside = 0; polygons().forEach(function (o) { if (o !== layer && !isZone(o) && !isTerr(o) && pointIn(p, centroid(o)[0], centroid(o)[1])) inside++; }); info += ' · ' + inside + ' emplacement(s) à l\'intérieur'; }
          if (m.usage && data.usages[m.usage]) info += ' · ' + data.usages[m.usage];
        }
        $('selInfo').textContent = info;
        $('rotBox').hidden = layer instanceof L.Marker;
        $('splitBox').hidden = !(layer instanceof L.Polygon && p.length === 4) || isTerr(layer);
        $('splitTitle').textContent = isZone(layer) ? 'Découper cette zone en parcelles' : 'Diviser cet emplacement';
        $('terrBox').hidden = !isTerr(layer);
        justSplit = false;
        if (!$('splitBox').hidden) { syncSimPrice(layer); splitRefresh(); }
        else if (isTerr(layer)) { fillTerrSurf(); terrRefresh(); }
        else ghost.clearLayers();
      }
      $('selLabel').addEventListener('input', function () { if (selected) { selected._plan.label = this.value; look(selected); renderList(); } });
      $('selNum').addEventListener('input', function () { if (selected) { selected._plan.num = this.value; look(selected); renderList(); } });
      $('selColor').addEventListener('input', function () { if (selected) { selected._plan.color = this.value; look(selected); } });
      $('selNote').addEventListener('input', function () { if (selected) selected._plan.note = this.value; });
      $('selRole').addEventListener('change', function () { if (selected && selected instanceof L.Polygon) { pushUndo(true); selected._plan.role = this.value; look(selected); restack(); select(selected); count(); } });
      $('selZtype').addEventListener('change', function () { if (selected) { selected._plan.ztype = this.value; var d = data.locationDefaults && data.locationDefaults[this.value]; if (d) { if (!selected._plan.unit || selected._plan.unit === 'slot') selected._plan.unit = d.unit || 'slot'; $('selUnit').value = selected._plan.unit; if (!selected._plan.price && d.price) { selected._plan.price = d.price; $('selPrice').value = d.price; } } renderList(); } });
      $('selUnit').addEventListener('change', function () { if (selected) { selected._plan.unit = this.value; renderList(); select(selected); } });
      $('selPrice').addEventListener('input', function () { if (selected) { selected._plan.price = this.value; renderList(); } });
      $('selPricePro').addEventListener('input', function () { if (selected) { selected._plan.pricePro = this.value; renderList(); } });
      $('selUsage').addEventListener('change', function () {
        if (!selected) return;
        pushUndo(true);
        var m = selected._plan; m.usage = this.value;
        if (m.usage) { m.color = UCOL[m.usage] || m.color; $('selColor').value = m.color; }
        if (m.usage !== 'service') m.service = '';
        if (m.usage !== 'circulation') m.flow = '';
        look(selected); select(selected); count();
      });
      $('selFlow').addEventListener('change', function () { if (selected) { selected._plan.flow = this.value; look(selected); renderList(); } });
      $('selService').addEventListener('input', function () { if (selected) selected._plan.service = this.value; });
      $('selIndoor').addEventListener('change', function () { if (selected) selected._plan.indoor = this.checked; });
      shopSel.addEventListener('change', function () {
        if (!selected) return;
        selected._plan.shop = this.value;
        if (this.value && !selected._plan.label) { selected._plan.label = data.shops[this.value]; $('selLabel').value = selected._plan.label; look(selected); }
        renderList();
      });
      $('selDel').addEventListener('click', function () { if (selected) { pushUndo(true); group.removeLayer(selected); select(null); count(); } });
      $('selClose').addEventListener('click', function () { select(null); });

      // --- Points d'ancrage : insérer un point sur un bord ou retirer un coin d'une zone ---
      var vertexMode = null;
      function layerLatLngs(layer) { var ll = layer.getLatLngs(); while (Array.isArray(ll[0])) ll = ll[0]; return ll; }
      function nearestSegment(layer, latlng) {     // projette le clic sur le bord le plus proche : la forme n'est pas déformée
        var pts = layerLatLngs(layer), p = map.latLngToLayerPoint(latlng), best = { i: 0, pt: p, d: Infinity };
        for (var i = 0; i < pts.length; i++) {
          var a = map.latLngToLayerPoint(pts[i]), b = map.latLngToLayerPoint(pts[(i + 1) % pts.length]);
          var ab = b.subtract(a), d2 = ab.x * ab.x + ab.y * ab.y;
          var t = d2 ? Math.max(0, Math.min(1, ((p.x - a.x) * ab.x + (p.y - a.y) * ab.y) / d2)) : 0;
          var q = a.add(ab.multiplyBy(t)), d = p.distanceTo(q);
          if (d < best.d) best = { i: i, pt: q, d: d };
        }
        return best;
      }
      function nearestVertex(layer, latlng) {
        var pts = layerLatLngs(layer), p = map.latLngToLayerPoint(latlng), best = -1, bestD = Infinity;
        pts.forEach(function (v, i) { var d = p.distanceTo(map.latLngToLayerPoint(v)); if (d < bestD) { bestD = d; best = i; } });
        return bestD <= 18 ? best : -1;
      }
      function setVertexMode(mode) {
        vertexMode = mode;
        planEl.classList.toggle('vertexing', !!mode);
        $('vertexAdd').setAttribute('aria-pressed', mode === 'add' ? 'true' : 'false');
        $('vertexDel').setAttribute('aria-pressed', mode === 'del' ? 'true' : 'false');
        $('vertexStop').hidden = !mode;
        if (mode) {     // l'édition Geoman et l'insertion de points ne doivent pas se disputer les clics
          map.pm.disableDraw();
          if (map.pm.globalEditModeEnabled && map.pm.globalEditModeEnabled()) map.pm.disableGlobalEditMode();
        }
        $('vertexMsg').textContent = mode === 'add' ? 'Cliquez sur le plan : le point s\'insère sur le bord le plus proche.'
          : mode === 'del' ? 'Cliquez sur un point (coin) de la zone pour le retirer.'
          : 'Ajoutez un point sur le bord le plus proche ou retirez un coin pour ajuster la forme (3 points minimum).';
      }
      function vertexApply(latlng) {
        if (!vertexMode || !selected || !(selected instanceof L.Polygon)) { setVertexMode(null); return; }
        var pts = layerLatLngs(selected);
        if (vertexMode === 'add') {
          pushUndo(true);
          var seg = nearestSegment(selected, latlng), next = pts.slice();
          next.splice(seg.i + 1, 0, map.layerPointToLatLng(seg.pt));
          selected.setLatLngs(next);
          $('vertexMsg').textContent = 'Point ajouté : ' + next.length + ' points.';
        } else {
          if (pts.length <= 3) { $('vertexMsg').textContent = 'Une zone doit garder au moins 3 points ; pour l\'ajuster autrement, découpez-la ou supprimez-la.'; return; }
          var idx = nearestVertex(selected, latlng);
          if (idx < 0) { $('vertexMsg').textContent = 'Cliquez sur un point (coin) de la zone pour le retirer.'; return; }
          pushUndo(true);
          var rest = pts.slice(); rest.splice(idx, 1);
          selected.setLatLngs(rest);
          $('vertexMsg').textContent = 'Point supprimé : ' + rest.length + ' points restants.';
        }
        look(selected); renderList(); select(selected); count();
      }
      $('vertexAdd').addEventListener('click', function () { setVertexMode(vertexMode === 'add' ? null : 'add'); });
      $('vertexDel').addEventListener('click', function () { setVertexMode(vertexMode === 'del' ? null : 'del'); });
      $('vertexStop').addEventListener('click', function () { setVertexMode(null); });

      // --- Sous-zones : découpe d'une forme quelconque en bandes parallèles (Sutherland–Hodgman) ---
      function clipHalf(poly, axis, v, keepLess) {
        var out = [];
        for (var i = 0; i < poly.length; i++) {
          var a = poly[i], b = poly[(i + 1) % poly.length], ai = axis ? a[1] : a[0], bi = axis ? b[1] : b[0];
          var ain = keepLess ? ai <= v : ai >= v, bin = keepLess ? bi <= v : bi >= v;
          if (ain) out.push(a);
          if (ain !== bin) { var t = (v - ai) / ((bi - ai) || 1e-12); out.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]); }
        }
        return out;
      }
      function dedupe(poly) {
        var out = [];
        poly.forEach(function (p) { var q = out[out.length - 1]; if (!q || Math.abs(q[0] - p[0]) > 1e-9 || Math.abs(q[1] - p[1]) > 1e-9) out.push(p); });
        if (out.length > 2) { var f = out[0], l = out[out.length - 1]; if (Math.abs(f[0] - l[0]) < 1e-9 && Math.abs(f[1] - l[1]) < 1e-9) out.pop(); }
        return out;
      }
      function subSplit(layer, n, mode) {
        var p = points(layer), m = layer._plan;
        var las = p.map(function (q) { return q[0]; }), lns = p.map(function (q) { return q[1]; });
        var minLa = Math.min.apply(null, las), maxLa = Math.max.apply(null, las), minLn = Math.min.apply(null, lns), maxLn = Math.max.apply(null, lns);
        var h = (maxLa - minLa) * 111320, w = (maxLn - minLn) * 111320 * Math.cos(p[0][0] * Math.PI / 180);
        var axis = mode === 'lat' ? 0 : mode === 'lng' ? 1 : (h >= w ? 0 : 1);
        var lo = axis ? minLn : minLa, hi = axis ? maxLn : maxLa, step = (hi - lo) / n, pieces = [];
        for (var k = 0; k < n; k++) {
          var a = lo + step * k, b = k === n - 1 ? hi : lo + step * (k + 1) + 1e-12;
          var piece = dedupe(clipHalf(clipHalf(p, axis, b, true), axis, a, false));
          if (piece.length >= 3 && areaM2(piece) > 1) pieces.push(piece);
        }
        if (pieces.length < 2) return 0;
        pushUndo(true);
        pieces.forEach(function (pts, i) {
          track(L.polygon(pts), { role: m.role, ztype: m.ztype, label: (m.label || 'Zone') + ' ' + (i + 1) + '/' + pieces.length, color: m.color,
            unit: m.unit, price: m.price, pricePro: m.pricePro, surface: m.surface, note: m.note, shop: m.shop,
            usage: m.usage, flow: m.flow, indoor: m.indoor, service: m.service });
        });
        group.removeLayer(layer); select(null); restack(); count();
        return pieces.length;
      }
      $('subGo').addEventListener('click', function () {
        if (!selected) return;
        var made = subSplit(selected, Math.max(2, Math.min(20, parseInt($('subN').value, 10) || 2)), $('subAxis').value);
        $('count').textContent = made ? made + ' sous-zones créées. Pensez à enregistrer.' : 'Cette forme ne peut pas être divisée ainsi (forme trop fine).';
      });

      // --- Découpage d'une forme à 4 coins en parcelles, avec ou sans allée au milieu ---
      // p[0] p[1] p[2] p[3] : coins dans l'ordre ; u le long de p0→p1, v le long de p0→p3 (fractions 0..1). « f » : sens des façades, « g » : profondeur.
      function dist(a, b) { var kLat = 111320, kLng = 111320 * Math.cos(a[0] * Math.PI / 180); return Math.hypot((b[1] - a[1]) * kLng, (b[0] - a[0]) * kLat); }
      function sgn(x) { return x < 0 ? -1 : 1; }
      function num(id, def) { var n = parseFloat(String($(id).value).replace(',', '.')); return isNaN(n) ? def : n; }
      var ghost = L.layerGroup().addTo(map), justSplit = false;
      function splitPlan(over) {      // découpage demandé : { cells: [[u0,u1,v0,v1]…] (du nord au sud, de gauche à droite), aisle, F, G, nf, ng, w, d, at, quad… } ; over.nf impose le nombre par rangée
        over = over || {};
        var p = points(selected), Lu = (dist(p[0], p[1]) + dist(p[3], p[2])) / 2, Lv = (dist(p[0], p[3]) + dist(p[1], p[2])) / 2;
        var aisleOn = $('splitAisle').checked, aw = Math.max(0.5, num('splitAisleW', 3)), dir = $('splitAisleDir').value;
        var frontU = dir === 'long' ? Lu >= Lv : Lu < Lv, F = frontU ? Lu : Lv, G = frontU ? Lv : Lu;
        var aw2 = Math.min(aw, G * 0.8), a = aisleOn ? aw2 / G : 0, lo = (1 - a) / 2, hi = (1 + a) / 2, avail = aisleOn ? (G - aw2) / 2 : G, nf, ng;
        if ($('splitMode').value === 'size') {
          nf = Math.max(1, Math.floor(F / Math.max(0.5, num('splitW', 3)) + 1e-6)); ng = Math.max(1, Math.floor(avail / Math.max(0.5, num('splitD', 3)) + 1e-6));
        } else { nf = Math.max(1, Math.min(60, parseInt($('splitCols').value, 10) || 1)); ng = Math.max(1, Math.min(40, parseInt($('splitRows').value, 10) || 1)); }
        if (over.nf) nf = over.nf;
        function ranges(n, from, to) { var r = []; for (var k = 0; k < n; k++) r.push([from + (to - from) * k / n, from + (to - from) * (k + 1) / n]); return r; }
        var fs = ranges(nf, 0, 1), gs = aisleOn ? ranges(ng, 0, lo).concat(ranges(ng, hi, 1)) : ranges(ng, 0, 1), cells = [];
        gs.forEach(function (g) { fs.forEach(function (f) { cells.push(frontU ? [f[0], f[1], g[0], g[1]] : [g[0], g[1], f[0], f[1]]); }); });
        // lecture : colonnes dans le sens le plus horizontal (gauche → droite), rangées du nord au sud
        var kLat = 111320, kLng = 111320 * Math.cos(p[0][0] * Math.PI / 180);
        var U = [((p[1][1] - p[0][1]) + (p[2][1] - p[3][1])) / 2 * kLng, ((p[1][0] - p[0][0]) + (p[2][0] - p[3][0])) / 2 * kLat], V = [((p[3][1] - p[0][1]) + (p[2][1] - p[1][1])) / 2 * kLng, ((p[3][0] - p[0][0]) + (p[2][0] - p[1][0])) / 2 * kLat];
        var colU = Math.abs(U[0]) >= Math.abs(V[0]), cs = sgn(colU ? U[0] : V[0]), rs = sgn(-(colU ? V[1] : U[1]));
        function key(c) { var uc = (c[0] + c[1]) / 2, vc = (c[2] + c[3]) / 2; return colU ? [Math.round(vc * rs * 1e6), uc * cs] : [Math.round(uc * rs * 1e6), vc * cs]; }
        cells.sort(function (x, y) { var a = key(x), b = key(y); return (a[0] - b[0]) || (a[1] - b[1]); });
        function at(u, v) { return [(1 - u) * (1 - v) * p[0][0] + u * (1 - v) * p[1][0] + u * v * p[2][0] + (1 - u) * v * p[3][0], (1 - u) * (1 - v) * p[0][1] + u * (1 - v) * p[1][1] + u * v * p[2][1] + (1 - u) * v * p[3][1]]; }
        return { p: p, cells: cells, aisle: aisleOn ? (frontU ? [0, 1, lo, hi] : [lo, hi, 0, 1]) : null, Lu: Lu, Lv: Lv, F: F, G: G, nf: nf, ng: ng, perSide: aisleOn ? 2 : 1, aisleOn: aisleOn, aw: aw2, frontU: frontU,
          w: F / nf, d: avail / ng, at: at, quad: function (c) { return [at(c[0], c[2]), at(c[1], c[2]), at(c[1], c[3]), at(c[0], c[3])]; } };
      }

      // Aperçu fantôme (non enregistré) des parcelles sur la carte
      function drawGhost(sp) {
        ghost.clearLayers();
        if (!$('splitGhost').checked || justSplit || sp.cells.length > 150) return;
        var next = parseInt($('numNext').value, 10); next = isNaN(next) ? 1 : next; var numOn = $('numAuto').checked, pre = $('numPrefix').value;
        sp.cells.forEach(function (c, i) {
          var g = L.polygon(sp.quad(c), { color: '#1d4ed8', weight: 1.5, dashArray: '4 3', fillColor: '#3b82f6', fillOpacity: 0.2, interactive: false, pmIgnore: true });
          if (numOn) g.bindTooltip(text(pre + (next + i)), { permanent: true, direction: 'center', className: 'plan-label' });
          ghost.addLayer(g);
        });
        if (sp.aisle && $('splitAisleDraw').checked) ghost.addLayer(L.polygon(sp.quad(sp.aisle), { color: '#6b7280', weight: 1, dashArray: '2 4', fillColor: '#9ca3af', fillOpacity: 0.3, interactive: false, pmIgnore: true }));
      }

      // Simulation du chiffre d'affaires : hypothèses, scénarios bas / central / haut, marge et seuil de rentabilité
      function sim() {
        var pct = Math.max(0, Math.min(100, num('simPct', 0))) / 100;
        return { price: toCents($('simPrice').value), unit: $('simUnit').value, fixed: toCents($('simFixed').value), vari: toCents($('simVar').value), pct: pct,
          occ: [0, 1, 2].map(function (i) { return Math.max(0, Math.min(100, num('simOcc' + i, 0))) / 100; }), adj: [0, 1, 2].map(function (i) { return Math.max(-90, Math.min(500, num('simAdj' + i, 0))) / 100; }) };
      }
      function parcelPrice(s, w, d) { return s.unit === 'ml' ? s.price * w : s.unit === 'm2' ? s.price * w * d : s.price; }      // centimes
      function others() { var n = 0, sum = 0; polygons().forEach(function (l) { if (isZone(l) || l === selected) return; var pr = slotPrice(l, false); if (pr != null) { n++; sum += pr; } }); return { n: n, sum: sum }; }
      function scen(s, N, S, k) {          // N parcelles proposées au total, S somme de leurs tarifs (centimes) ; k = 0 bas, 1 central, 2 haut
        var occ = s.occ[k], ca = S * occ * (1 + s.adj[k]), varCost = s.vari * N * occ + s.pct * ca;
        return { occ: occ, ca: ca, varCost: varCost, fixed: s.fixed, cost: s.fixed + varCost, result: ca - s.fixed - varCost };
      }
      function seuil(s, N, S) {            // parcelles occupées pour couvrir les coûts fixes (tarif moyen du scénario central) : { q, pct } ou null (jamais) ; q = 0 sans coûts fixes
        if (s.fixed <= 0) return { q: 0, pct: 0 };
        var net = S / N * (1 + s.adj[1]) * (1 - s.pct) - s.vari;
        if (net <= 0) return null;
        var q = Math.ceil(s.fixed / net); return { q: q, pct: q / N };
      }
      function eur0(c) { return Math.round(c / 100).toLocaleString('fr-FR') + ' €'; }
      function fm(v) { return (Math.round(v * 100) / 100).toString().replace('.', ','); }
      function seuilText(sv, N) { return sv == null ? 'jamais (coût par parcelle ≥ prix)' : sv.q === 0 ? '—' : sv.q > N ? sv.q + ' (> ' + N + ' : hors d\'atteinte)' : sv.q + ' / ' + N + ' (' + Math.round(sv.pct * 100) + ' %)'; }
      function td(tr, v, cls) { var c = document.createElement('td'); c.textContent = v; if (cls) c.className = cls; tr.appendChild(c); return c; }

      function aidRefresh(sp) {
        var s = sim(), oth = others(), body = $('aidBody'), msg = $('aidMsg'), tbl = $('aidTable'), det = $('simDetail');
        body.textContent = ''; det.textContent = '';
        var wmin = Math.max(0.5, num('aidMin', 2)), wmax = Math.max(wmin, num('aidMax', 4)), nmin = Math.max(1, Math.ceil(sp.F / wmax - 1e-6)), nmax = Math.min(60, Math.floor(sp.F / wmin + 1e-6));
        var opts = [];
        for (var n = nmin; n <= nmax; n++) {
          var w = sp.F / n, N = n * sp.ng * sp.perSide, price = parcelPrice(s, w, sp.d), S = N * price;
          var lo = scen(s, N, S, 0), mid = scen(s, N, S, 1), hi = scen(s, N, S, 2);
          opts.push({ n: n, w: w, N: N, price: price, S: S, lo: lo, hi: hi, margin: mid.ca - mid.varCost, sv: seuil(s, oth.n + N, oth.sum + S), round: Math.abs(w * 2 - Math.round(w * 2)) < 0.03 });
        }
        var shown = opts;
        if (!opts.length) {
          var near = Math.max(1, Math.round(sp.F / ((wmin + wmax) / 2)));
          msg.textContent = 'Aucune division entière de ' + fm(sp.F) + ' m ne donne des parcelles de ' + fm(wmin) + ' à ' + fm(wmax) + ' m. Le plus proche : ' + near + ' parcelles de ' + fm(sp.F / near) + ' m par rangée.';
          tbl.hidden = true;
        } else {
          var mid = (wmin + wmax) / 2, best = opts.slice().sort(function (a, b) { return (Math.round(b.margin) - Math.round(a.margin)) || ((b.round ? 1 : 0) - (a.round ? 1 : 0)) || (Math.abs(a.w - mid) - Math.abs(b.w - mid)); })[0];
          msg.textContent = 'Côté de ' + fm(sp.F) + ' m' + (sp.ng > 1 || sp.perSide > 1 ? ', ' + (sp.ng * sp.perSide) + ' rangée' + (sp.ng * sp.perSide > 1 ? 's' : '') + ' de parcelles de ' + fm(sp.d) + ' m de profondeur' : ', parcelles de ' + fm(sp.d) + ' m de profondeur') + ' : '
            + opts.length + ' division' + (opts.length > 1 ? 's' : '') + ' possible' + (opts.length > 1 ? 's' : '') + ' entre ' + fm(wmin) + ' et ' + fm(wmax) + ' m. Recommandé : ' + best.n + ' par rangée (' + fm(best.w) + ' m, ' + best.N + ' parcelles)'
            + (s.price > 0 ? ' — meilleure marge au scénario central' + (s.unit === 'slot' ? ' (tarif forfaitaire : plus de parcelles = plus de CA sur le papier, vérifiez le prix au m²)' : ' (tarif proportionnel à la taille : le CA varie peu, on retient une largeur ronde)') : ' — indiquez un tarif simulé pour chiffrer') + '.';
          tbl.hidden = false;
          opts.forEach(function (o) {
            var tr = document.createElement('tr'); if (o === best) tr.className = 'best';
            td(tr, o.n + (o === best ? ' ★' : '')); td(tr, fm(o.w) + ' m', 'num'); td(tr, o.N, 'num');
            td(tr, s.price > 0 ? eur(Math.round(o.price)) : '—', 'num'); td(tr, s.price > 0 ? fm(Math.round(o.price / (o.w * sp.d)) / 100) + ' €' : '—', 'num');
            td(tr, s.price > 0 ? eur0(o.lo.ca) : '—', 'num'); td(tr, s.price > 0 ? eur0(o.hi.ca) : '—', 'num'); td(tr, s.price > 0 ? eur0(o.margin) : '—', 'num');
            td(tr, s.price > 0 ? seuilText(o.sv, oth.n + o.N) : '—', 'num');
            var c = document.createElement('td'), b = document.createElement('button'); b.type = 'button'; b.className = 'btn mini'; b.textContent = 'Utiliser'; b.addEventListener('click', function () {
              $('splitMode').value = 'count'; $('splitCols').value = o.n; justSplit = false; splitRefresh();
            }); c.appendChild(b); tr.appendChild(c); body.appendChild(tr);
          });
        }
        // Détail de la division en cours (plan entier : autres emplacements tarifés + ces parcelles)
        var N = sp.cells.length, price = parcelPrice(s, sp.w, sp.d), S = N * price, NT = oth.n + N, ST = oth.sum + S;
        if (s.price <= 0) { det.appendChild(Object.assign(document.createElement('p'), { className: 'tip', textContent: 'Saisissez un tarif simulé (ou renseignez le tarif de la zone) pour obtenir le chiffre d\'affaires et le seuil de rentabilité.' })); return; }
        var t = document.createElement('table'); t.className = 't simdet'; var th = document.createElement('thead'), hr = document.createElement('tr');
        ['Division en cours : ' + N + ' parcelles de ' + fm(sp.w) + ' × ' + fm(sp.d) + ' m à ' + eur(Math.round(price)) + (oth.n ? ' + ' + oth.n + ' autres emplacements tarifés' : ''), 'Bas', 'Central', 'Haut'].forEach(function (h, i) { var c = document.createElement('th'); c.textContent = h; if (i) c.className = 'num'; hr.appendChild(c); });
        th.appendChild(hr); t.appendChild(th); var tb = document.createElement('tbody');
        var sc = [0, 1, 2].map(function (k) { return scen(s, NT, ST, k); });
        [['Parcelles occupées', function (x, k) { return Math.round(NT * x.occ) + ' / ' + NT; }], ['Prix moyen', function (x, k) { return eur(Math.round(ST / NT * (1 + s.adj[k]))); }], ['Chiffre d\'affaires', function (x) { return eur0(x.ca); }],
          ['Coûts variables', function (x) { return eur0(x.varCost); }], ['Coûts fixes', function (x) { return eur0(x.fixed); }], ['Résultat', function (x) { return (x.result < 0 ? '− ' : '') + eur0(Math.abs(x.result)); }]].forEach(function (r, ri) {
          var tr = document.createElement('tr'); if (ri === 5) tr.className = 'tot'; var h = document.createElement('th'); h.textContent = r[0]; tr.appendChild(h);
          sc.forEach(function (x, k) { var c = td(tr, r[1](x, k), 'num'); if (ri === 5) c.className += x.result < 0 ? ' neg' : ' pos'; }); tb.appendChild(tr);
        });
        t.appendChild(tb); det.appendChild(t);
        var p = document.createElement('p'); p.className = 'tip';
        var sv = seuil(s, NT, ST);
        p.textContent = 'Seuil de rentabilité (tarif central) : ' + (s.fixed <= 0 ? 'pas de coûts fixes renseignés, rentable dès la première parcelle louée.' : sv == null ? 'jamais atteint, le coût par parcelle et les frais dépassent le tarif.'
          : sv.q + ' parcelle' + (sv.q > 1 ? 's' : '') + ' occupée' + (sv.q > 1 ? 's' : '') + ' sur ' + NT + ' (' + Math.round(sv.pct * 100) + ' %)' + (sv.q > NT ? ' : impossible avec ce plan, relevez les tarifs ou ajoutez des parcelles.' : sv.pct > s.occ[1] ? ' : au-dessus du remplissage central (' + Math.round(s.occ[1] * 100) + ' %), le tarif paraît trop bas.' : sv.pct > s.occ[0] ? ' : au-dessus du scénario bas (' + Math.round(s.occ[0] * 100) + ' %), l\'événement n\'est pas rentable si la fréquentation est faible.' : ' : sous le scénario bas, le positionnement prix est confortable.'));
        det.appendChild(p);
      }

      // Tarif de simulation repris de la zone (ou de la zone de l'emplacement sélectionné)
      function syncSimPrice(layer) {
        var z = isZone(layer) ? layer : zoneOf(layer);
        if (z && toCents(z._plan.price) > 0) { $('simPrice').value = z._plan.price; $('simUnit').value = z._plan.unit; }
        else if (!$('simPrice').value) $('simUnit').value = 'slot';
      }

      // --- Découverte des emplacements potentiels d'un territoire : quadrillage de la surface choisie (repère métrique tourné),
      // une cellule n'est retenue que si ses 4 coins sont entièrement dans le territoire ---
      function fillTerrSurf() {
        var sel = $('terrSurf'); sel.textContent = '';
        catalog.forEach(function (c) { var o = document.createElement('option'); o.value = c.id; o.textContent = c.name + ' (' + fm(c.w) + ' × ' + fm(c.d) + ' m)'; sel.appendChild(o); });
      }
      function terrCandidates(t, surf, gap, angle) {
        var p = points(t), lat0 = p[0][0], lng0 = p[0][1], kLat = 111320, kLng = 111320 * Math.cos(lat0 * Math.PI / 180);
        var xy = p.map(function (q) { return [(q[1] - lng0) * kLng, (q[0] - lat0) * kLat]; });
        var cx = 0, cy = 0; xy.forEach(function (q) { cx += q[0] / xy.length; cy += q[1] / xy.length; });
        var a = angle * Math.PI / 180, cs = Math.cos(a), sn = Math.sin(a);
        var minU = Infinity, maxU = -Infinity, minV = Infinity, maxV = -Infinity;
        xy.forEach(function (q) { var dx = q[0] - cx, dy = q[1] - cy, u = dx * cs + dy * sn, v = -dx * sn + dy * cs; minU = Math.min(minU, u); maxU = Math.max(maxU, u); minV = Math.min(minV, v); maxV = Math.max(maxV, v); });
        var w = Math.max(0.5, surf.w + gap), d = Math.max(0.5, surf.d + gap), out = [];
        for (var u = minU + w / 2; u <= maxU - w / 2 + 1e-9 && out.length < 400; u += w) {
          for (var v = minV + d / 2; v <= maxV - d / 2 + 1e-9 && out.length < 400; v += d) {
            var ok = true;
            [[-1, -1], [1, -1], [1, 1], [-1, 1]].forEach(function (sg) {
              if (!ok) return;
              var uu = u + sg[0] * surf.w / 2, vv = v + sg[1] * surf.d / 2;
              if (!pointIn(p, lat0 + (cy + (uu * sn + vv * cs)) / kLat, lng0 + (cx + (uu * cs - vv * sn)) / kLng)) ok = false;
            });
            if (ok) out.push(rectAround({ lat: lat0 + (cy + (u * sn + v * cs)) / kLat, lng: lng0 + (cx + (u * cs - v * sn)) / kLng }, surf.w, surf.d, angle));
          }
        }
        return out;
      }
      var justTerr = false;
      function terrRefresh() {
        if (!selected || !isTerr(selected)) { ghost.clearLayers(); return; }
        var surf = catalog.filter(function (x) { return x.id === $('terrSurf').value; })[0] || catalog[0];
        if (surf && $('terrSurf').value !== surf.id) $('terrSurf').value = surf.id;
        ghost.clearLayers();
        if (!surf) { $('terrPreview').textContent = 'Ajoutez d\'abord une surface au catalogue (bloc « Catalogue de surfaces »).'; $('terrApply').disabled = true; return; }
        $('terrApply').disabled = false;
        var gap = Math.max(0, num('terrGap', 1)), angle = parseFloat($('terrAngle').value) || 0;
        var cells = terrCandidates(selected, surf, gap, angle);
        $('terrPreview').textContent = cells.length
          ? '→ ' + cells.length + ' emplacement' + (cells.length > 1 ? 's' : '') + ' de ' + fm(surf.w) + ' × ' + fm(surf.d) + ' m (écart ' + fm(gap) + ' m, angle ' + angle + '°) tiennent entièrement dans ce territoire.'
            + (cells.length >= 400 ? ' ⚠ aperçu limité à 400 emplacements.' : '') + (group.getLayers().length + cells.length > 600 ? ' ⚠ plus de 600 éléments sur le plan.' : '')
          : 'Aucun emplacement de ' + fm(surf.w) + ' × ' + fm(surf.d) + ' m ne tient entièrement dans ce territoire (écart ' + fm(gap) + ' m) : réduisez la surface ou l\'écart, ou redessinez le territoire plus grand.';
        if ($('terrGhost').checked && !justTerr && cells.length && cells.length <= 150) {
          var next = parseInt($('numNext').value, 10); next = isNaN(next) ? 1 : next; var numOn = $('numAuto').checked, pre = $('numPrefix').value;
          cells.forEach(function (q, i) {
            var g = L.polygon(q, { color: '#1d4ed8', weight: 1.5, dashArray: '4 3', fillColor: '#3b82f6', fillOpacity: 0.2, interactive: false, pmIgnore: true });
            if (numOn) g.bindTooltip(text(pre + (next + i)), { permanent: true, direction: 'center', className: 'plan-label' });
            ghost.addLayer(g);
          });
        }
      }
      ['terrSurf', 'terrGap', 'terrAngle', 'terrGhost'].forEach(function (id) {
        $(id).addEventListener('input', function () { if (selected && isTerr(selected)) terrRefresh(); });
        $(id).addEventListener('change', function () { if (selected && isTerr(selected)) terrRefresh(); });
      });
      $('terrApply').addEventListener('click', function () {
        if (!selected || !isTerr(selected)) return;
        var surf = catalog.filter(function (x) { return x.id === $('terrSurf').value; })[0];
        if (!surf) return;
        var cells = terrCandidates(selected, surf, Math.max(0, num('terrGap', 1)), parseFloat($('terrAngle').value) || 0);
        if (!cells.length) { terrRefresh(); return; }
        if (group.getLayers().length + cells.length > 600) { $('count').textContent = 'Trop d\'éléments sur le plan (600 au plus).'; return; }
        pushUndo(true);
        cells.forEach(function (q) { autoNum(track(L.polygon(q), { color: surf.color, surface: surf.id, label: '' })); });
        justTerr = true; select(selected); justTerr = false; count();      // l'aperçu n'est pas redessiné par-dessus les emplacements créés
      });

      function splitRefresh() {
        if (!selected || !(selected instanceof L.Polygon) || points(selected).length !== 4) { ghost.clearLayers(); return; }
        var sizeMode = $('splitMode').value === 'size', aisleOn = $('splitAisle').checked;
        $('splitCountBox').hidden = sizeMode; $('splitSizeBox').hidden = !sizeMode; $('splitAisleBox').hidden = !aisleOn;
        $('splitColsLbl').textContent = 'Parcelles par rangée';
        $('splitRowsLbl').textContent = aisleOn ? 'Rangées de chaque côté' : 'Rangées';
        var sp = splitPlan(), n = sp.cells.length;
        $('splitPreview').textContent = '→ ' + n + ' parcelle' + (n > 1 ? 's' : '') + ' de ≈ ' + fm(sp.w) + ' × ' + fm(sp.d) + ' m'
          + (sp.aisle ? ', allée de ' + fm(sp.aw) + ' m dans le sens de ' + fm(sp.F) + ' m' : '') + ' — zone de ' + Math.round(sp.Lu) + ' × ' + Math.round(sp.Lv) + ' m.'
          + (sp.w < 1 || sp.d < 1 ? ' ⚠ parcelles très petites.' : '') + (group.getLayers().length + n > 600 ? ' ⚠ plus de 600 éléments sur le plan.' : '');
        drawGhost(sp); aidRefresh(sp);
      }
      ['splitMode', 'splitCols', 'splitRows', 'splitW', 'splitD', 'splitAisle', 'splitAisleW', 'splitAisleDir', 'splitAisleDraw', 'splitGhost', 'aidMin', 'aidMax', 'simPrice', 'simUnit', 'simFixed', 'simVar', 'simPct',
        'simOcc0', 'simOcc1', 'simOcc2', 'simAdj0', 'simAdj1', 'simAdj2', 'numNext', 'numPrefix', 'numAuto'].forEach(function (id) {
        function go() { justSplit = false; if (!$('splitBox').hidden) splitRefresh(); }
        $(id).addEventListener('input', go); $(id).addEventListener('change', go);
      });
      $('splitGo').addEventListener('click', function () {
        if (!selected || !(selected instanceof L.Polygon) || points(selected).length !== 4) return;
        var sp = splitPlan(), n = sp.cells.length + (sp.aisle && $('splitAisleDraw').checked ? 1 : 0);
        if (sp.cells.length < 2 && !sp.aisle) { $('count').textContent = 'Indiquez au moins 2 parcelles.'; return; }
        if (group.getLayers().length + n > 600) { $('count').textContent = 'Trop d\'éléments sur le plan (600 au plus).'; return; }
        pushUndo(true);
        var m = selected._plan, src = selected, fromZone = isZone(src), made = [];
        sp.cells.forEach(function (c) { made.push(track(L.polygon(sp.quad(c)), { color: m.color, shop: fromZone ? '' : m.shop, surface: fromZone ? '' : m.surface })); });
        if (sp.aisle && $('splitAisleDraw').checked) track(L.polygon(sp.quad(sp.aisle)), { role: 'zone', ztype: 'allee', label: 'Allée', color: '#8a8a8a' });
        ghost.clearLayers();
        if (!fromZone) { group.removeLayer(src); select(null); } else select(src);      // une zone reste en place : elle contient désormais les parcelles
        if ($('numAuto').checked) made.forEach(function (l) { l._plan.num = numText(); look(l); });
        ghost.clearLayers(); justSplit = true;
        count();
      });

      // --- Assistant de zonage : zones du territoire proposées par la BD TOPO (IGN) ---
      var zdRows = [], zdEntries = [];
      function fmtNum(v) { return Math.round(v).toLocaleString('fr-FR'); }
      function fillTerrList() {
        var sel = $('zdTerr'), list = polygons().filter(isTerr), cur = sel.value;
        sel.textContent = '';
        if (!list.length) { var o = document.createElement('option'); o.value = ''; o.textContent = '— aucun territoire sur ce plan —'; sel.appendChild(o); $('zdGo').disabled = false; return; }
        list.forEach(function (t, i) { var o = document.createElement('option'); o.value = t._plan.id; o.textContent = (t._plan.label || 'Territoire ' + (i + 1)) + ' · ' + fmtNum(areaM2(points(t))) + ' m²'; sel.appendChild(o); });
        $('zdGo').disabled = false;
        sel.value = list.some(function (t) { return t._plan.id === cur; }) ? cur : list[0]._plan.id;
      }
      function terrById(id) { return polygons().filter(isTerr).filter(function (t) { return t._plan.id === id; })[0] || null; }
      function zdRender() {
        var body = $('zdBody'); body.textContent = '';
        zdRows.forEach(function (r) {
          var tr = document.createElement('tr');
          var c0 = document.createElement('td'), cb = document.createElement('input'); cb.type = 'checkbox'; cb.checked = r.on; c0.appendChild(cb); tr.appendChild(c0);
          var c1 = document.createElement('td'), lab = document.createElement('input'), cell = document.createElement('span');
          cell.className = 'zdcell';
          lab.type = 'text'; lab.maxLength = 60; lab.value = r.label;
          cell.appendChild(lab);
          if (r.ai) {
            var badge = document.createElement('span');
            badge.className = 'zdai' + (r.extra ? ' extra' : '');
            badge.textContent = r.extra ? 'IA · image' : 'IA';
            badge.title = (r.aiConf ? 'Confiance ' + r.aiConf : 'Proposé par l\'IA') + (r.aiWhy ? ' — ' + r.aiWhy : '');
            cell.appendChild(badge);
          }
          c1.appendChild(cell); tr.appendChild(c1);
          var c2 = document.createElement('td'), us = document.createElement('select');
          ['vente', 'circulation', 'service', 'parking', 'exclu'].forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = data.usages[k]; us.appendChild(o); });
          us.value = r.usage; c2.appendChild(us); tr.appendChild(c2);
          var c3 = document.createElement('td'); c3.className = 'num'; c3.textContent = fmtNum(r.z.area); tr.appendChild(c3);
          var c4 = document.createElement('td'), ind = document.createElement('input'); ind.type = 'checkbox'; ind.checked = r.indoor; ind.title = 'À l\'intérieur d\'un bâtiment'; c4.appendChild(ind); tr.appendChild(c4);
          var c5 = document.createElement('td'), svc = document.createElement('input'); svc.type = 'text'; svc.maxLength = 40; svc.value = r.service; svc.placeholder = 'accueil…'; svc.style.display = r.usage === 'service' ? '' : 'none'; c5.appendChild(svc); tr.appendChild(c5);
          cb.addEventListener('change', function () { r.on = cb.checked; });
          lab.addEventListener('input', function () { r.label = lab.value; });
          us.addEventListener('change', function () { r.usage = us.value; svc.style.display = r.usage === 'service' ? '' : 'none'; });
          ind.addEventListener('change', function () { r.indoor = ind.checked; });
          svc.addEventListener('input', function () { r.service = svc.value; });
          body.appendChild(tr);
        });
        $('zdResult').hidden = !zdRows.length && !zdEntries.length;
      }
      function zdEntriesRender() {
        var ul = $('zdEntryList'); ul.textContent = '';
        $('zdEntries').hidden = !zdEntries.length;
        zdEntries.forEach(function (e) {
          var li = document.createElement('li'), b = document.createElement('button');
          b.type = 'button'; b.className = 'btn mini'; b.textContent = 'Ajouter';
          b.addEventListener('click', function () {
            pushUndo(true);
            track(L.marker([e.pts[0][0], e.pts[0][1]]), { label: e.label, usage: 'acces', flow: e.flow, color: UCOL.acces });
            count(); b.disabled = true; b.textContent = 'Ajoutée';
          });
          li.appendChild(document.createTextNode((e.main ? '★ ' : '') + e.label + (e.flow ? ' · ' + (data.flows[e.flow] || '') : '') + ' '));
          li.appendChild(b); ul.appendChild(li);
        });
      }
      function zdReset() { zdRows = []; zdEntries = []; $('zdResult').hidden = true; $('zdSummary').textContent = ''; }
      $('zdGo').addEventListener('click', function () {
        fillTerrList();
        var t = terrById($('zdTerr').value);
        if (!t) { $('zdMsg').textContent = 'Aucun territoire sur ce plan : dessinez un polygone avec l\'outil polygone (en haut à gauche de la carte), puis dans sa fiche choisissez Nature = « Territoire (quartier…) ».'; return; }
        var withAi = $('zdAi').checked, fd = new FormData();
        fd.append('csrf', CSRF); fd.append('action', 'discover');
        fd.append('territory', JSON.stringify(points(t)));
        fd.append('min_area', $('zdMin').value || '25');
        if ($('zdRoads').checked) fd.append('roads', '1');
        if ($('zdParking').checked) fd.append('parking', '1');
        if ($('zdServices').checked) fd.append('services', '1');
        if (withAi) fd.append('ai', '1');
        $('zdGo').disabled = true;
        $('zdMsg').textContent = withAi ? 'Analyse du site et lecture de l\'orthophoto en cours (10 à 40 s)…' : 'Analyse du site en cours (quelques secondes)…';
        $('zdResult').hidden = true;
        fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            $('zdGo').disabled = false;
            if (!j || !j.ok) { $('zdMsg').textContent = (j && j.error) || 'Analyse impossible.'; return; }
            var ev = j.event || {}, st = j.stats || {}, ai = j.ai || null;
            zdRows = (j.zones || []).map(function (z) {
              return { z: z, on: !z.extra, label: z.label, usage: ['vente', 'circulation', 'service', 'parking', 'exclu'].indexOf(z.usage) >= 0 ? z.usage : 'vente',
                indoor: !!z.indoor, service: z.service || '', flow: z.flow || '',
                ai: !!z.ai, aiConf: z.ai_confidence || '', aiWhy: z.ai_why || '', extra: !!z.extra };
            });
            zdEntries = j.entries || [];
            var sum = 'Site : ' + fmtNum(j.territory.area) + ' m² · ' + (ev.indoor ? 'plutôt intérieur' : 'plutôt extérieur')
              + ' (bâtiments : ' + ev.coverage + ' % de la surface) · ' + zdRows.length + ' zone(s) proposée(s), ' + zdEntries.length + ' entrée(s) détectée(s) · surface utile hors circulation, parking et services : ' + fmtNum(st.useful_area) + ' m².';
            if (ai && ai.ok) sum += ' IA : ' + ai.applied + ' zone(s) précisée(s)' + (ai.extras ? ', ' + ai.extras + ' élément(s) repéré(s) sur l\'image' : '') + (ai.cached ? ' (analyse en cache)' : '') + '.';
            $('zdSummary').textContent = sum;
            var notes = [];
            if ((j.warnings || []).length) notes.push(j.warnings.join(' '));
            if (ai && ai.notes) notes.push('Lecture de l\'image : ' + ai.notes);
            if (ai && !ai.ok && ai.error) notes.push('Affinage IA indisponible : ' + ai.error);
            $('zdMsg').textContent = notes.join(' ');
            $('zdAll').checked = !zdRows.some(function (r) { return !r.on; });
            zdRender(); zdEntriesRender();
          })
          .catch(function () { $('zdGo').disabled = false; $('zdMsg').textContent = 'Analyse indisponible (réseau ou délai dépassé).'; });
      });
      $('zdAll').addEventListener('change', function () { var on = this.checked; zdRows.forEach(function (r) { r.on = on; }); zdRender(); });
      $('zdClear').addEventListener('click', function () { zdReset(); $('zdMsg').textContent = ''; });
      $('zdTerr').addEventListener('change', function () { zdReset(); $('zdMsg').textContent = ''; });
      // Raccourci depuis la fiche du territoire : sélectionne ce territoire dans l'assistant et lance l'analyse.
      $('terrZones').addEventListener('click', function () {
        if (!selected || !isTerr(selected)) return;
        fillTerrList();
        $('zdTerr').value = selected._plan.id;
        zdReset(); $('zdMsg').textContent = '';
        setGcardCollapsed($('zoneCard'), false);
        $('zoneCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
        $('zdGo').click();
      });
      $('zdAdd').addEventListener('click', function () {
        var picked = zdRows.filter(function (r) { return r.on; });
        if (!picked.length) { $('zdMsg').textContent = 'Cochez au moins une zone.'; return; }
        if (group.getLayers().length + picked.length > 600) { $('zdMsg').textContent = 'Le plan dépasserait 600 éléments.'; return; }
        pushUndo(true);
        picked.forEach(function (r) {
          track(L.polygon(r.z.pts), { role: 'zone', ztype: r.z.ztype || 'autre', label: r.label, usage: r.usage, flow: r.flow, indoor: r.indoor, service: r.service, color: UCOL[r.usage] || '#b5502e' });
        });
        restack(); count();
        zdRows = zdRows.filter(function (r) { return !r.on; });
        zdRender();
        $('zdMsg').textContent = picked.length + ' zone(s) ajoutée(s) au plan. Pensez à enregistrer.';
      });

      // --- Recherche d'adresse : suggestions au fil de la frappe (IGN pour les adresses, Photon pour les lieux) ---
      var photonTypes = { house: 'adresse', street: 'rue', townhall: 'mairie', park: 'parc', garden: 'jardin', school: 'école', college: 'collège', university: 'université', city: 'ville', town: 'ville', village: 'village', hamlet: 'lieu-dit', locality: 'lieu-dit', suburb: 'quartier', neighbourhood: 'quartier', museum: 'musée', artwork: 'œuvre', attraction: 'site', restaurant: 'restaurant', cafe: 'café', hotel: 'hôtel', supermarket: 'commerce', marketplace: 'marché', train_station: 'gare', bus_station: 'gare routière', hospital: 'hôpital', pharmacy: 'pharmacie', sports_centre: 'sport', pitch: 'terrain de sport', playground: 'aire de jeux', parking: 'parking', place_of_worship: 'lieu de culte', library: 'bibliothèque', theatre: 'théâtre', cinema: 'cinéma' };
      function geoFetch(url) {
        return fetch(url, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) { return (j && j.features) || []; })
          .catch(function () { return []; });
      }
      function ignSuggest(q) {
        var c = map.getCenter();
        return geoFetch('https://data.geopf.fr/geocodage/search?index=address&limit=4&lat=' + c.lat.toFixed(5) + '&lon=' + c.lng.toFixed(5) + '&q=' + encodeURIComponent(q)).then(function (fs) {
          return fs.map(function (f) {
            var p = f.properties || {}, c = f.geometry && f.geometry.coordinates; if (!c) return null;
            return { lat: c[1], lon: c[0], label: p.label || p.name || q, ctx: [p.postcode, p.city].filter(Boolean).join(' '),
              typ: ({ housenumber: 'adresse', street: 'rue', locality: 'lieu-dit', municipality: 'commune', poi: 'lieu' })[p.type] || 'adresse' };
          }).filter(Boolean);
        });
      }
      function photonSuggest(q) {
        var c = map.getCenter();
        return geoFetch('https://photon.komoot.io/api/?lang=fr&limit=8&lat=' + c.lat.toFixed(5) + '&lon=' + c.lng.toFixed(5) + '&q=' + encodeURIComponent(q)).then(function (fs) {
          return fs.map(function (f) {
            var p = f.properties || {}, c = f.geometry && f.geometry.coordinates; if (!c) return null;
            return { lat: c[1], lon: c[0], label: p.name || [p.housenumber, p.street].filter(Boolean).join(' ') || q,
              ctx: [p.postcode, p.city].filter(Boolean).join(' '), typ: photonTypes[p.osm_value] || (p.housenumber ? 'adresse' : 'lieu') };
          }).filter(Boolean);
        });
      }
      function addrSuggest(q) {
        return Promise.all([ignSuggest(q), photonSuggest(q)]).then(function (r) {
          var seen = {};
          return r[0].concat(r[1]).filter(function (it) {
            var k = it.label.toLowerCase() + '|' + (it.ctx || '').toLowerCase();
            if (seen[k]) return false; seen[k] = 1; return true;
          }).slice(0, 8);
        });
      }
      var addrEl = $('addr'), addrList = $('addrList'), addrItems = [], addrActive = -1, addrTimer = null, addrSeq = 0;
      function addrEcho(t) { $('addrEcho').textContent = (t || '').trim() || 'Adresse du lieu'; }
      function addrClose() {
        addrList.hidden = true; addrEl.setAttribute('aria-expanded', 'false'); addrEl.removeAttribute('aria-activedescendant');
        addrItems = []; addrActive = -1;
      }
      function addrRender(items) {
        addrList.textContent = ''; addrItems = items; addrActive = -1;
        if (!items.length) { addrClose(); return; }
        items.forEach(function (it, i) {
          var li = document.createElement('li'), t = document.createElement('span'), l = document.createElement('span');
          li.id = 'addrOpt' + i; li.setAttribute('role', 'option'); li.setAttribute('aria-selected', 'false');
          t.className = 'atyp'; t.textContent = it.typ || 'lieu';
          l.className = 'albl'; l.textContent = it.label;
          li.appendChild(t); li.appendChild(l);
          if (it.ctx) { var c = document.createElement('span'); c.className = 'actx'; c.textContent = it.ctx; li.appendChild(c); }
          li.addEventListener('mousedown', function (e) { e.preventDefault(); addrGoTo(it); });
          addrList.appendChild(li);
        });
        addrList.hidden = false; addrEl.setAttribute('aria-expanded', 'true');
      }
      function addrHighlight(i) {
        addrActive = i;
        [].forEach.call(addrList.children, function (li, k) { li.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
        if (i < 0) { addrEl.removeAttribute('aria-activedescendant'); return; }
        addrEl.setAttribute('aria-activedescendant', 'addrOpt' + i);
        var li = addrList.children[i]; if (li) li.scrollIntoView({ block: 'nearest' });
      }
      function addrGoTo(it) {
        if (!it) return;
        addrEl.value = it.label;
        addrEcho(it.label);
        addrClose();
        $('addrMsg').textContent = it.label.slice(0, 70) + (it.ctx ? ' · ' + it.ctx : '');
        map.setView([it.lat, it.lon], Math.max(map.getZoom(), 18));
      }
      function search() {
        if (!addrList.hidden && addrActive >= 0 && addrItems[addrActive]) { addrGoTo(addrItems[addrActive]); return; }
        if (!addrList.hidden && addrItems.length) { addrGoTo(addrItems[0]); return; }
        var q = addrEl.value.trim(); if (!q) return;
        $('addrMsg').textContent = 'Recherche…';
        var seq = ++addrSeq;
        addrSuggest(q).then(function (out) {
          if (seq !== addrSeq) return;
          if (!out.length) { $('addrMsg').textContent = 'Adresse introuvable.'; return; }
          addrGoTo(out[0]);
        });
      }
      // Adresse la plus proche d'une position : IGN, puis Photon en secours
      function reverseAddr(lat, lon) {
        var q = 'lat=' + lat.toFixed(6) + '&lon=' + lon.toFixed(6);
        function ctxOf(p, label) {     // l'IGN renvoie déjà « 12 rue… 75004 Paris » : on ne répète pas le code postal
          var c = [p.postcode, p.city].filter(Boolean).join(' ');
          return p.postcode && label.indexOf(p.postcode) >= 0 ? '' : c;
        }
        return geoFetch('https://data.geopf.fr/geocodage/reverse?index=address&limit=1&' + q).then(function (fs) {
          var f = fs[0]; if (!f) return null;
          var p = f.properties || {}, l = p.label || p.name || '';
          return { label: l, ctx: l ? ctxOf(p, l) : '' };
        }).then(function (it) {
          if (it && it.label) return it;
          return geoFetch('https://photon.komoot.io/reverse?lang=fr&limit=1&' + q).then(function (fs) {
            var f = fs[0]; if (!f) return null;
            var p = f.properties || {}, l = p.name || [p.housenumber, p.street].filter(Boolean).join(' ');
            return l ? { label: l, ctx: ctxOf(p, l) } : null;
          });
        }).catch(function () { return null; });
      }
      // Position de l'appareil : le navigateur demande l'autorisation, puis la carte se cale et le champ reprend l'adresse
      $('addrLoc').addEventListener('click', function () {
        var btn = this;
        if (!navigator.geolocation) { $('addrMsg').textContent = 'La localisation n\'est pas disponible dans ce navigateur.'; return; }
        btn.disabled = true;
        $('addrMsg').textContent = 'Localisation en cours : autorisez le navigateur à utiliser votre position…';
        navigator.geolocation.getCurrentPosition(function (pos) {
          var lat = pos.coords.latitude, lon = pos.coords.longitude;
          map.setView([lat, lon], Math.max(map.getZoom(), 19));
          $('addrMsg').textContent = 'Position trouvée (' + lat.toFixed(5) + ', ' + lon.toFixed(5) + ') · recherche de l\'adresse…';
          reverseAddr(lat, lon).then(function (it) {
            btn.disabled = false;
            if (!it) { $('addrMsg').textContent = 'Position trouvée mais adresse introuvable : saisissez-la à la main, la carte est déjà centrée.'; return; }
            addrEl.value = it.label; addrEcho(it.label);
            $('addrMsg').textContent = 'Position du navigateur : ' + it.label + (it.ctx ? ' · ' + it.ctx : '');
          });
        }, function (err) {
          btn.disabled = false;
          $('addrMsg').textContent = err && err.code === 1 ? 'Localisation refusée : autorisez-la dans les réglages du navigateur pour ce site.'
            : err && err.code === 3 ? 'Localisation trop lente : réessayez ou saisissez l\'adresse à la main.' : 'Localisation indisponible sur cet appareil.';
        }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
      });

      // --- Enregistrement ---
      function collectMap() {
        var c = map.getCenter();
        return { center: [c.lat, c.lng], zoom: map.getZoom(), base: hidden ? 'none' : baseKind, catalog: catalog.filter(function (x) { return x.name && x.w >= 0.5 && x.d >= 0.5; }),
          background: bg ? { path: bg.path, bounds: bgLayer ? bgBoundsArr(bgLayer.getBounds()) : bg.bounds, opacity: bg.opacity } : null,
          items: group.getLayers().map(function (l) { var m = l._plan; return { id: m.id, kind: kindOf(l), pts: points(l), label: m.label, num: m.num, shop: m.shop, color: m.color, note: m.note, surface: m.surface, role: m.role, ztype: m.ztype, unit: m.unit, price_cents: toCents(m.price), price_pro_cents: toCents(m.pricePro), usage: m.usage, flow: m.flow, indoor: !!m.indoor, service: m.service }; }), costs: { fixed_cents: toCents($('simFixed').value), var_cents: toCents($('simVar').value), pct: num('simPct', 0), occ: [0, 1, 2].map(function (i) { return num('simOcc' + i, 0); }), adj: [0, 1, 2].map(function (i) { return num('simAdj' + i, 0); }) }, booking: { enabled: $('bkOn').checked, hold_days: parseInt($('bkDays').value, 10) || 7, intro: $('bkIntro').value } };
      }
      $('saveForm').addEventListener('submit', function () {
        $('mapJson').value = JSON.stringify(collectMap());
      });

      // --- Export impression : PDF 300 dpi ou PSD calé, reflète l'état courant du plan (sans l'enregistrer) ---
      (function () {
        var btn = $('expGo'), msg = $('expMsg');
        if (!btn) return;
        btn.addEventListener('click', function () {
          var fd = new FormData();
          fd.append('csrf', CSRF); fd.append('action', 'export');
          fd.append('map', JSON.stringify(collectMap()));
          fd.append('format', $('expFmt').value);
          fd.append('page', $('expPage').value);
          btn.disabled = true; msg.textContent = 'Rendu haute résolution en cours…';
          fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            var cd = r.headers.get('Content-Disposition') || '';
            var m = cd.match(/filename="?([^";]+)"?/);
            return r.blob().then(function (b) { return [b, m ? m[1] : 'plan-export']; });
          }).then(function (pair) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(pair[0]); a.download = pair[1];
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
            msg.textContent = 'Fichier téléchargé.';
          }).catch(function (e) {
            msg.textContent = 'Export impossible : ' + (e && e.message ? e.message : e);
          }).finally(function () { btn.disabled = false; });
        });
      })();

      // --- Hypothèses de simulation du chiffre d'affaires ---
      var cst = saved.costs || {};
      if (cst.fixed_cents) $('simFixed').value = fm(cst.fixed_cents / 100); if (cst.var_cents) $('simVar').value = fm(cst.var_cents / 100); if (cst.pct) $('simPct').value = cst.pct;
      [0, 1, 2].forEach(function (i) { if (cst.occ && cst.occ[i] != null) $('simOcc' + i).value = cst.occ[i]; if (cst.adj && cst.adj[i] != null) $('simAdj' + i).value = cst.adj[i]; });

      // --- Réservation par les exposants ---
      $('bookCard').hidden = !data.features.exposants;
      var bk = saved.booking || {}; $('bkOn').checked = !!bk.enabled; $('bkDays').value = bk.hold_days || 7; $('bkIntro').value = bk.intro || '';

      // --- Blocs repliables : les quatre cartes de gestion et la barre d'outils, en page normale comme en plein écran ---
      var COLLAPSE_KEY = 'planEditorCollapsed';
      var collapseState = {};
      try { collapseState = JSON.parse(localStorage.getItem(COLLAPSE_KEY) || '{}') || {}; } catch (e) {}
      function persistCollapse() { try { localStorage.setItem(COLLAPSE_KEY, JSON.stringify(collapseState)); } catch (e) {} }
      function setGcardCollapsed(el, on) {
        el.classList.toggle('collapsed', on);
        var h = el.querySelector('h2');
        if (h) h.setAttribute('aria-expanded', on ? 'false' : 'true');
        collapseState[el.id] = on; persistCollapse();
      }
      ['catCard', 'numCard', 'bookCard', 'zoneCard'].forEach(function (id) {
        var el = $(id), h = el && el.querySelector('h2');
        if (!h) return;
        h.tabIndex = 0; h.setAttribute('role', 'button');
        setGcardCollapsed(el, !!collapseState[id]);
        h.addEventListener('click', function () { setGcardCollapsed(el, !el.classList.contains('collapsed')); });
        h.addEventListener('keydown', function (e) {
          if (e.key !== 'Enter' && e.key !== ' ') return;
          e.preventDefault(); setGcardCollapsed(el, !el.classList.contains('collapsed'));
        });
      });

      // --- Plein écran : la carte occupe l'écran, les modules deviennent des panneaux flottants ---
      var card = $('planCard'), fsSide = $('fsSide'), fsOn = false;
      var fsCards = ['catCard', 'numCard', 'bookCard', 'zoneCard'];
      function resizeMap() { setTimeout(function () { map.invalidateSize(); }, 60); }
      function afterLayout() { requestAnimationFrame(function () { if (bearing) syncGeometry(); else resizeMap(); }); }
      var mapBarEl = $('mapBar'), mapBarToggle = $('mapBarToggle');
      function setMapBarCollapsed(on) {
        mapBarEl.classList.toggle('collapsed', on);
        mapBarToggle.setAttribute('aria-pressed', on ? 'false' : 'true');
        collapseState.mapBar = on; persistCollapse();
        afterLayout();
      }
      mapBarToggle.addEventListener('click', function () { setMapBarCollapsed(!mapBarEl.classList.contains('collapsed')); });
      setMapBarCollapsed(!!collapseState.mapBar);
      var addrBarEl = $('addrBar'), addrToggle = $('addrToggle');
      function setAddrCollapsed(on) {
        addrBarEl.classList.toggle('collapsed', on);
        addrToggle.setAttribute('aria-pressed', on ? 'false' : 'true');
        addrToggle.setAttribute('aria-expanded', on ? 'false' : 'true');
        addrToggle.title = on ? "Déplier la recherche d'adresse et la localisation" : "Replier la recherche d'adresse";
        collapseState.addr = on; persistCollapse();
        afterLayout();
      }
      addrToggle.addEventListener('click', function () { setAddrCollapsed(!addrBarEl.classList.contains('collapsed')); });
      setAddrCollapsed(!!collapseState.addr);
      function fsPanels(on) {
        if (on) {
          fsSide.appendChild($('saveBar'));
          fsCards.forEach(function (id) { fsSide.appendChild($(id)); });
          fsSide.appendChild($('sel'));
        } else {
          var form = $('saveForm');
          fsCards.forEach(function (id) { form.parentNode.insertBefore($(id), form); });
          $('fsMain').appendChild($('saveBar'));
          $('fsMain').appendChild($('sel'));
        }
      }
      function openFs() {
        fsOn = true; card.classList.add('fs'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'Plan en plein écran');
        fsSide.hidden = false; fsPanels(true);
        $('fsOpen').hidden = true; $('fsClose').hidden = false; document.body.style.overflow = 'hidden'; card.scrollTop = 0; afterLayout(); $('fsClose').focus();
      }
      function closeFs() {
        if (!fsOn) return;
        fsOn = false; card.classList.remove('fs'); card.removeAttribute('role'); card.removeAttribute('aria-modal'); card.removeAttribute('aria-label');
        fsPanels(false);
        fsSide.hidden = true; $('fsOpen').hidden = false; $('fsClose').hidden = true; document.body.style.overflow = ''; afterLayout(); $('fsOpen').focus();
      }
      $('fsOpen').addEventListener('click', openFs);
      $('fsClose').addEventListener('click', closeFs);
      var resizeTimer;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () { if (bearing) syncGeometry(); else resizeMap(); }, 120);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        if (e.__posee) return;        // un premier Échap termine la pose, le suivant ferme le plein écran
        closeFs();
      });
      // Tab reste dans la modale tant qu'elle est ouverte
      document.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || !fsOn) return;
        var f = [].filter.call(card.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'), function (n) { return !n.disabled && !n.hidden && n.offsetParent !== null; });
        if (!f.length) return;
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
        else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
      });

      // --- Recherche d'adresse : saisie au clavier ou au clic, découverte des lieux au fil de la frappe ---
      addrEl.addEventListener('input', function () {
        var q = addrEl.value.trim(), seq = ++addrSeq;
        addrEcho(q);
        clearTimeout(addrTimer);
        if (!addrList.hidden) addrClose();
        if (q.length < 2) return;
        addrTimer = setTimeout(function () {
          addrEl.setAttribute('aria-busy', 'true');
          addrSuggest(q).then(function (out) {
            if (seq !== addrSeq) return;
            addrEl.removeAttribute('aria-busy');
            addrRender(out);
          });
        }, 280);
      });
      addrEl.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); if (!addrList.hidden && addrItems.length) addrHighlight((addrActive + 1) % addrItems.length); return; }
        if (e.key === 'ArrowUp') { e.preventDefault(); if (!addrList.hidden && addrItems.length) addrHighlight(addrActive <= 0 ? addrItems.length - 1 : addrActive - 1); return; }
        if (e.key === 'Escape') { if (!addrList.hidden) { e.stopPropagation(); addrClose(); } return; }
        if (e.key === 'Enter') { e.preventDefault(); search(); }
      });
      $('addrGo').addEventListener('click', search);
      document.addEventListener('click', function (e) { if (e.target.closest && !e.target.closest('.addrwrap')) addrClose(); });
    })();
    </script>
<?php ga_foot(); ?>
