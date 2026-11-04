/* Studio vidéo — préparation, prise de vue assistée et montage dans le navigateur.
   Aucune prise n'est envoyée au serveur : les fichiers restent dans IndexedDB sur cet appareil. */
(() => {
  'use strict';

  const root = document.getElementById('stv');
  if (!root) return;

  const API_PROJECTS = root.dataset.apiProjects;
  const API_AI = root.dataset.apiAi;
  const CSRF = root.dataset.csrf || '';
  const FRAMINGS = ['plan large', 'plan moyen', 'gros plan', 'détail', 'mains à l’ouvrage', 'plan de coupe'];
  // H.264 explicite : « video/mp4 » seul laisse le navigateur choisir VP9/Opus
  // dans un conteneur MP4, illisible sur beaucoup de lecteurs et de réseaux sociaux.
  const MIME_CANDIDATES = [
    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
    'video/mp4;codecs=avc1.4d002a,mp4a.40.2',
    'video/mp4;codecs=avc1.42E01E',
    'video/mp4;codecs=h264,aac',
    'video/webm;codecs=vp9,opus',
    'video/webm;codecs=vp8,opus',
    'video/webm',
  ];

  const $ = (sel, scope = document) => scope.querySelector(sel);
  const $$ = (sel, scope = document) => Array.from(scope.querySelectorAll(sel));
  const el = {
    project: $('#stv-project'), newBtn: $('#stv-new'), deleteBtn: $('#stv-delete'), saved: $('#stv-saved'),
    tabs: $$('.stv-steps [data-step]'), panels: $$('[data-panel]'), status: $('#stv-status'),
    title: $('#stv-title'), kind: $('#stv-kind'), seconds: $('#stv-seconds'), format: $('#stv-format'),
    tone: $('#stv-tone'), audience: $('#stv-audience'), brief: $('#stv-brief'),
    aiScript: $('#stv-ai-script'), saveBtn: $('#stv-save'),
    script: $('#stv-script'), scriptMeta: $('#stv-script-meta'), titles: $('#stv-titles'),
    checklist: $('#stv-checklist'), hashtags: $('#stv-hashtags'),
    shots: $('#stv-shots'), addShot: $('#stv-add-shot'), clearShots: $('#stv-clear-shots'),
    stage: $('#stv-stage'), video: $('#stv-video'), overlay: $('#stv-overlay'), tele: $('#stv-tele'),
    teleText: $('#stv-tele-text'), countdown: $('#stv-countdown'), recflag: $('#stv-recflag'),
    recTime: $('#stv-rec-time'),
    camBtn: $('#stv-cam'), flipBtn: $('#stv-flip'), guides: $('#stv-guides'), mirror: $('#stv-mirror'),
    teleToggle: $('#stv-tele-toggle'), telePlay: $('#stv-tele-play'), teleSpeed: $('#stv-tele-speed'),
    teleSize: $('#stv-tele-size'), cd: $('#stv-cd'), shotSelect: $('#stv-shot-select'), shotHint: $('#stv-shot-hint'),
    meterAudio: $('#stv-meter-audio'), audioNum: $('#stv-audio-num'),
    meterLight: $('#stv-meter-light'), lightNum: $('#stv-light-num'),
    record: $('#stv-record'), stop: $('#stv-stop'), timer: $('#stv-timer'),
    clips: $('#stv-clips'), clipCount: $('#stv-clip-count'), storage: $('#stv-storage'),
    timeline: $('#stv-timeline'), aiPlan: $('#stv-ai-plan'), autoPlan: $('#stv-auto-plan'), fade: $('#stv-fade'),
    planMeta: $('#stv-plan-meta'), preview: $('#stv-preview'), previewCanvas: $('#stv-preview-canvas'),
    previewBtn: $('#stv-preview-btn'), exportBtn: $('#stv-export'), cancel: $('#stv-cancel'),
    progress: $('#stv-progress'), progressBar: $('#stv-progress i'), exportNote: $('#stv-export-note'), download: $('#stv-download'),
    camnote: $('#stv-camnote'),
  };

  const state = {
    projectId: null, project: null,
    shots: [], titles: [], checklist: [], hashtags: [], plan: [], clips: [],
    loading: false, saving: false, saveQueue: false, saveTimer: 0,
    stream: null, facing: 'user',
    recording: false, chunks: [], recordStart: 0, timer: 0, recorder: null,
    audioCtx: null, analyser: null, meterRaf: 0, lightTimer: 0, lightCanvas: null,
    teleRaf: 0, teleLast: 0, teleRunning: false,
    running: false, abort: false, downloading: '', exporting: false,
  };

  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const safeThumb = (value) => /^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$/.test(String(value || '')) ? String(value) : '';
  const clamp = (n, min, max) => Math.max(min, Math.min(max, n));
  const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
  const uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : 'c' + Date.now() + Math.random().toString(36).slice(2, 8));

  function fmtClock(seconds) {
    const s = Math.max(0, Math.floor(seconds));
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
  }
  function fmtSeconds(seconds) {
    const n = Math.round(Number(seconds) * 10) / 10;
    return (Number.isInteger(n) ? String(n) : n.toFixed(1).replace('.', ',')) + ' s';
  }
  function fmtBytes(bytes) {
    if (!bytes) return '0 Mo';
    return (bytes / 1024 / 1024).toFixed(bytes > 20 * 1024 * 1024 ? 0 : 1).replace('.', ',') + ' Mo';
  }
  function slug(value) {
    return String(value || 'video').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 48) || 'video';
  }

  let statusTimer = 0;
  function notify(message, kind = 'info') {
    if (!el.status) return;
    el.status.textContent = message || '';
    el.status.className = 'stv-status' + (kind === 'error' ? ' is-error' : kind === 'ok' ? ' is-ok' : kind === 'busy' ? ' is-busy' : '');
    clearTimeout(statusTimer);
    if (kind !== 'error' && kind !== 'busy') statusTimer = setTimeout(() => { el.status.textContent = ''; el.status.className = 'stv-status'; }, 8000);
  }

  function withId(url, id) {
    return url + (url.includes('?') ? '&' : '?') + 'id=' + encodeURIComponent(id);
  }

  async function request(url, method = 'GET', body = null) {
    const opts = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (method !== 'GET') {
      opts.headers['Content-Type'] = 'application/json';
      opts.headers['X-CSRF-Token'] = CSRF;
      opts.body = JSON.stringify(body || {});
    }
    const response = await fetch(url, opts);
    let data = {};
    try { data = await response.json(); } catch (e) { data = {}; }
    if (!response.ok) {
      const error = new Error((data && data.error) || 'Erreur ' + response.status);
      error.status = response.status;
      throw error;
    }
    return data;
  }

  // ── Projets (base de la boutique) ─────────────────────────────────────────

  function collectProject() {
    return {
      id: state.projectId || undefined,
      title: el.title.value.trim(),
      kind: el.kind.value,
      audience: el.audience.value.trim(),
      tone: el.tone.value,
      target_seconds: Number(el.seconds.value) || 45,
      format: el.format.value,
      brief: el.brief.value.trim(),
      script: el.script.value.trim(),
      shots: state.shots,
      titles: state.titles,
      checklist: state.checklist,
      hashtags: state.hashtags,
      plan: state.plan,
      status: state.project ? state.project.status : 'draft',
    };
  }

  async function saveProject(immediate = false) {
    if (state.loading) return;
    if (state.saving) {
      state.saveQueue = immediate;
      return;
    }
    if (!immediate) {
      clearTimeout(state.saveTimer);
      state.saveTimer = setTimeout(() => saveProject(true), 1200);
      return;
    }
    clearTimeout(state.saveTimer);
    state.saving = true;
    el.saved.textContent = 'Enregistrement…';
    const created = !state.projectId;
    try {
      const result = await request(API_PROJECTS, 'POST', collectProject());
      const project = result.project;
      state.project = project;
      state.projectId = project.id;
      if (created) {
        await dbReassign('draft', String(project.id));
        state.clips.forEach((clip) => { if (clip.project === 'draft') clip.project = String(project.id); });
      }
      el.project.value = String(project.id);
      el.deleteBtn.hidden = false;
      await refreshProjects(project.id);
      el.saved.textContent = 'Enregistré à ' + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      setTimeout(() => { if (!state.saving) el.saved.textContent = ''; }, 6000);
    } catch (error) {
      el.saved.textContent = '';
      notify(error.message, 'error');
    } finally {
      state.saving = false;
      if (state.saveQueue) {
        state.saveQueue = false;
        saveProject(true);
      }
    }
  }

  async function refreshProjects(selectId) {
    try {
      const result = await request(API_PROJECTS, 'GET');
      const list = result.projects || [];
      el.project.innerHTML = '<option value="">Nouveau projet</option>' + list.map((p) =>
        '<option value="' + p.id + '">' + esc(p.title) + ' · ' + esc(String(p.updated_at || '').slice(0, 10)) + '</option>').join('');
      if (selectId) el.project.value = String(selectId);
      el.deleteBtn.hidden = !selectId;
    } catch (error) {
      notify('Liste des projets indisponible : ' + error.message, 'error');
    }
  }

  function fillForm(project) {
    state.loading = true;
    el.title.value = project.title || '';
    el.kind.value = project.kind || 'piece';
    el.seconds.value = String(project.target_seconds || 45);
    el.format.value = project.format || 'landscape';
    el.tone.value = project.tone || 'simple';
    el.audience.value = project.audience || '';
    el.brief.value = project.brief || '';
    el.script.value = project.script || '';
    state.shots = Array.isArray(project.shots) ? project.shots : [];
    state.titles = Array.isArray(project.titles) ? project.titles : [];
    state.checklist = Array.isArray(project.checklist) ? project.checklist : [];
    state.hashtags = Array.isArray(project.hashtags) ? project.hashtags : [];
    state.plan = Array.isArray(project.plan) ? project.plan : [];
    state.loading = false;
  }

  async function openProject(id) {
    if (!id) {
      newProject();
      return;
    }
    try {
      const result = await request(withId(API_PROJECTS, id), 'GET');
      const project = result.project;
      state.project = project;
      state.projectId = project.id;
      fillForm(project);
      applyFormat();
      state.clips = await clipsForProject(project.id);
      renderAll();
      el.project.value = String(project.id);
      el.deleteBtn.hidden = false;
      el.saved.textContent = '';
    } catch (error) {
      notify(error.message, 'error');
    }
  }

  function newProject() {
    state.project = null;
    state.projectId = null;
    state.clips = [];
    fillForm({ title: '', kind: 'piece', target_seconds: 45, format: 'landscape', tone: 'simple', audience: '', brief: '', script: '', shots: [], titles: [], checklist: [], hashtags: [], plan: [] });
    applyFormat();
    renderAll();
    el.project.value = '';
    el.deleteBtn.hidden = true;
    el.saved.textContent = '';
    notify('Nouveau projet. Décrivez le sujet, puis demandez le script à l’IA.', 'info');
    el.title.focus();
  }

  async function deleteProject() {
    if (!state.projectId) return;
    if (!confirm('Supprimer ce projet et toutes ses prises ? Cette action est définitive.')) return;
    const id = state.projectId;
    try {
      await request(withId(API_PROJECTS, id), 'DELETE');
      await dbDeleteProject(String(id));
      state.projectId = null;
      state.project = null;
      await refreshProjects('');
      newProject();
      notify('Projet supprimé.', 'ok');
    } catch (error) {
      notify(error.message, 'error');
    }
  }

  // ── Prises (IndexedDB, sur cet appareil) ──────────────────────────────────

  let dbPromise = null;
  function openDb() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
      const req = indexedDB.open('brocante-studio', 1);
      req.onupgradeneeded = () => {
        const db = req.result;
        if (!db.objectStoreNames.contains('clips')) {
          const store = db.createObjectStore('clips', { keyPath: 'id' });
          store.createIndex('project', 'project');
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error || new Error('Stockage local indisponible.'));
    });
    return dbPromise;
  }

  async function dbAll() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const req = db.transaction('clips', 'readonly').objectStore('clips').getAll();
      req.onsuccess = () => resolve(req.result || []);
      req.onerror = () => reject(req.error || new Error('Lecture du stockage impossible.'));
    });
  }

  async function dbPut(clip) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('clips', 'readwrite');
      tx.objectStore('clips').put(clip);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error || new Error('Écriture impossible.'));
      tx.onabort = () => reject(tx.error || new Error('Écriture interrompue.'));
    });
  }

  async function dbDelete(id) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('clips', 'readwrite');
      tx.objectStore('clips').delete(id);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error || new Error('Suppression impossible.'));
      tx.onabort = () => reject(tx.error || new Error('Suppression interrompue.'));
    });
  }

  async function dbForEach(callback) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction('clips', 'readwrite');
      const store = tx.objectStore('clips');
      const req = store.openCursor();
      req.onsuccess = () => {
        const cursor = req.result;
        if (cursor) { callback(cursor.value, store); cursor.continue(); }
      };
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error || new Error('Parcours du stockage impossible.'));
      tx.onabort = () => reject(tx.error || new Error('Parcours interrompu.'));
    });
  }

  /** Fichier vidéo d'une prise : Safari n'accepte pas un Blob dans IndexedDB, on garde les octets. */
  function clipBlob(clip) {
    return clip.blob || new Blob([clip.bytes], { type: clip.mime || 'video/webm' });
  }

  async function clipsForProject(projectId) {
    const all = await dbAll();
    return all.filter((clip) => String(clip.project) === String(projectId)).sort((a, b) => String(a.created_at).localeCompare(String(b.created_at)));
  }

  async function dbReassign(from, to) {
    await dbForEach((clip, store) => {
      if (String(clip.project) === String(from)) store.put(Object.assign({}, clip, { project: String(to) }));
    });
  }

  async function dbDeleteProject(projectId) {
    await dbForEach((clip, store) => {
      if (String(clip.project) === String(projectId)) store.delete(clip.id);
    });
  }

  // ── Rendu ─────────────────────────────────────────────────────────────────

  function renderAll() {
    renderShots();
    renderMeta();
    renderTitles();
    renderChecklist();
    renderHashtags();
    renderShotSelect();
    renderClips();
    renderTimeline();
    updatePlanMeta();
  }

  function shotAsCard(shot, index) {
    const framing = FRAMINGS.map((f) => '<option value="' + esc(f) + '"' + (f === shot.framing ? ' selected' : '') + '>' + esc(f) + '</option>').join('');
    return '<div class="stv-shot" data-index="' + index + '">'
      + '<div class="stv-shot-n">' + (index + 1) + '</div>'
      + '<div class="stv-shot-main">'
      + '<div class="stv-shot-head"><strong data-role="shot-label">' + esc(shot.label) + '</strong><span>' + esc(shot.framing) + ' · ' + esc(fmtSeconds(shot.seconds)) + '</span></div>'
      + (shot.line ? '<p class="stv-shot-line">« ' + esc(shot.line) + ' »</p>' : '')
      + (shot.tip ? '<p class="stv-shot-tip">Conseil : ' + esc(shot.tip) + '</p>' : '')
      + '<div class="stv-shot-actions">'
      + '<button type="button" data-act="shoot">Tourner ce plan</button>'
      + '<button type="button" data-act="edit">Modifier</button>'
      + '<button type="button" data-act="up">Monter</button>'
      + '<button type="button" data-act="down">Descendre</button>'
      + '<button type="button" data-act="del">Supprimer</button>'
      + '</div>'
      + '<div class="stv-shot-edit" hidden>'
      + '<label class="stv-field">Nom du plan<input type="text" maxlength="80" data-field="label" value="' + esc(shot.label) + '"></label>'
      + '<div class="stv-row2">'
      + '<label class="stv-field">Cadrage<select data-field="framing">' + framing + '</select></label>'
      + '<label class="stv-field">Durée (secondes)<input type="number" min="2" max="20" step="0.5" data-field="seconds" value="' + esc(shot.seconds) + '"></label>'
      + '</div>'
      + '<label class="stv-field">Ce qui est dit<textarea rows="2" maxlength="400" data-field="line">' + esc(shot.line) + '</textarea></label>'
      + '<label class="stv-field">Conseil de prise de vue<input type="text" maxlength="240" data-field="tip" value="' + esc(shot.tip) + '"></label>'
      + '</div>'
      + '</div></div>';
  }

  function renderShots() {
    if (!el.shots) return;
    if (!state.shots.length) {
      el.shots.innerHTML = '<p class="hint">Écrivez le script avec l’IA pour obtenir une liste de plans, ou ajoutez-les à la main.</p>';
      return;
    }
    el.shots.innerHTML = state.shots.map(shotAsCard).join('');
  }

  function renderMeta() {
    const text = el.script.value.trim();
    const words = text ? text.split(/\s+/).length : 0;
    const estimate = words / 2.4;
    const target = Number(el.seconds.value) || 45;
    el.scriptMeta.textContent = words
      ? words + ' mot' + (words > 1 ? 's' : '') + ' ≈ ' + fmtSeconds(estimate) + ' à l’oral (cible : ' + target + ' s)'
      : 'Aucun texte pour l’instant.';
  }

  function renderTitles() {
    el.titles.innerHTML = state.titles.length
      ? state.titles.map((title) => '<li>' + esc(title) + '</li>').join('')
      : '<li class="hint">Aucun titre pour l’instant.</li>';
  }

  function renderChecklist() {
    el.checklist.innerHTML = state.checklist.length
      ? state.checklist.map((item) => '<li><label class="stv-check"><input type="checkbox"> <span>' + esc(item) + '</span></label></li>').join('')
      : '<li class="hint">La liste apparaîtra avec le script.</li>';
  }

  function renderHashtags() {
    el.hashtags.innerHTML = state.hashtags.map((tag) => '<span>' + esc(tag) + '</span>').join('');
  }

  function renderShotSelect() {
    const current = el.shotSelect.value;
    el.shotSelect.innerHTML = '<option value="0">Prise libre</option>' + state.shots.map((shot, i) =>
      '<option value="' + (i + 1) + '">Plan ' + (i + 1) + ' · ' + esc(shot.label) + '</option>').join('');
    el.shotSelect.value = state.shots[Number(current) - 1] ? current : '0';
    updateShotHint();
  }

  function updateShotHint() {
    const index = Number(el.shotSelect.value) || 0;
    const shot = state.shots[index - 1];
    if (!shot) {
      el.shotHint.textContent = el.script.value.trim() ? 'Le script complet défile dans le téléprompteur.' : 'Aucun script : parlez librement, puis gardez la meilleure prise.';
      return;
    }
    el.shotHint.textContent = 'Plan ' + index + ' · ' + shot.framing + ' · environ ' + fmtSeconds(shot.seconds)
      + (shot.line ? ' · « ' + shot.line + ' »' : '') + (shot.tip ? ' — ' + shot.tip : '');
  }

  function renderClips() {
    const count = state.clips.length;
    el.clipCount.hidden = count === 0;
    el.clipCount.textContent = String(count);
    if (!count) {
      el.clips.innerHTML = '<p class="hint">Aucune prise pour l’instant.</p>';
      el.storage.textContent = '';
      return;
    }
    el.clips.innerHTML = state.clips.slice().reverse().map((clip) => {
      const label = clip.shot > 0 ? 'Plan ' + clip.shot + (clip.label ? ' · ' + clip.label : '') : 'Prise libre';
      const date = new Date(clip.created_at);
      const time = isNaN(date.getTime()) ? '' : date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
      const thumb = safeThumb(clip.thumb);
      return '<div class="stv-clip" data-id="' + esc(clip.id) + '">'
        + (thumb ? '<img class="stv-clip-thumb" src="' + thumb + '" alt="">' : '<div class="stv-clip-thumb"></div>')
        + '<div class="stv-clip-main"><strong>' + esc(label) + '</strong><small>' + esc(fmtSeconds(clip.seconds)) + (time ? ' · ' + esc(time) : '') + '</small>'
        + '<div class="stv-clip-actions"><button type="button" data-act="use">Utiliser</button>'
        + '<button type="button" data-act="redo">Refaire</button>'
        + '<button type="button" data-act="download">Télécharger</button>'
        + '<button type="button" data-act="del">Supprimer</button></div></div></div>';
    }).join('');
    updateStorage();
  }

  async function updateStorage() {
    if (!navigator.storage || !navigator.storage.estimate) { el.storage.textContent = ''; return; }
    try {
      const estimate = await navigator.storage.estimate();
      el.storage.textContent = 'Prises enregistrées sur cet appareil : ' + fmtBytes(estimate.usage) + ' utilisés par le studio.';
    } catch (e) { el.storage.textContent = ''; }
  }

  function timelineRows() {
    if (state.shots.length) {
      return state.shots.map((shot, i) => ({ key: 'shot:' + (i + 1), shot: i + 1, label: shot.label, line: shot.line, target: shot.seconds }));
    }
    return state.clips.map((clip, i) => ({ key: 'clip:' + clip.id, shot: 0, label: clip.label || 'Prise ' + (i + 1), line: '', target: 0, own: clip.id }));
  }

  function findEntry(row) {
    if (row.own) return state.plan.find((entry) => entry.clip === row.own) || null;
    return state.plan.find((entry) => entry.shot === row.shot) || null;
  }

  function renderTimeline() {
    if (!el.timeline) return;
    const rows = timelineRows();
    if (!rows.length) {
      el.timeline.innerHTML = '<p class="hint">Tournez au moins une prise pour commencer le montage.</p>';
      updatePlanMeta();
      return;
    }
    el.timeline.innerHTML = rows.map((row, index) => {
      const entry = findEntry(row);
      const clip = entry ? state.clips.find((c) => c.id === entry.clip) : null;
      const duration = clip ? Number(clip.seconds) : 0;
      const inValue = clip ? clamp(Number(entry.in) || 0, 0, Math.max(0, duration - 0.2)) : 0;
      const outValue = clip ? clamp(Number(entry.out) || duration, inValue + 0.2, duration) : 0;
      const options = '<option value="">— aucune prise —</option>' + state.clips.map((c) => {
        const tag = c.shot > 0 ? 'Plan ' + c.shot : 'Prise libre';
        return '<option value="' + esc(c.id) + '"' + (entry && entry.clip === c.id ? ' selected' : '') + '>'
          + esc(fmtSeconds(c.seconds) + ' · ' + tag + (c.label ? ' · ' + c.label : '')) + '</option>';
      }).join('');
      return '<div class="stv-cut" data-key="' + esc(row.key) + '" data-index="' + index + '">'
        + '<div class="stv-cut-head"><strong>' + (index + 1) + '. ' + esc(row.label) + '</strong>'
        + '<span>' + (clip ? 'prise de ' + esc(fmtSeconds(duration)) : 'aucune prise choisie') + '</span>'
        + '<span class="stv-cut-move"><button type="button" data-move="-1" aria-label="Monter">↑</button>'
        + '<button type="button" data-move="1" aria-label="Descendre">↓</button>'
        + '<button type="button" data-move="0" aria-label="Retirer">×</button></span></div>'
        + '<div class="stv-cut-grid">'
        + '<label>Prise<select data-role="clip">' + options + '</select></label>'
        + '<div class="stv-cut-range"><label>Début → fin <small data-role="range-label">' + (clip ? esc(inValue.toFixed(1).replace('.', ',')) + ' → ' + esc(outValue.toFixed(1).replace('.', ',')) + ' s' : '—') + '</small></label>'
        + '<input type="range" min="0" max="' + (duration || 1) + '" step="0.1" value="' + inValue + '" data-role="in" aria-label="Début de la coupe"' + (clip ? '' : ' disabled') + '>'
        + '<input type="range" min="0" max="' + (duration || 1) + '" step="0.1" value="' + outValue + '" data-role="out" aria-label="Fin de la coupe"' + (clip ? '' : ' disabled') + '></div>'
        + '<label>Titre à l’écran<input type="text" maxlength="60" data-role="title" value="' + esc(entry ? entry.title : '') + '"' + (clip ? '' : ' disabled') + '></label>'
        + '</div></div>';
    }).join('');
    updatePlanMeta();
  }

  function updatePlanMeta() {
    const rows = timelineRows();
    let total = 0;
    let cuts = 0;
    rows.forEach((row) => {
      const entry = findEntry(row);
      if (!entry) return;
      const clip = state.clips.find((c) => c.id === entry.clip);
      if (!clip) return;
      const inValue = clamp(Number(entry.in) || 0, 0, clip.seconds);
      const outValue = clamp(Number(entry.out) || clip.seconds, inValue, clip.seconds);
      total += Math.max(0, outValue - inValue);
      cuts += 1;
    });
    const target = Number(el.seconds.value) || 45;
    el.planMeta.textContent = cuts
      ? 'Montage : ' + fmtSeconds(total) + ' · ' + cuts + ' plan' + (cuts > 1 ? 's' : '') + ' · cible ' + target + ' s'
      : 'Aucune coupe choisie pour l’instant.';
  }

  // ── Paramètres et navigation ──────────────────────────────────────────────

  function goTo(step) {
    el.tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.step === step)));
    el.panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== step; });
    if (step === 'shoot') {
      drawGuides();
      updateTeleText();
    }
    if (step === 'edit') {
      renderTimeline();
      el.preview.classList.toggle('is-portrait', el.format.value === 'portrait');
      el.previewCanvas.width = el.format.value === 'portrait' ? 720 : 1280;
      el.previewCanvas.height = el.format.value === 'portrait' ? 1280 : 720;
      paintPoster();
    }
    if (step !== 'brief' && !state.projectId) saveProject(true);
    else saveProject(false);
  }

  function applyFormat() {
    const portrait = el.format.value === 'portrait';
    el.stage.dataset.format = portrait ? 'portrait' : 'landscape';
    el.preview.classList.toggle('is-portrait', portrait);
    el.previewCanvas.width = portrait ? 720 : 1280;
    el.previewCanvas.height = portrait ? 1280 : 720;
    drawGuides();
  }

  // ── Guides de cadrage ─────────────────────────────────────────────────────

  function drawGuides() {
    const canvas = el.overlay;
    const rect = el.stage.getBoundingClientRect();
    if (!canvas || !rect.width) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    canvas.width = Math.round(rect.width * dpr);
    canvas.height = Math.round(rect.height * dpr);
    const ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const w = rect.width;
    const h = rect.height;
    ctx.clearRect(0, 0, w, h);
    if (!el.guides.checked) return;

    ctx.strokeStyle = 'rgba(255,255,255,.38)';
    ctx.lineWidth = 1;
    for (let i = 1; i <= 2; i++) {
      ctx.beginPath(); ctx.moveTo((w * i) / 3, 0); ctx.lineTo((w * i) / 3, h); ctx.stroke();
      ctx.beginPath(); ctx.moveTo(0, (h * i) / 3); ctx.lineTo(w, (h * i) / 3); ctx.stroke();
    }
    ctx.strokeStyle = 'rgba(255,255,255,.55)';
    ctx.beginPath(); ctx.moveTo(w / 2 - 12, h / 2); ctx.lineTo(w / 2 + 12, h / 2); ctx.stroke();
    ctx.beginPath(); ctx.moveTo(w / 2, h / 2 - 12); ctx.lineTo(w / 2, h / 2 + 12); ctx.stroke();

    ctx.strokeStyle = 'rgba(255,255,255,.22)';
    ctx.setLineDash([6, 6]);
    ctx.strokeRect(w * 0.06, h * 0.06, w * 0.88, h * 0.88);
    ctx.setLineDash([]);

    ctx.fillStyle = 'rgba(255,255,255,.55)';
    ctx.font = '600 12px system-ui, sans-serif';
    ctx.fillText(el.format.value === 'portrait' ? '9:16' : '16:9', w - 44, h - 12);
  }

  // ── Caméra, mesure du son et de la lumière ────────────────────────────────

  function cameraError(error) {
    const name = error && error.name ? error.name : '';
    if (name === 'NotAllowedError' || name === 'SecurityError') return 'Accès à la caméra refusé. Autorisez-la dans le navigateur, puis réessayez.';
    if (name === 'NotFoundError' || name === 'OverconstrainedError') return 'Aucune caméra compatible n’a été trouvée.';
    if (name === 'NotReadableError') return 'La caméra est occupée par une autre application.';
    return 'La caméra n’a pas pu démarrer. Vérifiez l’adresse sécurisée (https) et les autorisations.';
  }

  function updateFlipLabel() {
    if (!el.flipBtn) return;
    el.flipBtn.textContent = state.facing === 'user' ? 'Passer à la caméra arrière' : 'Passer à la caméra avant';
  }

  async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      notify('Ce navigateur ne donne pas accès à la caméra. Utilisez Chrome, Safari ou Firefox récent, sur une adresse https.', 'error');
      return false;
    }
    stopCamera();
    const portrait = el.format.value === 'portrait';
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true },
        video: {
          facingMode: state.facing,
          width: { ideal: portrait ? 1080 : 1920 },
          height: { ideal: portrait ? 1920 : 1080 },
        },
      });
      state.stream = stream;
      el.video.srcObject = stream;
      try { await el.video.play(); } catch (e) { /* autoplay muet */ }
      el.stage.classList.remove('is-off');
      el.record.disabled = false;
      el.camBtn.textContent = 'Couper la caméra';
      el.camnote.textContent = 'La caméra est active. La vidéo reste dans ce navigateur.';
      startMeters(stream);
      let inputs = 0;
      try {
        inputs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput').length;
      } catch (e) { inputs = 0; }
      // Sur téléphone, enumerateDevices() ne liste parfois qu'une entrée : on garde le bouton visible.
      const mobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent) || (navigator.maxTouchPoints || 0) > 1;
      el.flipBtn.hidden = !(inputs >= 2 || mobile);
      updateFlipLabel();
      drawGuides();
      return true;
    } catch (error) {
      notify(cameraError(error), 'error');
      el.stage.classList.add('is-off');
      return false;
    }
  }

  function stopCamera() {
    if (state.recording) stopRecording();
    if (state.stream) state.stream.getTracks().forEach((track) => track.stop());
    state.stream = null;
    el.video.srcObject = null;
    el.stage.classList.add('is-off');
    el.record.disabled = true;
    el.camBtn.textContent = 'Activer la caméra';
    el.flipBtn.hidden = true;
    el.flipBtn.disabled = false;
    el.camnote.textContent = 'La caméra ne fonctionne que sur une adresse sécurisée (https) et avec votre accord.';
    stopMeters();
  }

  function startMeters(stream) {
    stopMeters();
    state.lightCanvas = document.createElement('canvas');
    state.lightCanvas.width = 64;
    state.lightCanvas.height = 36;
    try {
      const Ctx = window.AudioContext || window.webkitAudioContext;
      const ctx = new Ctx();
      const source = ctx.createMediaStreamSource(stream);
      const analyser = ctx.createAnalyser();
      analyser.fftSize = 1024;
      source.connect(analyser);
      state.audioCtx = ctx;
      state.analyser = analyser;
      const buffer = new Uint8Array(analyser.fftSize);
      const loop = () => {
        analyser.getByteTimeDomainData(buffer);
        let sum = 0;
        for (let i = 0; i < buffer.length; i++) { const v = (buffer[i] - 128) / 128; sum += v * v; }
        const rms = Math.sqrt(sum / buffer.length);
        const db = 20 * Math.log10(rms || 1e-6);
        const level = clamp((db + 60) / 60 * 100, 0, 100);
        setMeter(el.meterAudio, level, db < -45 ? 'low' : db > -6 ? 'hot' : 'ok');
        el.audioNum.textContent = db < -60 ? '—' : Math.round(db) + ' dB';
        state.meterRaf = requestAnimationFrame(loop);
      };
      loop();
    } catch (e) {
      el.meterAudio.style.width = '0%';
      el.audioNum.textContent = '—';
    }
    state.lightTimer = setInterval(() => {
      const video = el.video;
      if (!video.videoWidth || !state.lightCanvas) return;
      const ctx = state.lightCanvas.getContext('2d', { willReadFrequently: true });
      ctx.drawImage(video, 0, 0, 64, 36);
      let data;
      try { data = ctx.getImageData(0, 0, 64, 36).data; } catch (e) { return; }
      let sum = 0;
      for (let i = 0; i < data.length; i += 4) sum += 0.2126 * data[i] + 0.7152 * data[i + 1] + 0.0722 * data[i + 2];
      const luma = sum / (data.length / 4);
      const level = clamp(luma / 255 * 100, 0, 100);
      setMeter(el.meterLight, level, luma < 55 ? 'low' : luma > 215 ? 'hot' : 'ok');
      el.lightNum.textContent = Math.round(level) + ' %';
    }, 450);
  }

  function stopMeters() {
    if (state.meterRaf) cancelAnimationFrame(state.meterRaf);
    state.meterRaf = 0;
    if (state.lightTimer) clearInterval(state.lightTimer);
    state.lightTimer = 0;
    if (state.audioCtx) { try { state.audioCtx.close(); } catch (e) {} }
    state.audioCtx = null;
    state.analyser = null;
    [el.meterAudio, el.meterLight].forEach((meter) => { meter.style.width = '0%'; meter.className = ''; });
    el.audioNum.textContent = '—';
    el.lightNum.textContent = '—';
  }

  function setMeter(node, level, kind) {
    if (!node) return;
    node.style.width = Math.round(level) + '%';
    node.className = kind === 'low' ? 'is-low' : kind === 'hot' ? 'is-hot' : '';
  }

  // ── Téléprompteur ────────────────────────────────────────────────────────

  function updateTeleText() {
    const index = Number(el.shotSelect.value) || 0;
    const shot = state.shots[index - 1];
    let text = '';
    if (shot) text = shot.line || shot.label;
    if (!text) text = el.script.value.trim();
    el.teleText.textContent = text || 'Le script est vide. Écrivez-le à l’étape « Préparer ».';
    el.tele.scrollTop = 0;
    if (state.teleRunning) {
      state.teleRunning = false;
      cancelAnimationFrame(state.teleRaf);
      el.telePlay.textContent = 'Défiler';
    }
  }

  function toggleTele() {
    const visible = el.teleToggle.checked;
    el.tele.hidden = !visible;
    el.telePlay.hidden = !visible;
    if (!visible) {
      state.teleRunning = false;
      cancelAnimationFrame(state.teleRaf);
      el.telePlay.textContent = 'Défiler';
      return;
    }
    updateTeleText();
  }

  function teleTick(timestamp) {
    if (!state.teleRunning) return;
    if (!state.teleLast) state.teleLast = timestamp;
    const dt = Math.min(0.1, (timestamp - state.teleLast) / 1000);
    state.teleLast = timestamp;
    el.tele.scrollTop += (Number(el.teleSpeed.value) || 30) * dt;
    if (el.tele.scrollTop + el.tele.clientHeight >= el.tele.scrollHeight - 2) {
      state.teleRunning = false;
      el.telePlay.textContent = 'Recommencer';
      return;
    }
    state.teleRaf = requestAnimationFrame(teleTick);
  }

  function toggleTeleRun() {
    if (state.teleRunning) {
      state.teleRunning = false;
      cancelAnimationFrame(state.teleRaf);
      el.telePlay.textContent = 'Défiler';
      return;
    }
    if (el.tele.scrollTop + el.tele.clientHeight >= el.tele.scrollHeight - 2) el.tele.scrollTop = 0;
    state.teleRunning = true;
    state.teleLast = 0;
    el.telePlay.textContent = 'Pause';
    state.teleRaf = requestAnimationFrame(teleTick);
  }

  // ── Enregistrement d'une prise ────────────────────────────────────────────

  function pickMime() {
    if (!window.MediaRecorder) return '';
    return MIME_CANDIDATES.find((type) => {
      try { return MediaRecorder.isTypeSupported(type); } catch (e) { return false; }
    }) || '';
  }

  function updateTimer() {
    if (!state.recording) return;
    const seconds = (performance.now() - state.recordStart) / 1000;
    el.timer.textContent = fmtClock(seconds);
    el.recTime.textContent = fmtClock(seconds);
  }

  async function startRecording() {
    if (!state.stream || state.recording) return;
    el.record.disabled = true;
    const count = Number(el.cd.value) || 0;
    for (let n = count; n > 0; n--) {
      el.countdown.hidden = false;
      el.countdown.textContent = String(n);
      await sleep(1000);
      if (!state.stream) { el.countdown.hidden = true; el.record.disabled = false; return; }
    }
    if (count > 0) {
      el.countdown.textContent = '●';
      await sleep(300);
    }
    el.countdown.hidden = true;

    const mime = pickMime();
    let recorder;
    try {
      recorder = mime ? new MediaRecorder(state.stream, { mimeType: mime, videoBitsPerSecond: 5000000 }) : new MediaRecorder(state.stream);
    } catch (error) {
      notify('L’enregistrement n’est pas disponible dans ce navigateur.', 'error');
      el.record.disabled = false;
      return;
    }
    state.chunks = [];
    state.recorder = recorder;
    recorder.ondataavailable = (event) => { if (event.data && event.data.size) state.chunks.push(event.data); };
    recorder.onstop = () => finishClip(recorder.mimeType || mime || 'video/webm');
    try {
      recorder.start(1000);
    } catch (error) {
      notify('L’enregistrement n’a pas pu démarrer.', 'error');
      el.record.disabled = false;
      return;
    }
    state.recording = true;
    state.recordStart = performance.now();
    el.flipBtn.disabled = true;
    el.record.hidden = true;
    el.record.disabled = false;
    el.stop.hidden = false;
    el.recflag.hidden = false;
    el.timer.textContent = '00:00';
    state.timer = setInterval(updateTimer, 200);
    updateTimer();
  }

  function stopRecording() {
    if (!state.recording) return;
    state.recording = false;
    el.flipBtn.disabled = false;
    clearInterval(state.timer);
    state.timer = 0;
    el.stop.hidden = true;
    el.record.hidden = false;
    el.record.disabled = false;
    el.recflag.hidden = true;
    el.timer.textContent = '00:00';
    if (state.recorder && state.recorder.state !== 'inactive') state.recorder.stop();
  }

  /** Durée réelle du fichier enregistré (Safari dépasse parfois le temps affiché au tournage). */
  async function measureSeconds(blob) {
    const url = URL.createObjectURL(blob);
    const video = document.createElement('video');
    video.preload = 'metadata';
    video.src = url;
    const duration = await new Promise((resolve) => {
      video.onloadedmetadata = () => resolve(video.duration);
      video.onerror = () => resolve(0);
      setTimeout(() => resolve(0), 2500);
    });
    URL.revokeObjectURL(url);
    return Number.isFinite(duration) && duration > 0.2 ? Math.round(duration * 10) / 10 : 0;
  }

  async function makeThumb(blob) {
    const video = document.createElement('video');
    const url = URL.createObjectURL(blob);
    video.src = url;
    video.muted = true;
    video.playsInline = true;
    try {
      await new Promise((resolve, reject) => {
        video.onloadeddata = resolve;
        video.onerror = () => reject(new Error('illisible'));
        setTimeout(resolve, 2500);
      });
      video.currentTime = Math.min(0.4, (video.duration || 1) / 2);
      await new Promise((resolve) => { video.onseeked = resolve; setTimeout(resolve, 600); });
      const width = 320;
      const height = Math.max(120, Math.round(width * (video.videoHeight / (video.videoWidth || 16 / 9)) || 180));
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = Math.min(420, height);
      canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
      return canvas.toDataURL('image/jpeg', 0.62);
    } catch (error) {
      return '';
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  async function finishClip(mime) {
    const blob = new Blob(state.chunks, { type: mime });
    state.chunks = [];
    state.recorder = null;
    if (!blob.size) {
      notify('La prise est vide. Vérifiez la caméra, puis réessayez.', 'error');
      return;
    }
    const seconds = (await measureSeconds(blob)) || Math.max(0.3, (performance.now() - state.recordStart) / 1000);
    const shot = Number(el.shotSelect.value) || 0;
    const shotInfo = state.shots[shot - 1];
    const clip = {
      id: uid(),
      project: String(state.projectId || 'draft'),
      shot,
      label: shotInfo ? shotInfo.label : '',
      seconds: Math.round(seconds * 10) / 10,
      mime: blob.type || mime,
      created_at: new Date().toISOString(),
      bytes: new Uint8Array(await blob.arrayBuffer()),
      thumb: '',
    };
    try { clip.thumb = await makeThumb(blob); } catch (e) { /* vignette facultative */ }
    try {
      await dbPut(clip);
    } catch (error) {
      notify('La prise n’a pas pu être rangée dans le navigateur : ' + ((error && error.message) || 'stockage plein.'), 'error');
      return;
    }
    state.clips.push(clip);
    assignRecordedClip(clip);
    renderClips();
    renderShotSelect();
    renderTimeline();
    notify('Prise enregistrée (' + fmtSeconds(clip.seconds) + '). Vous pouvez la refaire ou passer au plan suivant.', 'ok');
    saveProject(false);
  }

  function assignRecordedClip(clip) {
    const existing = clip.shot > 0
      ? state.plan.find((entry) => entry.shot === clip.shot)
      : state.plan.find((entry) => entry.clip === clip.id);
    if (clip.shot > 0) {
      if (existing) {
        existing.clip = clip.id;
        existing.in = 0;
        existing.out = clip.seconds;
      } else {
        state.plan.push({ shot: clip.shot, clip: clip.id, in: 0, out: clip.seconds, title: '' });
      }
      state.plan.sort((a, b) => a.shot - b.shot);
      return;
    }
    if (!state.shots.length && !existing) {
      state.plan.push({ shot: 0, clip: clip.id, in: 0, out: clip.seconds, title: '' });
    }
  }

  function downloadClip(clip) {
    const url = URL.createObjectURL(clipBlob(clip));
    const link = document.createElement('a');
    const extension = (clip.mime || '').includes('mp4') ? 'mp4' : 'webm';
    link.href = url;
    link.download = 'studio-' + slug(el.title.value || 'prise') + '-' + String(clip.created_at).slice(5, 10) + '.' + extension;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
  }

  // ── Montage et export ────────────────────────────────────────────────────

  function segmentsFromPlan() {
    const segments = [];
    timelineRows().forEach((row) => {
      const entry = findEntry(row);
      if (!entry) return;
      const clip = state.clips.find((c) => c.id === entry.clip);
      if (!clip) return;
      const inValue = clamp(Number(entry.in) || 0, 0, Math.max(0, clip.seconds - 0.2));
      const outValue = clamp(Number(entry.out) || clip.seconds, inValue + 0.2, clip.seconds);
      segments.push({ clip, in: inValue, out: outValue, title: entry.title || '' });
    });
    return segments;
  }

  function pickMovieMime() {
    return MIME_CANDIDATES.find((type) => {
      try { return window.MediaRecorder && MediaRecorder.isTypeSupported(type); } catch (e) { return false; }
    }) || '';
  }

  function seek(video, seconds) {
    return new Promise((resolve) => {
      if (Math.abs(video.currentTime - seconds) < 0.05 && video.readyState >= 2) { resolve(); return; }
      let done = false;
      const finish = () => {
        if (done) return;
        done = true;
        video.removeEventListener('seeked', finish);
        resolve();
      };
      video.addEventListener('seeked', finish);
      try { video.currentTime = seconds; } catch (e) { finish(); }
      setTimeout(finish, 2000);
    });
  }

  function roundRect(ctx, x, y, w, h, radius) {
    const r = Math.min(radius, h / 2, w / 2);
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.arcTo(x + w, y, x + w, y + h, r);
    ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r);
    ctx.arcTo(x, y, x + w, y, r);
    ctx.closePath();
  }

  function wrapLines(ctx, text, maxWidth) {
    const words = text.split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';
    words.forEach((word) => {
      const test = line ? line + ' ' + word : word;
      if (ctx.measureText(test).width > maxWidth && line) {
        lines.push(line);
        line = word;
      } else {
        line = test;
      }
    });
    if (line) lines.push(line);
    return lines.slice(0, 3);
  }

  function drawTitle(ctx, width, height, title) {
    const size = Math.round(width * 0.034);
    const pad = Math.round(width * 0.05);
    ctx.save();
    ctx.font = '700 ' + size + 'px Archivo, system-ui, sans-serif';
    const lines = wrapLines(ctx, title, width - pad * 2);
    const lineHeight = Math.round(size * 1.25);
    const boxHeight = lines.length * lineHeight + Math.round(size * 0.7);
    const y = height - pad - boxHeight;
    ctx.fillStyle = 'rgba(0,0,0,.58)';
    roundRect(ctx, pad - Math.round(size * 0.4), y, width - pad * 2 + Math.round(size * 0.8), boxHeight, Math.round(size * 0.35));
    ctx.fill();
    ctx.fillStyle = '#fff';
    ctx.textBaseline = 'top';
    lines.forEach((line, index) => {
      ctx.fillText(line, pad, y + Math.round(size * 0.35) + index * lineHeight);
    });
    ctx.restore();
  }

  function drawMovieFrame(ctx, video, width, height, title, elapsed, total, fade) {
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);
    if (video.videoWidth && video.videoHeight) {
      const scale = Math.max(width / video.videoWidth, height / video.videoHeight);
      const drawWidth = video.videoWidth * scale;
      const drawHeight = video.videoHeight * scale;
      ctx.drawImage(video, (width - drawWidth) / 2, (height - drawHeight) / 2, drawWidth, drawHeight);
    }
    if (title) drawTitle(ctx, width, height, title);
    if (fade) {
      const alpha = Math.max(1 - Math.min(elapsed, Math.max(0, total - elapsed)) / 0.5, 0);
      if (alpha > 0) {
        ctx.fillStyle = 'rgba(0,0,0,' + alpha.toFixed(3) + ')';
        ctx.fillRect(0, 0, width, height);
      }
    }
  }

  function setProgress(ratio) {
    if (!el.progressBar) return;
    el.progressBar.style.width = Math.round(clamp(ratio, 0, 1) * 100) + '%';
  }

  function paintPoster() {
    const ctx = el.previewCanvas.getContext('2d');
    const width = el.previewCanvas.width;
    const height = el.previewCanvas.height;
    ctx.fillStyle = '#0b0a08';
    ctx.fillRect(0, 0, width, height);
    ctx.fillStyle = 'rgba(255,255,255,.5)';
    ctx.font = '600 ' + Math.round(width * 0.03) + 'px system-ui, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText('Appuyez sur « Lire » pour voir le montage', width / 2, height / 2);
    ctx.textAlign = 'start';
  }

  async function renderMovie(segments, mode) {
    const portrait = el.format.value === 'portrait';
    const width = portrait ? 720 : 1280;
    const height = portrait ? 1280 : 720;
    const canvas = mode === 'preview' ? el.previewCanvas : document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    if (mode === 'export' && !canvas.captureStream) {
      throw new Error('Ce navigateur ne sait pas exporter la vidéo. Essayez Chrome ou Firefox récent.');
    }

    state.running = true;
    state.abort = false;
    const urls = [];
    const videos = [];
    let audioCtx = null;
    let destination = null;
    let recorder = null;
    let stopped = null;
    let capture = null;

    try {
      try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        audioCtx = new Ctx();
        destination = audioCtx.createMediaStreamDestination();
        if (audioCtx.state === 'suspended') await audioCtx.resume();
      } catch (e) {
        audioCtx = null;
        destination = null;
      }

      for (const segment of segments) {
        const video = document.createElement('video');
        const url = URL.createObjectURL(clipBlob(segment.clip));
        urls.push(url);
        video.src = url;
        video.playsInline = true;
        video.preload = 'auto';
        await new Promise((resolve, reject) => {
          video.onloadedmetadata = resolve;
          video.onerror = () => reject(new Error('Une prise est illisible. Réenregistrez-la.'));
          setTimeout(resolve, 6000);
        });
        if (audioCtx) {
          try {
            const source = audioCtx.createMediaElementSource(video);
            source.connect(destination);
            const speaker = audioCtx.createGain();
            speaker.gain.value = mode === 'export' ? 0 : 1;
            source.connect(speaker);
            speaker.connect(audioCtx.destination);
          } catch (e) { /* prise sans son exploitable */ }
        }
        videos.push(video);
      }

      if (mode === 'export') {
        capture = canvas.captureStream(30);
        if (destination) {
          const audioTrack = destination.stream.getAudioTracks()[0];
          if (audioTrack) capture.addTrack(audioTrack);
        }
        const mime = pickMovieMime();
        const options = { videoBitsPerSecond: 6000000 };
        if (mime) options.mimeType = mime;
        recorder = new MediaRecorder(capture, options);
        const chunks = [];
        recorder.ondataavailable = (event) => { if (event.data && event.data.size) chunks.push(event.data); };
        stopped = new Promise((resolve) => {
          recorder.onstop = () => resolve(new Blob(chunks, { type: recorder.mimeType || 'video/webm' }));
        });
      }

      const total = segments.reduce((sum, segment) => sum + (segment.out - segment.in), 0);
      const fade = el.fade.checked;
      let elapsed = 0;
      let recording = false;

      for (let i = 0; i < segments.length; i++) {
        if (state.abort) break;
        const segment = segments[i];
        const video = videos[i];
        await seek(video, segment.in);
        if (recorder && !recording) {
          // La première image du plan est affichée avant le démarrage : sur Safari,
          // l'initialisation de l'encodeur prenait parfois près d'une seconde et les
          // images dessinées entre-temps étaient perdues, le fichier commençant après
          // le début du plan.
          drawMovieFrame(ctx, video, width, height, segment.title, elapsed, total, fade);
          await new Promise((resolve) => {
            let done = false;
            const go = () => { if (!done) { done = true; resolve(); } };
            recorder.onstart = go;
            setTimeout(go, 600);
            recorder.start(500);
          });
          recording = true;
        }
        try { await video.play(); } catch (e) { /* lecture bloquée : le minuteur de secours prend le relais */ }
        await new Promise((resolve) => {
          const tick = () => {
            if (state.abort) { resolve(); return; }
            const position = elapsed + Math.max(0, video.currentTime - segment.in);
            drawMovieFrame(ctx, video, width, height, segment.title, position, total, fade);
            if (mode === 'export') setProgress(position / total);
            if (video.currentTime >= segment.out - 0.03 || video.ended) {
              video.pause();
              resolve();
              return;
            }
            requestAnimationFrame(tick);
          };
          requestAnimationFrame(tick);
          setTimeout(() => {
            if (video.paused && video.currentTime < segment.out - 0.05) resolve();
          }, (segment.out - segment.in) * 1000 + 4000);
        });
        elapsed += segment.out - segment.in;
        if (mode === 'export') setProgress(elapsed / total);
      }

      if (state.abort) {
        if (recorder && recorder.state !== 'inactive') recorder.stop();
        return null;
      }

      if (recorder) {
        if (recorder.state !== 'inactive') recorder.stop();
        const blob = await stopped;
        return blob && blob.size ? blob : null;
      }
      return null;
    } finally {
      state.running = false;
      videos.forEach((video) => {
        try { video.pause(); } catch (e) {}
        video.removeAttribute('src');
        try { video.load(); } catch (e) {}
      });
      urls.forEach((url) => URL.revokeObjectURL(url));
      if (capture) capture.getTracks().forEach((track) => track.stop());
      if (audioCtx) { try { audioCtx.close(); } catch (e) {} }
    }
  }

  async function previewMovie() {
    if (state.running) return;
    const segments = segmentsFromPlan();
    if (!segments.length) { notify('Choisissez au moins une prise dans le montage.', 'error'); return; }
    el.previewBtn.disabled = true;
    el.exportBtn.disabled = true;
    el.cancel.hidden = false;
    try {
      await renderMovie(segments, 'preview');
      if (state.abort) notify('Lecture arrêtée.', 'info');
    } catch (error) {
      notify(error.message || 'La lecture a échoué.', 'error');
    } finally {
      el.previewBtn.disabled = false;
      el.exportBtn.disabled = false;
      el.cancel.hidden = true;
    }
  }

  function showDownload(blob) {
    if (state.downloading) URL.revokeObjectURL(state.downloading);
    const url = URL.createObjectURL(blob);
    state.downloading = url;
    const extension = blob.type.includes('mp4') ? 'mp4' : 'webm';
    const name = 'studio-' + slug(el.title.value || 'video') + '-' + new Date().toISOString().slice(0, 10) + '.' + extension;
    el.download.innerHTML = '';
    const link = document.createElement('a');
    link.href = url;
    link.download = name;
    link.textContent = 'Télécharger la vidéo (' + fmtBytes(blob.size) + ')';
    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    video.playsInline = true;
    el.download.appendChild(link);
    el.download.appendChild(video);
    el.exportNote.textContent = 'Fichier prêt. Vérifiez-le en entier avant de le publier.';
  }

  async function exportMovie() {
    if (state.running) return;
    const segments = segmentsFromPlan();
    if (!segments.length) { notify('Choisissez au moins une prise dans le montage.', 'error'); return; }
    state.exporting = true;
    el.exportBtn.disabled = true;
    el.previewBtn.disabled = true;
    el.cancel.hidden = false;
    el.progress.hidden = false;
    setProgress(0);
    el.exportNote.textContent = 'Export en cours : laissez cet onglet ouvert jusqu’au bout.';
    try {
      const blob = await renderMovie(segments, 'export');
      if (!blob) {
        notify('Export arrêté.', 'info');
        el.exportNote.textContent = 'L’export a été arrêté avant la fin.';
        return;
      }
      showDownload(blob);
      notify('Vidéo exportée.', 'ok');
    } catch (error) {
      notify(error.message || 'L’export a échoué.', 'error');
      el.exportNote.textContent = 'L’export a échoué. Réessayez avec un autre navigateur si le problème persiste.';
    } finally {
      state.exporting = false;
      el.exportBtn.disabled = false;
      el.previewBtn.disabled = false;
      el.cancel.hidden = true;
      el.progress.hidden = true;
      setProgress(0);
    }
  }

  function cancelMovie() {
    if (!state.running) return;
    state.abort = true;
    notify('Arrêt demandé…', 'busy');
  }

  function autoPlan() {
    const rows = timelineRows();
    if (!rows.length) { notify('Tournez d’abord au moins une prise.', 'error'); return; }
    const plan = [];
    rows.forEach((row) => {
      const candidates = row.own
        ? state.clips.filter((clip) => clip.id === row.own)
        : state.clips.filter((clip) => clip.shot === row.shot);
      if (!candidates.length) return;
      const clip = candidates[candidates.length - 1];
      const target = Number(row.target) || clip.seconds;
      const out = clamp(Math.min(clip.seconds, target + 0.5), 0.3, clip.seconds);
      const existing = findEntry(row);
      plan.push({ shot: row.shot, clip: clip.id, in: 0, out: Math.round(out * 10) / 10, title: existing ? existing.title : '' });
    });
    state.plan = plan;
    renderTimeline();
    saveProject(false);
    notify('Montage dans l’ordre des plans. Réglez les coupes, puis lisez le résultat.', 'ok');
  }

  async function askScript() {
    const has = el.title.value.trim() || el.brief.value.trim();
    if (!has) {
      notify('Écrivez au moins le titre ou ce que vous voulez montrer.', 'error');
      el.title.focus();
      return;
    }
    el.aiScript.disabled = true;
    el.aiScript.textContent = 'L’IA écrit…';
    notify('L’IA prépare le script et les plans. Cela prend quelques secondes.', 'busy');
    try {
      await saveProject(true);
      const result = await request(API_AI, 'POST', { action: 'script', project: collectProject() });
      const data = result.data || {};
      if (data.title) el.title.value = data.title;
      if (data.script) el.script.value = data.script;
      state.shots = Array.isArray(data.shots) ? data.shots : [];
      state.titles = Array.isArray(data.titles) ? data.titles : [];
      state.checklist = Array.isArray(data.checklist) ? data.checklist : [];
      state.hashtags = Array.isArray(data.hashtags) ? data.hashtags : [];
      state.plan = [];
      renderAll();
      await saveProject(true);
      notify('Script prêt. Relisez-le à voix haute, corrigez ce qui sonne faux, puis passez au tournage.', 'ok');
    } catch (error) {
      notify(error.message, 'error');
    } finally {
      el.aiScript.disabled = false;
      el.aiScript.textContent = 'Écrire le script avec l’IA';
    }
  }

  async function askPlan() {
    if (!state.clips.length) {
      notify('Tournez d’abord au moins une prise.', 'error');
      return;
    }
    el.aiPlan.disabled = true;
    el.aiPlan.textContent = 'L’IA prépare le montage…';
    notify('L’IA compare les prises et prépare les coupes.', 'busy');
    try {
      await saveProject(true);
      const clips = state.clips.map((clip) => ({ id: clip.id, shot: clip.shot, seconds: clip.seconds, label: clip.label || '' }));
      const result = await request(API_AI, 'POST', { action: 'plan', project: collectProject(), shots: state.shots, clips });
      state.plan = (Array.isArray(result.data.plan) ? result.data.plan : []).map((entry) => {
        const clip = state.clips.find((c) => c.id === entry.clip);
        return clip && clip.shot > 0 ? Object.assign({}, entry, { shot: clip.shot }) : entry;
      });
      renderTimeline();
      await saveProject(true);
      notify(result.data.notes || 'Montage proposé. Vérifiez les coupes et lisez le résultat.', 'ok');
      goTo('edit');
    } catch (error) {
      notify(error.message, 'error');
    } finally {
      el.aiPlan.disabled = false;
      el.aiPlan.textContent = 'Proposer un montage';
    }
  }

  // ── Événements ───────────────────────────────────────────────────────────

  function bind() {
    el.tabs.forEach((tab) => tab.addEventListener('click', () => goTo(tab.dataset.step)));
    el.project.addEventListener('change', () => { openProject(el.project.value); });
    el.newBtn.addEventListener('click', () => newProject());
    el.deleteBtn.addEventListener('click', deleteProject);
    el.saveBtn.addEventListener('click', () => saveProject(true));

    [el.title, el.brief, el.audience, el.script].forEach((field) => {
      field.addEventListener('input', () => {
        renderMeta();
        if (field === el.script) {
          updateShotHint();
          updateTeleText();
        }
        saveProject(false);
      });
    });
    [el.kind, el.seconds, el.tone].forEach((field) => {
      field.addEventListener('change', () => { renderMeta(); updatePlanMeta(); saveProject(false); });
    });
    el.format.addEventListener('change', () => {
      applyFormat();
      updateShotHint();
      saveProject(false);
    });

    el.addShot.addEventListener('click', () => {
      state.shots.push({ label: 'Plan ' + (state.shots.length + 1), framing: 'plan moyen', seconds: 6, line: '', tip: '' });
      renderShots();
      renderShotSelect();
      renderTimeline();
      saveProject(false);
    });
    el.clearShots.addEventListener('click', () => {
      if (!state.shots.length) return;
      if (!confirm('Vider la liste des plans ? Le script est conservé.')) return;
      state.shots = [];
      state.plan = [];
      renderShots();
      renderShotSelect();
      renderTimeline();
      saveProject(false);
    });

    el.shots.addEventListener('click', (event) => {
      const button = event.target.closest('button[data-act]');
      const card = event.target.closest('.stv-shot');
      if (!button || !card) return;
      const index = Number(card.dataset.index);
      const shot = state.shots[index];
      if (!shot) return;
      const action = button.dataset.act;
      if (action === 'edit') {
        const box = $('.stv-shot-edit', card);
        if (box) box.hidden = !box.hidden;
        return;
      }
      if (action === 'shoot') {
        el.shotSelect.value = String(index + 1);
        updateShotHint();
        updateTeleText();
        goTo('shoot');
        el.stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      }
      if (action === 'up' && index > 0) swapShots(index, index - 1);
      if (action === 'down' && index < state.shots.length - 1) swapShots(index, index + 1);
      if (action === 'del') {
        if (!confirm('Supprimer ce plan de la liste ?')) return;
        const removedShot = index + 1;
        state.shots.splice(index, 1);
        state.plan = state.plan.filter((entry) => entry.shot !== removedShot)
          .map((entry) => (entry.shot > removedShot ? Object.assign({}, entry, { shot: entry.shot - 1 }) : entry));
        renderShots();
        renderShotSelect();
        renderTimeline();
        saveProject(false);
      }
    });

    el.shots.addEventListener('input', (event) => {
      const field = event.target.closest('[data-field]');
      const card = event.target.closest('.stv-shot');
      if (!field || !card) return;
      const index = Number(card.dataset.index);
      const shot = state.shots[index];
      if (!shot) return;
      const key = field.dataset.field;
      shot[key] = key === 'seconds' ? clamp(Number(field.value) || 6, 2, 20) : field.value;
      const head = $('[data-role="shot-label"]', card);
      if (head && key === 'label') head.textContent = field.value;
      const meta = $('.stv-shot-head span', card);
      if (meta) meta.textContent = shot.framing + ' · ' + fmtSeconds(shot.seconds);
      if (key !== 'tip' && key !== 'label') updateShotHint();
      saveProject(false);
    });

    function swapShots(from, to) {
      const moved = state.shots[from];
      state.shots[from] = state.shots[to];
      state.shots[to] = moved;
      const entryA = state.plan.find((entry) => entry.shot === from + 1);
      const entryB = state.plan.find((entry) => entry.shot === to + 1);
      if (entryA) entryA.shot = to + 1;
      if (entryB) entryB.shot = from + 1;
      state.plan.sort((a, b) => a.shot - b.shot);
      renderShots();
      renderShotSelect();
      renderTimeline();
      saveProject(false);
    }

    el.camBtn.addEventListener('click', () => { if (state.stream) stopCamera(); else startCamera(); });
    el.flipBtn.addEventListener('click', async () => {
      if (state.recording) return;
      const previous = state.facing;
      state.facing = previous === 'user' ? 'environment' : 'user';
      el.flipBtn.disabled = true;
      const ok = await startCamera();
      if (!ok) {
        state.facing = previous;
        if (await startCamera()) notify('Une seule caméra est disponible sur cet appareil.', 'error');
      }
      el.flipBtn.disabled = false;
    });
    el.guides.addEventListener('change', drawGuides);
    el.mirror.addEventListener('change', () => el.stage.classList.toggle('is-mirror', el.mirror.checked));
    el.teleToggle.addEventListener('change', toggleTele);
    el.telePlay.addEventListener('click', toggleTeleRun);
    el.teleSpeed.addEventListener('input', () => { state.teleLast = 0; });
    el.teleSize.addEventListener('input', () => {
      el.teleText.style.fontSize = (Number(el.teleSize.value) || 28) + 'px';
    });
    el.shotSelect.addEventListener('change', () => { updateShotHint(); updateTeleText(); });

    el.record.addEventListener('click', startRecording);
    el.stop.addEventListener('click', stopRecording);

    el.clips.addEventListener('click', async (event) => {
      const button = event.target.closest('button[data-act]');
      const card = event.target.closest('.stv-clip');
      if (!button || !card) return;
      const clip = state.clips.find((c) => String(c.id) === card.dataset.id);
      if (!clip) return;
      const action = button.dataset.act;
      if (action === 'download') { downloadClip(clip); return; }
      if (action === 'use') {
        const rows = timelineRows();
        let row = rows.find((r) => (r.own ? r.own === clip.id : r.shot === clip.shot));
        if (!row && state.shots.length) row = rows.find((r) => !findEntry(r)) || rows[0];
        if (!row) { notify('Ajoutez d’abord un plan ou tournez une prise.', 'error'); return; }
        if (row.shot > 0 && clip.shot > 0 && row.shot !== clip.shot) {
          notify('Cette prise a été tournée pour le plan ' + clip.shot + ' : elle est placée dans le plan ' + row.shot + '.', 'info');
        }
        assignClip(row, clip.id);
        goTo('edit');
        return;
      }
      if (action === 'redo') {
        if (!confirm('Supprimer cette prise et la refaire ?')) return;
        state.plan = state.plan.filter((entry) => entry.clip !== clip.id);
        await dbDelete(clip.id);
        state.clips = state.clips.filter((c) => c.id !== clip.id);
        if (clip.shot > 0) el.shotSelect.value = String(clip.shot);
        renderClips();
        renderTimeline();
        goTo('shoot');
        return;
      }
      if (action === 'del') {
        if (!confirm('Supprimer cette prise ?')) return;
        state.plan = state.plan.filter((entry) => entry.clip !== clip.id);
        await dbDelete(clip.id);
        state.clips = state.clips.filter((c) => c.id !== clip.id);
        renderClips();
        renderTimeline();
        saveProject(false);
      }
    });

    function assignClip(row, clipId) {
      const clip = state.clips.find((c) => c.id === clipId);
      if (!clip) return;
      let entry = findEntry(row);
      if (!entry) {
        entry = { shot: row.shot, clip: clipId, in: 0, out: clip.seconds, title: '' };
        state.plan.push(entry);
      } else {
        entry.clip = clipId;
        entry.in = 0;
        entry.out = clip.seconds;
      }
      state.plan.sort((a, b) => a.shot - b.shot);
      renderTimeline();
      saveProject(false);
    }

    el.timeline.addEventListener('change', (event) => {
      const cut = event.target.closest('.stv-cut');
      if (!cut) return;
      const row = timelineRows().find((r) => r.key === cut.dataset.key);
      if (!row) return;
      const select = event.target.closest('[data-role="clip"]');
      if (select) {
        if (!select.value) {
          state.plan = state.plan.filter((entry) => entry !== findEntry(row));
        } else {
          assignClip(row, select.value);
        }
        renderTimeline();
        saveProject(false);
      }
      const title = event.target.closest('[data-role="title"]');
      if (title) {
        const entry = findEntry(row);
        if (entry) { entry.title = title.value; saveProject(false); }
      }
    });

    el.timeline.addEventListener('input', (event) => {
      const cut = event.target.closest('.stv-cut');
      if (!cut) return;
      const row = timelineRows().find((r) => r.key === cut.dataset.key);
      const entry = row ? findEntry(row) : null;
      if (!entry) return;
      const clip = state.clips.find((c) => c.id === entry.clip);
      if (!clip) return;
      const inInput = event.target.closest('[data-role="in"]');
      const outInput = event.target.closest('[data-role="out"]');
      if (inInput) entry.in = clamp(Number(inInput.value), 0, Math.max(0, clip.seconds - 0.2));
      if (outInput) entry.out = clamp(Number(outInput.value), entry.in + 0.2, clip.seconds);
      if (entry.out <= entry.in) entry.out = Math.min(clip.seconds, entry.in + 0.2);
      const inNode = $('[data-role="in"]', cut);
      const outNode = $('[data-role="out"]', cut);
      if (inNode) inNode.value = String(entry.in);
      if (outNode) outNode.value = String(entry.out);
      const label = $('[data-role="range-label"]', cut);
      if (label) label.textContent = entry.in.toFixed(1).replace('.', ',') + ' → ' + entry.out.toFixed(1).replace('.', ',') + ' s';
      updatePlanMeta();
      saveProject(false);
    });

    el.timeline.addEventListener('click', (event) => {
      const move = event.target.closest('button[data-move]');
      if (!move) return;
      const cut = move.closest('.stv-cut');
      const row = timelineRows().find((r) => r.key === cut.dataset.key);
      if (!row) return;
      const direction = Number(move.dataset.move);
      if (direction === 0) {
        const entry = findEntry(row);
        if (entry) state.plan = state.plan.filter((item) => item !== entry);
        renderTimeline();
        saveProject(false);
        return;
      }
      const rows = timelineRows();
      const index = rows.findIndex((r) => r.key === row.key);
      const other = rows[index + direction];
      if (!other) return;
      if (state.shots.length) {
        const a = state.shots[index];
        state.shots[index] = state.shots[index + direction];
        state.shots[index + direction] = a;
        const entryA = state.plan.find((entry) => entry.shot === row.shot);
        const entryB = state.plan.find((entry) => entry.shot === other.shot);
        if (entryA) entryA.shot = other.shot;
        if (entryB) entryB.shot = row.shot;
        state.plan.sort((a, b) => a.shot - b.shot);
        renderShots();
        renderShotSelect();
      }
      renderTimeline();
      saveProject(false);
    });

    el.autoPlan.addEventListener('click', autoPlan);
    el.aiPlan.addEventListener('click', askPlan);
    el.aiScript.addEventListener('click', askScript);
    el.previewBtn.addEventListener('click', previewMovie);
    el.exportBtn.addEventListener('click', exportMovie);
    el.cancel.addEventListener('click', cancelMovie);

    window.addEventListener('resize', drawGuides);
    if (window.ResizeObserver) new ResizeObserver(drawGuides).observe(el.stage);
    window.addEventListener('pagehide', () => { stopCamera(); state.abort = true; });
    window.addEventListener('beforeunload', (event) => {
      if (state.recording || state.running) {
        event.preventDefault();
        event.returnValue = '';
      }
    });
  }

  async function init() {
    bind();
    paintPoster();
    el.teleText.style.fontSize = (Number(el.teleSize.value) || 28) + 'px';
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      el.camBtn.disabled = true;
      el.camnote.textContent = 'Ce navigateur ne donne pas accès à la caméra. Utilisez Chrome, Safari ou Firefox récent, sur une adresse https.';
    }
    if (!window.MediaRecorder) {
      el.record.disabled = true;
      el.camnote.textContent = 'Ce navigateur ne sait pas enregistrer la vidéo. Utilisez Chrome, Safari ou Firefox récent.';
    }
    await refreshProjects('');
    const first = Array.from(el.project.options).find((option) => option.value);
    if (first) {
      await openProject(first.value);
    } else {
      newProject();
    }
    renderMeta();
  }

  init();
})();
