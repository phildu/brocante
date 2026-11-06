<?php
// Tests du pont entre les rôles de la production (includes/accounts.php) et les droits fins (includes/auth.php).
//   php scripts/test-auth-pont.php
// Base jetable : brocante.db n'est jamais touchée. Vérifie surtout qu'aucun rôle n'obtient plus de droits qu'il n'en a
// aujourd'hui : une session « is_admin » ne vaut PAS propriétaire (le photographe ne doit rien voir du catalogue).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-pont-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
(new PDO('sqlite:' . $tmp))->exec(file_get_contents(__DIR__ . '/../schema.sql'));

require_once __DIR__ . '/../includes/functions.php';

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) { echo "  ✓ $label\n"; return; }
    $failures++;
    echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
}

/** Ouvre une session d'administration pour un compte (null = compte principal du commerce). */
function login_as(?int $accountId): void
{
    $_SESSION = ['is_admin' => tenant_slug()];
    if ($accountId !== null) $_SESSION['admin_account_id'] = $accountId;
    auth_user(true);
}

$cm = account_create(['role' => 'community_manager', 'name' => 'Cécile', 'email' => 'cm@example.fr'], 'motdepasse-cm-1');
$photo = account_create(['role' => 'photographe', 'name' => 'Paul', 'email' => 'photo@example.fr'], 'motdepasse-ph-1');
$client = account_create(['role' => 'client', 'name' => 'Cli', 'email' => 'client@example.fr'], 'motdepasse-cl-1');

echo "Sans session\n";
$_SESSION = [];
auth_user(true);
check('personne n\'est connecté', auth_user(), null);
check('aucun droit', auth_can('portail.notifications.lire'), false);

echo "Compte principal du commerce (admin_user / admin_password)\n";
login_as(null);
check('propriétaire', auth_is_owner(), true);
check('droit des notifications', auth_can('portail.notifications.lire'), true);
check('droit du catalogue', auth_can('boutique.produits.supprimer'), true);

echo "Administrateur (compte nommé)\n";
$admin = account_create(['role' => 'admin', 'name' => 'Ada', 'email' => 'admin@example.fr'], 'motdepasse-ad-1');
login_as($admin);
check('propriétaire', auth_is_owner(), true);
check('tous les droits', auth_can('portail.cles_api.modifier'), true);

echo "Community manager : les visuels, rien d'autre\n";
login_as($cm);
check('pas propriétaire', auth_is_owner(), false);
check('médiathèque', auth_can('galerie.mediatheque.modifier'), true);
check('bandeaux', auth_can('galerie.bandeaux.lire'), true);
check('catalogue refusé', auth_can('boutique.produits.lire'), false);
check('commandes refusées', auth_can('boutique.commandes.lire'), false);
check('clés API refusées', auth_can('portail.cles_api.lire'), false);
check('notifications refusées', auth_can('portail.notifications.lire'), false);
check('impression refusée', auth_can('portail.impression.lire'), false);
check('boutiques connectées refusées', auth_can('boutique.connecteurs.lire'), false);

echo "Photographe : aucun droit du catalogue\n";
login_as($photo);
check('pas propriétaire', auth_is_owner(), false);
check('aucun droit galerie', auth_can('galerie.mediatheque.lire'), false);
check('aucun droit boutique', auth_can('boutique.produits.lire'), false);
check('aucun droit portail', auth_can('portail.tableau_bord.lire'), false);
check('liste des droits vide', auth_user()['perms'], []);

echo "Un contact (client) n'ouvre pas l'administration\n";
login_as($client);
check('refusé', auth_user(), null);

echo "Compte désactivé pendant la session : accès coupé\n";
login_as($cm);
check('connecté avant', auth_user() !== null, true);
account_set_active($cm, false, null);
// Une vraie requête est un nouveau processus : admin_session() relit le compte à chaque requête (cache d'un seul processus).
$script = 'require ' . var_export(__DIR__ . '/../includes/functions.php', true) . ';'
    . '$_SESSION = ["is_admin" => tenant_slug(), "admin_account_id" => ' . (int) $cm . '];'
    . 'echo auth_user() === null ? "coupé" : "encore ouvert";';
$out = trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>/dev/null'));
check('coupé à la requête suivante après désactivation', $out, 'coupé');

echo "Refus par défaut\n";
login_as(null);
$threw = false;
try { auth_can('portail.droit_qui_nexiste_pas'); } catch (LogicException $e) { $threw = true; }
check('un droit inconnu lève une exception (jamais un accès silencieux)', $threw, true);
check('rôle inconnu : aucun droit', auth_prod_role_permissions('inconnu'), []);
check('rôle community_manager : seulement le niveau galerie', count(array_filter(auth_prod_role_permissions('community_manager'), fn ($k) => !str_starts_with($k, 'galerie.'))), 0);

echo $failures ? "\nÉCHEC : $failures vérification(s) sur $checks.\n" : "\nOK : $checks vérifications passées.\n";
exit($failures ? 1 : 0);
