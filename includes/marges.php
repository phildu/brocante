<?php

// Marges moyennes par type de galerie : table réglée dans le portail (Revenus → Marge & rentabilité), préremplie par l'IA.
// Elle sert aux pages de vente à calculer le CA de rentabilité d'une formule :
//   CA de rentabilité = prix de la formule (ramené au mois) ÷ taux de marge.
// Exemple : offre à 39 € / mois et marge de 60 % → 39 ÷ 0,60 = 65 € de ventes par mois pour couvrir la formule.

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/gallery-types.php';

function marge_file(): string
{
    return saas_data_dir() . '/saas/marges-types.json';
}

/** Taux proposés au départ : part du prix de vente qui reste après l'achat des pièces et les frais directs. */
function marge_defaults(): array
{
    return [
        'marchande' => ['pct' => 55, 'note' => 'Antiquaires et brocanteurs : achat des pièces, restauration, transport.'],
        'puces' => ['pct' => 60, 'note' => 'Marchands professionnels : achat, stockage et préparation des pièces.'],
        'vide-grenier' => ['pct' => 100, 'note' => 'Particuliers : objets déjà possédés, aucun achat à déduire.'],
        'quartier' => ['pct' => 45, 'note' => 'Commerces de proximité : achat des marchandises et frais de vente.'],
        'marche-de-noel' => ['pct' => 50, 'note' => 'Artisans et créateurs : matières premières, production et emballage.'],
        'marche-producteurs' => ['pct' => 55, 'note' => 'Producteurs et créateurs : matières premières, production et emballage.'],
    ];
}

/** Table complète (un taux par type, y compris les types créés après coup), dans l'ordre des types. */
function marges_load(): array
{
    $saved = is_file(marge_file()) ? (json_decode((string) file_get_contents(marge_file()), true) ?: []) : [];
    $defaults = marge_defaults();
    $out = [];
    foreach (gt_types() as $key => $type) {
        $base = $defaults[$key] ?? ['pct' => 55, 'note' => (string) ($type['tagline'] ?? '')];
        $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
        $out[$key] = [
            'pct' => max(1, min(100, (int) ($row['pct'] ?? $base['pct']))),
            'note' => mb_substr(trim((string) ($row['note'] ?? $base['note'])), 0, 200),
            'source' => (string) ($row['source'] ?? 'manuel') === 'ia' ? 'ia' : 'manuel',
            'updated' => (int) ($row['updated'] ?? 0),
        ];
    }
    return $out;
}

/** Enregistre la table (toutes les lignes envoyées, bornées). */
function marges_save(array $rows): bool
{
    $clean = [];
    foreach ($rows as $key => $row) {
        if (!is_array($row)) continue;
        $clean[(string) $key] = [
            'pct' => max(1, min(100, (int) ($row['pct'] ?? 55))),
            'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 200),
            'source' => (string) ($row['source'] ?? 'manuel') === 'ia' ? 'ia' : 'manuel',
            'updated' => time(),
        ];
    }
    $dir = dirname(marge_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
    return @file_put_contents(marge_file(), json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

function marge_pct(string $typeKey): int
{
    return (int) (marges_load()[$typeKey]['pct'] ?? 55);
}

/** CA mensuel nécessaire pour couvrir une formule (centimes), ou null si l'offre est gratuite. */
function marge_break_even_cents(?int $monthlyCents, int $pct): ?int
{
    if ($monthlyCents === null || $monthlyCents <= 0 || $pct <= 0) return null;
    return (int) ceil($monthlyCents * 100 / $pct);
}

/** Équivalent mensuel d'une offre annuelle pour le calcul. */
function marge_monthly_cents(array $offer): ?int
{
    $month = $offer['month_cents'] ?? null;
    if ($month !== null && (int) $month > 0) return (int) $month;
    $year = (int) ($offer['year_cents'] ?? 0);
    return $year > 0 ? (int) ceil($year / 12) : null;
}
