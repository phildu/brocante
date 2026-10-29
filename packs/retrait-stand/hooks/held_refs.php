<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rt.php';
return addon_is_entitled(RT_MODULE) ? rt_held() : [];
