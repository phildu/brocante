<?php
/**
 * Édition du contenu du centre d'aide depuis le portail (portail/aide-contenu.php).
 *
 * Les fichiers aide/contenu/*.json restent la base livrée avec le code. Les corrections faites dans le portail
 * sont gardées à part, dans data/saas/aide-contenu.json (dossier jamais envoyé par le déploiement), puis
 * appliquées par-dessus la base à chaque lecture (aide_json_file()) : un déploiement ne les écrase donc pas.
 *
 * Pour chaque type (faq, glossaire, tutoriels) le fichier d'édition garde :
 *   modifs  : clé de la fiche => champs corrigés (seuls ceux qui diffèrent de la base),
 *   ajouts  : fiches créées dans le portail (FAQ et glossaire),
 *   masques : clés des fiches de la base cachées du centre d'aide.
 *
 * Ce fichier ne dépend d'aucun autre : il peut être chargé par n'importe quelle page.
 */

const AIDE_EDIT_TYPES = [
    'faq' => ['file' => 'faq.json', 'list' => 'questions', 'key' => 'id', 'label' => 'FAQ'],
    'glossaire' => ['file' => 'glossaire.json', 'list' => 'termes', 'key' => 'terme', 'label' => 'Glossaire'],
    'tutoriels' => ['file' => 'tutoriels.json', 'list' => 'tutoriels', 'key' => 'slug', 'label' => 'Tutoriels'],
];

const AIDE_EDIT_ROLES = ['vendeur', 'organisateur', 'visiteur', 'commune', 'tous'];

function aide_edit_file(): string
{
    // BROCANTE_AIDE_EDIT_FILE : fichier jetable pour les tests.
    return getenv('BROCANTE_AIDE_EDIT_FILE') ?: dirname(__DIR__) . '/data/saas/aide-contenu.json';
}

/** Texte nettoyé : sans caractères de contrôle, espaces rognés, longueur bornée ; $multiline garde les retours à la ligne. */
function aide_edit_clean(mixed $value, int $max, bool $multiline = false): string
{
    $v = str_replace(["\r\n", "\r"], "\n", is_scalar($value) ? (string) $value : '');
    $v = (string) preg_replace('/[^\P{C}\n]+/u', '', $v);
    $v = $multiline ? trim($v) : trim((string) preg_replace('/\s+/u', ' ', $v));
    return mb_substr($v, 0, $max);
}

