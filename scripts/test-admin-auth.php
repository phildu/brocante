<?php
// Tests de la vérification du mot de passe admin (includes/functions.php).
//   php scripts/test-admin-auth.php
// Régression : sans fichier .secrets/admin_password.txt, ADMIN_PASSWORD valait '' et
// hash_equals('', '') acceptait un mot de passe vide -> administration ouverte à tous.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}
$tmp = sys_get_temp_dir() . '/brocante-auth-test-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
require_once __DIR__ . '/../includes/functions.php';

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "  ✓ $label\n";
    }
}

echo "Mot de passe admin\n";
check('mot de passe attendu vide + saisie vide : REFUSÉ (le bug d\'origine)', admin_password_matches('', ''), false);
check('mot de passe attendu vide + saisie quelconque : refusé', admin_password_matches('', 'azerty'), false);
check('bon mot de passe : accepté', admin_password_matches('Secret-123', 'Secret-123'), true);
check('mauvais mot de passe : refusé', admin_password_matches('Secret-123', 'secret-123'), false);
check('saisie vide face à un vrai mot de passe : refusée', admin_password_matches('Secret-123', ''), false);
check('espace en plus : refusé', admin_password_matches('Secret-123', 'Secret-123 '), false);
check('« 0 » n\'est pas confondu avec vide', admin_password_matches('0', '0'), true);
check('admin_check_password est cohérent avec la configuration', admin_check_password('') , false);
check('sans mot de passe configuré, aucune saisie ne passe', admin_password_configured() ? 'configuré (fichier local présent)' : admin_check_password('n-importe-quoi'), admin_password_configured() ? 'configuré (fichier local présent)' : false);

echo "\n";
if ($failures > 0) {
    echo "ÉCHEC : $failures vérification(s) sur $checks.\n";
    exit(1);
}
echo "OK : $checks vérifications passées.\n";
