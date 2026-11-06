<?php
// File d'attente et places voisines (module « attente ») : l'organisateur prévient les exposants inscrits dès qu'une place se libère,
// constate les absences le jour même et laisse les stands existants agrandir leur emplacement à prix réduit.
// Toute la logique vit dans includes/gallery-waitlist.php ; cette page ne fait que régler, déclencher et suivre.
require __DIR__ . '/_galerie-layout.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page
require_once PORTAIL_ROOT . '/includes/gallery-waitlist.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';

$self = '/portail/galerie-attente.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $slot = (string) ($_POST['slot'] ?? '');
    $slot = preg_match('/^[a-z0-9]{2,20}$/', $slot) ? $slot : '';
    $entryToken = (string) ($_POST['entry'] ?? '');
    $msg = null;
    $kind = 'ok';
    if (!gwl_available($gaGallery)) {
        [$msg, $kind] = ["La file d'attente n'est pas active : vérifiez {le ext} et les réservations en ligne.", 'error'];
    } elseif ($action === 'settings') {
        $err = gwl_save_settings($gaGallery, $_POST, $actor);
        [$msg, $kind] = $err ? [$err, 'error'] : ['Réglages enregistrés.', 'ok'];
    } elseif ($action === 'notify') {
        $r = gwl_notify_waitlist($gaGallery, $slot !== '' ? [$slot] : [], $actor);
        [$msg, $kind] = $r['slots'] === 0
            ? ['Aucune place libre à proposer pour le moment.', 'error']
            : ($r['notified'] > 0 ? [$r['notified'] . ' inscrit(s) prévenu(s) par e-mail.', 'ok'] : ['Aucun inscrit à prévenir (file vide, délai d\'attente ou quota atteint).', 'error']);
    } elseif ($action === 'scan') {
        $r = gwl_scan_neighbors($gaGallery, $slot !== '' ? $slot : null, $actor);
        [$msg, $kind] = $r['offers'] > 0
            ? [$r['offers'] . ' offre(s) de place voisine envoyée(s) aux exposants.', 'ok']
            : ['Aucune nouvelle place voisine à proposer (offres déjà ouvertes ou aucune place libre à côté d\'un stand).', 'error'];
    } elseif ($action === 'sweep') {
        $r = gwl_sweep($gaGallery, $actor);
        if ($r['notified'] + $r['offers'] > 0) {
            [$msg, $kind] = [$r['notified'] . ' inscrit(s) prévenu(s), ' . $r['offers'] . ' offre(s) de voisinage envoyée(s).', 'ok'];
        } elseif ($r['free'] > 0) {
            [$msg, $kind] = ['Aucun nouvel envoi : la file est vide ou les délais de notification ne sont pas écoulés.', 'error'];
        } else {
            [$msg, $kind] = ['Aucune place libre pour le moment.', 'error'];
        }
    } elseif ($action === 'absent') {
        $err = gwl_no_show($gaGallery, $slot, $actor);
        [$msg, $kind] = $err ? [$err, 'error'] : ['Absence constatée : la place est libre, la file et les voisins sont prévenus.', 'ok'];
    } elseif ($action === 'leave') {
        $ok = gwl_leave($gaSlug, $entryToken);
        [$msg, $kind] = $ok ? ['Inscription retirée de la file d\'attente.', 'ok'] : ['Inscription introuvable ou déjà close.', 'error'];
    } else {
        [$msg, $kind] = ['Action non reconnue.', 'error'];
    }
    portail_flash($msg, $kind);
    header('Location: ' . $self);
    exit;
}

gwl_purge($gaSlug);
gwl_expire($gaGallery);
$settings = gwl_settings($gaGallery);
$free = gwl_free_slots($gaGallery);
$waiting = gwl_waiting($gaGallery);
$offers = gwl_offers($gaGallery);
$all = gwl_all_slots($gaGallery);
$event = gallery_event($gaGallery);
$taken = array_values(array_filter(gb_rows($gaGallery), static fn ($r) => ($r['status'] ?? '') === 'taken'));
$openOffers = array_filter($offers, static fn ($o) => ($o['status'] ?? '') === 'open');
$notifiedCount = count(array_filter($waiting, static fn ($e) => ($e['status'] ?? '') === 'notified'));

