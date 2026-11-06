<?php

// Test de l'extension de galerie « Annulation & remboursements » : disponibilité (gm_*), aperçu sans écriture, annulation avec remboursement
// total / partiel / nul, ajustement exposant par exposant, relance ciblée, export CSV, réactivation.
// Usage : php scripts/test-annulation-galerie.php — n'écrit que dans un dossier temporaire (et le journal d'e-mails local, remis en l'état).

$tmp = sys_get_temp_dir() . '/brocante-test-ann-' . getmypid();
mkdir($tmp, 0777, true);
putenv('BROCANTE_GALLERIES_DIR=' . $tmp);
putenv('BROCANTE_GM_FILE=' . $tmp . '/modules-absent.json');   // réglages du portail : valeurs d'origine
putenv('BROCANTE_DB=' . $tmp . '/boutique.db');
$dataDir = __DIR__ . '/../data';
$mailLog = $dataDir . '/mail-outbox.log';
$dataDirExisted = is_dir($dataDir);
if (!$dataDirExisted) mkdir($dataDir, 0755);   // saas_mail() n'écrit son journal local que si le dossier existe
$mailLogExisted = is_file($mailLog);
$mailLogSize = $mailLogExisted ? filesize($mailLog) : 0;
register_shutdown_function(function () use ($tmp, $dataDir, $dataDirExisted, $mailLog, $mailLogExisted, $mailLogSize) {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
    // Le journal d'e-mails local reçoit les messages du test : on le remet comme on l'a trouvé.
    if (!$mailLogExisted) @unlink($mailLog);
    elseif (is_file($mailLog)) { $h = fopen($mailLog, 'r+'); ftruncate($h, $mailLogSize); fclose($h); }
    if (!$dataDirExisted) @rmdir($dataDir);
});
$_SERVER['HTTP_HOST'] = 'galeries.test';   // hôte local : les e-mails vont au journal, rien n'est envoyé
define('DB_PATH', $tmp . '/boutique.db');
(new PDO('sqlite:' . DB_PATH))->exec(file_get_contents(__DIR__ . '/../schema.sql'));
require_once __DIR__ . '/../includes/gallery-cancellation.php';
$_SESSION = [];

$checks = 0;
$failures = 0;
function check(string $label, $actual, $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual === $expected) { echo "  ✓ $label\n"; return; }
    $failures++;
    echo "  ✗ $label\n      attendu : " . var_export($expected, true) . "\n      obtenu  : " . var_export($actual, true) . "\n";
}
/** Texte des e-mails écrits dans le journal local depuis le début du test. */
function mails_since_start(): string
{
    global $mailLog, $mailLogSize;
    return is_file($mailLog) ? (string) substr((string) file_get_contents($mailLog), $mailLogSize) : '';
}

/** Lignes d'un CSV du registre (après le BOM), chacune en tableau de cellules. */
function csv_rows(string $csv): array
{
    $rows = [];
    foreach (preg_split('/\r?\n/', substr($csv, 3)) as $line) if ($line !== '') $rows[] = str_getcsv($line, ';', '"', '');
    return $rows;
}

