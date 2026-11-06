<?php
// Page affichée au client après la page de paiement Stripe d'un achat à la buvette. Publique, sans donnée : la vente est confirmée par la caisse.
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
$cancel = isset($_GET['annule']);
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title><?= $cancel ? 'Paiement annulé' : 'Merci !' ?></title>
<style>body{font:18px/1.4 system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;text-align:center;background:#f7f4ee;color:#1d1b17;padding:24px}h1{font-size:2.2rem;margin:0 0 8px}p{margin:0;color:#6b665c}</style></head>
<body><main><h1><?= $cancel ? 'Paiement annulé' : 'Merci ! ✓' ?></h1>
<p><?= $cancel ? 'Rien n\'a été débité. Vous pouvez demander au bar de refaire un code.' : 'Votre paiement est bien reçu. Vous pouvez récupérer votre commande au bar.' ?></p></main></body></html>
