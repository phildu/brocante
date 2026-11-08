<?php

// /robots.txt (réécrit vers ce fichier par le .htaccess). Fermé aux robots par défaut : voir includes/indexation.php.
require_once __DIR__ . '/includes/app-env.php';

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-cache');
echo app_robots_txt(app_indexation_open());
