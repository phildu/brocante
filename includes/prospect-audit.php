<?php

// Audit public des sites web et des pages sociales des prospects.
//
// Le moteur ne dépend pas de l'application : la commande scripts/audit-prospects.php
// l'alimente avec un export CSV ou la base du carnet de vente, et les tests
// l'utilisent directement. Il ne se connecte à aucun compte : il lit seulement
// les pages publiques. Facebook, Instagram et TikTok renvoient souvent un mur de
// connexion ; ces pages sont alors marquées « non vérifiée » et les chiffres
// peuvent être complétés à la main dans le fichier d'entrée.
//
// Score web (0-100) : disponibilité et sécurité, mobile, performance, contenu et
// référencement, fraîcheur, conversion et contact, accessibilité de base.
// Score réseaux sociaux (0-100) : couverture, complétude des profils, audience,
// activité. La fiche Google est traitée comme un canal local à part.
// Priorité de prospection = besoin (100 - qualité globale) pondéré par la valeur
// du commerce et sa joignabilité. Un commerce joignable avec un site faible passe
// donc avant un commerce déjà bien équipé.

const PA_VERSION = '1.0';
const PA_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const PA_MAX_BODY = 2500000;

const PA_PLATFORMS = [
    'facebook' => ['label' => 'Facebook', 'manual_followers' => 'fb_followers'],
    'instagram' => ['label' => 'Instagram', 'manual_followers' => 'ig_followers'],
    'tiktok' => ['label' => 'TikTok', 'manual_followers' => 'tiktok_followers'],
    'youtube' => ['label' => 'YouTube', 'manual_followers' => 'youtube_followers'],
    'linkedin' => ['label' => 'LinkedIn', 'manual_followers' => 'linkedin_followers'],
    'google' => ['label' => 'Fiche Google', 'manual_followers' => null],
];

/** Adresse saisissable par un humain -> URL http(s), ou '' si ce n'en est pas une. */
function pa_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $url)) return '';
    $url = preg_replace('/\s+/', '', $url);
    if (str_starts_with($url, '//')) $url = 'https:' . $url;
    elseif (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    $p = parse_url($url);
    if (!$p || empty($p['host'])) return '';
    return $url;
}

/** Adresse locale, privée ou réservée : refusée par défaut (l'outil n'a pas à scanner le réseau interne). */
function pa_is_private_host(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) return true;
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if ($host === '::1' || str_starts_with($host, 'fe80:') || str_starts_with($host, 'fc') || str_starts_with($host, 'fd')) return true;
        if (preg_match('/^(10\.|127\.|0\.|169\.254\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $host)) return true;
    }
    return false;
}

/** Rend une adresse relative absolue (pour les liens trouvés dans la page). */
function pa_absolutize(string $href, string $base): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($href === '' || str_starts_with($href, '#') || preg_match('~^(mailto:|tel:|javascript:|data:)~i', $href)) return '';
    if (str_starts_with($href, '//')) {
        $scheme = (string) parse_url($base, PHP_URL_SCHEME) ?: 'https';
        return $scheme . ':' . $href;
    }
    if (preg_match('~^https?://~i', $href)) return $href;
    $p = parse_url($base);
    $scheme = (string) ($p['scheme'] ?? 'https');
    $host = (string) ($p['host'] ?? '');
    if ($host === '') return '';
    if (str_starts_with($href, '/')) return $scheme . '://' . $host . $href;
    $dir = isset($p['path']) ? rtrim(dirname($p['path']), '/') : '';
    return $scheme . '://' . $host . ($dir !== '' ? $dir : '') . '/' . $href;
}

/** Télécharge une page publique. Body plafonné, redirections suivies, erreurs remontées sans exception. */
function pa_fetch(string $url, array $opts = []): array
{
    $url = pa_normalize_url($url);
    $timeout = max(3, (int) ($opts['timeout'] ?? 12));
    $maxBytes = max(20000, (int) ($opts['max_bytes'] ?? PA_MAX_BODY));
    $out = [
        'url' => $url, 'final_url' => $url, 'status' => 0, 'error' => '', 'ttfb_ms' => 0, 'total_ms' => 0,
        'bytes' => 0, 'content_type' => '', 'redirects' => 0, 'headers' => [], 'body' => '', 'truncated' => false,
    ];
    if ($url === '') {
        $out['error'] = 'Adresse vide';
        return $out;
    }
    if (!empty($opts['block_private']) && pa_is_private_host($url)) {
        $out['error'] = 'Adresse locale ou privée ignorée';
        return $out;
    }
    $body = '';
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 6,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => (string) ($opts['user_agent'] ?? PA_UA),
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: fr-FR,fr;q=0.9,en;q=0.7',
            'Cache-Control: no-cache',
        ],
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
            if (preg_match('~^HTTP/\S+\s+\d{3}~', $line)) {
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
            if (strlen($body) >= $maxBytes) return 0;
            $room = $maxBytes - strlen($body);
            $body .= strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk;
            return strlen($chunk) > $room ? 0 : strlen($chunk);
        },
    ]);
    curl_exec($ch);
    $errno = curl_errno($ch);
    $info = curl_getinfo($ch);
    if (is_array($info)) {
        $out['final_url'] = (string) ($info['url'] ?? $url);
        $out['status'] = (int) ($info['http_code'] ?? 0);
        $out['content_type'] = (string) ($info['content_type'] ?? '');
        $out['redirects'] = (int) ($info['redirect_count'] ?? 0);
        $out['ttfb_ms'] = (int) round(((float) ($info['starttransfer_time'] ?? 0)) * 1000);
        $out['total_ms'] = (int) round(((float) ($info['total_time'] ?? 0)) * 1000);
        $out['headers'] = $headers;
    }
    $out['body'] = $body;
    $out['bytes'] = strlen($body);
    $out['truncated'] = strlen($body) >= $maxBytes;
    if ($errno === 23 && $body !== '') {
        $out['truncated'] = true;      // corps trop gros : la partie lue suffit à l'analyse
    } elseif ($errno !== 0) {
        $out['error'] = curl_strerror($errno) ?: ('Erreur réseau ' . $errno);
    } elseif ($out['status'] === 0) {
        $out['error'] = 'Aucune réponse HTTP';
    }
    return $out;
}