/** Galerie de test : un vide-grenier de 10 places à 20 €, avec des exposants dans plusieurs situations. */
function make_gallery(string $slug, string $type = 'vide-grenier'): array
{
    $g = gallery_normalize($slug, [
        'name' => 'Vide-grenier ' . strtoupper($slug), 'type' => $type, 'members' => [],
        'admins' => [['email' => 'orga@example.test', 'name' => 'Orga', 'hash' => 'x', 'created' => 1]],
        'event' => ['title' => 'Grand vide-grenier', 'date' => date('Y-m-d', time() + 30 * 86400), 'place' => 'Place du marché', 'city' => 'Lyon',
            'mode' => 'places', 'places_count' => 10, 'price_cents' => 2000, 'published' => true, 'booking' => ['enabled' => true]],
    ]);
    gallery_save($g);
    return $g;
}
function add_request(string $slug, string $slot, string $status, string $name, ?int $price, string $email): void
{
    gb_update($slug, static function (array &$d) use ($slot, $status, $name, $price, $email) {
        $d['slots'][$slot] = ['status' => $status, 'name' => $name, 'email' => $email, 'phone' => '0600000000', 'price_cents' => $price,
            'token' => md5($slot . $name), 'kind' => 'particulier', 'requested' => time() - 3600];
    });
}
function seed(string $slug): void
{
    add_request($slug, 'ev1', 'taken', 'Alice Martin', 2000, 'alice@example.test');
    add_request($slug, 'ev2', 'taken', 'Bob Durand', 2000, 'bob@example.test');
    add_request($slug, 'ev3', 'taken', 'Chloé Pro', 3000, 'chloe@example.test');
    add_request($slug, 'ev4', 'pending', 'Denis Attente', 2000, 'denis@example.test');
    add_request($slug, 'ev5', 'taken', 'Emma Sans Tarif', null, 'emma@example.test');
    add_request($slug, 'ev6', 'taken', '=HYPERLINK("http://x")', 2000, 'pas-un-email');
}
function slots_file_hash(string $slug): string
{
    return md5((string) @file_get_contents(gb_file($slug)));
}
function refund_of(string $slug, string $slot): array
{
    return gb_read($slug)['slots'][$slot]['refund'] ?? [];
}

echo "Disponibilité (gm_*)\n";
$vg = make_gallery('vg-a');
$mar = gallery_normalize('mar-b', ['name' => 'Galerie B', 'type' => 'marchande', 'members' => []]);
check('proposée d\'office aux vide-greniers (option payante)', gm_state($vg, 'annulation')['mode'], 'option');
check('non proposée aux autres types', gm_state($mar, 'annulation')['mode'], 'off');
foreach (['puces', 'marche-de-noel'] as $t) {
    $other = gallery_normalize('t-' . substr($t, 0, 6), ['name' => 'Galerie ' . $t, 'type' => $t, 'members' => []]);
    check("proposée aux galeries de type « $t » (option payante)", gm_state($other, 'annulation')['mode'], 'option');
    check("…avec essai gratuit ($t)", [gm_start_trial($other, 'annulation'), gcn_available($other)], [null, true]);
}
$prod = gallery_normalize('mp-c', ['name' => 'Marché producteurs', 'type' => 'marche-producteurs', 'members' => []]);
check('proposée aux marchés de producteurs (option payante)', gm_state($prod, 'annulation')['mode'], 'option');
check('…avec essai gratuit (marche-producteurs)', [gm_start_trial($prod, 'annulation'), gcn_available($prod)], [null, true]);
check('inactive tant qu\'elle n\'est ni essayée ni souscrite', gcn_available($vg), false);
check('essai gratuit démarré', gm_start_trial($vg, 'annulation'), null);
check('active pendant l\'essai', gcn_available($vg), true);
check('essai : 14 jours', round((gm_state($vg, 'annulation')['until'] - time()) / 86400), 14.0);
check('refus de l\'essai pour un type qui ne la propose pas', is_string(gm_start_trial($mar, 'annulation')), true);
check('indépendante des autres extensions', gm_enabled($vg, 'buvette') || gm_enabled($vg, 'invendus') || gm_enabled($vg, 'comptage'), false);
check('l\'extension est dans la liste des modules', isset(gm_modules()['annulation']), true);
gallery_save($vg);
$vg = gallery_load('vg-a');
check('l\'état d\'essai survit à l\'enregistrement', gcn_available($vg), true);

