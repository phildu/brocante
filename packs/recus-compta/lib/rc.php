<?php
// Reçus d'achat et journal des ventes : règles métier du module « Reçus & journal des ventes ».
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const RC_MODULE = 'recus-compta';

function rc_regimes(): array
{
    return [
        'marge' => "TVA sur marge — biens d'occasion",
        'franchise' => 'Franchise en base de TVA (art. 293 B du CGI)',
        'normal' => 'TVA normale (prix TTC)',
    ];
}

function rc_defaults(): array
{
    return [
        'seller_name' => '', 'seller_address' => '', 'seller_siret' => '', 'seller_vat' => '', 'seller_email' => '',
        'regime' => 'marge', 'vat_rate' => '20', 'email_auto' => '1', 'footer' => '',
        'acc_bank' => '512000', 'acc_sales' => '707000', 'acc_shipping' => '708500', 'acc_vat' => '445710', 'journal' => 'VE',
    ];
}

function rc_settings(): array
{
    $out = rc_defaults();
    foreach (db()->query('SELECT key, value FROM rc_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (array_key_exists($r['key'], $out)) $out[$r['key']] = (string) $r['value'];
    }
    if ($out['seller_name'] === '') $out['seller_name'] = (string) (get_content()['site_name'] ?? tenant('name'));
    return $out;
}

function rc_settings_save(array $in): void
{
    $def = rc_defaults();
    $st = db()->prepare('INSERT INTO rc_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    foreach ($def as $k => $_) {
        if (!array_key_exists($k, $in)) continue;
        $v = trim((string) $in[$k]);
        if ($k === 'regime' && !isset(rc_regimes()[$v])) $v = 'marge';
        if ($k === 'vat_rate') $v = (string) max(0, min(30, (float) str_replace(',', '.', $v)));
        if ($k === 'email_auto') $v = $v === '1' ? '1' : '0';
        if (in_array($k, ['acc_bank', 'acc_sales', 'acc_shipping', 'acc_vat'], true)) $v = preg_replace('/[^0-9A-Za-z]/', '', $v) ?: $def[$k];
        if ($k === 'journal') $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v) ?: $def[$k]);
        $st->execute([$k, mb_substr($v, 0, 400)]);
    }
}

function rc_mention(array $s): string
{
    return match ($s['regime']) {
        'franchise' => 'TVA non applicable, article 293 B du Code général des impôts.',
        'normal' => 'Prix TTC. TVA ' . rtrim(rtrim(number_format((float) $s['vat_rate'], 2, ',', ''), '0'), ',') . ' %.',
        default => "Bien d'occasion — TVA sur marge (régime de la marge, art. 297 A du CGI) : la TVA n'est pas mentionnée et n'est pas récupérable.",
    };
}

