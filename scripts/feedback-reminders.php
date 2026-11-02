<?php
// Relance des testeurs qui n'ont rien envoyé depuis le délai réglé dans le
// portail (Retours testeurs → Programme) et des renouvellements de domaine à
// échéance. À planifier chaque semaine : php scripts/feedback-reminders.php [--dry]
PHP_SAPI === 'cli' || exit;
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/feedback.php';
require_once dirname(__DIR__) . '/includes/domain-shop.php';
$dry = in_array('--dry', $argv, true);
echo feedback_send_reminders($dry) . " relance(s)\n";
echo domain_shop_renewal_remind($dry) . " renouvellement(s) de domaine\n";