/** Analyse le HTML public d'une page : contenu, référencement, mobile, contact, liens sociaux. */
function pa_parse_html(string $html, string $baseUrl = ''): array
{
    $m = [
        'title' => '', 'title_len' => 0, 'meta_description' => '', 'meta_description_len' => 0,
        'viewport' => '', 'robots' => '', 'generator' => '', 'lang' => '', 'canonical' => '',
        'og_title' => '', 'og_description' => '', 'og_image' => '', 'og_site_name' => '',
        'h1' => [], 'h1_count' => 0, 'h2_count' => 0, 'text' => '', 'text_length' => 0,
        'images' => 0, 'images_with_alt' => 0, 'forms' => 0, 'inputs' => 0, 'labels' => 0,
        'tel_links' => 0, 'mailto_links' => 0, 'anchors' => 0, 'has_skip_link' => false,
        'scripts' => 0, 'inline_js_bytes' => 0, 'stylesheets' => 0, 'inline_css_bytes' => 0,
        'resources' => 0, 'external_scripts' => 0, 'mixed_content' => 0, 'analytics' => false,
        'jsonld_types' => [], 'has_local_business' => false, 'tables' => 0, 'fonts' => 0,
        'has_flash' => false, 'has_frameset' => false, 'jquery_old' => false, 'legacy_layout' => false,
        'placeholder' => false, 'copyright_year' => 0, 'has_ecommerce' => false, 'has_hours' => false,
        'has_postal' => false, 'has_media_query' => false, 'has_srcset' => false, 'cms' => '',
        'links' => [], 'social_links' => [], 'js_heavy' => false,
    ];
    if (trim($html) === '') return $m;

    if (!preg_match('~<meta[^>]+charset~i', substr($html, 0, 8000))) {
        $html = preg_replace('~<head\b[^>]*>~i', '$0<meta charset="utf-8">', $html, 1) ?? $html;
    }
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $loaded = $doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return $m;

    $xp = new DOMXPath($doc);
    $one = static function (string $query) use ($xp): string {
        try {
            return trim((string) $xp->evaluate('string(' . $query . ')'));
        } catch (Throwable) {
            return '';
        }
    };

    $m['title'] = $one('//title');
    $m['title_len'] = mb_strlen($m['title']);
    $m['lang'] = strtolower(trim((string) ($doc->documentElement?->getAttribute('lang') ?? '')));

    $meta = [];
    foreach ($xp->query('//meta') as $node) {
        $name = strtolower(trim($node->getAttribute('name')));
        $property = strtolower(trim($node->getAttribute('property')));
        $equiv = strtolower(trim($node->getAttribute('http-equiv')));
        $key = $name !== '' ? $name : ($property !== '' ? $property : $equiv);
        if ($key !== '' && !isset($meta[$key])) $meta[$key] = trim($node->getAttribute('content'));
    }
    $m['meta_description'] = $meta['description'] ?? '';
    $m['meta_description_len'] = mb_strlen($m['meta_description']);
    $m['viewport'] = $meta['viewport'] ?? '';
    $m['robots'] = $meta['robots'] ?? '';
    $m['generator'] = $meta['generator'] ?? '';
    $m['og_title'] = $meta['og:title'] ?? ($meta['twitter:title'] ?? '');
    $m['og_description'] = $meta['og:description'] ?? ($meta['twitter:description'] ?? '');
    $m['og_image'] = $meta['og:image'] ?? ($meta['twitter:image'] ?? '');
    $m['og_site_name'] = $meta['og:site_name'] ?? '';
    $m['canonical'] = $one('//link[@rel="canonical"]/@href');

    $seenHrefs = [];
    foreach ($xp->query('//a[@href]') as $a) {
        $m['anchors']++;
        $href = trim($a->getAttribute('href'));
        $text = trim(preg_replace('/\s+/u', ' ', $a->textContent) ?? '');
        if ($href === '') continue;
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme === 'tel') $m['tel_links']++;
        if ($scheme === 'mailto') $m['mailto_links']++;
        if (!$m['has_skip_link'] && str_starts_with($href, '#') && preg_match('/(contenu|skip|aller|main)/iu', $text)) $m['has_skip_link'] = true;
        $abs = pa_absolutize($href, $baseUrl);
        if ($abs === '') continue;
        if (!isset($seenHrefs[$abs])) {
            $seenHrefs[$abs] = true;
            $m['links'][] = $abs;
        }
    }

    foreach ($xp->query('//img') as $img) {
        $m['images']++;
        if ($img->hasAttribute('alt')) $m['images_with_alt']++;
        $src = trim($img->getAttribute('src')) ?: trim($img->getAttribute('data-src'));
        if ($src !== '' && preg_match('~^http://~i', $src) && !preg_match('~^http://(www\.)?w3\.org~i', $src)) $m['mixed_content']++;
        if ($img->hasAttribute('srcset') && trim($img->getAttribute('srcset')) !== '') $m['has_srcset'] = true;
    }
    foreach ($xp->query('//picture') as $_) $m['has_srcset'] = true;
    foreach ($xp->query('//script') as $script) {
        $m['scripts']++;
        $src = trim($script->getAttribute('src'));
        if ($src !== '') {
            $abs = pa_absolutize($src, $baseUrl);
            $host = strtolower((string) parse_url($abs, PHP_URL_HOST));
            $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
            if ($host !== '' && $baseHost !== '' && $host !== $baseHost) $m['external_scripts']++;
            if (preg_match('~jquery[-./]?1\.~i', $src)) $m['jquery_old'] = true;
            if (preg_match('~^http://~i', $src)) $m['mixed_content']++;
        } else {
            $m['inline_js_bytes'] += mb_strlen($script->textContent);
        }
    }
    foreach ($xp->query('//style') as $style) {
        $css = $style->textContent;
        $m['inline_css_bytes'] += mb_strlen($css);
        if (stripos($css, '@media') !== false) $m['has_media_query'] = true;
    }
    foreach ($xp->query('//link[@rel]') as $link) {
        $rel = strtolower(trim($link->getAttribute('rel')));
        $href = trim($link->getAttribute('href'));
        if ($rel === 'stylesheet') {
            $m['stylesheets']++;
            if (preg_match('~^http://~i', $href)) $m['mixed_content']++;
        }
    }
    $assets = [];
    foreach ($xp->query('//*[@src or @href]') as $node) {
        $src = trim($node->getAttribute('src')) ?: trim($node->getAttribute('href'));
        if ($src === '' || str_starts_with($src, '#')) continue;
        $abs = pa_absolutize($src, $baseUrl);
        if ($abs !== '') $assets[$abs] = true;
    }
    $m['resources'] = count($assets);
    $m['tables'] = $xp->query('//table')->length;
    $m['fonts'] = $xp->query('//font')->length;
    $m['has_frameset'] = $xp->query('//frameset | //frame')->length > 0;
    foreach ($xp->query('//embed | //object') as $node) {
        $type = strtolower($node->getAttribute('type') . ' ' . $node->getAttribute('src') . ' ' . $node->getAttribute('data'));
        if (str_contains($type, 'flash') || str_contains($type, '.swf')) $m['has_flash'] = true;
    }
    $m['forms'] = $xp->query('//form')->length;
    $m['inputs'] = $xp->query('//form//input[not(@type="hidden") and not(@type="submit")] | //form//select | //form//textarea')->length;
    $m['labels'] = $xp->query('//label')->length;

    $h1 = [];
    foreach ($xp->query('//h1') as $node) {
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        if ($text !== '') $h1[] = $text;
    }
    $m['h1'] = $h1;
    $m['h1_count'] = count($h1);
    $m['h2_count'] = $xp->query('//h2')->length;

    $text = '';
    foreach ($xp->query('//body//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]') as $node) {
        $text .= ' ' . $node->nodeValue;
    }
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    $m['text'] = mb_substr($text, 0, 20000);
    $m['text_length'] = mb_strlen($text);
    $lowerText = mb_strtolower($m['text']);

    $m['has_ecommerce'] = (bool) preg_match('/(panier|ajouter au panier|add to cart|checkout|mon panier|boutique en ligne|achat en ligne|payer en ligne)/iu', $lowerText);
    $m['has_hours'] = (bool) preg_match('/(horaires|ouvert du|ouvert le|du mardi au|ferm[ée] le|opening hours)/iu', $lowerText);
    $m['has_postal'] = (bool) preg_match('/\b(?:0[1-9]|[1-8]\d|9[0-5])\d{3}\b/u', $m['text']);
    $m['placeholder'] = (bool) preg_match('/(domaine (?:est )?[àa] vendre|site en construction|en construction|coming soon|site en maintenance|default web page|welcome to nginx|index of \/|ce domaine est disponible|parked domain)/iu', mb_strtolower($m['title'] . ' ' . mb_substr($m['text'], 0, 3000)));

    if (preg_match_all('/\b(20\d{2})\b/u', $m['text'], $years)) {
        $valid = array_filter(array_map('intval', $years[1]), static fn (int $y): bool => $y >= 2000 && $y <= (int) date('Y') + 1);
        if ($valid) $m['copyright_year'] = max($valid);
    }

    $raw = str_replace(['\\/', '\\u002F'], '/', $html);
    foreach (['wp-content' => 'WordPress', 'wp-json' => 'WordPress', 'cdn.shopify.com' => 'Shopify', 'shopify.theme' => 'Shopify',
                 'wix.com' => 'Wix', 'static.wixstatic.com' => 'Wix', 'squarespace' => 'Squarespace', 'webflow' => 'Webflow',
                 'prestashop' => 'PrestaShop', 'woocommerce' => 'WooCommerce', 'jimdo' => 'Jimdo', 'joomla' => 'Joomla',
                 'drupal' => 'Drupal', 'eklablog' => 'Eklablog'] as $needle => $label) {
        if (stripos($raw, $needle) !== false && !str_contains($m['cms'], $label)) $m['cms'] .= ($m['cms'] !== '' ? ' · ' : '') . $label;
    }
    if ($m['cms'] === '' && preg_match('/(wordpress|shopify|wix|squarespace|webflow|prestashop|joomla|drupal)/i', $m['generator'], $g)) {
        $m['cms'] = ucfirst(strtolower($g[1]));
    }

    $m['analytics'] = (bool) preg_match('/(gtag\(|googletagmanager|google-analytics|matomo|plausible|umami)/i', $raw);
    $m['has_media_query'] = $m['has_media_query'] || str_contains($raw, '@media');
    $m['legacy_layout'] = $m['has_flash'] || $m['has_frameset'] || $m['tables'] > 8 || $m['fonts'] > 20;
    $m['js_heavy'] = $m['text_length'] < 250 && $m['scripts'] > 8;

    $types = [];
    foreach ($xp->query('//script[@type="application/ld+json"]') as $script) {
        $decoded = json_decode(trim($script->textContent), true);
        if (is_array($decoded)) pa_collect_jsonld_types($decoded, $types);
    }
    $m['jsonld_types'] = array_values(array_unique($types));
    foreach ($types as $type) {
        if (str_contains($type, 'localbusiness') || str_contains($type, 'store') || str_contains($type, 'antique')) {
            $m['has_local_business'] = true;
            break;
        }
    }
    $m['social_links'] = pa_social_from_links($m['links'], $baseUrl, $raw);
    return $m;
}

