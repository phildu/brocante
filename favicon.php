<?php

// /favicon.ico (réécrit vers ce fichier par le .htaccess) : icône d'onglet des pages qui n'en déclarent pas.
// Sur l'adresse d'un commerce : son logo carré, s'il en a un ; sinon l'icône du portail (Portail → Logo & favicon).
require_once __DIR__ . '/includes/tenant.php';
require_once __DIR__ . '/includes/saas.php';

$path = '';
try {
    if (tenant_from_host() !== '' || tenant_from_domain() !== '') {
        require_once __DIR__ . '/includes/appearance.php';
        $path = logo_url('square');
    }
} catch (Throwable $e) {
    $path = '';
}
if ($path === '') {
    $path = saas_brand_image(saas_config(), 'favicon');
}

header('Cache-Control: public, max-age=3600');
header('Location: /' . ltrim($path, '/'), true, 302);
