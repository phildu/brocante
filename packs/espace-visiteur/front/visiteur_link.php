<?php
// Bandeau de la page d'accueil de la boutique : lien vers l'espace visiteur (visible seulement si le module est actif).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ev.php';
if (!addon_is_entitled(EV_MODULE)) return;
$s = ev_settings();
$sec = ev_sections();
$bits = [];
if ($s['enabled'] === '1') $bits[] = 'vos points de ' . mb_strtolower($s['program_name']);
if ($sec['alertes']) $bits[] = 'vos alertes';
if ($sec['billets']) $bits[] = 'vos réservations et billets';
if ($sec['scan']) $bits[] = "le scan des QR codes";
?>
<section class="visiteur-band" style="max-width:960px;margin:28px auto;padding:18px 20px;border:1px solid var(--line);border-radius:12px;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;">
  <p style="margin:0;max-width:56ch;"><strong>Votre espace visiteur</strong><?= $bits ? ' : ' . htmlspecialchars(implode(', ', $bits), ENT_QUOTES, 'UTF-8') . '.' : '.' ?></p>
  <a class="btn btn-primary" href="/visiteur.php">Ouvrir mon espace</a>
</section>
