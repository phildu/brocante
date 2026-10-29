// Autocomplétion d'adresse (Base Adresse Nationale) pour les champs qui portent
// data-address-autocomplete. Choisir une suggestion remplit le champ avec
// l'adresse complète, puis émet input/change pour les scripts qui écoutent.
(function () {
  'use strict';

  function setup(input) {
    if (input.dataset.addressReady === '1') return;
    input.dataset.addressReady = '1';

    var wrap = document.createElement('span');
    wrap.className = 'addr-wrap';
    input.parentNode.insertBefore(wrap, input);
    wrap.appendChild(input);

    var box = document.createElement('span');
    box.className = 'addr-suggestions';
    box.id = input.id ? input.id + '-suggestions' : '';
    box.hidden = true;
    wrap.appendChild(box);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('autocomplete', 'off');
    if (box.id) input.setAttribute('aria-controls', box.id);
    box.setAttribute('role', 'listbox');

    var timer = null, items = [], active = -1, seq = 0;

    function close() {
      box.hidden = true;
      box.textContent = '';
      items = [];
      active = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function choose(props) {
      input.value = props.label || '';
      close();
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function setActive(i) {
      items.forEach(function (b, k) {
        b.setAttribute('aria-selected', k === i ? 'true' : 'false');
        b.classList.toggle('is-active', k === i);
      });
      active = i;
      if (i >= 0 && items[i]) {
        input.setAttribute('aria-activedescendant', items[i].id);
        items[i].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    function render(features) {
      box.textContent = '';
      items = [];
      features.forEach(function (f, k) {
        var p = f.properties || {};
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'addr-suggestion';
        b.id = (box.id || 'addr') + '-' + k;
        b.setAttribute('role', 'option');
        b.setAttribute('aria-selected', 'false');
        var main = document.createElement('strong');
        main.textContent = p.name || p.label || '';
        var sub = document.createElement('span');
        sub.textContent = [p.postcode, p.city].filter(Boolean).join(' ');
        b.appendChild(main);
        if (sub.textContent) b.appendChild(sub);
        b.addEventListener('mousedown', function (e) { e.preventDefault(); });
        b.addEventListener('click', function () { choose(p); });
        box.appendChild(b);
        items.push(b);
      });
      box.hidden = !items.length;
      input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
      active = -1;
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      var q = input.value.trim();
      if (q.length < 3) { close(); return; }
      var mine = ++seq;
      timer = setTimeout(function () {
        fetch('https://api-adresse.data.gouv.fr/search/?autocomplete=1&limit=6&q=' + encodeURIComponent(q))
          .then(function (r) { return r.json(); })
          .then(function (d) { if (mine === seq) render((d && d.features) || []); })
          .catch(function () { if (mine === seq) close(); });
      }, 250);
    });

    input.addEventListener('keydown', function (e) {
      if (!items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); setActive((active + 1) % items.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((active - 1 + items.length) % items.length); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items[active].click(); }
      else if (e.key === 'Escape') { close(); }
    });

    input.addEventListener('blur', function () {
      setTimeout(close, 150);
    });
  }

  function init() {
    document.querySelectorAll('input[data-address-autocomplete]').forEach(setup);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
