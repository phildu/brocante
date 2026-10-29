<?php
// Commande payée : points de fidélité crédités et récompense utilisée soldée.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';

if (!addon_is_entitled(EV_MODULE)) return;
ev_order_paid((int) ($payload['order_id'] ?? 0));
