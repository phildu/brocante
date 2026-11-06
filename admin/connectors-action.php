<?php
session_start();
require_once __DIR__ . '/../includes/connectors.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/connectors.php');
    exit;
}

$action = (string) ($_POST['action'] ?? '');
$isAjax = in_array($action, ['test', 'sync_page', 'sync_finish'], true);

function connectors_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!validate_csrf()) {
    if ($isAjax) connectors_json(['ok' => false, 'error' => 'Session expirée : rechargez la page.'], 403);
    http_response_code(403);
    exit('CSRF token validation failed');
}

connectors_ensure_schema();

/* ---------- Actions asynchrones (appelées par le navigateur, page par page) ---------- */
if ($isAjax) {
    $source = connector_source_get((int) ($_POST['id'] ?? 0));
    if (!$source) connectors_json(['ok' => false, 'error' => 'Boutique introuvable.'], 404);
    if (!connector_platforms()[$source['platform']]['pull']) connectors_json(['ok' => false, 'error' => 'Cette source envoie elle-même ses produits.'], 400);

    // Une synchro dure : on libère le verrou de session pour ne pas bloquer les autres onglets admin.
    session_write_close();

    try {
        if ($action === 'test') {
            $page = connector_fetch_page($source, null);
            $names = array_slice(array_map(fn($i) => $i['name'], $page['items']), 0, 3);
            connectors_json([
                'ok' => true,
                'message' => count($page['items']) === 0
                    ? 'Connexion réussie, mais aucun produit publié n\'a été trouvé.'
                    : 'Connexion réussie. Premiers produits : ' . implode(' · ', $names) . ($page['next'] ? ' …' : '') . '.',
            ]);
        }

        if ($action === 'sync_page') {
            $cursor = (string) ($_POST['cursor'] ?? '');
            if ($cursor !== '' && !preg_match('/^[A-Za-z0-9_.:=-]{1,500}$/', $cursor)) connectors_json(['ok' => false, 'error' => 'Curseur invalide.'], 400);
            $run = (string) ($_POST['run'] ?? '');
            if ($run === '') $run = connector_new_run_id();
            elseif (!connector_valid_run_id($run)) connectors_json(['ok' => false, 'error' => 'Identifiant de synchronisation invalide.'], 400);

            $r = connector_sync_page($source, $cursor === '' ? null : $cursor, $run);
            connectors_json(['ok' => true, 'run' => $run] + $r);
        }

        // sync_finish
        $run = (string) ($_POST['run'] ?? '');
        if (!connector_valid_run_id($run)) connectors_json(['ok' => false, 'error' => 'Identifiant de synchronisation invalide.'], 400);
        $totals = [];
        foreach (['created', 'updated', 'skipped', 'failed'] as $k) $totals[$k] = max(0, (int) ($_POST[$k] ?? 0));
        $complete = ($_POST['complete'] ?? '') === '1';
        $retired = connector_finish_run($source, $run, $complete, $totals);
        $count = db()->prepare('SELECT COUNT(*) FROM products WHERE source_id = ?');
        $count->execute([(int) $source['id']]);
        connectors_json(['ok' => true, 'retired' => $retired, 'source' => connector_source_get((int) $source['id'])['last_sync_summary'], 'product_count' => (int) $count->fetchColumn()]);
    } catch (Throwable $e) {
        connectors_json(['ok' => false, 'error' => $e->getMessage()]);
    }
}

/* ---------- Actions classiques (formulaire → redirection) ---------- */

function connectors_back(string $query = ''): void
{
    header('Location: /admin/connectors.php' . $query);
    exit;
}

