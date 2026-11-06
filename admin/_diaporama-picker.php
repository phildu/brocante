<?php
// Sélecteur du diaporama (inclus par admin/gallery.php) : variables $ref, $photos.
// Une vignette par image / vidéo de la pièce : un clic l'ajoute ou la retire du diaporama, un glisser-déposer
// (ou les flèches) change l'ordre ; l'enregistrement est automatique (admin/diaporama-action.php).
if (!isset($photos, $ref)) { http_response_code(404); exit; }
$dpItems = [];
foreach ($photos as $ph) {
    $isVideo = ($ph['type'] ?? 'photo') === 'video';
    $kind = $isVideo || !empty($ph['path_mobile']) ? null : image_format_kind(__DIR__ . '/../' . $ph['path']);
    $dpItems[] = [
        'id' => (int) $ph['id'], 'src' => $ph['path'], 'label' => $ph['label'], 'video' => $isVideo,
        'on' => !$ph['is_hidden'], 'ai' => (bool) $ph['is_illustration'],
        'screen' => $kind === 'mobile' ? 'smartphone' : ($kind === 'desktop' ? 'ordinateur' : ''),
    ];
}
// Les images montrées d'abord (dans l'ordre du diaporama), puis les masquées.
usort($dpItems, fn($a, $b) => (int) $b['on'] <=> (int) $a['on']);
?>
<link rel="stylesheet" href="/assets/diaporama-picker.css">
<div class="admin-block" style="margin-bottom:24px;" id="dp-block" data-csrf="<?= h(admin_csrf_token()) ?>" data-ref="<?= h($ref) ?>">
  <h2 style="font-size:1.1rem;">Diaporama de la fiche</h2>
  <p class="hint">Cliquez une vignette pour l'ajouter au diaporama ou l'en retirer ; <strong>glissez-les</strong> (ou utilisez ◀ ▶) pour changer l'ordre — la première est l'image principale. Enregistré automatiquement.
    Par défaut, une pièce créée avec le Studio ne montre que ses <strong>images générées</strong> : les photos du shooting restent ici, masquées, et peuvent être réajoutées.</p>
  <div class="dp-bar">
    <span class="dp-count" id="dp-count" aria-live="polite"></span>
    <span class="dp-save" id="dp-save" role="status" aria-live="polite"></span>
    <span class="dp-actions">
      <button type="button" class="btn-small" id="dp-generated">Seulement les images générées</button>
      <button type="button" class="btn-small" id="dp-all">Tout afficher</button>
    </span>
  </div>
  <div class="dp-grid" id="dp-grid" data-items="<?= h(json_encode($dpItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"></div>
</div>
<script src="/assets/diaporama-picker.js" defer></script>
