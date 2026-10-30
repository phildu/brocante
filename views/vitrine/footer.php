<?php
// Modèle « Vitrine » — pied de page : grand nom, colonnes sobres, lettre d'information.
$fc = get_content();
?>
<footer class="site vt-foot">
  <div class="wrap">
    <p class="vt-foot-mark"><?= h($siteName ?? tenant('name')) ?></p>
    <div class="vt-foot-grid">
      <div>
        <h4>Collection</h4>
        <ul>
          <li><a href="/boutique.php">Tout voir</a></li>
          <?php foreach (category_list() as $c): ?><li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a></li><?php endforeach; ?>
        </ul>
      </div>
      <div>
        <h4>Maison</h4>
        <ul>
          <li><a href="/index.php#histoire">Notre histoire</a></li>
          <li><a href="/index.php#contact">Nous trouver</a></li>
          <?php if (shop_sales_enabled()): ?><li><a href="/cart.php">Mon panier</a></li><?php endif; ?>
          <li><a href="/admin/" class="footer-admin-link">Administration</a></li>
        </ul>
      </div>
      <div>
        <h4>Infos</h4>
        <ul>
          <?php if (trim((string) ($fc['contact_address'] ?? '')) !== ''): ?><li><?= h($fc['contact_address']) ?></li><?php endif; ?>
          <?php if (trim((string) ($fc['contact_hours'] ?? '')) !== ''): ?><li><?= h($fc['contact_hours']) ?></li><?php endif; ?>
          <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><li><?= h($fc['contact_delivery']) ?></li><?php endif; ?>
        </ul>
      </div>
      <div>
        <h4>La lettre</h4>
        <form method="post" action="/newsletter.php" class="vt-news">
          <input type="email" name="email" required placeholder="votre@email.fr" aria-label="Adresse e-mail">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <button type="submit">S'abonner</button>
        </form>
        <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
      </div>
    </div>
    <?php if (($footerNote = shop_footer_note()) !== ''): ?><p class="footer-note"><?= h($footerNote) ?></p><?php endif; ?>
    <div class="vt-foot-bottom">
      <span>© <?= date('Y') ?> <?= h($siteName ?? tenant('name')) ?></span>
      <?= share_button_html(shop_abs_url('/'), (string) ($siteName ?? tenant('name')), (string) ($siteTagline ?? ''), '', 'share-link') ?>
    </div>
  </div>
</footer>
