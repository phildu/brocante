<?php

// S1 — Inscription jusqu'à la boutique en ligne : tunnel complet avec paiement
// (faux Stripe), retour client (merci.php) + webhook (filet), création de la boutique.

return function (E2e $e): void {
    $client = 's1';
    $plan = null;
    $r = null;
    $k = null;
    $cs = null;

    $e->step('Étape 1 : formule payante choisie, demande créée', function () use ($e, $client, &$plan, &$r, &$k) {
        $res = $e->get('/inscription/', $client);
        $e->check($res['status'] === 200, 'GET /inscription/ → ' . $res['status']);
        $e->check(preg_match_all('/name="plan" value="([^"]+)"/', $res['body'], $m) > 0, 'aucune formule proposée');
        $plan = $m[1][0];
        $fields = $e->formFields($res['body'], '/inscription/');
        $e->check(($fields['csrf'] ?? '') !== '', 'jeton csrf absent');
        $res = $e->post('/inscription/', $fields + [
            'site_mode' => 'sales', 'plan' => $plan, 'facturation' => 'month', 'website' => '',
        ], $client, true);
        $e->check($res['status'] === 303 && str_contains($res['location'], 'configurateur.php'), 'attendu 303 vers configurateur, reçu ' . $res['status'] . ' → ' . $res['location']);
        parse_str((string) parse_url($res['location'], PHP_URL_QUERY), $q);
        $r = $q['r'] ?? '';
        $k = $q['k'] ?? '';
        $e->check(preg_match('/^[a-f0-9]{16}$/', $r) && $k !== '', 'identifiants de demande absents du 303');
        return "plan $plan, demande $r";
    });

    $e->step('Étape 2 : coordonnées boutique (domaine différé)', function () use ($e, $client, $r, $k) {
        $res = $e->get("/inscription/configurateur.php?r=$r&k=$k", $client);
        $e->check($res['status'] === 200, 'GET configurateur → ' . $res['status']);
        $csrf = $e->csrfFrom($res['body']);
        $e->check($csrf !== '', 'csrf configurateur absent');
        $res = $e->post('/inscription/configurateur.php', [
            'csrf' => $csrf, 'r' => $r, 'k' => $k, 'action' => 'continue_payment',
            'shop_name' => 'Boutique E2E S1', 'about' => 'Objets de brocante testés en e2e',
            'location' => 'Lyon', 'activity_primary' => 'other', 'activity_other' => 'Brocante e2e',
            'email' => 'e2e-s1@example.com', 'terms' => '1', 'defer_domain' => '1',
        ], $client, true);
        $e->check($res['status'] === 303 && str_contains($res['location'], 'design.php'), 'attendu 303 vers design.php, reçu ' . $res['status'] . ' → ' . $res['location']);
        return "demande $r";
    });

    $e->step('Étape design : paiement (faux Stripe)', function () use ($e, $client, $r, $k, &$cs) {
        $res = $e->get("/inscription/design.php?r=$r&k=$k", $client);
        $csrf = $e->csrfFrom($res['body']);
        $res = $e->post('/inscription/design.php', ['csrf' => $csrf, 'r' => $r, 'k' => $k, 'skip_design' => '1'], $client, true);
        $e->check($res['status'] === 303 && str_contains($res['location'], '/v1/pay/'), 'attendu 303 vers Stripe, reçu ' . $res['status'] . ' → ' . $res['location']);
        $cs = basename((string) parse_url($res['location'], PHP_URL_PATH));
        $paid = $e->stripe('POST', "/v1/test/pay/$cs");
        $e->check(($paid['payment_status'] ?? '') === 'paid', 'le faux Stripe n a pas marqué la session payée');
        return "session $cs";
    });

    $e->step('Retour client : merci.php confirme et renvoie vers la création', function () use ($e, $client, $r, $cs) {
        $res = $e->get("/inscription/merci.php?r=$r&s=$cs", $client, true);
        $e->check($res['status'] === 303 && str_contains($res['location'], 'creer.php'), 'attendu 303 vers creer.php, reçu ' . $res['status'] . ' → ' . $res['location']);
        $req = json_decode((string) file_get_contents($e->workspace . "/app/data/inscriptions/$r.json"), true);
        $e->check(($req['status'] ?? '') === 'paid', 'statut de la demande != paid (' . ($req['status'] ?? '?') . ')');
        return 'statut paid';
    });

    $e->step('Filet : le webhook checkout.session.completed est idempotent', function () use ($e, $r, $cs) {
        $session = $e->stripe('GET', "/v1/checkout/sessions/$cs");
        $res = $e->sendWebhook('checkout.session.completed', $session);
        $e->check($res['status'] === 200, 'webhook → HTTP ' . $res['status'] . ' : ' . substr($res['body'], 0, 120));
        $req = json_decode((string) file_get_contents($e->workspace . "/app/data/inscriptions/$r.json"), true);
        $e->check(($req['status'] ?? '') === 'paid', 'le webhook a cassé le statut paid');
        return 'webhook accepté, statut inchangé';
    });

    $e->step('Étape 3 : création de la boutique', function () use ($e, $client, $r, $k) {
        $res = $e->get("/inscription/creer.php?r=$r&k=$k", $client);
        $e->check($res['status'] === 200, 'GET creer.php → ' . $res['status']);
        $csrf = $e->csrfFrom($res['body']);
        $res = $e->post('/inscription/creer.php', [
            'csrf' => $csrf, 'r' => $r, 'k' => $k,
            'email' => 'e2e-s1@example.com', 'shop_name' => 'Boutique E2E S1', 'slug' => 'e2e-s1',
            'first_name' => 'E2E', 'last_name' => 'Test', 'phone' => '0612345678',
            'password' => 'motdepasse-e2e1', 'password2' => 'motdepasse-e2e1',
            'about' => 'Objets de brocante', 'terms' => '1',
        ], $client, true);
        $e->check(in_array($res['status'], [302, 303], true), 'attendu 302/303 après création, reçu ' . $res['status']);
        $e->check(is_file($e->workspace . '/app/tenants/e2e-s1/tenant.php'), 'tenants/e2e-s1/tenant.php absent');
        $e->check(is_file($e->workspace . '/app/data/e2e-s1.db'), 'data/e2e-s1.db absente');
        $req = json_decode((string) file_get_contents($e->workspace . "/app/data/inscriptions/$r.json"), true);
        $e->check(($req['status'] ?? '') === 'approved', 'statut != approved (' . ($req['status'] ?? '?') . ')');
        return 'boutique e2e-s1 générée';
    });

    $e->step('La boutique publique répond', function () use ($e) {
        $res = $e->get('/e2e-s1/');
        $e->check($res['status'] === 200, 'GET /e2e-s1/ → ' . $res['status']);
        return '200';
    });
};
