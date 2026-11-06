<?php
// Parcours d'inscription public de l'équipe du projet : 5 étapes (vous, rôle,
// domaines, disponibilité, envoi). On y arrive par un lien d'invitation nominatif
// (portail → Équipe → Invitations), par le code d'accès de l'équipe, ou sans rien
// si l'exploitant a laissé l'inscription ouverte. Chaque envoi crée une
// candidature visible dans /portail/equipe.php?vue=candidatures.
define('PORTAIL_PUBLIC_ROUTE', true);
require __DIR__ . '/_bootstrap.php';
require_once PORTAIL_ROOT . '/includes/equipe.php';

const EQI_STEPS = [1 => 'Vous', 2 => 'Votre rôle', 3 => 'Vos domaines', 4 => 'Disponibilité', 5 => 'Envoi'];
const EQI_MAX_PER_HOUR = 4;

/** Champs de l'étape 1 et 2, nettoyés depuis le formulaire. */
function eqi_input(array $post, string $key, int $max = 200): string
{
    return eq_text($post[$key] ?? '', $max);
}

/** Enregistre le brouillon en session (gardé d'une étape à l'autre). */
function eqi_draft_save(array $draft): void
{
    $_SESSION['eq_draft'] = $draft;
}

function eqi_draft_reset(): void
{
    unset($_SESSION['eq_draft']);
}

/** Erreurs bloquantes pour l'étape demandée (tableau vide = on peut avancer). */
function eqi_step_errors(int $step, array $d): array
{
    $errors = [];
    if ($step === 1) {
        if (eq_text($d['first'] ?? '', 60) === '') $errors[] = 'Indiquez votre prénom.';
        if (eq_text($d['last'] ?? '', 60) === '') $errors[] = 'Indiquez votre nom.';
        $email = mb_strtolower(eq_text($d['email'] ?? '', 190));
        if ($email === '') $errors[] = 'Indiquez votre adresse e-mail.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "L'adresse e-mail n'est pas valide.";
    } elseif ($step === 2) {
        if (!isset(EQ_CATEGORIES[(string) ($d['category'] ?? '')])) $errors[] = 'Choisissez votre rôle : conseiller, testeur ou partenaire.';
        if (($d['category'] ?? '') === 'testeur' && !isset(EQ_TEST_MODES[(string) ($d['mode'] ?? '')])) $errors[] = 'Précisez le type de testeur : réel ou virtuel.';
        if (($d['category'] ?? '') === 'partenaire' && !isset(EQ_PARTNER_NATURES[(string) ($d['nature'] ?? '')])) $errors[] = 'Précisez la nature du partenariat.';
    } elseif ($step === 3) {
        if (!eq_domains($d['domains'] ?? [])) $errors[] = 'Choisissez au moins un domaine, même « Autre / à préciser ».';
    } elseif ($step === 4) {
        if (eq_text($d['heures'] ?? '', 60) === '') $errors[] = 'Indiquez le temps que vous pouvez consacrer au projet.';
    } elseif ($step === 5) {
        if (empty($d['consent'])) $errors[] = 'Cochez l’accord pour envoyer votre candidature.';
    }
    return $errors;
}

$invitationCode = eq_code_normalize((string) ($_GET['invitation'] ?? $_POST['invitation'] ?? ''));
$invitation = $invitationCode !== '' ? eq_invitation_find($invitationCode) : null;
$codeInput = eq_code_normalize((string) ($_POST['code'] ?? $_GET['code'] ?? ''));
$draft = $_SESSION['eq_draft'] ?? null;
$gateError = '';
$errors = [];
$sentRef = (string) ($_SESSION['eq_sent'] ?? '');

