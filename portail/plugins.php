<?php
// Plugins de la plateforme (packs/<id>/, voir includes/plugins.php) : catalogue, situation dans chaque boutique de ce serveur,
// activer / désactiver / mettre à jour une boutique, ou mettre à jour d'un clic toutes celles qui l'ont. Chaque action est
// journalisée (portail → Activité) ; une mise à jour sauvegarde d'abord la base de la boutique dans data/backups/.
// Les plugins se rangent par catégorie (Vente, Promo, CRM, Actions commerciales…) : catégories et affectations sont gérées
// ici et conservées dans data/saas/plugins-categories.json (propre à chaque serveur, hors déploiement).
// Plugin recopié d'un autre projet (entrée « sync » du manifeste, ex. Communication ← electroboy80) : en local seulement, le
// portail montre si la copie est à jour et propose « Recopier » (le script d'extraction du plugin) ; le résultat se vérifie,
// se teste, puis se commite et se déploie comme tout le code. En ligne, il indique seulement de quel commit vient la copie.
require __DIR__ . '/_bootstrap.php';
ext_page();   // jetons du vocabulaire ({Exts}, {un ext}…) remplacés à l'envoi de la page
require_once PORTAIL_ROOT . '/includes/saas-events.php';
require_once PORTAIL_ROOT . '/includes/plugins.php';
require_once PORTAIL_ROOT . '/includes/addons.php';
require_once PORTAIL_ROOT . '/includes/branding-services.php';

// Catégories livrées par défaut au premier chargement (ensuite modifiables dans la page).
const PLUGIN_CATEGORY_DEFAULTS = [
    'vente' => 'Vente',
    'promo' => 'Promo',
    'crm' => 'CRM',
    'actions-commerciales' => 'Actions commerciales',
];

function plugins_categories_file(): string
{
    return saas_data_dir() . '/saas/plugins-categories.json';
}

/** Catégories et affectations : [categories => [{id,label}], plugins => [pluginId => catId]]. */
function plugins_categories_load(): array
{
    $data = ['categories' => [], 'plugins' => ['vente-en-ligne' => 'vente']];
    foreach (PLUGIN_CATEGORY_DEFAULTS as $id => $label) $data['categories'][] = ['id' => $id, 'label' => $label];
    $raw = json_decode((string) @file_get_contents(plugins_categories_file()), true);
    if (!is_array($raw)) return $data;
    $cats = [];
    foreach ((array) ($raw['categories'] ?? []) as $c) {
        $id = (string) ($c['id'] ?? '');
        $label = trim((string) ($c['label'] ?? ''));
        if ($id !== '' && $label !== '' && !isset($cats[$id])) $cats[$id] = ['id' => $id, 'label' => $label];
    }
    $assign = [];
    foreach ((array) ($raw['plugins'] ?? []) as $pid => $cid) {
        if (is_string($pid) && is_string($cid) && isset($cats[$cid])) $assign[$pid] = $cid;
    }
    return ['categories' => array_values($cats), 'plugins' => $assign];
}

function plugins_categories_save(array $data): void
{
    $file = plugins_categories_file();
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $file . '.tmp';
    if ($json === false || @file_put_contents($tmp, $json . "\n") === false || !@rename($tmp, $file)) {
        throw new RuntimeException("Impossible d'enregistrer les catégories (écriture refusée dans data/saas/).");
    }
}

/** Identifiant de catégorie à partir de son nom (« Actions commerciales » → actions-commerciales). */
function plugins_cat_slug(string $label): string
{
    $s = strtr(mb_strtolower(trim($label)), [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a', 'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ü' => 'u', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', 'ÿ' => 'y',
    ]);
    return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
}

/** Message de confirmation pour un attribut onsubmit (chaîne JS sûre, sans guillemets bruts). */
function plugins_confirm_js(string $text): string
{
    return e((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT));
}

$shops = tenant_list();
$catalog = plugin_catalog();
$catData = plugins_categories_load();
$catIds = array_column($catData['categories'], 'id');
$rules = plugin_rules_load();
$activityLabels = branding_activity_catalog();

/** Situation d'un plugin dans une boutique : ['status', 'row', 'error']. */
function plugins_shop_situation(string $slug, array $m): array
{
    try {
        [$pdo] = plugin_open_tenant_db($slug);
        $row = plugin_states($pdo)[$m['id']] ?? null;
        return ['status' => plugin_status($row, $m), 'row' => $row, 'error' => null];
    } catch (Throwable $e) {
        return ['status' => 'nobase', 'row' => null, 'error' => $e->getMessage()];
    }
}

