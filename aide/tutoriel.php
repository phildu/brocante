<?php
// Fiche tutoriel : prérequis, étapes, erreurs, captures réelles ou emplacements « capture à faire »,
// storyboard vidéo et sous-titres. Aucune capture ni vidéo n'est fabriquée : les fichiers absents sont annoncés.
require_once __DIR__ . '/_base.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
$tutorial = aide_find($slug);
if (!$tutorial || $tutorial['type'] !== 'tutoriel') {
    http_response_code(404);
    saas_page_start('Tutoriel introuvable — Aide', "Ce tutoriel n'existe pas ou a été renommé.");
    ?>
    <link rel="stylesheet" href="/aide/assets/aide.css">
    <section class="aide-section wrap">
      <nav class="aide-breadcrumb" aria-label="Fil d'Ariane"><a href="/aide/">Aide</a> <span aria-hidden="true">›</span> Tutoriel introuvable</nav>
      <h1>Tutoriel introuvable</h1>
      <p>Ce tutoriel n'existe pas ou a été renommé. La recherche du centre d'aide peut vous aider.</p>
      <p><a class="aide-btn" href="/aide/">Revenir au centre d'aide</a></p>
    </section>
    <?php
    saas_page_end();
    exit;
}

$related = aide_related($tutorial, 3);
$ficheCapture = '/aide/assets/captures/fiche-' . $tutorial['slug'] . '.md';
$video = $tutorial['video'];
$hasVideo = aide_video_exists($tutorial);
$hasVtt = aide_vtt_exists($tutorial);

