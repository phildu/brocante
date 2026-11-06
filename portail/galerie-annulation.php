<?php
// Extension de galerie « Annulation & remboursements » (organisateur d'un vide-grenier) : annuler l'événement, rembourser les exposants en
// totalité ou en partie, et suivre chaque remboursement. Les calculs et le registre sont dans includes/gallery-cancellation.php.
//
// Accès : exploitant, ou administrateur de CETTE galerie (voir _galerie-layout.php). L'extension doit être active pour LANCER une annulation ;
// le suivi d'un événement déjà annulé (registre, ajustement des montants, alertes, réactivation, export) reste ouvert même si elle s'arrête :
// les exposants attendent leur argent. Le paiement des emplacements n'est pas encaissé par la plateforme : c'est un registre, pas un virement.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';
require_once PORTAIL_ROOT . '/includes/gallery-cancellation.php';

$q = $limited ? '' : '?g=' . urlencode($gaSlug);
$self = '/portail/galerie-annulation.php' . $q;
$optionsUrl = '/portail/galerie-modules.php' . $q;
$event = gallery_event($gaGallery);
$cancelled = ge_cancelled($event);
$on = gcn_available($gaGallery);

if (!$on && !$cancelled) {
    portail_flash(ext_t('{Ext} « Annulation & remboursements » n’est pas {?actif|active} pour cette galerie : activez-{?le|la} depuis l’onglet {Exts}.'), 'error');
    header('Location: ' . $optionsUrl . '#m-annulation');
    exit;
}

// Export CSV des virements (avant ext_page() : une cellule contenant un jeton « {Ext} » ne doit pas être réécrite).
if (($_GET['export'] ?? '') === 'csv' && $cancelled) {
    $only = ($_GET['etat'] ?? '') === 'due' ? ['due'] : [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="remboursements-' . $gaSlug . ($only ? '-a-rembourser' : '') . '-' . date('Ymd') . '.csv"');
    header('Cache-Control: no-store');
    echo gcn_csv($gaGallery, $only);
    exit;
}

ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $actor = (string) (($_SESSION['gallery_admin']['email'] ?? '') ?: 'exploitant');
    $slot = (string) ($_POST['slot'] ?? '');
    $anchor = '';
    if ($action === 'cancel_event') {
        if (!$on) {
            portail_flash(ext_t('{Ext} « Annulation & remboursements » n’est pas {?actif|active} : activez-{?le|la} pour annuler un événement.'), 'error');
        } else {
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
        }
    } elseif ($action === 'reopen_event') {
        $err = gcn_reopen($gaGallery, $actor);
        portail_flash($err ?? 'Événement réactivé : les demandes d’emplacement sont de nouveau ouvertes.', $err ? 'error' : 'ok');
    } elseif ($action === 'refund_status') {
        $err = gcn_mark_refund($gaSlug, $slot, (string) ($_POST['status'] ?? ''), $actor, (string) ($_POST['note'] ?? ''));
        portail_flash($err ?? 'Remboursement mis à jour.', $err ? 'error' : 'ok');
        $anchor = '#registre';
    } elseif ($action === 'refund_amount') {
        $raw = trim((string) ($_POST['amount'] ?? ''));
        $cents = $raw === '' ? null : gm_cents_from_input($raw);   // un champ vide n'est pas « 0 € » : on demande un montant
        $err = $cents === null ? 'Montant invalide : saisissez un montant en euros (ex. 12,50), de 0 à 10 000.' : gcn_set_amount($gaSlug, $slot, $cents, $actor, (string) ($_POST['note'] ?? ''));
        portail_flash($err ?? 'Montant ajusté : pensez à prévenir l’exposant avec le bouton « Prévenir ».', $err ? 'error' : 'ok');
        $anchor = '#registre';
    } elseif ($action === 'notify_event') {
        $n = gcn_notify($gaGallery, !empty($_POST['force']));
        portail_flash($n > 0 ? $n . ' exposant(s) prévenu(s).' : 'Aucun nouvel e-mail à envoyer : tous les exposants ont déjà reçu l’information à jour, ou aucun n’a d’adresse valide.', $n > 0 ? 'ok' : 'error');
        $anchor = '#registre';
    } elseif ($action === 'notify_one') {
        $n = gcn_notify($gaGallery, true, $slot);
        portail_flash($n > 0 ? 'Exposant prévenu par e-mail.' : 'Aucun e-mail envoyé : cet exposant n’a pas d’adresse valide.', $n > 0 ? 'ok' : 'error');
        $anchor = '#registre';
    }
    header('Location: ' . $self . $anchor);
    exit;
}

