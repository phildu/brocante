<?php
// Espace organisateur (vide-grenier, marché aux puces) : création de l'événement (date, lieu, places ou mètres, prix au mètre),
// numérotation automatique des places (Place 1 à Place N) sans éditeur visuel, réglages des réservations et accès au tableau de bord.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/gallery-cancellation.php';

$self = '/portail/galerie-evenement.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$orgOn = gm_enabled($gaGallery, 'organisateur');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
    if ($action === 'save') {
        $eur = static fn ($v): int => max(0, min(1000000, (int) round((float) str_replace(',', '.', (string) $v) * 100)));
        $in = [
            'title' => (string) ($_POST['title'] ?? ''), 'date' => (string) ($_POST['date'] ?? ''), 'end_date' => (string) ($_POST['end_date'] ?? ''),
            'place' => (string) ($_POST['place'] ?? ''), 'address' => (string) ($_POST['address'] ?? ''),
            'postcode' => (string) ($_POST['postcode'] ?? ''), 'city' => (string) ($_POST['city'] ?? ''),
            'opening' => (string) ($_POST['opening'] ?? ''), 'closing' => (string) ($_POST['closing'] ?? ''),
            'notes' => (string) ($_POST['notes'] ?? ''), 'published' => !empty($_POST['published']),
            'mode' => (string) ($_POST['mode'] ?? 'places'), 'places_count' => (int) ($_POST['places_count'] ?? 0),
            'meters_total' => (string) ($_POST['meters_total'] ?? '0'), 'meters_per_place' => (string) ($_POST['meters_per_place'] ?? '3'),
            'price_meter_cents' => $eur($_POST['price_meter_eur'] ?? 0), 'price_cents' => $eur($_POST['price_eur'] ?? 0),
            'price_pro_cents' => $eur($_POST['price_pro_eur'] ?? 0),
            'booking' => ['enabled' => !empty($_POST['booking_enabled']), 'hold_days' => (int) ($_POST['hold_days'] ?? 7), 'intro' => (string) ($_POST['intro'] ?? '')],
            'cancelled' => $gaGallery['event']['cancelled'] ?? null,
            'updated' => time(),
        ];
        $new = gallery_event_normalize($in);
        $err = $new ? ge_validate($new) : 'Renseignez au moins la date, le lieu ou les places de l\'événement.';
        if (!$err && $new) {
            $count = ge_place_count($new);
            $max = 0;
            foreach (gb_read($gaSlug)['slots'] as $id => $e) {
                if (gb_active($e, gb_hold_seconds($gaGallery)) && preg_match('/^ev([0-9]{1,4})$/', (string) $id, $m)) $max = max($max, (int) $m[1]);
            }
            if ($count < $max) $err = 'Des demandes ou attributions portent sur des places au-delà de la place ' . $count . ' : gardez au moins ' . $max . ' places, ou refusez d\'abord ces demandes depuis les inscriptions.';
        }
        if ($err) {
            portail_flash($err, 'error');
        } else {
            $gaGallery['event'] = $new;
            $ok = gallery_save($gaGallery);
            portail_flash($ok
                ? 'Événement enregistré : ' . ge_place_count($new) . ' place(s) numérotée(s) de 1 à ' . ge_place_count($new) . '.'
                : "Impossible d'enregistrer l'événement (droits du dossier data).", $ok ? 'ok' : 'error');
        }
    } elseif ($action === 'cancel_event') {
        [$err, $res] = gcn_cancel($gaGallery, [
            'cause' => (string) ($_POST['cause'] ?? 'organisateur'),
            'reason' => (string) ($_POST['reason'] ?? ''),
            'refund_mode' => (string) ($_POST['refund_mode'] ?? 'rules'),
            'refund_pct' => (int) ($_POST['refund_pct'] ?? 0),
            'refund_delay_days' => (int) ($_POST['refund_delay_days'] ?? 30),
            'refund_method' => (string) ($_POST['refund_method'] ?? ''),
            'notify' => !empty($_POST['notify']),
        ], $actor);
        if ($err) {
            portail_flash($err, 'error');
        } else {
            $ledger = (array) ($res['ledger'] ?? []);
            portail_flash('Événement annulé. ' . (int) ($ledger['taken'] ?? 0) . ' exposant(s) attribué(s), ' . (int) ($ledger['pending'] ?? 0)
                . ' demande(s) en attente. Remboursements à effectuer : ' . gb_eur((int) ($ledger['due_cents'] ?? 0))
                . '. ' . (int) ($res['notified'] ?? 0) . ' e-mail(s) d’alerte envoyé(s).', 'ok');
        }
    } elseif ($action === 'reopen_event') {
        $err = gcn_reopen($gaGallery, $actor);
        portail_flash($err ?? 'Événement réactivé : les demandes d’emplacement sont de nouveau ouvertes.', $err ? 'error' : 'ok');
    } elseif ($action === 'refund_status') {
        $err = gcn_mark_refund($gaSlug, (string) ($_POST['slot'] ?? ''), (string) ($_POST['status'] ?? ''), $actor, (string) ($_POST['note'] ?? ''));
        portail_flash($err ?? 'Remboursement mis à jour.', $err ? 'error' : 'ok');
    } elseif ($action === 'notify_event') {
        $n = gcn_notify($gaGallery, !empty($_POST['force']));
        portail_flash($n > 0 ? $n . ' exposant(s) prévenu(s) de l’annulation.' : 'Aucun nouvel e-mail à envoyer : tous les exposants ont déjà été prévenus, ou aucun n’a d’adresse valide.', $n > 0 ? 'ok' : 'error');
    }
    header('Location: ' . $self);
    exit;
}

