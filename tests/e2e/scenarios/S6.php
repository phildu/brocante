<?php

// S6 — Achat de domaine : webhook checkout.session.completed (métadonnée
// type=domain_purchase) avec devis injecté. Sans identifiants OVH dans le
// workspace, la commande tombe en « paid_needs_review » et le webhook répond
// 500 (filet de rejeu). Branche nominale OVH (ordered) : hors ligne, requiert
// un bac à sable OVH — marquée SKIP.

return function (E2e $e): void {
    $client = 's6';
    $r = null;
    $domain = 'e2e-boutique-s6.fr';
    $cartId = 'cart_e2e_fake1';
    $amount = 1299;
    $cs = 'cs_test_e2edom1';

    $e->step('Demande d inscription réelle (étape 1 du tunnel)', function () use ($e, $client, &$r) {
        $res = $e->get('/inscription/', $client);
        $fields = $e->formFields($res['body'], '/inscription/');
        preg_match_all('/name="plan" value="([^"]+)"/', $res['body'], $m);
        $e->check(($fields['csrf'] ?? '') !== '' && !empty($m[1]), 'formulaire d inscription illisible');
        $res = $e->post('/inscription/', $fields + [
            'site_mode' => 'sales', 'plan' => $m[1][0], 'facturation' => 'month', 'website' => '',
        ], $client, true);
        $e->check($res['status'] === 303 && str_contains($res['location'], 'configurateur.php'), 'attendu 303 vers configurateur, reçu ' . $res['status']);
        parse_str((string) parse_url($res['location'], PHP_URL_QUERY), $q);
        $r = (string) ($q['r'] ?? '');
        $e->check(preg_match('/^[a-f0-9]{16}$/', $r), 'identifiant de demande invalide');
        $e->check(is_file($e->workspace . "/app/data/inscriptions/$r.json"), 'fichier de demande absent');
        return "demande $r";
    });

    $e->step('Devis de domaine injecté dans la demande (étape normalement faite par le configurateur OVH)', function () use ($e, &$r, $domain, $cartId, $amount, $cs) {
        $file = $e->workspace . "/app/data/inscriptions/$r.json";
        $req = json_decode((string) file_get_contents($file), true);
        $e->check(is_array($req), 'demande illisible');
        $req['branding']['domain_purchase'] = [
            'quote' => ['domain' => $domain, 'cart_id' => $cartId, 'amount_cents' => $amount],
            'stripe_session' => $cs,
            'state' => 'awaiting_payment',
        ];
        file_put_contents($file, json_encode($req, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return "devis $domain ($amount cts), session $cs";
    });

    $e->step('Session Stripe correspondante injectée dans le faux Stripe', function () use ($e, $r, $domain, $cartId, $amount, $cs) {
        $res = $e->stripe('POST', '/v1/test/sessions', json_encode([
            'id' => $cs,
            'session' => [
                'id' => $cs, 'object' => 'checkout.session', 'mode' => 'payment', 'currency' => 'eur',
                'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => $amount,
                'client_reference_id' => $r,
                'metadata' => ['type' => 'domain_purchase', 'request_id' => $r, 'domain' => $domain, 'cart_id' => $cartId],
            ],
        ]));
        $e->check(($res['ok'] ?? false) === true, 'injection refusée : ' . json_encode($res));
        return "session $cs payée stockée";
    });

    $e->step('Webhook signé : OVH indisponible → 500 + état paid_needs_review', function () use ($e, $r, $cs) {
        $session = $e->stripe('GET', "/v1/checkout/sessions/$cs");
        $res = $e->sendWebhook('checkout.session.completed', $session);
        $e->check($res['status'] === 500, 'attendu 500 (commande à vérifier), reçu ' . $res['status'] . ' : ' . substr($res['body'], 0, 120));
        $req = json_decode((string) file_get_contents($e->workspace . "/app/data/inscriptions/$r.json"), true);
        $purchase = $req['branding']['domain_purchase'] ?? [];
        $e->check(($purchase['state'] ?? '') === 'paid_needs_review', 'état attendu paid_needs_review, reçu ' . ($purchase['state'] ?? '?'));
        $e->check(($purchase['paid_at'] ?? 0) > 0, 'paid_at non enregistré');
        $e->check(!isset($purchase['ovh_order_id']), 'un ovh_order_id ne devrait pas exister');
        return 'paiement noté, commande en revue';
    });

    $e->step('Rejeu du webhook : idempotent, sans nouvelle tentative OVH', function () use ($e, $r, $cs) {
        $session = $e->stripe('GET', "/v1/checkout/sessions/$cs");
        $res = $e->sendWebhook('checkout.session.completed', $session);
        $e->check($res['status'] === 200, 'attendu 200 au rejeu, reçu ' . $res['status']);
        $req = json_decode((string) file_get_contents($e->workspace . "/app/data/inscriptions/$r.json"), true);
        $purchase = $req['branding']['domain_purchase'] ?? [];
        $e->check(($purchase['state'] ?? '') === 'paid_needs_review', 'le rejeu a changé l état : ' . ($purchase['state'] ?? '?'));
        return 'état stable au rejeu';
    });

    $e->skip('Branche nominale OVH (état ordered + ovh_order_id)', 'nécessite un bac à sable API OVH ; le code (validate/checkout) ne peut pas être exercé hors ligne');
};
