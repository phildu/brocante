<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shooting-assistant.php';
require_once __DIR__ . '/../includes/shooting-tabs.php';
require_admin();

$content = get_content();
$aiReady = (bool) GEMINI_API_KEY;
$siteName = trim((string) $content['site_name']) ?: (string) tenant('name');
$dataJson = json_encode([
    'programs' => shooting_programs(),
    'naturePrograms' => shooting_nature_program_map(),
    'manualPrograms' => shooting_manual_program_map(),
    'types' => shooting_type_choices(),
    'generalTips' => shooting_general_tips(),
    'issueLabels' => shooting_issue_labels(),
    'voiceConditions' => shooting_voice_conditions(),
    'conditionLabels' => shooting_condition_labels(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4eee1">
<title>Studio photo/vidéo — <?= h($siteName) ?></title>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/shooting-tabs.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shooting-tabs.css') ?>">
<link rel="stylesheet" href="/assets/object-picker.css">
<link rel="stylesheet" href="/assets/shooting-assistant.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shooting-assistant.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'assistant'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="sa-main">
  <div class="sa qa" data-action="/admin/shooting-assistant-action.php" data-qa="/admin/quick-add-action.php" data-ai="<?= $aiReady ? '1' : '0' ?>" data-catalog="/admin/catalog.php#produit-">
    <?= shooting_tabs_html(admin_role(), 'assistant') ?>

    <header class="sa-head">
      <p class="eyebrow">Article à vendre · studio photo/vidéo</p>
      <h1>Studio photo/vidéo</h1>
      <details class="sa-fold" id="sa-intro-fold" open>
        <summary>Comment fonctionne l'assistant</summary>
        <p class="sa-lede">Une première photo suffit pour démarrer : l'IA compte les objets, contrôle la netteté, le cadrage et la lumière, reconnaît la nature de la pièce et vous guide ensuite vue par vue, avec une voix.</p>
      </details>
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
          </div>
        </div>

        <!-- Première visite : la voix. Les explications se replient ensuite. -->
        <div class="sa-setup" id="sa-setup" hidden>
          <div class="sa-setup-step">
            <h3>Une voix pour vous guider ?</h3>
            <p>L'assistant peut annoncer chaque vue et prévenir quand la photo est floue ou trop sombre. Aucun son n'est envoyé.</p>
            <div class="sa-setup-actions">
              <button type="button" class="btn btn-primary" data-audio-answer="1">🔊 Oui, avec la voix</button>
              <button type="button" class="btn btn-ghost" data-audio-answer="0">🔇 Non, sans la voix</button>
            </div>
          </div>
        </div>

        <!-- Question posée avant chaque pièce, sauf si on a coché « ne plus demander ». -->
        <div class="sa-setup" id="sa-mode-ask" hidden>
          <div class="sa-setup-step">
            <h3>Voulez-vous photographier…</h3>
            <div class="sa-mode-choices" role="group" aria-label="Que photographier ?">
              <button type="button" class="sa-mode-choice" data-mode-answer="single">
                <span class="sa-mode-num" aria-hidden="true">1</span>
                <span><b>Un ou des articles, un par un</b><small>Dites « un »</small></span>
              </button>
              <button type="button" class="sa-mode-choice" data-mode-answer="batch">
                <span class="sa-mode-num" aria-hidden="true">2</span>
                <span><b>Des articles en lot, à traiter plus tard</b><small>Dites « deux »</small></span>
              </button>
              <button type="button" class="sa-mode-choice" data-mode-answer="group">
                <span class="sa-mode-num" aria-hidden="true">3</span>
                <span><b>Une photo d'un ensemble de produits, à traiter rapidement</b><small>Dites « trois »</small></span>
              </button>
            </div>
            <div class="sa-setup-actions">
              <button type="button" class="btn btn-ghost" id="sa-mode-mic">🎤 Répondre à la voix</button>
            </div>
            <p class="sa-note" id="sa-mode-heard" aria-live="polite"></p>
            <label class="sa-check">
              <input type="checkbox" id="sa-mode-never">
              <span>Ne plus poser la question dorénavant</span>
            </label>
          </div>
        </div>
        <p class="sa-session" id="sa-session" hidden></p>
        <label class="sa-check sa-intro-mute" id="sa-intro-mute-wrap" hidden>
          <input type="checkbox" id="sa-intro-mute">
          <span>Ne plus écouter la présentation dorénavant</span>
        </label>

        <details class="sa-fold sa-fold-first" id="sa-first-fold" open>
          <summary>Réussir la photo d'ensemble</summary>
          <p>Posez la pièce seule sur un fond simple, à la lumière du jour. Une seule photo pour commencer ; le programme s'occupe de la suite.</p>
        </details>

        <div class="sa-typeask" id="sa-typeask">
          <label class="sa-field" for="sa-type-input">Quel type de produit ?</label>
          <div class="sa-typeask-row">
            <input type="text" id="sa-type-input" autocomplete="off" enterkeyhint="go" placeholder="Ex. : théière, manteau, vinyle…">
            <button type="button" class="btn btn-ghost" id="sa-type-go">OK</button>
          </div>
          <div class="sa-typeask-msg" id="sa-type-msg" aria-live="polite" hidden></div>
        </div>
        <div class="sa-types" id="sa-types" role="group" aria-label="Type de pièce"></div>
        <p class="sa-note" id="sa-type-note">Laissez « Automatique » : l'IA reconnaît la nature de la pièce et choisit le programme.</p>
        <div class="sa-draft" id="sa-draft" hidden></div>

        <div class="sa-audio-proposal" id="sa-audio-proposal">
          <label class="sa-switch">
            <input type="checkbox" id="sa-audio-first" checked>
            <span><b>Assistance vocale</b><small>Une voix vous accompagne dès la première photo, puis annonce le conseil de chaque vue. Aucune donnée n'est envoyée.</small></span>
          </label>
          <button type="button" class="btn btn-ghost" id="sa-audio-first-play">🔊 Écouter la présentation</button>
        </div>

        <div class="sa-drop" id="sa-first-drop">
          <input type="file" accept="image/*" capture id="sa-cam-first" hidden>
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
            <span class="sa-preview-actions">
              <button type="button" class="btn-small" id="sa-first-lib-again">Galerie</button>
              <button type="button" class="btn-small" id="sa-first-retake">↺ Reprendre</button>
            </span>
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
            <span><b>Assistance vocale</b><small>Une voix annonce le conseil de chaque vue ; la fiche se vérifie aussi à la voix à la fin. Le coaching audio n'envoie aucune donnée.</small></span>
          </label>
          <div class="sa-audio-actions">
            <button type="button" class="btn btn-ghost" id="sa-audio-play">🔊 Écouter la présentation</button>
            <button type="button" class="btn btn-ghost" id="sa-audio-stop" hidden>⏹ Arrêter</button>
          </div>
        </div>

        <ul class="sa-tips" id="sa-general-tips"></ul>
        <ol class="sa-plan" id="sa-plan" aria-label="Vues du programme"></ol>

        <div class="sa-actions">
          <button type="button" class="btn btn-primary" id="sa-start-shots">Ouvrir l'appareil photo</button>
          <button type="button" class="btn btn-ghost" id="sa-back-start">Revoir la première photo</button>
          <button type="button" class="btn btn-ghost" id="sa-stop-program">⏹ Arrêter la séance</button>
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
          <p class="sa-shot-notice" id="sa-shot-notice" role="status" hidden></p>
          <div class="sa-shot-tools">
            <button type="button" class="btn-small sa-listen" id="sa-shot-audio">🔊 Écouter le conseil</button>
            <button type="button" class="btn-small" id="sa-shot-audio-off">🔇 Couper la voix</button>
            <button type="button" class="btn-small" id="sa-view-program">Programme</button>
            <button type="button" class="btn-small" id="sa-stop-shooting">⏹ Arrêter le shooting</button>
          </div>
        </div>

        <input type="file" accept="image/*" capture id="sa-shot-cam" hidden>
        <input type="file" accept="image/*" id="sa-shot-lib" hidden>

        <div class="sa-shot-stage" id="sa-shot-stage">
          <div class="sa-shot-empty" id="sa-shot-empty">
            <div class="sa-drop-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
            </div>
            <div class="sa-drop-actions">
              <button type="button" class="btn btn-primary" id="sa-shot-take">Ouvrir l'appareil photo</button>
              <button type="button" class="btn btn-ghost" id="sa-shot-lib-btn">Galerie</button>
            </div>
          </div>
          <img id="sa-shot-img" alt="" hidden>
        </div>

        <div class="sa-quality" id="sa-shot-quality" aria-live="polite" hidden></div>

        <div class="sa-actions sa-actions-row">
          <button type="button" class="btn btn-ghost" id="sa-shot-again" hidden>↺ Reprendre</button>
          <button type="button" class="btn btn-ghost" id="sa-shot-correct" hidden>☀ Corriger la lumière</button>
          <button type="button" class="btn btn-ghost" id="sa-shot-enhance" hidden>✨ Corriger avec l'IA</button>
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

        <div class="sa-video" id="sa-video">
          <div class="sa-card-head">
            <span class="sa-num" aria-hidden="true">🎬</span>
            <div>
              <h3>Vidéo de l'objet (facultatif)</h3>
              <p>Un court mouvement montre la matière et le volume mieux qu'une photo.</p>
            </div>
          </div>
          <div class="sa-video-modes" id="sa-video-modes" role="group" aria-label="Type de vidéo">
            <button type="button" data-video-mode="rotate-continuous">🔄 Sur lui-même · continu</button>
            <button type="button" data-video-mode="rotate-frame">🔄 Sur lui-même · image par image</button>
            <button type="button" data-video-mode="orbit-continuous">🎥 Tour de l'objet · continu</button>
            <button type="button" data-video-mode="orbit-frame">🎥 Tour de l'objet · image par image</button>
          </div>
          <input type="file" accept="video/*" capture id="sa-video-cam" hidden>
          <input type="file" accept="image/*" capture id="sa-video-frame-cam" hidden>
          <p class="sa-video-help" id="sa-video-help"></p>
          <div class="sa-video-stage" id="sa-video-stage" hidden>
            <div class="sa-video-actions">
              <button type="button" class="btn btn-primary" id="sa-video-start">● Filmer</button>
              <button type="button" class="btn btn-primary" id="sa-video-frame" hidden>📸 Prendre une image</button>
              <button type="button" class="btn btn-primary" id="sa-video-finish" hidden>✓ Terminer la vidéo</button>
              <button type="button" class="btn btn-ghost" id="sa-video-close">Fermer</button>
            </div>
            <video id="sa-video-result" controls playsinline hidden></video>
          </div>
          <p class="sa-video-status" id="sa-video-status" aria-live="polite"></p>
        </div>

        <label class="sa-field">Indications pour l'IA (facultatif)
          <textarea id="sa-notes" rows="2" maxlength="300" placeholder="Ex. : années 70, grès, petit éclat au pied, 32 cm de haut"></textarea>
        </label>
        <p class="sa-note" id="sa-finish-mode"></p>
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
          <li data-step="video"><span class="sa-dot"></span><b>Vidéo de l'objet</b><small>Envoi de la vidéo</small></li>
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
        <div class="sa-batch" id="sa-batch" hidden>
          <h3>Lot en cours</h3>
          <ul id="sa-batch-list"></ul>
        </div>
        <h2 id="sa-done-title">Fiche prête</h2>
        <p class="sa-note" id="sa-done-text"></p>
        <div id="sa-review" hidden>
          <label class="sa-field">Nom <input type="text" id="sa-f-name" maxlength="120"></label>
          <div class="sa-two">
            <label class="sa-field">Prix affiché (€) <input type="number" id="sa-f-price" min="0" step="0.01" inputmode="decimal"></label>
            <label class="sa-field">Prix du neuf (€) <input type="number" id="sa-f-new-price" min="0" step="0.01" inputmode="decimal"></label>
          </div>
          <label class="sa-field">Poids (g) <input type="number" id="sa-f-weight" min="0" step="10" inputmode="numeric"></label>
          <label class="sa-field">Description <textarea id="sa-f-desc" rows="4" maxlength="1200"></textarea></label>

          <div class="sa-voice" id="sa-voice" hidden>
            <div class="sa-voice-intro" id="sa-voice-intro">
              <p class="sa-kicker">Validation vocale</p>
              <h3>Vérifier la fiche à la voix</h3>
              <p class="sa-note">L'assistant pose une question, écoute votre réponse et n'enregistre que ce que vous corrigez. « Je ne sais pas » laisse la fiche telle quelle.</p>
              <p class="sa-note" id="sa-voice-support"></p>
              <div class="sa-actions">
                <button type="button" class="btn btn-primary" id="sa-voice-start">🎙 Vérifier à la voix</button>
              </div>
            </div>
            <div class="sa-voice-panel" id="sa-voice-panel" hidden>
              <p class="sa-voice-count" id="sa-voice-count"></p>
              <p class="sa-voice-question" id="sa-voice-question" aria-live="polite"></p>
              <p class="sa-voice-hint" id="sa-voice-hint"></p>
              <p class="sa-voice-heard" id="sa-voice-heard" aria-live="polite"></p>
              <div class="sa-voice-answers" id="sa-voice-answers"></div>
              <div class="sa-field sa-voice-input" id="sa-voice-input-wrap" hidden>
                <span id="sa-voice-input-label">Votre réponse</span>
                <div class="sa-voice-input-row">
                  <input type="text" id="sa-voice-input" autocomplete="off" enterkeyhint="done">
                  <button type="button" class="btn-small" id="sa-voice-send">Valider</button>
                </div>
              </div>
              <div class="sa-voice-bar">
                <button type="button" class="btn btn-ghost" id="sa-voice-mic">🎤 Répondre</button>
                <button type="button" class="btn btn-ghost" id="sa-voice-repeat">🔊 Répéter</button>
                <button type="button" class="btn btn-ghost" id="sa-voice-unknown">Je ne sais pas</button>
                <button type="button" class="btn btn-ghost" id="sa-voice-stop">⏹ Arrêter</button>
              </div>
              <ul class="sa-voice-summary" id="sa-voice-summary" hidden></ul>
            </div>
          </div>

          <label class="sa-switch"><input type="checkbox" id="sa-f-publish"> <span>Publier la fiche<br><small>Sans cette case, elle reste masquée dans le catalogue pour relecture.</small></span></label>
          <div class="sa-actions">
            <button type="button" class="btn btn-primary" id="sa-save">Enregistrer</button>
          </div>
        </div>
        <div class="sa-actions">
          <a class="btn btn-ghost" id="sa-catalog-link" href="/admin/catalog.php">Voir dans le catalogue</a>
          <a class="btn btn-ghost" id="sa-batch-studio" href="/admin/studio.php" hidden>Ouvrir le Studio (traitement du lot)</a>
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
