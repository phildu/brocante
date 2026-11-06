<?php
// Carte « installer l'application » : code QR, lien, bouton d'ouverture et copie du lien.
// Utilisée par l'admin de la boutique (Studio, Vente) et par le portail de galerie (Admin, Visiteurs).
// Le QR est dessiné dans le navigateur (assets/vendor/qrcode-generator.js, chargé une seule fois).

/** Une adresse locale (localhost, *.test) n'est pas joignable depuis un téléphone. */
function app_url_reachable(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host !== '' && !($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.test'));
}

/**
 * Affiche la carte d'une application.
 * $o : title, desc, url (absolue), icon (adresse de l'icône, facultatif), note (facultatif), disabled (facultatif : raison, la carte est alors grisée sans QR).
 */
function app_install_card(array $o): void
{
    static $first = true;
    $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $url = (string) ($o['url'] ?? '');
    $disabled = (string) ($o['disabled'] ?? '');
    if ($first) {
        $first = false;
        ?><style>
.appcard{border:1px solid var(--line,#ddd);background:var(--surface,#fff);padding:14px;display:grid;grid-template-columns:132px 1fr;gap:14px;align-items:start;margin:0 0 12px;border-radius:10px}
.appcard .appcard-qr{background:#fff;padding:6px;line-height:0;border:1px solid var(--line,#ddd);border-radius:6px}
.appcard .appcard-qr svg{width:100%;height:auto;display:block}
.appcard h2{font-size:1rem;margin:0 0 4px;display:flex;align-items:center;gap:8px}.appcard h2 img{width:36px;height:36px;border-radius:9px;border:1px solid var(--line,#ddd)}
.appcard p{margin:0 0 6px;font-size:.88rem;color:var(--ink-soft,#555)}
.appcard .appcard-url{word-break:break-all;font-size:.8rem}
.appcard .appcard-warn{color:#8a4b00;background:#fff4e0;border-radius:6px;padding:6px 8px}
.appcard .appcard-btns{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0}
.appcard.is-off{opacity:.65;grid-template-columns:1fr}
.appcard.is-off .appcard-qr{display:none}
@media(max-width:620px){.appcard{grid-template-columns:1fr}.appcard .appcard-qr{max-width:180px}}
</style>
<?php
    }
    ?><section class="appcard<?= $disabled !== '' ? ' is-off' : '' ?>" aria-label="<?= $e($o['title'] ?? '') ?>">
  <div class="appcard-qr" data-appcard-qr="<?= $e($url) ?>" role="img" aria-label="Code QR : <?= $e($o['title'] ?? '') ?>"></div>
  <div>
    <h2><?php if (($o['icon'] ?? '') !== ''): ?><img src="<?= $e($o['icon']) ?>" alt="" width="36" height="36"><?php endif; ?><?= $e($o['title'] ?? '') ?></h2>
    <p><?= $e($o['desc'] ?? '') ?></p>
    <?php if ($disabled !== ''): ?>
      <p class="appcard-warn"><?= $e($disabled) ?></p>
    <?php else: ?>
      <div class="appcard-btns">
        <a class="btn btn-primary" href="<?= $e($url) ?>" target="_blank" rel="noopener">Ouvrir</a>
        <button type="button" class="btn" data-appcard-copy="<?= $e($url) ?>">Copier le lien</button>
      </div>
      <p class="appcard-url"><a href="<?= $e($url) ?>" target="_blank" rel="noopener"><?= $e($url) ?></a></p>
      <?php if (!app_url_reachable($url)): ?><p class="appcard-warn">Adresse locale : un téléphone ne peut pas l'ouvrir. En ligne, le code fonctionne.</p><?php endif; ?>
      <?php if (($o['note'] ?? '') !== ''): ?><p><?= $e($o['note']) ?></p><?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php
}

/** Script du QR et de la copie : à appeler une fois, après la dernière carte. */
function app_install_cards_script(): void
{
    ?><script src="/assets/vendor/qrcode-generator.js"></script>
<script>
(function () {
  document.querySelectorAll('[data-appcard-qr]').forEach(function (box) {
    var u = box.getAttribute('data-appcard-qr');
    if (!u || typeof qrcode !== 'function') return;
    var q = qrcode(0, 'M'); q.addData(u); q.make();
    box.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
  });
  document.querySelectorAll('[data-appcard-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var u = b.getAttribute('data-appcard-copy'), old = b.textContent;
      var done = function () { b.textContent = 'Lien copié'; setTimeout(function () { b.textContent = old; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(u).then(done, function () { window.prompt('Copiez le lien :', u); });
      else window.prompt('Copiez le lien :', u);
    });
  });
})();
</script>
<?php
}
