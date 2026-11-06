<?php
require __DIR__ . '/_bootstrap.php';

unset($_SESSION['portail_user']);
session_regenerate_id(true);
portail_login_page();
