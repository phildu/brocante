<?php

// Fiches d'annuaire pour les commerces qui ont déjà leur propre site (hors Brocs).
// Le commerçant saisit l'adresse de son site, la page d'accueil est lue une fois (includes/site-analyzer.php),
// la fiche est proposée à la correction puis soumise à validation. Une fois validée, elle rejoint l'annuaire public
// et son catalogue est lu par le connecteur de sa solution (Shopify, WooCommerce, PrestaShop) quand elle expose un flux.
//
// Données : data/annuaire-externe/<identifiant>.json (jamais envoyé par le déploiement),
//           data/annuaire-externe/.rate.json (compteurs de demandes par adresse IP, non listés).

require_once __DIR__ . '/site-analyzer.php';

const ANNUAIRE_EXT_MAX_PER_HOUR = 5;

function annuaire_ext_dir(): string
{
    $dir = defined('ANNUAIRE_EXTERNE_DIR') ? (string) ANNUAIRE_EXTERNE_DIR : dirname(__DIR__) . '/data/annuaire-externe';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** Identifiant stable d'une fiche : empreinte de l'adresse du site. */
function annuaire_ext_key(string $url): string
{
    return substr(sha1(strtolower(rtrim(trim($url), '/'))), 0, 16);
}

function annuaire_ext_valid_url(string $url): bool
{
    return site_analyzer_url_valid($url);
}

function annuaire_ext_text($value, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $value)), 0, $max);
}

/** Adresses d'une liste, http(s) seulement, sans doublon, plafonnées. */
function annuaire_ext_urls($list, int $max): array
{
    $out = [];
    foreach ((array) $list as $u) {
        $u = trim((string) $u);
        if (!preg_match('#^https?://[^\s"<>]+$#i', $u) || in_array($u, $out, true)) continue;
        $out[] = $u;
        if (count($out) >= $max) break;
    }
    return $out;
}