/** Types Schema.org trouvés dans un bloc JSON-LD (y compris @graph). */
function pa_collect_jsonld_types(mixed $data, array &$types, int $depth = 0): void
{
    if (!is_array($data) || $depth > 8) return;
    if (isset($data['@type'])) {
        foreach ((array) $data['@type'] as $type) {
            if (is_string($type) && trim($type) !== '') $types[] = strtolower(trim($type));
        }
    }
    foreach ($data as $value) {
        if (is_array($value)) pa_collect_jsonld_types($value, $types, $depth + 1);
    }
}

/** Liens de profil sociaux trouvés dans la page, hors boutons de partage et pages de connexion. */
function pa_social_from_links(array $links, string $baseUrl, string $rawHtml = ''): array
{
    $found = [];
    $candidates = $links;
    if ($rawHtml !== '') {
        $scan = str_replace(['\\/', '\\u002F'], '/', $rawHtml);
        if (preg_match_all('~https?://(?:www\.)?(facebook|instagram|tiktok|youtube)\.com/[A-Za-z0-9._@%/?=&+\-]+~i', $scan, $matches)) {
            foreach ($matches[0] as $url) $candidates[] = $url;
        }
        if (preg_match_all('~https?://(?:www\.)?(?:linkedin\.com/(?:company|in|school)/[A-Za-z0-9._%\-]+)~i', $scan, $matches)) {
            foreach ($matches[0] as $url) $candidates[] = $url;
        }
    }
    foreach ($candidates as $href) {
        $p = parse_url($href);
        if (!$p || empty($p['host'])) continue;
        $host = strtolower(preg_replace('/^www\./', '', (string) $p['host']));
        $path = (string) ($p['path'] ?? '/');
        $lowerPath = strtolower($path);
        $platform = '';
        if ($host === 'facebook.com' || str_ends_with($host, '.facebook.com')) {
            if (preg_match('~^/(sharer|share|dialog|plugins|tr|login|help|policies|privacy|groups|events|watch|marketplace|photo|story)~', $lowerPath)) continue;
            $platform = 'facebook';
        } elseif ($host === 'instagram.com' || str_ends_with($host, '.instagram.com')) {
            if (preg_match('~^/(p|reel|reels|explore|accounts|stories|tv|directory|about|legal)~', $lowerPath)) continue;
            $platform = 'instagram';
        } elseif ($host === 'tiktok.com' || str_ends_with($host, '.tiktok.com')) {
            if (!str_starts_with($path, '/@')) continue;
            $platform = 'tiktok';
        } elseif ($host === 'youtube.com' || $host === 'm.youtube.com' || str_ends_with($host, '.youtube.com')) {
            if (!preg_match('~^/(@|channel/|c/|user/)~', $path)) continue;
            $platform = 'youtube';
        } elseif ($host === 'linkedin.com' || str_ends_with($host, '.linkedin.com')) {
            if (!preg_match('~^/(company|in|school)/~', $lowerPath)) continue;
            $platform = 'linkedin';
        } elseif ($host === 'maps.app.goo.gl' || $host === 'g.page' || ($host === 'google.com' && str_contains($lowerPath, '/maps')) || $host === 'goo.gl' && str_contains($lowerPath, '/maps')) {
            $platform = 'google';
        }
        if ($platform === '' || $path === '' || $path === '/') continue;
        $clean = strtolower((string) ($p['scheme'] ?? 'https')) . '://' . $p['host'] . rtrim($path, '/');
        if (!isset($found[$platform]) || strlen($clean) < strlen($found[$platform])) $found[$platform] = $clean;
    }
    return $found;
}

