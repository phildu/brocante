<?php
// Tests du module Comptage (logique métier), sur une base SQLite jetable.
//   php scripts/test-comptage.php
// N'écrit jamais dans brocante.db : la base de test vit dans le dossier
// temporaire système et est supprimée à la fin.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-cp-' . getmypid() . '.db';
putenv('BROCANTE_DB=' . $tmp);
register_shutdown_function(fn() => is_file($tmp) && unlink($tmp));
// Base jetable créée à partir de schema.sql : db() (config.php) attend les tables de base d'un commerce (settings, content…).
(new PDO('sqlite:' . $tmp))->exec(file_get_contents(__DIR__ . '/../schema.sql'));

require_once __DIR__ . '/../includes/comptage.php';
require_once __DIR__ . '/../includes/qrcode.php';

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
function tap(string $uid, int $gate, int $dir, int $qty, int $ts): array
{
    return ['op' => 'tap', 'uid' => $uid, 'gate' => $gate, 'dir' => $dir, 'qty' => $qty, 'ts' => $ts];
}

cp_boot();
date_default_timezone_set('Europe/Paris');
$T0 = strtotime('2026-05-10 09:00:00');

echo "Événements & postes\n";
check('aucun événement actif au départ', cp_active_event(), null);
$eid = cp_event_create('Vide-grenier de printemps', '2026-05-10', 'Place de la mairie', 200, true);
check('le premier événement devient actif', (int) cp_active_event()['id'], $eid);
$gates = cp_gates($eid);
check('un poste par défaut', count($gates), 1);
check('poste « Entrée principale »', $gates[0]['name'], 'Entrée principale');
$g1 = (int) $gates[0]['id'];
$g2 = cp_gate_add($eid, 'Parking');
check('ajout d\'un 2e poste', count(cp_gates($eid)), 2);
$eid2 = cp_event_create('Autre édition', null, '', 0, false);
check('le 2e événement ne vole pas l\'activation', (int) cp_active_event()['id'], $eid);
cp_set_active_event($eid2);
check('activation exclusive (nouvel actif)', (int) cp_active_event()['id'], $eid2);
cp_set_active_event($eid);
check('activation exclusive (retour)', (int) db()->query('SELECT COUNT(*) FROM cp_events WHERE is_active = 1')->fetchColumn(), 1);

echo "\nEnregistrement des appuis\n";
$now = $T0 + 4 * 3600; // 13h
$r = cp_record($eid, [
    tap('uid-0001-aaaa', $g1, 1, 1, $T0 + 60),
    tap('uid-0002-aaaa', $g1, 1, 3, $T0 + 120),   // une famille de 3
    tap('uid-0003-aaaa', $g2, 1, 2, $T0 + 180),
    tap('uid-0004-aaaa', $g1, -1, 1, $T0 + 3600),
], $now);
check('4 appuis appliqués', $r['applied'], 4);
$t = cp_totals($eid);
check('entrées = 1+3+2', $t['in'], 6);
check('sorties = 1', $t['out'], 1);
check('présents = 5', $t['present'], 5);
check('entrées du poste 1', $t['gates'][0]['in'], 4);
check('entrées du poste 2', $t['gates'][1]['in'], 2);

echo "\nIdempotence (renvoi après coupure réseau)\n";
$r = cp_record($eid, [tap('uid-0001-aaaa', $g1, 1, 1, $T0 + 60), tap('uid-0002-aaaa', $g1, 1, 3, $T0 + 120)], $now);
check('rejeu : rien d\'appliqué', $r['applied'], 0);
check('rejeu : totaux inchangés', cp_totals($eid)['in'], 6);

echo "\nAnnulation\n";
$r = cp_record($eid, [['op' => 'undo', 'uid' => 'uid-0002-aaaa']], $now);
check('annulation d\'un appui', $r['undone'], 1);
check('entrées après annulation = 3', cp_totals($eid)['in'], 3);
$r = cp_record($eid, [['op' => 'undo', 'uid' => 'uid-0002-aaaa']], $now);
check('annuler deux fois ne fait rien', $r['undone'], 0);
check('annulation d\'un uid inconnu sans effet', cp_record($eid, [['op' => 'undo', 'uid' => 'jamais-vu-xx']], $now)['undone'], 0);
check('annulation limitée à l\'événement', cp_record($eid2, [['op' => 'undo', 'uid' => 'uid-0001-aaaa']], $now)['undone'], 0);
check('l\'appui est toujours là', cp_totals($eid)['in'], 3);

