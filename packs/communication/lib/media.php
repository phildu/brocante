<?php
// Médiathèque du pack Communication : fonctions communes aux routes de l'API (base de la boutique courante, db()).
//
// Une seule médiathèque : les éléments sont ceux de la plateforme (get_media_overview() : table media_library et photos des
// fiches, lues en place), complétés par com_media_meta (titre, texte alternatif, crédit, mots-clés, dossier, galerie, version
// web, dimensions). Les fichiers envoyés par le pack vont dans uploads/<boutique>/ et sont inscrits dans media_library, pour
// que tous les sélecteurs de la plateforme les voient. Un chemin n'est accepté que s'il fait partie de la médiathèque de CETTE
// boutique (com_find_item()) : jamais un chemin libre venu du navigateur.
defined('PLUGIN_RUNTIME') || exit;

const COM_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const COM_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v'];
const COM_IMAGE_MAX_BYTES = 25 * 1024 * 1024;
const COM_VIDEO_MAX_BYTES = 500 * 1024 * 1024;
const COM_IMAGE_MAX_PIXELS = 40_000_000; // au-delà, GD dépasserait la mémoire d'un hébergement mutualisé
const COM_CHUNK_BYTES = 1_572_864;       // 1,5 Mo : sous la limite d'envoi PHP la plus basse (2 Mo)
const COM_WEB_MAX_PX = 1200;

function com_root(): string
{
    return dirname(__DIR__, 3);
}

/** Dossier des fichiers du pack pour cette boutique, relatif à la racine (« uploads/la-fripe-a-naty »). */
function com_upload_rel(): string
{
    return 'uploads/' . tenant_slug();
}

function com_dir(string $rel): string
{
    $dir = com_root() . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('dossier impossible à créer : ' . $rel);
    return $dir;
}

/** Dossier de travail (morceaux d'envoi, imports) : data/ n'est jamais servi ni déployé. */
function com_tmp_dir(): string
{
    return com_dir('data/com-tmp');
}

function com_meta_all(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM com_media_meta')->fetchAll(PDO::FETCH_ASSOC) as $row) $out[$row['path']] = $row;
    return $out;
}

/** Enregistre des champs de com_media_meta pour un chemin (crée la ligne au besoin). */
function com_meta_save(string $path, array $fields): void
{
    $allowed = ['title', 'alt', 'credit', 'tags', 'folder', 'in_gallery', 'web_path', 'width', 'height', 'bytes', 'mime'];
    $fields = array_intersect_key($fields, array_flip($allowed));
    if (!$fields) return;
    $cols = array_keys($fields);
    $sql = 'INSERT INTO com_media_meta (path, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')
        ON CONFLICT(path) DO UPDATE SET ' . implode(', ', array_map(static fn ($c) => "$c = excluded.$c", $cols)) . ', updated_at = CURRENT_TIMESTAMP';
    db()->prepare($sql)->execute(array_merge([$path], array_values($fields)));
}

/** Mots-clés nettoyés : « a, b , a » → « a, b ». */
function com_tags(string $tags): string
{
    $list = array_filter(array_map(static fn ($t) => mb_substr(trim($t), 0, 40), preg_split('/[,;\n]+/', $tags) ?: []), 'strlen');
    return implode(', ', array_slice(array_values(array_unique($list)), 0, 30));
}

/** Le fichier appartient-il au dossier du pack de cette boutique ? */
function com_is_own(string $path): bool
{
    return str_starts_with($path, com_upload_rel() . '/') && !str_contains($path, '..');
}

/**
 * Éléments de la médiathèque de la boutique, par chemin, avec leurs informations en plus. Les dimensions et le poids
 * manquants sont relevés au passage (au plus $measure fichiers par appel) et mémorisés.
 */