$event = gallery_event($gaGallery);
$count = $event ? ge_place_count($event) : 0;
$price = $event ? ge_place_price($event) : null;
$pricePro = $event ? ge_place_price($event, true) : null;
$rows = gb_rows($gaGallery);
$evRows = array_values(array_filter($rows, static fn ($r) => (bool) preg_match('/^ev[0-9]{1,4}$/', (string) $r['slot_id'])));
$taken = count(array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'taken'));
$pending = count(array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'pending' && empty($r['expired'])));
$free = max(0, $count - $taken - $pending);
$revenue = array_sum(array_map(static fn ($r) => (int) ($r['price_cents'] ?? 0), array_filter($evRows, static fn ($r) => ($r['status'] ?? '') === 'taken')));
$cancelled = ge_cancelled($event);
$rules = gcn_rules_from_gallery($gaGallery);
$cancelSummary = $cancelled ? gcn_summary($gaGallery) : null;
$hidden = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
$regles = '/portail/galerie-assistant.php' . ($limited ? '?vue=regles' : '?g=' . urlencode($gaSlug) . '&vue=regles');
$demoPrice = $price !== null && $price > 0 ? $price : 2000;
$publicUrl = '/galerie/' . $gaGallery['slug'] . '/' . ($gaGallery['published'] ? '' : '?apercu=' . gallery_preview_token($gaGallery)) . '#evenement';
$inscriptions = '/portail/galerie-inscriptions.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$registre = '/portail/galerie-registre.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$options = '/portail/galerie-modules.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$val = static fn (string $k, string $d = ''): string => htmlspecialchars((string) ($event[$k] ?? $d), ENT_QUOTES, 'UTF-8');
$eurVal = static fn (?int $c): string => $c === null ? '' : number_format($c / 100, 2, ',', '');
ga_head('Événement');
?>
    <?php if (!$orgOn): ?>
    <p class="flash" data-kind="error">L'option « Espace organisateur » n'est pas active pour cette galerie : vous pouvez préparer l'événement, mais les réservations publiques et le tableau de bord resteront fermés. <a href="<?= e($options) ?>">Activer l'option</a>.</p>
    <?php endif; ?>

    <?php if ($cancelled && $cancelSummary): ?>
    <section class="gcard" style="border-color:#e0b4b4;background:#fff7f6;">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div>
          <h2 style="margin:0;">Événement annulé <span class="pill" style="background:#fde8e8;color:#a51b1b;">Annulé le <?= e(date('d/m/Y', (int) $event['cancelled']['at'])) ?></span></h2>
          <p class="meta" style="margin:4px 0 0;"><?= e(ge_cancel_reason($event)) ?> · Remboursement appliqué : <?= (int) $event['cancelled']['refund_pct'] ?> % · sous <?= (int) $event['cancelled']['refund_delay_days'] ?> jours par <?= e($event['cancelled']['refund_method']) ?>.</p>
        </div>
      </div>
      <p class="meta" style="margin:0;">Les demandes d'emplacement sont fermées et la page publique affiche l'annulation. Le registre ci-dessous suit chaque exposant : à rembourser, remboursé ou sans remboursement.</p>
      <div class="kpis">
        <div class="kpi"><b><?= (int) $cancelSummary['taken'] ?></b><span>exposants attribués</span></div>
        <div class="kpi"><b><?= (int) $cancelSummary['pending'] ?></b><span>demandes en attente</span></div>
        <div class="kpi"><b><?= e(gb_eur((int) $cancelSummary['due_cents'])) ?></b><span>à rembourser</span></div>
        <div class="kpi"><b><?= e(gb_eur((int) $cancelSummary['paid_cents'])) ?></b><span>déjà remboursé</span></div>
        <div class="kpi"><b><?= (int) $cancelSummary['waived'] + (int) $cancelSummary['none'] ?></b><span>sans remboursement</span></div>
      </div>
      <p class="meta" style="margin:0;"><?= (int) ($event['cancelled']['notified'] ?? 0) > 0 ? 'Dernière alerte envoyée le ' . e(date('d/m/Y à H:i', (int) $event['cancelled']['notified'])) . '.' : 'Les exposants n’ont pas encore reçu d’alerte par e-mail.' ?></p>
      <div class="row">
        <form method="post"><?= $hidden ?>
          <input type="hidden" name="action" value="notify_event">
          <button class="btn" type="submit">Prévenir les exposants</button>
        </form>
        <form method="post" onsubmit="return confirm('Réactiver cet événement ? Les lignes de remboursement non versées seront supprimées.');"><?= $hidden ?>
          <input type="hidden" name="action" value="reopen_event">
          <button class="btn danger" type="submit">Réactiver l'événement</button>
        </form>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($cancelled): $ledgerRows = gcn_rows($gaGallery); ?>
    <section class="gcard">
      <h2>Registre des remboursements</h2>
      <p class="meta" style="margin:0;">Chaque ligne suit un exposant : montant calculé à l’annulation, puis « Remboursé » quand le virement ou le chèque est parti. La note libre garde la référence du paiement.</p>
      <?php if (!$ledgerRows): ?>
        <p class="meta">Aucun exposant inscrit sur cet événement : il n’y a personne à rembourser.</p>
      <?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Emplacement</th><th>Exposant</th><th>Demande</th><th class="num">Tarif</th><th>Part</th><th class="num">À rendre</th><th>État</th><th>Suivi</th></tr></thead><tbody>
      <?php foreach ($ledgerRows as $r): $ref = $r['refund']; ?>
        <tr>
          <td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?><br><small class="meta"><?= e($r['email']) ?></small></td>
          <td><?= ($r['status'] ?? '') === 'taken' ? '<span class="pill done">Attribuée</span>' : '<span class="pill sent">En attente</span>' ?></td>
          <td class="num"><?= e(gb_eur((int) ($r['price_cents'] ?? 0))) ?></td>
          <td><?= (int) $ref['pct'] ?> %</td>
          <td class="num"><?= e(gb_eur($ref['amount_cents'])) ?></td>
          <td>
            <span class="pill <?= $ref['status'] === 'paid' ? 'done' : '' ?>"><?= e(($r['status'] ?? '') === 'pending' ? 'Rien à payer' : gcn_refund_label($ref)) ?></span>
            <?php if ($ref['paid_at'] > 0): ?><br><small class="meta">le <?= e(date('d/m/Y', $ref['paid_at'])) ?><?= $ref['by'] !== '' ? ' par ' . e($ref['by']) : '' ?></small><?php endif; ?>
            <?php if ($ref['notified'] > 0): ?><br><small class="meta">alerte envoyée</small><?php endif; ?>
          </td>
          <td>
            <form method="post" class="row" style="gap:4px;">
              <?= $hidden ?><input type="hidden" name="action" value="refund_status"><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
              <select name="status">
                <?php foreach (GCN_REFUND_STATUSES as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $ref['status'] === $sk ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?>
              </select>
              <input type="text" name="note" maxlength="300" placeholder="Référence du virement" value="<?= e($ref['note']) ?>" style="max-width:150px;">
              <button class="btn small" type="submit">Mettre à jour</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

      <div class="kpi"><b><?= $count ?></b><span>places numérotées</span></div>
      <div class="kpi"><b><?= $taken ?></b><span>attribuées</span></div>
      <div class="kpi"><b><?= $pending ?></b><span>en attente</span></div>
      <div class="kpi"><b><?= $free ?></b><span>libres</span></div>
      <div class="kpi"><b><?= $price === null ? '—' : e(gb_eur($price)) ?></b><span>tarif d'une place</span></div>
      <div class="kpi"><b><?= e(gb_eur($revenue)) ?></b><span>recettes attribuées</span></div>
    </div>

    <?php if ($event): ?>
    <section class="gcard">
      <h2><?= e($event['title']) ?> <span class="pill <?= $event['published'] ? 'done' : '' ?>"><?= $event['published'] ? 'Publié' : 'Brouillon' ?></span></h2>
      <p class="meta" style="margin:0;"><?= e(ge_date_text($event)) ?><?= ge_place_text($event) !== '' ? ' · ' . e(ge_place_text($event)) : '' ?><?= ge_hours_text($event) !== '' ? ' · ' . e(ge_hours_text($event)) : '' ?></p>
      <div class="row">
        <a class="btn btn-primary" href="<?= e($inscriptions) ?>">Tableau de bord des inscriptions</a>
        <a class="btn" href="<?= e($registre) ?>">Registre de police (Excel / PDF)</a>
        <a class="btn" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">Voir la page publique</a>
        <a class="btn" href="<?= e($options) ?>">Options de la galerie</a>
      </div>
    </section>
    <?php endif; ?>

    <form method="post" class="gcard" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save">
      <h2>L'événement</h2>
      <div class="row">
        <label class="f">Nom de l'événement<input type="text" name="title" maxlength="80" value="<?= $val('title', $gaGallery['name']) ?>" required></label>
        <label class="f">Date<input type="date" name="date" value="<?= $val('date') ?>" required></label>
        <label class="f">Dernier jour (facultatif)<input type="date" name="end_date" value="<?= $val('end_date') ?>"></label>
        <label class="f"><span><input type="checkbox" name="published" value="1"<?= ($event['published'] ?? false) ? ' checked' : '' ?>> Publier l'événement et ouvrir les réservations</span></label>
      </div>
      <div class="row">
        <label class="f">Lieu (nom)<input type="text" name="place" maxlength="120" value="<?= $val('place') ?>" placeholder="Place du marché, halle…"></label>
        <label class="f">Adresse<input type="text" name="address" maxlength="160" value="<?= $val('address') ?>" autocomplete="street-address"></label>
        <label class="f">Code postal<input type="text" name="postcode" maxlength="12" value="<?= $val('postcode') ?>" autocomplete="postal-code" inputmode="numeric"></label>
        <label class="f">Ville<input type="text" name="city" maxlength="80" value="<?= $val('city') ?>" autocomplete="address-level2"></label>
      </div>
      <div class="row">
        <label class="f">Ouverture<input type="time" name="opening" value="<?= $val('opening') ?>"></label>
        <label class="f">Fermeture<input type="time" name="closing" value="<?= $val('closing') ?>"></label>
      </div>
      <label class="f">Consignes affichées aux exposants<textarea name="notes" rows="3" maxlength="600" placeholder="Installation à partir de 6 h, véhicule sur le parking, objets interdits…"><?= $val('notes') ?></textarea></label>

      <h3><?= e(ucfirst(gt_word($gaGallery, 'places'))) ?> et tarif</h3>
      <p class="meta" style="margin:0;">La numérotation est automatique : <?= e(ucfirst(gt_word($gaGallery, 'place'))) ?> 1 à <?= e(ucfirst(gt_word($gaGallery, 'place'))) ?> N. Aucun plan à dessiner n'est nécessaire.</p>
      <div class="row">
        <label class="f">Je saisis<select name="mode" id="ev-mode">
          <?php foreach (GE_MODES as $mk => $ml): ?><option value="<?= e($mk) ?>"<?= ($event['mode'] ?? 'places') === $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?>
        </select></label>
        <label class="f" id="ev-count-wrap">Nombre de places<input type="number" name="places_count" id="ev-count" min="0" max="<?= GE_MAX_PLACES ?>" value="<?= (int) ($event['places_count'] ?? 50) ?>"></label>
        <label class="f" id="ev-meters-wrap" hidden>Mètres linéaires disponibles<input type="number" name="meters_total" id="ev-meters" min="0" max="1000000" step="0.5" value="<?= e((string) ($event['meters_total'] ?? 0)) ?>"></label>
        <label class="f" id="ev-per-wrap" hidden>Longueur d'une place (m)<input type="number" name="meters_per_place" id="ev-per" min="0.5" max="100" step="0.5" value="<?= e((string) ($event['meters_per_place'] ?? 3)) ?>"></label>
      </div>
      <div class="row">
        <label class="f">Prix au mètre linéaire (€)<input type="number" name="price_meter_eur" id="ev-price-meter" min="0" step="0.01" value="<?= e($eurVal((int) ($event['price_meter_cents'] ?? 0))) ?>"></label>
        <label class="f">Prix d'une place (€, prioritaire)<input type="number" name="price_eur" id="ev-price" min="0" step="0.01" value="<?= e($eurVal((int) ($event['price_cents'] ?? 0))) ?>"></label>
        <label class="f">Tarif entreprise / association (€, 0 = même prix)<input type="number" name="price_pro_eur" id="ev-price-pro" min="0" step="0.01" value="<?= e($eurVal((int) ($event['price_pro_cents'] ?? 0))) ?>"></label>
      </div>
      <p class="meta" id="ev-calc" role="status" style="margin:0;"></p>

      <h3>Réserver en ligne</h3>
      <div class="row">
        <label class="f"><span><input type="checkbox" name="booking_enabled" value="1"<?= !empty($event['booking']['enabled']) ? ' checked' : '' ?>> Accepter les demandes d'emplacement en ligne</span></label>
        <label class="f">Une demande retient la place (jours)<input type="number" name="hold_days" min="1" max="60" value="<?= (int) ($event['booking']['hold_days'] ?? 7) ?>"></label>
      </div>
      <label class="f">Phrase d'accueil des exposants<input type="text" name="intro" maxlength="300" value="<?= e((string) ($event['booking']['intro'] ?? '')) ?>" placeholder="Choisissez votre place, l'organisateur vous répond par e-mail."></label>

      <p><button class="btn btn-primary" type="submit">Enregistrer l'événement</button></p>
    </form>

    <?php if ($count > 0): ?>
    <section class="gcard">
      <h2>Aperçu des places</h2>
      <p class="meta" style="margin:0;">Les places libres sont proposées aux exposants sur la page publique. Une place en attente est retenue le temps indiqué ; une place attribuée est définitive jusqu'à libération.</p>
      <?php
      $states = gb_states($gaGallery);
      $preview = array_slice(ge_places($event), 0, 200);
      ?>
      <div class="ev-chips">
        <?php foreach ($preview as $p): $st = $states[$p['id']] ?? 'free'; ?>
          <span class="ev-chip <?= e($st) ?>" title="<?= e(ge_slot_name($p, ucfirst(gt_word($gaGallery, 'place')))) ?> : <?= $st === 'taken' ? 'attribuée' : ($st === 'pending' ? 'en attente' : 'libre') ?>"><?= e($p['num']) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($count > 200): ?><p class="meta">Aperçu des 200 premières places ; les <?= $count - 200 ?> suivantes sont numérotées de la même façon.</p><?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($event && !$cancelled): ?>
    <section class="gcard" id="annulation">
      <h2>Annuler l’événement</h2>
      <p class="meta" style="margin:0;">L’annulation ferme les demandes d’emplacement, affiche un avis sur la page publique et crée une ligne de remboursement par exposant. Conditions en vigueur, modifiables dans <a href="<?= e($regles) ?>">l’assistant, onglet Règlements</a> :</p>
      <table class="t"><tbody>
        <?php foreach (gcn_rules_lines($rules) as $label => $value): ?>
          <tr><th style="width:46%;"><?= e($label) ?></th><td><?= e($value) ?></td></tr>
        <?php endforeach; ?>
      </tbody></table>
      <form method="post" onsubmit="return confirm('Annuler cet événement ? Les demandes d’emplacement seront fermées et les remboursements calculés.');">
        <?= $hidden ?><input type="hidden" name="action" value="cancel_event">
        <div class="row">
          <label class="f">Cause de l’annulation<select name="cause" id="cn-cause">
            <?php foreach (GE_CANCEL_CAUSES as $ck => $cl): ?><option value="<?= e($ck) ?>"><?= e($cl) ?></option><?php endforeach; ?>
          </select></label>
          <label class="f">Règle appliquée<select name="refund_mode" id="cn-mode">
            <option value="rules">Selon les conditions de vente</option>
            <option value="full">Remboursement intégral (100 %)</option>
            <option value="partial">Pourcentage personnalisé</option>
            <option value="none">Aucun remboursement</option>
          </select></label>
          <label class="f">Pourcentage personnalisé<input type="number" name="refund_pct" id="cn-pct" min="0" max="100" value="<?= (int) $rules['partial_pct'] ?>"></label>
          <label class="f">Délai de remboursement (jours)<input type="number" name="refund_delay_days" min="0" max="180" value="<?= (int) $rules['refund_delay_days'] ?>"></label>
        </div>
        <div class="row">
          <label class="f">Moyen de remboursement<input type="text" name="refund_method" maxlength="120" value="<?= e((string) $rules['refund_method']) ?>"></label>
          <label class="f"><span><input type="checkbox" name="notify" value="1" checked> Prévenir les exposants par e-mail (alerte immédiate)</span></label>
        </div>
        <label class="f">Motif communiqué aux exposants<textarea name="reason" rows="2" maxlength="400" placeholder="Ex. : arrêté municipal, terrain impraticable, nombre d’inscrits insuffisant…"></textarea></label>
        <p class="meta" id="cn-hint" role="status"></p>
        <p><button class="btn danger" type="submit">Annuler l’événement</button></p>
      </form>
    </section>
    <script>
    (function () {
      var cause = document.getElementById('cn-cause'), mode = document.getElementById('cn-mode'), pct = document.getElementById('cn-pct'), hint = document.getElementById('cn-hint');
      if (!cause) return;
      var rules = {organizer: <?= (int) $rules['organizer_pct'] ?>, weather: <?= (int) $rules['force_majeure_pct'] ?>, partial: <?= (int) $rules['partial_pct'] ?>}, price = <?= (int) $demoPrice ?>;
      function refresh() {
        var p = mode.value === 'full' ? 100 : mode.value === 'none' ? 0
          : mode.value === 'partial' ? Math.max(0, Math.min(100, parseInt(pct.value, 10) || 0))
          : (cause.value === 'meteo' || cause.value === 'force_majeure' ? rules.weather : rules.organizer);
        var amount = Math.round(price * p / 100);
        hint.textContent = 'Remboursement appliqué : ' + p + ' % du tarif, soit ' + (amount / 100).toFixed(2).replace('.', ',') + ' € pour une place à ' + (price / 100).toFixed(2).replace('.', ',') + ' €.';
      }
      [cause, mode, pct].forEach(function (el) { el.addEventListener('change', refresh); el.addEventListener('input', refresh); });
      refresh();
    })();
    </script>
    <?php endif; ?>

    <style>
      .ev-chips { display: flex; flex-wrap: wrap; gap: 5px; }
      .ev-chip { display: inline-grid; place-items: center; min-width: 34px; height: 30px; padding: 0 5px; border-radius: 6px; border: 1px solid var(--line); font: 600 .78rem/1 "IBM Plex Mono", monospace; background: var(--bg); }
      .ev-chip.free { border-color: #2e8b57; color: #1d6b42; } .ev-chip.pending { border-color: #e08a1e; color: #9a5a0a; background: #fff7ea; } .ev-chip.taken { border-color: #bbb; color: #777; background: #f1f1ef; }
    </style>
    <script>
    (function () {
      var mode = document.getElementById('ev-mode'), count = document.getElementById('ev-count'), meters = document.getElementById('ev-meters'),
          per = document.getElementById('ev-per'), price = document.getElementById('ev-price'), priceMeter = document.getElementById('ev-price-meter'),
          calc = document.getElementById('ev-calc');
      if (!mode) return;
      function show() {
        var isMeters = mode.value === 'meters';
        document.getElementById('ev-count-wrap').hidden = isMeters;
        document.getElementById('ev-meters-wrap').hidden = !isMeters;
        document.getElementById('ev-per-wrap').hidden = !isMeters;
        var n = isMeters ? Math.floor((parseFloat(meters.value) || 0) / Math.max(0.5, parseFloat(per.value) || 3)) : (parseInt(count.value, 10) || 0);
        n = Math.max(0, Math.min(<?= GE_MAX_PLACES ?>, n));
        var unit = parseFloat(price.value) || 0;
        if (!unit && isMeters) unit = (parseFloat(priceMeter.value) || 0) * (parseFloat(per.value) || 3);
        calc.textContent = n + ' place' + (n > 1 ? 's' : '') + ' numérotée' + (n > 1 ? 's' : '') + ' de 1 à ' + n
          + (unit > 0 ? ' · ' + unit.toFixed(2).replace('.', ',') + ' € la place · ' + (unit * n).toFixed(2).replace('.', ',') + ' € si complet' : ' · tarif libre');
      }
      ['change', 'input'].forEach(function (ev) { mode.addEventListener(ev, show); count.addEventListener(ev, show); meters.addEventListener(ev, show); per.addEventListener(ev, show); price.addEventListener(ev, show); priceMeter.addEventListener(ev, show); });
      show();
    })();
    </script>
<?php ga_foot(); ?>
