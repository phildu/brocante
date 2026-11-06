<?php
// Le classement du menu latéral est commun aux boutiques : il se règle dans le
// portail (portail/menu.php). Cette adresse ne modifie plus rien.
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

flash_set('Le menu latéral est commun à toutes les boutiques : il se règle dans le portail des commerces.');
header('Location: ' . app_prefix('/admin/menu.php'));
