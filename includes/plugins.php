<?php

// Plugins de la plateforme (« packs ») : un seul code par plugin, dans packs/<id>/, servi à toutes les boutiques et activé
// boutique par boutique (portail → Plugins). Les données restent isolées : chaque boutique garde dans SA base (data/<slug>.db,
// brocante.db pour le Petit Chalet) la table `plugins` (activé, version installée, dernière migration appliquée) et les tables
// du plugin, toutes préfixées comme le déclare son manifeste.
//
// Manifeste packs/<id>/plugin.json (exemple complet : packs/hello/plugin.json, mode d'emploi dans le README, « Plugins ») :
//   id, name, version (x.y.z), description, table_prefix (« hello_ »),
//   pages : [{ id, label, icon, file, roles, replaces }] → /admin/plugin.php?p=<id>&v=<page> ; « replaces » : entrée du menu
//           de la plateforme dont la page prend la place (ex. « media »)
//   api   : [{ id, file, methods, roles }]              → /admin/plugin-api.php?p=<id>&r=<route> (JSON ; GET, POST, PUT, PATCH, DELETE)
//   admin_assets : [{ file, roles }]                    → scripts et styles de assets/ chargés sur toutes les pages de l'administration
//   cron  : [{ file }]                                  → tâches lancées par scripts/plugins-cron.php pour chaque boutique où le
//           plugin est actif (ligne de commande, variable TENANT)
//   agent_api : [{ prefix, file }]                      → /api/<prefix>/… (plugin-agent.php) : appels d'un agent externe (ex. agent de
//           rendu vidéo), sans session ni CSRF ; la route authentifie elle-même l'agent (jeton) ; boutique d'après l'adresse
//   sync  : { tool, source, record }                    → plugin recopié d'un autre projet (ex. electroboy80) : « tool » est le
//           script d'extraction (ligne de commande, option --status en JSON), « source » le nom du projet, « record » le fichier
//           qui dit d'où vient la copie ; le portail local propose « Recopier » (portail → Plugins)
//   migrations : fichiers packs/<id>/migrations/NNN-quelque-chose.sql, appliqués dans l'ordre, une seule fois chacun.
// Rôles : « admin » (toujours autorisé) et « community_manager ». Les pages et l'API passent par la session de
// l'administration de la boutique ; l'API exige le jeton CSRF (en-tête X-CSRF-Token) pour tout ce qui n'est pas GET.
// Aucun fichier de packs/ n'est servi directement, sauf les ressources de packs/<id>/assets/ (packs/.htaccess, router.php,
// LocalValetDriver.php), et chaque fichier PHP d'un plugin commence par « defined('PLUGIN_RUNTIME') || exit; ».
//
// Une boutique sans plugin n'a ni table `plugins`, ni menu, ni route : la table n'est créée qu'à la première activation.
// Une boutique dont la base est en retard sur le code (nouvelle version déployée) garde le plugin en pause jusqu'à
// « Mettre à jour » dans le portail, qui sauvegarde la base (data/backups/) avant d'appliquer les migrations.

const PLUGIN_ROLES = ['admin', 'community_manager'];
const PLUGIN_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

/** Dossier des plugins. */
function plugins_dir(): string
{
    return dirname(__DIR__) . '/packs';
}

