<?php
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/td.php';
if (addon_is_entitled(TD_MODULE)) td_track_view();
