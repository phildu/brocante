<?php
// « Palettes des visuels » (administrateurs) : la partie palettes de la page Apparence d'electroboy80, portée dans le cadre commun.
// L'IA (Gemini ou Claude, quota de la boutique) compose 10 palettes et leurs 5 nuances à partir des couleurs du thème de la
// boutique ; l'éditeur visuel les propose dans sa section « Palettes ». Les couleurs du site d'electroboy80 sont masquées
// (celles de la boutique se règlent dans son Apparence).
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/eb80-frame.php';
com_eb80_frame($plugin, $pluginCsrf, $role, 'appearance', 'Palettes des visuels');