echo "\nPourcentage de remboursement (gcn_pct_for)\n";
$rules = gcn_rules(['organizer_pct' => 100, 'force_majeure_pct' => 70, 'partial_pct' => 40]);
check('conditions de vente, décision de l\'organisateur', gcn_pct_for($rules, ['cause' => 'organisateur', 'refund_mode' => 'rules']), 100);
check('conditions de vente, météo', gcn_pct_for($rules, ['cause' => 'meteo', 'refund_mode' => 'rules']), 70);
check('conditions de vente, force majeure', gcn_pct_for($rules, ['cause' => 'force_majeure']), 70);
check('mode absent : conditions de vente', gcn_pct_for($rules, []), 100);
check('mode inconnu : conditions de vente', gcn_pct_for($rules, ['refund_mode' => 'gratuit', 'cause' => 'meteo']), 70);
check('cause inconnue : décision de l\'organisateur', gcn_pct_for($rules, ['cause' => 'ovni']), 100);
check('intégral malgré une météo à 70 %', gcn_pct_for($rules, ['refund_mode' => 'full', 'cause' => 'meteo']), 100);
check('aucun remboursement', gcn_pct_for($rules, ['refund_mode' => 'none']), 0);
check('partiel : pourcentage saisi', gcn_pct_for($rules, ['refund_mode' => 'partial', 'refund_pct' => 35]), 35);
check('partiel : plafonné à 100', gcn_pct_for($rules, ['refund_mode' => 'partial', 'refund_pct' => 250]), 100);
check('partiel : jamais négatif', gcn_pct_for($rules, ['refund_mode' => 'partial', 'refund_pct' => -8]), 0);
check('partiel sans valeur : pourcentage partiel des conditions', gcn_pct_for($rules, ['refund_mode' => 'partial']), 40);

echo "\nAperçu avant d'annuler (aucune écriture)\n";
seed('vg-a');
$before = slots_file_hash('vg-a');
$pv = gcn_preview($vg, ['refund_mode' => 'partial', 'refund_pct' => 60]);
check('pourcentage retenu', $pv['pct'], 60);
check('six demandes listées', count($pv['rows']), 6);
$byslot = array_column($pv['rows'], null, 'slot_id');
check('Alice : 60 % de 20 €', $byslot['ev1']['amount_cents'], 1200);
check('Chloé : 60 % de 30 €', $byslot['ev3']['amount_cents'], 1800);
check('demande en attente : rien à rendre', $byslot['ev4']['amount_cents'], 0);
check('exposant sans tarif : rien à rendre', $byslot['ev5']['amount_cents'], 0);
check('total de l\'aperçu', $pv['total_cents'], 1200 + 1200 + 1800 + 1200);
check('l\'aperçu n\'écrit pas dans les demandes', slots_file_hash('vg-a'), $before);
check('l\'aperçu n\'annule rien', ge_cancelled(gallery_event(gallery_load('vg-a'))), false);
check('aperçu intégral', gcn_preview($vg, ['refund_mode' => 'full'])['total_cents'], 2000 + 2000 + 3000 + 2000);
check('aperçu sans remboursement', gcn_preview($vg, ['refund_mode' => 'none'])['total_cents'], 0);

echo "\nAnnulation avec remboursement partiel\n";
[$err, $res] = gcn_cancel($vg, ['cause' => 'meteo', 'reason' => 'Orage violent', 'refund_mode' => 'partial', 'refund_pct' => 60,
    'refund_delay_days' => 21, 'refund_method' => 'Virement', 'notify' => true], 'orga@example.test');
