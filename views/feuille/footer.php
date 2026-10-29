<?php
// Modèle « Feuille » — pied de page : colophon en pied de page imprimée (grand nom, colonnes, lettre, signature).
$fc = get_content();
$flName = $siteName ?? tenant('name');
?>
<footer class="site fl-foot">
  <p class="fl-foot-mark"><?= h($flName) ?></p>
  <div class="fl-foot-grid">
    <div>
      <p class="fl-facet-title">La collection</p>
      <ul>
        <li><a href="/boutique.php">Tout voir</a></li>
        <?php foreach (category_list() as $c): ?><li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a></li><?php endforeach; ?>
      </ul>
    </div>
    <div>
      <p class="fl-facet-title">La maison</p>
      <ul>
        <li><a href="/index.php#histoire">Notre histoire</a></li>
        <li><a href="/index.php#contact">Nous trouver</a></li>
        <?php if (shop_sales_enabled()): ?><li><a href="/cart.php">Mon panier</a></li><?php endif; ?>
        <li><a href="/admin/" class="footer-admin-link">Administration</a></li>
      </ul>
    </div>
    <div>
      <p class="fl-facet-title">Coordonnées</p>
      <ul>
        <?php if (trim((string) ($fc['contact_address'] ?? '')) !== ''): ?><li><?= h($fc['contact_address']) ?></li><?php endif; ?>
        <?php if (trim((string) ($fc['contact_hours'] ?? '')) !== ''): ?><li><?= h($fc['contact_hours']) ?></li><?php endif; ?>
        <?php if (trim((string) ($fc['contact_delivery'] ?? '')) !== ''): ?><li><?= h($fc['contact_delivery']) ?></li><?php endif; ?>
      </ul>
    </div>
    <div>
      <p class="fl-facet-title">La lettre</p>
      <form method="post" action="/newsletter.php" class="fl-news">
        <label class="fl-sr" for="fl-foot-email">Votre adresse e-mail</label>
        <div class="fl-news-row">
          <input id="fl-foot-email" type="email" name="email" required placeholder="votre@email.fr" autocomplete="email">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <button type="submit">Recevoir</button>
        </div>
      </form>
      <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
    </div>
  </div>
  <div class="fl-foot-bottom">
    <p class="fl-colophon">Édité par <?= h($flName) ?> — <?= date('Y') ?>. Pièces uniques, photographiées et décrites une à une.</p>
    <span><?= share_button_html(shop_abs_url('/'), (string) $flName, (string) ($siteTagline ?? ''), '', 'share-link') ?></span>
  </div>
</footer>