echo "\nValidation\n";
$bad = [
    tap('uid-bad-0001', 999, 1, 1, $T0),           // poste inconnu
    tap('uid-bad-0002', $g1, 0, 1, $T0),           // sens invalide
    tap('uid-bad-0003', $g1, 1, 0, $T0),           // quantité nulle
    tap('uid-bad-0004', $g1, 1, 51, $T0),          // quantité trop grande
    tap('x', $g1, 1, 1, $T0),                      // uid trop court
    tap('uid avec espace', $g1, 1, 1, $T0),        // uid invalide
    ['op' => 'rm', 'uid' => 'uid-bad-0005'],       // opération inconnue
    'pas un tableau',
];
$r = cp_record($eid, $bad, $now);
check('8 opérations invalides rejetées', $r['rejected'], 8);
check('aucune appliquée', $r['applied'], 0);
check('poste d\'un autre événement refusé', cp_record($eid, [tap('uid-bad-0006', (int) cp_gates($eid2)[0]['id'], 1, 1, $T0)], $now)['rejected'], 1);
check('totaux intacts', cp_totals($eid)['in'], 3);

echo "\nHorodatage\n";
cp_record($eid, [tap('uid-ts-00001', $g1, 1, 1, $now - 40 * 3600)], $now);   // trop ancien
cp_record($eid, [tap('uid-ts-00002', $g1, 1, 1, $now + 3600)], $now);        // dans le futur
$ts = db()->query("SELECT ts FROM cp_taps WHERE uid IN ('uid-ts-00001','uid-ts-00002')")->fetchAll(PDO::FETCH_COLUMN);
check('heure absurde -> heure du serveur', [(int) $ts[0], (int) $ts[1]], [$now, $now]);
cp_record($eid, [tap('uid-ts-00003', $g1, 1, 1, $now - 2 * 3600)], $now);    // hors ligne depuis 2 h : gardée
check('heure plausible conservée (hors ligne)', (int) db()->query("SELECT ts FROM cp_taps WHERE uid = 'uid-ts-00003'")->fetchColumn(), $now - 2 * 3600);
cp_record($eid, [['op' => 'undo', 'uid' => 'uid-ts-00001'], ['op' => 'undo', 'uid' => 'uid-ts-00002'], ['op' => 'undo', 'uid' => 'uid-ts-00003']], $now);
check('retour à 3 entrées', cp_totals($eid)['in'], 3);

echo "\nPlafond d'un lot\n";
$many = [];
for ($i = 0; $i < CP_MAX_OPS + 50; $i++) $many[] = tap(sprintf('uid-many-%05d', $i), $g1, 1, 1, $T0 + 7200);
check('au plus ' . CP_MAX_OPS . ' opérations par envoi', cp_record($eid, $many, $now)['applied'], CP_MAX_OPS);
cp_reset($eid);

echo "\nStatistiques, pic et courbe\n";
// 09:00-09:29 : 10 entrées ; 09:30-09:59 : 20 entrées, 4 sorties ; 10:00-10:29 : rien ; 10:30-10:59 : 5 entrées puis 21 sorties
cp_record($eid, [
    tap('uid-s-000001', $g1, 1, 10, $T0 + 5 * 60),
    tap('uid-s-000002', $g1, 1, 12, $T0 + 31 * 60),
    tap('uid-s-000003', $g2, 1, 8, $T0 + 40 * 60),
    tap('uid-s-000004', $g1, -1, 4, $T0 + 50 * 60),
    tap('uid-s-000005', $g2, 1, 5, $T0 + 91 * 60),
    tap('uid-s-000006', $g1, -1, 21, $T0 + 100 * 60),
], $T0 + 3 * 3600);
$s = cp_stats($eid);
check('visiteurs = 35', $s['visitors'], 35);
check('sorties = 25', $s['exits'], 25);
check('présents = 10', $s['present'], 10);
check('pic = 31 (26 à 09:50, puis +5 avant les 21 sorties)', $s['peak'], 31);
check('pic à 10:31', cp_hm($s['peak_at']), '10:31');
check('premier appui 09:05', cp_hm($s['first']), '09:05');
check('dernier appui 10:40', cp_hm($s['last']), '10:40');
check('6 appuis', $s['taps'], 6);

