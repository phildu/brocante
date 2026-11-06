<?php
// Centre d'aide public : recherche (?q=), filtres par rôle, FAQ, tutoriels, glossaire et assistant documentaire.
require_once __DIR__ . '/_base.php';
require_once __DIR__ . '/../includes/vocabulary.php';

$roles = aide_roles();
$roleFilter = (string) ($_GET['role'] ?? '');
if (!isset($roles[$roleFilter])) $roleFilter = '';

$q = trim((string) ($_GET['q'] ?? ''));
$open = trim((string) ($_GET['open'] ?? ''));
$question = trim((string) ($_GET['question'] ?? ''));
$answer = $question !== '' ? aide_answer($question) : null;
$results = $q !== '' ? aide_search($q, $roleFilter !== '' ? $roleFilter : null, 20) : [];
$faq = aide_faq();
$tutoriels = aide_tutoriels();
$glossaire = aide_glossaire();
$contact = aide_contact();
$mailto = aide_contact_mailto();

$roleTitles = [
    'vendeur' => 'Vendeur',
    'organisateur' => "Organisateur de galerie ou de vide-grenier",
    'visiteur' => 'Visiteur',
    'commune' => 'Commune',
    'tous' => 'Questions générales',
];
$roleIntros = [
    'vendeur' => "Boutique, fiches, photos, paiement, livraison : les gestes du quotidien.",
    'organisateur' => "Plan, emplacements, exposants, registre : préparer et tenir un événement.",
    'visiteur' => "Réserver un emplacement, proposer une pièce, acheter, suivre une commande.",
    'commune' => "Autorisation, registre, subvention, données : ce que la plateforme fait et ne fait pas.",
    'tous' => "Données, accessibilité, contact et fonctionnement général du centre d'aide.",
];
$roleOrder = ['vendeur', 'organisateur', 'visiteur', 'commune', 'tous'];
$visibleTutoriels = $roleFilter === '' ? $tutoriels : array_values(array_filter($tutoriels, static fn (array $t): bool => $t['role'] === $roleFilter));

$renderAnswer = static function (array $a): void {
    if ($a['ok']) {
        $article = $a['article'];
        $tuto = $a['tutoriel'] ?? null;
        ?><div class="aide-msg aide-msg-bot">
            <p class="aide-msg-who">Assistant</p>
            <p><strong><?= saas_e($article['titre']) ?></strong></p>
            <p><?= saas_e($article['resume']) ?></p>
            <p><a href="<?= saas_e($article['url']) ?>">Ouvrir la fiche</a><?php if ($tuto && $tuto['url'] !== $article['url']): ?> · <a href="<?= saas_e($tuto['url']) ?>">Voir le tutoriel</a><?php endif; ?></p>
        </div><?php
    } else {
        ?><div class="aide-msg aide-msg-bot">
            <p class="aide-msg-who">Assistant</p>
            <p><?= saas_e($a['message']) ?></p>
            <?php if ($a['contact'] !== '' && $a['mailto'] !== ''): ?><p><a class="aide-btn" href="<?= saas_e($a['mailto']) ?>">Écrire à <?= saas_e($a['contact']) ?></a></p><?php endif; ?>
            <p class="aide-hint">Le formulaire de contact d'une boutique se trouve sur la fiche d'une pièce, quand <?= htmlspecialchars(ext_t('{le ext}'), ENT_QUOTES, 'UTF-8') ?> Messagerie est <?= htmlspecialchars(ext_t('{?activé|activée}'), ENT_QUOTES, 'UTF-8') ?>.</p>
        </div><?php
    }
};

saas_page_start('Aide — ' . saas_config()['name'], "Centre d'aide Brocs : questions par rôle, tutoriels pas à pas, recherche sans accent et assistant documentaire.");
?>
<link rel="stylesheet" href="/aide/assets/aide.css">
<section class="aide-hero">
  <div class="wrap">
    <p class="eyebrow">Centre d'aide</p>
    <h1>Une question sur Brocs ?</h1>
    <p class="lede">Trouvez la réponse par rôle, suivez un tutoriel pas à pas ou posez la question à l'assistant. Tout est écrit à partir des écrans réels du portail et des boutiques.</p>
    <form class="aide-search" method="get" action="/aide/" role="search" aria-describedby="aide-search-help">
      <label for="aide-q">Rechercher dans l'aide</label>
      <div class="aide-search-row">
        <input type="search" id="aide-q" name="q" value="<?= saas_e($q) ?>" placeholder="Ex. plan, arrhes, studio, encaisser" autocomplete="off" spellcheck="false">
        <select name="role" aria-label="Limiter la recherche à un rôle">
          <option value="">Tous les rôles</option>
          <?php foreach ($roleOrder as $r): ?><option value="<?= saas_e($r) ?>"<?= $roleFilter === $r ? ' selected' : '' ?>><?= saas_e($roles[$r]) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="aide-btn">Chercher</button>
      </div>
      <p class="aide-hint" id="aide-search-help">La recherche ignore les accents et les majuscules. Sans JavaScript, validez avec Entrée ; avec JavaScript, les résultats s'affichent pendant la saisie.</p>
    </form>
  </div>
