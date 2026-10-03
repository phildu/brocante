<?php

// Prise de vue depuis un téléphone proche : l'administration affiche un code QR
// qui ouvre sur le téléphone une page de prise de vue (capture.php), SANS
// connexion — le lien secret fait office d'autorisation, limité à l'envoi de
// photos et de courtes vidéos vers la médiathèque de CE commerce, et il expire.
// Les fichiers reçus apparaissent sur l'ordinateur au fil de l'eau.

/** Durée de validité d'un code, renouvelée à chaque fichier reçu. */
const CAPTURE_TTL_MINUTES = 30;
/** Fichiers acceptés par code (un code oublié ne doit pas pouvoir remplir le disque). */
const CAPTURE_MAX_UPLOADS = 60;
/**
 * Plafond d'un envoi, mesuré sur l'hébergement OVH : 10 Mo passent, mais dès 16 Mo
 * un intermédiaire coupe la connexion avant PHP (aucune erreur lisible), quelle que
 * soit la valeur de upload_max_filesize. Au-delà, il faudrait envoyer par morceaux.
 */
const CAPTURE_SAFE_MAX_BYTES = 10 * 1048576;

/**
 * Les vidéos dépassent souvent ce plafond : le téléphone les découpe en morceaux
 * (au plus CAPTURE_CHUNK_BYTES chacun), envoyés l'un après l'autre et recollés ici.
 */
const CAPTURE_CHUNK_BYTES = 5 * 1048576;
/** Poids maximal d'une vidéo recollée (30 s en qualité standard : 10 à 60 Mo). */
const CAPTURE_VIDEO_MAX_BYTES = 200 * 1048576;
const CAPTURE_VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'];

/** Durée maximale d'une vidéo, vérifiée sur le téléphone avant l'envoi. */
const CAPTURE_VIDEO_MAX_SECONDS = 30;

function capture_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'capture_sessions'")->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS capture_sessions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      token_hash TEXT NOT NULL UNIQUE,
      created_by INTEGER,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at TEXT NOT NULL,
      closed_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS capture_uploads (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      session_id INTEGER NOT NULL,
      media_id INTEGER NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (session_id) REFERENCES capture_sessions(id) ON DELETE CASCADE
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_capture_uploads_session ON capture_uploads(session_id)');
}

/**
 * Ouvre une session de prise de vue. Le jeton (32 caractères hexadécimaux,
 * 128 bits) n'est jamais stocké : seule son empreinte l'est.
 * Retourne ['id' => …, 'token' => …, 'expires_at' => …].
 */
function capture_session_create(?int $accountId): array
{
    // Sessions anciennes : nettoyées au passage (leurs lignes d'envoi partent en cascade).
    db()->prepare('DELETE FROM capture_sessions WHERE expires_at < ?')->execute([gmdate('Y-m-d H:i:s', time() - 86400)]);

    $token = bin2hex(random_bytes(16));
    $expires = gmdate('Y-m-d H:i:s', time() + CAPTURE_TTL_MINUTES * 60);
    $stmt = db()->prepare('INSERT INTO capture_sessions (token_hash, created_by, expires_at) VALUES (?, ?, ?)');
    $stmt->execute([hash('sha256', $token), $accountId, $expires]);
    return ['id' => (int) db()->lastInsertId(), 'token' => $token, 'expires_at' => $expires];
}

/** Session valide (jeton connu, ni expiré ni fermée), ou null. */
function capture_session_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM capture_sessions WHERE token_hash = ? AND closed_at IS NULL AND expires_at > ?');
    $stmt->execute([hash('sha256', $token), gmdate('Y-m-d H:i:s')]);
    return $stmt->fetch() ?: null;
}

function capture_session_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM capture_sessions WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function capture_session_close(int $id): void
{
    db()->prepare('UPDATE capture_sessions SET closed_at = ? WHERE id = ? AND closed_at IS NULL')->execute([gmdate('Y-m-d H:i:s'), $id]);
}

function capture_session_is_active(array $session): bool
{
    return $session['closed_at'] === null && $session['expires_at'] > gmdate('Y-m-d H:i:s');
}

function capture_upload_count(int $sessionId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM capture_uploads WHERE session_id = ?');
    $stmt->execute([$sessionId]);
    return (int) $stmt->fetchColumn();
}

