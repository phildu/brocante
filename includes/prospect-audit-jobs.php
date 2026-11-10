<?php

// Audits lancés depuis le portail : chaque audit vit dans son dossier sous
// data/rapports/vente-<carnet>/<identifiant>/ (jamais servi en HTTP), rempli par
// petits lots pour que chaque requête reste courte, puis finalisé en HTML, CSV,
// Markdown et JSON. Le dossier contient des coordonnées de prospects : accès par
// le portail authentifié uniquement, et purge automatique des plus anciens.

require_once __DIR__ . '/prospect-audit.php';
require_once __DIR__ . '/prospect-audit-report.php';

const PAJ_KEEP = 10;
const PAJ_BATCH_MAX = 5;

/** Racine des rapports (surchargeable par les tests avec AUDIT_REPORTS_DIR). */
function paj_root(): string
{
    $dir = getenv('AUDIT_REPORTS_DIR');
    return rtrim($dir !== false && $dir !== '' ? $dir : dirname(__DIR__) . '/data/rapports', '/');
}

/** Dossier propre à un carnet : « portail », « galerie-<slug> » ou « boutique-<slug> ». */
function paj_scope(string $scope): string
{
    $scope = preg_replace('/[^a-z0-9-]+/', '-', strtolower(trim($scope))) ?? '';
    return 'vente-' . trim($scope, '-');
}

function paj_valid_id(string $id): bool
{
    return (bool) preg_match('/^[a-f0-9]{16}$/', $id);
}

/** Dossier d'un audit ; l'identifiant est validé, aucun chemin extérieur n'est accepté. */
function paj_dir(string $scope, string $id): string
{
    if (!paj_valid_id($id)) throw new InvalidArgumentException('Audit inconnu.');
    return paj_root() . '/' . paj_scope($scope) . '/' . $id;
}

/** Crée un audit à partir des prospects déjà lus ; renvoie identifiant et total. */
function paj_create(string $scope, array $prospects, array $options = []): array
{
    $id = bin2hex(random_bytes(8));
    $job = [
        'id' => $id,
        'scope' => paj_scope($scope),
        'created_at' => date('c'),
        'options' => [
            'social' => !empty($options['social']),
            'timeout' => max(4, min(30, (int) ($options['timeout'] ?? 10))),
            'social_timeout' => max(3, min(15, (int) ($options['social_timeout'] ?? 6))),
        ],
        'prospects' => array_values($prospects),
        'offset' => 0,
        'results' => [],
        'finished' => false,
    ];
    paj_save($job);
    paj_prune($scope);
    return ['id' => $id, 'total' => count($job['prospects']), 'options' => $job['options']];
}

function paj_load(string $scope, string $id): ?array
{
    if (!paj_valid_id($id)) return null;
    $file = paj_dir($scope, $id) . '/job.json';
    if (!is_file($file)) return null;
    $job = json_decode((string) file_get_contents($file), true);
    if (!is_array($job) || ($job['id'] ?? '') !== $id || ($job['scope'] ?? '') !== paj_scope($scope)) return null;
    return $job;
}

function paj_save(array $job): void
{
    $scopeKey = (string) ($job['scope'] ?? '');
    $id = (string) ($job['id'] ?? '');
    if (!preg_match('/^vente-[a-z0-9-]*$/', $scopeKey) || !paj_valid_id($id)) {
        throw new InvalidArgumentException('Audit invalide.');
    }
    $dir = paj_root() . '/' . $scopeKey . '/' . $id;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossible de créer ' . $dir);
    }
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($dir . '/job.json', $json, LOCK_EX) === false) {
        throw new RuntimeException('Impossible d’écrire l’audit dans ' . $dir);
    }
}

