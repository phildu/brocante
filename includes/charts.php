<?php
/**
 * Graphiques SVG générés côté serveur pour le tableau de bord : aucune
 * bibliothèque JavaScript, aucun appel externe, couleurs prises dans les
 * variables CSS du site (donc cohérentes en mode clair et sombre).
 */

/** 1 250 → « 1,3 k€ », 980 → « 980 € » (valeur en centimes). */
function chart_money_short(float $cents): string
{
    $eur = $cents / 100;
    $abs = abs($eur);
    if ($abs >= 10000) return number_format($eur / 1000, 0, ',', ' ') . ' k€';
    if ($abs >= 1000) return number_format($eur / 1000, 1, ',', ' ') . ' k€';
    return number_format($eur, 0, ',', ' ') . ' €';
}

function chart_nice_ticks(float $min, float $max, int $target = 4): array
{
    if ($max <= $min) $max = $min + 1;
    $span = $max - $min;
    $raw = $span / $target;
    $pow = 10 ** floor(log10($raw));
    $step = $pow;
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        $step = $m * $pow;
        if ($span / $step <= $target) break;
    }
    $lo = floor($min / $step) * $step;
    $hi = ceil($max / $step) * $step;
    $ticks = [];
    for ($v = $lo; $v <= $hi + $step / 2; $v += $step) $ticks[] = $v;
    return $ticks;
}

/**
 * @param string[] $labels Libellés de l'axe X
 * @param array<int,array{name:string,type:string,values:array,color:string,dashed?:bool,stack?:string}> $series
 *        type = 'bar' | 'line' ; les barres de même `stack` sont empilées, sinon groupées
 * @param array<int,array{value:float,label:string,color:string}> $hlines Lignes horizontales de repère (ex : seuil de rentabilité)
 * @param callable $fmt Formateur de valeur (pour axe et info-bulles)
 */
