<?php

// Compteur d'impact environnemental : CO₂ et eau ÉVITÉS par le réemploi (une pièce d'occasion vendue évite d'en produire une neuve).
// Ordres de grandeur, pas une mesure : facteurs par kilo de produit neuf (ADEME Base Empreinte, Water Footprint Network) × poids de la
// pièce. Ils sont regroupés ici pour être corrigés à un seul endroit. Ventes comptées :
//   - commandes payées du site (table orders) ;
//   - pièces marquées vendues (products.sold_at) par canal : Leboncoin, Vinted, Facebook, marché, stand… hors commandes du site ;
//   - ventes de la boutique physique saisies à la main (physical_visits), au poids moyen du catalogue.
// Côté « coût » : seul l'usage de l'IA (api_usage) est estimé ; l'hébergement et les trajets ne sont pas comptés.

/** Part de l'impact du neuf réellement évitée (nettoyage, transport et emballage de la pièce d'occasion). */
const IMPACT_REUSE_RATE = 0.9;
/** kg CO₂e d'une voiture moyenne au kilomètre, litres d'une douche : équivalences parlantes. */
const IMPACT_KG_PER_KM = 0.22;
const IMPACT_LITRES_PER_SHOWER = 60;

/** Profils par famille d'objets : kg CO₂e et litres d'eau par kilo de produit neuf, poids moyen (g) si la fiche n'en a pas. */
function impact_profiles(): array
{
    return [
        'ceramique' => ['label' => 'Céramique & verre', 'co2' => 2.5, 'water' => 30, 'grams' => 700],
        'bois' => ['label' => 'Bois & mobilier', 'co2' => 3.5, 'water' => 60, 'grams' => 4000],
        'textile' => ['label' => 'Textile', 'co2' => 20.0, 'water' => 7000, 'grams' => 600],
        'lumiere' => ['label' => 'Lumière', 'co2' => 6.0, 'water' => 250, 'grams' => 1500],
        'jardin' => ['label' => 'Jardin', 'co2' => 2.0, 'water' => 25, 'grams' => 2000],
        'livre' => ['label' => 'Livres & papier', 'co2' => 2.5, 'water' => 250, 'grams' => 400],
        'curiosites' => ['label' => 'Curiosités & divers', 'co2' => 4.0, 'water' => 100, 'grams' => 1000],
    ];
}

/** Famille d'objets d'une catégorie de boutique (clé libre : « romans », « bois », « vetements »…). */
function impact_family(string $cat): string
{
    $cat = mb_strtolower(trim($cat));
    $rules = [
        'livre' => '/livre|roman|bd|bande|jeunesse|papier|disque/u',
        'textile' => '/textile|vetement|vêtement|mode|linge|plaid|tissu/u',
        'bois' => '/bois|meuble|mobilier/u',
        'lumiere' => '/lumi|lampe|luminaire/u',
        'jardin' => '/jardin|outil/u',
        'ceramique' => '/cerami|vaisselle|verre|faience|faïence|porcelaine/u',
    ];
    foreach ($rules as $family => $re) {
        if (preg_match($re, $cat)) return $family;
    }
    return 'curiosites';
}

/** Impact évité par UNE pièce vendue : ['co2' => kg, 'water' => litres, 'family' => clé]. */
function impact_piece(string $cat, int $weightGrams = 0): array
{
    $family = impact_family($cat);
    $p = impact_profiles()[$family];
    $kg = ($weightGrams > 0 ? $weightGrams : $p['grams']) / 1000;
    return ['co2' => $kg * $p['co2'] * IMPACT_REUSE_RATE, 'water' => $kg * $p['water'] * IMPACT_REUSE_RATE, 'family' => $family];
}

/** Groupe d'un canal de vente : marketplace (places de marché), online (site du commerce), physical (points de vente). */
function impact_channel_group(string $channel): string
{
    return match ($channel) {
        'leboncoin', 'vinted', 'facebook' => 'marketplace',
        'ecommerce' => 'online',
        default => 'physical',
    };
}

function impact_channel_label(string $channel): string
{
    $own = ['stand' => 'Stand / caisse', 'reservation' => 'Réservation', 'ecommerce' => 'Site e-commerce', 'boutique' => 'Boutique physique'];
    if (isset($own[$channel])) return $own[$channel];
    if (function_exists('product_channels')) {
        $all = product_channels();
        if (isset($all[$channel])) return $all[$channel];
    }
    return $channel === '' || $channel === 'autre' ? 'Autre canal' : ucfirst($channel);
}

/** Coût numérique d'un appel d'IA, d'après les ordres de grandeur publiés (texte ≈ 0,03 g et 0,26 mL ; image bien plus). */
function impact_digital_unit(string $kind): array
{
    return match ($kind) {
        'gemini_image' => ['co2' => 0.003, 'water' => 0.03],      // kg CO₂e, litres
        'fal_cutout' => ['co2' => 0.001, 'water' => 0.01],
        default => ['co2' => 0.00003, 'water' => 0.00026],
    };
}