function com_items(int $measure = 150): array
{
    $meta = com_meta_all();
    $items = [];
    foreach (get_media_overview() as $m) {
        $path = (string) $m['path'];
        if ($path === '' || str_contains($path, '..')) continue;
        if (isset($items[$path])) {
            // Même fichier déjà listé (ressource inscrite ET photo de fiche) : on note la fiche.
            if ($m['kind'] === 'fiche') $items[$path]['fiche'] = ['ref' => $m['origin_ref'], 'name' => $m['origin_name']];
            continue;
        }
        $x = $meta[$path] ?? [];
        $abs = com_root() . '/' . $path;
        if (!isset($x['bytes']) && $measure > 0 && is_file($abs)) {
            $measure--;
            $info = $m['type'] === 'photo' ? @getimagesize($abs) : null;
            $x = array_merge($x, ['bytes' => (int) filesize($abs), 'width' => $info[0] ?? null, 'height' => $info[1] ?? null, 'mime' => $info['mime'] ?? null]);
            com_meta_save($path, array_intersect_key($x, array_flip(['bytes', 'width', 'height', 'mime'])));
        }
        $items[$path] = [
            'path' => $path,
            'url' => '/' . $path,
            'type' => $m['type'] === 'video' ? 'video' : 'photo',
            'kind' => $m['kind'],
            'media_id' => $m['kind'] === 'media' ? (int) $m['id'] : null,
            'title' => ($x['title'] ?? '') !== '' ? $x['title'] : (string) $m['label'],
            'alt' => (string) ($x['alt'] ?? ''),
            'credit' => (string) ($x['credit'] ?? ''),
            'tags' => ($x['tags'] ?? '') !== '' ? $x['tags'] : (string) ($m['tags'] ?? ''),
            'folder' => (string) ($x['folder'] ?? ''),
            'in_gallery' => (int) ($x['in_gallery'] ?? 0) === 1,
            'web_url' => !empty($x['web_path']) && is_file(com_root() . '/' . $x['web_path']) ? '/' . $x['web_path'] : null,
            'width' => isset($x['width']) ? (int) $x['width'] : null,
            'height' => isset($x['height']) ? (int) $x['height'] : null,
            'bytes' => isset($x['bytes']) ? (int) $x['bytes'] : null,
            'source' => (string) $m['source'],
            'created_at' => (string) $m['created_at'],
            'fiche' => $m['kind'] === 'fiche' ? ['ref' => $m['origin_ref'], 'name' => $m['origin_name']] : null,
            'origin_name' => (string) ($m['origin_name'] ?? ''),
            'badges' => $m['badges'],
            'own' => com_is_own($path),
            'missing' => !is_file($abs),
        ];
    }
    return $items;
}

/** Un élément de la médiathèque de cette boutique, ou null (chemin inconnu : refusé). */
function com_find_item(string $path): ?array
{
    return com_items(0)[$path] ?? null;
}

/** Type réel d'un fichier (contenu, pas extension) : ['type' => photo|video, 'mime', 'ext', 'width', 'height'] ou null. */
function com_detect(string $file): ?array
{
    if (!function_exists('finfo_open')) return null;
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file);
    if (isset(COM_IMAGE_TYPES[$mime])) {
        $info = @getimagesize($file);
        if (!$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) return null;
        return ['type' => 'photo', 'mime' => $mime, 'ext' => COM_IMAGE_TYPES[$mime], 'width' => $info[0], 'height' => $info[1]];
    }
    if (isset(COM_VIDEO_TYPES[$mime])) return ['type' => 'video', 'mime' => $mime, 'ext' => COM_VIDEO_TYPES[$mime], 'width' => null, 'height' => null];
    return null;
}

/** Type d'après le début d'un fichier (premier morceau d'un envoi) : 'photo', 'video' ou null. */
function com_sniff_start(string $file): ?string
{
    if (!function_exists('finfo_open')) return null;
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file);
    return isset(COM_IMAGE_TYPES[$mime]) ? 'photo' : (isset(COM_VIDEO_TYPES[$mime]) ? 'video' : null);
}

/** Libellé lisible tiré d'un nom de fichier (« photo_vitrine-2.jpg » → « Photo vitrine 2 »). */
function com_pretty_name(string $name): string
{
    $base = trim(preg_replace('/[_\-.]+/', ' ', pathinfo($name, PATHINFO_FILENAME)) ?? '');
    return $base === '' ? 'Média' : mb_strtoupper(mb_substr($base, 0, 1)) . mb_substr($base, 1, 80);
}

/**
 * Range un fichier reçu (morceaux recollés ou import) dans uploads/<boutique>/ après contrôle du type réel et de la taille,
 * l'inscrit dans media_library, mémorise ses informations et crée sa version web. Le fichier source est déplacé.
 */
