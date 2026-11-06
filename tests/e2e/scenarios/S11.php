<?php

// S11 — Rangement de l'article et délai de récupération : champs de la fiche
// (onglet Vente), enregistrement, mention « prêt au retrait » côté client et
// rappel du rangement dans la liste des commandes.

return function (E2e $e): void {
    $admin = 'pickup-admin';
    $db = $e->db('e2e-boutique-a');
    $ref = 'E2E-PICKUP-1';

    $e->step('Connexion à l’administration', function () use ($e, $admin) {
        $e->get('/e2e-boutique-a/admin/login.php', $admin);
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], $admin, true);
        $e->check(in_array($res['status'], [302, 303], true), 'connexion refusée (' . $res['status'] . ')');
        return 'session administrateur ouverte';
    });

    $e->step('Les deux champs sont présents sur la fiche', function () use ($e, $admin, $db, $ref) {
        $db->prepare("INSERT INTO products (ref, name, cat, price, stock, description) VALUES (?, ?, 'brocante', '12 €', 1, 'Pièce de test pickup')")
            ->execute([$ref, 'Panier E2E pickup']);
        $res = $e->get('/e2e-boutique-a/admin/catalog.php', $admin);
        $e->check(str_contains($res['body'], 'name="storage_location"'), 'champ Rangement absent');
        $e->check(str_contains($res['body'], 'name="pickup_delay_minutes"'), 'champ Délai absent');
        return 'Rangement + Délai de récupération';
    });

    $e->step('Enregistrement du rangement et du délai', function () use ($e, $admin, $db, $ref) {
        $res = $e->post('/e2e-boutique-a/admin/save-product.php', [
            'ref' => $ref,
            'pickup_form' => '1',
            'storage_location' => 'Carton 12 · étagère B',
            'pickup_delay_minutes' => '20',
        ], $admin, true);
        $e->check(in_array($res['status'], [302, 303], true), 'enregistrement refusé (' . $res['status'] . ')');
        $row = $db->query('SELECT storage_location, pickup_delay_minutes FROM products WHERE ref = ' . $db->quote($ref))->fetch();
        $e->check($row && $row['storage_location'] === 'Carton 12 · étagère B', 'rangement non enregistré');
        $e->check((int) $row['pickup_delay_minutes'] === 20, 'délai non enregistré');
        return 'Carton 12 · étagère B — 20 min';
    });

    $e->step('La fiche publique annonce le délai sans révéler le rangement', function () use ($e, $ref) {
        $res = $e->get('/e2e-boutique-a/produit.php?ref=' . urlencode($ref), 'pickup-shopper');
        $e->check(str_contains($res['body'], 'Prêt au retrait sous 20 min'), 'mention du délai absente');
        $e->check(!str_contains($res['body'], 'Carton 12'), 'le rangement interne fuit sur la fiche');
        return '« Prêt au retrait sous 20 min »';
    });

    $e->step('La commande rappelle le rangement et le délai', function () use ($e, $admin, $db, $ref) {
        $db->prepare("INSERT INTO orders (stripe_session_id, amount_total, status, items, email, shop_slug, shipping_mode) VALUES (?, ?, 'paid', ?, ?, ?, 'pickup')")
            ->execute(['e2e-pickup-' . getmypid(), 1200, json_encode([['ref' => $ref, 'name' => 'Panier E2E pickup', 'qty' => 1, 'unit_cents' => 1200]], JSON_UNESCAPED_UNICODE), 'pickup@example.com', 'e2e-boutique-a']);
        $res = $e->get('/e2e-boutique-a/admin/orders.php', $admin);
        $e->check(str_contains($res['body'], 'Carton 12 · étagère B'), 'rangement absent de la commande');
        $e->check(str_contains($res['body'], 'prêt sous 20 min'), 'délai absent de la commande');
        return 'rangement et délai affichés au vendeur';
    });
};
