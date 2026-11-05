<?php
/**
 * Test de la logique du module « Impression » (sans serveur web ni réseau).
 *
 *   php scripts/test-impression.php
 *
 * - base SQLite jetable : brocante.db n'est jamais touchée ;
 * - dossier de clés jetable : .secrets/ n'est jamais touché ;
 * - transport HTTP simulé (PR_HTTP_HANDLER) : aucun appel réel aux fournisseurs.
 *   Les réponses simulées reprennent la forme des exemples de leur documentation.
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Accessible uniquement en ligne de commande.');
}

$tmp = sys_get_temp_dir() . '/brocante-test-impression-' . getmypid();
@mkdir($tmp, 0700, true);
define('DB_PATH', $tmp . '/test.db');
define('PR_SECRETS_DIR', $tmp . '/secrets');
$storedFiles = [];
register_shutdown_function(function () use ($tmp, &$storedFiles) {
    foreach ($storedFiles as $f) @unlink($f);
    foreach (glob($tmp . '/{,secrets/}*', GLOB_BRACE) ?: [] as $f) is_file($f) && @unlink($f);
    @rmdir($tmp . '/secrets');
    @rmdir($tmp);
});

require_once __DIR__ . '/../includes/impression.php';

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

/* ---------- faux fournisseurs ---------- */
$calls = [];
$script = [];   // [ 'METHOD substring-of-url' => réponse ]
$GLOBALS['PR_HTTP_HANDLER'] = function (string $method, string $url, array $headers, ?array $json) use (&$calls, &$script) {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'json' => $json];
    foreach ($script as $pattern => $resp) {
        [$m, $needle] = explode(' ', $pattern, 2);
        if ($m === $method && str_contains($url, $needle)) {
            $resp = is_callable($resp) ? $resp($json) : $resp;
            return ['status' => $resp['status'] ?? 200, 'body' => $resp['body'] ?? null, 'raw' => json_encode($resp['body'] ?? null), 'error' => $resp['error'] ?? ''];
        }
    }
    return ['status' => 599, 'body' => null, 'raw' => 'no scripted response for ' . $method . ' ' . $url, 'error' => ''];
};
function lastCall(): array { global $calls; return end($calls) ?: []; }
function resetCalls(): void { global $calls, $script; $calls = []; $script = []; }

$gelatoQuote = ['body' => ['orderReferenceId' => 'x', 'quotes' => [[
    'id' => 'q1', 'itemReferenceIds' => ['item-1'], 'fulfillmentCountry' => 'FR',
    'shipmentMethods' => [
        ['name' => 'Colissimo', 'shipmentMethodUid' => 'colissimo_standard', 'price' => 4.9, 'currency' => 'EUR', 'minDeliveryDays' => 3, 'maxDeliveryDays' => 5, 'type' => 'normal'],
        ['name' => 'Chronopost', 'shipmentMethodUid' => 'chronopost_express', 'price' => 12.5, 'currency' => 'EUR', 'minDeliveryDays' => 1, 'maxDeliveryDays' => 2, 'type' => 'express'],
    ],
    'products' => [['itemReferenceId' => 'item-1', 'productUid' => 'p', 'quantity' => 250, 'price' => 21.35, 'currency' => 'EUR']],
]]]];
$prodigiQuote = fn(string $m, string $items, string $ship) => ['body' => ['outcome' => 'Created', 'quotes' => [[
    'shipmentMethod' => $m, 'costSummary' => ['items' => ['amount' => $items, 'currency' => 'EUR'], 'shipping' => ['amount' => $ship, 'currency' => 'EUR']],
]]]];

/* ======================================================================= */
echo "Utilitaires\n";
check('12.47 → 1247 centimes', pr_cents('12.47'), 1247);
check('"12,50" → 1250 centimes', pr_cents('12,50'), 1250);
check('arrondi flottant 19.99 → 1999', pr_cents(19.99), 1999);
check('formatage euros', pr_eur(123456), "1\u{202F}234,56\u{00A0}€");
check('null → tiret', pr_eur(null), '—');
check('taille en Ko', pr_size_label(348160), "340\u{00A0}Ko");
check('taille en Mo', pr_size_label(13002342), "12,4\u{00A0}Mo");

