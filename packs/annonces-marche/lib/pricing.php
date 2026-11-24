<?php
// Prix proposés par les plateformes (relevés par l'extension) comparés aux prix de la boutique.
// Le relevé voyage dans la note d'une ligne de am_listings : « [s:8-15] » (min-max en euros, « [s:?] » si illisible).
defined('PLUGIN_RUNTIME') || exit;

/** Tolérance avant de recommander un changement : un prix à moins de 10 % de la fourchette reste « dans la fourchette ». */
const AM_PRICE_TOLERANCE = 0.10;

/** Écart (en part de la borne la plus proche) à partir duquel la différence de prix est signalée comme importante. */
const AM_PRICE_ALERT = 0.30;

/** @return array{min: float, max: float}|null */
function am_suggestion_from_note(string $note): ?array
{
    if (!preg_match('/\[s:(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)\]/', $note, $m)) return null;
    $min = (float) $m[1];
    $max = (float) $m[2];
    return $min > 0 && $max >= $min ? ['min' => $min, 'max' => $max] : null;
}

/**
 * Verdict pour un prix face à une fourchette.
 * @return array{state: string, target: float|null, label: string}  state : lower | raise | ok
 */
function am_price_verdict(float $price, float $min, float $max): array
{
    // « gap » : écart en % de la borne la plus proche ; « big » : à partir de AM_PRICE_ALERT.
    $gap = $price > $max ? ($price - $max) / $max : ($price < $min ? ($min - $price) / $min : 0.0);
    $extra = ['gap' => (int) round($gap * 100), 'big' => $gap >= AM_PRICE_ALERT];
    if ($price > $max * (1 + AM_PRICE_TOLERANCE)) {
        return ['state' => 'lower', 'target' => $max, 'label' => 'Baisser à ' . am_money($max)] + $extra;
    }
    if ($price < $min * (1 - AM_PRICE_TOLERANCE)) {
        return ['state' => 'raise', 'target' => $min, 'label' => 'Monter à ' . am_money($min)] + $extra;
    }
    return ['state' => 'ok', 'target' => null, 'label' => 'Dans la fourchette'] + $extra;
}

function am_money(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' €';
}

/**
 * Une ligne par pièce et par canal : dernier relevé lisible, prix actuel de la boutique, verdict.
 * @return array{rows: list<array<string,mixed>>, unreadable: int}
 */
function am_price_report(): array
{
    $latest = db()->query("SELECT l.product_ref, l.channel, l.note, l.created_at, l.status
        FROM am_listings l
        WHERE l.id IN (SELECT MAX(id) FROM am_listings WHERE note LIKE '%[s:%' GROUP BY product_ref, channel)
        ORDER BY l.id DESC LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    $unreadable = 0;
    $stmt = db()->prepare('SELECT * FROM products WHERE ref = ?');
    foreach ($latest as $l) {
        $s = am_suggestion_from_note((string) $l['note']);
        if (!$s) { $unreadable++; continue; }
        $stmt->execute([$l['product_ref']]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) continue;
        $price = am_price_euros($p);
        $verdict = $price === null ? ['state' => 'unknown', 'target' => null, 'label' => 'Prix boutique absent', 'gap' => 0, 'big' => false] : am_price_verdict($price, $s['min'], $s['max']);
        $rows[] = [
            'ref' => (string) $l['product_ref'],
            'name' => (string) $p['name'],
            'channel' => (string) $l['channel'],
            'price' => $price,
            'min' => $s['min'],
            'max' => $s['max'],
            'state' => $verdict['state'],
            'target' => $verdict['target'],
            'label' => $verdict['label'],
            'gap' => $verdict['gap'],
            'big' => $verdict['big'],
            'read_at' => (string) $l['created_at'],
        ];
    }
    // Gros écarts d'abord, puis à repricer (baisser, monter), puis le reste.
    $order = ['lower' => 0, 'raise' => 1, 'unknown' => 2, 'ok' => 3];
    usort($rows, static fn (array $a, array $b): int => ((int) $b['big'] <=> (int) $a['big']) ?: (($order[$a['state']] ?? 9) <=> ($order[$b['state']] ?? 9)) ?: ($b['gap'] <=> $a['gap']) ?: strcmp($a['ref'], $b['ref']));
    return ['rows' => $rows, 'unreadable' => $unreadable];
}
