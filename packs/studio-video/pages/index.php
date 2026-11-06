<?php
// Studio vidéo : préparation (IA), prise de vue assistée et montage dans le navigateur.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/stv.php';
?>
<link rel="stylesheet" href="<?= h($pluginAsset('studio.css')) ?>">

<p class="eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
<h1 class="stv-title">Studio vidéo</h1>
<p class="stv-lede">
  Trois étapes : l’IA écrit le script et la liste des plans, l’écran vous guide pendant la prise de vue,
  puis vous montez les prises et exportez la vidéo. Tout se passe dans ce navigateur ; aucune vidéo n’est envoyée sur le serveur.
</p>

<div class="stv" id="stv"
     data-api-projects="<?= h($pluginApi('projects')) ?>"
     data-api-ai="<?= h($pluginApi('ai')) ?>"
     data-csrf="<?= h($pluginCsrf) ?>"
     data-shop="<?= h($content['site_name']) ?>">

  <div class="stv-bar">
    <label class="stv-field stv-field-inline">Projet
      <select id="stv-project"><option value="">Nouveau projet</option></select>
    </label>
    <button type="button" class="stv-btn stv-ghost" id="stv-new">Nouveau</button>
    <button type="button" class="stv-btn stv-ghost" id="stv-delete" hidden>Supprimer</button>
    <span class="stv-saved" id="stv-saved" aria-live="polite"></span>
  </div>

  <div class="stv-steps" role="tablist" aria-label="Étapes du studio">
    <button type="button" role="tab" id="stv-tab-brief" aria-controls="stv-panel-brief" aria-selected="true" data-step="brief"><span>1</span> Préparer</button>
    <button type="button" role="tab" id="stv-tab-shoot" aria-controls="stv-panel-shoot" aria-selected="false" data-step="shoot"><span>2</span> Tourner</button>
    <button type="button" role="tab" id="stv-tab-edit" aria-controls="stv-panel-edit" aria-selected="false" data-step="edit"><span>3</span> Monter</button>
  </div>

  <p class="stv-status" id="stv-status" role="status" aria-live="polite"></p>

  <section class="stv-panel" id="stv-panel-brief" data-panel="brief" role="tabpanel" aria-labelledby="stv-tab-brief">
    <div class="stv-grid2">
      <div class="stv-card">
        <h2>Le sujet</h2>
        <div class="stv-fields">
          <label class="stv-field">Titre du projet
            <input type="text" id="stv-title" maxlength="120" placeholder="Ex. Une commode Louis-Philippe restaurée">
          </label>
          <div class="stv-row2">
            <label class="stv-field">Type de vidéo
              <select id="stv-kind">
                <?php foreach (STV_KINDS as $key => $label): ?>
                  <option value="<?= h($key) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="stv-field">Durée visée
              <select id="stv-seconds">
                <option value="30">30 s</option>
                <option value="45" selected>45 s</option>
                <option value="60">60 s</option>
                <option value="90">1 min 30</option>
              </select>
            </label>
          </div>
          <div class="stv-row2">
            <label class="stv-field">Format
              <select id="stv-format">
                <option value="landscape">Paysage 16:9</option>
                <option value="portrait">Portrait 9:16</option>
              </select>
            </label>
            <label class="stv-field">Ton
              <select id="stv-tone">
                <?php foreach (STV_TONES as $key => $label): ?>
                  <option value="<?= h($key) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <label class="stv-field">À qui elle parle
            <input type="text" id="stv-audience" maxlength="120" placeholder="Clients de la boutique, habitants du quartier…">
          </label>
          <label class="stv-field">Ce que je veux montrer et dire
            <textarea id="stv-brief" rows="5" maxlength="1200" placeholder="L’objet, son histoire, ce qu’il faut regarder, ce que je veux que la personne retienne…"></textarea>
          </label>
        </div>
        <div class="stv-actions">
          <button type="button" class="stv-btn" id="stv-ai-script">Écrire le script avec l’IA</button>
          <button type="button" class="stv-btn stv-ghost" id="stv-save">Enregistrer</button>
        </div>
        <p class="hint">L’IA propose un texte à corriger. Elle n’invente ni prix, ni date, ni chiffre : complétez vous-même si besoin.</p>
      </div>

      <div class="stv-card">
        <h2>Le script</h2>
        <label class="stv-field">Texte à dire
          <textarea id="stv-script" rows="12" maxlength="4000" placeholder="Le script apparaîtra ici. Vous pouvez aussi l’écrire à la main."></textarea>
        </label>
        <p class="stv-meta" id="stv-script-meta">—</p>

        <h3>Titres à l’écran</h3>
        <ul class="stv-titles" id="stv-titles"><li class="hint">Aucun titre pour l’instant.</li></ul>

        <h3>Avant de tourner</h3>
        <ul class="stv-checklist" id="stv-checklist"><li class="hint">La liste apparaîtra avec le script.</li></ul>

        <div class="stv-tags" id="stv-hashtags"></div>
      </div>
    </div>

    <div class="stv-card">
      <div class="stv-card-head">
        <h2>Les plans</h2>
        <p class="hint">Chaque ligne se tourne séparément. Le cadrage et le conseil restent affichés pendant la prise de vue.</p>
      </div>
      <div class="stv-shots" id="stv-shots"><p class="hint">Écrivez le script avec l’IA pour obtenir une liste de plans, ou ajoutez-les à la main.</p></div>
      <div class="stv-actions">
        <button type="button" class="stv-btn stv-ghost" id="stv-add-shot">Ajouter un plan</button>
        <button type="button" class="stv-btn stv-ghost" id="stv-clear-shots">Vider la liste</button>
      </div>
    </div>
  </section>

  <section class="stv-panel" id="stv-panel-shoot" data-panel="shoot" role="tabpanel" aria-labelledby="stv-tab-shoot" hidden>
    <div class="stv-shoot">
      <div class="stv-stagewrap">
        <div class="stv-stage is-off" id="stv-stage" data-format="landscape">
          <video id="stv-video" playsinline muted autoplay></video>
          <canvas id="stv-overlay" aria-hidden="true"></canvas>
          <div class="stv-tele" id="stv-tele" hidden><div class="stv-tele-text" id="stv-tele-text"></div></div>
          <div class="stv-countdown" id="stv-countdown" hidden>3</div>
          <div class="stv-recflag" id="stv-recflag" hidden><i></i> REC <span id="stv-rec-time">00:00</span></div>
          <div class="stv-stage-empty" id="stv-stage-empty">
            <strong>Caméra éteinte</strong>
            <span>Activez la caméra pour voir le cadre et les repères.</span>
          </div>
        </div>
        <p class="hint stv-camnote" id="stv-camnote">La caméra ne fonctionne que sur une adresse sécurisée (https) et avec votre accord.</p>
      </div>

      <div class="stv-shoot-side">
        <div class="stv-card">
          <h2>Prise de vue</h2>
          <div class="stv-actions">
            <button type="button" class="stv-btn" id="stv-cam">Activer la caméra</button>
            <button type="button" class="stv-btn stv-ghost" id="stv-flip" hidden>Changer de caméra</button>
          </div>
          <div class="stv-toggles">
            <label class="stv-check"><input type="checkbox" id="stv-guides" checked> Repères de cadrage</label>
            <label class="stv-check"><input type="checkbox" id="stv-mirror"> Miroir</label>
            <label class="stv-check"><input type="checkbox" id="stv-tele-toggle"> Téléprompteur</label>
            <button type="button" class="stv-btn stv-ghost stv-btn-small" id="stv-tele-play" hidden>Défiler</button>
          </div>
          <div class="stv-row2">
            <label class="stv-field">Vitesse du texte
              <input type="range" id="stv-tele-speed" min="10" max="90" step="2" value="30">
            </label>
            <label class="stv-field">Taille du texte
              <input type="range" id="stv-tele-size" min="18" max="52" step="2" value="28">
            </label>
          </div>
          <div class="stv-row2">
            <label class="stv-field">Décompte
              <select id="stv-cd">
                <option value="0">Aucun</option>
                <option value="3" selected>3 secondes</option>
                <option value="5">5 secondes</option>
              </select>
            </label>
            <label class="stv-field">Je tourne
              <select id="stv-shot-select"><option value="0">Prise libre</option></select>
            </label>
          </div>
          <p class="stv-shot-hint" id="stv-shot-hint"></p>

          <div class="stv-meters">
            <div class="stv-meter-row">
              <span>Son</span>
              <div class="stv-meter"><i id="stv-meter-audio"></i></div>
              <em id="stv-audio-num">—</em>
            </div>
            <div class="stv-meter-row">
              <span>Lumière</span>
              <div class="stv-meter"><i id="stv-meter-light"></i></div>
              <em id="stv-light-num">—</em>
            </div>
          </div>

          <div class="stv-actions">
            <button type="button" class="stv-btn stv-record" id="stv-record" disabled>Enregistrer</button>
            <button type="button" class="stv-btn stv-ghost" id="stv-stop" hidden>Arrêter</button>
          </div>
          <p class="stv-timer" id="stv-timer">00:00</p>
          <p class="hint">Une prise peut être refaite autant de fois que nécessaire : la meilleure reste dans la bibliothèque.</p>
        </div>

        <div class="stv-card">
          <div class="stv-card-head">
            <h2>Prises <span class="stv-badge" id="stv-clip-count" hidden></span></h2>
          </div>
          <div class="stv-clips" id="stv-clips"><p class="hint">Aucune prise pour l’instant.</p></div>
          <p class="hint" id="stv-storage"></p>
        </div>
      </div>
    </div>
  </section>

  <section class="stv-panel" id="stv-panel-edit" data-panel="edit" role="tabpanel" aria-labelledby="stv-tab-edit" hidden>
    <div class="stv-grid2">
      <div class="stv-card">
        <h2>Monter</h2>
        <p class="hint">Un plan par ligne : choisissez la prise, réglez le début et la fin, ajoutez un titre si utile.</p>
        <div class="stv-timeline" id="stv-timeline"><p class="hint">Tournez au moins une prise pour commencer le montage.</p></div>
        <div class="stv-actions">
          <button type="button" class="stv-btn" id="stv-ai-plan">Proposer un montage</button>
          <button type="button" class="stv-btn stv-ghost" id="stv-auto-plan">Monter dans l’ordre</button>
        </div>
        <label class="stv-check"><input type="checkbox" id="stv-fade" checked> Fondu au début et à la fin</label>
        <p class="stv-meta" id="stv-plan-meta">—</p>
      </div>

      <div class="stv-card">
        <h2>Résultat</h2>
        <div class="stv-preview" id="stv-preview">
          <canvas id="stv-preview-canvas" width="1280" height="720"></canvas>
        </div>
        <div class="stv-actions">
          <button type="button" class="stv-btn stv-ghost" id="stv-preview-btn">Lire</button>
          <button type="button" class="stv-btn" id="stv-export">Exporter la vidéo</button>
          <button type="button" class="stv-btn stv-ghost" id="stv-cancel" hidden>Arrêter</button>
        </div>
        <div class="stv-progress" id="stv-progress" hidden><i></i></div>
        <p class="hint" id="stv-export-note">L’export se fait en temps réel : laissez cet onglet ouvert jusqu’au bout.</p>
        <div class="stv-download" id="stv-download"></div>
      </div>
    </div>
  </section>

  <details class="stv-card stv-help">
    <summary>Conseils de prise de vue et de montage</summary>
    <div class="stv-help-grid">
      <div>
        <h3>Avant d’appuyer</h3>
        <ul>
          <li>Posez le téléphone ou calez les coudes : la stabilité compte plus que la netteté de l’objectif.</li>
          <li>Tournez avec la lumière derrière vous, jamais avec une fenêtre dans le dos de l’objet.</li>
          <li>Nettoyez l’objet et le fond. Un fond simple vaut mieux qu’une étagère chargée.</li>
          <li>Faites une prise de 5 secondes pour vérifier le son avant la vraie prise.</li>
        </ul>
      </div>
      <div>
        <h3>Pendant la prise</h3>
        <ul>
          <li>Parlez à 30 cm du micro, sans crier, en regardant l’objectif.</li>
          <li>Laissez une seconde de silence au début et à la fin : le montage respire.</li>
          <li>Pour un détail, approchez-vous au lieu de zoomer.</li>
          <li>Si vous vous trompez, continuez : la meilleure partie suffit souvent.</li>
        </ul>
      </div>
      <div>
        <h3>Au montage</h3>
        <ul>
          <li>Coupez les hésitations, gardez les phrases utiles.</li>
          <li>Un plan d’ouverture court, puis le sujet, puis un détail, puis l’adresse ou le prix affiché.</li>
          <li>Un titre lisible en 2 secondes maximum : pas de paragraphe.</li>
          <li>Regardez le résultat en entier avant de le publier.</li>
        </ul>
      </div>
    </div>
  </details>
</div>

<script src="<?= h($pluginAsset('studio.js')) ?>" defer></script>
