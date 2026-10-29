<?php
// Chaque visite peut déclencher la vérification des alertes (au plus toutes les 5 minutes) : pas de tâche planifiée à installer.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ac.php';
if (addon_is_entitled(AC_MODULE)) ac_scan();
