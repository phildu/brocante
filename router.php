<?php

// Routeur du serveur PHP intégré (php -S … router.php) : reproduit les règles
// d'adresses du .htaccess pour tester un commerce servi sous /<identifiant>/
// sans Apache. Inutile en ligne, où le .htaccess s'en charge.

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Racine du domaine : la page d'accueil de la plateforme, quand le portail est présent (déploiement portail).
if ($path === '/' && !getenv('TENANT') && is_file(__DIR__ . '/portail/index.php')) {
    $_SERVER['SCRIPT_NAME'] = '/accueil/index.php';
    require __DIR__ . '/accueil/index.php';
    return true;
}

// Galeries commerciales : /galerie/ et /galerie/<identifiant>/ (même règle que dans le .htaccess).
if (preg_match('#^/galerie(?:/([a-z0-9][a-z0-9-]*))?/?$#', $path, $m)) {
    $_GET['g'] = $m[1] ?? '';
    $_SERVER['SCRIPT_NAME'] = '/galerie/index.php';
    require __DIR__ . '/galerie/index.php';
    return true;
}

if (preg_match('#^/([a-z0-9][a-z0-9_-]*)(/.*)?$#', $path, $m)
    && is_file(__DIR__ . '/tenants/' . $m[1] . '/tenant.php')
    && !file_exists(__DIR__ . '/' . $m[1])) {
    $rest = $m[2] ?? '';
    if ($rest === '') {
        header('Location: /' . $m[1] . '/' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
        return true;
    }
    $target = $rest === '/' ? '/index.php' : $rest;
    $file = __DIR__ . $target;
    if (is_file($file) && str_ends_with($file, '.php')) {
        $_SERVER['SCRIPT_NAME'] = $target;
        $_SERVER['PHP_SELF'] = $target;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        chdir(dirname($file));
        require $file;
        return true;
    }
}

// Tout le reste : comportement habituel du serveur intégré.
return false;