check('annulation acceptée', $err, null);
$vg = gallery_load('vg-a');
check('événement annulé', ge_cancelled(gallery_event($vg)), true);
check('pourcentage enregistré', gallery_event($vg)['cancelled']['refund_pct'], 60);
check('délai enregistré', gallery_event($vg)['cancelled']['refund_delay_days'], 21);
check('5 exposants attribués', $res['ledger']['taken'], 5);
check('1 demande en attente', $res['ledger']['pending'], 1);
check('total à rembourser identique à l\'aperçu', $res['ledger']['due_cents'], $pv['total_cents']);
check('Alice : à rembourser 12 €', [refund_of('vg-a', 'ev1')['status'], refund_of('vg-a', 'ev1')['amount_cents']], ['due', 1200]);
check('attente : rien à rembourser', refund_of('vg-a', 'ev4')['status'], 'none');
check('sans tarif : rien à rembourser', refund_of('vg-a', 'ev5')['status'], 'none');
check('4 exposants alertés par e-mail (adresses valides)', $res['notified'], 5);
$mails = mails_since_start();
check('e-mail à Alice', str_contains($mails, 'À : alice@example.test'), true);
check('e-mail à la demande en attente', str_contains($mails, 'À : denis@example.test'), true);
check('aucun e-mail à une adresse invalide', str_contains($mails, 'pas-un-email'), false);
check('le montant est dans l\'e-mail', str_contains($mails, '12,00 €'), true);
check('récapitulatif aux administrateurs', str_contains($mails, 'À : orga@example.test'), true);
[$err2] = gcn_cancel($vg, ['cause' => 'meteo']);
check('annuler deux fois est refusé', is_string($err2), true);
check('la réservation est fermée', gb_booking($vg)['enabled'], false);

echo "\nRécapitulatif\n";
$sum = gcn_summary($vg);
check('à rembourser', $sum['due_cents'], 1200 + 1200 + 1800 + 1200);
check('rien de remboursé', $sum['paid_cents'], 0);
check('4 lignes à rembourser', $sum['due'], 4);

echo "\nAjustement du montant exposant par exposant\n";
check('Alice : 5 € seulement', gcn_set_amount('vg-a', 'ev1', 500, 'orga@example.test', 'Frais de dossier retenus'), null);
$r = refund_of('vg-a', 'ev1');
check('montant enregistré', $r['amount_cents'], 500);
check('pourcentage recalculé', $r['pct'], 25);
check('toujours à rembourser', $r['status'], 'due');
check('ligne marquée « ajustée »', $r['adjusted'], true);
check('note conservée', $r['note'], 'Frais de dossier retenus');
check('l\'alerte est à renvoyer', $r['notified'], 0);
check('Bob inchangé', refund_of('vg-a', 'ev2')['amount_cents'], 1200);
check('Bob : pas ajusté', empty(refund_of('vg-a', 'ev2')['adjusted']), true);
check('montant supérieur au tarif payé refusé', is_string(gcn_set_amount('vg-a', 'ev2', 2500, 'orga@example.test')), true);
check('…et la ligne n\'a pas bougé', refund_of('vg-a', 'ev2')['amount_cents'], 1200);
check('remboursement du tarif complet accepté', gcn_set_amount('vg-a', 'ev2', 2000, 'orga@example.test'), null);
check('…soit 100 %', refund_of('vg-a', 'ev2')['pct'], 100);
check('montant négatif refusé', is_string(gcn_set_amount('vg-a', 'ev1', -100)), true);
check('montant démesuré refusé', is_string(gcn_set_amount('vg-a', 'ev1', GCN_MAX_REFUND_CENTS + 1)), true);
check('demande en attente : refusé', is_string(gcn_set_amount('vg-a', 'ev4', 500)), true);
check('emplacement inconnu : refusé', is_string(gcn_set_amount('vg-a', 'ev9', 500)), true);
check('galerie inconnue : refusé', is_string(gcn_set_amount('nulle-part', 'ev1', 500)), true);
check('montant nul : « sans remboursement »', [gcn_set_amount('vg-a', 'ev3', 0, 'orga@example.test', 'Désistement tardif'), refund_of('vg-a', 'ev3')['status']], [null, 'waived']);
check('exposant sans tarif : remboursement libre accepté', gcn_set_amount('vg-a', 'ev5', 1500, 'orga@example.test', 'Payé sur place'), null);
check('…sans pourcentage calculable', [refund_of('vg-a', 'ev5')['amount_cents'], refund_of('vg-a', 'ev5')['pct']], [1500, 0]);

