<?php
// Chaque visite peut mettre le registre à jour (au plus toutes les 2 minutes) : inscriptions des nouvelles pièces, sorties des pièces vendues.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/pr.php';
pr_sync();