function rc_eur(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

/** Commande payée → reçu (créé une seule fois, numérotation continue). null si la commande n'est pas payée. */
function rc_issue(int $orderId): ?array
{
    $st = db()->prepare("SELECT * FROM orders WHERE id = ? AND status = 'paid'");
    $st->execute([$orderId]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) return null;
    $ex = db()->prepare('SELECT * FROM rc_receipts WHERE order_id = ?');
    $ex->execute([$orderId]);
    if ($r = $ex->fetch(PDO::FETCH_ASSOC)) return $r;

    $s = rc_settings();
    $lines = array_map(static fn ($l) => [
        'ref' => (string) ($l['ref'] ?? ''), 'name' => (string) ($l['name'] ?? ''), 'qty' => max(1, (int) ($l['qty'] ?? 1)),
        'unit_cents' => (int) ($l['unit_cents'] ?? 0),
    ], json_decode((string) $o['items'], true) ?: []);
    $total = (int) $o['amount_total'];
    $vat = $s['regime'] === 'normal' ? (int) round($total - $total / (1 + (float) $s['vat_rate'] / 100)) : 0;
    $data = [
        'seller' => ['name' => $s['seller_name'], 'address' => $s['seller_address'], 'siret' => $s['seller_siret'], 'vat' => $s['seller_vat'], 'email' => $s['seller_email']],
        'buyer' => ['email' => (string) $o['email'], 'address' => (string) $o['shipping_address']],
        'lines' => $lines, 'shipping_cents' => (int) $o['shipping_cents'], 'total_cents' => $total, 'vat_cents' => $vat,
        'regime' => $s['regime'], 'mention' => rc_mention($s), 'footer' => $s['footer'], 'paid_at' => (string) $o['created_at'],
    ];
    $year = (int) date('Y');
    for ($try = 0; $try < 5; $try++) {
        $seq = (int) db()->query("SELECT COALESCE(MAX(seq), 0) + 1 FROM rc_receipts WHERE year = $year")->fetchColumn();
        $number = sprintf('REC-%d-%04d', $year, $seq);
        try {
            db()->prepare('INSERT INTO rc_receipts (order_id, year, seq, number, token, email, data) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$orderId, $year, $seq, $number, bin2hex(random_bytes(16)), (string) $o['email'], json_encode($data, JSON_UNESCAPED_UNICODE)]);
            break;
        } catch (PDOException $e) {
            $again = db()->prepare('SELECT * FROM rc_receipts WHERE order_id = ?');
            $again->execute([$orderId]);
            if ($r = $again->fetch(PDO::FETCH_ASSOC)) return $r;   // émis entre-temps par une autre requête
        }
    }
    $got = db()->prepare('SELECT * FROM rc_receipts WHERE order_id = ?');
    $got->execute([$orderId]);
    return $got->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rc_public_url(array $r): string
{
    require_once dirname(__DIR__, 3) . '/includes/share.php';
    return shop_abs_url('/api/recu/' . $r['token']);
}

/**
 * Lien de suivi de la commande (page publique et appli client) et informations
 * de retrait ou de livraison. Vide si la commande n'existe plus.
 * -> url, label
 */
function rc_tracking(array $r): array
{
    $out = ['url' => '', 'label' => ''];
    if (!function_exists('order_get') || !function_exists('order_track_url')) {
        require_once dirname(__DIR__, 3) . '/includes/order-flow.php';
    }
    $order = order_get((int) ($r['order_id'] ?? 0));
    if (!$order) return $out;
    $out['url'] = order_track_url($order, true);
    $t = order_buyer_tracking($order);
    if ($t['pickup']) {
        $place = trim(implode(' — ', array_filter([$t['pickup_label'], $t['address']])));
        $out['label'] = 'Retrait sur place' . ($place !== '' ? ' : ' . $place : '')
            . ($t['deadline'] !== '' ? ', à retirer avant le ' . $t['deadline'] : ($t['hours'] !== '' ? ' (' . $t['hours'] . ')' : ''));
    } elseif ($t['shipping_label'] !== '') {
        $out['label'] = 'Livraison : ' . $t['shipping_label'];
    }
    return $out;
}

/** Reçu en texte (corps de l'e-mail). */
function rc_text(array $r): string
{
    $d = json_decode((string) $r['data'], true) ?: [];
    $s = $d['seller'] ?? [];
    $out = ['REÇU D\'ACHAT ' . $r['number'], 'Date : ' . date('d/m/Y', strtotime((string) ($d['paid_at'] ?? 'now'))), '',
        'Vendeur : ' . ($s['name'] ?? '') . ($s['address'] ? ', ' . $s['address'] : '') . ($s['siret'] ? ' — SIRET ' . $s['siret'] : '') . (!empty($s['vat']) ? ' — TVA ' . $s['vat'] : ''), ''];
    foreach ($d['lines'] ?? [] as $l) $out[] = sprintf('- %s × %d : %s', $l['name'], $l['qty'], rc_eur($l['unit_cents'] * $l['qty']));
    if (!empty($d['shipping_cents'])) $out[] = '- Livraison : ' . rc_eur((int) $d['shipping_cents']);
    $out[] = '';
    $out[] = 'TOTAL PAYÉ : ' . rc_eur((int) ($d['total_cents'] ?? 0));
    if (!empty($d['vat_cents'])) $out[] = 'dont TVA : ' . rc_eur((int) $d['vat_cents']);
    $out[] = '';
    $out[] = (string) ($d['mention'] ?? '');
    if (!empty($d['footer'])) $out[] = (string) $d['footer'];
    $tk = rc_tracking($r);
    if ($tk['label'] !== '') {
        $out[] = '';
        $out[] = $tk['label'];
    }
    $out[] = '';
    $out[] = 'Reçu imprimable : ' . rc_public_url($r);
    if ($tk['url'] !== '') $out[] = 'Suivre votre commande (lieu, délai et appli client) : ' . $tk['url'];
    return implode("\n", $out);
}

function rc_html(array $r): string
{
    $d = json_decode((string) $r['data'], true) ?: [];
    $s = $d['seller'] ?? [];
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($d['lines'] ?? [] as $l) {
        $rows .= '<tr><td>' . $h($l['name']) . '<br><small>Réf. ' . $h($l['ref']) . '</small></td><td class="n">' . (int) $l['qty'] . '</td><td class="n">' . $h(rc_eur((int) $l['unit_cents'])) . '</td><td class="n">' . $h(rc_eur((int) $l['unit_cents'] * (int) $l['qty'])) . '</td></tr>';
    }
    if (!empty($d['shipping_cents'])) $rows .= '<tr><td>Livraison</td><td class="n">1</td><td class="n">' . $h(rc_eur((int) $d['shipping_cents'])) . '</td><td class="n">' . $h(rc_eur((int) $d['shipping_cents'])) . '</td></tr>';
    $vat = !empty($d['vat_cents']) ? '<p class="n">dont TVA : ' . $h(rc_eur((int) $d['vat_cents'])) . '</p>' : '';
    $tk = rc_tracking($r);
    $collect = $tk['label'] !== '' ? '<p class="collect"><strong>' . $h($tk['label']) . '</strong></p>' : '';
    $qr = '';
    if ($tk['url'] !== '') {
        $asset = function_exists('shop_abs_url') ? shop_abs_url('/assets/vendor/qrcode-generator.js') : '/assets/vendor/qrcode-generator.js';
        $qr = '<div class="trk"><div id="rc-qr" aria-hidden="true"></div><p><strong>Suivez votre commande</strong><br>Scannez ce code avec le téléphone : lieu et délai de collecte, avancement, et installation de l\'appli client.<br><a href="' . $h($tk['url']) . '">' . $h($tk['url']) . '</a></p></div>'
            . '<script src="' . $h($asset) . '"></script>'
            . '<script>(function(){var b=document.getElementById("rc-qr");if(!b)return;if(typeof qrcode!=="function"){b.style.display="none";return;}try{var q=qrcode(0,"M");q.addData(' . json_encode($tk['url'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');q.make();b.innerHTML=q.createSvgTag({cellSize:4,margin:4,scalable:true});}catch(e){b.style.display="none";}})();</script>';
    }
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Reçu ' . $h($r['number']) . '</title>'
        . '<style>body{font:15px/1.5 system-ui,sans-serif;max-width:720px;margin:24px auto;padding:0 16px;color:#222}h1{font-size:1.4rem;margin:0}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}.n{text-align:right}small{color:#666}.tot{font-size:1.2rem;font-weight:700;text-align:right}.mention{border:1px solid #ccc;padding:10px;font-size:.85rem;background:#f8f8f8}.collect{margin:14px 0 0;font-size:.9rem}.trk{display:flex;gap:16px;align-items:center;margin:18px 0;padding:12px;border:1px solid #ccc;break-inside:avoid}.trk svg{width:128px;height:128px;flex:none}.trk p{margin:0;font-size:.82rem}.trk a{word-break:break-all}@media print{.noprint{display:none}}</style></head><body>'
        . '<h1>Reçu d\'achat ' . $h($r['number']) . '</h1><p>Date : ' . $h(date('d/m/Y', strtotime((string) ($d['paid_at'] ?? 'now')))) . '</p>'
        . '<p><strong>' . $h($s['name'] ?? '') . '</strong><br>' . $h($s['address'] ?? '') . ($s['siret'] ? '<br>SIRET ' . $h($s['siret']) : '') . (!empty($s['vat']) ? '<br>N° TVA ' . $h($s['vat']) : '') . '</p>'
        . '<table><thead><tr><th>Désignation</th><th class="n">Qté</th><th class="n">Prix</th><th class="n">Total</th></tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p class="tot">Total payé : ' . $h(rc_eur((int) ($d['total_cents'] ?? 0))) . '</p>' . $vat
        . $collect
        . $qr
        . '<p class="mention">' . $h($d['mention'] ?? '') . ($d['footer'] ? '<br>' . $h($d['footer']) : '') . '</p>'
        . '<p class="noprint"><button onclick="print()">Imprimer / enregistrer en PDF</button></p></body></html>';
}

/** Envoie le reçu par e-mail à l'acheteur. -> true si l'envoi est parti. */
function rc_send(array $r): bool
{
    if ($r['email'] === '' || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) return false;
    require_once dirname(__DIR__, 3) . '/includes/password-reset.php';
    $d = json_decode((string) $r['data'], true) ?: [];
    $ok = password_reset_mail($r['email'], 'Votre reçu ' . $r['number'] . ' — ' . ($d['seller']['name'] ?? ''), rc_text($r), 'recu');
    if ($ok) db()->prepare('UPDATE rc_receipts SET emailed_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$r['id']]);
    return $ok;
}

// ── Journal des ventes ──────────────────────────────────────────────────────

/** Commandes payées de la période [from, to] (AAAA-MM-JJ), avec leur reçu s'il existe. */
function rc_orders(string $from, string $to): array
{
    $st = db()->prepare("SELECT o.*, r.number, r.data AS rdata FROM orders o LEFT JOIN rc_receipts r ON r.order_id = o.id
        WHERE o.status = 'paid' AND date(o.created_at) BETWEEN ? AND ? ORDER BY o.created_at, o.id");
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function rc_split(array $o, array $s): array
{
    $total = (int) $o['amount_total'];
    $ship = (int) $o['shipping_cents'];
    $vat = $s['regime'] === 'normal' ? (int) round($total - $total / (1 + (float) $s['vat_rate'] / 100)) : 0;
    // Ventilation HT (TVA normale : le port est ramené hors taxe comme les marchandises ; sinon rien à retirer). Les écritures restent équilibrées.
    $k = $s['regime'] === 'normal' ? 1 + (float) $s['vat_rate'] / 100 : 1.0;
    $shipNet = (int) round($ship / $k);
    return ['total' => $total, 'ship' => $ship, 'vat' => $vat, 'ht' => $total - $vat, 'ship_net' => $shipNet, 'goods_net' => $total - $vat - $shipNet];
}

function rc_csv_cell(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;   // pas d'injection de formule dans Excel
    return '"' . str_replace('"', '""', $v) . '"';
}

/** Journal des ventes en CSV (séparateur « ; », UTF-8 avec BOM : s'ouvre directement dans Excel). */
function rc_journal_csv(string $from, string $to): string
{
    $s = rc_settings();
    $out = "\xEF\xBB\xBF" . implode(';', array_map('rc_csv_cell', ['Date', 'N° pièce', 'Commande', 'Client (e-mail)', 'Désignation', 'Total HT', 'TVA', 'Total TTC', 'dont livraison', 'Régime', 'Paiement Stripe'])) . "\r\n";
    foreach (rc_orders($from, $to) as $o) {
        $x = rc_split($o, $s);
        $names = implode(' + ', array_map(static fn ($l) => ($l['name'] ?? '') . ((int) ($l['qty'] ?? 1) > 1 ? ' ×' . (int) $l['qty'] : ''), json_decode((string) $o['items'], true) ?: []));
        $f = static fn (int $c) => number_format($c / 100, 2, ',', '');
        $out .= implode(';', array_map('rc_csv_cell', [date('d/m/Y', strtotime((string) $o['created_at'])), $o['number'] ?: 'CMD-' . $o['id'], (string) $o['id'], (string) $o['email'], $names,
            $f($x['ht']), $f($x['vat']), $f($x['total']), $f($x['ship']), rc_regimes()[$s['regime']], (string) $o['stripe_session_id']])) . "\r\n";
    }
    return $out;
}

/** Fichier des écritures comptables (FEC) : 18 colonnes, tabulations, dates AAAAMMJJ, décimales à virgule. */
function rc_fec(string $from, string $to): string
{
    $s = rc_settings();
    $cols = ['JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit', 'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise'];
    $clean = static fn (string $v) => trim(str_replace(["\t", "\r", "\n"], ' ', $v));
    $out = implode("\t", $cols) . "\r\n";
    $n = 0;
    foreach (rc_orders($from, $to) as $o) {
        $n++;
        $x = rc_split($o, $s);
        $d = date('Ymd', strtotime((string) $o['created_at']));
        $piece = $clean($o['number'] ?: 'CMD-' . $o['id']);
        $lib = $clean('Vente ' . $piece);
        $m = static fn (int $c) => number_format($c / 100, 2, ',', '');
        $row = function (string $acc, string $accLib, int $debit, int $credit) use ($s, $n, $d, $piece, $lib, $m, $clean, &$out): void {
            $out .= implode("\t", [$s['journal'], 'Journal des ventes', $n, $d, $acc, $clean($accLib), '', '', $piece, $d, $lib, $m($debit), $m($credit), '', '', $d, '', '']) . "\r\n";
        };
        $row($s['acc_bank'], 'Banque (encaissements en ligne)', $x['total'], 0);
        if ($x['goods_net'] > 0) $row($s['acc_sales'], 'Ventes de marchandises', 0, $x['goods_net']);
        if ($x['ship_net'] > 0) $row($s['acc_shipping'], 'Port et frais accessoires facturés', 0, $x['ship_net']);
        if ($x['vat'] > 0) $row($s['acc_vat'], 'TVA collectée', 0, $x['vat']);
    }
    return $out;
}

/** SIREN (9 premiers chiffres du SIRET) pour le nom du fichier FEC, ou 000000000. */
function rc_siren(): string
{
    $d = preg_replace('/\D/', '', rc_settings()['seller_siret']);
    return strlen($d) >= 9 ? substr($d, 0, 9) : '000000000';
}
