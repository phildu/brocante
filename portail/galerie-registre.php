<?php
// Registre de police d'une galerie : export en un clic des exposants attribués (Excel, impression/PDF, CSV),
// avec l'en-tête de la manifestation et les mentions du code pénal (art. 321-7 et R321-9 à R321-12).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/client-types.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';

$self = '/portail/galerie-registre.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$event = gallery_event($gaGallery);
$register = gb_register($gaGallery);
usort($register, static fn ($a, $b) => strnatcasecmp((string) $a['slot'], (string) $b['slot']));
$incomplete = array_filter($register, static fn ($r) => !gb_register_ok($r));

$editDate = date('d/m/Y');
$editStamp = date('d/m/Y H:i');
$legal = "Ce registre doit être coté et paraphé par le commissaire de police ou, à défaut, par le maire. Il reste à la disposition des services de police, de gendarmerie, fiscaux, des douanes et de la concurrence pendant toute la manifestation, puis est déposé à la mairie du lieu dans les 8 jours. Les pièces d'identité sont recopiées (nature, numéro, date et autorité de délivrance) ; aucune copie n'est annexée. Les attestations des particuliers sont jointes au registre. Document à conserver cinq ans après la clôture du registre.";

$placeCount = $event ? ge_place_count($event) : 0;
if ($placeCount < 1) {
    $placeCount = count(array_filter($gaGallery['map']['items'] ?? [], static fn ($i) => ($i['role'] ?? '') === 'slot'));
}
$meta = [
    'Organisateur' => $gaGallery['name'],
    'Manifestation' => $event && $event['title'] !== '' ? $event['title'] : $gaGallery['name'],
    'Date' => $event ? (ge_date_text($event) ?: '—') : '—',
    'Lieu' => $event ? (ge_place_text($event) ?: '—') : '—',
    'Horaires' => $event ? (ge_hours_text($event) ?: '—') : '—',
    'Places' => $placeCount > 0 ? (string) $placeCount : '—',
    'Exposants inscrits' => (string) count($register),
    'Édité le' => $editStamp,
];

// Colonnes du registre : libellé et largeur (caractères, pour Excel).
$columns = [
    ['N°', 5], ['Emplacement', 14], ['Date', 11], ['Type', 13], ['Nom / dénomination', 28], ['Prénoms', 18],
    ['Représentant', 20], ['Fonction', 16], ['Domicile / siège', 36], ['RNA / SIREN / SIRET', 18],
    ["Pièce d'identité", 14], ['Numéro', 16], ['Délivrée le', 11], ['Autorité', 15], ['Attestation', 13], ['Signature', 20],
];
$colCount = count($columns);

/** Ligne de registre : valeurs dans l'ordre des colonnes, et colonnes manquantes. */
$rowOf = static function (array $r, int $n) use ($columns): array {
    $c = $r['client'] ?? null;
    $reg = $c ? ct_register_row($c, (string) $r['kind']) : null;
    $vals = [
        (string) $n,
        (string) ($r['slot'] ?? ''),
        date('d/m/Y', (int) ($r['requested'] ?? time())),
        ct_label((string) ($r['kind'] ?? 'particulier')),
        $reg ? (string) $reg['name'] : '',
        $reg ? (string) $reg['firstname'] : '',
        $reg ? (string) $reg['representative'] : '',
        $reg ? (string) $reg['function'] : '',
        $reg ? (string) $reg['address'] : '',
        $reg ? (string) $reg['org'] : '',
        $reg ? (string) $reg['id_type'] : '',
        $reg ? (string) $reg['id_number'] : '',
        $reg ? (string) $reg['id_date'] : '',
        $reg ? (string) $reg['id_issuer'] : '',
        $reg ? (string) $reg['attestation'] : '',
        '',
    ];
    $missing = [];
    if (!$reg) {
        foreach ([4, 7, 8, 10, 11, 12, 13, 14] as $i) $missing[$i] = true;
    } else {
        if ($vals[4] === '') $missing[4] = true;
        if ($vals[5] === '' && ($r['kind'] ?? '') === 'particulier') $missing[5] = true;
        if ($vals[6] === '' && ($r['kind'] ?? '') !== 'particulier') $missing[6] = true;
        if ($vals[7] === '') $missing[7] = true;
        if ($vals[8] === '') $missing[8] = true;
        if ($vals[10] === '' || $vals[11] === '' || $vals[12] === '' || $vals[13] === '') $missing[10] = $missing[11] = $missing[12] = $missing[13] = true;
        if ($vals[14] === '') $missing[14] = true;
    }
    return [$vals, $missing];
};

$format = (string) ($_GET['format'] ?? '');

