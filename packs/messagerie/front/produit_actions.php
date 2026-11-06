<?php
// Sous les boutons d'achat de la fiche pièce : WhatsApp et question au vendeur (si le module est actif).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ms.php';
if (!addon_is_entitled(MS_MODULE) || empty($product)) return;
$s = ms_settings();
$wa = ms_wa_number($s['whatsapp']);
$url = shop_abs_url('/produit.php?ref=' . rawurlencode($product['ref']));
$text = strtr($s['wa_template'], ['{piece}' => $product['name'], '{lien}' => $url]);
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="ms-box" style="margin-top:14px;display:grid;gap:8px;">
  <?php if ($wa !== '' && $s['show_whatsapp'] === '1'): ?>
    <a class="btn btn-ghost" href="https://wa.me/<?= $h($wa) ?>?text=<?= rawurlencode($text) ?>" target="_blank" rel="noopener">Écrire sur WhatsApp</a>
  <?php endif; ?>
  <?php if ($s['show_form'] === '1'): ?>
    <details><summary style="cursor:pointer;">Poser une question sur cette pièce</summary>
      <form class="ms-form" style="display:grid;gap:6px;margin-top:8px;max-width:420px;" autocomplete="off">
        <input type="hidden" name="ref" value="<?= $h($product['ref']) ?>">
        <input type="text" name="name" placeholder="Votre prénom" maxlength="80">
        <input type="email" name="email" placeholder="Votre e-mail" required maxlength="160">
        <textarea name="body" rows="3" placeholder="Votre question" required minlength="5" maxlength="2000"></textarea>
        <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
        <button class="btn btn-primary" type="submit">Envoyer</button>
        <p class="ms-msg" role="status" style="margin:0;"></p>
      </form>
    </details>
    <script>
    (function(){var f=document.querySelector('.ms-form');if(!f)return;f.addEventListener('submit',function(e){e.preventDefault();var m=f.querySelector('.ms-msg'),d={};
    Array.prototype.forEach.call(f.elements,function(i){if(i.name)d[i.name]=i.value});
    fetch(<?= json_encode(shop_abs_url('/api/messages/envoyer')) ?>,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(d)})
    .then(function(r){return r.json()}).then(function(r){m.textContent=r.error||r.message;if(!r.error)f.reset()}).catch(function(){m.textContent='Envoi impossible, réessayez.'})});})();
    </script>
  <?php endif; ?>
</div>
