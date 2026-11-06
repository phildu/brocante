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

echo "\nLimitation des tentatives de connexion\n";
$T = 1_800_000_000;
$S = 'Secret-123';
$try = fn(string $pw, string $ip, int $t) => admin_login_attempt($pw, $ip, $t, $S);

$r = [];
for ($i = 0; $i < 5; $i++) $r[] = $try('faux', 'A', $T);
check('5 essais ratés : jamais bloqué, compte à rebours des essais restants', [array_column($r, 'blocked'), array_column($r, 'remaining')], [[false, false, false, false, false], [4, 3, 2, 1, 0]]);
$r6 = $try('faux', 'A', $T + 10);
check('6e essai : bloqué avec un délai', [$r6['blocked'], $r6['retry_after']], [true, 890]);
check('pendant le blocage, le BON mot de passe est refusé aussi', [$try($S, 'A', $T + 20)['ok'], $try($S, 'A', $T + 20)['blocked']], [false, true]);
for ($i = 0; $i < 50; $i++) $try('faux', 'A', $T + 100 + $i);
check('les essais bloqués ne prolongent pas le blocage (fin à T+900)', [$try($S, 'A', $T + 900)['blocked'], $try($S, 'A', $T + 900)['retry_after']], [true, 1]);
check('15 minutes après le premier essai : de nouveau possible', $try($S, 'A', $T + 901)['ok'], true);
check('autre adresse IP non affectée pendant que A est bloquée', [$try('faux', 'A2', $T)['blocked'], $try($S, 'B', $T)['ok']], [false, true]);

for ($i = 0; $i < 4; $i++) $try('faux', 'C', $T);
check('4 échecs puis succès : connecté', $try($S, 'C', $T + 1)['ok'], true);
$r = [];
for ($i = 0; $i < 5; $i++) $r[] = $try('faux', 'C', $T + 2)['blocked'];
check('le succès remet le compteur à zéro (5 nouveaux essais permis)', $r, [false, false, false, false, false]);
check('… puis le 6e est bloqué', $try('faux', 'C', $T + 3)['blocked'], true);

$d = admin_login_attempt('x', 'D', $T, '');
$rows = (int) db()->query("SELECT COUNT(*) FROM admin_login_attempts WHERE ip = 'D'")->fetchColumn();
check('aucun mot de passe configuré : refusé, non bloquant, rien enregistré', [$d['ok'], $d['blocked'], $rows], [false, false, 0]);
check('saisie vide face à un vrai mot de passe : refusée et comptée', [$try('', 'E', $T)['ok'], $try('', 'E', $T)['remaining']], [false, 3]);
check('table purgée des essais expirés', (function () use ($try, $T) {
    $try('faux', 'F', $T + 5000);
    return (int) db()->query('SELECT COUNT(*) FROM admin_login_attempts WHERE at < ' . ($T + 5000 - ADMIN_LOGIN_WINDOW_SECONDS))->fetchColumn();
})(), 0);

echo "\nRafale de requêtes parallèles (25 processus PHP simultanés, même IP)\n";
if (function_exists('proc_open')) {
    $code = 'require ' . var_export(__DIR__ . '/../includes/functions.php', true) . '; echo json_encode(admin_login_attempt("faux", "rafale", time(), "Secret-123"));';
    $procs = [];
    for ($i = 0; $i < 25; $i++) {
        $procs[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $verified = $blocked = 0;
    foreach ($procs as $i => $p) {
        $out = json_decode(stream_get_contents($pipes[$i][1]), true);
        proc_close($p);
        if (!is_array($out)) { $verified = $blocked = -1000; break; }
        $out['blocked'] ? $blocked++ : $verified++;
    }
    check('au plus 5 mots de passe examinés sur 25 requêtes simultanées', [$verified, $blocked], [5, 20]);
} else {
    echo "  (proc_open indisponible : test de rafale ignoré)\n";
}

echo "\n";
if ($failures > 0) {
    echo "ÉCHEC : $failures vérification(s) sur $checks.\n";
    exit(1);
}
echo "OK : $checks vérifications passées.\n";
