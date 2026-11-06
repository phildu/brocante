<?php
// Réglages des web apps (Administration → Réglages du site) : incitation à installer la boutique et noms affichés sous les icônes.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/apps.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    db(); // charge includes/recto-verso.php (réglages)
    $prompt = !empty($_POST['pwa_prompt']);
    shop_setting_set('pwa_prompt', $prompt ? '1' : '0');
    foreach (['shop', 'article', 'vente'] as $app) {
        shop_setting_set('app_name_' . $app, app_name_clean((string) ($_POST['app_name_' . $app] ?? ''), 40));
        shop_setting_set('app_icon_' . $app, app_icon_mode_clean((string) ($_POST['app_icon_' . $app] ?? ''), $app));
    }
    flash_set(($prompt
        ? 'La boutique propose maintenant aux visiteurs de l\'installer sur leur écran d\'accueil.'
        : 'L\'incitation à installer la boutique est désactivée.') . ' Noms et icônes des applications enregistrés.');
}
header('Location: /admin/index.php#pwa-settings');
exit;
