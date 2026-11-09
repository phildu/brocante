#!/usr/bin/env php
<?php

// Générateur du rapport de qualité des sites et réseaux sociaux des prospects.
//
// Exemples :
//   php scripts/audit-prospects.php --input=prospects.csv
//   php scripts/audit-prospects.php --input=export.xlsx --limit=20
//   php scripts/audit-prospects.php --db=data/vente/vente.db
//   php scripts/audit-prospects.php --template
//
// Le rapport est écrit dans data/rapports/audit-prospects-AAAA-MM-JJ-HHMM/ (dossier
// jamais servi en HTTP) : Markdown pour lire, CSV pour le tableur, JSON pour
// retraiter, HTML pour le classeur d'appels interactif. Aucun compte n'est
// utilisé : seules les pages publiques sont lues.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/prospect-audit.php';
require_once __DIR__ . '/../includes/prospect-audit-report.php';
require_once __DIR__ . '/../includes/contacts-import.php';

const PA_CLI_STATUS_LABELS = [
    'a_contacter' => 'À contacter', 'contacte' => 'Contacté', 'interesse' => 'Intéressé',
    'rdv' => 'RDV pris', 'boutique_creee' => 'Client / inscrit', 'perdu' => 'Sans suite',
];

$options = getopt('', [
    'input:', 'db:', 'out::', 'limit:', 'timeout::', 'social-timeout::', 'no-social',
    'include-clients', 'include-lost', 'statuts:', 'sort::', 'template', 'open', 'help', 'verbose', 'allow-private',
]);
$opt = static function (string $key, ?string $default = null) use ($options): ?string {
    if (!isset($options[$key]) || is_array($options[$key])) return $default;
    return (string) $options[$key];
};
$flag = static fn (string $key): bool => isset($options[$key]);

if ($flag('help')) {
    pa_cli_help();
    exit(0);
}

if ($flag('template')) {
    $target = $opt('out', 'prospects-audit-modele.csv');
    if (is_dir($target)) $target = rtrim($target, '/') . '/prospects-audit-modele.csv';
    elseif (!str_ends_with(strtolower($target), '.csv')) $target = rtrim($target, '/') . '/prospects-audit-modele.csv';
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) pa_cli_fail('Impossible de créer ' . $dir);
    file_put_contents($target, pa_cli_template());
    fwrite(STDOUT, "Modèle écrit : $target\nRemplissez-le (les exemples sont fictifs) puis lancez :\n  php scripts/audit-prospects.php --input=$target\n");
    exit(0);
}

$timeout = max(4, (int) ($opt('timeout', '12') ?? 12));
$socialTimeout = max(3, (int) ($opt('social-timeout', '7') ?? 7));
$limit = max(0, (int) ($opt('limit', '0') ?? 0));
$noSocial = $flag('no-social');
$allowPrivate = $flag('allow-private');
$sort = (string) ($opt('sort', 'priorite') ?? 'priorite');
if (!in_array($sort, ['priorite', 'qualite', 'nom'], true)) pa_cli_fail('Tri inconnu : ' . $sort . ' (priorite, qualite ou nom).');

$input = $opt('input');
$db = $opt('db');
if ($input === null && $db === null) {
    $defaultDb = __DIR__ . '/../data/vente/vente.db';
    if (is_file($defaultDb)) $db = $defaultDb;
    else pa_cli_fail("Indiquez un export : --input=prospects.csv (ou --db=data/vente/vente.db).\nPour créer un modèle : php scripts/audit-prospects.php --template");
}

try {
    if ($db !== null && $db !== '') {
        [$prospects, $source] = pa_cli_load_db($db);
    } else {
        [$prospects, $source] = pa_cli_load_input((string) $input);
    }
} catch (Throwable $e) {
    pa_cli_fail($e->getMessage());
}

$statuses = null;
if (($rawStatuses = $opt('statuts')) !== null && $rawStatuses !== '') {
    $statuses = array_values(array_filter(array_map('pa_normalize_status', preg_split('/[,;]+/', $rawStatuses) ?: [])));
}
$filtered = [];
$seen = [];
$duplicates = 0;
foreach ($prospects as $prospect) {
    $status = pa_normalize_status((string) ($prospect['status'] ?? ''));
    $prospect['status'] = $status;
    if ($status === 'boutique_creee' && !$flag('include-clients')) continue;
    if ($status === 'perdu' && !$flag('include-lost')) continue;
    if ($statuses !== null && !in_array($status, $statuses, true)) continue;
    $key = pa_cli_dedupe_key($prospect);
    if ($key !== '' && isset($seen[$key])) {
        $duplicates++;
        continue;
    }
    if ($key !== '') $seen[$key] = true;
    $filtered[] = $prospect;
}
if ($limit > 0) $filtered = array_slice($filtered, 0, $limit);
if (!$filtered) pa_cli_fail('Aucun prospect à auditer après filtrage (vérifiez les statuts, les doublons ou le fichier).');