/** Prochains prospects à auditer, sans avancer le curseur (un lot interrompu se reprend). */
function paj_take(string $scope, string $id, int $limit = 2): ?array
{
    $job = paj_load($scope, $id);
    if (!$job || !empty($job['finished'])) return null;
    $total = count($job['prospects']);
    $offset = max(0, min($total, (int) ($job['offset'] ?? 0)));
    if ($offset >= $total) return null;
    return [
        'prospects' => array_slice($job['prospects'], $offset, max(1, min(PAJ_BATCH_MAX, $limit))),
        'offset' => $offset,
        'total' => $total,
        'options' => (array) ($job['options'] ?? []),
    ];
}

/** Enregistre les résultats d'un lot ; le curseur avance du nombre réellement audité. */
function paj_append(string $scope, string $id, array $results): array
{
    $job = paj_load($scope, $id);
    if (!$job) throw new InvalidArgumentException('Audit inconnu.');
    foreach ($results as $result) {
        if (is_array($result)) $job['results'][] = $result;
    }
    $job['offset'] = min(count($job['prospects']), (int) ($job['offset'] ?? 0) + count($results));
    paj_save($job);
    return ['done' => (int) $job['offset'], 'total' => count($job['prospects'])];
}

/** Classe, met en forme et écrit les quatre formats ; renvoie les chemins et le rapport. */
function paj_finish(string $scope, string $id, string $source = 'carnet de vente'): array
{
    $job = paj_load($scope, $id);
    if (!$job) throw new InvalidArgumentException('Audit inconnu.');
    if (!$job['results']) throw new RuntimeException('Aucun résultat à mettre en forme.');
    $results = $job['results'];
    pa_sort_results($results);
    $started = (int) (strtotime((string) $job['created_at']) ?: time());
    $report = pa_build_report($results, $source, (float) $started);
    $files = pa_write_report($report, paj_dir($scope, $id));
    $job['finished'] = true;
    $job['finished_at'] = date('c');
    $job['count'] = count($results);
    paj_save($job);
    return ['files' => $files, 'report' => $report, 'job' => $job];
}

/** Audits du carnet, du plus récent au plus ancien (métadonnées seulement). */
function paj_list(string $scope, int $limit = PAJ_KEEP): array
{
    $base = paj_root() . '/' . paj_scope($scope);
    $items = [];
    foreach (glob($base . '/*/job.json') ?: [] as $file) {
        $id = basename(dirname($file));
        if (!paj_valid_id($id)) continue;
        $job = json_decode((string) file_get_contents($file), true);
        if (!is_array($job) || ($job['id'] ?? '') !== $id) continue;
        $items[] = [
            'id' => $id,
            'created_at' => (string) ($job['created_at'] ?? ''),
            'total' => count((array) ($job['prospects'] ?? [])),
            'done' => (int) ($job['offset'] ?? 0),
            'finished' => !empty($job['finished']),
            'count' => (int) ($job['count'] ?? 0),
        ];
    }
    usort($items, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return array_slice($items, 0, max(1, $limit));
}

/** Chemin d'un fichier du rapport finalisé, ou null s'il n'existe pas. */
function paj_file(string $scope, string $id, string $format): ?string
{
    if (!in_array($format, ['html', 'csv', 'md', 'json'], true)) return null;
    $job = paj_load($scope, $id);
    if (!$job || empty($job['finished'])) return null;
    $path = paj_dir($scope, $id) . '/audit-prospects.' . $format;
    return is_file($path) ? $path : null;
}

/** Ne garde que les PAJ_KEEP audits les plus récents du carnet. */
function paj_prune(string $scope, int $keep = PAJ_KEEP): void
{
    $base = paj_root() . '/' . paj_scope($scope);
    $dirs = [];
    foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $id = basename($dir);
        if (!paj_valid_id($id)) continue;
        $dirs[$id] = (int) (@filemtime($dir . '/job.json') ?: 0);
    }
    if (count($dirs) <= $keep) return;
    arsort($dirs);
    foreach (array_slice(array_keys($dirs), $keep) as $id) {
        foreach (glob($base . '/' . $id . '/*') ?: [] as $file) {
            if (is_file($file)) @unlink($file);
        }
        @rmdir($base . '/' . $id);
    }
}