// Un lien différent de celui du brouillon repart de zéro (et applique sa présélection).
if ($invitation && $draft && ($draft['invitation'] ?? '') !== $invitation['code']) {
    eqi_draft_reset();
    $draft = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'acces') {
        if (eq_access_valid((string) ($_POST['code'] ?? ''))) {
            eqi_draft_reset();
            $_SESSION['eq_draft'] = [
                'invitation' => '', 'source' => 'code',
                'data' => ['category' => '', 'domains' => []], 'step' => 1, 'max' => 1,
            ];
            header('Location: /portail/equipe-inscription.php?etape=1');
            exit;
        }
        $gateError = 'Code incorrect. Vérifiez auprès de la personne qui vous a invité.';
    } elseif ($action === 'etape') {
        csrf_check();
        $step = max(1, min(5, (int) ($_POST['etape'] ?? 1)));
        $goBack = ($_POST['go'] ?? '') === 'back';
        $draft = $_SESSION['eq_draft'] ?? null;
        if (!$draft) {
            header('Location: /portail/equipe-inscription.php');
            exit;
        }
        $data = $draft['data'] ?? [];
        if (!$goBack) {
            // Chaque étape ne met à jour que ses propres champs : ceux des étapes
            // précédentes restent dans le brouillon.
            if ($step === 1) {
                $data['first'] = eqi_input($_POST, 'first', 60);
                $data['last'] = eqi_input($_POST, 'last', 60);
                $data['email'] = mb_strtolower(eqi_input($_POST, 'email', 190));
                $data['phone'] = eqi_input($_POST, 'phone', 40);
                $data['city'] = eqi_input($_POST, 'city', 90);
            } elseif ($step === 2) {
                $data['category'] = (string) ($_POST['category'] ?? '');
                $data['mode'] = (string) ($_POST['mode'] ?? '');
                $data['outils'] = eq_paragraph($_POST['outils'] ?? '', 600);
                $data['nature'] = (string) ($_POST['nature'] ?? '');
                $data['montant'] = eqi_input($_POST, 'montant', 60);
                $data['echeance'] = eqi_input($_POST, 'echeance', 60);
            } elseif ($step === 3) {
                $data['domains'] = eq_domains($_POST['domains'] ?? []);
                $data['precision'] = eq_paragraph($_POST['precision'] ?? '', 600);
            } elseif ($step === 4) {
                $data['heures'] = eqi_input($_POST, 'heures', 60);
                $data['dispo'] = eqi_input($_POST, 'dispo', 200);
                $data['message'] = eq_paragraph($_POST['message'] ?? '', 2000);
            } elseif ($step === 5) {
                $data['consent'] = !empty($_POST['consent']);
            }
        }

        if ($goBack) {
            $draft['data'] = $data;
            $draft['step'] = max(1, $step - 1);
            eqi_draft_save($draft);
            header('Location: /portail/equipe-inscription.php?etape=' . $draft['step']);
            exit;
        }

        $errors = eqi_step_errors($step, $data);
        if ($errors) {
            $draft['data'] = $data;
            $draft['step'] = $step;
            $draft['max'] = max((int) $draft['max'], $step);
            eqi_draft_save($draft);
        } elseif ($step < 5) {
            $draft['data'] = $data;
            $draft['step'] = $step + 1;
            $draft['max'] = max((int) $draft['max'], $step + 1);
            eqi_draft_save($draft);
            header('Location: /portail/equipe-inscription.php?etape=' . ($step + 1));
            exit;
        } else {
            // Envoi final : une candidature par personne, pas de doublon dans la file.
            if (!empty($_POST['site_web'])) {
                // Champ piège rempli : on répond comme si tout s'était bien passé.
                eqi_draft_reset();
                $_SESSION['eq_sent'] = 'OK';
                header('Location: /portail/equipe-inscription.php?envoye=1');
                exit;
            }
            $recent = array_values(array_filter((array) ($_SESSION['eq_sends'] ?? []), static fn ($t): bool => $t > time() - 3600));
            if (count($recent) >= EQI_MAX_PER_HOUR) {
                $errors[] = 'Plusieurs candidatures ont déjà été envoyées depuis ce navigateur. Réessayez dans une heure.';
            } else {
                $application = eq_application_save([
                    'first' => $data['first'] ?? '',
                    'last' => $data['last'] ?? '',
                    'email' => $data['email'] ?? '',
                    'phone' => $data['phone'] ?? '',
                    'city' => $data['city'] ?? '',
                    'category' => $data['category'] ?? '',
                    'domains' => $data['domains'] ?? [],
                    'mode' => $data['mode'] ?? '',
                    'outils' => $data['outils'] ?? '',
                    'heures' => $data['heures'] ?? '',
                    'dispo' => $data['dispo'] ?? '',
                    'message' => trim(($data['message'] ?? '') . (($data['precision'] ?? '') !== '' ? "\n\nPrécisions : " . $data['precision'] : '')),
                    'partner' => ['nature' => $data['nature'] ?? '', 'montant' => $data['montant'] ?? '', 'echeance' => $data['echeance'] ?? ''],
                    'source' => $draft['source'] ?? 'lien',
                    'invitation' => $draft['invitation'] ?? '',
                    'status' => 'nouvelle',
                    'created' => time(),
                ]);
                if (!$application) {
                    $errors[] = "La candidature n'a pas pu être enregistrée. Réessayez ou écrivez directement à la personne qui vous a invité.";
                } else {
                    $recent[] = time();
                    $_SESSION['eq_sends'] = $recent;
                    if (($draft['invitation'] ?? '') !== '') eq_invitation_use($draft['invitation']);
                    eq_notify_application($application);
                    eq_event('team_application', [
                        'email' => $application['email'],
                        'note' => $application['name'] . ' · ' . EQ_CATEGORIES[$application['category']]['label'] . ' · ' . ($draft['source'] ?? 'lien'),
                    ]);
                    eqi_draft_reset();
                    $_SESSION['eq_sent'] = mb_strtoupper(substr($application['id'], 0, 8));
                    header('Location: /portail/equipe-inscription.php?envoye=1');
                    exit;
                }
            }
            $draft['data'] = $data;
            $draft['step'] = 5;
            $draft['max'] = 5;
            eqi_draft_save($draft);
        }
    }
}

