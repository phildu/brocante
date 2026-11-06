<?php

// S7 — Quota IA : boutique B quotaée à 0,01 €/mois avec 0,05 $ de consommation
// enregistrée → la génération IA est bloquée (JSON ok:false, quota:true). La
// boutique A non quotaée n'est pas bloquée. Le rachat de crédit (webhook
// ai_credit, faux Stripe) débloque la boutique B ; le rejeu du webhook est
// idempotent. Aucune clé Gemini n'est requise : le gate s'applique avant
// l'appel fournisseur.

return function (E2e $e): void {
    $quota = 0.01;

    $e->step('Quota de 0,01 € affecté à la boutique B + consommation 0,05 $ enregistrée', function () use ($e, $quota) {
        $policyFile = $e->workspace . '/app/data/saas/ai-policy.json';
        $all = is_file($policyFile) ? (json_decode((string) file_get_contents($policyFile), true) ?: []) : [];
        $all['e2e-boutique-b'] = ['quota_eur' => $quota, 'since' => gmdate('Y-m'), 'source' => 'e2e', 'updated' => time()];
        file_put_contents($policyFile, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $e->db('e2e-boutique-b')->prepare("INSERT INTO ai_usage (at, kind, model, cost_usd, script) VALUES (?, 'text', 'gemini-2.5-flash', 0.05, 'e2e')")
            ->execute([gmdate('Y-m-d H:i:s')]);
        return 'quota 0,01 €, dépense 0,05 $';
    });

    $e->step('Admin B : la génération IA est bloquée (quota)', function () use ($e) {
        $res = $e->get('/e2e-boutique-b/admin/login.php', 'adminB');
        $res = $e->post('/e2e-boutique-b/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'adminB', true);
        $e->check($res['status'] === 302, 'login admin B → HTTP ' . $res['status']);
        $res = $e->post('/e2e-boutique-b/admin/product-ai.php', ['fields' => 'name', 'name' => 'Chaise ancienne'], 'adminB');
        $json = json_decode($res['body'], true);
        $e->check(is_array($json) && ($json['ok'] ?? true) === false, 'ok:false attendu : ' . substr($res['body'], 0, 120));
        $e->check(($json['quota'] ?? false) === true, 'drapeau quota attendu : ' . substr($res['body'], 0, 160));
        return 'gate quota actif';
    });

    $e->step('Admin A (sans quota) : pas de blocage quota', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/admin/login.php', 'adminA');
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'adminA', true);
        $e->check($res['status'] === 302, 'login admin A → HTTP ' . $res['status']);
        $res = $e->post('/e2e-boutique-a/admin/product-ai.php', ['fields' => 'name', 'name' => 'Chaise ancienne'], 'adminA');
        $json = json_decode($res['body'], true);
        $e->check(is_array($json) && ($json['ok'] ?? true) === false, 'ok:false attendu (pas de clé Gemini) : ' . substr($res['body'], 0, 120));
        $e->check(empty($json['quota']), 'le drapeau quota ne doit pas être levé pour A');
        return 'A bloqué seulement par l absence de clé';
    });

    $e->step('Rachat de crédit : webhook ai_credit (faux Stripe) crédite la boutique B', function () use ($e) {
        $res = $e->stripe('POST', '/v1/test/sessions', json_encode([
            'id' => 'cs_test_e2ecredit1',
            'session' => [
                'id' => 'cs_test_e2ecredit1', 'object' => 'checkout.session', 'mode' => 'payment', 'currency' => 'eur',
                'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 500,
                'metadata' => ['type' => 'ai_credit', 'shop' => 'e2e-boutique-b', 'credit_eur' => '3', 'pack' => 'petit'],
            ],
        ]));
        $e->check(($res['ok'] ?? false) === true, 'injection refusée : ' . json_encode($res));
        $session = $e->stripe('GET', '/v1/checkout/sessions/cs_test_e2ecredit1');
        $res = $e->sendWebhook('checkout.session.completed', $session);
        $e->check($res['status'] === 200, 'webhook ai_credit → HTTP ' . $res['status'] . ' : ' . substr($res['body'], 0, 120));
        $credits = json_decode((string) file_get_contents($e->workspace . '/app/data/saas/ai-credits.json'), true);
        $purchases = $credits['e2e-boutique-b']['purchases'] ?? [];
        $e->check(count($purchases) === 1 && (float) $purchases[0]['credit_eur'] === 3.0, 'crédit 3 € introuvable : ' . json_encode($credits));
        return 'crédit 3 € (pack petit) enregistré';
    });

    $e->step('B débloquée : la génération IA n est plus arrêtée par le quota', function () use ($e) {
        $res = $e->post('/e2e-boutique-b/admin/product-ai.php', ['fields' => 'name', 'name' => 'Chaise ancienne'], 'adminB');
        $json = json_decode($res['body'], true);
        $e->check(is_array($json), 'réponse JSON attendue : ' . substr($res['body'], 0, 120));
        $e->check(empty($json['quota']), 'le quota ne doit plus bloquer : ' . substr($res['body'], 0, 160));
        return 'quota levé (échec éventuel lié à la clé absente seulement)';
    });

    $e->step('Rejeu du webhook ai_credit : pas de double crédit', function () use ($e) {
        $session = $e->stripe('GET', '/v1/checkout/sessions/cs_test_e2ecredit1');
        $res = $e->sendWebhook('checkout.session.completed', $session);
        $e->check($res['status'] === 200, 'rejeu → HTTP ' . $res['status']);
        $credits = json_decode((string) file_get_contents($e->workspace . '/app/data/saas/ai-credits.json'), true);
        $purchases = $credits['e2e-boutique-b']['purchases'] ?? [];
        $e->check(count($purchases) === 1, 'double crédit détecté (' . count($purchases) . ' achats)');
        return 'crédit unique';
    });
};
