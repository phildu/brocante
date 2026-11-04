<?php

// S9 — Paiement par QR de la fiche produit : le client scanne le code, règle
// via Stripe (Connect) et la pièce passe en « Vendue » sans intervention.

return function (E2e $e): void {
    $client = 'qr-shopper';
    $cs = null;
    $orderId = 0;

    $e->step('La fiche produit affiche le QR de paiement', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/produit.php?ref=E2E-QR-1');
        $e->check($res['status'] === 200, 'GET fiche → ' . $res['status']);
        $e->check(str_contains($res['body'], 'class="qr-pay"'), 'carte QR absente de la fiche');
        $e->check(str_contains($res['body'], 'data-qr-url="http://127.0.0.1:8199/e2e-boutique-a/payer.php?ref=E2E-QR-1"'), 'adresse du QR incorrecte');
        $e->check(str_contains($res['body'], 'qrcode-generator.js'), 'bibliothèque QR non chargée');
        return 'QR affiché → payer.php';
    });

    $e->step('La page de paiement propose Stripe', function () use ($e, $client) {
        $res = $e->get('/e2e-boutique-a/payer.php?ref=E2E-QR-1', $client);
        $e->check($res['status'] === 200, 'GET payer → ' . $res['status']);
        $fields = $e->formFields($res['body'], 'payer.php');
        $e->check(($fields['csrf'] ?? '') !== '' && ($fields['ref'] ?? '') === 'E2E-QR-1', 'formulaire de paiement illisible');
        $e->check(($fields['payment_method'] ?? '') === 'stripe', 'Stripe n est pas le moyen proposé : ' . ($fields['payment_method'] ?? '(aucun)'));
        $e->check(str_contains($res['body'], 'Payer 18 €'), 'montant du bouton incorrect');
        return 'formulaire Stripe prêt';
    });

    $e->step('Le paiement crée une commande « Vente en boutique — QR »', function () use ($e, $client, &$cs, &$orderId) {
        $res = $e->get('/e2e-boutique-a/payer.php?ref=E2E-QR-1', $client);
        $fields = $e->formFields($res['body'], 'payer.php');
        $res = $e->post('/e2e-boutique-a/payer.php', $fields + ['email' => 'qr-client@example.com'], $client, true);
        if (!($res['status'] === 303 && str_contains($res['location'], '/v1/pay/'))) {
            throw new RuntimeException('attendu 303 vers Stripe, reçu ' . $res['status'] . ' → ' . $res['location']);
        }
        $cs = basename((string) parse_url($res['location'], PHP_URL_PATH));
        $db = $e->db('e2e-boutique-a');
        $order = $db->query('SELECT * FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetch();
        $e->check($order !== false && $order['status'] === 'pending', 'commande pending absente');
        $e->check($order['shipping_method'] === 'Vente en boutique — QR', 'mode de vente incorrect : ' . ($order['shipping_method'] ?? '?'));
        $e->check((int) $order['amount_total'] === 1800, 'montant attendu 1800, reçu ' . $order['amount_total']);
        $e->check($order['payment_via'] === 'connect', 'paiement hors plateforme (Connect attendu)');
        $orderId = (int) $order['id'];
        return "session $cs, commande #$orderId pending";
    });

    $e->step('Paiement et confirmation : la pièce passe en Vendue', function () use ($e, $client, $cs, $orderId) {
        $e->stripe('POST', "/v1/test/pay/$cs");
        $res = $e->get("/e2e-boutique-a/checkout-success.php?session_id=$cs", $client);
        $e->check(str_contains($res['body'], 'Paiement reçu'), 'page de confirmation QR absente : ' . strip_tags(substr($res['body'], 0, 240)));
        $db = $e->db('e2e-boutique-a');
        $order = $db->query('SELECT * FROM orders WHERE id = ' . $orderId)->fetch();
        $e->check($order && $order['status'] === 'paid', 'commande non payée');
        $e->check($order['fulfillment_status'] === 'livree', 'commande non livrée : ' . ($order['fulfillment_status'] ?? '?'));
        $e->check(!empty($order['picked_up_at']), 'date de remise absente');
        $p = $db->query("SELECT * FROM products WHERE ref = 'E2E-QR-1'")->fetch();
        $e->check((int) $p['stock'] === 0, 'stock attendu 0, reçu ' . $p['stock']);
        $e->check($p['sold_channel'] === 'boutique', 'canal de vente incorrect : ' . ($p['sold_channel'] ?? '?'));
        $e->check(!empty($p['sold_at']), 'date de vente absente');
        return 'paid, stock 0, pièce vendue';
    });

    $e->step('La fiche ne propose plus le QR et affiche « Vendue »', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/produit.php?ref=E2E-QR-1');
        $e->check(!str_contains($res['body'], 'class="qr-pay"'), 'le QR reste affiché après la vente');
        $e->check(str_contains($res['body'], 'Vendue'), 'badge « Vendue » absent');
        return 'QR retiré, badge Vendue';
    });

    $e->step('Rejeu de la confirmation : aucun doublon', function () use ($e, $client, $cs) {
        $e->get("/e2e-boutique-a/checkout-success.php?session_id=$cs", $client);
        $db = $e->db('e2e-boutique-a');
        $count = (int) $db->query('SELECT COUNT(*) FROM orders WHERE stripe_session_id = ' . $db->quote($cs))->fetchColumn();
        $stock = (int) $db->query("SELECT stock FROM products WHERE ref = 'E2E-QR-1'")->fetchColumn();
        $e->check($count === 1 && $stock === 0, "doublon détecté (commandes $count, stock $stock)");
        return '1 commande, stock stable';
    });
};
