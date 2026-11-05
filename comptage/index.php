<?php
/**
 * Le compteur : une page plein écran pour téléphone. Tout le comportement
 * (appuis, file d'attente hors ligne, synchronisation) est dans
 * assets/comptage.js ; cette page ne fait que lui passer sa configuration.
 */
require_once __DIR__ . '/../includes/comptage-layout.php';
if (session_status() === PHP_SESSION_NONE) session_start();
cp_boot();
cp_require_volunteer();

// Marque la vraie page du compteur pour le service worker (voir sw.js).
header('X-Cp-App: 1');
header('Cache-Control: no-cache');

$content = get_content();
$siteName = $content['site_name'] ?? 'La Brocante';
$event = cp_active_event();
$gates = $event ? cp_gates((int) $event['id']) : [];
$totals = $event ? cp_totals((int) $event['id']) : null;

$config = null;
if ($event && $gates) {
    $config = [
        'eventId' => (int) $event['id'],
        'eventName' => $event['name'],
        'capacity' => (int) $event['capacity'],
        'trackExits' => (bool) $event['track_exits'],
        'gates' => array_map(fn($g) => ['id' => (int) $g['id'], 'name' => $g['name']], $gates),
        'totals' => $totals,
        'csrf' => csrf_token(),
        'api' => '/comptage/api.php',
        'qtyChoices' => CP_QTY_CHOICES,
        'configHash' => cp_config_hash($event, $gates),
        'generated' => time(),
    ];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#b5502e">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Comptage">
<link rel="manifest" href="/comptage/manifest.json">
<link rel="apple-touch-icon" href="/assets/logo-macaron.png">
<title>Comptage — <?= h($siteName) ?></title>
<link href="<?= h(CP_FONTS) ?>" rel="stylesheet">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/comptage.css">
</head>
<body class="cp-app">
<?php if (!$config): ?>
  <main class="cp-login">
    <p class="eyebrow">Comptage de foule</p>
    <h1>Pas d'événement à compter</h1>
    <p><?= !$event ? 'Aucun événement n\'est actif pour l\'instant.' : 'L\'événement « ' . h($event['name']) . ' » n\'a aucun poste de comptage.' ?> Demandez au responsable de le préparer, puis rechargez cette page.</p>
    <a class="btn btn-primary" href="/comptage/index.php">Recharger</a>
    <p class="cp-quit"><a href="/comptage/logout.php">Quitter</a></p>
  </main>
<?php else: ?>
<div class="cp-screen" id="app" data-level="ok" data-mode="manual">
  <header class="cp-top">
    <div class="cp-event">
      <strong><?= h($event['name']) ?></strong>
      <span><?= h(trim(cp_fr_date($event['event_date']) . ($event['place'] ? ' · ' . $event['place'] : ''), ' ·')) ?></span>
    </div>
    <button type="button" class="cp-pill" id="sync" aria-live="polite" title="Appuyez pour synchroniser maintenant">…</button>
  </header>

  <p class="cp-banner" id="banner" role="alert" hidden></p>

  <nav class="cp-gates" id="gates" aria-label="Poste de comptage"<?= count($gates) < 2 ? ' hidden' : '' ?>></nav>

  <section class="cp-board" aria-live="polite">
    <div class="cp-present"><span id="present">0</span></div>
    <div class="cp-present-label" id="presentLabel"></div>
    <?php if ($config['capacity'] > 0): ?>
      <div class="cp-gauge" id="gaugeWrap"><div class="cp-gauge-bar"><span id="gauge"></span></div><div class="cp-gauge-text" id="gaugeText"></div></div>
    <?php endif; ?>
    <div class="cp-sub" id="sub"></div>
  </section>

  <div class="cp-mode" id="mode" role="group" aria-label="Mode de comptage">
    <button type="button" data-mode="manual" aria-pressed="true">✋ Manuel</button>
    <button type="button" data-mode="cam" aria-pressed="false">📷 Caméra</button>
  </div>

  <section class="cp-cam" id="cam" hidden>
    <div class="cp-cam-view" id="camView">
      <video id="camVideo" playsinline muted autoplay></video>
      <canvas id="camCanvas"></canvas>
      <div class="cp-cam-overlay" id="camOverlay">
        <p id="camStatus">La caméra compte les personnes qui franchissent la ligne. Les images restent sur ce téléphone : elles ne sont ni enregistrées ni envoyées.</p>
        <button type="button" class="cp-cam-start" id="camStart">▶ Démarrer la caméra</button>
      </div>
    </div>
    <div class="cp-cam-tools">
      <button type="button" id="camFlip">⇄ Inverser le sens</button>
      <button type="button" id="camSwitch">🔄 Autre caméra</button>
      <button type="button" id="camStop">⏹ Arrêter</button>
    </div>
    <p class="cp-cam-hint" id="camHint">Fixez le téléphone en hauteur, face au passage. Faites glisser les deux bouts de la ligne ; la flèche indique le sens « entrée ».</p>
  </section>

  <section class="cp-controls">
    <div class="cp-qty" id="qty" role="group" aria-label="Nombre de personnes pour le prochain appui"></div>
    <button type="button" class="cp-big cp-in" id="btnIn"><span class="cp-big-sign" id="inSign">+1</span><span class="cp-big-label">entrée</span></button>
    <div class="cp-row">
      <?php if ($config['trackExits']): ?>
        <button type="button" class="cp-out" id="btnOut"><span id="outSign">−1</span> sortie</button>
      <?php endif; ?>
      <button type="button" class="cp-undo" id="btnUndo" disabled>↩ Annuler</button>
    </div>
  </section>

  <footer class="cp-foot">
    <label><input type="checkbox" id="optVibrate" checked> Vibration</label>
    <label><input type="checkbox" id="optSound"> Son</label>
    <label><input type="checkbox" id="optWake" checked> Écran allumé</label>
    <a href="/comptage/logout.php">Quitter</a>
  </footer>
</div>
<script>window.CP = <?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/assets/comptage-vision.js"></script>
<script src="/assets/comptage.js"></script>
<?php endif; ?>
</body>
</html>
