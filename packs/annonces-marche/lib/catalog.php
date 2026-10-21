<?php
// Catalogue exposé à l'extension : produits en stock, visibles, avec photos absolues.

require_once __DIR__ . '/settings.php';

/** Mapping état boutique → libellés proches Leboncoin / Vinted (indicatif ; le commerçant peut corriger). */
function am_condition_for_channel(?string $etatKey, string $channel): array
{
    $info = function_exists('product_condition') ? product_condition($etatKey) : null;
    $label = $info[0] ?? '';
    $hint = $info[1] ?? '';
    // Correspondances approximatives (les listes évoluent côté sites).
    $map = [
        'neuf_etiquette' => ['leboncoin' => 'Neuf', 'vinted' => 'Neuf avec étiquette'],
        'neuf_emballe' => ['leboncoin' => 'Neuf', 'vinted' => 'Neuf avec étiquette'],
        'neuf_boite' => ['leboncoin' => 'Neuf', 'vinted' => 'Neuf sans étiquette'],
        'neuf' => ['leboncoin' => 'Neuf', 'vinted' => 'Neuf sans étiquette'],
        'comme_neuf' => ['leboncoin' => 'Très bon état', 'vinted' => 'Très bon état'],
        'presque_neuf' => ['leboncoin' => 'Très bon état', 'vinted' => 'Très bon état'],
        'excellent' => ['leboncoin' => 'Très bon état', 'vinted' => 'Très bon état'],
        'tres_bon' => ['leboncoin' => 'Très bon état', 'vinted' => 'Très bon état'],
        'bon' => ['leboncoin' => 'Bon état', 'vinted' => 'Bon état'],
        'bon_age' => ['leboncoin' => 'Bon état', 'vinted' => 'Satisfaisant'],
        'patine' => ['leboncoin' => 'Bon état', 'vinted' => 'Satisfaisant'],
        'correct' => ['leboncoin' => 'État correct', 'vinted' => 'Satisfaisant'],
        'usage' => ['leboncoin' => 'État correct', 'vinted' => 'Satisfaisant'],
        'defauts' => ['leboncoin' => 'État correct', 'vinted' => 'Satisfaisant'],
        'use' => ['leboncoin' => 'Pour pièces', 'vinted' => 'Satisfaisant'],
        'restaurer' => ['leboncoin' => 'Pour pièces', 'vinted' => 'Satisfaisant'],
        'pieces' => ['leboncoin' => 'Pour pièces', 'vinted' => 'Satisfaisant'],
    ];
    $ch = $map[(string) $etatKey][$channel] ?? ($label !== '' ? $label : '');
    return ['key' => (string) $etatKey, 'label' => $label, 'hint' => $hint, 'channel_label' => $ch];
}

/** Prix en euros (nombre) pour les formulaires de dépôt, ou null. */
function am_price_euros(array $p): ?float
{
    $cents = function_exists('price_to_cents') ? price_to_cents(effective_price($p)) : null;
    return $cents === null ? null : round($cents / 100, 2);
}

/** Description prête pour une annonce (longueur raisonnable pour LBC / Vinted). */
function am_listing_description(array $p): string
{
    $parts = [];
    $desc = trim((string) ($p['description'] ?? ''));
    if ($desc !== '') $parts[] = $desc;
    $bits = [];
    if (!empty($p['brand'])) $bits[] = 'Marque : ' . $p['brand'];
    if (!empty($p['materials'])) $bits[] = 'Matières : ' . $p['materials'];
    if (!empty($p['colors'])) $bits[] = 'Couleurs : ' . $p['colors'];
    if (!empty($p['size_text'])) $bits[] = 'Dimensions / taille : ' . $p['size_text'];
    $etat = function_exists('product_condition') ? product_condition($p['etat'] ?? null) : null;
    if ($etat) $bits[] = 'État : ' . $etat[0] . ($etat[1] !== '' ? ' — ' . $etat[1] : '');
    if ($bits) $parts[] = implode("\n", $bits);
    $shop = function_exists('get_content') ? (string) (get_content()['site_name'] ?? '') : '';
    if ($shop !== '') $parts[] = 'Article proposé par « ' . $shop . ' ». Remise en main propre ou envoi possible selon l\'annonce.';
    $text = trim(implode("\n\n", $parts));
    return mb_substr($text, 0, 4000);
}