/* ======================================================================= */
echo "Schéma et données de départ\n";
pr_boot();
check('7 fournisseurs au départ', (int) db()->query('SELECT COUNT(*) FROM pr_suppliers')->fetchColumn(), 7);
check('12 produits au départ', (int) db()->query('SELECT COUNT(*) FROM pr_products')->fetchColumn(), 12);
check('seule référence d\'exemple : Prodigi', (int) db()->query('SELECT COUNT(*) FROM pr_product_map')->fetchColumn(), 1);
pr_seed_defaults();
check('chargement idempotent (pas de doublons)', (int) db()->query('SELECT COUNT(*) FROM pr_suppliers')->fetchColumn(), 7);
check('2 fournisseurs connectés', (int) db()->query("SELECT COUNT(*) FROM pr_suppliers WHERE kind = 'api'")->fetchColumn(), 2);
check('mode test par défaut', pr_mode(), 'test');
check('plafond par défaut 150 €', pr_max_order_cents(), 15000);

$gelato = (int) db()->query("SELECT id FROM pr_suppliers WHERE slug = 'gelato'")->fetchColumn();
$prodigi = (int) db()->query("SELECT id FROM pr_suppliers WHERE slug = 'prodigi'")->fetchColumn();
$exaprint = (int) db()->query("SELECT id FROM pr_suppliers WHERE slug = 'exaprint'")->fetchColumn();
$affiche = (int) db()->query("SELECT id FROM pr_products WHERE slug = 'affiche-40x60'")->fetchColumn();
$carte = (int) db()->query("SELECT id FROM pr_products WHERE slug = 'carte-visite-85x55'")->fetchColumn();

/* ======================================================================= */
echo "Clés API\n";
check('clé absente → vide', pr_secret_get('gelato'), '');
check('nom de clé inconnu refusé', pr_secret_set('../../etc/passwd', 'x'), false);
check('enregistrement d\'une clé', pr_secret_set('gelato', "  abc-123 \n"), true);
check('clé relue sans espaces', pr_secret_get('gelato'), 'abc-123');
check('fichier de clé en 0600', substr(sprintf('%o', fileperms(PR_SECRETS_DIR . '/impression_gelato.key')), -4), '0600');

/* ======================================================================= */
echo "Validation de la commande\n";
$ok = ['title' => 'Cartes asso', 'product_id' => $carte, 'quantity' => '250', 'rec_first' => 'Marie', 'rec_last' => 'Dupont',
    'rec_address1' => '12 rue des Lilas', 'rec_postcode' => '80000', 'rec_city' => 'Amiens', 'rec_country' => 'fr', 'rec_email' => 'marie@example.org'];
[$clean, $err] = pr_job_input($ok);
check('commande valide', $err, []);
check('pays mis en majuscules', $clean['rec_country'], 'FR');
check('titre manquant refusé', count(pr_job_input(array_merge($ok, ['title' => '']))[1]), 1);
check('quantité 0 refusée', count(pr_job_input(array_merge($ok, ['quantity' => '0']))[1]), 1);
check('quantité énorme refusée', count(pr_job_input(array_merge($ok, ['quantity' => '999999']))[1]), 1);
check('produit inconnu refusé', count(pr_job_input(array_merge($ok, ['product_id' => 9999]))[1]), 1);
check('e-mail invalide refusé', count(pr_job_input(array_merge($ok, ['rec_email' => 'pas-un-mail']))[1]), 1);
check('pays à 3 lettres refusé', count(pr_job_input(array_merge($ok, ['rec_country' => 'FRA']))[1]), 1);
check('téléphone invalide refusé', count(pr_job_input(array_merge($ok, ['rec_phone' => 'abc']))[1]), 1);
check('adresse vide refusée', count(pr_job_input(array_merge($ok, ['rec_address1' => '']))[1]), 1);