/** Exécute une action sur une boutique ; renvoie le message (succès) ou lève une exception. */
function plugins_apply(string $action, string $slug, array $m): string
{
    [$pdo, $file] = plugin_open_tenant_db($slug);
    $label = '« ' . $m['name'] . ' » pour « ' . $slug . ' »';
    if ($action === 'disable') {
        plugin_disable($pdo, $m['id']);
        saas_event('plugin_disabled', ['slug' => $slug, 'note' => $m['name'] . ' (données conservées)']);
        return "{Ext} $label désactivé{e} (ses données sont conservées).";
    }
    $r = $action === 'enable' ? plugin_enable($pdo, $file, $m) : plugin_install($pdo, $file, $m);
    $what = $r['from'] === $r['to'] ? 'v' . $r['to'] : ($r['from'] !== '' ? 'v' . $r['from'] . ' → v' . $r['to'] : 'v' . $r['to']);
    $note = $m['name'] . ' ' . $what . ($r['applied'] ? ' (' . $r['applied'] . ' migration' . ($r['applied'] > 1 ? 's' : '') . ', sauvegarde ' . basename((string) $r['backup']) . ')' : '');
    saas_event($action === 'enable' ? 'plugin_enabled' : 'plugin_updated', ['slug' => $slug, 'note' => $note]);
    return ($action === 'enable' ? '{Ext} activé{e} : ' : '{Ext} mis{e} à jour : ') . $label . ', ' . $what . '.';
}

/** Premier exécutable trouvé parmi $candidates. */
function plugins_first_bin(array $candidates): ?string
{
    foreach ($candidates as $bin) {
        if ($bin !== '' && is_file($bin) && is_executable($bin)) return $bin;
    }
    return null;
}

/** Lance une commande (tableau, sans shell) depuis la racine du projet ; renvoie [code, sortie, erreurs]. */
function plugins_run(array $cmd): array
{
    if (!function_exists('proc_open')) return [127, '', 'proc_open indisponible sur ce serveur.'];
    $env = ['PATH' => '/usr/bin:/bin:/usr/local/bin:/opt/homebrew/bin', 'HOME' => (string) (getenv('HOME') ?: sys_get_temp_dir()), 'LANG' => 'fr_FR.UTF-8'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, PORTAIL_ROOT, $env);
    if (!is_resource($proc)) return [127, '', 'Lancement impossible.'];
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out, $err];
}

/** Script d'extraction d'un plugin recopié, lancé avec le PHP en ligne de commande (sous Herd, PHP_BINARY est php-fpm). */
function plugins_sync_run(array $m, array $args = []): array
{
    $php = plugins_first_bin([PHP_SAPI === 'cli' ? PHP_BINARY : '', preg_replace('/-fpm$/', '', PHP_BINARY), PHP_BINDIR . '/php',
        '/opt/homebrew/bin/php', '/usr/local/bin/php', '/usr/bin/php']);
    if ($php === null || str_contains(basename($php), 'fpm')) return [127, '', 'PHP en ligne de commande introuvable.'];
    return plugins_run(array_merge([$php, $m['dir'] . '/' . $m['sync']['tool']], $args));
}

/** Fichiers du plugin modifiés par rapport au dernier commit (git status), pour relire la recopie. */
function plugins_changed_files(string $id): array
{
    $git = plugins_first_bin(['/usr/bin/git', '/opt/homebrew/bin/git', '/usr/local/bin/git']);
    if ($git === null) return [];
    [$code, $out] = plugins_run([$git, '-C', PORTAIL_ROOT, 'status', '--porcelain', '--', 'packs/' . $id]);
    return $code === 0 ? array_values(array_filter(array_map('rtrim', explode("\n", $out)))) : [];
}

/** « de X » ou « d'X » (electroboy80 → d'electroboy80). */
function plugins_de(string $name): string
{
    return (preg_match('/^[aeiouyhéèêàâîôû]/iu', $name) ? "d'" : 'de ') . $name;
}

