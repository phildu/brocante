<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/social.php');
    exit;
}
admin_csrf_check();

$cfg = oauth_shop_config();
$action = (string) ($_POST['action'] ?? '');

if ($action === 'save') {
    $cfg['admin'] = !empty($_POST['admin']);
    $cfg['customer'] = !empty($_POST['customer']);
    $offered = (array) ($_POST['offer'] ?? []);
    $cfg['off'] = array_values(array_diff(array_keys(OAUTH_PROVIDERS), $offered));
    foreach (OAUTH_PROVIDERS as $k => $_) {
        $p = &$cfg['providers'][$k];
        $id = trim((string) ($_POST['client_id'][$k] ?? ''));
        $p['client_id'] = $id;
        foreach (['team_id', 'key_id'] as $f) {
            $v = trim((string) ($_POST[$f][$k] ?? ''));
            if ($v !== '') $p[$f] = $v;
        }
        foreach (['client_secret', 'private_key'] as $f) {
            $v = trim((string) ($_POST[$f][$k] ?? ''));
            if ($v !== '') $p[$f] = $v;   // un secret laissé vide est conservé
        }
        unset($p);
    }
    $ok = oauth_shop_config_save($cfg);
    $incomplete = array_filter(array_keys(OAUTH_PROVIDERS), static fn (string $k): bool => $cfg['providers'][$k]['client_id'] !== '' && !oauth_fields_complete($k, $cfg['providers'][$k]));
    if (!$ok) flash_set("Impossible d'enregistrer (droits d'écriture du dossier .secrets).", 'error');
    elseif ($incomplete) flash_set('Enregistré, mais clés incomplètes pour : ' . implode(', ', array_map(static fn (string $k): string => OAUTH_PROVIDERS[$k]['label'], $incomplete)) . ' — elles ne seront utilisées que complètes.', 'error');
    else flash_set('Réglages de connexion sociale enregistrés.');
} elseif (str_starts_with($action, 'clear_') && isset(OAUTH_PROVIDERS[$k = substr($action, 6)])) {
    $cfg['providers'][$k] = ['client_id' => '', 'client_secret' => '', 'team_id' => '', 'key_id' => '', 'private_key' => ''];
    oauth_shop_config_save($cfg);
    flash_set('Clés ' . OAUTH_PROVIDERS[$k]['label'] . ' retirées : ' . (oauth_platform_creds($k) ? 'ce commerce utilise de nouveau celles de la plateforme.' : 'ce fournisseur n\'est plus proposé.'));
}
header('Location: /admin/social.php');
exit;