$started = microtime(true);
$results = [];
$total = count($filtered);
foreach ($filtered as $index => $prospect) {
    $name = pa_cli_display_name($prospect);
    fwrite(STDERR, sprintf("\r[%d/%d] %s", $index + 1, $total, mb_substr($name, 0, 48)));
    $site = pa_audit_site((string) ($prospect['website'] ?? ''), ['timeout' => $timeout, 'block_private' => !$allowPrivate]);
    $social = pa_audit_social($prospect, $site, ['social_timeout' => $socialTimeout, 'no_social' => $noSocial]);
    $scores = pa_score_prospect($site, $social, $prospect);
    if ($flag('verbose')) {
        fwrite(STDERR, sprintf(
            "\n  site %s · web %d · réseaux %s · global %d · priorité %d (%s)\n",
            $site['state'], $scores['web'], $scores['social'] === null ? '—' : $scores['social'],
            $scores['global'], $scores['priority'], $scores['tier_label']
        ));
    }
    $results[] = [
        'name' => $name,
        'business' => (string) ($prospect['business'] ?? ''),
        'contact' => (string) ($prospect['contact'] ?? ''),
        'city' => (string) ($prospect['city'] ?? ''),
        'zone' => (string) ($prospect['zone'] ?? ''),
        'phone' => (string) ($prospect['phone'] ?? ''),
        'email' => (string) ($prospect['email'] ?? ''),
        'website' => (string) ($prospect['website'] ?? ''),
        'status' => (string) ($prospect['status'] ?? ''),
        'status_label' => PA_CLI_STATUS_LABELS[(string) ($prospect['status'] ?? '')] ?? (string) ($prospect['status'] ?? ''),
        'notes' => (string) ($prospect['notes'] ?? ''),
        'site' => $site,
        'social' => $social,
        'scores' => $scores,
    ];
}
fwrite(STDERR, "\n");

usort($results, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'qualite') return $a['scores']['global'] <=> $b['scores']['global'] ?: $b['scores']['priority'] <=> $a['scores']['priority'];
    if ($sort === 'nom') return strcasecmp($a['name'], $b['name']);
    return $b['scores']['priority'] <=> $a['scores']['priority'] ?: $a['scores']['web'] <=> $b['scores']['web'] ?: strcasecmp($a['name'], $b['name']);
});
foreach ($results as $index => &$result) $result['rank'] = $index + 1;
unset($result);

$stats = pa_cli_stats($results);
$duration = (int) round(microtime(true) - $started);
$report = [
    'generated_at' => date('d/m/Y à H:i'),
    'source' => $source,
    'duration' => $duration < 60 ? $duration . ' s' : (int) floor($duration / 60) . ' min ' . ($duration % 60) . ' s',
    'count' => count($results),
    'stats' => $stats,
    'prospects' => $results,
];

$outRoot = $opt('out', dirname(__DIR__) . '/data/rapports/audit-prospects-' . date('Y-m-d-Hi'));
$outDir = rtrim((string) $outRoot, '/');
if (!is_dir($outDir) && !mkdir($outDir, 0750, true) && !is_dir($outDir)) pa_cli_fail('Impossible de créer ' . $outDir);
$base = $outDir . '/audit-prospects';
$files = [
    'html' => $base . '.html',
    'csv' => $base . '.csv',
    'md' => $base . '.md',
    'json' => $base . '.json',
];
foreach ([
    'html' => pa_report_html($report),
    'csv' => pa_report_csv($report),
    'md' => pa_report_markdown($report),
    'json' => pa_report_json($report),
] as $ext => $content) {
    if (file_put_contents($files[$ext], $content) === false) pa_cli_fail('Impossible d’écrire ' . $files[$ext]);
}

