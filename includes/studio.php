<?php

// Application smartphone « Studio » (studio.php) : prise de vue pièce par pièce
// ou en lot, puis génération des fiches (détourage, mise en situation, texte).
// Chaque pièce créée est suivie dans studio_jobs, pour que le traitement puisse
// reprendre plus tard (téléphone éteint, réseau coupé) et pour la liste « Mes pièces ».

/** Photos au plus par pièce (un envoi reste sous la limite d'envoi de l'hébergement). */
const STUDIO_MAX_PHOTOS = 10;
/** Durée de connexion mémorisée sur un téléphone (« Rester connecté »). */
const STUDIO_REMEMBER_DAYS = 30;

/** Types de pièce choisis au shooting : l'objet (5 angles), le vêtement (recto et verso seulement), le média (livre, CD, vinyle, DVD : scan). */
const STUDIO_KINDS = ['objet', 'vetement', 'media'];

function studio_kind(?string $kind): string
{
    return in_array($kind, STUDIO_KINDS, true) ? $kind : 'objet';
}

function studio_ensure_schema(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'studio_jobs'")->fetchColumn();
    if (!$exists) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS studio_jobs (
          ref TEXT PRIMARY KEY,
          status TEXT NOT NULL DEFAULT 'queued',
          source TEXT NOT NULL DEFAULT 'single',
          ai_notes TEXT NOT NULL DEFAULT '',
          note TEXT NOT NULL DEFAULT '',
          kind TEXT NOT NULL DEFAULT 'objet',
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        return;
    }
    // Bases créées avant le choix du type de pièce.
    if (!in_array('kind', $pdo->query('PRAGMA table_info(studio_jobs)')->fetchAll(PDO::FETCH_COLUMN, 1), true)) {
        $pdo->exec("ALTER TABLE studio_jobs ADD COLUMN kind TEXT NOT NULL DEFAULT 'objet'");
    }
}

/** Crée le suivi d'une pièce (à traiter). */
function studio_job_create(string $ref, string $source, string $aiNotes, string $kind = 'objet'): void
{
    $source = $source === 'batch' ? 'batch' : 'single';
    db()->prepare("INSERT OR REPLACE INTO studio_jobs (ref, status, source, ai_notes, kind) VALUES (?, 'queued', ?, ?, ?)")
        ->execute([$ref, $source, mb_substr($aiNotes, 0, 300), studio_kind($kind)]);
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

/**
 * Objets distincts visibles sur une photo (un « ensemble » : plusieurs pièces posées ensemble), d'après l'IA :
 * [['label' => 'vase bleu', 'box' => [x, y, largeur, hauteur]], …], coordonnées en fraction de l'image (0 à 1) ; [] si
 * un seul objet, si l'IA ne répond pas ou si la clé Gemini manque.
 */
function studio_detect_objects(string $photoAbsPath): array
{
    $small = downscale_for_ai($photoAbsPath, 1024, 80);
    if (!$small) return [];
    $prompt = "Tu regardes la photo d'" . tenant('ai.item') . ' pour ' . tenant('ai.shop') . '. '
        . "Si elle montre PLUSIEURS objets distincts à vendre séparément (un ensemble posé ensemble), liste-les (8 au plus) ; "
        . "s'il n'y a qu'un seul objet (ou un service / un lot indissociable), liste-le seul. "
        . 'Réponds UNIQUEMENT avec un tableau JSON, sans texte autour, sans markdown : '
        . '[{"label": "nom très court de l\'objet", "box_2d": [ymin, xmin, ymax, xmax]}], coordonnées entières de 0 à 1000 relatives à l\'image.';
    $text = gemini_describe_image($small, $prompt, 1);
    @unlink($small);
    if (!$text) return [];
    $list = json_decode(preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($text)), true);
    if (!is_array($list)) return [];
    $out = [];
    foreach ($list as $item) {
        $box = $item['box_2d'] ?? null;
        if (!is_array($box) || count($box) !== 4) continue;
        [$y0, $x0, $y1, $x1] = array_map(static fn ($v): float => max(0.0, min(1000.0, (float) $v)) / 1000, array_values($box));
        if ($x1 - $x0 < 0.05 || $y1 - $y0 < 0.05) continue;
        $out[] = ['label' => mb_substr(trim((string) ($item['label'] ?? 'Objet')), 0, 40) ?: 'Objet', 'box' => [round($x0, 4), round($y0, 4), round($x1 - $x0, 4), round($y1 - $y0, 4)]];
        if (count($out) >= 8) break;
    }
    return count($out) >= 2 ? $out : [];
}
