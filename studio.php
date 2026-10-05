<?php
// Studio : application smartphone du commerce (installable sur l'écran d'accueil).
// Prise de vue pièce par pièce ou en lot, génération des fiches par l'IA, relecture
// et publication — sans passer par l'administration sur ordinateur.
// Connexion propre à l'application (identifiant ou e-mail + mot de passe), mémorisable
// 30 jours sur le téléphone. Les envois passent par admin/quick-add-action.php.
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store');
$content = get_content();
$error = null;

// ── Connexion / déconnexion ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'logout') {
        unset($_SESSION['is_admin'], $_SESSION['admin_account_id']);
        session_regenerate_id(true);
        header('Location: /studio.php');
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
            header('Location: /studio.php');
            exit;
        }
        sleep(1); // freine les essais en série
        $error = 'Identifiant ou mot de passe incorrect.';
    }
}

$account = admin_session();
$isAdmin = $account !== null && $account['role'] === 'admin';
$aiReady = (bool) GEMINI_API_KEY;
$angles = [
    ['face', 'Face', "L'objet entier, de face, sur un fond simple", true],
    ['profil', 'Profil', 'De côté, pour montrer la forme et l’épaisseur', false],
    ['dos', 'Dos', 'L’arrière de la pièce', false],
    ['detail', 'Détail', 'Marque, signature, poinçon ou défaut', false],
    ['dessous', 'Dessous', 'Le dessous ou l’intérieur', false],
];
$qaOpts = ['action' => '/admin/quick-add-action.php', 'again' => '/studio.php', 'list' => '/studio.php#pieces', 'list_label' => 'Voir mes pièces', 'title' => false];
$ico = static fn (string $d): string => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$siteName = (string) $content['site_name'];
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4eee1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Studio">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="manifest" href="/studio-manifest.php">
<link rel="apple-touch-icon" href="/studio-icon.php?s=180">
<title>Studio — <?= h($siteName) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/studio.css">
</head>
<body class="studio">
<?php if (!$account): ?>
  <main class="st-login">
    <img class="st-logo" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>">
    <p class="eyebrow">Studio</p>
    <h1>Votre shooting, depuis la poche</h1>
    <p class="lede">Photographiez vos pièces, l'application prépare les fiches pour la boutique.</p>
    <?php if ($error): ?><p class="st-error" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?php if (!admin_password_configured()): ?><p class="st-error" role="alert">Aucun compte n'est défini pour ce commerce.</p><?php endif; ?>
    <form method="post" class="st-form">
      <input type="hidden" name="action" value="login">
      <label class="qa-field">Identifiant ou e-mail
        <input type="text" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required value="<?= h($_POST['username'] ?? '') ?>">
      </label>
      <label class="qa-field">Mot de passe
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <label class="qa-switch"><input type="checkbox" name="remember" value="1" checked> <span>Rester connecté sur ce téléphone<br><small class="qa-note">30 jours. Décochez sur un appareil partagé.</small></span></label>
      <button class="btn btn-primary" type="submit">Se connecter</button>
    </form>
  </main>
<?php elseif (!$isAdmin): ?>
  <main class="st-login">
    <p class="eyebrow">Studio</p>
    <h1>Accès réservé</h1>
    <p class="lede">Le Studio crée des fiches du catalogue : il est réservé aux administrateurs de la boutique. Vous êtes connecté·e en tant que « <?= h(account_role_label($account['role'])) ?> ».</p>
    <form method="post" class="st-form"><input type="hidden" name="action" value="logout"><button class="btn btn-ghost" type="submit">Changer de compte</button></form>
  </main>
