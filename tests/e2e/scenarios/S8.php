<?php

// S8 — Sauvegarde / restauration de la base d'une boutique : la sauvegarde du
// jour est créée à la première ouverture (db_backup_daily), la copie de la
// journée est restaurée après corruption (retrait d'un article + insertion
// d'un marqueur). Pas de script de restauration dans l'app : la procédure
// documentée est le remplacement du fichier, site au repos — ce que fait ce
// scénario.

return function (E2e $e): void {
    $backup = null;
    $snapshot = null;

    $e->step('Sauvegarde du jour recréée à la première requête', function () use ($e, &$backup) {
        foreach (glob($e->workspace . '/app/data/backups/e2e-boutique-a-*.db') ?: [] as $f) unlink($f);
        $res = $e->get('/e2e-boutique-a/');
        $e->check($res['status'] === 200, 'GET boutique A → ' . $res['status']);
        $today = $e->workspace . '/app/data/backups/e2e-boutique-a-' . date('Y-m-d') . '.db';
        $e->check(is_file($today), 'sauvegarde du jour absente après requête');
        $backup = $today;
        return basename($today);
    });

    $e->step('Référence : contenu du catalogue figé', function () use ($e, &$snapshot) {
        $rows = $e->db('e2e-boutique-a')->query('SELECT ref, name, price, stock FROM products ORDER BY ref')->fetchAll();
        $e->check(count($rows) > 0, 'catalogue vide');
        $snapshot = $rows;
        return count($rows) . ' articles figés';
    });

    $e->step('Corruption : article E2E-REF-1 supprimé, marqueur inséré', function () use ($e) {
        $db = $e->db('e2e-boutique-a');
        $db->exec("DELETE FROM products WHERE ref = 'E2E-REF-1'");
        $db->prepare("INSERT INTO products (ref, name, cat, price, stock, weight_grams, description) VALUES ('E2E-MARKER', 'Marqueur corruption', 'brocante', '1 €', 1, 100, 'article fantôme')")
            ->execute();
        $gone = $db->query("SELECT COUNT(*) FROM products WHERE ref = 'E2E-REF-1'")->fetchColumn();
        $marker = $db->query("SELECT COUNT(*) FROM products WHERE ref = 'E2E-MARKER'")->fetchColumn();
        $e->check((int) $gone === 0 && (int) $marker === 1, 'corruption non appliquée');
        return 'E2E-REF-1 supprimé, E2E-MARKER présent';
    });

    $e->step('Restauration : la sauvegarde remplace la base (site au repos)', function () use ($e, &$backup) {
        copy($backup, $e->workspace . '/app/data/e2e-boutique-a.db');
        $db = $e->db('e2e-boutique-a');
        $ok = (int) $db->query("SELECT COUNT(*) FROM products WHERE ref = 'E2E-REF-1'")->fetchColumn() === 1
            && (int) $db->query("SELECT COUNT(*) FROM products WHERE ref = 'E2E-MARKER'")->fetchColumn() === 0;
        $e->check($ok, 'le marqueur persiste ou E2E-REF-1 manque après restauration');
        return 'base restaurée depuis la sauvegarde';
    });

    $e->step('Le catalogue restauré correspond au contenu figé, en boutique publique aussi', function () use ($e, &$snapshot) {
        $rows = $e->db('e2e-boutique-a')->query('SELECT ref, name, price, stock FROM products ORDER BY ref')->fetchAll();
        $e->check($rows === $snapshot, 'le catalogue diffère du figé : ' . json_encode($rows));
        $res = $e->get('/e2e-boutique-a/boutique.php');
        $e->check(str_contains($res['body'], 'Chaise E2E'), 'la chaise E2E n est pas de retour en boutique');
        $e->check(!str_contains($res['body'], 'Marqueur corruption'), 'le marqueur est toujours affiché');
        return 'catalogue identique, boutique rétablie';
    });
};