/** Audite un site : état, métriques et score. Une adresse vide donne l'état « absent ». */
function pa_audit_site(?string $url, array $opts = []): array
{
    $url = trim((string) $url);
    $site = [
        'url' => $url, 'state' => 'absent', 'fetch' => null, 'metrics' => pa_parse_html('', ''),
        'score' => 0, 'criteria' => [], 'gaps' => [], 'social_links' => [], 'confidence' => 0,
    ];
    if ($url === '') {
        $scored = pa_score_web($site);
        $site['criteria'] = $scored['criteria'];
        $site['gaps'] = $scored['gaps'];
        $site['confidence'] = $scored['confidence'];
        return $site;
    }

    $fetch = pa_fetch($url, $opts + ['block_private' => true]);
    $site['fetch'] = $fetch;
    if ($fetch['error'] !== '' && $fetch['status'] === 0) {
        $site['state'] = 'inaccessible';
    } elseif ($fetch['status'] >= 400 || $fetch['status'] === 0) {
        $site['state'] = 'erreur';
    } else {
        $type = strtolower($fetch['content_type']);
        if ($type !== '' && !str_contains($type, 'html') && !str_contains($type, 'xhtml')) {
            $site['state'] = 'non_html';
        } else {
            $site['state'] = 'ok';
            $site['metrics'] = pa_parse_html($fetch['body'], $fetch['final_url']);
            $site['social_links'] = $site['metrics']['social_links'];
        }
    }
    $scored = pa_score_web($site);
    $site['score'] = $scored['score'];
    $site['criteria'] = $scored['criteria'];
    $site['gaps'] = $scored['gaps'];
    $site['confidence'] = $scored['confidence'];
    return $site;
}

/** Score du site et détail critère par critère (0-100). */
function pa_score_web(array $site): array
{
    $state = (string) ($site['state'] ?? 'absent');
    $criteria = [];
    $gaps = [];
    $add = static function (string $key, string $label, int $points, int $max, array $notes = []) use (&$criteria, &$gaps): void {
        $points = max(0, min($max, $points));
        $criteria[] = ['key' => $key, 'label' => $label, 'points' => $points, 'max' => $max, 'notes' => $notes];
        if ($points < $max) $gaps[] = ['key' => $key, 'label' => $label, 'lost' => $max - $points, 'detail' => implode(' · ', $notes)];
    };
    if ($state === 'absent') {
        foreach ([['dispo', 'Disponibilité et sécurité', 20], ['mobile', 'Adapté au mobile', 18], ['perf', 'Performance', 18], ['seo', 'Contenu et référencement', 18], ['fraicheur', 'Fraîcheur et maintenance', 10], ['conversion', 'Conversion et contact', 10], ['a11y', 'Accessibilité de base', 6]] as [$k, $l, $max]) {
            $add($k, $l, 0, $max, ['Aucun site web']);
        }
        $gaps = [['key' => 'absent', 'label' => 'Aucun site web', 'lost' => 100, 'detail' => 'Aucune adresse de site renseignée']];
        return ['score' => 0, 'criteria' => $criteria, 'gaps' => $gaps, 'confidence' => 100];
    }
    if ($state !== 'ok') {
        foreach ([['dispo', 'Disponibilité et sécurité', 20], ['mobile', 'Adapté au mobile', 18], ['perf', 'Performance', 18], ['seo', 'Contenu et référencement', 18], ['fraicheur', 'Fraîcheur et maintenance', 10], ['conversion', 'Conversion et contact', 10], ['a11y', 'Accessibilité de base', 6]] as [$k, $l, $max]) {
            $add($k, $l, 0, $max, ['Site non analysable']);
        }
        $label = $state === 'inaccessible' ? 'Site injoignable' : ($state === 'erreur' ? 'Page en erreur' : 'Contenu non HTML');
        return ['score' => 0, 'criteria' => $criteria, 'gaps' => [['key' => $state, 'label' => $label, 'lost' => 100, 'detail' => (string) ($site['fetch']['error'] ?? '')]], 'confidence' => 90];
    }

    $f = $site['fetch'];
    $m = $site['metrics'];
    $https = str_starts_with(strtolower((string) $f['final_url']), 'https://');
    $statusOk = $f['status'] >= 200 && $f['status'] < 300;
    $notes = [];

    $points = 0;
    if ($statusOk) { $points += 6; $notes[] = 'Page servie (' . $f['status'] . ')'; } else { $notes[] = 'Statut HTTP ' . $f['status']; }
    if ($https) { $points += 7; $notes[] = 'HTTPS'; } else { $notes[] = 'Pas de HTTPS'; }
    if ($f['redirects'] <= 2) { $points += 2; } else { $notes[] = $f['redirects'] . ' redirections'; }
    if ($m['mixed_content'] === 0) { $points += 2; } else { $notes[] = $m['mixed_content'] . ' ressource(s) en HTTP'; }
    if (!$m['placeholder']) { $points += 3; } else { $notes[] = 'Page de domaine parqué ou en construction'; }
    $add('dispo', 'Disponibilité et sécurité', $points, 20, $notes);

    $points = 0;
    $notes = [];
    $viewport = strtolower($m['viewport']);
    if (str_contains($viewport, 'width=device-width')) { $points += 12; $notes[] = 'Viewport mobile déclaré'; } else { $notes[] = 'Pas de viewport mobile'; }
    if (!$m['legacy_layout']) { $points += 3; } else { $notes[] = 'Mise en page ancienne (tableaux, Flash ou cadres)'; }
    if ($m['has_srcset'] || $m['has_media_query']) { $points += 3; } else { $notes[] = 'Pas d’images adaptatives détectées'; }
    $add('mobile', 'Adapté au mobile', $points, 18, $notes);

    $points = 0;
    $notes = [];
    $ttfb = (int) $f['ttfb_ms'];
    if ($ttfb <= 400) { $points += 5; $notes[] = 'Réponse en ' . $ttfb . ' ms'; } elseif ($ttfb <= 1000) { $points += 3; $notes[] = 'Réponse en ' . $ttfb . ' ms'; } elseif ($ttfb <= 2500) { $points += 1; $notes[] = 'Réponse en ' . $ttfb . ' ms'; } else { $notes[] = 'Réponse lente (' . $ttfb . ' ms)'; }
    $total = (int) $f['total_ms'];
    if ($total <= 1200) { $points += 5; } elseif ($total <= 3000) { $points += 3; } elseif ($total <= 6000) { $points += 1; } else { $notes[] = 'Page chargée en ' . $total . ' ms'; }
    $bytes = (int) $f['bytes'];
    if ($bytes <= 150000) { $points += 4; } elseif ($bytes <= 500000) { $points += 2; } else { $notes[] = 'HTML lourd (' . round($bytes / 1024) . ' Ko)'; }
    $resources = (int) $m['resources'];
    if ($resources <= 40) { $points += 4; } elseif ($resources <= 100) { $points += 2; } else { $notes[] = $resources . ' ressources référencées'; }
    $add('perf', 'Performance', $points, 18, $notes);

    $points = 0;
    $notes = [];
    if ($m['title_len'] >= 15 && $m['title_len'] <= 65) { $points += 4; } elseif ($m['title_len'] > 0) { $points += 2; $notes[] = 'Titre de ' . $m['title_len'] . ' caractères'; } else { $notes[] = 'Pas de titre'; }
    if ($m['meta_description_len'] >= 50 && $m['meta_description_len'] <= 160) { $points += 4; } elseif ($m['meta_description_len'] > 0) { $points += 2; $notes[] = 'Description de ' . $m['meta_description_len'] . ' caractères'; } else { $notes[] = 'Pas de description'; }
    if ($m['h1_count'] === 1) { $points += 3; } elseif ($m['h1_count'] > 1) { $points += 1; $notes[] = $m['h1_count'] . ' titres H1'; } else { $notes[] = 'Pas de titre H1'; }
    if ($m['lang'] !== '') { $points += 2; } else { $notes[] = 'Langue de page non déclarée'; }
    if ($m['canonical'] !== '') { $points += 2; } else { $notes[] = 'Pas d’adresse canonique'; }
    if ($m['og_title'] !== '' || $m['og_image'] !== '') { $points += 2; } else { $notes[] = 'Pas d’aperçu social (Open Graph)'; }
    if ($m['has_local_business']) { $points += 1; } else { $notes[] = 'Pas de données structurées LocalBusiness'; }
    $add('seo', 'Contenu et référencement', $points, 18, $notes);

    $points = 0;
    $notes = [];
    $year = (int) $m['copyright_year'];
    $current = (int) date('Y');
    if ($year >= $current - 1) { $points += 5; } elseif ($year === $current - 2) { $points += 3; $notes[] = 'Dernière année affichée : ' . $year; } elseif ($year > 0) { $points += 1; $notes[] = 'Dernière année affichée : ' . $year; } else { $notes[] = 'Aucune date de mise à jour'; }
    if (!$m['legacy_layout'] && !$m['jquery_old'] && !$m['has_flash']) { $points += 5; } else { $notes[] = 'Technologies anciennes détectées'; }
    $add('fraicheur', 'Fraîcheur et maintenance', $points, 10, $notes);

    $points = 0;
    $notes = [];
    if ($m['tel_links'] > 0) { $points += 3; } else { $notes[] = 'Pas de lien téléphone cliquable'; }
    if ($m['mailto_links'] > 0 || $m['forms'] > 0) { $points += 3; } else { $notes[] = 'Pas de formulaire ni d’e-mail cliquable'; }
    if ($m['has_postal']) { $points += 2; } else { $notes[] = 'Adresse postale non détectée'; }
    if ($m['has_hours']) { $points += 1; } else { $notes[] = 'Horaires non détectés'; }
    if ($m['has_ecommerce']) { $points += 1; } else { $notes[] = 'Pas de vente en ligne détectée'; }
    $add('conversion', 'Conversion et contact', $points, 10, $notes);

    $points = 0;
    $notes = [];
    $altRatio = $m['images'] > 0 ? $m['images_with_alt'] / $m['images'] : 1;
    if ($altRatio >= 0.8) { $points += 3; } elseif ($altRatio >= 0.5) { $points += 2; $notes[] = round($altRatio * 100) . ' % d’images décrites'; } else { $notes[] = 'Images sans description'; }
    if ($m['inputs'] === 0) { $points += 2; } elseif ($m['labels'] > 0) { $points += 2; } else { $notes[] = 'Champs de formulaire sans étiquette'; }
    if ($m['has_skip_link']) { $points += 1; } else { $notes[] = 'Pas de lien d’évitement'; }
    $add('a11y', 'Accessibilité de base', $points, 6, $notes);

    $score = 0;
    foreach ($criteria as $criterion) $score += $criterion['points'];
    usort($gaps, static fn (array $a, array $b): int => $b['lost'] <=> $a['lost']);
    return ['score' => max(0, min(100, $score)), 'criteria' => $criteria, 'gaps' => $gaps, 'confidence' => $m['js_heavy'] ? 55 : 90];
}

