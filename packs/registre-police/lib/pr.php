<?php
// Registre de police numérique : règles métier.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__, 3) . '/includes/addons.php';

const PR_MODULE = 'registre-police';
const PR_SYNC_EVERY = 120;

function pr_setting(string $k, string $d = ''): string
{
    $st = db()->prepare('SELECT value FROM pr_settings WHERE key = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $d : (string) $v;
}

function pr_setting_set(string $k, string $v): void
{
    db()->prepare('INSERT INTO pr_settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')->execute([$k, $v]);
}

/** Champs saisissables à la main (le reste est automatique ou protégé). */
function pr_fields(): array
{
    return ['entry_date', 'description', 'purchase_cents', 'payment_mode', 'seller_type', 'seller_lastname', 'seller_firstname', 'seller_birth_date', 'seller_birth_place',
        'seller_address', 'id_doc_type', 'id_doc_number', 'id_doc_issuer', 'id_doc_date', 'notes', 'sale_date', 'sale_cents', 'buyer', 'sale_channel'];
}

/** Entrée complète = tout ce que le livre de police exige pour l'acquisition est renseigné. */
function pr_missing(array $e): array
{
    $m = [];
    if ($e['purchase_cents'] === null || $e['purchase_cents'] === '') $m[] = "prix d'achat";
    if (trim($e['payment_mode']) === '') $m[] = 'mode de règlement';
    if (trim($e['seller_lastname']) === '' || trim($e['seller_firstname']) === '') $m[] = 'nom et prénom du vendeur';
    if ($e['seller_type'] === 'particulier' && (trim($e['seller_birth_date']) === '' || trim($e['seller_birth_place']) === '')) $m[] = 'date et lieu de naissance';
    if (trim($e['seller_address']) === '') $m[] = 'adresse du vendeur';
    if ($e['seller_type'] === 'particulier' && (trim($e['id_doc_type']) === '' || trim($e['id_doc_number']) === '')) $m[] = "pièce d'identité";
    return $m;
}

// ── Journal infalsifiable ───────────────────────────────────────────────────

function pr_log(int $entryId, string $action, array $payload): void
{
    $prev = (string) db()->query('SELECT hash FROM pr_log ORDER BY id DESC LIMIT 1')->fetchColumn();
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $at = gmdate('c');
    $hash = hash('sha256', $prev . '|' . $entryId . '|' . $at . '|' . $action . '|' . $json);
    db()->prepare('INSERT INTO pr_log (entry_id, at, action, payload, hash) VALUES (?, ?, ?, ?, ?)')->execute([$entryId, $at, $action, $json, $hash]);
}

/** Recalcule la chaîne : -> [ok, nombre de lignes, id de la première ligne altérée ou null]. */
function pr_verify(): array
{
    $prev = '';
    $n = 0;
    foreach (db()->query('SELECT * FROM pr_log ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $n++;
        if (!hash_equals(hash('sha256', $prev . '|' . $r['entry_id'] . '|' . $r['at'] . '|' . $r['action'] . '|' . $r['payload']), $r['hash'])) return [false, $n, (int) $r['id']];
        $prev = $r['hash'];
    }
    return [true, $n, null];
}

// ── Écriture ────────────────────────────────────────────────────────────────

function pr_description(array $p): string
{
    $parts = [trim((string) $p['name'])];
    foreach (['brand' => 'Marque', 'materials' => 'Matière', 'colors' => 'Couleur', 'size_text' => 'Dimensions'] as $k => $label) {
        if (trim((string) ($p[$k] ?? '')) !== '') $parts[] = $label . ' : ' . trim((string) $p[$k]);
    }
    if (!empty($p['etat']) && function_exists('product_condition') && ($c = product_condition($p['etat']))) $parts[] = 'État : ' . $c[0];
    $d = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($p['description'] ?? ''))));
    if ($d !== '') $parts[] = mb_substr($d, 0, 300);
    return implode(' — ', $parts) . ' (réf. ' . $p['ref'] . ')';
}

