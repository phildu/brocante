<?php
// Paiement ouvert avec une récompense de fidélité : elle est réservée pour cette commande jusqu'au paiement.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE) || empty($payload['code'])) return;
ev_reward_reserve((string) $payload['code'], (int) ($payload['order_id'] ?? 0));
