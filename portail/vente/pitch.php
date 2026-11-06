<?php
require __DIR__ . '/_bootstrap.php';
fv_require_auth();

$ctx = fv_current();
$member = $ctx['member'];
$brand = fv_setting('marque_nom', 'Brocs');
$city = $member && $member['city'] !== '' ? (string) $member['city'] : 'votre ville';
$offer = fv_setting('offre_pitch', '');
$prices = fv_setting('offre_prix', '');
$tel = fv_setting('contact_tel', '');
$mail = fv_setting('contact_email', '');
$site = rtrim(fv_setting('site_url', ''), '/');
$remise = fv_setting_int('remise_defaut', 20);
$referral = $member ? fv_referral_code((int) $member['id']) : null;
$code = $referral ? (string) $referral['code'] : 'votre code';

$slides = [
    [
        'k' => $brand . ' · commerces de ' . $city,
        'title' => 'Votre commerce, <em>aussi en ligne</em>.',
        'body' => '<p>Une boutique à votre nom, prête en 48 h, sans commission sur vos ventes.</p>',
        'notes' => 'Se présenter en une phrase, remercier du temps accordé, annoncer le déroulé : constat, solution, démonstration, prochaine étape. 30 secondes maximum.',
    ],
    [
        'k' => 'Le constat',
        'title' => 'Vos clients vous cherchent… et ne vous trouvent pas.',
        'body' => '<div class="cols"><div class="mini"><b>Invisible en ligne</b><span>Pas de site, ou un site qui n’a pas bougé depuis des années.</span></div><div class="mini"><b>Trop de commission</b><span>Les places de marché prennent une part sur chaque vente.</span></div><div class="mini"><b>Instagram ne suffit plus</b><span>Un message perdu, pas de catalogue, pas de panier.</span></div></div>',
        'notes' => 'Faire parler : « Comment vos clients vous trouvent-ils aujourd’hui ? » Noter la réponse, elle servira à la conclusion.',
    ],
    [
        'k' => 'La réponse',
        'title' => 'Une boutique qui vous ressemble, <em>sans intermédiaire</em>.',
        'body' => '<ul><li>Votre vitrine, vos articles, vos photos, vos horaires.</li><li>Commande en ligne et retrait en boutique.</li><li>Zéro commission sur vos ventes.</li></ul>',
        'notes' => 'Insister : la boutique est au nom du commerce, pas dans un annuaire. Le commerçant garde ses clients et ses prix.',
    ],
    [
        'k' => 'Comment ça marche',
        'title' => 'Trois étapes, <em>48 heures</em>.',
        'body' => '<div class="cols"><div class="mini"><b>1 · On collecte</b><span>Photos, articles, horaires. Dix minutes avec vous.</span></div><div class="mini"><b>2 · On met en ligne</b><span>La boutique est montée, vous validez.</span></div><div class="mini"><b>3 · Vous vendez</b><span>Commande, retrait, suivi. On reste joignable.</span></div></div>',
        'notes' => 'Montrer que le commerçant n’a rien à installer ni à apprendre : tout se fait par formulaire.',
    ],
    [
        'k' => 'Ce que vous gardez',
        'title' => 'Votre clientèle, vos prix, <em>votre voix</em>.',
        'body' => '<ul><li>Vos coordonnées et votre histoire, pas un catalogue anonyme.</li><li>Vos prix, sans majoration imposée.</li><li>Vos horaires et votre retrait en boutique.</li><li>La possibilité de tout modifier vous-même.</li></ul>',
        'notes' => 'Réponse à l’objection « je vais perdre la relation client ». La boutique est un prolongement, pas un remplacement.',
    ],
    [
        'k' => 'Démonstration',
        'title' => 'Quinze minutes, <em>en vrai</em>.',
        'body' => '<div class="cols"><div class="mini"><b>La page d’accueil</b><span>Ce que voit un client en arrivant.</span></div><div class="mini"><b>Une fiche article</b><span>Photos, prix, stock, retrait.</span></div><div class="mini"><b>L’administration</b><span>Ajouter un article en deux minutes.</span></div></div>',
        'notes' => 'Ouvrir la boutique de démonstration sur téléphone. Faire faire un ajout au commerçant, c’est ce qui déclenche l’adhésion.',
    ],
    [
        'k' => 'L’offre',
        'title' => 'Simple, lisible, <em>sans surprise</em>.',
        'body' => '<p>' . e($offer) . '</p><p class="big" style="font-size:clamp(1.2rem,2.4vw,1.8rem);margin-top:10px;">' . e($prices) . '</p>',
        'notes' => 'Ne pas noyer dans les options. Une phrase sur le prix, une phrase sur l’absence de commission, puis silence.',
    ],
    [
        'k' => 'Pourquoi maintenant',
        'title' => 'Le commerce local a besoin d’un <em>canal à lui</em>.',
        'body' => '<ul><li>Les clients comparent et réservent en ligne avant de se déplacer.</li><li>Les commissions rognent les marges des petits commerces.</li><li>Une boutique propre se retrouve dans les recherches et les partages.</li></ul>',
        'notes' => 'Relier au contexte local : rue, quartier, saison, événements. Éviter les statistiques non vérifiées.',
    ],
    [
        'k' => 'Pour aller plus loin',
        'title' => 'Devenez <em>commerce référent</em>.',
        'body' => '<p>Recommandez ' . e($brand) . ' aux commerces de votre réseau : chaque boutique créée avec votre code vous rapporte une récompense, et ' . $remise . ' % de remise à vos propres clients.</p><p class="big" style="font-size:clamp(1.2rem,2.4vw,1.8rem);">' . e($code) . '</p>',
        'notes' => 'Proposer le rôle seulement si la personne est influente localement. Sinon, garder pour un second rendez-vous.',
    ],
    [
        'k' => 'Prochaine étape',
        'title' => 'On ouvre votre boutique <em>cette semaine</em> ?',
        'body' => '<p>Choisissez un créneau de quinze minutes : sur place, en visio ou au téléphone. Je repars avec vos premières photos, et vous validez la mise en ligne.</p><p style="margin-top:12px;">' . e($member ? $member['name'] : $brand) . ' · ' . e($tel) . ' · ' . e($mail) . '</p><p class="fv-mono" style="font-size:.8rem;">' . e($site) . '</p>',
        'notes' => 'Toujours terminer par une date. Si hésitation : proposer jeudi, pas « quand vous voulez ». Noter le rendez-vous dans la fiche prospect.',
    ],
];

