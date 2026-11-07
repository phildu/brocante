<?php
// Nom de domaine de la boutique : rechercher, vérifier le titulaire, payer et
// suivre la commande OVH — depuis l'administration de la boutique, avec le
// même compte Stripe de plateforme que le parcours d'inscription.
// Le contenu (cartes) est commun avec la galerie : includes/domain-panel.php.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/saas.php';
require_once __DIR__ . '/../includes/domain-panel.php';
require_admin();

$slug = tenant_slug();
$content = get_content();
$flash = flash_get();
$csrf = admin_csrf_token();

// Retour de Stripe : la session est relue chez Stripe, jamais crue sur parole.
if (isset($_GET['s'])) {
    [$message, $kind] = domain_panel_apply_return((string) $_GET['s']);
    flash_set($message, $kind);
    header('Location: /admin/domaine.php', true, 303);
    exit;
}

$identity = legal_identity();
$panel = domain_panel_context($slug, [
    'address' => tenant_site_url() . '/',
    'name' => (string) ($content['site_name'] ?? ''),
    'draft' => $slug,
    'identity' => $identity,
    'owner_email' => filter_var((string) ($identity['email'] ?? ''), FILTER_VALIDATE_EMAIL)
        ? (string) $identity['email']
        : trim((string) tenant('admin_email', '')),
    'action' => '/admin/domaine-action.php',
    'self_path' => '/admin/domaine.php',
    'csrf' => $csrf,
]);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nom de domaine — <?= h($content['site_name']) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
<?= dm_css() ?>
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'domaine'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Boutique</p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Nom de domaine</h1>
    <p class="hint">Achetez une adresse propre pour votre boutique (par exemple <code>ma-boutique.fr</code>) sans quitter son administration. La disponibilité et le prix viennent d’OVH ; le paiement est encaissé par la plateforme, puis la commande est transmise à OVH. Rien n’est commandé avant la confirmation du paiement.</p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin:16px 0;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

<?php domain_panel_render($panel); ?>
  </div>
</section>
</main>
</body>
</html>