/** Vérifie si la page sociale est derrière une connexion ou un anti-robot. */
function pa_social_blocked(array $fetch, array $metrics, string $html): bool
{
    $final = strtolower((string) $fetch['final_url']);
    if (preg_match('~/(login|checkpoint|accounts/login|signin)~', $final)) return true;
    if (in_array((int) $fetch['status'], [401, 403, 429], true)) return true;
    $title = strtolower(trim((string) $metrics['title']));
    if (in_array($title, ['instagram', 'facebook', 'tiktok', 'log in', 'connexion', 'linkedin login'], true)) return true;
    if (preg_match('~"login_form"|id="login_form"|/accounts/login|/checkpoint~i', $html)) return true;
    return false;
}

/** Nettoie le nom affiché d'un profil social. */
function pa_clean_social_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $name = preg_replace('~\s*[|•·-]\s*(Facebook|Instagram|TikTok|YouTube|LinkedIn).*$~iu', '', $name) ?? $name;
    $name = preg_replace('~\s*\(@[^)]+\).*$~u', '', $name) ?? $name;
    return trim($name);
}

/** Nombre lisible (« 1,2 k », « 12 500 », « 1.5M ») -> entier. */
function pa_human_count(string $raw): ?int
{
    $raw = trim(str_replace(["\xc2\xa0", "\u{202f}"], ' ', $raw));
    if ($raw === '' || !preg_match('/([\d][\d\s.,]*)\s*([kKmM])?/u', $raw, $m)) return null;
    $num = preg_replace('/[^\d.,]/', '', $m[1]) ?? '';
    if ($num === '' || $num === '.' || $num === ',') return null;
    $hasDot = str_contains($num, '.');
    $hasComma = str_contains($num, ',');
    if ($hasDot && $hasComma) {
        if (strrpos($num, '.') > strrpos($num, ',')) $num = str_replace(',', '', $num);
        else $num = str_replace(',', '.', str_replace('.', '', $num));
    } elseif ($hasComma) {
        $after = strlen(substr($num, (int) strrpos($num, ',') + 1));
        $digits = strlen(str_replace(',', '', $num));
        $num = ($after === 3 && $digits > 3) ? str_replace(',', '', $num) : str_replace(',', '.', $num);
    } elseif ($hasDot) {
        $after = strlen(substr($num, (int) strrpos($num, '.') + 1));
        $digits = strlen(str_replace('.', '', $num));
        if ($after === 3 && $digits > 3) $num = str_replace('.', '', $num);
    }
    $value = (float) $num;
    $suffix = strtolower($m[2] ?? '');
    if ($suffix === 'k') $value *= 1000;
    elseif ($suffix === 'm') $value *= 1000000;
    return $value > 0 ? (int) round($value) : null;
}

