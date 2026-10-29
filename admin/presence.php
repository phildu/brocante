<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_csrf_check();
    $mode = business_presence_mode($_POST['presence_mode'] ?? '', '');
    if (!isset(BUSINESS_PRESENCE_MODES[(string) ($_POST['presence_mode'] ?? '')])) {
        flash_set('Choisissez un mode valide : physique, en ligne ou les deux.', 'error');
    } else {
        shop_setting_set('presence_mode', $mode);
        flash_set('Mode d’activité enregistré pour cette boutique.');
    }
    header('Location: /admin/presence.php');
    exit;
}

$content = get_content();
$flash = flash_get();
$mode = shop_presence_mode();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mode d’activité — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'presence'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Identité du commerce</p>
    <h1 style="font-size:1.8rem;margin:12px 0;">Mode d’activité</h1>
    <?php if ($flash): ?><p class="publish-status" data-kind="<?= h($flash['kind']) ?>" role="status"><?= h($flash['message']) ?></p><?php endif; ?>
    <p class="hint">Indiquez si le commerce accueille le public sur place, exerce en ligne, ou les deux. Cette mention descriptive apparaît sur le site public ; elle ne change pas les ventes, les livraisons ni les horaires.</p>
    <form method="post" class="admin-block" style="max-width:620px;">
      <input type="hidden" name="csrf" value="<?= h(admin_csrf_token()) ?>">
      <label for="presence-mode">Mode d’activité</label>
      <select name="presence_mode" id="presence-mode">
        <?php foreach (BUSINESS_PRESENCE_MODES as $key => $label): ?><option value="<?= h($key) ?>"<?= $mode === $key ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
      </select>
      <p style="margin-top:14px;"><button type="submit" class="btn btn-primary">Enregistrer</button></p>
    </form>
  </div>
</section>
</main>
</body>
</html>
