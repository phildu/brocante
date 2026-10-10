<?php
// Modèle « Librairie » — pied de page en quatre colonnes : la maison, les rayons, les infos pratiques, la lettre d'information.
$fc = get_content();
?>
<footer class="site lb-foot">
  <div class="wrap lb-foot-grid">
    <div>
      <p class="lb-foot-mark"><?= h($siteName ?? tenant('name')) ?></p>
      <p><?= h($siteTagline ?? '') ?></p>
      <p><a href="/index.php#histoire">Le mot du libraire</a></p>
    </div>
    <div>
      <h4>Les rayons</h4>
      <ul>
        <li><a href="/boutique.php">Tout le catalogue</a></li>
        <?php foreach (category_list() as $c): ?><li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a></li><?php endforeach; ?>
      </ul>
    </div>
    <div>
      <h4>Infos pratiques</h4>
      <ul>
        <?php if (trim((string) ($fc['contact_address'] ?? '')) !== ''): ?><li><?= h($fc['contact_address']) ?></li><?php endif; ?>
        <?php if (trim((string) ($fc['contact_hours'] ?? '')) !== ''): ?><li><?= h($fc['contact_hours']) ?></li><?php endif; ?>
        <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><li><?= h($fc['contact_delivery']) ?></li><?php endif; ?>
        <li><a href="/cart.php">Mon panier</a></li>
      </ul>
    </div>
    <div>
      <h4>La lettre du libraire</h4>
      <form method="post" action="/newsletter.php" class="lb-news">
        <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
        <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
        <button type="submit">S'abonner</button>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
    </div>
  </div>
  <div class="wrap footer-bottom">
    <span>© <?= date('Y') ?> <?= h($siteName ?? tenant('name')) ?></span>
    <a href="/admin/" class="footer-admin-link">Administration</a>
  </div>
</footer>
