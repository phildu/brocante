/* Appels — dictée et résumé par IA. Aucune dépendance : MediaRecorder, puis
   conversion en WAV 16 kHz mono (format accepté par le fournisseur), envoi et
   remplissage des notes. Le texte de l'IA n'est jamais injecté en HTML. */
(function () {
  'use strict';

  var root = document.querySelector('[data-fv-ia]');
  if (!root) return;

  var notes = document.querySelector('textarea[name="notes"]');
  var recordBtn = root.querySelector('[data-ia-record]');
  var stopBtn = root.querySelector('[data-ia-stop]');
  var timeOut = root.querySelector('[data-ia-time]');
  var status = root.querySelector('[data-ia-status]');
  var paste = root.querySelector('[data-ia-text]');
  var pasteBtn = root.querySelector('[data-ia-send-text]');
  var resultBox = root.querySelector('[data-ia-result]');
  var summary = root.querySelector('[data-ia-summary]');
  var chips = root.querySelector('[data-ia-chips]');
  var transcript = root.querySelector('[data-ia-transcript]');
  var endpoint = root.getAttribute('data-endpoint') || 'appel-ia.php';
  var limit = 180;

  var recorder = null;
  var chunks = [];
  var stream = null;
  var startedAt = 0;
  var tick = null;
  var seconds = 0;
  var last = null;

  function say(message, kind) {
    if (!status) return;
    status.textContent = message || '';
    status.setAttribute('data-kind', kind || '');
  }

  function clock(value) {
    var m = Math.floor(value / 60);
    var s = value % 60;
    return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function setBusy(busy) {
    root.setAttribute('aria-busy', busy ? 'true' : 'false');
    if (recordBtn) recordBtn.disabled = busy;
    if (pasteBtn) pasteBtn.disabled = busy;
  }

  function supported() {
    return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia && window.MediaRecorder);
  }

  if (!supported() && recordBtn) {
    recordBtn.parentNode.removeChild(recordBtn);
    recordBtn = null;
    var fallback = root.querySelector('[data-ia-paste]');
    if (fallback) fallback.open = true;
  }

  function pickType() {
    var types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus'];
    for (var i = 0; i < types.length; i++) {
      if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(types[i])) return types[i];
    }
    return '';
  }

  function start() {
    if (!recordBtn) return;
    setBusy(true);
    say('Demande d’accès au micro…');
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (s) {
      stream = s;
      chunks = [];
      var type = pickType();
      try {
        recorder = type ? new MediaRecorder(s, { mimeType: type }) : new MediaRecorder(s);
      } catch (error) {
        recorder = new MediaRecorder(s);
      }
      recorder.addEventListener('dataavailable', function (event) {
        if (event.data && event.data.size) chunks.push(event.data);
      });
      recorder.addEventListener('stop', function () {
        var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
        release();
        if (!blob.size) {
          setBusy(false);
          say('Rien n’a été enregistré. Réessayez.', 'error');
          return;
        }
        submit(blob);
      });
      recorder.start();
      startedAt = Date.now();
      seconds = 0;
      if (stopBtn) stopBtn.hidden = false;
      if (timeOut) timeOut.hidden = false;
      say('Enregistrement en cours. Parlez à voix posée.', 'rec');
      tick = window.setInterval(function () {
        seconds = Math.floor((Date.now() - startedAt) / 1000);
        if (timeOut) timeOut.textContent = clock(seconds);
        if (seconds >= limit) stop();
        else if (seconds === limit - 15) say('Encore quinze secondes.', 'rec');
      }, 250);
    }).catch(function () {
      setBusy(false);
      say('Micro refusé ou indisponible. Autorisez le micro, ou collez le texte de l’échange.', 'error');
      var panel = root.querySelector('[data-ia-paste]');
      if (panel) panel.open = true;
    });
  }

  function release() {
    if (tick) { window.clearInterval(tick); tick = null; }
    if (stream) {
      stream.getTracks().forEach(function (track) { track.stop(); });
      stream = null;
    }
    recorder = null;
    if (stopBtn) stopBtn.hidden = true;
    if (timeOut) timeOut.hidden = true;
  }

  function stop() {
    if (recorder && recorder.state !== 'inactive') {
      recorder.stop();
      setBusy(true);
      say('Transcription et résumé en cours…');
    }
  }

  function audioContext() {
    var Ctor = window.AudioContext || window.webkitAudioContext;
    return Ctor ? new Ctor() : null;
  }

  function toWav(blob) {
    var Ctor = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    var ctx = audioContext();
    if (!ctx || !Ctor || !ctx.decodeAudioData) return Promise.resolve(blob);
    return blob.arrayBuffer().then(function (buffer) {
      return new Promise(function (resolve) { ctx.decodeAudioData(buffer, resolve, function () { resolve(null); }); });
    }).then(function (audio) {
      if (!audio) return blob;
      var rate = 16000;
      var frames = Math.ceil(audio.duration * rate);
      if (!frames) return blob;
      var offline = new Ctor(1, frames, rate);
      var source = offline.createBufferSource();
      source.buffer = audio;
      source.connect(offline.destination);
      source.start(0);
      return offline.startRendering().then(function (rendered) {
        var data = rendered.getChannelData(0);
        var view = new DataView(new ArrayBuffer(44 + data.length * 2));
        var write = function (offset, text) {
          for (var i = 0; i < text.length; i++) view.setUint8(offset + i, text.charCodeAt(i));
        };
        write(0, 'RIFF');
        view.setUint32(4, 36 + data.length * 2, true);
        write(8, 'WAVE');
        write(12, 'fmt ');
        view.setUint32(16, 16, true);
        view.setUint16(20, 1, true);
        view.setUint16(22, 1, true);
        view.setUint32(24, rate, true);
        view.setUint32(28, rate * 2, true);
        view.setUint16(32, 2, true);
        view.setUint16(34, 16, true);
        write(36, 'data');
        view.setUint32(40, data.length * 2, true);
        var offset = 44;
        for (var i = 0; i < data.length; i++, offset += 2) {
          var sample = Math.max(-1, Math.min(1, data[i]));
          view.setInt16(offset, sample < 0 ? sample * 0x8000 : sample * 0x7FFF, true);
        }
        return new Blob([view.buffer], { type: 'audio/wav' });
      }).catch(function () { return blob; });
    }).catch(function () { return blob; }).then(function (out) {
      if (ctx && ctx.close) ctx.close();
      return out;
    });
  }

  function submit(blob) {
    var prepared = blob;
    var go = function (file) {
      var form = new FormData();
      form.append('csrf', root.getAttribute('data-csrf') || '');
      form.append('prospect_id', root.getAttribute('data-prospect') || '0');
      form.append('audio', file, file.type === 'audio/wav' ? 'appel.wav' : 'appel.webm');
      post(form);
    };
    if (blob.type === 'audio/wav') {
      go(blob);
    } else {
      toWav(blob).then(go);
    }
  }

  function sendText() {
    var value = paste ? String(paste.value || '').trim() : '';
    if (value.length < 40) {
      say('Collez au moins quelques phrases de l’échange.', 'error');
      if (paste) paste.focus();
      return;
    }
    setBusy(true);
    say('Analyse du texte en cours…');
    var form = new FormData();
    form.append('csrf', root.getAttribute('data-csrf') || '');
    form.append('prospect_id', root.getAttribute('data-prospect') || '0');
    form.append('texte', value);
    post(form);
  }

  function post(form) {
    var started = seconds;
    fetch(endpoint, {
      method: 'POST',
      body: form,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    }).then(function (response) {
      return response.json().catch(function () {
        throw new Error('Réponse illisible (code ' + response.status + ').');
      }).then(function (data) {
        if (!response.ok || !data.ok) throw new Error(data && data.error ? data.error : 'Le résumé a échoué.');
        return data;
      });
    }).then(function (data) {
      setBusy(false);
      seconds = 0;
      if (timeOut) timeOut.textContent = '00:00';
      render(data, started);
      say('Résumé prêt : relisez-le, puis choisissez le résultat de l’appel.', 'ok');
    }).catch(function (error) {
      setBusy(false);
      say(error && error.message ? error.message : 'Le résumé a échoué. Réessayez.', 'error');
    });
  }

  var interestLabel = { chaud: 'Intérêt chaud', tiede: 'Intérêt tiède', froid: 'Intérêt froid', inconnu: 'Intérêt à qualifier' };

  function render(data, audioSeconds) {
    last = data;
    if (summary) summary.value = String(data.resume || '');
    if (transcript) transcript.textContent = String(data.verbatim || '');
    if (chips) {
      chips.textContent = '';
      var add = function (label, value, kind) {
        if (!value) return;
        var li = document.createElement('li');
        if (kind) li.setAttribute('data-kind', kind);
        var b = document.createElement('b');
        b.textContent = label;
        li.appendChild(b);
        li.appendChild(document.createTextNode(String(value)));
        chips.appendChild(li);
      };
      add('Besoin', data.besoin);
      add('Objection', data.objection);
      add('Suite', data.prochaine_etape);
      if (data.interet && data.interet !== 'inconnu') add('', interestLabel[data.interet] || '', 'interet');
      if (data.resultat) add('Résultat suggéré', data.resultat_label || data.resultat, 'outcome');
    }
    var suggested = root.querySelector('[data-ia-apply-outcome]');
    if (suggested) {
      if (data.resultat) {
        suggested.hidden = false;
        suggested.setAttribute('data-ia-outcome', data.resultat);
        suggested.textContent = 'Repérer : ' + (data.resultat_label || data.resultat);
      } else {
        suggested.hidden = true;
      }
    }
    var duration = document.querySelector('input[name="duration"]');
    if (duration && audioSeconds > 20 && (!duration.value || duration.value === '0')) {
      duration.value = String(Math.max(1, Math.round(audioSeconds / 60)));
    }
    if (resultBox) {
      resultBox.hidden = false;
      resultBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      if (summary) summary.focus();
    }
  }

  function compose() {
    var parts = [];
    var text = summary ? String(summary.value || '').trim() : '';
    if (text) parts.push(text);
    if (!last) return parts.join('\n\n');
    var extras = [
      ['Besoin', last.besoin],
      ['Objection', last.objection],
      ['Suite', last.prochaine_etape]
    ];
    var lines = [];
    extras.forEach(function (pair) {
      if (pair[1]) lines.push(pair[0] + ' : ' + pair[1]);
    });
    if (lines.length) parts.push(lines.join('\n'));
    return parts.join('\n\n');
  }

  function put(append) {
    if (!notes) {
      say('Zone de notes introuvable sur cette page.', 'error');
      return;
    }
    var value = compose();
    if (!value) {
      say('Résumé vide : rien à insérer.', 'error');
      return;
    }
    notes.value = append && notes.value.trim() !== '' ? notes.value.trim() + '\n\n' + value : value;
    notes.dispatchEvent(new Event('input', { bubbles: true }));
    notes.focus();
    say(append ? 'Ajouté à la fin des notes.' : 'Notes remplacées par le résumé.', 'ok');
  }

  if (recordBtn) recordBtn.addEventListener('click', start);
  if (stopBtn) stopBtn.addEventListener('click', stop);
  if (pasteBtn) pasteBtn.addEventListener('click', sendText);

  root.addEventListener('click', function (event) {
    var target = event.target.closest('[data-ia-insert], [data-ia-append], [data-ia-apply-outcome]');
    if (!target) return;
    if (target.hasAttribute('data-ia-insert')) { event.preventDefault(); put(false); return; }
    if (target.hasAttribute('data-ia-append')) { event.preventDefault(); put(true); return; }
    var value = target.getAttribute('data-ia-outcome') || '';
    var button = value ? document.querySelector('.fv-outcomes button[data-o="' + value + '"]') : null;
    if (!button) {
      say('Ce résultat n’est pas proposé par le formulaire.', 'error');
      return;
    }
    event.preventDefault();
    document.querySelectorAll('.fv-outcomes .is-suggested').forEach(function (el) { el.classList.remove('is-suggested'); });
    button.classList.add('is-suggested');
    button.scrollIntoView({ block: 'center', behavior: 'smooth' });
    button.focus({ preventScroll: true });
  });

  window.addEventListener('beforeunload', function (event) {
    if (recorder && recorder.state !== 'inactive') {
      event.preventDefault();
      event.returnValue = '';
    }
  });
})();