fwrite(STDOUT, sprintf(
    "%d prospect(s) audité(s) en %s · %d priorité 1 · %d sans site · score web moyen %d/100 · score global moyen %d/100\n",
    $stats['count'], $report['duration'], $stats['priorite_1'], $stats['no_site'], $stats['avg_web'], $stats['avg_global']
));
if ($duplicates > 0) fwrite(STDOUT, "Doublons ignorés : $duplicates\n");
fwrite(STDOUT, "HTML  : {$files['html']}\nCSV   : {$files['csv']}\nMD    : {$files['md']}\nJSON  : {$files['json']}\n");

if ($flag('open')) {
    $command = PHP_OS_FAMILY === 'Darwin' ? 'open ' : (PHP_OS_FAMILY === 'Windows' ? 'start "" ' : 'xdg-open ');
    @exec($command . escapeshellarg($files['html']));
}

function pa_cli_help(): void
{
    fwrite(STDOUT, <<<'TXT'
Génère un rapport de qualité des sites et réseaux sociaux des prospects.

Usage :
  php scripts/audit-prospects.php --input=prospects.csv [options]
  php scripts/audit-prospects.php --db=data/vente/vente.db [options]
  php scripts/audit-prospects.php --template

Entrées :
  --input=FICHIER      Export CSV, Excel (.xls/.xlsx) ou JSON.
  --db=FICHIER         Base SQLite du carnet de vente (table prospects).
                       Sans --input ni --db : data/vente/vente.db si présent.

Options :
  --out=DOSSIER        Dossier du rapport (défaut : data/rapports/audit-prospects-…).
  --limit=N            N'auditer que les N premiers prospects.
  --timeout=SECONDES   Délai par page de site (défaut 12).
  --social-timeout=S   Délai par page sociale (défaut 7).
  --no-social          Ne pas ouvrir les pages sociales (plus rapide).
  --include-clients    Inclure les prospects déjà clients / inscrits.
  --include-lost       Inclure les prospects sans suite.
  --statuts=LISTE      Ne garder que ces statuts (a_contacter,contacte,interesse,rdv…).
  --sort=priorite|qualite|nom   Ordre du rapport (défaut : priorité).
  --open               Ouvrir le rapport HTML à la fin.
  --allow-private      Autoriser les adresses locales ou privées.
  --template           Écrire un modèle CSV avec les colonnes reconnues.
  --verbose            Afficher le détail de chaque prospect pendant l'audit.
  --help               Cette aide.

Colonnes reconnues (les en-têtes usuels sont acceptés) :
  Commerce, Contact, Téléphone, E-mail, Site web, Facebook, Instagram,
  TikTok, YouTube, Fiche Google, Ville, Code postal, Adresse, Zone,
  Activité, Statut, Priorité, Notes, Abonnés Facebook/Instagram/TikTok/
  YouTube, Note Google, Avis Google, Dernière publication.

Le rapport est écrit en HTML (classeur d'appels), CSV, Markdown et JSON.
TXT
    );
}

function pa_cli_fail(string $message): never
{
    fwrite(STDERR, "Erreur : $message\n");
    exit(1);
}

function pa_cli_template(): string
{
    $rows = [
        ['Commerce', 'Contact', 'Téléphone', 'E-mail', 'Site web', 'Facebook', 'Instagram', 'TikTok', 'YouTube', 'Fiche Google', 'Ville', 'Code postal', 'Adresse', 'Zone', 'Activité', 'Statut', 'Priorité', 'Notes', 'Abonnés Facebook', 'Abonnés Instagram', 'Abonnés TikTok', 'Abonnés YouTube', 'Note Google', 'Avis Google', 'Dernière publication'],
        ['Brocante des Tilleuls', 'Marie Dupont', '04 00 00 00 00', 'contact@example.com', 'https://www.example.com', 'https://www.facebook.com/brocantedestilleuls', 'https://www.instagram.com/brocantedestilleuls', '', '', '', 'Lyon', '69001', '1 rue de l’Exemple', 'Presqu’île', 'Brocante', 'À contacter', '1', 'Exemple fictif : site vitrine ancien.', '', '', '', '', '', '', ''],
        ['Le Grenier d’Anatole', 'Paul Martin', '06 00 00 00 00', '', '', '', 'https://www.tiktok.com/@legrenierdanatole', '', '', 'Villeurbanne', '69100', '2 avenue de l’Exemple', 'Nord', 'Brocante', 'Contacté', '2', 'Exemple fictif : pas de site, seulement TikTok.', '', '', '', '', '', '', '2026-10-15'],
    ];
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, "\xEF\xBB\xBF");
    foreach ($rows as $row) fputcsv($handle, $row, ';', '"', '');
    rewind($handle);
    $csv = (string) stream_get_contents($handle);
    fclose($handle);
    return $csv;
}

