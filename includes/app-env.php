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
 * Hors production, le site ne doit jamais être indexé par les moteurs de recherche.
 * (Les fichiers statiques sont couverts par le robots.txt que le déploiement dépose en préprod.)
 */
function app_env_send_headers(): void
{
    if (PHP_SAPI === 'cli' || app_is_prod() || headers_sent()) return;
    header('X-Robots-Tag: noindex, nofollow', false);
}
