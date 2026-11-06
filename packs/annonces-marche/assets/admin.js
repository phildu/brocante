(function () {
  var page = document.getElementById('am-page');
  if (!page) return;

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-Token': page.dataset.csrf
      },
      body: JSON.stringify(body || {})
    }).then(function (r) {
      return r.json().catch(function () {
        return { error: 'Réponse illisible (' + r.status + ')' };
      });
    });
  }

  var tokenBtn = document.getElementById('am-token');
  var tokenCopyBtn = document.getElementById('am-token-copy');
  var tokenOut = document.getElementById('am-token-out');
  var tokenStatus = document.getElementById('am-token-status');
  var currentToken = '';
  var selectionSection = document.getElementById('am-selection-dialog');

  document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = document.getElementById(button.dataset.openModal);
      if (dialog && typeof dialog.showModal === 'function') dialog.showModal();
    });
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
      var dialog = button.closest('dialog');
      if (dialog) dialog.close();
    });
  });
  document.querySelectorAll('dialog.am-modal').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
      if (event.target === dialog) dialog.close();
    });
  });

  var requestedModal = new URLSearchParams(location.search).get('modal');
  var modalByName = { selection: 'am-selection-dialog', api: 'am-api-dialog', token: 'am-api-dialog' };
  if (requestedModal && modalByName[requestedModal]) {
    var requestedDialog = document.getElementById(modalByName[requestedModal]);
    if (requestedDialog && typeof requestedDialog.showModal === 'function') requestedDialog.showModal();
  }

  if (tokenBtn) {
    tokenBtn.addEventListener('click', function () {
      if (page.dataset.entitled !== '1') return;
      if (page.dataset.hasToken === '1' && !confirm('Régénérer la clé API ? L’ancienne ne fonctionnera plus.')) return;
      tokenBtn.disabled = true;
      tokenStatus.textContent = 'Génération de la clé…';
      post(page.dataset.apiToken).then(function (data) {
        tokenBtn.disabled = false;
        if (data.error) {
          tokenOut.hidden = false;
          tokenOut.textContent = data.error;
          tokenStatus.textContent = '';
          return;
        }
        currentToken = data.token;
        tokenOut.hidden = false;
        tokenOut.textContent = data.token;
        tokenCopyBtn.hidden = false;
        tokenStatus.textContent = data.hint || '';
        page.dataset.hasToken = '1';
        tokenBtn.textContent = 'Régénérer la clé API';
      }).catch(function (error) {
        tokenBtn.disabled = false;
        tokenOut.hidden = false;
        tokenOut.textContent = error.message || 'Impossible de créer la clé API.';
        tokenStatus.textContent = '';
      });
    });
  }

  if (tokenCopyBtn) {
    tokenCopyBtn.addEventListener('click', function () {
      if (!currentToken) return;
      var copyPromise;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        copyPromise = navigator.clipboard.writeText(currentToken);
      } else {
        var temporaryInput = document.createElement('textarea');
        temporaryInput.value = currentToken;
        temporaryInput.setAttribute('readonly', '');
        temporaryInput.style.position = 'fixed';
        temporaryInput.style.opacity = '0';
        document.body.appendChild(temporaryInput);
        temporaryInput.select();
        var copied = document.execCommand('copy');
        temporaryInput.remove();
        copyPromise = copied ? Promise.resolve() : Promise.reject(new Error('Copie dans le presse-papiers indisponible.'));
      }
      copyPromise.then(function () {
        tokenStatus.textContent = 'Clé copiée dans le presse-papiers.';
      }).catch(function (error) {
        tokenStatus.textContent = error.message || 'Impossible de copier la clé.';
      });
    });
  }

  if (selectionSection) {
    var selectionUrl = page.dataset.apiSelection;
    var selectionSearch = document.getElementById('am-product-search');
    var selectionItems = document.getElementById('am-selection-items');
    var selectionStatus = document.getElementById('am-selection-status');
    var selectionPageLabel = document.getElementById('am-selection-page');
    var selectionPrev = document.getElementById('am-selection-prev');
    var selectionNext = document.getElementById('am-selection-next');
    var selectionView = 'catalogue';
    var selectionQuery = '';
    var selectionPage = 0;
    var selectionLimit = 30;

    function selectionApiError(response, data) {
      return data.error || 'Erreur HTTP ' + response.status;
    }

    function formatSelectionPrice(value) {
      if (!value) return '—';
      var amount = Number(String(value).replace(',', '.'));
      return Number.isFinite(amount) ? amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }) : value;
    }

    function loadSelection() {
      var params = new URLSearchParams({
        view: selectionView,
        q: selectionQuery,
        limit: String(selectionLimit),
        offset: String(selectionPage * selectionLimit)
      });
      selectionStatus.textContent = 'Chargement des produits…';
      selectionItems.replaceChildren();
      return fetch(selectionUrl + '&' + params.toString(), {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      }).then(function (response) {
        return response.json().catch(function () {
          throw new Error('Réponse illisible (' + response.status + ')');
        }).then(function (data) {
          if (!response.ok) throw new Error(selectionApiError(response, data));
          return data;
        });
      }).then(function (data) {
        selectionStatus.textContent = data.selected_count + ' article(s) dans la sélection à transmettre à l’application locale.';
        selectionItems.replaceChildren();
        if (!data.items.length) {
          var empty = document.createElement('tr');
          var cell = document.createElement('td');
          cell.colSpan = 5;
          cell.textContent = selectionView === 'selected' ? 'La sélection est vide.' : 'Aucun article trouvé.';
          empty.appendChild(cell);
          selectionItems.appendChild(empty);
        }
        data.items.forEach(function (item) {
          var row = document.createElement('tr');
          var checkCell = document.createElement('td');
          var checkbox = document.createElement('input');
          checkbox.type = 'checkbox';
          checkbox.checked = item.selected;
          checkbox.setAttribute('aria-label', (item.selected ? 'Retirer ' : 'Ajouter ') + item.name);
          checkbox.addEventListener('change', function () {
            checkbox.disabled = true;
            post(selectionUrl, {
              action: checkbox.checked ? 'select' : 'deselect',
              product_ref: item.ref
            }).then(function (result) {
              if (result.error) throw new Error(result.error);
              selectionStatus.textContent = result.selected_count + ' article(s) dans la sélection à transmettre à l’application locale.';
              if (selectionView === 'selected' && !checkbox.checked) loadSelection();
            }).catch(function (error) {
              checkbox.checked = !checkbox.checked;
              selectionStatus.textContent = error.message || 'Impossible de modifier la sélection.';
            }).finally(function () {
              checkbox.disabled = false;
            });
          });
          checkCell.appendChild(checkbox);
          row.appendChild(checkCell);
          [item.name, item.ref, item.brand || '—', formatSelectionPrice(item.price)].forEach(function (value) {
            var cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
          });
          selectionItems.appendChild(row);
        });
        var pageCount = Math.max(1, Math.ceil(data.total / selectionLimit));
        selectionPageLabel.textContent = 'Page ' + (selectionPage + 1) + ' / ' + pageCount;
        selectionPrev.disabled = selectionPage <= 0;
        selectionNext.disabled = selectionPage + 1 >= pageCount;
      }).catch(function (error) {
        selectionStatus.textContent = error.message || 'Impossible de charger les produits.';
        var row = document.createElement('tr');
        var cell = document.createElement('td');
        cell.colSpan = 5;
        cell.textContent = 'Erreur de chargement.';
        row.appendChild(cell);
        selectionItems.replaceChildren(row);
      });
    }

    document.getElementById('am-selection-search').addEventListener('click', function () {
      selectionView = 'catalogue';
      selectionQuery = selectionSearch.value.trim();
      selectionPage = 0;
      loadSelection();
    });
    selectionSearch.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') document.getElementById('am-selection-search').click();
    });
    document.getElementById('am-selection-view').addEventListener('click', function () {
      selectionView = 'selected';
      selectionQuery = '';
      selectionPage = 0;
      loadSelection();
    });
    document.getElementById('am-selection-all').addEventListener('click', function () {
      selectionView = 'catalogue';
      selectionQuery = '';
      selectionSearch.value = '';
      selectionPage = 0;
      loadSelection();
    });
    document.getElementById('am-selection-clear').addEventListener('click', function () {
      if (!confirm('Retirer tous les articles de la sélection destinée à l’application locale ?')) return;
      post(selectionUrl, { action: 'clear' }).then(function (data) {
        if (data.error) throw new Error(data.error);
        selectionView = 'selected';
        selectionQuery = '';
        selectionPage = 0;
        return loadSelection();
      }).catch(function (error) {
        selectionStatus.textContent = error.message || 'Impossible de vider la sélection.';
      });
    });
    selectionPrev.addEventListener('click', function () {
      if (selectionPage > 0) {
        selectionPage--;
        loadSelection();
      }
    });
    selectionNext.addEventListener('click', function () {
      selectionPage++;
      loadSelection();
    });
    loadSelection();
  }
})();
