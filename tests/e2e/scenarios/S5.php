<?php

// S5 — Activation d'un pack : le portail active « hello » pour la boutique A,
// la base enregistre l'activation, la page d'admin du plugin s'affiche, son
// API persiste une note, et un POST sans jeton CSRF est refusé.

return function (E2e $e): void {
    $e->step('Le portail local liste le pack hello', function () use ($e) {
        $res = $e->get('/portail/plugins.php', 'portail');
        $e->check($res['status'] === 200, 'plugins.php → HTTP ' . $res['status']);
        $e->check(str_contains($res['body'], 'hello'), 'le pack hello n apparaît pas dans le portail');
        return 'liste des packs affichée';
    });

    $e->step('Activation du pack pour e2e-boutique-a', function () use ($e) {
        $res = $e->get('/portail/plugins.php', 'portail');
        $csrf = $e->csrfFrom($res['body']);
        $e->check($csrf !== '', 'jeton csrf du portail absent');
        $res = $e->post('/portail/plugins.php', [
            'csrf' => $csrf, 'action' => 'enable', 'plugin' => 'hello', 'slug' => 'e2e-boutique-a',
        ], 'portail', true);
        $e->check(in_array($res['status'], [302, 303], true), 'activation → HTTP ' . $res['status'] . ' → ' . $res['location']);
        return 'enable → 302';
    });

    $e->step('La base de la boutique a enregistré l activation', function () use ($e) {
        $row = $e->db('e2e-boutique-a')->query("SELECT * FROM plugins WHERE id = 'hello'")->fetch();
        $e->check($row !== false, 'aucune ligne plugins/hello');
        $e->check((int) $row['enabled'] === 1, 'enabled != 1 (' . var_export($row['enabled'] ?? null, true) . ')');
        $e->check(trim((string) ($row['version'] ?? '')) !== '', 'version du plugin vide');
        return 'ligne hello active';
    });

    $e->step('Page d administration du plugin (session admin A)', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/login.php', 'adminA');
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'adminA', true);
        $e->check($res['status'] === 302, 'login admin A → HTTP ' . $res['status']);
        $res = $e->get('/e2e-boutique-a/admin/plugin.php?p=hello&v=index', 'adminA');
        $e->check($res['status'] === 200, 'plugin.php → HTTP ' . $res['status']);
        $e->check(str_contains($res['body'], 'Bonjour'), 'la page du plugin ne s affiche pas (titre « Bonjour » absent)');
        return 'page hello affichée';
    });

    $e->step('API du plugin : ajout d une note', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/plugin.php?p=hello&v=index', 'adminA');
        $e->check(preg_match('/data-csrf="([a-f0-9]{32})"/', $res['body'], $m) === 1, 'jeton csrf (data-csrf) absent de la page admin');
        $res = $e->post('/e2e-boutique-a/admin/plugin-api.php?p=hello&r=notes', [
            'csrf' => $m[1], 'text' => 'Note E2E hello',
        ], 'adminA');
        $json = json_decode($res['body'], true);
        $e->check(is_array($json) && str_contains(json_encode($json['notes'] ?? [], JSON_UNESCAPED_UNICODE), 'Note E2E hello'), 'POST notes → ' . mb_substr($res['body'], 0, 140));
        return 'note acceptée';
    });

    $e->step('La note est persistée et ressortie par l API', function () use ($e) {
        $row = $e->db('e2e-boutique-a')->query('SELECT text FROM hello_notes ORDER BY id DESC LIMIT 1')->fetch();
        $e->check($row !== false && str_contains((string) $row['text'], 'Note E2E hello'), 'note absente de hello_notes');
        $res = $e->get('/e2e-boutique-a/admin/plugin-api.php?p=hello&r=notes', 'adminA');
        $json = json_decode($res['body'], true);
        $e->check(is_array($json) && str_contains(json_encode($json['notes'] ?? [], JSON_UNESCAPED_UNICODE), 'Note E2E hello'), 'note absente de la réponse API');
        return 'note en base + API';
    });

    $e->step('Un POST sans jeton CSRF est refusé', function () use ($e) {
        $res = $e->post('/e2e-boutique-a/admin/plugin-api.php?p=hello&r=notes', ['text' => 'sans jeton'], 'adminA');
        $json = json_decode($res['body'], true);
        $e->check($res['status'] === 400, 'attendu 400, reçu ' . $res['status'] . ' : ' . substr($res['body'], 0, 100));
        $e->check(is_array($json) && isset($json['error']), 'corps JSON de refus attendu');
        return '400 sans jeton';
    });
};