echo "\nRemboursement versé : plus d'ajustement tant qu'il n'est pas rouvert\n";
check('Alice remboursée', gcn_mark_refund('vg-a', 'ev1', 'paid', 'orga@example.test', 'VIR-001'), null);
check('date de paiement posée', refund_of('vg-a', 'ev1')['paid_at'] > 0, true);
check('ajustement refusé sur une ligne remboursée', is_string(gcn_set_amount('vg-a', 'ev1', 100)), true);
check('…et le montant versé est intact', refund_of('vg-a', 'ev1')['amount_cents'], 500);
check('on rouvre la ligne', gcn_mark_refund('vg-a', 'ev1', 'due', 'orga@example.test'), null);
check('ajustement de nouveau possible', gcn_set_amount('vg-a', 'ev1', 800, 'orga@example.test'), null);
check('statut invalide refusé', is_string(gcn_mark_refund('vg-a', 'ev1', 'inconnu')), true);

echo "\nRelance ciblée\n";
$vg = gallery_load('vg-a');
$sizeBefore = strlen(mails_since_start());
check('alerte à un seul exposant (Alice)', gcn_notify($vg, true, 'ev1'), 1);
$delta = substr(mails_since_start(), $sizeBefore);
check('le message part à Alice seule', substr_count($delta, 'À : '), 1);
check('…avec le montant ajusté', str_contains($delta, '8,00 €'), true);
check('pas de récapitulatif administrateur pour un envoi ciblé', str_contains($delta, 'À : orga@example.test') || str_contains($delta, 'récapitulatif des remboursements'), false);
check('…le contact de l\'organisateur figure dans le message d\'Alice', str_contains($delta, 'E-mail : orga@example.test'), true);
check('l\'alerte d\'Alice est datée', refund_of('vg-a', 'ev1')['notified'] > 0, true);
check('exposant sans e-mail valide : rien envoyé', gcn_notify($vg, true, 'ev6'), 0);
check('emplacement inconnu : rien envoyé', gcn_notify($vg, true, 'ev9'), 0);
$sizeBefore = strlen(mails_since_start());
$n = gcn_notify($vg);
$delta = substr(mails_since_start(), $sizeBefore);
check('relance générale : seulement les lignes ajustées depuis (Bob, Chloé, Emma)', $n, 3);
check('…pas Alice, déjà reprévenue', str_contains($delta, 'À : alice@example.test'), false);
check('…Bob oui', str_contains($delta, 'À : bob@example.test'), true);
check('rien de plus à envoyer ensuite', gcn_notify(gallery_load('vg-a')), 0);

echo "\nExport CSV\n";
$csv = gcn_csv($vg);
check('encodage UTF-8 avec BOM (Excel)', str_starts_with($csv, "\xEF\xBB\xBF"), true);
$rows = csv_rows($csv);
check('en-tête + 6 lignes', count($rows), 7);
check('16 colonnes partout', count(array_unique(array_map('count', $rows))), 1);
check('16 colonnes', count($rows[0]), 16);
$by = [];
foreach (array_slice($rows, 1) as $r) $by[$r[1]] = array_combine($rows[0], $r);
check('Alice : tarif, part et montant ajustés', [$by['Alice Martin']['Tarif payé (€)'], $by['Alice Martin']['Part remboursée (%)'], $by['Alice Martin']['Montant à rembourser (€)']], ['20,00', '40', '8,00']);
check('Alice : à rembourser, marquée ajustée', [$by['Alice Martin']['État'], $by['Alice Martin']['Montant ajusté']], ['À rembourser', 'oui']);
check('Alice : e-mail et téléphone', [$by['Alice Martin']['E-mail'], $by['Alice Martin']['Téléphone']], ['alice@example.test', '0600000000']);
check('Alice : alerte datée', $by['Alice Martin']['Alerte envoyée le'], date('d/m/Y'));
check('Bob : remboursé intégralement', [$by['Bob Durand']['Montant à rembourser (€)'], $by['Bob Durand']['Part remboursée (%)']], ['20,00', '100']);
check('Chloé : sans remboursement', [$by['Chloé Pro']['État'], $by['Chloé Pro']['Montant à rembourser (€)']], ['Sans remboursement', '0,00']);
check('Chloé : la note du motif', $by['Chloé Pro']['Référence / note'], 'Désistement tardif');
check('demande en attente : « Rien à payer »', [$by['Denis Attente']['Demande'], $by['Denis Attente']['État'], $by['Denis Attente']['Montant à rembourser (€)']], ['En attente', 'Rien à payer', '0,00']);
check('exposant sans tarif : montant libre', $by['Emma Sans Tarif']['Montant à rembourser (€)'], '15,00');
check('cellule dangereuse neutralisée', isset($by["'=HYPERLINK(\"http://x\")"]), true);
check('aucune cellule ne commence par =', count(array_filter(array_merge(...$rows), static fn ($c) => str_starts_with($c, '='))), 0);
check('moyen et délai de remboursement', [$by['Alice Martin']['Moyen de remboursement'], $by['Alice Martin']['Délai (jours)']], ['Virement', '21']);
$due = csv_rows(gcn_csv($vg, ['due']));
check('filtre « à rembourser » : 4 lignes + en-tête', count($due), 1 + 4);
check('…sans Chloé (sans remboursement)', in_array('Chloé Pro', array_column($due, 1), true), false);
check('filtre sans résultat : en-tête seul', count(csv_rows(gcn_csv($vg, ['paid']))), 1);