/** Nombre de jours depuis une date ou une expression relative (« il y a 3 jours », « hier », 2026-10-01). */
function pa_days_since(string $raw): ?int
{
    $raw = trim(mb_strtolower($raw));
    if ($raw === '') return null;
    if (is_numeric($raw)) return max(0, (int) $raw);
    if (preg_match('/(aujourd[’\']?hui|today)/u', $raw)) return 0;
    if (preg_match('/(hier|yesterday)/u', $raw)) return 1;
    if (preg_match('/(?:il y a|there is|ago)\s*(\d+)\s*(jour|jours|semaine|semaines|mois|an|ans|day|days|week|weeks|month|months|year|years)/u', $raw, $m)) {
        $n = (int) $m[1];
        $unit = $m[2];
        if (str_starts_with($unit, 'semaine') || str_starts_with($unit, 'week')) return $n * 7;
        if (str_starts_with($unit, 'mois') || str_starts_with($unit, 'month')) return $n * 30;
        if (str_starts_with($unit, 'an') || str_starts_with($unit, 'year')) return $n * 365;
        return $n;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
        $ts = strtotime($m[1] . '-' . $m[2] . '-' . $m[3]);
        return $ts === false ? null : max(0, (int) floor((time() - $ts) / 86400));
    }
    if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$~', $raw, $m)) {
        $year = (int) $m[3];
        if ($year < 100) $year += 2000;
        $ts = strtotime(sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]));
        return $ts === false ? null : max(0, (int) floor((time() - $ts) / 86400));
    }
    $ts = strtotime($raw);
    return $ts === false ? null : max(0, (int) floor((time() - $ts) / 86400));
}

/** Audite les pages sociales et la fiche Google d'un prospect, et applique les chiffres saisis à la main. */
function pa_audit_social(array $prospect, array $site, array $opts = []): array
{
    $checked = !empty($prospect['social_checked']) || (($site['state'] ?? '') === 'ok');
    $found = (array) ($site['social_links'] ?? []);
    $noSocial = !empty($opts['no_social']);
    $platforms = [];
    $followersTotal = 0;
    $followersKnown = false;

    foreach (PA_PLATFORMS as $key => $def) {
        $url = trim((string) ($prospect[$key] ?? ''));
        if ($url === '' && isset($found[$key])) $url = (string) $found[$key];
        $manualKey = $def['manual_followers'];
        $manualFollowers = $manualKey ? pa_human_count((string) ($prospect[$manualKey] ?? '')) : null;
        $manualRating = $key === 'google' ? (float) str_replace(',', '.', (string) ($prospect['google_rating'] ?? '0')) : 0.0;
        $manualReviews = $key === 'google' ? (pa_human_count((string) ($prospect['google_reviews'] ?? '')) ?? 0) : 0;
        $present = $url !== '' || $manualFollowers !== null || $manualRating > 0 || $manualReviews > 0;
        $p = [
            'key' => $key, 'label' => $def['label'], 'url' => $url, 'present' => $present, 'readable' => false,
            'blocked' => false, 'name' => '', 'bio_len' => null, 'has_image' => null, 'has_link' => null,
            'followers' => $manualFollowers, 'posts' => null, 'last_post_days' => null, 'manual' => $manualFollowers !== null,
            'rating' => $key === 'google' ? $manualRating : null, 'reviews' => $key === 'google' ? $manualReviews : null,
            'confidence' => 100, 'score' => 0, 'notes' => [],
        ];
        if (!$present) {
            $platforms[$key] = $p;
            continue;
        }
        if ($manualFollowers !== null) {
            $followersTotal += $manualFollowers;
            $followersKnown = true;
        }
        if ($key === 'google') {
            $p['readable'] = true;
            $p['confidence'] = ($manualRating > 0 || $manualReviews > 0) ? 85 : 35;
            $p['notes'][] = 'Chiffres saisis à la main';
            $platforms[$key] = $p;
            continue;
        }
        if ($noSocial) {
            $p['confidence'] = 30;
            $p['notes'][] = 'Audit social désactivé';
            $platforms[$key] = $p;
            continue;
        }
        $fetch = pa_fetch($url, ['timeout' => (int) ($opts['social_timeout'] ?? 7), 'max_bytes' => 1500000, 'block_private' => true]);
        $metrics = pa_parse_html($fetch['body'], $fetch['final_url']);
        $p['readable'] = $fetch['status'] >= 200 && $fetch['status'] < 300 && $fetch['error'] === '';
        $p['blocked'] = $p['readable'] ? pa_social_blocked($fetch, $metrics, $fetch['body']) : false;
        if ($p['blocked']) $p['readable'] = false;
        $p['name'] = pa_clean_social_name($metrics['og_title'] !== '' ? $metrics['og_title'] : $metrics['title']);
        $bio = $metrics['og_description'] !== '' ? $metrics['og_description'] : $metrics['meta_description'];
        $p['bio_len'] = $bio !== '' ? mb_strlen($bio) : null;
        $p['has_image'] = $metrics['og_image'] !== '' ? true : null;
        if ($p['readable']) {
            $p['has_link'] = pa_social_has_link($fetch['body'], $bio);
            $p['last_post_days'] = pa_extract_last_post_days($fetch['body'], $key);
            $p['posts'] = pa_extract_posts($fetch['body']);
            $p['confidence'] = 80;
            $p['notes'][] = 'Page publique lue';
        } else {
            $p['confidence'] = $p['blocked'] ? 35 : 25;
            $p['notes'][] = $p['blocked'] ? 'Connexion requise : à vérifier à la main' : 'Page non lisible';
        }
        if ($p['followers'] === null) {
            $p['followers'] = pa_extract_followers($fetch['body'], $key);
            if ($p['followers'] !== null) {
                $followersTotal += $p['followers'];
                $followersKnown = true;
                $p['manual'] = false;
            }
        }
        if ($p['last_post_days'] === null && trim((string) ($prospect['last_post'] ?? '')) !== '') {
            $p['last_post_days'] = pa_days_since((string) $prospect['last_post']);
        }
        $platforms[$key] = $p;
    }

    $social = [
        'checked' => $checked, 'platforms' => $platforms, 'followers_total' => $followersKnown ? $followersTotal : null,
        'followers_known' => $followersKnown,
    ];
    $scored = pa_score_social($social);
    return array_merge($social, $scored);
}

/** Cherche le lien externe d'un profil (bio, site). null = non vérifiable sans connexion. */
function pa_social_has_link(string $html, string $bio): ?bool
{
    $scan = str_replace(['\\/', '\\u002F'], '/', $html);
    if (preg_match('~"external_url"\s*:\s*"https?://~i', $scan)) return true;
    if (preg_match('~"website"\s*:\s*"https?://~i', $scan)) return true;
    if (preg_match('~(lien en bio|link in bio)~iu', $scan)) return true;
    if (preg_match('~https?://~i', $bio)) return true;
    return null;
}

