/**
 * Alerte de résolution faible sur les sélecteurs de photo de l'admin, avec
 * proposition d'amélioration via l'IA (Gemini) avant l'envoi du formulaire.
 * S'attache à tout <input type="file" data-check-resolution">.
 */
(function () {
  var MIN_SIDE = 900;

  function initResolutionCheck(input) {
    var wrapper = document.createElement('div');
    wrapper.className = 'res-warning';
    wrapper.hidden = true;
    input.insertAdjacentElement('afterend', wrapper);

    input.addEventListener('change', function () {
      wrapper.hidden = true;
      wrapper.innerHTML = '';

      var file = input.files && input.files[0];
      if (!file || file.type.indexOf('image/') !== 0) return;

      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () {
        var minSide = Math.min(img.naturalWidth, img.naturalHeight);
        if (minSide < MIN_SIDE) {
          showWarning(wrapper, input, img.naturalWidth, img.naturalHeight, file);
        }
        URL.revokeObjectURL(url);
      };
      img.onerror = function () { URL.revokeObjectURL(url); };
      img.src = url;
    });
  }

  function showWarning(wrapper, input, w, h, file) {
    wrapper.hidden = false;
    wrapper.innerHTML =
      '<p>Résolution faible (' + w + '×' + h + 'px) — cette image risque d’être floue une fois affichée en grand.</p>' +
      '<button type="button" class="btn-small">Améliorer la netteté via l’IA</button>' +
      '<span class="res-warning-status"></span>';

    var btn = wrapper.querySelector('button');
    var status = wrapper.querySelector('.res-warning-status');

    btn.addEventListener('click', function () {
      btn.disabled = true;
      status.textContent = ' Amélioration en cours (peut prendre jusqu’à une minute)…';

      var fd = new FormData();
      fd.append('photo', file);

      fetch('/admin/enhance-image.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.ok) {
            status.textContent = ' ' + (data.error || 'Échec — réessayez plus tard.');
            btn.disabled = false;
            return;
          }
          return fetch(data.url)
            .then(function (res) { return res.blob(); })
            .then(function (blob) {
              var enhancedFile = new File(
                [blob],
                file.name.replace(/\.[^.]+$/, '') + '-ia.jpg',
                { type: blob.type || 'image/jpeg' }
              );
              var dt = new DataTransfer();
              dt.items.add(enhancedFile);
              input.files = dt.files;
              wrapper.hidden = true;
            });
        })
        .catch(function (err) {
          status.textContent = ' Erreur réseau — réessayez (' + err.message + ').';
          btn.disabled = false;
        });
    });
  }

  document.querySelectorAll('input[type="file"][data-check-resolution]').forEach(initResolutionCheck);
})();
