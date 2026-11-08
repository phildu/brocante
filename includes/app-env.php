<?php

// Environnement d'exécution : local → preprod → prod.
//
// Source, dans l'ordre :
//   1. la variable d'environnement APP_ENV ;
//   2. le fichier .app-env à la racine (déposé sur le serveur par le script de déploiement,
//      jamais présent en local ni versionné) ;
//   3. à défaut : « local » si config.local.php existe (machine de développement),
//      « preprod » sinon. Un serveur dont l'environnement n'est pas déclaré est donc traité
//      comme la préprod, jamais comme la production : le mode le plus prudent.
//
// Voir DEPLOIEMENT.md.

const APP_ENVS = ['local', 'preprod', 'prod'];

/**
 * Résolution pure (sans lecture de fichier ni d'environnement), testable.
 * Accepte quelques synonymes : production, staging, dev, development.
 */
function app_env_resolve(?string $declared, bool $hasLocalConfig): string
{
    $value = strtolower(trim((string) $declared));
    $value = match ($value) {
        'production' => 'prod',
        'staging', 'preproduction', 'pre-prod', 'recette' => 'preprod',
        'dev', 'development' => 'local',
        default => $value,
    };
    if (in_array($value, APP_ENVS, true)) return $value;
    return $hasLocalConfig ? 'local' : 'preprod';
}

/** Environnement courant : 'local', 'preprod' ou 'prod'. */
function app_env(): string
{
    static $env = null;
    if ($env !== null) return $env;
    $declared = (string) getenv('APP_ENV');
    $file = __DIR__ . '/../.app-env';
    if ($declared === '' && is_file($file)) {
        $declared = (string) @file_get_contents($file);
    }
    return $env = app_env_resolve($declared, is_file(__DIR__ . '/../config.local.php'));
}

function app_is_prod(): bool
{
    return app_env() === 'prod';
}

/**
 * Indexation autorisée ? Seulement en production ET quand l'interrupteur includes/indexation.php vaut true (« go » de
 * l'utilisateur). Pure (testable) : l'environnement et l'état de l'interrupteur sont passés en paramètres.
 */
function app_indexation_allowed(string $env, bool $switchOpen): bool
{
    return $env === 'prod' && $switchOpen;
}

/** État réel : environnement courant + interrupteur versionné (includes/indexation.php, fermé par défaut). */
function app_indexation_open(): bool
{
    $file = __DIR__ . '/indexation.php';
    $switch = is_file($file) && (include $file) === true;
    return app_indexation_allowed(app_env(), $switch);
}

/** Contenu de /robots.txt : tout interdit tant que l'indexation n'est pas ouverte, sinon seules les zones privées le sont. */
function app_robots_txt(bool $open): string
{
    if (!$open) return "User-agent: *\nDisallow: /\n";
    return "User-agent: *\nDisallow: /admin/\nDisallow: /portail/\nDisallow: /api/\n";
}

/**
 * Le site ne doit être indexé par les moteurs de recherche qu'en production après le « go » de l'utilisateur ;
 * partout ailleurs (et en production tant que l'interrupteur est fermé), chaque page PHP porte noindex.
 * (Les fichiers statiques sont couverts par /robots.txt, servi par robots.php.)
 */
function app_env_send_headers(): void
{
    if (PHP_SAPI === 'cli' || app_indexation_open() || headers_sent()) return;
    header('X-Robots-Tag: noindex, nofollow', false);
}
