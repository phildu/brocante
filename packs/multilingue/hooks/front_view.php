<?php
// Affichage de la version figée : la langue choisie (?lang=xx ou cookie) est retenue, puis la page entière est
// traduite en fin de rendu à partir du fichier de langue — aucun appel à l'IA pendant la visite. Dès qu'une
// traduction est publiée, le sélecteur de langue apparaît aussi sur la page française.
defined('PLUGIN_RUNTIME') || exit;

require_once dirname(__DIR__) . '/lib/ml.php';

ml_cookie_sync();

if (!ml_should_serve()) return;
if (!empty($GLOBALS['ml_buffer_started'])) return;   // une page peut inclure l'en-tête deux fois
$GLOBALS['ml_buffer_started'] = true;

ob_start('ml_buffer');
