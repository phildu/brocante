<?php

// Lecture d'un site existant (page d'accueil) pour proposer une fiche d'annuaire ou brancher un connecteur catalogue.
// Le site est lu une seule fois, sans JavaScript : balises meta, données structurées JSON-LD, images, adresse, téléphone,
// et indices de la solution e-commerce (Shopify, WooCommerce, PrestaShop, boutique brocs).
//
// Sécurité : seules les adresses http(s) publiques sont acceptées ; les redirections sont suivies à la main (3 au plus) et
// l'adresse IP est vérifiée à chaque saut (pas de lecture de services internes) ; la réponse est plafonnée (1 Mo).

const SITE_ANALYZER_MAX_BYTES = 1048576;
const SITE_ANALYZER_UA = 'BricbrokBot/1.0 (+https://bricbrok.com; annuaire)';

/** Poste de développement (config.local.php présent, jamais déployé). */
function site_analyzer_dev(): bool
{
    return is_file(dirname(__DIR__) . '/config.local.php');
}

/** Adresse lisible d'un site : http(s), hôte public, sans identifiants. */
function site_analyzer_url_valid(string $url): bool
{
    $p = parse_url(trim($url));
    if (!$p || !in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) return false;
    if (isset($p['user']) || isset($p['pass'])) return false;
    return site_analyzer_host_allowed(strtolower((string) $p['host']));
}

/** Hôte autorisé : public (ou local en développement). */
function site_analyzer_host_allowed(string $host): bool
{
    $host = rtrim($host, '.');
    if ($host === '') return false;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            || site_analyzer_dev();
    }
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
        return site_analyzer_dev();
    }
    return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
}

/** Adresses IPv4 publiques de l'hôte, ou null si aucune (domaine inconnu, adresse privée sans développement). */
function site_analyzer_public_ips(string $host): ?array
{
    $host = trim($host, '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ips = [$host];
    } else {
        $found = @gethostbynamel($host);
        $ips = is_array($found) ? $found : [];
        if (!$ips) {
            foreach ((array) @dns_get_record($host, DNS_A) as $r) {
                if (!empty($r['ip'])) $ips[] = (string) $r['ip'];
            }
        }
    }
    $out = [];
    foreach (array_unique($ips) as $ip) {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        if ($public || site_analyzer_dev()) $out[] = $ip;
    }
    return $out ?: null;
}

/**
 * Requête HTTP sûre : redirections contrôlées, IP figée, taille plafonnée.
 * Retourne ['url','status','content_type','body','truncated'] ou null.
 */
function site_analyzer_fetch(string $url, array $opts = []): ?array
{
    $maxBytes = max(4096, (int) ($opts['max_bytes'] ?? SITE_ANALYZER_MAX_BYTES));
    $accept = (string) ($opts['accept'] ?? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5');
    $redirects = max(0, min(5, (int) ($opts['redirects'] ?? 3)));
    $timeout = max(2, min(20, (int) ($opts['timeout'] ?? 8)));
    $connect = max(1, min(10, (int) ($opts['connect_timeout'] ?? 4)));
    $current = trim($url);

    for ($hop = 0; $hop <= $redirects; $hop++) {
        if (!site_analyzer_url_valid($current)) return null;
        $p = parse_url($current);
        $host = strtolower((string) ($p['host'] ?? ''));
        $port = isset($p['port']) ? (int) $p['port'] : (($p['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $ips = site_analyzer_public_ips($host);
        if (!$ips) return null;

        $body = '';
        $truncated = false;
        $ch = curl_init($current);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connect,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => (string) ($opts['user_agent'] ?? SITE_ANALYZER_UA),
            CURLOPT_HTTPHEADER => array_merge(['Accept: ' . $accept, 'Accept-Language: fr-FR,fr;q=0.9'], (array) ($opts['headers'] ?? [])),
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],
        ]);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$body, &$truncated, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $take = max(0, $maxBytes - strlen($body));
                if ($take > 0) $body .= substr($chunk, 0, $take);
                $truncated = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        });
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        if ($status >= 300 && $status < 400 && $location !== '') {
            $current = site_analyzer_resolve_url($current, $location);
            continue;
        }
        if ($status !== 200 || $body === '') return null;
        return ['url' => $current, 'status' => $status, 'content_type' => $type, 'body' => $body, 'truncated' => $truncated];
    }
    return null;
}

