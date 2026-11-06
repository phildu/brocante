<?php

/**
 * Base SQLite d'une galerie : data/galeries/<identifiant>.db.
 *
 * Les galeries gardent leurs données dans des fichiers JSON (includes/galleries.php, gallery-admin.php). Une extension de galerie qui a
 * besoin de vraies tables (la buvette, invendus & dons ; plus tard le comptage de foule) les range ici : une base par galerie, jamais
 * partagée avec une autre galerie ni avec une boutique. Les tables de chaque extension portent son préfixe (bv_…, unsold_…, cp_…) et se
 * créent toutes seules au premier accès. Le dossier data/ n'est servi ni envoyé par le déploiement ; la base disparaît avec sa galerie
 * (gallery_delete()).
 */

require_once __DIR__ . '/galleries.php';

/** Chemin de la base d'une galerie ('' si l'identifiant est invalide). */
function gallery_sql_file(string $slug): string
{
    return gallery_slug_valid($slug) ? galleries_dir() . '/' . $slug . '.db' : '';
}

/** Base d'une galerie (créée au besoin). Un PDO par galerie et par requête. */
function gallery_sql(string $slug): PDO
{
    static $open = [];
    if (isset($open[$slug])) return $open[$slug];
    $file = gallery_sql_file($slug);
    if ($file === '') throw new InvalidArgumentException('Identifiant de galerie invalide.');
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("Impossible de créer le dossier $dir (droits d'écriture manquants).");
    }
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $open[$slug] = $pdo;
}
