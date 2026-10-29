<?php
// Formulaire de demande de place d'un événement (vide-grenier) : mêmes mentions légales que le formulaire du plan.
// Variables attendues : $evSlug (identifiant de la galerie). Le JS expose window.GXEventBooking.open().
$evSlug = (string) ($evSlug ?? '');
?>
<style>
#ev-form .pf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px;}
#ev-form .pf-grid label{display:grid;gap:3px;font-size:.85rem;}
#ev-form .pf-grid input,#ev-form .pf-grid select{width:100%;box-sizing:border-box;}
#ev-form .pf-box{border:1px solid var(--line);border-radius:8px;padding:10px;display:grid;gap:8px;}
#ev-form .pf-box>strong{font-size:.85rem;}
#ev-form .pf-consent{display:flex;gap:8px;align-items:flex-start;font-size:.85rem;}
#ev-form .pf-req{color:var(--accent-2,var(--accent));font-weight:700;}
</style>
<form id="ev-form" hidden enctype="multipart/form-data" data-slug="<?= e($evSlug) ?>" data-endpoint="/galerie/exposant.php" style="margin-top:12px;border:1px solid var(--line);border-radius:12px;padding:14px;display:none;gap:10px;max-width:760px;" autocomplete="on">
  <strong id="ev-form-title">Votre demande</strong>
  <p id="ev-form-price" style="margin:0;color:var(--ink-soft);"></p>
  <input type="hidden" name="slot"><input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;" aria-hidden="true">
  <div class="pf-grid">
    <label>Nom affiché (facultatif)<input type="text" name="name" maxlength="80" autocomplete="name"></label>
    <label>E-mail <span class="pf-req">*</span><input type="email" name="email" required maxlength="160" autocomplete="email"></label>
    <label>Téléphone (facultatif)<input type="text" name="phone" maxlength="30" autocomplete="tel"></label>
    <label>Je suis <span class="pf-req">*</span><select name="kind" id="ev-kind">
      <option value="particulier">Particulier</option><option value="association">Association</option><option value="entreprise">Entreprise</option>
    </select></label>
  </div>
  <p class="hint" style="margin:0;font-size:.8rem;">Le registre des vendeurs, obligatoire pour l'organisateur, demande l'identité, le domicile et les références de la pièce d'identité. <span class="pf-req">*</span> champs obligatoires.</p>

  <div class="pf-box" data-ct="particulier">
    <strong>Particulier</strong>
    <div class="pf-grid">
      <label>Prénom <span class="pf-req">*</span><input type="text" name="firstname" maxlength="60" autocomplete="given-name"></label>
      <label>Nom <span class="pf-req">*</span><input type="text" name="lastname" maxlength="60" autocomplete="family-name"></label>
      <label>Date de naissance (facultatif)<input type="date" name="birth_date" data-opt="1"></label>
      <label>Lieu de naissance (facultatif)<input type="text" name="birth_place" maxlength="120" data-opt="1"></label>
    </div>
    <div class="pf-grid">
      <label>Adresse du domicile <span class="pf-req">*</span><input type="text" name="address" maxlength="160" autocomplete="street-address"></label>
      <label>Code postal <span class="pf-req">*</span><input type="text" name="postal_code" maxlength="12" autocomplete="postal-code" inputmode="numeric"></label>
      <label>Ville <span class="pf-req">*</span><input type="text" name="city" maxlength="80" autocomplete="address-level2"></label>
    </div>
    <label class="pf-consent"><input type="checkbox" name="attestation" value="1"> <span>J'atteste sur l'honneur ne pas participer à plus de deux autres manifestations de ce type dans l'année et ne vendre que des objets personnels usagés.</span></label>
  </div>

  <div class="pf-box" data-ct="association entreprise">
    <strong>Association ou entreprise</strong>
    <div class="pf-grid">
      <label>Dénomination <span class="pf-req">*</span><input type="text" name="org_name" maxlength="120" autocomplete="organization"></label>
      <label data-ct="entreprise">Forme juridique <span class="pf-req">*</span><select name="legal_form"><option value="">Choisir…</option><?php foreach (CT_LEGAL_FORMS as $lk => $lv): ?><option value="<?= e($lk) ?>"><?= e($lv) ?></option><?php endforeach; ?></select></label>
      <label data-ct="association">Numéro RNA (facultatif si SIRET)<input type="text" name="rna" maxlength="20" placeholder="W123456789" data-opt="1"></label>
      <label>SIRET <span class="pf-req">*</span><input type="text" name="siret" maxlength="20" inputmode="numeric" placeholder="14 chiffres" data-opt="1"></label>
      <label data-ct="entreprise">SIREN (si pas de SIRET)<input type="text" name="siren" maxlength="20" inputmode="numeric" data-opt="1"></label>
      <label data-ct="entreprise">TVA intracommunautaire (facultatif)<input type="text" name="vat" maxlength="20" data-opt="1"></label>
    </div>
    <div class="pf-grid">
      <label>Adresse du siège <span class="pf-req">*</span><input type="text" name="org_address" maxlength="160"></label>
      <label>Code postal du siège <span class="pf-req">*</span><input type="text" name="org_postal_code" maxlength="12" inputmode="numeric"></label>
      <label>Ville du siège <span class="pf-req">*</span><input type="text" name="org_city" maxlength="80"></label>
    </div>
    <div class="pf-grid">
      <label>Prénom du représentant <span class="pf-req">*</span><input type="text" name="rep_firstname" maxlength="60"></label>
      <label>Nom du représentant <span class="pf-req">*</span><input type="text" name="rep_lastname" maxlength="60"></label>
      <label>Fonction du représentant <span class="pf-req">*</span><input type="text" name="rep_function" maxlength="80" placeholder="Président, gérant…"></label>
    </div>
    <label class="pf-consent" data-ct="association"><input type="checkbox" name="association_declaration" value="1" data-req="1"> <span>Je déclare que l'association ne vend que des objets usagés issus de dons de particuliers.</span></label>
  </div>

  <div class="pf-box">
    <strong>Pièce d'identité (références obligatoires au registre)</strong>
    <div class="pf-grid">
      <label>Nature <span class="pf-req">*</span><select name="id_doc_type" required><option value="">Choisir…</option><?php foreach (CT_ID_DOC_TYPES as $dk => $dv): ?><option value="<?= e($dk) ?>"><?= e($dv) ?></option><?php endforeach; ?></select></label>
      <label>Numéro <span class="pf-req">*</span><input type="text" name="id_doc_number" maxlength="40" required></label>
      <label>Délivrée le <span class="pf-req">*</span><input type="date" name="id_doc_date" required></label>
      <label>Autorité émettrice <span class="pf-req">*</span><input type="text" name="id_doc_issuer" maxlength="100" placeholder="Préfecture de…, mairie de…" required></label>
    </div>
    <p class="hint" style="margin:0;font-size:.8rem;">Ces références sont recopiées au registre des vendeurs, conservé cinq ans après la manifestation.</p>
  </div>

  <details class="pf-box">
    <summary style="cursor:pointer;"><strong>Vérification d'identité : copie de la pièce (facultatif)</strong></summary>
    <p class="hint" style="margin:6px 0 0;font-size:.8rem;">La copie n'est pas exigée par le registre. Si vous l'ajoutez, elle sert uniquement à l'organisateur pour vérifier la pièce, reste privée et est supprimée automatiquement au bout de <?= (int) GB_CNI_RETENTION_DAYS ?> jours. Vous pouvez la refuser et ne saisir que les références ci-dessus.</p>
    <div class="pf-grid">
      <label>Recto (photo)<input type="file" name="cni_recto" accept="image/*" capture="environment"></label>
      <label>Verso (photo, si la pièce en a un)<input type="file" name="cni_verso" accept="image/*" capture="environment"></label>
    </div>
    <label class="pf-consent"><input type="checkbox" name="cni_consent" value="1"> <span>J'accepte le dépôt et la consultation de la copie de ma pièce d'identité par l'organisateur, pour vérification, avec suppression au terme du délai indiqué.</span></label>
  </details>

  <label>Message (facultatif)<input type="text" name="message" maxlength="400" style="width:100%;" placeholder="Matériel, type d'objets, véhicule…"></label>
  <label class="pf-consent"><input type="checkbox" name="consent" value="1" required> <span>J'accepte que mes coordonnées et les informations de ce formulaire soient transmises à l'organisateur pour traiter ma demande et tenir le registre des vendeurs.</span></label>
  <div><button type="submit" class="btn-cta" style="font-size:.95rem;padding:10px 18px;">Envoyer ma demande</button> <button type="button" id="ev-form-cancel" class="btn">Annuler</button></div>
  <p id="ev-form-msg" role="status" style="margin:0;"></p>
