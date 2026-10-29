<?php
// Bouton « Retirer sur le stand » sur la fiche pièce (module actif, Stripe configuré, pièce à prix fixe et libre).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/rt.php';
if (!addon_is_entitled(RT_MODULE) || empty($product) || !in_stock($product) || !stripe_configured()) return;
$s = rt_settings();
if ($s['enabled'] !== '1' || product_free_qty($product) < 1 || !($cents = rt_price($product))) return;
$h = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$terms = strtr($s['terms'], ['{piece}' => $product['name'], '{prix}' => rt_eur($cents), '{date}' => date('d/m/Y', time() + (int) $s['days'] * 86400)]);
?>
<div class="rt-box" style="margin-top:14px;">
  <details><summary style="cursor:pointer;">Réserver et retirer sur le stand (<?= (int) $s['days'] ?> jours)</summary>
    <form class="rt-form" style="display:grid;gap:6px;margin-top:8px;max-width:440px;" autocomplete="off">
      <input type="hidden" name="ref" value="<?= $h($product['ref']) ?>">
      <input type="text" name="name" placeholder="Votre nom" required maxlength="80">
      <input type="email" name="email" placeholder="Votre e-mail" required maxlength="160">
      <input type="text" name="phone" placeholder="Téléphone (facultatif)" maxlength="30">
      <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
      <p style="margin:0;font-size:.82rem;color:var(--ink-soft);"><?= $h($terms) ?><?= $s['place'] !== '' ? ' Retrait : ' . $h($s['place']) : '' ?></p>
      <label style="font-size:.88rem;"><input type="checkbox" name="accept" value="1" required> J'ai lu et j'accepte ces conditions</label>
      <button class="btn btn-primary" type="submit">Bloquer <?= $h(rt_eur($cents)) ?> sur ma carte et réserver</button>
      <p class="rt-msg" role="status" style="margin:0;"></p>
    </form>
  </details>
</div>
<script>
(function(){var f=document.querySelector('.rt-form');if(!f)return;f.addEventListener('submit',function(e){e.preventDefault();var m=f.querySelector('.rt-msg'),d={};
Array.prototype.forEach.call(f.elements,function(i){if(i.name)d[i.name]=i.type==='checkbox'?i.checked:i.value});
m.textContent='Un instant…';
fetch(<?= json_encode(shop_abs_url('/api/retrait/creer')) ?>,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(d)})
.then(function(r){return r.json()}).then(function(r){if(r.url){location.href=r.url;return}m.textContent=r.error||'Erreur.'}).catch(function(){m.textContent='Envoi impossible, réessayez.'})});})();
</script>