/** Charge un fichier d'entrée et renvoie [prospects, libellé de source]. */
function pa_cli_load_input(string $path): array
{
    if ($path === '' || !is_file($path)) throw new RuntimeException('Fichier introuvable : ' . $path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $source = 'fichier ' . basename($path);
    if (in_array($ext, ['xls', 'xlsx'], true)) {
        [$headers, $rows] = ci_parse_spreadsheet($path);
        return [pa_cli_rows_from_table($headers, $rows), $source];
    }
    if ($ext === 'json') {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) throw new RuntimeException('JSON illisible : ' . basename($path));
        if (isset($decoded['prospects']) && is_array($decoded['prospects'])) $decoded = $decoded['prospects'];
        $prospects = [];
        foreach ($decoded as $row) {
            if (is_array($row)) $prospects[] = pa_map_values($row);
        }
        return [$prospects, $source];
    }
    if (in_array($ext, ['db', 'sqlite', 'sqlite3'], true)) return pa_cli_load_db($path);
    $text = ci_decode((string) file_get_contents($path));
    [$headers, $rows] = ci_parse_csv($text);
    return [pa_cli_rows_from_table($headers, $rows), $source];
}

function pa_cli_rows_from_table(array $headers, array $rows): array
{
    $keys = array_map('pa_key', $headers);
    $prospects = [];
    foreach ($rows as $row) {
        $values = [];
        foreach ($keys as $index => $key) {
            if ($key !== '') $values[$key] = trim((string) ($row[$index] ?? ''));
        }
        $prospect = pa_map_values($values);
        if (pa_cli_display_name($prospect) !== '' && pa_cli_display_name($prospect) !== 'Prospect sans nom') $prospects[] = $prospect;
    }
    return $prospects;
}

/** Charge la base SQLite du carnet de vente (ou d'une galerie). */
function pa_cli_load_db(string $path): array
{
    if ($path === '' || !is_file($path)) throw new RuntimeException('Base introuvable : ' . $path . "\nExportez le carnet en CSV, ou indiquez une base existante.");
    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('PRAGMA query_only = ON');
        $rows = $pdo->query('SELECT * FROM prospects ORDER BY business, name')->fetchAll();
    } catch (Throwable $e) {
        throw new RuntimeException('Impossible de lire la base ' . basename($path) . ' : ' . $e->getMessage());
    }
    $prospects = [];
    foreach ($rows as $row) {
        $values = [];
        foreach ($row as $key => $value) {
            if (is_string($value) || is_numeric($value)) $values[pa_key((string) $key)] = trim((string) $value);
        }
        $custom = json_decode((string) ($row['custom_fields'] ?? ''), true);
        if (is_array($custom)) {
            foreach ($custom as $label => $value) {
                if (!is_scalar($value)) continue;
                $key = pa_key((string) $label);
                if ($key !== '' && (!isset($values[$key]) || $values[$key] === '')) $values[$key] = trim((string) $value);
            }
        }
        $prospect = pa_map_values($values);
        if (pa_cli_display_name($prospect) !== '') $prospects[] = $prospect;
    }
    return [$prospects, 'carnet SQLite · ' . basename($path)];
}

