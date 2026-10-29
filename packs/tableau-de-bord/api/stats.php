<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/td.php';

if (!addon_is_entitled(TD_MODULE)) plugin_json(['error' => "Module non actif : démarrez l'essai gratuit ou souscrivez."], 402);
require_once dirname(__DIR__, 3) . '/includes/share.php';
$prods = db()->query('SELECT ref, name FROM products WHERE is_hidden = 0 ORDER BY created_at DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
plugin_json(['stats' => td_stats((int) ($_GET['days'] ?? 30)), 'products' => $prods, 'shop_url' => shop_abs_url('/boutique.php'), 'product_url' => shop_abs_url('/produit.php')]);
