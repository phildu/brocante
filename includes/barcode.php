<?php

// Fiche d'article à partir d'un code-barres (EAN-13 / UPC / ISBN) ou d'un code QR : livres, CD, vinyles, DVD…
//
// Le scanner (assets/barcode-scan.js) lit le code avec la caméra du téléphone ; ce fichier cherche les informations, sans clé d'API :
//   livres  : BnF (catalogue français), Open Library (couverture, poids, sujets, extrait), Google Books s'il répond (résumé) ;
//   musique : MusicBrainz (CD, vinyles, cassettes : artiste, titre, label, année, pistes) + Cover Art Archive (pochette) ;
//   QR      : adresse Discogs (API publique), ISBN ou ASIN dans une adresse (Amazon, Open Library…), sinon titre / image / description de la page ;
//   autres  : UPCitemdb (essai gratuit : quelques recherches par jour).
// Les adresses saisies par l'extérieur (QR) ne sont ouvertes que si elles mènent à une machine publique (jamais le réseau local), sans
// suivre de redirection vers un hôte privé.
// Les résultats réussis sont gardés 30 jours dans data/barcode-cache/ (les services gratuits limitent le nombre de recherches).

const BARCODE_UA = 'BoutiqueSaaS/1.0 (+https://github.com; fiche par code-barres)';
const BARCODE_CACHE_DAYS = 30;

// ── Codes ────────────────────────────────────────────────────────────────────

/** La clé de contrôle d'un EAN-8 / UPC-12 / EAN-13 est-elle bonne ? */
function barcode_ean_valid(string $code): bool
{
    if (!preg_match('/^\d{8}$|^\d{12}$|^\d{13}$/', $code)) return false;
    $digits = array_map('intval', str_split($code));
    $check = array_pop($digits);
    $sum = 0;
    foreach (array_reverse($digits) as $i => $d) $sum += $d * ($i % 2 === 0 ? 3 : 1);
    return (10 - $sum % 10) % 10 === $check;
}

function barcode_isbn10_valid(string $isbn): bool
{
    if (!preg_match('/^\d{9}[\dX]$/', $isbn)) return false;
    $sum = 0;
    for ($i = 0; $i < 10; $i++) $sum += (10 - $i) * ($isbn[$i] === 'X' ? 10 : (int) $isbn[$i]);
    return $sum % 11 === 0;
}

function barcode_isbn10_to_13(string $isbn10): string
{
    $core = '978' . substr($isbn10, 0, 9);
    $sum = 0;
    foreach (str_split($core) as $i => $d) $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
    return $core . ((10 - $sum % 10) % 10);
}

/**
 * Comprend ce que le scanner a lu : ['type' => 'isbn' | 'ean' | 'discogs' | 'url' | 'text', 'code' => EAN/ISBN-13 normalisé, ...].
 * Le texte d'un code QR peut être un ISBN, une adresse (Discogs, Amazon, Open Library…) ou n'importe quel texte.
 */