/** Enregistre un fichier reçu et prolonge le code (une prise de vue active ne doit pas expirer en cours de route). */
function capture_record_upload(int $sessionId, int $mediaId): void
{
    db()->prepare('INSERT INTO capture_uploads (session_id, media_id) VALUES (?, ?)')->execute([$sessionId, $mediaId]);
    db()->prepare('UPDATE capture_sessions SET expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() + CAPTURE_TTL_MINUTES * 60), $sessionId]);
}

/** Fichiers reçus par une session, après l'envoi n° $afterId (pour le suivi en direct sur l'ordinateur). */
function capture_uploads_since(int $sessionId, int $afterId): array
{
    $stmt = db()->prepare('SELECT u.id, m.id AS media_id, m.type, m.path, m.label
        FROM capture_uploads u JOIN media_library m ON m.id = u.media_id
        WHERE u.session_id = ? AND u.id > ? ORDER BY u.id');
    $stmt->execute([$sessionId, $afterId]);
    return $stmt->fetchAll();
}

/** Valeur php.ini de type « 8M » / « 2G » en octets. */
function capture_ini_bytes(string $name): int
{
    $value = trim((string) ini_get($name));
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $number = (float) $value;
    return (int) match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

/** Taille maximale acceptée par fichier : le plus petit des plafonds PHP (fichier et requête) et du plafond vérifié sur l'hébergement. */
function capture_max_file_bytes(): int
{
    return min(capture_ini_bytes('upload_max_filesize'), capture_ini_bytes('post_max_size'), CAPTURE_SAFE_MAX_BYTES);
}

/**
 * Adresse que le téléphone doit ouvrir : même domaine et même préfixe de
 * commerce que l'administration, avec le jeton. Le second élément indique si
 * l'adresse est joignable depuis un autre appareil (faux pour .test et localhost).
 */
function capture_public_url(string $token): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $hostname = strtolower(preg_replace('/:\d+$/', '', $host));
    $reachable = !($hostname === 'localhost' || $hostname === '127.0.0.1' || $hostname === '::1' || str_ends_with($hostname, '.test'));
    return [($https ? 'https' : 'http') . '://' . $host . tenant_base_path() . '/capture.php?t=' . $token, $reachable];
}

/** Taille d'un morceau de vidéo : 5 Mo, ou moins si PHP accepte moins. */
function capture_chunk_bytes(): int
{
    return min(CAPTURE_CHUNK_BYTES, capture_max_file_bytes());
}

/** Prolonge le code : un envoi long (vidéo en 4G) ne doit pas expirer en cours de route. */
function capture_session_touch(int $sessionId): void
{
    db()->prepare('UPDATE capture_sessions SET expires_at = ? WHERE id = ?')
        ->execute([gmdate('Y-m-d H:i:s', time() + CAPTURE_TTL_MINUTES * 60), $sessionId]);
}

/**
 * Fichier d'assemblage d'une vidéo en cours d'envoi. Dans data/ (jamais servi
 * par le site), nommé d'après l'empreinte du code : un autre code ou un autre
 * commerce ne peut pas y toucher.
 */
function capture_part_path(array $session, string $uploadId): string
{
    $dir = __DIR__ . '/../data/capture-tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/' . substr((string) $session['token_hash'], 0, 24) . '-' . $uploadId . '.part';
}

/** Supprime les assemblages abandonnés depuis plus d'un jour. */
function capture_part_cleanup(): void
{
    foreach (glob(__DIR__ . '/../data/capture-tmp/*.part') ?: [] as $file) {
        if (filemtime($file) < time() - 86400) {
            @unlink($file);
        }
    }
}

/** Type MIME réel d'un fichier (contenu, pas extension). */
function capture_sniff_mime(string $path): string
{
    return function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path) : '';
}

/** Range une vidéo recollée dans uploads/ ; retourne le chemin relatif ou null (extension refusée). */
function capture_store_video(string $partPath, string $origName): ?string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['mp4', 'mov', 'webm', 'm4v'], true)) {
        return null;
    }
    $uploadsDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadsDir)) {
        mkdir($uploadsDir, 0755, true);
    }
    $filename = 'telephone-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.' . $ext;
    return rename($partPath, $uploadsDir . '/' . $filename) ? 'uploads/' . $filename : null;
}
