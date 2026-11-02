<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth.php';
require_once __DIR__ . '/includes/customers.php';
require_once __DIR__ . '/includes/legal.php';
require_once __DIR__ . '/includes/login-ui.php';
require_once __DIR__ . '/includes/login-otp.php';

// Compte client : e-mail + mot de passe (création confirmée par un lien envoyé à l'adresse), ou connexion par un réseau social (Google, Facebook…) ;
// puis suivi des commandes payées avec la même adresse e-mail, téléphone, mot de passe, suppression du compte.
if (empty($_SESSION['customer_csrf'])) $_SESSION['customer_csrf'] = bin2hex(random_bytes(16));
db();

/** Retour sur la page du compte (ou du panier) avec un message, après chaque envoi de formulaire. */
function compte_back(string $message = '', bool $error = false, string $to = '/compte.php'): never
{
    if ($message !== '') $_SESSION[$error ? 'customer_error' : 'customer_notice'] = $message;
    header('Location: ' . $to, true, 303);
    exit;
}

$customer = customer_session();
$account = $customer ? customer_account($customer['email']) : null;
$next = (($_GET['next'] ?? $_POST['next'] ?? '') === 'cart') ? '/cart.php' : '/compte.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['customer_csrf'], (string) ($_POST['csrf'] ?? ''))) compte_back('La page avait expiré : recommencez.', true);
    $action = (string) ($_POST['action'] ?? '');
    $accepted = !empty($_POST['accept_terms']);
    switch ($action) {
        case 'logout':
            unset($_SESSION['customer']);
            compte_back();
        case 'login':
            [$a, $err] = customer_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            if ($err) compte_back($err, true, '/compte.php' . ($next === '/cart.php' ? '?next=cart' : ''));
            customer_login_session($a);
            compte_back('', false, $next);
        case 'register':
            if (!empty($_POST['website'])) compte_back('Un e-mail avec un lien vient de vous être envoyé.');       // champ piège
            if (!$accepted) compte_back("Pour créer un compte, acceptez les conditions d'utilisation et la politique de confidentialité.", true, '/compte.php?creer=1');
            $phone = customer_phone_normalize((string) ($_POST['phone'] ?? ''));
            if ($phone === null) compte_back('Indiquez un numéro de téléphone valide (par exemple 06 12 34 56 78).', true, '/compte.php?creer=1');
            [$msg, $err] = customer_send_link((string) ($_POST['email'] ?? ''), (string) ($_POST['name'] ?? ''), $phone, 'setup');
            compte_back($err ?? $msg, (bool) $err, $err ? '/compte.php?creer=1' : '/compte.php');
        case 'forgot':
            $login = (string) ($_POST['email'] ?? '');
            if (($_POST['method'] ?? 'email') === 'sms') {
                $res = login_otp_request('customer', $login);
                if ($res['sent']) {
                    $_SESSION['customer_otp'] = ['login' => $login, 'phone' => $res['phone']];
                    compte_back('Un code à 6 chiffres vient d\'être envoyé par SMS au ' . $res['phone'] . '.', false, '/compte.php?oubli=1&otp=1' . ($next === '/cart.php' ? '&next=cart' : ''));
                }
                compte_back($res['error'], true, '/compte.php?oubli=1' . ($next === '/cart.php' ? '&next=cart' : ''));
            }
            [$msg, $err] = customer_request_reset($login);
            compte_back($err ?? $msg, (bool) $err, $err ? '/compte.php?oubli=1' : '/compte.php');
        case 'otp':
            $otp = $_SESSION['customer_otp'] ?? null;
            if (!is_array($otp) || empty($otp['login'])) compte_back('Demandez d\'abord un code par SMS.', true, '/compte.php?oubli=1');
            $err = login_otp_apply('customer', (string) $otp['login'], (string) ($_POST['code'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            if ($err) compte_back($err, true, '/compte.php?oubli=1&otp=1' . ($next === '/cart.php' ? '&next=cart' : ''));
            unset($_SESSION['customer_otp']);
            $a = customer_account_by_login((string) $otp['login']);
            if ($a) customer_login_session($a);
            compte_back('Votre mot de passe est enregistré : vous êtes connecté·e.', false, $next);
        case 'setpw':
            $err = customer_token_use((string) ($_POST['t'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            if ($err) compte_back($err, true, '/compte.php?a=lien&t=' . rawurlencode((string) ($_POST['t'] ?? '')));
            compte_back('Votre mot de passe est enregistré : vous êtes connecté·e.', false, $next);
        case 'profile':
            if (!$account) compte_back('Connectez-vous.', true);
            $err = customer_update_profile((int) $account['id'], (string) ($_POST['name'] ?? ''), (string) ($_POST['phone'] ?? ''));
            compte_back($err ?? 'Vos informations sont enregistrées.', (bool) $err);
        case 'password':
            if (!$account) compte_back('Connectez-vous.', true);
            $err = customer_change_password($account, (string) ($_POST['current'] ?? ''), (string) ($_POST['new'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            compte_back($err ?? 'Votre mot de passe est modifié.', (bool) $err);
        case 'delete':
            if (!$account) compte_back('Connectez-vous.', true);
            if (empty($_POST['sure'])) compte_back('Cochez la case pour confirmer la suppression.', true);
            customer_delete_account($account);
            unset($_SESSION['customer']);
            compte_back('Votre compte est supprimé. Vos commandes passées restent conservées pour notre comptabilité.');
    }
    compte_back();
}

$error = (string) ($_SESSION['customer_error'] ?? '');
$notice = (string) ($_SESSION['customer_notice'] ?? '');
unset($_SESSION['customer_error'], $_SESSION['customer_notice']);
$view = 'login';
$tokenRow = null;
if (($_GET['a'] ?? '') === 'lien') {
    $tokenRow = customer_token_find((string) ($_GET['t'] ?? ''));
    $view = $tokenRow ? 'setpw' : 'login';
    if (!$tokenRow) $error = $error ?: 'Ce lien n\'est plus valable : demandez-en un nouveau (« Mot de passe oublié » ou « Créer mon compte »).';
} elseif (isset($_GET['creer'])) $view = 'register';
elseif (isset($_GET['oubli'])) $view = (!empty($_GET['otp']) && !empty($_SESSION['customer_otp'])) ? 'otp' : 'forgot';
$orders = $customer ? customer_orders($customer['email']) : [];
$csrf = (string) $_SESSION['customer_csrf'];
$nextField = $next === '/cart.php' ? '<input type="hidden" name="next" value="cart">' : '';

$activeNav = 'compte';
$pageTitle = 'Mon compte';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/oauth.css">
<link rel="stylesheet" href="/assets/login.css">
<style>
  .cpt-card { max-width: 440px; display: grid; gap: 14px; margin-top: 18px; }
  .cpt-card label { display: block; font-family: var(--font-mono); font-size: 0.7rem; color: var(--ink-soft); }
  .cpt-card input[type=text], .cpt-card input[type=email], .cpt-card input[type=tel], .cpt-card input[type=password] { display: block; width: 100%; margin-top: 6px; min-height: 44px; background: var(--bg); border: 1px solid var(--line); color: var(--ink); padding: 8px 10px; font: inherit; font-family: var(--font-body); font-size: 1rem; }
  .cpt-card small { display: block; margin-top: 4px; font-family: var(--font-body); font-size: .78rem; }
  .login-unified { max-width: 440px; margin-inline: 0; }
  .cpt-check { display: flex; gap: 10px; align-items: flex-start; font-family: var(--font-body) !important; font-size: .88rem !important; color: var(--ink) !important; line-height: 1.45; }
  .cpt-check input { width: 22px; height: 22px; flex: none; margin-top: 1px; }
  .cpt-links { display: flex; gap: 6px 18px; flex-wrap: wrap; font-size: .9rem; margin-top: 4px; } .cpt-links a { display: inline-block; padding: 8px 0; }
  .cpt-sec { margin-top: 34px; padding-top: 22px; border-top: 1px solid var(--line); }
  .cpt-hp { position: absolute; left: -9999px; }
</style>
<section class="tight">
  <div class="wrap" style="max-width:720px;">
    <p class="eyebrow">Mon compte</p>
    <?php if ($notice): ?><p class="publish-status" data-kind="ok" style="margin:18px 0;" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="publish-status" data-kind="error" style="margin:18px 0;" role="alert"><?= h($error) ?></p><?php endif; ?>

    <?php if ($customer): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Bonjour<?= $customer['name'] !== '' ? ' ' . h($customer['name']) : '' ?></h1>
      <p class="lede">Connecté·e<?= $customer['provider'] !== 'password' ? ' avec ' . h(OAUTH_PROVIDERS[$customer['provider']]['label'] ?? $customer['provider']) : '' ?> (<?= h($customer['email']) ?>).</p>
      <form method="post" style="margin:14px 0 30px;">
        <input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="btn btn-ghost" type="submit">Se déconnecter</button>
      </form>

      <h2 style="font-size:1.3rem;margin-bottom:14px;">Mes commandes</h2>
      <?php if (!$orders): ?>
        <p class="empty-state">Aucune commande avec cette adresse pour l'instant. <a href="/boutique.php" style="color:var(--accent);">Voir la boutique →</a></p>
      <?php else: ?>
        <div class="admin-block">
          <?php foreach ($orders as $o): ?>
            <div style="padding:14px 0;border-bottom:1px solid var(--line);">
              <p class="ref"><?= h(date('d/m/Y', strtotime($o['created_at']))) ?> — <?= format_cents((int) $o['amount_total']) ?>
                <span class="badge" style="margin-left:6px;"><?= h(fulfillment_label($o['fulfillment_status'])) ?></span></p>
              <p style="margin:6px 0;">
                <?php foreach (json_decode((string) $o['items'], true) ?: [] as $item): ?>
                  <?= h($item['qty']) ?> × <?= h($item['name']) ?><br>
                <?php endforeach; ?>
              </p>
              <?php if (!empty($o['shipping_address'])): ?><p style="font-size:0.82rem;color:var(--ink-soft);">📦 <?= h($o['shipping_address']) ?></p><?php endif; ?>
              <?php if (!empty($o['tracking_number'])): ?><p style="font-size:0.82rem;color:var(--ink-soft);">N° de suivi : <?= h($o['tracking_number']) ?></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($account): ?>
        <div class="cpt-sec">
          <h2 style="font-size:1.3rem;">Mes informations</h2>
          <form method="post" class="cpt-card">
            <input type="hidden" name="action" value="profile"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Nom et prénom<input type="text" name="name" value="<?= h($account['name']) ?>" maxlength="80" autocomplete="name"></label>
            <label>Téléphone<input type="tel" name="phone" value="<?= h(customer_phone_display((string) $account['phone'])) ?>" maxlength="30" autocomplete="tel" inputmode="tel" placeholder="06 12 34 56 78"><small>Pour vous joindre à propos d'une livraison.</small></label>
            <label>E-mail<input type="email" value="<?= h($account['email']) ?>" readonly><small>Pour changer d'adresse, créez un compte avec la nouvelle.</small></label>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
          </form>
        </div>
        <?php if (!empty($account['password_hash'])): ?>
        <div class="cpt-sec">
          <h2 style="font-size:1.3rem;">Mot de passe</h2>
          <form method="post" class="cpt-card">
            <input type="hidden" name="action" value="password"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Mot de passe actuel<input type="password" name="current" required autocomplete="current-password"></label>
            <label>Nouveau mot de passe<input type="password" name="new" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password"><small><?= ACCOUNT_PASSWORD_MIN ?> caractères au moins.</small></label>
            <label>Confirmer<input type="password" name="confirm" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password"></label>
            <button class="btn" type="submit">Changer mon mot de passe</button>
          </form>
        </div>
        <?php endif; ?>
        <div class="cpt-sec">
          <h2 style="font-size:1.3rem;">Supprimer mon compte</h2>
          <p class="hint">Votre accès et vos coordonnées sont effacés. Vos commandes passées restent conservées pour notre comptabilité (<a href="<?= h(legal_url('confidentialite')) ?>">confidentialité</a>).</p>
          <form method="post" class="cpt-card" onsubmit="return confirm('Supprimer définitivement mon compte ?');">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label class="cpt-check"><input type="checkbox" name="sure" value="1" required> <span>Je confirme vouloir supprimer mon compte.</span></label>
            <button class="btn" type="submit">Supprimer mon compte</button>
          </form>
        </div>
      <?php endif; ?>

    <?php elseif ($view === 'setpw' && $tokenRow): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;"><?= $tokenRow['kind'] === 'reset' ? 'Nouveau mot de passe' : 'Choisissez votre mot de passe' ?></h1>
      <p class="lede"><?= $tokenRow['kind'] === 'reset' ? 'Pour' : 'Pour finir la création de votre compte avec' ?> <strong><?= h($tokenRow['email']) ?></strong>.</p>
      <form method="post" class="cpt-card">
        <input type="hidden" name="action" value="setpw"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="t" value="<?= h((string) ($_GET['t'] ?? '')) ?>"><?= $nextField ?>
        <label>Mot de passe<input type="password" name="password" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password" autofocus><small><?= ACCOUNT_PASSWORD_MIN ?> caractères au moins.</small></label>
        <label>Confirmer le mot de passe<input type="password" name="confirm" required minlength="<?= ACCOUNT_PASSWORD_MIN ?>" autocomplete="new-password"></label>
        <button class="btn btn-primary" type="submit">Enregistrer et me connecter</button>
      </form>

    <?php elseif ($view === 'register'): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Créer mon compte</h1>
      <p class="lede">Retrouvez vos commandes et commandez plus vite. Nous vous envoyons un e-mail pour confirmer votre adresse et choisir votre mot de passe.</p>
      <form method="post" class="cpt-card" id="register-form">
        <input type="hidden" name="action" value="register"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><?= $nextField ?>
        <input type="text" name="website" class="cpt-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
        <label>Nom et prénom<input type="text" name="name" maxlength="80" autocomplete="name" placeholder="Marie Dupont"></label>
        <label>E-mail <span style="color:#b3261e;">*</span><input type="email" name="email" required maxlength="120" autocomplete="email" inputmode="email"></label>
        <label>Téléphone <span style="color:#b3261e;">*</span><input type="tel" name="phone" required maxlength="30" autocomplete="tel" inputmode="tel" placeholder="06 12 34 56 78"><small>Pour vous joindre à propos d'une livraison.</small></label>
        <label class="cpt-check"><input type="checkbox" name="accept_terms" value="1" required> <span>J'accepte les <a href="<?= h(legal_url('cgu')) ?>" target="_blank" rel="noopener">conditions d'utilisation</a> et la <a href="<?= h(legal_url('confidentialite')) ?>" target="_blank" rel="noopener">politique de confidentialité</a>.</span></label>
        <button class="btn btn-primary" type="submit">Recevoir le lien de confirmation</button>
        <?php if ($social = oauth_buttons_html('customer', ['next' => $next], false)): ?>
          <p class="oauth-note" style="margin-top:16px;">…ou créez votre compte avec un réseau social :</p>
          <?= $social ?>
        <?php endif; ?>
        <p class="cpt-links"><a href="/compte.php<?= $next === '/cart.php' ? '?next=cart' : '' ?>">J'ai déjà un compte</a></p>
      </form>
      <?php if ($social): ?>
      <script>
      (function () {   // Un compte créé par réseau social engage aussi les conditions d'utilisation.
        var form = document.getElementById('register-form'), terms = form && form.querySelector('input[name="accept_terms"]');
        if (!form || !terms) return;
        var buttons = form.querySelectorAll('.oauth-btn');
        function sync() { buttons.forEach(function (b) { b.disabled = !terms.checked; b.title = terms.checked ? '' : "Cochez d'abord la case des conditions."; }); }
        terms.addEventListener('change', sync); sync();
      })();
      </script>
      <?php endif; ?>

    <?php elseif ($view === 'forgot'): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Mot de passe oublié</h1>
      <p class="lede">Indiquez votre identifiant, votre nom ou votre e-mail : recevez un lien par e-mail ou un code par SMS.</p>
      <?= login_forgot_form_html([
        'id' => 'customer-forgot',
        'identifier_name' => 'email',
        'identifier_label' => 'Identifiant, nom ou e-mail',
        'hidden' => ['action' => 'forgot', 'csrf' => $csrf, 'next' => $next === '/cart.php' ? 'cart' : ''],
        'sms' => login_otp_sms_available(),
        'note' => 'Le code par SMS n\'est proposé que si un numéro est enregistré sur votre compte.',
      ]) ?>
      <p class="cpt-links"><a href="/compte.php<?= $next === '/cart.php' ? '?next=cart' : '' ?>">Retour à la connexion</a></p>

    <?php elseif ($view === 'otp' && !empty($_SESSION['customer_otp'])): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Code par SMS</h1>
      <p class="lede">Saisissez le code reçu au <?= h((string) $_SESSION['customer_otp']['phone']) ?> et choisissez un nouveau mot de passe.</p>
      <?= login_otp_form_html([
        'id' => 'customer-otp',
        'identifier_name' => 'email',
        'identifier_value' => (string) $_SESSION['customer_otp']['login'],
        'hidden' => ['action' => 'otp', 'csrf' => $csrf, 'next' => $next === '/cart.php' ? 'cart' : ''],
        'minlength' => ACCOUNT_PASSWORD_MIN,
      ]) ?>
      <p class="cpt-links"><a href="/compte.php?oubli=1<?= $next === '/cart.php' ? '&amp;next=cart' : '' ?>">Recevoir un nouveau code ou un lien e-mail</a></p>

    <?php else: ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Connexion</h1>
      <p class="lede">Connectez-vous pour retrouver vos commandes et commander plus vite.</p>
      <?= login_form_html([
        'id' => 'customer-login',
        'identifier_name' => 'email',
        'social' => oauth_buttons_html('customer', ['next' => $next]),
        'forgot_url' => '/compte.php?oubli=1' . ($next === '/cart.php' ? '&next=cart' : ''),
        'submit' => 'Me connecter',
        'hidden' => ['action' => 'login', 'csrf' => $csrf, 'next' => $next === '/cart.php' ? 'cart' : ''],
        'links' => [
          ['url' => '/compte.php?creer=1' . ($next === '/cart.php' ? '&next=cart' : ''), 'label' => 'Créer mon compte'],
          ['url' => '/connexion.php', 'label' => 'Connexion unique (boutique, galerie, portail)'],
          ['url' => '/admin/login.php', 'label' => 'Connexion à l’administration'],
        ],
        'note' => 'Avec un réseau social, nous ne recevons que votre nom et votre adresse e-mail.',
      ]) ?>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
