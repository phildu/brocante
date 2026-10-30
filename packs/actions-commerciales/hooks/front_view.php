<?php
// À chaque visite du site : démarre ou termine les opérations arrivées à échéance (au plus une vérification toutes les 20 s).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';
if (aco_enabled()) aco_tick();