/** Adresse absolue d'un lien relatif, ou '' si le lien n'est pas http(s). */
function site_analyzer_resolve_url(string $base, string $rel): string
{
    $rel = trim(html_entity_decode($rel, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($rel === '' || str_starts_with($rel, '#') || preg_match('#^(?:javascript|data|mailto|tel):#i', $rel)) return '';
    if (preg_match('#^https?://#i', $rel)) return $rel;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return '';
    $scheme = (string) ($b['scheme'] ?? 'https');
    $authority = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($rel, '//')) return $scheme . ':' . $rel;
    if (str_starts_with($rel, '/')) return $authority . $rel;
    $path = (string) ($b['path'] ?? '/');
    $dir = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';
    if (str_starts_with($rel, '?')) return $authority . $path . $rel;
    $full = $dir . $rel;
    $parts = [];
    foreach (explode('/', $full) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') array_pop($parts);
        else $parts[] = $seg;
    }
    return $authority . '/' . implode('/', $parts);
}

function site_analyzer_text(?string $s, int $max = 300): string
{
    $s = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return mb_substr($s, 0, $max);
}

/** Premier texte non vide d'une valeur JSON-LD (chaîne, tableau, objet à clé value/name). */
function site_analyzer_ld_text($value, int $max = 300): string
{
    if (is_string($value)) return site_analyzer_text($value, $max);
    if (is_array($value)) {
        foreach ($value as $item) {
            $t = site_analyzer_ld_text($item, $max);
            if ($t !== '') return $t;
        }
    }
    return '';
}

/** Première adresse d'une valeur JSON-LD (chaîne, ImageObject, tableau). */
function site_analyzer_ld_url($value): string
{
    if (is_string($value)) return $value;
    if (is_array($value)) {
        foreach (['url', 'contentUrl', 'src', '@id'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) return $value[$key];
        }
        foreach ($value as $item) {
            $u = site_analyzer_ld_url($item);
            if ($u !== '') return $u;
        }
    }
    return '';
}

/** Nœuds JSON-LD aplatis (gère @graph et les tableaux imbriqués). */
function site_analyzer_ld_nodes($value, array &$out = []): array
{
    if (!is_array($value)) return $out;
    if (isset($value['@graph'])) site_analyzer_ld_nodes($value['@graph'], $out);
    if (isset($value['@type'])) $out[] = $value;
    foreach ($value as $k => $item) {
        if ($k === '@graph' || !is_array($item)) continue;
        if (isset($item['@type']) || array_is_list($item)) site_analyzer_ld_nodes($item, $out);
    }
    return $out;
}

/** Solution e-commerce reconnue d'après le code de la page. */
function site_analyzer_platform(string $html, string $url): string
{
    $all = $html . ' ' . $url;
    $tests = [
        'shopify' => '#cdn\.shopify\.com|Shopify\.theme|shopify-section|myshopify\.com|shopify-pc__#i',
        'woocommerce' => '#woocommerce|wp-content/plugins/woocommerce|wc-block-|wp-json/wc/store#i',
        'prestashop' => '#prestashop|var\s+prestashop|/modules/ps_|id_product|blockcart#i',
        'wix' => '#wix\.com|wixstatic\.com|X-Wix#i',
        'squarespace' => '#squarespace\.com|static1\.squarespace#i',
    ];
    foreach ($tests as $name => $pattern) {
        if (preg_match($pattern, $all)) return $name;
    }
    return 'generic';
}

/**
 * Extrait la fiche d'un site depuis son HTML. Toutes les adresses rendues sont absolues et http(s).
 * @return array{name:string,tagline:string,description:string,address:string,phone:string,email:string,hours:string,
 *   logo:string,image:string,images:array,socials:array,accent:string,platform:string,url:string}
 */
function site_analyzer_extract(string $html, string $baseUrl): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $charset = 'UTF-8';
    if (preg_match('/charset\s*=\s*["\']?([a-z0-9._-]+)/i', $html, $m)) $charset = strtoupper($m[1]);
    if (!in_array($charset, ['UTF-8', 'UTF8'], true)) {
        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        if (is_string($converted)) $html = $converted;
    }
    // Sans déclaration, libxml lit le HTML en ISO-8859-1 : le texte étant désormais en UTF-8, on impose le bon encodage.
    if (preg_match('/charset\s*=\s*["\']?[a-z0-9._-]+/i', $html)) {
        $html = (string) preg_replace('/charset\s*=\s*["\']?[a-z0-9._-]+/i', 'charset=utf-8', $html, 1);
    } else {
        $html = '<meta charset="utf-8">' . $html;
    }
    @$dom->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $meta = [];
    foreach ($dom->getElementsByTagName('meta') as $tag) {
        $key = strtolower((string) ($tag->getAttribute('property') ?: $tag->getAttribute('name') ?: $tag->getAttribute('itemprop')));
        $content = trim((string) $tag->getAttribute('content'));
        if ($key !== '' && $content !== '' && !isset($meta[$key])) $meta[$key] = $content;
    }
    $title = '';
    foreach ($dom->getElementsByTagName('title') as $tag) {
        $title = site_analyzer_text($tag->textContent, 160);
        break;
    }

    $ld = [];
    foreach ($dom->getElementsByTagName('script') as $script) {
        if (strtolower((string) $script->getAttribute('type')) !== 'application/ld+json') continue;
        $raw = trim((string) $script->textContent);
        if ($raw === '') continue;
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = json_decode((string) preg_replace('/[\x00-\x1f]+/', ' ', $raw), true);
        }
        if (is_array($data)) site_analyzer_ld_nodes($data, $ld);
    }

    $businessTypes = ['localbusiness', 'store', 'organization', 'onlinestore', 'clothingstore', 'bookstore', 'furniturestore', 'jewelrystore', 'antiquestore', 'artgallery', 'restaurant', 'professionalservice'];
    $business = null;
    $best = -1;
    foreach ($ld as $node) {
        $types = array_map('strtolower', array_map('strval', (array) ($node['@type'] ?? [])));
        $score = 0;
        foreach ($types as $type) {
            if (in_array($type, $businessTypes, true)) $score += in_array($type, ['localbusiness', 'store', 'onlinestore'], true) ? 3 : 1;
        }
        if ($score > $best) { $best = $score; $business = $node; }
    }

    $pick = static function (array $keys, array $source): string {
        foreach ($keys as $key) {
            if (isset($source[$key])) {
                $v = site_analyzer_ld_text($source[$key]);
                if ($v !== '') return $v;
            }
        }
        return '';
    };

    $name = $pick(['name', 'legalName'], $business ?: []);
    if ($name === '') $name = trim((string) ($meta['og:site_name'] ?? ''));
    if ($name === '') $name = site_analyzer_text((string) ($meta['og:title'] ?? ''), 80);
    if ($name === '') $name = $title;
    $name = mb_substr($name, 0, 80);

    $description = $pick(['description'], $business ?: []);
    if ($description === '') $description = trim((string) ($meta['description'] ?? $meta['og:description'] ?? ''));
    $description = site_analyzer_text($description, 400);
    $tagline = site_analyzer_text((string) ($meta['og:description'] ?? $description), 160);

    $address = '';
    if ($business && isset($business['address'])) {
        $a = $business['address'];
        if (is_string($a)) {
            $address = site_analyzer_text($a, 160);
        } elseif (is_array($a)) {
            $lines = [];
            foreach (['streetAddress', 'postalCode', 'addressLocality', 'addressCountry'] as $k) {
                $v = site_analyzer_ld_text($a[$k] ?? '');
                if ($v !== '') $lines[] = $v;
            }
            if ($lines) $address = site_analyzer_text($lines[0] . (count($lines) > 1 ? ', ' . implode(' ', array_slice($lines, 1)) : ''), 160);
        }
    }
    if ($address === '') {
        foreach ($dom->getElementsByTagName('address') as $tag) {
            $address = site_analyzer_text($tag->textContent, 160);
            if ($address !== '') break;
        }
    }

    $phone = $pick(['telephone'], $business ?: []);
    $email = $pick(['email'], $business ?: []);
    $hours = $pick(['openingHours'], $business ?: []);
    if ($hours === '' && isset($business['openingHoursSpecification'])) {
        $parts = [];
        foreach ((array) $business['openingHoursSpecification'] as $spec) {
            if (!is_array($spec)) continue;
            $days = array_map(static fn ($d) => is_string($d) ? preg_replace('#https?://schema\.org/#i', '', $d) : '', (array) ($spec['dayOfWeek'] ?? []));
            $opens = site_analyzer_ld_text($spec['opens'] ?? '');
            $closes = site_analyzer_ld_text($spec['closes'] ?? '');
            if ($opens !== '' || $closes !== '') $parts[] = implode(', ', array_filter($days)) . ' ' . $opens . '–' . $closes;
        }
        $hours = site_analyzer_text(implode(' · ', $parts), 200);
    }

    $socials = [];
    foreach ((array) ($business['sameAs'] ?? []) as $same) {
        $u = site_analyzer_ld_url($same);
        if (preg_match('#^https?://#i', $u)) $socials[] = $u;
    }
    foreach ($dom->getElementsByTagName('a') as $a) {
        $href = site_analyzer_resolve_url($baseUrl, (string) $a->getAttribute('href'));
        if ($href !== '' && preg_match('#^https?://(?:www\.)?(?:facebook|instagram|twitter|x|linkedin|tiktok|youtube|pinterest)\.#i', $href)) $socials[] = $href;
        if ($phone === '') {
            $tel = trim((string) $a->getAttribute('href'));
            if (str_starts_with($tel, 'tel:')) $phone = site_analyzer_text(substr($tel, 4), 40);
        }
        if ($email === '') {
            $mail = trim((string) $a->getAttribute('href'));
            if (str_starts_with($mail, 'mailto:')) $email = filter_var(explode('?', substr($mail, 7))[0], FILTER_VALIDATE_EMAIL) ?: '';
        }
    }
    $socials = array_slice(array_values(array_unique($socials)), 0, 6);

    $logo = '';
    $image = '';
    $images = [];
    $add_image = static function (string $u) use (&$images, $baseUrl): void {
        $u = site_analyzer_resolve_url($baseUrl, $u);
        if ($u !== '' && preg_match('#^https?://#i', $u) && !in_array($u, $images, true) && count($images) < 10) $images[] = $u;
    };
    if ($business) {
        $logo = site_analyzer_ld_url($business['logo'] ?? '');
        $image = site_analyzer_ld_url($business['image'] ?? '');
    }
    if ($image === '') $image = (string) ($meta['og:image'] ?? $meta['twitter:image'] ?? '');
    if ($image !== '') $image = site_analyzer_resolve_url($baseUrl, $image);
    if ($logo !== '') $logo = site_analyzer_resolve_url($baseUrl, $logo);
    if ($logo === '') {
        foreach ($dom->getElementsByTagName('link') as $link) {
            $rel = strtolower((string) $link->getAttribute('rel'));
            if (!in_array($rel, ['apple-touch-icon', 'icon', 'shortcut icon', 'mask-icon'], true)) continue;
            $candidate = site_analyzer_resolve_url($baseUrl, (string) $link->getAttribute('href'));
            if ($candidate !== '') { $logo = $candidate; if ($rel === 'apple-touch-icon') break; }
        }
    }
    if ($logo === '' && $image !== '') $logo = $image;
    if ($image !== '') $add_image($image);
    foreach ($dom->getElementsByTagName('img') as $img) {
        $src = (string) ($img->getAttribute('src') ?: $img->getAttribute('data-src'));
        if ($src === '' && (string) $img->getAttribute('srcset') !== '') $src = explode(',', (string) $img->getAttribute('srcset'))[0];
        $src = trim(explode(' ', $src)[0]);
        $alt = strtolower((string) $img->getAttribute('alt'));
        $class = strtolower((string) $img->getAttribute('class'));
        if ($src !== '' && (str_contains($alt, 'logo') || str_contains($class, 'logo')) && $logo === '') $logo = site_analyzer_resolve_url($baseUrl, $src);
        $add_image($src);
    }

    $accent = (string) ($meta['theme-color'] ?? '');
    $accent = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtolower($accent) : '';

    return [
        'name' => $name,
        'tagline' => $tagline,
        'description' => $description,
        'address' => $address,
        'phone' => site_analyzer_text($phone, 40),
        'email' => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ?: '',
        'hours' => site_analyzer_text($hours, 200),
        'logo' => preg_match('#^https?://#i', $logo) ? $logo : '',
        'image' => preg_match('#^https?://#i', $image) ? $image : '',
        'images' => $images,
        'socials' => $socials,
        'accent' => $accent,
        'platform' => site_analyzer_platform($html, $baseUrl),
        'url' => $baseUrl,
    ];
}

