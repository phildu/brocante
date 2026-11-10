<?php
// Fichiers du pack Publication réseaux sociaux : contrôles du type réel, dossier de travail hors de uploads/, import d'une
// adresse web sans joindre le réseau interne. Les fichiers publiés restent dans uploads/<boutique>/ (le dossier de la boutique,
// servi publiquement), partagé avec les autres packs : une photo envoyée ici se retrouve dans la médiathèque de la boutique.
defined('PLUGIN_RUNTIME') || exit;

const PRS_IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const PRS_VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'm4v'];
const PRS_IMAGE_MAX_BYTES = 25 * 1024 * 1024;
const PRS_VIDEO_MAX_BYTES = 500 * 1024 * 1024;
const PRS_IMAGE_MAX_PIXELS = 40_000_000; // au-delà, GD dépasserait la mémoire d'un hébergement mutualisé

/** Une clé Gemini est-elle configurée ? (Tolère un site où la constante n'existe pas.) */
function prs_ai_ready(): bool
{
    return defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '';
}

function prs_root(): string
{
    return dirname(__DIR__, 3);
}

function prs_dir(string $rel): string
{
    $dir = prs_root() . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('dossier impossible à créer : ' . $rel);
    return $dir;
}

/** Dossier de travail (morceaux d'envoi, imports) : data/ n'est jamais servi ni déployé. */
function prs_tmp_dir(): string
{
    return prs_dir('data/rs-tmp');
}

/** Type réel d'un fichier (contenu, pas extension) : ['type' => photo|video, 'mime', 'ext', 'width', 'height'] ou null. */
function prs_detect(string $file): ?array
{
    if (!function_exists('finfo_open')) return null;
    $mime = (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file);
    if (isset(PRS_IMAGE_TYPES[$mime])) {
        $info = @getimagesize($file);
        if (!$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) return null;
        return ['type' => 'photo', 'mime' => $mime, 'ext' => PRS_IMAGE_TYPES[$mime], 'width' => $info[0], 'height' => $info[1]];
    }
    if (isset(PRS_VIDEO_TYPES[$mime])) return ['type' => 'video', 'mime' => $mime, 'ext' => PRS_VIDEO_TYPES[$mime], 'width' => null, 'height' => null];
    return null;
}

/** L'adresse IP est-elle publique (ni privée, ni réservée, ni locale) ? */
function prs_public_ip(string $ip): bool
{
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/**
 * Télécharge un fichier depuis une adresse web vers un fichier temporaire, sans jamais joindre le réseau interne :
 * http(s) seulement, ports 80/443, adresse résolue une fois et imposée à cURL (pas de changement d'adresse en cours de route),
 * redirections suivies à la main (3 au plus) et revérifiées, taille plafonnée. Renvoie [chemin temporaire, nom de fichier].
 */
function prs_fetch_url(string $url, int $maxBytes): array
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
        if (!$ips || array_filter($ips, static fn ($ip) => !prs_public_ip($ip))) throw new InvalidArgumentException('Adresse refusée (serveur introuvable ou interne).');
        $tmp = tempnam(prs_tmp_dir(), 'imp');
        $fh = fopen($tmp, 'wb');
        $size = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; publication-boutique)',
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
function prs_tmp_cleanup(): void
{
    foreach (glob(prs_tmp_dir() . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 86400) @unlink($f);
    }
}
