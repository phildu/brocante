<?php
// Tests de la caisse de la buvette (includes/buvette-caisse.php), sur une base SQLite jetable.
//   php scripts/test-buvette-caisse.php
// N'écrit jamais dans brocante.db : la base de test vit dans le dossier temporaire système.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-caisse-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
(new PDO('sqlite:' . $tmp))->exec(file_get_contents(__DIR__ . '/../schema.sql'));

require_once __DIR__ . '/../includes/buvette-caisse.php';

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "  ✓ $label\n";
    }
}

bv_boot();
bv_seed_example();
$eid = (int) bv_active_event()['id'];
$byName = [];
foreach (bv_items_list() as $it) $byName[$it['name']] = $it;
$cafe = (int) $byName['Café']['id'];
$soda = (int) $byName['Soda (canette)']['id'];
$pCafe = (int) $byName['Café']['price_cents'];
$pSoda = (int) $byName['Soda (canette)']['price_cents'];
$soldOf = static function (int $itemId) use ($eid): ?int {
    $s = db()->prepare('SELECT sold_qty FROM bv_event_items WHERE event_id = ? AND item_id = ?');
    $s->execute([$eid, $itemId]);
    $v = $s->fetchColumn();
    return $v === false || $v === null ? null : (int) $v;
};

echo "Vente en espèces\n";
$before = $soldOf($cafe);
[$sale, $err] = bv_sale_create($eid, [['id' => $cafe, 'qty' => 2], ['id' => $soda, 'qty' => 1]], 'cash', 1000, 'Léa');
check('vente acceptée', $err, null);
check('total = prix de la carte, pas du téléphone', $sale['total_cents'], 2 * $pCafe + $pSoda);
check('rendu de monnaie', $sale['change_cents'], 1000 - (2 * $pCafe + $pSoda));
check('le bilan « quantités vendues » avance', $soldOf($cafe), ($before ?? 0) + 2);

echo "\nRefus\n";
[$s2, $e2] = bv_sale_create($eid, [['id' => $cafe, 'qty' => 1]], 'cash', 1, 'Léa');
check('somme remise insuffisante refusée', $s2, null);
check('message clair', str_contains((string) $e2, 'inférieure au total'), true);
check('panier vide refusé', bv_sale_create($eid, [], 'cash', null)[1], 'Le panier est vide.');
check('article inconnu refusé', bv_sale_create($eid, [['id' => 99999, 'qty' => 1]], 'cash', null)[1] !== null, true);
check('quantité énorme refusée', bv_sale_create($eid, [['id' => $cafe, 'qty' => 500]], 'cash', null)[1] !== null, true);
check('mode de paiement inconnu refusé', bv_sale_create($eid, [['id' => $cafe, 'qty' => 1]], 'cheque', null)[1], 'Mode de paiement inconnu.');
check('événement inexistant refusé', bv_sale_create(9999, [['id' => $cafe, 'qty' => 1]], 'cash', null)[0], null);
check('un refus ne touche pas au bilan', $soldOf($cafe), ($before ?? 0) + 2);

echo "\nCarte et regroupement\n";
[$s3] = bv_sale_create($eid, [['id' => $soda, 'qty' => 1], ['id' => $soda, 'qty' => 2]], 'card', 5000, 'Léa');
check('lignes identiques regroupées', $s3['total_cents'], 3 * $pSoda);
check('carte : pas de rendu de monnaie', $s3['change_cents'], null);

echo "\nBilan\n";
$sum = bv_sales_summary($eid);
check('2 ventes valables', $sum['count'], 2);
check('total', $sum['total_cents'], 2 * $pCafe + $pSoda + 3 * $pSoda);
check('espèces', $sum['cash_cents'], 2 * $pCafe + $pSoda);
check('carte', $sum['card_cents'], 3 * $pSoda);
check('détail par article', array_sum(array_column($sum['items'], 'qty')), 2 + 1 + 3);