saas_page_start($tutorial['titre'] . ' — Aide', $tutorial['resume']);
?>
<link rel="stylesheet" href="/aide/assets/aide.css">
<article class="aide-article">
  <div class="wrap">
    <nav class="aide-breadcrumb" aria-label="Fil d'Ariane"><a href="/aide/">Aide</a> <span aria-hidden="true">›</span> <a href="/aide/?role=<?= saas_e($tutorial['role']) ?>"><?= saas_e($tutorial['role_label']) ?></a> <span aria-hidden="true">›</span> Tutoriel</nav>
    <header class="aide-article-head">
      <p class="aide-kicker">Tutoriel · <?= saas_e($tutorial['role_label']) ?><?= $video && $video['duree'] !== '' ? ' · ' . saas_e($video['duree']) : '' ?></p>
      <h1><?= saas_e($tutorial['titre']) ?></h1>
      <p class="lede"><?= saas_e($tutorial['resume']) ?></p>
      <div class="aide-article-actions">
        <?php if ($tutorial['url_cible'] !== ''): ?><a class="aide-btn" href="<?= saas_e($tutorial['url_cible']) ?>">Ouvrir la page du portail</a><?php endif; ?>
        <?php if ($tutorial['url_publique'] !== ''): ?><a class="aide-btn aide-btn-ghost" href="<?= saas_e($tutorial['url_publique']) ?>">Voir la page publique</a><?php endif; ?>
      </div>
    </header>

    <?php if ($tutorial['ecart'] !== ''): ?>
    <aside class="aide-callout" role="note">
      <h2>Écart constaté</h2>
      <p><?= saas_e($tutorial['ecart']) ?></p>
    </aside>
    <?php endif; ?>

    <?php if ($tutorial['prerequis']): ?>
    <section class="aide-article-section" aria-labelledby="prerequis-titre">
      <h2 id="prerequis-titre">Avant de commencer</h2>
      <ul class="aide-checklist">
        <?php foreach ($tutorial['prerequis'] as $prerequis): ?><li><?= saas_e($prerequis) ?></li><?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <?php if ($tutorial['etapes']): ?>
    <section class="aide-article-section" aria-labelledby="etapes-titre">
      <h2 id="etapes-titre">Étapes</h2>
      <ol class="aide-tutorial-steps">
        <?php foreach ($tutorial['etapes'] as $i => $step): ?>
        <li>
          <h3><?= saas_e($step['titre'] !== '' ? $step['titre'] : 'Étape ' . ($i + 1)) ?></h3>
          <p><?= saas_e($step['texte']) ?></p>
          <?php if ($step['ecran'] !== ''): ?><p class="aide-screen"><strong>Ce qui doit apparaître :</strong> <?= saas_e($step['ecran']) ?></p><?php endif; ?>
          <?php if ($step['lien'] !== ''): ?><p class="aide-more"><a href="<?= saas_e($step['lien']) ?>">Ouvrir cette page</a></p><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php endif; ?>

    <?php if ($tutorial['captures']): ?>
    <section class="aide-article-section" aria-labelledby="captures-titre">
      <h2 id="captures-titre">Captures d'écran</h2>
      <p class="aide-hint">Les captures réelles sont prises sur le serveur local avec un compte de test. Quand une capture manque, l'emplacement indique exactement quoi photographier : aucune image n'est fabriquée.</p>
      <div class="aide-captures">
        <?php foreach ($tutorial['captures'] as $capture): ?>
          <?php if (aide_capture_exists($capture)): ?>
          <figure class="aide-capture">
            <img src="<?= saas_e(aide_capture_url($capture)) ?>" alt="<?= saas_e($capture['legende'] !== '' ? $capture['legende'] : 'Capture du tutoriel') ?>" loading="lazy">
            <figcaption><?= saas_e($capture['legende']) ?></figcaption>
          </figure>
          <?php else: ?>
          <div class="aide-capture-todo" role="note">
            <p class="aide-kicker">Capture à faire</p>
            <?php if ($capture['legende'] !== ''): ?><p><strong><?= saas_e($capture['legende']) ?></strong></p><?php endif; ?>
            <dl>
              <?php if ($capture['url'] !== ''): ?><dt>URL exacte</dt><dd><code><?= saas_e($capture['url']) ?></code></dd><?php endif; ?>
              <?php if ($capture['compte'] !== ''): ?><dt>Compte / jeu de données</dt><dd><?= saas_e($capture['compte']) ?></dd><?php endif; ?>
              <?php if ($capture['action'] !== ''): ?><dt>Action</dt><dd><?= saas_e($capture['action']) ?></dd><?php endif; ?>
              <?php if ($capture['cadrage'] !== ''): ?><dt>Cadrage</dt><dd><?= saas_e($capture['cadrage']) ?></dd><?php endif; ?>
              <?php if ($capture['remplacer'] !== ''): ?><dt>Texte de remplacement</dt><dd><?= saas_e($capture['remplacer']) ?></dd><?php endif; ?>
            </dl>
            <p class="aide-hint">Fichier attendu : <code><?= saas_e($capture['fichier']) ?></code> · <a href="<?= saas_e($ficheCapture) ?>">Fiche de capture</a></p>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($video): ?>
    <section class="aide-article-section" aria-labelledby="video-titre">
      <h2 id="video-titre">Vidéo</h2>
      <?php if ($hasVideo): ?>
      <figure class="aide-video">
        <video controls preload="metadata" width="1280" height="720">
          <source src="<?= saas_e($video['fichier']) ?>" type="video/mp4">
          <?php if ($hasVtt): ?><track kind="captions" src="<?= saas_e($video['sous_titres']) ?>" srclang="fr" label="Français" default><?php endif; ?>
        </video>
        <?php if ($hasVtt): ?><figcaption>Sous-titres : <a href="<?= saas_e($video['sous_titres']) ?>"><?= saas_e(basename($video['sous_titres'])) ?></a></figcaption><?php endif; ?>
      </figure>
      <?php else: ?>
      <div class="aide-video-todo" role="note">
        <p class="aide-kicker">Vidéo à tourner</p>
        <p>Aucun fichier vidéo factice n'est livré. Le storyboard ci-dessous donne le découpage, les actions et les textes à l'écran ; les sous-titres sont prêts.</p>
        <p>Fichier attendu : <code><?= saas_e($video['fichier']) ?></code> (MP4 H.264, 60–90 s) · Sous-titres : <a href="<?= saas_e($video['sous_titres']) ?>"><?= saas_e(basename($video['sous_titres'])) ?></a></p>
      </div>
      <?php endif; ?>
      <?php if ($video['plans']): ?>
      <div class="aide-table-wrap">
        <table class="aide-storyboard">
          <caption>Storyboard — <?= saas_e($video['duree'] !== '' ? $video['duree'] : '60 à 90 secondes') ?></caption>
          <thead><tr><th scope="col">Temps</th><th scope="col">Plan</th><th scope="col">Action</th><th scope="col">Texte à l'écran</th></tr></thead>
          <tbody>
            <?php foreach ($video['plans'] as $plan): ?>
            <tr><td><?= saas_e($plan['debut']) ?>–<?= saas_e($plan['fin']) ?></td><td><?= saas_e($plan['plan']) ?></td><td><?= saas_e($plan['action']) ?></td><td><?= saas_e($plan['texte']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($tutorial['erreurs']): ?>
    <section class="aide-article-section" aria-labelledby="erreurs-titre">
      <h2 id="erreurs-titre">Erreurs fréquentes</h2>
      <div class="aide-errors"><ul><?php foreach ($tutorial['erreurs'] as $err): ?><li><?= saas_e($err) ?></li><?php endforeach; ?></ul></div>
    </section>
    <?php endif; ?>

    <section class="aide-article-section" aria-labelledby="aide-plus-titre">
      <h2 id="aide-plus-titre">Besoin d'autre chose ?</h2>
      <p><a href="/aide/">Toutes les questions par rôle</a> · <a href="/aide/#assistant">Poser la question à l'assistant</a><?php if (aide_contact() !== ''): ?> · <a href="<?= saas_e(aide_contact_mailto()) ?>">Écrire à <?= saas_e(aide_contact()) ?></a><?php endif; ?></p>
    </section>

    <?php if ($related): ?>
    <section class="aide-article-section" aria-labelledby="related-titre">
      <h2 id="related-titre">À lire aussi</h2>
      <div class="aide-cards">
        <?php foreach ($related as $r): ?>
        <article class="aide-card">
          <p class="aide-kicker"><?= saas_e(aide_type_label($r['type'])) ?> · <?= saas_e($r['role_label']) ?></p>
          <h3><a href="<?= saas_e($r['url']) ?>"><?= saas_e($r['titre']) ?></a></h3>
          <p><?= saas_e($r['resume']) ?></p>
        </article>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>
  </div>
</article>
<?php saas_page_end(); ?>
