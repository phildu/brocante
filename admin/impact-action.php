<?php
// Option « afficher le bilan CO₂ et eau sur l'accueil de la boutique » (onglet Impact du tableau de bord).
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/impact.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/dashboard.php?s=impact');
    exit;
}
shop_require_csrf();

$on = !empty($_POST['impact_public']);
impact_setting_save('impact_public', $on ? '1' : '0');
impact_setting_save('impact_public_cache', '');   // les chiffres affichés repartent d'un calcul frais
flash_set($on ? "Le bilan s'affiche maintenant sur l'accueil de la boutique (dès qu'une pièce est vendue)." : "Le bilan n'est plus affiché sur l'accueil.");
header('Location: /admin/dashboard.php?s=impact');
exit;
