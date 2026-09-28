<?php
// Charge les données de démonstration du commerce actif dans sa base. En CLI :
//   php seed.php                  (commerce actif : TENANT, .tenant ou petit-chalet)
//   TENANT=mon-commerce php seed.php
// Crée les tables manquantes (schema.sql) puis vide et recrée le contenu et
// les produits — peut être relancé sans risque.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/seed.php';

$count = seed_tenant(tenant());
echo "« " . tenant('name') . " » : contenu et $count produits importés dans " . DB_PATH . "\n";
