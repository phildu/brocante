<?php

// Outils MCP supplémentaires : corbeille réversible (suppressions), frais de port, suivi des commandes, photos du catalogue.
// Mêmes garde-fous que includes/mcp.php : sauvegarde de la base avant écriture, journal, liste blanche de champs.
// Jamais d'effacement définitif : « supprimer » = mettre à la corbeille (30 jours, restaurable). Les actions qui préviennent un client
// par e-mail ou qui retirent un élément passent par une confirmation en deux temps (aperçu + jeton à usage unique, 10 minutes).
// Hors de ce MCP : remboursements, annulations, comptes, clés de paiement (administration uniquement). Voir MCP.md.

require_once __DIR__ . '/mcp.php';
require_once __DIR__ . '/analytics.php'; // analytics_ensure_schema(), parse_euros()

const MCP_TRASH_DAYS = 30;
const MCP_CONFIRM_MINUTES = 10;
const MCP_PRODUCT_PHOTOS_MAX = 12;

/* ------------------------------------------------------------------ */
/* Confirmation en deux temps                                          */
/* ------------------------------------------------------------------ */

function mcp_confirm_issue(string $action, string $key): string
{
    $token = bin2hex(random_bytes(4));
    mcp_audit('confirm', ['action' => $action, 'key' => $key, 'h' => hash('sha256', $token)], 'en attente');
    return $token;
}

/** Jeton valable (émis à l'aperçu, < 10 minutes, même action et même cible) : consommé s'il l'est. */
function mcp_confirm_consume(string $action, string $key, ?string $token): bool
{
    if ($token === null || !preg_match('/^[a-f0-9]{8}$/', $token)) return false;
    mcp_ensure_schema();
    $st = db()->prepare("SELECT id, args FROM mcp_audit WHERE tool = 'confirm' AND outcome = 'en attente' AND at >= datetime('now', ?) ORDER BY id DESC LIMIT 100");
    $st->execute(['-' . MCP_CONFIRM_MINUTES . ' minutes']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $a = json_decode((string) $row['args'], true);
        if (is_array($a) && ($a['action'] ?? '') === $action && ($a['key'] ?? '') === $key && hash_equals((string) ($a['h'] ?? ''), hash('sha256', $token))) {
            db()->prepare("UPDATE mcp_audit SET outcome = 'utilisé' WHERE id = ?")->execute([(int) $row['id']]);
            return true;
        }
    }
    return false;
}

/** Réponse de l'étape 1 : ce qui va se passer, et le jeton à renvoyer pour confirmer. */
function mcp_confirm_preview(string $action, string $key, array $summary, string $warning = ''): array
{
    return ['applique' => false, 'confirmation_requise' => true, 'apercu' => $summary]
        + ($warning !== '' ? ['attention' => $warning] : [])
        + ['confirm_token' => mcp_confirm_issue($action, $key), 'valable_minutes' => MCP_CONFIRM_MINUTES,
            'etape_suivante' => 'Faites valider par l\'utilisateur, puis rappelez le même outil avec les mêmes arguments et confirm_token.'];
}

/* ------------------------------------------------------------------ */
/* Corbeille                                                           */
/* ------------------------------------------------------------------ */

function mcp_trash_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    db()->exec('CREATE TABLE IF NOT EXISTS mcp_trash (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        kind TEXT NOT NULL,
        ref TEXT NOT NULL,
        label TEXT NOT NULL DEFAULT \'\',
        payload TEXT NOT NULL,
        trashed_at INTEGER NOT NULL
    )');
    if (random_int(1, 20) === 1) db()->prepare('DELETE FROM mcp_trash WHERE trashed_at < ?')->execute([time() - MCP_TRASH_DAYS * 86400]);
    $done = true;
}

function mcp_trash_kinds(): array
{
    return ['product' => 'produit (référence)', 'discount' => 'remise du panier (id)', 'shipping_rate' => 'palier de frais de port (id)',
        'shipping_carrier' => 'transporteur et ses paliers (id)', 'product_photo' => 'photo ou vidéo d\'un produit (id)'];
}