/** « 12,3 kg » ou « 1,2 t » de CO₂e. */
function impact_fmt_co2(float $kg): string
{
    if ($kg >= 1000) return number_format($kg / 1000, 1, ',', ' ') . ' t';
    return number_format($kg, $kg < 10 ? 1 : 0, ',', ' ') . ' kg';
}

/** « 850 L » ou « 12,4 m³ » d'eau. */
function impact_fmt_water(float $litres): string
{
    if ($litres >= 10000) return number_format($litres / 1000, 1, ',', ' ') . ' m³';
    return number_format($litres, 0, ',', ' ') . ' L';
}

/**
 * Cœur du calcul, sans base de données.
 * $lines : une entrée par pièce vendue, ['channel' => clé, 'cat' => catégorie, 'weight' => grammes, 'qty' => nombre].
 * $physicalCount : ventes de boutique saisies à la main, $avgPiece : impact moyen d'une pièce du catalogue (impact_piece).
 * $aiCalls : ['gemini_image' => n, …].
 */
function impact_aggregate(array $lines, int $physicalCount = 0, ?array $avgPiece = null, array $aiCalls = []): array
{
    $groups = [
        'marketplace' => ['label' => 'Places de marché', 'rows' => ['leboncoin' => null, 'vinted' => null, 'facebook' => null]],
        'online' => ['label' => 'Site du commerce', 'rows' => ['ecommerce' => null]],
        'physical' => ['label' => 'Points de vente', 'rows' => ['boutique' => null, 'stand' => null, 'marche' => null]],
    ];
    $empty = static fn (): array => ['pieces' => 0, 'co2' => 0.0, 'water' => 0.0];
    foreach ($groups as $g => $def) {
        $groups[$g]['total'] = $empty();
        foreach ($def['rows'] as $key => $_) $groups[$g]['rows'][$key] = $empty();
    }
    $families = [];
    $total = $empty();

    $add = static function (string $channel, string $family, int $pieces, float $co2, float $water) use (&$groups, &$families, &$total, $empty): void {
        $g = impact_channel_group($channel);
        $groups[$g]['rows'][$channel] ??= $empty();
        foreach ([&$groups[$g]['rows'][$channel], &$groups[$g]['total'], &$total] as &$bucket) {
            $bucket['pieces'] += $pieces;
            $bucket['co2'] += $co2;
            $bucket['water'] += $water;
        }
        unset($bucket);
        $families[$family] ??= $empty();
        $families[$family]['pieces'] += $pieces;
        $families[$family]['co2'] += $co2;
        $families[$family]['water'] += $water;
    };

    foreach ($lines as $l) {
        $qty = max(0, (int) ($l['qty'] ?? 1));
        if ($qty === 0) continue;
        $piece = impact_piece((string) ($l['cat'] ?? ''), (int) ($l['weight'] ?? 0));
        $add((string) ($l['channel'] ?? 'autre') ?: 'autre', $piece['family'], $qty, $piece['co2'] * $qty, $piece['water'] * $qty);
    }
    if ($physicalCount > 0) {
        $piece = $avgPiece ?? impact_piece('curiosites');
        $add('boutique', (string) ($piece['family'] ?? 'curiosites'), $physicalCount, $piece['co2'] * $physicalCount, $piece['water'] * $physicalCount);
    }

    $dCo2 = 0.0;
    $dWater = 0.0;
    $calls = 0;
    foreach ($aiCalls as $kind => $n) {
        $u = impact_digital_unit((string) $kind);
        $dCo2 += $u['co2'] * (int) $n;
        $dWater += $u['water'] * (int) $n;
        $calls += (int) $n;
    }
    uasort($families, static fn (array $a, array $b): int => $b['co2'] <=> $a['co2']);

    return [
        'total' => $total,
        'groups' => $groups,
        'families' => $families,
        'digital' => ['calls' => $calls, 'co2' => $dCo2, 'water' => $dWater],
        'net' => ['co2' => $total['co2'] - $dCo2, 'water' => $total['water'] - $dWater],
        'km' => $total['co2'] / IMPACT_KG_PER_KM,
        'showers' => $total['water'] / IMPACT_LITRES_PER_SHOWER,
    ];
}