function barcode_parse(string $raw): array
{
    $raw = trim(mb_substr($raw, 0, 600));
    $plain = strtoupper(preg_replace('/[\s\-]+/', '', preg_replace('/^(?:ISBN(?:-1[03])?|EAN)[:\s]*/i', '', $raw)));
    if (preg_match('/^\d{13}$/', $plain) && barcode_ean_valid($plain)) {
        return ['type' => str_starts_with($plain, '978') || str_starts_with($plain, '979') ? 'isbn' : 'ean', 'code' => $plain];
    }
    if (barcode_isbn10_valid($plain)) return ['type' => 'isbn', 'code' => barcode_isbn10_to_13($plain), 'isbn10' => $plain];
    if (preg_match('/^\d{8}$|^\d{12}$/', $plain) && barcode_ean_valid($plain)) return ['type' => 'ean', 'code' => $plain];
    if (preg_match('/^\d{14}$/', $plain) && barcode_ean_valid(substr($plain, 1))) return ['type' => 'ean', 'code' => substr($plain, 1)];   // GTIN-14

    if (preg_match('#^https?://#i', $raw)) {
        if (preg_match('#discogs\.com/(?:[a-z]{2}/)?(release|master)/(\d+)#i', $raw, $m)) return ['type' => 'discogs', 'code' => $m[2], 'kind' => strtolower($m[1]), 'url' => $raw];
        if (preg_match('#(?:/dp/|/gp/product/|/ASIN/)([0-9A-Z]{10})(?:[/?]|$)#', $raw, $m) && barcode_isbn10_valid($m[1])) return ['type' => 'isbn', 'code' => barcode_isbn10_to_13($m[1]), 'isbn10' => $m[1]];
        if (preg_match('#/isbn/(\d{9}[\dXx]|\d{13})#i', $raw, $m)) return barcode_parse($m[1]);
        return ['type' => 'url', 'code' => '', 'url' => $raw];
    }
    if (preg_match('/ISBN[^0-9]{0,6}([0-9][0-9\- ]{8,16}[0-9Xx])/i', $raw, $m)) {
        $sub = barcode_parse($m[1]);
        if (in_array($sub['type'], ['isbn', 'ean'], true)) return $sub;
    }
    return ['type' => 'text', 'code' => '', 'text' => $raw];
}

// ── Réseau ───────────────────────────────────────────────────────────────────

/** L'adresse mène-t-elle à une machine publique (http/https, ports 80/443, pas d'identifiants, aucune adresse privée ou locale) ? */
function barcode_url_public(string $url): bool
{
    $p = parse_url($url);
    if (!$p || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || isset($p['user']) || isset($p['pass']) || empty($p['host'])) return false;
    if (isset($p['port']) && !in_array((int) $p['port'], [80, 443], true)) return false;
    $host = $p['host'];
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) return false;
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
    }
    return true;
}

/**
 * Télécharge une adresse (redirections suivies à la main, chacune revérifiée ; corps limité à $maxBytes). Retourne ['status', 'body', 'type', 'url'] ;
 * 'status' = 0 en cas d'échec réseau ou d'adresse refusée.
 */
function barcode_http(string $url, array $headers = [], int $timeout = 8, int $maxBytes = 1500000, bool $head = false): array
{
    $out = ['status' => 0, 'body' => '', 'type' => '', 'url' => $url];
    for ($hop = 0; $hop < 4; $hop++) {
        if (!barcode_url_public($url)) return $out;
        $body = '';
        $location = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => BARCODE_UA,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json, text/html;q=0.9, image/*;q=0.8, */*;q=0.5'], $headers), CURLOPT_NOBODY => $head,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$location) { if (stripos($h, 'location:') === 0) $location = trim(substr($h, 9)); return strlen($h); },
            CURLOPT_WRITEFUNCTION => static function ($c, $chunk) use (&$body, $maxBytes) { $body .= $chunk; return strlen($body) > $maxBytes ? 0 : strlen($chunk); },
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if (in_array($status, [301, 302, 303, 307, 308], true) && $location !== '') {
            $url = preg_match('#^https?://#i', $location) ? $location : (parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (str_starts_with($location, '/') ? '' : '/') . $location);
            continue;
        }
        return ['status' => $status, 'body' => $body, 'type' => $type, 'url' => $url];
    }
    return $out;
}