if ($format === 'xls') {
    $x = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $border = '<Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#444444"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#444444"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#444444"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#444444"/></Borders>';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="registre-police-' . $gaSlug . '-' . date('Ymd') . '.xls"');
    header('Cache-Control: no-store');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    ?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>Registre des vendeurs — <?= $x($gaGallery['name']) ?></Title><Author><?= $x($gaGallery['name']) ?></Author><Created><?= $x(gmdate('Y-m-d\TH:i:s\Z')) ?></Created></DocumentProperties>
 <Styles>
  <Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Top" ss:WrapText="1"/><Font ss:FontName="Arial" ss:Size="10"/><Borders/></Style>
  <Style ss:ID="title"><Font ss:FontName="Arial" ss:Size="16" ss:Bold="1"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="sub"><Font ss:FontName="Arial" ss:Size="11" ss:Bold="1"/><Alignment ss:Vertical="Center"/></Style>
  <Style ss:ID="meta"><Font ss:FontName="Arial" ss:Size="9"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>
  <Style ss:ID="legal"><Font ss:FontName="Arial" ss:Size="9" ss:Italic="1"/><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style>
  <Style ss:ID="hdr"><Font ss:FontName="Arial" ss:Size="9" ss:Bold="1"/><Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/><Interior ss:Color="#E8E8E8" ss:Pattern="Solid"/><?= $border ?></Style>
  <Style ss:ID="cell"><?= $border ?></Style>
  <Style ss:ID="num"><?= $border ?><Alignment ss:Horizontal="Center"/></Style>
  <Style ss:ID="miss"><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#AA1111"/><?= $border ?></Style>
  <Style ss:ID="sign"><?= $border ?></Style>
  <Style ss:ID="foot"><Font ss:FontName="Arial" ss:Size="8" ss:Color="#555555"/></Style>
 </Styles>
 <Worksheet ss:Name="Registre">
  <Table>
<?php foreach ($columns as [$label, $width]): ?>
   <Column ss:AutoFitWidth="0" ss:Width="<?= (int) round($width * 6.2) ?>"/>
<?php endforeach; ?>
   <Row ss:Height="26"><Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="title"><Data ss:Type="String">REGISTRE DES VENDEURS</Data></Cell></Row>
   <Row ss:Height="18"><Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="sub"><Data ss:Type="String"><?= $x($gaGallery['name']) ?></Data></Cell></Row>
<?php foreach ($meta as $label => $value): ?>
   <Row><Cell ss:StyleID="sub"><Data ss:Type="String"><?= $x($label) ?></Data></Cell><Cell ss:MergeAcross="<?= $colCount - 2 ?>" ss:StyleID="meta"><Data ss:Type="String"><?= $x($value) ?></Data></Cell></Row>
<?php endforeach; ?>
   <Row ss:Height="60"><Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="legal"><Data ss:Type="String">Obligations légales (code pénal, art. 321-7 et R321-9 à R321-12). <?= $x($legal) ?></Data></Cell></Row>
   <Row ss:Height="6"></Row>
   <Row ss:Height="34">
<?php foreach ($columns as [$label]): ?>
    <Cell ss:StyleID="hdr"><Data ss:Type="String"><?= $x($label) ?></Data></Cell>
<?php endforeach; ?>
   </Row>
<?php foreach ($register as $i => $r): [$vals, $missing] = $rowOf($r, $i + 1); ?>
   <Row ss:Height="30">
<?php foreach ($vals as $j => $v): ?>
<?php if ($j === 0): ?>
    <Cell ss:StyleID="num"><Data ss:Type="Number"><?= (int) $v ?></Data></Cell>
<?php elseif ($j === 15): ?>
    <Cell ss:StyleID="sign"><Data ss:Type="String"></Data></Cell>
<?php elseif (isset($missing[$j]) && $v === ''): ?>
    <Cell ss:StyleID="miss"><Data ss:Type="String">À compléter</Data></Cell>
<?php else: ?>
    <Cell ss:StyleID="cell"><Data ss:Type="String"><?= $x($v) ?></Data></Cell>
<?php endif; ?>
<?php endforeach; ?>
   </Row>
<?php endforeach; ?>
   <Row ss:Height="6"></Row>
   <Row><Cell ss:MergeAcross="<?= $colCount - 1 ?>" ss:StyleID="foot"><Data ss:Type="String">Édité le <?= $x($editStamp) ?> — <?= count($register) ?> exposant<?= count($register) > 1 ? 's' : '' ?> attribué<?= count($register) > 1 ? 's' : '' ?><?= $incomplete ? ' — ' . count($incomplete) . ' fiche(s) à compléter' : '' ?> — document à conserver cinq ans après la clôture du registre.</Data></Cell></Row>
  </Table>
  <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
   <PageSetup><Layout x:Orientation="Landscape" x:CenterHorizontal="1"/><Header x:Margin="0.2"/><Footer x:Margin="0.2"/><PageMargins x:Bottom="0.4" x:Left="0.3" x:Right="0.3" x:Top="0.4"/></PageSetup>
   <FitToPage/><Print><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex><HorizontalResolution>600</HorizontalResolution><VerticalResolution>600</VerticalResolution></Print>
   <Selected/><FreezePanes/><FrozenNoSplit/><SplitHorizontal>12</SplitHorizontal><TopRowBottomPane>12</TopRowBottomPane><ActivePane>2</ActivePane>
   <ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios>
  </WorksheetOptions>
 </Worksheet>
</Workbook><?php
    exit;
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registre-police-' . $gaSlug . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    $q = static fn ($v): string => '"' . str_replace('"', '""', ltrim((string) $v, "=+-@\t\r")) . '"';
    echo "\xEF\xBB\xBF";
    echo implode(';', array_map(static fn ($c) => $q($c[0]), $columns)) . "\r\n";
    foreach ($meta as $label => $value) echo $q($label) . ';' . $q($value) . "\r\n";
    echo $q('Mentions légales') . ';' . $q($legal) . "\r\n\r\n";
    foreach ($register as $i => $r) {
        [$vals] = $rowOf($r, $i + 1);
        echo implode(';', array_map($q, $vals)) . "\r\n";
    }
    exit;
}