/* ======================================================================= */
echo "Fichiers à imprimer\n";
$pdf = $tmp . '/ok.pdf'; file_put_contents($pdf, "%PDF-1.4\n%fake\n");
$png = $tmp . '/ok.png';
$im = imagecreatetruecolor(1000, 600); imagepng($im, $png);
$fake = $tmp . '/fake.pdf'; file_put_contents($fake, 'hello');
$exe = $tmp . '/x.exe'; file_put_contents($exe, 'MZ');
$notimg = $tmp . '/notimg.png'; file_put_contents($notimg, 'pas une image');
check('PDF valide accepté', pr_file_inspect($pdf, 'a.pdf', filesize($pdf))['ok']);
check('PNG valide accepté + dimensions', [pr_file_inspect($png, 'a.png', filesize($png))['width'], pr_file_inspect($png, 'a.png', filesize($png))['height']], [1000, 600]);
check('faux PDF refusé', pr_file_inspect($fake, 'a.pdf', filesize($fake))['ok'], false);
check('.exe refusé', pr_file_inspect($exe, 'a.exe', filesize($exe))['ok'], false);
check('faux PNG refusé', pr_file_inspect($notimg, 'a.png', filesize($notimg))['ok'], false);
check('fichier trop lourd refusé', pr_file_inspect($pdf, 'a.pdf', PR_MAX_FILE_BYTES + 1)['ok'], false);
check('fichier vide refusé', pr_file_inspect($pdf, 'a.pdf', 0)['ok'], false);
check('double extension « .pdf.php » refusée', pr_file_inspect($pdf, 'a.pdf.php', filesize($pdf))['ok'], false);

$prod = pr_product_get($affiche); // 400 × 600 mm
$dpi = fn($w, $h) => pr_dpi_check(['file_w' => $w, 'file_h' => $h], $prod);
check('4800×7200 px sur 40×60 cm → ok', $dpi(4800, 7200)['level'], 'ok');
check('1000×600 px sur 40×60 cm → bad', $dpi(1000, 600)['level'], 'bad');
check('orientation indifférente', $dpi(7200, 4800)['dpi'], $dpi(4800, 7200)['dpi']);
check('PDF (sans dimensions) → non évaluable', pr_dpi_check(['file_w' => null, 'file_h' => null], $prod), null);
check('goodies sans format → non évaluable', pr_dpi_check(['file_w' => 100, 'file_h' => 100], ['width_mm' => null, 'height_mm' => null, 'category' => 'goodies']), null);

$stored = pr_file_store($png, pr_file_inspect($png, 'a.png', filesize($png)), false);
$storedFiles[] = dirname(__DIR__) . '/' . $stored;
check('fichier stocké sous un nom aléatoire', (bool) preg_match('#^uploads/impression/[a-f0-9]{32}\.png$#', (string) $stored));
$jobFile = ['path' => $stored, 'name' => 'a.png', 'mime' => 'image/png', 'bytes' => filesize($png), 'width' => 1000, 'height' => 600];

check('site public en https détecté (SITE_URL de prod)', pr_site_is_public());

/* ======================================================================= */
echo "Création d'une commande et devis (Gelato + Prodigi)\n";
$jobId = pr_job_create($clean, $jobFile);
$job = pr_job_get($jobId);
check('référence PR-0001', $job['ref'], 'PR-0001');
check('statut brouillon, mode test mémorisé', [$job['status'], (int) $job['test_mode']], ['draft', 1]);
check('URL publique du fichier', pr_job_file_url($job), rtrim(SITE_URL, '/') . '/' . $stored);

// 1) Aucune clé Prodigi, aucune référence Gelato pour cette carte de visite.
resetCalls();
$res = pr_job_quote_all($jobId);
check('sans références : 0 offre', $res['offers'], 0);
check('2 fournisseurs connectés signalés en échec', $res['failures'], 2);
check('aucun appel réseau sans clé / sans référence', count($calls), 0);
check('statut inchangé sans offre', pr_job_get($jobId)['status'], 'draft');

// 2) Référence + clé Gelato → devis.
check('référence Gelato enregistrée', pr_map_save($carte, $gelato, 'cards_pf_85x55_test'), null);
$script = ['POST orders:quote' => $gelatoQuote];
$res = pr_job_quote_all($jobId);
check('Gelato : 2 offres', $res['offers'], 2);
$q = lastCall();
check('Gelato : URL de devis', $q['url'], 'https://order.gelatoapis.com/v4/orders:quote');
check('Gelato : clé en en-tête X-API-KEY', in_array('X-API-KEY: abc-123', $q['headers'], true));
check('Gelato : productUid envoyé', $q['json']['products'][0]['productUid'], 'cards_pf_85x55_test');
check('Gelato : quantité envoyée', $q['json']['products'][0]['quantity'], 250);
check('Gelato : devise EUR', $q['json']['currency'], 'EUR');
check('Gelato : fichier joint par URL', str_starts_with($q['json']['products'][0]['files'][0]['url'], 'https://'));
check('Gelato : pays destinataire', $q['json']['recipient']['country'], 'FR');
$quotes = pr_quotes_for_job($jobId);
$offers = array_values(array_filter($quotes, fn($x) => $x['total_cents'] !== null && $x['supplier_adapter'] === 'gelato'));
check('Gelato : total = articles + livraison (21,35 + 4,90)', (int) $offers[0]['total_cents'], 2625);
check('Gelato : tri par prix croissant', (int) $offers[1]['total_cents'], 3385);
check('Gelato : délai lu', [(int) $offers[0]['days_min'], (int) $offers[0]['days_max']], [3, 5]);
check('statut → devis comparés', pr_job_get($jobId)['status'], 'quoted');

