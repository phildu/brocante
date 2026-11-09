<?php
// Caisse : application smartphone « Vente » du commerce (installable sur l'écran d'accueil), distincte du Studio.
// Lecture d'un QR code (billet de retrait, reçu, étiquette), vente directe en boutique (espèces ou carte via Stripe)
// et validation des commandes en ligne. Les appels passent par admin/pos-action.php (includes/pos.php).
// Connexion propre à l'application (identifiant ou e-mail + mot de passe), mémorisable 30 jours sur le téléphone.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/share.php';
require_once __DIR__ . '/includes/oauth.php';
require_once __DIR__ . '/includes/apps.php';
require_once __DIR__ . '/includes/login-ui.php';

header('Cache-Control: no-store');
$content = get_content();
$error = null;
$notice = null;
// Messages laissés par la connexion sociale (oauth/finish.php) ou la réinitialisation du mot de passe.
if (!empty($_SESSION['login_error'])) { $error = (string) $_SESSION['login_error']; unset($_SESSION['login_error']); }
if (!empty($_SESSION['login_notice'])) { $notice = (string) $_SESSION['login_notice']; unset($_SESSION['login_notice']); }

// ── Connexion / déconnexion ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'logout') {
        unset($_SESSION['is_admin'], $_SESSION['admin_account_id']);
        session_regenerate_id(true);
        header('Location: /caisse.php');
        exit;
    }
    if ($postAction === 'login') {
        $login = admin_login((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($login) {
            session_regenerate_id(true);
            $_SESSION['is_admin'] = tenant_slug();
            if ($login['id'] === null) {
                unset($_SESSION['admin_account_id']);
            } else {
                $_SESSION['admin_account_id'] = $login['id'];
            }
            if (!empty($_POST['remember'])) {
                // Cookie de session prolongé : le téléphone reste connecté (le fichier de session, lui, est conservé par session.php).
                $params = session_get_cookie_params();
                setcookie(session_name(), session_id(), [
                    'expires' => time() + STUDIO_REMEMBER_DAYS * 86400,
                    'path' => $params['path'] ?: '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            header('Location: /caisse.php');
            exit;
        }
        sleep(1); // freine les essais en série
        $error = 'Identifiant, nom ou e-mail incorrect.';
    }
}

$account = admin_session();
$isAdmin = $account !== null && $account['role'] === 'admin';
$canSell = $account !== null && in_array($account['role'], ['admin', 'vendeur'], true);   // le gérant et les vendeurs de la caisse
$ico = static fn (string $d): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$siteName = trim((string) $content['site_name']) ?: (string) tenant('name');
$appName = shop_app_name('vente');
$base = tenant_base_path();
// Adresse de l'application et texte partagé (réseaux, WhatsApp, SMS, e-mail, code QR).
[$studioUrl, $reachable] = studio_public_url();
$appUrl = str_replace('/studio.php', '/caisse.php', $studioUrl);
$shareAttrs = ' data-share-url="' . h($appUrl) . '" data-share-title="' . h($appName) . '" data-share-text="' . h("$appName : la caisse de la boutique, pour encaisser une vente, scanner un billet de retrait et valider les commandes. Ouvrez le lien, connectez-vous, puis « Ajouter à l'écran d'accueil ».") . '" data-share-dialog="1"';
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4eee1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h($appName) ?>">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="<?= h($base) ?>/vente-manifest.php">
<link rel="apple-touch-icon" href="<?= h(shop_app_icon_url('vente', 180, $base)) ?>">
<script src="/assets/pwa-save.js?v=<?= (int) @filemtime(__DIR__ . '/assets/pwa-save.js') ?>" data-auto data-delay="2000" data-key="studio-vente" data-name="<?= h($appName) ?>" data-icon="<?= h(shop_app_icon_url('vente', 192, $base)) ?>" data-manifest="<?= h($base) ?>/vente-manifest.php" data-sw="<?= h($base) ?>/pwa-sw.php" data-scope="<?= h($base . '/') ?>"></script>
<title><?= h($appName) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/studio.css">
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/login.css">
<link rel="stylesheet" href="/assets/barcode-scan.css">
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/assets/share.css') ?>">
<style>.studio .st-main { padding-bottom: calc(24px + env(safe-area-inset-bottom, 0px)); }</style>
</head>
<body class="studio">
<?php if (!$account): ?>
  <main class="st-login">
    <img class="st-logo" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>">
    <p class="eyebrow">Vente</p>
    <h1>Votre caisse, depuis la poche</h1>
    <p class="lede">Encaissez une vente, scannez un billet de retrait et validez les commandes en ligne.</p>
    <?php if ($notice): ?><p class="st-notice" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="st-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if (!admin_password_configured()): ?><p class="st-error" role="alert">Aucun compte n'est défini pour ce commerce.</p><?php endif; ?>
    <?= login_form_html([
      'id' => 'caisse-login',
      'identifier_name' => 'username',
      'social' => oauth_buttons_html('admin', ['next' => '/caisse.php']),
      'forgot_url' => '/admin/mot-de-passe-oublie.php?from=studio',
      'submit' => 'Se connecter',
      'remember' => true,
      'remember_name' => 'remember',
      'remember_label' => 'Rester connecté sur ce téléphone (30 jours)',
      'remember_checked' => true,
      'hidden' => ['action' => 'login'],
    ]) ?>
    <p class="st-share-row"><button type="button" class="btn btn-ghost"<?= $shareAttrs ?> data-share-open="share">Partager l'application</button></p>
  </main>
<?php elseif (!$canSell): ?>
  <main class="st-login">
    <p class="eyebrow">Vente</p>
    <h1>Accès réservé</h1>
    <p class="lede">L'application Vente encaisse les ventes et valide les commandes : elle est réservée aux administrateurs et aux vendeurs de la boutique. Vous êtes connecté·e en tant que « <?= h(account_role_label($account['role'])) ?> ».</p>
    <form method="post" class="st-form"><input type="hidden" name="action" value="logout"><button class="btn btn-ghost" type="submit">Changer de compte</button></form>
  </main>
<?php else: ?>
  <header class="st-top">
    <img class="st-top-logo" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>">
    <button type="button" class="st-share" data-share-open="share"<?= $shareAttrs ?> aria-label="Partager l'application" title="Partager l'application"><?= $ico('<circle cx="18" cy="5" r="2.6"/><circle cx="6" cy="12" r="2.6"/><circle cx="18" cy="19" r="2.6"/><path d="m8.3 10.8 7.4-4.3M8.3 13.2l7.4 4.3"/>') ?></button>
    <details class="st-menu">
      <summary aria-label="Menu"><?= $ico('<circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/>') ?></summary>
      <div class="st-menu-box">
        <p class="qa-note"><?= h($account['name'] ?? '') ?: 'Connecté' ?></p>
        <button type="button" class="btn btn-ghost" id="st-install" hidden>Installer l'application</button>
        <button type="button" class="btn btn-ghost" data-share-open="share"<?= $shareAttrs ?>>Partager l'application</button>
        <button type="button" class="btn btn-ghost" data-share-open="qr"<?= $shareAttrs ?>>Code QR de l'application</button>
        <?php if (!$reachable): ?><p class="qa-note">Adresse locale : un téléphone ne peut pas l'ouvrir. En ligne, le lien fonctionne.</p><?php endif; ?>
<?php if ($isAdmin): ?>
        <a class="btn btn-ghost" href="<?= h($base) ?>/studio.php">Studio (photos)</a>
        <a class="btn btn-ghost" href="/admin/dashboard.php">Administration</a>
<?php endif; ?>
        <form method="post"><input type="hidden" name="action" value="logout"><button class="btn btn-ghost" type="submit">Se déconnecter</button></form>
      </div>
    </details>
  </header>

  <main class="st-main">
    <div class="pos" id="pos"></div>
  </main>

  <script src="/assets/barcode-scan.js"></script>
  <script src="/assets/vendor/qrcode-generator.js"></script>
  <script src="/assets/pos.js?v=<?= (int) @filemtime(__DIR__ . '/assets/pos.js') ?>" data-action="/admin/pos-action.php"<?= sumup_terminal_configured() ? ' data-terminal="1"' : '' ?>></script>
  <script>
  // Bouton « Installer l'application » : PwaSave donne la marche à suivre du navigateur (Safari, Chrome, Samsung…).
  (function () {
    var b = document.getElementById('st-install');
    var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    if (!b || !window.PwaSave || standalone) return;
    b.hidden = false;
    b.addEventListener('click', function () { window.PwaSave.open(); });
  })();
  </script>
<?php endif; ?>
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/assets/share.js') ?>" defer></script>
<script src="/assets/pwd-toggle.js" defer></script>
</body>
</html>
