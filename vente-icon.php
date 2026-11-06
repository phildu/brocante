<?php
// Icône de l'application Vente (caisse) : pictogramme (caisse) sur la couleur d'accent du commerce, ou son logo
// (Administration → Réglages du site → Boutique installable). Le dessin est dans includes/app-icons.php.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apps.php';

shop_app_icon_serve('vente');