/** Bilan d'un commerce (le courant, ou celui de la base $pdo) sur les $days derniers jours (0 = depuis le début). */
function impact_report(int $days = 0, ?PDO $pdo = null): array
{
    $own = $pdo === null;
    $pdo ??= db();
    if ($own && function_exists('analytics_ensure_schema')) {
        try {
            analytics_ensure_schema();
        } catch (Throwable $e) {
            // tables facultatives : le bilan s'établit sans elles
        }
    }
    $since = $days > 0 ? gmdate('Y-m-d H:i:s', time() - $days * 86400) : '0000-00-00 00:00:00';
    $sinceDay = $days > 0 ? date('Y-m-d', time() - $days * 86400) : '0000-00-00';

    $catalog = [];
    foreach ($pdo->query('SELECT ref, cat, weight_grams FROM products')->fetchAll(PDO::FETCH_ASSOC) as $p) $catalog[$p['ref']] = $p;

    $lines = [];
    $orderedRefs = [];
    foreach ($pdo->query("SELECT items, created_at FROM orders WHERE status = 'paid'")->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $items = json_decode((string) $o['items'], true) ?: [];
        foreach ($items as $it) {
            $ref = (string) ($it['ref'] ?? '');
            $orderedRefs[$ref] = true;
            if ($o['created_at'] < $since) continue;
            $lines[] = ['channel' => 'ecommerce', 'cat' => (string) ($catalog[$ref]['cat'] ?? ''), 'weight' => (int) ($catalog[$ref]['weight_grams'] ?? 0), 'qty' => max(0, (int) ($it['qty'] ?? 0))];
        }
    }
    $st = $pdo->prepare('SELECT ref, cat, weight_grams, sold_channel FROM products WHERE sold_at IS NOT NULL AND stock <= 0 AND sold_at >= ?');
    $st->execute([$since]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        if (isset($orderedRefs[$p['ref']])) continue;   // déjà comptée avec la commande du site
        $lines[] = ['channel' => (string) $p['sold_channel'], 'cat' => (string) $p['cat'], 'weight' => (int) $p['weight_grams'], 'qty' => 1];
    }

    $physical = 0;
    $aiCalls = [];
    try {
        $st = $pdo->prepare('SELECT COALESCE(SUM(sales_count), 0) FROM physical_visits WHERE day >= ?');
        $st->execute([$sinceDay]);
        $physical = (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT kind, COUNT(*) AS n FROM api_usage WHERE ts >= ? GROUP BY kind');
        $st->execute([$since]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $aiCalls[$r['kind']] = (int) $r['n'];
    } catch (Throwable $e) {
        // tables absentes sur une très ancienne base : on les ignore
    }

    $avg = null;
    if ($physical > 0 && $catalog) {
        $co2 = $water = 0.0;
        foreach ($catalog as $p) {
            $x = impact_piece((string) $p['cat'], (int) $p['weight_grams']);
            $co2 += $x['co2'];
            $water += $x['water'];
        }
        $avg = ['co2' => $co2 / count($catalog), 'water' => $water / count($catalog), 'family' => 'curiosites'];
    }

    return impact_aggregate($lines, $physical, $avg, $aiCalls);
}

/** Additionne plusieurs bilans (un par commerce) en un seul, de même forme qu'impact_aggregate(). */
function impact_merge(array $reports): array
{
    $sum = static function (array &$into, array $from, array $keys): void {
        foreach ($keys as $k) $into[$k] = ($into[$k] ?? 0) + ($from[$k] ?? 0);
    };
    $out = impact_aggregate([]);
    foreach ($reports as $r) {
        $sum($out['total'], $r['total'], ['pieces', 'co2', 'water']);
        $sum($out['digital'], $r['digital'], ['calls', 'co2', 'water']);
        foreach ($r['groups'] as $g => $group) {
            $sum($out['groups'][$g]['total'], $group['total'], ['pieces', 'co2', 'water']);
            foreach ($group['rows'] as $channel => $row) {
                $out['groups'][$g]['rows'][$channel] ??= ['pieces' => 0, 'co2' => 0.0, 'water' => 0.0];
                $sum($out['groups'][$g]['rows'][$channel], $row, ['pieces', 'co2', 'water']);
            }
        }
        foreach ($r['families'] as $family => $row) {
            $out['families'][$family] ??= ['pieces' => 0, 'co2' => 0.0, 'water' => 0.0];
            $sum($out['families'][$family], $row, ['pieces', 'co2', 'water']);
        }
    }
    uasort($out['families'], static fn (array $a, array $b): int => $b['co2'] <=> $a['co2']);
    $out['net'] = ['co2' => $out['total']['co2'] - $out['digital']['co2'], 'water' => $out['total']['water'] - $out['digital']['water']];
    $out['km'] = $out['total']['co2'] / IMPACT_KG_PER_KM;
    $out['showers'] = $out['total']['water'] / IMPACT_LITRES_PER_SHOWER;
    return $out;
}

/**
 * Bilan de toute la plateforme : un bilan par commerce (tenants/), puis leur somme.
 * -> ['all' => bilan cumulé, 'shops' => [slug => ['name' => …, 'report' => bilan] | ['name' => …, 'error' => message]]]
 */
function impact_report_all(int $days = 0): array
{
    require_once __DIR__ . '/tenant.php';
    $shops = [];
    $reports = [];
    foreach (tenant_list() as $slug => $config) {
        $name = (string) ($config['name'] ?? $slug);
        try {
            $file = tenant_file($config, 'db_file');
            if (!is_file($file)) {
                $shops[$slug] = ['name' => $name, 'error' => 'base pas encore créée'];
                continue;
            }
            $pdo = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $report = impact_report($days, $pdo);
            $shops[$slug] = ['name' => $name, 'report' => $report];
            $reports[] = $report;
        } catch (Throwable $e) {
            $shops[$slug] = ['name' => $name, 'error' => 'bilan impossible'];
        }
    }
    uasort($shops, static fn (array $a, array $b): int => ($b['report']['total']['co2'] ?? -1) <=> ($a['report']['total']['co2'] ?? -1));
    return ['all' => impact_merge($reports), 'shops' => $shops];
}
