<?php
// Fiche pièce : rappel de l'opération en cours sur cette pièce.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/aco.php';
if (!aco_enabled() || empty($product['ref'])) return;
$op = aco_operation_for_ref((string) $product['ref']);
if (!$op) return;
$label = trim((string) $op['label']) ?: aco_kind_label((string) $op['kind']);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<p class="aco-product-note hint" style="margin:8px 0 0;">
  <strong><?= $h($label) ?></strong> · <?= $h(aco_discount_text($op)) ?> · prix réduit jusqu'au <?= $h(date('d/m/Y à H:i', (int) $op['ends_at'])) ?><?= $op['place'] !== '' ? ' · ' . $h($op['place']) : '' ?>
</p>
