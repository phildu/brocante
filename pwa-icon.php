<?php
// Icône de la web app de la boutique : son logo carré (par défaut) ou un pictogramme sur la couleur d'accent
// (Administration → Réglages du site → Boutique installable). Le dessin est dans includes/app-icons.php.
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/apps.php';

shop_app_icon_serve('shop');
