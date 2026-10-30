<?php

// S4 — Modèles : chacun des 5 modèles s'applique, s'affiche sur le site public
// et persiste en base.

return function (E2e $e): void {
    $templates = ['vitrine', 'marche', 'feuille', 'librairie', 'mode'];

    $e->step('Session admin (boutique A)', function () use ($e) {
        $e->get('/e2e-boutique-a/admin/login.php', 'adminA');
        $res = $e->post('/e2e-boutique-a/admin/login.php', ['username' => 'admin', 'password' => 'boutique-e2e'], 'adminA', true);
        $e->check($res['status'] === 302, 'login → HTTP ' . $res['status']);
        return 'ok';
    });

    $seen = [];
    foreach ($templates as $t) {
        $e->step("Modèle « $t » appliqué et visible", function () use ($e, $t, &$seen) {
            $res = $e->post('/e2e-boutique-a/admin/appearance-action.php', ['action' => 'template', 'template' => $t, 'with_style' => '1'], 'adminA', true);
            $e->check($res['status'] === 302, 'POST appearance-action → HTTP ' . $res['status']);
            $res = $e->get('/e2e-boutique-a/');
            $marker = 'template-' . $t;
            $e->check(str_contains($res['body'], $marker) || str_contains($res['body'], 'data-layout="' . $t . '"'), "marqueur $marker absent du HTML public");
            $seen[$t] = true;
            return 'marqueur présent';
        });
    }

    $e->step('5 modèles distincts vus', function () use (&$seen) {
        if (count($seen) !== 5) throw new RuntimeException(count($seen) . '/5 modèles affichés');
        return '5/5';
    });

    $e->step('Persistance : le dernier choix survit à une nouvelle requête', function () use ($e) {
        $res = $e->get('/e2e-boutique-a/');
        $e->check(str_contains($res['body'], 'template-mode'), 'le modèle mode ne persiste pas');
        $db = $e->db('e2e-boutique-a');
        $appearance = $db->query("SELECT value FROM settings WHERE name = 'appearance'")->fetch();
        $saved = json_decode((string) ($appearance['value'] ?? ''), true);
        $e->check(($saved['template'] ?? '') === 'mode', 'settings.appearance.template != mode (' . ($saved['template'] ?? '?') . ')');
        return 'template-mode en base et en HTML';
    });
};