echo "\nJournal d'audit\n";
$audit = array_column(gb_audit_read('vg-a', 100), 'action');
check('annulation tracée', in_array('event_cancel', $audit, true), true);
check('ajustement tracé', in_array('refund_adjust', $audit, true), true);
check('remboursement versé tracé', in_array('refund_paid', $audit, true), true);
check('libellé de l\'ajustement', GB_AUDIT_LABELS['refund_adjust'] ?? '', 'Montant du remboursement ajusté');

echo "\nRéactivation\n";
check('réactivation', gcn_reopen($vg, 'orga@example.test'), null);
$vg = gallery_load('vg-a');
check('événement de nouveau ouvert', ge_cancelled(gallery_event($vg)), false);
check('lignes de remboursement retirées', refund_of('vg-a', 'ev1'), []);
check('réactiver un événement non annulé est refusé', is_string(gcn_reopen($vg)), true);
check('ajustement impossible hors annulation', is_string(gcn_set_amount('vg-a', 'ev1', 100)), true);
check('la réservation se rouvre', gb_booking($vg)['enabled'], true);

echo "\nAnnulation sans remboursement et intégrale\n";
make_gallery('vg-c');
seed('vg-c');
$c = gallery_load('vg-c');
[$e1, $r1] = gcn_cancel($c, ['cause' => 'organisateur', 'refund_mode' => 'none'], 'orga');
check('sans remboursement : rien à rendre', [$e1, $r1['ledger']['due_cents'], $r1['ledger']['due']], [null, 0, 0]);
check('…tout est « rien à rembourser »', gcn_summary(gallery_load('vg-c'))['none'], 6);
make_gallery('vg-d');
seed('vg-d');
[$e2, $r2] = gcn_cancel(gallery_load('vg-d'), ['cause' => 'organisateur', 'refund_mode' => 'full'], 'orga');
check('intégral : tout le tarif payé', [$e2, $r2['ledger']['due_cents']], [null, 2000 + 2000 + 3000 + 2000]);
check('annulation sans e-mail (case décochée) : personne prévenu', $r2['notified'], 0);

echo "\nUne galerie ne voit pas les remboursements d'une autre\n";
check('vg-a n\'a pas été touchée par vg-d', ge_cancelled(gallery_event(gallery_load('vg-a'))), false);
check('vg-d est annulée', ge_cancelled(gallery_event(gallery_load('vg-d'))), true);
check('ajustement refusé dans une galerie non annulée', is_string(gcn_set_amount('vg-a', 'ev1', 100)), true);

echo "\n" . ($failures === 0 ? "OK : $checks contrôles réussis.\n" : "ÉCHEC : $failures contrôle(s) sur $checks.\n");
exit($failures === 0 ? 0 : 1);
