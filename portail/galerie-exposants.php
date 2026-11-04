<?php
// Exposants d'une galerie : demandes d'emplacement faites sur le plan public (à accepter, refuser ou libérer), fiches légales,
// vérification des copies de pièce d'identité et registre des vendeurs (impression, export).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/gallery-waitlist.php';
require_once PORTAIL_ROOT . '/includes/client-types.php';

$actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
$self = '/portail/galerie-exposants.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$detailBase = '/portail/galerie-exposant.php' . ($limited ? '?' : '?g=' . urlencode($gaSlug) . '&');
$msgBase = '/portail/galerie-messages.php' . ($limited ? '?' : '?g=' . urlencode($gaSlug) . '&');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slot = (string) ($_POST['slot'] ?? '');
    $err = gb_decide($gaGallery, $slot, $action, $actor);
    if (!$err && in_array($action, ['refuse', 'release'], true)) gwl_on_release($gaGallery, $slot, $actor);
    $labels = ['accept' => 'Demande acceptée : l\'exposant est prévenu.', 'refuse' => 'Demande refusée : l\'emplacement est libre et l\'exposant est prévenu.', 'release' => 'Emplacement libéré : l\'exposant est prévenu.'];
    portail_flash($err ?? ($labels[$action] ?? 'Fait.'), $err ? 'error' : 'ok');
    header('Location: ' . $self);
    exit;
}

