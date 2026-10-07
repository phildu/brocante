<?php
/**
 * Sauvegarde de la base de données SQLite
 * Stocke les sauvegardes dans un dossier hors de la racine web
 */

require_once __DIR__ . '/../config.php';

/**
 * Répertoire de sauvegarde (hors de la racine web)
 * Doit être configurable ou définir manuellement
 */
define('BACKUP_DIR', __DIR__ . '/../../backups/');

/**
 * Crée une sauvegarde de la base de données
 * @param string $suffix Suffixe optionnel pour le nom de fichier
 * @return string Chemin vers le fichier de sauvegarde, ou null en cas d'échec
 */
function create_database_backup(string $suffix = ''): ?string
{
    $dbPath = DB_PATH;
    
    if (!is_file($dbPath)) {
        error_log("Base de données introuvable : $dbPath");
        return null;
    }
    
    // Créer le répertoire de sauvegarde si nécessaire
    if (!is_dir(BACKUP_DIR)) {
        if (!mkdir(BACKUP_DIR, 0755, true)) {
            error_log("Impossible de créer le répertoire de sauvegarde : " . BACKUP_DIR);
            return null;
        }
    }
    
    // Générer un nom de fichier unique
    $timestamp = date('Ymd-His');
    $filename = 'brocante-db-' . $timestamp . ($suffix ? '-' . $suffix : '') . '.bak';
    $backupPath = BACKUP_DIR . '/' . $filename;
    
    // Copier la base de données
    if (!copy($dbPath, $backupPath)) {
        error_log("Impossible de copier la base de données vers : $backupPath");
        return null;
    }
    
    // Nettoyer les anciennes sauvegardes (garder les 10 dernières)
    cleanup_old_backups();
    
    return $backupPath;
}

/**
 * Nettoie les anciennes sauvegardes (garde seulement les 10 dernières)
 */
function cleanup_old_backups(int $maxBackups = 10): void
{
    if (!is_dir(BACKUP_DIR)) {
        return;
    }
    
    $backups = glob(BACKUP_DIR . '/brocante-db-*.bak');
    if (!$backups) {
        return;
    }
    
    // Trier par date de modification (plus récent en premier)
    usort($backups, function($a, $b) {
        return filemtime($b) <=> filemtime($a);
    });
    
    // Supprimer les sauvegardes excédentaires
    $toDelete = array_slice($backups, $maxBackups);
    foreach ($toDelete as $backup) {
        if (is_file($backup)) {
            unlink($backup);
        }
    }
}

/**
 * Restaure une sauvegarde de la base de données
 * @param string $backupPath Chemin vers le fichier de sauvegarde
 * @return bool True en cas de succès, false sinon
 */
function restore_database_backup(string $backupPath): bool
{
    if (!is_file($backupPath)) {
        return false;
    }
    
    $dbPath = DB_PATH;
    
    // Créer une sauvegarde avant de restaurer
    create_database_backup('pre-restore');
    
    // Copier la sauvegarde par-dessus la base actuelle
    if (!copy($backupPath, $dbPath)) {
        error_log("Impossible de restaurer la sauvegarde : $backupPath");
        return false;
    }
    
    return true;
}

/**
 * Liste toutes les sauvegardes disponibles
 * @return array Liste des sauvegardes avec leurs infos
 */
function list_backups(): array
{
    if (!is_dir(BACKUP_DIR)) {
        return [];
    }
    
    $backups = glob(BACKUP_DIR . '/brocante-db-*.bak');
    if (!$backups) {
        return [];
    }
    
    $result = [];
    foreach ($backups as $backup) {
        $result[] = [
            'path' => $backup,
            'filename' => basename($backup),
            'size' => filesize($backup),
            'created_at' => date('Y-m-d H:i:s', filemtime($backup)),
        ];
    }
    
    // Trier par date de création (plus récent en premier)
    usort($result, function($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    
    return $result;
}
