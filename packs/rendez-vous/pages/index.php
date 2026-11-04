<?php
// Rendez-vous — liste des consultations et création. Les écritures passent par l'API du plugin (api/admin.php), car le socle
// a déjà commencé à envoyer la page quand elle est incluse.
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rdv.php';

$self = plugin_page_url($plugin['id'], 'index');
$manageUrl = static fn (int $id): string => plugin_page_url($plugin['id'], 'gerer') . '&id=' . $id;
$polls = rdv_polls();
$flash = rdv_flash_take();
$defaultNotify = implode(', ', array_filter(pricing_admin_emails()));
$genFrom = (new DateTimeImmutable('tomorrow', rdv_tz()))->format('Y-m-d');
$genTo = (new DateTimeImmutable('+14 days', rdv_tz()))->format('Y-m-d');
$template = "2026-11-14 09:00-12:00\n2026-11-15 09:00-12:00";
?>
<link rel="stylesheet" href="<?= h($pluginAsset('rdv.css')) ?>">
<div class="rdv-page" id="rdv-admin"
  data-api="<?= h($pluginApi('admin')) ?>" data-csrf="<?= h($pluginCsrf) ?>" data-page="index"
  data-list="<?= h($self) ?>">
  <div>
    <p class="rdv-eyebrow">Module · <?= h($plugin['name']) ?> · v<?= h($plugin['version']) ?></p>
    <h1 class="rdv-title" style="margin:8px 0 6px;">Rendez-vous</h1>
    <p class="rdv-lede">Proposez plusieurs dates ou horaires, partagez le lien public : chacun indique ses disponibilités,
      sans créer de compte. Vous suivez les réponses dans un tableau et confirmez le créneau retenu. Les participants sont
      alors prévenus par e-mail.</p>
  </div>

  <?php if ($flash): ?>
    <p class="rdv-flash" data-kind="<?= h($flash['kind'] ?? 'ok') ?>" role="status"><?= h($flash['message'] ?? '') ?></p>
  <?php endif; ?>
  <p class="rdv-note" id="rdv-msg" role="status" hidden></p>

  <details class="rdv-card">
    <summary style="cursor:pointer;font-weight:700;">Créer une consultation de disponibilités</summary>
    <form id="rdv-create-form" style="display:grid;gap:14px;margin-top:14px;">
      <div class="rdv-grid-2">
        <label>Titre de la consultation
          <input type="text" name="title" maxlength="140" required placeholder="Ex. Essayage des retouches, dépôt d’objets…">
        </label>
        <label>Lieu ou adresse
          <input type="text" name="place" maxlength="160" placeholder="Ex. à la boutique, 12 rue des Puces">
        </label>
      </div>
      <label>Présentation (facultatif)
        <textarea name="description" rows="3" maxlength="2000" placeholder="Ce qu’il faut savoir : durée prévue, objets à apporter, accès…"></textarea>
      </label>

      <label>Créneaux proposés — une date par ligne
        <textarea name="slots" id="rdv-slots-create" rows="5" required placeholder="<?= h($template) ?>"></textarea>
      </label>
      <p class="rdv-note">Formats acceptés : <code>2026-11-14</code>, <code>14/11/2026</code>, <code>2026-11-14 09:00-12:00</code>,
        <code>14/11/2026 9h-12h</code>. Ajoutez <code>| Matin</code> pour nommer un créneau. Plusieurs lignes = plusieurs créneaux.</p>

      <div class="rdv-gen" data-rdv-generator data-target="#rdv-slots-create">
        <strong>Générer plusieurs créneaux</strong>
        <div class="rdv-grid-3">
          <label>Du <input type="date" data-gen="from" value="<?= h($genFrom) ?>"></label>
          <label>Au <input type="date" data-gen="to" value="<?= h($genTo) ?>"></label>
          <label>Durée (minutes) <input type="number" data-gen="duration" min="0" max="480" value="60" step="15"></label>
          <label>Premier horaire <input type="time" data-gen="start" value=""></label>
          <label>Dernier horaire <input type="time" data-gen="end" value=""></label>
        </div>
        <div class="rdv-days">
          <?php foreach (['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'] as $day => $label): ?>
            <label><input type="checkbox" class="rdv-gen-day" value="<?= $day ?>" checked> <?= h($label) ?></label>
          <?php endforeach; ?>
        </div>
        <div class="rdv-actions">
          <button type="button" class="rdv-btn rdv-btn-small" data-gen-run>Générer et ajouter</button>
          <span class="rdv-note" data-gen-status>Laissez les horaires vides pour proposer des journées entières.</span>
        </div>
      </div>

      <div class="rdv-grid-2">
        <label>Prévenir ces adresses des nouvelles réponses
          <input type="text" name="notify_email" maxlength="300" value="<?= h($defaultNotify) ?>" placeholder="vous@exemple.fr">
        </label>
        <label>Réponses closes après le (facultatif)
          <input type="date" name="closes_on" value="">
        </label>
      </div>

      <fieldset>
        <legend>Options</legend>
        <div style="display:grid;gap:8px;">
          <input type="hidden" name="results_public" value="0">
          <label class="rdv-inline"><input type="checkbox" name="results_public" value="1" checked> Afficher les résultats aux participants après leur réponse</label>
          <input type="hidden" name="names_public" value="0">
          <label class="rdv-inline"><input type="checkbox" name="names_public" value="1"> Afficher aussi les noms des autres participants</label>
          <input type="hidden" name="allow_maybe" value="0">
          <label class="rdv-inline"><input type="checkbox" name="allow_maybe" value="1" checked> Proposer la réponse « peut-être »</label>
        </div>
      </fieldset>

      <div class="rdv-actions">
        <button type="submit" class="rdv-btn rdv-btn-primary">Créer la consultation</button>
        <span class="rdv-note">Le lien public est créé avec la consultation ; vous pourrez le copier juste après.</span>
      </div>
    </form>
  </details>

  <section class="rdv-card">
    <h2>Consultations (<?= count($polls) ?>)</h2>
    <?php if (!$polls): ?>
      <p class="rdv-note" style="margin:0;">Aucune consultation pour le moment. Créez la première ci-dessus : elle peut servir
        à caler un essayage, une expertise, un dépôt ou un retrait d’objets.</p>
    <?php else: ?>
      <div class="rdv-list">
        <?php foreach ($polls as $poll):
          $id = (int) $poll['id'];
          $slots = rdv_slots($id);
          $answers = rdv_answers($id);
          $counts = rdv_counts($id);
          $bestId = rdv_best_slot($slots, $counts);
          $bestSlot = null;
          $confirmedSlot = null;
          foreach ($slots as $slot) {
            if ((int) $slot['id'] === $bestId) $bestSlot = $slot;
            if ((int) $slot['id'] === (int) ($poll['confirmed_slot_id'] ?? 0)) $confirmedSlot = $slot;
          }
          $status = (string) $poll['status'];
          $displayStatus = ($status === 'open' && !rdv_poll_open($poll)) ? 'closed' : $status;
          $publicPath = rdv_public_path((string) $poll['token']);
          ?>
          <article class="rdv-row">
            <div>
              <h3><a href="<?= h($manageUrl($id)) ?>" style="color:inherit;"><?= h($poll['title']) ?></a></h3>
              <p class="rdv-meta"><?= h(rdv_poll_range($slots, true) ?: 'Aucun créneau') ?>
                · <?= count($slots) ?> créneau<?= count($slots) > 1 ? 'x' : '' ?></p>
              <?php if ($confirmedSlot): ?>
                <p class="rdv-meta">Créneau confirmé : <strong><?= h(rdv_slot_label($confirmedSlot, true)) ?></strong></p>
              <?php elseif ($bestSlot): ?>
                <p class="rdv-meta">Meilleur créneau : <?= h(rdv_slot_label($bestSlot, true)) ?></p>
              <?php endif; ?>
            </div>
            <div>
              <span class="rdv-badge" data-status="<?= h($displayStatus) ?>"><?= h(rdv_status_label($displayStatus)) ?></span>
              <?php if ($poll['closes_on'] !== ''): ?><p class="rdv-meta" style="margin-top:6px;">Réponses jusqu’au <?= h(rdv_fr_date((string) $poll['closes_on'], true)) ?></p><?php endif; ?>
            </div>
            <div>
              <p class="rdv-meta" style="margin:0;"><strong><?= count($answers) ?></strong> réponse<?= count($answers) > 1 ? 's' : '' ?></p>
              <p class="rdv-meta" style="margin:0;"><?= $bestId ? (int) ($counts[$bestId]['yes'] ?? 0) : 0 ?> oui au meilleur créneau</p>
            </div>
            <div class="rdv-actions">
              <a class="rdv-btn rdv-btn-small" href="<?= h($manageUrl($id)) ?>">Gérer</a>
              <button type="button" class="rdv-btn rdv-btn-small" data-copy="<?= h(rdv_public_url((string) $poll['token'])) ?>">Copier le lien</button>
              <a class="rdv-btn rdv-btn-small" href="<?= h($publicPath) ?>" target="_blank" rel="noopener">Voir</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <p class="rdv-note">Les réponses arrivent dans cette page et par e-mail. Une consultation peut être close à tout moment :
    le lien public reste consultable, mais plus personne ne peut répondre. Supprimer une consultation efface aussi ses réponses.</p>
</div>
<script src="<?= h($pluginAsset('admin.js')) ?>" defer></script>