</form>
<script>
(function(){
  var f=document.getElementById('ev-form');if(!f)return;
  var msg=document.getElementById('ev-form-msg'),title=document.getElementById('ev-form-title'),priceEl=document.getElementById('ev-form-price'),current=null;
  function eur(c){return (c/100).toFixed(2).replace('.',',')+' €'}
  function syncKind(){
    var t=f.kind.value;
    [].forEach.call(f.querySelectorAll('[data-ct]'),function(g){
      var on=g.getAttribute('data-ct').split(' ').indexOf(t)>=0,need=on&&g.querySelector('.pf-req')!==null;
      g.hidden=!on;
      [].forEach.call(g.querySelectorAll('input,select,textarea'),function(i){
        i.disabled=!on;
        if(i.type!=='file')i.required=on&&(need?i.dataset.opt!=='1':i.dataset.req==='1');
        if(!on&&i.type==='checkbox')i.checked=false;
      });
    });
  }
  function price(){
    if(!current){priceEl.textContent='';return}
    var pro=f.kind.value==='entreprise'&&current.price_pro!=null?current.price_pro:current.price;
    priceEl.textContent=pro!=null?'Tarif : '+eur(pro)+' ('+(f.kind.value==='entreprise'?'entreprise':(f.kind.value==='association'?'association':'particulier'))+')':"Tarif communiqué par l'organisateur";
  }
  function open(slot,label,priceCents,pricePro){
    current={slot:slot,price:priceCents,price_pro:pricePro};
    f.hidden=false;f.style.display='grid';f.slot.value=slot;
    title.textContent='Demande pour : '+label;
    msg.textContent='';syncKind();price();
    f.scrollIntoView({behavior:'smooth',block:'center'});
  }
  function close(){f.style.display='none';f.hidden=true;current=null;f.reset();syncKind()}
  f.kind.addEventListener('change',function(){syncKind();price()});
  document.getElementById('ev-form-cancel').addEventListener('click',close);
  f.addEventListener('submit',function(e){
    e.preventDefault();if(!current)return;
    var fd=new FormData(f),slot=current.slot;
    msg.textContent='Un instant…';
    fetch(f.dataset.endpoint,{method:'POST',headers:{Accept:'application/json'},body:fd}).then(function(r){return r.json()})
      .then(function(r){
        if(r.error){msg.textContent=r.error;return}
        msg.textContent=r.message||'Votre demande est enregistrée.';
        window.dispatchEvent(new CustomEvent('gx:booking',{detail:{slot:slot,status:'pending'}}));
        f.reset();f.slot.value=slot;syncKind();price();current=null;
      }).catch(function(){msg.textContent='Envoi impossible, réessayez.'});
  });
  syncKind();
  window.GXEventBooking={open:open,close:close};
})();
</script>