echo "\nAnnulation\n";
check('annulation par un bénévole', bv_sale_void((int) $sale['id'], false), null);
check('le bilan « vendu » est corrigé', $soldOf($cafe), $before ?? 0);
check('déjà annulée', bv_sale_void((int) $sale['id'], false), 'Cette vente est déjà annulée.');
check('bilan sans la vente annulée', bv_sales_summary($eid)['count'], 1);
check('la vente annulée reste listée', count(bv_sales_list($eid)), 2);
check('vente inconnue', bv_sale_void(9999, true), 'Vente introuvable.');
db()->prepare('UPDATE bv_sales SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), (int) $s3['id']]);
check('trop tard pour un bénévole', str_contains((string) bv_sale_void((int) $s3['id'], false), 'Trop tard'), true);
check('le responsable annule à tout moment', bv_sale_void((int) $s3['id'], true), null);
check('plus rien de valable', bv_sales_summary($eid)['total_cents'], 0);
check('le bilan ne passe jamais sous zéro', $soldOf($soda) >= 0, true);

echo "\nPaiement par QR Stripe (double de l'API)\n";
$GLOBALS['bv_card_account_stub'] = '';
check('sans compte Stripe prêt : QR refusé', bv_sale_start_card($eid, [['id' => $cafe, 'qty' => 1]], 'Léa', 'http://x/')[1] !== null, true);
$GLOBALS['bv_card_account_stub'] = 'acct_galerie';
$calls = [];
$paid = false;
$GLOBALS['bv_stripe_stub'] = function (string $m, string $path, array $p) use (&$calls, &$paid): array {
    $calls[] = [$m, $path, $p];
    if ($m === 'POST' && $path === '/checkout/sessions') return ['id' => 'cs_test_1', 'url' => 'https://pay.example/cs_test_1'];
    if ($m === 'GET') return ['payment_status' => $paid ? 'paid' : 'unpaid', 'status' => $paid ? 'complete' : 'open', 'payment_intent' => 'pi_1'];
    return [];
};
$before = $soldOf($cafe);
$sumBefore = bv_sales_summary($eid);
db()->exec("INSERT INTO bv_items (name, category, price_cents) VALUES ('Gobelet', 'autre', 20)");
$gobelet = (int) db()->lastInsertId();
check('sous 0,50 € : QR refusé', str_contains((string) bv_sale_start_card($eid, [['id' => $gobelet, 'qty' => 1]], 'Léa', 'http://x/')[1], '0,50'), true);
check('refus : aucun appel Stripe, aucune vente', [$calls, (int) db()->query("SELECT COUNT(*) FROM bv_sales WHERE status = 'pending'")->fetchColumn()], [[], 0]);
[$q, $qe] = bv_sale_start_card($eid, [['id' => $cafe, 'qty' => 2], ['id' => $soda, 'qty' => 1]], 'Léa', 'http://x/galerie/g/buvette/');
check('QR créé', $qe, null);
check('adresse de paiement renvoyée', $q['url'], 'https://pay.example/cs_test_1');
$create = $calls[0][2];
check('l\'argent va au compte de la galerie', $create['payment_intent_data']['transfer_data']['destination'], 'acct_galerie');
check('lignes envoyées à Stripe : 2 articles', count($create['line_items']), 2);
check('prix envoyés = carte, pas téléphone', $create['line_items'][0]['price_data']['unit_amount'], $pCafe);
check('page de retour du client', $create['success_url'], 'http://x/galerie/g/buvette/merci.php');
check('vente en attente', bv_sale_get((int) $q['id'])['status'], 'pending');
check('en attente : le bilan « vendu » ne bouge pas', $soldOf($cafe), $before);
check('en attente : pas comptée dans la recette', bv_sales_summary($eid)['total_cents'], $sumBefore['total_cents']);
check('en attente : signalée au bilan', bv_sales_summary($eid)['pending'], 1);
check('suivi : toujours en attente', bv_sale_card_status((int) $q['id']), 'pending');
check('annulation d\'une vente en attente refusée', str_contains((string) bv_sale_void((int) $q['id'], true), 'en attente'), true);
$paid = true;
check('suivi : payée', bv_sale_card_status((int) $q['id']), 'paid');
check('payée : le bilan « vendu » avance', $soldOf($cafe), ($before ?? 0) + 2);
check('payée : comptée en carte', bv_sales_summary($eid)['stripe_cents'], 2 * $pCafe + $pSoda);
bv_sale_card_status((int) $q['id']);
check('un 2e suivi ne compte pas deux fois', $soldOf($cafe), ($before ?? 0) + 2);
check('un bénévole ne peut pas annuler un paiement QR', str_contains((string) bv_sale_void((int) $q['id'], false), 'responsable'), true);
$calls = [];
check('le responsable annule : OK', bv_sale_void((int) $q['id'], true), null);
check('remboursement Stripe demandé', array_values(array_filter($calls, fn ($c) => $c[1] === '/refunds'))[0][2]['payment_intent'] ?? '', 'pi_1');
check('annulée : le bilan « vendu » est corrigé', $soldOf($cafe), $before ?? 0);

echo "\nQR abandonné ou expiré\n";
$paid = false;
[$q2] = bv_sale_start_card($eid, [['id' => $soda, 'qty' => 1]], 'Léa', 'http://x/');
$soldSoda = $soldOf($soda);
check('abandon : annulée', bv_sale_card_status((int) $q2['id'], true), 'canceled');
check('abandon : rien dans le bilan', $soldOf($soda), $soldSoda);
check('abandon : statut enregistré', bv_sale_get((int) $q2['id'])['status'], 'canceled');
[$q3] = bv_sale_start_card($eid, [['id' => $soda, 'qty' => 1]], 'Léa', 'http://x/');
$paid = true;     // le client a payé juste avant l'abandon
check('abandon trop tard : reste payée', bv_sale_card_status((int) $q3['id'], true), 'paid');
check('abandon trop tard : comptée', $soldOf($soda), ($soldSoda ?? 0) + 1);
$GLOBALS['bv_stripe_stub'] = function (): array { throw new RuntimeException('réseau'); };
$pending = db()->query("SELECT COUNT(*) FROM bv_sales WHERE status = 'pending'")->fetchColumn();
check('Stripe injoignable : pas de vente fantôme', bv_sale_start_card($eid, [['id' => $soda, 'qty' => 1]], 'Léa', 'http://x/')[1] !== null && (int) db()->query("SELECT COUNT(*) FROM bv_sales WHERE status = 'pending'")->fetchColumn() === (int) $pending, true);

echo "\nQR de la carte imprimée\n";
check('adresse du QR d\'un article', str_ends_with(bv_item_scan_url($cafe), '/buvette/caisse.php?a=' . $cafe), true);
check('le QR ne contient aucun prix', str_contains(bv_item_scan_url($cafe), (string) $pCafe), false);

echo "\nGratuités des bénévoles\n";
db()->exec("INSERT INTO bv_volunteers (name) VALUES ('Léa')");
$lea = (int) db()->lastInsertId();
db()->exec("INSERT INTO bv_volunteers (name, is_active) VALUES ('Parti', 0)");
$parti = (int) db()->lastInsertId();
$crepe = (int) $byName['Crêpe au sucre']['id'];
check('quotas par défaut : 2 boissons, 1 repas', bv_gift_quotas(), ['bar' => 2, 'food' => 1]);
check('café = boisson', bv_gift_kind($byName['Café']), 'bar');
check('crêpe = repas', bv_gift_kind($byName['Crêpe au sucre']), 'food');
$code = bv_badge_code($lea);
check('le badge retrouve le bénévole', bv_badge_volunteer($code)['name'], 'Léa');
check('…aussi depuis l\'adresse du QR', bv_badge_volunteer(bv_badge_url($lea))['name'], 'Léa');
check('jeton falsifié refusé', bv_badge_volunteer($lea . '.0000000000000000'), null);
check('badge d\'un autre numéro refusé', bv_badge_volunteer(($lea + 1) . '.' . bv_badge_token($lea)), null);
check('bénévole désactivé refusé', bv_badge_volunteer(bv_badge_code($parti)), null);
check('texte quelconque refusé', bv_badge_volunteer('n\'importe quoi'), null);
check('le jeton est stable', bv_badge_code($lea), $code);
$soldBefore = $soldOf($cafe);
$recetteBefore = bv_sales_summary($eid)['total_cents'];
[$g1, $ge1, $st1] = bv_gift_give($eid, $lea, $cafe, 'Tom');
check('1re boisson offerte', $ge1, null);
check('reste 1 boisson', $st1['bar']['left'], 1);
check('le repas est intact', $st1['food']['left'], 1);
[$g2] = bv_gift_give($eid, $lea, $soda, 'Tom');
[$g3, $ge3, $st3] = bv_gift_give($eid, $lea, $cafe, 'Tom');
check('3e boisson refusée (quota 2)', $g3, null);
check('message clair', str_contains((string) $ge3, '2 sur 2'), true);
[$g4, $ge4] = bv_gift_give($eid, $lea, $crepe, 'Tom');
check('le repas reste possible', $ge4, null);
check('2e repas refusé (quota 1)', bv_gift_give($eid, $lea, $crepe, 'Tom')[0], null);
check('une gratuité ne touche pas la recette', bv_sales_summary($eid)['total_cents'], $recetteBefore);
check('ni les quantités vendues', $soldOf($cafe), $soldBefore);
$gs = bv_gifts_summary($eid);
check('3 gratuités servies', [$gs['count'], $gs['bar'], $gs['food']], [3, 2, 1]);
check('valeur offerte', $gs['value_cents'], $pCafe + $pSoda + (int) $byName['Crêpe au sucre']['price_cents']);
check('annulation : une boisson revient', bv_gift_void((int) $g2['id'], false), null);
check('…le quota se libère', bv_gift_status($eid, $lea)['bar']['left'], 1);
check('annulation en double refusée', bv_gift_void((int) $g2['id'], false), 'Cette gratuité est déjà annulée.');
db()->prepare('UPDATE bv_gifts SET created_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() - 3600), (int) $g1['id']]);
check('trop tard pour un bénévole', str_contains((string) bv_gift_void((int) $g1['id'], false), 'Trop tard'), true);
check('le responsable annule à tout moment', bv_gift_void((int) $g1['id'], true), null);
bv_gift_set_quotas(0, 3);
check('quotas modifiables', bv_gift_quotas(), ['bar' => 0, 'food' => 3]);
check('quota 0 : plus de boisson', bv_gift_give($eid, $lea, $cafe, 'Tom')[0], null);
bv_gift_set_quotas(99, -5);
check('quotas bornés', bv_gift_quotas(), ['bar' => BV_GIFT_MAX_QUOTA, 'food' => 0]);
check('article inconnu refusé', bv_gift_give($eid, $lea, 99999, 'Tom')[1] !== null, true);
check('bénévole inconnu refusé', bv_gift_give($eid, 99999, $cafe, 'Tom')[1] !== null, true);

echo "\nMode dégradé : synchronisation sans doublon\n";
bv_gift_set_quotas(2, 1);
$leaStart = bv_gift_status($eid, $lea);
$ops = [
    ['uid' => 'op-sale-1', 'type' => 'sale', 'at' => time() - 600, 'items' => [['id' => $cafe, 'qty' => 2]], 'method' => 'cash', 'given_cents' => 500, 'total_cents' => 2 * $pCafe],
    ['uid' => 'op-sale-2', 'type' => 'sale', 'at' => time() - 300, 'items' => [['id' => $soda, 'qty' => 1]], 'method' => 'card', 'total_cents' => 99999],
    ['uid' => 'op-gift-1', 'type' => 'gift', 'at' => time() - 200, 'code' => bv_badge_code($lea), 'item_id' => $cafe],
    ['uid' => 'op-bad-1', 'type' => 'sale', 'at' => time(), 'items' => [], 'method' => 'cash'],
    ['uid' => 'op-bad-2', 'type' => 'gift', 'at' => time(), 'code' => '1.0000000000000000', 'item_id' => $cafe],
    ['uid' => '', 'type' => 'sale'],
];
$cashBefore = bv_sales_summary($eid)['cash_cents'];
$soldCafeBefore = $soldOf($cafe);
$res = bv_sync_ops($eid, $ops, 'Tom');
check('ventes et gratuité valides acceptées', [$res[0]['ok'], $res[1]['ok'], $res[2]['ok']], [true, true, true]);
check('panier vide refusé', $res[3]['ok'], false);
check('badge falsifié refusé', $res[4]['ok'], false);
check('opération sans identifiant refusée', $res[5]['ok'], false);
check('la vente hors ligne est comptée', bv_sales_summary($eid)['cash_cents'], $cashBefore + 2 * $pCafe);
check('…et alimente le bilan', $soldOf($cafe), $soldCafeBefore + 2);
$again = bv_sync_ops($eid, $ops, 'Tom');
check('2e envoi de la même file : acceptée comme doublon', [$again[0]['ok'], $again[0]['duplicate'], $again[1]['duplicate'], $again[2]['duplicate']], [true, true, true, true]);
check('…sans rien recompter (recette)', bv_sales_summary($eid)['cash_cents'], $cashBefore + 2 * $pCafe);
check('…ni le bilan', $soldOf($cafe), $soldCafeBefore + 2);
check('…ni la gratuité', bv_gift_status($eid, $lea)['bar']['used'], $leaStart['bar']['used'] + 1);
$row = db()->query("SELECT note, created_at FROM bv_sales WHERE client_uid = 'op-sale-1'")->fetch();
check('la vente est marquée « hors ligne »', str_contains($row['note'], 'hors ligne'), true);
check('elle garde l\'heure saisie sur le téléphone', abs(strtotime($row['created_at']) - (time() - 600)) < 5, true);
$row2 = db()->query("SELECT note FROM bv_sales WHERE client_uid = 'op-sale-2'")->fetch();
check('un écart de prix est signalé', str_contains($row2['note'], 'écart de prix'), true);
$totalOk = bv_sales_summary($eid)['total_cents'];
check('le prix de la carte fait foi (pas celui du téléphone)', $totalOk >= $pSoda && $totalOk < 99999, true);
// Gratuités saisies hors ligne au-delà du quota : déjà servies, donc enregistrées, avec une note.
$over = [];
for ($i = 1; $i <= 3; $i++) $over[] = ['uid' => 'op-over-' . $i, 'type' => 'gift', 'at' => time(), 'code' => bv_badge_code($lea), 'item_id' => $soda];
$r3 = bv_sync_ops($eid, $over, 'Tom');
check('gratuités hors ligne enregistrées même au-delà du quota', array_column($r3, 'ok'), [true, true, true]);
check('…avec la note « hors quota »', (int) db()->query("SELECT COUNT(*) FROM bv_gifts WHERE client_uid LIKE 'op-over-%' AND note LIKE '%hors quota%'")->fetchColumn() >= 1, true);
check('heure future refusée (heure du serveur)', bv_client_ts(time() + 86400), null);
check('heure trop ancienne refusée', bv_client_ts(time() - 10 * 86400), null);
check('roster : badge et reste de chaque bénévole', array_column(bv_roster($eid), 'name') === array_column(bv_volunteers_list(true), 'name'), true);
check('roster : le badge est lisible', bv_badge_volunteer(bv_roster($eid)[0]['code']) !== null, true);

echo "\n";
if ($failures) {
    echo "ÉCHEC : $failures / $checks contrôle(s) en erreur.\n";
    exit(1);
}
echo "OK : $checks contrôles réussis.\n";
