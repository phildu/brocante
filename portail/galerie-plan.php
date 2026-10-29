<?php
// Plan d'implantation d'une galerie : dessin des commerces (repères, zones, tracés) sur un fond IGN (plan ou orthophoto) ou sans fond,
// avec un catalogue de surfaces, des emplacements numérotés, la division de zones et la localisation de la marchandise de chaque commerce.
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
    if ((string) ($_POST['action'] ?? '') === 'save') {
        $raw = json_decode((string) ($_POST['map'] ?? ''), true);
        $gaGallery['map'] = is_array($raw) ? gallery_map_normalize($raw, $memberKeys) : null;
        if (gallery_save($gaGallery)) portail_flash($gaGallery['map'] ? 'Plan enregistré (' . count($gaGallery['map']['items']) . ' élément' . (count($gaGallery['map']['items']) > 1 ? 's' : '') . ').' : 'Plan vidé.');
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
$payload = ['map' => $map, 'shops' => $names, 'zoneTypes' => GALLERY_ZONE_TYPES, 'features' => gt_of($gaGallery)['features'],
    'tiles' => ['osm' => GALLERY_MAP_TILE_PLAN, 'sat' => GALLERY_MAP_TILE_SAT]];
$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$zoneTypes = GALLERY_ZONE_TYPES;
$priceUnits = GALLERY_PRICE_UNITS;
$publicUrl = '/galerie/' . $gaGallery['slug'] . '/' . ($gaGallery['published'] ? '' : '?apercu=' . gallery_preview_token($gaGallery)) . '#plan-commerces';
ga_head('Plan des commerces');
?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@geoman-io/leaflet-geoman-free@2.17.0/dist/leaflet-geoman.css">
    <style>
      #plan { height: 64vh; min-height: 400px; border: 1px solid var(--line); border-radius: 10px; }
      #splitBox [hidden] { display: none !important; } #splitBox input[type=checkbox] { width: auto; flex: none; }
      .simscen th { text-align: left; font-weight: 600; white-space: nowrap; } .simscen td { padding: 4px; } .simscen input { width: 100%; max-width: 110px; min-width: 0; }
      .simwrap { max-height: 300px; overflow: auto; margin: 6px 0; } #aidTable tr.best td { background: rgba(47,107,79,.12); font-weight: 600; } #aidTable small { font-weight: 400; opacity: .75; }
      .simdet { margin-top: 8px; } .simdet th:first-child { text-align: left; } .simdet tr.tot th, .simdet tr.tot td { border-top: 2px solid var(--line); font-weight: 700; } .simdet .pos { color: #1b7a43; } .simdet .neg { color: #b42318; }
      #plan.nofond { background-color: #f6f3ec; background-image: linear-gradient(rgba(0,0,0,.06) 1px, transparent 1px), linear-gradient(90deg, rgba(0,0,0,.06) 1px, transparent 1px); background-size: 40px 40px; }
      .plan-label { background: rgba(255,255,255,.92); border: 1px solid rgba(0,0,0,.25); border-radius: 4px; padding: 1px 5px; font: 600 11px/1.3 system-ui, sans-serif; color: #222; box-shadow: none; }
      .plan-dot { display: block; width: 18px; height: 18px; border-radius: 50%; border: 3px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,.45), 0 2px 5px rgba(0,0,0,.35); }
      .plan-grid { display: grid; gap: 8px; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); align-items: end; }
      .plan-grid[hidden], .plan-sub[hidden] { display: none; } .plan-grid input[type=color], .gcard input[type=color] { width: 100%; height: 38px; padding: 2px; }
      .plan-sub { border: 1px dashed var(--line); border-radius: 8px; padding: 10px; display: grid; gap: 8px; }
      .help { border: 1px solid var(--line); border-radius: 10px; padding: 12px 16px; background: var(--panel); }
      .help summary { cursor: pointer; font-weight: 700; } .help ol, .help ul { margin: 8px 0 0; padding-left: 20px; display: grid; gap: 4px; font-size: .9rem; }
      .tip { font-size: .8rem; opacity: .75; margin: 0; }
      .mini { padding: 3px 8px; font-size: .8rem; } table.t input[type=text], table.t input[type=number] { min-width: 0; width: 100%; padding: 5px 7px; }
      .listplan { max-height: 240px; overflow: auto; } .listplan tr { cursor: pointer; } .listplan tr:hover { background: rgba(0,0,0,.05); }
      body.placing #plan { cursor: crosshair; }
      .plan-zone { display: inline-block; transform: translate(-50%, -100%); white-space: nowrap; font: 700 11px/1.3 system-ui, sans-serif; letter-spacing: .04em; text-transform: uppercase; color: #222; background: rgba(255,255,255,.88); border: 1px solid rgba(0,0,0,.3); border-radius: 4px; padding: 2px 7px; pointer-events: none; }
      .plan-zone small { font-weight: 500; opacity: .7; margin-right: 4px; }
      /* Plein écran : la carte devient une modale, catalogue et numérotation passent dans une colonne latérale */
      #fsLayout, #fsMain { display: grid; gap: 10px; min-width: 0; } #fsSide[hidden] { display: none; }
      #planCard.fs { position: fixed; inset: 0; z-index: 9999; margin: 0; border-radius: 0; border: 0; overflow: auto; align-content: start; padding: 14px 18px; }
      #planCard.fs #fsLayout { grid-template-columns: minmax(320px, 460px) 1fr; gap: 14px; align-items: start; }
      #planCard.fs #fsSide { position: sticky; top: 0; max-height: calc(100vh - 70px); overflow: auto; min-width: 0; padding-right: 2px; }
      #fsSide .gcard { min-width: 0; overflow-x: auto; }
      /* le catalogue de surfaces passe en fiches empilées : plus rien ne déborde sur la carte */
      #fsSide table.t, #fsSide table.t tbody { display: block; width: 100%; } #fsSide table.t thead { display: none; }
      #fsSide #catBody tr { display: grid; grid-template-columns: 1fr 1fr 64px 1fr; gap: 6px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--line); }
      #fsSide #catBody td { display: block; border: 0; padding: 0; min-width: 0; } #fsSide #catBody td:first-child, #fsSide #catBody td:last-child { grid-column: 1 / -1; }
      #fsSide #catBody td:nth-child(2)::before { content: 'Larg. (m)'; display: block; font-size: .7rem; opacity: .7; } #fsSide #catBody td:nth-child(3)::before { content: 'Prof. (m)'; display: block; font-size: .7rem; opacity: .7; }
      #fsSide #catBody td.num { text-align: right; }
      #fsSide .listplan table.t { display: table; } #fsSide .listplan table.t tbody { display: table-row-group; } #fsSide .listplan table.t thead { display: table-header-group; }
      #planCard.fs #fsSide { display: grid; gap: 12px; align-content: start; } #planCard.fs #fsSide .gcard { box-shadow: none; }
      #planCard.fs #plan { height: calc(100vh - 270px); min-height: 320px; }
      @media (max-width: 800px) { #planCard.fs #fsLayout { grid-template-columns: 1fr; } #planCard.fs #plan { height: 60vh; } }
    </style>

    <details class="help" open>
      <summary>Aide : dessiner le plan du site pas à pas</summary>
      <ol>
        <li><strong>Cadrez le lieu</strong> : tapez l'adresse dans la recherche, puis « Centrer la carte ». Choisissez le fond (plan ou satellite) ou cochez <em>Masquer le fond de carte</em> pour ne garder que votre dessin sur une grille.</li>
        <li><strong>Définissez vos surfaces</strong> dans le catalogue (ex. « Stand 3 × 2 m ») : une fois créées, un clic sur « Poser » dessine l'emplacement aux bonnes dimensions, à l'échelle de la carte (réglez l'angle pour l'aligner sur l'allée).</li>
        <li><strong>Tournez un emplacement</strong> : sélectionnez-le puis ↻ / ↺ (1°, 15°, 90° ou un angle précis), ou prenez l'outil de rotation à gauche de la carte.</li>
        <li><strong>Dessinez à la main</strong> avec les outils à gauche de la carte : repère (entrée, caisse, toilettes), rectangle ou zone libre (halle, cour), tracé (allée).</li>
        <li><strong>Numérotez</strong> : un numéro par emplacement dans sa fiche, ou « Numéroter tout » pour le faire d'un coup (de gauche à droite et de haut en bas, par exemple). Le compteur « prochain numéro » sert aussi à la pose et à la division.</li>
        <li><strong>Découpez une zone en parcelles</strong> : sélectionnez la zone (rue, hall) à 4 coins, choisissez le nombre de parcelles ou leurs dimensions, cochez « Allée au milieu » pour laisser un couloir entre deux rangées, puis « Découper ». L'aperçu donne la taille des parcelles ; chacune reçoit le numéro suivant.</li>
        <li><strong>Reliez chaque emplacement à un commerce</strong> et notez en bas où se trouve sa marchandise : cette adresse alimente le champ « adresse du bien » des plateformes (Leboncoin…).</li>
        <li>Pensez à <strong>Enregistrer le plan</strong> : rien n'est conservé avant.</li>
      </ol>
      <p class="tip" style="margin-top:8px;">Astuce : zoomez fort (jusqu'au niveau du mètre) avant de poser des surfaces ; l'échelle s'affiche en bas à gauche de la carte. Échap annule la pose en cours.</p>
    </details>

    <section class="gcard" id="planCard">
      <div class="row" style="justify-content:space-between;"><h2 style="margin:0;">Plan d'implantation</h2>
        <span class="row"><button class="btn" type="button" id="fsOpen" title="Agrandir le plan en plein écran, avec le catalogue et la numérotation à côté">⛶ Plein écran</button>
        <button class="btn btn-primary" type="button" id="fsClose" hidden>✕ Fermer le plein écran</button></span></div>
      <div id="fsLayout"><div id="fsSide" hidden></div><div id="fsMain">
      <div class="row">
        <input type="text" id="addr" placeholder="Rechercher une adresse, une rue, un lieu…" autocomplete="off"><button class="btn" type="button" id="addrGo">Centrer la carte</button>
        <span class="meta" id="addrMsg" role="status"></span>
      </div>
      <div class="row">
        <label class="row" style="gap:6px;">Fond de carte
          <select id="baseSel" style="flex:none;min-width:0;"><option value="osm">Plan (IGN)</option><option value="sat">Satellite (IGN)</option></select></label>
        <label class="row" style="gap:6px;" title="N'affiche que votre dessin, sur une grille : utile pour un plan intérieur ou quand le fond gêne la lecture."><input type="checkbox" id="hideBase"> Masquer le fond de carte (plan seul)</label>
        <span class="meta" id="mode" role="status"></span>
      </div>
      <div id="plan" aria-label="Carte du plan d'implantation"></div>

      <div class="plan-grid" id="sel" hidden>
        <label class="f">Nom affiché<input type="text" id="selLabel" maxlength="60" placeholder="Brocante de Paul, Entrée…"></label>
        <label class="f" title="Numéro d'emplacement affiché sur le plan (ex. 12 ou A3).">N° d'emplacement<input type="text" id="selNum" maxlength="12" placeholder="12"></label>
        <label class="f" id="natureBox" title="Une zone regroupe des emplacements (rue, hall…) : elle porte un nom, un type et des tarifs.">Nature<select id="selRole"><option value="slot">Emplacement</option><option value="zone">Zone (rue, hall…)</option></select></label>
        <label class="f" id="ztypeBox" hidden>Type de zone<select id="selZtype"><?php foreach ($zoneTypes as $zk => $zl): ?><option value="<?= e($zk) ?>"><?= e($zl) ?></option><?php endforeach; ?></select></label>
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
            <label class="row" style="gap:6px;"><input type="checkbox" id="splitGhost" checked> Aperçu sur la carte</label>
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

      <p class="meta" id="count"></p>
      <div class="row">
        <button class="btn btn-primary" type="submit" form="saveForm">Enregistrer le plan</button>
        <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Voir sur la page publique ↗</a>
      </div>
      </div></div>
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
      <div class="listplan" id="listWrap" hidden><table class="t"><thead><tr><th>N°</th><th>Nom</th><th>Zone</th><th>Commerce</th><th class="num">m²</th><th class="num">Tarif</th></tr></thead><tbody id="listBody"></tbody></table></div>
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

    <section class="gcard" id="placesCard">
      <h2>Localisation de la marchandise</h2>
      <p class="meta">Où se trouve physiquement la marchandise de chaque commerce. Cette adresse alimente automatiquement le champ « adresse du bien » des formulaires des plateformes (le bot Leboncoin l'utilise à la place de l'adresse du profil) pour les commerces de ce serveur.</p>
      <?php if (!$gaGallery['members']): ?><p class="meta">Ajoutez d'abord des commerces à la galerie (page « Présentation et commerces »).</p><?php endif; ?>
      <div id="places" style="display:grid;gap:10px;"></div>
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
      var saved = data.map || { center: null, zoom: 17, base: 'osm', items: [], catalog: [], places: {} };
      var $ = function (id) { return document.getElementById(id); };
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

      // --- Éléments du plan ---
      var group = L.featureGroup().addTo(map), selected = null, placing = null;
      var catalog = (saved.catalog || []).map(function (c) { return { id: c.id, name: c.name, w: c.w, d: c.d, color: c.color }; });
      var places = (saved.places && !Array.isArray(saved.places)) ? JSON.parse(JSON.stringify(saved.places)) : {};   // PHP envoie [] quand il n'y en a pas : ce serait un tableau, qui perd ses clés à l'enregistrement
      var shopSel = $('selShop');
      Object.keys(data.shops).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = data.shops[k]; shopSel.appendChild(o); });

      function uid() { return Math.random().toString(36).slice(2, 10).padEnd(8, '0'); }
      function text(t) { var n = document.createElement('span'); n.textContent = t; return n; }   // Leaflet interprète une chaîne comme du HTML : on passe un nœud texte
      function dot(color) { return L.divIcon({ className: '', html: '<span class="plan-dot" style="background:' + color + '"></span>', iconSize: [18, 18], iconAnchor: [9, 9] }); }
      function tip(m) { return [m.num, m.label].filter(Boolean).join(' · '); }
      var zoneLabels = L.layerGroup().addTo(map);
      function isZone(l) { return l instanceof L.Polygon && l._plan && l._plan.role === 'zone'; }
      function look(layer) {
        var m = layer._plan;
        if (layer instanceof L.Marker) layer.setIcon(dot(m.color));
        else if (isZone(layer)) layer.setStyle({ color: m.color, weight: 2, dashArray: '8 6', fillColor: m.color, fillOpacity: 0.07 });
        else layer.setStyle({ color: m.color, weight: 2, dashArray: null, fillColor: m.color, fillOpacity: layer instanceof L.Polygon ? 0.3 : 0 });
        layer.unbindTooltip();
        if (!isZone(layer) && tip(m)) layer.bindTooltip(text(tip(m)), layer instanceof L.Marker ? { permanent: true, direction: 'top', offset: [0, -10], className: 'plan-label' } : { permanent: true, direction: 'center', className: 'plan-label' });
        if (isZone(layer) && layer.bringToBack) layer.bringToBack();
      }
      // Étiquettes des zones (« RUE · Rue des Fleurs ») posées au milieu du bord nord, hors du fichier enregistré
      function refreshZoneLabels() {
        zoneLabels.clearLayers();
        polygons().filter(isZone).forEach(function (z) {
          var b = z.getBounds(), n = document.createElement('span'); n.className = 'plan-zone';
          var zt = data.zoneTypes[z._plan.ztype] || '', zl = z._plan.label || '(zone sans nom)'; if (zt && zl.toLowerCase().indexOf(zt.toLowerCase()) !== 0 && z._plan.ztype !== 'autre') { var t = document.createElement('small'); t.textContent = zt; n.appendChild(t); } n.appendChild(document.createTextNode(zl));
          zoneLabels.addLayer(L.marker(z._plan.ztype === 'allee' ? b.getCenter() : [b.getNorth(), b.getCenter().lng], { icon: L.divIcon({ className: '', html: n, iconSize: [0, 0] }), interactive: false, keyboard: false }));
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
      // Zone qui contient l'emplacement (la plus petite si elles se recouvrent)
      function zoneOf(layer) {
        if (!(layer instanceof L.Polygon) || isZone(layer)) return null;
        var c = centroid(layer), best = null, bestArea = Infinity;
        polygons().filter(isZone).forEach(function (z) { var pz = points(z); if (pointIn(pz, c[0], c[1])) { var a = areaM2(pz); if (a < bestArea) { best = z; bestArea = a; } } });
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
        meta.id = meta.id || uid(); meta.label = meta.label || ''; meta.num = meta.num || ''; meta.shop = meta.shop || ''; meta.note = meta.note || ''; meta.surface = meta.surface || ''; meta.color = meta.color || '#b5502e'; meta.role = meta.role || 'slot'; meta.ztype = meta.ztype || 'autre'; meta.unit = meta.unit || 'slot'; meta.price = meta.price || ''; meta.pricePro = meta.pricePro || '';
        layer._plan = meta; group.addLayer(layer); look(layer);
        layer.on('click', function (e) { if (placing) return; L.DomEvent.stopPropagation(e); select(layer); });
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
          [m.num || '—', (isZone(l) ? '▦ ' : '') + (m.label || ''), isZone(l) ? (data.zoneTypes[m.ztype] || '') : (z ? zoneName(z) : ''), m.shop && data.shops[m.shop] ? data.shops[m.shop] : '', fmtM2(areaM2(points(l))), price].forEach(function (v, i) { var td = document.createElement('td'); td.textContent = v; if (i >= 4) td.className = 'num'; tr.appendChild(td); });
          tr.addEventListener('click', function () { map.fitBounds(l.getBounds().pad(0.8), { maxZoom: 21 }); select(l); });
          body.appendChild(tr);
        });
      }

      (saved.items || []).forEach(function (it) {
        var layer = it.kind === 'marker' ? L.marker(it.pts[0], { icon: dot(it.color) }) : it.kind === 'polygon' ? L.polygon(it.pts) : L.polyline(it.pts);
        track(layer, { id: it.id, label: it.label, num: it.num, shop: it.shop, color: it.color, note: it.note, surface: it.surface, role: it.role || 'slot', ztype: it.ztype, unit: it.unit, price: it.price_cents ? (it.price_cents / 100).toString().replace('.', ',') : '', pricePro: it.price_pro_cents ? (it.price_pro_cents / 100).toString().replace('.', ',') : '' });
      });
      if (!saved.center && group.getLayers().length) map.fitBounds(group.getBounds().pad(0.2));
      count();

      // --- Numérotation ---
      function numText() { var n = parseInt($('numNext').value, 10); n = isNaN(n) ? 1 : n; var t = $('numPrefix').value + n; $('numNext').value = n + 1; return t; }
      function autoNum(layer) { if ($('numAuto').checked && layer instanceof L.Polygon && !isZone(layer) && !layer._plan.num) { layer._plan.num = numText(); look(layer); } }
      $('numAll').addEventListener('click', function () {
        var only = $('numOnlyEmpty').checked, order = $('numOrder').value, byZone = $('numByZone').checked, list = polygons().filter(function (l) { return !isZone(l) && (!only || !l._plan.num); });
        if (!list.length) { $('count').textContent = 'Aucun emplacement à numéroter.'; return; }
        if (!only && !confirm('Renuméroter ' + list.length + ' emplacement(s), y compris ceux qui ont déjà un numéro ?')) return;
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
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && placing) { e.__posee = true; stopPlace(); } });
      map.on('click', function (e) {
        if (!placing) { select(null); return; }
        var angle = parseFloat($('angle').value) || 0;
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

      // --- Création au crayon, suppression ---
      map.on('pm:create', function (e) {
        var layer = e.layer;
        if (e.shape === 'Marker') { layer.pm.disable(); map.pm.disableDraw(); }   // un repère à la fois : sans cela l'outil reste actif
        track(layer, {});
        autoNum(layer); select(layer); count();
      });
      map.on('pm:remove', function (e) { if (e.layer === selected) select(null); group.removeLayer(e.layer); count(); });

      // --- Rotation d'un élément autour de son centre (calculée en mètres : les formes gardent leurs proportions) ---
      function rotateLayer(layer, deg) {
        if (layer instanceof L.Marker) return;
        var p = points(layer), la = 0, ln = 0; p.forEach(function (x) { la += x[0]; ln += x[1]; }); la /= p.length; ln /= p.length;
        var kLat = 111320, kLng = 111320 * Math.cos(la * Math.PI / 180), a = deg * Math.PI / 180, c = Math.cos(a), s = Math.sin(a);
        var out = p.map(function (x) { var X = (x[1] - ln) * kLng, Y = (x[0] - la) * kLat; return [la + (-X * s + Y * c) / kLat, ln + (X * c + Y * s) / kLng]; });   // sens horaire pour un angle positif
        var editing = layer.pm && layer.pm.enabled && layer.pm.enabled();
        if (editing) layer.pm.disable();
        layer.setLatLngs(out);
        if (editing) layer.pm.enable();
        look(layer); renderList(); if (selected === layer) select(layer);
      }
      document.querySelectorAll('#rotBox [data-rot]').forEach(function (b) { b.addEventListener('click', function () { if (selected) rotateLayer(selected, parseFloat(b.dataset.rot)); }); });
      $('rotGo').addEventListener('click', function () { var d = parseFloat($('rotAngle').value); if (selected && !isNaN(d)) rotateLayer(selected, d); });

      // --- Fiche de l'élément sélectionné ---
      function select(layer) {
        selected = layer;
        $('sel').hidden = !layer;
        if (!layer) { ghost.clearLayers(); return; }
        var m = layer._plan, p = points(layer);
        var poly = layer instanceof L.Polygon;
        $('natureBox').hidden = !poly; $('selRole').value = m.role; $('ztypeBox').hidden = !isZone(layer); $('selZtype').value = m.ztype; $('priceBox').hidden = !isZone(layer) || !data.features.tarifs;
        $('selUnit').value = m.unit; $('selPrice').value = m.price; $('selPricePro').value = m.pricePro;
        $('selLabel').value = m.label; $('selNum').value = m.num; $('selColor').value = m.color; $('selNote').value = m.note;
        shopSel.value = m.shop in data.shops ? m.shop : '';
        var info = '';
        if (layer instanceof L.Polygon) {
          info = 'Surface : ' + fmtM2(areaM2(p)) + ' m²';
          var c = catalog.filter(function (x) { return x.id === m.surface; })[0]; if (c) info += ' (' + c.name + ')';
          if (!isZone(layer)) { var zz = zoneOf(layer), sp = slotPrice(layer, false), sq = slotPrice(layer, true); info += zz ? ' · Zone : ' + zoneName(zz) : ' · hors de toute zone'; if (sp != null) info += ' · Tarif : ' + eur(sp) + (sq != null && sq !== sp ? ' (entreprise ' + eur(sq) + ')' : ''); }
        }
        $('selInfo').textContent = info;
        $('rotBox').hidden = layer instanceof L.Marker;
        $('splitBox').hidden = !(layer instanceof L.Polygon && p.length === 4);
        $('splitTitle').textContent = isZone(layer) ? 'Découper cette zone en parcelles' : 'Diviser cet emplacement';
        justSplit = false; if (!$('splitBox').hidden) { syncSimPrice(layer); splitRefresh(); } else ghost.clearLayers();
      }
      $('selLabel').addEventListener('input', function () { if (selected) { selected._plan.label = this.value; look(selected); renderList(); } });
      $('selNum').addEventListener('input', function () { if (selected) { selected._plan.num = this.value; look(selected); renderList(); } });
      $('selColor').addEventListener('input', function () { if (selected) { selected._plan.color = this.value; look(selected); } });
      $('selNote').addEventListener('input', function () { if (selected) selected._plan.note = this.value; });
      $('selRole').addEventListener('change', function () { if (selected && selected instanceof L.Polygon) { selected._plan.role = this.value; look(selected); select(selected); count(); } });
      $('selZtype').addEventListener('change', function () { if (selected) { selected._plan.ztype = this.value; renderList(); } });
      $('selUnit').addEventListener('change', function () { if (selected) { selected._plan.unit = this.value; renderList(); select(selected); } });
      $('selPrice').addEventListener('input', function () { if (selected) { selected._plan.price = this.value; renderList(); } });
      $('selPricePro').addEventListener('input', function () { if (selected) { selected._plan.pricePro = this.value; renderList(); } });
      shopSel.addEventListener('change', function () {
        if (!selected) return;
        selected._plan.shop = this.value;
        if (this.value && !selected._plan.label) { selected._plan.label = data.shops[this.value]; $('selLabel').value = selected._plan.label; look(selected); }
        renderList();
      });
      $('selDel').addEventListener('click', function () { if (selected) { group.removeLayer(selected); select(null); count(); } });

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
        var m = selected._plan, src = selected, fromZone = isZone(src), made = [];
        sp.cells.forEach(function (c) { made.push(track(L.polygon(sp.quad(c)), { color: m.color, shop: fromZone ? '' : m.shop, surface: fromZone ? '' : m.surface })); });
        if (sp.aisle && $('splitAisleDraw').checked) track(L.polygon(sp.quad(sp.aisle)), { role: 'zone', ztype: 'allee', label: 'Allée', color: '#8a8a8a' });
        ghost.clearLayers();
        if (!fromZone) { group.removeLayer(src); select(null); } else select(src);      // une zone reste en place : elle contient désormais les parcelles
        if ($('numAuto').checked) made.forEach(function (l) { l._plan.num = numText(); look(l); });
        ghost.clearLayers(); justSplit = true;
        count();
      });

      // --- Localisation de la marchandise ---
      var placeMsg = {};
      // Adresse ↔ coordonnées : géocodeur IGN (adresses françaises), puis Photon en secours hors de France.
      function geocodeReverse(lat, lon) {
        return fetch('https://data.geopf.fr/geocodage/reverse?index=address&limit=1&lat=' + lat + '&lon=' + lon, { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) {
            var p = j && j.features && j.features[0] && j.features[0].properties;
            if (p && (p.street || p.city)) return { street: [p.housenumber, p.street].filter(Boolean).join(' '), postcode: p.postcode || '', city: p.city || '' };
            return fetch('https://photon.komoot.io/reverse?lang=fr&limit=1&lat=' + lat + '&lon=' + lon, { headers: { Accept: 'application/json' } })
              .then(function (r) { return r.ok ? r.json() : null; })
              .then(function (q) {
                var a = q && q.features && q.features[0] && q.features[0].properties;
                return a ? { street: [a.housenumber, a.street].filter(Boolean).join(' '), postcode: a.postcode || '', city: a.city || a.town || a.village || '' } : null;
              });
          });
      }
      function geocodeSearch(q) {
        return fetch('https://data.geopf.fr/geocodage/search?index=address&limit=1&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) {
            var f = j && j.features && j.features[0];
            if (f && f.geometry && f.geometry.coordinates) return { lat: f.geometry.coordinates[1], lon: f.geometry.coordinates[0], label: (f.properties && f.properties.label) || q };
            return fetch('https://photon.komoot.io/api/?lang=fr&limit=1&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
              .then(function (r) { return r.ok ? r.json() : null; })
              .then(function (q2) {
                var g = q2 && q2.features && q2.features[0];
                if (!g || !g.geometry || !g.geometry.coordinates) return null;
                var pr = g.properties || {};
                return { lat: g.geometry.coordinates[1], lon: g.geometry.coordinates[0], label: [pr.name || pr.street, pr.postcode, pr.city].filter(Boolean).join(' ') || q };
              });
          });
      }
      function renderPlaces() {
        var box = $('places'); box.textContent = '';
        Object.keys(data.shops).forEach(function (k) {
          var pl = places[k] = places[k] || { street: '', postcode: '', city: '', stand: '', note: '' };
          var card = document.createElement('div'); card.className = 'plan-sub';
          var h = document.createElement('strong'); h.textContent = data.shops[k]; card.appendChild(h);
          var row = document.createElement('div'); row.className = 'plan-grid';
          function field(label, key, ph, attrs) {
            var l = document.createElement('label'); l.className = 'f'; l.appendChild(document.createTextNode(label));
            var i = document.createElement('input'); i.type = 'text'; i.value = pl[key]; i.placeholder = ph || ''; Object.keys(attrs || {}).forEach(function (a) { i.setAttribute(a, attrs[a]); });
            i.addEventListener('input', function () { pl[key] = i.value; }); l.appendChild(i); row.appendChild(l); return i;
          }
          field('Adresse', 'street', 'N° et rue', { maxlength: 120 }); field('Code postal', 'postcode', '69001', { maxlength: 10 }); field('Ville', 'city', 'Lyon', { maxlength: 60 });
          var stand = field('Emplacement', 'stand', 'N° de stand', { maxlength: 12 });
          card.appendChild(row);
          var acts = document.createElement('div'); acts.className = 'row';
          var b = document.createElement('button'); b.type = 'button'; b.className = 'btn mini'; b.textContent = 'Reprendre l\'adresse de son repère sur le plan';
          var msg = document.createElement('span'); msg.className = 'meta'; msg.textContent = placeMsg[k] || '';
          b.addEventListener('click', function () {
            var l = group.getLayers().filter(function (x) { return x._plan.shop === k; })[0];
            if (!l) { msg.textContent = 'Reliez d\'abord un élément du plan à ce commerce.'; return; }
            var c = centroid(l); msg.textContent = 'Recherche…';
            if (l._plan.num && !pl.stand) { pl.stand = l._plan.num; stand.value = pl.stand; }
            geocodeReverse(c[0], c[1]).then(function (a) {
              if (a) { pl.street = a.street; pl.postcode = a.postcode; pl.city = a.city; }
              placeMsg[k] = a && (a.street || a.city) ? 'Adresse trouvée : vérifiez-la.' : 'Aucune adresse à cet endroit : saisissez-la.';
              renderPlaces();
            }).catch(function () { msg.textContent = 'Recherche indisponible.'; });
          });
          acts.appendChild(b); acts.appendChild(msg); card.appendChild(acts); box.appendChild(card);
        });
      }
      renderPlaces();

      // --- Enregistrement ---
      $('saveForm').addEventListener('submit', function () {
        var c = map.getCenter();
        $('mapJson').value = JSON.stringify({ center: [c.lat, c.lng], zoom: map.getZoom(), base: hidden ? 'none' : baseKind, catalog: catalog.filter(function (x) { return x.name && x.w >= 0.5 && x.d >= 0.5; }), places: places,
          items: group.getLayers().map(function (l) { var m = l._plan; return { id: m.id, kind: kindOf(l), pts: points(l), label: m.label, num: m.num, shop: m.shop, color: m.color, note: m.note, surface: m.surface, role: m.role, ztype: m.ztype, unit: m.unit, price_cents: toCents(m.price), price_pro_cents: toCents(m.pricePro) }; }), costs: { fixed_cents: toCents($('simFixed').value), var_cents: toCents($('simVar').value), pct: num('simPct', 0), occ: [0, 1, 2].map(function (i) { return num('simOcc' + i, 0); }), adj: [0, 1, 2].map(function (i) { return num('simAdj' + i, 0); }) }, booking: { enabled: $('bkOn').checked, hold_days: parseInt($('bkDays').value, 10) || 7, intro: $('bkIntro').value } });
      });

      // --- Hypothèses de simulation du chiffre d'affaires ---
      var cst = saved.costs || {};
      if (cst.fixed_cents) $('simFixed').value = fm(cst.fixed_cents / 100); if (cst.var_cents) $('simVar').value = fm(cst.var_cents / 100); if (cst.pct) $('simPct').value = cst.pct;
      [0, 1, 2].forEach(function (i) { if (cst.occ && cst.occ[i] != null) $('simOcc' + i).value = cst.occ[i]; if (cst.adj && cst.adj[i] != null) $('simAdj' + i).value = cst.adj[i]; });

      // --- Réservation par les exposants ---
      $('bookCard').hidden = !data.features.exposants;
      var bk = saved.booking || {}; $('bkOn').checked = !!bk.enabled; $('bkDays').value = bk.hold_days || 7; $('bkIntro').value = bk.intro || '';

      // --- Plein écran (modale) ---
      var card = $('planCard'), fsSide = $('fsSide'), fsOn = false;
      function resizeMap() { setTimeout(function () { map.invalidateSize(); }, 60); }
      function openFs() {
        fsOn = true; card.classList.add('fs'); card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-label', 'Plan en plein écran');
        fsSide.hidden = false; fsSide.appendChild($('catCard')); fsSide.appendChild($('numCard'));
        $('fsOpen').hidden = true; $('fsClose').hidden = false; document.body.style.overflow = 'hidden'; card.scrollTop = 0; resizeMap(); $('fsClose').focus();
      }
      function closeFs() {
        if (!fsOn) return;
        fsOn = false; card.classList.remove('fs'); card.removeAttribute('role'); card.removeAttribute('aria-modal'); card.removeAttribute('aria-label');
        var before = $('placesCard'); before.parentNode.insertBefore($('catCard'), before); before.parentNode.insertBefore($('numCard'), before);
        fsSide.hidden = true; $('fsOpen').hidden = false; $('fsClose').hidden = true; document.body.style.overflow = ''; resizeMap(); $('fsOpen').focus();
      }
      $('fsOpen').addEventListener('click', openFs);
      $('fsClose').addEventListener('click', closeFs);
      window.addEventListener('resize', function () { if (fsOn) resizeMap(); });
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

      // --- Recherche d'adresse ---
      function search() {
        var q = $('addr').value.trim(); if (!q) return;
        $('addrMsg').textContent = 'Recherche…';
        geocodeSearch(q)
          .then(function (r) {
            if (!r) { $('addrMsg').textContent = 'Adresse introuvable.'; return; }
            $('addrMsg').textContent = r.label.slice(0, 90);
            map.setView([r.lat, r.lon], Math.max(map.getZoom(), 18));
          }).catch(function () { $('addrMsg').textContent = 'Recherche indisponible.'; });
      }
      $('addrGo').addEventListener('click', search);
      $('addr').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); search(); } });
    })();
    </script>
<?php ga_foot(); ?>
