<?php
// Tests du module Buvette (logique métier), sur une base SQLite jetable.
//   php scripts/test-buvette.php
// N'écrit jamais dans brocante.db : la base de test vit dans le dossier
// temporaire système et est supprimée à la fin.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
// Base jetable créée à partir de schema.sql : db() (config.php) attend les tables de base d'un commerce (settings, content…).
(new PDO('sqlite:' . $tmp))->exec(file_get_contents(__DIR__ . '/../schema.sql'));

require_once __DIR__ . '/../includes/buvette.php';

$failures = 0;
$checks = 0;
function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    $ok = is_float($expected) ? abs($actual - $expected) < 1e-6 : $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
    } else {
        echo "  ✓ $label\n";
    }
}

bv_boot();
echo "Saisie & formatage\n";
check('« 1,5 » -> 150 centimes', bv_parse_cents('1,5'), 150);
check('« 2.50 € » -> 250', bv_parse_cents('2.50 €'), 250);
check('« 0 » -> 0 (prix gratuit valide)', bv_parse_cents('0'), 0);
check('« abc » -> null', bv_parse_cents('abc'), null);
check('« -3 » -> null', bv_parse_cents('-3'), null);
check('« 1,999 » -> null (3 décimales refusées)', bv_parse_cents('1,999'), null);
check('« 0,25 » -> 0.25', bv_parse_float('0,25'), 0.25);
check('quantité 1.0 -> « 1 »', bv_qty(1.0), '1');
check('quantité 0.25 -> « 0,25 »', bv_qty(0.25), '0,25');
check('quantité 1250 -> « 1250 »', bv_qty(1250), '1250');
check('date en français', bv_fr_date('2026-12-05'), 'samedi 5 décembre 2026');
check('heure valide', bv_valid_time('09:30'), true);
check('heure invalide', bv_valid_time('25:00'), false);
check('date invalide (31 février)', bv_valid_date('2026-02-31'), false);

echo "\nDonnées d'exemple\n";
check('base vide au départ', bv_is_empty(), true);
check('chargement de l\'exemple', bv_seed_example(), true);
check('2e chargement refusé (ne rien écraser)', bv_seed_example(), false);
check('base non vide ensuite', bv_is_empty(), false);

$event = bv_active_event();
check('un événement actif existe', $event !== null, true);
$eid = (int) $event['id'];

echo "\nCoûts de revient\n";
$byName = [];
foreach (bv_items_list() as $it) $byName[$it['name']] = $it;
$costs = bv_item_costs();
// Café : 7 g × 1090/1000 = 7,63 ; gobelet 590/100 = 5,9 ; sucre 0,5 × 590/500 = 0,59 ; touillette 450/500 = 0,9
check('coût du café', $costs[(int) $byName['Café']['id']], 7 * 1.09 + 5.9 + 0.5 * 1.18 + 0.9);
check('soda : coût = 1150/24', $costs[(int) $byName['Soda (canette)']['id']], 1150 / 24);
check('article sans recette (aucune ligne) absent des coûts', isset($costs[999]), false);

echo "\nBesoins & liste de courses\n";
$needs = [];
foreach (bv_event_needs($eid) as $n) $needs[$n['name']] = $n;
// Café : 120 portions × 7 g × 1,10 (marge 10 %) = 924 g -> 1 paquet de 1 kg
check('café : besoin avec marge', $needs['Café moulu']['need_total'], 924.0);
// Régression : 120 × 7 × 1,1 = 924.0000000000001 en flottant. Égalité stricte (pas de tolérance),
// sinon l'arrondi par excès de l'affichage donnait « 924,01 g ».
check('café : besoin exactement 924 (pas de bruit flottant)', $needs['Café moulu']['need_total'] === 924.0, true);
check('affichage du besoin : « 924 »', bv_need_qty($needs['Café moulu']['need_total']), '924');
check('affichage 6,05 L (lait) sans dérive', bv_need_qty(6.050000000000001), '6,05');
check('affichage sous 10 : arrondi par excès au centième', bv_need_qty(0.123), '0,13');
check('affichage dès 10 : arrondi par excès à l\'unité', bv_need_qty(10.2), '11');
check('café : 1 paquet', $needs['Café moulu']['packs'], 1);
check('café : coût = 1 paquet', $needs['Café moulu']['cost_cents'], 1090);
// Gobelets : café 120 + thé 25 + jus 40 = 185 × 1,1 = 203,5 -> 3 sachets de 100
check('gobelets : besoin', $needs['Gobelets carton 20 cl']['need_total'], 203.5);
check('gobelets : 3 sachets', $needs['Gobelets carton 20 cl']['packs'], 3);
// Matériel : 4 tables à trouver, 2 en stock -> manque 2
check('tables : besoin manuel', $needs['Tables pliantes']['need_manual'], 4.0);
check('tables : manque = 4 - 2 de stock', $needs['Tables pliantes']['to_buy'], 2.0);
check('matériel gratuit : coût nul', $needs['Tables pliantes']['cost_cents'], 0);
// Œufs : (60 + 50) × 0,4 × 1,1 = 48,4 -> 5 boîtes de 12
check('œufs : 5 boîtes', $needs['Œufs']['packs'], 5);

