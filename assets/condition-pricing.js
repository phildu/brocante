(function () {
  'use strict';

  function init(form) {
    var condition = form.querySelector('select[name="etat"], #f-etat');
    var newPrice = form.querySelector('input[name="new_price"], #f-new-price');
    var salePrice = form.querySelector('input[name="price"], #f-price');
    if (!condition || !newPrice || !salePrice) return;

    var rates;
    try {
      rates = JSON.parse(form.dataset.conditionRates || '{}');
    } catch (error) {
      console.error('Impossible de lire le barème des états.', error);
      return;
    }

    var field = newPrice.closest('.field') || newPrice.parentElement;
    var output = document.createElement('p');
    output.className = 'hint';
    output.hidden = true;
    output.setAttribute('data-condition-price-suggestion', '');
    field.appendChild(output);

    function update() {
      var rate = rates[condition.value];
      var base = Number(String(newPrice.value).replace(',', '.'));
      if (!condition.value || !Number.isFinite(base) || base <= 0 || !Number.isFinite(Number(rate)) || Number(rate) <= 0) {
        output.hidden = true;
        output.replaceChildren();
        return;
      }
      var suggestion = Math.round(base * Number(rate)) / 100;
      output.replaceChildren(document.createTextNode('Suggestion selon l’état (' + rate + ' % du prix du neuf) : ' + suggestion.toFixed(2).replace('.', ',') + ' € '));
      var apply = document.createElement('button');
      apply.type = 'button';
      apply.className = 'btn-small';
      apply.textContent = 'Utiliser ce prix';
      apply.addEventListener('click', function () {
        salePrice.value = suggestion.toFixed(2);
        salePrice.dispatchEvent(new Event('input', { bubbles: true }));
        salePrice.dispatchEvent(new Event('change', { bubbles: true }));
      });
      output.appendChild(apply);
      output.hidden = false;
    }

    condition.addEventListener('change', update);
    newPrice.addEventListener('input', update);
    update();
  }

  document.querySelectorAll('[data-condition-rates]').forEach(init);
})();
