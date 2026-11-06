<?php
defined('PLUGIN_RUNTIME') || exit;
// Fiche du module : panneau d'activation (essai, abonnement) puis accès au module. Voir includes/shop-modules.php.
require_once dirname(__DIR__, 3) . '/includes/shop-modules.php';
shop_module_page($plugin, $pluginApi, $pluginCsrf);
