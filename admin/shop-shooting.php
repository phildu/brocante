<?php
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/shop-shooting.php';
require_once __DIR__ . '/../includes/share.php';
require_once __DIR__ . '/../includes/shooting-tabs.php';
require_admin();

$content = get_content();
$aiReady = (bool) GEMINI_API_KEY;
$siteName = trim((string) $content['site_name']) ?: (string) tenant('name');
$state = shop_shooting_state();
$targets = shop_shooting_targets();
$session = admin_session() ?? [];
$role = (string) ($session['role'] ?? '');
$isAdmin = $role === 'admin';
$canPublishLive = shop_shooting_can_publish_live();
$pendingRows = $isAdmin ? shop_shooting_requests_pending() : [];
$myRows = !$isAdmin && ($session['id'] ?? null) !== null ? shop_shooting_requests_mine((int) $session['id']) : [];
$activeInvites = $isAdmin ? shop_shooting_invites_active() : [];
$dataJson = json_encode([
    'places' => shop_shooting_places(),
    'targets' => $targets,
    'generalTips' => shop_shooting_general_tips(),
    'issueLabels' => shop_shooting_issue_labels(),
    'defaultPlace' => shop_shooting_default_place(),
    'canPublishLive' => $canPublishLive,
    'sessionKey' => bin2hex(random_bytes(8)),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

/** Une cible est « en place » dès qu'une photo y est publiée. */
$targetFilled = static function (string $key) use ($state): bool {
    if (str_starts_with($key, 'banner:')) return !empty($state['banners'][substr($key, 7)]);
    if ($key === 'story') return (bool) $state['story'];
    return ((int) ($state[$key] ?? 0)) > 0;
};
/** Libellés des supports d'un envoi en attente. */
$requestTargets = static function (array $row): string {
    $labels = [];
    foreach ((array) (json_decode((string) $row['targets'], true) ?: []) as $key) {
        $info = shop_shooting_target((string) $key);
        if ($info !== null) $labels[] = $info['label'];
    }
    return $labels ? implode(' · ', $labels) : 'Aucun support choisi';
};
$requestStatus = static function (string $status): string {
    return ['pending' => 'En attente', 'published' => 'Publiée', 'rejected' => 'Refusée'][$status] ?? $status;
};
/** Les dates d'invitation sont stockées en UTC : on les affiche à l'heure du lieu. */
$inviteDate = static function (string $utc): string {
    try {
        $date = new DateTime($utc, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('Europe/Paris'));
        return $date->format('d/m/Y à H:i');
    } catch (Throwable $e) {
        return $utc;
    }
};
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f4eee1">
<title>Shooting boutique / stand — <?= h($siteName) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?= tenant_head_html() ?>
<link rel="icon" type="image/png" sizes="32x32" href="/pwa-icon.php?s=32">
<link rel="icon" type="image/png" sizes="16x16" href="/pwa-icon.php?s=16">
<link rel="manifest" href="/admin/shooting-manifest.php">
<link rel="apple-touch-icon" href="/pwa-icon.php?s=180">
<link rel="stylesheet" href="/assets/style.css">
<link rel="stylesheet" href="/assets/shooting-tabs.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shooting-tabs.css') ?>">
<link rel="stylesheet" href="/assets/share.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.css') ?>">
<link rel="stylesheet" href="/assets/shop-shooting.css?v=<?= (int) @filemtime(__DIR__ . '/../assets/shop-shooting.css') ?>">
</head>
<body>
<?php include __DIR__ . '/../includes/icons.php'; ?>
<?php $adminNav = 'assistant'; include __DIR__ . '/../includes/admin-nav.php'; ?>
<main class="ss-main">
  <div class="ss" data-action="/admin/shop-shooting-action.php" data-ai="<?= $aiReady ? '1' : '0' ?>" data-role="<?= h($role) ?>">
    <?= shooting_tabs_html($role, 'shooting-lieu') ?>

    <header class="ss-head">
      <p class="eyebrow">Photos du lieu · parcours guidé</p>
      <h1>Shooting boutique / stand</h1>
      <p class="ss-lede">Un programme de prise de vue pour photographier votre boutique, votre stand ou votre atelier, puis publier chaque photo au bon endroit du site : accueil, « Notre histoire », écran en magasin et bandeaux de page.</p>
      <?php if (!$canPublishLive): ?>
        <p class="ss-notice"><b>Accès photographe.</b> Vos photos ne partent pas directement sur le site : vous préparez les supports et la légende, l'administrateur valide la mise en ligne. Il est prévenu dès que vous terminez la séance.</p>
      <?php endif; ?>
      <?php if ($isAdmin && $pendingRows): ?>
        <p class="ss-notice ss-notice-admin"><b><?= count($pendingRows) ?> photo<?= count($pendingRows) > 1 ? 's' : '' ?> à valider</b> — envoyée<?= count($pendingRows) > 1 ? 's' : '' ?> par un photographe, en bas de page.</p>
      <?php endif; ?>
      <?php if (!$aiReady): ?>
        <p class="ss-warn">Clé Gemini non configurée : le contrôle de netteté et de lumière fonctionne (il est calculé sur le serveur), mais la critique IA, la retouche et la légende suggérée seront indisponibles.</p>
      <?php endif; ?>
    </header>

    <ol class="ss-steps" aria-label="Étapes">
      <li id="ss-step-1" aria-current="step">Lieu</li>
      <li id="ss-step-2">Programme</li>
      <li id="ss-step-3">Prise de vue</li>
      <li id="ss-step-4"><?= $canPublishLive ? 'Publication' : 'Validation' ?></li>
    </ol>

    <!-- Étape 1 : le lieu à photographier -->
    <section class="ss-panel" id="ss-panel-place" aria-labelledby="ss-place-title">
      <div class="ss-card">
        <div class="ss-card-head">
          <span class="ss-num" aria-hidden="true">1</span>
          <div>
            <h2 id="ss-place-title">Que photographiez-vous ?</h2>
            <p>Le programme s'adapte au lieu : les vues attendues, les conseils et les endroits où publier ne sont pas les mêmes.</p>
          </div>
        </div>

        <div class="ss-places" id="ss-places" role="group" aria-label="Lieu à photographier">
          <?php foreach (shop_shooting_places() as $key => $place): ?>
            <button type="button" data-place="<?= h($key) ?>" aria-pressed="false">
              <span aria-hidden="true"><?= $place['icon'] ?></span>
              <b><?= h($place['title']) ?></b>
              <small><?= count($place['shots']) ?> vues</small>
            </button>
          <?php endforeach; ?>
        </div>

        <div class="ss-before" id="ss-before" hidden>
          <p class="ss-kicker">Avant de commencer</p>
          <ul id="ss-before-list"></ul>
        </div>

        <div class="ss-actions">
          <button type="button" class="btn btn-primary" id="ss-to-program" disabled>Voir le programme</button>
        </div>
      </div>

      <?php if ($isAdmin): ?>
      <div class="ss-card" id="ss-pending-card">
        <div class="ss-card-head">
          <span class="ss-num" aria-hidden="true">✓</span>
          <div>
            <h2>Photos à valider <span class="ss-count" id="ss-pending-count"<?= $pendingRows ? '' : ' hidden' ?>><?= count($pendingRows) ?></span></h2>
            <p>Envoyées par un photographe : vérifiez le cadrage, la légende et les supports avant de publier.</p>
          </div>
        </div>
        <ul class="ss-pending" id="ss-pending-list"<?= $pendingRows ? '' : ' hidden' ?>>
          <?php foreach ($pendingRows as $row): ?>
            <li data-request="<?= (int) $row['id'] ?>">
              <a href="/<?= h($row['path']) ?>" target="_blank" rel="noopener"><img src="/<?= h($row['path']) ?>" alt="" loading="lazy"></a>
              <div class="ss-pending-body">
                <b><?= h($row['shot_label']) ?></b>
                <small><?= h($row['author_name'] !== '' ? $row['author_name'] : 'Photographe') ?> · <?= h($row['created_at']) ?></small>
                <?php if ($row['caption'] !== ''): ?><p>« <?= h($row['caption']) ?> »</p><?php endif; ?>
                <small><?= h($requestTargets($row)) ?></small>
              </div>
              <div class="ss-pending-actions">
                <button type="button" class="btn-small" data-decide="publish">Publier</button>
                <button type="button" class="btn-small ss-ghost" data-decide="reject">Refuser</button>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="ss-note" id="ss-pending-empty"<?= $pendingRows ? ' hidden' : '' ?>>Aucune photo en attente de validation.</p>
        <p class="ss-note" id="ss-pending-note" role="status" aria-live="polite" hidden></p>
      </div>
      <?php else: ?>
      <div class="ss-card">
        <h2>Mes envois</h2>
        <p class="ss-note">Les photos que vous avez envoyées, cette séance ou les jours précédents.</p>
        <ul class="ss-pending" id="ss-mine-list"<?= $myRows ? '' : ' hidden' ?>>
          <?php foreach ($myRows as $row): ?>
            <li data-status="<?= h($row['status']) ?>">
              <img src="/<?= h($row['path']) ?>" alt="" loading="lazy">
              <div class="ss-pending-body">
                <b><?= h($row['shot_label']) ?></b>
                <small><?= h($requestTargets($row)) ?></small>
              </div>
              <span class="ss-pill" data-status="<?= h($row['status']) ?>"><?= h($requestStatus((string) $row['status'])) ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
        <p class="ss-note" id="ss-mine-empty"<?= $myRows ? ' hidden' : '' ?>>Aucun envoi pour l'instant.</p>
      </div>
      <?php endif; ?>

      <div class="ss-card">
        <h2>Ce qui est déjà en place</h2>
        <p class="ss-note">Les photos publiées ici se règlent ensuite dans les écrans habituels.</p>
        <ul class="ss-state">
          <?php foreach ($targets as $key => $target): ?>
            <li class="<?= $targetFilled($key) ? 'is-filled' : 'is-empty' ?>">
              <span class="ss-dot" aria-hidden="true"></span>
              <span class="ss-state-label"><?= h($target['label']) ?></span>
              <small><?= h($target['where']) ?></small>
              <?php if ($isAdmin): ?><a href="<?= h($target['admin']) ?>">Régler</a><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($state['published']): ?>
          <p class="ss-kicker">Dernières photos publiées par ce parcours</p>
          <div class="ss-history">
            <?php foreach (array_slice($state['published'], 0, 8) as $row): ?>
              <figure>
                <img src="/<?= h($row['path']) ?>" alt="<?= h($row['label']) ?>" loading="lazy">
                <figcaption><?= h($row['label']) ?></figcaption>
              </figure>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isAdmin): ?>
      <div class="ss-card" id="ss-share-card">
        <h2>Partager l'accès au shooting</h2>
        <p class="ss-note">Envoyez ce lien à un photographe : il crée son compte « photographe » et arrive directement sur ce parcours. Il peut préparer les photos, mais seul un administrateur les met en ligne. Le lien ne sert qu'une fois et expire au bout de 7 jours.</p>
        <div class="ss-actions">
          <button type="button" class="btn btn-primary" id="ss-share">Créer un lien de partage</button>
        </div>
        <p class="ss-note" id="ss-share-note" role="status" aria-live="polite" hidden></p>
        <div class="ss-share-result" id="ss-share-result" hidden>
          <label class="ss-field">Lien à transmettre
            <input type="text" id="ss-share-url" readonly>
          </label>
          <p class="ss-note" id="ss-share-expires"></p>
          <div class="ss-actions">
            <button type="button" class="btn btn-ghost" id="ss-share-copy">Copier le lien</button>
            <?= share_button_html('', 'Shooting boutique / stand — ' . $siteName, 'Prenez les photos de la boutique avec ce lien :', '', 'ss-share-open', true, true) ?>
          </div>
        </div>
        <?php if ($activeInvites): ?>
          <div id="ss-invites-wrap">
            <p class="ss-kicker">Liens encore valables</p>
            <ul class="ss-invites" id="ss-invites">
              <?php foreach ($activeInvites as $invite): ?>
                <li data-invite="<?= (int) $invite['id'] ?>">
                  <span>Créé le <?= h($inviteDate((string) $invite['created_at'])) ?> · expire le <?= h($inviteDate((string) $invite['expires_at'])) ?></span>
                  <button type="button" class="btn-small ss-ghost" data-revoke>Révoquer</button>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <div id="ss-invites-wrap" hidden>
            <p class="ss-kicker">Liens encore valables</p>
            <ul class="ss-invites" id="ss-invites"></ul>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="ss-card">
        <h2>Quelques repères</h2>
        <ul class="ss-tips">
          <?php foreach (shop_shooting_general_tips() as $tip): ?>
            <li><span aria-hidden="true"><?= $tip['icon'] ?></span><?= h($tip['text']) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <!-- Étape 2 : le programme de prise de vue -->
    <section class="ss-panel" id="ss-panel-program" aria-labelledby="ss-program-title" hidden>
      <div class="ss-card">
        <div class="ss-card-head">
          <span class="ss-num" aria-hidden="true">2</span>
          <div>
            <h2 id="ss-program-title">Le programme</h2>
            <p id="ss-program-intro"></p>
          </div>
        </div>

        <ol class="ss-plan" id="ss-plan"></ol>

        <div class="ss-tools">
          <label class="ss-switch">
            <input type="checkbox" id="ss-audio-toggle" checked>
            <span>Coaching audio<br><small>Chaque vue est annoncée à voix haute, avec son conseil.</small></span>
          </label>
          <div class="ss-audio-actions">
            <button type="button" class="btn btn-ghost" id="ss-audio-play">🔊 Écouter le programme</button>
            <button type="button" class="btn btn-ghost" id="ss-audio-stop" hidden>⏹ Arrêter</button>
          </div>
        </div>

        <div class="ss-actions">
          <button type="button" class="btn btn-primary" id="ss-start">Commencer la prise de vue</button>
          <button type="button" class="btn btn-ghost" id="ss-back-place">Changer de lieu</button>
        </div>
      </div>
    </section>

    <!-- Étape 3 : la prise de vue, vue par vue -->
    <section class="ss-panel" id="ss-panel-shot" aria-labelledby="ss-shot-title" hidden>
      <div class="ss-card">
        <div class="ss-progress">
          <div class="ss-progress-bar"><span id="ss-progress-fill"></span></div>
          <p class="ss-progress-text" id="ss-progress-text"></p>
        </div>

        <div class="ss-shot-head">
          <p class="ss-badge" id="ss-shot-badge"></p>
          <h2 id="ss-shot-title"></h2>
          <p id="ss-shot-tip"></p>
          <p class="ss-ratio" id="ss-shot-ratio" hidden></p>
          <button type="button" class="btn btn-ghost ss-listen" id="ss-shot-coach">🔊 Écouter le conseil</button>
        </div>

        <input type="file" accept="image/*" capture="environment" id="ss-shot-cam" hidden>
        <input type="file" accept="image/*" id="ss-shot-lib" hidden>

        <div class="ss-shot-stage" id="ss-shot-stage">
          <div class="ss-shot-empty" id="ss-shot-empty">
            <div class="ss-drop-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13.5" r="3.5"/></svg>
            </div>
            <p class="ss-drop-help">Pas de flash, téléphone à deux mains.</p>
            <div class="ss-drop-actions">
              <button type="button" class="btn btn-primary" id="ss-shot-take">Ouvrir l'appareil photo</button>
              <button type="button" class="btn btn-ghost" id="ss-shot-lib-btn">Choisir dans la galerie</button>
            </div>
          </div>
          <img id="ss-shot-img" alt="Photo du lieu en cours de validation" hidden>
        </div>

        <div class="ss-quality" id="ss-shot-quality" aria-live="polite" hidden></div>

        <div class="ss-compare" id="ss-compare" hidden>
          <figure>
            <img id="ss-compare-before" alt="Photo avant retouche">
            <figcaption>Avant</figcaption>
            <button type="button" class="btn-small" data-keep="before">Garder l'originale</button>
          </figure>
          <figure>
            <img id="ss-compare-after" alt="Photo après retouche IA">
            <figcaption>Après retouche IA</figcaption>
            <button type="button" class="btn-small" data-keep="after">Garder la retouche</button>
          </figure>
        </div>

        <div class="ss-publish" id="ss-publish-box" hidden>
          <label class="ss-field">Légende affichée avec la photo (facultatif)
            <input type="text" id="ss-caption" maxlength="120" placeholder="Ex. : Notre boutique, rue des Halles">
          </label>
          <button type="button" class="btn-small" id="ss-caption-ai" hidden>✨ Suggérer une légende</button>

          <p class="ss-kicker">Publier sur</p>
          <div class="ss-targets" id="ss-targets"></div>

          <div class="ss-option" id="ss-overlay-wrap" hidden>
            <label for="ss-overlay">Voile sombre du bandeau : <span id="ss-overlay-value">60 %</span></label>
            <input type="range" id="ss-overlay" min="0" max="100" step="5" value="60">
            <p class="ss-note">Plus le voile est dense, plus le titre reste lisible sur la photo.</p>
          </div>

          <div class="ss-option" id="ss-duration-wrap" hidden>
            <label for="ss-duration">Durée d'affichage dans le diaporama boutique</label>
            <select id="ss-duration">
              <option value="5">5 secondes</option>
              <option value="8" selected>8 secondes</option>
              <option value="12">12 secondes</option>
              <option value="20">20 secondes</option>
            </select>
          </div>
        </div>

        <div class="ss-actions ss-actions-row">
          <button type="button" class="btn btn-ghost" id="ss-shot-again" hidden>↺ Reprendre</button>
          <button type="button" class="btn btn-ghost" id="ss-shot-skip">Passer cette vue</button>
          <button type="button" class="btn btn-ghost" id="ss-shot-review" hidden>🔍 Vérifier avec l'IA</button>
          <button type="button" class="btn btn-ghost" id="ss-shot-enhance" hidden>✨ Améliorer</button>
          <button type="button" class="btn btn-primary" id="ss-shot-publish" hidden><?= $canPublishLive ? 'Publier cette vue' : 'Envoyer pour validation' ?></button>
        </div>
        <p class="ss-note" id="ss-shot-note" hidden></p>
      </div>

      <div class="ss-thumbs" id="ss-thumbs" aria-label="Photos déjà <?= $canPublishLive ? 'publiées' : 'envoyées' ?> pendant la séance"></div>
      <div class="ss-actions">
        <button type="button" class="btn btn-primary" id="ss-to-done" hidden>Voir le récapitulatif</button>
      </div>
    </section>

    <!-- Étape 4 : ce qui a été publié -->
    <section class="ss-panel" id="ss-panel-done" aria-labelledby="ss-done-title" hidden>
      <div class="ss-card">
        <div class="ss-card-head">
          <span class="ss-num" aria-hidden="true">4</span>
          <div>
            <h2 id="ss-done-title"><?= $canPublishLive ? 'Photos publiées' : 'Photos envoyées' ?></h2>
            <p id="ss-done-text"></p>
            <p class="ss-notice" id="ss-done-notice" role="status" aria-live="polite" hidden></p>
          </div>
        </div>
        <div class="ss-done-grid" id="ss-done-grid"></div>
        <?php if ($isAdmin): ?>
        <div class="ss-actions">
          <a class="btn btn-primary" href="/" target="_blank" rel="noopener">Voir l'accueil du site</a>
          <a class="btn btn-ghost" href="/slideshow.php" target="_blank" rel="noopener">Voir l'écran en magasin</a>
          <a class="btn btn-ghost" href="/boutique.php" target="_blank" rel="noopener">Voir la boutique</a>
        </div>
        <?php endif; ?>
        <div class="ss-actions">
          <button type="button" class="btn btn-ghost" id="ss-again-place">Photographier un autre lieu</button>
          <?php if ($isAdmin): ?>
            <a class="btn btn-ghost" href="/admin/media.php">Retrouver les photos dans la médiathèque</a>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <p class="ss-audio-status" id="ss-audio-status" role="status" aria-live="polite"></p>
  </div>
</main>

<script type="application/json" id="ss-data"><?= $dataJson ?></script>
<script src="/assets/share.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/share.js') ?>" defer></script>
<script src="/assets/shop-shooting.js?v=<?= (int) @filemtime(__DIR__ . '/../assets/shop-shooting.js') ?>"></script>
</body>
</html>
