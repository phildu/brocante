// Listes « Nature » et « Sous-catégorie » liées : la sous-catégorie ne propose que celles de la nature choisie, et
// choisir une sous-catégorie sans nature renseigne la nature. S'applique à tout bloc [data-ai-form] contenant les
// deux champs (name ou data-ai-input « nature » et « sous_categorie » : catalogue, relectures du Studio et de l'ajout rapide).
(function () {
  'use strict';
  function field(root, name) {
    return root.querySelector('[name="' + name + '"]') || root.querySelector('[data-ai-input="' + name + '"]');
  }
  function link(root) {
    var nat = field(root, 'nature'), sub = field(root, 'sous_categorie');
    if (!nat || !sub || sub.dataset.linked) return;
    sub.dataset.linked = '1';
    function sync() {
      var n = nat.value;
      Array.prototype.forEach.call(sub.querySelectorAll('option[data-nature]'), function (o) {
        var ok = !n || o.dataset.nature === n;
        o.disabled = !ok; o.hidden = !ok;
      });
      var cur = sub.selectedOptions[0];
      if (cur && cur.disabled) sub.value = '';
    }
    nat.addEventListener('change', sync);
    sub.addEventListener('change', function () {
      var o = sub.selectedOptions[0];
      if (!nat.value && o && o.dataset.nature) { nat.value = o.dataset.nature; sync(); }
    });
    sync();
  }
  function start() { document.querySelectorAll('[data-ai-form]').forEach(link); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