function aide_edit_slug(string $text): string
{
    $s = strtr(mb_strtolower($text), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y']);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Contenu de la base livrée avec le code (sans les corrections du portail) : liste de fiches d'un type. */
function aide_edit_base(string $type): array
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return [];
    $t = AIDE_EDIT_TYPES[$type];
    $path = dirname(__DIR__) . '/aide/contenu/' . $t['file'];
    $data = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
    $rows = [];
    foreach ((array) (is_array($data) ? ($data[$t['list']] ?? []) : []) as $row) {
        if (is_array($row) && (string) ($row[$t['key']] ?? '') !== '') $rows[] = $row;
    }
    return $rows;
}

/** Corrections enregistrées, au format complet (les trois types, avec modifs / ajouts / masques). */
function aide_edit_load(bool $reload = false): array
{
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $file = aide_edit_file();
    $raw = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    $out = [];
    foreach (AIDE_EDIT_TYPES as $type => $_) {
        $block = is_array($raw) && is_array($raw[$type] ?? null) ? $raw[$type] : [];
        $out[$type] = [
            'modifs' => array_filter((array) ($block['modifs'] ?? []), 'is_array'),
            'ajouts' => array_values(array_filter((array) ($block['ajouts'] ?? []), 'is_array')),
            'masques' => array_values(array_filter((array) ($block['masques'] ?? []), 'is_string')),
        ];
    }
    return $cache = $out;
}

function aide_edit_save(array $all): ?string
{
    $file = aide_edit_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return "Impossible de créer le dossier $dir.";
    $payload = ['version' => 1, 'updated_at' => gmdate('c')];
    foreach (AIDE_EDIT_TYPES as $type => $_) {
        $b = $all[$type] ?? [];
        $payload[$type] = [
            'modifs' => (object) ($b['modifs'] ?? []),
            'ajouts' => array_values($b['ajouts'] ?? []),
            'masques' => array_values(array_unique($b['masques'] ?? [])),
        ];
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $file . '.tmp';
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return "Impossible d'écrire " . basename($file) . ' (droits du dossier data/saas).';
    }
    aide_edit_load(true);
    return null;
}

/** Applique les corrections à un fichier de la base déjà décodé. Les fichiers sans édition (captures, vidéos) passent tels quels. */
function aide_edit_apply(string $file, array $data): array
{
    foreach (AIDE_EDIT_TYPES as $type => $t) {
        if ($t['file'] !== $file) continue;
        $e = aide_edit_load()[$type];
        $key = $t['key'];
        $rows = [];
        foreach ((array) ($data[$t['list']] ?? []) as $row) {
            if (!is_array($row)) continue;
            $k = (string) ($row[$key] ?? '');
            if (in_array($k, $e['masques'], true)) continue;
            if (isset($e['modifs'][$k])) $row = aide_edit_merge($row, $e['modifs'][$k]);
            $rows[] = $row;
        }
        foreach ($e['ajouts'] as $row) $rows[] = $row;
        $data[$t['list']] = $rows;
    }
    return $data;
}

/** Fiche de la base + champs corrigés. Les étapes d'un tutoriel se corrigent une à une (titre et texte), le reste de l'étape est gardé. */
function aide_edit_merge(array $row, array $modif): array
{
    foreach ($modif as $field => $value) {
        if ($field === 'etapes') {
            foreach ((array) $value as $i => $step) {
                if (!is_array($step) || !isset($row['etapes'][$i]) || !is_array($row['etapes'][$i])) continue;
                foreach (['titre', 'texte'] as $f) {
                    if (isset($step[$f])) $row['etapes'][$i][$f] = (string) $step[$f];
                }
            }
        } else {
            $row[$field] = $value;
        }
    }
    return $row;
}

/** Champs saisis, nettoyés. -> [champs, erreur]. Pour un tutoriel, $base sert à borner les étapes. */
function aide_edit_clean_fields(string $type, array $in, array $base = []): array
{
    $role = in_array($in['role'] ?? '', AIDE_EDIT_ROLES, true) ? (string) $in['role'] : 'tous';
    if ($type === 'faq') {
        $f = ['question' => aide_edit_clean($in['question'] ?? '', 200), 'reponse' => aide_edit_clean($in['reponse'] ?? '', 2000, true), 'role' => $role,
            'mots_cles' => aide_edit_keywords($in['mots_cles'] ?? '')];
        if ($f['question'] === '') return [[], 'La question est vide.'];
        if ($f['reponse'] === '') return [[], 'La réponse est vide.'];
        return [$f, null];
    }
    if ($type === 'glossaire') {
        $f = ['definition' => aide_edit_clean($in['definition'] ?? '', 1000, true), 'exemple' => aide_edit_clean($in['exemple'] ?? '', 500, true)];
        if ($f['definition'] === '') return [[], 'La définition est vide.'];
        return [$f, null];
    }
    $f = ['titre' => aide_edit_clean($in['titre'] ?? '', 200), 'resume' => aide_edit_clean($in['resume'] ?? '', 600, true), 'role' => $role, 'etapes' => []];
    if ($f['titre'] === '') return [[], 'Le titre est vide.'];
    if ($f['resume'] === '') return [[], 'Le résumé est vide.'];
    foreach ((array) ($base['etapes'] ?? []) as $i => $_) {
        $s = $in['etapes'][$i] ?? null;
        if (!is_array($s)) continue;
        $titre = aide_edit_clean($s['titre'] ?? '', 160);
        $texte = aide_edit_clean($s['texte'] ?? '', 1500, true);
        if ($titre === '' || $texte === '') return [[], 'L’étape ' . ($i + 1) . ' ne peut pas avoir un titre ou un texte vide.'];
        $f['etapes'][$i] = ['titre' => $titre, 'texte' => $texte];
    }
    return [$f, null];
}

function aide_edit_keywords(mixed $value): array
{
    $parts = is_array($value) ? $value : explode(',', (string) $value);
    $out = [];
    foreach ($parts as $p) {
        $w = aide_edit_clean($p, 40);
        if ($w !== '' && !in_array($w, $out, true)) $out[] = $w;
        if (count($out) >= 20) break;
    }
    return $out;
}

/** Ne garde que ce qui diffère de la fiche de la base. */
function aide_edit_diff(string $type, array $base, array $fields): array
{
    $diff = [];
    foreach ($fields as $field => $value) {
        if ($field === 'etapes') {
            foreach ($value as $i => $step) {
                foreach (['titre', 'texte'] as $f) {
                    if ((string) ($base['etapes'][$i][$f] ?? '') !== $step[$f]) $diff['etapes'][$i][$f] = $step[$f];
                }
            }
        } elseif ($field === 'mots_cles') {
            if (array_values((array) ($base[$field] ?? [])) !== $value) $diff[$field] = $value;
        } elseif ((string) ($base[$field] ?? ($field === 'role' ? 'tous' : '')) !== $value) {
            $diff[$field] = $value;
        }
    }
    return $diff;
}

function aide_edit_find_base(string $type, string $key): ?array
{
    $k = AIDE_EDIT_TYPES[$type]['key'] ?? '';
    foreach (aide_edit_base($type) as $row) {
        if ((string) $row[$k] === $key) return $row;
    }
    return null;
}

/** Corrige une fiche de la base ou une fiche ajoutée dans le portail. -> message d'erreur ou null. */
function aide_edit_update(string $type, string $key, array $input): ?string
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return 'Type de fiche inconnu.';
    $all = aide_edit_load();
    $keyField = AIDE_EDIT_TYPES[$type]['key'];
    $base = aide_edit_find_base($type, $key);
    if ($base !== null) {
        [$fields, $err] = aide_edit_clean_fields($type, $input, $base);
        if ($err !== null) return $err;
        $diff = aide_edit_diff($type, $base, $fields);
        if ($diff) $all[$type]['modifs'][$key] = $diff; else unset($all[$type]['modifs'][$key]);
        return aide_edit_save($all);
    }
    foreach ($all[$type]['ajouts'] as $i => $row) {
        if ((string) ($row[$keyField] ?? '') !== $key) continue;
        [$fields, $err] = aide_edit_clean_fields($type, $input);
        if ($err !== null) return $err;
        $all[$type]['ajouts'][$i] = array_merge($row, $fields, [$keyField => $key]);
        return aide_edit_save($all);
    }
    return 'Fiche introuvable.';
}