<?php else: ?>
  <header class="st-top">
    <img class="st-top-logo" src="/<?= h(logo_url('horizontal')) ?>" alt="<?= h($siteName) ?>">
    <details class="st-menu">
      <summary aria-label="Menu"><?= $ico('<circle cx="12" cy="5" r="1.2"/><circle cx="12" cy="12" r="1.2"/><circle cx="12" cy="19" r="1.2"/>') ?></summary>
      <div class="st-menu-box">
        <p class="qa-note"><?= h($account['name'] ?? '') ?: 'Connecté' ?></p>
        <button type="button" class="btn btn-ghost" id="st-install" hidden>Installer l'application</button>
        <a class="btn btn-ghost" href="/admin/catalog.php">Administration</a>
        <form method="post"><input type="hidden" name="action" value="logout"><button class="btn btn-ghost" type="submit">Se déconnecter</button></form>
      </div>
    </details>
  </header>
  <aside class="st-hint" id="st-ios" hidden>
    <span>Pour garder le Studio sur l'écran d'accueil : touchez Partager, puis « Sur l'écran d'accueil ».</span>
    <button type="button" id="st-ios-close" aria-label="Fermer">Fermer</button>
  </aside>

  <main class="st-main">
    <!-- Une pièce -->
    <section class="st-tab" id="tab-single" data-tab="single">
      <?php include __DIR__ . '/includes/quick-add-flow.php'; ?>
      <div class="qa-bar">
        <button type="button" class="btn btn-primary" id="main-btn" disabled>Prenez au moins la photo de face</button>
      </div>
    </section>

    <!-- En lot -->
    <section class="st-tab" id="tab-batch" data-tab="batch" hidden>
      <div class="sb" id="sb">
        <!-- Prise de vue -->
        <div class="sb-screen" id="sb-shoot">
          <div class="sb-intro" id="sb-intro">
            <h1>Shooting en lot</h1>
            <p class="qa-note">Photographiez vos pièces à la suite : plusieurs vues d'une même pièce, puis « Pièce suivante ». Vous lancez ensuite la génération de toutes les fiches d'un coup, ou plus tard.</p>
            <button type="button" class="btn btn-primary" id="sb-start">Ouvrir l'appareil photo</button>
            <label class="sb-file" for="sb-gallery">ou choisir des photos dans la galerie</label>
            <input type="file" id="sb-gallery" accept="image/*" multiple hidden>
            <p class="qa-note" id="sb-resume" hidden></p>
          </div>
          <div class="sb-camera" id="sb-camera" hidden>
            <div class="sb-head"><b id="sb-piece-label">Pièce 1</b><span id="sb-total-label">0 photo</span></div>
            <div class="sb-view">
              <video id="sb-video" playsinline muted autoplay></video>
              <div class="sb-flash" id="sb-flash"></div>
            </div>
            <div class="sb-strip" id="sb-strip" aria-label="Photos de la pièce en cours"></div>
            <div class="sb-controls">
              <label class="sb-side" for="sb-gallery" aria-label="Ajouter depuis la galerie"><?= $ico('<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>') ?><span>Galerie</span></label>
              <button type="button" class="sb-shutter" id="sb-shutter" aria-label="Prendre la photo"><span></span></button>
              <button type="button" class="sb-side" id="sb-next" aria-label="Pièce suivante"><?= $ico('<path d="M5 12h14M13 6l6 6-6 6"/>') ?><span>Pièce suivante</span></button>
            </div>
            <button type="button" class="btn btn-primary sb-done" id="sb-done" disabled>Terminer le shooting</button>
          </div>
        </div>

        <!-- Relecture du lot -->
        <div class="sb-screen" id="sb-review" hidden>
          <h1>Votre lot</h1>
          <p class="qa-note" id="sb-summary"></p>
          <div class="sb-pieces" id="sb-pieces"></div>
          <label class="qa-field">Indications pour l'IA, valables pour tout le lot (facultatif)
            <textarea id="sb-notes" rows="2" maxlength="300" placeholder="Ex. : vaisselle des années 70, ambiance chaleureuse"></textarea>
          </label>
          <?php if (!$aiReady): ?><p class="qa-warn">Clé Gemini non configurée : les pièces seront enregistrées, sans détourage ni rédaction automatiques.</p><?php endif; ?>
          <div class="sb-actions">
            <button type="button" class="btn btn-primary" id="sb-generate">Générer les fiches</button>
            <button type="button" class="btn btn-ghost" id="sb-send-only">Envoyer sans traiter (plus tard)</button>
            <button type="button" class="btn btn-ghost" id="sb-back">Continuer le shooting</button>
          </div>
        </div>

        <!-- Envoi et traitement -->
        <div class="sb-screen" id="sb-run" hidden>
          <h1 id="sb-run-title">Traitement du lot</h1>
          <div class="sb-meter" aria-hidden="true"><span id="sb-meter"></span></div>
          <p class="qa-note" id="sb-run-note">Gardez l'écran allumé et cette page ouverte.</p>
          <ol class="sb-runlist" id="sb-runlist"></ol>
          <div class="sb-actions">
            <button type="button" class="btn btn-ghost" id="sb-stop">Arrêter après la pièce en cours</button>
            <button type="button" class="btn btn-primary" id="sb-retry" hidden>Réessayer les pièces en échec</button>
            <button type="button" class="btn btn-primary" id="sb-finish" hidden>Voir mes pièces</button>
            <button type="button" class="btn btn-ghost" id="sb-again" hidden>Nouveau lot</button>
          </div>
        </div>
      </div>
    </section>

    <!-- Mes pièces -->
    <section class="st-tab" id="tab-list" data-tab="pieces" hidden>
      <div class="sl">
        <div class="sl-head">
          <h1>Mes pièces</h1>
          <button type="button" class="btn btn-ghost sl-refresh" id="sl-refresh">Actualiser</button>
        </div>
        <div id="sl-bulk" hidden>
          <button type="button" class="btn btn-primary" id="sl-process-all">Traiter les pièces en attente</button>
          <p class="qa-note" id="sl-bulk-note"></p>
        </div>
        <div id="sl-groups"></div>
      </div>
    </section>
  </main>

  <!-- Relecture d'une pièce -->
  <dialog class="sr" id="sr">
    <form method="dialog" class="sr-close"><button aria-label="Fermer"><?= $ico('<path d="M6 6l12 12M18 6 6 18"/>') ?></button></form>
    <div class="sr-body">
      <p class="eyebrow" id="sr-ref"></p>
      <div class="qa-visuals" id="sr-photos"></div>
      <p class="qa-warn" id="sr-warn" hidden></p>
      <label class="qa-field">Nom<input type="text" id="sr-name" maxlength="120"></label>
      <div class="qa-two">
        <label class="qa-field">Prix<input type="text" id="sr-price" maxlength="30" inputmode="decimal" placeholder="25 €"></label>
        <label class="qa-field">Poids (g)<input type="number" id="sr-weight" min="0" step="10" inputmode="numeric" placeholder="500"></label>
      </div>
      <label class="qa-field">Catégorie
        <select id="sr-cat">
          <?php foreach (category_list() as $c): ?>
            <option value="<?= h($c['key']) ?>"><?= h($c['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="qa-field">Description<textarea id="sr-desc" rows="5" maxlength="1200"></textarea></label>
      <label class="qa-field">Étiquette<input type="text" id="sr-badge" maxlength="30" placeholder="Chiné, Rare, Coup de cœur…"></label>
      <label class="qa-switch"><input type="checkbox" id="sr-publish"> <span>Visible dans la boutique<br><small class="qa-note">Sinon la fiche reste masquée.</small></span></label>
      <p class="qa-warn" id="sr-error" role="alert" hidden></p>
      <div class="sr-actions">
        <button type="button" class="btn btn-primary" id="sr-save">Enregistrer</button>
        <button type="button" class="btn btn-ghost sr-discard" id="sr-discard">Jeter cette pièce</button>
      </div>
    </div>
  </dialog>

  <nav class="st-tabs" aria-label="Sections">
    <button type="button" data-go="single" aria-current="page"><?= $ico('<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/>') ?><span>Une pièce</span></button>
    <button type="button" data-go="batch"><?= $ico('<rect x="3" y="7" width="13" height="13" rx="1"/><path d="M8 7V4h13v13h-3"/>') ?><span>En lot</span></button>
    <button type="button" data-go="pieces"><?= $ico('<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>') ?><span>Mes pièces</span><i class="st-count" id="st-count" hidden></i></button>
  </nav>

  <script src="/assets/quick-add.js"></script>
  <script src="/assets/studio.js" data-action="/admin/quick-add-action.php" data-sw="/studio-sw.php"></script>
<?php endif; ?>
</body>
</html>
