<?php
/**
 * Test de la logique du module « Invendus & dons » (sans serveur web).
 *
 *   php scripts/test-invendus.php
 *
 * Utilise une base SQLite jetable : brocante.db n'est jamais touchée.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmpDb = sys_get_temp_dir() . '/brocante-test-invendus-' . getmypid() . '.db';
@unlink($tmpDb);
define('DB_PATH', $tmpDb);
define('UNSOLD_TESTING', true);
register_shutdown_function(function () use ($tmpDb) { @unlink($tmpDb); });
// Base jetable créée à partir de schema.sql : db() (config.php) attend les tables de base d'un commerce (settings, content…).
(new PDO('sqlite:' . $tmpDb))->exec(file_get_contents(__DIR__ . '/../schema.sql'));

require_once __DIR__ . '/../includes/invendus.php';
unsold_ensure_schema();   // le schéma n'est plus créé au chargement du fichier (galerie : voir unsold_enter_gallery())

$pass = 0;
$fail = 0;
function check(string $label, $actual, $expected = true): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label\n       attendu : " . var_export($expected, true) . "\n       obtenu  : " . var_export($actual, true) . "\n";
    }
}

$today = unsold_now()->format('Y-m-d');

/* ---------- Édition & phases ---------- */
echo "Éditions et phases\n";
[$ev, $errs] = unsold_event_from_post([
    'name' => 'Vide-grenier du Petit Chalet', 'event_date' => '2026-11-08', 'location' => 'Place du village',
    'collect_start_time' => '16:00', 'collect_end_time' => '18:30', 'status' => 'published',
]);
check('édition valide', $errs, []);
check('heures combinées', $ev['collect_start'], '2026-11-08 16:00');
$eid = unsold_event_save(null, $ev);
$event = unsold_event_get($eid);
check('jeton du point généré (32 hex)', (bool) preg_match('/^[a-f0-9]{32}$/', $event['desk_token']));

$at = fn(string $s) => new DateTimeImmutable($s, unsold_tz());
check('avant 16h → propositions', unsold_event_phase($event, $at('2026-11-08 09:00')), 'proposals');
check('à 16h pile → collecte', unsold_event_phase($event, $at('2026-11-08 16:00')), 'collecting');
check('à 18h29 → collecte', unsold_event_phase($event, $at('2026-11-08 18:29')), 'collecting');
check('à 18h30 → terminée', unsold_event_phase($event, $at('2026-11-08 18:30')), 'ended');
$draft = $event; $draft['status'] = 'draft';
check('brouillon → draft', unsold_event_phase($draft, $at('2026-11-08 17:00')), 'draft');

[, $e1] = unsold_event_from_post(['name' => 'x', 'event_date' => '2026-02-30', 'collect_start_time' => '16:00', 'collect_end_time' => '18:00']);
check('date impossible refusée', count($e1) >= 1);
[, $e2] = unsold_event_from_post(['name' => 'x', 'event_date' => '2026-11-08', 'collect_start_time' => '18:00', 'collect_end_time' => '16:00']);
check('fermeture avant ouverture refusée', count($e2), 1);
[, $e3] = unsold_event_from_post(['name' => 'x', 'event_date' => '2026-11-08', 'collect_start_time' => '25:00', 'collect_end_time' => '16:00']);
check('heure invalide refusée', count($e3), 1);

/* ---------- Validation d'une proposition ---------- */
echo "Validation des propositions\n";
$base = ['donor_name' => 'Marie Dupont', 'donor_phone' => '06 12 34 56 78', 'title' => 'Lot de livres de poche', 'consent' => '1'];
[, $err] = unsold_offer_from_post($base);
check('proposition valide', $err, []);
[, $err] = unsold_offer_from_post(array_merge($base, ['donor_phone' => '', 'donor_email' => '']));
check('sans moyen de contact refusée', count($err), 1);
[, $err] = unsold_offer_from_post(array_merge($base, ['donor_email' => 'pas-un-mail']));
check('e-mail invalide refusé', count($err), 1);
[, $err] = unsold_offer_from_post(array_merge($base, ['donor_phone' => 'abc']));
check('téléphone invalide refusé', count($err), 1);
[, $err] = unsold_offer_from_post(array_merge($base, ['handover' => 'pickup']));
check('ramassage sans emplacement refusé', count($err), 1);
[, $err] = unsold_offer_from_post(array_merge($base, ['handover' => 'pickup', 'stand_ref' => 'B12']));
check('ramassage avec emplacement accepté', $err, []);
unset($base['consent']);
[, $err] = unsold_offer_from_post($base);
check('sans consentement refusé', count($err), 1);
[, $err] = unsold_offer_from_post($base, false);
check('consentement non exigé côté admin', $err, []);
[, $err] = unsold_offer_from_post(array_merge($base, ['donor_phone' => '']), false, false);
check('contact facultatif côté admin', $err, []);
[$d] = unsold_offer_from_post(array_merge($base, ['category' => 'hack', 'size' => 'XXL', 'quantity' => '-5', 'consent' => 1]));
check('catégorie inconnue → autre', $d['category'], 'autre');
check('taille inconnue → M', $d['size'], 'M');
check('quantité plafonnée à ≥ 1', $d['quantity'], 1);
[$d] = unsold_offer_from_post(array_merge($base, ['title' => "Vélo\x00 <b>enfant</b>\n\n  rouge", 'consent' => 1]));
check('contrôles retirés, espaces tassés', $d['title'], 'Vélo <b>enfant</b> rouge');