function barcode_json(string $url, array $headers = [], int $timeout = 8): ?array
{
    $r = barcode_http($url, $headers, $timeout);
    if ($r['status'] !== 200) return null;
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

/** Une image existe-t-elle à cette adresse ? (vérification légère, sans la télécharger en entier) */
function barcode_image_exists(string $url): bool
{
    $r = barcode_http($url, [], 6, 400000, true);
    return $r['status'] === 200 && str_starts_with(strtolower($r['type']), 'image/');
}

/** Télécharge une image distante dans un fichier temporaire et retourne son chemin, ou null (taille max 8 Mo, vérifiée avec getimagesize). */
function barcode_download_image(string $url): ?string
{
    $r = barcode_http($url, [], 12, 8000000);
    if ($r['status'] !== 200 || $r['body'] === '') return null;
    $tmp = tempnam(sys_get_temp_dir(), 'cover');
    file_put_contents($tmp, $r['body']);
    if (!@getimagesize($tmp)) { @unlink($tmp); return null; }
    return $tmp;
}

// ── Mise en forme ────────────────────────────────────────────────────────────

function barcode_clean(string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** « Saint-Exupéry, Antoine de (1900-1944). Auteur du texte » → « Antoine de Saint-Exupéry ». */
function barcode_author(string $s): string
{
    $s = preg_replace('/\s*\([^)]*\)/u', '', $s);
    $s = preg_replace('/\.\s+(?:Auteur|Illustrateur|Traducteur|Préfacier|Éditeur|Adaptateur|Postfacier|Scénariste|Dessinateur|Coloriste|Réalisateur|Compositeur|Interprète|Narrateur|Metteur).*$/ui', '', $s);
    $s = trim($s, " .");
    if (substr_count($s, ',') === 1) {
        [$last, $first] = array_map('trim', explode(',', $s));
        if ($first !== '') return $first . ' ' . $last;
    }
    return $s;
}

function barcode_lang(string $code): string
{
    return ['fre' => 'français', 'fra' => 'français', 'fr' => 'français', 'eng' => 'anglais', 'en' => 'anglais', 'spa' => 'espagnol', 'es' => 'espagnol', 'ger' => 'allemand', 'deu' => 'allemand', 'de' => 'allemand',
        'ita' => 'italien', 'it' => 'italien', 'por' => 'portugais', 'pt' => 'portugais', 'lat' => 'latin', 'dut' => 'néerlandais', 'nld' => 'néerlandais'][strtolower($code)] ?? '';
}

function barcode_cut(string $s, int $max): string
{
    $s = trim($s);
    return mb_strlen($s) <= $max ? $s : rtrim(mb_substr($s, 0, mb_strrpos(mb_substr($s, 0, $max), ' ') ?: $max), " ,;:.") . '…';
}

// ── Livres ───────────────────────────────────────────────────────────────────

function barcode_book_bnf(string $isbn13): ?array
{
    $r = barcode_http('https://catalogue.bnf.fr/api/SRU?version=1.2&operation=searchRetrieve&recordSchema=dublincore&maximumRecords=1&query=' . rawurlencode('bib.isbn adj "' . $isbn13 . '"'), [], 10);
    if ($r['status'] !== 200 || !str_contains($r['body'], '<dc:title>')) return null;
    $x = @simplexml_load_string($r['body']);
    if ($x === false) return null;   // (attention : un SimpleXMLElement dont les éléments sont tous dans un espace de noms vaut « faux »)
    $x->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
    $one = static fn (string $tag): string => trim((string) ($x->xpath('//dc:' . $tag)[0] ?? ''));
    $all = static fn (string $tag): array => array_map(static fn ($n) => trim((string) $n), $x->xpath('//dc:' . $tag) ?: []);
    $title = explode(' / ', $one('title'))[0];
    $format = $one('format');
    $collection = '';
    foreach ($all('description') as $d) if (preg_match('/^Collection\s*:\s*(.+?)(?:\s*;\s*\d+)?$/u', $d, $m)) $collection = $m[1];
    return [
        'title' => $title,
        'authors' => array_values(array_filter(array_map('barcode_author', array_slice($all('creator'), 0, 3)))),
        'publisher' => trim(preg_replace('/\s*\([^)]*\)/u', '', $one('publisher'))),
        'year' => preg_match('/\d{4}/', $one('date'), $m) ? $m[0] : '',
        'pages' => preg_match('/(\d+)\s*p\./', $format, $m) ? (int) $m[1] : 0,
        'lang' => barcode_lang($one('language')),
        'collection' => $collection,
        'subjects' => $all('subject'),
    ];
}

function barcode_book_openlibrary(string $isbn13): ?array
{
    $j = barcode_json('https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn13 . '&format=json&jscmd=data');
    $d = $j['ISBN:' . $isbn13] ?? null;
    if (!is_array($d) || empty($d['title'])) return null;
    $weight = 0;
    if (!empty($d['weight']) && preg_match('/([\d.,]+)\s*(ounces?|oz|pounds?|lbs?|grams?|g|kg|kilograms?)/i', (string) $d['weight'], $m)) {
        $n = (float) str_replace(',', '.', $m[1]);
        $weight = (int) round(match (strtolower($m[2])) { 'ounce', 'ounces', 'oz' => $n * 28.35, 'pound', 'pounds', 'lb', 'lbs' => $n * 453.6, 'kg', 'kilogram', 'kilograms' => $n * 1000, default => $n });
    }
    return [
        'title' => trim((string) $d['title'] . (!empty($d['subtitle']) ? ' : ' . $d['subtitle'] : '')),
        'authors' => array_values(array_filter(array_map(static fn ($a) => trim((string) ($a['name'] ?? '')), array_slice((array) ($d['authors'] ?? []), 0, 3)))),
        'publisher' => trim((string) ($d['publishers'][0]['name'] ?? '')),
        'year' => preg_match('/\d{4}/', (string) ($d['publish_date'] ?? ''), $m) ? $m[0] : '',
        'pages' => (int) ($d['number_of_pages'] ?? 0),
        'weight' => $weight,
        'subjects' => array_values(array_filter(array_map(static fn ($s) => (string) ($s['name'] ?? ''), array_slice((array) ($d['subjects'] ?? []), 0, 12)))),
        'excerpt' => barcode_clean((string) ($d['excerpts'][0]['text'] ?? '')),
        'cover' => (string) ($d['cover']['large'] ?? $d['cover']['medium'] ?? ''),
    ];
}

/** Résumé éditorial (Google Books) : peut être refusé par quota (réponse 429) : sans conséquence. */
function barcode_book_google(string $isbn13): ?array
{
    $j = barcode_json('https://www.googleapis.com/books/v1/volumes?maxResults=1&q=isbn:' . $isbn13);
    $v = $j['items'][0]['volumeInfo'] ?? null;
    if (!is_array($v)) return null;
    $thumb = (string) ($v['imageLinks']['thumbnail'] ?? '');
    return [
        'title' => trim((string) ($v['title'] ?? '') . (!empty($v['subtitle']) ? ' : ' . $v['subtitle'] : '')),
        'authors' => array_slice(array_map('strval', (array) ($v['authors'] ?? [])), 0, 3),
        'publisher' => (string) ($v['publisher'] ?? ''),
        'year' => preg_match('/\d{4}/', (string) ($v['publishedDate'] ?? ''), $m) ? $m[0] : '',
        'pages' => (int) ($v['pageCount'] ?? 0),
        'subjects' => array_map('strval', (array) ($v['categories'] ?? [])),
        'summary' => barcode_clean((string) ($v['description'] ?? '')),
        'cover' => $thumb !== '' ? str_replace(['http://', '&edge=curl'], ['https://', ''], $thumb) : '',
        'lang' => barcode_lang((string) ($v['language'] ?? '')),
    ];
}

function barcode_book(array $in): ?array
{
    $isbn = $in['code'];
    $bnf = str_starts_with($isbn, '9782') || str_starts_with($isbn, '97910') ? barcode_book_bnf($isbn) : null;
    $ol = barcode_book_openlibrary($isbn);
    $gb = barcode_book_google($isbn);
    if (!$bnf && !$ol && !$gb) return null;
    $first = static function (string $key, ...$srcs): string {
        foreach ($srcs as $s) if (is_array($s) && !empty($s[$key])) return (string) $s[$key];
        return '';
    };
    $title = $first('title', $ol, $bnf, $gb);
    $authors = ($ol['authors'] ?? []) ?: (($bnf['authors'] ?? []) ?: ($gb['authors'] ?? []));
    $publisher = $first('publisher', $bnf, $ol, $gb);
    $year = $first('year', $bnf, $ol, $gb);
    $pages = (int) ($first('pages', $bnf, $ol, $gb) ?: 0);
    $lang = $first('lang', $bnf, $gb);
    $subjects = array_merge($ol['subjects'] ?? [], $bnf['subjects'] ?? [], $gb['subjects'] ?? []);
    $summary = ($gb['summary'] ?? '') !== '' ? $gb['summary'] : ($ol['excerpt'] ?? '');
    $isExcerpt = ($gb['summary'] ?? '') === '' && $summary !== '';
    $subText = mb_strtolower(implode(' ', $subjects));
    $sous = match (true) {
        (bool) preg_match('/comics|bande dessin|manga|graphic novel|\bbd\b/u', $subText) => 'bd',
        (bool) preg_match('/juvenile|jeunesse|enfant|children/u', $subText) => 'livres_enfants',
        (bool) preg_match('/cook|cuisine|recette|gastronom/u', $subText) => 'livres_cuisine',
        default => 'livres',
    };
    $meta = array_filter([$publisher . ($year !== '' ? ($publisher !== '' ? ', ' : '') . $year : ''), $pages > 0 ? $pages . ' pages' : '', $lang, ($bnf['collection'] ?? '') !== '' ? 'collection ' . $bnf['collection'] : '']);
    $desc = $title . ($authors ? "\npar " . implode(', ', $authors) : '') . ($meta ? "\n" . implode(' · ', $meta) : '');
    if ($summary !== '') $desc .= "\n\n" . ($isExcerpt ? 'Extrait : « ' . barcode_cut($summary, 500) . ' »' : barcode_cut($summary, 1100));
    $cover = '';
    foreach ([($ol['cover'] ?? ''), 'https://covers.openlibrary.org/b/isbn/' . $isbn . '-L.jpg?default=false', ($gb['cover'] ?? '')] as $c) {
        if ($c !== '' && barcode_image_exists($c)) { $cover = $c; break; }
    }
    return [
        'kind' => 'livre', 'label' => 'Livre', 'name' => barcode_cut($title . ($authors ? ' — ' . implode(', ', array_slice($authors, 0, 2)) : ''), 120),
        'description' => $desc, 'nature' => 'livres_medias', 'sous_categorie' => $sous, 'materials' => '', 'size_text' => $pages > 0 ? $pages . ' pages' : '',
        'weight_grams' => ($ol['weight'] ?? 0) ?: ($pages > 0 ? max(80, (int) round($pages * 1.0)) : 0), 'weight_estimated' => empty($ol['weight']) && $pages > 0,
        'cover' => $cover, 'sources' => array_values(array_filter([$bnf ? 'BnF' : '', $ol ? 'Open Library' : '', $gb ? 'Google Books' : ''])),
        'facts' => array_filter(['Titre' => $title, 'Auteur' => implode(', ', $authors), 'Éditeur' => $publisher, 'Année' => $year, 'Pages' => $pages ?: '', 'Langue' => $lang]),
    ];
}

// ── Musique ──────────────────────────────────────────────────────────────────

function barcode_music_format(string $f): array
{
    $f = trim($f);
    return match (true) {
        (bool) preg_match('/vinyl|\blp\b|12"|10"|7"|45 ?rpm|33 ?rpm|shellac/i', $f) => ['vinyle', 'vinyles', 'Vinyle' . (preg_match('/(12|10|7)"/', $f, $m) ? ' ' . $m[1] . ' pouces' : ''), 260],
        (bool) preg_match('/cassette|\bmc\b|tape/i', $f) => ['cassette', 'cassettes', 'Cassette audio', 60],
        (bool) preg_match('/dvd|blu|vhs|video/i', $f) => ['dvd', 'cassettes', $f, 110],
        (bool) preg_match('/cd|sacd|hdcd/i', $f) => ['cd', 'cassettes', 'CD audio', 100],
        default => ['cd', 'cassettes', $f !== '' ? $f : 'CD audio', 100],
    };
}

function barcode_music_musicbrainz(string $code): ?array
{
    $h = ['Accept: application/json'];
    $s = barcode_json('https://musicbrainz.org/ws/2/release/?fmt=json&limit=5&query=' . rawurlencode('barcode:' . $code), $h, 10);
    $releases = $s['releases'] ?? [];
    if (!$releases) return null;
    // Parmi les éditions de ce code : de préférence officielle, avec support (CD, vinyle…) et date.
    usort($releases, static fn ($a, $b) => [(($b['status'] ?? '') === 'Official'), !empty($b['media'][0]['format']), !empty($b['date'])] <=> [(($a['status'] ?? '') === 'Official'), !empty($a['media'][0]['format']), !empty($a['date'])]);
    $r = $releases[0];
    $detail = barcode_json('https://musicbrainz.org/ws/2/release/' . $r['id'] . '?fmt=json&inc=recordings+artist-credits+labels+release-groups', $h, 10) ?: $r;
    $artist = trim(implode('', array_map(static fn ($c) => ($c['name'] ?? $c['artist']['name'] ?? '') . ($c['joinphrase'] ?? ''), (array) ($detail['artist-credit'] ?? $r['artist-credit'] ?? []))));
    $media = (array) ($detail['media'] ?? $r['media'] ?? []);
    $format = (string) ($media[0]['format'] ?? '');
    $tracks = [];
    foreach ($media as $mi => $m) {
        foreach ((array) ($m['tracks'] ?? []) as $t) {
            $len = isset($t['length']) ? sprintf(' (%d:%02d)', intdiv((int) $t['length'], 60000), intdiv((int) $t['length'] % 60000, 1000)) : '';
            $tracks[] = (count($media) > 1 ? ($mi + 1) . '.' : '') . ($t['number'] ?? $t['position'] ?? '') . ' ' . ($t['title'] ?? '') . $len;
        }
    }
    $label = (string) ($detail['label-info'][0]['label']['name'] ?? $r['label-info'][0]['label']['name'] ?? '');
    $catno = (string) ($detail['label-info'][0]['catalog-number'] ?? '');
    $rg = (string) ($detail['release-group']['id'] ?? $r['release-group']['id'] ?? '');
    $cover = '';
    foreach (['https://coverartarchive.org/release/' . $r['id'] . '/front-500', $rg !== '' ? 'https://coverartarchive.org/release-group/' . $rg . '/front-500' : ''] as $c) {
        if ($c !== '' && barcode_image_exists($c)) { $cover = $c; break; }
    }
    return ['title' => (string) ($detail['title'] ?? $r['title']), 'artist' => $artist, 'label' => $label, 'catno' => $catno, 'year' => substr((string) ($detail['date'] ?? $r['date'] ?? ''), 0, 4),
        'country' => (string) ($detail['country'] ?? $r['country'] ?? ''), 'format' => $format, 'discs' => count($media) ?: 1, 'tracks' => $tracks, 'cover' => $cover, 'source' => 'MusicBrainz'];
}

function barcode_music_discogs(string $id, string $kind): ?array
{
    $d = barcode_json('https://api.discogs.com/' . ($kind === 'master' ? 'masters' : 'releases') . '/' . (int) $id, [], 10);
    if (!$d || empty($d['title'])) return null;
    $artist = trim(implode('', array_map(static fn ($a) => trim(preg_replace('/\s*\(\d+\)$/', '', (string) ($a['name'] ?? ''))) . ($a['join'] ?? '') . ' ', (array) ($d['artists'] ?? []))));
    $fmt = $d['formats'][0] ?? [];
    $format = trim((string) ($fmt['name'] ?? '') . ' ' . implode(' ', array_slice((array) ($fmt['descriptions'] ?? []), 0, 2)));
    $tracks = array_map(static fn ($t) => trim(($t['position'] ?? '') . ' ' . ($t['title'] ?? '') . (!empty($t['duration']) ? ' (' . $t['duration'] . ')' : '')), array_filter((array) ($d['tracklist'] ?? []), static fn ($t) => ($t['type_'] ?? 'track') === 'track'));
    $cover = (string) ($d['images'][0]['uri'] ?? '');
    return ['title' => (string) $d['title'], 'artist' => rtrim($artist, ' ,'), 'label' => (string) ($d['labels'][0]['name'] ?? ''), 'catno' => (string) ($d['labels'][0]['catno'] ?? ''), 'year' => (string) ($d['year'] ?? ''),
        'country' => (string) ($d['country'] ?? ''), 'format' => $format, 'discs' => max(1, (int) ($fmt['qty'] ?? 1)), 'tracks' => array_values($tracks), 'cover' => $cover !== '' && barcode_image_exists($cover) ? $cover : '', 'source' => 'Discogs'];
}

function barcode_music_item(array $m): array
{
    [$kind, $sous, $matLabel, $grams] = barcode_music_format($m['format']);
    $meta = array_filter([$m['label'] . ($m['catno'] !== '' ? ' (' . $m['catno'] . ')' : ''), $m['year'] . ($m['country'] !== '' ? ' · ' . $m['country'] : ''), $matLabel . ($m['discs'] > 1 ? ' × ' . $m['discs'] : '')]);
    $desc = $m['title'] . ($m['artist'] !== '' ? "\n" . $m['artist'] : '') . ($meta ? "\n" . implode(' · ', $meta) : '');
    if ($m['tracks']) $desc .= "\n\nPistes :\n" . implode("\n", array_slice($m['tracks'], 0, 40));
    return [
        'kind' => $kind, 'label' => ['cd' => 'CD', 'vinyle' => 'Vinyle', 'cassette' => 'Cassette', 'dvd' => 'DVD'][$kind] ?? 'Disque', 'name' => barcode_cut(($m['artist'] !== '' ? $m['artist'] . ' — ' : '') . $m['title'], 120),
        'description' => $desc, 'nature' => 'livres_medias', 'sous_categorie' => $sous, 'materials' => $matLabel, 'size_text' => $m['tracks'] ? count($m['tracks']) . ' pistes' : '',
        'weight_grams' => $grams * max(1, $m['discs']), 'weight_estimated' => true, 'cover' => $m['cover'], 'sources' => [$m['source']],
        'facts' => array_filter(['Titre' => $m['title'], 'Artiste' => $m['artist'], 'Label' => $m['label'], 'Année' => $m['year'], 'Support' => $matLabel, 'Pistes' => count($m['tracks']) ?: '']),
    ];
}

// ── Autres codes, pages web ─────────────────────────────────────────────────

function barcode_upcitemdb(string $code): ?array
{
    $j = barcode_json('https://api.upcitemdb.com/prod/trial/lookup?upc=' . $code);
    $i = $j['items'][0] ?? null;
    if (!is_array($i) || empty($i['title'])) return null;
    $img = '';
    foreach ((array) ($i['images'] ?? []) as $u) if (str_starts_with((string) $u, 'https://') && barcode_image_exists((string) $u)) { $img = (string) $u; break; }
    $desc = barcode_clean((string) ($i['description'] ?? ''));
    return [
        'kind' => 'autre', 'label' => 'Article', 'name' => barcode_cut((string) $i['title'], 120),
        'description' => trim(($i['brand'] ?? '') !== '' ? 'Marque : ' . $i['brand'] . "\n" . barcode_cut($desc, 900) : barcode_cut($desc, 900)),
        'nature' => '', 'sous_categorie' => '', 'materials' => '', 'size_text' => (string) ($i['dimension'] ?? ''), 'weight_grams' => 0, 'weight_estimated' => false, 'cover' => $img,
        'sources' => ['UPCitemdb'], 'facts' => array_filter(['Titre' => $i['title'], 'Marque' => $i['brand'] ?? '', 'Modèle' => $i['model'] ?? '']),
    ];
}

/** Titre, description et image d'une page web (balises Open Graph) : repli pour un code QR qui est une simple adresse. */
function barcode_page(string $url): ?array
{
    $r = barcode_http($url, [], 8, 800000);
    if ($r['status'] !== 200 || !str_contains(strtolower($r['type']), 'html')) return null;
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8"?>' . $r['body']);
    $meta = [];
    foreach ($doc->getElementsByTagName('meta') as $m) {
        $k = strtolower($m->getAttribute('property') ?: $m->getAttribute('name'));
        if ($k !== '' && !isset($meta[$k])) $meta[$k] = trim($m->getAttribute('content'));
    }
    $title = $meta['og:title'] ?? $meta['twitter:title'] ?? trim((string) $doc->getElementsByTagName('title')->item(0)?->textContent);
    if ($title === '') return null;
    $img = $meta['og:image'] ?? $meta['twitter:image'] ?? '';
    if ($img !== '' && !preg_match('#^https?://#i', $img)) $img = parse_url($r['url'], PHP_URL_SCHEME) . '://' . parse_url($r['url'], PHP_URL_HOST) . '/' . ltrim($img, '/');
    return [
        'kind' => 'autre', 'label' => 'Page web', 'name' => barcode_cut(barcode_clean($title), 120), 'description' => barcode_cut(barcode_clean($meta['og:description'] ?? $meta['description'] ?? ''), 900),
        'nature' => '', 'sous_categorie' => '', 'materials' => '', 'size_text' => '', 'weight_grams' => 0, 'weight_estimated' => false,
        'cover' => $img !== '' && barcode_image_exists($img) ? $img : '', 'sources' => [parse_url($r['url'], PHP_URL_HOST)], 'facts' => ['Adresse' => $r['url']],
    ];
}

// ── Point d'entrée ───────────────────────────────────────────────────────────

function barcode_cache_file(string $key): string
{
    return dirname(__DIR__) . '/data/barcode-cache/' . sha1($key) . '.json';
}

/**
 * Cherche la fiche correspondant à ce que le scanner a lu (code-barres, ISBN, texte d'un code QR).
 * Retourne ['ok' => true, 'code' => EAN/ISBN ou '', 'kind', 'label', 'name', 'description', 'nature', 'sous_categorie', 'materials', 'size_text',
 * 'weight_grams', 'weight_estimated', 'cover', 'sources', 'facts'] ou ['ok' => false, 'error' => …, 'code' => …].
 */
function barcode_lookup(string $raw): array
{
    $in = barcode_parse($raw);
    $key = $in['type'] . '|' . ($in['code'] ?: ($in['url'] ?? $in['text'] ?? ''));
    $cache = barcode_cache_file($key);
    if (is_file($cache) && time() - filemtime($cache) < BARCODE_CACHE_DAYS * 86400) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) return $c;
    }
    $item = null;
    switch ($in['type']) {
        case 'isbn':
            $item = barcode_book($in);
            if (!$item && ($m = barcode_music_musicbrainz($in['code']))) $item = barcode_music_item($m);
            break;
        case 'ean':
            if ($m = barcode_music_musicbrainz($in['code'])) $item = barcode_music_item($m);
            else $item = barcode_upcitemdb($in['code']);
            break;
        case 'discogs':
            if ($m = barcode_music_discogs($in['code'], $in['kind'])) $item = barcode_music_item($m);
            break;
        case 'url':
            $item = barcode_page($in['url']);
            break;
        default:
            return ['ok' => false, 'code' => '', 'error' => "Ce code n'est pas reconnu comme un code-barres, un ISBN ou une adresse. Contenu lu : « " . barcode_cut((string) ($in['text'] ?? $raw), 80) . ' ».'];
    }
    if (!$item) {
        $what = in_array($in['type'], ['isbn', 'ean'], true) ? 'le code ' . $in['code'] : ($in['type'] === 'url' ? 'cette page' : 'cet article');
        return ['ok' => false, 'code' => $in['code'], 'error' => "Aucune fiche trouvée pour $what. Le code est gardé : complétez la fiche à la main."];
    }
    $res = ['ok' => true, 'code' => $in['code']] + $item;
    $dir = dirname($cache);
    if (is_dir($dir) || @mkdir($dir, 0755, true)) @file_put_contents($cache, json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $res;
}
