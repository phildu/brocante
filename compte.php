<?php
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/oauth.php';

// Compte client : connexion par un réseau social (Google, Facebook…), puis suivi des commandes payées avec la même adresse e-mail.
if (empty($_SESSION['customer_csrf'])) $_SESSION['customer_csrf'] = bin2hex(random_bytes(16));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout'
    && hash_equals((string) $_SESSION['customer_csrf'], (string) ($_POST['csrf'] ?? ''))) {
    unset($_SESSION['customer']);
    header('Location: /compte.php', true, 303);
    exit;
}

$customer = customer_session();
$error = (string) ($_SESSION['customer_error'] ?? '');
unset($_SESSION['customer_error']);
$orders = $customer ? customer_orders($customer['email']) : [];

$activeNav = 'compte';
$pageTitle = 'Mon compte';
include __DIR__ . '/includes/header.php';
?>
<link rel="stylesheet" href="/assets/oauth.css">
<section class="tight">
  <div class="wrap" style="max-width:720px;">
    <p class="eyebrow">Mon compte</p>
    <?php if ($customer): ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Bonjour<?= $customer['name'] !== '' ? ' ' . h($customer['name']) : '' ?></h1>
      <p class="lede">Connecté avec <?= h(OAUTH_PROVIDERS[$customer['provider']]['label'] ?? $customer['provider']) ?> (<?= h($customer['email']) ?>).</p>
      <form method="post" style="margin:14px 0 30px;">
        <input type="hidden" name="action" value="logout"><input type="hidden" name="csrf" value="<?= h($_SESSION['customer_csrf']) ?>">
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
    <?php else: ?>
      <h1 style="font-size:2rem;margin:12px 0 8px;">Connexion</h1>
      <p class="lede">Connectez-vous pour retrouver vos commandes et commander plus vite.</p>
      <?php if ($error): ?><p class="publish-status" data-kind="error" style="margin:18px 0;"><?= h($error) ?></p><?php endif; ?>
      <div style="max-width:380px;margin-top:22px;">
        <?php if ($buttons = oauth_buttons_html('customer', ['next' => '/compte.php'])): ?>
          <?= $buttons ?>
          <p class="oauth-note">Nous ne recevons de votre compte que votre nom et votre adresse e-mail.</p>
        <?php else: ?>
          <p class="empty-state">La connexion par compte n'est pas disponible pour le moment.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
