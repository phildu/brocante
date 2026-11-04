<?php
// Index JSON du centre d'aide, consommé par le panneau contextuel (assets/aide-widget.js).
// Aucune donnée secrète : uniquement les fiches publiques.
require_once __DIR__ . '/_base.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: public, max-age=300');
echo aide_index_json();
