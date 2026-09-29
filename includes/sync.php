<?php
/**
 * Envoi de pièces du catalogue d'un site vers un autre (ex. du Mac vers la
 * préprod), par HTTP : aucun accès FTP ni écrasement de base.
 *
 *   - Le site qui REÇOIT génère une clé de réception (Administration →
 *     Envoyer en préprod) ; seule son empreinte SHA-256 est gardée dans
 *     SECRETS_DIR/sync_receive.key.
 *   - Le site qui ENVOIE enregistre l'adresse du site distant et cette clé
 *     (SECRETS_DIR/sync_target.json), puis pousse les pièces une par une :
 *     chaque fichier (photos, visuels, vidéos) est transmis s'il manque, puis
 *     la fiche et sa galerie (admin/sync-receive.php).
 */

const SYNC_PRODUCT_FILE_COLUMNS = ['photo', 'photo_retouche', 'photo_detoure', 'photo_angle', 'photo_ambiance'];
const SYNC_FILE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'mp4', 'webm', 'mov', 'm4v'];

function sync_receive_key_file(): string
{
    return SECRETS_DIR . '/sync_receive.key';
}

function sync_target_file(): string
{
    return SECRETS_DIR . '/sync_target.json';
}

function sync_ensure_secrets_dir(): void
{
    if (!is_dir(SECRETS_DIR)) @mkdir(SECRETS_DIR, 0700, true);
}

/** Nouvelle clé de réception : renvoyée en clair une seule fois, seule son empreinte est stockée. */
function sync_generate_receive_key(): string
{
    sync_ensure_secrets_dir();
    $key = 'bsync_' . bin2hex(random_bytes(24));
    file_put_contents(sync_receive_key_file(), hash('sha256', $key));
    @chmod(sync_receive_key_file(), 0600);
    return $key;
}

function sync_receive_enabled(): bool
{
    return is_file(sync_receive_key_file()) && trim((string) file_get_contents(sync_receive_key_file())) !== '';
}

function sync_receive_key_matches(string $given): bool
{
    if ($given === '' || !sync_receive_enabled()) return false;
    return hash_equals(trim((string) file_get_contents(sync_receive_key_file())), hash('sha256', $given));
}

/** Site distant configuré : ['url' => ..., 'key' => ...] ou null. */
function sync_target(): ?array
{
    $raw = is_file(sync_target_file()) ? json_decode((string) file_get_contents(sync_target_file()), true) : null;
    return is_array($raw) && !empty($raw['url']) && !empty($raw['key']) ? $raw : null;
}

function sync_save_target(string $url, string $key, ?array $map = null): void
{
    $url = rtrim($url, '/');
    $current = sync_target();
    // Correspondance des numéros gardée tant que la destination ne change pas.
    $map ??= ($current && $current['url'] === $url) ? ($current['map'] ?? []) : [];
    sync_ensure_secrets_dir();
    file_put_contents(sync_target_file(), json_encode(['url' => $url, 'key' => $key, 'map' => (object) $map], JSON_UNESCAPED_SLASHES));
    @chmod(sync_target_file(), 0600);
}

/**
 * Numéros des pièces envoyées sous un autre numéro là-bas (numéro local →
 * numéro distant), pour mettre à jour la même fiche aux envois suivants.
 */
function sync_ref_map(array $target): array
{
    return array_map('strval', (array) ($target['map'] ?? []));
}

/** Chemin relatif sûr sous uploads/ (pas de « .. », extension connue). */
function sync_safe_upload_path(string $path): bool
{
    if (!preg_match('#^uploads/[A-Za-z0-9][A-Za-z0-9._-]*(/[A-Za-z0-9][A-Za-z0-9._-]*)*$#', $path)) return false;
    if (str_contains($path, '..') || str_starts_with($path, 'uploads/import/')) return false;
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), SYNC_FILE_EXTENSIONS, true);
}

/**
 * Appel au site distant (admin/sync-receive.php). $fileAbs : fichier joint.
 * Renvoie la réponse JSON décodée, ou ['ok' => false, 'error' => ...].
 */
