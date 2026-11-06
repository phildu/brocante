<?php
// Connexion des administrateurs d'une galerie. Adresse propre à chaque galerie : /galerie/<identifiant>/admin (réécrite vers ce fichier
// avec ?g=<identifiant>, voir .htaccess, router.php et LocalValetDriver.php) — page de connexion à ses couleurs, qui n'ouvre QUE cette
// galerie ; /galerie/admin.php, sans identifiant, reste la connexion générique (liens des anciens e-mails). Une fois connecté, l'administrateur
// gère SA galerie depuis le portail (mode restreint : voir portail/_bootstrap.php). Les comptes sont créés par l'exploitant
// (portail → Galeries → Administrateurs).
session_start();
require_once __DIR__ . '/../includes/gallery-admin.php';
require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/login-ui.php';

function ga_e($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['ga_csrf'])) $_SESSION['ga_csrf'] = bin2hex(random_bytes(16));

// Galerie visée par l'adresse (/galerie/<identifiant>/admin) ; un identifiant inconnu retombe sur la connexion générique.
$slug = strtolower((string) ($_GET['g'] ?? ''));
$target = gallery_slug_valid($slug) ? gallery_load($slug) : null;
if (!$target) $slug = '';
// Retenue pour la connexion sociale : elle revient sur /galerie/admin.php (oauth/finish.php), qui reprend alors cette galerie.
if ($target) $_SESSION['ga_target'] = $slug;
$loginUrl = ga_login_url($slug);
$error = null;
$notice = null;
if (!empty($_SESSION['ga_oauth_error'])) { $error = (string) $_SESSION['ga_oauth_error']; unset($_SESSION['ga_oauth_error']); }   // message laissé par la connexion sociale (oauth/finish.php)

if (!empty($_SESSION['ga_notice'])) { $notice = (string) $_SESSION['ga_notice']; unset($_SESSION['ga_notice']); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['ga_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'La page avait expiré : réessayez.';
    } elseif (($_POST['action'] ?? '') === 'logout') {
        unset($_SESSION['gallery_admin']);
        $notice = 'Vous êtes déconnecté·e.';
    } elseif (($_POST['action'] ?? '') === 'login') {
        if (!$target) unset($_SESSION['ga_target']);   // connexion générique : aucune galerie précise n'est demandée
        $error = ga_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
        // Depuis l'adresse d'une galerie, ce compte doit l'administrer : sinon on ne l'ouvre pas (et on ne l'envoie pas dans une autre).
        if ($error === null && $target && !in_array($slug, (ga_current()['slugs'] ?? []), true)) {
            unset($_SESSION['gallery_admin']);
            $error = 'Ce compte n\'administre pas « ' . $target['name'] . ' ». Utilisez l\'identifiant d\'un administrateur de cette galerie.';
        }
    }
}
if ($admin = ga_current()) {
    // Galerie à ouvrir : celle de l'adresse, ou (retour d'une connexion sociale) celle retenue au départ.
    $want = $slug !== '' ? $slug : (string) ($_SESSION['ga_target'] ?? '');
    if ($slug !== '' && !in_array($slug, $admin['slugs'], true)) {
        $notice = 'Vous êtes connecté·e à une autre galerie : connectez-vous avec un administrateur de « ' . $target['name'] . ' » pour l\'ouvrir.';
    } else {
        if ($want !== '' && in_array($want, $admin['slugs'], true)) $_SESSION['gallery_admin']['slug'] = $want;
        unset($_SESSION['ga_target']);
        header('Location: ' . ($admin['must_change'] ? '/portail/galerie-compte.php' : '/portail/galerie-tableau.php'));
        exit;
    }
}
http_response_code($error ? 401 : 200);

// Habillage : nom, logo et couleur d'accent de la galerie visée (couleur du bouton lisible : texte clair ou foncé selon la luminosité).
$brandName = $target ? (string) $target['name'] : 'brocs';
$brandLogo = $target ? trim((string) ($target['style']['logo'] ?? '')) : '';
$accent = $target ? (string) $target['accent'] : '';
$accentInk = '';
if ($accent !== '' && sscanf($accent, '#%02x%02x%02x', $ar, $ag, $ab) === 3) {
    $accentInk = (0.299 * $ar + 0.587 * $ag + 0.114 * $ab) > 160 ? '#1c1a17' : '#ffffff';
}
$social = oauth_buttons_html('gadmin', ['csrf' => $_SESSION['ga_csrf']]);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $target ? ga_e('Administration — ' . $target['name']) : 'Administration de galerie — Connexion' ?></title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/login.css">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<?php if ($accentInk !== ''): ?><style>:root, :root[data-theme="dark"], :root:not([data-theme="light"]) { --accent: <?= ga_e($accent) ?>; --accent-ink: <?= ga_e($accentInk) ?>; }
.login-brand .mark { background: <?= ga_e($accent) ?>; color: <?= ga_e($accentInk) ?>; overflow: hidden; }
.login-brand .mark img { width: 100%; height: 100%; object-fit: contain; display: block; background: #fff; }</style><?php endif; ?>
</head>
<body class="login-body">
<main class="login-card">
  <div class="login-brand">
    <span class="mark" aria-hidden="true"><?php if ($brandLogo !== ''): ?><img src="/<?= ga_e($brandLogo) ?>" alt=""><?php else: ?><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/></svg><?php endif; ?></span>
    <div><b><?= ga_e($brandName) ?></b><small>Espace galerie</small></div>
  </div>
  <h1>Connexion</h1>
  <p class="hint"><?= $target ? 'Gérez la page, les membres et le tableau de bord de « ' . ga_e($target['name']) . ' ».' : 'Gérez votre galerie commerciale, ses commerces membres et ses exposants.' ?></p>
  <?php if ($error): ?><p class="flash" data-kind="error" role="alert"><?= ga_e($error) ?></p><?php endif; ?>
  <?php if ($notice): ?><p class="flash" data-kind="ok" role="status"><?= ga_e($notice) ?></p><?php endif; ?>
  <?= login_form_html([
    'id' => 'gallery-login',
    'identifier_name' => 'email',
    'social' => $social,
    'forgot_url' => '/galerie/mot-de-passe.php' . ($slug !== '' ? '?g=' . rawurlencode($slug) : ''),
    'submit' => 'Se connecter',
    'hidden' => ['csrf' => $_SESSION['ga_csrf'], 'action' => 'login'],
  ]) ?>
  <?php if ($social): ?>
    <p class="oauth-note">Avec un réseau social, l'adresse e-mail doit être celle de votre compte d'administrateur de galerie.</p>
  <?php endif; ?>
  <p class="login-foot">Compte créé par l'exploitant de la plateforme.<br>
    <?php if ($target): ?><a href="<?= ga_e(gallery_public_url($target)) ?>">← Retour à la page de « <?= ga_e($target['name']) ?> »</a> · <?php endif; ?><a href="/connexion.php">Connexion unique — boutique, galerie, portail</a></p>
</main>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