/** Inscrit une pièce au registre (une seule fois). -> l'entrée, ou null si la pièce n'existe pas. */
function pr_create(string $ref): ?array
{
    $ex = db()->prepare('SELECT * FROM pr_entries WHERE product_ref = ?');
    $ex->execute([$ref]);
    if ($e = $ex->fetch(PDO::FETCH_ASSOC)) return $e;
    $ps = db()->prepare('SELECT * FROM products WHERE ref = ?');
    $ps->execute([$ref]);
    $p = $ps->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    db()->exec('BEGIN IMMEDIATE');
    try {
        $n = (int) db()->query('SELECT COALESCE(MAX(number), 0) + 1 FROM pr_entries')->fetchColumn();
        db()->prepare('INSERT INTO pr_entries (number, product_ref, entry_date, description) VALUES (?, ?, ?, ?)')
            ->execute([$n, $ref, substr((string) ($p['created_at'] ?: date('Y-m-d')), 0, 10), pr_description($p)]);
        $id = (int) db()->lastInsertId();
        pr_log($id, 'creation', ['number' => $n, 'ref' => $ref]);
        db()->exec('COMMIT');
    } catch (Throwable $e) {
        db()->exec('ROLLBACK');
        throw $e;
    }
    $st = db()->prepare('SELECT * FROM pr_entries WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/** Modifie une entrée ; chaque changement est journalisé (avant / après). -> message d'erreur ou null. */
function pr_update(int $id, array $in): ?string
{
    $st = db()->prepare('SELECT * FROM pr_entries WHERE id = ?');
    $st->execute([$id]);
    $e = $st->fetch(PDO::FETCH_ASSOC);
    if (!$e) return 'Entrée introuvable.';
    $new = [];
    foreach (pr_fields() as $f) {
        if (!array_key_exists($f, $in)) continue;
        $v = trim((string) $in[$f]);
        if (in_array($f, ['purchase_cents', 'sale_cents'], true)) {
            if ($v === '') { $new[$f] = null; continue; }
            $n = (float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $v));
            $new[$f] = (int) round($n * 100);
            continue;
        }
        if (in_array($f, ['entry_date', 'sale_date', 'seller_birth_date', 'id_doc_date'], true) && $v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return 'Date invalide pour « ' . $f . ' » (AAAA-MM-JJ).';
        if ($f === 'seller_type' && !in_array($v, ['particulier', 'professionnel'], true)) $v = 'particulier';
        if ($f === 'entry_date' && $v === '') return "La date d'entrée est obligatoire.";
        if ($f === 'description' && $v === '') return 'La description est obligatoire.';
        $new[$f] = mb_substr($v, 0, $f === 'description' ? 1000 : 300);
    }
    $diff = [];
    foreach ($new as $f => $v) {
        if ((string) ($e[$f] ?? '') !== (string) ($v ?? '')) $diff[$f] = ['avant' => $e[$f], 'après' => $v];
    }
    if (!$diff) return null;
    $sets = implode(', ', array_map(static fn ($f) => "$f = :$f", array_keys($diff)));
    $params = array_map(static fn ($d) => $d['après'], $diff) + ['id' => $id];
    db()->exec('BEGIN IMMEDIATE');
    try {
        db()->prepare("UPDATE pr_entries SET $sets, updated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute($params);
        pr_log($id, 'modification', $diff);
        db()->exec('COMMIT');
    } catch (Throwable $ex) {
        db()->exec('ROLLBACK');
        throw $ex;
    }
    return null;
}

/** Inscriptions automatiques des pièces mises en ligne depuis l'activation, et sorties des pièces vendues. -> [créées, sorties]. */
function pr_sync(bool $force = false): array
{
    if (!addon_is_entitled(PR_MODULE)) return [0, 0];
    if (!$force && time() - (int) pr_setting('last_sync', '0') < PR_SYNC_EVERY) return [0, 0];
    pr_setting_set('last_sync', (string) time());
    if (pr_setting('since') === '') pr_setting_set('since', gmdate('Y-m-d H:i:s'));
    $created = 0;
    $st = db()->prepare('SELECT ref FROM products WHERE created_at >= ? AND ref NOT IN (SELECT product_ref FROM pr_entries) ORDER BY created_at LIMIT 100');
    $st->execute([pr_setting('since')]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ref) { if (pr_create((string) $ref)) $created++; }

    $out = 0;
    $open = db()->query("SELECT e.id, e.product_ref, p.stock, p.sold_at, p.sold_channel, p.price FROM pr_entries e JOIN products p ON p.ref = e.product_ref WHERE e.sale_date IS NULL AND p.stock <= 0")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($open as $o) {
        $order = null;
        $q = db()->prepare("SELECT * FROM orders WHERE status = 'paid' AND items LIKE ? ORDER BY id DESC LIMIT 1");
        $q->execute(['%"ref":' . json_encode((string) $o['product_ref']) . '%']);
        $order = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        $cents = null;
        if ($order) {
            foreach (json_decode((string) $order['items'], true) ?: [] as $l) if (($l['ref'] ?? '') === $o['product_ref']) $cents = (int) $l['unit_cents'];
        }
        $cents ??= price_to_cents((string) $o['price']);
        $date = substr((string) ($order['created_at'] ?? $o['sold_at'] ?? date('Y-m-d')), 0, 10);
        $channel = $order ? 'boutique en ligne' : (string) ($o['sold_channel'] ?: '');
        $buyer = $order ? trim((string) ($order['shipping_address'] ?: $order['email'])) : '';
        $upd = ['sale_date' => $date, 'sale_cents' => $cents === null ? '' : number_format($cents / 100, 2, '.', ''), 'sale_channel' => $channel, 'buyer' => $buyer];
        if (pr_update((int) $o['id'], $upd) === null) {
            if ($order) db()->prepare('UPDATE pr_entries SET order_id = ? WHERE id = ?')->execute([$order['id'], $o['id']]);
            $out++;
        }
    }
    return [$created, $out];
}

// ── Exports ─────────────────────────────────────────────────────────────────

function pr_rows(string $from, string $to): array
{
    $st = db()->prepare('SELECT * FROM pr_entries WHERE date(entry_date) BETWEEN ? AND ? ORDER BY number');
    $st->execute([$from, $to]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function pr_eur(?int $c): string
{
    return $c === null ? '' : number_format($c / 100, 2, ',', ' ') . ' €';
}

function pr_d(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '';
}

function pr_seller_text(array $e): string
{
    $id = trim($e['id_doc_type'] . ' n° ' . $e['id_doc_number'] . ($e['id_doc_issuer'] ? ', délivrée par ' . $e['id_doc_issuer'] : '') . ($e['id_doc_date'] ? ' le ' . pr_d($e['id_doc_date']) : ''));
    $who = trim($e['seller_lastname'] . ' ' . $e['seller_firstname']);
    if ($who === '') return '';
    return $who . ($e['seller_type'] === 'professionnel' ? ' (professionnel)' : '')
        . ($e['seller_birth_date'] || $e['seller_birth_place'] ? ' — né(e) le ' . pr_d($e['seller_birth_date']) . ' à ' . $e['seller_birth_place'] : '')
        . ($e['seller_address'] ? ' — ' . $e['seller_address'] : '') . ($e['id_doc_number'] ? ' — ' . $id : '');
}

function pr_csv_cell(string $v): string
{
    if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}

function pr_csv(string $from, string $to): string
{
    $out = "\xEF\xBB\xBF" . implode(';', array_map('pr_csv_cell', ["N° d'ordre", "Date d'entrée", 'Description', "Prix d'achat", 'Règlement', 'Vendeur (identité)', 'Date de sortie', 'Prix de vente', 'Acquéreur', 'Canal', 'Complet'])) . "\r\n";
    foreach (pr_rows($from, $to) as $e) {
        $out .= implode(';', array_map('pr_csv_cell', [(string) $e['number'], pr_d($e['entry_date']), $e['description'], pr_eur($e['purchase_cents'] === null ? null : (int) $e['purchase_cents']), $e['payment_mode'],
            pr_seller_text($e), pr_d($e['sale_date']), pr_eur($e['sale_cents'] === null ? null : (int) $e['sale_cents']), $e['buyer'], $e['sale_channel'], pr_missing($e) ? 'non' : 'oui'])) . "\r\n";
    }
    return $out;
}

function pr_html(string $from, string $to): string
{
    $h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $site = (string) (get_content()['site_name'] ?? tenant('name'));
    [$ok, $n] = pr_verify();
    $rows = '';
    foreach (pr_rows($from, $to) as $e) {
        $rows .= '<tr><td>' . (int) $e['number'] . '</td><td>' . $h(pr_d($e['entry_date'])) . '</td><td>' . $h($e['description']) . '</td><td class="r">' . $h(pr_eur($e['purchase_cents'] === null ? null : (int) $e['purchase_cents'])) . '<br><small>' . $h($e['payment_mode']) . '</small></td>'
            . '<td>' . $h(pr_seller_text($e)) . '</td><td>' . $h(pr_d($e['sale_date'])) . '</td><td class="r">' . $h(pr_eur($e['sale_cents'] === null ? null : (int) $e['sale_cents'])) . '</td><td>' . $h($e['buyer']) . '</td></tr>';
    }
    return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Livre de police — ' . $h($site) . '</title><meta name="robots" content="noindex">'
        . '<style>@page{size:A4 landscape;margin:10mm}body{font:11px/1.35 system-ui,sans-serif;color:#111}h1{font-size:16px;margin:0 0 4px}table{width:100%;border-collapse:collapse;margin-top:8px}th,td{border:1px solid #888;padding:4px 5px;vertical-align:top;text-align:left}th{background:#eee}.r{text-align:right;white-space:nowrap}small{color:#555}.np{margin:10px 0}@media print{.np{display:none}}</style></head><body>'
        . '<h1>Livre de police — ' . $h($site) . '</h1><div>Période d\'entrée du ' . $h(pr_d($from)) . ' au ' . $h(pr_d($to)) . ' · édité le ' . $h(date('d/m/Y H:i')) . ' · journal des modifications : ' . ($ok ? 'intègre (' . $n . ' lignes)' : 'ALTÉRÉ') . '</div>'
        . '<p class="np"><button onclick="print()">Imprimer / enregistrer en PDF</button></p>'
        . '<table><thead><tr><th>N°</th><th>Date d\'entrée</th><th>Description de l\'objet</th><th>Prix d\'achat / règlement</th><th>Identité du vendeur</th><th>Date de sortie</th><th>Prix de vente</th><th>Acquéreur</th></tr></thead><tbody>' . $rows . '</tbody></table></body></html>';
}