// 3) Prodigi (mode test = sandbox) sur l'affiche.
$prodJob = pr_job_create(array_merge($clean, ['title' => 'Affiche', 'product_id' => $affiche, 'quantity' => 2]), $jobFile);
pr_secret_set('prodigi_sandbox', 'sandbox-key');
resetCalls();
$script = [
    'POST /quotes' => function ($j) use ($prodigiQuote) {
        return match ($j['shippingMethod']) {
            'Budget' => $prodigiQuote('Budget', '18.00', '4.50'),
            'Standard' => $prodigiQuote('Standard', '18.00', '7.00'),
            default => $prodigiQuote('Express', '18.00', '19.00'),
        };
    },
];
$res = pr_job_quote_all($prodJob);
// Gelato n'a pas de référence pour l'affiche → échec signalé ; Prodigi → 3 offres.
check('Prodigi : 3 offres', $res['offers'], 3);
check('Prodigi : hôte sandbox en mode test', str_starts_with($calls[0]['url'], 'https://api.sandbox.prodigi.com/v4.0/quotes'));
check('Prodigi : clé sandbox en en-tête X-API-Key', in_array('X-API-Key: sandbox-key', $calls[0]['headers'], true));
check('Prodigi : SKU et copies', [$calls[0]['json']['items'][0]['sku'], $calls[0]['json']['items'][0]['copies']], ['GLOBAL-FAP-16x24', 2]);
check('Prodigi : pays de destination', $calls[0]['json']['destinationCountryCode'], 'FR');
check('Prodigi : attributes encodé en objet {} (pas [])', str_contains(json_encode($calls[0]['json']), '"attributes":{}'));
$pq = array_values(array_filter(pr_quotes_for_job($prodJob), fn($x) => $x['total_cents'] !== null));
check('Prodigi : moins cher en premier (22,50 €)', (int) $pq[0]['total_cents'], 2250);
check('Prodigi : Express 37,00 €', (int) $pq[2]['total_cents'], 3700);

// 4) Clé refusée → un seul essai, message clair.
resetCalls();
pr_secret_set('prodigi_sandbox', 'mauvaise');
$script = ['POST /quotes' => ['status' => 401, 'body' => ['message' => 'Unauthorized']]];
pr_job_quote_all($prodJob);
$prodCalls = count(array_filter($calls, fn($c) => str_contains($c['url'], 'prodigi')));
check('clé refusée : un seul appel Prodigi (pas 3)', $prodCalls, 1);
$err401 = array_values(array_filter(pr_quotes_for_job($prodJob), fn($x) => $x['supplier_adapter'] === 'prodigi'))[0]['error'];
check('clé refusée : message lisible', str_contains($err401, 'Clé API refusée'));
pr_secret_set('prodigi_sandbox', 'sandbox-key');

