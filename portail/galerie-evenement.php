<?php
// Espace organisateur (vide-grenier, marché aux puces) : création de l'événement (date, lieu, places ou mètres, prix au mètre),
// numérotation automatique des places (Place 1 à Place N) sans éditeur visuel, réglages des réservations et accès au tableau de bord.
require __DIR__ . '/_galerie-layout.php';
require_once PORTAIL_ROOT . '/includes/gallery-event.php';
require_once PORTAIL_ROOT . '/includes/gallery-booking.php';

$self = '/portail/galerie-evenement.php' . ($limited ? '' : '?g=' . urlencode($gaSlug));
$orgOn = gm_enabled($gaGallery, 'organisateur');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['action'] ?? '') === 'save') {
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

    <div class="kpis">
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

      <h3>Places et tarif</h3>
      <p class="meta" style="margin:0;">La numérotation est automatique : Place 1 à Place N. Aucun plan à dessiner n'est nécessaire.</p>
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
          <span class="ev-chip <?= e($st) ?>" title="<?= e(ge_slot_name($p)) ?> : <?= $st === 'taken' ? 'attribuée' : ($st === 'pending' ? 'en attente' : 'libre') ?>"><?= e($p['num']) ?></span>
        <?php endforeach; ?>
      </div>
      <?php if ($count > 200): ?><p class="meta">Aperçu des 200 premières places ; les <?= $count - 200 ?> suivantes sont numérotées de la même façon.</p><?php endif; ?>
    </section>
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