fv_layout_start('Pitch & slides', [
    'eyebrow' => 'Argumentaire',
    'desc' => 'Un support de dix diapositives, des versions de 30 secondes à 10 minutes, et les réponses aux objections. Flèches du clavier pour naviguer.',
    'wide' => true,
    'actions' => '<button class="fv-btn fv-btn-sm" type="button" data-print="pitch">' . fv_icon('print', 14) . ' Imprimer les slides</button>'
        . '<a class="fv-btn fv-btn-sm" href="kits.php">' . fv_icon('image', 14) . ' Kits</a>',
]);
?>

<div class="deck" data-deck>
  <div class="deck-stage">
    <?php foreach ($slides as $i => $slide): ?>
      <section class="slide<?= $i === 0 ? ' is-active' : '' ?>" data-n="<?= $i + 1 ?> / <?= count($slides) ?>" data-notes="<?= e($slide['notes']) ?>" aria-hidden="<?= $i === 0 ? 'false' : 'true' ?>">
        <span class="k"><?= e($slide['k']) ?></span>
        <h2><?= $slide['title'] ?></h2>
        <?= $slide['body'] ?>
      </section>
    <?php endforeach; ?>
  </div>
  <div class="deck-bar">
    <button type="button" data-deck-prev aria-label="Diapositive précédente">←</button>
    <button type="button" data-deck-next aria-label="Diapositive suivante">→</button>
    <span class="fv-mono" style="font-size:.72rem;opacity:.7;">Flèches ← → au clavier</span>
    <div class="dots" data-deck-dots aria-label="Diapositives"></div>
  </div>
  <div class="deck-notes" data-deck-notes></div>
</div>