/* ---------- Création, suivi par jeton ---------- */
echo "Création et lien de suivi\n";
$mk = function (string $title, string $handover = 'self', string $stand = 'A1', string $size = 'M', ?string $token = null) use ($eid) {
    [$d] = unsold_offer_from_post(['donor_name' => 'Donateur ' . $stand, 'donor_phone' => '0612345678', 'title' => $title,
        'handover' => $handover, 'stand_ref' => $stand, 'size' => $size, 'consent' => 1]);
    return unsold_offer_create($eid, $d, $token);
};
$a = $mk('Chaise en bois', 'self', 'A2');
$b = $mk('Carton de jouets', 'pickup', 'A10', 'L', $a['token']);
$c = $mk('Lampe', 'pickup', 'A1', 'S');
$d = $mk('Vaisselle', 'pickup', 'B3', 'M');
check('2 propositions partagent le jeton du donateur', $a['token'], $b['token']);
check('suivi par jeton retourne les 2', count(unsold_offers_by_token($a['token'])), 2);
check('jeton malformé → rien', unsold_offers_by_token("' OR 1=1 --"), []);
check('statut initial', unsold_offer_get($a['id'])['status'], 'proposed');

/* ---------- Règles de transition ---------- */
echo "Cycle de vie\n";
check('donateur ne peut pas marquer « pris »', unsold_transition($a['id'], 'taken', 'donor'), false);
check('bénévole du point ne peut pas « donner »', unsold_transition($a['id'], 'donated', 'desk'), false);
check('proposé → pris interdit (doit passer par le point)', unsold_transition($a['id'], 'taken', 'admin'), false);
check('point : réception d\'un apport personnel', unsold_transition($a['id'], 'at_point', 'desk'), true);
check('réception en double refusée (déjà au point)', unsold_transition($a['id'], 'at_point', 'desk'), false);
check('visiteur se sert (pris)', unsold_transition($a['id'], 'taken', 'desk'), true);
check('annulation d\'un « pris » par erreur', unsold_transition($a['id'], 'at_point', 'desk'), true);
check('donateur retire son objet vendu', unsold_transition($c['id'], 'withdrawn', 'donor'), true);
check('donateur ne peut pas ressusciter', unsold_transition($c['id'], 'proposed', 'donor'), false);
check('admin peut restaurer', unsold_transition($c['id'], 'proposed', 'admin'), true);
check('journal : 4 entrées pour A', count(unsold_offer_log($a['id'])), 4);

check('confirmation invendu', unsold_confirm_offer($b['id']), true);
check('confirmation en double sans effet', unsold_confirm_offer($b['id']), false);

check('dépôt spontané au point', is_int($qa = unsold_offer_quick_add($eid, 'Sac de vêtements', 'vetements', 'M', '')), true);
check('… directement au point', unsold_offer_get($qa)['status'], 'at_point');
check('… donateur par défaut', unsold_offer_get($qa)['donor_name'], 'Dépôt sur place');
check('dépôt spontané sans titre refusé', unsold_offer_quick_add($eid, 'ab', 'autre', 'M'), null);
unsold_delete_offer($qa);