switch ($action) {

    case 'save': {
        $id = (int) ($_POST['id'] ?? 0);
        $existing = $id > 0 ? connector_source_get($id) : null;
        if ($id > 0 && !$existing) { flash_set('Boutique introuvable.', 'error'); connectors_back(); }

        $platform = $existing['platform'] ?? (string) ($_POST['platform'] ?? '');
        if (!isset(connector_platforms()[$platform])) { flash_set('Plateforme inconnue.', 'error'); connectors_back(); }
        $pull = connector_platforms()[$platform]['pull'];
        $back = $existing ? '?edit=' . $id : '?new=' . urlencode($platform);

        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        if ($name === '') $name = connector_platform_label($platform);

        $url = '';
        if ($pull) {
            [$url, $err] = connector_normalize_base_url((string) ($_POST['base_url'] ?? ''), $platform);
            if ($err) { flash_set($err, 'error'); connectors_back($back); }
        }

        // Identifiants : un champ laissé vide conserve celui déjà enregistré.
        $secrets = $existing ? connector_secrets_load($id) : [];
        $fields = ['shopify' => ['token'], 'woocommerce' => ['key', 'secret'], 'prestashop' => ['key']][$platform] ?? [];
        foreach ($fields as $f) {
            $v = trim((string) ($_POST['secret_' . $f] ?? ''));
            if ($v !== '') $secrets[$f] = $v;
        }
        $missing = [
            'woocommerce' => empty($secrets['key']) || empty($secrets['secret']) ? 'la clé et le secret WooCommerce' : null,
            'prestashop' => empty($secrets['key']) ? 'la clé de webservice PrestaShop' : null,
        ][$platform] ?? null;
        if ($missing) { flash_set("Renseignez $missing.", 'error'); connectors_back($back); }

        $settings = connector_clean_settings($_POST);
        $pdo = db();
        if ($existing) {
            $pdo->prepare('UPDATE import_sources SET name = ?, base_url = ?, settings = ?, is_active = ? WHERE id = ?')
                ->execute([$name, $url, json_encode($settings), !empty($_POST['is_active']) ? 1 : 0, $id]);
        } else {
            $pdo->prepare('INSERT INTO import_sources (name, platform, base_url, settings) VALUES (?, ?, ?, ?)')
                ->execute([$name, $platform, $url, json_encode($settings)]);
            $id = (int) $pdo->lastInsertId();
        }
        if ($fields) connector_secrets_save($id, $secrets);

        if (!$existing && $platform === 'api') {
            $token = connector_api_new_token($id);
            flash_set("Source « $name » créée. Votre jeton d'accès (copiez-le maintenant, il ne sera plus jamais affiché) : $token");
        } else {
            $warn = '';
            if ($platform === 'woocommerce' && (!str_starts_with($secrets['key'] ?? '', 'ck_') || !str_starts_with($secrets['secret'] ?? '', 'cs_'))) {
                $warn = ' Attention : une clé WooCommerce commence normalement par ck_ et son secret par cs_.';
            }
            flash_set("Boutique « $name » enregistrée." . ($pull ? ' Cliquez « Tester la connexion » puis « Synchroniser ».' : '') . $warn, $warn ? 'error' : 'ok');
        }
        connectors_back();
    }

    case 'new_token': {
        $source = connector_source_get((int) ($_POST['id'] ?? 0));
        if (!$source || $source['platform'] !== 'api') { flash_set('Source introuvable.', 'error'); connectors_back(); }
        $token = connector_api_new_token((int) $source['id']);
        flash_set("Nouveau jeton pour « {$source['name']} » — l'ancien ne fonctionne plus. Copiez-le maintenant, il ne sera plus jamais affiché : $token");
        connectors_back();
    }

    case 'delete': {
        $source = connector_source_get((int) ($_POST['id'] ?? 0));
        if (!$source) { flash_set('Boutique introuvable.', 'error'); connectors_back(); }
        connector_delete_source((int) $source['id']);
        flash_set("Boutique « {$source['name']} » supprimée. Ses produits restent dans le catalogue (ils ne sont plus synchronisés).");
        connectors_back();
    }

    default:
        connectors_back();
}
