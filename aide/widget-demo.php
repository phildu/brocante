<?php
// Démonstration du panneau d'aide contextuel. Page noindex, non liée depuis la navigation.
require_once __DIR__ . '/_base.php';

header('X-Robots-Tag: noindex, nofollow');

$exemples = [
    'plan-d-implantation' => 'Qu\'est-ce qu\'un plan d\'implantation ?',
    'vendeur-encaisser' => 'Comment encaisser les paiements de ma boutique ?',
    'arrhes' => 'Qu\'est-ce que des arrhes ?',
    'organisateur-export' => 'Comment exporter la liste des exposants ou le plan ?',
];
$tutoriels = aide_tutoriels();

saas_page_start('Démo du panneau d\'aide — Aide', "Démonstration locale du panneau d'aide contextuel BrocsAide.open().");
?>
<link rel="stylesheet" href="/aide/assets/aide.css">
<section class="aide-section wrap">
  <nav class="aide-breadcrumb" aria-label="Fil d'Ariane"><a href="/aide/">Aide</a> <span aria-hidden="true">›</span> Démonstration</nav>
  <h1>Panneau d'aide contextuel</h1>
  <p class="lede">Cette page montre le panneau latéral accessible fourni par <code>aide-widget.js</code>. Il s'ouvre avec un bouton ou avec <code>BrocsAide.open('slug')</code>, se ferme avec Échap ou le bouton de fermeture, et rend le focus à l'élément qui l'a ouvert.</p>
  <p class="aide-hint">Page de démonstration en <code>noindex</code>. Aucun gabarit du portail ou de l'administration n'est modifié : l'intégration se fait en ajoutant les deux lignes ci-dessous dans le gabarit concerné.</p>

  <h2>Essayer</h2>
  <div class="aide-cards">
    <?php foreach ($exemples as $slug => $titre): ?>
    <article class="aide-card">
      <p class="aide-kicker">Fiche existante</p>
      <h3><?= saas_e($titre) ?></h3>
      <p><button type="button" class="aide-btn" data-aide-open="<?= saas_e($slug) ?>">Ouvrir l'aide contextuelle</button></p>
    </article>
    <?php endforeach; ?>
    <?php foreach ($tutoriels as $t): ?>
    <article class="aide-card">
      <p class="aide-kicker">Tutoriel</p>
      <h3><?= saas_e($t['titre']) ?></h3>
      <p><button type="button" class="aide-btn" data-aide-open="<?= saas_e($t['slug']) ?>">Ouvrir l'aide contextuelle</button></p>
    </article>
    <?php endforeach; ?>
  </div>

  <h2>Intégration en deux lignes</h2>
  <p>Dans le gabarit de <code>/portail/</code> ou de <code>/admin/</code>, avant la fermeture de <code>&lt;/body&gt;</code> :</p>
  <pre class="aide-code"><code>&lt;link rel="stylesheet" href="/aide/assets/aide.css"&gt;
&lt;script src="/aide/assets/aide-widget.js" defer&gt;&lt;/script&gt;</code></pre>
  <p>Puis, sur le bouton ou le lien d'aide déjà présent dans l'interface :</p>
  <pre class="aide-code"><code>&lt;button type="button" data-aide-open="organisateur-plan"&gt;Aide&lt;/button&gt;</code></pre>
  <p>Ou depuis un script existant : <code>BrocsAide.open('organisateur-plan')</code>.</p>
  <p class="aide-hint">Le panneau charge <a href="/aide/aide-index.json.php"><code>/aide/aide-index.json.php</code></a> une seule fois, sans envoyer de données personnelles. Il ne conserve aucune conversation.</p>
</section>
<script src="/aide/assets/aide-widget.js?v=<?= (int) @filemtime(__DIR__ . '/assets/aide-widget.js') ?>" defer></script>
<?php saas_page_end(); ?>
