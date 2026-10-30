<?php
// Bas de la boutique : braderie en cours ou annoncée dans les sept jours (si la bannière est activée).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';
if (!aco_enabled()) return;
$op = aco_front_operation();
if (!$op || empty($op['show_banner'])) return;
$running = $op['status'] === 'running';
$label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
$discount = aco_discount_text($op);
$text = trim((string) $op['banner_text']);
if ($text === '') {
    $text = $running
        ? $label . ' en cours : ' . $discount . ' sur une sélection.'
        : $label . ' ' . aco_period_short($op) . ' : ' . $discount . ' sur une sélection.';
}
$when = $running ? 'jusqu\'au ' . date('d/m/Y à H:i', (int) $op['ends_at']) : aco_period_short($op);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<section class="aco-banner" style="max-width:920px;margin:32px auto 0;padding:16px 20px;display:flex;gap:10px 16px;flex-wrap:wrap;align-items:center;border:1px solid var(--line);background:var(--bg-soft, #f6f3ee);">
  <span style="font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;font-weight:700;padding:4px 10px;background:var(--accent);color:var(--accent-ink);"><?= $h($label) ?></span>
  <p style="margin:0;flex:1;min-width:220px;"><?= $h($text) ?></p>
  <p class="hint" style="margin:0;"><?= $h($when) ?><?= $op['place'] !== '' ? ' · ' . $h($op['place']) : '' ?></p>
</section>