/** Sondage des flux publics d'un site (Shopify, WooCommerce, boutique brocs). PrestaShop demande une clé : non sondé ici. */
function site_analyzer_probe_feeds(string $url): array
{
    $origin = site_analyzer_origin($url);
    $feeds = ['shopify' => false, 'woocommerce' => false, 'prestashop' => false, 'brocs' => false];
    $json = site_analyzer_fetch($origin . '/products.json?limit=1', ['accept' => 'application/json', 'max_bytes' => 65536, 'timeout' => 5]);
    if ($json) {
        $data = json_decode($json['body'], true);
        $feeds['shopify'] = is_array($data) && isset($data['products']) && is_array($data['products']);
    }
    if (!$feeds['shopify']) {
        $woo = site_analyzer_fetch($origin . '/wp-json/wc/store/v1/products?per_page=1', ['accept' => 'application/json', 'max_bytes' => 65536, 'timeout' => 5]);
        if ($woo) {
            $data = json_decode($woo['body'], true);
            $feeds['woocommerce'] = is_array($data) && array_is_list($data);
        }
    }
    if (!$feeds['shopify'] && !$feeds['woocommerce']) {
        $brocs = site_analyzer_fetch($origin . '/catalogue.php', ['accept' => 'application/json', 'max_bytes' => 262144, 'timeout' => 5]);
        if ($brocs) {
            $data = json_decode($brocs['body'], true);
            $feeds['brocs'] = is_array($data) && isset($data['shop'], $data['products']) && is_array($data['products']);
        }
    }
    return $feeds;
}