/* ---------- Tournée ---------- */
echo "Tournée à vélo cargo\n";
[$td, $terr] = unsold_tour_from_post(['volunteer_name' => 'Paul', 'volunteer_phone' => '0698765432', 'capacity' => '6']);
check('tournée valide', $terr, []);
$tid = unsold_tour_create($eid, $td);
$tour = unsold_tour_get($tid);
check('jeton de tournée', (bool) preg_match('/^[a-f0-9]{32}$/', $tour['token']));
check('affectation de 3 ramassages', unsold_tour_assign($tid, [$b['id'], $c['id'], $d['id']]), 3);
check('ré-affectation sans effet', unsold_tour_assign($tid, [$b['id']]), 0);
check('un objet déjà au point n\'est pas affectable', unsold_tour_assign($tid, [$a['id']]), 0);
$load = unsold_tour_load($tid);
check('charge : 4 (L) + 1 (S) + 2 (M) = 7 unités', $load['units_planned'], 7);
check('3 arrêts distincts', $load['stops'], 3);
check('ordre naturel des stands (A1, A10, B3)', array_column(unsold_tour_offers($tid), 'stand_ref'), ['A1', 'A10', 'B3']);
check('démarrage', unsold_tour_start($tid), true);
check('démarrage en double sans effet', unsold_tour_start($tid), false);
check('ramassage du carton', unsold_transition($b['id'], 'picked', 'tour', 'Paul'), true);
check('ramassage de la lampe', unsold_transition($c['id'], 'picked', 'tour', 'Paul'), true);
check('la tournée ne peut pas « donner »', unsold_transition($b['id'], 'donated', 'tour'), false);
check('vaisselle : plus dispo', unsold_transition($d['id'], 'withdrawn', 'tour', 'Paul'), true);
check('« plus dispo » rattrapable par la tournée', unsold_transition($d['id'], 'proposed', 'tour', 'Paul', ['keep_tour' => true]), true);
check('… et l\'objet reste dans la tournée', unsold_offer_get($d['id'])['tour_id'], $tid);
check('plus dispo (à nouveau)', unsold_transition($d['id'], 'withdrawn', 'tour', 'Paul'), true);
check('charge chargée : 4 + 1', unsold_tour_load($tid)['units_picked'], 5);
$res = unsold_tour_finish($tid, 'tour');
check('fin de tournée : 2 déposés, 0 libérés', $res, ['moved' => 2, 'released' => 0]);
check('carton désormais au point', unsold_offer_get($b['id'])['status'], 'at_point');
check('tournée terminée', unsold_tour_get($tid)['status'], 'done');
check('terminer deux fois → null', unsold_tour_finish($tid, 'tour'), null);
check('tournée terminée non supprimable', unsold_tour_delete($tid), false);

// Une tournée abandonnée en route rend les objets non visités
$e1o = $mk('Table basse', 'pickup', 'C5');
$e2o = $mk('Tapis', 'pickup', 'C6');
$tid2 = unsold_tour_create($eid, $td);
unsold_tour_assign($tid2, [$e1o['id'], $e2o['id']]);
unsold_transition($e1o['id'], 'picked', 'tour');
$res = unsold_tour_finish($tid2, 'tour');
check('fin partielle : 1 déposé, 1 libéré', $res, ['moved' => 1, 'released' => 1]);
check('objet non visité redevient affectable', unsold_offer_get($e2o['id'])['tour_id'], null);

// Suppression d'une tournée avec un objet déjà ramassé
$f = $mk('Fauteuil', 'pickup', 'D1');
$tid3 = unsold_tour_create($eid, $td);
unsold_tour_assign($tid3, [$f['id']]);
unsold_transition($f['id'], 'picked', 'tour');
check('suppression de tournée en cours', unsold_tour_delete($tid3), true);
check('objet ramassé non perdu : remis en « proposé »', unsold_offer_get($f['id'])['status'], 'proposed');
check('… et détaché de la tournée', unsold_offer_get($f['id'])['tour_id'], null);

/* ---------- Visibilité publique ---------- */
echo "Catalogue public\n";
$avail = unsold_available_offers($eid);
$titles = array_column($avail, 'title');
sort($titles);
check('seuls les objets au point sont visibles', $titles, ['Carton de jouets', 'Chaise en bois', 'Lampe', 'Table basse']);
check('aucune donnée personnelle exposée', array_intersect(['donor_name', 'donor_phone', 'donor_email', 'token', 'stand_ref'], array_keys($avail[0])), []);
check('recherche texte', array_column(unsold_available_offers($eid, '', 'chaise'), 'title'), ['Chaise en bois']);
check('le joker % est neutralisé', unsold_available_offers($eid, '', '%'), []);
check('filtre catégorie inconnu ignoré', count(unsold_available_offers($eid, 'nimporte')), count($avail));