/* ======================================================================= */
echo "Commande chez Gelato (mode test = brouillon)\n";
resetCalls();
$script = ['POST orders:quote' => $gelatoQuote];
pr_job_quote_all($jobId);
$chosen = array_values(array_filter(pr_quotes_for_job($jobId), fn($x) => $x['total_cents'] !== null))[0];
$gelatoOrderResp = ['body' => [
    'id' => 'gel-order-1', 'orderType' => 'draft', 'fulfillmentStatus' => 'draft', 'currency' => 'EUR',
    'receipts' => [['total' => 26.25]],
]];
$script = ['POST /v4/orders' => $gelatoOrderResp];
$r = pr_job_order($jobId, (int) $chosen['id']);
check('commande acceptée', $r, ['ok' => true, 'error' => '']);
$o = lastCall();
check('Gelato : orderType = draft en mode test', $o['json']['orderType'], 'draft');
check('Gelato : méthode de livraison choisie', $o['json']['shipmentMethodUid'], 'colissimo_standard');
check('Gelato : référence de commande', $o['json']['orderReferenceId'], 'PR-0001');
check('Gelato : adresse de livraison', [$o['json']['shippingAddress']['firstName'], $o['json']['shippingAddress']['postCode'], $o['json']['shippingAddress']['email']], ['Marie', '80000', 'marie@example.org']);
$job = pr_job_get($jobId);
check('statut → commandée', $job['status'], 'ordered');
check('n° de commande fournisseur mémorisé', $job['provider_order_id'], 'gel-order-1');
check('canal api + mode test mémorisés', [$job['channel'], (int) $job['test_mode']], ['api', 1]);
check('prix estimé conservé', (int) $job['estimate_cents'], 2625);
check('montant confirmé par le fournisseur', (int) $job['charged_cents'], 2625);

resetCalls();
$r = pr_job_order($jobId, (int) $chosen['id']);
check('re-commande refusée (pas de doublon)', $r['ok'], false);
check('aucun appel réseau pour un doublon', count($calls), 0);
check('re-devis refusé après commande', pr_job_quote_all($jobId)['ok'], false);

// Suivi
$script = ['GET /v4/orders/gel-order-1' => ['body' => [
    'id' => 'gel-order-1', 'fulfillmentStatus' => 'shipped',
    'shipment' => ['packages' => [['trackingCode' => 'XX123', 'trackingUrl' => 'https://suivi.example/XX123']]],
    'receipts' => [['total' => 26.25]],
]]];
check('synchronisation réussie', pr_job_sync($jobId)['ok']);
$job = pr_job_get($jobId);
check('statut → expédiée', $job['status'], 'shipped');
check('suivi colis récupéré', [$job['tracking_code'], $job['tracking_url']], ['XX123', 'https://suivi.example/XX123']);
check('journal alimenté', count(pr_job_log($jobId)) >= 4);

// Statuts
foreach (['created' => 'ordered', 'passed' => 'ordered', 'draft' => 'ordered', 'in_production' => 'in_production', 'printed' => 'in_production',
    'shipped' => 'shipped', 'delivered' => 'delivered', 'canceled' => 'cancelled', 'failed' => 'failed'] as $raw => $exp) {
    check("Gelato « $raw » → $exp", PrGelato::mapStatus($raw), $exp);
}

// Annulation refusée (409) puis acceptée
$script = ['POST /v4/orders/gel-order-1:cancel' => ['status' => 409, 'body' => ['message' => 'printed']]];
$r = pr_job_cancel($jobId);
check('annulation Gelato refusée si imprimée', $r['ok'], false);
check('statut inchangé après refus', pr_job_get($jobId)['status'], 'shipped');
$script = ['POST /v4/orders/gel-order-1:cancel' => ['status' => 200, 'body' => []]];
check('annulation Gelato acceptée', pr_job_cancel($jobId)['ok']);
check('statut → annulée', pr_job_get($jobId)['status'], 'cancelled');

/* ======================================================================= */
echo "Commande chez Prodigi (sandbox) et garde-fous\n";
resetCalls();
$script = ['POST /quotes' => fn($j) => $prodigiQuote($j['shippingMethod'], '18.00', $j['shippingMethod'] === 'Budget' ? '4.50' : '9.00')];
pr_job_quote_all($prodJob);
$pChosen = array_values(array_filter(pr_quotes_for_job($prodJob), fn($x) => $x['total_cents'] !== null && $x['supplier_adapter'] === 'prodigi'))[0];

// Plafond de sécurité
pr_setting_set('max_order_cents', '2000');
$r = pr_job_order($prodJob, (int) $pChosen['id']);
check('plafond de sécurité respecté', $r['ok'], false);
check('message de plafond lisible', str_contains($r['error'], 'plafond'));
pr_setting_set('max_order_cents', '15000');

// Clé absente
rename(PR_SECRETS_DIR . '/impression_prodigi_sandbox.key', PR_SECRETS_DIR . '/hidden.key');
check('clé absente → refus clair', str_contains(pr_job_order($prodJob, (int) $pChosen['id'])['error'], 'non renseignée'));
rename(PR_SECRETS_DIR . '/hidden.key', PR_SECRETS_DIR . '/impression_prodigi_sandbox.key');

