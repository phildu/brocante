<?php

// Lance les scénarios e2e S1–S11 sur une copie isolée de l'application.
// Usage : php tests/e2e/run.php [--keep] [--scenario=S3]
//   --keep       conserve le workspace /tmp/brocs-e2e (serveurs arrêtés)
//   --scenario   n'exécute qu'un scénario (S1…S11)

declare(strict_types=1);

require __DIR__ . '/lib.php';

$keep = in_array('--keep', $argv, true);
$only = null;
foreach ($argv as $a) if (preg_match('/^--scenario=(S\d+)$/', $a, $m)) $only = $m[1];

$src = dirname(__DIR__, 2);              // racine brocante
$ws = sys_get_temp_dir() . '/brocs-e2e';
$marker = $ws . '/.e2e-workspace';

// ── Workspace : copie isolée de l'application ───────────────────────────────
if (is_dir($ws)) {
    if (!is_file($marker)) {
        fwrite(STDERR, "Refus d'écraser $ws : marqueur absent.\n");
        exit(2);
    }
    exec('rm -rf ' . escapeshellarg($ws));
}
mkdir($ws, 0700, true);
touch($marker);
mkdir("$ws/logs", 0700);

echo "Provisionnement du workspace…\n";
$excludes = array_map(static fn ($d) => '--exclude=' . escapeshellarg($d), [
    'data', 'tenants', 'uploads', '.secrets', 'tests', 'config.local.php', '.git', '.venv', 'var', 'node_modules', 'db-backup', '.DS_Store',
]);
exec(sprintf('rsync -a %s %s/ %s', implode(' ', $excludes), escapeshellarg($src), escapeshellarg($ws . '/app')));

// Dossiers de données frais.
foreach (['data/sessions', 'data/inscriptions', 'data/saas', 'data/backups', 'data/galeries', 'uploads', 'assets/tenants', '.secrets'] as $d) {
    mkdir("$ws/app/$d", 0755, true);
}

// Clés Stripe de la plateforme → faux Stripe local (nécessite config.local.php, d'où sa présence ici).
file_put_contents("$ws/app/.secrets/stripe_plateforme.json", json_encode([
    'secret_key' => 'sk_test_e2e00000000000000000000000000',
    'webhook_secret' => 'whsec_e2e00000000000000000000000000',
    'api_base' => 'http://127.0.0.1:8191/v1',
], JSON_PRETTY_PRINT));

// Config locale du workspace : aucune clé réelle, paiement des commandes via Stripe Connect (commission plateforme).
file_put_contents("$ws/app/config.local.php", <<<'PHP'
<?php
// Config du workspace e2e (générée) : faux Stripe local via .secrets/stripe_plateforme.json + api_base.
define('ADMIN_PASSWORD', 'armoire-e2e');
define('STRIPE_SECRET_KEY', '');
define('STRIPE_PUBLISHABLE_KEY', '');
define('SITE_URL', 'http://127.0.0.1:8199');
define('GEMINI_API_KEY', '');
define('FAL_API_KEY', '');
define('PYTHON_BIN', '');
define('PHP_CLI_BIN', '');
define('FFMPEG_BIN', '');
define('FFPROBE_BIN', '');
PHP);

// La boutique A vend via la plateforme (Stripe Connect) : le paiement des commandes passe par le faux Stripe.
// « account » en alphanumérique pur (la validation de l'app refuse les _ après acct_) ; commission 10 % plateforme.
file_put_contents("$ws/app/data/saas/connect-rates.json", json_encode([
    'e2e-boutique-a' => ['account' => 'acct_teste2e0001', 'pct' => 10, 'platform_pct' => 10, 'gallery_pct' => 0],
], JSON_PRETTY_PRINT));

