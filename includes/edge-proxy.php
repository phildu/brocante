<?php

// Requêtes qui arrivent par Cloudflare (proxy devant bricbrok.com).
//
// 1. Adresse du visiteur : chez OVH, REMOTE_ADDR contient déjà l'IP réelle du
//    visiteur. Si une IP Cloudflare arrive tout de même (autre hébergeur),
//    CF-Connecting-IP n'est cru que si la connexion vient bien d'une plage
//    Cloudflare ; sinon il serait falsifiable (limites de tentatives).
// 2. Sous-domaines des boutiques : l'hébergement OVH ne sert pas les noms qu'il
//    ne connaît pas. Cloudflare réécrit donc le Host de nom.bricbrok.com en
//    bricbrok.com et place le nom d'origine dans X-Orig-Host. On ne le reprend
//    que s'il est un sous-domaine direct de l'hôte reçu (nom.<hôte>), ce qui
//    évite qu'un en-tête forgé désigne un autre domaine. OVH masque l'IP du
//    proxy : on ne peut pas exiger qu'elle soit celle de Cloudflare.

const EDGE_PROXY_CLOUDFLARE_RANGES = [
    '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
    '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
    '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
    '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
    '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
    '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
];

/** Vrai si $ip appartient à la plage CIDR $cidr (IPv4 ou IPv6). */
function edge_proxy_ip_in_range(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr) + [1 => ''];
    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b) || !ctype_digit($bits)) {
        return false;
    }
    $bits = (int) $bits;
    $bytes = intdiv($bits, 8);
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) {
        return false;
    }
    $rest = $bits % 8;
    if ($rest === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $rest)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** Vrai si $ip est une adresse de serveur Cloudflare. */
function edge_proxy_is_cloudflare(string $ip): bool
{
    foreach (EDGE_PROXY_CLOUDFLARE_RANGES as $range) {
        if (edge_proxy_ip_in_range($ip, $range)) {
            return true;
        }
    }
    return false;
}

/**
 * Valeurs de $_SERVER à remplacer pour une requête passée par Cloudflare :
 * ['REMOTE_ADDR' => …, 'HTTP_HOST' => …] (clés absentes si rien à changer).
 *
 * @param array<string,mixed> $server
 * @return array<string,string>
 */
function edge_proxy_overrides(array $server): array
{
    $out = [];
    $remote = (string) ($server['REMOTE_ADDR'] ?? '');
    $viaCloudflare = $remote !== '' && edge_proxy_is_cloudflare($remote);

    $client = trim((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($viaCloudflare && $client !== '' && filter_var($client, FILTER_VALIDATE_IP) !== false) {
        $out['REMOTE_ADDR'] = $client;
    }

    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')));
    $base = (string) preg_replace('/^www\./', '', $host);
    $orig = strtolower(trim((string) ($server['HTTP_X_ORIG_HOST'] ?? '')));
    $orig = (string) preg_replace('/:\d+$/', '', $orig);
    if ($base !== '' && $orig !== ''
        && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.' . preg_quote($base, '/') . '$/', $orig) === 1) {
        $out['HTTP_HOST'] = $orig;
    }

    return $out;
}

/** Applique les remplacements à $_SERVER (appelé une fois, très tôt). */
function edge_proxy_apply(): void
{
    foreach (edge_proxy_overrides($_SERVER) as $key => $value) {
        $_SERVER[$key] = $value;
    }
}

edge_proxy_apply();