</section>

<?php if ($q !== ''): ?>
<section class="aide-section wrap" id="resultats" aria-labelledby="resultats-titre">
  <nav class="aide-breadcrumb" aria-label="Fil d'Ariane"><a href="/aide/">Aide</a> <span aria-hidden="true">›</span> Recherche</nav>
  <h2 id="resultats-titre">Résultats pour « <?= saas_e($q) ?> »</h2>
  <p role="status" class="aide-count"><?= count($results) ?> résultat<?= count($results) > 1 ? 's' : '' ?><?= $roleFilter !== '' ? ' pour le rôle ' . saas_e($roles[$roleFilter]) : '' ?>.</p>
  <?php if (!$results): ?>
    <div class="aide-empty">
      <p>Aucune fiche ne correspond à cette recherche.</p>
      <p>Essayez un mot plus court (« plan », « paiement », « registre ») ou posez la question à l'assistant.</p>
      <p><a href="#assistant">Poser la question à l'assistant</a><?php if ($contact !== ''): ?> · <a href="<?= saas_e($mailto) ?>">Écrire à <?= saas_e($contact) ?></a><?php endif; ?></p>
    </div>
  <?php else: ?>
    <div class="aide-results">
      <?php foreach ($results as $result): $article = $result['article']; ?>
      <article class="aide-result">
        <p class="aide-kicker"><?= saas_e(aide_type_label($article['type'])) ?> · <?= saas_e($article['role_label']) ?></p>
        <h3><a href="<?= saas_e($article['url']) ?>"><?= saas_e($article['titre']) ?></a></h3>
        <p><?= saas_e($article['resume']) ?></p>
        <p class="aide-more"><a href="<?= saas_e($article['url']) ?>">Lire la fiche</a></p>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <p class="aide-back"><a href="/aide/">Revenir à toutes les questions</a></p>
</section>
<?php else: ?>
<div id="aide-instant" class="aide-section wrap" aria-live="polite" hidden></div>
<div id="aide-browse" class="aide-browse">
  <section class="aide-section wrap" aria-labelledby="roles-titre">
    <h2 id="roles-titre">Par rôle</h2>
    <nav class="aide-roles" aria-label="Filtrer les questions par rôle">
      <a href="/aide/" data-aide-role-link="all"<?= $roleFilter === '' ? ' aria-current="page"' : '' ?>>Tout</a>
      <?php foreach ($roleOrder as $r): ?><a href="/aide/?role=<?= saas_e($r) ?>" data-aide-role-link="<?= saas_e($r) ?>"<?= $roleFilter === $r ? ' aria-current="page"' : '' ?>><?= saas_e($roles[$r]) ?></a><?php endforeach; ?>
    </nav>
    <p class="aide-hint">Choisissez un rôle pour ne voir que ses questions. Le filtre fonctionne aussi sans JavaScript.</p>
  </section>

  <?php if ($visibleTutoriels): ?>
  <section class="aide-section wrap" data-aide-role="all" data-aide-tutoriels aria-labelledby="tutoriels-titre">
    <div class="aide-sec-head"><p class="aide-kicker">Pas à pas</p><h2 id="tutoriels-titre">Tutoriels</h2><p class="aide-hint">Chaque tutoriel indique les prérequis, les étapes, ce qui doit apparaître à l'écran et les erreurs fréquentes.</p></div>
    <div class="aide-cards">
      <?php foreach ($visibleTutoriels as $t): ?>
      <article class="aide-card" data-aide-role="<?= saas_e($t['role']) ?>">
        <p class="aide-kicker">Tutoriel · <?= saas_e($t['role_label']) ?><?= $t['video']['duree'] !== '' ? ' · ' . saas_e($t['video']['duree']) : '' ?></p>
        <h3><a href="<?= saas_e($t['url']) ?>"><?= saas_e($t['titre']) ?></a></h3>
        <p><?= saas_e($t['resume']) ?></p>
        <p class="aide-more"><a href="<?= saas_e($t['url']) ?>">Lire le tutoriel</a></p>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <?php foreach ($roleOrder as $r): if ($roleFilter !== '' && $r !== $roleFilter) continue; $items = array_values(array_filter($faq, static fn (array $item): bool => $item['role'] === $r)); if (!$items) continue; ?>
  <section class="aide-section wrap" data-aide-role="<?= saas_e($r) ?>" id="role-<?= saas_e($r) ?>" aria-labelledby="role-<?= saas_e($r) ?>-titre">
    <div class="aide-sec-head">
      <p class="aide-kicker"><?= saas_e($roles[$r]) ?></p>
      <h2 id="role-<?= saas_e($r) ?>-titre"><?= saas_e($roleTitles[$r]) ?></h2>
      <p class="aide-hint"><?= saas_e($roleIntros[$r]) ?></p>
    </div>
    <div class="aide-faqs">
      <?php foreach ($items as $item): ?>
      <details id="<?= saas_e($item['slug']) ?>" class="aide-faq"<?= $open === $item['slug'] ? ' open' : '' ?>>
        <summary><span><?= saas_e($item['titre']) ?></span></summary>
        <div class="aide-faq-body">
          <p><?= saas_e($item['resume']) ?></p>
          <?php if ($item['etapes']): ?>
          <ol class="aide-steps">
            <?php foreach ($item['etapes'] as $step): ?><li><?= saas_e(aide_step_text($step)) ?></li><?php endforeach; ?>
          </ol>
          <?php endif; ?>
          <?php if ($item['erreurs']): ?>
          <div class="aide-errors"><h3>Erreurs fréquentes</h3><ul><?php foreach ($item['erreurs'] as $err): ?><li><?= saas_e($err) ?></li><?php endforeach; ?></ul></div>
          <?php endif; ?>
          <?php if ($item['liens']): ?>
          <p class="aide-more"><?php foreach ($item['liens'] as $i => $lien): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= saas_e($lien['url']) ?>"><?= saas_e($lien['libelle']) ?></a><?php endforeach; ?></p>
          <?php endif; ?>
          <?php $tuto = $item['tutoriel'] !== '' ? aide_find($item['tutoriel']) : null; ?>
          <?php if ($tuto && $tuto['type'] === 'tutoriel'): ?><p class="aide-more"><a href="<?= saas_e($tuto['url']) ?>">Voir le tutoriel : <?= saas_e($tuto['titre']) ?></a></p><?php endif; ?>
        </div>
      </details>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if ($glossaire && ($roleFilter === '' || $roleFilter === 'tous')): ?>
  <section class="aide-section wrap" data-aide-role="all" data-aide-glossaire aria-labelledby="glossaire-titre">
    <div class="aide-sec-head"><p class="aide-kicker">Vocabulaire</p><h2 id="glossaire-titre">Glossaire</h2><p class="aide-hint">Les mots du métier, avec une définition courte et un exemple quand c'est utile.</p></div>
    <dl class="aide-glossary">
      <?php foreach ($glossaire as $g): ?>
      <div id="glossaire-<?= saas_e($g['slug']) ?>"><dt><?= saas_e($g['titre']) ?></dt><dd><?= saas_e($g['resume']) ?><?php if ($g['url_cible'] !== ''): ?> <a href="<?= saas_e($g['url_cible']) ?>">Voir la page</a><?php endif; ?></dd></div>
      <?php endforeach; ?>
    </dl>
  </section>
  <?php endif; ?>