/** D'où vient la copie (fichier « record » du manifeste) : [date, commit]. */
function plugins_sync_record(array $m): array
{
    $text = $m['sync']['record'] ? (string) @file_get_contents($m['dir'] . '/' . $m['sync']['record']) : '';
    return [preg_match('/faite le (.*)$/m', $text, $a) ? trim($a[1]) : '', preg_match('/^Commit : (.*)$/m', $text, $b) ? trim($b[1]) : ''];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');
    $m = $catalog[(string) ($_POST['plugin'] ?? '')] ?? null;
    $slug = (string) ($_POST['slug'] ?? '');
    $backCat = (string) ($_POST['cat'] ?? '');

    /** Retour à la page, filtre conservé, ancre sur l'élément concerné. */
    $back = static function (string $anchor = '') use ($backCat): never {
        $url = '/portail/plugins.php';
        if (in_array($backCat, ['all', 'none'], true) || preg_match('/^[a-z0-9-]{1,32}$/', $backCat)) $url .= '?cat=' . rawurlencode($backCat);
        header('Location: ' . $url . ($anchor !== '' ? '#' . $anchor : ''));
        exit;
    };

    if ($action === 'save_categories') {
        $assign = (array) ($_POST['plugin_cat'] ?? []);
        $saved = [];
        foreach ($catalog as $pid => $pm) {
            $cid = (string) ($assign[$pid] ?? '');
            if ($cid !== '' && in_array($cid, $catIds, true)) $saved[$pid] = $cid;
        }
        $catData['plugins'] = $saved;
        try {
            plugins_categories_save($catData);
            portail_flash('Catégories {des exts} enregistrées.');
        } catch (Throwable $e) {
            portail_flash($e->getMessage(), 'error');
        }
        $back('categories');
    } elseif ($action === 'add_category') {
        $label = trim((string) ($_POST['label'] ?? ''));
        $id = plugins_cat_slug($label);
        if ($label === '' || $id === '') {
            portail_flash('Indiquez un nom de catégorie.', 'error');
        } elseif (in_array($id, $catIds, true)) {
            portail_flash('Cette catégorie existe déjà.', 'error');
        } else {
            $catData['categories'][] = ['id' => $id, 'label' => mb_substr($label, 0, 40)];
            try {
                plugins_categories_save($catData);
                portail_flash('Catégorie « ' . $label . ' » ajoutée — affectez-lui {des exts} dans le tableau.');
            } catch (Throwable $e) {
                portail_flash($e->getMessage(), 'error');
            }
        }
        $back('categories');
    } elseif ($action === 'delete_category') {
        $cid = (string) ($_POST['cat_id'] ?? '');
        $keep = array_values(array_filter($catData['categories'], static fn (array $c): bool => $c['id'] !== $cid));
        if (count($keep) === count($catData['categories'])) {
            portail_flash('Catégorie introuvable.', 'error');
        } else {
            $catData['categories'] = $keep;
            $catData['plugins'] = array_filter($catData['plugins'], static fn (string $v): bool => $v !== $cid);
            try {
                plugins_categories_save($catData);
                portail_flash('Catégorie supprimée ; {les exts} concerné{es} {?sont repassés|sont repassées} en « sans catégorie ».');
            } catch (Throwable $e) {
                portail_flash($e->getMessage(), 'error');
            }
        }
        $back('categories');
    } elseif ($action === 'save_rules') {
        $sent = (array) ($_POST['rules'] ?? []);
        if (!$sent) {
            portail_flash('Aucun{e} {ext} reçu{e} : le catalogue n’a pas été modifié.', 'error');
            $back('catalogue');
        }
        foreach ($catalog as $pid => $pm) {
            if (!isset($sent[$pid]) || !is_array($sent[$pid])) continue;
            $row = $sent[$pid];
            $rules[$pid] = plugin_rule_normalize([
                'listed' => !empty($row['listed']),
                'stage' => (string) ($row['stage'] ?? 'live'),
                'free' => !empty($row['free']),
                'activities' => (array) ($row['activities'] ?? []),
                'online_sales' => !empty($row['online_sales']),
                'default_install' => !empty($row['default_install']),
            ], plugin_rule_defaults($pid));
        }
        try {
            plugin_rules_save($rules);
            $free = count(array_filter($rules, static fn (array $r): bool => plugin_rule_free($r)));
            saas_event('config_changed', ['note' => 'Catalogue {des exts} : ' . count($rules) . ' plugin(s), ' . $free . ' gratuit(s)']);
            portail_flash('Catalogue enregistré : il est appliqué à la prochaine inscription. Les boutiques existantes gardent leurs {exts}.');
        } catch (Throwable $e) {
            portail_flash($e->getMessage(), 'error');
        }
        $back('catalogue');
    } elseif (!$m) {
        portail_flash('{Ext} introuvable.', 'error');
        $back();
    } elseif (in_array($action, ['enable', 'disable', 'update'], true)) {
        if (!isset($shops[$slug])) {
            portail_flash('Boutique introuvable.', 'error');
        } else {
            try {
                portail_flash(plugins_apply($action, $slug, $m));
            } catch (Throwable $e) {
                saas_event('plugin_failed', ['slug' => $slug, 'note' => $m['name'] . ' (' . $action . ') : ' . $e->getMessage()]);
                portail_flash('Échec pour « ' . $slug . ' » : ' . $e->getMessage() . '. La base n\'a pas été modifiée.', 'error');
            }
        }
        $back('plugin-' . $m['id']);
    } elseif ($action === 'sync') {
        // Recopie depuis le projet d'origine : uniquement dans le portail local, là où se trouve ce projet.
        if (!$portailLocal || !$m['sync']) {
            portail_flash('Recopie possible seulement depuis le portail local.', 'error');
        } else {
            [$code, $out, $err] = plugins_sync_run($m);
            date_default_timezone_set('Europe/Paris');
            $files = plugins_changed_files($m['id']);
            $_SESSION['plugins_sync'][$m['id']] = ['ok' => $code === 0, 'at' => date('Y-m-d H:i:s'), 'out' => $out, 'err' => $err, 'files' => $files];
            portail_flash($code === 0
                ? 'Copie ' . plugins_de($m['sync']['source']) . ' refaite : ' . count($files) . ' fichier(s) {du ext} diffèrent du dernier commit. À relire et tester avant le commit.'
                : 'Copie interrompue, aucun fichier modifié : ' . (strtok(trim($err ?: $out), "\n") ?: 'erreur inconnue') . '.', $code === 0 ? 'ok' : 'error');
        }
        $back('plugin-' . $m['id']);
    } elseif ($action === 'update_all') {
        $ok = [];
        $failed = [];
        foreach ($shops as $s => $cfg) {
            if (plugins_shop_situation($s, $m)['status'] !== 'update') continue;
            try {
                plugins_apply('update', $s, $m);
                $ok[] = $s;
            } catch (Throwable $e) {
                saas_event('plugin_failed', ['slug' => $s, 'note' => $m['name'] . ' (mise à jour) : ' . $e->getMessage()]);
                $failed[] = $s . ' (' . $e->getMessage() . ')';
            }
        }
        portail_flash($ok || $failed
            ? '« ' . $m['name'] . ' » v' . $m['version'] . ' : ' . count($ok) . ' boutique(s) mise(s) à jour' . ($failed ? ' ; échec : ' . implode(', ', $failed) : '') . '.'
            : 'Aucune boutique à mettre à jour.', $failed ? 'error' : 'ok');
        $back('plugin-' . $m['id']);
    } else {
        portail_flash('Action inconnue.', 'error');
        $back();
    }
}
$flash = portail_flash();
$tags = ['absent' => '', 'disabled' => 'warn', 'ready' => 'ok', 'update' => 'warn', 'newer' => 'bad', 'nobase' => ''];

