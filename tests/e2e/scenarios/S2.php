<?php

// S2 — Achat complet : panier, passage de commande (paiement via Stripe Connect
// de la plateforme, faux Stripe), confirmation, décrément du stock, idempotence.

return function (E2e $e): void {
    $client = 'shopper';
    $cs = null;

    $e->step('Le produit de test est visible en boutique', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/boutique.php');
        $e->check($res['status'] === 200, 'GET boutique → ' . $res['status']);
        $e->check(str_contains($res['body'], 'Chaise E2E'), 'le produit E2E-REF-1 n apparaît pas dans la boutique');
        return 'E2E-REF-1 listé';
    });

    $e->step('Ajout au panier (fiche produit)', function () use ($e, $client) {
        $res = $e->get('/e2e-boutique-a/produit.php?ref=E2E-REF-1', $client);
        $fields = $e->formFields($res['body'], 'cart-add');
        $e->check(($fields['csrf'] ?? '') !== '' && ($fields['ref'] ?? '') === 'E2E-REF-1', 'formulaire d ajout au panier illisible');
        $res = $e->post('/e2e-boutique-a/cart-add.php', $fields + [
            'redirect' => '/e2e-boutique-a/cart.php',
        ], $client, true);
        $e->check(in_array($res['status'], [302, 303], true), 'cart-add → ' . $res['status']);
        $res = $e->get('/e2e-boutique-a/cart.php', $client);
        $e->check(str_contains($res['body'], 'Chaise E2E'), 'le panier n affiche pas la chaise');
        return 'panier OK';
    });

    $e->step('Checkout : session Stripe créée, commande en attente', function () use ($e, $client, &$cs) {
        $res = $e->get('/e2e-boutique-a/cart.php', $client);
        $fields = $e->formFields($res['body'], 'checkout');
        $e->check(($fields['csrf'] ?? '') !== '', 'csrf du checkout absent');
        $post = array_merge($fields, [
            'shipping_choice' => 'pickup', 'contact_first_name' => 'Cliente', 'contact_last_name' => 'Test',
            'contact_email' => 'e2e-client@example.com', 'contact_phone' => '0612345678',
            'buyer_country' => 'FR', 'buyer_line1' => '12 rue de Rivoli', 'buyer_postal' => '75004', 'buyer_city' => 'Paris',
            'accept_terms' => '1', 'dest_country' => 'FR',
        ]);
        $res = $e->post('/e2e-boutique-a/checkout.php', $post, $client, true);
        if (!($res['status'] === 303 && str_contains($res['location'], '/v1/pay/'))) {
            $cart = $e->get('/e2e-boutique-a/cart.php', $client);
            $flash = preg_match('/<p class="publish-status"[^>]*>(.*?)<\/p>/s', $cart['body'], $fm) ? html_entity_decode(strip_tags($fm[1]), ENT_QUOTES, 'UTF-8') : '(pas de message flash)';
            throw new RuntimeException('attendu 303 vers Stripe, reçu ' . $res['status'] . ' → ' . $res['location'] . ' ; flash : ' . $flash);
        }
        $cs = basename((string) parse_url($res['location'], PHP_URL_PATH));
        $order = $e->db('e2e-boutique-a')->query("SELECT * FROM orders WHERE stripe_session_id = " . $e->db('e2e-boutique-a')->quote($cs))->fetch();
        $e->check($order !== false && $order['status'] === 'pending', 'commande pending absente de la base');
        $e->check(($order['buyer_address'] ?? '') === '12 rue de Rivoli, 75004 Paris, FR', 'adresse de l acheteur non enregistrée : ' . ($order['buyer_address'] ?? '(absente)'));
        $e->check($order['payment_via'] === 'connect', 'le paiement ne passe pas par la plateforme (Connect)');
        return "session $cs, commande #{$order['id']} pending";
    });

    $e->step('Paiement (faux Stripe) + page de confirmation', function () use ($e, $client, $cs) {
        $e->stripe('POST', "/v1/test/pay/$cs");
        $res = $e->get("/e2e-boutique-a/checkout-success.php?session_id=$cs", $client);
        $e->check(str_contains($res['body'], 'commande est confirmée'), 'confirmation absente : ' . strip_tags(substr($res['body'], 0, 300)));
        return 'commande confirmée';
    });

    $e->step('Base : commande paid, stock décrémenté', function () use ($e, $cs) {
        $db = $e->db('e2e-boutique-a');
        $order = $db->query('SELECT * FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetch();
        $e->check($order && $order['status'] === 'paid', 'statut de commande != paid (' . ($order['status'] ?? '?') . ')');
        $stock = (int) $db->query("SELECT stock FROM products WHERE ref = 'E2E-REF-1'")->fetchColumn();
        $e->check($stock === 2, 'stock attendu 2, reçu ' . $stock);
        return "paid, stock 2";
    });

    $e->step('Idempotence : rejeu de la page de confirmation', function () use ($e, $client, $cs) {
        $res = $e->get("/e2e-boutique-a/checkout-success.php?session_id=$cs", $client);
        $e->check(str_contains($res['body'], 'commande est confirmée'), 'le rejeu affiche une erreur');
        $db = $e->db('e2e-boutique-a');
        $count = (int) $db->query('SELECT COUNT(*) FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetchColumn();
        $stock = (int) $db->query("SELECT stock FROM products WHERE ref = 'E2E-REF-1'")->fetchColumn();
        $e->check($count === 1 && $stock === 2, "doublon détecté (commandes $count, stock $stock)");
        return '1 commande, stock stable';
    });
};
