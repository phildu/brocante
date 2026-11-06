<?php
// Commande payée : émet le reçu et l'envoie à l'acheteur (si le module est actif et l'envoi automatique coché).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

if (!addon_is_entitled(RC_MODULE)) return;
$receipt = rc_issue((int) ($payload['order_id'] ?? 0));
if ($receipt && rc_settings()['email_auto'] === '1' && empty($receipt['emailed_at'])) rc_send($receipt);