// Le stock réduit ce qu'il faut acheter
db()->prepare('UPDATE bv_supplies SET stock = 500 WHERE id = ?')->execute([$needs['Café moulu']['id']]);
$again = [];
foreach (bv_event_needs($eid) as $n) $again[$n['name']] = $n;
check('café avec 500 g en stock : reste 424 g', $again['Café moulu']['to_buy'], 424.0);
db()->prepare('UPDATE bv_supplies SET stock = 5000 WHERE id = ?')->execute([$needs['Café moulu']['id']]);
$again = [];
foreach (bv_event_needs($eid) as $n) $again[$n['name']] = $n;
check('stock suffisant : rien à acheter', $again['Café moulu']['packs'], 0);

// Un article désactivé n'entre plus dans les besoins
db()->prepare('UPDATE bv_items SET is_active = 0 WHERE id = ?')->execute([$byName['Café']['id']]);
$again = [];
foreach (bv_event_needs($eid) as $n) $again[$n['name']] = $n;
check('article retiré de la carte : plus de besoin en café', isset($again['Café moulu']), false);
db()->prepare('UPDATE bv_items SET is_active = 1 WHERE id = ?')->execute([$byName['Café']['id']]);

echo "\nOrdre des lignes de recette\n";
$sandwichLines = bv_recipe_lines((int) $byName['Sandwich jambon-beurre']['id']);
$kinds = array_column($sandwichLines, 'kind');
check('ingrédients avant consommables', array_search('consommable', $kinds) > array_search('ingredient', array_reverse($kinds, true)), true);
check('première ligne = un ingrédient', $kinds[0], 'ingredient');

echo "\nBilan\n";
$t = bv_event_totals($eid);
// CA prévu : 120×100 + 25×100 + 80×100 + 70×150 + 40×150 + 60×350 + 50×300 + 60×150 + 50×250 + 40×200
$expectedCa = 12000 + 2500 + 8000 + 10500 + 6000 + 21000 + 15000 + 9000 + 12500 + 8000;
check('CA prévu', $t['revenue_forecast'], $expectedCa);
check('marge prévue = CA - coût de revient', $t['margin_forecast'], $t['revenue_forecast'] - $t['cost_forecast']);
check('pas de réel tant que rien n\'est saisi', $t['has_actual'], false);
db()->prepare('UPDATE bv_event_items SET sold_qty = 100 WHERE event_id = ? AND item_id = ?')->execute([$eid, $byName['Café']['id']]);
$t = bv_event_totals($eid);
check('réel saisi détecté', $t['has_actual'], true);
check('CA réel = 100 cafés', $t['revenue_actual'], 10000);

echo "\nPlanning bénévoles\n";
$shifts = bv_shifts($eid);
check('créneaux types chargés', count($shifts), 10);
$cov = bv_shift_coverage($shifts);
check('aucun bénévole placé : rien de pourvu', $cov['filled'], 0);
$vols = bv_volunteers_list();
$camille = (int) $vols[0]['id'];
$matin = array_values(array_filter($shifts, fn($s) => $s['role'] === 'Caisse' && $s['start_time'] === '09:00'))[0];
$matin2 = array_values(array_filter($shifts, fn($s) => $s['role'] === 'Service & boissons' && $s['start_time'] === '09:00'))[0];
bv_shift_assign((int) $matin['id'], [$camille]);
$shifts = bv_shifts($eid);
$cov = bv_shift_coverage($shifts);
check('1 poste pourvu', $cov['filled'], 1);
check('aucun conflit pour un seul créneau', count(bv_shift_conflicts($shifts)), 0);
bv_shift_assign((int) $matin2['id'], [$camille]);
check('même bénévole sur deux créneaux simultanés = conflit', count(bv_shift_conflicts(bv_shifts($eid))), 1);
bv_shift_assign((int) $matin2['id'], [$camille, $camille, 99999]);
$s2 = array_values(array_filter(bv_shifts($eid), fn($s) => (int) $s['id'] === (int) $matin2['id']))[0];
check('doublons et bénévole inexistant ignorés', count($s2['volunteers']), 1);
// Créneaux consécutifs (12:00 fin / 12:00 début) ne se chevauchent pas
$suivant = array_values(array_filter($shifts, fn($s) => $s['role'] === 'Caisse' && $s['start_time'] === '12:00'))[0];
bv_shift_assign((int) $matin2['id'], []);
bv_shift_assign((int) $suivant['id'], [$camille]);
check('créneaux bout à bout : pas de conflit', count(bv_shift_conflicts(bv_shifts($eid))), 0);