$rules = gcn_rules_from_gallery($gaGallery);
$hidden = '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' . ($limited ? '' : '<input type="hidden" name="g" value="' . e($gaSlug) . '">');
$regles = '/portail/galerie-assistant.php' . ($limited ? '?vue=regles' : '?g=' . urlencode($gaSlug) . '&vue=regles');
$eventUrl = '/portail/galerie-evenement.php' . $q;
$inscriptions = '/portail/galerie-inscriptions.php' . $q;
$eur = static fn (int $c): string => number_format($c / 100, 2, ',', '');   // valeur d'un champ de saisie
$cancelInfo = $cancelled ? (array) $event['cancelled'] : [];
$summary = $cancelled ? gcn_summary($gaGallery) : null;
$ledgerRows = $cancelled ? gcn_rows($gaGallery) : [];
$preview = ($event && !$cancelled) ? gcn_preview($gaGallery, ['refund_mode' => 'rules', 'cause' => 'organisateur']) : null;
$takenCount = $preview ? count(array_filter($preview['rows'], static fn ($r) => $r['status'] === 'taken')) : 0;
$pendingCount = $preview ? count($preview['rows']) - $takenCount : 0;
$auditLabels = ['event_cancel', 'event_reopen', 'event_notice', 'refund_due', 'refund_paid', 'refund_waived', 'refund_adjust'];
$audit = $cancelled ? array_slice(array_values(array_filter(gb_audit_read($gaSlug, 200), static fn ($a) => in_array($a['action'] ?? '', $auditLabels, true))), 0, 15) : [];
$paidShare = $summary && ($summary['due'] + $summary['paid']) > 0 ? (int) round(100 * $summary['paid'] / ($summary['due'] + $summary['paid'])) : 0;
ga_head('Annulation & remboursements');
?>
    <style>
      .cn-choices { display: grid; gap: 8px; border: 0; padding: 0; margin: 0; }
      .cn-choices legend { font-weight: 700; padding: 0; margin-bottom: 4px; }
      .cn-choice { display: grid; grid-template-columns: auto 1fr; gap: 2px 10px; align-items: start; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; cursor: pointer; background: var(--bg); }
      .cn-choice input { margin-top: 3px; }
      .cn-choice:has(input:checked) { border-color: var(--accent, #b5502e); box-shadow: 0 0 0 1px var(--accent, #b5502e) inset; }
      .cn-choice small { grid-column: 2; color: var(--muted); }
      .cn-choice .cn-inline { grid-column: 2; display: flex; align-items: center; gap: 6px; }
      .cn-choice .cn-inline input[type=number] { width: 84px; margin: 0; }
      .cn-total td, .cn-total th { font-weight: 700; border-top: 2px solid var(--line); }
      .cn-bar { height: 8px; border-radius: 999px; background: var(--line); overflow: hidden; }
      .cn-bar > i { display: block; height: 100%; background: #2e8b57; }
      .cn-amt-form { display: flex; gap: 4px; align-items: center; flex-wrap: nowrap; }
      .cn-amt-form input[type=text] { width: 74px; text-align: right; margin: 0; }
      .cn-status-form { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; }
      .cn-status-form input[type=text] { max-width: 170px; margin: 0; }
      .cn-note { display: block; color: var(--muted); font-size: .78rem; }
      .cn-log { margin: 0; padding-left: 18px; font-size: .86rem; }
    </style>

    <?php if (!$event): ?>
    <section class="gcard">
      <h2>Aucun événement à annuler</h2>
      <p class="meta" style="margin:0;">Cette extension annule l’événement de la galerie (vide-grenier, marché aux puces) : créez-le d’abord, avec ses places et son tarif.</p>
      <p><a class="btn btn-primary" href="<?= e($eventUrl) ?>">Créer l’événement</a></p>
    </section>

    <?php elseif (!$cancelled): ?>
    <section class="gcard">
      <h2>Annuler « <?= e($event['title']) ?> »</h2>
      <p class="meta" style="margin:0;"><?= e(ge_date_text($event)) ?><?= ge_place_text($event) !== '' ? ' · ' . e(ge_place_text($event)) : '' ?> ·
        <?= (int) $takenCount ?> exposant(s) attribué(s), <?= (int) $pendingCount ?> demande(s) en attente.</p>
      <p class="meta" style="margin:0;">L’annulation <strong>ferme les demandes d’emplacement</strong>, <strong>affiche l’annulation sur la page publique</strong> et dans l’espace de chaque exposant,
        et prépare <strong>une ligne de remboursement par exposant</strong>. Vous choisissez ci-dessous combien rendre : tout, une partie, ou rien. Vous pourrez ensuite
        <strong>ajuster le montant exposant par exposant</strong>. Vous pouvez réactiver l’événement tant que rien n’a été versé.</p>
      <p class="meta" style="margin:0;">Le paiement des emplacements n’est pas encaissé par le site : ce registre vous sert à suivre les virements ou chèques que <strong>vous</strong> faites aux exposants.</p>
    </section>

    <form method="post" class="gcard" id="cn-form">
      <?= $hidden ?><input type="hidden" name="action" value="cancel_event">
      <h2>1. Pourquoi annulez-vous ?</h2>
      <div class="row">
        <label class="f">Cause<select name="cause" id="cn-cause">
          <?php foreach (GE_CANCEL_CAUSES as $ck => $cl): ?><option value="<?= e($ck) ?>"><?= e($cl) ?></option><?php endforeach; ?>
        </select></label>
      </div>
      <label class="f">Motif communiqué aux exposants<textarea name="reason" rows="2" maxlength="400" placeholder="Ex. : alerte orange orages, arrêté municipal, terrain impraticable, trop peu d’inscrits…"></textarea></label>

      <h2>2. Combien rembourser ?</h2>
      <fieldset class="cn-choices">
        <legend class="meta" style="font-weight:600;">Part du tarif payé rendue à chaque exposant</legend>
        <label class="cn-choice"><input type="radio" name="refund_mode" value="rules" checked>
          <span><strong>Selon vos conditions de vente</strong></span>
          <small><?= (int) $rules['organizer_pct'] ?> % si vous décidez d’annuler · <?= (int) $rules['force_majeure_pct'] ?> % en cas de météo ou de force majeure.
            Modifiables dans <a href="<?= e($regles) ?>">l’assistant, onglet Règlements</a>.</small></label>
        <label class="cn-choice"><input type="radio" name="refund_mode" value="full">
          <span><strong>Tout rembourser</strong> (100 % du tarif payé)</span></label>
        <label class="cn-choice"><input type="radio" name="refund_mode" value="partial">
          <span><strong>Rembourser une partie</strong></span>
          <span class="cn-inline"><input type="number" name="refund_pct" id="cn-pct" min="0" max="100" value="<?= (int) $rules['partial_pct'] ?>" aria-label="Pourcentage remboursé"> % du tarif payé</span>
          <small>Par exemple 50 % pour couvrir vos frais déjà engagés.</small></label>
        <label class="cn-choice"><input type="radio" name="refund_mode" value="none">
          <span><strong>Ne rien rembourser</strong></span>
          <small>Les exposants sont prévenus ; aucune ligne à rembourser n’est créée.</small></label>
      </fieldset>
      <div class="row">
        <label class="f">Délai annoncé (jours)<input type="number" name="refund_delay_days" min="0" max="180" value="<?= (int) $rules['refund_delay_days'] ?>"></label>
        <label class="f">Moyen de remboursement<input type="text" name="refund_method" maxlength="120" value="<?= e((string) $rules['refund_method']) ?>"></label>
      </div>

      <h2>3. Aperçu avant de confirmer</h2>
      <p class="meta" id="cn-hint" role="status" style="margin:0;"></p>
      <?php if (!$preview['rows']): ?>
        <p class="meta">Aucun exposant n’est inscrit : l’annulation se limite à l’avis sur la page publique.</p>
      <?php else: ?>
      <div style="overflow-x:auto;"><table class="t" id="cn-preview"><thead><tr><th>Emplacement</th><th>Exposant</th><th>Demande</th><th class="num">Tarif payé</th><th class="num">Remboursement</th></tr></thead><tbody>
        <?php foreach ($preview['rows'] as $r): $taken = $r['status'] === 'taken'; ?>
        <tr data-taken="<?= $taken ? '1' : '0' ?>" data-price="<?= (int) $r['price_cents'] ?>">
          <td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?></td>
          <td><?= $taken ? '<span class="pill done">Attribuée</span>' : '<span class="pill sent">En attente</span>' ?></td>
          <td class="num"><?= $taken ? ($r['price_cents'] > 0 ? e(gb_eur($r['price_cents'])) : '<span class="meta">non renseigné</span>') : '—' ?></td>
          <td class="num cn-amt"><?= $taken ? e(gb_eur($r['amount_cents'])) : '<span class="meta">rien à payer</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="cn-total"><th colspan="4" style="text-align:right;">Total à rembourser</th><td class="num" id="cn-total"><?= e(gb_eur($preview['total_cents'])) ?></td></tr>
      </tbody></table></div>
      <?php if (array_filter($preview['rows'], static fn ($r) => $r['status'] === 'taken' && $r['price_cents'] <= 0)): ?>
        <p class="meta" style="margin:0;">Un exposant attribué sans tarif enregistré n’a aucun montant calculé : après l’annulation, saisissez-lui le montant payé dans le registre.</p>
      <?php endif; ?>
      <?php endif; ?>

      <label class="f"><span><input type="checkbox" name="notify" value="1" checked> Prévenir tout de suite les exposants par e-mail (montant, délai, moyen de remboursement)</span></label>
      <p><button class="btn danger-solid" type="submit">Annuler l’événement</button> <a class="btn" href="<?= e($eventUrl) ?>">Retour à l’événement</a></p>
    </form>
    <script>
    (function () {
      var form = document.getElementById('cn-form');
      if (!form) return;
      var cause = document.getElementById('cn-cause'), pctInput = document.getElementById('cn-pct'), hint = document.getElementById('cn-hint'),
          total = document.getElementById('cn-total'), rows = document.querySelectorAll('#cn-preview tbody tr[data-price]'),
          rules = {organizer: <?= (int) $rules['organizer_pct'] ?>, weather: <?= (int) $rules['force_majeure_pct'] ?>};
      function euros(c) { return (c / 100).toFixed(2).replace('.', ',') + ' €'; }
      function mode() { var r = form.querySelector('input[name=refund_mode]:checked'); return r ? r.value : 'rules'; }
      function pct() {
        var m = mode();
        if (m === 'full') return 100;
        if (m === 'none') return 0;
        if (m === 'partial') return Math.max(0, Math.min(100, parseInt(pctInput.value, 10) || 0));
        return cause.value === 'meteo' || cause.value === 'force_majeure' ? rules.weather : rules.organizer;
      }
      var sum = 0, payers = 0;
      function refresh() {
        var p = pct(), t = 0, n = 0;
        rows.forEach(function (tr) {
          if (tr.dataset.taken !== '1') return;
          var price = parseInt(tr.dataset.price, 10) || 0, a = Math.round(price * p / 100);
          tr.querySelector('.cn-amt').textContent = euros(a);
          t += a; if (a > 0) n++;
        });
        sum = t; payers = n;
        if (total) total.textContent = euros(t);
        hint.textContent = p + ' % du tarif payé : ' + (rows.length ? n + ' exposant(s) remboursé(s), ' + euros(t) + ' au total.' : 'aucun exposant à rembourser.');
      }
      pctInput.addEventListener('input', function () { form.querySelector('input[name=refund_mode][value=partial]').checked = true; refresh(); });
      form.addEventListener('change', refresh);
      form.addEventListener('submit', function (ev) {
        refresh();
        var ok = confirm('Annuler cet événement ?\n\n' + pct() + ' % du tarif payé : ' + payers + ' exposant(s) remboursé(s), ' + euros(sum) + ' au total.\nLes demandes d’emplacement seront fermées et l’annulation sera publique.');
        if (!ok) ev.preventDefault();
      });
      refresh();
    })();
    </script>

    <?php else: /* événement annulé : suivi des remboursements */ ?>
    <section class="gcard" style="border-color:#e0b4b4;background:#fff7f6;">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div>
          <h2 style="margin:0;">« <?= e($event['title']) ?> » est annulé <span class="pill" style="background:#fde8e8;color:#a51b1b;">Annulé le <?= e(date('d/m/Y', (int) $cancelInfo['at'])) ?></span></h2>
          <p class="meta" style="margin:4px 0 0;"><?= e(ge_cancel_reason($event)) ?> · Remboursement de base : <?= (int) $cancelInfo['refund_pct'] ?> % du tarif · sous <?= (int) $cancelInfo['refund_delay_days'] ?> jours par <?= e((string) $cancelInfo['refund_method']) ?>.</p>
        </div>
      </div>
      <?php if (!$on): ?>
      <p class="meta" style="margin:0;">{Ext} « Annulation &amp; remboursements » n’est plus {?actif|active} : vous suivez et réglez les remboursements en cours, mais vous ne pourrez pas lancer une nouvelle annulation. <a href="<?= e($optionsUrl) ?>#m-annulation">Voir {les exts}</a>.</p>
      <?php endif; ?>
      <p class="meta" style="margin:0;">Les demandes d’emplacement sont fermées et la page publique affiche l’annulation. Le registre ci-dessous suit chaque exposant.</p>
      <div class="kpis">
        <div class="kpi"><b><?= (int) $summary['taken'] ?></b><span>exposants attribués</span></div>
        <div class="kpi"><b><?= (int) $summary['pending'] ?></b><span>demandes en attente</span></div>
        <div class="kpi"><b><?= e(gb_eur((int) $summary['due_cents'])) ?></b><span>reste à rembourser (<?= (int) $summary['due'] ?>)</span></div>
        <div class="kpi"><b><?= e(gb_eur((int) $summary['paid_cents'])) ?></b><span>déjà remboursé (<?= (int) $summary['paid'] ?>)</span></div>
        <div class="kpi"><b><?= (int) $summary['waived'] + (int) $summary['none'] ?></b><span>sans remboursement</span></div>
      </div>
      <?php if (($summary['due'] + $summary['paid']) > 0): ?>
      <div class="cn-bar" role="img" aria-label="<?= $paidShare ?> % des remboursements versés"><i style="width:<?= $paidShare ?>%"></i></div>
      <?php endif; ?>
      <p class="meta" style="margin:0;"><?= (int) ($cancelInfo['notified'] ?? 0) > 0 ? 'Dernière alerte envoyée le ' . e(date('d/m/Y à H:i', (int) $cancelInfo['notified'])) . '.' : 'Les exposants n’ont pas encore reçu d’alerte par e-mail.' ?></p>
      <div class="row">
        <form method="post"><?= $hidden ?><input type="hidden" name="action" value="notify_event">
          <button class="btn btn-primary" type="submit" title="Envoie l’alerte à ceux qui ne l’ont pas encore reçue, ou dont le montant a changé depuis">Prévenir les exposants</button></form>
        <form method="post" onsubmit="return confirm('Renvoyer l’alerte à TOUS les exposants, même ceux déjà prévenus ?');"><?= $hidden ?><input type="hidden" name="action" value="notify_event"><input type="hidden" name="force" value="1">
          <button class="btn" type="submit">Renvoyer à tous</button></form>
        <form method="post" onsubmit="return confirm('Réactiver cet événement ? Les lignes de remboursement seront supprimées (y compris celles déjà marquées « remboursé »).');"><?= $hidden ?><input type="hidden" name="action" value="reopen_event">
          <button class="btn danger" type="submit">Réactiver l’événement</button></form>
        <a class="btn" href="<?= e($inscriptions) ?>">Inscriptions</a>
      </div>
    </section>

    <section class="gcard" id="registre">
      <div class="row" style="justify-content:space-between;align-items:flex-start;">
        <div><h2 style="margin:0;">Registre des remboursements</h2>
          <p class="meta" style="margin:4px 0 0;">Montant calculé à l’annulation, que vous pouvez <strong>ajuster exposant par exposant</strong> (remboursement partiel, frais retenus, geste commercial…). Marquez « Remboursé » quand le virement ou le chèque est parti, avec sa référence.</p></div>
        <div class="row">
          <a class="btn" href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'export=csv&etat=due') ?>">Virements à faire (CSV)</a>
          <a class="btn" href="<?= e($self . (str_contains($self, '?') ? '&' : '?') . 'export=csv') ?>">Tout le registre (CSV)</a>
        </div>
      </div>
      <?php if (!$ledgerRows): ?>
        <p class="meta">Aucun exposant inscrit sur cet événement : il n’y a personne à rembourser.</p>
      <?php else: ?>
      <div style="overflow-x:auto;"><table class="t"><thead><tr><th>Emplacement</th><th>Exposant</th><th class="num">Tarif payé</th><th>Montant à rendre</th><th>État</th><th>Alerte e-mail</th></tr></thead><tbody>
      <?php foreach ($ledgerRows as $r): $ref = $r['refund']; $taken = ($r['status'] ?? '') === 'taken'; $paid = $ref['status'] === 'paid';
            $mailOk = filter_var(trim((string) ($r['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;
            $stale = $taken && $ref['notified'] === 0 && (int) ($cancelInfo['notified'] ?? 0) > 0; ?>
        <tr>
          <td><?= e($r['slot']) ?></td>
          <td><?= e($r['name']) ?><br><small class="meta"><?= e($r['email']) ?><?= ($r['phone'] ?? '') !== '' ? ' · ' . e($r['phone']) : '' ?></small>
            <?= $taken ? '' : '<br><span class="pill sent">Demande en attente</span>' ?></td>
          <td class="num"><?= $taken ? ($ref['price_cents'] > 0 ? e(gb_eur($ref['price_cents'])) : '<span class="meta">non renseigné</span>') : '—' ?></td>
          <td>
            <?php if (!$taken): ?>
              <span class="meta">Rien à payer</span>
            <?php else: ?>
              <form method="post" class="cn-amt-form"><?= $hidden ?><input type="hidden" name="action" value="refund_amount"><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
                <input type="text" name="amount" inputmode="decimal" value="<?= e($eur($ref['amount_cents'])) ?>" aria-label="Montant à rembourser à <?= e($r['name']) ?>, en euros"<?= $paid ? ' disabled title="Déjà remboursé : repassez la ligne en « À rembourser » pour la modifier."' : '' ?>> €
                <button class="btn small" type="submit"<?= $paid ? ' disabled' : '' ?>>Ajuster</button></form>
              <small class="cn-note"><?= $ref['price_cents'] > 0 ? (int) $ref['pct'] . ' % du tarif' : 'tarif non renseigné' ?><?= $ref['adjusted'] ? ' · <strong>ajusté</strong>' : '' ?></small>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$taken): ?>
              <span class="pill">Rien à payer</span>
            <?php else: ?>
              <span class="pill <?= $paid ? 'done' : '' ?>"><?= e(gcn_refund_label($ref)) ?></span>
              <?php if ($ref['paid_at'] > 0): ?><small class="cn-note">le <?= e(date('d/m/Y', $ref['paid_at'])) ?><?= $ref['by'] !== '' ? ' par ' . e($ref['by']) : '' ?></small><?php endif; ?>
              <form method="post" class="cn-status-form"><?= $hidden ?><input type="hidden" name="action" value="refund_status"><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
                <select name="status" aria-label="État du remboursement de <?= e($r['name']) ?>">
                  <?php foreach (GCN_REFUND_STATUSES as $sk => $sl): ?><option value="<?= e($sk) ?>"<?= $ref['status'] === $sk ? ' selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="note" maxlength="300" placeholder="Réf. du virement" value="<?= e($ref['note']) ?>" aria-label="Référence ou note">
                <button class="btn small" type="submit">Mettre à jour</button></form>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($ref['notified'] > 0): ?><small class="cn-note">envoyée le <?= e(date('d/m/Y', $ref['notified'])) ?></small>
            <?php else: ?><small class="cn-note"><?= $mailOk ? 'pas encore envoyée' : 'pas d’adresse valide' ?></small><?php endif; ?>
            <?php if ($stale): ?><span class="pill sent" title="Le montant a changé depuis l’alerte envoyée à cet exposant">à renvoyer</span><?php endif; ?>
            <?php if ($mailOk): ?>
              <form method="post" style="margin-top:4px;"><?= $hidden ?><input type="hidden" name="action" value="notify_one"><input type="hidden" name="slot" value="<?= e($r['slot_id']) ?>">
                <button class="btn small" type="submit">Prévenir</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <?php endif; ?>
    </section>

    <?php if ($audit): ?>
    <section class="gcard">
      <h2>Journal</h2>
      <p class="meta" style="margin:0;">Dernières opérations sur l’annulation et les remboursements (journal chaîné, non modifiable depuis cette page).</p>
      <ul class="cn-log">
        <?php foreach ($audit as $a): $when = strtotime((string) ($a['at'] ?? '')); ?>
        <li><?= $when ? e(date('d/m/Y H:i', $when)) : '' ?> — <?= e(GB_AUDIT_LABELS[$a['action']] ?? (string) $a['action']) ?><?php
            if (!empty($a['slot'])) echo ' · ' . e((string) $a['slot']);
            if (isset($a['amount'])) echo ' · ' . e(gb_eur((int) $a['amount']));
            if (!empty($a['by'])) echo ' · par ' . e((string) $a['by']);
            if (!empty($a['note'])) echo ' · « ' . e((string) $a['note']) . ' »'; ?></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>
    <?php endif; ?>
<?php ga_foot(); ?>