<div class="fv-grid fv-grid-3 fv-pitch-extra" style="margin-top:16px;">
  <section class="fv-card">
    <div class="fv-card-head"><div><h2>Pitch 30 secondes</h2><p>Dans l’ascenseur, au comptoir.</p></div></div>
    <div class="fv-card-body fv-script">
      <p>« Bonjour, je suis <?= e($member ? $member['name'] : $brand) ?>. J’aide les commerces de <?= e($city) ?> à avoir une boutique en ligne à leur nom, sans commission. C’est prêt en 48 h, et je peux vous montrer en quinze minutes. Vous avez un créneau cette semaine ? »</p>
    </div>
  </section>
  <section class="fv-card">
    <div class="fv-card-head"><div><h2>Pitch 2 minutes</h2><p>Le rendez-vous découverte.</p></div></div>
    <div class="fv-card-body fv-script">
      <p>« Vos clients vous cherchent en ligne. Aujourd’hui, soit ils ne vous trouvent pas, soit ils passent par une plateforme qui prend une commission. <?= e($brand) ?> vous donne votre propre boutique : vos articles, vos photos, votre retrait en boutique. Vous gardez vos prix et votre clientèle. On la met en ligne en 48 h, et vous pouvez tout modifier vous-même. <?= e($prices) ?> Je vous montre ? »</p>
    </div>
  </section>
  <section class="fv-card">
    <div class="fv-card-head"><div><h2>Pitch 10 minutes</h2><p>Le déroulé complet.</p></div></div>
    <div class="fv-card-body fv-script">
      <ul style="padding-left:18px;display:grid;gap:6px;font-size:.85rem;">
        <li>1 min · qui vous êtes, pourquoi vous êtes là.</li>
        <li>2 min · comment le commerce trouve ses clients aujourd’hui.</li>
        <li>3 min · la boutique en démonstration sur téléphone.</li>
        <li>2 min · l’offre, le prix, l’absence de commission.</li>
        <li>1 min · les questions du commerçant.</li>
        <li>1 min · une date, un créneau, les premières photos.</li>
      </ul>
    </div>
  </section>
</div>

<div class="fv-grid fv-grid-2 fv-pitch-extra" style="margin-top:16px;">
  <section class="fv-card">
    <div class="fv-card-head"><div><h2>Objections</h2><p>Répondre court, puis poser une question.</p></div></div>
    <div class="fv-card-body fv-script">
      <details><summary>« C’est trop cher. »</summary><p>L’offre de départ est gratuite, et il n’y a pas de commission sur les ventes. Vous payez seulement si la boutique vous apporte des clients. Quel budget aviez-vous en tête ?</p></details>
      <details><summary>« Je n’ai pas le temps. »</summary><p>Dix minutes avec vous pour les photos et les articles, 48 h pour la mise en ligne. Ensuite, ajouter un article prend deux minutes. On peut commencer par dix articles.</p></details>
      <details><summary>« J’ai déjà Instagram. »</summary><p>Gardez Instagram : la boutique devient votre catalogue et votre panier. Un lien dans la bio, et les commandes sont centralisées, avec le retrait en boutique.</p></details>
      <details><summary>« Je ne suis pas à l’aise avec l’informatique. »</summary><p>Tout se fait par formulaire, sans installation. On remplit ensemble, et vous pouvez nous appeler. Si vous savez envoyer une photo par message, vous savez gérer la boutique.</p></details>
      <details><summary>« Je dois en parler. »</summary><p>Bien sûr. Je vous laisse le flyer et le lien de démonstration. On se rappelle jeudi : je réponds aux questions, et on fixe la mise en ligne si c’est validé.</p></details>
      <details><summary>« Je vends déjà sur une place de marché. »</summary><p>Gardez-la si elle vous apporte des clients. La boutique vous permet de vendre sans commission et de garder le contact direct. Les deux peuvent coexister.</p></details>
    </div>
  </section>

  <section class="fv-card">
    <div class="fv-card-head"><div><h2>Après le rendez-vous</h2></div></div>
    <div class="fv-card-body fv-script">
      <p><b>Dans l’heure</b> — consignez l’échange dans la fiche prospect (résultat, notes, prochaine action).</p>
      <p><b>Le lendemain</b> — envoyez le message de suivi depuis <a href="kits.php">les kits</a> ou <a href="mailing.php">le mailing</a>.</p>
      <p><b>À J+7</b> — relance courte si pas de réponse, puis classement sans suite après deux relances.</p>
      <div class="fv-actions" style="margin-top:6px;">
        <a class="fv-btn fv-btn-sm" href="prospects.php">Ouvrir les prospects</a>
        <a class="fv-btn fv-btn-sm" href="appels.php">Appels</a>
      </div>
    </div>
  </section>
</div>

<?php fv_layout_end(); ?>