function dash_chart(array $labels, array $series, array $hlines = [], ?callable $fmt = null, string $ariaLabel = 'Graphique', int $labelEvery = 1): string
{
    $fmt ??= fn($v) => chart_money_short($v);
    $n = count($labels);
    if ($n === 0) return '';

    $W = 720; $H = 260; $padL = 54; $padR = 12; $padT = 14; $padB = 30;
    $plotW = $W - $padL - $padR; $plotH = $H - $padT - $padB;

    // Bornes : on empile les barres d'un même groupe `stack`.
    $min = 0.0; $max = 0.0;
    $stacks = [];
    foreach ($series as $si => $s) {
        if ($s['type'] === 'bar' && isset($s['stack'])) $stacks[$s['stack']][] = $si;
    }
    for ($i = 0; $i < $n; $i++) {
        foreach ($series as $si => $s) {
            $v = (float) ($s['values'][$i] ?? 0);
            if ($s['type'] === 'line' || !isset($s['stack'])) { $min = min($min, $v); $max = max($max, $v); }
        }
        foreach ($stacks as $idxs) {
            $pos = $neg = 0.0;
            foreach ($idxs as $si) { $v = (float) ($series[$si]['values'][$i] ?? 0); if ($v >= 0) $pos += $v; else $neg += $v; }
            $min = min($min, $neg); $max = max($max, $pos);
        }
    }
    foreach ($hlines as $hl) { $min = min($min, $hl['value']); $max = max($max, $hl['value']); }
    $ticks = chart_nice_ticks($min, $max <= 0 ? 1 : $max);
    $yMin = $ticks[0]; $yMax = end($ticks);
    $y = fn(float $v) => $padT + $plotH - ($v - $yMin) / ($yMax - $yMin) * $plotH;
    $x0 = fn(int $i) => $padL + $plotW * $i / $n;
    $slot = $plotW / $n;

    $barSeries = array_values(array_filter(array_keys($series), fn($si) => $series[$si]['type'] === 'bar'));
    // Chaque série de barres non empilée, et chaque pile, occupe une « colonne » dans le groupe.
    $columns = [];
    foreach ($barSeries as $si) {
        $key = $series[$si]['stack'] ?? ('s' . $si);
        if (!in_array($key, $columns, true)) $columns[] = $key;
    }
    $nc = max(1, count($columns));
    $groupW = $slot * 0.74;
    $barW = $groupW / $nc;

    ob_start();
    ?>
<svg class="dash-chart" viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= h($ariaLabel) ?>" preserveAspectRatio="xMidYMid meet">
  <?php foreach ($ticks as $t): ?>
    <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($y($t), 1) ?>" y2="<?= round($y($t), 1) ?>" style="stroke:var(--line);stroke-width:<?= abs($t) < 1e-9 ? 1.4 : 0.6 ?>;<?= abs($t) < 1e-9 ? '' : 'stroke-dasharray:3 3;' ?>"/>
    <text x="<?= $padL - 8 ?>" y="<?= round($y($t) + 4, 1) ?>" text-anchor="end" style="fill:var(--ink-soft);font-size:11px;"><?= h($fmt($t)) ?></text>
  <?php endforeach; ?>

  <?php for ($i = 0; $i < $n; $i++): if ($i % max(1, $labelEvery) !== 0) continue; ?>
    <text x="<?= round($x0($i) + $slot / 2, 1) ?>" y="<?= $H - 10 ?>" text-anchor="middle" style="fill:var(--ink-soft);font-size:11px;"><?= h($labels[$i]) ?></text>
  <?php endfor; ?>

  <?php foreach ($barSeries as $si):
      $s = $series[$si];
      $col = array_search($s['stack'] ?? ('s' . $si), $columns, true);
      $stackKey = $s['stack'] ?? null;
      for ($i = 0; $i < $n; $i++):
          $v = (float) ($s['values'][$i] ?? 0);
          if (abs($v) < 1e-9) continue;
          // base d'une barre empilée : somme des séries précédentes de la pile, de même signe
          $base = 0.0;
          if ($stackKey) foreach ($stacks[$stackKey] as $other) {
              if ($other === $si) break;
              $ov = (float) ($series[$other]['values'][$i] ?? 0);
              if (($ov >= 0) === ($v >= 0)) $base += $ov;
          }
          $top = $y($base + $v); $bottom = $y($base);
          $bx = $x0($i) + ($slot - $groupW) / 2 + $barW * $col;
          $by = min($top, $bottom); $bh = max(1.0, abs($bottom - $top));
  ?>
    <rect x="<?= round($bx + 1, 1) ?>" y="<?= round($by, 1) ?>" width="<?= round(max(1, $barW - 2), 1) ?>" height="<?= round($bh, 1) ?>" rx="1.5" style="fill:<?= h($s['color']) ?>;">
      <title><?= h($labels[$i] . ' — ' . $s['name'] . ' : ' . $fmt($v)) ?></title>
    </rect>
  <?php endfor; endforeach; ?>

  <?php foreach ($hlines as $hl): ?>
    <line x1="<?= $padL ?>" x2="<?= $W - $padR ?>" y1="<?= round($y($hl['value']), 1) ?>" y2="<?= round($y($hl['value']), 1) ?>" style="stroke:<?= h($hl['color']) ?>;stroke-width:1.6;stroke-dasharray:6 4;"/>
    <text x="<?= $W - $padR - 4 ?>" y="<?= round($y($hl['value']) - 5, 1) ?>" text-anchor="end" style="fill:<?= h($hl['color']) ?>;font-size:11px;font-weight:600;"><?= h($hl['label']) ?></text>
  <?php endforeach; ?>

  <?php foreach ($series as $s): if ($s['type'] !== 'line') continue;
      // Une valeur null interrompt la courbe (ex : jours à venir) au lieu de la faire retomber à zéro.
      $segments = []; $cur = [];
      for ($i = 0; $i < $n; $i++) {
          $v = $s['values'][$i] ?? null;
          if ($v === null) { if ($cur) $segments[] = $cur; $cur = []; continue; }
          $cur[] = round($x0($i) + $slot / 2, 1) . ',' . round($y((float) $v), 1);
      }
      if ($cur) $segments[] = $cur;
      foreach ($segments as $seg): if (count($seg) < 2) continue; ?>
    <polyline points="<?= implode(' ', $seg) ?>" style="fill:none;stroke:<?= h($s['color']) ?>;stroke-width:2.2;stroke-linejoin:round;stroke-linecap:round;<?= !empty($s['dashed']) ? 'stroke-dasharray:6 4;' : '' ?>"/>
    <?php endforeach; ?>
    <?php if ($n <= 31): for ($i = 0; $i < $n; $i++): if (($s['values'][$i] ?? null) === null) continue; $px = $x0($i) + $slot / 2; $py = $y((float) $s['values'][$i]); ?>
      <circle cx="<?= round($px, 1) ?>" cy="<?= round($py, 1) ?>" r="<?= $n > 14 ? 2 : 3 ?>" style="fill:<?= h($s['color']) ?>;"><title><?= h($labels[$i] . ' — ' . $s['name'] . ' : ' . $fmt((float) ($s['values'][$i] ?? 0))) ?></title></circle>
    <?php endfor; endif; ?>
  <?php endforeach; ?>
</svg>
<?php
    return (string) ob_get_clean();
}

/** Légende HTML des séries d'un graphique. */
function dash_legend(array $items): string
{
    $out = '<ul class="dash-legend">';
    foreach ($items as $it) {
        $out .= '<li><span class="sw' . (!empty($it['dashed']) ? ' dashed' : '') . '" style="background:' . h($it['color']) . ';' . (!empty($it['dashed']) ? 'background:none;border-top:2px dashed ' . h($it['color']) . ';height:0;' : '') . '"></span>' . h($it['name']) . '</li>';
    }
    return $out . '</ul>';
}

/** Liste de barres horizontales (provenance, appareils, catégories…). */
function dash_hbars(array $rows, ?callable $fmt = null, string $color = 'var(--accent)'): string
{
    if (!$rows) return '<p class="dash-empty">Pas encore de données.</p>';
    $max = max(array_map(fn($r) => (float) $r['value'], $rows)) ?: 1;
    $out = '<ul class="dash-hbars">';
    foreach ($rows as $r) {
        $pct = max(1.5, (float) $r['value'] / $max * 100);
        $val = $fmt ? $fmt($r['value']) : number_format((float) $r['value'], 0, ',', ' ');
        $out .= '<li><span class="hb-label">' . h($r['label']) . '</span>'
              . '<span class="hb-track"><span class="hb-fill" style="width:' . round($pct, 1) . '%;background:' . h($r['color'] ?? $color) . ';"></span></span>'
              . '<span class="hb-val">' . h($val) . (isset($r['extra']) ? ' <small>' . h($r['extra']) . '</small>' : '') . '</span></li>';
    }
    return $out . '</ul>';
}