// Registre des vendeurs imprimable (mentions du code pénal, art. R321-9 à R321-12) : seuls les exposants attribués y figurent.
if (($_GET['registre'] ?? '') === '1') {
    $register = gb_register($gaGallery);
    $incomplete = array_filter($register, static fn ($r) => !gb_register_ok($r));
    header('Content-Type: text/html; charset=utf-8');
    header('X-Robots-Tag: noindex');
    ?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registre des vendeurs — <?= e($gaGallery['name']) ?></title>
<style>
  @page { size: A4 landscape; margin: 10mm; }
  body { font: 12px/1.35 system-ui, sans-serif; color: #111; margin: 16px; }
  h1 { font-size: 18px; margin: 0 0 2px; } .meta { color: #555; font-size: 11px; margin: 0 0 10px; }
  .legal { border: 1px solid #444; padding: 8px 10px; font-size: 10.5px; margin: 0 0 10px; }
  .legal strong { display: block; margin-bottom: 2px; }
  table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
  th, td { border: 1px solid #444; padding: 4px 5px; vertical-align: top; text-align: left; }
  th { background: #eee; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
  .n { width: 24px; text-align: right; } .sign { width: 90px; height: 34px; }
  .missing { color: #a11; font-weight: 700; }
  .warn { color: #a11; font-weight: 700; margin: 0 0 8px; }
  @media print { .noprint { display: none; } body { margin: 0; } }
</style></head><body>
<h1>Registre des vendeurs — <?= e($gaGallery['name']) ?></h1>
<p class="meta">Édité le <?= e(date('d/m/Y H:i')) ?> · <?= count($register) ?> exposant<?= count($register) > 1 ? 's' : '' ?> attribué<?= count($register) > 1 ? 's' : '' ?> · document à conserver cinq ans après la clôture du registre.</p>
<div class="legal"><strong>Obligations légales (code pénal, art. 321-7 et R321-9 à R321-12)</strong>
Ce registre doit être coté et paraphé par le commissaire de police ou, à défaut, par le maire. Il reste à la disposition des services de police, de gendarmerie, fiscaux, des douanes et de la concurrence pendant toute la manifestation, puis est déposé à la mairie du lieu dans les 8 jours. Les références des pièces d'identité sont recopiées (nature, numéro, date et autorité de délivrance) ; aucune copie de pièce n'est annexée. L'attestation sur l'honneur de chaque particulier y est mentionnée.</div>
<?php if ($incomplete): ?><p class="warn">Attention : <?= count($incomplete) ?> fiche(s) incomplète(s) (marquées « À compléter ») — complétez-les avant de faire coter et parapher le registre.</p><?php endif; ?>
<div class="noprint" style="margin:0 0 10px;"><button type="button" onclick="window.print()">Imprimer le registre</button> <a href="<?= e($self) ?>">Retour aux exposants</a></div>
<table><thead><tr><th class="n">N°</th><th>Date</th><th>Type</th><th>Nom / dénomination</th><th>Prénoms</th><th>Représentant</th><th>Fonction</th><th>Domicile / siège</th><th>Pièce d'identité</th><th>Attestation / déclaration</th><th class="sign">Signature</th></tr></thead><tbody>
<?php foreach ($register as $i => $r):
    $c = $r['client'];
    $reg = $c ? ct_register_row($c, $r['kind']) : null;
    $miss = $r['register_missing'];
    $cell = static function (string $v, bool $required) use ($miss, $reg): string {
        if (!$reg) return '<span class="missing">Fiche à saisir</span>';
        if ($v === '') return $required ? '<span class="missing">À compléter</span>' : '';
        return e($v);
    };
?>
<tr><td class="n"><?= $i + 1 ?></td><td><?= e(date('d/m/Y', (int) $r['requested'])) ?></td><td><?= e(ct_label($r['kind'])) ?></td>
<td><?= $cell((string) ($reg['name'] ?? ''), true) ?></td><td><?= $cell((string) ($reg['firstname'] ?? ''), $r['kind'] === 'particulier') ?></td>
<td><?= $cell((string) ($reg['representative'] ?? ''), $r['kind'] !== 'particulier') ?></td><td><?= $cell((string) ($reg['function'] ?? ''), true) ?></td>
<td><?= $reg ? nl2br(e($reg['address'])) : '<span class="missing">À compléter</span>' ?></td>
<td><?php if (!$reg): ?><span class="missing">À compléter</span><?php else: ?><?= e($reg['id_type']) ?> · n° <?= e($reg['id_number']) ?><br><small>Délivrée le <?= e($reg['id_date']) ?> par <?= e($reg['id_issuer']) ?></small><?php endif; ?></td>
<td><?= $cell((string) ($reg['attestation'] ?? ''), true) ?></td><td class="sign"></td></tr>
<?php endforeach; ?>
</tbody></table>
</body></html><?php
    exit;
}

$rows = gb_rows($gaGallery);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="exposants-' . $gaSlug . '.csv"');
    $q = static fn ($v) => '"' . str_replace('"', '""', ltrim((string) $v, "=+-@\t\r")) . '"';
    echo "\xEF\xBB\xBF" . "Emplacement;Type;Nom ou raison sociale;Prénoms;Représentant;Fonction;Domicile ou siège;RNA/SIREN/SIRET;Pièce;Numéro;Délivrée le;Autorité;Attestation/déclaration;E-mail;Téléphone;Tarif (€);État;Demandé le;Copie de pièce;Message\r\n";
    foreach ($rows as $r) {
        $c = $r['client'];
        $reg = $c ? ct_register_row($c, $r['kind']) : null;
        echo implode(';', [$q($r['slot']), $q(ct_label($r['kind'])), $q($reg['name'] ?? ''), $q($reg['firstname'] ?? ''), $q($reg['representative'] ?? ''),
            $q($reg['function'] ?? ''), $q($reg['address'] ?? ''), $q($reg['org'] ?? ''), $q($reg['id_type'] ?? ''), $q($reg['id_number'] ?? ''),
            $q($reg['id_date'] ?? ''), $q($reg['id_issuer'] ?? ''), $q($reg['attestation'] ?? ''), $q($r['email']), $q($r['phone']),
            $r['price_cents'] === null ? '' : number_format($r['price_cents'] / 100, 2, ',', ''), $q($r['status'] === 'taken' ? 'attribué' : ($r['expired'] ? 'expirée' : 'en attente')),
            $q(date('d/m/Y H:i', (int) $r['requested'])), $q(gb_cni_status_label($r['cni'] ?? null)), $q($r['message'])]) . "\r\n";
    }
    exit;
}
$b = gt_feature($gaGallery, 'exposants') ? gb_booking($gaGallery) : null;
$slots = array_values(array_filter($gaGallery['map']['items'] ?? [], static fn ($i) => $i['role'] === 'slot'));
$eventMode = $b && ($b['source'] ?? '') === 'event' && !empty($b['event']);
$totalSlots = $eventMode ? ge_place_count($b['event']) : count($slots);
$taken = count(array_filter($rows, static fn ($r) => $r['status'] === 'taken'));
$pending = count(array_filter($rows, static fn ($r) => $r['status'] === 'pending' && !$r['expired']));
$incomplete = count(array_filter($rows, static fn ($r) => !gb_register_ok($r)));
$cniPending = count(array_filter($rows, static fn ($r) => ($r['cni']['status'] ?? '') === 'pending'));
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
ga_head('Exposants');
?>
    <section class="gcard">
      <h2>Demandes d'emplacement</h2>
      <?php if (!$b || empty($b['enabled'])): ?>
        <p class="meta">Les demandes ne sont pas ouvertes. Activez-les dans <?php if ($eventMode || gm_enabled($gaGallery, 'organisateur')): ?><a href="/portail/galerie-evenement.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Événement</a> (réservations en ligne)<?php else: ?><a href="/portail/galerie-plan.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Plan des commerces</a> (bloc « Exposants : choisir son emplacement »)<?php endif; ?>.</p>
      <?php else: ?>
        <p class="meta"><?= $eventMode
          ? 'Les exposants choisissent une place numérotée de l\'événement. Une demande en attente bloque la place ' . (int) $b['hold_days'] . ' jour(s) ; vous la validez ici et l\'exposant est prévenu par e-mail.'
          : 'Les exposants choisissent un emplacement libre sur le plan de la page publique. Une demande en attente bloque l\'emplacement ' . (int) $b['hold_days'] . ' jour(s) ; vous la validez ici et l\'exposant est prévenu par e-mail.' ?></p>
        <?php if ($eventMode): ?><p class="meta" style="margin:0;">Suivi en temps réel et export du registre : <a href="/portail/galerie-inscriptions.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Tableau de bord des inscriptions</a> · <a href="/portail/galerie-registre.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Registre de police</a>.</p><?php endif; ?>
      <?php endif; ?>
      <div class="kpis">
        <div class="kpi"><b><?= (int) $totalSlots ?></b><span><?= $eventMode ? 'places de l\'événement' : 'emplacements sur le plan' ?></span></div>
        <div class="kpi"><b><?= $taken ?></b><span>attribués</span></div>
        <div class="kpi"><b><?= $pending ?></b><span>demandes en attente</span></div>
        <div class="kpi"><b><?= max(0, $totalSlots - $taken - $pending) ?></b><span>libres</span></div>
        <div class="kpi"><b><?= $incomplete ?></b><span>fiches à compléter</span></div>
        <div class="kpi"><b><?= $cniPending ?></b><span>pièces à vérifier</span></div>
      </div>
      <p class="meta">Registre des vendeurs : les exposants sont de trois types (particulier, association, entreprise). Les références de leur pièce d'identité sont obligatoires ; la copie de la pièce, facultative, est conservée au maximum <?= (int) GB_CNI_RETENTION_DAYS ?> jours puis supprimée automatiquement. Le registre se conserve cinq ans après sa clôture.</p>
      <?php if (!$rows): ?><p class="meta">Aucune demande pour le moment.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Emplacement</th><th>Exposant</th><th>Profil</th><th>Registre</th><th>Pièce</th><th class="num">Tarif</th><th>État</th><th>Demandé le</th><th></th></tr></thead><tbody>
      <?php foreach ($rows as $r):
          $c = $r['client'];
          $orgLine = $c && $r['kind'] !== 'particulier' ? trim((string) $c['org_name']) : '';
          $repLine = $c && $r['kind'] !== 'particulier' ? trim('Représentant : ' . $c['rep_firstname'] . ' ' . $c['rep_lastname'] . ($c['rep_function'] !== '' ? ' (' . $c['rep_function'] . ')' : '')) : '';
          $cni = $r['cni'] ?? null;
      ?>
        <tr><td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?><br><small class="meta"><?= e($r['email']) ?><?= $r['phone'] !== '' ? ' · ' . e($r['phone']) : '' ?></small>
            <?php if ($orgLine !== ''): ?><br><small class="meta"><?= e($orgLine) ?></small><?php endif; ?>
            <?php if ($repLine !== ''): ?><br><small class="meta"><?= e($repLine) ?></small><?php endif; ?>
            <?php if ($r['message'] !== ''): ?><br><small class="meta">« <?= e($r['message']) ?> »</small><?php endif; ?></td>
          <td><?= e(ct_label($r['kind'])) ?></td>
          <td><?php if (gb_register_ok($r)): ?><span class="pill done">Complet</span><?php else: ?><span class="pill" title="<?= e(implode(', ', $r['register_missing'])) ?>"><?= count($r['register_missing']) ?> manque<?= count($r['register_missing']) > 1 ? 'nt' : '' ?></span><?php endif; ?></td>
          <td><?php $cniStatus = (string) ($cni['status'] ?? ''); ?>
            <?php if ($cniStatus === 'pending'): ?><span class="pill sent">À vérifier</span>
            <?php elseif ($cniStatus === 'verified'): ?><span class="pill done">Vérifiée</span>
            <?php elseif ($cniStatus === 'rejected'): ?><span class="pill">Refusée</span>
            <?php elseif ($cniStatus === 'purged'): ?><span class="meta">Purgée</span>
            <?php elseif ($cniStatus === 'deleted'): ?><span class="meta">Supprimée</span>
            <?php else: ?><span class="meta">Sans copie</span><?php endif; ?></td>
          <td class="num"><?= e(gb_eur($r['price_cents'])) ?></td>
          <td><?= $r['status'] === 'taken' ? '<span class="pill done">Attribué</span>' : ($r['expired'] ? '<span class="pill">Expirée</span>' : '<span class="pill sent">En attente</span>') ?></td>
          <td><?= e(date('d/m/Y H:i', (int) $r['requested'])) ?></td>
          <td><div class="row" style="gap:4px;">
            <a class="btn small" href="<?= e($detailBase . 'slot=' . urlencode($r['slot_id'])) ?>">Fiche</a>
            <a class="btn small" href="<?= e($msgBase . 'new=slot:' . urlencode($r['slot_id'])) ?>">Message</a>
            <form method="post" class="row" style="gap:4px;"><?= $hid ?><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
            <?php if ($r['status'] === 'pending'): ?>
              <button class="btn small btn-primary" name="action" value="accept" type="submit">Accepter</button>
              <button class="btn small" name="action" value="refuse" type="submit" onclick="return confirm('Refuser cette demande ? L\'exposant en est informé.');">Refuser</button>
            <?php else: ?>
              <button class="btn small" name="action" value="release" type="submit" onclick="return confirm('Libérer cet emplacement ? L\'exposant en est informé.');">Libérer</button>
            <?php endif; ?>
            </form>
          </div></td></tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <p><a class="btn" href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'export=csv') ?>">Exporter (CSV)</a>
        <a class="btn" href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'registre=1') ?>" target="_blank" rel="noopener">Registre des vendeurs (imprimer)</a></p>
      <?php endif; ?>
    </section>
<?php ga_foot(); ?>