/** Abonnés lisibles dans la page publique, selon le réseau. */
function pa_extract_followers(string $html, string $platform): ?int
{
    $scan = str_replace(['\\/', '\\u002F'], '/', $html);
    $patterns = match ($platform) {
        'instagram' => [
            '/"edge_followed_by"\s*:\s*\{\s*"count"\s*:\s*(\d+)/',
            '/"follower_count"\s*:\s*(\d+)/',
            '/([\d][\d\s.,]*\s*[kKmM]?)\s*(?:followers|abonn[eé]s)/iu',
        ],
        'facebook' => [
            '/"follower_count"\s*:\s*(\d+)/',
            '/"fan_count"\s*:\s*(\d+)/',
            '/([\d][\d\s.,]*\s*[kKmM]?)\s*(?:followers|abonn[eé]s|j’aime|aime)/iu',
        ],
        'tiktok' => [
            '/"followerCount"\s*:\s*(\d+)/',
            '/"fans"\s*:\s*(\d+)/',
            '/([\d][\d\s.,]*\s*[kKmM]?)\s*(?:followers|abonn[eé]s)/iu',
        ],
        'youtube' => [
            '/"subscriberCount"\s*:\s*"?(\d+)/',
            '/"subscriberCountText"\s*:\s*\{\s*"simpleText"\s*:\s*"([^"]+)"/',
            '/([\d][\d\s.,]*\s*[kKmM]?)\s*(?:subscribers|abonn[eé]s)/iu',
        ],
        'linkedin' => [
            '/"followerCount"\s*:\s*(\d+)/',
            '/([\d][\d\s.,]*)\s*(?:followers|abonn[eé]s)/iu',
        ],
        default => [],
    };
    $best = null;
    foreach ($patterns as $pattern) {
        if (!preg_match_all($pattern, $scan, $matches)) continue;
        foreach ($matches[1] as $raw) {
            $value = pa_human_count((string) $raw);
            if ($value !== null && $value < 100000000 && ($best === null || $value > $best)) $best = $value;
        }
    }
    return $best;
}

/** Date du dernier contenu visible -> nombre de jours (approximatif, sans connexion). */
function pa_extract_last_post_days(string $html, string $platform): ?int
{
    $scan = str_replace(['\\/', '\\u002F'], '/', $html);
    $timestamps = [];
    foreach (['taken_at_timestamp', 'creation_time', 'publish_time', 'createTime', 'create_time'] as $key) {
        if (preg_match_all('/"' . preg_quote($key, '/') . '"\s*:\s*"?(\d{10})/', $scan, $matches)) {
            foreach ($matches[1] as $ts) $timestamps[] = (int) $ts;
        }
    }
    $days = null;
    if ($timestamps) {
        $latest = max($timestamps);
        $days = max(0, (int) floor((time() - $latest) / 86400));
    }
    if (preg_match_all('/"(?:publishedTimeText|publishedTime)"\s*:\s*\{\s*"simpleText"\s*:\s*"([^"]+)"/i', $scan, $matches)) {
        foreach ($matches[1] as $text) {
            $value = pa_days_since($text);
            if ($value !== null && ($days === null || $value < $days)) $days = $value;
        }
    }
    if (preg_match_all('/(?:il y a|ago)\s*(\d+)\s*(jour|jours|semaine|semaines|mois|an|ans|day|days|week|weeks|month|months|year|years)/iu', $scan, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $value = pa_days_since($match[0]);
            if ($value !== null && ($days === null || $value < $days)) $days = $value;
        }
    }
    return $days;
}

/** Nombre de publications visibles dans la page publique. */
function pa_extract_posts(string $html): ?int
{
    $scan = str_replace(['\\/', '\\u002F'], '/', $html);
    foreach (['"edge_owner_to_timeline_media"\s*:\s*\{\s*"count"\s*:\s*(\d+)', '"media_count"\s*:\s*(\d+)', '"videoCount"\s*:\s*(\d+)'] as $pattern) {
        if (preg_match('/' . $pattern . '/', $scan, $m)) {
            $n = (int) $m[1];
            if ($n > 0) return $n;
        }
    }
    return null;
}

/** Score des réseaux sociaux et de la fiche Google (0-100). */
function pa_score_social(array $social): array
{
    $platforms = (array) ($social['platforms'] ?? []);
    $checked = !empty($social['checked']);
    $present = 0;
    $scores = [];
    $confidences = [];
    $gaps = [];
    $blocked = 0;
    foreach ($platforms as $key => $p) {
        if (empty($p['present'])) continue;
        $present++;
        $points = 0;
        $points += 25;      // présence
        $nameKnown = trim((string) ($p['name'] ?? '')) !== '';
        $bioKnown = $p['bio_len'] !== null;
        $imageKnown = $p['has_image'] !== null;
        $linkKnown = $p['has_link'] !== null;
        $points += $nameKnown ? ($p['name'] !== '' ? 5 : 0) : 2.5;
        $points += $bioKnown ? (($p['bio_len'] ?? 0) >= 40 ? 7 : (($p['bio_len'] ?? 0) > 0 ? 3 : 0)) : 3.5;
        $points += $imageKnown ? ($p['has_image'] ? 5 : 0) : 2.5;
        $points += $linkKnown ? ($p['has_link'] ? 8 : 0) : 4;
        if ($key === 'google') {
            $rating = (float) ($p['rating'] ?? 0);
            $reviews = (int) ($p['reviews'] ?? 0);
            $points = 25 + (($rating > 0 || $reviews > 0) ? 25 : 12.5) + ($reviews > 0 ? 25 : 12.5);
            $audience = ($rating > 0 ? ($rating / 5) * 10 : 5) + ($reviews > 0 ? min(15, log10($reviews + 1) / 2 * 15) : 5);
        } else {
            $days = $p['last_post_days'] ?? null;
            $activity = $days === null ? (($p['posts'] ?? 0) > 0 ? 15 : 12) : ($days <= 7 ? 25 : ($days <= 30 ? 18 : ($days <= 90 ? 10 : ($days <= 180 ? 5 : 2))));
            $followers = $p['followers'] ?? null;
            $audience = $followers === null ? 10 : min(25, (log10($followers + 1) / 4) * 25);
            $points += $activity;
        }
        $points += $audience;
        $score = (int) round(max(0, min(100, $points)));
        $p['score'] = $score;
        $scores[] = $score;
        $confidences[] = (int) ($p['confidence'] ?? 50);
        if (!empty($p['blocked'])) {
            $blocked++;
            $gaps[] = ['key' => 'social_unverified', 'label' => $p['label'] . ' non vérifiée', 'lost' => 5, 'detail' => 'Connexion requise pour lire la page'];
        }
        if (($p['last_post_days'] ?? null) !== null && $p['last_post_days'] > 90) {
            $gaps[] = ['key' => 'social_inactive', 'label' => $p['label'] . ' peu actif', 'lost' => 12, 'detail' => 'Dernier contenu il y a ' . $p['last_post_days'] . ' jours'];
        }
        $platforms[$key] = $p;
    }
    if ($present === 0) {
        if ($checked) $gaps[] = ['key' => 'social_absent', 'label' => 'Aucune page sociale', 'lost' => 100, 'detail' => 'Aucun lien de réseau social trouvé sur le site ni dans la fiche'];
        else $gaps[] = ['key' => 'social_unknown', 'label' => 'Présence sociale non renseignée', 'lost' => 0, 'detail' => 'Aucun site et aucune colonne sociale : à vérifier à la main'];
        return ['score' => $checked ? 0 : null, 'confidence' => $checked ? 100 : 0, 'platforms' => $platforms, 'gaps' => $gaps, 'present' => 0];
    }
    $best = max($scores);
    $average = array_sum($scores) / count($scores);
    $score = (int) round($best * 0.6 + $average * 0.4);
    $confidence = (int) round(array_sum($confidences) / count($confidences));
    if (($social['followers_total'] ?? null) !== null && $social['followers_total'] < 100) {
        $gaps[] = ['key' => 'social_low', 'label' => 'Audience sociale faible', 'lost' => 8, 'detail' => 'Moins de 100 abonnés au total'];
    }
    foreach (['facebook', 'instagram', 'google'] as $key) {
        if (empty($platforms[$key]['present'])) $gaps[] = ['key' => 'social_' . $key, 'label' => 'Canal ' . PA_PLATFORMS[$key]['label'] . ' absent', 'lost' => 6, 'detail' => 'Canal absent'];
    }
    usort($gaps, static fn (array $a, array $b): int => $b['lost'] <=> $a['lost']);
    return ['score' => $score, 'confidence' => $confidence, 'platforms' => $platforms, 'gaps' => $gaps, 'present' => $present, 'blocked' => $blocked];
}

