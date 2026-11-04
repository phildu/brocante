<?php

// S3 — Isolation entre boutiques : une session admin de la boutique A ne vaut
// rien sur la boutique B (cookies de session distincts, exigence is_admin == tenant).

return function (E2e $e): void {
    $e->step('Connexion admin sur la boutique A', function () use ($e) {
        $e->get('/e2e-boutique-a/admin/login.php', 'adminA');
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'adminA', true);
        $e->check($res['status'] === 302, 'login A → HTTP ' . $res['status'] . ' (attendu 302)');
        $res = $e->get('/e2e-boutique-a/admin/catalog.php', 'adminA');
        $e->check($res['status'] === 200, 'admin A produits → HTTP ' . $res['status']);
        return 'admin A connecté';
    });

    $e->step('La session A n ouvre pas l admin de la boutique B', function () use ($e) {
        $res = $e->get('/e2e-boutique-b/admin/catalog.php', 'adminA', true);
        $e->check($res['status'] === 302, 'attendu 302, reçu ' . $res['status']);
        $e->check(str_contains($res['location'], '/e2e-boutique-b/admin/login.php'), 'redirection inattendue : ' . $res['location']);
        $res = $e->get('/e2e-boutique-b/admin/orders.php', 'adminA', true);
        $e->check($res['status'] === 302, 'commandes admin B → ' . $res['status']);
        return 'refus propre (302 vers login B)';
    });

    $e->step('La session A est toujours valide sur A (contrôle)', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/catalog.php', 'adminA');
        $e->check($res['status'] === 200, 'session A perdue après la tentative B : HTTP ' . $res['status']);
        return 'session A intacte';
    });

    $e->step('Sans connexion : l admin public est refusé', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/catalog.php', 'anonyme', true);
        $e->check($res['status'] === 302 && str_contains($res['location'], 'login.php'), 'attendu 302 vers login, reçu ' . $res['status'] . ' → ' . $res['location']);
        return 'anonyme → login';
    });

    $e->step('La page de connexion propose « Rester connecté »', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/login.php', 'rememberA');
        $e->check($res['status'] === 200, 'login → HTTP ' . $res['status']);
        $e->check(str_contains($res['body'], 'name="remember"'), 'case « Rester connecté » absente du formulaire');
        return 'case présente';
    });

    $e->step('Sans l option, le cookie reste un cookie de session', function () use ($e) {
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'sessionA', true);
        $e->check($res['status'] === 302, 'login → HTTP ' . $res['status'] . ' (attendu 302)');
        $e->check(preg_match('/sess_e2e-boutique-a=[^;\s]+/', $res['headers']) === 1, 'cookie de session absent de la réponse');
        $e->check(!preg_match('/sess_e2e-boutique-a=[^;\s]+;\s*expires=/i', $res['headers']), 'cookie daté alors que la case n était pas cochée');
        return 'cookie sans date d expiration';
    });

    $e->step('« Rester connecté » : le cookie est daté de ~30 jours', function () use ($e) {
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e', 'remember' => '1'], 'rememberA', true);
        $e->check($res['status'] === 302, 'login → HTTP ' . $res['status'] . ' (attendu 302)');
        $e->check(preg_match('/sess_e2e-boutique-a=[^;\s]+;\s*expires=/i', $res['headers']) === 1, 'aucun cookie daté dans la réponse');
        preg_match('/sess_e2e-boutique-a=[^;\s]+;\s*expires=([^;]+)/i', $res['headers'], $m);
        $days = ((strtotime(trim($m[1])) - time()) ?: 0) / 86400;
        $e->check($days > 28 && $days <= 31, 'expiration à ' . round($days, 1) . ' jours (attendu ~30)');
        $res = $e->get('/e2e-boutique-a/admin/catalog.php', 'rememberA');
        $e->check($res['status'] === 200, 'admin inaccessible avec le cookie mémorisé : HTTP ' . $res['status']);
        return 'cookie à ' . round($days, 1) . ' jours, admin accessible';
    });
};