function site_analyzer_origin(string $url): string
{
    $p = parse_url($url);
    $scheme = strtolower((string) ($p['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
    return $scheme . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
}

/**
 * Analyse complète d'un site : fiche + flux reconnus. $opts['probe'] = false pour ne pas sonder les flux.
 * Retourne null si la page est injoignable ou n'est pas du HTML.
 */
function site_analyzer_analyze(string $url, array $opts = []): ?array
{
    $url = trim($url);
    if (!site_analyzer_url_valid($url)) return null;
    $res = site_analyzer_fetch($url, $opts);
    if (!$res) return null;
    $type = strtolower((string) ($res['content_type'] ?? ''));
    if ($type !== '' && !str_contains($type, 'html') && !str_contains($type, 'xml')) return null;
    $profile = site_analyzer_extract($res['body'], $res['url']);
    $platform = $profile['platform'];
    $feeds = ['shopify' => $platform === 'shopify', 'woocommerce' => $platform === 'woocommerce', 'prestashop' => $platform === 'prestashop', 'brocs' => false];
    if (($opts['probe'] ?? true) && in_array($platform, ['generic', 'shopify', 'woocommerce'], true)) {
        $probed = site_analyzer_probe_feeds($res['url']);
        foreach ($probed as $k => $v) if ($v) $feeds[$k] = true;
    }
    $profile['platform'] = $feeds['shopify'] ? 'shopify' : ($feeds['woocommerce'] ? 'woocommerce' : ($feeds['prestashop'] ? 'prestashop' : ($feeds['brocs'] ? 'brocs' : $platform)));
    $profile['feeds'] = $feeds;
    $profile['final_url'] = $res['url'];
    $profile['fetched_at'] = time();
    return $profile;
}