function com_store_file(string $srcFile, string $origName, string $source): array
{
    $d = com_detect($srcFile);
    if (!$d) throw new InvalidArgumentException('Format non pris en charge (JPEG, PNG, WebP, GIF, MP4, MOV ou WebM).');
    $bytes = (int) filesize($srcFile);
    $max = $d['type'] === 'photo' ? COM_IMAGE_MAX_BYTES : COM_VIDEO_MAX_BYTES;
    if ($bytes > $max) throw new InvalidArgumentException('Fichier trop lourd (' . round($max / 1048576) . ' Mo au plus).');
    if ($d['type'] === 'photo' && $d['width'] * $d['height'] > COM_IMAGE_MAX_PIXELS) throw new InvalidArgumentException('Image trop grande (40 mégapixels au plus).');
    $base = slugify(pathinfo($origName, PATHINFO_FILENAME), 50) ?: 'media';
    $rel = com_upload_rel() . '/' . $base . '-' . bin2hex(random_bytes(4)) . '.' . $d['ext'];
    com_dir(com_upload_rel());
    if (!@rename($srcFile, com_root() . '/' . $rel)) throw new RuntimeException('enregistrement du fichier impossible');
    @chmod(com_root() . '/' . $rel, 0644);
    $title = com_pretty_name($origName);
    add_media_item($d['type'], $rel, $title, $source);
    com_meta_save($rel, ['title' => $title, 'width' => $d['width'], 'height' => $d['height'], 'bytes' => $bytes, 'mime' => $d['mime']]);
    if ($d['type'] === 'photo') com_make_web($rel);
    return com_items(0)[$rel];
}

/**
 * Version web d'une image (1 200 px au plus) dans uploads/<boutique>/web/ : JPEG pour une photo, WebP (ou PNG) quand l'original
 * peut avoir de la transparence. Une image déjà petite et légère sert elle-même de version web. Renvoie le chemin ou null.
 */
function com_make_web(string $path): ?string
{
    $abs = com_root() . '/' . $path;
    $info = @getimagesize($abs);
    if (!$info || $info[0] * $info[1] > COM_IMAGE_MAX_PIXELS) return null;
    [$w, $h] = $info;
    $mime = (string) $info['mime'];
    if (max($w, $h) <= COM_WEB_MAX_PX && filesize($abs) <= 400 * 1024) {
        com_meta_save($path, ['web_path' => $path]);
        return $path;
    }
    $src = @imagecreatefromstring((string) file_get_contents($abs));
    if (!$src) return null;
    // Photo de téléphone : appliquer l'orientation EXIF, sinon la version web serait couchée.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($abs)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $rot = imagerotate($src, $angle, 0);
            if ($rot) { $src = $rot; [$w, $h] = [imagesx($src), imagesy($src)]; }
        }
    }
    $scale = min(1, COM_WEB_MAX_PX / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    $alpha = $mime !== 'image/jpeg';
    if ($alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ext = !$alpha ? 'jpg' : (function_exists('imagewebp') ? 'webp' : 'png');
    $rel = com_upload_rel() . '/web/' . (slugify(pathinfo($path, PATHINFO_FILENAME), 50) ?: 'image') . '-' . substr(md5($path), 0, 6) . '-web.' . $ext;
    com_dir(com_upload_rel() . '/web');
    $ok = match ($ext) {
        'jpg' => imagejpeg($dst, com_root() . '/' . $rel, 82),
        'webp' => imagewebp($dst, com_root() . '/' . $rel, 82),
        default => imagepng($dst, com_root() . '/' . $rel, 8),
    };
    if (!$ok) return null;
    com_meta_save($path, ['web_path' => $rel]);
    return $rel;
}

/**
 * Le fichier est-il utilisé ailleurs dans la boutique (fiche, hero, diaporama, bandeau, réglages…) ? Recherche son nom (unique
 * grâce à son suffixe aléatoire) dans toutes les colonnes texte des tables de la boutique, hors médiathèque et pack.
 */
function com_is_referenced(string $path): bool
{
    $needle = '%' . basename($path) . '%';
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
        AND name NOT IN ('media_library', 'plugins', 'ai_usage') AND name NOT LIKE 'com\\_%' ESCAPE '\\'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $cols = array_filter(db()->query('PRAGMA table_info("' . str_replace('"', '""', $t) . '")')->fetchAll(PDO::FETCH_ASSOC),
            static fn ($c) => $c['type'] === '' || stripos($c['type'], 'TEXT') !== false || stripos($c['type'], 'CHAR') !== false);
        foreach ($cols as $c) {
            $stmt = db()->prepare('SELECT 1 FROM "' . str_replace('"', '""', $t) . '" WHERE "' . str_replace('"', '""', $c['name']) . '" LIKE ? LIMIT 1');
            $stmt->execute([$needle]);
            if ($stmt->fetchColumn()) return true;
        }
    }
    return false;
}