// Deux boutiques témoins à partir du modèle (copié depuis la source : tenants/ est exclu de la synchro).
mkdir("$ws/app/tenants", 0755, true);
$tenantPass = password_hash('boutique-e2e', PASSWORD_DEFAULT);
foreach (['e2e-boutique-a' => 'Boutique E2E A', 'e2e-boutique-b' => 'Boutique E2E B'] as $slug => $name) {
    exec(sprintf('cp -R %s %s', escapeshellarg("$src/tenants/_modele"), escapeshellarg("$ws/app/tenants/$slug")));
    file_put_contents("$ws/app/tenants/$slug/tenant.php", <<<PHP
<?php
return [
    'name' => '$name',
    'tagline' => 'Boutique de test automatisé',
    'site_url' => 'http://127.0.0.1:8199/$slug',
    'presence_mode' => 'online',
    'admin_user' => 'admin',
    'admin_password' => '$tenantPass',
    'sales_enabled' => true,
    'categories' => [
        ['key' => 'brocante', 'label' => 'Brocante', 'icon' => 'ic-basket'],
    ],
    'texts' => ['item_singular' => 'article', 'item_plural' => 'articles'],
];
PHP);
}

// ── Serveurs ────────────────────────────────────────────────────────────────
$php = PHP_BINARY;
$fakeStripe = proc_open([$php, '-S', '127.0.0.1:8191', '-t', $ws, __DIR__ . '/fake-stripe.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$ws/logs/stripe.log", 'w'], 2 => ['file', "$ws/logs/stripe.err", 'w']], $pipes);
$app = proc_open([$php, '-S', '127.0.0.1:8199', '-t', "$ws/app", "$ws/app/router.php"], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$ws/logs/app.log", 'w'], 2 => ['file', "$ws/logs/app.err", 'w']], $pipes);

$ready = static function (string $url): bool {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_TIMEOUT => 2, CURLOPT_RETURNTRANSFER => true]);
    $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) > 0;
    return $ok;
};
$t0 = microtime(true);
while ((!$ready('http://127.0.0.1:8191/v1/pay/x') || !$ready('http://127.0.0.1:8199/')) && microtime(true) - $t0 < 15) usleep(100000);
echo "Serveurs prêts en " . round(microtime(true) - $t0, 1) . " s.\n";

// ── Fixtures produit + amorçage des bases ───────────────────────────────────
$e = new E2e($ws);
$e->get('/e2e-boutique-a/');
$e->get('/e2e-boutique-b/');
$pdo = $e->db('e2e-boutique-a');
$pdo->prepare("INSERT INTO products (ref, name, cat, price, stock, weight_grams, description) VALUES (?, ?, 'brocante', '25 €', 3, 500, 'Article de test e2e')")
    ->execute(['E2E-REF-1', 'Chaise E2E']);
$pdo->prepare("INSERT INTO products (ref, name, cat, price, stock, weight_grams, description) VALUES (?, ?, 'brocante', '18 €', 1, 300, 'Pièce à régler par QR')")
    ->execute(['E2E-QR-1', 'Lampe E2E QR']);
echo "Fixtures en place.\n";

// ── Scénarios ───────────────────────────────────────────────────────────────
$all = ['S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9', 'S10', 'S11'];
foreach ($all as $code) {
    if ($only && $only !== $code) continue;
    $file = __DIR__ . '/scenarios/' . $code . '.php';
    if (!is_file($file)) { echo "\n=== $code ===\n  [skip] fichier absent\n"; continue; }
    $fn = require $file;
    $e->scenario("$code — " . basename($file, '.php'), $fn);
}

// ── Bilan ───────────────────────────────────────────────────────────────────
$counts = $e->summary();
echo "\n======== BILAN ========\n";
printf("PASS: %d   FAIL: %d   SKIP: %d\n", $counts['PASS'], $counts['FAIL'], $counts['SKIP']);
foreach ($e->results as $name => $steps) {
    foreach ($steps as $s) {
        if ($s['state'] === 'FAIL') printf("  FAIL %s › %s — %s\n", $name, $s['step'], $s['detail']);
    }
}
echo "Logs : $ws/logs/  " . ($keep ? "(workspace conservé : $ws)" : '') . "\n";

proc_terminate($app);
proc_terminate($fakeStripe);
proc_close($app);
proc_close($fakeStripe);

exit($counts['FAIL'] > 0 ? 1 : 0);