// Offre d'une autre commande
check('offre d\'une autre commande refusée', pr_job_order($prodJob, (int) $chosen['id'])['ok'], false);

// Échec fournisseur puis réessai
resetCalls();
$script = ['POST /Orders' => ['status' => 400, 'body' => ['outcome' => 'ValidationFailed', 'failures' => ['items[0].sku' => ['unknown']]]]];
$r = pr_job_order($prodJob, (int) $pChosen['id']);
check('échec fournisseur remonté', $r['ok'], false);
$pj = pr_job_get($prodJob);
check('statut → échec, erreur mémorisée', [$pj['status'], $pj['error'] !== ''], ['failed', true]);
check('pas de n° de commande après échec', $pj['provider_order_id'], '');

resetCalls();
$script = ['POST /Orders' => ['body' => ['outcome' => 'Created', 'order' => [
    'id' => 'ord_978731', 'status' => ['stage' => 'InProgress', 'details' => ['inProduction' => 'NotStarted', 'shipping' => 'NotStarted']], 'shipments' => [], 'charges' => [],
]]]];
$r = pr_job_order($prodJob, (int) $pChosen['id']);
check('réessai après échec accepté', $r['ok'], true);
$o = lastCall();
check('Prodigi : hôte sandbox pour la commande', str_starts_with($o['url'], 'https://api.sandbox.prodigi.com/v4.0/Orders'));
check('Prodigi : méthode de livraison choisie', $o['json']['shippingMethod'], 'Budget');
check('Prodigi : clé anti-doublon envoyée', (bool) preg_match('/^PR-0002-[a-f0-9]{16}$/', $o['json']['idempotencyKey']));
check('Prodigi : destinataire', [$o['json']['recipient']['name'], $o['json']['recipient']['address']['countryCode'], $o['json']['recipient']['address']['postalOrZipCode']], ['Marie Dupont', 'FR', '80000']);
check('Prodigi : fichier par URL, zone d\'impression default', [$o['json']['items'][0]['assets'][0]['printArea'], str_starts_with($o['json']['items'][0]['assets'][0]['url'], 'https://')], ['default', true]);
check('Prodigi : sizing fillPrintArea', $o['json']['items'][0]['sizing'], 'fillPrintArea');
check('Prodigi : statut commandée', pr_job_get($prodJob)['status'], 'ordered');

// Statuts Prodigi
check('Prodigi InProgress/NotStarted → ordered', PrProdigi::mapStatus(['status' => ['stage' => 'InProgress', 'details' => ['inProduction' => 'NotStarted']]]), 'ordered');
check('Prodigi inProduction → in_production', PrProdigi::mapStatus(['status' => ['stage' => 'InProgress', 'details' => ['inProduction' => 'InProgress']]]), 'in_production');
check('Prodigi Complete → shipped', PrProdigi::mapStatus(['status' => ['stage' => 'Complete', 'details' => []]]), 'shipped');
check('Prodigi Cancelled → cancelled', PrProdigi::mapStatus(['status' => ['stage' => 'Cancelled']]), 'cancelled');

$script = ['GET /Orders/ord_978731' => ['body' => ['outcome' => 'Ok', 'order' => [
    'id' => 'ord_978731', 'status' => ['stage' => 'Complete', 'details' => ['shipping' => 'Complete']],
    'shipments' => [['tracking' => ['number' => 'PH000000000GB', 'url' => 'https://track.example/PH']]],
    'charges' => [['totalCost' => ['amount' => '22.50', 'currency' => 'EUR']]],
]]]];
check('sync Prodigi', pr_job_sync($prodJob)['ok']);
$pj = pr_job_get($prodJob);
check('Prodigi : expédiée + suivi + montant facturé', [$pj['status'], $pj['tracking_code'], (int) $pj['charged_cents']], ['shipped', 'PH000000000GB', 2250]);
check('Prodigi : sync en sandbox (hôte)', str_contains(lastCall()['url'], 'api.sandbox.prodigi.com'));

$script = ['POST /Orders/ord_978731/actions/cancel' => ['body' => ['outcome' => 'Cancelled']]];
check('annulation Prodigi', pr_job_cancel($prodJob)['ok']);
check('URL d\'annulation Prodigi', lastCall()['url'], 'https://api.sandbox.prodigi.com/v4.0/Orders/ord_978731/actions/cancel');

