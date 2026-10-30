<?php
// Relance des testeurs qui n'ont rien envoyé depuis le délai réglé dans le
// portail (Retours testeurs → Programme). À planifier chaque jour :
// php scripts/feedback-reminders.php [--dry]
PHP_SAPI === 'cli' || exit;
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/feedback.php';
echo feedback_send_reminders(in_array('--dry', $argv, true)) . " relance(s)\n";
