<?php
// Tableau de bord des inscriptions d'une galerie (vide-grenier, marché aux puces) : demandes reçues, validées, en attente ou expirées,
// avec mise à jour automatique (toutes les 12 s) et les mêmes actions que la page Exposants (accepter, refuser, libérer).
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/gallery-waitlist.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';

$self = '/portail/galerie-inscriptions.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$with = static function (string $extra) use ($self): string {
    return $self . (str_contains($self, '?') ? '&' : '?') . $extra;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slot = (string) ($_POST['slot'] ?? '');
    $actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
    $err = gb_decide($gaGallery, $slot, $action, $actor);
    if (!$err && in_array($action, ['refuse', 'release'], true)) gwl_on_release($gaGallery, $slot, $actor);
    $labels = ['accept' => 'Demande acceptée : l\'exposant est prévenu.', 'refuse' => 'Demande refusée : la place est libre et l\'exposant est prévenu.', 'release' => 'Place libérée : l\'exposant est prévenu.'];
    portail_flash($err ?? ($labels[$action] ?? 'Fait.'), $err ? 'error' : 'ok');
    $filter = (string) ($_POST['etat'] ?? '');
    header('Location: ' . $self . (in_array($filter, ['pending', 'taken', 'expired'], true) ? '&etat=' . $filter : ''));
    exit;
}

$event = gallery_event($gaGallery);
$rows = gb_rows($gaGallery);
$mapSlots = count(array_filter($gaGallery['map']['items'] ?? [], static fn ($i) => ($i['role'] ?? '') === 'slot'));
$eventSlots = $event && gm_enabled($gaGallery, 'organisateur') ? ge_place_count($event) : 0;
$slotTotal = $mapSlots + $eventSlots;
$taken = count(array_filter($rows, static fn ($r) => ($r['status'] ?? '') === 'taken'));
$pending = count(array_filter($rows, static fn ($r) => ($r['status'] ?? '') === 'pending' && empty($r['expired'])));
$expired = count(array_filter($rows, static fn ($r) => ($r['status'] ?? '') === 'pending' && !empty($r['expired'])));
$free = max(0, $slotTotal - $taken - $pending);
$revenue = array_sum(array_map(static fn ($r) => (int) ($r['price_cents'] ?? 0), array_filter($rows, static fn ($r) => ($r['status'] ?? '') === 'taken')));

$filter = (string) ($_GET['etat'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'taken', 'expired'], true)) $filter = 'all';
$visible = array_values(array_filter($rows, static function (array $r) use ($filter): bool {
    if ($filter === 'pending') return ($r['status'] ?? '') === 'pending' && empty($r['expired']);
    if ($filter === 'taken') return ($r['status'] ?? '') === 'taken';
    if ($filter === 'expired') return ($r['status'] ?? '') === 'pending' && !empty($r['expired']);
    return true;
}));