// Mode live : clé live distincte, hôte de production
pr_setting_set('mode', 'live');
$liveJob = pr_job_create(array_merge($clean, ['title' => 'Live', 'product_id' => $affiche, 'quantity' => 1]), $jobFile);
resetCalls();
$script = ['POST /quotes' => $prodigiQuote('Budget', '9.00', '4.50')];
$r = pr_job_quote_all($liveJob);
$liveCalls = array_filter($calls, fn($c) => str_contains($c['url'], 'prodigi'));
check('live : sans clé live, aucun appel Prodigi', count($liveCalls), 0);
pr_secret_set('prodigi_live', 'live-key');
pr_job_quote_all($liveJob);
check('live : hôte de production Prodigi', str_starts_with($calls[0]['url'], 'https://api.prodigi.com/v4.0/quotes'));
check('live : clé live utilisée', in_array('X-API-Key: live-key', $calls[0]['headers'], true));
check('live : la commande mémorise qu\'elle n\'est pas en mode test', (int) pr_job_get($liveJob)['test_mode'], 0);
pr_setting_set('mode', 'test');

/* ======================================================================= */
echo "Fournisseurs sans API (devis à la main)\n";
$manual = pr_job_create(array_merge($clean, ['title' => 'Plan', 'product_id' => $carte, 'quantity' => 100]), $jobFile);
$brief = pr_job_brief(pr_job_get($manual), pr_product_get($carte));
check('brief : produit et quantité', str_contains($brief, 'Cartes de visite 85 × 55 mm') && str_contains($brief, 'Quantité : 100'));
check('brief : format fini', str_contains($brief, '85 × 55 mm'));
check('brief : adresse', str_contains($brief, '12 rue des Lilas') && str_contains($brief, '80000 Amiens'));
check('brief : lien de téléchargement du fichier', str_contains($brief, 'https://'));
check('demande de devis consignée', pr_job_mark_quote_requested($manual, $exaprint), null);
check('statut → devis demandé', pr_job_get($manual)['status'], 'quote_requested');
check('prix négatif refusé', pr_manual_quote_add($manual, $exaprint, '-5') !== null);
check('prix non numérique refusé', pr_manual_quote_add($manual, $exaprint, 'abc') !== null);
check('prix saisi à la main (virgule)', pr_manual_quote_add($manual, $exaprint, '34,90', 'devis du 5/11', 6), null);
check('statut → devis comparés', pr_job_get($manual)['status'], 'quoted');
$mq = array_values(array_filter(pr_quotes_for_job($manual), fn($x) => (int) $x['manual'] === 1))[0];
check('devis manuel en centimes', (int) $mq['total_cents'], 3490);

resetCalls();
check('commande par API refusée pour un devis manuel', pr_job_order($manual, (int) $mq['id'])['ok'], false);
check('aucun appel réseau pour un fournisseur sans API', count($calls), 0);
check('commande passée hors API', pr_job_mark_ordered_manually($manual, (int) $mq['id'], 'WEB-4471'), null);
$mj = pr_job_get($manual);
check('hors API : statut, canal, référence, montant', [$mj['status'], $mj['channel'], $mj['provider_order_id'], (int) $mj['estimate_cents']], ['ordered', 'manual', 'WEB-4471', 3490]);
check('hors API : pas de synchronisation API', pr_job_sync($manual)['ok'], false);
check('suivi manuel : expédiée + lien', pr_job_set_status_manual($manual, 'shipped', 'https://suivi.example/1'), null);
check('suivi manuel : lien invalide refusé', pr_job_set_status_manual($manual, 'shipped', 'javascript:alert(1)') !== null);
check('suivi manuel refusé pour une commande API', pr_job_set_status_manual($jobId, 'shipped') !== null);
check('suppression refusée pour une commande expédiée', pr_job_delete($manual) !== null);
check('annulation hors API = locale (sans appel)', pr_job_cancel($manual)['ok'] && count($calls) === 0);

