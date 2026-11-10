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
    $key = pa_dedupe_key($prospect);
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
    fwrite(STDERR, sprintf("\r[%d/%d] %s", $index + 1, $total, mb_substr(pa_display_name($prospect), 0, 48)));
    $result = pa_audit_prospect($prospect, [
        'timeout' => $timeout,
        'social_timeout' => $socialTimeout,
        'no_social' => $noSocial,
        'allow_private' => $allowPrivate,
    ]);
    if ($flag('verbose')) {
        $scores = $result['scores'];
        fwrite(STDERR, sprintf(
            "\n  site %s · web %d · réseaux %s · global %d · priorité %d (%s)\n",
            $result['site']['state'], $scores['web'], $scores['social'] === null ? '—' : $scores['social'],
            $scores['global'], $scores['priority'], $scores['tier_label']
        ));
    }
    $results[] = $result;
}
fwrite(STDERR, "\n");

pa_sort_results($results, $sort);
$report = pa_build_report($results, $source, $started);
$stats = $report['stats'];

$outDir = rtrim((string) $opt('out', dirname(__DIR__) . '/data/rapports/audit-prospects-' . date('Y-m-d-Hi')), '/');
try {
    $files = pa_write_report($report, $outDir);
} catch (RuntimeException $e) {
    pa_cli_fail($e->getMessage());
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
        if (pa_display_name($prospect) !== 'Prospect sans nom') $prospects[] = $prospect;
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
        $prospect = pa_prospect_from_row($row);
        if (pa_display_name($prospect) !== 'Prospect sans nom') $prospects[] = $prospect;
    }
    return [$prospects, 'carnet SQLite · ' . basename($path)];
}
