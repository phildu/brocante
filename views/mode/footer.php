<?php
// Modèle « Mode » — pied de page : le nom en grand, trois colonnes sobres, lettre d'information soulignée.
$fc = get_content();
?>
<footer class="site md-foot">
  <div class="wrap">
    <p class="md-foot-brand"><?= h($siteName ?? tenant('name')) ?></p>
    <div class="md-foot-grid">
      <div>
        <h4>Boutique</h4>
        <ul>
          <li><a href="/boutique.php">Tout voir</a></li>
          <?php foreach (category_list() as $c): ?><li><a href="/boutique.php?cat=<?= h($c['key']) ?>"><?= h($c['label']) ?></a></li><?php endforeach; ?>
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
        <h4>Restez au courant</h4>
        <form method="post" action="/newsletter.php" class="md-news">
          <input type="email" name="email" required placeholder="Votre e-mail" aria-label="Adresse e-mail">
          <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
          <button type="submit">OK</button>
        </form>
        <?php if (!empty($_GET['inscrit'])): ?><p class="form-note">Inscription enregistrée — merci !</p><?php endif; ?>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© <?= date('Y') ?> <?= h($siteName ?? tenant('name')) ?></span>
      <a href="/admin/" class="footer-admin-link">Administration</a>
    </div>
  </div>
</footer>