</div>
<?php endif; ?>

<section class="aide-section wrap aide-chat" id="assistant" aria-labelledby="assistant-titre">
  <div class="aide-sec-head">
    <p class="aide-kicker">Sans réseau</p>
    <h2 id="assistant-titre">Assistant</h2>
    <p class="aide-hint">L'assistant répond uniquement avec les fiches de ce centre d'aide. Il ne conserve aucune conversation et n'envoie vos mots à aucun service externe.</p>
  </div>
  <div id="aide-chat-log" class="aide-chat-log" role="log" aria-live="polite" aria-atomic="false">
    <?php if ($answer): ?>
    <div class="aide-msg aide-msg-user"><p class="aide-msg-who">Vous</p><p><?= saas_e($question) ?></p></div>
    <?php $renderAnswer($answer); ?>
    <?php endif; ?>
  </div>
  <form id="aide-chat-form" class="aide-chat-form" method="get" action="/aide/#assistant">
    <label for="aide-chat-question">Votre question</label>
    <div class="aide-chat-row">
      <input id="aide-chat-question" name="question" type="text" autocomplete="off" placeholder="Ex. Comment créer un plan ?" value="">
      <button type="submit" class="aide-btn">Envoyer</button>
    </div>
    <p class="aide-hint">Sans JavaScript, la question recharge la page et la même base répond. Avec JavaScript, la réponse s'affiche immédiatement, sans requête.</p>
  </form>
  <noscript><p class="aide-noscript">Le chatbot interactif nécessite JavaScript. La recherche et les fiches fonctionnent sans JavaScript.</p></noscript>
</section>

<div id="aide-chat-data" data-contact="<?= saas_e($contact) ?>" data-mailto="<?= saas_e($mailto) ?>" data-threshold="<?= (int) AIDE_CHAT_MIN_SCORE ?>" data-coverage="<?= (float) AIDE_CHAT_MIN_COVERAGE ?>" hidden></div>
<script type="application/json" id="aide-corpus"><?= aide_corpus_json() ?></script>
<script src="/aide/assets/aide.js?v=<?= (int) @filemtime(__DIR__ . '/assets/aide.js') ?>" defer></script>
<script src="/aide/assets/aide-chat.js?v=<?= (int) @filemtime(__DIR__ . '/assets/aide-chat.js') ?>" defer></script>
<?php saas_page_end(); ?>