if (($_GET['json'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode([
        'generated' => time(),
        'kpis' => ['total' => $slotTotal, 'taken' => $taken, 'pending' => $pending, 'expired' => $expired, 'free' => $free, 'revenue' => $revenue],
        'rows' => array_map(static fn ($r) => ['slot_id' => (string) $r['slot_id'], 'slot' => (string) $r['slot'], 'name' => (string) $r['name'],
            'status' => (string) $r['status'], 'expired' => !empty($r['expired']), 'requested' => (int) $r['requested'], 'price_cents' => $r['price_cents']], $rows),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$registre = '/portail/galerie-registre.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$evenement = '/portail/galerie-evenement.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$exposants = '/portail/galerie-exposants.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$hid = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">')
    . '<input type="hidden" name="etat" value="' . e($filter) . '">';
ga_head('Inscriptions');
?>
    <?php if (!$event && !$mapSlots): ?>
      <section class="gcard"><h2>Aucun emplacement à réserver</h2>
        <p>Créez l'événement et son nombre de places : la numérotation est automatique. Vous pouvez aussi dessiner un plan si l'option Carte interactive est active.</p>
        <p><a class="btn btn-primary" href="<?= e($evenement) ?>">Créer l'événement</a> <a class="btn" href="<?= e('/portail/galerie-plan.php' . ($limited ? '' : '?g=' . urlencode($gaSlug))) ?>">Ouvrir le plan</a></p></section>
    <?php endif; ?>

    <section class="gcard">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div>
          <h2 style="margin:0;"><?= $event ? e($event['title']) : 'Inscriptions' ?></h2>
          <p class="meta" style="margin:4px 0 0;"><?= $event ? e(implode(' · ', array_filter([ge_date_text($event), ge_place_text($event), ge_hours_text($event)]))) : 'Plan de la galerie' ?></p>
        </div>
        <span class="pill" id="live-dot" title="Mise à jour automatique toutes les 12 secondes">● en direct</span>
      </div>
      <div class="kpis">
        <div class="kpi"><b data-kpi="total"><?= $slotTotal ?></b><span>places au total</span></div>
        <div class="kpi"><b data-kpi="pending"><?= $pending ?></b><span>en attente</span></div>
        <div class="kpi"><b data-kpi="taken"><?= $taken ?></b><span>attribuées</span></div>
        <div class="kpi"><b data-kpi="free"><?= $free ?></b><span>libres</span></div>
        <div class="kpi"><b data-kpi="expired"><?= $expired ?></b><span>demandes expirées</span></div>
        <div class="kpi"><b data-kpi="revenue"><?= e(gb_eur($revenue)) ?></b><span>recettes attribuées</span></div>
      </div>
      <div class="row">
        <a class="btn<?= $filter === 'all' ? ' btn-primary' : '' ?>" href="<?= e($self) ?>">Toutes</a>
        <a class="btn<?= $filter === 'pending' ? ' btn-primary' : '' ?>" href="<?= e($with('etat=pending')) ?>">En attente (<?= $pending ?>)</a>
        <a class="btn<?= $filter === 'taken' ? ' btn-primary' : '' ?>" href="<?= e($with('etat=taken')) ?>">Attribuées (<?= $taken ?>)</a>
        <a class="btn<?= $filter === 'expired' ? ' btn-primary' : '' ?>" href="<?= e($with('etat=expired')) ?>">Expirées (<?= $expired ?>)</a>
      </div>
      <p id="live-new" class="flash" data-kind="ok" hidden></p>
    </section>

    <section class="gcard">
      <h2>Demandes d'emplacement</h2>
      <?php if (!$visible): ?><p class="meta">Aucune demande dans cette liste.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Emplacement</th><th>Exposant</th><th>Profil</th><th>Registre</th><th class="num">Tarif</th><th>État</th><th>Demandé le</th><th></th></tr></thead><tbody>
      <?php foreach ($visible as $r): $c = $r['client']; $org = $c && $r['kind'] !== 'particulier' ? trim((string) $c['org_name']) : ''; ?>
        <tr data-slot="<?= e($r['slot_id']) ?>">
          <td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?><br><small class="meta"><?= e($r['email']) ?><?= $r['phone'] !== '' ? ' · ' . e($r['phone']) : '' ?></small><?php if ($org !== ''): ?><br><small class="meta"><?= e($org) ?></small><?php endif; ?></td>
          <td><?= e(ct_label($r['kind'])) ?></td>
          <td><?php if (gb_register_ok($r)): ?><span class="pill done">Complet</span><?php else: ?><span class="pill" title="<?= e(implode(', ', $r['register_missing'])) ?>">À compléter</span><?php endif; ?></td>
          <td class="num"><?= e(gb_eur($r['price_cents'])) ?></td>
          <td class="js-state"><?= $r['status'] === 'taken' ? '<span class="pill done">Attribué</span>' : (!empty($r['expired']) ? '<span class="pill">Expirée</span>' : '<span class="pill sent">En attente</span>') ?></td>
          <td><?= e(date('d/m/Y H:i', (int) $r['requested'])) ?></td>
          <td><div class="row" style="gap:4px;">
            <a class="btn small" href="/portail/galerie-exposant.php<?= $limited ? '?' : '?g=' . e(urlencode($gaSlug)) . '&amp;' ?>slot=<?= e(urlencode($r['slot_id'])) ?>">Fiche</a>
            <form method="post" class="row" style="gap:4px;"><?= $hid ?><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
            <?php if ($r['status'] === 'pending' && empty($r['expired'])): ?>
              <button class="btn small btn-primary" name="action" value="accept" type="submit">Accepter</button>
              <button class="btn small" name="action" value="refuse" type="submit" onclick="return confirm('Refuser cette demande ? L\'exposant en est informé.');">Refuser</button>
            <?php elseif ($r['status'] === 'taken'): ?>
              <button class="btn small" name="action" value="release" type="submit" onclick="return confirm('Libérer cette place ? L\'exposant en est informé.');">Libérer</button>
            <?php else: ?>
              <button class="btn small" name="action" value="refuse" type="submit">Retirer</button>
            <?php endif; ?>
            </form>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
      <p class="row"><a class="btn" href="<?= e($exposants) ?>">Fiches exposants et pièces</a> <a class="btn" href="<?= e($registre) ?>">Registre de police</a> <a class="btn" href="<?= e($registre . (str_contains($registre, '?') ? '&' : '?') . 'format=xls') ?>">Exporter le registre (Excel)</a> <a class="btn" href="<?= e($evenement) ?>">Modifier l'événement</a></p>
    </section>

    <style>.pill.sent{background:#fff3df;color:#8a5200}.pill.done{background:#e6f4ec;color:#1d6b42}#live-dot{font-size:.78rem;color:#2e8b57;border:1px solid #bfe0cd;background:#f2faf5;padding:2px 8px;border-radius:999px}</style>
    <script>
    (function () {
      var initial = <?= count($rows) ?>, timer = null;
      function refresh() {
        fetch('<?= e($with('json=1')) ?>', {headers: {'Accept': 'application/json'}, cache: 'no-store'})
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (d) {
            if (!d) return;
            Object.keys(d.kpis).forEach(function (k) {
              var el = document.querySelector('[data-kpi="' + k + '"]');
              if (!el) return;
              el.textContent = k === 'revenue' ? (d.kpis[k] / 100).toFixed(2).replace('.', ',') + ' €' : d.kpis[k];
            });
            if (d.rows.length !== initial) {
              var n = document.getElementById('live-new');
              n.hidden = false;
              n.textContent = (d.rows.length > initial ? 'Nouvelle demande reçue' : 'Une demande a changé d\'état') + ' : actualisez pour la traiter.';
              n.innerHTML += ' <a href="">Actualiser</a>';
            }
            document.getElementById('live-dot').title = 'Mis à jour à ' + new Date(d.generated * 1000).toLocaleTimeString();
          })
          .catch(function () {});
      }
      timer = setInterval(refresh, 12000);
      document.addEventListener('visibilitychange', function () { if (document.hidden) clearInterval(timer); else timer = setInterval(refresh, 12000); });
    })();
    </script>
<?php ga_foot(); ?>
