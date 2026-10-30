<?php

// Faux serveur Stripe pour les tests e2e (routeur php -S).
// Couvre ce que l'application utilise : POST /v1/checkout/sessions,
// GET /v1/checkout/sessions/<id>, POST /v1/coupons, POST /v1/customers.
// Routes de test (hors API Stripe) : POST /v1/test/pay/<id> marque une session
// payée ; POST /v1/test/sessions enregistre une session fabriquée à la main.
// Stockage : fichier JSON (FAKE_STRIPE_STORE) partagé entre les process.

$storePath = getenv('FAKE_STRIPE_STORE') ?: sys_get_temp_dir() . '/fake-stripe-store.json';

function store(): array
{
    global $storePath;
    return is_file($storePath) ? (json_decode((string) file_get_contents($storePath), true) ?: []) : [];
}

function save(array $data): void
{
    global $storePath;
    file_put_contents($storePath, json_encode($data, JSON_UNESCAPED_SLASHES));
}

function reply(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

$authOk = str_starts_with((string) ($_SERVER['PHP_AUTH_USER'] ?? ''), 'sk_');
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Création d'une session de checkout : $_POST porte les paramètres imbriqués
// (line_items[0][price_data][unit_amount]…) décodés par PHP.
if ($method === 'POST' && $path === '/v1/checkout/sessions') {
    if (!$authOk) reply(['error' => ['message' => 'Authentification requise.']], 401);
    $s = store();
    $id = 'cs_test_' . bin2hex(random_bytes(8));
    $mode = (string) ($_POST['mode'] ?? 'payment');
    $session = [
        'id' => $id,
        'object' => 'checkout.session',
        'url' => 'http://' . $_SERVER['HTTP_HOST'] . '/v1/pay/' . $id,   // fausse page de paiement
        'status' => 'open',
        'payment_status' => 'unpaid',
        'mode' => $mode,
        'currency' => 'eur',
        'amount_total' => (int) ($_POST['line_items'][0]['price_data']['unit_amount'] ?? 0) * (int) ($_POST['line_items'][0]['quantity'] ?? 1),
        'client_reference_id' => (string) ($_POST['client_reference_id'] ?? ''),
        'customer' => '',
        'customer_email' => (string) ($_POST['customer_email'] ?? ''),
        'metadata' => (array) ($_POST['metadata'] ?? []),
        'subscription' => $mode === 'subscription' ? 'sub_test_' . bin2hex(random_bytes(6)) : null,
        'success_url' => (string) ($_POST['success_url'] ?? ''),
    ];
    $s['sessions'][$id] = $session;
    save($s);
    reply($session);
}

// Lecture d'une session.
if ($method === 'GET' && preg_match('#^/v1/checkout/sessions/([A-Za-z0-9_]+)$#', $path, $m)) {
    if (!$authOk) reply(['error' => ['message' => 'Authentification requise.']], 401);
    $s = store();
    if (empty($s['sessions'][$m[1]])) reply(['error' => ['message' => 'No such checkout session']], 404);
    reply($s['sessions'][$m[1]]);
}

if ($method === 'POST' && $path === '/v1/coupons') {
    if (!$authOk) reply(['error' => ['message' => 'Authentification requise.']], 401);
    $s = store();
    $id = 'cpn_test_' . bin2hex(random_bytes(6));
    $s['coupons'][$id] = ['id' => $id, 'object' => 'coupon'] + $_POST;
    save($s);
    reply($s['coupons'][$id]);
}

if ($method === 'POST' && $path === '/v1/customers') {
    if (!$authOk) reply(['error' => ['message' => 'Authentification requise.']], 401);
    $s = store();
    $id = 'cus_test_' . bin2hex(random_bytes(6));
    $s['customers'][$id] = ['id' => $id, 'object' => 'customer'] + $_POST;
    save($s);
    reply($s['customers'][$id]);
}

// ── Routes de test (ne font pas partie de l'API Stripe) ─────────────────────

if ($method === 'POST' && preg_match('#^/v1/test/pay/([A-Za-z0-9_]+)$#', $path, $m)) {
    $s = store();
    if (empty($s['sessions'][$m[1]])) reply(['error' => 'session inconnue'], 404);
    $s['sessions'][$m[1]]['payment_status'] = 'paid';
    $s['sessions'][$m[1]]['status'] = 'complete';
    save($s);
    reply($s['sessions'][$m[1]]);
}

// Enregistre une session fabriquée (payload JSON brut) : {id, session:{…}}.
if ($method === 'POST' && $path === '/v1/test/sessions') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($in) || empty($in['id']) || !is_array($in['session'] ?? null)) reply(['error' => 'payload attendu : {id, session}'], 400);
    $s = store();
    $s['sessions'][(string) $in['id']] = $in['session'];
    save($s);
    reply(['ok' => true, 'id' => $in['id']]);
}

if ($method === 'GET' && preg_match('#^/v1/pay/([A-Za-z0-9_]+)$#', $path, $m)) {
    echo '<!doctype html><title>Faux Stripe</title><p>Session ' . h($m[1]) . ' — utilisez POST /v1/test/pay/' . h($m[1]) . ' pour la marquer payée.';
    return true;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

http_response_code(404);
echo json_encode(['error' => ['message' => 'Route inconnue du faux Stripe : ' . $method . ' ' . $path]]);
