<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rc.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') plugin_json(['settings' => rc_settings(), 'regimes' => rc_regimes()]);
rc_settings_save(plugin_input());
plugin_json(['ok' => true, 'settings' => rc_settings()]);