/** Fiche normalisée (mêmes clés quel que soit l'appelant). */
function annuaire_ext_normalize(array $r): array
{
    $url = rtrim(trim((string) ($r['url'] ?? '')), '/');
    $id = annuaire_ext_valid_url($url) ? annuaire_ext_key($url) : '';
    $rawUniverses = $r['universes'] ?? ($r['category'] ?? '');
    $items = is_array($rawUniverses) ? $rawUniverses : (preg_split('/[,;]+/', (string) $rawUniverses) ?: []);
    $universes = [];
    foreach ($items as $u) {
        $u = annuaire_ext_text($u, 40);
        if ($u !== '' && !in_array($u, $universes, true)) $universes[] = $u;
        if (count($universes) >= 3) break;
    }
    if (!$universes && trim((string) ($r['category'] ?? '')) !== '') $universes[] = annuaire_ext_text($r['category'], 40);
    $status = (string) ($r['status'] ?? 'pending');
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) $status = 'pending';
    return [
        'id' => $id,
        'url' => $url,
        'status' => $status,
        'source' => in_array((string) ($r['source'] ?? ''), ['public', 'portal'], true) ? (string) $r['source'] : 'public',
        'name' => annuaire_ext_text($r['name'] ?? '', 90),
        'tagline' => annuaire_ext_text($r['tagline'] ?? '', 160),
        'description' => annuaire_ext_text($r['description'] ?? '', 400),
        'address' => annuaire_ext_text($r['address'] ?? '', 160),
        'phone' => annuaire_ext_text($r['phone'] ?? '', 40),
        'email' => filter_var(trim((string) ($r['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'hours' => annuaire_ext_text($r['hours'] ?? '', 200),
        'logo' => annuaire_ext_urls([$r['logo'] ?? ''], 1)[0] ?? '',
        'image' => annuaire_ext_urls([$r['image'] ?? ''], 1)[0] ?? '',
        'images' => annuaire_ext_urls($r['images'] ?? [], 8),
        'socials' => annuaire_ext_urls($r['socials'] ?? [], 6),
        'universes' => $universes,
        'accent' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($r['accent'] ?? '')) ? strtolower((string) $r['accent']) : '',
        'platform' => preg_match('/^[a-z]{3,20}$/', (string) ($r['platform'] ?? '')) ? (string) $r['platform'] : 'generic',
        'contact_name' => annuaire_ext_text($r['contact_name'] ?? '', 60),
        'contact_email' => filter_var(trim((string) ($r['contact_email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
        'gallery' => preg_match('/^[a-z0-9][a-z0-9-]{1,48}$/', (string) ($r['gallery'] ?? '')) ? (string) $r['gallery'] : '',
        'note' => annuaire_ext_text($r['note'] ?? '', 400),
        'created' => (int) ($r['created'] ?? time()),
        'updated' => (int) ($r['updated'] ?? time()),
        'reviewed' => (int) ($r['reviewed'] ?? 0),
    ];
}

function annuaire_ext_load(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{16}$/', $id)) return null;
    $file = annuaire_ext_dir() . '/' . $id . '.json';
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($data) ? annuaire_ext_normalize($data) : null;
}

function annuaire_ext_save(array $record): bool
{
    $record = annuaire_ext_normalize($record);
    if ($record['id'] === '') return false;
    $record['updated'] = time();
    $ok = @file_put_contents(annuaire_ext_dir() . '/' . $record['id'] . '.json', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    if ($ok) annuaire_ext_cache_clear();
    return $ok;
}

/** Fiches par statut ('all' pour toutes), les plus récentes d'abord. */
function annuaire_ext_list(string $status = 'all'): array
{
    $out = [];
    foreach (glob(annuaire_ext_dir() . '/*.json') ?: [] as $file) {
        $record = annuaire_ext_load(basename($file, '.json'));
        if (!$record) continue;
        if ($status !== 'all' && $record['status'] !== $status) continue;
        $out[] = $record;
    }
    usort($out, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);
    return $out;
}

function annuaire_ext_pending_count(): int
{
    return count(annuaire_ext_list('pending'));
}

function annuaire_ext_find(string $url): ?array
{
    return annuaire_ext_load(annuaire_ext_key($url));
}

/** L'annuaire public (cache 10 min) est recalculé après chaque validation. */
function annuaire_ext_cache_clear(): void
{
    @unlink(defined('ANNUAIRE_CACHE_FILE') ? (string) ANNUAIRE_CACHE_FILE : dirname(__DIR__) . '/data/saas/cache.json');
}

/**
 * Lecture de la page d'accueil pour pré-remplir la fiche.
 * Retourne le profil (nom, adresse, téléphone, image, solution reconnue, flux) ou null si la page est injoignable.
 */
function annuaire_ext_scan(string $url): ?array
{
    $url = rtrim(trim($url), '/');
    if (str_starts_with($url, 'www.')) $url = 'https://' . $url;
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!annuaire_ext_valid_url($url)) return null;
    return site_analyzer_analyze($url, ['probe' => true]);
}

/** Libellé lisible de la solution reconnue. */
function annuaire_ext_platform_label(string $platform): string
{
    return [
        'shopify' => 'Shopify',
        'woocommerce' => 'WooCommerce',
        'prestashop' => 'PrestaShop',
        'brocs' => 'Boutique Brocs',
        'wix' => 'Wix',
        'squarespace' => 'Squarespace',
        'generic' => 'Site existant',
    ][$platform] ?? 'Site existant';
}

/** Enregistre une soumission publique : statut « en attente », doublons refusés. */
function annuaire_ext_submit(array $profile, array $input, string $source = 'public'): array
{
    $url = rtrim(trim((string) ($input['url'] ?? $profile['url'] ?? '')), '/');
    if (!annuaire_ext_valid_url($url)) return ['ok' => false, 'error' => "L'adresse du site n'est pas valide."];
    $existing = annuaire_ext_find($url);
    if ($existing && $existing['status'] !== 'rejected') {
        return ['ok' => false, 'error' => $existing['status'] === 'approved'
            ? 'Ce site est déjà dans l’annuaire.'
            : 'Une demande est déjà en cours pour ce site.'];
    }
    $record = annuaire_ext_normalize(array_merge($profile, $input, [
        'url' => $url,
        'status' => $source === 'portal' ? 'approved' : 'pending',
        'source' => $source,
        'created' => time(),
        'reviewed' => $source === 'portal' ? time() : 0,
        'platform' => $profile['platform'] ?? ($input['platform'] ?? 'generic'),
    ]));
    if ($record['name'] === '') return ['ok' => false, 'error' => 'Le nom du commerce est obligatoire.'];
    if ($source === 'public' && $record['contact_email'] === '') return ['ok' => false, 'error' => 'Votre adresse e-mail est obligatoire pour vous répondre.'];
    return annuaire_ext_save($record) ? ['ok' => true, 'record' => $record] : ['ok' => false, 'error' => "La fiche n'a pas pu être enregistrée."];
}

/** Validation ou refus d'une fiche (portail). */
function annuaire_ext_review(string $id, string $decision, string $note = ''): ?array
{
    $record = annuaire_ext_load($id);
    if (!$record || !in_array($decision, ['approved', 'rejected'], true)) return null;
    $record['status'] = $decision;
    $record['note'] = $note;
    $record['reviewed'] = time();
    return annuaire_ext_save($record) ? $record : null;
}

/** Ajout direct par l'exploitant : lecture du site puis fiche validée. */
function annuaire_ext_add(string $url): array
{
    $profile = annuaire_ext_scan($url);
    if (!$profile) return ['ok' => false, 'error' => "Le site n'a pas pu être lu (adresse injoignable ou page vide)."];
    return annuaire_ext_submit($profile, [], 'portal');
}

/**
 * Entrées prêtes pour l'annuaire public (forme de saas_directory_entry).
 * Le catalogue du site est lu par le connecteur quand il expose un flux ; sinon la fiche scannée suffit (count 0).
 */
function annuaire_ext_directory_entries(): array
{
    $entries = [];
    foreach (annuaire_ext_list('approved') as $r) {
        $cat = function_exists('catalogue_remote') ? catalogue_remote($r['url']) : null;
        $count = $cat ? count((array) ($cat['products'] ?? [])) : 0;
        $universes = $r['universes'];
        $preview = $r['images'];
        if ($cat) {
            $shop = (array) ($cat['shop'] ?? []);
            if ($universes === []) $universes = array_slice(array_values(array_map('strval', (array) ($shop['universes'] ?? []))), 0, 6);
            $photos = array_values(array_filter(array_map(static fn ($p) => (string) preg_match('#^https?://#i', (string) ($p['photo'] ?? '')) ? (string) $p['photo'] : '', array_slice((array) ($cat['products'] ?? []), 0, 8))));
            if ($photos) $preview = $photos;
        }
        $entries[] = [
            'key' => 'x' . $r['id'],
            'name' => $r['name'] !== '' ? $r['name'] : (string) parse_url($r['url'], PHP_URL_HOST),
            'tagline' => $r['tagline'],
            'url' => $r['url'],
            'logo' => $r['logo'] !== '' ? $r['logo'] : $r['image'],
            'accent' => $r['accent'],
            'universes' => array_slice($universes, 0, 6),
            'count' => $count,
            'preview' => array_slice($preview, 0, 4),
            'external' => true,
            'platform' => $r['platform'],
        ];
    }
    return $entries;
}

/** Ajoute une fiche externe validée à une galerie commerciale (membre distant). */
function annuaire_ext_attach_gallery(array $record, string $slug): bool
{
    if (!function_exists('gallery_load') || !function_exists('gallery_save')) return false;
    $gallery = gallery_load($slug);
    if (!$gallery) return false;
    $members = (array) ($gallery['members'] ?? []);
    foreach ($members as $m) {
        if (($m['type'] ?? '') === 'remote' && rtrim((string) ($m['url'] ?? ''), '/') === $record['url']) {
            $record['gallery'] = $slug;
            return annuaire_ext_save($record);
        }
    }
    $members[] = ['type' => 'remote', 'slug' => '', 'url' => $record['url'], 'name' => $record['name'], 'featured' => false];
    $gallery['members'] = $members;
    if (!gallery_save($gallery)) return false;
    $record['gallery'] = $slug;
    return annuaire_ext_save($record);
}

/** Compteur glissant par adresse IP : true si la demande est autorisée. */
function annuaire_ext_rate_limit(string $ip, int $max = ANNUAIRE_EXT_MAX_PER_HOUR, int $window = 3600): bool
{
    $file = annuaire_ext_dir() . '/.rate.json';
    $now = time();
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    try {
        if (!flock($fh, LOCK_EX)) return true;
        $all = (array) (json_decode((string) stream_get_contents($fh), true) ?: []);
        foreach ($all as $key => $times) {
            $all[$key] = array_values(array_filter((array) $times, static fn ($t) => is_numeric($t) && $t > $now - $window));
            if (!$all[$key]) unset($all[$key]);
        }
        $key = substr(sha1($ip . '|' . date('Y-m-d', $now)), 0, 20);
        $allowed = count((array) ($all[$key] ?? [])) < $max;
        if ($allowed) $all[$key][] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($all, JSON_UNESCAPED_SLASHES));
        fflush($fh);
        return $allowed;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Prévient l'exploitant (et le commerçant si l'adresse est fournie) d'une nouvelle fiche. */
function annuaire_ext_notify(array $record): bool
{
    if (!function_exists('saas_mail') || !function_exists('saas_config')) return false;
    $cfg = saas_config();
    $origin = function_exists('saas_origin') ? saas_origin() : '';
    $lines = [
        'Nouvelle fiche d’annuaire à valider.',
        '',
        'Commerce : ' . $record['name'],
        'Site : ' . $record['url'],
        'Solution reconnue : ' . annuaire_ext_platform_label($record['platform']),
        'Contact : ' . $record['contact_name'] . ' <' . $record['contact_email'] . '>',
        'Téléphone : ' . $record['phone'],
        'Adresse : ' . $record['address'],
    ];
    $body = implode("\n", array_filter($lines, static fn ($l) => $l !== ''));
    $ok = $cfg['contact'] !== '' ? saas_mail($cfg['contact'], 'Annuaire — nouvelle fiche : ' . $record['name'], $body . ($origin !== '' ? "\n\nValider ou refuser : $origin/portail/annuaire-demandes.php\n" : ''), 'annuaire') : false;
    if ($record['contact_email'] !== '') {
        $message = "Bonjour " . ($record['contact_name'] !== '' ? $record['contact_name'] : '') . ",\n\n"
            . "Nous avons bien reçu la fiche de « {$record['name']} » pour l’annuaire.\n"
            . "Elle sera vérifiée avant publication ; nous vous répondons à cette adresse.\n\n"
            . $cfg['name'] . "\n";
        saas_mail($record['contact_email'], 'Votre fiche annuaire — ' . $cfg['name'], $message, 'annuaire');
    }
    return $ok;
}