function mcp_table_columns(string $table): array
{
    return array_column(db()->query('PRAGMA table_info(' . preg_replace('/[^a-z_]/', '', $table) . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
}

/** Insère une ligne (colonnes inconnues ignorées). Garde l'id d'origine s'il est libre. Retourne l'id inséré. */
function mcp_insert_row(string $table, array $row, bool $keepId = true): string
{
    $cols = mcp_table_columns($table);
    $data = array_intersect_key($row, array_flip($cols));
    if (!$keepId) unset($data['id']);
    $names = array_keys($data);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_map(static fn ($n) => ':' . $n, $names)) . ')';
    try {
        db()->prepare($sql)->execute($data);
    } catch (PDOException $e) {
        if (!$keepId || !isset($data['id'])) throw $e;
        return mcp_insert_row($table, $row, false); // id déjà pris : nouvel id
    }
    return (string) db()->lastInsertId();
}

/** Contenu et effets d'une mise à la corbeille : ['label', 'payload', 'summary', 'warning']. Lève McpToolError si la cible n'existe pas. */
function mcp_trash_collect(string $kind, string $id): array
{
    $pdo = db();
    switch ($kind) {
        case 'product':
            $p = get_product($id);
            if (!$p) throw new McpToolError('Produit introuvable.');
            $photos = product_photos_list($id);
            $st = $pdo->prepare('SELECT * FROM slideshow_slides WHERE product_ref = ?'); $st->execute([$id]);
            $slides = $st->fetchAll(PDO::FETCH_ASSOC);
            return ['label' => $p['name'], 'payload' => ['product' => $p, 'photos' => $photos, 'slides' => $slides],
                'summary' => ['ref' => $id, 'nom' => $p['name'], 'prix' => $p['price'], 'stock' => (int) $p['stock'], 'photos' => count($photos), 'diaporama' => count($slides)],
                'warning' => 'Le produit disparaît du site et du catalogue. Les commandes déjà passées gardent leurs lignes. Les fichiers photo restent en place.'];
        case 'discount':
            $st = $pdo->prepare('SELECT * FROM cart_discounts WHERE id = ?'); $st->execute([(int) $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new McpToolError('Palier de remise introuvable.');
            return ['label' => cart_discount_describe($r), 'payload' => ['row' => $r], 'summary' => ['id' => (int) $id, 'remise' => cart_discount_describe($r), 'active' => (bool) $r['is_active']], 'warning' => ''];
        case 'shipping_rate':
            $st = $pdo->prepare('SELECT * FROM shipping_rates WHERE id = ?'); $st->execute([(int) $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new McpToolError('Palier de tarif introuvable.');
            return ['label' => $r['service_name'], 'payload' => ['row' => $r], 'summary' => ['id' => (int) $id, 'service' => $r['service_name'], 'prix' => format_cents((int) $r['price_cents']), 'zone' => $r['zone']],
                'warning' => 'Sans ce palier, certains colis peuvent n\'avoir aucun tarif proposé.'];
        case 'shipping_carrier':
            $st = $pdo->prepare('SELECT * FROM shipping_carriers WHERE id = ?'); $st->execute([(int) $id]);
            $c = $st->fetch(PDO::FETCH_ASSOC);
            if (!$c) throw new McpToolError('Transporteur introuvable.');
            $rates = shipping_rates_for_carrier((int) $id);
            return ['label' => $c['name'], 'payload' => ['carrier' => $c, 'rates' => $rates], 'summary' => ['id' => (int) $id, 'transporteur' => $c['name'], 'paliers' => count($rates)],
                'warning' => 'Le transporteur et tous ses paliers disparaissent de la boutique.'];
        case 'product_photo':
            $st = $pdo->prepare('SELECT * FROM product_photos WHERE id = ?'); $st->execute([(int) $id]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new McpToolError('Photo introuvable.');
            return ['label' => $r['label'], 'payload' => ['row' => $r], 'summary' => ['id' => (int) $id, 'produit' => $r['product_ref'], 'etiquette' => $r['label'], 'type' => $r['type']], 'warning' => 'Le fichier reste en place.'];
    }
    throw new McpToolError('Type inconnu. Types : ' . implode(', ', array_keys(mcp_trash_kinds())) . '.');
}

function mcp_trash_apply(string $kind, string $id, array $payload): void
{
    $pdo = db();
    switch ($kind) {
        case 'product': $pdo->prepare('DELETE FROM products WHERE ref = ?')->execute([$id]); break; // photos et diaporama suivent (ON DELETE CASCADE)
        case 'discount': $pdo->prepare('DELETE FROM cart_discounts WHERE id = ?')->execute([(int) $id]); break;
        case 'shipping_rate': $pdo->prepare('DELETE FROM shipping_rates WHERE id = ?')->execute([(int) $id]); break;
        case 'shipping_carrier': $pdo->prepare('DELETE FROM shipping_carriers WHERE id = ?')->execute([(int) $id]); break;
        case 'product_photo':
            $pdo->prepare('DELETE FROM product_photos WHERE id = ?')->execute([(int) $id]);
            sync_cover_photo((string) $payload['row']['product_ref']);
            break;
    }
}

/** Remet un élément de la corbeille en place. Retourne un résumé. */
function mcp_trash_restore(array $t): array
{
    $kind = (string) $t['kind'];
    $p = json_decode((string) $t['payload'], true);
    if (!is_array($p)) throw new McpToolError('Contenu de la corbeille illisible.');
    $pdo = db();
    switch ($kind) {
        case 'product':
            $ref = (string) $p['product']['ref'];
            $exists = $pdo->prepare('SELECT 1 FROM products WHERE ref = ?'); $exists->execute([$ref]);
            $newRef = $exists->fetchColumn() ? next_ref() : $ref; // la référence a pu être réattribuée depuis
            $row = $p['product']; $row['ref'] = $newRef;
            mcp_insert_row('products', $row);
            foreach ($p['photos'] as $ph) { $ph['product_ref'] = $newRef; mcp_insert_row('product_photos', $ph); }
            foreach ($p['slides'] as $sl) { $sl['product_ref'] = $newRef; mcp_insert_row('slideshow_slides', $sl, false); }
            sync_cover_photo($newRef);
            return ['restaure' => 'produit', 'ref' => $newRef] + ($newRef !== $ref ? ['note' => "La référence $ref était reprise par un autre produit : restauré sous $newRef."] : []);
        case 'discount':
            return ['restaure' => 'remise', 'id' => mcp_insert_row('cart_discounts', $p['row'])];
        case 'shipping_rate':
            $c = $pdo->prepare('SELECT 1 FROM shipping_carriers WHERE id = ?'); $c->execute([(int) $p['row']['carrier_id']]);
            if (!$c->fetchColumn()) throw new McpToolError('Le transporteur de ce palier n\'existe plus : restaurez d\'abord le transporteur.');
            return ['restaure' => 'palier de tarif', 'id' => mcp_insert_row('shipping_rates', $p['row'])];
        case 'shipping_carrier':
            $newId = mcp_insert_row('shipping_carriers', $p['carrier']);
            foreach ($p['rates'] as $r) { $r['carrier_id'] = (int) $newId; mcp_insert_row('shipping_rates', $r, false); }
            return ['restaure' => 'transporteur', 'id' => $newId, 'paliers' => count($p['rates'])];
        case 'product_photo':
            $q = $pdo->prepare('SELECT 1 FROM products WHERE ref = ?'); $q->execute([(string) $p['row']['product_ref']]);
            if (!$q->fetchColumn()) throw new McpToolError('Le produit de cette photo n\'existe plus : restaurez d\'abord le produit.');
            $id = mcp_insert_row('product_photos', $p['row']);
            sync_cover_photo((string) $p['row']['product_ref']);
            return ['restaure' => 'photo', 'id' => $id];
    }
    throw new McpToolError('Type inconnu.');
}

/* ------------------------------------------------------------------ */
/* Validation des frais de port                                        */
/* ------------------------------------------------------------------ */

function mcp_kg_to_g($v, string $label): int
{
    $n = str_replace(',', '.', trim((string) $v));
    if (!is_numeric($n) || (float) $n < 0 || (float) $n > 1000) throw new McpToolError("$label : poids en kg entre 0 et 1000.");
    return (int) round((float) $n * 1000);
}

function mcp_rate_public(array $r): array
{
    return ['id' => (int) $r['id'], 'service' => $r['service_name'], 'poids_min_kg' => $r['weight_min_g'] / 1000, 'poids_max_kg' => $r['weight_max_g'] / 1000,
        'prix' => format_cents((int) $r['price_cents']), 'delai' => $r['delivery_delay'], 'zone' => $r['zone']];
}

/* ------------------------------------------------------------------ */
/* Outils                                                              */
/* ------------------------------------------------------------------ */

function mcp_tools_extra(): array
{
    $kinds = implode(', ', array_map(static fn ($k, $d) => "$k = $d", array_keys(mcp_trash_kinds()), mcp_trash_kinds()));
    $none = ['type' => 'object', 'properties' => new stdClass()];
    return [
        /* ---- Corbeille ---- */
        'trash_item' => [
            'write' => true,
            'description' => 'SUPPRIME un élément en le mettant à la corbeille (restaurable 30 jours avec trash_restore). Types : ' . $kinds . '. En deux temps : sans confirm_token, montre ce qui sera retiré et renvoie un confirm_token ; après accord explicite de l\'utilisateur, rappeler avec ce confirm_token.',
            'schema' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => array_keys(mcp_trash_kinds())], 'id' => ['type' => 'string', 'description' => 'Référence du produit, ou id numérique.'],
                'confirm_token' => ['type' => 'string'],
            ], 'required' => ['kind', 'id']],
            'run' => static function (array $a): array {
                mcp_trash_ensure_schema();
                $kind = mcp_str($a['kind'] ?? '', 30);
                $id = mcp_str($a['id'] ?? '', 60);
                $info = mcp_trash_collect($kind, $id);
                $key = $kind . ':' . $id;
                if (!mcp_confirm_consume('trash_item', $key, isset($a['confirm_token']) ? (string) $a['confirm_token'] : null)) {
                    return mcp_confirm_preview('trash_item', $key, $info['summary'], $info['warning']);
                }
                mcp_backup_before_write();
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO mcp_trash (kind, ref, label, payload, trashed_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$kind, $id, mb_substr((string) $info['label'], 0, 200), json_encode($info['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
                    $trashId = (int) $pdo->lastInsertId();
                    mcp_trash_apply($kind, $id, $info['payload']);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                return ['applique' => true, 'corbeille_id' => $trashId, 'retire' => $info['summary'], 'restaurable_jours' => MCP_TRASH_DAYS, '_audit' => [$info['payload'], null]];
            },
        ],
        'trash_list' => [
            'write' => false,
            'description' => 'Contenu de la corbeille (éléments retirés depuis moins de 30 jours), du plus récent au plus ancien. Filtre facultatif : kind.',
            'schema' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => array_keys(mcp_trash_kinds())], 'limit' => ['type' => 'integer', 'default' => 30]]],
            'run' => static function (array $a): array {
                mcp_trash_ensure_schema();
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 100, 'limit') : 30;
                $where = ''; $p = [];
                if (($k = mcp_str($a['kind'] ?? '', 30)) !== '') { $where = 'WHERE kind = ?'; $p[] = $k; }
                $st = db()->prepare("SELECT id, kind, ref, label, trashed_at FROM mcp_trash $where ORDER BY id DESC LIMIT $limit");
                $st->execute($p);
                return ['corbeille' => array_map(static fn ($r) => ['corbeille_id' => (int) $r['id'], 'type' => $r['kind'], 'ref' => $r['ref'], 'nom' => $r['label'], 'retire_le' => gmdate('Y-m-d H:i', (int) $r['trashed_at']) . ' UTC'], $st->fetchAll(PDO::FETCH_ASSOC))];
            },
        ],
        'trash_restore' => [
            'write' => true,
            'description' => 'Remet en place un élément de la corbeille (corbeille_id de trash_list).',
            'schema' => ['type' => 'object', 'properties' => ['corbeille_id' => ['type' => 'integer']], 'required' => ['corbeille_id']],
            'run' => static function (array $a): array {
                mcp_trash_ensure_schema();
                $st = db()->prepare('SELECT * FROM mcp_trash WHERE id = ?');
                $st->execute([mcp_int($a['corbeille_id'] ?? null, 1, PHP_INT_MAX, 'corbeille_id')]);
                $t = $st->fetch(PDO::FETCH_ASSOC);
                if (!$t) throw new McpToolError('Élément introuvable dans la corbeille (purgé après ' . MCP_TRASH_DAYS . ' jours ?).');
                mcp_backup_before_write();
                $pdo = db();
                $pdo->beginTransaction();
                try {
                    $res = mcp_trash_restore($t);
                    $pdo->prepare('DELETE FROM mcp_trash WHERE id = ?')->execute([(int) $t['id']]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                return ['applique' => true] + $res + ['_audit' => [null, $res]];
            },
        ],

        /* ---- Frais de port ---- */
        'shipping_get' => [
            'write' => false,
            'description' => 'Transporteurs et paliers de frais de port (poids en kg, prix en euros, zones : ' . implode(', ', array_keys(shipping_zones())) . ').',
            'schema' => $none,
            'run' => static function (array $a): array {
                $out = [];
                foreach (db()->query('SELECT * FROM shipping_carriers ORDER BY sort_order, id')->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $out[] = ['id' => (int) $c['id'], 'nom' => $c['name'], 'actif' => (bool) $c['is_active'], 'paliers' => array_map('mcp_rate_public', shipping_rates_for_carrier((int) $c['id']))];
                }
                return ['transporteurs' => $out];
            },
        ],
        'shipping_carrier_add' => [
            'write' => true,
            'description' => 'Ajoute un transporteur (sans palier : ajoutez-en avec shipping_rate_add).',
            'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
            'run' => static function (array $a): array {
                $name = mcp_str($a['name'] ?? '', 80);
                if ($name === '') throw new McpToolError('Nom du transporteur obligatoire.');
                mcp_backup_before_write();
                $id = add_shipping_carrier($name);
                return ['cree' => ['id' => $id, 'nom' => $name], '_audit' => [null, ['id' => $id, 'name' => $name]]];
            },
        ],
        'shipping_carrier_set' => [
            'write' => true,
            'description' => 'Renomme, active ou désactive un transporteur.',
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'name' => ['type' => 'string'], 'active' => ['type' => 'boolean']], 'required' => ['id']],
            'run' => static function (array $a): array {
                $id = mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id');
                $st = db()->prepare('SELECT * FROM shipping_carriers WHERE id = ?'); $st->execute([$id]);
                $c = $st->fetch(PDO::FETCH_ASSOC);
                if (!$c) throw new McpToolError('Transporteur introuvable.');
                $new = [];
                if (array_key_exists('name', $a)) { $new['name'] = mcp_str($a['name'], 80); if ($new['name'] === '') throw new McpToolError('Nom vide.'); }
                if (array_key_exists('active', $a)) $new['is_active'] = mcp_bool($a['active']);
                if (!$new) throw new McpToolError('Rien à modifier : name et / ou active.');
                $before = array_intersect_key($c, $new);
                if ($before == $new) return ['applique' => false, 'inchange' => true];
                mcp_backup_before_write();
                db()->prepare('UPDATE shipping_carriers SET ' . implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($new))) . ' WHERE id = :__id')->execute($new + ['__id' => $id]);
                return ['applique' => true, 'avant' => $before, 'apres' => $new, '_audit' => [$before, $new]];
            },
        ],
        'shipping_rate_add' => [
            'write' => true,
            'description' => 'Ajoute un palier de frais de port : weight_min_kg < weight_max_kg, price en euros (> 0), zone facultative (' . implode(', ', array_keys(shipping_zones())) . ').',
            'schema' => ['type' => 'object', 'properties' => [
                'carrier_id' => ['type' => 'integer'], 'service_name' => ['type' => 'string'], 'weight_min_kg' => ['type' => ['number', 'string']],
                'weight_max_kg' => ['type' => ['number', 'string']], 'price' => ['type' => ['number', 'string']], 'delivery_delay' => ['type' => 'string'],
                'zone' => ['type' => 'string', 'default' => 'metropole'],
            ], 'required' => ['carrier_id', 'service_name', 'weight_min_kg', 'weight_max_kg', 'price']],
            'run' => static function (array $a): array {
                $cid = mcp_int($a['carrier_id'] ?? null, 1, PHP_INT_MAX, 'carrier_id');
                $q = db()->prepare('SELECT 1 FROM shipping_carriers WHERE id = ?'); $q->execute([$cid]);
                if (!$q->fetchColumn()) throw new McpToolError('Transporteur introuvable.');
                $service = mcp_str($a['service_name'] ?? '', 100);
                if ($service === '') throw new McpToolError('service_name obligatoire.');
                $min = mcp_kg_to_g($a['weight_min_kg'] ?? '', 'weight_min_kg'); $max = mcp_kg_to_g($a['weight_max_kg'] ?? '', 'weight_max_kg');
                if ($max <= $min) throw new McpToolError('weight_max_kg doit dépasser weight_min_kg.');
                $cents = price_to_cents(mcp_price($a['price'] ?? '')) ?? 0;
                if ($cents <= 0) throw new McpToolError('price doit être supérieur à 0.');
                $zone = mcp_str($a['zone'] ?? 'metropole', 20);
                if (!array_key_exists($zone, shipping_zones())) throw new McpToolError('Zone inconnue. Zones : ' . implode(', ', array_keys(shipping_zones())) . '.');
                mcp_backup_before_write();
                $id = add_shipping_rate($cid, $service, $min, $max, $cents, mcp_str($a['delivery_delay'] ?? '', 60), $zone);
                $row = ['id' => $id, 'carrier_id' => $cid, 'service_name' => $service, 'weight_min_g' => $min, 'weight_max_g' => $max, 'price_cents' => $cents, 'zone' => $zone];
                return ['cree' => mcp_rate_public($row + ['delivery_delay' => mcp_str($a['delivery_delay'] ?? '', 60)]), '_audit' => [null, $row]];
            },
        ],
        'shipping_rate_update' => [
            'write' => true,
            'description' => 'Modifie un palier de frais de port. Champs : service_name, weight_min_kg, weight_max_kg, price (euros), delivery_delay, zone. dry_run=true pour prévisualiser.',
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object'], 'dry_run' => ['type' => 'boolean', 'default' => false]], 'required' => ['id', 'fields']],
            'run' => static function (array $a): array {
                $id = mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id');
                $st = db()->prepare('SELECT * FROM shipping_rates WHERE id = ?'); $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new McpToolError('Palier introuvable.');
                $in = $a['fields'] ?? null;
                if (!is_array($in) || !$in) throw new McpToolError('« fields » : objet non vide attendu.');
                $unknown = array_diff(array_keys($in), ['service_name', 'weight_min_kg', 'weight_max_kg', 'price', 'delivery_delay', 'zone']);
                if ($unknown) throw new McpToolError('Champ(s) non modifiable(s) : ' . implode(', ', $unknown) . '.');
                $new = [];
                foreach ($in as $k => $v) {
                    switch ($k) {
                        case 'service_name': $new['service_name'] = mcp_str($v, 100); if ($new['service_name'] === '') throw new McpToolError('service_name vide.'); break;
                        case 'weight_min_kg': $new['weight_min_g'] = mcp_kg_to_g($v, $k); break;
                        case 'weight_max_kg': $new['weight_max_g'] = mcp_kg_to_g($v, $k); break;
                        case 'price': $new['price_cents'] = price_to_cents(mcp_price($v)) ?? 0; if ($new['price_cents'] <= 0) throw new McpToolError('price doit être supérieur à 0.'); break;
                        case 'delivery_delay': $new['delivery_delay'] = mcp_str($v, 60); break;
                        case 'zone': $z = mcp_str($v, 20); if (!array_key_exists($z, shipping_zones())) throw new McpToolError('Zone inconnue.'); $new['zone'] = $z; break;
                    }
                }
                if (($new['weight_max_g'] ?? $r['weight_max_g']) <= ($new['weight_min_g'] ?? $r['weight_min_g'])) throw new McpToolError('Le poids max doit dépasser le poids min.');
                $before = array_intersect_key($r, $new);
                $res = ['id' => $id, 'avant' => $before, 'apres' => $new];
                if (!empty($a['dry_run']) || $before == $new) return $res + ['applique' => false];
                mcp_backup_before_write();
                db()->prepare('UPDATE shipping_rates SET ' . implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($new))) . ' WHERE id = :__id')->execute($new + ['__id' => $id]);
                return $res + ['applique' => true, '_audit' => [$before, $new]];
            },
        ],

        /* ---- Suivi des commandes ---- */
        'order_set_fulfillment' => [
            'write' => true,
            'description' => 'Met à jour le suivi d\'une commande : status (a_confirmer, a_preparer, pret_retrait / expediee / livree selon le mode de livraison) et/ou tracking_number. Statuts pret_retrait, expediee, livree : le CLIENT EST PRÉVENU PAR E-MAIL, donc confirmation en deux temps (sans confirm_token : aperçu ; après accord explicite de l\'utilisateur, rappeler avec confirm_token). « retiree » (code de retrait) et les remboursements / annulations restent dans l\'administration.',
            'schema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'status' => ['type' => 'string'], 'tracking_number' => ['type' => 'string'], 'confirm_token' => ['type' => 'string'],
            ], 'required' => ['id']],
            'run' => static function (array $a): array {
                $id = mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id');
                $order = order_get($id);
                if (!$order) throw new McpToolError('Commande introuvable.');
                $steps = order_fulfillment_steps($order);
                $status = mcp_str($a['status'] ?? '', 30);
                $tracking = mcp_str($a['tracking_number'] ?? '', 80);
                if ($status === '' && $tracking === '') throw new McpToolError('Indiquez status et / ou tracking_number.');
                if ($status === 'retiree') throw new McpToolError('« retiree » exige le code de retrait du client : à faire dans l\'administration.');
                if ($status !== '' && !isset($steps[$status])) throw new McpToolError('Statut invalide pour cette commande. Statuts : ' . implode(', ', array_keys($steps)) . '.');
                $notifies = in_array($status, ['pret_retrait', 'expediee', 'livree'], true);
                $before = ['fulfillment_status' => $order['fulfillment_status'], 'tracking_number' => $order['tracking_number']];
                $key = $id . ':' . $status . ':' . $tracking;
                if ($notifies && !mcp_confirm_consume('order_set_fulfillment', $key, isset($a['confirm_token']) ? (string) $a['confirm_token'] : null)) {
                    return mcp_confirm_preview('order_set_fulfillment', $key, ['commande' => $id, 'statut_actuel' => $before['fulfillment_status'], 'nouveau_statut' => $status, 'suivi' => $tracking ?: null, 'client' => $order['email']],
                        'Le client (' . ($order['email'] ?: 'adresse inconnue') . ') recevra un e-mail.');
                }
                mcp_backup_before_write();
                if ($status === 'pret_retrait') $ok = order_mark_ready($id);
                elseif ($status === 'expediee') $ok = order_mark_shipped($id, $tracking);
                elseif ($status === 'livree') $ok = order_mark_delivered($id);
                else {
                    $ok = true;
                    $newStatus = $status !== '' ? $status : (string) $order['fulfillment_status'];
                    db()->prepare('UPDATE orders SET fulfillment_status = ?, tracking_number = ? WHERE id = ?')->execute([$newStatus, $tracking !== '' ? $tracking : $order['tracking_number'], $id]);
                    order_event($id, 'suivi', 'Statut : ' . fulfillment_label($newStatus) . ($tracking !== '' ? ' — suivi ' . $tracking : ''), 'mcp');
                }
                if (!$ok) throw new McpToolError('Changement refusé : la commande doit être payée et son mode de livraison compatible avec ce statut.');
                $after = order_get($id);
                $res = ['fulfillment_status' => $after['fulfillment_status'], 'tracking_number' => $after['tracking_number']];
                return ['applique' => true, 'commande' => $id, 'avant' => $before, 'apres' => $res, 'client_prevenu' => $notifies, '_audit' => [$before, $res]];
            },
        ],

        /* ---- Photos du catalogue ---- */
        'media_list' => [
            'write' => false,
            'description' => 'Médiathèque du site : photos et vidéos déjà envoyées (id, type, étiquette, tags, chemin). Filtres : type (photo|video), q (étiquette ou tags), limit (≤ 100).',
            'schema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['photo', 'video']], 'q' => ['type' => 'string'], 'limit' => ['type' => 'integer', 'default' => 30]]],
            'run' => static function (array $a): array {
                $where = []; $p = [];
                if (($t = mcp_str($a['type'] ?? '', 10)) !== '') { $where[] = 'type = :t'; $p['t'] = $t; }
                if (($q = mcp_str($a['q'] ?? '', 80)) !== '') { $where[] = '(label LIKE :q OR tags LIKE :q)'; $p['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'; }
                $limit = isset($a['limit']) ? mcp_int($a['limit'], 1, 100, 'limit') : 30;
                $st = db()->prepare('SELECT id, type, label, tags, path FROM media_library' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT $limit");
                $st->execute($p);
                return ['medias' => $st->fetchAll(PDO::FETCH_ASSOC)];
            },
        ],
        'product_photos_get' => [
            'write' => false,
            'description' => 'Photos et vidéos d\'un produit (id, étiquette, type, masquée, ordre).',
            'schema' => ['type' => 'object', 'properties' => ['ref' => ['type' => 'string']], 'required' => ['ref']],
            'run' => static function (array $a): array {
                $ref = mcp_str($a['ref'] ?? '', 40);
                if (!get_product($ref)) throw new McpToolError('Produit introuvable.');
                return ['ref' => $ref, 'photos' => array_map(static fn ($p) => ['id' => (int) $p['id'], 'etiquette' => $p['label'], 'type' => $p['type'], 'masquee' => (bool) $p['is_hidden'], 'ordre' => (int) $p['sort_order'], 'chemin' => $p['path']], product_photos_list($ref))];
            },
        ],
        'product_photo_add' => [
            'write' => true,
            'description' => 'Ajoute une photo ou vidéo à un produit : soit media_id (médiathèque, voir media_list), soit url (image https publique, réencodée en JPEG). cover=true la place en premier (couverture). 12 maximum par produit.',
            'schema' => ['type' => 'object', 'properties' => [
                'ref' => ['type' => 'string'], 'media_id' => ['type' => 'integer'], 'url' => ['type' => 'string'], 'label' => ['type' => 'string'], 'cover' => ['type' => 'boolean', 'default' => false],
            ], 'required' => ['ref']],
            'run' => static function (array $a): array {
                $ref = mcp_str($a['ref'] ?? '', 40);
                if (!get_product($ref)) throw new McpToolError('Produit introuvable.');
                if ((int) db()->query('SELECT COUNT(*) FROM product_photos WHERE product_ref = ' . db()->quote($ref))->fetchColumn() >= MCP_PRODUCT_PHOTOS_MAX) throw new McpToolError('12 photos maximum par produit.');
                $hasMedia = isset($a['media_id']); $url = mcp_str($a['url'] ?? '', 1000);
                if ($hasMedia === ($url !== '')) throw new McpToolError('Indiquez media_id OU url.');
                $label = mcp_str($a['label'] ?? '', 60);
                if ($hasMedia) {
                    $st = db()->prepare('SELECT * FROM media_library WHERE id = ?'); $st->execute([mcp_int($a['media_id'], 1, PHP_INT_MAX, 'media_id')]);
                    $m = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$m) throw new McpToolError('Média introuvable dans la médiathèque.');
                    $path = (string) $m['path']; $type = $m['type'] === 'video' ? 'video' : 'photo'; $label = $label !== '' ? $label : ((string) $m['label'] ?: 'Photo');
                } else {
                    if (!preg_match('#^https://#i', $url)) throw new McpToolError('url : https obligatoire.');
                    require_once __DIR__ . '/connectors.php';
                    $jpeg = connector_download_image($url);
                    if ($jpeg === null) throw new McpToolError('Image introuvable, trop lourde ou adresse refusée (publique et https uniquement).');
                    mcp_backup_before_write();
                    $path = connector_store_jpeg($jpeg, 'product-' . $ref);
                    if (!$path) throw new McpToolError('Enregistrement de l\'image impossible.');
                    $type = 'photo'; $label = $label !== '' ? $label : 'Photo';
                    media_library_register($path, $label, 'Import MCP');
                }
                mcp_backup_before_write();
                $order = !empty($a['cover'])
                    ? ((int) db()->query('SELECT COALESCE(MIN(sort_order), 0) - 1 FROM product_photos WHERE product_ref = ' . db()->quote($ref))->fetchColumn())
                    : ((int) db()->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_photos WHERE product_ref = ' . db()->quote($ref))->fetchColumn());
                db()->prepare('INSERT INTO product_photos (product_ref, path, type, label, sort_order) VALUES (?, ?, ?, ?, ?)')->execute([$ref, $path, $type, $label, $order]);
                $id = (int) db()->lastInsertId();
                sync_cover_photo($ref);
                $row = ['id' => $id, 'product_ref' => $ref, 'path' => $path, 'type' => $type, 'label' => $label, 'sort_order' => $order];
                return ['cree' => $row, '_audit' => [null, $row]];
            },
        ],
        'product_photo_update' => [
            'write' => true,
            'description' => 'Modifie une photo ou vidéo de produit (id de product_photos_get) : label, is_hidden (true = masquer), sort_order (plus petit = plus haut). Pour la retirer : trash_item kind=product_photo.',
            'schema' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'fields' => ['type' => 'object']], 'required' => ['id', 'fields']],
            'run' => static function (array $a): array {
                $id = mcp_int($a['id'] ?? null, 1, PHP_INT_MAX, 'id');
                $st = db()->prepare('SELECT * FROM product_photos WHERE id = ?'); $st->execute([$id]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if (!$r) throw new McpToolError('Photo introuvable.');
                $in = $a['fields'] ?? null;
                if (!is_array($in) || !$in) throw new McpToolError('« fields » : objet non vide attendu.');
                $unknown = array_diff(array_keys($in), ['label', 'is_hidden', 'sort_order']);
                if ($unknown) throw new McpToolError('Champ(s) non modifiable(s) : ' . implode(', ', $unknown) . '.');
                $new = [];
                if (isset($in['label'])) $new['label'] = mcp_str($in['label'], 60);
                if (array_key_exists('is_hidden', $in)) $new['is_hidden'] = mcp_bool($in['is_hidden']);
                if (array_key_exists('sort_order', $in)) $new['sort_order'] = mcp_int($in['sort_order'], -1000, 1000, 'sort_order');
                $before = array_intersect_key($r, $new);
                if ($before == $new) return ['applique' => false, 'inchange' => true];
                mcp_backup_before_write();
                db()->prepare('UPDATE product_photos SET ' . implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($new))) . ' WHERE id = :__id')->execute($new + ['__id' => $id]);
                sync_cover_photo((string) $r['product_ref']);
                return ['applique' => true, 'avant' => $before, 'apres' => $new, '_audit' => [$before, $new]];
            },
        ],
    ];
}