/** Photos absolues (hors vidéos, hors masquées), max 8. */
function am_product_photos(array $p): array
{
    $out = [];
    if (function_exists('product_gallery')) {
        foreach (product_gallery($p) as $g) {
            if (($g['type'] ?? 'photo') === 'video') continue;
            $src = (string) ($g['src'] ?? '');
            if ($src === '') continue;
            $out[] = [
                'url' => function_exists('share_media_url') ? share_media_url($src) : $src,
                'label' => (string) ($g['label'] ?? 'Photo'),
            ];
            if (count($out) >= 8) break;
        }
    }
    if (!$out && !empty($p['photo'])) {
        $out[] = [
            'url' => function_exists('share_media_url') ? share_media_url($p['photo']) : $p['photo'],
            'label' => 'Photo',
        ];
    }
    return $out;
}

/** Fiche produit normalisée pour l'extension. */
function am_product_payload(array $p): array
{
    $price = am_price_euros($p);
    return [
        'ref' => (string) $p['ref'],
        'name' => (string) $p['name'],
        'title' => mb_substr(trim((string) $p['name']), 0, 70),
        'description' => am_listing_description($p),
        'price' => $price,
        'price_text' => (string) (function_exists('effective_price') ? effective_price($p) : ($p['price'] ?? '')),
        'category' => (string) ($p['cat'] ?? ''),
        'brand' => (string) ($p['brand'] ?? ''),
        'size_text' => (string) ($p['size_text'] ?? ''),
        'materials' => (string) ($p['materials'] ?? ''),
        'colors' => (string) ($p['colors'] ?? ''),
        'nature' => (string) ($p['nature'] ?? ''),
        'sous_categorie' => (string) ($p['sous_categorie'] ?? ''),
        'stock' => (int) ($p['stock'] ?? 0),
        'condition' => [
            'leboncoin' => am_condition_for_channel($p['etat'] ?? null, 'leboncoin'),
            'vinted' => am_condition_for_channel($p['etat'] ?? null, 'vinted'),
        ],
        'photos' => am_product_photos($p),
        'product_url' => function_exists('shop_abs_url') ? shop_abs_url('/produit.php?ref=' . rawurlencode((string) $p['ref'])) : '',
    ];
}

/**
 * Liste paginée des produits exportables (en stock, visibles).
 * $q : recherche libre ; $limit 1–50 ; $offset ≥ 0.
 */
function am_catalogue(string $q = '', int $limit = 30, int $offset = 0): array
{
    $limit = max(1, min(50, $limit));
    $offset = max(0, $offset);
    $where = ['is_hidden = 0', 'stock > 0'];
    $params = [];
    $q = trim($q);
    if ($q !== '') {
        $where[] = '(name LIKE ? OR ref LIKE ? OR brand LIKE ? OR description LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like, $like];
    }
    $sqlWhere = implode(' AND ', $where);
    $count = db()->prepare("SELECT COUNT(*) FROM products WHERE $sqlWhere");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $stmt = db()->prepare("SELECT * FROM products WHERE $sqlWhere ORDER BY sort_order, ref LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $items = array_map('am_product_payload', $stmt->fetchAll(PDO::FETCH_ASSOC));
    return ['total' => $total, 'items' => $items, 'limit' => $limit, 'offset' => $offset];
}

function am_product_by_ref(string $ref): ?array
{
    $stmt = db()->prepare('SELECT * FROM products WHERE ref = ? AND is_hidden = 0 AND stock > 0');
    $stmt->execute([$ref]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    return $p ? am_product_payload($p) : null;
}
