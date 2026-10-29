<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shooting-assistant.php';
require_admin();

$content = get_content();
$aiReady = (bool) GEMINI_API_KEY;
$siteName = trim((string) $content['site_name']) ?: (string) tenant('name');
$dataJson = json_encode([
    'programs' => shooting_programs(),
    'naturePrograms' => shooting_nature_program_map(),
    'manualPrograms' => shooting_manual_program_map(),
    'generalTips' => shooting_general_tips(),
    'issueLabels' => shooting_issue_labels(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4eee1">
<title>Assistant de shooting — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/object-picker.css">
<link rel="stylesheet" href="/assets/shooting-assistant.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shooting-assistant.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'assistant'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="sa-main">
  <div class="sa qa" data-action="/admin/shooting-assistant-action.php" data-qa="/admin/quick-add-action.php" data-ai="<?= $aiReady ? '1' : '0' ?>" data-catalog="/admin/catalog.php#produit-">

    <header class="sa-head">
      <p class="eyebrow">Nouvelle pièce · parcours guidé</p>
      <h1>Assistant de shooting</h1>
      <p class="sa-lede">Une première photo suffit pour démarrer : l'IA compte les objets, contrôle la netteté, le cadrage et la lumière, reconnaît la nature de la pièce et vous guide ensuite vue par vue, avec une voix.</p>
      <p class="sa-classic">Vous préférez le parcours classique ? <a href="/admin/quick-add.php">Nouvelle pièce (photo)</a></p>
    </header>

    <ol class="sa-steps" aria-label="Étapes">
      <li id="sa-step-1" aria-current="step">Photo</li>
      <li id="sa-step-2">Programme</li>
      <li id="sa-step-3">Shooting</li>
      <li id="sa-step-4">Fiche</li>
    </ol>

    <!-- Étape 1 : première photo, analyse et contrôle qualité -->
    <section class="sa-panel" id="sa-panel-start" aria-labelledby="sa-start-title">
      <div class="sa-card">
        <div class="sa-card-head">
          <span class="sa-num" aria-hidden="true">1</span>
          <div>
            <h2 id="sa-start-title">La photo d'ensemble</h2>
            <p>Posez la pièce seule sur un fond simple, à la lumière du jour. Une seule photo pour commencer ; le programme s'occupe de la suite.</p>
          </div>
        </div>

        <div class="sa-types" id="sa-types" role="group" aria-label="Type de pièce">
          <button type="button" data-type="auto" aria-pressed="true">✨ Automatique</button>
          <button type="button" data-type="objet" aria-pressed="false">📦 Objet</button>
          <button type="button" data-type="vetement" aria-pressed="false">👕 Vêtement</button>
          <button type="button" data-type="media" aria-pressed="false">📚 Livre / média</button>
          <button type="button" data-type="chaussures" aria-pressed="false">👟 Chaussures</button>
          <button type="button" data-type="bijou" aria-pressed="false">💍 Bijou</button>
          <button type="button" data-type="mobilier" aria-pressed="false">🪑 Mobilier</button>
        </div>
        <p class="sa-note" id="sa-type-note">Laissez « Automatique » : l'IA reconnaît la nature de la pièce et choisit le programme.</p>

        <div class="sa-drop" id="sa-first-drop">
          <input type="file" accept="image/*" capture="environment" id="sa-cam-first" hidden>
          <input type="file" accept="image/*" id="sa-lib-first" hidden>
          <div class="sa-drop-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
          </div>
          <p class="sa-drop-title">Prenez la première photo</p>
          <p class="sa-drop-help">La pièce entière, avec une marge autour. Pas de flash.</p>
          <div class="sa-drop-actions">
            <button type="button" class="btn btn-primary" id="sa-take-first">Ouvrir l'appareil photo</button>
            <button type="button" class="btn btn-ghost" id="sa-lib-first-btn">Choisir dans la galerie</button>
          </div>
        </div>

        <div class="sa-preview" id="sa-first-preview" hidden>
          <img id="sa-first-img" alt="Première photo de la pièce">
          <div class="sa-preview-bar">
            <span id="sa-first-caption">Photo d'ensemble</span>
            <button type="button" class="btn-small" id="sa-first-retake">↺ Reprendre</button>
          </div>
        </div>

        <div class="sa-analysis" id="sa-analysis" aria-live="polite"></div>
      </div>
    </section>

    <!-- Étape 2 : programme adapté -->
    <section class="sa-panel" id="sa-panel-program" aria-labelledby="sa-program-title" hidden>
      <div class="sa-card">
        <div class="sa-card-head">
          <span class="sa-num" aria-hidden="true">2</span>
          <div>
            <p class="sa-kicker" id="sa-program-kicker">Programme conseillé</p>
            <h2 id="sa-program-title">Programme</h2>
            <p id="sa-program-intro"></p>
          </div>
        </div>

        <div class="sa-program-tools">
          <div class="sa-types sa-types-compact" id="sa-program-types" role="group" aria-label="Changer le type de pièce"></div>
          <label class="sa-switch">
            <input type="checkbox" id="sa-audio-toggle" checked>
            <span><b>Coaching audio</b><small>Une voix lit les conseils de chaque vue. Aucune donnée n'est envoyée.</small></span>
          </label>
          <div class="sa-audio-actions">
            <button type="button" class="btn btn-ghost" id="sa-audio-play">🔊 Écouter la présentation</button>
            <button type="button" class="btn btn-ghost" id="sa-audio-stop" hidden>⏹ Arrêter</button>
          </div>
        </div>

        <ul class="sa-tips" id="sa-general-tips"></ul>
        <ol class="sa-plan" id="sa-plan" aria-label="Vues du programme"></ol>

        <div class="sa-actions">
          <button type="button" class="btn btn-primary" id="sa-start-shots">Commencer les photos</button>
          <button type="button" class="btn btn-ghost" id="sa-back-start">Revoir la première photo</button>
        </div>
      </div>
    </section>

    <!-- Étape 3 : shooting guidé, vue par vue -->
    <section class="sa-panel" id="sa-panel-shots" aria-label="Shooting guidé" hidden>
      <div class="sa-card">
        <div class="sa-progress">
          <div class="sa-progress-bar"><span id="sa-progress-fill"></span></div>
          <p class="sa-progress-text" id="sa-progress-text"></p>
        </div>

        <div class="sa-shot-head">
          <span class="sa-badge" id="sa-shot-badge"></span>
          <h2 id="sa-shot-title"></h2>
          <p id="sa-shot-tip"></p>
          <button type="button" class="btn-small sa-listen" id="sa-shot-audio">🔊 Écouter le conseil</button>
        </div>

        <input type="file" accept="image/*" capture="environment" id="sa-shot-cam" hidden>
        <input type="file" accept="image/*" id="sa-shot-lib" hidden>

        <div class="sa-shot-stage" id="sa-shot-stage">
          <div class="sa-shot-empty" id="sa-shot-empty">
            <div class="sa-drop-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
            </div>
            <div class="sa-drop-actions">
              <button type="button" class="btn btn-primary" id="sa-shot-take">Prendre la photo</button>
              <button type="button" class="btn btn-ghost" id="sa-shot-lib-btn">Galerie</button>
            </div>
          </div>
          <img id="sa-shot-img" alt="" hidden>
        </div>

        <div class="sa-quality" id="sa-shot-quality" aria-live="polite" hidden></div>

        <div class="sa-actions sa-actions-row">
          <button type="button" class="btn btn-ghost" id="sa-shot-again" hidden>↺ Reprendre</button>
          <button type="button" class="btn btn-ghost" id="sa-shot-skip">Passer cette vue</button>
          <button type="button" class="btn btn-primary" id="sa-shot-next" hidden>Valider et continuer</button>
        </div>
      </div>

      <div class="sa-thumbs" id="sa-thumbs" aria-label="Photos déjà prises"></div>
      <div class="sa-actions">
        <button type="button" class="btn btn-primary" id="sa-to-finish" hidden>Voir le récapitulatif</button>
      </div>
    </section>

    <!-- Étape 4 : récapitulatif avant génération -->
    <section class="sa-panel" id="sa-panel-finish" aria-labelledby="sa-finish-title" hidden>
      <div class="sa-card">
        <div class="sa-card-head">
          <span class="sa-num" aria-hidden="true">4</span>
          <div>
            <h2 id="sa-finish-title">Vos photos</h2>
            <p>Vérifiez la série, ajoutez une précision si besoin, puis laissez l'IA préparer la fiche.</p>
          </div>
        </div>
        <div class="sa-finish-grid" id="sa-finish-grid"></div>
        <label class="sa-field">Indications pour l'IA (facultatif)
          <textarea id="sa-notes" rows="2" maxlength="300" placeholder="Ex. : années 70, grès, petit éclat au pied, 32 cm de haut"></textarea>
        </label>
        <div class="sa-actions">
          <button type="button" class="btn btn-primary" id="sa-generate">Générer la fiche</button>
          <button type="button" class="btn btn-ghost" id="sa-resume-shots">Revenir aux photos</button>
          <button type="button" class="btn btn-ghost" id="sa-restart">Recommencer</button>
        </div>
        <?php if (!$aiReady): ?>
          <p class="sa-warn">Clé Gemini non configurée : le contrôle local et le programme fonctionnent, mais l'analyse IA, le détourage, la mise en situation et la rédaction seront sautés.</p>
        <?php endif; ?>
      </div>
    </section>

    <!-- Génération, puis relecture rapide -->
    <section class="sa-panel" id="sa-panel-generate" aria-labelledby="sa-generate-title" hidden>
      <div class="sa-card">
        <h2 id="sa-generate-title">Préparation de la fiche…</h2>
        <p class="sa-note">Comptez 1 à 3 minutes. Gardez cette page ouverte.</p>
        <ol class="sa-gen-list" id="sa-gen-list">
          <li data-step="create"><span class="sa-dot"></span><b>Envoi des photos</b><small>Enregistrement dans le catalogue</small></li>
          <li data-step="detoure"><span class="sa-dot"></span><b>Détourage</b><small>Photo principale sur fond neutre</small></li>
          <li data-step="sheet"><span class="sa-dot"></span><b>Rédaction de la fiche</b><small>Nom, description, catégorie, prix</small></li>
          <li data-step="ambiance"><span class="sa-dot"></span><b>Mise en situation</b><small>La pièce dans un décor adapté</small></li>
          <li data-step="angles"><span class="sa-dot"></span><b>Détourage des autres vues</b><small>Chaque photo sur fond neutre</small></li>
          <li data-step="recto"><span class="sa-dot"></span><b>Recto</b><small>Visuel de studio</small></li>
          <li data-step="verso"><span class="sa-dot"></span><b>Verso</b><small>Visuel de studio</small></li>
        </ol>
        <div class="sa-actions" id="sa-generate-retry" hidden>
          <button type="button" class="btn btn-primary" id="sa-generate-again">Réessayer</button>
          <button type="button" class="btn btn-ghost" id="sa-generate-back">Revenir aux photos</button>
        </div>
      </div>
    </section>

    <section class="sa-panel" id="sa-panel-done" aria-labelledby="sa-done-title" hidden>
      <div class="sa-card">
        <div class="sa-visuals" id="sa-visuals"></div>
        <h2 id="sa-done-title">Fiche prête</h2>
        <p class="sa-note" id="sa-done-text"></p>
        <div id="sa-review" hidden>
          <label class="sa-field">Nom <input type="text" id="sa-f-name" maxlength="120"></label>
          <div class="sa-two">
            <label class="sa-field">Prix affiché (€) <input type="number" id="sa-f-price" min="0" step="0.01" inputmode="decimal"></label>
            <label class="sa-field">Poids (g) <input type="number" id="sa-f-weight" min="0" step="10" inputmode="numeric"></label>
          </div>
          <label class="sa-field">Description <textarea id="sa-f-desc" rows="4" maxlength="1200"></textarea></label>
          <label class="sa-switch"><input type="checkbox" id="sa-f-publish"> <span>Publier la fiche<br><small>Sans cette case, elle reste masquée dans le catalogue pour relecture.</small></span></label>
          <div class="sa-actions">
            <button type="button" class="btn btn-primary" id="sa-save">Enregistrer</button>
          </div>
        </div>
        <div class="sa-actions">
          <a class="btn btn-ghost" id="sa-catalog-link" href="/admin/catalog.php">Voir dans le catalogue</a>
          <button type="button" class="btn btn-primary" id="sa-new">Ajouter une autre pièce</button>
        </div>
      </div>
    </section>

    <p class="sa-audio-status" id="sa-audio-status" role="status" aria-live="polite"></p>
  </div>
</main>

<script type="application/json" id="sa-data"><?= $dataJson ?></script>
<script src="/assets/object-picker.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/object-picker.js') ?>"></script>
<script src="/assets/shooting-assistant.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/shooting-assistant.js') ?>"></script>
</body>
</html>
