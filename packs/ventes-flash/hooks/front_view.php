<?php
// Chaque visite fait avancer les ventes flash en cours (au plus toutes les 20 secondes) : aucune tâche planifiée à installer.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/vf.php';
vf_tick();
