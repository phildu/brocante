<?php
/**
 * Parcours « nouvelle pièce » (photos par angle → génération → relecture),
 * partagé par admin/quick-add.php et l'application smartphone studio.php.
 * Attend $angles, $aiReady et $qaOpts (action, again, list, list_label, title).
 * Le script est assets/quick-add.js ; la barre d'action est ensuite placée par la page hôte.
 */
?><div class="qa" data-action="<?= h($qaOpts['action']) ?>" data-again="<?= h($qaOpts['again']) ?>" data-list="<?= h($qaOpts['list']) ?>">
  <?php if (!empty($qaOpts['title'])): ?>
  <div>
    <p class="eyebrow" style="margin:0;">Catalogue</p>
    <h1>Nouvelle pièce</h1>
  </div>
  <?php endif; ?>
  <ol class="qa-steps" aria-label="Étapes">
    <li id="st-1" aria-current="step">Photos</li>
    <li id="st-2">Génération</li>
    <li id="st-3">Vérification</li>
  </ol>

  <!-- Étape 1 : photos -->
  <section class="qa-panel" id="panel-photos">
    <p class="qa-note">Génération complète (détourage, mise en situation 3:2 et 9:16, fiche) : <?= h(ai_estimate_label(ai_estimate_piece_counts())) ?> par pièce.</p>
    <p class="qa-note">Touchez un cadre pour ouvrir l'appareil photo. La photo de face est obligatoire ; les autres angles aident l'IA à décrire la pièce. « Principale » choisit la photo détourée et mise en situation.</p>
    <div class="qa-shots" id="shots">
      <?php foreach ($angles as $i => [$key, $title, $tip, $required]): ?>
        <div class="qa-shot<?= $i === 0 ? ' is-main' : '' ?>" data-index="<?= $i ?>" data-label="<?= h($title) ?>">
          <input type="file" accept="image/*" capture="environment" id="cam-<?= $i ?>" hidden>
          <label class="qa-shot-take" for="cam-<?= $i ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
            <b><?= h($title) ?><?= $required ? '' : ' <small>(facultatif)</small>' ?></b>
            <small><?= h($tip) ?></small>
          </label>
          <div class="qa-shot-bar">
            <span><?= h($title) ?></span>
            <span class="qa-mini">
              <button type="button" data-act="main" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Photo principale"><span aria-hidden="true">★</span><span class="txt"> Principale</span></button>
              <button type="button" data-act="retake" aria-label="Reprendre la photo"><span aria-hidden="true">↺</span><span class="txt"> Reprendre</span></button>
              <button type="button" data-act="remove" aria-label="Retirer la photo">✕</button>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="qa-add" id="add-shot">+ Autre angle</button>
    <div class="qa-gallery">
      Photos déjà prises ?
      <label for="gallery">Choisir dans la galerie</label>
      <input type="file" accept="image/*" multiple id="gallery" hidden>
    </div>
    <label class="qa-field">Indications pour l'IA (facultatif)
      <textarea id="notes" rows="2" maxlength="300" data-saved-prompts="notes" data-prompt-helper="notes" placeholder="Ex. : années 70, grès, petit éclat au pied, 32 cm de haut"></textarea>
    </label>
    <?php if (!$aiReady): ?>
      <p class="qa-warn">Clé Gemini non configurée (Réglages du site) : les photos seront enregistrées, mais le détourage, la mise en situation et la rédaction automatiques seront sautés.</p>
    <?php endif; ?>
  </section>

  <!-- Étape 2 : génération -->
  <section class="qa-panel" id="panel-progress" hidden>
    <ol class="qa-progress" id="progress">
      <li data-step="create"><span class="dot"></span><b>Envoi des photos</b><small>Enregistrement dans le catalogue</small></li>
      <li data-step="detoure"><span class="dot"></span><b>Détourage</b><small>Photo principale sur fond neutre</small></li>
      <li data-step="ambiance"><span class="dot"></span><b>Mise en situation</b><small>La pièce dans un intérieur</small></li>
      <li data-step="sheet"><span class="dot"></span><b>Rédaction de la fiche</b><small>Nom, description, catégorie, prix</small></li>
    </ol>
    <p class="qa-note">Comptez 30 secondes à 1 minute. Gardez cette page ouverte.</p>
  </section>

  <!-- Étape 3 : vérification -->
  <section class="qa-panel" id="panel-review" data-ai-form hidden>
    <div class="qa-visuals" id="visuals"></div>
    <div class="ai-bar">
      <button type="button" class="btn btn-ghost ai-all" data-ai-field="all" title="Propose de nouveau un nom, une description, une catégorie, des matières et un prix d'après les photos">Tout régénérer par l'IA</button>
      <span class="ai-status" data-ai-status role="status"></span>
    </div>
    <label class="qa-field">Nom <button type="button" class="ai-btn" data-ai-field="name" title="Générer / régénérer le nom">↻ IA</button><input type="text" id="f-name" data-ai-input="name" maxlength="120"></label>
    <div class="qa-two">
      <label class="qa-field">Prix <button type="button" class="ai-btn" data-ai-field="price" title="Estimer un prix">↻ IA</button> <button type="button" class="ai-btn" data-price-research title="Chercher sur le web le prix du même article, neuf et d'occasion — coût estimé <?= h(ai_estimate_label(['search' => 1])) ?>">Marché</button><input type="text" id="f-price" data-ai-input="price" maxlength="30" inputmode="decimal" placeholder="25 €"></label>
      <label class="qa-field">Poids (g) <button type="button" class="ai-btn" data-ai-field="weight" title="Estimer le poids (à vérifier avec une balance)">↻ IA</button><input type="number" id="f-weight" data-ai-input="weight_grams" min="0" step="10" inputmode="numeric" placeholder="500"></label>
    </div>
    <label class="qa-field">Taille <button type="button" class="ai-btn" data-ai-field="size" title="Lire la taille sur l'étiquette ou estimer les dimensions">↻ IA</button><input type="text" id="f-size" data-ai-input="size_text" maxlength="60" placeholder="20 × 15 × 30 cm, M, 38…"></label>
    <label class="qa-field">Catégorie <button type="button" class="ai-btn" data-ai-field="category" title="Choisir la catégorie d'après la pièce">↻ IA</button>
      <select id="f-cat" data-ai-input="cat">
        <?php foreach (category_list() as $c): ?>
          <option value="<?= h($c['key']) ?>"><?= h($c['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="qa-field">Description <button type="button" class="ai-btn" data-ai-field="description" title="Générer / régénérer la description">↻ IA</button><textarea id="f-desc" data-ai-input="description" rows="5" maxlength="1200"></textarea></label>
    <label class="qa-field">Matières <button type="button" class="ai-btn" data-ai-field="materials" title="Reconnaître les matières visibles">↻ IA</button><input type="text" id="f-materials" data-ai-input="materials" maxlength="200" placeholder="grès émaillé, bois de chêne…"></label>
    <label class="qa-field">État <button type="button" class="ai-btn" data-ai-field="etat" title="Estimer l'état d'après les photos">↻ IA</button><select id="f-etat" data-ai-input="etat"><?= product_condition_select_html(null) ?></select></label>
    <label class="qa-field">Nature <button type="button" class="ai-btn" data-ai-field="nature" title="Détecter la nature et la sous-catégorie">↻ IA</button><select id="f-nature" data-ai-input="nature"><?= product_nature_select_html(null) ?></select></label>
    <label class="qa-field">Sous-catégorie<select id="f-sous" data-ai-input="sous_categorie"><?= product_subcategory_select_html(null) ?></select></label>
    <label class="qa-field">Étiquette<input type="text" id="f-badge" maxlength="30" placeholder="Chiné, Rare, Coup de cœur…"></label>
    <label class="qa-switch"><input type="checkbox" id="f-publish"> <span>Publier tout de suite<br><small class="qa-note">Sinon la fiche reste masquée, à relire dans le catalogue.</small></span></label>
  </section>

  <!-- Terminé -->
  <section class="qa-panel qa-done" id="panel-done" hidden>
    <h2 id="done-title" style="margin:0;font-size:1.3rem;"></h2>
    <p class="qa-note" id="done-text"></p>
    <a class="btn btn-primary" href="<?= h($qaOpts['again']) ?>">Ajouter une autre pièce</a>
    <a class="btn btn-ghost" id="done-link" href="<?= h($qaOpts['list']) ?>"><?= h($qaOpts['list_label']) ?></a>
  </section>
</div>