/** Revient au texte de la base : efface les corrections d'une fiche et la remet dans le centre d'aide. */
function aide_edit_reset(string $type, string $key): ?string
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return 'Type de fiche inconnu.';
    $all = aide_edit_load();
    unset($all[$type]['modifs'][$key]);
    $all[$type]['masques'] = array_values(array_diff($all[$type]['masques'], [$key]));
    return aide_edit_save($all);
}

/** Cache ou remet une fiche de la base dans le centre d'aide (elle n'est jamais supprimée). */
function aide_edit_hide(string $type, string $key, bool $hide): ?string
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return 'Type de fiche inconnu.';
    if (aide_edit_find_base($type, $key) === null) return 'Fiche introuvable.';
    $all = aide_edit_load();
    $all[$type]['masques'] = array_values(array_diff($all[$type]['masques'], [$key]));
    if ($hide) $all[$type]['masques'][] = $key;
    return aide_edit_save($all);
}

/** Crée une fiche (FAQ ou glossaire). -> message d'erreur ou null. */
function aide_edit_add(string $type, array $input): ?string
{
    if ($type !== 'faq' && $type !== 'glossaire') return 'On ne crée ici que des questions ou des termes du glossaire.';
    [$fields, $err] = aide_edit_clean_fields($type, $input);
    if ($err !== null) return $err;
    $all = aide_edit_load();
    if ($type === 'faq') {
        $base = 'perso-' . (mb_substr(aide_edit_slug($fields['question']), 0, 50) ?: 'question');
        $taken = array_merge(array_column(aide_edit_base('faq'), 'id'), array_column($all['faq']['ajouts'], 'id'));
        $id = $base;
        for ($n = 2; in_array($id, $taken, true); $n++) $id = $base . '-' . $n;
        $all['faq']['ajouts'][] = ['id' => $id, 'role' => $fields['role'], 'question' => $fields['question'], 'reponse' => $fields['reponse'],
            'mots_cles' => $fields['mots_cles'], 'liens' => []];
    } else {
        $terme = aide_edit_clean($input['terme'] ?? '', 80);
        if ($terme === '') return 'Le terme est vide.';
        $taken = array_merge(array_column(aide_edit_base('glossaire'), 'terme'), array_column($all['glossaire']['ajouts'], 'terme'));
        foreach ($taken as $t) {
            if (mb_strtolower((string) $t) === mb_strtolower($terme)) return 'Ce terme existe déjà.';
        }
        $all['glossaire']['ajouts'][] = ['terme' => $terme, 'definition' => $fields['definition'], 'exemple' => $fields['exemple'], 'voir' => []];
    }
    return aide_edit_save($all);
}

/** Supprime une fiche créée dans le portail (celles de la base se cachent, elles ne se suppriment pas). */
function aide_edit_delete_added(string $type, string $key): ?string
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return 'Type de fiche inconnu.';
    $keyField = AIDE_EDIT_TYPES[$type]['key'];
    $all = aide_edit_load();
    $before = count($all[$type]['ajouts']);
    $all[$type]['ajouts'] = array_values(array_filter($all[$type]['ajouts'], static fn (array $r): bool => (string) ($r[$keyField] ?? '') !== $key));
    if (count($all[$type]['ajouts']) === $before) return 'Fiche introuvable.';
    return aide_edit_save($all);
}

/** Fiches d'un type pour l'écran d'édition : base + ajouts, avec leur état (corrigée, cachée, ajoutée) et le texte livré avec le code. */
function aide_edit_rows(string $type): array
{
    if (!isset(AIDE_EDIT_TYPES[$type])) return [];
    $keyField = AIDE_EDIT_TYPES[$type]['key'];
    $e = aide_edit_load()[$type];
    $rows = [];
    foreach (aide_edit_base($type) as $base) {
        $k = (string) $base[$keyField];
        $rows[] = ['key' => $k, 'added' => false, 'hidden' => in_array($k, $e['masques'], true), 'changed' => isset($e['modifs'][$k]),
            'base' => $base, 'row' => isset($e['modifs'][$k]) ? aide_edit_merge($base, $e['modifs'][$k]) : $base];
    }
    foreach ($e['ajouts'] as $row) {
        $rows[] = ['key' => (string) ($row[$keyField] ?? ''), 'added' => true, 'hidden' => false, 'changed' => false, 'base' => $row, 'row' => $row];
    }
    return $rows;
}