/* ---------- Reliquat → donnerie ---------- */
echo "Reliquat et donnerie\n";
[$rd, $rerr] = unsold_recipient_from_post(['name' => 'Recyclerie du coin', 'email' => 'contact@recyclerie.example']);
check('structure valide', $rerr, []);
$rid = unsold_recipient_save(null, $rd);
check('remise sans objet → null', unsold_donation_create($eid, $rid, [], 'Léa', ''), null);
unsold_transition($a['id'], 'taken', 'desk'); // une chaise prise par un visiteur
$donId = unsold_donation_create($eid, $rid, null, 'Léa', 'Tout le reliquat');
check('remise créée', is_int($donId));
$donated = unsold_donation_offers($donId);
$dt = array_column($donated, 'title');
sort($dt);
check('le reliquat exclut l\'objet pris', $dt, ['Carton de jouets', 'Lampe', 'Table basse']);
check('statut donné', unsold_offer_get($b['id'])['status'], 'donated');
check('l\'objet pris reste pris', unsold_offer_get($a['id'])['status'], 'taken');
check('catalogue public vide après remise', unsold_available_offers($eid), []);
check('annuler un don (admin) le remet au point', unsold_transition($b['id'], 'at_point', 'admin'), true);
check('… et efface le lien à la remise', unsold_offer_get($b['id'])['donation_id'], null);
check('la structure utilisée est désactivée, pas supprimée', unsold_recipient_delete($rid), 'deactivated');
check('structure inutilisée supprimée', unsold_recipient_delete(unsold_recipient_save(null, $rd)), 'deleted');

/* ---------- Statistiques ---------- */
echo "Statistiques\n";
$st = unsold_event_stats($eid);
check('total cohérent avec la somme des statuts', $st['total'], array_sum($st['counts']));
check('objets sauvés = pris + donnés', $st['saved'], $st['counts']['taken'] + $st['counts']['donated']);
check('1 pris', $st['counts']['taken'], 1);
check('2 donnés', $st['counts']['donated'], 2);

/* ---------- Filtres admin ---------- */
echo "Filtres admin\n";
check('filtre statut', count(unsold_offers_list($eid, ['status' => 'taken'])), 1);
check('filtre ramassage non affecté', count(unsold_offers_list($eid, ['handover' => 'pickup', 'tour' => 'none', 'status' => 'proposed'])) >= 2);
check('recherche par emplacement', array_column(unsold_offers_list($eid, ['q' => 'D1']), 'title'), ['Fauteuil']);
check('joker % neutralisé côté admin', unsold_offers_list($eid, ['q' => '%']), []);
check('statut inconnu ignoré (pas d\'erreur SQL)', count(unsold_offers_list($eid, ['status' => "x' OR '1'='1"])) > 0);

/* ---------- Entrées POST ---------- */
echo "Entrées POST\n";
$_POST = ['a' => 'x', 'title' => ['evil'], 'ids' => ['3', ['nested'], '5']];
unsold_scalar_post(['ids']);
check('tableau inattendu retiré', isset($_POST['title']), false);
check('tableau déclaré conservé (imbriqués retirés)', $_POST['ids'], ['3', '5']);
check('scalaire conservé', $_POST['a'], 'x');
check('unsold_param ignore un tableau', unsold_param(['q' => ['x']], 'q', 'défaut'), 'défaut');

/* ---------- Utilitaires de sécurité ---------- */
echo "Sécurité\n";
check('CSV : formule neutralisée', unsold_csv_cell('=HYPERLINK("http://evil")'), "'=HYPERLINK(\"http://evil\")");
check('CSV : + neutralisé', unsold_csv_cell('+33 6'), "'+33 6");
check('CSV : texte normal inchangé', unsold_csv_cell('Chaise'), 'Chaise');
check('retour admin légitime accepté', unsold_safe_return('/admin/invendus-event.php?id=3&tab=tournees', '/x'), '/admin/invendus-event.php?id=3&tab=tournees');
check('retour avec recherche (espace encodé +)', unsold_safe_return('/admin/invendus-event.php?id=3&tab=propositions&f_q=marie+dupont%C3%A9', '/x'), '/admin/invendus-event.php?id=3&tab=propositions&f_q=marie+dupont%C3%A9');
check('redirection ouverte refusée (//)', unsold_safe_return('//evil.com/admin/invendus.php', '/x'), '/x');
check('redirection externe refusée', unsold_safe_return('https://evil.com', '/x'), '/x');
check('retour hors module refusé', unsold_safe_return('/admin/index.php', '/x'), '/x');
check('injection d\'en-tête refusée', unsold_safe_return("/admin/invendus.php\r\nSet-Cookie: a=b", '/x'), '/x');

/* ---------- Suppression d'édition ---------- */
echo "Suppression\n";
unsold_event_delete($eid);
check('édition supprimée', unsold_event_get($eid), null);
check('propositions supprimées en cascade', (int) db()->query('SELECT COUNT(*) FROM unsold_offers')->fetchColumn(), 0);
check('journal supprimé en cascade', (int) db()->query('SELECT COUNT(*) FROM unsold_log')->fetchColumn(), 0);
check('tournées supprimées en cascade', (int) db()->query('SELECT COUNT(*) FROM unsold_tours')->fetchColumn(), 0);

echo "\n$pass réussis, $fail échoués\n";
exit($fail > 0 ? 1 : 0);
