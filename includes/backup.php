<?php

// Sauvegarde quotidienne de la base du commerce, sans tâche planifiée : la
// première ouverture de la base dans la journée en fait une copie dans
// data/backups/<base>-AAAA-MM-JJ.db (data/ n'est jamais servi en HTTP, jamais
// envoyé par deploy-brocante.sh ni suivi par git). Les DB_BACKUP_KEEP plus
// récentes sont gardées. Pour restaurer : remplacer data/<base>.db (ou
// brocante.db pour le Petit Chalet) par la sauvegarde voulue, site au repos.

const DB_BACKUP_KEEP = 14;

function db_backup_dir(): string
{
    return dirname(__DIR__) . '/data/backups';
}

/** Préfixe des sauvegardes d'une base : son nom de fichier sans .db (« la-fripe-a-naty », « brocante »…). */
function db_backup_prefix(): string
{
    return basename(DB_PATH, '.db');
}

/** Sauvegardes de la base du commerce, de la plus récente à la plus ancienne. */
function db_backup_list(): array
{
    $files = glob(db_backup_dir() . '/' . db_backup_prefix() . '-????-??-??.db') ?: [];
    rsort($files);
    return $files;
}

/**
 * Fait la sauvegarde du jour si elle manque. Ne bloque jamais le site : une
 * erreur (disque plein, droits) est seulement journalisée.
 */
function db_backup_daily(PDO $pdo): void
{
    $dir = db_backup_dir();
    $target = $dir . '/' . db_backup_prefix() . '-' . date('Y-m-d') . '.db';
    if (is_file($target)) return;
    try {
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return;
        // Une seule requête fait la copie, les autres passent leur chemin.
        $lock = @fopen($dir . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return;
        try {
            if (is_file($target)) return;
            $tmp = $target . '.tmp';
            @unlink($tmp);
            // VACUUM INTO : copie cohérente même pendant une écriture (SQLite ≥ 3.27).
            $pdo->exec('VACUUM INTO ' . $pdo->quote($tmp));
            rename($tmp, $target);
            @chmod($target, 0600);
            foreach (array_slice(db_backup_list(), DB_BACKUP_KEEP) as $old) {
                @unlink($old);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    } catch (Throwable $e) {
        error_log('Sauvegarde de la base impossible : ' . $e->getMessage());
        if (isset($tmp)) @unlink($tmp);
    }
}
