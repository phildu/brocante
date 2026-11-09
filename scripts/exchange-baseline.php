<?php

/**
 * Garde-fou de l'échange prod / préprod (includes/exchange.php).
 *
 * Recense ce que le projet contient de « transférable » : tables SQL, clés de configuration d'une boutique (tenant.php), clés d'une galerie,
 * réglages nommés (table settings) et fichiers annexes d'une galerie. tests/ExchangeGuardTest.php compare ce recensement à
 * tests/fixtures/exchange-baseline.json : tout élément nouveau fait échouer la CI tant qu'on n'a pas DÉCIDÉ s'il voyage.
 *
 *   - il voyage       → l'ajouter à la constante correspondante de includes/exchange.php (EXCHANGE_SHOP_TABLES, EXCHANGE_SHOP_CONFIG_KEYS,
 *                       EXCHANGE_GALLERY_KEYS, EXCHANGE_SETTINGS_ALLOWED), puis régénérer la base de référence ;
 *   - il ne voyage pas → régénérer la base de référence (il est alors « revu, non transféré »).
 *
 *   php scripts/exchange-baseline.php            réécrit tests/fixtures/exchange-baseline.json
 *   php scripts/exchange-baseline.php --show     affiche le recensement sans rien écrire
 */

const EXCHANGE_BASELINE_FILE = __DIR__ . '/../tests/fixtures/exchange-baseline.json';

/** Recensement du projet : ['tables' => [...], 'gallery_keys' => [...], 'tenant_keys' => [...], 'settings' => [...], 'gallery_files' => [...]] (listes triées). */
function exchange_scan_project(string $root): array
{
    require_once $root . '/includes/galleries.php';
    $tables = $settings = $galleryFiles = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $rel = substr($file->getPathname(), strlen($root) + 1);
        if (!preg_match('/\.(php|sql)$/', $rel) || str_contains($rel, '.bak-')
            || preg_match('#^(vendor|tests|\.claude|\.git|data|backups|uploads|node_modules|var)/#', $rel)
            || in_array($rel, ['includes/exchange.php', 'scripts/exchange-baseline.php'], true)) {
            continue;
        }
        $code = (string) file_get_contents($file->getPathname());
        if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"\[]?([a-z_][a-z0-9_]*)/i', $code, $m)) {
            foreach ($m[1] as $name) {
                if (!in_array(strtoupper($name), ['IF', 'TABLE', 'AS'], true)) $tables[strtolower($name)] = true;
            }
        }
        // Réglages nommés : helpers setting_set('x', …) et INSERT … INTO settings (name, value) VALUES ('x', …).
        if (preg_match_all("/(?:setting_set|set_setting|save_setting|setting_save)\(\s*'([a-z0-9_:.-]+)'/", $code, $m)) {
            foreach ($m[1] as $name) $settings[$name] = true;
        }
        if (preg_match_all("/INTO\s+settings\s*\(\s*name\s*,\s*value\s*\)\s*VALUES\s*\(\s*'([a-z0-9_:.-]+)'/i", $code, $m)) {
            foreach ($m[1] as $name) $settings[$name] = true;
        }
        // Fichiers annexes d'une galerie : galleries_dir() . '/' . $slug . '.slots.json', et dossiers galleries_dir() . '/cni/'.
        if (preg_match_all("/galleries_dir\(\)\s*\.\s*'\/'\s*\.\s*\\\$slug\s*\.\s*'(\.[a-z0-9.-]+)'/", $code, $m)) {
            foreach ($m[1] as $suffix) $galleryFiles[$suffix] = true;
        }
        if (preg_match_all("/galleries_dir\(\)\s*\.\s*'\/([a-z0-9_-]+)\//", $code, $m)) {
            foreach ($m[1] as $dir) $galleryFiles['/' . $dir . '/'] = true;
        }
    }

    $out = [
        'tables' => array_keys($tables),
        'gallery_keys' => array_keys(gallery_normalize('x', [])),
        'tenant_keys' => array_keys(tenant_defaults('x')),
        'settings' => array_keys($settings),
        'gallery_files' => array_keys($galleryFiles),
    ];
    foreach ($out as &$list) sort($list);
    return $out;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $scan = exchange_scan_project(dirname(__DIR__));
    if (in_array('--show', $argv, true)) {
        echo json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if (!is_dir(dirname(EXCHANGE_BASELINE_FILE))) mkdir(dirname(EXCHANGE_BASELINE_FILE), 0775, true);
    file_put_contents(EXCHANGE_BASELINE_FILE, json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo 'Base de référence écrite : ' . count($scan['tables']) . ' tables, ' . count($scan['gallery_keys']) . ' clés de galerie, '
        . count($scan['tenant_keys']) . ' clés de boutique, ' . count($scan['settings']) . ' réglages, ' . count($scan['gallery_files']) . " fichiers annexes.\n";
}
