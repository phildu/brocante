<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$ref = (string) ($_GET['ref'] ?? '');
$product = get_product($ref);
if (!$product) {
    flash_set('Pièce introuvable.', 'error');
    header('Location: /admin/catalog.php');
    exit;
}

$content = get_content();
$photos = product_photos_list($ref);
$flash = flash_get();
$ffmpegOk = FFMPEG_BIN && shell_exec_available();
$photoPaths = [];
foreach ($photos as $ph) if (($ph['type'] ?? 'photo') === 'photo') $photoPaths[(int) $ph['id']] = $ph['path'];
$pendingVideos = db()->prepare("SELECT id, label, created_at FROM veo_jobs WHERE product_ref = ? AND status IN ('pending', 'saving') AND created_at > datetime('now', '-15 minutes') ORDER BY id");
$pendingVideos->execute([$ref]);
$pendingVideos = array_map(static fn (array $j): array => ['id' => (int) $j['id'], 'label' => $j['label'], 'since' => strtotime($j['created_at'] . ' UTC')], $pendingVideos->fetchAll());
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Photos — <?= h($product['name']) ?> — <?= h($content['site_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="stylesheet" href="/assets/style.css">
<style>
  .gen-sources { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
  .gen-src { position: relative; width: 88px; padding: 0; border: 2px solid var(--line); background: var(--surface); color: var(--ink); cursor: pointer; font: inherit; text-align: left; }
  .gen-src img { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; }
  .gen-src.is-on { border-color: var(--accent); }
  .gen-src-badge { display: none; position: absolute; top: 4px; left: 4px; min-width: 20px; height: 20px; line-height: 20px; padding: 0 4px; background: var(--accent); color: var(--accent-ink); font-size: 0.75rem; font-weight: 700; text-align: center; }
  .gen-src.is-on .gen-src-badge { display: block; }
  .gen-src-label { display: block; padding: 3px 5px; font-size: 0.68rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .gm-list { display: flex; flex-direction: column; gap: 16px; }
  .gm-item {
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 16px;
    align-items: center;
    padding: 16px;
    background: var(--surface);
    border: 1px solid var(--line);
  }
  .gm-thumb { width: 120px; height: 90px; background: var(--surface-2); overflow: hidden; }
  .gm-item.is-hidden-photo { opacity: 0.5; }
  .gm-item.is-hidden-photo .gm-thumb { filter: grayscale(1); }
  .gm-thumb img, .gm-thumb video { width: 100%; height: 100%; object-fit: contain; }
  /* Version ordinateur (3:2) et, à côté, sa version smartphone (9:16). */
  .gm-thumbs { display: flex; gap: 6px; align-items: center; }
  .gm-thumb-mobile { width: 51px; height: 90px; background: var(--surface-2); overflow: hidden; }
  .gm-thumb-mobile img { width: 100%; height: 100%; object-fit: contain; }
  .gm-thumb-mobile.is-pending { display: flex; align-items: center; justify-content: center; text-align: center; font-family: var(--font-mono); font-size: 0.6rem; color: var(--ink-soft); border: 1px dashed var(--line); }
  .gm-fields { display: flex; flex-direction: column; gap: 8px; }
  .gm-fields input[type="text"] {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 8px 10px; font-family: var(--font-body); font-size: 0.9rem; max-width: 240px;
  }
  .gm-tag { font-family: var(--font-mono); font-size: 0.65rem; color: var(--accent); text-transform: uppercase; }
  .gm-actions { display: flex; flex-direction: column; gap: 6px; align-items: stretch; min-width: 130px; }
  .gm-actions .btn-small { width: 100%; }
  .gm-order { display: flex; gap: 6px; }
  .gm-order button { flex: 1; }
  .gm-generate { display: flex; gap: 14px; flex-wrap: wrap; margin: 24px 0; }

  .cropper-backdrop {
    position: fixed; inset: 0; background: rgba(20,15,8,0.7);
    display: none; align-items: center; justify-content: center; z-index: 100;
  }
  .cropper-backdrop.is-open { display: flex; }
  .cropper-box {
    background: var(--bg); border: 1px solid var(--line); padding: 20px;
    max-width: 92vw; max-height: 92vh; display: flex; flex-direction: column; gap: 14px;
  }
  .cropper-canvas-wrap { position: relative; max-width: 80vw; max-height: 58vh; overflow: auto; }
  .cropper-canvas-wrap canvas { display: block; cursor: crosshair; max-width: 100%; }
  .cropper-actions { display: flex; gap: 10px; justify-content: flex-end; }
  .cropper-options { display: flex; gap: 18px; align-items: center; flex-wrap: wrap; }
  .cropper-options select {
    background: var(--bg); border: 1px solid var(--line); color: var(--ink);
    padding: 6px 8px; font-family: var(--font-body); font-size: 0.85rem;
  }

  .formats-preview-wrap { position: relative; max-width: 78vw; max-height: 62vh; }
  .formats-preview-wrap svg { display: block; background: var(--surface-2); }
  .formats-preview-legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 0.78rem; }
  .formats-preview-legend span { display: inline-flex; align-items: center; gap: 6px; }
  .formats-preview-legend i { display: inline-block; width: 14px; height: 14px; border: 2px solid; border-radius: 2px; }
</style>
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'catalogue'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main>
<section class="tight">
  <div class="wrap">
    <p class="eyebrow">Réf. N°<?= h($ref) ?></p>
    <h1 style="font-size:1.8rem;margin:12px 0 8px;">Photos — <?= h($product['name']) ?></h1>
    <p style="margin:0 0 24px;">
      <a href="/admin/catalog.php#produit-<?= h($ref) ?>">← Retour à la fiche dans le catalogue</a>
      · <a href="/produit.php?ref=<?= h($ref) ?>" target="_blank">Voir la fiche publique →</a>
    </p>

    <?php if ($flash): ?>
      <p class="publish-status" data-kind="<?= h($flash['kind']) ?>" style="margin-bottom:20px;"><?= h($flash['message']) ?></p>
    <?php endif; ?>

    <?php $genStatus = recent_generation_status($ref); ?>
    <?php if ($genStatus && $genStatus['status'] !== 'done'): ?>
      <p class="publish-status" data-kind="<?= $genStatus['status'] === 'failed' ? 'error' : '' ?>" style="margin-bottom:20px;">
        <?= h($genStatus['message']) ?>
        <?php if ($genStatus['status'] === 'pending'): ?> <a href="?ref=<?= h($ref) ?>" style="color:var(--accent);">Actualiser</a><?php endif; ?>
      </p>
    <?php endif; ?>

    <div class="admin-block" style="margin-bottom:24px;">
      <h2 style="font-size:1.1rem;">Générer une nouvelle vue</h2>
      <p class="hint">Choisissez la photo de départ et le type de vue. Pour "Autre angle", précisez si besoin l'angle souhaité et des mots-clés libres.</p>
      <p class="publish-status" id="video-status" role="status" aria-live="polite" hidden style="margin:0 0 16px;"></p>
      <form method="post" action="/admin/gallery-action.php" id="generate-form"
        data-csrf="<?= h(admin_csrf_token()) ?>" data-ffmpeg="<?= $ffmpegOk ? '1' : '0' ?>"
        data-paths="<?= h(json_encode($photoPaths)) ?>" data-pending="<?= h(json_encode($pendingVideos)) ?>"
        data-modal-cutout="<?= modal_video_available() && !(shell_exec_available() && PHP_CLI_BIN && PYTHON_BIN) ? '1' : '0' ?>">
        <input type="hidden" name="ref" value="<?= h($ref) ?>">
        <input type="hidden" name="action" value="generate">
        <div class="field" id="gen-sources-field">
          <label>Photos de départ <span style="text-transform:none;letter-spacing:0;font-weight:400;">— cliquez pour en choisir une ou plusieurs ; la première choisie est la principale</span></label>
          <div class="gen-sources" id="gen-sources">
            <?php foreach ($photos as $i => $ph): if (($ph['type'] ?? 'photo') !== 'photo') continue; ?>
              <button type="button" class="gen-src" data-id="<?= (int) $ph['id'] ?>" title="<?= h($ph['label']) ?>" aria-pressed="false">
                <img src="/<?= h($ph['path']) ?>" alt="" loading="lazy"><span class="gen-src-badge"></span><span class="gen-src-label"><?= h($ph['label']) ?></span>
              </button>
            <?php endforeach; ?>
          </div>
          <input type="hidden" name="source_photo_id" value="">
          <input type="hidden" name="source_photo_ids" value="">
          <p class="hint" id="gen-sources-note" style="margin:6px 0 0;"></p>
        </div>
        <div class="field-row-3">
          <div class="field">
            <label>Type de génération</label>
            <select name="kind" id="gen-kind">
              <option value="angle">🔄 Autre angle</option>
              <option value="ambiance">🪄 Mise en situation (ambiance)</option>
              <option value="detoure">✂️ Détourage (fond transparent)</option>
              <option value="complete">🧩 Compléter l'objet (tronqué)</option>
              <option value="video">🎬 Petite vidéo (zoom, travelling) — gratuite</option>
              <option value="video_ai">🎞 Vidéo IA (Veo) — animation réaliste, payante</option>
            </select>
          </div>
          <div class="field" id="gen-angle-field">
            <label>Angle souhaité</label>
            <select name="angle_preset">
              <option value="auto">Laisser le choix à l'IA</option>
              <option value="dessus">Vue de dessus</option>
              <option value="dessous">Vue de dessous</option>
              <option value="trois-quarts">Trois-quarts</option>
              <option value="face">Face</option>
              <option value="profil">Profil</option>
              <option value="arriere">Arrière</option>
            </select>
          </div>
          <div class="field" id="gen-video-field" style="display:none;">
            <label>Effet vidéo</label>
            <select name="video_effect">
              <?php foreach (video_effects() as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field-row-3" id="gen-veo-field" style="display:none;">
          <div class="field">
            <label>Modèle</label>
            <select name="model">
              <?php foreach (VEO_MODELS as $key => $m): ?>
                <option value="<?= h($key) ?>"<?= $key === 'fast' ? ' selected' : '' ?>>Google Veo — <?= h($m['label']) ?></option>
              <?php endforeach; ?>
              <option value="<?= MODAL_MODEL_KEY ?>"<?= modal_video_available() ? '' : ' disabled' ?>>LTX-Video (Modal) — crédit gratuit Modal<?= modal_video_available() ? '' : ' · service à configurer dans les réglages' ?></option>
              <option value="<?= SF_MODEL_KEY ?>"<?= sf_available() ? '' : ' disabled' ?>>Wan 2.2 (SiliconFlow) — économique<?= sf_available() ? '' : ' · clé à renseigner dans les réglages' ?></option>
            </select>
          </div>
          <div class="field" id="gen-seconds-field">
            <label id="gen-seconds-label">Durée</label>
            <select name="seconds">
              <?php foreach (VEO_SECONDS as $sec): ?>
                <option value="<?= $sec ?>"<?= $sec === 6 ? ' selected' : '' ?>><?= $sec ?> secondes</option>
              <?php endforeach; ?>
            </select>
            <p class="hint" id="gen-seconds-note" style="display:none;margin:4px 0 0;"><span id="gen-seconds-note-text"></span></p>
          </div>
          <div class="field">
            <label>Format</label>
            <select name="aspect">
              <option value="auto">Selon la photo (portrait ou paysage)</option>
              <option value="16:9">Paysage 16:9 (ordinateur)</option>
              <option value="9:16">Portrait 9:16 (smartphone)</option>
            </select>
          </div>
          <div class="field">
            <label>Mouvement de caméra</label>
            <select name="veo_effect">
              <?php foreach (veo_motions() as $key => $label): ?>
                <option value="<?= h($key) ?>"><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="field" id="gen-keywords-field">
          <label>Mots-clés / précisions (optionnel)</label>
          <input type="text" name="keywords" placeholder="ex : fond en bois clair, lumière du matin, sans le couvercle..." maxlength="300" data-saved-prompts="@gen-kind" data-prompt-helper="@gen-kind">
        </div>
        <button type="submit" class="btn btn-primary">Générer</button>
        <span class="hint" id="gen-cost" style="margin:0 0 0 12px;" data-costs="<?= h(json_encode([
            'angle' => ai_estimate_label(['image' => 2]), 'ambiance' => ai_estimate_label(['image' => 2]), 'complete' => ai_estimate_label(['image' => 2]),
            'detoure' => FAL_API_KEY ? ai_estimate_label(['cutout' => 1]) : ai_estimate_label(['image' => 1]), 'video' => '',
        ])) ?>" data-veo-costs="<?= h(json_encode(veo_cost_labels())) ?>"
          data-eur="<?= h(json_encode(['angle' => ai_eur(ai_estimate_usd(['image' => 2])), 'ambiance' => ai_eur(ai_estimate_usd(['image' => 2])), 'complete' => ai_eur(ai_estimate_usd(['image' => 2])),
            'detoure' => ai_eur(ai_estimate_usd(ai_cutout_counts())), 'video' => 0])) ?>"
          data-veo-eur="<?= h(json_encode(veo_cost_eur_map())) ?>"></span>
      </form>
    </div>
    <script>
      (function () {
        var kindSelect = document.getElementById('gen-kind');
        var angleField = document.getElementById('gen-angle-field');
        var videoField = document.getElementById('gen-video-field');
        var keywordsField = document.getElementById('gen-keywords-field');
        var cost = document.getElementById('gen-cost');
        var costs = JSON.parse(cost.dataset.costs);
        var veoCosts = JSON.parse(cost.dataset.veoCosts);
        var veoField = document.getElementById('gen-veo-field');
        var genForm = document.getElementById('generate-form');
        var ffmpegOk = genForm.dataset.ffmpeg === '1';
        var costNote = { angle: ' : image 3:2 + version 9:16', ambiance: ' : image 3:2 + version 9:16', complete: ' : image 3:2 + version 9:16', detoure: '' };
        var eur = JSON.parse(cost.dataset.eur), veoEur = JSON.parse(cost.dataset.veoEur);
        function fmt(v) { return v < 0.005 ? '< 0,01 €' : '≈ ' + v.toFixed(2).replace('.', ',') + ' €'; }

        // Photos de départ : on les choisit en cliquant (la première choisie est la principale, marquée ★).
        var srcBox = document.getElementById('gen-sources'), srcNote = document.getElementById('gen-sources-note');
        var order = srcBox.firstElementChild ? [srcBox.firstElementChild.dataset.id] : [];
        window.genSelectedIds = function () { return order.slice(); };
        function renderSources() {
          Array.prototype.forEach.call(srcBox.children, function (b) {
            var i = order.indexOf(b.dataset.id);
            b.classList.toggle('is-on', i >= 0);
            b.setAttribute('aria-pressed', i >= 0 ? 'true' : 'false');
            b.querySelector('.gen-src-badge').textContent = i === 0 ? '★' : (i > 0 ? String(i + 1) : '');
          });
          genForm.elements.source_photo_id.value = order[0] || '';
          genForm.elements.source_photo_ids.value = order.join(',');
          sync();
        }
        srcBox.addEventListener('click', function (e) {
          var b = e.target.closest('.gen-src');
          if (!b) return;
          var i = order.indexOf(b.dataset.id);
          if (i >= 0) { if (order.length > 1) order.splice(i, 1); } else order.push(b.dataset.id);
          renderSources();
        });

        function sync() {
          var k = kindSelect.value;
          var n = Math.max(1, order.length);
          var perPhoto = k === 'detoure' || k === 'video' || k === 'video_ai';
          srcNote.textContent = n < 2
            ? (perPhoto ? '' : "Une seule photo choisie : pour que l'IA voie l'objet sous plusieurs côtés (dos, détails), choisissez-en plusieurs.")
            : k === 'detoure' ? n + ' photos : un détourage par photo (3 au plus par demande).' + (genForm.dataset.modalCutout === '1' ? ' Détourage haute précision (Modal) : 20 s à 2 min, suivi ici.' : '')
            : k === 'video' ? n + ' photos : une vidéo par photo (6 au plus par demande).'
            : k === 'video_ai' ? n + ' photos : une vidéo IA par photo — le coût est multiplié par ' + n + '.'
            : n + ' photos envoyées ensemble à l\'IA pour produire UNE image' + (n > 4 ? ' (seules les 4 premières sont envoyées).' : '.');
          var model = genForm.elements.model.value;
          var wan = model === 'wan22' || model === 'ltx';
          genForm.elements.seconds.disabled = wan;
          document.getElementById('gen-seconds-label').textContent = wan ? 'Durée (fixée par le service)' : 'Durée';
          document.getElementById('gen-seconds-note').style.display = wan ? '' : 'none';
          document.getElementById('gen-seconds-note-text').textContent = model === 'ltx'
            ? "LTX-Video n'a pas de réglage de durée : le clip dure environ 3 secondes (au-delà, il déformerait l'objet)."
            : "Wan 2.2 n'a pas de réglage de durée : le service fixe la longueur du clip (courte, de l'ordre de quelques secondes).";
          if (k === 'video_ai') {
            var vc = fmt(veoEur[genForm.elements.model.value + '-' + genForm.elements.seconds.value] * n);
            cost.textContent = model === 'ltx' ? 'Coût estimé ' + vc + ' par vidéo, prélevé sur le crédit gratuit Modal (30 $ par mois) · prête en 1 à 3 minutes'
              : wan ? 'Coût estimé ' + vc + ' par vidéo (courte, durée fixée par le service) · prête en quelques minutes'
              : 'Coût estimé ' + vc + ' (facturé seulement si la vidéo aboutit) · prête en 1 à 6 minutes';
          } else {
            cost.textContent = eur[k] ? 'Coût estimé ' + fmt(eur[k] * (k === 'detoure' ? n : 1)) + costNote[k] : (k === 'video' ? 'Vidéo : gratuite' + (ffmpegOk ? '' : ' (fabriquée dans votre navigateur)') : '');
          }
          angleField.style.display = k === 'angle' ? '' : 'none';
          videoField.style.display = k === 'video' ? '' : 'none';
          veoField.style.display = k === 'video_ai' ? '' : 'none';
          keywordsField.style.display = (k === 'detoure' || k === 'video') ? 'none' : '';
        }
        genForm.elements.model.addEventListener('change', sync);
        genForm.elements.seconds.addEventListener('change', sync);
        kindSelect.addEventListener('change', sync);
        renderSources();
      })();
    </script>
    <?php if (!GEMINI_API_KEY): ?>
      <p class="publish-status" data-kind="error" style="margin-bottom:20px;">Clé Gemini absente — les générations "ambiance", "autre angle" et "compléter l'objet" ne fonctionneront pas tant que la clé n'est pas renseignée dans Réglages du site. La vidéo « zoom, travelling » fonctionne sans elle ; la vidéo IA Veo la demande (la vidéo IA Wan 2.2 demande plutôt une clé SiliconFlow).</p>
    <?php endif; ?>
    <?php if (!shell_exec_available() || !PHP_CLI_BIN || !PYTHON_BIN): ?>
      <?php if (modal_video_available()): ?>
        <p class="publish-status" data-kind="ok" style="margin-bottom:20px;">Le détourage utilise votre service Modal (modèle BiRefNet, haute précision, vrai fond transparent). Le premier détourage après une pause peut prendre 1 à 2 minutes : la page le suit toute seule.</p>
      <?php elseif (FAL_API_KEY): ?>
        <p class="publish-status" data-kind="ok" style="margin-bottom:20px;">Python (rembg) local indisponible sur cet hébergement — le détourage utilise fal.ai à la place (vrai fond transparent, aucune différence pour vous).</p>
      <?php else: ?>
        <p class="publish-status" style="margin-bottom:20px;">Le vrai détourage (fond transparent) nécessite soit Python (rembg) en local, soit une clé fal.ai (voir Réglages du site) — sans les deux, "Détourage" génère un repli à fond blanc via Gemini (pas un vrai fond transparent). Les autres générations (ambiance, autre angle, compléter l'objet, netteté) fonctionnent normalement, elles n'en ont besoin d'aucun des deux.</p>
      <?php endif; ?>
    <?php endif; ?>
    <?php if (!modal_video_available()): ?>
      <p class="publish-status" style="margin-bottom:20px;">Vidéo IA gratuite (LTX-Video sur Modal) : déployez le service sur votre compte Modal, puis renseignez son adresse et son jeton dans <a href="/admin/index.php#modal-video-settings" style="color:var(--accent);">Réglages du site</a> (mode d'emploi dans le README, section « Vidéos de la galerie »).</p>
    <?php endif; ?>
    <?php if (!sf_available()): ?>
      <p class="publish-status" style="margin-bottom:20px;">Vidéo IA économique (Wan 2.2) : renseignez une clé SiliconFlow dans <a href="/admin/index.php#siliconflow-settings" style="color:var(--accent);">Réglages du site</a> pour l'activer.</p>
    <?php endif; ?>
    <?php if (!$ffmpegOk): ?>
      <p class="publish-status" style="margin-bottom:20px;">ffmpeg n'est pas disponible sur cet hébergement : la vidéo « zoom, travelling » est fabriquée dans votre navigateur (gratuite, 3 secondes) et envoyée à la galerie. La vidéo IA (Veo) passe par Google et ne dépend pas du serveur.</p>
    <?php endif; ?>

    <div class="gm-list">
      <?php foreach ($photos as $i => $ph): ?>
        <?php $isVideo = ($ph['type'] ?? 'photo') === 'video'; ?>
        <div class="gm-item<?= $ph['is_hidden'] ? ' is-hidden-photo' : '' ?>" id="photo-<?= (int) $ph['id'] ?>">
          <div class="gm-thumbs">
            <div class="gm-thumb">
              <?php if ($isVideo): ?>
                <video src="/<?= h($ph['path']) ?>" muted></video>
              <?php else: ?>
                <img src="/<?= h($ph['path']) ?>" alt="">
              <?php endif; ?>
            </div>
            <?php if (!empty($ph['path_mobile'])): ?>
              <div class="gm-thumb-mobile" title="Version smartphone (9:16)"><img src="/<?= h($ph['path_mobile']) ?>" alt=""></div>
            <?php elseif (!empty($ph['mobile_pending'])): ?>
              <div class="gm-thumb-mobile is-pending" title="Format manquant en cours de génération">format<br>en cours…</div>
            <?php endif; ?>
          </div>
          <div class="gm-fields">
            <?php if ($ph['is_illustration']): ?><span class="gm-tag">Vue d'illustration IA</span><?php endif; ?>
            <?php if ($isVideo): ?><span class="gm-tag">Vidéo</span><?php endif; ?>
            <form method="post" action="/admin/gallery-action.php" style="display:flex;gap:8px;align-items:center;">
              <input type="hidden" name="ref" value="<?= h($ref) ?>">
              <input type="hidden" name="action" value="rename">
              <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
              <input type="text" name="label" value="<?= h($ph['label']) ?>">
              <button type="submit" class="btn-small">Renommer</button>
            </form>
            <?php if (!$isVideo): ?>
              <label class="btn-small" style="cursor:pointer;width:fit-content;">
                Remplacer la photo
                <input type="file" accept="image/*" style="display:none" data-replace-input data-photo-id="<?= (int) $ph['id'] ?>">
              </label>
            <?php endif; ?>
            <form method="post" action="/admin/gallery-action.php" data-auto-submit style="display:flex;align-items:center;gap:6px;">
              <input type="hidden" name="ref" value="<?= h($ref) ?>">
              <input type="hidden" name="action" value="toggle_hidden">
              <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
              <label class="featured-check"><input type="checkbox" name="hidden" value="1" onchange="this.form.submit()"<?= $ph['is_hidden'] ? ' checked' : '' ?>> Masquer cette photo (invisible sur la fiche publique)</label>
            </form>
          </div>
          <div class="gm-actions">
            <div class="gm-order">
              <form method="post" action="/admin/gallery-action.php"><input type="hidden" name="ref" value="<?= h($ref) ?>"><input type="hidden" name="action" value="move_up"><input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>"><button type="submit" class="btn-small"<?= $i === 0 ? ' disabled' : '' ?>>↑</button></form>
              <form method="post" action="/admin/gallery-action.php"><input type="hidden" name="ref" value="<?= h($ref) ?>"><input type="hidden" name="action" value="move_down"><input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>"><button type="submit" class="btn-small"<?= $i === count($photos) - 1 ? ' disabled' : '' ?>>↓</button></form>
            </div>
            <?php if (!$isVideo): ?>
              <form method="post" action="/admin/gallery-action.php">
                <input type="hidden" name="ref" value="<?= h($ref) ?>">
                <input type="hidden" name="action" value="rotate">
                <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
                <button type="submit" class="btn-small" style="width:100%;">↻ Tourner (horaire)</button>
              </form>
              <button type="button" class="btn-small" data-crop-open data-mode="replace" data-photo-id="<?= (int) $ph['id'] ?>" data-src="/<?= h($ph['path']) ?>">Recadrer</button>
              <button type="button" class="btn-small" data-crop-open data-mode="detail" data-photo-id="<?= (int) $ph['id'] ?>" data-src="/<?= h($ph['path']) ?>">Créer un détail</button>
              <?php $isCutout = is_cutout_photo($ph); ?>
              <?php if ($ph['is_illustration'] && !$isCutout && empty($ph['path_mobile']) && empty($ph['mobile_pending'])): ?>
              <?php
                // Le bouton annonce le format manquant, d'après le format réel du visuel.
                [$missingLabel, $missingTitle] = match (image_format_kind(__DIR__ . '/../' . $ph['path'])) {
                    'mobile' => ['🖥 Créer la version 3:2 (IA)', 'Ce visuel est en 9:16 (smartphone) : prolonge le décor sur les côtés pour la version ordinateur'],
                    'other' => ['▭ Créer les versions 3:2 + 9:16 (IA)', 'Ce visuel n\'est ni en 3:2 ni en 9:16 : prolonge le décor pour obtenir les deux formats'],
                    default => ['📱 Créer la version 9:16 (IA)', 'Ce visuel est en 3:2 (ordinateur) : prolonge le décor en hauteur pour la version smartphone'],
                };
              ?>
              <form method="post" action="/admin/gallery-action.php">
                <input type="hidden" name="ref" value="<?= h($ref) ?>">
                <input type="hidden" name="action" value="mobile_variant">
                <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
                <button type="submit" class="btn-small" style="width:100%;" title="<?= h($missingTitle) ?>"><?= h($missingLabel) ?></button>
              </form>
              <?php endif; ?>
              <?php if ($ph['is_illustration'] && !$isCutout && !empty($ph['path_mobile']) && empty($ph['mobile_pending']) && image_format_kind(__DIR__ . '/../' . $ph['path']) === 'desktop'): ?>
              <form method="post" action="/admin/gallery-action.php" onsubmit="return confirm('Refaire la version smartphone (9:16) avec l\'IA ? L\'actuelle sera remplacée.');">
                <input type="hidden" name="ref" value="<?= h($ref) ?>">
                <input type="hidden" name="action" value="mobile_variant">
                <input type="hidden" name="force" value="1">
                <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
                <button type="submit" class="btn-small" style="width:100%;" title="La version smartphone est refaite par l'IA comme une vraie photo verticale de la même scène (pas des bandes floutées autour de la 3:2)">↻ Refaire la version 9:16 (IA)</button>
              </form>
              <?php endif; ?>
              <?php if ((!$ph['is_illustration'] || $isCutout) && empty($ph['path_mobile']) && empty($ph['mobile_pending'])): ?>
              <form method="post" action="/admin/gallery-action.php">
                <input type="hidden" name="ref" value="<?= h($ref) ?>">
                <input type="hidden" name="action" value="fit_formats">
                <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
                <?php if ($isCutout): ?>
                  <label class="featured-check" style="font-size:0.78rem;margin-bottom:4px;"><input type="checkbox" name="shadow" value="1" checked> Ombre portée (IA)</label>
                <?php endif; ?>
                <button type="submit" class="btn-small" style="width:100%;" title="Ajoute à la galerie une copie de cette photo en 3:2 (ordinateur) et 9:16 (smartphone), sans recadrage — transparente si la photo est détourée">▭ Format 3:2 + 9:16 → galerie</button>
              </form>
              <?php endif; ?>
              <button type="button" class="btn-small" style="width:100%;" data-formats-preview-open data-photo-id="<?= (int) $ph['id'] ?>" data-src="/<?= h($ph['path']) ?>" title="Vignette catalogue et fiche produit (3:2), diaporama plein écran, format réel, post et story réseaux sociaux">📐 Générer tous les formats → médiathèque</button>
            <?php endif; ?>
            <form method="post" action="/admin/gallery-action.php" onsubmit="return confirm('Retirer cette photo de la galerie ?');">
              <input type="hidden" name="ref" value="<?= h($ref) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
              <button type="submit" class="admin-delete" style="width:100%;">Supprimer</button>
            </form>
          </div>
          <form method="post" action="/admin/gallery-action.php" enctype="multipart/form-data" data-replace-form data-photo-id="<?= (int) $ph['id'] ?>" style="display:none;">
            <input type="hidden" name="ref" value="<?= h($ref) ?>">
            <input type="hidden" name="action" value="replace">
            <input type="hidden" name="photo_id" value="<?= (int) $ph['id'] ?>">
            <input type="file" name="photo" data-replace-file>
          </form>
        </div>
      <?php endforeach; ?>
      <?php if (!$photos): ?>
        <p class="empty-state">Aucune photo pour l'instant.</p>
      <?php endif; ?>
    </div>

    <form method="post" action="/admin/gallery-action.php" enctype="multipart/form-data" class="admin-block" style="margin-top:28px;">
      <input type="hidden" name="ref" value="<?= h($ref) ?>">
      <input type="hidden" name="action" value="add">
      <h2 style="font-size:1.1rem;">Ajouter une photo</h2>
      <div class="field-row" style="margin-top:14px;">
        <input type="text" name="label" placeholder="Nom (ex : Détail, Vue de dos...)" style="max-width:260px;">
        <input type="file" name="photo" accept="image/*" data-check-resolution required>
        <button type="submit" class="btn btn-primary">Ajouter</button>
      </div>
    </form>
  </div>
</section>
</main>

<div class="cropper-backdrop" id="formats-preview-backdrop">
  <div class="cropper-box">
    <p class="eyebrow" style="margin:0;">Aperçu des formats</p>
    <p class="hint" style="margin:0;">Rien n'est recadré : la photo est gardée en entier et chaque cadre montre le fond ajouté autour pour atteindre le format (couleur du bord de la photo, ou fond flouté). Le format réel reste tel quel.</p>
    <div class="formats-preview-wrap">
      <img id="formats-preview-img" src="" alt="" style="display:none">
      <svg id="formats-preview-svg" xmlns="http://www.w3.org/2000/svg"></svg>
    </div>
    <div class="formats-preview-legend">
      <span><i style="border-color:#b5502e;"></i> 3:2 — catalogue &amp; fiche produit</span>
      <span><i style="border-color:#46647a;"></i> 16:9 — diaporama point de vente</span>
      <span><i style="border-color:#6e7c57;"></i> 1:1 — post réseaux sociaux</span>
      <span><i style="border-color:#8a4fb0;"></i> 9:16 — story réseaux sociaux</span>
    </div>
    <div class="cropper-actions">
      <button type="button" class="btn-small" id="formats-preview-cancel">Annuler</button>
      <button type="button" class="btn btn-primary" id="formats-preview-confirm">Générer ces formats</button>
    </div>
  </div>
</div>

<form method="post" action="/admin/gallery-action.php" id="formats-preview-form">
  <input type="hidden" name="ref" value="<?= h($ref) ?>">
  <input type="hidden" name="action" value="export_formats">
  <input type="hidden" name="photo_id" id="formats-preview-photo-id">
</form>

<div class="cropper-backdrop" id="cropper-backdrop">
  <div class="cropper-box">
    <p class="eyebrow" style="margin:0;" id="cropper-title">Recadrer — cliquez-glissez pour choisir la zone</p>
    <div class="cropper-options">
      <label>Format
        <select id="cropper-ratio">
          <option value="libre">Libre</option>
          <option value="1.3333">Bureau (4:3)</option>
          <option value="0.75">Mobile (3:4)</option>
        </select>
      </label>
      <label class="featured-check" id="cropper-enhance-row" style="display:none;">
        <input type="checkbox" id="cropper-enhance"> Améliorer la netteté avec l'IA (si le détail semble flou ou petit)
      </label>
    </div>
    <div class="cropper-canvas-wrap"><canvas id="cropper-canvas"></canvas></div>
    <div class="cropper-actions">
      <button type="button" class="btn-small" id="cropper-cancel">Annuler</button>
      <button type="button" class="btn btn-primary" id="cropper-apply">Appliquer le recadrage</button>
    </div>
  </div>
</div>

<script>
(function () {
  // auto-submit "Remplacer la photo" once a file is chosen
  document.querySelectorAll('[data-replace-input]').forEach(function (input) {
    input.addEventListener('change', function () {
      if (!input.files[0]) return;
      var id = input.dataset.photoId;
      var form = document.querySelector('[data-replace-form][data-photo-id="' + id + '"]');
      var fileInput = form.querySelector('[data-replace-file]');
      var dt = new DataTransfer();
      dt.items.add(input.files[0]);
      fileInput.files = dt.files;
      form.submit();
    });
  });

  // cropper
  var backdrop = document.getElementById('cropper-backdrop');
  var canvas = document.getElementById('cropper-canvas');
  var ctx = canvas.getContext('2d');
  var img = new Image();
  var currentPhotoId = null;
  var currentMode = 'replace';
  var sel = null, dragging = false, startX = 0, startY = 0;
  var title = document.getElementById('cropper-title');
  var applyBtn = document.getElementById('cropper-apply');
  var ratioSelect = document.getElementById('cropper-ratio');
  var enhanceRow = document.getElementById('cropper-enhance-row');
  var enhanceCheckbox = document.getElementById('cropper-enhance');
  var ratioLabels = { '1.3333': 'Bureau 4:3', '0.75': 'Mobile 3:4', 'libre': '' };

  document.querySelectorAll('[data-crop-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      currentPhotoId = btn.dataset.photoId;
      currentMode = btn.dataset.mode || 'replace';
      img.onload = function () {
        // Fit within BOTH the available width and height — constraining only
        // width let tall/portrait photos render taller than the viewport.
        var maxW = Math.min(window.innerWidth * 0.8, 800);
        var maxH = window.innerHeight * 0.5;
        var scale = Math.min(1, maxW / img.naturalWidth, maxH / img.naturalHeight);
        canvas.width = img.naturalWidth * scale;
        canvas.height = img.naturalHeight * scale;
        canvas.dataset.scale = scale;
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        sel = null;
        ratioSelect.value = 'libre';
        if (currentMode === 'detail') {
          title.textContent = "Créer un détail — cliquez-glissez pour isoler la zone";
          applyBtn.textContent = 'Enregistrer comme détail';
          enhanceRow.style.display = '';
          enhanceCheckbox.checked = false;
        } else {
          title.textContent = 'Recadrer — cliquez-glissez pour choisir la zone';
          applyBtn.textContent = 'Appliquer le recadrage';
          enhanceRow.style.display = 'none';
        }
        backdrop.classList.add('is-open');
      };
      img.src = btn.dataset.src;
    });
  });

  function redraw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    if (sel) {
      ctx.strokeStyle = '#b5502e';
      ctx.lineWidth = 2;
      ctx.setLineDash([6, 4]);
      ctx.strokeRect(sel.x, sel.y, sel.w, sel.h);
      ctx.fillStyle = 'rgba(181,80,46,0.12)';
      ctx.fillRect(sel.x, sel.y, sel.w, sel.h);
    }
  }

  canvas.addEventListener('mousedown', function (e) {
    var rect = canvas.getBoundingClientRect();
    startX = e.clientX - rect.left;
    startY = e.clientY - rect.top;
    dragging = true;
  });
  canvas.addEventListener('mousemove', function (e) {
    if (!dragging) return;
    var rect = canvas.getBoundingClientRect();
    var x = Math.max(0, Math.min(canvas.width, e.clientX - rect.left));
    var y = Math.max(0, Math.min(canvas.height, e.clientY - rect.top));
    var ratioVal = ratioSelect.value;
    if (ratioVal === 'libre') {
      sel = { x: Math.min(startX, x), y: Math.min(startY, y), w: Math.abs(x - startX), h: Math.abs(y - startY) };
    } else {
      var ratio = parseFloat(ratioVal);
      var dx = x - startX, dy = y - startY;
      var w = Math.abs(dx), h = w / ratio;
      if (Math.abs(dy) > h) { h = Math.abs(dy); w = h * ratio; }
      var sx = dx < 0 ? startX - w : startX;
      var sy = dy < 0 ? startY - h : startY;
      sx = Math.max(0, sx); sy = Math.max(0, sy);
      if (sx + w > canvas.width) w = canvas.width - sx;
      if (sy + h > canvas.height) h = canvas.height - sy;
      sel = { x: sx, y: sy, w: w, h: h };
    }
    redraw();
  });
  window.addEventListener('mouseup', function () { dragging = false; });

  document.getElementById('cropper-cancel').addEventListener('click', function () {
    backdrop.classList.remove('is-open');
  });

  applyBtn.addEventListener('click', function () {
    if (!sel || sel.w < 8 || sel.h < 8) { alert('Dessinez d\'abord une zone à recadrer.'); return; }
    var scale = parseFloat(canvas.dataset.scale);
    var sx = sel.x / scale, sy = sel.y / scale, sw = sel.w / scale, sh = sel.h / scale;
    var out = document.createElement('canvas');
    out.width = sw; out.height = sh;
    out.getContext('2d').drawImage(img, sx, sy, sw, sh, 0, 0, sw, sh);
    var dataUrl = out.toDataURL('image/jpeg', 0.9);

    var form = document.createElement('form');
    form.method = 'post';
    form.action = '/admin/gallery-action.php';
    var html =
      '<input type="hidden" name="ref" value="<?= h($ref) ?>">' +
      '<input type="hidden" name="image_data" value="' + dataUrl.replace(/"/g, '&quot;') + '">';
    if (currentMode === 'detail') {
      html += '<input type="hidden" name="action" value="detail">'
        + '<input type="hidden" name="ratio_label" value="' + (ratioLabels[ratioSelect.value] || '') + '">'
        + '<input type="hidden" name="enhance" value="' + (enhanceCheckbox.checked ? '1' : '0') + '">';
    } else {
      html += '<input type="hidden" name="action" value="crop">'
        + '<input type="hidden" name="photo_id" value="' + currentPhotoId + '">';
    }
    form.innerHTML = html;
    document.body.appendChild(form);
    form.submit();
  });
})();

