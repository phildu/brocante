<?php
// Parcours public de recrutement : accueil des trois pistes, détail d'un
// besoin et candidature. Accessible sans compte, par lien de piste
// (?piste=emploi|primo|benevole) ou par lien de besoin (?offre=CODE).
// Chaque envoi crée une candidature visible dans /portail/recrutement/.
define('PORTAIL_PUBLIC_ROUTE', true);
require __DIR__ . '/../_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/recrutement.php';

const RCP_MAX_PER_HOUR = 3;

$settings = rc_settings();
$events = rc_upcoming_events(20, true);
$offer = null;
$offerCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_GET['offre'] ?? $_POST['offre'] ?? '')) ?? '');
if ($offerCode !== '') $offer = rc_offer_find_code($offerCode);
$offerOpen = $offer ? rc_offer_is_open($offer) : false;

$track = $offer['track'] ?? (isset(RC_TRACKS[(string) ($_GET['piste'] ?? $_POST['track'] ?? '')]) ? (string) ($_GET['piste'] ?? $_POST['track']) : '');
$offerEvent = null;
if ($offer && $offer['event'] !== '') {
    foreach ($events as $event) {
        if ($event['slug'] === $offer['event']) { $offerEvent = $event; break; }
    }
}

$errors = [];
$sent = isset($_GET['envoye']);
$sentTrack = isset(RC_TRACKS[(string) ($_GET['piste'] ?? '')]) ? (string) $_GET['piste'] : $track;
$old = [
    'first' => '', 'last' => '', 'email' => '', 'phone' => '', 'city' => '',
    'domains' => [], 'contract' => $offer['contract'] ?? '', 'availability' => '',
    'experience' => '', 'portfolio' => '', 'salary' => '', 'tasks' => '',
    'mobility' => '', 'motivation' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
    csrf_check();
    $old = array_merge($old, array_intersect_key($_POST, $old));
    $old['domains'] = rc_domains($_POST['domains'] ?? []);
    if (!$track) $errors[] = 'Choisissez une piste de candidature.';
    if (!$offer && !$settings['open']) $errors[] = 'Les candidatures spontanées sont fermées. Utilisez le lien d’un besoin ouvert.';
    if (!empty($_POST['site_web'])) {
        header('Location: /portail/recrutement/postuler.php?envoye=1' . ($track !== '' ? '&piste=' . urlencode($track) : ''));
        exit;
    }
    $recent = array_values(array_filter((array) ($_SESSION['rc_sends'] ?? []), static fn ($t): bool => $t > time() - 3600));
    if (count($recent) >= RCP_MAX_PER_HOUR) {
        $errors[] = 'Plusieurs candidatures ont déjà été envoyées depuis ce navigateur. Réessayez dans une heure.';
    }
    if ($offer && !$offerOpen) $errors[] = 'Ce besoin n’est plus ouvert aux candidatures.';
    if (!$errors) {
        [$application, $problems] = rc_application_from_input($_POST + ['track' => $track], $offer);
        if ($problems) {
            $errors = $problems;
        } elseif ($application) {
            $saved = rc_application_save($application);
            if (!$saved) {
                $errors[] = "La candidature n'a pas pu être enregistrée. Réessayez dans un instant.";
            } else {
                $recent[] = time();
                $_SESSION['rc_sends'] = $recent;
                rc_notify_application($saved, $offer);
                rc_event('recruitment_application', [
                    'email' => $saved['email'],
                    'note' => $saved['name'] . ' · ' . rc_track_label($saved['track']) . ' · ' . ($offer['title'] ?? 'spontanée'),
                ]);
                $query = ['envoye' => 1];
                if ($track !== '') $query['piste'] = $track;
                if ($offer) $query['offre'] = $offer['code'];
                header('Location: /portail/recrutement/postuler.php?' . http_build_query($query));
                exit;
            }
        }
    }
}

