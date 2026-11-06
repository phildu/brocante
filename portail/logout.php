<?php
require __DIR__ . '/_bootstrap.php';

unset($_SESSION['portail_user']);
session_regenerate_id(true);
header('Location: /portail/');
exit;