/** Assemble les scores et l'angle de prospection d'un prospect. */
function pa_score_prospect(array $site, array $social, array $prospect): array
{
    $web = (int) ($site['score'] ?? 0);
    $socialScore = $social['score'] ?? null;
    $global = $socialScore === null ? $web : (int) round($web * 0.6 + ((int) $socialScore) * 0.4);
    $need = 100 - $global;

    $value = 50;
    if (trim((string) ($prospect['phone'] ?? '')) !== '') $value += 10;
    if (trim((string) ($prospect['email'] ?? '')) !== '') $value += 10;
    if (trim((string) ($prospect['address'] ?? '')) !== '' && trim((string) ($prospect['city'] ?? '')) !== '') $value += 10;
    if (trim((string) ($prospect['website'] ?? '')) !== '') $value += 5;
    $followers = $social['followers_total'] ?? null;
    if ($followers !== null) {
        if ($followers >= 1000) $value += 8;
        elseif ($followers >= 300) $value += 5;
        elseif ($followers >= 100) $value += 3;
    }
    $reviews = 0;
    foreach ((array) ($social['platforms'] ?? []) as $p) {
        if (($p['key'] ?? '') === 'google') $reviews = (int) ($p['reviews'] ?? 0);
    }
    if ($reviews >= 50) $value += 10;
    elseif ($reviews >= 10) $value += 7;
    elseif ($reviews >= 3) $value += 4;
    if (in_array((string) ($prospect['status'] ?? ''), ['contacte', 'interesse', 'rdv'], true)) $value += 5;
    if ((int) ($prospect['priority'] ?? 2) === 1) $value += 5;
    $value = max(0, min(100, $value));

    $reach = 1.0;
    if (trim((string) ($prospect['phone'] ?? '')) === '' && trim((string) ($prospect['email'] ?? '')) === '') {
        $reach = (trim((string) ($prospect['address'] ?? '')) !== '' || trim((string) ($prospect['city'] ?? '')) !== '') ? 0.75 : 0.5;
    }
    $priority = (int) round((0.72 * $need + 0.28 * $value) * $reach);
    $priority = max(0, min(100, $priority));
    [$tier, $tierLabel] = pa_tier($priority);

    $gaps = array_merge((array) ($site['gaps'] ?? []), (array) ($social['gaps'] ?? []));
    usort($gaps, static fn (array $a, array $b): int => $b['lost'] <=> $a['lost']);
    $angle = pa_angle($gaps);

    return [
        'web' => $web, 'social' => $socialScore, 'global' => $global,
        'priority' => $priority, 'tier' => $tier, 'tier_label' => $tierLabel,
        'need' => $need, 'value' => $value, 'reach' => $reach,
        'social_confidence' => (int) ($social['confidence'] ?? 0),
        'gaps' => $gaps, 'angle' => $angle,
    ];
}

function pa_tier(int $priority): array
{
    if ($priority >= 75) return ['priorite_1', 'Priorité 1 · à contacter cette semaine'];
    if ($priority >= 60) return ['priorite_2', 'Priorité 2 · second passage'];
    if ($priority >= 45) return ['priorite_3', 'Priorité 3 · veille'];
    return ['deja_equipe', 'Déjà équipé · ne pas déranger'];
}

/** Phrase d'accroche concrète, tirée des principaux manques constatés. */
function pa_angle(array $gaps): string
{
    $phrases = [
        'absent' => 'Aucun site web : proposer une vitrine claire avec catalogue et retrait en boutique.',
        'inaccessible' => 'Site injoignable : montrer la page d’erreur que voient les clients et proposer une reprise.',
        'erreur' => 'Page en erreur : le site ne donne pas confiance, proposer une reprise rapide.',
        'non_html' => 'L’adresse ne mène pas à un vrai site : vérifier puis proposer une vitrine.',
        'dispo' => 'Site sans HTTPS ou mal servi : sécurité et confiance à rétablir.',
        'mobile' => 'Site non adapté au mobile : la majorité des visites se fait sur téléphone.',
        'perf' => 'Site lent ou lourd : montrer une version rapide et légère.',
        'seo' => 'Peu visible sur Google : titre, description et structure à reprendre.',
        'fraicheur' => 'Site qui n’a pas bougé depuis des années : signal de vétusté.',
        'conversion' => 'Vitrine sans vente en ligne : proposer catalogue, panier et click & collect.',
        'a11y' => 'Accessibilité de base à corriger (images, formulaires).',
        'social_absent' => 'Aucune page sociale : créer Facebook et Instagram, publier les arrivages.',
        'social_inactive' => 'Réseaux dormants : relancer avec un calendrier de publications prêt à l’emploi.',
        'social_low' => 'Audience sociale faible : régularité et contenu à travailler.',
        'social_unverified' => 'Réseaux non vérifiables sans connexion : contrôler abonnés et dernière publication à la main.',
        'social_facebook' => 'Pas de page Facebook : canal local encore très utilisé.',
        'social_instagram' => 'Pas de compte Instagram : photos des pièces et arrivages à valoriser.',
        'social_google' => 'Fiche Google absente ou incomplète : perte de clients qui cherchent à proximité.',
        'social_unknown' => 'Présence sociale non renseignée : vérifier les pages avant l’appel.',
    ];
    $lines = [];
    foreach ($gaps as $gap) {
        $key = (string) ($gap['key'] ?? '');
        if (!isset($phrases[$key])) continue;
        $line = $phrases[$key];
        if ($key === 'social_inactive' && !empty($gap['detail'])) $line = 'Réseaux dormants (' . $gap['detail'] . ') : relancer avec un calendrier de publications.';
        if (!in_array($line, $lines, true)) $lines[] = $line;
        if (count($lines) >= 3) break;
    }
    if (!$lines) $lines[] = 'Site et réseaux corrects : proposer un accompagnement de contenu et de régularité.';
    return implode(' ', $lines);
}