if ($format === 'print' || $format === 'pdf') {
    $auto = !empty($_GET['print']);
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    ?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registre des vendeurs — <?= e($gaGallery['name']) ?></title>
<style>
  @page { size: A4 landscape; margin: 9mm; }
  body { font: 11px/1.3 system-ui, sans-serif; color: #111; margin: 14px; }
  h1 { font-size: 17px; margin: 0 0 1px; letter-spacing: .04em; } h2 { font-size: 12px; margin: 0 0 8px; font-weight: 600; }
  .meta { color: #444; font-size: 10.5px; margin: 0 0 8px; }
  .meta b { color: #111; }
  .legal { border: 1px solid #444; padding: 7px 9px; font-size: 9.5px; margin: 0 0 8px; }
  .legal strong { display: block; margin-bottom: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 9.5px; }
  th, td { border: 1px solid #444; padding: 3px 4px; vertical-align: top; text-align: left; }
  th { background: #eee; font-size: 8.5px; text-transform: uppercase; letter-spacing: .02em; }
  thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .n { width: 22px; text-align: center; } .sign { width: 80px; height: 30px; }
  .missing { color: #a11; font-weight: 700; }
  .warn { color: #a11; font-weight: 700; margin: 0 0 8px; }
  .noprint { margin: 0 0 10px; display: flex; gap: 8px; flex-wrap: wrap; }
  .noprint a, .noprint button { font: inherit; border: 1px solid #888; background: #fff; border-radius: 5px; padding: 5px 9px; color: #111; text-decoration: none; cursor: pointer; }
  .foot { color: #555; font-size: 9px; margin-top: 8px; }
  @media print { .noprint { display: none; } body { margin: 0; } }
</style></head><body>
<div class="noprint"><button type="button" onclick="window.print()">Imprimer / enregistrer en PDF</button>
  <a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'format=xls') ?>">Télécharger Excel</a>
  <a href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'format=csv') ?>">CSV</a>
  <a href="<?= e($self) ?>">Retour</a></div>
<h1>REGISTRE DES VENDEURS</h1>
<h2><?= e($gaGallery['name']) ?><?= $event && $event['title'] !== '' ? ' — ' . e($event['title']) : '' ?></h2>
<p class="meta"><?php $parts = []; foreach ($meta as $label => $value) if (!in_array($label, ['Organisateur', 'Manifestation', 'Édité le'], true)) $parts[] = e($label) . ' : <b>' . e($value) . '</b>'; echo implode(' · ', $parts) . ' · Édité le <b>' . e($editStamp) . '</b>'; ?></p>
<div class="legal"><strong>Obligations légales (code pénal, art. 321-7 et R321-9 à R321-12)</strong><?= e($legal) ?></div>
<?php if ($incomplete): ?><p class="warn">Attention : <?= count($incomplete) ?> fiche(s) incomplète(s) (marquées « À compléter ») — complétez-les avant de faire coter et parapher le registre.</p><?php endif; ?>
<?php if (!$register): ?><p class="warn">Aucun exposant attribué pour le moment : le registre sera à rééditer après les validations.</p><?php endif; ?>
<table><thead><tr><?php foreach ($columns as $ci => [$label]): ?><th<?= $ci === 0 ? ' class="n"' : ($ci === 15 ? ' class="sign"' : '') ?>><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($register as $i => $r): [$vals, $missing] = $rowOf($r, $i + 1); ?>
<tr><?php foreach ($vals as $j => $v): ?>
<?php if ($j === 0): ?><td class="n"><?= (int) $v ?></td>
<?php elseif ($j === 15): ?><td class="sign"></td>
<?php elseif (isset($missing[$j]) && $v === ''): ?><td><span class="missing">À compléter</span></td>
<?php else: ?><td><?= nl2br(e($v)) ?></td><?php endif; ?>
<?php endforeach; ?></tr>
<?php endforeach; ?>
</tbody></table>
<p class="foot">Édité le <?= e($editStamp) ?> — <?= count($register) ?> exposant<?= count($register) > 1 ? 's' : '' ?> attribué<?= count($register) > 1 ? 's' : '' ?>. Les pièces d'identité ne sont pas annexées : seules leurs références sont recopiées. À conserver cinq ans après la clôture.</p>
<?php if ($auto): ?><script>window.addEventListener('load',function(){window.print();});</script><?php endif; ?>
</body></html><?php
    exit;
}

$q = $self . (str_contains($self, '?') ? '&' : '?');
ga_head('Registre de police');
?>
    <section class="gcard">
      <h2>Registre de police</h2>
      <p class="meta">Le fichier reprend les exposants <strong>attribués</strong>, leurs références de pièce d'identité et les mentions du code pénal. Il se cote et se paraphe avant la manifestation, puis se dépose en mairie dans les 8 jours.</p>
      <div class="kpis">
        <div class="kpi"><b><?= count($register) ?></b><span>exposants au registre</span></div>
        <div class="kpi"><b><?= count($incomplete) ?></b><span>fiches à compléter</span></div>
        <div class="kpi"><b><?= (int) $placeCount ?></b><span>places de la manifestation</span></div>
      </div>
      <?php if ($incomplete): ?><p class="flash" data-kind="error"><?= count($incomplete) ?> fiche(s) incomplète(s) : complétez-les dans <a href="/portail/galerie-inscriptions.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Inscriptions</a> avant de faire coter le registre.</p><?php endif; ?>
      <div class="row">
        <a class="btn btn-primary" href="<?= e($q . 'format=xls') ?>">Télécharger Excel</a>
        <a class="btn" href="<?= e($q . 'format=print&amp;print=1') ?>" target="_blank" rel="noopener">Imprimer / PDF</a>
        <a class="btn" href="<?= e($q . 'format=csv') ?>">CSV</a>
        <a class="btn" href="<?= e($q . 'format=print') ?>" target="_blank" rel="noopener">Aperçu avant impression</a>
      </div>
      <p class="meta">Format Excel : une feuille paysage prête à imprimer, colonne « Signature » vide pour le paraphe. Format impression : utiliser « Enregistrer au format PDF » dans la boîte d'impression du navigateur.</p>
    </section>

    <section class="gcard">
      <h2>En-tête de la manifestation</h2>
      <table class="t"><tbody>
      <?php foreach ($meta as $label => $value): ?><tr><th><?= e($label) ?></th><td><?= e($value) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php if (!$event): ?><p class="meta">Aucun événement renseigné : <a href="/portail/galerie-evenement.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">créer l'événement</a> pour compléter l'en-tête.</p><?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Aperçu du registre</h2>
      <?php if (!$register): ?>
        <p class="meta">Aucun exposant attribué : le fichier sera vide (en-tête seul), utile pour pré-remplir le cahier papier.</p>
      <?php else: ?>
      <div style="overflow:auto;">
        <table class="t"><thead><tr><?php foreach ($columns as $ci => [$label]): ?><th<?= $ci === 0 || $ci === 15 ? ' class="num"' : '' ?>><?= e($label) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach (array_slice($register, 0, 12) as $i => $r): [$vals, $missing] = $rowOf($r, $i + 1); ?>
          <tr><?php foreach ($vals as $j => $v): ?>
            <td<?= $j === 0 || $j === 15 ? ' class="num"' : '' ?>><?= isset($missing[$j]) && $v === '' ? '<span style="color:#b42318;font-weight:600;">À compléter</span>' : e($v) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php if (count($register) > 12): ?><p class="meta">Aperçu des 12 premières lignes sur <?= count($register) ?>.</p><?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Marche à suivre</h2>
      <ol class="meta" style="margin:0;padding-left:18px;">
        <li>Validez les demandes dans <a href="/portail/galerie-inscriptions.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Inscriptions</a> : seuls les exposants attribués entrent au registre.</li>
        <li>Complétez les fiches signalées « À compléter » (pièce d'identité, domicile, représentant).</li>
        <li>Téléchargez le fichier Excel, imprimez-le et faites-le coter et parapher.</li>
        <li>Déposez le registre à la mairie du lieu dans les 8 jours suivant la manifestation.</li>
      </ol>
    </section>
<?php ga_foot();
