<?php
// Réglage « Proposer d'installer la boutique comme web app » (Administration → Réglages du site).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    db(); // charge includes/recto-verso.php (réglages)
    shop_setting_set('pwa_prompt', !empty($_POST['pwa_prompt']) ? '1' : '0');
    flash_set(!empty($_POST['pwa_prompt']) ? 'La boutique propose maintenant aux visiteurs de l\'installer sur leur écran d\'accueil.' : 'L\'incitation à installer la boutique est désactivée.');
}
header('Location: /admin/index.php#pwa-settings');
exit;
