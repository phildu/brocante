/**
 * ELECTROBOY 80 — Vidéo IA (Éditeur Visuel → mode Vidéo → « Vidéo IA »)
 *
 * Fenêtre qui compose une vidéo promo Remotion : infos du concert (reprises de l'affiche
 * ouverte ou d'un événement), durée, scénario scène par scène (écrit par Claude ou à la main,
 * tous les textes modifiables), aperçu en direct, puis demande de rendu MP4 à l'agent.
 *   window.EB80VideoIA.ouvrir()
 * Le lecteur (eb80-video-player.js, ~730 Ko) n'est chargé qu'à la première ouverture.
 * API : api/video-jobs.php, api/video-jobs-ai.php.
 */
(() => {
  const T = window.EB80_VIA_TEXTES || {}; // textes de la boutique (eb80-shim.js), sinon ceux d'electroboy80
  const BASE = 'js/video-promo/';
  // À changer à chaque mise à jour du lecteur (et du ?v= de ce script dans admin.html),
  // sinon le navigateur garde l'ancienne version en cache.
  const VERSION = '20261018b';
  // Version de rendu attendue de l'agent (VERSION_RENDU de scripts/rendu-worker.mjs) :
  // un agent plus ancien ignore les réglages récents (animations, palette, recadrage).
  const VERSION_AGENT = 9;
  const CLE_BROUILLON = 'eb80_video_ia_brouillon';

  const CSS = `
.via{border:2px solid var(--yellow,#ff0);background:var(--mid);color:var(--white);padding:0;width:min(1280px,96vw);max-height:94vh;font-family:'IBM Plex Mono',monospace;font-size:12px;}
.via::backdrop{background:color-mix(in srgb, var(--black) 96%, transparent);}
.via [hidden]{display:none!important;}
.via *{box-sizing:border-box;}
.via-top{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-bottom:2px solid var(--border);background:var(--mid);position:sticky;top:0;z-index:2;}
.via-top h2{font-family:'Anton',sans-serif;font-weight:400;font-size:20px;letter-spacing:-.5px;margin:0;}
.via-top h2 span{color:#FF006E;}
.via-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,400px);gap:18px;padding:18px;overflow:auto;max-height:calc(94vh - 56px);}
@media (max-width:900px){.via-body{grid-template-columns:1fr;}}
.via-sec{background:var(--mid);border:1px solid var(--border);padding:14px;margin-bottom:14px;}
.via-sec h3{font-family:'VT323',monospace;font-size:18px;letter-spacing:3px;color:#ff0;font-weight:400;margin:0 0 8px;text-transform:uppercase;}
.via label{display:block;font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--muted);margin:8px 0 3px;}
.via input[type=text],.via select,.via textarea{width:100%;background:var(--mid);border:1px solid var(--border);color:var(--white);font-family:inherit;font-size:12px;padding:7px 9px;}
.via textarea{resize:vertical;min-height:54px;}
.via input:focus,.via select:focus,.via textarea:focus{outline:none;border-color:#ff0;}
.via-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.via-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.via-hint{font-size:10px;color:var(--muted);margin-top:5px;line-height:1.5;}
.via-btn{background:transparent;border:2px solid var(--border);color:var(--white);font-family:'VT323',monospace;font-size:16px;letter-spacing:2px;padding:5px 12px;cursor:pointer;text-transform:uppercase;}
.via-btn:hover{border-color:#ff0;color:#ff0;}
.via-btn.ia{background:#00FFDD;border-color:#00FFDD;color:#000;}
.via-btn.go{background:#FF006E;border-color:#FF006E;color:#000;width:100%;}
.via-btn.ia:hover,.via-btn.go:hover{background:#ff0;border-color:#ff0;color:#000;}
.via-btn:disabled{opacity:.4;cursor:not-allowed;}
.via-btn.sm{font-size:13px;padding:2px 7px;}
.via-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(84px,1fr));gap:6px;max-height:300px;overflow:auto;padding:2px;}
.via-tile{position:relative;aspect-ratio:1;background:var(--mid) center/cover no-repeat;border:2px solid transparent;cursor:pointer;}
.via-tile:hover{border-color:#777;}
.via-tile.on{border-color:#FF006E;}
.via-tile .n{position:absolute;top:3px;left:3px;background:#FF006E;color:#000;font-family:'VT323',monospace;font-size:16px;line-height:1;padding:1px 5px;}
.via-chips{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0;}
.via-chip{display:flex;align-items:center;gap:4px;background:var(--mid);border:1px solid var(--border);padding:3px;}
.via-chip img{width:40px;height:40px;object-fit:cover;display:block;}
.via-slot{display:flex;align-items:center;gap:8px;margin-top:6px;}
.via-slot .t{width:58px;height:36px;background:#555 center/contain no-repeat;border:1px solid var(--border);flex-shrink:0;}
.via-ia-msg{margin-top:8px;font-size:11px;line-height:1.5;color:#00FFDD;}
.via-ia-msg.err{color:#FF006E;}
.via-variantes{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:10px;}
.via-variante{background:var(--mid);border:1px solid var(--border);color:var(--white);font-family:inherit;font-size:11px;text-align:left;padding:8px 10px;cursor:pointer;line-height:1.45;}
.via-variante b{display:block;font-family:'VT323',monospace;font-size:18px;font-weight:400;letter-spacing:2px;color:#ff0;text-transform:uppercase;}
.via-variante:hover{border-color:#ff0;}
.via-variante.on{border-color:#00FFDD;box-shadow:inset 0 0 0 1px #00FFDD;}
.via-apercu{background:var(--mid);border:1px solid var(--border);margin:10px auto;position:relative;}
.via-safe-canvas{position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:2;}
.via-zone-btn.on{border-color:#ff0;color:#ff0;}
.via-formats{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:center;}
.via-formats label{display:inline-flex;gap:5px;align-items:center;color:var(--white);margin:0;font-size:11px;letter-spacing:1px;text-transform:none;}
.via-formats .x{background:transparent;border:none;color:var(--muted);cursor:pointer;font-size:11px;padding:0 2px;}
.via-formats .x:hover{color:#FF006E;}
.via-lot{margin-top:8px;padding:8px 10px;background:var(--mid);border:1px solid #252525;}
.via-lot-titre{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.via-lot-formats{display:flex;flex-wrap:wrap;gap:4px 12px;}
.via-lot-formats label{display:inline-flex;gap:5px;align-items:center;color:#bbb;margin:0;font-size:11px;cursor:pointer;}
.via-lot-formats label:hover{color:var(--white);}
.via-lot-formats input[type=checkbox]{accent-color:#FF006E;width:12px;height:12px;cursor:pointer;}
.via-jobs{list-style:none;margin:0;padding:0;}
.via-jobs li{border-top:1px solid var(--border);padding:8px 0;display:flex;justify-content:space-between;gap:8px;align-items:center;}
.via-st{font-family:'VT323',monospace;font-size:14px;letter-spacing:2px;padding:0 6px;border:1px solid currentColor;white-space:nowrap;}
.via-st.pending{color:var(--muted)}.via-st.rendering{color:#00FFFF}.via-st.done{color:#3f6}.via-st.error{color:#FF006E}
.via pre{background:var(--mid);border:1px solid var(--border);padding:8px;font-size:10px;white-space:pre-wrap;word-break:break-all;color:#ff0;margin:6px 0 0;}
.via-sub{background:var(--mid);border:2px solid #ff0;padding:12px;}
.via-durees{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.via-durees .via-btn.on{border-color:#ff0;color:#ff0;}
.via-scene{border:1px solid var(--border);background:var(--mid);padding:8px;margin-top:8px;}
.via-scene-top{display:flex;gap:6px;align-items:center;}
.via-scene-top b{font-family:'VT323',monospace;font-size:18px;color:#FF006E;min-width:22px;}
.via-scene-top select{width:auto;flex:1;}
.via-scene-top input[type=number]{width:64px;background:var(--mid);border:1px solid var(--border);color:var(--white);font-family:inherit;padding:6px;}
.via-scene .via-photo{display:flex;gap:8px;align-items:center;margin-top:6px;}
.via-scene .via-photo .t{width:44px;height:60px;background:var(--mid) center/cover no-repeat;border:1px solid var(--border);}
.via-crop-cadre{position:relative;overflow:hidden;background:var(--mid);margin:0 auto;cursor:grab;touch-action:none;border:2px solid #ff0;user-select:none;}
.via-crop-cadre:active{cursor:grabbing;}
.via-crop-cadre div{position:absolute;inset:0;}
.via-crop-cadre img{width:100%;height:100%;object-fit:cover;display:block;pointer-events:none;}
.via-crop-cadre::after{content:'';position:absolute;inset:0;pointer-events:none;background:linear-gradient(#ff04 0 0) 33.3% 0/1px 100% no-repeat,linear-gradient(#ff04 0 0) 66.6% 0/1px 100% no-repeat,linear-gradient(#ff04 0 0) 0 33.3%/100% 1px no-repeat,linear-gradient(#ff04 0 0) 0 66.6%/100% 1px no-repeat;}
`;

  // Palettes de toute la vidéo, identiques à PALETTES de theme.ts (remotion-videos).
  const LIB_PALETTES = {
    neon: 'Néon (rose, jaune, cyan)', chaud: 'Chaud (rouge, orange, ambre)', froid: 'Froid (bleus, cyan, violet)',
    acide: 'Acide (vert acide, magenta)', miami: 'Miami (rose bonbon, turquoise)', mono: 'Noir & blanc (accent rouge)',
  };

  const HTML = `
<div class="via-top">
  <h2>VIDÉO <span>IA</span> · ${T.sousTitre || 'promo concert'}</h2>
  <button class="via-btn sm" data-via="fermer">Fermer ✕</button>
</div>
<div class="via-body">
  <div>
    <div class="via-sec">
      <h3>✨ Assistant IA</h3>
      <label for="via-consigne">Consigne (facultatif)</label>
      <textarea id="via-consigne" placeholder="${T.consigneEx || 'Ex. ton festif, mettre en avant les lunettes fluo, entrée gratuite…'}"></textarea>
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
        <button class="via-btn ia" data-via="ia">✨ Proposer une vidéo</button>
        <button class="via-btn ia" data-via="ia3" title="Trois versions différentes à comparer dans l'aperçu">✨ 3 variantes</button>
        <span class="via-hint" style="margin:0">${T.iaNom || 'Claude'} regarde les photos de la médiathèque, rédige les textes, choisit les animations et règle le style. Tout reste modifiable.</span>
      </div>
      <div class="via-ia-msg" id="via-ia-msg"></div>
      <div class="via-variantes" id="via-variantes" hidden></div>
    </div>

    <div class="via-sec">
      <h3>Le sujet</h3>
      <label for="via-source-type">Partir d'un élément de l'admin</label>
      <div class="via-row">
        <select id="via-source-type"><option value="">📄 Affiche ouverte</option></select>
        <select id="via-evenement"><option value="">— Infos reprises de l'affiche ouverte —</option></select>
      </div>
      <div class="via-hint" id="via-sujet-note" hidden></div>
      <div class="via-row">
        <div><label for="via-date">Date affichée</label><input type="text" id="via-date" placeholder="${T.dateEx || 'VENDREDI 16 OCTOBRE'}"></div>
        <div><label for="via-heure">Heure</label><input type="text" id="via-heure" placeholder="20H30"></div>
      </div>
      <div class="via-row">
        <div><label for="via-lieu">Lieu</label><input type="text" id="via-lieu"></div>
        <div><label for="via-ville">Ville</label><input type="text" id="via-ville"></div>
      </div>
      <label for="via-mentions">Étiquettes (séparées par des virgules)</label>
      <input type="text" id="via-mentions" placeholder="${T.mentionsEx || "ROCK'N'ROLL, NEW WAVE"}">
      <div class="via-row">
        <div><label for="via-texte-infos">Appel final</label><input type="text" id="via-texte-infos" value="${T.texteInfos || "Plus d'infos ici"}"></div>
        <div><label for="via-lien-infos">Lien affiché</label><input type="text" id="via-lien-infos" value="${T.lien || 'electroboy80.com'}"></div>
      </div>
    </div>

    <div class="via-sec">
      <h3>Durée &amp; scénario</h3>
      <div class="via-durees">
        <span class="via-hint" style="margin:0">Durée :</span>
        <button class="via-btn sm" data-duree="10">10 s</button><button class="via-btn sm" data-duree="15">15 s</button>
        <button class="via-btn sm" data-duree="20">20 s</button><button class="via-btn sm" data-duree="30">30 s</button>
        <span class="via-hint" id="via-total" style="margin:0 0 0 auto"></span>
      </div>
      <p class="via-hint">Chaque scène a sa durée et ses textes. Changer la durée totale ajuste toutes les scènes ; l'IA écrit un nouveau scénario à la durée choisie.</p>
      <div id="via-scenes"></div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;align-items:center">
        <select id="via-ajout" style="width:auto">
          <option value="photo">Photo</option><option value="titre">Titre</option><option value="mots">Mots-chocs</option>
          <option value="compte">Compte à rebours</option><option value="concert">${T.carteNom || 'Carte concert'}</option><option value="fin">Fin</option>
        </select>
        <button class="via-btn sm" data-via="ajouter">+ Ajouter la scène</button>
        <button class="via-btn sm" data-via="photos-multi">+ Plusieurs photos</button>
        <button class="via-btn sm" data-via="classique" style="margin-left:auto">Scénario classique</button>
      </div>
    </div>

    <div class="via-sec">
      <h3>Style</h3>
      <div class="via-row">
        <div><label for="via-palette">Palette de couleurs</label>
          <select id="via-palette">${Object.entries(LIB_PALETTES).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></div>
        <div><label for="via-badge">Badge sur la carte (ex. J-5)</label><input type="text" id="via-badge"></div>
      </div>
      <div class="via-row">
        <div><label for="via-scanlines">Lignes d'écran cathodique (traits horizontaux)</label>
          <select id="via-scanlines"><option value="fortes">Fortes (d'origine)</option><option value="legeres">Légères</option><option value="aucune">Aucune</option></select></div>
        <div><label for="via-flash">Flash blanc au tempo (toutes les 4 pulsations)</label>
          <select id="via-flash"><option value="fort">Fort (d'origine)</option><option value="leger">Léger</option><option value="aucun">Aucun</option></select></div>
      </div>
    </div>

    <div class="via-sec">
      <h3>Musique</h3>
      <div class="via-slot" style="margin-top:0">
        <span id="via-musique-nom" class="via-hint" style="margin:0;flex:1">Aucune musique</span>
        <button class="via-btn sm" data-via="musique">Choisir dans la médiathèque</button>
        <button class="via-btn sm" data-via="musique-aucune" title="Sans musique">✕</button>
      </div>
      <div class="via-row">
        <div><label for="via-musique-debut">Départ dans le morceau (secondes)</label><input type="text" inputmode="decimal" id="via-musique-debut" value="0"></div>
        <div><label for="via-musique-volume">Volume</label><input type="range" id="via-musique-volume" min="0" max="1" step="0.05" value="0.9" style="width:100%"></div>
      </div>
      <p class="via-hint">Fondu automatique au début et à la fin ; le morceau boucle s'il est plus court que la vidéo. Lancez l'aperçu pour écouter.</p>
    </div>

    <div class="via-sec">
      <h3>Charte du lieu</h3>
      <label style="display:flex;gap:8px;align-items:center;color:#f0f0f0;letter-spacing:1px;margin:0">
        <input type="checkbox" id="via-charte-on"> ${T.charte || 'Habiller la carte du concert aux couleurs du lieu'}
      </label>
      <div class="via-visuel">
        <label>Visuel du lieu (inspire l'IA)</label>
        <div class="via-slot" style="margin-top:0"><div class="t" id="via-t-visuel"></div>
          <button class="via-btn sm" data-visuel="photo">📷 Photo</button>
          <button class="via-btn sm" data-visuel="video">🎞 Vidéo</button>
          <button class="via-btn sm" data-visuel="aucun" title="Retirer le visuel">✕</button>
        </div>
        <button class="via-btn sm ia" data-via="accorder" style="margin-top:6px">✨ Accorder la carte au visuel</button>
        <p class="via-hint">Une photo ou une vidéo du lieu (façade, salle, affiche…). ${T.iaNom || 'Claude'} s'en inspire pour la couleur
          d'accent, l'étiquette de la carte et la palette — ici et dans « Proposer une vidéo ». Elle n'apparaît pas dans la vidéo.</p>
        <div class="via-ia-msg" id="via-visuel-msg"></div>
      </div>
      <div id="via-charte">
        <p class="via-hint">Seul le logo est indispensable ; texture (sinon fond papier uni) et bandeau du bas sont facultatifs.
          PNG transparents de préférence. ${T.charteAide || 'La charte habille la carte du concert et ajoute le logo du lieu à l\'écran de fin.'}</p>
        <div class="via-slot"><div class="t" id="via-t-logo"></div><button class="via-btn sm" data-slot="logo">Logo du lieu</button><button class="via-btn sm" data-retirer="logo" title="Retirer">✕</button></div>
        <div class="via-slot"><div class="t" id="via-t-texture"></div><button class="via-btn sm" data-slot="texture">Texture de fond</button><button class="via-btn sm" data-retirer="texture" title="Retirer">✕</button></div>
        <div class="via-slot"><div class="t" id="via-t-sol"></div><button class="via-btn sm" data-slot="sol">Bandeau du bas</button><button class="via-btn sm" data-retirer="sol" title="Retirer">✕</button></div>
        <label for="via-accent">Couleur d'accent</label><input type="color" id="via-accent" value="#F87A00">
      </div>
    </div>
  </div>

  <div>
    <div class="via-sec">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><h3 style="margin:0">Aperçu</h3><button class="via-btn sm via-zone-btn" data-via="toggle-zones" title="Zones de sécurité réseaux (Instagram, TikTok…)">⊞ Zones</button></div>
      <div class="via-formats" id="via-formats"></div>
      <div class="via-apercu" id="via-apercu"></div>
      <button class="via-btn go" data-via="generer">Générer le MP4</button>
      <div class="via-lot" id="via-lot">
        <div class="via-lot-titre">Générer en lot</div>
        <div class="via-lot-formats" id="via-lot-formats"></div>
      </div>
      <p class="via-hint" id="via-generer-info"></p>
      <div style="margin-top:10px;padding-top:8px;border-top:1px solid #222;display:flex;gap:8px;align-items:center">
        <input type="text" id="via-save-nom" placeholder="Nom du projet…" style="flex:1;background:#111;border:1px solid #2a2a2a;color:#f0f0f0;font-family:inherit;font-size:11px;padding:5px 8px">
        <button class="via-btn sm" data-via="sauver-projet">💾 Sauver</button>
      </div>
      <p class="via-hint" id="via-save-msg" style="margin:4px 0 0;min-height:14px"></p>
    </div>

    <div class="via-sec" id="via-publication" hidden>
      <h3>Pour la publication</h3>
      <textarea id="via-legende" rows="5"></textarea>
      <button class="via-btn sm" data-via="copier" style="margin-top:6px">Copier légende + hashtags</button>
    </div>

    <div class="via-sec">
      <h3>Vidéos demandées</h3>
      <ul class="via-jobs" id="via-jobs"></ul>
    </div>

    <div class="via-sec">
      <h3>Agent de rendu</h3>
      <div class="via-hint" id="via-agent" style="margin:0">—</div>
      <button class="via-btn sm" data-via="jeton" style="margin-top:8px">Générer un jeton d'agent</button>
      <div id="via-agent-cmd" hidden>
        <p class="via-hint">À copier maintenant (affiché une seule fois). Dans le dossier <b>remotion-videos</b> de la machine de rendu :</p>
        <pre></pre>
      </div>
    </div>
  </div>
</div>
<dialog class="via-sub" id="via-picker">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
    <h3 id="via-picker-titre" style="font-family:'VT323',monospace;color:#ff0;font-weight:400;margin:0;font-size:18px">Choisir</h3>
    <span><button class="via-btn sm" data-via="aucun">Aucun</button> <button class="via-btn sm" data-via="picker-fermer">Fermer</button></span>
  </div>
  <div class="via-grid" id="via-picker-grille" style="width:min(640px,86vw)"></div>
</dialog>
<dialog class="via-sub" id="via-crop" style="width:min(440px,94vw);margin:auto">
  <h3 style="font-family:'VT323',monospace;color:#ff0;font-weight:400;margin:0 0 8px;font-size:18px">✂ Recadrer la photo</h3>
  <div class="via-crop-cadre" id="via-crop-cadre"><div id="via-crop-zone"><img id="via-crop-img" alt="" draggable="false"></div></div>
  <label for="via-crop-zoom" style="margin-top:10px">Zoom</label>
  <input type="range" id="via-crop-zoom" min="1" max="3" step="0.05" style="width:100%">
  <p class="via-hint">Faites glisser la photo pour choisir la partie visible. Le cadre a les proportions de la vidéo
    (vertical ou carré) ; le fichier d'origine n'est pas modifié.</p>
  <div style="display:flex;gap:6px;justify-content:flex-end;margin-top:8px">
    <button class="via-btn sm" data-via="crop-reset">Réinitialiser</button>
    <button class="via-btn sm" data-via="crop-annuler">Annuler</button>
    <button class="via-btn sm" data-via="crop-ok" style="background:#ff0;border-color:#ff0;color:#000">OK</button>
  </div>
</dialog>`;

  // ── Utilitaires ──────────────────────────────────────────────────────────────
  let dlg = null;
  const $ = (id) => document.getElementById(id);
  const token = () => localStorage.getItem('eb80_token') || '';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const urlMedia = (m) => m.url_web || m.url;

  const api = async (chemin, options = {}) => {
    const res = await fetch('/api' + chemin, {
      ...options,
      headers: { 'Authorization': 'Bearer ' + token(), 'Content-Type': 'application/json', ...(options.headers || {}) },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || ('HTTP ' + res.status));
    return data;
  };

  const charger = (tag, attrs) => new Promise((ok, ko) => {
    const el = document.createElement(tag);
    Object.assign(el, attrs);
    el.onload = ok;
    el.onerror = () => ko(new Error('Chargement impossible : ' + (attrs.src || attrs.href)));
    document.head.appendChild(el);
  });
  let lecteurCharge = null;
  const chargerLecteur = () => lecteurCharge ||= Promise.all([
    charger('link', { rel: 'stylesheet', href: BASE + 'eb80-video-player.css?v=' + VERSION }),
    charger('script', { src: BASE + 'eb80-video-player.js?v=' + VERSION }),
  ]);

  // ── État ─────────────────────────────────────────────────────────────────────
  const etat = { variantes: [], variante: 0, musique: '', scenes: [], duree: 15, charte: { logo: '', texture: '', sol: '', visuel: '' }, medias: [], evenements: [], legende: '', formatsLot: [] };
  const CHAMPS = ['via-date', 'via-heure', 'via-lieu', 'via-ville', 'via-mentions', 'via-texte-infos', 'via-lien-infos',
    'via-badge', 'via-palette', 'via-scanlines', 'via-flash', 'via-accent', 'via-consigne', 'via-musique-debut', 'via-musique-volume'];

  // ── Scénario ─────────────────────────────────────────────────────────────────
  // Même modèle que src/projects/electroboy80/scenario.ts (dépôt remotion-videos).
  const TEMPS = 60 / 170; // un temps à 170 BPM, en secondes
  // Variantes d'animation par type (la première : défaut) et effets d'entrée,
  // identiques à scenario.ts du dépôt remotion-videos.
  const LIB_ANIMS = {
    titre: { zoom: 'Zoom', glitch: 'Glitch', machine: 'Machine à écrire', chute: 'Chute', explosion: 'Explosion', rotation: 'Rotation', rebond: 'Rebond' },
    photo: { dezoom: 'Recul lent', zoom: 'Avancée lente', glisse: 'Travelling', glitch: 'Glitch', strobo: 'Strobo au tempo', oscillation: 'Oscillation', burn: 'Brûlure', parallax: 'Parallaxe' },
    mots: { claque: 'Empilés', 'un-par-un': 'Un par un', machine: 'Machine à écrire', rebond: 'Rebond', explosion: 'Explosion', vague: 'Vague' },
    compte: { rebond: 'Rebond', glitch: 'Glitch', machine: 'Machine à écrire', zoom: 'Zoom', rotation: 'Rotation', pulse: 'Pulsation' },
  };
  const LIB_TRANSITIONS = {
    coupe: 'Coupe sèche',
    fondu: 'Fondu au noir',
    fondu_blanc: 'Fondu au blanc',
    flash: 'Flash blanc',
    glitch: 'Brouillage',
    balayage: 'Balayage horizontal',
    balayage_v: 'Balayage vertical',
    zoom: 'Zoom avant',
    dezoom: 'Zoom arrière',
    rideau: 'Rideau',
    rotation: 'Rotation',
    eclat: 'Éclat de lumière',
    slide_g: 'Glissement gauche',
    slide_d: 'Glissement droite',
    spirale: 'Spirale',
    pixel: 'Pixelisation',
  };
  const LIB_TYPES = { titre: 'Titre', photo: 'Photo', mots: 'Mots-chocs', compte: 'Compte à rebours', concert: T.carteNom || 'Carte concert', fin: 'Fin' };
  const DUREES_TYPE = { titre: 8, photo: 4, mots: 8, compte: 4, concert: 16, fin: 8 }; // en temps
  const nouvelleScene = (type) => {
    const sc = { type, duree: Math.round(DUREES_TYPE[type] * TEMPS * 100) / 100 };
    if (type === 'titre') { sc.texte = T.titre || 'ELECTRO\nBOY 80'; sc.texte2 = T.titre2 || 'EN CONCERT'; }
    if (type === 'mots') sc.mots = T.mots ? T.mots.slice() : ['CHEAP.', 'PRIMITIF.', 'SAUVAGE.'];
    if (type === 'concert' && T.carteEtiquette) sc.texte2 = T.carteEtiquette;
    if (type === 'fin' && T.fin) sc.texte = T.fin;
    if (type === 'compte') sc.texte = $('via-badge').value.trim() || 'J-5';
    return sc;
  };
  const classique = (photos) => [
    nouvelleScene('titre'),
    ...(photos.length ? photos : ['']).map((photo) => ({ ...nouvelleScene('photo'), photo, texte: T.photo || "LA NEW WAVE DES 80'S", texte2: T.photo2 || 'À 170 BPM' })),
    nouvelleScene('mots'),
    nouvelleScene('concert'),
    nouvelleScene('fin'),
  ];
  const total = () => Math.round(etat.scenes.reduce((t, sc) => t + (Number(sc.duree) || 0), 0) * 10) / 10;
  // Ramène la somme des durées à la durée visée ; l'écart d'arrondi va à la carte concert.
  const ajuster = (cible) => {
    const t = total();
    if (!t) return;
    etat.scenes.forEach((sc) => { sc.duree = Math.max(0.5, Math.round(sc.duree * cible / t * 100) / 100); });
    const ecart = Math.round((cible - etat.scenes.reduce((x, sc) => x + sc.duree, 0)) * 100) / 100;
    const sc = etat.scenes.find((x) => x.type === 'concert') || etat.scenes[etat.scenes.length - 1];
    sc.duree = Math.max(0.5, Math.round((sc.duree + ecart) * 100) / 100);
  };
  // Formats : vertical (9:16), carré (1:1) ou ratio libre « r:L:H » (composition -libre).
  // « Hero 4:5 » : proche de la colonne vidéo du hero de l'accueil (sur ordinateur).
  const FORMATS_FIXES = [['vertical', 'Vertical 9:16'], ['carre', 'Carré 1:1'], ['r:3:2', '3:2 paysage'], ['r:4:5', 'Hero 4:5']];
  const format = () => etat.format || 'vertical';
  const infoFormat = (f = format()) => {
    if (f === 'carre') return { composition: 'electroboy80-promo-concert-carre', lecteur: 'carre', l: 1, h: 1, libelle: 'carré' };
    const m = /^r:([\d.]+):([\d.]+)$/.exec(f);
    if (m) return { composition: 'electroboy80-promo-concert-libre', lecteur: 'libre', l: Number(m[1]), h: Number(m[2]), libelle: `${m[1]}:${m[2]}` };
    return { composition: 'electroboy80-promo-concert', lecteur: 'vertical', l: 9, h: 16, libelle: 'vertical' };
  };
  const dessinerFormats = () => {
    const perso = (etat.ratios || []).map((r, i) => [`r:${r.largeur}:${r.hauteur}`, r.nom ? `${r.nom} (${r.largeur}:${r.hauteur})` : `${r.largeur}:${r.hauteur}`, i]);
    // Un ratio perso identique à un format fixe (ex. 4:5) n'est pas affiché deux fois.
    const liste = [...FORMATS_FIXES, ...perso.filter(([v]) => !FORMATS_FIXES.some(([f]) => f === v))];
    if (!liste.some(([v]) => v === format())) liste.push([format(), infoFormat().libelle]); // format d'une vidéo reprise
    $('via-formats').innerHTML = liste.map(([v, lib, i]) => `<label><input type="radio" name="via-format" value="${esc(v)}" ${v === format() ? 'checked' : ''}> ${esc(lib)}${i !== undefined ? ` <button class="x" data-ratio-suppr="${i}" title="Supprimer ce ratio">✕</button>` : ''}</label>`).join('')
      + '<button class="via-btn sm" data-via="ratio-ajout" title="Enregistrer un ratio personnalisé">+ Ratio perso</button>';
  };
  const dessinerLot = () => {
    const perso = (etat.ratios || []).map((r) => [`r:${r.largeur}:${r.hauteur}`, r.nom ? `${r.nom} (${r.largeur}:${r.hauteur})` : `${r.largeur}:${r.hauteur}`]);
    const liste = [...FORMATS_FIXES.map(([v, lib]) => [v, lib]), ...perso.filter(([v]) => !FORMATS_FIXES.some(([f]) => f === v))];
    const coches = etat.formatsLot || [];
    $('via-lot-formats').innerHTML = liste.map(([v, lib]) =>
      `<label><input type="checkbox" name="via-lot" value="${esc(v)}" ${coches.includes(v) ? 'checked' : ''}> ${esc(lib)}</label>`
    ).join('');
  };
  const chargerRatios = async () => {
    try { etat.ratios = await api('/video-jobs/ratios'); } catch (e) { etat.ratios = etat.ratios || []; }
    dessinerFormats();
    dessinerLot();
  };
  const ajouterRatio = async () => {
    const t = prompt('Ratio largeur:hauteur (ex. 4:5, 21:9, 2:3) :', '4:5');
    const m = /^\s*(\d+(?:[.,]\d+)?)\s*[:x/]\s*(\d+(?:[.,]\d+)?)\s*$/i.exec(t || '');
    if (!m) { if (t) alert('Format attendu : largeur:hauteur, par exemple 4:5.'); return; }
    const largeur = Number(m[1].replace(',', '.'));
    const hauteur = Number(m[2].replace(',', '.'));
    if (largeur / hauteur < 0.25 || largeur / hauteur > 4) { alert('Ratio hors limites (de 1:4 à 4:1).'); return; }
    const nom = (prompt('Nom du format (facultatif, ex. « Post Instagram ») :', '') || '').trim();
    try {
      etat.ratios = await api('/video-jobs/ratios', { method: 'POST', body: JSON.stringify({ ratios: [...(etat.ratios || []), { nom, largeur, hauteur }] }) });
      etat.format = `r:${largeur}:${hauteur}`;
      dessinerFormats(); majApercu();
    } catch (e) { alert('Enregistrement impossible : ' + e.message); }
  };
  const supprimerRatio = async (i) => {
    const r = etat.ratios[i];
    if (!r || !confirm(`Supprimer le ratio ${r.nom || ''} ${r.largeur}:${r.hauteur} pour toute l'équipe ?`)) return;
    try {
      etat.ratios = await api('/video-jobs/ratios', { method: 'POST', body: JSON.stringify({ ratios: etat.ratios.filter((_, k) => k !== i) }) });
      dessinerFormats();
    } catch (e) { alert('Suppression impossible : ' + e.message); }
  };

  const props = () => {
    const v = (id) => $(id).value.trim();
    const p = {
      date: v('via-date').toUpperCase(),
      heure: v('via-heure').toUpperCase(),
      lieu: v('via-lieu').toUpperCase(),
      ville: v('via-ville'),
      texteInfos: v('via-texte-infos'),
      lienInfos: v('via-lien-infos'),
      mentions: v('via-mentions').split(',').map((s) => s.trim().toUpperCase()).filter(Boolean),
      palette: $('via-palette').value,
      scanlines: $('via-scanlines').value,
      ratio: infoFormat().lecteur === 'libre' ? { largeur: infoFormat().l, hauteur: infoFormat().h } : null,
      flash: $('via-flash').value,
      // Scènes photo sans image écartées ; durées en secondes.
      scenes: etat.scenes.filter((sc) => sc.type !== 'photo' || sc.photo).map((sc) => ({ ...sc, duree: Number(sc.duree) || 1,
        // Mots-chocs sans bandeau saisi : texte de la boutique (vide = aucun bandeau), jamais celui de la composition d'origine.
        ...(sc.type === 'mots' && sc.bandeau == null && T.bandeau != null ? { bandeau: T.bandeau } : {}) })),
    };
    p.photos = p.scenes.filter((sc) => sc.type === 'photo').map((sc) => sc.photo);
    // Toujours explicite, comme la charte : null = sans musique.
    p.musique = etat.musique
      ? { src: etat.musique, debut: Math.max(0, parseFloat(v('via-musique-debut').replace(',', '.')) || 0), volume: Number($('via-musique-volume').value) }
      : null;
    if (v('via-badge')) p.badge = v('via-badge');
    // Toujours explicite : au rendu, une clé absente reprendrait la valeur par défaut de la composition.
    const c = etat.charte;
    p.charteLieu = ($('via-charte-on').checked && c.logo)
      ? { logo: c.logo, texture: c.texture || '', sol: c.sol || '', accent: $('via-accent').value }
      : null;
    return p;
  };

  // État complet de l'éditeur : brouillon local, et copie jointe à chaque demande de rendu
  // pour pouvoir reprendre la vidéo plus tard depuis la médiathèque.
  const brouillon = () => {
    const b = { musique: etat.musique, scenes: etat.scenes, duree: etat.duree, charte: etat.charte, charteOn: $('via-charte-on').checked, format: format(), legende: $('via-legende').value, sujet: etat.sujet || null };
    CHAMPS.forEach((id) => { b[id] = $(id).value; });
    return b;
  };
  const sauver = () => {
    try { localStorage.setItem(CLE_BROUILLON, JSON.stringify(brouillon())); } catch (e) { /* stockage indisponible */ }
  };
  const suggestNom = () => {
    const save = $('via-save-nom');
    if (!save || save.value) return;
    const lieu = ($('via-lieu') || {}).value || '';
    const date = ($('via-date') || {}).value || '';
    const parts = [lieu.trim(), date.trim()].filter(Boolean);
    if (!parts.length) return;
    save.value = parts.join(' ').replace(/[^a-zA-Z0-9À-ɏ ]/g, '').trim().replace(/\s+/g, '_').toLowerCase();
  };
  const restaurer = () => {
    try { return appliquerBrouillon(JSON.parse(localStorage.getItem(CLE_BROUILLON) || 'null')); } catch (e) { return false; }
  };
  // Vidéo rendue avant la sauvegarde de l'éditeur : on reconstruit l'état depuis ses réglages de rendu.
  const depuisProps = (job) => {
    const p = job.props || {};
    const c = p.charteLieu || null;
    const scenes = Array.isArray(p.scenes) && p.scenes.length ? p.scenes : classique(p.photos || []);
    return {
      scenes, duree: Math.round(scenes.reduce((t, sc) => t + (Number(sc.duree) || 0), 0)) || 15,
      musique: (p.musique && p.musique.src) || '',
      charte: { logo: c ? c.logo : '', texture: c ? c.texture || '' : '', sol: c ? c.sol || '' : '' }, charteOn: !!c,
      format: String(job.composition || '').endsWith('-carre') ? 'carre'
        : String(job.composition || '').endsWith('-libre') && p.ratio ? `r:${p.ratio.largeur}:${p.ratio.hauteur}` : 'vertical',
      legende: '',
      'via-date': p.date || '', 'via-heure': p.heure || '', 'via-lieu': p.lieu || '', 'via-ville': p.ville || '',
      'via-mentions': (p.mentions || []).join(', '), 'via-texte-infos': p.texteInfos || '', 'via-lien-infos': p.lienInfos || '',
      'via-badge': p.badge || '', 'via-palette': p.palette || 'neon', 'via-scanlines': p.scanlines || 'fortes', 'via-flash': p.flash || 'fort', 'via-accent': (c && c.accent) || '#F87A00', 'via-consigne': '',
      'via-musique-debut': p.musique ? p.musique.debut || 0 : 0, 'via-musique-volume': p.musique ? p.musique.volume ?? 0.9 : 0.9,
    };
  };
  const appliquerBrouillon = (b) => {
    try {
      if (!b) return false;
      CHAMPS.forEach((id) => { if (b[id] !== undefined) $(id).value = b[id]; });
      etat.duree = b.duree || 15;
      etat.musique = b.musique || '';
      // Ancien brouillon (liste de photos) : converti en scénario classique.
      etat.scenes = Array.isArray(b.scenes) && b.scenes.length ? b.scenes : classique(b.photos || []);
      etat.charte = { logo: '', texture: '', sol: '', visuel: '', ...(b.charte || {}) };
      $('via-charte-on').checked = !!b.charteOn;
      etat.format = b.format || 'vertical';
      dessinerFormats();
      dessinerLot();
      $('via-legende').value = b.legende || '';
      $('via-publication').hidden = !b.legende;
      etat.sujet = b.sujet || null;
      noteSujet();
      return true;
    } catch (e) { return false; }
  };

  // ── Infos depuis l'affiche ouverte dans l'éditeur ────────────────────────────
  // POSTER est l'état global de l'Éditeur Visuel (admin.html).
  const depuisAffiche = () => {
    const P = (typeof POSTER !== 'undefined') ? POSTER : null;
    if (!P) return;
    const txt = (k) => (P[k] && P[k].text ? String(P[k].text).trim() : '');
    // « SAMEDI 14 JUIN 2025 — 21H » → date + heure
    const [d, h] = txt('date').split(/\s+[—–-]\s+/);
    if (d) $('via-date').value = d.replace(/\s+20\d\d\b/, '').trim();
    if (h) $('via-heure').value = h.trim();
    // « LIEU — VILLE » ou « LIEU, VILLE »
    const [l, v] = txt('venue').split(/\s+[—–-]\s+|,\s*/);
    if (l) $('via-lieu').value = l.trim();
    if (v) $('via-ville').value = v.trim();
  };

  // ── Aperçu ───────────────────────────────────────────────────────────────────
  let lecteur = null;
  let attente = null;
  let _showVIASafeZones = false;
  const ensureVIACanvas = () => {
    const apercu = $('via-apercu');
    if (!apercu || document.getElementById('via-safe-canvas')) return;
    const c = document.createElement('canvas');
    c.id = 'via-safe-canvas'; c.className = 'via-safe-canvas';
    apercu.appendChild(c);
    new ResizeObserver(drawVIASafeZones).observe(apercu);
  };
  const drawVIASafeZones = () => {
    const apercu = $('via-apercu');
    const canvas = $('via-safe-canvas');
    if (!canvas || !apercu) return;
    const W = apercu.offsetWidth, H = apercu.offsetHeight;
    canvas.width = W; canvas.height = H;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, W, H);
    if (!_showVIASafeZones || !W || !H) return;
    const f = format();
    let sz;
    if (f === 'carre') {
      sz = { type: 'social', sx: 0.05, sy: 0.05 };
    } else {
      const fi = infoFormat(f);
      sz = (fi.h >= fi.l)
        ? { type: 'story', sx: 0.05, topY: 0.14, botY: 0.20 }
        : { type: 'social', sx: 0.05, sy: 0.05 };
    }
    const isStory = sz.type === 'story';
    ctx.fillStyle = 'rgba(0,0,0,0.42)';
    if (isStory) {
      const topH = H * sz.topY, botH = H * sz.botY, sw = W * sz.sx;
      ctx.fillRect(0, 0, W, topH);
      ctx.fillRect(0, H - botH, W, botH);
      ctx.fillRect(0, topH, sw, H - topH - botH);
      ctx.fillRect(W - sw, topH, sw, H - topH - botH);
    } else {
      const sx = W * sz.sx, sy = H * (sz.sy || sz.sx);
      ctx.fillRect(0, 0, W, sy);
      ctx.fillRect(0, H - sy, W, sy);
      ctx.fillRect(0, sy, sx, H - 2 * sy);
      ctx.fillRect(W - sx, sy, sx, H - 2 * sy);
    }
    ctx.strokeStyle = 'rgba(255,220,0,0.85)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([6, 4]);
    if (isStory) {
      const topH = H * sz.topY, botH = H * sz.botY, sw = W * sz.sx;
      ctx.strokeRect(sw, topH, W - 2 * sw, H - topH - botH);
    } else {
      const sx = W * sz.sx, sy = H * (sz.sy || sz.sx);
      ctx.strokeRect(sx, sy, W - 2 * sx, H - 2 * sy);
    }
    ctx.setLineDash([]);
    ctx.font = 'bold 10px monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = 'rgba(255,220,0,0.9)';
    if (isStory) {
      const topH = H * sz.topY, botH = H * sz.botY;
      ctx.fillText('UI réseau / Notifs', W / 2, topH / 2);
      ctx.fillText('Caption · Profil · Boutons', W / 2, H - botH / 2);
      ctx.fillStyle = 'rgba(255,220,0,0.7)';
      ctx.fillText('Zone sécurisée', W / 2, topH + 14);
    } else {
      const sy = H * (sz.sy || sz.sx);
      ctx.fillText('Zone sécurisée', W / 2, sy + 14);
    }
  };

  const majApercu = () => {
    clearTimeout(attente);
    attente = setTimeout(async () => {
      sauver();
      const p = props();
      const fi = infoFormat();
      $('via-apercu').style.maxWidth = `${Math.round(Math.min(560, (480 * fi.l) / fi.h))}px`; // ~480 px de haut au plus
      const incomplete = $('via-charte-on').checked && !p.charteLieu;
      const sansPhoto = etat.scenes.some((sc) => sc.type === 'photo' && !sc.photo);
      const finCourte = etat.scenes.some((sc) => sc.type === 'fin' && Number(sc.duree) < 2.5);
      $('via-generer-info').textContent = !p.scenes.length ? 'Ajoutez des scènes, ou demandez une proposition à l\'IA.'
        : sansPhoto ? 'Une scène photo n\'a pas encore d\'image : elle est ignorée.'
        : incomplete ? 'Charte du lieu sans logo : choisissez le logo du lieu, sinon la carte par défaut est utilisée.'
        : finCourte ? 'Écran de fin très court : donnez-lui 3 s au moins pour qu\'on ait le temps de lire le lien.' : '';
      dlg.querySelector('[data-via=generer]').disabled = !p.scenes.length;
      if (!p.scenes.length) return;
      try { await chargerLecteur(); } catch (e) { $('via-apercu').textContent = e.message; return; }
      const o = { format: fi.lecteur, props: p };
      if (lecteur) lecteur.update(o);
      else lecteur = window.EB80Video.mount($('via-apercu'), o);
      ensureVIACanvas();
      drawVIASafeZones();
    }, 250);
  };

  // ── Sélecteur de médias de l'admin (celui de la landing page) ────────────────
  // openMediaPicker et ses variables viennent d'admin.html ; hors admin, on garde le
  // mini-sélecteur intégré. Le panneau de l'admin (position fixe, z-index 3000) passerait
  // sous notre <dialog> modale : on la referme le temps du choix et on la rouvre ensuite.
  const pickerAdmin = () => typeof window.openMediaPicker === 'function' && document.getElementById('poster-media-picker');
  const viaPickerAdmin = (ouvrir) => {
    const panneau = document.getElementById('poster-media-picker');
    dlg.close();
    ouvrir(panneau);
    const t = setInterval(() => {
      if (panneau.style.display === 'none') { clearInterval(t); if (!dlg.open) dlg.showModal(); }
    }, 250);
  };
  const estImage = (url) => /\.(jpe?g|png|webp|gif|avif)(\?|$)/i.test(url || '');
  // Version _web (1200 px) quand elle existe : plus légère pour l'aperçu et le rendu.
  const versionWeb = (url) => {
    const m = etat.medias.find((x) => x.url === url);
    return (m && m.url_web) || url;
  };
  const choisirUne = (cb) => viaPickerAdmin(() => window.openMediaPicker((url) => {
    if (!estImage(url)) { alert('Choisissez une image (pas une vidéo ni un son).'); return; }
    cb(url);
  }));
  // Mode audio du sélecteur (celui de la musique de l'éditeur vidéo), avec notre propre retour.
  const choisirAudio = (cb) => viaPickerAdmin((panneau) => {
    /* global _pickerSlotRatio, _pickerSlotLabel */
    _pickerSlot = 'video-audio';
    _pickerCallback = cb;
    _pickerSlotRatio = null;
    _pickerSlotLabel = null;
    _pickerMultiMode = false;
    panneau.style.display = 'flex';
    loadPickerGrid().then(() => {
      // « Extraire depuis une vidéo » règle la musique de l'ancien éditeur vidéo, pas cette fenêtre.
      document.querySelectorAll('#picker-grid > div').forEach((d) => {
        if (d.textContent.includes('EXTRAIRE DEPUIS UNE VIDÉO')) d.remove();
      });
    });
  });
  const choisirPlusieurs = (cb) => viaPickerAdmin((panneau) => {
    /* global _pickerMultiSelected, _pickerMultiMode, _pickerMultiCallback, _pickerCallback, _pickerSlot, loadPickerGrid, _updatePickerMultiBar */
    _pickerMultiSelected = new Set();
    _pickerMultiMode = true;
    _pickerMultiCallback = (urls) => cb(urls.filter(estImage));
    _pickerCallback = null;
    _pickerSlot = '__gallery__';
    panneau.style.display = 'flex';
    loadPickerGrid().then(() => _updatePickerMultiBar());
  });

  // ── Médiathèque ──────────────────────────────────────────────────────────────
  const images = () => etat.medias.filter((m) => (m.mimetype || '').startsWith('image/'));
  const champ = (i, nom, libelle, val, attrs = '') =>
    `<div><label>${libelle}</label><input type="text" data-i="${i}" data-champ="${nom}" value="${esc(val)}" ${attrs}></div>`;
  const dessinerScenes = () => {
    $('via-scenes').innerHTML = etat.scenes.map((sc, i) => {
      let corps = '';
      if (sc.type === 'titre') corps = `<div class="via-row"><div><label>Titre (\\n = à la ligne)</label><input type="text" data-i="${i}" data-champ="texte" value="${esc((sc.texte || '').replace(/\n/g, '\\n'))}"></div>${champ(i, 'texte2', 'Surtitre', sc.texte2 || '')}</div>`;
      if (sc.type === 'photo') corps = `<div class="via-photo"><div class="t" style="background-image:url('${esc(sc.photo || '')}');background-position:${sc.cadrage ? `${sc.cadrage.x}% ${sc.cadrage.y}%` : 'center'}"></div><button class="via-btn sm" data-photo="${i}">${sc.photo ? 'Changer la photo' : 'Choisir la photo'}</button>${sc.photo ? `<button class="via-btn sm" data-recadrer="${i}">✂ Recadrer${sc.cadrage ? ' ✓' : ''}</button>` : ''}</div>
        <div class="via-row">${champ(i, 'texte', 'Accroche, ligne 1', sc.texte || '')}${champ(i, 'texte2', 'Accroche, ligne 2', sc.texte2 || '')}</div>`;
      if (sc.type === 'mots') corps = `<div class="via-row">${champ(i, 'mots', 'Mots (séparés par des virgules)', (sc.mots || []).join(', '))}${champ(i, 'bandeau', T.bandeauLib || 'Bandeau défilant (vide = influences)', sc.bandeau || '')}</div>`;
      if (sc.type === 'compte') corps = `<div class="via-row">${champ(i, 'texte', 'Chiffre (J-5, CE SOIR…)', sc.texte || '')}${champ(i, 'texte2', 'Sous-texte', sc.texte2 || '')}</div>`;
      if (sc.type === 'concert') corps = `<div class="via-row">${champ(i, 'texte2', 'Étiquette au-dessus de la date', sc.texte2 || '', 'placeholder="' + (T.carteEtiquette || 'PROCHAIN CONCERT') + '"')}<div><label>&nbsp;</label><span class="via-hint">Date, lieu, étiquettes : section « ${T.sujet || 'Le concert'} ».</span></div></div><div class="via-photo"><div class="t" style="background-image:url('${esc(sc.photo || '')}');background-position:${sc.cadrage ? `${sc.cadrage.x}% ${sc.cadrage.y}%` : 'center'}"></div><button class="via-btn sm" data-photo="${i}">${sc.photo ? 'Changer la photo de fond' : 'Ajouter une photo de fond'}</button>${sc.photo ? ` <button class="via-btn sm" data-recadrer="${i}">✂ Recadrer${sc.cadrage ? ' ✓' : ''}</button> <button class="via-btn sm" data-via="effacer-photo-scene" data-i="${i}" title="Retirer la photo">✕</button>` : ''}</div>`;
      if (sc.type === 'fin') corps = `<div class="via-row">${champ(i, 'texte', 'Titre de fin', sc.texte || '', 'placeholder="' + (T.fin || 'ELECTROBOY 80') + '"')}<div><label>&nbsp;</label><span class="via-hint">Appel final et lien : section « ${T.sujet || 'Le concert'} ».</span></div></div>`;
      return `<div class="via-scene">
        <div class="via-scene-top"><b>${i + 1}</b>
          <select data-i="${i}" data-champ="type">${Object.entries(LIB_TYPES).map(([k, l]) => `<option value="${k}" ${k === sc.type ? 'selected' : ''}>${l}</option>`).join('')}</select>
          <input type="number" min="0.5" max="30" step="0.1" data-i="${i}" data-champ="duree" value="${sc.duree}" title="Durée en secondes"> s
          <button class="via-btn sm" data-monter="${i}" ${i ? '' : 'disabled'}>↑</button>
          <button class="via-btn sm" data-descendre="${i}" ${i < etat.scenes.length - 1 ? '' : 'disabled'}>↓</button>
          <button class="via-btn sm" data-supprimer="${i}">✕</button>
        </div>${corps}${animation(i, sc)}</div>`;
    }).join('') || '<p class="via-hint">Aucune scène : ajoutez-en ou demandez une proposition à l\'IA.</p>';
    dlg.querySelectorAll('[data-duree]').forEach((b) => b.classList.toggle('on', Number(b.dataset.duree) === etat.duree));
    afficherTotal();
  };
  const options = (lib, val) => Object.entries(lib).map(([k, l]) => `<option value="${k}" ${k === val ? 'selected' : ''}>${l}</option>`).join('');
  const animation = (i, sc) => `<div class="via-row">
      ${LIB_ANIMS[sc.type] ? `<div><label>Animation</label><select data-i="${i}" data-champ="anim">${options(LIB_ANIMS[sc.type], sc.anim || Object.keys(LIB_ANIMS[sc.type])[0])}</select></div>` : '<div></div>'}
      <div><label>Entrée de la scène</label><select data-i="${i}" data-champ="transition">${options(LIB_TRANSITIONS, sc.transition || 'coupe')}</select></div>
    </div>`;
  const afficherTotal = () => { $('via-total').textContent = `Total : ${total()} s`; };
  const majChamp = (el) => {
    const sc = etat.scenes[Number(el.dataset.i)];
    if (!sc) return;
    const nom = el.dataset.champ;
    if (nom === 'duree') { sc.duree = Math.max(0.5, Number(el.value) || 0.5); afficherTotal(); }
    else if (nom === 'mots') sc.mots = el.value.split(',').map((m) => m.trim()).filter(Boolean);
    else if (nom === 'texte' && sc.type === 'titre') sc.texte = el.value.replace(/\\n/g, '\n');
    else if (nom === 'type') {
      // Nouveau type : on garde la durée, on repart des textes par défaut du type.
      etat.scenes[Number(el.dataset.i)] = { ...nouvelleScene(el.value), duree: sc.duree };
      dessinerScenes();
    } else sc[nom] = el.value;
    majApercu();
  };
  const NOMS = { logo: 'Logo du lieu', texture: 'Texture de fond', sol: 'Bandeau du bas' };
  let slot = null;
  let slotScene = 0;
  const estAudio = (url) => /\.(mp3|m4a|aac|wav|ogg|flac)(\?|$)/i.test(url || '');
  const dessinerMusique = () => {
    $('via-musique-nom').textContent = etat.musique ? '♪ ' + decodeURIComponent(etat.musique.split('/').pop()) : 'Aucune musique';
  };
  const estVideo = (url) => /\.(mp4|mov|webm|m4v)(\?|$)/i.test(url || '');
  const dessinerCharte = () => {
    Object.keys(NOMS).forEach((s) => {
      $('via-t-' + s).style.backgroundImage = etat.charte[s] ? `url('${etat.charte[s]}')` : 'none';
      dlg.querySelector(`[data-retirer="${s}"]`).hidden = !etat.charte[s];
    });
    dlg.querySelector('[data-visuel="aucun"]').hidden = !etat.charte.visuel;
    const v = etat.charte.visuel;
    $('via-t-visuel').style.backgroundImage = v && !estVideo(v) ? `url('${v}')` : 'none';
    $('via-t-visuel').innerHTML = v && estVideo(v)
      ? `<video src="${esc(v)}#t=1" muted playsinline preload="metadata" style="width:100%;height:100%;object-fit:cover;display:block"></video>` : '';
    dlg.querySelector('[data-via=accorder]').disabled = !v;
    $('via-charte').style.opacity = $('via-charte-on').checked ? 1 : 0.4;
  };

  // ── Événements ───────────────────────────────────────────────────────────────
  const JOURS = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
  const chargerEvenements = async () => {
    const data = await api('/events?all=1');
    etat.evenements = (Array.isArray(data) ? data : (data.events || [])).filter((e) => e.date);
    const auj = new Date().toISOString().slice(0, 10);
    const opt = (e) => `<option value="${e.id}">${esc(e.date)} · ${esc(e.venue || e.name)}${e.city ? ' (' + esc(e.city) + ')' : ''}</option>`;
    const avenir = etat.evenements.filter((e) => e.date >= auj).sort((a, b) => a.date.localeCompare(b.date));
    const passes = etat.evenements.filter((e) => e.date < auj).sort((a, b) => b.date.localeCompare(a.date));
    $('via-evenement').innerHTML = '<option value="">— Infos reprises de l\'affiche ouverte —</option>'
      + (avenir.length ? `<optgroup label="À venir">${avenir.map(opt).join('')}</optgroup>` : '')
      + (passes.length ? `<optgroup label="Passés">${passes.map(opt).join('')}</optgroup>` : '');
  };

  // ── Autres sources (article merch, partenaire) ───────────────────────────────
  const maj = (v) => String(v || '').toUpperCase();
  const noteSujet = () => {
    const n = $('via-sujet-note');
    if (!n) return;
    n.hidden = !etat.sujet;
    if (etat.sujet) {
      const carte = etat.sujet.profil === 'produit' ? 'le prix (« Date »)' : 'la catégorie (« Date »)';
      n.textContent = `Sujet : ${etat.sujet.nom}. La carte d'infos reprend ${carte} et le nom (« Lieu ») ; ${T.iaNom || 'Claude'} écrit les textes à partir de ces infos.`;
    }
  };
  // Types proposés = sources activées dans « API & Réseaux → Sources éditeur » (plugin/sources.js).
  const remplirTypes = async () => {
    const sel = $('via-source-type');
    const courant = sel.value;
    let actives = [];
    try { await window.EB80Source.init(); actives = window.EB80Source.sourcesActives(); } catch (e) { actives = []; }
    sel.innerHTML = '<option value="">📄 Affiche ouverte</option>'
      + actives.map((a) => `<option value="${esc(a.id)}">${esc(a.icone)} ${esc(a.label)}</option>`).join('');
    sel.value = actives.some((a) => a.id === courant) ? courant : (actives.some((a) => a.id === 'event') ? 'event' : '');
  };
  const chargerSources = async () => {
    const type = $('via-source-type').value;
    etat.sujet = null;
    noteSujet();
    const sel = $('via-evenement');
    if (type === 'event') { await chargerEvenements(); return; }
    if (!type) { sel.innerHTML = '<option value="">— Infos reprises de l\'affiche ouverte —</option>'; return; }
    sel.innerHTML = '<option value="">— Chargement… —</option>';
    try {
      const l = await window.EB80Source.liste(type);
      const groupes = {};
      l.forEach((e) => { (groupes[e.groupe] = groupes[e.groupe] || []).push(e); });
      sel.innerHTML = '<option value="">— Choisir —</option>'
        + Object.entries(groupes).map(([g, es]) => `<optgroup label="${esc(g)}">${es.map((e) => `<option value="${esc(e.id)}">${esc(e.label)}</option>`).join('')}</optgroup>`).join('');
    } catch (e) { sel.innerHTML = `<option value="">— Indisponible : ${esc(e.message)} —</option>`; }
  };
  const depuisSource = async (type, id) => {
    const s = await window.EB80Source.charger(type, id);
    const k = s.carte;
    etat.sujet = window.EB80Source.pourIA();
    $('via-date').value = k.date;
    $('via-heure').value = k.heure || '';
    $('via-lieu').value = k.lieu;
    $('via-ville').value = k.ville || '';
    $('via-mentions').value = (k.mentions || []).join(', ');
    if (k.texteInfos) $('via-texte-infos').value = k.texteInfos;
    $('via-lien-infos').value = s.lien || '';
    if (s.photos.length) {
      etat.scenes = classique(s.photos);
      etat.scenes.filter((sc) => sc.type === 'photo').forEach((sc) => {
        sc.texte = maj(s.nom).slice(0, 22);
        sc.texte2 = maj(s.profil === 'produit' ? s.prix : (s.profil === 'evenement' ? s.ville : s.ville || s.sousTitre)).slice(0, 14);
      });
      ajuster(etat.duree);
    }
    const carte = etat.scenes.find((sc) => sc.type === 'concert');
    if (carte && !carte.texte2 && k.etiquette) carte.texte2 = k.etiquette;
    dessinerScenes(); // le scénario a été refait d'après la source : la liste des scènes doit le montrer
    noteSujet();
  };

  // ── Demandes et agent ────────────────────────────────────────────────────────
  const LIB = { pending: 'En attente', rendering: 'Rendu…', done: 'Prête', error: 'Échec' };
  const chargerJobs = async () => {
    try {
      const jobs = await api('/video-jobs');
      $('via-jobs').innerHTML = jobs.length ? jobs.slice(0, 12).map((j) => `
        <li><div><div style="font-weight:700">${esc(j.title || 'Vidéo #' + j.id)}</div>
          <div class="via-hint" style="margin:0">${j.composition.endsWith('-carre') ? 'Carré' : j.composition.endsWith('-libre') && j.props && j.props.ratio ? `${j.props.ratio.largeur}:${j.props.ratio.hauteur}` : 'Vertical'} · ${esc(j.created_at)}</div>
          ${j.error ? `<div class="via-hint" style="margin:0;color:#FF006E">${esc(j.error)}</div>` : ''}</div>
        <div style="display:flex;gap:4px;align-items:center"><span class="via-st ${j.status}">${LIB[j.status] || j.status}</span>
          ${j.output_url ? `<a class="via-btn sm" href="${esc(j.output_url)}" download>MP4</a>` : ''}
          ${j.output_url && window.EB80Validation ? `<button class="via-btn sm" data-valider="${esc(j.output_url)}" data-titre="${esc(j.title || '')}" title="Envoyer à des tiers pour commentaires et validation">✅</button>` : ''}
          ${j.status === 'error' ? `<button class="via-btn sm" data-retry="${j.id}">Relancer</button>` : ''}
          <button class="via-btn sm" data-reprendre="${j.id}" title="Recharger cette vidéo dans l'éditeur pour la modifier">↺</button>
          <button class="via-btn sm" data-suppr="${j.id}" title="Retirer de la liste (le MP4 reste dans la médiathèque)">✕</button></div></li>`).join('')
        : '<li class="via-hint">Aucune vidéo demandée pour l\'instant.</li>';
    } catch (e) { $('via-jobs').innerHTML = `<li class="via-hint">${esc(e.message)}</li>`; }
  };
  const chargerAgent = async () => {
    try {
      const a = await api('/video-jobs/worker');
      if (!a.configured) $('via-agent').textContent = "Aucun agent configuré : générez un jeton puis lancez l'agent sur la machine de rendu.";
      else if (!a.last_seen) $('via-agent').textContent = "Jeton créé, mais l'agent ne s'est pas encore manifesté.";
      else {
        const min = Math.round((Date.now() - new Date(a.last_seen.replace(' ', 'T')).getTime()) / 60000);
        $('via-agent').innerHTML = `Dernier passage : <b>${esc(a.last_seen)}</b>`
          + (min > 5 ? ' — <span style="color:#FF006E">semble arrêté, les vidéos attendront son retour.</span>' : ' — actif.')
          + ((a.version || 0) < VERSION_AGENT
            ? '<br><span style="color:#FF006E">⚠ Agent pas à jour : les MP4 ignoreront animations, palette ou recadrage. '
              + 'Sur la machine de rendu : <code>git pull</code> dans remotion-videos, puis relancer l\'agent.</span>'
            : '');
      }
    } catch (e) { $('via-agent').textContent = e.message; }
  };

  // ── Recadrage ────────────────────────────────────────────────────────────────
  // Même calcul que ScenePhoto (Remotion) : image en « cover » positionnée sur le point de focus,
  // puis zoom autour de ce point. Le fichier n'est jamais modifié, seul sc.cadrage est enregistré.
  const recadrage = { i: 0, c: { x: 50, y: 50, zoom: 1 } };
  const arrondi = (v) => Math.round(v * 10) / 10;
  const dessinerRecadrage = () => {
    const { x, y, zoom } = recadrage.c;
    $('via-crop-zone').style.transform = `scale(${zoom})`;
    $('via-crop-zone').style.transformOrigin = `${x}% ${y}%`;
    $('via-crop-img').style.objectPosition = `${x}% ${y}%`;
    $('via-crop-zoom').value = zoom;
  };
  const ouvrirRecadrage = (i) => {
    const sc = etat.scenes[i];
    recadrage.i = i;
    recadrage.c = { x: 50, y: 50, zoom: 1, ...(sc.cadrage || {}) };
    const fi = infoFormat();
    const cadre = $('via-crop-cadre');
    cadre.style.width = `min(${Math.round(Math.min(400, (480 * fi.l) / fi.h))}px, 70vw)`;
    cadre.style.aspectRatio = `${fi.l} / ${fi.h}`;
    $('via-crop-img').src = sc.photo;
    dessinerRecadrage();
    $('via-crop').showModal();
  };
  const brancherRecadrage = () => {
    const cadre = $('via-crop-cadre');
    let depart = null;
    cadre.addEventListener('pointerdown', (e) => {
      depart = { x: e.clientX, y: e.clientY, c: { ...recadrage.c } };
      cadre.setPointerCapture(e.pointerId);
    });
    cadre.addEventListener('pointermove', (e) => {
      if (!depart) return;
      // L'image suit la souris : le point de focus part dans l'autre sens.
      const r = cadre.getBoundingClientRect();
      const k = 100 / depart.c.zoom;
      recadrage.c.x = arrondi(Math.min(100, Math.max(0, depart.c.x - ((e.clientX - depart.x) / r.width) * k)));
      recadrage.c.y = arrondi(Math.min(100, Math.max(0, depart.c.y - ((e.clientY - depart.y) / r.height) * k)));
      dessinerRecadrage();
    });
    const fin = () => { depart = null; };
    cadre.addEventListener('pointerup', fin);
    cadre.addEventListener('pointercancel', fin);
    $('via-crop-zoom').addEventListener('input', (e) => { recadrage.c.zoom = Number(e.target.value); dessinerRecadrage(); });
  };

  // ── Visuel du lieu ───────────────────────────────────────────────────────────
  // Claude ne lit pas les vidéos : on en extrait une image dans le navigateur (même origine,
  // donc le canvas n'est pas « teinté »). Résultat mis en cache par URL.
  const imagesVisuel = new Map();
  const imageDuVisuel = (url) => {
    if (!url) return Promise.resolve(null);
    if (imagesVisuel.has(url)) return imagesVisuel.get(url);
    const versJpeg = (src, w, h) => {
      const r = Math.min(1, 768 / Math.max(w, h));
      const c = document.createElement('canvas');
      c.width = Math.round(w * r); c.height = Math.round(h * r);
      c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.82);
    };
    const p = new Promise((ok, ko) => {
      if (estVideo(url)) {
        const v = document.createElement('video');
        v.muted = true; v.playsInline = true; v.preload = 'auto'; v.src = url;
        v.onloadedmetadata = () => { v.currentTime = Math.min(1, (v.duration || 2) / 3); };
        v.onseeked = () => { try { ok(versJpeg(v, v.videoWidth, v.videoHeight)); } catch (e) { ko(e); } };
        v.onerror = () => ko(new Error('vidéo illisible dans ce navigateur'));
      } else {
        const im = new Image();
        im.onload = () => { try { ok(versJpeg(im, im.naturalWidth, im.naturalHeight)); } catch (e) { ko(e); } };
        im.onerror = () => ko(new Error('image illisible'));
        im.src = url;
      }
      setTimeout(() => ko(new Error('délai dépassé')), 15000);
    });
    imagesVisuel.set(url, p);
    p.catch(() => imagesVisuel.delete(url));
    return p;
  };
  const choisirVideo = (cb) => viaPickerAdmin(() => window._openPicker('__ev_media_vid__', (url) => {
    if (!estVideo(url)) { alert('Choisissez un fichier vidéo de la médiathèque (pas un lien externe).'); return; }
    cb(url);
  }, null, null));
  const accorder = async (bouton) => {
    const msg = $('via-visuel-msg');
    bouton.disabled = true;
    msg.className = 'via-ia-msg';
    msg.textContent = '⏳ ' + (T.iaNom || 'Claude') + ' regarde le visuel du lieu…';
    try {
      const visuelLieu = await imageDuVisuel(etat.charte.visuel);
      const p = props();
      const r = await api('/video-jobs/ai', { method: 'POST', body: JSON.stringify({ mode: 'charte', visuelLieu, infos: { date: p.date, lieu: p.lieu, ville: p.ville } }) });
      $('via-accent').value = r.accent;
      etat.scenes.filter((sc) => sc.type === 'concert').forEach((sc) => { sc.texte2 = r.label; });
      $('via-charte-on').checked = true;
      dessinerCharte(); dessinerScenes(); majApercu();
      msg.innerHTML = `✓ Accent <b style="color:${esc(r.accent)}">${esc(r.accent)}</b>, étiquette « ${esc(r.label)} ». ${esc(r.explication)}`
        + (r.palette !== $('via-palette').value
          ? ` <button class="via-btn sm" data-palette-lieu="${esc(r.palette)}">Palette conseillée : ${esc(LIB_PALETTES[r.palette] || r.palette)}</button>` : '');
    } catch (e) {
      msg.className = 'via-ia-msg err';
      msg.textContent = 'Erreur : ' + e.message;
    } finally { bouton.disabled = !etat.charte.visuel; }
  };

  // ── Assistant IA ─────────────────────────────────────────────────────────────
  // Applique une proposition de l'IA (scénario, style, musique, légende) à l'éditeur.
  const appliquer = (v) => {
    const s = v.props;
    if (v.accentLieu && etat.charte.visuel) $('via-accent').value = v.accentLieu;
    etat.scenes = s.scenes || [];
    if (s.mentions && s.mentions.length) $('via-mentions').value = s.mentions.join(', ');
    if (s.texteInfos) $('via-texte-infos').value = s.texteInfos;
    $('via-palette').value = LIB_PALETTES[s.palette] ? s.palette : 'neon';
    $('via-badge').value = s.badge || '';
    if (s.musique && s.musique.src) {
      etat.musique = s.musique.src;
      $('via-musique-debut').value = s.musique.debut || 0;
    }
    dessinerMusique();
    $('via-legende').value = [v.legende, (v.hashtags || []).map((h) => '#' + h).join(' ')].filter(Boolean).join('\n\n');
    $('via-publication').hidden = false;
    dessinerScenes();
    majApercu();
  };
  const dessinerVariantes = () => {
    const el = $('via-variantes');
    el.hidden = etat.variantes.length < 2;
    el.innerHTML = etat.variantes.map((v, i) => `<button class="via-variante ${i === etat.variante ? 'on' : ''}" data-variante="${i}">
      <b>${'ABC'[i] || i + 1} · ${esc(v.nom)}</b>${esc(v.explication)}
      <span class="via-hint" style="display:block">Palette : ${esc(LIB_PALETTES[v.props.palette] || LIB_PALETTES.neon)}</span></button>`).join('');
  };
  const proposer = async (bouton, nombre = 1) => {
    const msg = $('via-ia-msg');
    dlg.querySelectorAll('[data-via^=ia]').forEach((b) => { b.disabled = true; });
    msg.className = 'via-ia-msg';
    msg.textContent = nombre > 1
      ? `⏳ ${T.iaNom || 'Claude'} regarde les photos et écrit ${nombre} variantes de ${etat.duree} s… (une à deux minutes)`
      : `⏳ ${T.iaNom || 'Claude'} regarde les photos et écrit un scénario de ${etat.duree} s… (jusqu'à une minute)`;
    try {
      const p = props();
      const r = await api('/video-jobs/ai', {
        method: 'POST',
        body: JSON.stringify({
          infos: p,
          consigne: $('via-consigne').value.trim(),
          visuelLieu: await imageDuVisuel(etat.charte.visuel).catch(() => null),
          duree: etat.duree,
          scenarioActuel: etat.scenes.map((sc) => sc.type),
          variantes: nombre,
          sujet: etat.sujet || null,
        }),
      });
      etat.duree = r.duree || etat.duree;
      etat.variantes = r.variantes && r.variantes.length ? r.variantes : [{ nom: '', ...r }];
      etat.variante = 0;
      appliquer(etat.variantes[0]);
      dessinerVariantes();
      msg.textContent = etat.variantes.length > 1
        ? `✓ ${etat.variantes.length} variantes : cliquez sur l'une d'elles pour la voir dans l'aperçu (vos retouches sur la variante affichée sont perdues en changeant).`
        : '✓ ' + (etat.variantes[0].explication || 'Proposition appliquée.');
    } catch (e) {
      msg.className = 'via-ia-msg err';
      msg.textContent = 'Erreur : ' + e.message;
    } finally {
      dlg.querySelectorAll('[data-via^=ia]').forEach((b) => { b.disabled = false; });
    }
  };

  // ── Interactions ─────────────────────────────────────────────────────────────
  const brancher = () => {
    dlg.addEventListener('close', () => {
      dlg.querySelectorAll('video, audio').forEach((m) => { try { m.pause(); m.currentTime = 0; } catch (e) {} });
    });
    dlg.addEventListener('click', async (ev) => {
      const b = ev.target.closest('button, .via-tile');
      if (!b) return;
      const action = b.dataset.via;
      if (action === 'fermer') dlg.close();
      else if (action === 'ia') proposer(b, 1);
      else if (action === 'ia3') proposer(b, 3);
      else if (b.dataset.variante !== undefined) {
        etat.variante = Number(b.dataset.variante);
        appliquer(etat.variantes[etat.variante]);
        dessinerVariantes();
      }
      else if (action === 'copier') { navigator.clipboard?.writeText($('via-legende').value); b.textContent = 'Copié ✓'; setTimeout(() => { b.textContent = 'Copier légende + hashtags'; }, 1500); }
      else if (action === 'generer') {
        const p = props();
        const cochees = [...dlg.querySelectorAll('#via-lot-formats input[type=checkbox]:checked')].map((x) => x.value);
        const formatsAGenerer = cochees.length ? cochees : [format()];
        b.disabled = true;
        $('via-generer-info').textContent = formatsAGenerer.length > 1 ? `Envoi de ${formatsAGenerer.length} demandes…` : '';
        try {
          for (const fmt of formatsAGenerer) {
            const fi = infoFormat(fmt);
            const pFmt = { ...p, ratio: fi.lecteur === 'libre' ? { largeur: fi.l, hauteur: fi.h } : null };
            const titre = [p.date, p.lieu, fi.libelle].filter(Boolean).join(' · ');
            await api('/video-jobs', { method: 'POST', body: JSON.stringify({ title: titre, composition: fi.composition, props: pFmt, editeur: brouillon() }) });
          }
          $('via-generer-info').textContent = formatsAGenerer.length > 1
            ? `${formatsAGenerer.length} demandes envoyées : l'agent de rendu les prendra à son prochain passage.`
            : "Demande envoyée : l'agent de rendu la prendra à son prochain passage.";
          chargerJobs();
        } catch (e) { $('via-generer-info').textContent = 'Erreur : ' + e.message; }
        finally { b.disabled = false; }
      } else if (action === 'sauver-projet') {
        const nom = ($('via-save-nom').value || '').trim();
        if (!nom) { $('via-save-msg').textContent = 'Entrez un nom de projet.'; return; }
        const slug = nom.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        const filename = `_project_video_${slug}`;
        b.disabled = true;
        $('via-save-msg').textContent = 'Sauvegarde…';
        try {
          await window._doSaveProject(filename, 'video', { videoIA: brouillon() });
          $('via-save-msg').textContent = `Sauvegardé sous "${nom}" ✓`;
          setTimeout(() => { if ($('via-save-msg')) $('via-save-msg').textContent = ''; }, 3000);
        } catch (e) { $('via-save-msg').textContent = 'Erreur : ' + e.message; }
        finally { b.disabled = false; }
      } else if (action === 'toggle-zones') {
        _showVIASafeZones = !_showVIASafeZones;
        b.classList.toggle('on', _showVIASafeZones);
        drawVIASafeZones();
      } else if (action === 'jeton') {
        if (!confirm("Générer un nouveau jeton ? L'ancien cessera de fonctionner.")) return;
        try {
          const { token: j } = await api('/video-jobs/worker-token', { method: 'POST' });
          $('via-agent-cmd').querySelector('pre').textContent = `EB80_SITE=${location.origin} EB80_WORKER_TOKEN=${j} npm run worker`;
          $('via-agent-cmd').hidden = false;
          chargerAgent();
        } catch (e) { alert("Réservé à l'administrateur : " + e.message); }
      } else if (action === 'aucun' || action === 'picker-fermer') {
        if (action === 'aucun') { etat.charte[slot] = ''; dessinerCharte(); majApercu(); }
        $('via-picker').close();
      } else if (b.dataset.slot && pickerAdmin()) {
        // Original (pas la version _web) : garde la transparence des PNG.
        const cible = b.dataset.slot;
        choisirUne((url) => { etat.charte[cible] = url; $('via-charte-on').checked = true; dessinerCharte(); majApercu(); });
      } else if (b.dataset.slot) {
        slot = b.dataset.slot;
        $('via-picker-titre').textContent = NOMS[slot];
        // Original (pas la version _web) : garde la transparence des PNG.
        $('via-picker-grille').innerHTML = images().map((m) =>
          `<div class="via-tile${etat.charte[slot] === m.url ? ' on' : ''}" data-charte="${esc(m.url)}" title="${esc(m.name)}" style="background-image:url('${esc(urlMedia(m))}');background-size:contain"></div>`).join('');
        $('via-picker').showModal();
      } else if (b.dataset.charte) {
        etat.charte[slot] = b.dataset.charte;
        $('via-charte-on').checked = true;
        $('via-picker').close();
        dessinerCharte();
        majApercu();
      } else if (b.dataset.photoChoix !== undefined) {
        etat.scenes[slotScene].photo = b.dataset.photoChoix;
        delete etat.scenes[slotScene].cadrage;
        $('via-picker').close();
        dessinerScenes(); majApercu();
      } else if (b.dataset.photo !== undefined && pickerAdmin()) {
        const i = Number(b.dataset.photo);
        choisirUne((url) => { etat.scenes[i].photo = versionWeb(url); delete etat.scenes[i].cadrage; dessinerScenes(); majApercu(); });
      } else if (b.dataset.photo !== undefined) {
        slotScene = Number(b.dataset.photo);
        $('via-picker-titre').textContent = `Photo de la scène ${slotScene + 1}`;
        $('via-picker-grille').innerHTML = images().map((m) =>
          `<div class="via-tile${etat.scenes[slotScene].photo === urlMedia(m) ? ' on' : ''}" data-photo-choix="${esc(urlMedia(m))}" title="${esc(m.name)}" style="background-image:url('${esc(urlMedia(m))}')"></div>`).join('');
        $('via-picker').showModal();
      } else if (action === 'effacer-photo-scene') {
        const idx = Number(b.dataset.i);
        delete etat.scenes[idx].photo; delete etat.scenes[idx].cadrage;
        dessinerScenes(); majApercu();
      } else if (b.dataset.recadrer !== undefined) {
        ouvrirRecadrage(Number(b.dataset.recadrer));
      } else if (action === 'crop-reset') {
        recadrage.c = { x: 50, y: 50, zoom: 1 }; dessinerRecadrage();
      } else if (action === 'crop-annuler') {
        $('via-crop').close();
      } else if (action === 'crop-ok') {
        const sc = etat.scenes[recadrage.i];
        const c = recadrage.c;
        if (c.x === 50 && c.y === 50 && c.zoom === 1) delete sc.cadrage; else sc.cadrage = c;
        $('via-crop').close();
        dessinerScenes(); majApercu();
      } else if (b.dataset.duree) {
        etat.duree = Number(b.dataset.duree);
        ajuster(etat.duree);
        dessinerScenes(); majApercu();
      } else if (action === 'musique') {
        const appliquer = (url) => {
          if (!estAudio(url)) { alert('Choisissez un fichier audio (mp3, m4a, wav…).'); return; }
          etat.musique = url; dessinerMusique(); majApercu();
        };
        if (pickerAdmin()) choisirAudio(appliquer);
        else {
          const audios = etat.medias.filter((m) => (m.mimetype || '').startsWith('audio/'));
          const choix = prompt('Fichier audio de la médiathèque :\n' + audios.map((m, i) => `${i + 1}. ${m.name}`).join('\n'));
          if (audios[Number(choix) - 1]) appliquer(audios[Number(choix) - 1].url);
        }
      } else if (action === 'musique-aucune') {
        etat.musique = ''; dessinerMusique(); majApercu();
      } else if (action === 'photos-multi') {
        const ajouter = (urls) => {
          const avant = etat.scenes.findIndex((sc) => sc.type === 'concert' || sc.type === 'fin');
          const pos = avant === -1 ? etat.scenes.length : avant;
          etat.scenes.splice(pos, 0, ...urls.map((u) => ({ ...nouvelleScene('photo'), photo: versionWeb(u) })));
          dessinerScenes(); majApercu();
        };
        if (pickerAdmin()) choisirPlusieurs(ajouter);
        else alert('Disponible depuis l\'Éditeur Visuel de l\'admin.');
      } else if (action === 'ajouter') {
        // Les nouvelles scènes se placent avant la carte concert et la fin.
        const type = $('via-ajout').value;
        const avant = etat.scenes.findIndex((sc) => sc.type === 'concert' || sc.type === 'fin');
        const pos = ['concert', 'fin'].includes(type) || avant === -1 ? etat.scenes.length : avant;
        etat.scenes.splice(pos, 0, nouvelleScene(type));
        dessinerScenes(); majApercu();
      } else if (action === 'classique') {
        const anciennes = etat.scenes.filter((sc) => sc.type === 'photo' && sc.photo).slice(0, 5);
        etat.scenes = classique(anciennes.map((sc) => sc.photo));
        // Les photos reprises gardent leur recadrage.
        etat.scenes.filter((sc) => sc.type === 'photo').forEach((sc, k) => {
          if (anciennes[k] && anciennes[k].cadrage) sc.cadrage = anciennes[k].cadrage;
        });
        ajuster(etat.duree);
        dessinerScenes(); majApercu();
      } else if (b.dataset.monter !== undefined || b.dataset.descendre !== undefined) {
        const i = Number(b.dataset.monter ?? b.dataset.descendre);
        const j = b.dataset.monter !== undefined ? i - 1 : i + 1;
        [etat.scenes[i], etat.scenes[j]] = [etat.scenes[j], etat.scenes[i]];
        dessinerScenes(); majApercu();
      } else if (b.dataset.supprimer !== undefined) {
        etat.scenes.splice(Number(b.dataset.supprimer), 1);
        dessinerScenes(); majApercu();
      } else if (b.dataset.valider) {
        window.EB80Validation.creer(b.dataset.valider, b.dataset.valider.split('/').pop(), b.dataset.titre);
      } else if (action === 'ratio-ajout') {
        ajouterRatio();
      } else if (b.dataset.ratioSuppr !== undefined) {
        ev.preventDefault();
        supprimerRatio(Number(b.dataset.ratioSuppr));
      } else if (b.dataset.retirer) {
        etat.charte[b.dataset.retirer] = '';
        dessinerCharte(); majApercu();
      } else if (b.dataset.visuel === 'aucun') {
        etat.charte.visuel = ''; dessinerCharte(); sauver();
      } else if (b.dataset.visuel === 'video' && pickerAdmin()) {
        choisirVideo((url) => { etat.charte.visuel = url; dessinerCharte(); sauver(); });
      } else if (b.dataset.visuel && pickerAdmin()) {
        choisirUne((url) => { etat.charte.visuel = url; dessinerCharte(); sauver(); });
      } else if (b.dataset.visuel) {
        alert('Le choix du visuel passe par la médiathèque de l\'admin.');
      } else if (action === 'accorder') {
        accorder(b);
      } else if (b.dataset.paletteLieu) {
        $('via-palette').value = b.dataset.paletteLieu; b.remove(); majApercu();
      } else if (b.dataset.reprendre) {
        reprendre(Number(b.dataset.reprendre));
      } else if (b.dataset.retry) {
        await api(`/video-jobs/${b.dataset.retry}/retry`, { method: 'POST' }); chargerJobs();
      } else if (b.dataset.suppr) {
        if (confirm('Retirer cette vidéo de la liste ? Le fichier MP4 reste dans la médiathèque.')) {
          await api(`/video-jobs/${b.dataset.suppr}`, { method: 'DELETE' }); chargerJobs();
        }
      }
    });
    CHAMPS.forEach((id) => $(id).addEventListener('input', majApercu));
    $('via-scenes').addEventListener('input', (ev) => { if (ev.target.dataset.champ && ev.target.dataset.champ !== 'type') majChamp(ev.target); });
    $('via-scenes').addEventListener('change', (ev) => { if (ev.target.dataset.champ) majChamp(ev.target); });
    $('via-legende').addEventListener('input', sauver);
    brancherRecadrage();
    $('via-charte-on').addEventListener('change', () => { dessinerCharte(); majApercu(); });
    $('via-formats').addEventListener('change', (e) => {
      if (e.target.name === 'via-format') { etat.format = e.target.value; majApercu(); }
    });
    $('via-lot-formats').addEventListener('change', () => {
      etat.formatsLot = [...dlg.querySelectorAll('#via-lot-formats input[type=checkbox]:checked')].map((x) => x.value);
    });
    $('via-source-type').addEventListener('change', chargerSources);
    $('via-evenement').addEventListener('change', async () => {
      const type = $('via-source-type').value;
      if (type !== 'event') {
        const id = $('via-evenement').value;
        if (!type) { etat.sujet = null; noteSujet(); depuisAffiche(); majApercu(); return; }
        if (!id) { etat.sujet = null; noteSujet(); depuisAffiche(); majApercu(); return; }
        try { await depuisSource(type, id); majApercu(); sauver(); }
        catch (err) { const m = $('via-ia-msg'); m.className = 'via-ia-msg err'; m.textContent = 'Source indisponible : ' + err.message; }
        return;
      }
      etat.sujet = null; noteSujet();
      const e = etat.evenements.find((x) => String(x.id) === $('via-evenement').value);
      if (!e) { depuisAffiche(); majApercu(); return; }
      const d = new Date(e.date + 'T12:00:00');
      $('via-date').value = isNaN(d) ? '' : JOURS.format(d).toUpperCase();
      $('via-heure').value = (e.time || '').replace(/^(\d{1,2}):(\d{2}).*$/, (_, h, m) => `${Number(h)}H${m}`);
      $('via-lieu').value = (e.venue || e.name || '').toUpperCase();
      $('via-ville').value = e.city || '';
      majApercu();
    });
  };

  const creer = () => {
    const style = document.createElement('style');
    style.textContent = CSS;
    document.head.appendChild(style);
    dlg = document.createElement('dialog');
    dlg.className = 'via';
    dlg.innerHTML = HTML;
    document.body.appendChild(dlg);
    brancher();
  };

  let suivi = null;
  const ouvrir = async (options = {}) => {
    if (!dlg) creer();
    if (options.brouillon) appliquerBrouillon(options.brouillon);
    else if (!restaurer()) { depuisAffiche(); etat.scenes = classique([]); ajuster(etat.duree); }
    dessinerFormats();
    dessinerLot();
    chargerRatios();
    dlg.showModal();
    try {
      const [medias] = await Promise.all([fetch('/api/media').then((r) => r.json()), chargerEvenements()]);
      etat.medias = Array.isArray(medias) ? medias : [];
    } catch (e) {
      $('via-ia-msg').className = 'via-ia-msg err';
      $('via-ia-msg').textContent = 'Chargement impossible : ' + e.message;
    }
    // Affiche alimentée par une source (hors événement) : la vidéo reprend le même sujet.
    try {
      await remplirTypes();
      if (!$('via-source-type').value) await chargerSources();
      const ps = typeof POSTER !== 'undefined' && POSTER._source;
      const src = window.EB80Source && window.EB80Source.courante.src;
      if (!options.brouillon && ps && ps.source !== 'event' && src && src.source === ps.source && String(src.id) === String(ps.id)
          && !(etat.sujet && etat.sujet.nom === src.nom) && [...$('via-source-type').options].some((o) => o.value === ps.source)) {
        $('via-source-type').value = ps.source;
        await chargerSources();
        $('via-evenement').value = String(ps.id);
        await depuisSource(ps.source, ps.id);
      }
    } catch (e) { /* la source reste choisissable à la main */ }
    dessinerScenes();
    dessinerCharte();
    dessinerMusique();
    majApercu();
    suggestNom();
    if (options.message) { $('via-ia-msg').className = 'via-ia-msg'; $('via-ia-msg').textContent = options.message; }
    chargerJobs();
    chargerAgent();
    clearInterval(suivi);
    suivi = setInterval(() => { if (dlg.open) { chargerJobs(); chargerAgent(); } else clearInterval(suivi); }, 15000);
  };

  // Reprise d'une vidéo déjà demandée : son état d'éditeur (ou, à défaut, ses réglages de rendu)
  // remplace le brouillon ; la nouvelle génération crée une nouvelle vidéo, l'ancienne reste.
  const reprendre = async (id) => {
    let job;
    try { job = await api(`/video-jobs/${id}`); } catch (e) { alert('Reprise impossible : ' + e.message); return; }
    const enCours = (() => { try { return !!localStorage.getItem(CLE_BROUILLON); } catch (e) { return false; } })();
    if (enCours && !confirm(`Reprendre « ${job.title || 'vidéo #' + job.id} » ? Le brouillon en cours de l'éditeur sera remplacé.`)) return;
    if (dlg && dlg.open) dlg.close();
    etat.variantes = []; etat.variante = 0;
    await ouvrir({
      brouillon: job.editeur || depuisProps(job),
      message: `↺ Vidéo « ${job.title || '#' + job.id} » reprise${job.editeur ? '' : ' (depuis ses réglages de rendu)'} : modifiez-la puis « Générer le MP4 » pour une nouvelle version.`,
    });
    if ($('via-variantes')) $('via-variantes').hidden = true;
  };

  // ── Médiathèque : « Reprendre dans Vidéo IA » sur les vidéos créées ici ─────────
  let reprises = new Map();
  let reprisesLe = 0;
  const enrichirMediatheque = async () => {
    const cartes = document.querySelectorAll('#media-grid .media-card[data-fn] .mc-dropdown');
    if (!cartes.length) return;
    if (Date.now() - reprisesLe > 20000) {
      try {
        const liste = await api('/video-jobs/reprises');
        reprises = new Map();
        liste.forEach((r) => { if (!reprises.has(r.output_url)) reprises.set(r.output_url, r.id); }); // la plus récente
        reprisesLe = Date.now();
      } catch (e) { return; }
    }
    cartes.forEach((menu) => {
      if (menu.querySelector('.via-item')) return;
      const id = reprises.get((window.EB80_UPLOADS || '/uploads/') + menu.closest('.media-card').dataset.fn);
      if (!id) return;
      const item = document.createElement('button');
      item.className = 'mc-dropdown-item via-item';
      item.innerHTML = '<span style="font-size:13px;width:16px;text-align:center">🎬</span><span>Reprendre dans Vidéo IA</span>';
      item.onclick = (e) => {
        e.stopPropagation();
        if (typeof window.closeMcMenu === 'function') window.closeMcMenu();
        reprendre(id);
      };
      menu.prepend(item);
    });
  };
  const brancherMediatheque = () => {
    const grille = document.getElementById('media-grid');
    if (!grille) return;
    let attente = null;
    new MutationObserver(() => { clearTimeout(attente); attente = setTimeout(enrichirMediatheque, 200); })
      .observe(grille, { childList: true, subtree: true });
    enrichirMediatheque();
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', brancherMediatheque);
  else brancherMediatheque();

  const reinitialiser = () => {
    try { localStorage.removeItem(CLE_BROUILLON); } catch(e) {}
    const vide = {
      musique: '', scenes: [], duree: 15,
      charte: { logo: '', texture: '', sol: '', visuel: '' }, charteOn: false,
      format: 'vertical', legende: '',
      'via-date': '', 'via-heure': '', 'via-lieu': '', 'via-ville': '',
      'via-mentions': '', 'via-texte-infos': '', 'via-lien-infos': '',
      'via-badge': '', 'via-palette': 'neon', 'via-scanlines': 'fortes',
      'via-flash': 'fort', 'via-accent': '#F87A00', 'via-consigne': '',
      'via-musique-debut': 0, 'via-musique-volume': 0.9,
    };
    appliquerBrouillon(vide);
    etat.variantes = []; etat.variante = 0;
    if (dlg && dlg.open) {
      const saveNom = $('via-save-nom'); if (saveNom) saveNom.value = '';
      const saveMsg = $('via-save-msg'); if (saveMsg) saveMsg.textContent = '';
      dessinerScenes(); dessinerCharte(); dessinerMusique(); majApercu();
    }
  };

  window.EB80VideoIA = { ouvrir, reprendre, reinitialiser };
})();