// Filtre par catégorie : all (tout), none (sans catégorie) ou identifiant de catégorie.
$filter = (string) ($_GET['cat'] ?? 'all');
if (!in_array($filter, ['all', 'none'], true) && !in_array($filter, $catIds, true)) $filter = 'all';
$assign = $catData['plugins'];
$countByCat = array_fill_keys($catIds, 0);
foreach ($catalog as $id => $m) {
    $cid = $assign[$id] ?? '';
    if (isset($countByCat[$cid])) $countByCat[$cid]++;
}
$countNone = count($catalog) - array_sum($countByCat);
$visible = array_filter($catalog, static function (array $m, string $id) use ($filter, $assign): bool {
    $cid = $assign[$id] ?? '';
    return $filter === 'all' || ($filter === 'none' ? $cid === '' : $cid === $filter);
}, ARRAY_FILTER_USE_BOTH);
$catLabel = static function (string $id) use ($catData): string {
    foreach ($catData['categories'] as $c) if ($c['id'] === $id) return $c['label'];
    return '';
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{Exts} — Portail des commerces</title>
<link href="https://fonts.googleapis.com/css2?family=Schibsted+Grotesk:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/portail/portail.css">
<link rel="stylesheet" href="/portail/admin.css?v=2">
<style>
  .owrap { display: grid; gap: 16px; max-width: 1100px; margin: 0 auto; padding: 0 16px 56px; }
  .ocard { border: 1px solid var(--line); background: var(--panel); border-radius: 10px; min-width: 0; }
  .ocard h2 { margin: 0; font-size: 1.05rem; } .ocard h2 small { font-weight: 400; color: var(--muted); margin-left: 6px; font-family: 'IBM Plex Mono', monospace; }
  .hint { color: var(--muted); font-size: .84rem; margin: 0; }
  table.t { width: 100%; border-collapse: collapse; font-size: .88rem; } .t th { text-align: left; font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 5px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; }
  .t td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } .t tr:last-child td { border-bottom: 0; }
  .scroll { overflow-x: auto; }
  .tag { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: .74rem; font-weight: 700; border: 1px solid var(--line); } .tag.bad { color: #b3261e; border-color: currentColor; } .tag.warn { color: #9a6700; border-color: currentColor; } .tag.ok { color: var(--ok); }
  .acts { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; } .acts form { margin: 0; }
  @media (max-width: 640px) {
    .t thead { display: none; } .t tr { display: grid; gap: 6px; padding: 10px 0; border-bottom: 1px solid var(--line); } .t tr:last-child { border-bottom: 0; } .t td { border: 0; padding: 0; }
  }
  .sync { border: 1px dashed var(--line); border-radius: 8px; padding: 10px 12px; display: grid; gap: 8px; }
  .report pre { margin: 6px 0; max-height: 260px; overflow: auto; font: .76rem/1.4 'IBM Plex Mono', monospace; background: var(--bg, transparent); border: 1px solid var(--line); border-radius: 6px; padding: 8px; white-space: pre-wrap; }
  .report pre.err { color: #b3261e; } .report summary { cursor: pointer; font-size: .86rem; }
  .head { display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
  /* Repli : chaque plugin est un <details>, le résumé reste visible, la situation des boutiques se déplie. */
  details.ocard > summary { cursor: pointer; padding: 14px 16px; display: flex; gap: 6px 10px; align-items: center; flex-wrap: wrap; list-style: none; border-radius: 10px; }
  details.ocard > summary::-webkit-details-marker { display: none; }
  details.ocard > summary::before { content: '▸'; font-size: .8rem; color: var(--muted); transition: transform .15s ease; }
  details.ocard[open] > summary::before { transform: rotate(90deg); }
  details.ocard[open] > summary { background: var(--panel-2); border-bottom: 1px solid var(--line); border-radius: 10px 10px 0 0; }
  .sum-name { font-weight: 600; font-size: 1.02rem; }
  .sum-meta { color: var(--muted); font-size: .8rem; font-family: 'IBM Plex Mono', monospace; }
  .sum-where { margin-left: auto; color: var(--muted); font-size: .8rem; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .ocard-body { padding: 16px; display: grid; gap: 12px; }
  .toolbar { display: flex; gap: 10px; justify-content: space-between; align-items: center; flex-wrap: wrap; }
  .catbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .chip { display: inline-flex; gap: 6px; align-items: center; padding: 4px 11px; border: 1px solid var(--line); border-radius: 999px; font-size: .82rem; text-decoration: none; color: inherit; background: var(--panel); }
  .chip span { font-family: 'IBM Plex Mono', monospace; font-size: .72rem; color: var(--muted); }
  .chip:hover { border-color: var(--muted); }
  .chip.active { background: var(--panel-2); border-color: var(--muted); font-weight: 600; }
  .catadd { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; } .catadd .field { margin: 0; }
  .catlist { display: flex; gap: 8px 14px; flex-wrap: wrap; align-items: center; }
  .catlist form { margin: 0; }
</style>
</head>
<body>
<div class="app">
<?php portail_page_start([
    'title' => '{Exts} des boutiques',
    'eyebrow' => 'Boutiques',
    'desc' => '{Exts} ajouté{es} aux boutiques : un seul code pour toutes, activé{e} boutique par boutique, rangé{e} par catégorie.',
    'actions' => '<a class="btn" href="/portail/menu.php">Réglage du menu</a>',
]); ?>
  <?php if ($flash): ?><p class="flash" data-kind="<?= e($flash['kind']) ?>" role="status"><?= e($flash['message']) ?></p><?php endif; ?>

  <div class="owrap">
    <p class="hint">Le code d’{un ext} arrive avec le déploiement (dossier <code>packs/</code>). Ses données vivent dans la base de chaque boutique.
      <strong>Désactiver</strong> retire son menu et ses pages, sans rien effacer. Quand une nouvelle version est déployée, {le ext} reste en pause
      dans les boutiques qui l'ont jusqu'à <strong>Mettre à jour</strong> : la base de la boutique est d'abord sauvegardée dans <code>data/backups/</code>.
      Chaque {ext} est {?replié|repliée} : cliquez son nom pour voir la situation de ses boutiques.</p>

    <?php if (!$catalog): ?>
      <section class="ocard" style="padding:16px;"><p class="hint">Aucun{e} {ext} dans <code>packs/</code>.</p></section>
    <?php else: ?>

    <div class="toolbar">
      <div class="catbar">
        <?php
          $chips = [['all', 'Tous', count($catalog)]];
          foreach ($catData['categories'] as $c) $chips[] = ['cat:' . $c['id'], $c['label'], $countByCat[$c['id']] ?? 0];
          if ($countNone > 0) $chips[] = ['none', 'Sans catégorie', $countNone];
          foreach ($chips as [$key, $label, $count]):
            $cat = str_starts_with($key, 'cat:') ? substr($key, 4) : $key;
        ?>
          <a class="chip<?= $filter === $cat ? ' active' : '' ?>" href="/portail/plugins.php?cat=<?= e(rawurlencode($cat)) ?>"><?= e($label) ?> <span><?= (int) $count ?></span></a>
        <?php endforeach; ?>
      </div>
      <span class="acts">
        <button type="button" class="btn small" data-fold="open">Tout déplier</button>
        <button type="button" class="btn small" data-fold="close">Tout replier</button>
        <a class="btn small" href="#catalogue">Catalogue et profils</a>
        <a class="btn small" href="#categories">Gérer les catégories</a>
      </span>
    </div>

    <details class="ocard" id="categories">
      <summary><span class="sum-name">Catégories {des exts}</span><span class="sum-meta"><?= count($catData['categories']) ?> catégorie(s)</span>
        <span class="sum-where">ajouter, affecter, supprimer</span></summary>
      <div class="ocard-body">
        <p class="hint">Rangez les plugins par usage : « Vente » pour la boutique et le paiement, « Promo » pour les opérations commerciales,
          « CRM » pour les contacts, « Actions commerciales » pour les campagnes. Une catégorie sans plugin peut être supprimée ;
          la suppression ne touche jamais aux boutiques, seulement au classement.</p>
        <form class="catadd" method="post" action="/portail/plugins.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="add_category">
          <label class="field" style="flex:1;min-width:220px;">Nouvelle catégorie
            <input name="label" maxlength="40" placeholder="ex. Fidélité" required>
          </label>
          <button type="submit" class="btn small">Ajouter</button>
        </form>
        <div class="scroll">
          <form method="post" action="/portail/plugins.php">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_categories">
            <table class="t">
              <thead><tr><th>{Ext}</th><th>Catégorie</th></tr></thead>
              <tbody>
              <?php foreach ($catalog as $id => $m): ?>
                <tr>
                  <td><?= e($m['name']) ?><?= ext_scope_badge(ext_scope($id, (string) $m['scope'])) ?> <span class="hint"><?= e($id) ?></span></td>
                  <td>
                    <select name="plugin_cat[<?= e($id) ?>]" style="min-width:200px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;background:var(--panel);color:inherit;">
                      <option value="">Sans catégorie</option>
                      <?php foreach ($catData['categories'] as $c): ?>
                        <option value="<?= e($c['id']) ?>"<?= ($assign[$id] ?? '') === $c['id'] ? ' selected' : '' ?>><?= e($c['label']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            <p style="margin-top:10px;"><button type="submit" class="btn small btn-primary">Enregistrer les catégories</button></p>
          </form>
        </div>
        <div class="catlist">
          <?php foreach ($catData['categories'] as $c): ?>
            <span class="chip"><?= e($c['label']) ?> <span><?= $countByCat[$c['id']] ?? 0 ?></span></span>
            <form method="post" action="/portail/plugins.php" onsubmit="return confirm(<?= plugins_confirm_js('Supprimer la catégorie « ' . $c['label'] . ' » ? Les {exts} concerné{es} repasseront en « sans catégorie ».') ?>);">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="delete_category">
              <input type="hidden" name="cat_id" value="<?= e($c['id']) ?>">
              <button type="submit" class="btn small">Supprimer</button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    </details>

    <details class="ocard" id="catalogue">
      <summary><span class="sum-name">Catalogue et profils</span>
        <span class="sum-meta"><?= count(array_filter($rules, static fn (array $r): bool => !empty($r['listed']))) ?> proposé(s) à l’inscription · <?= count(array_filter($rules, static fn (array $r): bool => !empty($r['default_install']))) ?> installé(s) d’office</span>
        <span class="sum-where">qui voit quoi, à quel prix</span></summary>
      <div class="ocard-body">
        <p class="hint">Décidez pour chaque plugin s’il est proposé pendant l’inscription, et à quel profil il s’adresse.
          Un plugin <strong>en test</strong> est affiché gratuitement jusqu’à sa mise en service : il peut être proposé et installé sans facturation.
          Les restrictions par activité ne s’appliquent qu’une fois l’activité connue (étape 2) ; une sélection incompatible est retirée avant le paiement.
          Les boutiques déjà créées gardent leurs plugins et leur tarif, quel que soit ce réglage.</p>
        <p class="hint"><strong>Ventes flash :</strong> retirée du catalogue d’inscription, car les <em>actions commerciales</em> couvrent déjà la vente flash programmée.
          Les boutiques qui l’ont conservent sa baisse de prix progressive automatique, que les actions commerciales ne font pas.</p>
        <form method="post" action="/portail/plugins.php">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_rules">
          <div class="scroll">
            <table class="t">
              <thead><tr><th>{Ext}</th><th>Proposé</th><th>État</th><th>Gratuit</th><th>Réservé aux activités</th><th>Vente en ligne</th><th>D’office</th></tr></thead>
              <tbody>
              <?php foreach ($catalog as $id => $m): $rule = $rules[$id] ?? plugin_rule_defaults($id); ?>
                <tr>
                  <td><strong><?= e($m['name']) ?></strong><?= ext_scope_badge(ext_scope($id, (string) $m['scope'])) ?> <span class="hint"><?= e($id) ?></span></td>
                  <td><input type="checkbox" name="rules[<?= e($id) ?>][listed]" value="1"<?= !empty($rule['listed']) ? ' checked' : '' ?> aria-label="Proposer <?= e($m['name']) ?> à l’inscription"></td>
                  <td>
                    <select name="rules[<?= e($id) ?>][stage]" aria-label="État de <?= e($m['name']) ?>">
                      <option value="live"<?= $rule['stage'] === 'live' ? ' selected' : '' ?>>En service</option>
                      <option value="test"<?= $rule['stage'] === 'test' ? ' selected' : '' ?>>En test (gratuit)</option>
                    </select>
                  </td>
                  <td><input type="checkbox" name="rules[<?= e($id) ?>][free]" value="1"<?= !empty($rule['free']) ? ' checked' : '' ?> aria-label="Rendre <?= e($m['name']) ?> gratuit"></td>
                  <td>
                    <select name="rules[<?= e($id) ?>][activities][]" multiple size="4" aria-label="Activités autorisées pour <?= e($m['name']) ?>" style="min-width:210px;">
                      <?php foreach ($activityLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>"<?= in_array($key, (array) $rule['activities'], true) ? ' selected' : '' ?>><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <div class="hint">Aucune sélection = toutes les activités.</div>
                  </td>
                  <td><input type="checkbox" name="rules[<?= e($id) ?>][online_sales]" value="1"<?= !empty($rule['online_sales']) ? ' checked' : '' ?> aria-label="<?= e($m['name']) ?> réservé à la vente en ligne"></td>
                  <td><input type="checkbox" name="rules[<?= e($id) ?>][default_install]" value="1"<?= !empty($rule['default_install']) ? ' checked' : '' ?> aria-label="Installer <?= e($m['name']) ?> d’office"></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="hint" style="margin-top:10px;">Une activité n’est retenue que si elle figure dans l’activité principale ou secondaire déclarée à l’étape 2.
            « D’office » installe le plugin dans chaque nouvelle boutique, sans passer par la sélection du client.</p>
          <p><button type="submit" class="btn small btn-primary">Enregistrer le catalogue</button></p>
        </form>
      </div>
    </details>

    <?php if (!$visible): ?>
      <section class="ocard" style="padding:16px;"><p class="hint">Aucun{e} {ext} dans cette catégorie. <a href="/portail/plugins.php">Voir {?tous|toutes} {les exts}</a>.</p></section>
    <?php endif; ?>

    <?php foreach ($visible as $id => $m):
        $rows = [];
        foreach ($shops as $slug => $cfg) $rows[$slug] = plugins_shop_situation($slug, $m);
        $toUpdate = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'update'));
        $active = count(array_filter($rows, static fn (array $r): bool => in_array($r['status'], ['ready', 'update', 'newer'], true)));
        $mCat = $assign[$id] ?? '';
    ?>
      <details class="ocard" id="plugin-<?= e($id) ?>" data-plugin>
        <summary>
          <span class="sum-name"><?= e($m['name']) ?></span>
          <?php if ($mCat !== ''): ?><span class="tag"><?= e($catLabel($mCat)) ?></span><?php endif; ?>
          <span class="sum-meta"><?= e($id) ?> · v<?= e($m['version']) ?> · <?= count($m['migrations']) ?> migration(s)</span>
          <?php if (isset(addon_defaults()[$id]) && (addon_info($id)['stage'] ?? 'live') === 'beta'): ?><span class="tag">En test</span><?php endif; ?>
          <?php $rule = $rules[$id] ?? plugin_rule_defaults($id); ?>
          <?php if (empty($rule['listed'])): ?><span class="tag warn">Non proposé</span><?php endif; ?>
          <?php if (!empty($rule['default_install'])): ?><span class="tag ok">Installé d’office</span><?php endif; ?>
          <?php if (plugin_rule_free($rule)): ?><span class="tag ok"><?= $rule['stage'] === 'test' ? 'En test — gratuit' : 'Gratuit' ?></span><?php endif; ?>
          <?php if ((array) $rule['activities']): ?><span class="tag"><?= e(implode(', ', array_map(static fn ($a): string => $activityLabels[$a] ?? $a, (array) $rule['activities']))) ?></span><?php endif; ?>
          <?php if (!empty($rule['online_sales'])): ?><span class="tag">Vente en ligne</span><?php endif; ?>
          <span class="sum-where">
            <?= $active ?> boutique(s) active(s)<?= $toUpdate ? ' · ' . $toUpdate . ' à mettre à jour' : '' ?>
            <?php if ($toUpdate): ?><span class="tag warn">mise à jour</span><?php endif; ?>
          </span>
        </summary>
        <div class="ocard-body">
          <div class="head">
            <p class="hint" style="margin:0;"><?= $m['description'] !== '' ? e($m['description']) : 'Aucune description.' ?></p>
            <div class="acts">
              <?php if (isset(addon_defaults()[$id])): ?><a class="btn small" href="/portail/addons.php?scope=global&amp;plugin=<?= e(rawurlencode($id)) ?>">Tarifs et modalités</a><?php endif; ?>
              <?php if ($toUpdate): ?>
                <form method="post" action="/portail/plugins.php">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="update_all">
                  <input type="hidden" name="plugin" value="<?= e($id) ?>">
                  <input type="hidden" name="cat" value="<?= e($filter) ?>">
                  <button type="submit" class="btn small btn-primary">Mettre à jour les <?= $toUpdate ?> boutique(s) en retard</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($m['sync']):
              $src = $m['sync']['source'];
              $deSrc = plugins_de($src);
              [$copiedAt, $copied] = plugins_sync_record($m);
          ?>
            <div class="sync">
            <?php if (!$portailLocal): ?>
              <p class="hint">Copie <?= e($deSrc) ?><?= $copiedAt !== '' ? ' du ' . e($copiedAt) : '' ?> · commit <code><?= e($copied ?: 'inconnu') ?></code>.
                Elle se refait depuis le portail local, puis part avec le déploiement.</p>
            <?php else:
                [$code, $out, $err] = plugins_sync_run($m, ['--status']);
                $st = $code === 0 ? json_decode($out, true) : null;
                $last = $_SESSION['plugins_sync'][$id] ?? null;
            ?>
              <div class="head">
                <p class="hint" style="margin:0">
                  <strong>Copie <?= e($deSrc) ?></strong>
                  <?php if (is_array($st)): ?>
                    <span class="tag <?= $st['up_to_date'] ? 'ok' : 'warn' ?>"><?= $st['up_to_date'] ? 'À jour' : 'En retard' ?></span><br>
                    Copié<?= $copiedAt !== '' ? ' le ' . e($copiedAt) : '' ?> : <code><?= e($st['copied_commit'] ?: 'inconnu') ?></code><br>
                    <?= e($src) ?> aujourd'hui : <code><?= e($st['source_commit'] ?: 'inconnu') ?></code><?= $st['source_dirty'] ? ' <strong>+ modifications non commitées</strong>' . ($st['up_to_date'] ? ' (comprises dans la copie)' : '') : '' ?>
                    <br><span style="font-family:'IBM Plex Mono',monospace;font-size:.78rem"><?= e($st['source']) ?></span>
                  <?php else: ?>
                    <span class="tag bad">À revoir</span><br><?= e(trim($err ?: $out) ?: 'État inconnu.') ?>
                    <br>Une retouche qui ne trouve plus son motif se corrige dans le script d'extraction {du ext} ; rien n'est recopié d'ici là.
                  <?php endif; ?>
                </p>
                <?php if (is_array($st)): ?>
                  <form method="post" action="/portail/plugins.php">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="sync">
                    <input type="hidden" name="plugin" value="<?= e($id) ?>">
                    <input type="hidden" name="cat" value="<?= e($filter) ?>">
                    <button type="submit" class="btn<?= $st['up_to_date'] ? '' : ' btn-primary' ?>">Recopier depuis <?= e($src) ?></button>
                  </form>
                <?php endif; ?>
              </div>
              <p class="hint">Recopie le code <?= e($deSrc) ?> dans ce dossier et réapplique les retouches de la plateforme ; si l'une ne trouve
                plus son motif, rien n'est modifié. Les boutiques ne la reçoivent qu'au commit et au déploiement.</p>
              <?php if ($last): ?>
                <details class="report"<?= $last['ok'] ? '' : ' open' ?>>
                  <summary>Dernière recopie (<?= e($last['at']) ?>) : <?= $last['ok'] ? 'réussie' : '<strong>interrompue</strong>' ?> · <?= count($last['files']) ?> fichier(s) différent(s) du dernier commit</summary>
                  <?php if ($last['files']): ?><pre><?= e(implode("\n", $last['files'])) ?></pre><?php endif; ?>
                  <?php if (trim($last['err']) !== ''): ?><pre class="err"><?= e(trim($last['err'])) ?></pre><?php endif; ?>
                  <pre><?= e(trim($last['out'])) ?></pre>
                  <p class="hint">Relire : <code>git diff packs/<?= e($id) ?></code> · tester sur une boutique locale · puis commit et déploiement.</p>
                </details>
              <?php endif; ?>
            <?php endif; ?>
            </div>
          <?php endif; ?>
          <p class="hint">Actif dans <?= $active ?> boutique(s) sur <?= count($shops) ?>.
            Pages : <?= e(implode(', ', array_map(static fn (array $p): string => $p['label'] . ' (' . implode(', ', array_map(static fn (string $r): string => $r === 'admin' ? 'administrateur' : 'community manager', $p['roles'])) . ')', $m['pages'])) ?: 'aucune') ?>.</p>
          <div class="scroll">
            <table class="t">
              <thead><tr><th>Boutique</th><th>Situation</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach ($rows as $slug => $r): ?>
                <tr>
                  <td><?= e((string) ($shops[$slug]['name'] ?? $slug)) ?> <span class="hint"><?= e($slug) ?></span></td>
                  <td><span class="tag <?= e($tags[$r['status']] ?? '') ?>"><?= e($r['status'] === 'nobase' ? 'Base pas encore créée' : plugin_status_label($r['status'], $r['row'], $m)) ?></span></td>
                  <td>
                    <div class="acts">
                    <?php if (isset(addon_defaults()[$id])): ?><a class="btn small" href="/portail/addons.php?scope=shop&amp;shop=<?= e(rawurlencode($slug)) ?>&amp;plugin=<?= e(rawurlencode($id)) ?>">Modalités boutique</a><?php endif; ?>
                    <?php
                      $buttons = match ($r['status']) {
                          'absent', 'disabled' => ['enable' => 'Activer'],
                          'ready' => ['disable' => 'Désactiver'],
                          'update' => ['update' => 'Mettre à jour', 'disable' => 'Désactiver'],
                          'newer' => ['disable' => 'Désactiver'],
                          default => [],
                      };
                      foreach ($buttons as $act => $text): ?>
                      <form method="post" action="/portail/plugins.php">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="<?= e($act) ?>">
                        <input type="hidden" name="plugin" value="<?= e($id) ?>">
                        <input type="hidden" name="slug" value="<?= e($slug) ?>">
                        <input type="hidden" name="cat" value="<?= e($filter) ?>">
                        <button type="submit" class="btn small<?= $act !== 'disable' ? ' btn-primary' : '' ?>"><?= e($text) ?></button>
                      </form>
                    <?php endforeach; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </details>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<script>
(function () {
  function openHash() {
    if (!location.hash) return;
    var el = document.querySelector(location.hash);
    if (el && el.tagName === 'DETAILS') el.open = true;
  }
  openHash();
  window.addEventListener('hashchange', openHash);
  document.querySelectorAll('[data-fold]').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = b.dataset.fold === 'open';
      document.querySelectorAll('details.ocard[data-plugin]').forEach(function (d) { d.open = open; });
    });
  });
})();
</script>
</body>
</html>
