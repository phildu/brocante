<?php

// Compteur de consommation IA des bannières publiques (accueil du portail et galeries) : journal ajout seul
// data/saas/ai-portal-usage.jsonl, une ligne par génération réussie. Indépendant des compteurs des boutiques
// (table ai_usage de chaque commerce) : ici la clé utilisée est celle de la plateforme.

require_once __DIR__ . '/ai-plans.php';

function banner_ai_usage_file(): string
{
    return saas_ai_dir() . '/ai-portal-usage.jsonl';
}

/** Enregistre une génération réussie (coût estimé au barème en vigueur). Ne casse jamais l'appel : toute erreur est ignorée. */
function banner_ai_usage_log(string $context, string $slug, int $images, string $model = 'gemini-2.5-flash-image'): void
{
    $images = max(0, $images);
    if ($images === 0) return;
    try {
        $unit = saas_ai_unit_costs()['image'];
        $row = ['ts' => time(), 'context' => $context, 'slug' => $slug, 'images' => $images,
            'unit_eur' => round($unit, 4), 'cost_eur' => round($unit * $images, 4), 'model' => $model];
        @file_put_contents(banner_ai_usage_file(), json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        error_log('banner_ai_usage_log : ' . $e->getMessage());
    }
}

/** Totaux du mois courant et depuis l'ouverture : ['month', 'month_images', 'month_eur', 'total_images', 'total_eur', 'last']. */
function banner_ai_usage_summary(?string $month = null): array
{
    $month = $month ?: gmdate('Y-m');
    $out = ['month' => $month, 'month_images' => 0, 'month_eur' => 0.0, 'total_images' => 0, 'total_eur' => 0.0, 'last' => []];
    $file = banner_ai_usage_file();
    if (!is_file($file)) return $out;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $images = max(0, (int) ($r['images'] ?? 0));
        $cost = max(0.0, (float) ($r['cost_eur'] ?? 0));
        $out['total_images'] += $images;
        $out['total_eur'] += $cost;
        if (gmdate('Y-m', (int) ($r['ts'] ?? 0)) === $month) {
            $out['month_images'] += $images;
            $out['month_eur'] += $cost;
        }
        $out['last'][] = $r;
    }
    $out['month_eur'] = round($out['month_eur'], 2);
    $out['total_eur'] = round($out['total_eur'], 2);
    usort($out['last'], static fn (array $a, array $b): int => (int) ($b['ts'] ?? 0) <=> (int) ($a['ts'] ?? 0));
    $out['last'] = array_slice($out['last'], 0, 5);
    return $out;
}

/** Ligne de compteur + détail des dernières générations, prête à insérer dans une carte du portail. */
function banner_ai_usage_html(): string
{
    $s = banner_ai_usage_summary();
    $plural = static fn (int $n): string => $n > 1 ? 's' : '';
    $html = '<p class="meta banner-ai-cost"><strong>Consommation IA des bannières</strong> — ce mois-ci : '
        . (int) $s['month_images'] . ' image' . $plural((int) $s['month_images']) . ' (' . e(ai_format_eur((float) $s['month_eur'])) . ')'
        . ' · depuis l\'ouverture : ' . (int) $s['total_images'] . ' image' . $plural((int) $s['total_images']) . ' (' . e(ai_format_eur((float) $s['total_eur'])) . ').</p>';
    if (!$s['last']) return $html;
    $rows = '';
    foreach ($s['last'] as $r) {
        $label = ($r['context'] ?? '') === 'accueil' ? 'accueil du portail' : 'galerie « ' . (string) ($r['slug'] ?? '') . ' »';
        $rows .= '<li>' . e(date('d/m/Y H:i', (int) ($r['ts'] ?? 0))) . ' — ' . e($label) . ' · '
            . (int) ($r['images'] ?? 0) . ' image' . $plural((int) ($r['images'] ?? 0)) . ' · ' . e(ai_format_eur((float) ($r['cost_eur'] ?? 0))) . '</li>';
    }
    return $html . '<details class="meta banner-ai-cost"><summary>Dernières générations</summary><ul>' . $rows . '</ul></details>';
}