$tl = cp_timeline($eid, 30);
check('4 tranches (dont une vide)', count($tl), 4);
check('étiquettes', array_column($tl, 'label'), ['09:00', '09:30', '10:00', '10:30']);
check('entrées par tranche', array_column($tl, 'in'), [10, 20, 0, 5]);
check('sorties par tranche', array_column($tl, 'out'), [0, 4, 0, 21]);
check('présents en fin de tranche', array_column($tl, 'present'), [10, 26, 26, 10]);
check('tranches d\'une heure', array_column(cp_timeline($eid, 60), 'in'), [30, 5]);
check('tranches de 15 min : de 09:00 à 10:30 = 7 tranches', count(cp_timeline($eid, 15)), 7);
check('courbe vide sans appui', cp_timeline($eid2, 30), []);

echo "\nPrésents jamais négatifs\n";
cp_record($eid2, [tap('uid-neg-00001', (int) cp_gates($eid2)[0]['id'], -1, 5, $T0)], $T0 + 100);
check('plus de sorties que d\'entrées -> 0 présent', cp_totals($eid2)['present'], 0);
check('stats idem', cp_stats($eid2)['present'], 0);
check('pic jamais négatif', cp_stats($eid2)['peak'], 0);

echo "\nCorrection manuelle & remise à zéro\n";
check('correction +50 entrées', cp_correction($eid, $g1, 1, 50, $T0 + 200 * 60), true);
check('totaux après correction', cp_totals($eid)['in'], 85);
check('correction : poste inconnu refusé', cp_correction($eid, 9999, 1, 5), false);
check('correction : quantité nulle refusée', cp_correction($eid, $g1, 1, 0), false);
check('une correction n\'est pas annulable par un téléphone', cp_record($eid, [['op' => 'undo', 'uid' => db()->query("SELECT uid FROM cp_taps WHERE kind = 'correction'")->fetchColumn()]], $T0 + 3 * 3600)['undone'], 0);
check('remise à zéro : nombre d\'appuis effacés', cp_reset($eid), 7);
check('remise à zéro : plus rien', cp_totals($eid)['in'], 0);
check('remise à zéro : les postes restent', count(cp_gates($eid)), 2);

echo "\nJauge\n";
check('sans capacité -> null', cp_gauge(50, 0), null);
check('50/200 = ok', cp_gauge(50, 200)['level'], 'ok');
check('140/200 = warn', cp_gauge(140, 200)['level'], 'warn');
check('180/200 = full', cp_gauge(180, 200)['level'], 'full');
check('250/200 : 125 %', cp_gauge(250, 200)['pct'], 125);

echo "\nSuppression en cascade\n";
cp_record($eid, [tap('uid-del-00001', $g2, 1, 4, $T0)], $T0 + 100);
cp_gate_delete($g2);
check('supprimer un poste supprime ses appuis', cp_totals($eid)['in'], 0);
cp_record($eid, [tap('uid-del-00002', $g1, 1, 4, $T0)], $T0 + 100);
cp_event_delete($eid);
check('supprimer un événement supprime ses appuis', (int) db()->query('SELECT COUNT(*) FROM cp_taps WHERE event_id = ' . $eid)->fetchColumn(), 0);
check('… et ses postes', count(cp_gates($eid)), 0);