function pa_key(string $value): string
{
    $value = ci_norm_header($value);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

/** Transforme une ligne d'export (en-têtes normalisés) en prospect du moteur d'audit. */
function pa_map_values(array $values): array
{
    $values = array_change_key_case($values, CASE_LOWER);
    $pick = static function (array $keys) use ($values): string {
        foreach ($keys as $key) {
            $candidate = trim((string) ($values[$key] ?? ''));
            if ($candidate !== '' && !ci_placeholder($candidate)) return $candidate;
        }
        return '';
    };
    $website = $pick(['site web', 'site', 'site internet', 'website', 'web', 'url du site', 'site officiel', 'adresse du site', 'url']);
    if ($website !== '' && preg_match('~(?:https?://|www\.)[^\s,;|]+~i', $website, $match)) $website = $match[0];
    if ($website !== '' && preg_match('~(aucun|pas de site|sans site|non)$~iu', $website)) $website = '';
    $social = [
        'facebook' => pa_handle_url($pick(['facebook', 'page facebook', 'compte facebook', 'fb']), 'facebook'),
        'instagram' => pa_handle_url($pick(['instagram', 'compte instagram', 'ig']), 'instagram'),
        'tiktok' => pa_handle_url($pick(['tiktok', 'compte tiktok']), 'tiktok'),
        'youtube' => pa_handle_url($pick(['youtube', 'chaine youtube', 'compte youtube']), 'youtube'),
        'linkedin' => pa_handle_url($pick(['linkedin', 'page linkedin', 'compte linkedin']), 'linkedin'),
        'google' => pa_handle_url($pick(['fiche google', 'google business', 'google maps', 'fiche google business', 'google']), 'google'),
    ];
    if ($website !== '') {
        foreach (['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin'] as $platform) {
            if ($social[$platform] === '' && stripos($website, $platform . '.com') !== false) {
                $social[$platform] = $website;
                $website = '';
            }
        }
    }
    $prospect = [
        'business' => $pick(['commerce', 'nom du commerce', 'nom boutique', 'nom de la boutique', 'boutique', 'enseigne', 'nom commercial', 'etablissement', 'societe', 'entreprise', 'raison sociale', 'nom']),
        'contact' => $pick(['contact', 'nom du contact', 'interlocuteur', 'personne a contacter', 'responsable', 'gerant', 'dirigeant', 'prenom nom', 'name']),
        'phone' => $pick(['telephone', 'tel', 'numero de telephone', 'portable', 'mobile', 'phone']),
        'email' => $pick(['e mail', 'email', 'mail', 'courriel', 'adresse e mail']),
        'website' => $website,
        'address' => $pick(['adresse', 'adresse postale', 'address', 'rue']),
        'postal' => $pick(['code postal', 'cp', 'zip', 'postal code', 'postal']),
        'city' => $pick(['ville', 'commune', 'city', 'localite']),
        'zone' => $pick(['zone', 'quartier', 'secteur geographique', 'secteur']),
        'kind' => $pick(['activite', 'categorie', 'category', 'type', 'secteur d activite', 'famille', 'kind']),
        'status' => pa_normalize_status($pick(['statut code', 'statut', 'status', 'statut du prospect'])),
        'priority' => (int) ($pick(['priorite', 'priority']) ?: 2),
        'notes' => $pick(['notes', 'note', 'commentaire', 'commentaires', 'remarque', 'remarques', 'description']),
        'social_checked' => false,
        'fb_followers' => $pick(['abonnes facebook', 'abonnes fb', 'followers facebook', 'facebook abonnes']),
        'ig_followers' => $pick(['abonnes instagram', 'abonnes ig', 'followers instagram', 'instagram abonnes']),
        'tiktok_followers' => $pick(['abonnes tiktok', 'followers tiktok', 'tiktok abonnes']),
        'youtube_followers' => $pick(['abonnes youtube', 'followers youtube', 'youtube abonnes']),
        'linkedin_followers' => $pick(['abonnes linkedin', 'followers linkedin', 'linkedin abonnes']),
        'google_rating' => $pick(['note google', 'note google maps', 'google note', 'note fiche google']),
        'google_reviews' => $pick(['avis google', 'nombre avis google', 'google avis', 'avis fiche google']),
        'last_post' => $pick(['derniere publication', 'dernier post', 'derniere publication sociale', 'last post']),
    ];
    if ($prospect['business'] === '' && $prospect['contact'] !== '') $prospect['business'] = $prospect['contact'];
    foreach ($social as $key => $url) $prospect[$key] = $url;
    foreach (['facebook', 'instagram', 'tiktok', 'youtube', 'linkedin'] as $key) {
        if ($prospect[$key] !== '') $prospect['social_checked'] = true;
    }
    foreach (['google_rating', 'google_reviews', 'last_post', 'google'] as $key) {
        if (trim((string) $prospect[$key]) !== '') $prospect['social_checked'] = true;
    }
    return $prospect;
}

function pa_handle_url(string $value, string $platform): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('~https?://~i', $value)) {
        if (preg_match('~https?://[^\s,;|]+~i', $value, $match)) return $match[0];
        return '';
    }
    if (preg_match('~^(?:www\.)?(?:facebook|instagram|tiktok|youtube|linkedin|google)\.com/~i', $value) || str_starts_with($value, 'maps.app.goo.gl') || str_starts_with($value, 'g.page')) {
        return pa_normalize_url($value);
    }
    if (preg_match('~^@([A-Za-z0-9._-]{2,})$~', $value, $match)) {      // un pseudo n'est reconnu qu'avec @, sinon « Lyon » deviendrait un profil Facebook
        $handle = $match[1];
        return match ($platform) {
            'facebook' => 'https://www.facebook.com/' . $handle,
            'instagram' => 'https://www.instagram.com/' . $handle,
            'tiktok' => 'https://www.tiktok.com/@' . $handle,
            'youtube' => 'https://www.youtube.com/@' . $handle,
            'linkedin' => 'https://www.linkedin.com/company/' . $handle,
            default => '',
        };
    }
    return '';
}

