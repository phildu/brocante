<?php

// Application smartphone « Studio » (studio.php) : prise de vue pièce par pièce
// ou en lot, puis génération des fiches (détourage, mise en situation, texte).
// Chaque pièce créée est suivie dans studio_jobs, pour que le traitement puisse
// reprendre plus tard (téléphone éteint, réseau coupé) et pour la liste « Mes pièces ».

/** Photos au plus par pièce (un envoi reste sous la limite d'envoi de l'hébergement). */
const STUDIO_MAX_PHOTOS = 10;
/** Durée de connexion mémorisée sur un téléphone (« Rester connecté »). */
const STUDIO_REMEMBER_DAYS = 30;

function studio_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'studio_jobs'")->fetchColumn();
    if ($exists) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS studio_jobs (
      ref TEXT PRIMARY KEY,
      status TEXT NOT NULL DEFAULT 'queued',
      source TEXT NOT NULL DEFAULT 'single',
      ai_notes TEXT NOT NULL DEFAULT '',
      note TEXT NOT NULL DEFAULT '',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
}

/** Crée le suivi d'une pièce (à traiter). */
function studio_job_create(string $ref, string $source, string $aiNotes): void
{
    $source = $source === 'batch' ? 'batch' : 'single';
    db()->prepare("INSERT OR REPLACE INTO studio_jobs (ref, status, source, ai_notes) VALUES (?, 'queued', ?, ?)")
        ->execute([$ref, $source, mb_substr($aiNotes, 0, 300)]);
}

/** Statut : queued (à traiter), ready (à relire), reviewed (relue et enregistrée). */
function studio_job_set(string $ref, string $status, string $note = ''): void
{
    db()->prepare("UPDATE studio_jobs SET status = ?, note = ?, updated_at = CURRENT_TIMESTAMP WHERE ref = ?")
        ->execute([$status, $note, $ref]);
}

function studio_job_get(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM studio_jobs WHERE ref = ?');
    $stmt->execute([$ref]);
    return $stmt->fetch() ?: null;
}

function studio_job_delete(string $ref): void
{
    db()->prepare('DELETE FROM studio_jobs WHERE ref = ?')->execute([$ref]);
}

/** Pièces du Studio, les plus récentes d'abord, avec de quoi les afficher en liste. */
function studio_jobs_list(int $limit = 60): array
{
    $stmt = db()->prepare("SELECT j.ref, j.status, j.source, j.note, j.created_at, p.name, p.price, p.cat, p.is_hidden, p.photo
        FROM studio_jobs j JOIN products p ON p.ref = j.ref
        ORDER BY j.created_at DESC, j.rowid DESC LIMIT ?");
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $thumb = $row['photo'];
        if (!$thumb) {
            $photos = product_photos_list($row['ref']);
            $thumb = $photos[0]['path'] ?? null;
        }
        $row['thumb'] = $thumb;
        $row['hidden'] = (bool) $row['is_hidden'];
        unset($row['photo'], $row['is_hidden']);
    }
    return $rows;
}

/** Adresse de l'application, à ouvrir sur le téléphone ; [adresse, joignable depuis un autre appareil]. */
function studio_public_url(): array
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $hostname = strtolower(preg_replace('/:\d+$/', '', $host));
    $reachable = !($hostname === 'localhost' || $hostname === '127.0.0.1' || $hostname === '::1' || str_ends_with($hostname, '.test'));
    return [($https ? 'https' : 'http') . '://' . $host . tenant_base_path() . '/studio.php', $reachable];
}