echo "\nExports CSV\n";
$e3 = cp_event_create('=cmd|calc', '2026-05-11', '', 0, true);
$g3 = (int) cp_gates($e3)[0]['id'];
cp_gate_rename($g3, '=HYPERLINK("http://x")');
cp_record($e3, [tap('uid-csv-00001', $g3, 1, 2, $T0), tap('uid-csv-00002', $g3, -1, 1, $T0 + 60)], $T0 + 100);
$csv = cp_export_taps($e3);
check('BOM UTF-8 pour Excel', str_starts_with($csv, "\u{FEFF}"), true);
check('séparateur point-virgule', str_contains($csv, 'Heure;Poste;Sens;Nombre;Origine'), true);
check('formule neutralisée dans le nom de poste', str_contains($csv, "'=HYPERLINK"), true);
check('aucune cellule ne commence par =', (bool) preg_match('/(^|;)=/m', $csv), false);
$csv2 = cp_export_timeline($e3, 30);
check('courbe : 1 ligne de données', count(array_filter(explode("\n", trim($csv2)))), 2);
check('courbe : entrées/sorties/présents', str_contains($csv2, '09:00;2;1;1'), true);
check('slug d\'export sans caractères dangereux', preg_match('/^[a-z0-9-]*$/', slugify('=cmd|calc / ../x')) === 1, true);

echo "\nConfiguration vue par le téléphone\n";
$ev = cp_event_get($e3);
$h1 = cp_config_hash($ev, cp_gates($e3));
cp_gate_add($e3, 'Nouveau');
check('ajouter un poste change l\'empreinte', cp_config_hash($ev, cp_gates($e3)) !== $h1, true);

echo "\nAccès bénévoles\n";
check('pas de code au départ', cp_volunteer_code(), '');
check('connexion refusée sans code défini', str_contains((string) cp_volunteer_login('x'), 'pas encore ouvert'), true);
cp_set_setting('volunteer_code', 'secret26');
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ($i = 0; $i < CP_LOGIN_MAX_ATTEMPTS; $i++) cp_volunteer_login('mauvais');
check('blocage après trop d\'essais', cp_volunteer_login('secret26'), 'Trop d\'essais. Réessayez dans quelques minutes.');
db()->exec('DELETE FROM cp_login_attempts');
check('mauvais code', cp_volunteer_login('nope'), 'Code incorrect.');
check('formatage des nombres', cp_n(12345), "12\u{202F}345");
check('durée', cp_duration(8100), '2 h 15');
check('durée courte', cp_duration(2400), '40 min');
check('date en français', cp_fr_date('2026-05-10'), 'dimanche 10 mai 2026');

echo "\nQR code du lien\n";
$qr = QrCode::encodeText('https://brocante.arrimage.com/comptage/');
$flat = '';
foreach ($qr->modules as $row) $flat .= implode('', array_map(fn($b) => $b ? '1' : '0', $row)) . "\n";
check('URL de 39 caractères -> version 3 (29×29)', [$qr->version, $qr->size], [3, 29]);
// Empreinte relevée sur une matrice identique, module pour module, à celle de la
// bibliothèque Python « qrcode » (mode octets, niveau M) : toute régression de
// l'encodeur (Reed-Solomon, masque, format…) la change.
check('matrice identique à la référence', sha1($flat), 'b8cef817a20d2808d56a6ec2023d9ff35ded13a1');
check('repère de position en haut à gauche', substr($flat, 0, 7), '1111111');
check('module sombre fixe', $qr->modules[$qr->size - 8][8], true);
check('texte court -> version 1', QrCode::encodeText('HELLO')->version, 1);
check('213 octets -> version 10', QrCode::encodeText(str_repeat('A', 213))->version, 10);
$tooLong = false;
try { QrCode::encodeText(str_repeat('A', 214)); } catch (InvalidArgumentException $e) { $tooLong = true; }
check('214 octets refusés', $tooLong, true);
$svg = qr_svg('https://brocante.arrimage.com/comptage/', 'Lien <test> & "ok"');
check('SVG : balise et zone de silence (29 + 2×4 = 37)', str_contains($svg, 'viewBox="0 0 37 37"'), true);
check('SVG : libellé échappé', str_contains($svg, 'aria-label="Lien &lt;test&gt; &amp; &quot;ok&quot;"'), true);
check('SVG : noir sur blanc imposé', str_contains($svg, 'fill="#fff"') && str_contains($svg, 'fill="#000"'), true);
check('SVG : document XML valide', (new DOMDocument())->loadXML($svg) !== false, true);

echo "\n" . ($failures ? "ÉCHEC : $failures / $checks vérifications\n" : "OK : $checks vérifications\n");
exit($failures ? 1 : 0);