function sync_remote_call(array $target, array $fields, ?string $fileAbs = null): array
{
    $ch = curl_init($target['url'] . '/admin/sync-receive.php');
    $fields['sync_key'] = $target['key'];
    if ($fileAbs !== null) $fields['file'] = new CURLFile($fileAbs);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => ['X-Sync-Key: ' . $target['key'], 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) return ['ok' => false, 'error' => 'Site distant injoignable : ' . $error];
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        $hint = match (true) {
            $status === 404 => 'adresse incorrecte, ou site distant pas encore mis à jour (déployez d\'abord cette version)',
            $status === 401 => 'le site distant est protégé par un mot de passe : indiquez-le dans l\'adresse (https://utilisateur:motdepasse@…)',
            $status === 413 => 'fichier trop lourd pour le serveur distant',
            $status >= 300 && $status < 400 => 'le site redirige ailleurs (vérifiez http/https et l\'adresse exacte)',
            default => 'réponse inattendue',
        };
        return ['ok' => false, 'error' => "Erreur du site distant (HTTP $status) : $hint."];
    }
    return $data;
}

/** Tous les fichiers d'une pièce : colonnes photo* de la fiche + galerie (dont versions 9:16). */
function sync_product_files(array $product, array $photos): array
{
    $files = [];
    foreach (SYNC_PRODUCT_FILE_COLUMNS as $col) {
        if (!empty($product[$col])) $files[] = $product[$col];
    }
    foreach ($photos as $p) {
        $files[] = $p['path'];
        if (!empty($p['path_mobile'])) $files[] = $p['path_mobile'];
    }
    return array_values(array_unique(array_filter($files, 'sync_safe_upload_path')));
}

/**
 * Envoie une pièce locale vers le site distant.
 * $mode : 'update' (même numéro, remplace la fiche distante) ou 'new' (nouveau numéro là-bas).
 */
function sync_push_product(array $target, string $ref, string $mode): array
{
    $product = get_product($ref);
    if (!$product) return ['ok' => false, 'error' => "Pièce $ref introuvable."];
    $map = sync_ref_map($target);
    if ($mode !== 'new' && isset($map[$ref])) $product['ref'] = $map[$ref];
    $photos = product_photos_list($ref);
    $root = realpath(__DIR__ . '/..');

    // 1. Fichiers : envoyés seulement s'ils manquent (ou diffèrent) là-bas.
    $renamed = [];
    $sent = 0;
    foreach (sync_product_files($product, $photos) as $path) {
        $abs = realpath($root . '/' . $path);
        if (!$abs || !str_starts_with($abs, $root . '/uploads/') || !is_file($abs)) continue;
        $sha1 = sha1_file($abs);
        $check = sync_remote_call($target, ['action' => 'file', 'path' => $path, 'sha1' => $sha1]);
        if (empty($check['ok'])) return $check;
        if (empty($check['have'])) {
            $check = sync_remote_call($target, ['action' => 'file', 'path' => $path, 'sha1' => $sha1], $abs);
            if (empty($check['ok'])) {
                return ['ok' => false, 'error' => basename($path) . ' : ' . ($check['error'] ?? 'envoi refusé')];
            }
            $sent++;
        }
        if (($check['path'] ?? $path) !== $path) $renamed[$path] = $check['path'];
    }

    // 2. Fiche et galerie, avec les chemins éventuellement renommés là-bas.
    $rename = static fn ($p) => $p === null ? null : ($renamed[$p] ?? $p);
    foreach (SYNC_PRODUCT_FILE_COLUMNS as $col) {
        if (array_key_exists($col, $product)) $product[$col] = $rename($product[$col]);
    }
    $gallery = array_map(static fn ($p) => [
        'path' => $rename($p['path']),
        'path_mobile' => $rename($p['path_mobile'] ?? null),
        'type' => $p['type'],
        'label' => $p['label'],
        'is_illustration' => (int) $p['is_illustration'],
        'is_hidden' => (int) $p['is_hidden'],
        'sort_order' => (int) $p['sort_order'],
        'created_at' => $p['created_at'],
    ], $photos);

    $result = sync_remote_call($target, [
        'action' => 'product',
        'mode' => $mode === 'new' ? 'new' : 'update',
        'product' => json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'photos' => json_encode($gallery, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if (!empty($result['ok'])) {
        $result['files_sent'] = $sent;
        if ($result['ref'] === $ref) unset($map[$ref]); else $map[$ref] = (string) $result['ref'];
        sync_save_target($target['url'], $target['key'], $map);
    }
    return $result;
}

/* ---------- Côté réception (admin/sync-receive.php) ---------- */

/** Fichier reçu : vérifié puis rangé sous le même chemin, ou un nom voisin si ce chemin est déjà pris par un autre fichier. */
function sync_receive_file(string $path, string $sha1, ?array $upload): array
{
    if (!sync_safe_upload_path($path)) return ['ok' => false, 'error' => 'Chemin de fichier refusé.'];
    $root = realpath(__DIR__ . '/..');
    $abs = $root . '/' . $path;

    // Déjà là à l'identique (même chemin, ou version déjà renommée lors d'un envoi précédent).
    $dir = dirname($abs);
    $stem = pathinfo($path, PATHINFO_FILENAME);
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $candidates = array_merge([$abs], glob($dir . '/' . $stem . '-s*.' . $ext) ?: []);
    foreach ($candidates as $c) {
        if (is_file($c) && sha1_file($c) === $sha1) {
            return ['ok' => true, 'have' => true, 'path' => substr($c, strlen($root) + 1)];
        }
    }
    if ($upload === null) return ['ok' => true, 'have' => false];

    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $msg = in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'fichier plus lourd que la limite du serveur (' . ini_get('upload_max_filesize') . ')'
            : 'fichier non reçu (code ' . (int) ($upload['error'] ?? 0) . ')';
        return ['ok' => false, 'error' => $msg];
    }
    if (sha1_file($upload['tmp_name']) !== $sha1) return ['ok' => false, 'error' => 'Fichier abîmé pendant l\'envoi.'];

    // Contenu cohérent avec l'extension (pas de script déguisé en image).
    $lower = strtolower($ext);
    if (in_array($lower, ['mp4', 'webm', 'mov', 'm4v'], true)) {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!str_starts_with($mime, 'video/') && $mime !== 'application/octet-stream') return ['ok' => false, 'error' => 'Vidéo non reconnue.'];
    } elseif ($lower === 'svg') {
        $svg = (string) file_get_contents($upload['tmp_name']);
        if (preg_match('/<script|on\w+\s*=|javascript:/i', $svg)) return ['ok' => false, 'error' => 'SVG refusé (contient du script).'];
    } elseif (!@getimagesize($upload['tmp_name'])) {
        return ['ok' => false, 'error' => 'Image non reconnue.'];
    }

    // Chemin occupé par un autre fichier : nom voisin, jamais d'écrasement.
    if (file_exists($abs)) {
        $abs = $dir . '/' . $stem . '-s' . substr($sha1, 0, 8) . '.' . $ext;
    }
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!move_uploaded_file($upload['tmp_name'], $abs)) return ['ok' => false, 'error' => 'Écriture impossible dans uploads/ sur le serveur.'];
    @chmod($abs, 0644);
    return ['ok' => true, 'have' => true, 'stored' => true, 'path' => substr($abs, strlen($root) + 1)];
}

