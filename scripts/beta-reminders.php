<?php
// Rappels de retour aux testeurs des modules « En test » (option du module). À planifier chaque jour : php scripts/beta-reminders.php
PHP_SAPI === 'cli' || exit;
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/addons.php';
echo addon_beta_send_reminders(in_array('--dry', $argv, true)) . " rappel(s)\n";
