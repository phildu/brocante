<?php
// File d'attente publique d'une galerie (vide-grenier, marché aux puces) : inscription, suivi par lien reçu par e-mail et acceptation
// d'une place voisine proposée à prix réduit. Voir includes/gallery-waitlist.php.
require_once __DIR__ . '/../includes/gallery-offers.php';
require_once __DIR__ . '/../includes/gallery-waitlist.php';

function gwa_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function gwa_ink(string $hex): string
{
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#1c1a17' : '#ffffff';
}

$slug = strtolower((string) ($_GET['g'] ?? $_POST['g'] ?? ''));
$gallery = gallery_slug_valid($slug) ? gallery_load($slug) : null;
$booking = $gallery ? gb_booking($gallery) : null;
$open = $gallery && gwl_available($gallery) && (!function_exists('go_is_live') || go_is_live($gallery));
$event = $gallery ? gallery_event($gallery) : null;
$settings = $gallery ? gwl_settings($gallery) : null;
$accent = $gallery['accent'] ?? '#b5502e';
$ink = gwa_ink($accent);
$notice = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!$gallery || !$open) {
        $error = "Les inscriptions à la file d'attente ne sont pas ouvertes.";
    } elseif ($action === 'join') {
        if (trim((string) ($_POST['website'] ?? '')) !== '') {   // champ piège : un robot remplit tout
            $notice = 'Votre inscription est enregistrée.';
        } else {
            [$entry, $err] = gwl_join($gallery, $_POST, (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($err) $error = $err;
            else $notice = 'entry:' . $entry['token'];
        }
    } elseif ($action === 'leave') {
        $ok = gwl_leave($slug, (string) ($_POST['t'] ?? ''));
        if ($ok) $notice = 'Inscription retirée : vous ne recevrez plus d\'e-mail pour cette file.';
        else $error = 'Cette inscription est introuvable ou déjà close.';
    } elseif ($action === 'claim') {
        [$row, $err] = gwl_claim_offer($gallery, (string) ($_POST['o'] ?? ''));
        if ($err) $error = $err;
        else $notice = 'claim:' . $row['token'];
    }
}

$entry = null;
$offer = null;
$token = (string) ($_GET['t'] ?? '');
if ($gallery && preg_match('/^[a-f0-9]{32}$/', $token)) $entry = gwl_entry_by_token($gallery, $token);
$offerToken = (string) ($_GET['o'] ?? '');
if ($gallery && !$entry && preg_match('/^[a-f0-9]{32}$/', $offerToken)) $offer = gwl_offer_by_token($gallery, $offerToken);

$free = $gallery && $open ? gwl_free_slots($gallery) : [];
$waitingCount = $gallery ? count(gwl_waiting($gallery)) : 0;
$allSlots = $gallery ? gwl_all_slots($gallery) : [];
$entryToken = $notice !== null && str_starts_with($notice, 'entry:') ? substr($notice, 6) : '';
$claimToken = $notice !== null && str_starts_with($notice, 'claim:') ? substr($notice, 6) : '';
$back = $gallery ? '/galerie/' . rawurlencode($slug) . '/' . ($event ? '#evenement' : '#plan-commerces') : '/';
$form = ['name' => '', 'email' => '', 'phone' => '', 'kind' => 'particulier', 'places' => '1', 'zone' => '', 'message' => ''];

http_response_code(!$gallery ? 404 : 200);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $gallery ? gwa_e("File d'attente — " . $gallery['name']) : 'Galerie introuvable' ?></title>
<style>
  :root { --accent: <?= gwa_e($accent) ?>; --ink-accent: <?= gwa_e($ink) ?>; --bg: #f4f0e8; --ink: #221f1a; --muted: #6b6457; --line: #ddd5c6; --panel: #fff; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 16px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
  a { color: var(--accent); }
  header.bar { background: var(--accent); color: var(--ink-accent); padding: 18px 18px 16px; }
  header.bar .wrap { max-width: 720px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; justify-content: space-between; }
  header.bar b { font-size: 1.15rem; } header.bar a { color: inherit; text-decoration: none; font-weight: 600; font-size: .9rem; }
  main { max-width: 720px; margin: 0 auto; padding: 18px; display: grid; gap: 14px; }
  .card { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 18px; display: grid; gap: 10px; }
  h1 { font-size: 1.35rem; margin: 0; } h2 { font-size: 1.05rem; margin: 0; }
  p { margin: 0; } .muted { color: var(--muted); font-size: .9rem; }
  .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  label.f { display: grid; gap: 4px; font-size: .82rem; font-weight: 600; flex: 1; min-width: 180px; }
  input, select, textarea { font: inherit; padding: 10px; border: 1px solid #cfc6b4; border-radius: 8px; background: #fffdf8; color: var(--ink); width: 100%; }
  textarea { min-height: 90px; resize: vertical; }
  button, .btn { font: inherit; font-weight: 600; padding: 11px 16px; border: 1px solid var(--accent); border-radius: 9px; background: var(--accent); color: var(--ink-accent); cursor: pointer; text-decoration: none; display: inline-block; }
  .btn.ghost { background: transparent; color: var(--accent); }
  .flash { border-radius: 9px; padding: 11px 13px; font-size: .95rem; }
  .flash.ok { background: #e6f4ec; color: #1d6b42; } .flash.err { background: #fbe9e4; color: #a4361b; }
  .slots { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: 6px; }
  .slots li { border: 1px solid var(--line); border-radius: 999px; padding: 3px 10px; font-size: .82rem; background: #faf7f0; }
  .pill { display: inline-block; border-radius: 999px; padding: 2px 9px; font-size: .76rem; background: #f1ece1; }
  .pill.sent { background: #fff3df; color: #8a5200; } .pill.done { background: #e6f4ec; color: #1d6b42; }
  .consent { display: flex; gap: 8px; align-items: flex-start; font-size: .82rem; color: var(--muted); }
  .consent input { width: auto; margin-top: 3px; }
  .hp { position: absolute; left: -9999px; height: 1px; width: 1px; overflow: hidden; }
  footer { text-align: center; color: var(--muted); font-size: .8rem; padding: 6px 18px 28px; }
</style>
</head>
<body>
<header class="bar"><div class="wrap"><b><?= $gallery ? gwa_e($gallery['name']) : 'Galerie' ?></b><a href="<?= gwa_e($back) ?>">Voir la galerie →</a></div></header>
<main>
<?php if (!$gallery): ?>
  <section class="card"><h1>Galerie introuvable</h1><p class="muted">Ce lien ne correspond à aucune galerie publiée.</p></section>
<?php else: ?>
  <?php if ($error): ?><p class="flash err" role="alert"><?= gwa_e($error) ?></p><?php endif; ?>
  <?php if ($notice !== null && !str_starts_with($notice, 'entry:') && !str_starts_with($notice, 'claim:')): ?><p class="flash ok" role="status"><?= gwa_e($notice) ?></p><?php endif; ?>

  <?php if ($entryToken !== ''): ?>
    <section class="card">
      <h1>Inscription enregistrée</h1>
      <p>Votre adresse <strong><?= gwa_e((string) ($_POST['email'] ?? '')) ?></strong> est dans la file d'attente de « <?= gwa_e($gallery['name']) ?> ».</p>
      <p class="muted">Vous recevrez un e-mail dès qu'une place se libère. L'inscription ne réserve pas de place : la place revient au premier exposant qui la demande sur la page de la galerie.</p>
      <p class="row"><a class="btn" href="<?= gwa_e(gwl_public_url($slug, ['t' => $entryToken])) ?>">Suivre ou annuler mon inscription</a> <a class="btn ghost" href="<?= gwa_e($back) ?>">Retour à la galerie</a></p>
    </section>
  <?php elseif ($claimToken !== ''): ?>
    <section class="card">
      <h1>Place voisine retenue</h1>
      <p>La place vient d'être retenue à votre nom, à côté de votre stand. L'organisateur valide la demande comme pour toute réservation et vous répond par e-mail.</p>
      <p class="row"><a class="btn" href="<?= gwa_e('/galerie/exposant.php?g=' . rawurlencode($slug) . '&t=' . rawurlencode($claimToken)) ?>">Suivre ma demande d'emplacement</a> <a class="btn ghost" href="<?= gwa_e($back) ?>">Retour à la galerie</a></p>
    </section>
  <?php elseif ($entry): $status = (string) ($entry['status'] ?? 'waiting'); ?>
    <section class="card">
      <h1>Votre inscription à la file</h1>
      <p><?= gwa_e((string) ($entry['name'] ?? '')) ?> · <?= gwa_e((string) ($entry['email'] ?? '')) ?></p>
      <p class="row"><span class="pill <?= $status === 'notified' ? 'sent' : ($status === 'joined' ? 'done' : '') ?>"><?= $status === 'joined' ? 'Place obtenue' : ($status === 'removed' ? 'Inscription retirée' : ($status === 'notified' ? 'Vous avez été prévenu' : 'En attente')) ?></span>
        <span class="muted"><?= (int) ($entry['places'] ?? 1) ?> place(s) souhaitée(s) · inscrit le <?= gwa_e(date('d/m/Y', (int) ($entry['created'] ?? 0))) ?></span></p>
      <?php if (in_array($status, ['waiting', 'notified'], true)): ?>
        <p class="muted"><?= (int) ($entry['notify_count'] ?? 0) ?> e-mail(s) reçu(s). La file avance dans l'ordre des inscriptions ; une place libre reste à prendre sur la page de la galerie.</p>
        <form method="post"><input type="hidden" name="g" value="<?= gwa_e($slug) ?>"><input type="hidden" name="action" value="leave"><input type="hidden" name="t" value="<?= gwa_e($token) ?>"><button class="btn ghost" type="submit" onclick="return confirm('Retirer cette inscription de la file ?');">Me retirer de la file</button></form>
      <?php elseif ($status === 'removed'): ?>
        <p class="muted">Cette inscription a été retirée. Vous pouvez vous réinscrire depuis la page de la galerie.</p>
      <?php endif; ?>
      <p class="row"><a class="btn" href="<?= gwa_e($back) ?>">Voir la galerie</a></p>
    </section>
  <?php elseif ($offer): $offerStatus = (string) ($offer['status'] ?? 'open'); ?>
    <section class="card">
      <h1>Une place à côté de votre stand</h1>
      <?php if ($offerStatus === 'open' && (int) ($offer['expires'] ?? 0) >= time()): ?>
        <p>La place <strong><?= gwa_e(gwl_slot_name_by_id($gallery, (string) $offer['slot'], $allSlots)) ?></strong> est libre, juste à côté de votre emplacement <strong><?= gwa_e(gwl_slot_name_by_id($gallery, (string) $offer['from_slot'], $allSlots)) ?></strong>.</p>
        <p>Elle vous est proposée à <strong><?= gwa_e(gwl_eur($offer['price_cents'] ?? null)) ?></strong><?php if (!empty($offer['discount_pct'])): ?> (soit -<?= (int) $offer['discount_pct'] ?> % sur le tarif de <?= gwa_e(gwl_eur($offer['base_cents'] ?? null)) ?>)<?php endif; ?>. Vous gardez votre emplacement actuel : vous gagnez simplement de la place.</p>
        <p class="muted">L'organisateur valide ensuite la demande, comme pour toute réservation. Offre valable jusqu'au <?= gwa_e(date('d/m/Y', (int) ($offer['expires'] ?? 0))) ?>.</p>
        <form method="post"><input type="hidden" name="g" value="<?= gwa_e($slug) ?>"><input type="hidden" name="action" value="claim"><input type="hidden" name="o" value="<?= gwa_e($offerToken) ?>"><button class="btn" type="submit">Accepter cette place</button></form>
      <?php else: ?>
        <p class="muted">Cette offre n'est plus disponible : la place a été prise ou l'offre a expiré. Vous pouvez suivre les places libres depuis la page de la galerie.</p>
      <?php endif; ?>
      <p class="row"><a class="btn ghost" href="<?= gwa_e($back) ?>">Voir la galerie</a></p>
    </section>
  <?php elseif (!$open): ?>
    <section class="card"><h1>File d'attente fermée</h1><p class="muted">Les inscriptions ne sont pas ouvertes pour cette galerie. Consultez la page de la galerie pour connaître les prochaines dates.</p><p class="row"><a class="btn" href="<?= gwa_e($back) ?>">Voir la galerie</a></p></section>
  <?php else: ?>
    <section class="card">
      <h1>File d'attente</h1>
      <?php if ($event): ?><p class="muted"><strong><?= gwa_e($event['title']) ?></strong><?= ge_date_text($event) !== '' ? ' · ' . gwa_e(ge_date_text($event)) : '' ?><?= ge_place_text($event) !== '' ? ' · ' . gwa_e(ge_place_text($event)) : '' ?><?= ge_hours_text($event) !== '' ? ' · ' . gwa_e(ge_hours_text($event)) : '' ?></p><?php endif; ?>
      <p><?= gwa_e($settings['public_intro']) ?></p>
      <p class="muted" id="etat"><?php if ($free): ?><?= count($free) ?> place(s) libre(s) en ce moment<?= $waitingCount ? ' · ' . $waitingCount . ' inscrit(s) dans la file' : '' ?>.<?php else: ?>Toutes les places sont prises pour le moment<?= $waitingCount ? ' · ' . $waitingCount . ' inscrit(s) dans la file' : '' ?>. Inscrivez-vous : vous serez prévenu dès qu'une place se libère.<?php endif; ?></p>
      <?php if ($free): ?><ul class="slots"><?php foreach (array_slice($free, 0, 12) as $s): ?><li><?= gwa_e($s['name']) ?><?= $s['price_cents'] !== null ? ' — ' . gwa_e(gwl_eur($s['price_cents'])) : '' ?></li><?php endforeach; ?><?php if (count($free) > 12): ?><li>+ <?= count($free) - 12 ?> autre(s)</li><?php endif; ?></ul><?php endif; ?>
      <p class="muted">Une place libre se réserve directement sur la page de la galerie (premier arrivé, premier servi). La file d'attente sert à être prévenu par e-mail ; elle ne bloque aucune place.</p>
    </section>

    <section class="card">
      <h2>M'inscrire à la file</h2>
      <form method="post" action="<?= gwa_e('/galerie/attente.php?g=' . rawurlencode($slug)) ?>">
        <input type="hidden" name="g" value="<?= gwa_e($slug) ?>"><input type="hidden" name="action" value="join">
        <div class="hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
        <div class="row">
          <label class="f">Nom ou raison sociale<input type="text" name="name" maxlength="80" autocomplete="name" value="<?= gwa_e($form['name']) ?>"></label>
          <label class="f">Adresse e-mail<input type="email" name="email" maxlength="120" autocomplete="email" required value="<?= gwa_e($form['email']) ?>"></label>
          <label class="f">Téléphone (facultatif)<input type="tel" name="phone" maxlength="30" autocomplete="tel" value="<?= gwa_e($form['phone']) ?>"></label>
          <label class="f">Profil<select name="kind"><?php foreach (CT_TYPES as $k => $label): ?><option value="<?= gwa_e($k) ?>"<?= $form['kind'] === $k ? ' selected' : '' ?>><?= gwa_e($label) ?></option><?php endforeach; ?></select></label>
          <label class="f">Places souhaitées<select name="places"><?php for ($i = 1; $i <= 4; $i++): ?><option value="<?= $i ?>"<?= (string) $i === $form['places'] ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></label>
          <label class="f">Zone souhaitée (facultatif)<input type="text" name="zone" maxlength="60" value="<?= gwa_e($form['zone']) ?>" placeholder="Ex. allée des livres"></label>
        </div>
        <label class="f" style="margin-top:8px;">Précisions (facultatif)<textarea name="message" maxlength="400" placeholder="Type de stand, besoin de place, matériel…"><?= gwa_e($form['message']) ?></textarea></label>
        <label class="consent" style="margin-top:10px;"><input type="checkbox" name="consent" value="1" required> J'accepte d'être prévenu·e par e-mail et que mes coordonnées soient conservées par l'organisateur pour cette file d'attente.</label>
        <p style="margin-top:12px;"><button class="btn" type="submit">M'inscrire à la file</button></p>
      </form>
    </section>
  <?php endif; ?>
<?php endif; ?>
</main>
<footer>File d'attente gérée par l'organisateur de la galerie. Les données servent uniquement à vous prévenir.</footer>
</body>
</html>