/** Fiche reçue : créée, ou remplacée si mode « update » et numéro déjà pris. */
function sync_receive_product(array $product, array $photos, string $mode): array
{
    $ref = trim((string) ($product['ref'] ?? ''));
    if ($ref === '' || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $ref) || trim((string) ($product['name'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'Fiche incomplète.'];
    }
    $pdo = db();
    $columns = $pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN, 1);
    $row = array_intersect_key($product, array_flip($columns));
    foreach (SYNC_PRODUCT_FILE_COLUMNS as $col) {
        if (!empty($row[$col]) && !sync_safe_upload_path((string) $row[$col])) $row[$col] = null;
    }

    $exists = (bool) get_product($ref);
    $pdo->beginTransaction();
    try {
        if ($exists && $mode === 'new') {
            $row['ref'] = $ref = next_ref();
            $exists = false;
        }
        if ($exists) {
            $sets = implode(', ', array_map(static fn ($c) => "$c = :$c", array_keys(array_diff_key($row, ['ref' => 1]))));
            $pdo->prepare("UPDATE products SET $sets WHERE ref = :ref")->execute($row);
            $pdo->prepare('DELETE FROM product_photos WHERE product_ref = ?')->execute([$ref]);
        } else {
            $cols = array_keys($row);
            $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($row);
        }

        $insert = $pdo->prepare('INSERT INTO product_photos (product_ref, path, path_mobile, type, label, is_illustration, is_hidden, sort_order, created_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($photos as $p) {
            $path = (string) ($p['path'] ?? '');
            if (!sync_safe_upload_path($path)) continue;
            $mobile = (string) ($p['path_mobile'] ?? '');
            $insert->execute([
                $ref, $path, sync_safe_upload_path($mobile) ? $mobile : null,
                in_array($p['type'] ?? 'photo', ['photo', 'video'], true) ? $p['type'] : 'photo',
                mb_substr((string) ($p['label'] ?? 'Photo'), 0, 80),
                (int) !empty($p['is_illustration']), (int) !empty($p['is_hidden']),
                (int) ($p['sort_order'] ?? 0),
                (string) ($p['created_at'] ?? '') ?: date('Y-m-d H:i:s'),
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Enregistrement impossible : ' . $e->getMessage()];
    }
    return ['ok' => true, 'ref' => $ref, 'created' => !$exists];
}