/* ======================================================================= */
echo "Annuaire, catalogue, références\n";
[$id, $e] = pr_supplier_save(null, ['name' => 'Imprimerie du Marais', 'kind' => 'api', 'email' => 'devis@marais.example', 'website' => 'https://marais.example', 'is_active' => 1]);
check('fournisseur local ajouté', $e, null);
check('un fournisseur ajouté à la main ne peut pas être « api » (pas de connecteur)', pr_supplier_get($id)['kind'], 'api_externe');
check('adresse web sans http refusée', pr_supplier_save(null, ['name' => 'X', 'kind' => 'devis', 'website' => 'javascript:alert(1)'])[1] !== null);
check('e-mail invalide refusé', pr_supplier_save(null, ['name' => 'X', 'kind' => 'devis', 'email' => 'zzz'])[1] !== null);
check('nom vide refusé', pr_supplier_save(null, ['name' => '', 'kind' => 'devis'])[1] !== null);
check('suppression d\'un fournisseur ajouté', pr_supplier_delete($id), null);
check('suppression d\'un fournisseur de base refusée', pr_supplier_delete($gelato) !== null);

check('référence invalide refusée', pr_map_save($carte, $gelato, 'a;b"c') !== null);
check('options JSON invalides refusées', pr_map_save($carte, $prodigi, 'SKU-1', '[1,2]') !== null);
check('options JSON valides', pr_map_save($carte, $prodigi, 'SKU-1', '{"color":"black"}'), null);
check('options relues', pr_map_attrs(pr_map_get($carte, $prodigi)), ['color' => 'black']);
check('référence vide = suppression', pr_map_save($carte, $prodigi, ''), null);
check('référence supprimée', pr_map_get($carte, $prodigi), null);

[$pid, $e] = pr_product_save(null, ['label' => 'Roll-up 85×200', 'category' => 'affiche', 'width_mm' => 850, 'height_mm' => 2000, 'default_qty' => 1, 'is_active' => 1]);
check('produit ajouté', $e, null);
check('suppression d\'un produit utilisé refusée', pr_product_delete($carte) !== null);
check('suppression d\'un produit libre', pr_product_delete($pid), null);

/* ======================================================================= */
echo "Suppression de commande et chiffres\n";
check('suppression d\'une commande annulée', pr_job_delete($prodJob), null);
$fresh = pr_job_create($clean, $jobFile);
$f2 = pr_file_store($png, pr_file_inspect($png, 'a.png', filesize($png)), false);
$storedFiles[] = dirname(__DIR__) . '/' . $f2;
db()->prepare('UPDATE pr_jobs SET file_path = ? WHERE id = ?')->execute([$f2, $fresh]);
check('suppression d\'un brouillon', pr_job_delete($fresh), null);
check('fichier supprimé avec la commande', is_file(dirname(__DIR__) . '/' . $f2), false);
// Commande restée coincée en « Envoi en cours »
$stuck = pr_job_create($clean, $jobFile);
pr_job_touch($stuck, ['status' => 'ordering']);
check('envoi récent : pas débloqué', pr_job_recover_stuck($stuck), false);
check('envoi récent : annulation refusée', pr_job_cancel($stuck)['ok'], false);
db()->prepare('UPDATE pr_jobs SET updated_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 900), $stuck]);
check('envoi vieux de 15 min : débloqué', pr_job_recover_stuck($stuck), true);
$sj = pr_job_get($stuck);
check('débloqué → échec avec consigne anti-doublon', [$sj['status'], str_contains($sj['error'], 'doublon')], ['failed', true]);
check('commande débloquée supprimable', pr_job_can_delete($sj), true);
check('commande expédiée non supprimable', pr_job_can_delete(['status' => 'shipped', 'channel' => 'api']), false);
check('commande API annulée supprimable', pr_job_can_delete(['status' => 'cancelled', 'channel' => 'api']), true);
check('commande en attente de réponse API non supprimable', pr_job_can_delete(['status' => 'ordered', 'channel' => 'api']), false);
pr_job_delete($stuck);
$st = pr_stats();
// Restent : la commande Gelato (annulée), la commande live (devis comparés) et la commande hors API (annulée).
check('chiffres : 3 commandes', $st['total'], 3);
check('chiffres : 1 en préparation (devis comparés)', $st['open'], 1);
check('chiffres : aucun échec, rien en cours', [$st['failed'], $st['running'], $st['shipped']], [0, 0, 0]);
check('chiffres : rien dépensé (tout annulé)', $st['spent_cents'], 0);

echo "\n" . ($fail === 0 ? "✅ $pass vérifications réussies.\n" : "❌ $fail échec(s), $pass réussite(s).\n");
exit($fail === 0 ? 0 : 1);
