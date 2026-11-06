<?php
// Formulaire « Prévenez-moi » en bas de la boutique (visible seulement si le module est actif).
defined('PLUGIN_RUNTIME') || exit;
require_once dirname(__DIR__) . '/lib/ac.php';
if (!addon_is_entitled(AC_MODULE)) return;
$api = ac_url('abonner');
?>
<section class="chineur-box" style="max-width:640px;margin:32px auto;padding:20px;border:1px solid var(--line);display:grid;gap:10px;">
  <h2 style="margin:0;font-size:1.2rem;">Ne ratez plus la pièce rare</h2>
  <p style="margin:0;color:var(--ink-soft);">Dites-nous ce que vous cherchez : nous vous écrivons dès qu'une pièce correspondante arrive.</p>
  <form id="chineur-form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;" autocomplete="off">
    <label>Je cherche<br><input type="text" name="keywords" required minlength="3" maxlength="120" placeholder="ex. céramique vintage"></label>
    <label>Prix max. (€)<br><input type="text" name="max_price" inputmode="decimal" style="width:90px;" placeholder="facultatif"></label>
    <label>Mon e-mail<br><input type="email" name="email" required maxlength="160"></label>
    <input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
    <button type="submit" class="btn-small">Me prévenir</button>
  </form>
  <p id="chineur-msg" role="status" style="margin:0;"></p>
  <p style="margin:0;font-size:.8rem;color:var(--ink-soft);">Votre adresse sert uniquement à ces alertes ; chaque e-mail contient un lien de désinscription.</p>
</section>
<script>
(function(){var f=document.getElementById('chineur-form');if(!f)return;f.addEventListener('submit',function(e){e.preventDefault();var m=document.getElementById('chineur-msg');
var d={};Array.prototype.forEach.call(f.elements,function(i){if(i.name)d[i.name]=i.value});
fetch(<?= json_encode($api) ?>,{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(d)})
.then(function(r){return r.json()}).then(function(r){m.textContent=r.error||r.message;if(!r.error)f.reset()}).catch(function(){m.textContent='Envoi impossible, réessayez.'})});})();
</script>