/** Chemin relatif sûr d'un fichier de plugin (« pages/index.php ») : pas de « .. », pas de chemin absolu. */
function plugin_safe_file(string $dir, string $file, string $ext): ?string
{
    if (!preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.' . $ext . '$#', $file)) return null;
    return is_file($dir . '/' . $file) ? $file : null;
}

/** Rôles autorisés déclarés (« admin » toujours inclus). */
function plugin_roles(mixed $roles): array
{
    $roles = array_values(array_intersect(PLUGIN_ROLES, is_array($roles) ? $roles : []));
    return array_values(array_unique(array_merge(['admin'], $roles)));
}

/**
 * Lit et valide un manifeste. Renvoie le manifeste normalisé (avec 'dir' et 'migrations' => [numéro => chemin]) ou null,
 * les raisons du refus dans $errors.
 */
function plugin_manifest_read(string $file, ?array &$errors = null): ?array
{
    $errors = [];
    $dir = dirname($file);
    $raw = json_decode((string) @file_get_contents($file), true);
    if (!is_array($raw)) {
        $errors[] = 'JSON illisible';
        return null;
    }
    $id = (string) ($raw['id'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9-]{1,31}$/', $id) || $id !== basename($dir)) $errors[] = "identifiant « $id » invalide ou différent du dossier";
    $name = trim((string) ($raw['name'] ?? ''));
    if ($name === '') $errors[] = 'nom manquant';
    $version = (string) ($raw['version'] ?? '');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) $errors[] = "version « $version » invalide (x.y.z attendu)";
    $prefix = (string) ($raw['table_prefix'] ?? '');
    if (!preg_match('/^[a-z][a-z0-9]{1,15}_$/', $prefix) || $prefix === 'plugins_') $errors[] = "préfixe de table « $prefix » invalide";

    $pages = [];
    foreach ((array) ($raw['pages'] ?? []) as $p) {
        $pid = (string) ($p['id'] ?? '');
        $pfile = plugin_safe_file($dir, (string) ($p['file'] ?? ''), 'php');
        if (!preg_match('/^[a-z0-9-]{1,32}$/', $pid) || $pfile === null || isset($pages[$pid])) {
            $errors[] = "page « $pid » invalide (identifiant ou fichier)";
            continue;
        }
        $replaces = (string) ($p['replaces'] ?? '');
        if ($replaces !== '' && !preg_match('/^[a-z0-9-]{1,32}$/', $replaces)) {
            $errors[] = "page « $pid » : entrée de menu remplacée « $replaces » invalide";
            continue;
        }
        $pages[$pid] = ['id' => $pid, 'label' => trim((string) ($p['label'] ?? '')) ?: $name, 'icon' => (string) ($p['icon'] ?? 'puzzle'),
            'file' => $pfile, 'roles' => plugin_roles($p['roles'] ?? []), 'menu' => ($p['menu'] ?? true) !== false, 'replaces' => $replaces];
    }
    // Ressources chargées sur toutes les pages de l'administration (pour enrichir les pages de la plateforme) : [{ file, roles }].
    $assets = [];
    foreach ((array) ($raw['admin_assets'] ?? []) as $a) {
        $afile = (string) ($a['file'] ?? '');
        if (!preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.(?:js|css)$#', $afile) || !is_file($dir . '/assets/' . $afile)) {
            $errors[] = "ressource d'administration « $afile » invalide (fichier .js ou .css de assets/)";
            continue;
        }
        $assets[] = ['file' => $afile, 'roles' => plugin_roles($a['roles'] ?? [])];
    }
    $api = [];
    foreach ((array) ($raw['api'] ?? []) as $r) {
        $rid = (string) ($r['id'] ?? '');
        $rfile = plugin_safe_file($dir, (string) ($r['file'] ?? ''), 'php');
        $methods = array_values(array_intersect(PLUGIN_METHODS, array_map('strtoupper', (array) ($r['methods'] ?? ['GET']))));
        if (!preg_match('/^[a-z0-9-]{1,32}$/', $rid) || $rfile === null || !$methods || isset($api[$rid])) {
            $errors[] = "route d'API « $rid » invalide (identifiant, fichier ou méthodes)";
            continue;
        }
        $api[$rid] = ['id' => $rid, 'file' => $rfile, 'methods' => $methods, 'roles' => plugin_roles($r['roles'] ?? [])];
    }
    // Tâches planifiées (scripts/plugins-cron.php) : [{ file }], scripts PHP du plugin lancés en ligne de commande, boutique par boutique.
    $cron = [];
    foreach ((array) ($raw['cron'] ?? []) as $c) {
        $cfile = plugin_safe_file($dir, (string) ($c['file'] ?? ''), 'php');
        if ($cfile === null) {
            $errors[] = 'tâche planifiée « ' . ($c['file'] ?? '') . ' » invalide';
            continue;
        }
        $cron[] = ['file' => $cfile];
    }
    // Agents externes (plugin-agent.php) : [{ prefix, file }], préfixe d'adresse sous /api/ et fichier de route du plugin.
    $agentApi = [];
    foreach ((array) ($raw['agent_api'] ?? []) as $a) {
        $agentPrefix = (string) ($a['prefix'] ?? ''); // pas $prefix : c'est le préfixe des tables, renvoyé plus bas
        $afile = plugin_safe_file($dir, (string) ($a['file'] ?? ''), 'php');
        if (!preg_match('#^/[a-z0-9-]+(?:/[a-z0-9-]+)*$#', $agentPrefix) || $afile === null) {
            $errors[] = "route d'agent « $agentPrefix » invalide (préfixe /x/y ou fichier)";
            continue;
        }
        $agentApi[] = ['prefix' => $agentPrefix, 'file' => $afile];
    }
    // Copie d'un autre projet (portail local → Plugins → « Recopier ») : { tool, source, record }.
    $sync = null;
    if (isset($raw['sync'])) {
        $tool = plugin_safe_file($dir, (string) ($raw['sync']['tool'] ?? ''), 'php');
        $record = (string) ($raw['sync']['record'] ?? '');
        $record = preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.txt$#', $record) && is_file($dir . '/' . $record) ? $record : null;
        $source = trim((string) ($raw['sync']['source'] ?? ''));
        if ($tool === null || $source === '') $errors[] = 'copie « sync » invalide (script ou nom de la source)';
        else $sync = ['tool' => $tool, 'source' => $source, 'record' => $record];
    }
    $migrations = [];
    foreach (glob($dir . '/migrations/*.sql') ?: [] as $sql) {
        if (!preg_match('/^(\d{3})-[a-z0-9-]+\.sql$/', basename($sql), $mm) || isset($migrations[(int) $mm[1]])) {
            $errors[] = 'migration « ' . basename($sql) . ' » mal nommée (NNN-nom.sql, numéros uniques)';
            continue;
        }
        $migrations[(int) $mm[1]] = $sql;
    }
    ksort($migrations);
    if ($errors) return null;
    return [
        'id' => $id, 'name' => $name, 'version' => $version, 'description' => trim((string) ($raw['description'] ?? '')),
        'table_prefix' => $prefix, 'pages' => $pages, 'api' => $api, 'admin_assets' => $assets, 'cron' => $cron, 'agent_api' => $agentApi, 'sync' => $sync, 'migrations' => $migrations, 'dir' => $dir,
    ];
}

/** Plugins disponibles (manifestes valides de packs/*), par identifiant, triés par nom. Un manifeste invalide est ignoré et journalisé. */
function plugin_catalog(): array
{
    static $catalog = null;
    if ($catalog !== null) return $catalog;
    $catalog = [];
    foreach (glob(plugins_dir() . '/*/plugin.json') ?: [] as $file) {
        $m = plugin_manifest_read($file, $errors);
        if ($m === null) {
            error_log('Plugin ignoré (' . $file . ') : ' . implode(' ; ', $errors));
            continue;
        }
        $catalog[$m['id']] = $m;
    }
    uasort($catalog, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $catalog;
}

function plugin_manifest(string $id): ?array
{
    return plugin_catalog()[$id] ?? null;
}

/** Numéro de la dernière migration fournie par le code du plugin (0 sans migration). */
function plugin_last_migration(array $m): int
{
    return $m['migrations'] ? (int) array_key_last($m['migrations']) : 0;
}

// ── État dans la base d'une boutique ───────────────────────────────────────

function plugins_table_exists(PDO $pdo): bool
{
    return (bool) $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'plugins'")->fetchColumn();
}

/** État des plugins d'une base : id => ['enabled', 'version', 'migration', …] ([] si aucun plugin n'y a jamais été activé). */
function plugin_states(PDO $pdo): array
{
    if (!plugins_table_exists($pdo)) return [];
    $out = [];
    foreach ($pdo->query('SELECT * FROM plugins')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['id']] = $row;
    }
    return $out;
}

/**
 * Situation d'un plugin dans une base, d'après sa ligne de `plugins` (ou null) :
 * absent (jamais activé), disabled (désactivé, données conservées), ready (actif et à jour),
 * update (actif, base en retard sur le code : en pause jusqu'à la mise à jour), newer (base plus récente que le code : en pause).
 */
function plugin_status(?array $row, array $m): string
{
    if ($row === null) return 'absent';
    if ((int) $row['enabled'] !== 1) return 'disabled';
    $cmp = version_compare((string) $row['version'], $m['version']);
    if ($cmp > 0 || (int) $row['migration'] > plugin_last_migration($m)) return 'newer';
    if ($cmp < 0 || (int) $row['migration'] < plugin_last_migration($m)) return 'update';
    return 'ready';
}

/** Libellé de la situation (portail, administration). */
function plugin_status_label(string $status, ?array $row, array $m): string
{
    return match ($status) {
        'absent' => 'Non activé',
        'disabled' => 'Désactivé (données conservées)',
        'ready' => 'Actif · v' . $m['version'],
        'update' => 'Mise à jour à faire : v' . ($row['version'] ?? '?') . ' → v' . $m['version'],
        'newer' => 'Base en v' . ($row['version'] ?? '?') . ', plus récente que le code (v' . $m['version'] . ')',
        default => $status,
    };
}

// ── Côté boutique (base du commerce courant, db()) ─────────────────────────

/** État des plugins de la boutique courante (lu une fois par requête). */
function plugins_shop_states(): array
{
    static $states = null;
    if ($states === null) {
        try {
            $states = plugin_states(db());
        } catch (Throwable $e) {
            error_log('Plugins : état illisible : ' . $e->getMessage());
            $states = [];
        }
    }
    return $states;
}

/** Situation d'un plugin dans la boutique courante (« absent » s'il n'existe pas dans le code). */
function plugin_shop_status(string $id): string
{
    $m = plugin_manifest($id);
    return $m ? plugin_status(plugins_shop_states()[$id] ?? null, $m) : 'absent';
}

function plugin_role_allowed(array $roles, string $role): bool
{
    return $role === 'admin' || in_array($role, $roles, true);
}

/** Entrées du menu d'administration des plugins actifs de la boutique pour ce rôle : clé « plugin:<id>:<page> » => [adresse, libellé, icône]. */
function plugin_nav_items(string $role): array
{
    $items = [];
    foreach (plugins_shop_states() as $id => $row) {
        $m = plugin_manifest($id);
        if (!$m || !in_array(plugin_status($row, $m), ['ready', 'update', 'newer'], true)) continue;
        foreach ($m['pages'] as $page) {
            if (!$page['menu'] || !plugin_role_allowed($page['roles'], $role)) continue;
            $items['plugin:' . $id . ':' . $page['id']] = [plugin_page_url($id, $page['id']), $page['label'], $page['icon']];
        }
    }
    return $items;
}

/** Entrées du menu de la plateforme remplacées par une page de plugin active, ouverte à ce rôle (ex. « media »). */
function plugin_nav_replaced(string $role): array
{
    $keys = [];
    foreach (plugins_shop_states() as $id => $row) {
        $m = plugin_manifest($id);
        if (!$m || plugin_status($row, $m) !== 'ready') continue;
        foreach ($m['pages'] as $page) {
            if ($page['replaces'] !== '' && plugin_role_allowed($page['roles'], $role)) $keys[] = $page['replaces'];
        }
    }
    return $keys;
}

/**
 * Balises des ressources que les plugins actifs chargent sur toutes les pages de l'administration. Chaque script reçoit
 * l'adresse de base de l'API de son plugin (data-api, à compléter par le nom de la route), le jeton CSRF (data-csrf) et le rôle.
 */
function plugin_admin_assets_html(string $role): string
{
    $html = '';
    foreach (plugins_shop_states() as $id => $row) {
        $m = plugin_manifest($id);
        if (!$m || plugin_status($row, $m) !== 'ready') continue;
        foreach ($m['admin_assets'] as $a) {
            if (!plugin_role_allowed($a['roles'], $role) || ($url = plugin_asset_url($m, $a['file'])) === '') continue;
            $html .= str_ends_with($a['file'], '.css')
                ? '<link rel="stylesheet" href="' . h($url) . '">' . "\n"
                : '<script src="' . h($url) . '" data-plugin="' . h($id) . '" data-api="' . h(plugin_api_url($id, '')) . '" data-csrf="'
                    . h(admin_csrf_token()) . '" data-role="' . h($role) . '" defer></script>' . "\n";
        }
    }
    return $html;
}

function plugin_page_url(string $id, string $page): string
{
    return '/admin/plugin.php?' . http_build_query(['p' => $id, 'v' => $page]);
}

function plugin_api_url(string $id, string $route): string
{
    return '/admin/plugin-api.php?' . http_build_query(['p' => $id, 'r' => $route]);
}

/** Adresse d'une ressource de packs/<id>/assets/ (avec sa date, pour ne jamais servir une version périmée). */
function plugin_asset_url(array $m, string $file): string
{
    $path = $m['dir'] . '/assets/' . $file;
    if (!preg_match('#^[a-z0-9_-]+(?:/[a-z0-9_-]+)*\.[a-z0-9]+$#', $file) || !is_file($path)) return '';
    return '/packs/' . $m['id'] . '/assets/' . $file . '?v=' . (int) filemtime($path);
}

// ── Activation et mises à jour (portail, ou toute autre base ouverte à part) ─

/** Ouvre la base d'un commerce du serveur (exception si elle n'existe pas encore). */
function plugin_open_tenant_db(string $slug): array
{
    $file = tenant_file(tenant_load($slug), 'db_file');
    if (!is_file($file)) throw new RuntimeException("la base du commerce n'existe pas encore (ouvrez la boutique une première fois)");
    $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return [$pdo, $file];
}

/**
 * Vérifie qu'une migration ne touche que les tables du plugin : chaque nom qui suit TABLE, INDEX, TRIGGER, VIEW, INTO, UPDATE,
 * FROM, JOIN ou ON doit commencer par le préfixe déclaré. Renvoie le premier nom fautif, ou null.
 */
function plugin_migration_foreign_name(string $sql, string $prefix): ?string
{
    $sql = preg_replace(['#--[^\n]*#', '#/\*.*?\*/#s', "#'(?:[^']|'')*'#"], ' ', $sql);
    preg_match_all('/\b(?:TABLE|INDEX|TRIGGER|VIEW|INTO|UPDATE|FROM|JOIN|ON)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?["`\[]?([A-Za-z_][A-Za-z0-9_]*)/i', $sql, $mm);
    foreach ($mm[1] as $name) {
        if (in_array(strtoupper($name), ['CONFLICT', 'DELETE', 'UPDATE', 'INSERT'], true)) continue;
        if (!str_starts_with(strtolower($name), $prefix)) return $name;
    }
    return null;
}

/** Copie cohérente de la base avant une migration : data/backups/<base>-plugin-<id>-<de>-<vers>-<horodatage>.db. */
function plugin_backup(PDO $pdo, string $dbFile, string $id, string $from, string $to): string
{
    $dir = dirname(__DIR__) . '/data/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('dossier de sauvegarde impossible à créer');
    $target = $dir . '/' . basename($dbFile, '.db') . '-plugin-' . $id . '-' . ($from !== '' ? $from : '0') . '-' . $to . '-' . date('Ymd-His') . '.db';
    $pdo->exec('VACUUM INTO ' . $pdo->quote($target));
    @chmod($target, 0600);
    return $target;
}

/**
 * Installe ou met à jour un plugin dans une base : applique les migrations manquantes (sauvegarde d'abord, puis une
 * transaction), puis enregistre la version du code. Ne change pas l'activation. Renvoie
 * ['from' => version, 'to' => version, 'applied' => nombre de migrations, 'backup' => chemin ou null].
 */
function plugin_install(PDO $pdo, string $dbFile, array $m): array
{
    $lockDir = dirname(__DIR__) . '/data/backups';
    if (!is_dir($lockDir)) @mkdir($lockDir, 0700, true);
    $lock = @fopen($lockDir . '/.plugins-' . basename($dbFile) . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('verrou de mise à jour indisponible');
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS plugins (
            id TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL DEFAULT 0,
            version TEXT NOT NULL DEFAULT '',
            migration INTEGER NOT NULL DEFAULT 0,
            installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $row = plugin_states($pdo)[$m['id']] ?? null;
        $from = (string) ($row['version'] ?? '');
        $done = (int) ($row['migration'] ?? 0);
        if ($row !== null && plugin_status(['enabled' => 1] + $row, $m) === 'newer') {
            throw new RuntimeException('la base est en v' . $from . ', plus récente que le code (v' . $m['version'] . ') : redéployez la bonne version');
        }
        $pending = array_filter($m['migrations'], static fn (int $n): bool => $n > $done, ARRAY_FILTER_USE_KEY);
        foreach ($pending as $n => $file) {
            if (($bad = plugin_migration_foreign_name((string) file_get_contents($file), $m['table_prefix'])) !== null) {
                throw new RuntimeException('migration ' . basename($file) . " : la table « $bad » n'a pas le préfixe " . $m['table_prefix']);
            }
        }
        $backup = $pending ? plugin_backup($pdo, $dbFile, $m['id'], $from, $m['version']) : null;
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach ($pending as $n => $file) {
                $pdo->exec((string) file_get_contents($file));
                $done = $n;
            }
            $pdo->prepare("INSERT INTO plugins (id, enabled, version, migration) VALUES (?, 0, ?, ?)
                ON CONFLICT(id) DO UPDATE SET version = excluded.version, migration = excluded.migration, updated_at = CURRENT_TIMESTAMP")
                ->execute([$m['id'], $m['version'], $done]);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            $pdo->exec('ROLLBACK');
            throw $e;
        }
        return ['from' => $from, 'to' => $m['version'], 'applied' => count($pending), 'backup' => $backup];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Active un plugin dans une base (l'installe ou le met à jour d'abord). */
function plugin_enable(PDO $pdo, string $dbFile, array $m): array
{
    $result = plugin_install($pdo, $dbFile, $m);
    $pdo->prepare('UPDATE plugins SET enabled = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$m['id']]);
    return $result;
}

/** Désactive un plugin : menu et routes disparaissent, ses tables et données restent. */
function plugin_disable(PDO $pdo, string $id): void
{
    if (plugins_table_exists($pdo)) {
        $pdo->prepare('UPDATE plugins SET enabled = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$id]);
    }
}
