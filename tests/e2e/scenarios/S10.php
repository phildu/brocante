<?php

// S10 — Emballage cadeau : réglage de l'administration, option du panier,
// montant ajouté à la commande, petit mot dans la confirmation et l'e-mail.

return function (E2e $e): void {
    $client = 'gift-shopper';
    $cs = null;
    $db = $e->db('e2e-boutique-a');

    $e->step("L'administration active l'emballage cadeau à 4,50 €", function () use ($e, $db) {
        $db->exec("UPDATE content SET gift_wrap_enabled = '1', gift_wrap_price = '4.50', gift_wrap_label = 'Papier kraft et ruban' WHERE id = 1");
        $row = $db->query('SELECT gift_wrap_enabled, gift_wrap_price, gift_wrap_label FROM content WHERE id = 1')->fetch();
        $e->check($row && $row['gift_wrap_enabled'] === '1' && $row['gift_wrap_price'] === '4.50', 'réglage non enregistré');
        return '4,50 € — Papier kraft et ruban';
    });

    $e->step('L’option apparaît au panier avec son prix', function () use ($e, $client) {
        $res = $e->get('/e2e-boutique-a/produit.php?ref=E2E-REF-1', $client);
        $fields = $e->formFields($res['body'], 'cart-add');
        $e->check(($fields['csrf'] ?? '') !== '', 'formulaire d ajout au panier illisible');
        $e->post('/e2e-boutique-a/cart-add.php', $fields + ['redirect' => '/e2e-boutique-a/cart.php'], $client, true);
        $cart = $e->get('/e2e-boutique-a/cart.php', $client);
        $e->check(str_contains($cart['body'], 'name="gift_wrap"'), 'case emballage absente du panier');
        $e->check(str_contains($cart['body'], 'Papier kraft et ruban'), 'libellé personnalisé absent');
        $e->check(str_contains($cart['body'], '4,50'), 'prix de l emballage absent');
        return 'case, libellé et prix affichés';
    });

    $e->step('Commande avec emballage et petit mot', function () use ($e, $client, &$cs, $db) {
        $res = $e->get('/e2e-boutique-a/cart.php', $client);
        $fields = $e->formFields($res['body'], 'checkout');
        $e->check(($fields['csrf'] ?? '') !== '', 'csrf du checkout absent');
        $post = array_merge($fields, [
            'shipping_choice' => 'pickup', 'contact_first_name' => 'Cliente', 'contact_last_name' => 'Cadeau',
            'contact_email' => 'cadeau-e2e@example.com', 'contact_phone' => '0612345678',
            'buyer_country' => 'FR', 'buyer_line1' => '12 rue de Rivoli', 'buyer_postal' => '75004', 'buyer_city' => 'Paris',
            'accept_terms' => '1', 'dest_country' => 'FR',
            'gift_wrap' => '1', 'gift_message' => 'Joyeux anniversaire, Léa !',
        ]);
        $res = $e->post('/e2e-boutique-a/checkout.php', $post, $client, true);
        if (!($res['status'] === 303 && str_contains($res['location'], '/v1/pay/'))) {
            $cart = $e->get('/e2e-boutique-a/cart.php', $client);
            $flash = preg_match('/<p class="publish-status"[^>]*>(.*?)<\/p>/s', $cart['body'], $fm) ? html_entity_decode(strip_tags($fm[1]), ENT_QUOTES, 'UTF-8') : '(pas de message flash)';
            throw new RuntimeException('attendu 303 vers Stripe, reçu ' . $res['status'] . ' → ' . $res['location'] . ' ; flash : ' . $flash);
        }
        $cs = basename((string) parse_url($res['location'], PHP_URL_PATH));
        $order = $db->query('SELECT * FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetch();
        $e->check($order !== false, 'commande absente de la base');
        $e->check((int) $order['gift_wrap'] === 1, 'emballage non enregistré sur la commande');
        $e->check((int) $order['gift_wrap_cents'] === 450, 'montant de l emballage attendu 450, reçu ' . $order['gift_wrap_cents']);
        $e->check((string) $order['gift_message'] === 'Joyeux anniversaire, Léa !', 'petit mot non enregistré');
        return "session $cs, commande #{$order['id']}";
    });

    $e->step('Paiement : total et récapitulatif avec l’emballage', function () use ($e, $client, $cs, $db) {
        $e->stripe('POST', "/v1/test/pay/$cs");
        $res = $e->get("/e2e-boutique-a/checkout-success.php?session_id=$cs", $client);
        $e->check(str_contains($res['body'], 'Emballage cadeau'), 'ligne emballage absente de la confirmation');
        $e->check(str_contains($res['body'], '4,50'), 'montant de l emballage absent de la confirmation');
        $e->check(str_contains($res['body'], 'Joyeux anniversaire, Léa !'), 'petit mot absent de la confirmation');
        $order = $db->query('SELECT * FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetch();
        $e->check($order && $order['status'] === 'paid', 'commande non payée');
        $e->check((int) $order['amount_total'] === 2950, 'total attendu 2950, reçu ' . $order['amount_total']);
        return 'total 2950 cts (25,00 € + 4,50 €)';
    });

    $e->step('L’e-mail de confirmation mentionne l’emballage et le petit mot', function () use ($e) {
        $log = $e->workspace . '/app/data/mail-outbox.log';
        $text = is_file($log) ? (string) file_get_contents($log) : '';
        $e->check(str_contains($text, 'Emballage cadeau'), 'emballage absent de l e-mail');
        $e->check(str_contains($text, 'Joyeux anniversaire, Léa !'), 'petit mot absent de l e-mail');
        return 'e-mail complet';
    });
};