ga_head("File d'attente");
?>
    <?php if (!gwl_available($gaGallery)): ?>
      <section class="gcard">
        <h2>File d'attente indisponible</h2>
        <p class="meta" style="margin:0;">{Ce ext} a besoin {du ext} « File d'attente &amp; places voisines », de la fonctionnalité exposants du type de galerie et de réservations en ligne ouvertes (événement publié avec places, ou plan activé).</p>
        <div class="row"><a class="btn" href="/portail/galerie-modules.php<?= $limited ? '' : '?g=' . e(urlencode($gaSlug)) ?>">Ouvrir {les exts}</a></div>
      </section>
    <?php else: ?>
    <section class="gcard">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div>
          <h2 style="margin:0;">File d'attente et places voisines</h2>
          <p class="meta" style="margin:4px 0 0;"><?= $event ? e(implode(' · ', array_filter([$event['title'], ge_date_text($event), ge_place_text($event)]))) : 'Emplacements du plan' ?></p>
        </div>
        <a class="pill done" href="<?= e(gwl_public_url($gaSlug)) ?>" target="_blank" rel="noopener">Page publique ↗</a>
      </div>
      <div class="kpis">
        <div class="kpi"><b><?= count($free) ?></b><span>places libres</span></div>
        <div class="kpi"><b><?= count($waiting) ?></b><span>inscrits à la file</span></div>
        <div class="kpi"><b><?= $notifiedCount ?></b><span>déjà prévenus</span></div>
        <div class="kpi"><b><?= count($openOffers) ?></b><span>offres voisines ouvertes</span></div>
        <div class="kpi"><b><?= count($taken) ?></b><span>stands attribués</span></div>
      </div>
      <div class="row">
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="sweep"><button class="btn btn-primary" type="submit">Prévenir la file et les voisins</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notify"><button class="btn" type="submit"<?= $free ? '' : ' disabled' ?>>Prévenir la file (places libres)</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="scan"><button class="btn" type="submit"<?= $free && $taken ? '' : ' disabled' ?>>Chercher les places voisines</button></form>
      </div>
      <p class="meta" style="margin:0;">La file ne réserve aucune place : chaque inscrit reçoit un lien vers la page publique et la place revient au premier qui la demande. Les offres de voisinage retiennent la place au nom de l'exposant, à valider ensuite dans « Exposants ».</p>
    </section>

    <section class="gcard">
      <h2>Réglages</h2>
      <form method="post" class="row" style="align-items:flex-end;">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
        <label class="f">Inscrits prévenus à chaque libération<input type="text" name="notify_limit" inputmode="numeric" value="<?= (int) $settings['notify_limit'] ?>"></label>
        <label class="f">Délai entre deux e-mails (heures)<input type="text" name="cooldown_hours" inputmode="numeric" value="<?= (int) $settings['cooldown_hours'] ?>"></label>
        <label class="f">E-mails maximum par inscrit<input type="text" name="max_notifications" inputmode="numeric" value="<?= (int) $settings['max_notifications'] ?>"></label>
        <label class="f">Remise place voisine (%)<input type="text" name="discount_pct" inputmode="numeric" value="<?= (int) $settings['discount_pct'] ?>"></label>
        <label class="f">Rayon de voisinage sur le plan (m)<input type="text" name="neighbor_radius_m" inputmode="decimal" value="<?= e((string) $settings['neighbor_radius_m']) ?>"></label>
        <label class="f" style="flex-direction:row;align-items:center;gap:8px;min-width:220px;"><input type="checkbox" name="neighbor_enabled" value="1"<?= $settings['neighbor_enabled'] ? ' checked' : '' ?>> Proposer les places voisines</label>
        <label class="f" style="flex-basis:100%;">Texte d'accueil de la page publique<textarea name="public_intro" maxlength="300" style="min-height:70px;"><?= e($settings['public_intro']) ?></textarea></label>
        <button class="btn btn-primary" type="submit">Enregistrer</button>
      </form>
      <p class="meta" style="margin:0;">Sur un événement numéroté, les voisines sont les places juste avant et juste après. Sur un plan, ce sont les places de la même zone dont le centre est à moins du rayon indiqué (les deux plus proches).</p>
    </section>

    <section class="gcard">
      <h2>Places libres</h2>
      <?php if (!$free): ?><p class="meta" style="margin:0;">Aucune place libre pour le moment.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Place</th><th>Zone</th><th class="num">Tarif</th><th></th></tr></thead><tbody>
      <?php foreach ($free as $s): ?>
        <tr>
          <td><?= e($s['name']) ?></td>
          <td><?= e($s['zone'] !== '' ? $s['zone'] : '—') ?></td>
          <td class="num"><?= e(gwl_eur($s['price_cents'])) ?></td>
          <td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="notify"><input type="hidden" name="slot" value="<?= e($s['id']) ?>"><button class="btn small" type="submit">Prévenir la file</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Inscrits à la file</h2>
      <?php if (!$waiting): ?><p class="meta" style="margin:0;">Personne n'est inscrit pour le moment. Le formulaire public est sur la page de la galerie.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Inscrit</th><th>Profil</th><th>Places</th><th>Zone</th><th>Inscrit le</th><th>E-mails</th><th></th></tr></thead><tbody>
      <?php foreach ($waiting as $token => $entry): ?>
        <tr>
          <td><?= e($entry['name'] ?? '') ?><br><small class="meta"><?= e($entry['email'] ?? '') ?><?= !empty($entry['phone']) ? ' · ' . e($entry['phone']) : '' ?></small><?php if (!empty($entry['message'])): ?><br><small class="meta"><?= e($entry['message']) ?></small><?php endif; ?></td>
          <td><?= e(ct_label((string) ($entry['kind'] ?? 'particulier'))) ?></td>
          <td><?= (int) ($entry['places'] ?? 1) ?></td>
          <td><?= e((string) ($entry['zone'] ?? '') ?: '—') ?></td>
          <td><?= e(date('d/m/Y H:i', (int) ($entry['created'] ?? 0))) ?></td>
          <td><?= (int) ($entry['notify_count'] ?? 0) ?><br><small class="meta"><?= !empty($entry['last_notified']) ? 'le ' . e(date('d/m/Y H:i', (int) $entry['last_notified'])) : 'jamais' ?></small></td>
          <td>
            <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="leave"><input type="hidden" name="entry" value="<?= e($token) ?>"><button class="btn small" type="submit" onclick="return confirm('Retirer cette inscription de la file ?');">Retirer</button></form>
            <span class="pill <?= ($entry['status'] ?? '') === 'notified' ? 'sent' : '' ?>"><?= e(GWL_STATUS_LABELS[$entry['status'] ?? 'waiting'] ?? 'En attente') ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Stands attribués — absence le jour même</h2>
      <?php if (!$taken): ?><p class="meta" style="margin:0;">Aucun stand attribué pour le moment.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Place</th><th>Exposant</th><th class="num">Tarif</th><th></th></tr></thead><tbody>
      <?php foreach ($taken as $r): ?>
        <tr>
          <td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?><br><small class="meta"><?= e($r['email']) ?></small></td>
          <td class="num"><?= e(gb_eur($r['price_cents'] ?? null)) ?></td>
          <td><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="absent"><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>"><button class="btn small" type="submit" onclick="return confirm('Constater l\'absence et libérer cette place ? L\'exposant est prévenu, la file et les voisins aussi.');">Absent — libérer</button></form></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <section class="gcard">
      <h2>Offres de places voisines</h2>
      <?php if (!$offers): ?><p class="meta" style="margin:0;">Aucune offre envoyée pour cette édition.</p><?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Place proposée</th><th>À côté de</th><th>Exposant</th><th class="num">Tarif</th><th>État</th><th>Envoyée le</th><th></th></tr></thead><tbody>
      <?php foreach ($offers as $token => $o): ?>
        <tr>
          <td><?= e(gwl_slot_name_by_id($gaGallery, (string) $o['slot'], $all)) ?></td>
          <td><?= e(gwl_slot_name_by_id($gaGallery, (string) $o['from_slot'], $all)) ?></td>
          <td><?= e($o['name'] ?? '') ?><br><small class="meta"><?= e($o['email'] ?? '') ?></small></td>
          <td class="num"><?= e(gwl_eur($o['price_cents'] ?? null)) ?><?php if (!empty($o['discount_pct'])): ?><br><small class="meta">-<?= (int) $o['discount_pct'] ?> %</small><?php endif; ?></td>
          <td><span class="pill <?= ($o['status'] ?? '') === 'claimed' ? 'done' : '' ?>"><?= e(GWL_OFFER_LABELS[$o['status'] ?? 'open'] ?? 'Offre') ?></span></td>
          <td><?= e(date('d/m/Y H:i', (int) ($o['created'] ?? 0))) ?></td>
          <td><?php if (($o['status'] ?? '') === 'open'): ?><a class="btn small" href="<?= e(gwl_public_url($gaSlug, ['o' => $token])) ?>" target="_blank" rel="noopener">Voir l'offre</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <style>.pill.sent{background:#fff3df;color:#8a5200}.pill.done{background:#e6f4ec;color:#1d6b42}</style>
    <?php endif; ?>
<?php ga_foot(); ?>