function pa_normalize_status(string $status): string
{
    $key = pa_key($status);
    $map = [
        'a contacter' => 'a_contacter', 'contacter' => 'a_contacter', 'contacte' => 'contacte',
        'interesse' => 'interesse', 'rdv pris' => 'rdv', 'rdv' => 'rdv',
        'boutique creee' => 'boutique_creee', 'client' => 'boutique_creee', 'inscrit' => 'boutique_creee',
        'sans suite' => 'perdu', 'perdu' => 'perdu',
    ];
    return $map[$key] ?? $key;
}

function pa_cli_display_name(array $prospect): string
{
    foreach (['business', 'contact', 'name', 'email', 'phone'] as $key) {
        $value = trim((string) ($prospect[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return 'Prospect sans nom';
}

function pa_cli_dedupe_key(array $prospect): string
{
    $website = pa_normalize_url((string) ($prospect['website'] ?? ''));
    if ($website !== '') {
        $host = strtolower((string) parse_url($website, PHP_URL_HOST));
        $path = rtrim((string) parse_url($website, PHP_URL_PATH), '/');
        return preg_replace('/^www\./', '', $host) . $path;
    }
    $name = mb_strtolower(trim(($prospect['business'] ?? '') . '|' . ($prospect['city'] ?? '')));
    return $name !== '|' ? $name : '';
}

function pa_cli_stats(array $results): array
{
    $stats = [
        'count' => count($results), 'priorite_1' => 0, 'priorite_2' => 0, 'priorite_3' => 0, 'deja_equipe' => 0,
        'no_site' => 0, 'inaccessible' => 0, 'no_https' => 0, 'not_mobile' => 0, 'no_social' => 0,
        'social_unverified' => 0, 'avg_web' => 0, 'avg_social' => null, 'avg_global' => 0,
        'followers_total' => 0, 'with_contact' => 0,
    ];
    $web = [];
    $global = [];
    $social = [];
    foreach ($results as $result) {
        $scores = $result['scores'];
        $stats[$scores['tier']] = ($stats[$scores['tier']] ?? 0) + 1;
        $website = $result['site'];
        if (($website['state'] ?? '') === 'absent') $stats['no_site']++;
        if (in_array((string) ($website['state'] ?? ''), ['inaccessible', 'erreur', 'non_html'], true)) $stats['inaccessible']++;
        if (($website['state'] ?? '') === 'ok') {
            $fetch = (array) ($website['fetch'] ?? []);
            $metrics = (array) ($website['metrics'] ?? []);
            if (empty($fetch['final_url']) || !str_starts_with(strtolower((string) $fetch['final_url']), 'https://')) $stats['no_https']++;
            if (empty($metrics['viewport']) || !str_contains(strtolower((string) $metrics['viewport']), 'width=device-width')) $stats['not_mobile']++;
        }
        if (!empty($result['social']['checked']) && (int) ($result['social']['present'] ?? 0) === 0) $stats['no_social']++;
        if (!empty($result['social']['blocked'])) $stats['social_unverified']++;
        if (trim($result['phone']) !== '' || trim($result['email']) !== '') $stats['with_contact']++;
        $stats['followers_total'] += (int) ($result['social']['followers_total'] ?? 0);
        $web[] = (int) $scores['web'];
        $global[] = (int) $scores['global'];
        if ($scores['social'] !== null) $social[] = (int) $scores['social'];
    }
    $stats['avg_web'] = $web ? (int) round(array_sum($web) / count($web)) : 0;
    $stats['avg_global'] = $global ? (int) round(array_sum($global) / count($global)) : 0;
    $stats['avg_social'] = $social ? (int) round(array_sum($social) / count($social)) : null;
    return $stats;
}
