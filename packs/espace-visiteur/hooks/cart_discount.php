<?php
// Filtre de la remise du panier : ajoute la récompense de fidélité choisie par le client ($value = ['cents', 'tier'], $ctx = subtotal, qty, code).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE) || ev_settings()['enabled'] !== '1') return null;
$extra = ev_reward_discount((string) ($ctx['code'] ?? ''), (int) ($ctx['subtotal'] ?? 0), (int) $value['cents']);
if ($extra <= 0) return null;
$base = $value['tier'] ? cart_discount_label($value['tier']) . ' + ' : '';
return ['cents' => (int) $value['cents'] + $extra,
    'tier' => ['label' => $base . 'récompense fidélité', 'type' => 'amount', 'kind' => 'total', 'threshold' => 0, 'value' => (int) $value['cents'] + $extra],
    'reward' => ['code' => strtoupper((string) $ctx['code']), 'cents' => $extra]];
