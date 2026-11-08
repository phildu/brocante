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
    static $sent = false;
    if ($sent || PHP_SAPI === 'cli' || app_indexation_open() || headers_sent()) return;
    $sent = true;
    header('X-Robots-Tag: noindex, nofollow', false);
}

/**
 * Cartouche d'environnement : petite étiquette de couleur en haut à gauche de chaque page
 * HTML, pour savoir d'un coup d'œil si l'on est en local (bleu), en préprod (orange) ou en
 * prod (rouge). En prod, seuls les administrateurs connectés la voient : les visiteurs
 * n'en voient jamais. Pure et testable : app_env_badge_html() ne lit rien d'autre que ses arguments.
 */
function app_env_badge_html(string $env, bool $isAdmin): string
{
    if ($env === 'prod' && !$isAdmin) return '';
    [$label, $bg, $fg] = match ($env) {
        'local'   => ['LOCAL', '#2563eb', '#ffffff'],
        'preprod' => ['PRÉPROD', '#f59e0b', '#1f1300'],
        'prod'    => ['PRODUCTION', '#dc2626', '#ffffff'],
        default   => ['', '', ''],
    };
    if ($label === '') return '';
    return '<div id="app-env-badge" aria-hidden="true" style="position:fixed;top:0;left:0;z-index:2147483000;'
        . 'background:' . $bg . ';color:' . $fg . ';font:700 11px/1 system-ui,-apple-system,Segoe UI,sans-serif;'
        . 'letter-spacing:.08em;padding:5px 10px 5px 8px;border-radius:0 0 8px 0;'
        . 'box-shadow:0 1px 4px rgba(0,0,0,.35);pointer-events:none;user-select:none">' . $label . '</div>'
        . '<style>@media print{#app-env-badge{display:none!important}}</style>';
}

/** Insère le cartouche avant le dernier </body> ; la page est rendue telle quelle si elle n'en a pas. */
function app_env_badge_inject(string $html, string $badge): string
{
    if ($badge === '') return $html;
    $pos = strripos($html, '</body>');
    if ($pos === false) return $html;
    return substr($html, 0, $pos) . $badge . substr($html, $pos);
}

/** Rappel du tampon de sortie : n'agit que sur les réponses HTML (ni JSON, ni fichiers, ni extraits sans </body>). */
function app_env_badge_output(string $html): string
{
    if ($html === '') return $html;
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return $html;
        }
    }
    $isAdmin = session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['is_admin']);
    return app_env_badge_inject($html, app_env_badge_html(app_env(), $isAdmin));
}

/** Active le cartouche (appelée par config.php). Sans effet en ligne de commande. */
function app_env_enable_badge(): void
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli') return;
    $done = true;
    ob_start('app_env_badge_output');
}