$draft = $_SESSION['eq_draft'] ?? null;

// Première visite autorisée : le brouillon se crée avec la présélection de l'invitation.
if (!$draft && ($invitation || eq_access_valid($codeInput))) {
    $_SESSION['eq_draft'] = [
        'invitation' => $invitation['code'] ?? '',
        'source' => $invitation ? 'lien' : ($codeInput !== '' ? 'code' : 'libre'),
        'data' => [
            'category' => $invitation['category'] ?? '',
            'domains' => !empty($invitation['domain']) ? [$invitation['domain']] : [],
        ],
        'step' => 1,
        'max' => 1,
    ];
    $draft = $_SESSION['eq_draft'];
}
$authorized = $draft !== null;
$data = $draft['data'] ?? [];
$step = $authorized ? max(1, min((int) ($draft['max'] ?? 1), (int) ($_GET['etape'] ?? $draft['step'] ?? 1))) : 1;
if ($sentRef !== '') {
    unset($_SESSION['eq_sent']);
    $step = 6;
}
$activeInvitation = $authorized && ($draft['invitation'] ?? '') !== '' ? eq_invitation_find((string) $draft['invitation']) : null;
$v = static fn (string $key, string $default = ''): string => (string) ($data[$key] ?? $default);
$has = static fn (string $key, string $value): string => (string) ($data[$key] ?? '') === $value ? ' checked' : '';
$sel = static fn (string $key, string $value): string => (string) ($data[$key] ?? '') === $value ? ' selected' : '';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Rejoindre l’équipe — Brocs</title>
<link href="/assets/fonts.css" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<style>
  body.eqi { padding: 0 16px 48px; }
  .eqi-shell { width: min(760px, 100%); margin: 0 auto; padding-top: clamp(20px, 4vw, 40px); display: grid; gap: 18px; }
  .eqi-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .eqi-mark { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 12px; background: var(--ink); color: var(--panel); font-weight: 800; letter-spacing: -0.02em; }
  .eqi-head b { display: block; font-size: 1.05rem; letter-spacing: -0.02em; line-height: 1.15; }
  .eqi-head small { display: block; color: var(--muted); font-size: 0.72rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: 700; }
  .eqi-card { border: 1px solid var(--line); border-radius: 16px; background: var(--panel); box-shadow: 0 24px 60px -42px rgba(12, 20, 15, 0.4); padding: clamp(18px, 3vw, 30px); display: grid; gap: 16px; }
  .eqi-card h1 { margin: 0; font-size: clamp(1.4rem, 3vw, 1.85rem); letter-spacing: -0.03em; line-height: 1.15; }
  .eqi-card h2 { margin: 0; font-size: 1.15rem; letter-spacing: -0.02em; }
  .eqi-lead { margin: 0; color: var(--muted); font-size: 0.95rem; line-height: 1.55; max-width: 62ch; }
  .eqi-progress { display: grid; gap: 8px; }
  .eqi-bars { display: grid; grid-template-columns: repeat(5, 1fr); gap: 5px; }
  .eqi-bars i { display: block; height: 5px; border-radius: 999px; background: var(--line); }
  .eqi-bars i.done { background: var(--accent); }
  .eqi-steps { display: flex; justify-content: space-between; gap: 6px; font-size: 0.7rem; font-weight: 700; color: var(--muted); }
  .eqi-steps span.now { color: var(--accent); }
  .eqi-fields { display: grid; gap: 13px; }
  .eqi-2 { display: grid; gap: 13px; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }
  .eqi-fields label, .eqi-2 label { display: grid; gap: 5px; font-size: 0.78rem; font-weight: 700; color: var(--muted); }
  .eqi-fields input, .eqi-fields select, .eqi-fields textarea, .eqi-2 input, .eqi-2 select { width: 100%; border: 1px solid var(--line); border-radius: 9px; background: var(--panel); padding: 9px 11px; color: var(--ink); }
  .eqi-fields textarea { min-height: 82px; resize: vertical; }
  .eqi-fields input:focus, .eqi-fields select:focus, .eqi-fields textarea:focus, .eqi-2 input:focus, .eqi-2 select:focus { border-color: var(--accent); outline: 2px solid color-mix(in srgb, var(--accent) 22%, transparent); }
  .eqi-choice { display: grid; gap: 8px; }
  .eqi-role { display: flex; gap: 11px; align-items: flex-start; padding: 13px 14px; border: 1px solid var(--line); border-radius: 11px; cursor: pointer; background: var(--panel); }
  .eqi-role:hover { border-color: color-mix(in srgb, var(--accent) 35%, var(--line)); }
  .eqi-role:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
  .eqi-role input { margin-top: 3px; accent-color: var(--accent); }
  .eqi-role b { display: block; font-size: 0.93rem; }
  .eqi-role small { display: block; color: var(--muted); font-size: 0.8rem; font-weight: 400; line-height: 1.45; }
  .eqi-sub { display: none; gap: 12px; padding: 13px 14px; border: 1px dashed color-mix(in srgb, var(--accent) 40%, var(--line)); border-radius: 11px; background: var(--panel-2); }
  .eqi-sub.on { display: grid; }
  .eqi-checks { display: grid; gap: 7px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
  .eqi-check { display: flex; gap: 8px; align-items: flex-start; padding: 9px 11px; border: 1px solid var(--line); border-radius: 9px; background: var(--panel); font-size: 0.87rem; cursor: pointer; }
  .eqi-check input { margin-top: 3px; accent-color: var(--accent); }
  .eqi-check:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
  .eqi-actions { display: flex; gap: 9px; flex-wrap: wrap; align-items: center; margin-top: 3px; }
  .eqi-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 16px; border-radius: 10px; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-ink); font-weight: 700; font-size: 0.9rem; cursor: pointer; text-decoration: none; }
  .eqi-btn.ghost { background: transparent; color: var(--ink); border-color: var(--line); font-weight: 600; }
  .eqi-btn:hover { filter: brightness(1.05); }
  .eqi-err { margin: 0; padding: 10px 13px; border: 1px solid color-mix(in srgb, #b3261e 35%, var(--line)); border-radius: 9px; background: #fbe9e7; color: #8a1c13; font-size: 0.86rem; font-weight: 600; }
  .eqi-note { margin: 0; color: var(--muted); font-size: 0.78rem; }
  .eqi-recap { display: grid; gap: 8px; margin: 0; }
  .eqi-recap div { display: grid; grid-template-columns: 170px 1fr; gap: 10px; padding: 7px 0; border-bottom: 1px dashed var(--line); font-size: 0.88rem; }
  .eqi-recap dt { color: var(--muted); font-weight: 700; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; }
  .eqi-recap dd { margin: 0; white-space: pre-line; }
  .eqi-consent { display: flex; gap: 9px; align-items: flex-start; font-size: 0.85rem; color: var(--muted); }
  .eqi-consent input { margin-top: 3px; accent-color: var(--accent); }
  .eqi-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
  .eqi-done { text-align: center; display: grid; gap: 10px; justify-items: center; padding: 12px 0; }
  .eqi-done .badge { display: grid; place-items: center; width: 54px; height: 54px; border-radius: 50%; background: #e1f2e7; color: #2e7d4f; font-size: 1.5rem; }
  .eqi-done h2 { margin: 0; }
  .eqi-ref { font-family: var(--font-mono); font-size: 0.86rem; padding: 4px 10px; border: 1px solid var(--line); border-radius: 999px; background: var(--panel-2); }
  @media (max-width: 560px) { .eqi-recap div { grid-template-columns: 1fr; gap: 2px; } .eqi-steps { display: none; } }
</style>
</head>
<body class="eqi">
<main class="eqi-shell">
  <header class="eqi-head">
    <span class="eqi-mark" aria-hidden="true">b</span>
    <div><b>Brocs · équipe du projet</b><small>Invitation</small></div>
  </header>

<?php if ($step === 6): ?>
  <section class="eqi-card">
    <div class="eqi-done">
      <span class="badge" aria-hidden="true">✓</span>
      <h2>Candidature envoyée</h2>
      <p class="eqi-lead">Merci ! Votre demande a bien été transmise. Elle sera étudiée et vous recevrez une réponse à l’adresse indiquée.</p>
      <?php if ($sentRef !== 'OK'): ?><span class="eqi-ref">Référence <?= e($sentRef) ?></span><?php endif; ?>
      <p class="eqi-note">Vous pouvez fermer cette page.</p>
    </div>
  </section>

<?php elseif (!$authorized): ?>
  <section class="eqi-card">
    <h1>Rejoindre l’équipe</h1>
    <p class="eqi-lead">Conseil, tests ou partenariat financier : ce parcours sert à présenter votre profil et vos disponibilités. Il prend cinq minutes. Il vous faut le lien d’invitation ou le code d’accès qui vous a été communiqué.</p>
    <?php if ($invitationCode !== '' && !$invitation): ?>
      <p class="eqi-err" role="alert">Ce lien d’invitation n’est plus valable (expiré, désactivé ou quota atteint). Demandez-en un nouveau.</p>
    <?php endif; ?>
    <?php if ($gateError !== ''): ?><p class="eqi-err" role="alert"><?= e($gateError) ?></p><?php endif; ?>
    <form method="post" class="eqi-fields" autocomplete="off">
      <input type="hidden" name="action" value="acces">
      <label>Code d’accès<input type="text" name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ex. A1B2C3D4"></label>
      <p class="eqi-note">Les tirets et les espaces sont ignorés.</p>
      <div class="eqi-actions"><button class="eqi-btn" type="submit">Continuer</button></div>
    </form>
  </section>

<?php else: ?>
  <section class="eqi-card">
    <div class="eqi-progress" aria-label="Progression">
      <div class="eqi-bars" aria-hidden="true">
        <?php foreach (EQI_STEPS as $i => $label): ?><i class="<?= $i <= $step ? 'done' : '' ?>"></i><?php endforeach; ?>
      </div>
      <div class="eqi-steps">
        <?php foreach (EQI_STEPS as $i => $label): ?><span class="<?= $i === $step ? 'now' : '' ?>"><?= e($label) ?></span><?php endforeach; ?>
      </div>
    </div>

    <?php if ($errors): ?>
      <p class="eqi-err" role="alert"><?= e(implode(' ', $errors)) ?></p>
    <?php endif; ?>

    <form method="post" class="eqi-fields" autocomplete="on" novalidate>
      <input type="hidden" name="action" value="etape">
      <input type="hidden" name="etape" value="<?= (int) $step ?>">
      <input type="hidden" name="invitation" value="<?= e($draft['invitation'] ?? '') ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="eqi-hp" aria-hidden="true"><label>Ne pas remplir<input type="text" name="site_web" tabindex="-1" autocomplete="off"></label></div>

      <?php if ($step === 1): ?>
        <h2>Qui êtes-vous ?</h2>
        <p class="eqi-lead">Vos coordonnées servent uniquement à vous recontacter au sujet du projet.</p>
        <div class="eqi-2">
          <label>Prénom<input type="text" name="first" maxlength="60" required value="<?= e($v('first')) ?>"></label>
          <label>Nom<input type="text" name="last" maxlength="60" required value="<?= e($v('last')) ?>"></label>
          <label>E-mail<input type="email" name="email" maxlength="190" required value="<?= e($v('email')) ?>"></label>
          <label>Téléphone (facultatif)<input type="tel" name="phone" maxlength="40" value="<?= e($v('phone')) ?>"></label>
          <label>Ville (facultatif)<input type="text" name="city" maxlength="90" value="<?= e($v('city')) ?>"></label>
        </div>

      <?php elseif ($step === 2): ?>
        <h2>Comment souhaitez-vous aider ?</h2>
        <p class="eqi-lead">Une seule catégorie : elle détermine la suite du parcours et la façon dont votre fiche sera suivie.</p>
        <div class="eqi-choice" role="radiogroup" aria-label="Rôle">
          <?php foreach (EQ_CATEGORIES as $key => $cat): ?>
            <label class="eqi-role"><input type="radio" name="category" value="<?= e($key) ?>" required<?= $has('category', $key) ?> data-role="<?= e($key) ?>"> <span><b><?= e($cat['label']) ?></b><small><?= e($cat['desc']) ?></small></span></label>
          <?php endforeach; ?>
        </div>
        <div class="eqi-sub" id="role-testeur" data-sub="testeur">
          <h3 style="margin:0;font-size:0.9rem;">Votre profil de testeur</h3>
          <div class="eqi-choice">
            <?php foreach (EQ_TEST_MODES as $key => $label): ?>
              <label class="eqi-role" style="padding:10px 12px;"><input type="radio" name="mode" value="<?= e($key) ?>"<?= $has('mode', $key) ?: ($v('mode') === '' && $key === 'reel' ? ' checked' : '') ?>> <span><small style="font-size:0.84rem;color:var(--ink);font-weight:600;"><?= e($label) ?></small></span></label>
            <?php endforeach; ?>
          </div>
          <label>Appareils, outils, scénarios à tester<textarea name="outils" maxlength="600" placeholder="ex. iPhone + Chrome, parcours d’achat sur mobile, paiement, création de boutique…"><?= e($v('outils')) ?></textarea></label>
        </div>
        <div class="eqi-sub" id="role-partenaire" data-sub="partenaire">
          <h3 style="margin:0;font-size:0.9rem;">Votre apport</h3>
          <p class="eqi-note">Les conditions précises se définiront ensemble ; indiquez seulement ce que vous envisagez.</p>
          <div class="eqi-2">
            <label>Nature<select name="nature"><?php foreach (EQ_PARTNER_NATURES as $key => $label): ?><option value="<?= e($key) ?>"<?= $sel('nature', $key) ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            <label>Montant envisagé (facultatif)<input type="text" name="montant" maxlength="60" value="<?= e($v('montant')) ?>" placeholder="ex. 3 000 € ou 10 %"></label>
            <label>Échéance souhaitée (facultatif)<input type="text" name="echeance" maxlength="60" value="<?= e($v('echeance')) ?>" placeholder="ex. fin 2026"></label>
          </div>
        </div>

      <?php elseif ($step === 3): ?>
        <h2>Sur quels domaines ?</h2>
        <p class="eqi-lead">Plusieurs réponses possibles. Cela permet de trouver les bonnes personnes au bon moment.</p>
        <div class="eqi-checks">
          <?php foreach (EQ_DOMAINS as $key => $label): ?>
            <label class="eqi-check"><input type="checkbox" name="domains[]" value="<?= e($key) ?>"<?= in_array($key, (array) ($data['domains'] ?? []), true) ? ' checked' : '' ?>> <span><?= e($label) ?></span></label>
          <?php endforeach; ?>
        </div>
        <label>Précisions (facultatif)<textarea name="precision" maxlength="600" placeholder="ex. sites vitrines, paiements, référencement local, relation commerçants…"><?= e($v('precision')) ?></textarea></label>

      <?php elseif ($step === 4): ?>
        <h2>Vos disponibilités</h2>
        <p class="eqi-lead">Aucun engagement formel : ces informations servent à proposer des moments réalistes.</p>
        <div class="eqi-fields">
          <label>Temps disponible<select name="heures" required><option value="">— choisir —</option><?php foreach (EQ_HEURES as $heure): ?><option value="<?= e($heure) ?>"<?= $sel('heures', $heure) ?>><?= e($heure) ?></option><?php endforeach; ?></select></label>
          <label>Format préféré (facultatif)<input type="text" name="dispo" maxlength="200" value="<?= e($v('dispo')) ?>" placeholder="ex. visio le soir, sur place le week-end…"></label>
          <label>Un mot pour l’équipe (facultatif)<textarea name="message" maxlength="2000" placeholder="Ce que vous aimeriez apporter, ce que vous attendez du projet…"><?= e($v('message')) ?></textarea></label>
        </div>

      <?php else: ?>
        <h2>Vérifier et envoyer</h2>
        <p class="eqi-lead">Relisez votre demande. Vous pourrez encore revenir en arrière pour corriger.</p>
        <dl class="eqi-recap">
          <div><dt>Identité</dt><dd><?= e(trim(($data['first'] ?? '') . ' ' . ($data['last'] ?? ''))) ?><br><?= e($data['email'] ?? '') ?><?= ($data['phone'] ?? '') !== '' ? ' · ' . e($data['phone']) : '' ?><?= ($data['city'] ?? '') !== '' ? ' · ' . e($data['city']) : '' ?></dd></div>
          <div><dt>Rôle</dt><dd><?= e(EQ_CATEGORIES[$data['category'] ?? 'conseiller']['label']) ?><?php if (($data['category'] ?? '') === 'testeur'): ?> · <?= e(EQ_TEST_MODES[$data['mode'] ?? 'reel']) ?><?php if (($data['outils'] ?? '') !== ''): ?><br><?= e($data['outils']) ?><?php endif; ?><?php endif; ?><?php if (($data['category'] ?? '') === 'partenaire'): ?> · <?= e(EQ_PARTNER_NATURES[$data['nature'] ?? 'a_definir']) ?><?= ($data['montant'] ?? '') !== '' ? ' · ' . e($data['montant']) : '' ?><?= ($data['echeance'] ?? '') !== '' ? ' · ' . e($data['echeance']) : '' ?><?php endif; ?></dd></div>
          <div><dt>Domaines</dt><dd><?= e(implode(', ', array_map(static fn ($d) => EQ_DOMAINS[$d], eq_domains($data['domains'] ?? [])))) ?><?= ($data['precision'] ?? '') !== '' ? '<br>' . e($data['precision']) : '' ?></dd></div>
          <div><dt>Disponibilité</dt><dd><?= e($data['heures'] ?? '—') ?><?= ($data['dispo'] ?? '') !== '' ? ' · ' . e($data['dispo']) : '' ?><?= ($data['message'] ?? '') !== '' ? '<br>' . e($data['message']) : '' ?></dd></div>
        </dl>
        <label class="eqi-consent"><input type="checkbox" name="consent" value="1" required<?= empty($data['consent']) ? '' : ' checked' ?>> <span>J’accepte que Brocs conserve ces informations pour gérer ma candidature et me recontacter. Elles ne sont ni revendues ni transmises à des tiers, et je peux demander leur suppression à tout moment.</span></label>
      <?php endif; ?>

      <div class="eqi-actions">
        <?php if ($step > 1): ?><button class="eqi-btn ghost" type="submit" name="go" value="back" formnovalidate>← Précédent</button><?php endif; ?>
        <button class="eqi-btn" type="submit" name="go" value="next"><?= $step === 5 ? 'Envoyer ma candidature' : 'Continuer →' ?></button>
        <?php if ($activeInvitation && $activeInvitation['label'] !== ''): ?><span class="eqi-note">Invitation : <?= e($activeInvitation['label']) ?></span><?php endif; ?>
      </div>
    </form>
  </section>
  <p class="eqi-note">Vos réponses servent uniquement à organiser l’entraide autour du projet. Aucun engagement ne découle de l’envoi de ce formulaire.</p>
<?php endif; ?>
</main>
<script>
(function () {
  var roles = document.querySelectorAll('input[name="category"]');
  var subs = document.querySelectorAll('[data-sub]');
  function sync() {
    var chosen = document.querySelector('input[name="category"]:checked');
    var value = chosen ? chosen.value : '';
    subs.forEach(function (sub) { sub.classList.toggle('on', sub.getAttribute('data-sub') === value); });
  }
  roles.forEach(function (radio) { radio.addEventListener('change', sync); });
  sync();
})();
</script>
</body>
</html>
