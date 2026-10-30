<?php

// Smoke test : vérifie que les pages clés répondent sur une instance donnée.
// Usage : php scripts/smoke.php [base_url]   (défaut : BASE_URL ou http://127.0.0.1:8080)

$base = rtrim($argv[1] ?? getenv('BASE_URL') ?: 'http://127.0.0.1:8080', '/');

$paths = [
    '/' => 200,              // accueil
    '/inscription/' => 200,  // tunnel d'inscription
    '/portail/' => null,     // portail : 200 (session) ou 302 (login) selon l'état
];

$failures = 0;
foreach ($paths as $path => $expected) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'brocs-smoke/1.0',
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);

    $ok = $error === '' && $status > 0 && $status < 400 && ($expected === null || $status === $expected);
    if (!$ok) $failures++;
    printf("%-4s %-20s %s%s\n", $ok ? 'OK' : 'KO', $path, $error !== '' ? $error : (string) $status,
        $ok || $expected === null ? '' : " (attendu $expected)");
}

exit($failures > 0 ? 1 : 0);
