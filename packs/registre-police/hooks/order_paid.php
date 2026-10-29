<?php
// Commande payée : la sortie des pièces vendues est inscrite tout de suite.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/pr.php';
pr_sync(true);