echo "\nCheck-list\n";
$p = bv_task_progress($eid);
check('check-list type chargée', $p['total'] > 30, true);
check('rien de coché au départ', $p['done'], 0);
check('rechargement : aucun doublon', bv_load_default_checklist($eid), 0);
db()->prepare('DELETE FROM bv_tasks WHERE event_id = ? AND id = (SELECT MIN(id) FROM bv_tasks WHERE event_id = ?)')->execute([$eid, $eid]);
check('rechargement : ne rajoute que la tâche supprimée', bv_load_default_checklist($eid), 1);
$grouped = bv_tasks_by_phase($eid);
check('phases dans l\'ordre chronologique', array_keys($grouped), array_keys(bv_task_phases()));
check('démarches officielles : première phase', array_key_first(bv_task_phases()), 'demarches');
check('registre des exposants dans la check-list type', count(array_filter(bv_default_checklist()['demarches'], fn($l) => str_contains($l, 'registre'))), 1);

echo "\nDuplication d'événement\n";
db()->prepare('UPDATE bv_tasks SET done = 1 WHERE event_id = ?')->execute([$eid]);
$newId = bv_event_duplicate($eid, 'Édition suivante', '2027-05-01');
check('duplication réussie', $newId !== null, true);
check('prévisions reprises', count(bv_event_forecast($newId)), count(bv_event_forecast($eid)));
check('créneaux repris', count(bv_shifts($newId)), count(bv_shifts($eid)));
check('bénévoles non repris', bv_shift_coverage(bv_shifts($newId))['filled'], 0);
check('check-list reprise mais décochée', bv_task_progress($newId)['done'], 0);
check('l\'ancien événement garde ses tâches cochées', bv_task_progress($eid)['done'] > 0, true);
bv_set_active_event($newId);
check('un seul événement actif', (int) db()->query('SELECT COUNT(*) FROM bv_events WHERE is_active = 1')->fetchColumn(), 1);
check('événement actif = le nouveau', (int) bv_active_event()['id'], $newId);
bv_event_delete($newId);
check('suppression : l\'autre événement redevient actif', (int) bv_active_event()['id'], $eid);
check('suppression en cascade (prévisions)', (int) db()->query('SELECT COUNT(*) FROM bv_event_items WHERE event_id = ' . $newId)->fetchColumn(), 0);
check('suppression en cascade (tâches)', (int) db()->query('SELECT COUNT(*) FROM bv_tasks WHERE event_id = ' . $newId)->fetchColumn(), 0);

echo "\nIntégrité référentielle\n";
$supplyId = (int) db()->query("SELECT id FROM bv_supplies WHERE name = 'Café moulu'")->fetchColumn();
db()->prepare('DELETE FROM bv_supplies WHERE id = ?')->execute([$supplyId]);
check('supprimer une fourniture retire ses lignes de recette', (int) db()->query('SELECT COUNT(*) FROM bv_recipe_lines WHERE supply_id = ' . $supplyId)->fetchColumn(), 0);
$sansRecette = array_column(bv_items_without_recipe(), 'name');
check('l\'article garde ses autres lignes (pas « sans recette »)', in_array('Café', $sansRecette, true), false);

echo "\nAccès bénévoles (code partagé)\n";
$_SESSION = [];
check('sans code défini : espace fermé', str_contains((string) bv_volunteer_login('1234'), 'pas encore ouvert'), true);
bv_set_setting('volunteer_code', 'secret42');
check('mauvais code refusé', bv_volunteer_login('nope'), 'Code incorrect.');
check('non connecté après un échec', bv_is_volunteer(), false);
for ($i = 0; $i < 4; $i++) bv_volunteer_login('nope');
check('blocage après 5 essais ratés', str_contains((string) bv_volunteer_login('secret42'), 'Trop d\'essais'), true);
db()->exec('DELETE FROM bv_login_attempts');

echo "\n";
if ($failures) {
    echo "ÉCHEC : $failures / $checks contrôle(s) en erreur.\n";
    exit(1);
}
echo "OK : $checks contrôles réussis.\n";