(function () {
  // Aperçu avant "Générer tous les formats" : la photo entière, entourée du
  // cadre de chaque format (fond ajouté autour, jamais de recadrage). Même
  // formule que fit_ratio_file() côté PHP, pour que l'aperçu soit exact.
  var fill = <?= json_encode(EXPORT_SUBJECT_FILL) ?>;
  var backdrop = document.getElementById('formats-preview-backdrop');
  var img = document.getElementById('formats-preview-img');
  var svg = document.getElementById('formats-preview-svg');
  var photoIdInput = document.getElementById('formats-preview-photo-id');

  var ratios = [
    { ratio: 3 / 2, color: '#b5502e' },
    { ratio: 16 / 9, color: '#46647a' },
    { ratio: 1, color: '#6e7c57' },
    { ratio: 9 / 16, color: '#8a4fb0' },
  ];

  function frameRect(iw, ih, ratio) {
    var cw, ch;
    if (iw / ih > ratio) { cw = iw / fill; ch = cw / ratio; } else { ch = ih / fill; cw = ch * ratio; }
    return { x: (iw - cw) / 2, y: (ih - ch) / 2, w: cw, h: ch };
  }

  function drawOverlay() {
    var iw = img.naturalWidth, ih = img.naturalHeight;
    var rects = ratios.map(function (r) { return frameRect(iw, ih, r.ratio); });
    // Vue englobant tous les cadres, mise à l'échelle de l'écran.
    var minX = Math.min.apply(null, rects.map(function (r) { return r.x; }));
    var minY = Math.min.apply(null, rects.map(function (r) { return r.y; }));
    var vw = iw - 2 * minX, vh = ih - 2 * minY;
    var pad = Math.max(vw, vh) * 0.01;
    svg.setAttribute('viewBox', (minX - pad) + ' ' + (minY - pad) + ' ' + (vw + 2 * pad) + ' ' + (vh + 2 * pad));
    var scale = Math.min(window.innerWidth * 0.78 / (vw + 2 * pad), window.innerHeight * 0.62 / (vh + 2 * pad));
    svg.setAttribute('width', Math.round((vw + 2 * pad) * scale));
    svg.setAttribute('height', Math.round((vh + 2 * pad) * scale));
    var html = '<image href="' + img.src.replace(/"/g, '&quot;') + '" x="0" y="0" width="' + iw + '" height="' + ih + '"></image>';
    ratios.forEach(function (r, i) {
      var rect = rects[i];
      var strokeWidth = Math.max(2, Math.round(Math.max(vw, vh) * 0.004));
      html += '<rect x="' + rect.x + '" y="' + rect.y + '" width="' + rect.w + '" height="' + rect.h + '" '
        + 'fill="none" stroke="' + r.color + '" stroke-width="' + strokeWidth + '" stroke-dasharray="'
        + (strokeWidth * 3) + ',' + (strokeWidth * 2) + '"></rect>';
    });
    svg.innerHTML = html;
  }

  document.querySelectorAll('[data-formats-preview-open]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      photoIdInput.value = btn.dataset.photoId;
      img.onload = drawOverlay;
      img.src = btn.dataset.src;
      backdrop.classList.add('is-open');
    });
  });

  document.getElementById('formats-preview-cancel').addEventListener('click', function () {
    backdrop.classList.remove('is-open');
  });

  document.getElementById('formats-preview-confirm').addEventListener('click', function () {
    document.getElementById('formats-preview-form').submit();
  });
})();
</script>
<script src="/assets/admin-upload-check.js"></script>
<script src="/assets/saved-prompts.js"></script>
<script src="/assets/prompt-helper.js"></script>
<script src="/assets/video-gen.js"></script>
</body>
</html>
