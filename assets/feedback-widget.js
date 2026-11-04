/* Widget « Un retour ? » (programme testeurs). Aucune dépendance.
   L'API /admin/feedback-api.php vérifie la session de la boutique et le jeton
   CSRF ; ici on ne fait que composer la demande et afficher le suivi. */
(function () {
  var root = document.querySelector('[data-fbw]');
  if (!root) return;
  var config;
  try { config = JSON.parse(root.getAttribute('data-config') || '{}'); } catch (e) { return; }
  if (!config.api) return;

  var launch = root.querySelector('[data-fbw-open]');
  var panel = root.querySelector('.fbw-panel');
  var closeBtn = root.querySelector('[data-fbw-close]');
  var form = root.querySelector('[data-fbw-form]');
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-fbw-tab]'));
  var formView = form;
  var history = root.querySelector('[data-fbw-history]');
  var list = root.querySelector('[data-fbw-list]');
  var empty = root.querySelector('[data-fbw-empty]');
  var msg = root.querySelector('[data-fbw-msg]');
  var submit = root.querySelector('.fbw-submit');
  var dot = root.querySelector('[data-fbw-dot]');
  var count = root.querySelector('[data-fbw-count]');
  var severity = root.querySelector('[data-fbw-severity]');
  var textarea = form ? form.querySelector('textarea[name="message"]') : null;
  var historyLoaded = false;
  var MAX_SHOT = 3 * 1024 * 1024;
  var shotInput = form ? form.querySelector('input[name="shot"]') : null;
  var captureBtn = root.querySelector('[data-fbw-capture]');
  var annotateBtn = root.querySelector('[data-fbw-annotate]');
  var shotRemoveBtn = root.querySelector('[data-fbw-shot-remove]');
  var shotState = root.querySelector('[data-fbw-shot-state]');
  var editor = root.querySelector('[data-fbw-editor]');
  var canvas = editor ? editor.querySelector('[data-fbw-canvas]') : null;
  var capturedFile = null;
  var capturing = false;
  var editorImage = null;
  var strokes = [];
  var drawing = null;
  var tool = 'pen';
  var inkColor = '#d92d20';
  var micBtn = root.querySelector('[data-fbw-mic]');
  var dictateState = root.querySelector('[data-fbw-dictate-state]');
  var Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  var recognition = null;
  var listening = false;
  var micBase = '';

  function setMessage(text, kind) {
    if (!msg) return;
    msg.textContent = text || '';
    if (kind) msg.setAttribute('data-kind', kind); else msg.removeAttribute('data-kind');
  }

  function openPanel() {
    if (!panel) return;
    panel.hidden = false;
    launch.setAttribute('aria-expanded', 'true');
    peek();
    if (textarea && !form.hidden) textarea.focus();
  }

  function closePanel() {
    if (!panel) return;
    panel.hidden = true;
    launch.setAttribute('aria-expanded', 'false');
    stopDictation(true);
    launch.focus();
  }

  function showTab(name) {
    tabs.forEach(function (tab) {
      var active = tab.getAttribute('data-fbw-tab') === name;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    formView.hidden = name !== 'send';
    history.hidden = name !== 'mine';
    if (name === 'mine') loadHistory(true);
  }

  function syncSeverity() {
    var checked = form.querySelector('input[name="type"]:checked');
    if (severity) severity.hidden = !checked || checked.value !== 'bug';
  }

  function formatDate(iso) {
    var date = new Date(iso);
    if (isNaN(date.getTime())) return '';
    try {
      return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' +
        date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
      return date.toLocaleDateString('fr-FR');
    }
  }

  function renderHistory(items) {
    if (!list) return;
    list.textContent = '';
    var total = 0;
    (items || []).forEach(function (item) {
      total += (item.replies || []).length;
      var card = document.createElement('article');
      card.className = 'fbw-history-item';

      var top = document.createElement('div');
      top.className = 'fbw-history-top';
      var type = document.createElement('span');
      type.className = 'fbw-history-type';
      type.textContent = item.type_label || '';
      var badge = document.createElement('span');
      badge.className = 'fbw-badge';
      badge.setAttribute('data-tone', item.status_tone || 'new');
      badge.textContent = item.status_label || '';
      top.appendChild(type);
      top.appendChild(badge);

      var date = document.createElement('p');
      date.className = 'fbw-history-date';
      date.textContent = formatDate(item.at);

      var body = document.createElement('p');
      body.className = 'fbw-history-msg';
      body.textContent = item.message || '';

      card.appendChild(top);
      card.appendChild(date);
      card.appendChild(body);

      (item.replies || []).forEach(function (reply) {
        var box = document.createElement('div');
        box.className = 'fbw-history-reply';
        var who = document.createElement('b');
        who.textContent = 'Réponse de l’équipe' + (reply.at ? ' · ' + formatDate(reply.at) : '');
        box.appendChild(who);
        box.appendChild(document.createTextNode(reply.text || ''));
        card.appendChild(box);
      });

      list.appendChild(card);
    });
    if (empty) empty.hidden = total > 0 || (items || []).length > 0;
    if (count) {
      count.hidden = (items || []).length === 0;
      count.textContent = String((items || []).length);
    }
  }

  function peek() {
    return fetch(config.api + '?action=peek', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (dot && data && data.ok) dot.hidden = !(data.unread > 0);
      })
      .catch(function () {});
  }

  function loadHistory(force) {
    if (historyLoaded && !force) return Promise.resolve();
    return fetch(config.api + '?action=mine', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data || !data.ok) return;
        historyLoaded = true;
        if (dot) dot.hidden = true;
        renderHistory(data.items);
      })
      .catch(function () { /* le widget reste utilisable même si l'historique ne se charge pas */ });
  }

  function submitForm(event) {
    event.preventDefault();
    var data = new FormData(form);
    var message = String(data.get('message') || '').trim();
    if (message.length < 10) {
      setMessage('Décrivez votre retour en quelques mots (10 caractères minimum).', 'error');
      if (textarea) textarea.focus();
      return;
    }
    data.append('action', 'submit');
    data.append('page', location.pathname + location.search);
    data.append('url', location.href.split('#')[0]);
    data.append('page_title', document.title);
    data.append('module', config.module || '');
    data.append('viewport', window.innerWidth + '×' + window.innerHeight);
    if (capturedFile) {
      data.delete('shot');
      data.append('shot', capturedFile, capturedFile.name || 'capture.png');
    }
    if (submit) { submit.disabled = true; submit.textContent = 'Envoi…'; }
    setMessage('', '');
    fetch(config.api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-CSRF-Token': config.csrf || '' },
      body: data
    })
      .then(function (response) { return response.json().catch(function () { return { ok: false, error: 'Réponse illisible.' }; }); })
      .then(function (result) {
        if (!result || !result.ok) {
          setMessage((result && result.error) || 'Le retour n’a pas pu être envoyé.', 'error');
          return;
        }
        setMessage(result.message || 'Merci ! Votre retour est enregistré.', 'ok');
        form.reset();
        capturedFile = null;
        syncShot();
        stopDictation(true);
        syncSeverity();
        loadHistory(true);
      })
      .catch(function () { setMessage('Erreur réseau : réessayez dans un instant.', 'error'); })
      .finally(function () {
        if (submit) { submit.disabled = false; submit.textContent = 'Envoyer mon retour'; }
      });
  }

  // ── Capture d’écran et annotation (canvas, sans dépendance) ─────────────

  function syncShot() {
    var hasFile = !!(shotInput && shotInput.files && shotInput.files[0]);
    if (annotateBtn) annotateBtn.hidden = !hasFile || !!capturedFile;
    if (shotRemoveBtn) shotRemoveBtn.hidden = !capturedFile;
    if (shotState) {
      shotState.hidden = !capturedFile;
      if (capturedFile) shotState.textContent = 'Capture prête (' + Math.max(1, Math.round(capturedFile.size / 1024)) + ' Ko) : elle partira avec votre retour.';
    }
  }

  function setCaptureLabel(text) {
    var label = captureBtn ? captureBtn.querySelector('.fbw-tool-label') : null;
    if (label) label.textContent = text;
  }

  function openEditor(source) {
    if (!editor || !canvas) return;
    var maxWidth = 1600;
    var ratio = Math.min(1, maxWidth / (source.width || maxWidth));
    var width = Math.max(1, Math.round(source.width * ratio));
    var height = Math.max(1, Math.round(source.height * ratio));
    var base = document.createElement('canvas');
    base.width = width;
    base.height = height;
    base.getContext('2d').drawImage(source, 0, 0, width, height);
    editorImage = base;
    canvas.width = width;
    canvas.height = height;
    strokes = [];
    drawing = null;
    redraw();
    editor.hidden = false;
    root.classList.add('is-modal');
    var ok = editor.querySelector('[data-fbw-editor-ok]');
    if (ok) ok.focus();
  }

  function closeEditor() {
    if (!editor) return;
    editor.hidden = true;
    root.classList.remove('is-modal');
    drawing = null;
  }

  function drawShape(shape) {
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    ctx.lineWidth = shape.width;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = shape.color;
    ctx.beginPath();
    if (shape.type === 'pen') {
      shape.points.forEach(function (point, index) {
        if (index === 0) ctx.moveTo(point.x, point.y); else ctx.lineTo(point.x, point.y);
      });
      ctx.stroke();
    } else if (shape.type === 'rect') {
      ctx.rect(shape.x, shape.y, shape.x2 - shape.x, shape.y2 - shape.y);
      ctx.stroke();
    } else {
      ctx.moveTo(shape.x, shape.y);
      ctx.lineTo(shape.x2, shape.y2);
      ctx.stroke();
      var angle = Math.atan2(shape.y2 - shape.y, shape.x2 - shape.x);
      var head = Math.max(12, shape.width * 5);
      ctx.beginPath();
      ctx.moveTo(shape.x2, shape.y2);
      ctx.lineTo(shape.x2 - head * Math.cos(angle - Math.PI / 7), shape.y2 - head * Math.sin(angle - Math.PI / 7));
      ctx.moveTo(shape.x2, shape.y2);
      ctx.lineTo(shape.x2 - head * Math.cos(angle + Math.PI / 7), shape.y2 - head * Math.sin(angle + Math.PI / 7));
      ctx.stroke();
    }
  }

  function redraw() {
    if (!canvas || !editorImage) return;
    var ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(editorImage, 0, 0);
    strokes.forEach(drawShape);
    if (drawing) drawShape(drawing);
  }

  function canvasPoint(event) {
    var rect = canvas.getBoundingClientRect();
    return {
      x: (event.clientX - rect.left) * canvas.width / (rect.width || 1),
      y: (event.clientY - rect.top) * canvas.height / (rect.height || 1)
    };
  }

  function captureScreen() {
    if (capturing) return; // évite d'empiler plusieurs demandes (le sélecteur macOS/Chrome reviendrait)
    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
      setMessage('Capture d’écran indisponible dans ce navigateur : choisissez une image dans « Joindre une capture ».', 'error');
      if (shotInput) shotInput.click();
      return;
    }
    capturing = true;
    setCaptureLabel('Choix de la fenêtre…');
    if (captureBtn) captureBtn.disabled = true;
    navigator.mediaDevices.getDisplayMedia({ video: { frameRate: 2 }, audio: false })
      .then(function (stream) {
        var video = document.createElement('video');
        video.muted = true;
        video.playsInline = true;
        video.srcObject = stream;
        return video.play().then(function () {
          return new Promise(function (resolve) { setTimeout(resolve, 250); });
        }).then(function () {
          var shot = document.createElement('canvas');
          shot.width = video.videoWidth || 1280;
          shot.height = video.videoHeight || 800;
          shot.getContext('2d').drawImage(video, 0, 0, shot.width, shot.height);
          stream.getTracks().forEach(function (track) { track.stop(); });
          openEditor(shot);
        });
      })
      .catch(function (error) {
        setMessage(error && error.name === 'NotAllowedError'
          ? 'Capture annulée ou refusée par le système. Autorisez la capture d’écran du navigateur (macOS : Réglages Système → Confidentialité et sécurité → Enregistrement de l’écran, puis relancez Chrome), ou joignez une image ci-dessous.'
          : 'Capture impossible : choisissez une image dans « Joindre une capture ».', 'error');
      })
      .finally(function () {
        capturing = false;
        setCaptureLabel('Capturer l’écran');
        if (captureBtn) captureBtn.disabled = false;
      });
  }

  function annotateChosen() {
    var file = shotInput && shotInput.files && shotInput.files[0];
    if (!file) return;
    if (!/^image\/(png|jpe?g|webp)$/.test(file.type)) {
      setMessage('Formats acceptés : PNG, JPEG ou WebP.', 'error');
      return;
    }
    var url = URL.createObjectURL(file);
    var image = new Image();
    image.onload = function () {
      var source = document.createElement('canvas');
      source.width = image.naturalWidth;
      source.height = image.naturalHeight;
      source.getContext('2d').drawImage(image, 0, 0);
      URL.revokeObjectURL(url);
      openEditor(source);
    };
    image.onerror = function () {
      URL.revokeObjectURL(url);
      setMessage('Image illisible : réessayez avec un autre fichier.', 'error');
    };
    image.src = url;
  }

  function attachCapture(blob, name) {
    capturedFile = new File([blob], name, { type: blob.type || 'image/png' });
    if (shotInput) shotInput.value = '';
    closeEditor();
    syncShot();
    setMessage('Capture prête : elle partira avec votre retour.', 'ok');
  }

  function saveAnnotated() {
    if (!canvas) return;
    canvas.toBlob(function (blob) {
      if (!blob) { setMessage('La capture n’a pas pu être préparée.', 'error'); return; }
      if (blob.size <= MAX_SHOT) { attachCapture(blob, 'capture-annotee.png'); return; }
      canvas.toBlob(function (jpeg) {
        if (!jpeg) { setMessage('La capture dépasse 3 Mo : recadrez ou recommencez.', 'error'); return; }
        attachCapture(jpeg, 'capture-annotee.jpg');
      }, 'image/jpeg', 0.88);
    }, 'image/png');
  }

  // ── Dictée vocale (Web Speech API, aucune dépendance) ───────────────────

  function syncMic() {
    if (!micBtn) return;
    micBtn.classList.toggle('is-active', listening);
    micBtn.setAttribute('aria-pressed', listening ? 'true' : 'false');
    var label = micBtn.querySelector('.fbw-tool-label');
    if (label) label.textContent = listening ? 'Arrêter la dictée' : 'Dicter';
    if (dictateState) dictateState.hidden = !listening;
  }

  function stopDictation(silent) {
    listening = false;
    syncMic();
    if (recognition) {
      try { recognition.stop(); } catch (e) { /* déjà arrêtée */ }
    }
    if (!silent && textarea) textarea.focus();
  }

  function startDictation() {
    if (!Recognition || !textarea) return;
    micBase = textarea.value;
    if (micBase && !/\s$/.test(micBase)) micBase += ' ';
    recognition = new Recognition();
    recognition.lang = config.lang || 'fr-FR';
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.onresult = function (event) {
      var finalText = '';
      var interimText = '';
      for (var i = 0; i < event.results.length; i++) {
        var transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) finalText += transcript + ' ';
        else interimText += transcript;
      }
      textarea.value = (micBase + finalText + interimText).replace(/\s+$/, '');
    };
    recognition.onerror = function (event) {
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
        setMessage('Micro refusé : autorisez l’accès dans le navigateur.', 'error');
        stopDictation(true);
      } else if (event.error === 'network') {
        setMessage('Dictée interrompue : vérifiez la connexion.', 'error');
        stopDictation(true);
      }
    };
    recognition.onend = function () {
      if (!listening) { syncMic(); return; }
      // Chrome arrête la reconnaissance après un silence : on relance sans perdre le texte.
      micBase = textarea.value ? textarea.value.replace(/\s+$/, '') + ' ' : '';
      try { recognition.start(); } catch (e) { listening = false; syncMic(); }
    };
    listening = true;
    try {
      recognition.start();
      setMessage('Dictée en cours : parlez, puis cliquez sur « Arrêter la dictée ».', '');
      syncMic();
    } catch (e) {
      listening = false;
      syncMic();
      setMessage('La dictée n’a pas pu démarrer dans ce navigateur.', 'error');
    }
  }

  if (!Recognition) {
    if (micBtn) micBtn.hidden = true;
  } else if (micBtn) {
    micBtn.hidden = false;
    micBtn.addEventListener('click', function () {
      if (listening) stopDictation(false); else startDictation();
    });
  }

  if (captureBtn) captureBtn.addEventListener('click', captureScreen);
  if (annotateBtn) annotateBtn.addEventListener('click', annotateChosen);
  if (shotInput) shotInput.addEventListener('change', function () {
    capturedFile = null;
    syncShot();
  });
  if (shotRemoveBtn) shotRemoveBtn.addEventListener('click', function () {
    capturedFile = null;
    if (shotInput) shotInput.value = '';
    syncShot();
  });

  if (editor && canvas) {
    canvas.addEventListener('pointerdown', function (event) {
      if (event.button !== undefined && event.button !== 0) return;
      event.preventDefault();
      try {
        if (canvas.setPointerCapture && event.pointerId !== undefined) canvas.setPointerCapture(event.pointerId);
      } catch (e) { /* pointeur déjà relâché */ }
      var point = canvasPoint(event);
      drawing = {
        type: tool, color: inkColor,
        width: Math.max(2, Math.round(canvas.width / 320)),
        points: [point], x: point.x, y: point.y, x2: point.x, y2: point.y
      };
      strokes.push(drawing);
      redraw();
    });
    canvas.addEventListener('pointermove', function (event) {
      if (!drawing) return;
      event.preventDefault();
      var point = canvasPoint(event);
      if (drawing.type === 'pen') drawing.points.push(point);
      else { drawing.x2 = point.x; drawing.y2 = point.y; }
      redraw();
    });
    canvas.addEventListener('pointerup', function () { drawing = null; redraw(); });
    canvas.addEventListener('pointercancel', function () { drawing = null; redraw(); });

    editor.querySelectorAll('[data-fbw-tool]').forEach(function (button) {
      button.addEventListener('click', function () {
        tool = button.getAttribute('data-fbw-tool') || 'pen';
        editor.querySelectorAll('[data-fbw-tool]').forEach(function (other) {
          var active = other === button;
          other.classList.toggle('is-active', active);
          other.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      });
    });
    editor.querySelectorAll('[data-fbw-color]').forEach(function (button) {
      button.addEventListener('click', function () {
        inkColor = button.getAttribute('data-fbw-color') || '#d92d20';
        editor.querySelectorAll('[data-fbw-color]').forEach(function (other) {
          var active = other === button;
          other.classList.toggle('is-active', active);
          other.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
      });
    });
    editor.querySelectorAll('[data-fbw-undo]').forEach(function (button) {
      button.addEventListener('click', function () { strokes.pop(); redraw(); });
    });
    editor.querySelectorAll('[data-fbw-clear]').forEach(function (button) {
      button.addEventListener('click', function () { strokes = []; redraw(); });
    });
    editor.querySelectorAll('[data-fbw-editor-cancel]').forEach(function (button) {
      button.addEventListener('click', closeEditor);
    });
    editor.querySelectorAll('[data-fbw-editor-ok]').forEach(function (button) {
      button.addEventListener('click', saveAnnotated);
    });
  }

  syncShot();

  if (launch) launch.addEventListener('click', openPanel);
  if (closeBtn) closeBtn.addEventListener('click', closePanel);
  if (form) form.addEventListener('submit', submitForm);
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function () { showTab(tab.getAttribute('data-fbw-tab')); });
  });
  if (form) form.querySelectorAll('input[name="type"]').forEach(function (input) {
    input.addEventListener('change', syncSeverity);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    if (editor && !editor.hidden) { closeEditor(); return; }
    if (panel && !panel.hidden) closePanel();
  });

  syncSeverity();
  peek();
})();