$openOffers = array_values(array_filter(rc_offers(), static fn (array $o): bool => rc_offer_is_open($o)));
$trackOffers = $track !== '' ? array_values(array_filter($openOffers, static fn (array $o): bool => $o['track'] === $track)) : $openOffers;
$showForm = $offer ? $offerOpen : ($track !== '' && $settings['open']);
$counts = [];
foreach ($openOffers as $o) $counts[$o['track']] = ($counts[$o['track']] ?? 0) + 1;
$v = static fn (string $key): string => (string) ($old[$key] ?? '');
$sel = static fn (string $key, string $value): string => (string) ($old[$key] ?? '') === $value ? ' selected' : '';
$has = static fn (string $key, string $value): string => in_array($value, (array) ($old[$key] ?? []), true) ? ' checked' : '';
$trackDomains = static function (string $track): array {
    return match ($track) {
        'benevole' => ['logistique', 'accueil', 'communication', 'autre'],
        'primo' => ['design', 'fonctionnel', 'financement', 'tests', 'autre'],
        default => ['design', 'fonctionnel', 'developpement', 'tests', 'financement', 'communication', 'autre'],
    };
};
$dateFr = static function (string $date): string {
    $ts = $date !== '' ? strtotime($date) : false;
    return $ts ? date('d/m/Y', $ts) : '';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($offer ? $offer['title'] . ' — Recrutement' : 'Rejoindre les brocs — Recrutement') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  body.rcp { padding: 0 16px 56px; }
  .rcp-shell { width: min(1000px, 100%); margin: 0 auto; padding-top: clamp(18px, 4vw, 36px); display: grid; gap: 18px; }
  .rcp-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
  .rcp-brand { display: flex; align-items: center; gap: 11px; text-decoration: none; color: var(--ink); }
  .rcp-mark { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--ink); color: var(--panel); font-weight: 800; letter-spacing: -0.02em; }
  .rcp-brand b { display: block; font-size: 1.05rem; letter-spacing: -0.02em; line-height: 1.15; }
  .rcp-brand small { display: block; color: var(--muted); font-size: 0.7rem; letter-spacing: 0.12em; text-transform: uppercase; font-weight: 700; }
  .rcp-back { color: var(--muted); font-size: 0.84rem; text-decoration: none; }
  .rcp-back:hover { color: var(--accent); }
  .rcp-hero { display: grid; gap: 12px; padding: clamp(20px, 4vw, 34px); border: 1px solid var(--line); border-radius: 18px; background: var(--panel); box-shadow: 0 24px 60px -46px rgba(12, 20, 15, 0.4); }
  .rcp-hero h1 { margin: 0; font-size: clamp(1.6rem, 4vw, 2.4rem); letter-spacing: -0.035em; line-height: 1.08; }
  .rcp-hero p { margin: 0; color: var(--muted); font-size: 0.98rem; line-height: 1.55; max-width: 66ch; }
  .rcp-tracks { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
  .rcp-track { display: grid; gap: 9px; align-content: start; padding: 18px; border: 1px solid var(--line); border-radius: 16px; background: var(--panel); }
  .rcp-track h2 { margin: 0; font-size: 1.05rem; letter-spacing: -0.02em; }
  .rcp-track p { margin: 0; color: var(--muted); font-size: 0.86rem; line-height: 1.5; }
  .rcp-track .rcp-count { font-size: 0.74rem; font-weight: 700; color: var(--accent); }
  .rcp-track .btn { justify-self: start; }
  .rcp-section { display: grid; gap: 12px; }
  .rcp-section h2 { margin: 0; font-size: 1.15rem; letter-spacing: -0.02em; }
  .rcp-offers { display: grid; gap: 10px; }
  .rcp-offer { display: grid; gap: 6px; padding: 14px 16px; border: 1px solid var(--line); border-radius: 14px; background: var(--panel); text-decoration: none; color: inherit; }
  .rcp-offer:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--line)); }
  .rcp-offer b { font-size: 0.98rem; }
  .rcp-tag { display: inline-block; padding: 1px 8px; border: 1px solid var(--line); border-radius: 999px; font-size: 0.7rem; font-weight: 600; color: var(--muted); }
  .rcp-meta { color: var(--muted); font-size: 0.8rem; }
  .rcp-offer .rcp-go { font-size: 0.8rem; font-weight: 700; color: var(--accent); }
  .rcp-form { display: grid; gap: 16px; padding: clamp(18px, 3vw, 30px); border: 1px solid var(--line); border-radius: 18px; background: var(--panel); }
  .rcp-form h2 { margin: 0; font-size: 1.3rem; letter-spacing: -0.025em; }
  .rcp-form .rcp-lead { margin: 0; color: var(--muted); font-size: 0.9rem; line-height: 1.5; }
  .rcp-fields { display: grid; gap: 14px; }
  .rcp-2 { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
  .rcp-fields label, .rcp-2 label { display: grid; gap: 5px; font-size: 0.78rem; font-weight: 700; color: var(--muted); }
  .rcp-fields input, .rcp-fields select, .rcp-fields textarea, .rcp-2 input, .rcp-2 select { width: 100%; border: 1px solid var(--line); border-radius: 9px; background: var(--panel); padding: 9px 11px; color: var(--ink); font: inherit; }
  .rcp-fields textarea { min-height: 88px; resize: vertical; }
  .rcp-fields input:focus, .rcp-fields select:focus, .rcp-fields textarea:focus, .rcp-2 input:focus, .rcp-2 select:focus { border-color: var(--accent); outline: 2px solid color-mix(in srgb, var(--accent) 22%, transparent); }
  .rcp-checks { display: grid; gap: 7px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); }
  .rcp-check { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; border: 1px solid var(--line); border-radius: 9px; background: var(--panel); font-size: 0.84rem; font-weight: 500; color: var(--ink); cursor: pointer; }
  .rcp-check input { margin-top: 2px; }
  .rcp-consent { display: flex; gap: 9px; align-items: flex-start; font-size: 0.84rem; color: var(--muted); line-height: 1.5; }
  .rcp-consent input { margin-top: 3px; }
  .rcp-errors { display: grid; gap: 6px; padding: 12px 14px; border: 1px solid color-mix(in srgb, var(--danger) 35%, var(--line)); border-radius: 11px; background: var(--danger-soft); color: var(--danger); font-size: 0.86rem; }
  .rcp-errors ul { margin: 0; padding-left: 18px; }
  .rcp-submit { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .rcp-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
  .rcp-event { display: grid; gap: 4px; padding: 12px 14px; border: 1px dashed var(--line-strong); border-radius: 11px; background: var(--panel-2); font-size: 0.86rem; }
  .rcp-success { display: grid; gap: 12px; padding: clamp(22px, 4vw, 36px); border: 1px solid var(--line); border-radius: 18px; background: var(--panel); }
  .rcp-success h1 { margin: 0; font-size: clamp(1.4rem, 3vw, 1.9rem); letter-spacing: -0.03em; }
  .rcp-success p { margin: 0; color: var(--muted); line-height: 1.55; }
  .rcp-foot { color: var(--muted); font-size: 0.76rem; text-align: center; line-height: 1.5; }
  @media (max-width: 620px) { .rcp-hero { padding: 20px 16px; } .rcp-form { padding: 18px 15px; } }
</style>
</head>
<body class="rcp">
<div class="rcp-shell">
  <header class="rcp-head">
    <a class="rcp-brand" href="/portail/recrutement/postuler.php">
      <span class="rcp-mark" aria-hidden="true">b</span>
      <span><b>brocs</b><small>Recrutement</small></span>
    </a>
    <?php if ($offer || $track): ?><a class="rcp-back" href="/portail/recrutement/postuler.php">← Toutes les pistes</a><?php endif; ?>
  </header>

  <?php if ($sent): ?>
    <section class="rcp-success">
      <h1>Candidature envoyée, merci !</h1>
      <p>Votre message est bien arrivé. Nous revenons vers vous par e-mail, généralement sous quelques jours ouvrés, pour préciser la suite.</p>
      <p class="rcp-meta"><?= e($sentTrack !== '' ? rc_track_label($sentTrack) : 'Candidature') ?><?= $offer ? ' · ' . e($offer['title']) : '' ?></p>
      <div class="rcp-submit">
        <a class="btn" href="/portail/recrutement/postuler.php">Voir les autres pistes</a>
      </div>
    </section>

  <?php elseif ($offer): ?>
    <section class="rcp-hero">
      <span class="rcp-tag"><?= e(rc_track_label($offer['track'])) ?> · <?= e(RC_CONTRACTS[$offer['contract']]) ?></span>
      <h1><?= e($offer['title']) ?></h1>
      <p><?= e($offer['description']) ?></p>
      <p class="rcp-meta">
        <?= $offer['location'] !== '' ? e($offer['location']) : 'Lieu à préciser' ?><?= $offer['remote'] ? ' · à distance possible' : '' ?>
        <?= $offer['compensation'] !== '' ? ' · ' . e($offer['compensation']) : '' ?>
        <?= $offer['deadline'] !== '' ? ' · candidatures avant le ' . e($dateFr($offer['deadline'])) : '' ?>
      </p>
      <?php if ($offerEvent): ?>
        <div class="rcp-event">
          <b><?= e($offerEvent['title']) ?></b>
          <span class="rcp-meta"><?= e($offerEvent['date'] !== '' ? $dateFr($offerEvent['date']) : 'date à confirmer') ?><?= $offerEvent['opening'] !== '' ? ' · ' . e($offerEvent['opening']) : '' ?><?= $offerEvent['closing'] !== '' ? '–' . e($offerEvent['closing']) : '' ?></span>
          <span class="rcp-meta"><?= e(trim($offerEvent['place'] . ($offerEvent['city'] !== '' ? ' · ' . $offerEvent['city'] : ''))) ?></span>
        </div>
      <?php elseif ($offer['date'] !== ''): ?>
        <p class="rcp-meta">Mission prévue le <?= e($dateFr($offer['date'])) ?>.</p>
      <?php endif; ?>
      <?php if ($offer['missions'] !== ''): ?>
        <div><b>Ce qu’il y a à faire</b><p style="white-space:pre-line;margin-top:4px;"><?= e($offer['missions']) ?></p></div>
      <?php endif; ?>
      <?php if ($offer['profile'] !== ''): ?>
        <div><b>Profil recherché</b><p style="white-space:pre-line;margin-top:4px;"><?= e($offer['profile']) ?></p></div>
      <?php endif; ?>
      <?php if (!$offerOpen): ?>
        <p class="rcp-errors"><span>Ce besoin n’est plus ouvert aux candidatures. Vous pouvez parcourir les autres pistes.</span></p>
      <?php endif; ?>
    </section>

  <?php else: ?>
    <section class="rcp-hero">
      <h1>Rejoindre les brocs</h1>
      <p><?= e($settings['intro']) ?></p>
    </section>

    <section class="rcp-tracks" aria-label="Les pistes">
      <?php foreach (RC_TRACKS as $key => $info): ?>
        <article class="rcp-track">
          <h2><?= e($info['label']) ?></h2>
          <p><?= e($info['desc']) ?></p>
          <span class="rcp-count"><?= (int) ($counts[$key] ?? 0) ?> besoin<?= ($counts[$key] ?? 0) > 1 ? 's' : '' ?> ouvert<?= ($counts[$key] ?? 0) > 1 ? 's' : '' ?></span>
          <a class="btn btn-primary" href="/portail/recrutement/postuler.php?piste=<?= e($key) ?>#candidature"><?= e($info['cta']) ?></a>
        </article>
      <?php endforeach; ?>
    </section>

    <section class="rcp-section">
      <h2>Besoins ouverts</h2>
      <?php if (!$openOffers): ?>
        <p class="rcp-meta"><?= $settings['open'] ? 'Aucun besoin publié pour le moment. Les candidatures spontanées restent ouvertes si vous voulez vous présenter.' : 'Aucun besoin publié pour le moment. Les candidatures spontanées sont fermées : revenez quand un besoin sera en ligne.' ?></p>
      <?php else: ?>
        <div class="rcp-offers">
          <?php foreach ($openOffers as $o): ?>
            <a class="rcp-offer" href="/portail/recrutement/postuler.php?offre=<?= e(urlencode($o['code'])) ?>#candidature">
              <span class="rcp-tag"><?= e(RC_TRACKS[$o['track']]['short']) ?> · <?= e(RC_CONTRACTS[$o['contract']]) ?></span>
              <b><?= e($o['title']) ?></b>
              <span class="rcp-meta"><?= e($o['location'] !== '' ? $o['location'] : 'Lieu à préciser') ?><?= $o['remote'] ? ' · à distance possible' : '' ?><?= $o['compensation'] !== '' ? ' · ' . e($o['compensation']) : '' ?><?= $o['slots'] > 1 ? ' · ' . (int) $o['slots'] . ' places' : '' ?></span>
              <span class="rcp-go">Candidater →</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if (!$sent && $showForm): ?>
    <section class="rcp-form" id="candidature">
      <h2><?= $offer ? 'Candidater à ce besoin' : 'Candidature ' . mb_strtolower(rc_track_label($track)) ?></h2>
      <p class="rcp-lead"><?= $offer ? 'Remplissez ce formulaire : nous revenons vers vous par e-mail.' : 'Quelques minutes suffisent. Choisissez d’abord la piste, puis précisez ce que vous pouvez apporter.' ?></p>

      <?php if ($errors): ?>
        <div class="rcp-errors" role="alert"><b>Il manque quelque chose :</b><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <form method="post" action="/portail/recrutement/postuler.php<?= $offer ? '?offre=' . e(urlencode($offer['code'])) : '' ?>" class="rcp-fields" autocomplete="on">
        <?= '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">' ?>
        <input type="hidden" name="action" value="apply">
        <?php if ($offer): ?>
          <input type="hidden" name="offre" value="<?= e($offer['code']) ?>">
          <input type="hidden" name="track" value="<?= e($offer['track']) ?>">
        <?php else: ?>
          <label>Vous candidatez pour<select name="track" id="rcp-track" required>
            <?php foreach (RC_TRACKS as $key => $info): ?><option value="<?= e($key) ?>"<?= $track === $key ? ' selected' : '' ?>><?= e($info['label']) ?></option><?php endforeach; ?>
          </select></label>
        <?php endif; ?>

        <div class="rcp-2">
          <label>Prénom<input type="text" name="first" maxlength="60" required value="<?= e($v('first')) ?>" autocomplete="given-name"></label>
          <label>Nom<input type="text" name="last" maxlength="60" required value="<?= e($v('last')) ?>" autocomplete="family-name"></label>
          <label>E-mail<input type="email" name="email" maxlength="190" required value="<?= e($v('email')) ?>" autocomplete="email"></label>
          <label>Téléphone<input type="tel" name="phone" maxlength="40" value="<?= e($v('phone')) ?>" autocomplete="tel" placeholder="Facultatif"></label>
          <label>Ville<input type="text" name="city" maxlength="90" value="<?= e($v('city')) ?>" autocomplete="address-level2"></label>
          <label>Disponibilité<select name="availability">
            <option value="">— à préciser —</option>
            <?php foreach (RC_AVAILABILITIES as $availability): ?><option value="<?= e($availability) ?>"<?= $sel('availability', $availability) ?>><?= e($availability) ?></option><?php endforeach; ?>
          </select></label>
        </div>

        <div>
          <p class="rcp-meta" style="margin:0 0 6px;font-weight:700;">Ce que vous pouvez apporter</p>
          <div class="rcp-checks">
            <?php foreach (($offer || $track !== '' ? $trackDomains($track) : array_keys(RC_DOMAINS)) as $domain): ?>
              <label class="rcp-check"><input type="checkbox" name="domains[]" value="<?= e($domain) ?>"<?= $has('domains', $domain) ?>> <?= e(RC_DOMAINS[$domain]) ?></label>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($offer): ?>
          <?php if ($track === 'emploi'): ?>
            <div class="rcp-2">
              <label>Type de contrat souhaité<select name="contract">
                <?php foreach (RC_CONTRACTS as $key => $label): ?><option value="<?= e($key) ?>"<?= $sel('contract', $key) ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></label>
              <label>Prétentions<input type="text" name="salary" maxlength="80" value="<?= e($v('salary')) ?>" placeholder="ex. 350 €/jour, à discuter"></label>
            </div>
            <label>Portfolio, LinkedIn ou site<input type="text" name="portfolio" maxlength="300" value="<?= e($v('portfolio')) ?>" placeholder="https://…"></label>
            <label>Parcours et références<textarea name="experience" maxlength="2500" placeholder="Vos expériences utiles pour ce besoin…"><?= e($v('experience')) ?></textarea></label>
          <?php elseif ($track === 'primo'): ?>
            <label>Ce que vous savez faire, ou ce qui vous ferait plaisir<textarea name="experience" maxlength="2500" placeholder="Design, relecture, tests sur mobile, conseils, financement…"><?= e($v('experience')) ?></textarea></label>
          <?php else: ?>
            <label>Mobilité / comment venir<input type="text" name="mobility" maxlength="120" value="<?= e($v('mobility')) ?>" placeholder="ex. voiture, vélo, transports"></label>
            <label>Tâches que vous préférez<textarea name="tasks" maxlength="1200" placeholder="Installation, accueil, stand, démontage…"><?= e($v('tasks')) ?></textarea></label>
          <?php endif; ?>
        <?php else: ?>
          <div data-track-block="emploi" hidden>
            <div class="rcp-2">
              <label>Type de contrat souhaité<select name="contract">
                <?php foreach (RC_CONTRACTS as $key => $label): ?><option value="<?= e($key) ?>"<?= $sel('contract', $key) ?>><?= e($label) ?></option><?php endforeach; ?>
              </select></label>
              <label>Prétentions<input type="text" name="salary" maxlength="80" value="<?= e($v('salary')) ?>" placeholder="ex. 350 €/jour, à discuter"></label>
            </div>
            <label>Portfolio, LinkedIn ou site<input type="text" name="portfolio" maxlength="300" value="<?= e($v('portfolio')) ?>" placeholder="https://…"></label>
            <label>Parcours et références<textarea name="experience" maxlength="2500" placeholder="Vos expériences utiles pour ce besoin…"><?= e($v('experience')) ?></textarea></label>
          </div>
          <div data-track-block="primo" hidden>
            <label>Ce que vous savez faire, ou ce qui vous ferait plaisir<textarea name="experience" maxlength="2500" placeholder="Design, relecture, tests sur mobile, conseils, financement…"><?= e($v('experience')) ?></textarea></label>
          </div>
          <div data-track-block="benevole" hidden>
            <label>Mobilité / comment venir<input type="text" name="mobility" maxlength="120" value="<?= e($v('mobility')) ?>" placeholder="ex. voiture, vélo, transports"></label>
            <label>Tâches que vous préférez<textarea name="tasks" maxlength="1200" placeholder="Installation, accueil, stand, démontage…"><?= e($v('tasks')) ?></textarea></label>
          </div>
        <?php endif; ?>

        <label>Votre message<textarea name="motivation" maxlength="2500" placeholder="<?= $offer ? 'Pourquoi ce besoin vous intéresse…' : 'Présentez-vous en quelques lignes…' ?>"><?= e($v('motivation')) ?></textarea></label>

        <label class="rcp-consent"><input type="checkbox" name="consent" value="1" required> J’accepte que mes coordonnées soient utilisées pour répondre à ma candidature. Aucun démarchage, aucune revente.</label>
        <div class="rcp-hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>

        <div class="rcp-submit">
          <button class="btn btn-primary" type="submit">Envoyer ma candidature</button>
          <span class="rcp-meta">Réponse par e-mail sous quelques jours ouvrés.</span>
        </div>
      </form>
    </section>
  <?php elseif (!$sent && $track !== '' && !$offer): ?>
    <section class="rcp-section">
      <h2><?= e(rc_track_label($track)) ?></h2>
      <p class="rcp-meta"><?= e(RC_TRACKS[$track]['desc']) ?></p>
      <?php if ($trackOffers): ?>
        <div class="rcp-offers">
          <?php foreach ($trackOffers as $o): ?>
            <a class="rcp-offer" href="/portail/recrutement/postuler.php?offre=<?= e(urlencode($o['code'])) ?>#candidature">
              <span class="rcp-tag"><?= e(RC_CONTRACTS[$o['contract']]) ?></span>
              <b><?= e($o['title']) ?></b>
              <span class="rcp-meta"><?= e($o['location'] !== '' ? $o['location'] : 'Lieu à préciser') ?><?= $o['compensation'] !== '' ? ' · ' . e($o['compensation']) : '' ?></span>
              <span class="rcp-go">Candidater →</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$settings['open']): ?><p class="rcp-meta">Les candidatures spontanées sont fermées : choisissez un besoin ci-dessus.</p><?php endif; ?>
    </section>
  <?php endif; ?>

  <p class="rcp-foot">brocs · recrutement — les informations envoyées servent uniquement à traiter votre candidature et sont conservées dans le portail de l’association.</p>
</div>
<script>
(function () {
  var track = document.getElementById('rcp-track');
  if (!track) return;
  var form = track.form;
  var blocks = Array.prototype.slice.call(form.querySelectorAll('[data-track-block]'));
  var sync = function () {
    blocks.forEach(function (block) {
      var active = block.getAttribute('data-track-block') === track.value;
      block.hidden = !active;
      block.querySelectorAll('input, select, textarea').forEach(function (field) { field.disabled = !active; });
    });
  };
  track.addEventListener('change', sync);
  sync();
})();
</script>
</body>
</html>