/** YouTube ou Vimeo : ['platform', 'video_id'] ou null. */
function com_parse_video_link(string $url): ?array
{
    $url = trim($url);
    if (preg_match('#^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})(?:[?&\#/].*)?$#', $url, $m)) {
        return ['platform' => 'youtube', 'video_id' => $m[1]];
    }
    if (preg_match('#^https?://(?:www\.|player\.)?vimeo\.com/(?:video/)?(\d{6,12})(?:[?\#/].*)?$#', $url, $m)) {
        return ['platform' => 'vimeo', 'video_id' => $m[1]];
    }
    return null;
}

function com_links(): array
{
    $rows = db()->query('SELECT * FROM com_media_links ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn (array $r): array => $r + [
        'thumb' => $r['platform'] === 'youtube' ? 'https://i.ytimg.com/vi/' . $r['video_id'] . '/hqdefault.jpg' : null,
        'embed' => $r['platform'] === 'youtube' ? 'https://www.youtube-nocookie.com/embed/' . $r['video_id'] : 'https://player.vimeo.com/video/' . $r['video_id'],
    ], $rows);
}

/** L'adresse IP est-elle publique (ni privée, ni réservée, ni locale) ? */
function com_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * Télécharge un fichier depuis une adresse web vers un fichier temporaire, sans jamais joindre le réseau interne :
 * http(s) seulement, ports 80/443, adresse résolue une fois et imposée à cURL (pas de changement d'adresse en cours de route),
 * redirections suivies à la main (3 au plus) et revérifiées, taille plafonnée. Renvoie [chemin temporaire, nom de fichier].
 */
function com_fetch_url(string $url, int $maxBytes): array
{
    if (!function_exists('curl_init')) throw new RuntimeException('cURL indisponible sur ce serveur');
    for ($hop = 0; $hop <= 3; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower((string) ($p['scheme'] ?? ''));
        $host = strtolower((string) ($p['host'] ?? ''));
        $port = (int) ($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443], true) || isset($p['user'])) {
            throw new InvalidArgumentException('Adresse refusée (http ou https, ports 80 et 443 seulement).');
        }
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!$ips || array_filter($ips, static fn ($ip) => !com_public_ip($ip))) throw new InvalidArgumentException('Adresse refusée (serveur introuvable ou interne).');
        $tmp = tempnam(com_tmp_dir(), 'imp');
        $fh = fopen($tmp, 'wb');
        $size = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; mediatheque-boutique)',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use ($fh, &$size, $maxBytes): int {
                $size += strlen($data);
                return $size > $maxBytes ? 0 : (int) fwrite($fh, $data);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        fclose($fh);
        if ($size > $maxBytes) { @unlink($tmp); throw new InvalidArgumentException('Fichier trop lourd (' . round($maxBytes / 1048576) . ' Mo au plus).'); }
        if ($code >= 300 && $code < 400 && $location !== '') { @unlink($tmp); $url = $location; continue; }
        if (!$ok || $code !== 200 || $size === 0) { @unlink($tmp); throw new RuntimeException('Téléchargement impossible (réponse ' . $code . ').'); }
        $name = basename((string) ($p['path'] ?? '')) ?: 'import';
        return [$tmp, $name];
    }
    throw new RuntimeException('Trop de redirections.');
}

/** Supprime les morceaux d'envoi et fichiers d'import abandonnés depuis plus d'un jour. */
function com_tmp_cleanup(): void
{
    foreach (glob(com_tmp_dir() . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
    }
}
