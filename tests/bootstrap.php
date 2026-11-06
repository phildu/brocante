<?php

// Charge les helpers purs testés en CLI : la chaîne config.php est sans effet sous PHP_SAPI === 'cli'.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/saas-events.php'; // saas_money() pour saas_price_text()
require_once __DIR__ . '/../includes/saas-pricing.php';
