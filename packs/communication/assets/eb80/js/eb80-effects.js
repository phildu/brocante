/**
 * EB80 Effects — Overlay effects engine
 *
 * Sources :
 *   Canvas natif  — grain, scanlines, glitch, VHS, aberration chromatique, bichromie
 *   Three.js r128 — plasma, vague, glitch WebGL, VHS WebGL  (CDN lazy)
 *   Curtains.js 8 — ondulation, vague Curtains               (CDN lazy)
 *   Lottie Web 5  — animation JSON custom                     (CDN lazy)
 *   Hydra Synth   — oscillateur, kaléidoscope, feedback       (CDN lazy)
 *
 * API publique :
 *   EB80Effects.apply(el, config)               — pose un canvas sur un img/video (boutique)
 *   EB80Effects.applyToSlot(slotName, config)   — pose un canvas dans le slot landing
 *   EB80Effects.remove(el)                      — retire l'effet d'un img/video wrappé
 *   EB80Effects.removeFromSlot(slotName)        — retire l'effet du slot landing
 *   EB80Effects.CATALOG                         — métadonnées effets
 *   EB80Effects.list()                          — tableau des effets avec leurs groupes
 */
const EB80Effects = (() => {
  'use strict';

  // ── CDN loader ─────────────────────────────────────────────────────────────
  const _cdnCache = {};
  function _cdn(url, globalName) {
    if (_cdnCache[url]) return _cdnCache[url];
    _cdnCache[url] = new Promise((resolve, reject) => {
      if (globalName && window[globalName]) return resolve(window[globalName]);
      const s = document.createElement('script');
      s.src = url;
      s.onload  = () => resolve(globalName ? window[globalName] : true);
      s.onerror = () => reject(new Error('CDN échec : ' + url));
      document.head.appendChild(s);
    });
    return _cdnCache[url];
  }

  // ── Catalogue des effets ───────────────────────────────────────────────────
  const CATALOG = {
    // ---- Canvas natif (aucune dépendance) -----------------------------------
    grain: {
      label: 'Grain photo', lib: 'canvas', group: 'Canvas',
      params: { intensity: 0.35, opacity: 0.5, speed: 1, blendMode: 'overlay' }
    },
    scanlines: {
      label: 'Scanlines CRT', lib: 'canvas', group: 'Canvas',
      params: { density: 2, opacity: 0.25, speed: 0.5, color: '#000000' }
    },
    glitch: {
      label: 'Glitch', lib: 'canvas', group: 'Canvas',
      params: { intensity: 0.4, opacity: 0.9, speed: 1 }
    },
    vhs: {
      label: 'VHS', lib: 'canvas', group: 'Canvas',
      params: { intensity: 0.5, opacity: 0.7, speed: 1 }
    },
    chromaberr: {
      label: 'Aberration chromatique', lib: 'canvas', group: 'Canvas',
      params: { amount: 8, opacity: 0.9 }
    },
    duotone: {
      label: 'Bichromie', lib: 'canvas', group: 'Canvas',
      params: { colorA: '#ff4400', colorB: '#0044ff', opacity: 0.7 }
    },
    halo: {
      label: 'Halo pulsé', lib: 'canvas', group: 'Canvas',
      params: { seuil: 0.45, rayon: 26, gain: 2.2, opacity: 0.85, speed: 1,
                creux: 0.4, derive: 0, teinte: '#ff8844', melange: 0,
                blendMode: 'screen' }
    },

    // ---- Three.js GLSL (CDN) ------------------------------------------------
    plasma: {
      label: 'Plasma', lib: 'threejs', group: 'Three.js',
      params: { mix: 0.4, speed: 1, opacity: 1 }
    },
    wave: {
      label: 'Vague / Distorsion', lib: 'threejs', group: 'Three.js',
      params: { amplitude: 0.02, frequency: 8, speed: 1, opacity: 1 }
    },
    glitch3: {
      label: 'Glitch WebGL', lib: 'threejs', group: 'Three.js',
      params: { intensity: 0.4, speed: 1, opacity: 1 }
    },
    vhs3: {
      label: 'VHS WebGL', lib: 'threejs', group: 'Three.js',
      params: { intensity: 0.5, speed: 1, opacity: 1 }
    },

    // ---- Curtains.js (CDN) --------------------------------------------------
    'curtains-ripple': {
      label: 'Ondulation (Curtains)', lib: 'curtains', group: 'Curtains.js',
      params: { amplitude: 25, speed: 1, opacity: 1 }
    },
    'curtains-wave': {
      label: 'Vague (Curtains)', lib: 'curtains', group: 'Curtains.js',
      params: { amplitude: 0.04, frequency: 4, speed: 1, opacity: 1 }
    },

    // ---- Lottie Web (CDN) ---------------------------------------------------
    lottie: {
      label: 'Animation Lottie (JSON)', lib: 'lottie', group: 'Lottie Web',
      params: { jsonUrl: '', opacity: 0.8, speed: 1, loop: true }
    },

    // ---- Hydra Synth (CDN) --------------------------------------------------
    'hydra-osc': {
      label: 'Oscillateur (Hydra)', lib: 'hydra', group: 'Hydra Synth',
      params: { freq: 60, opacity: 0.35, blendMode: 'screen' }
    },
    'hydra-kaleido': {
      label: 'Kaléidoscope (Hydra)', lib: 'hydra', group: 'Hydra Synth',
      params: { n: 4, opacity: 0.45, blendMode: 'overlay' }
    },
    'hydra-feedback': {
      label: 'Feedback (Hydra)', lib: 'hydra', group: 'Hydra Synth',
      params: { scale: 1.002, opacity: 0.4, blendMode: 'screen' }
    },

    // ---- GLSL Sandbox -------------------------------------------------------
    glslsandbox: {
      label: 'GLSL Sandbox', lib: 'glslsandbox', group: 'GLSL Sandbox',
      params: { glsl: '', opacity: 1, blendMode: 'normal' }
    },
  };

  // ── Helpers canvas ─────────────────────────────────────────────────────────
  function _newCanvas(container, zIndex) {
    const c = document.createElement('canvas');
    c.className = 'eb80-fx-canvas';
    c.style.cssText = `position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:${zIndex || 3}`;
    c.width  = container.offsetWidth  || 300;
    c.height = container.offsetHeight || 200;
    container.appendChild(c);
    return c;
  }

  function _sync(container, canvas) {
    const w = container.offsetWidth  || 1;
    const h = container.offsetHeight || 1;
    if (canvas.width !== w || canvas.height !== h) { canvas.width = w; canvas.height = h; }
    return { w, h };
  }

  // Wraps an img/video in a relative div and returns container + canvas.
  // Used by apply() for boutique product cards.
  function _wrapEl(el) {
    const parent = el.parentElement;
    const wrap = document.createElement('div');
    wrap.className = 'eb80-fx-wrap';
    const cs = getComputedStyle(el);
    wrap.style.cssText = `position:relative;display:${cs.display === 'inline' ? 'inline-block' : 'block'};width:${cs.width};height:${cs.height}`;
    parent.insertBefore(wrap, el);
    wrap.appendChild(el);
    const canvas = _newCanvas(wrap, 3);
    return { container: wrap, canvas, wrapped: true };
  }

  // ── Canvas effects ─────────────────────────────────────────────────────────

  function _runGrain(container, canvas, params) {
    const ctx = canvas.getContext('2d');
    let raf;
    function draw() {
      const { w, h } = _sync(container, canvas);
      const id = ctx.createImageData(w, h);
      const d = id.data;
      const range = (params.intensity || 0.35) * 255;
      for (let i = 0; i < d.length; i += 4) {
        const v = 128 + (Math.random() - 0.5) * range;
        d[i] = d[i+1] = d[i+2] = v; d[i+3] = 255;
      }
      ctx.putImageData(id, 0, 0);
      canvas.style.opacity = params.opacity || 0.5;
      canvas.style.mixBlendMode = params.blendMode || 'overlay';
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  function _runScanlines(container, canvas, params) {
    const ctx = canvas.getContext('2d');
    let raf, off = 0;
    function draw() {
      const { w, h } = _sync(container, canvas);
      ctx.clearRect(0, 0, w, h);
      const gap = Math.max(1, params.density || 2);
      ctx.fillStyle = params.color || '#000000';
      for (let y = off % (gap * 2); y < h; y += gap * 2) ctx.fillRect(0, y, w, gap);
      canvas.style.opacity = params.opacity || 0.25;
      canvas.style.mixBlendMode = 'multiply';
      off += (params.speed || 0.5) * 0.4;
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  function _runGlitch(container, canvas, params) {
    const ctx = canvas.getContext('2d');
    const COLS = ['rgba(255,0,0,.18)', 'rgba(0,255,240,.15)', 'rgba(255,220,0,.12)'];
    let raf, frame = 0;
    function draw() {
      const { w, h } = _sync(container, canvas);
      frame++;
      const rate = Math.max(2, Math.round(6 / (params.speed || 1)));
      if (frame % rate === 0 && Math.random() < (params.intensity || 0.4)) {
        ctx.clearRect(0, 0, w, h);
        const n = Math.floor(Math.random() * 5) + 2;
        for (let i = 0; i < n; i++) {
          const y   = Math.random() * h;
          const sh  = Math.random() * 14 + 2;
          const xOf = (Math.random() - .5) * w * .25 * (params.intensity || .4);
          ctx.fillStyle = COLS[i % COLS.length];
          ctx.fillRect(xOf, y, w, sh);
          ctx.fillStyle = 'rgba(255,255,255,.04)';
          ctx.fillRect(0, y, w, sh);
        }
      } else if (frame % 4 === 0) ctx.clearRect(0, 0, w, h);
      canvas.style.opacity = params.opacity || 0.9;
      canvas.style.mixBlendMode = 'screen';
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  function _runVHS(container, canvas, params) {
    const ctx = canvas.getContext('2d');
    let raf;
    function draw() {
      const { w, h } = _sync(container, canvas);
      ctx.clearRect(0, 0, w, h);
      const k = params.intensity || 0.5;
      ctx.fillStyle = 'rgba(0,0,0,.025)';
      for (let y = 0; y < h; y += 2) ctx.fillRect(0, y, w, 1);
      if (Math.random() < .08) {
        const by = Math.random() * h, bh = Math.min((Math.random() * 18 + 3) | 0, h - by | 0);
        if (bh > 0) {
          const id = ctx.createImageData(w, bh);
          for (let i = 0; i < id.data.length; i += 4) {
            id.data[i] = id.data[i+1] = id.data[i+2] = Math.random() * 255;
            id.data[i+3] = 40 * k;
          }
          ctx.putImageData(id, 0, by);
        }
      }
      if (Math.random() < .04 * k) {
        const y = Math.random() * h;
        ctx.fillStyle = 'rgba(255,0,50,.07)'; ctx.fillRect(0, y, w * (.4 + Math.random() * .6), 1);
        ctx.fillStyle = 'rgba(0,200,255,.05)'; ctx.fillRect(0, y+1, w * Math.random() * .7, 1);
      }
      canvas.style.opacity = params.opacity || 0.7;
      canvas.style.mixBlendMode = 'screen';
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  function _runChromaberr(container, canvas, params, sourceEl) {
    const ctx = canvas.getContext('2d');
    let raf;
    function draw() {
      const { w, h } = _sync(container, canvas);
      const img = sourceEl || container.querySelector('img');
      if (!img || !img.complete || !img.naturalWidth) { raf = requestAnimationFrame(draw); return; }
      const tmp = document.createElement('canvas');
      tmp.width = w; tmp.height = h;
      const t = tmp.getContext('2d');
      try {
        t.drawImage(img, 0, 0, w, h);
        const src = t.getImageData(0, 0, w, h);
        const out = ctx.createImageData(w, h);
        const sd = src.data, od = out.data;
        const amt = Math.max(1, params.amount || 8) | 0;
        for (let y = 0; y < h; y++) {
          for (let x = 0; x < w; x++) {
            const i  = (y * w + x) * 4;
            const ir = (y * w + Math.min(w-1, x + amt)) * 4;
            const ib = (y * w + Math.max(0, x - amt)) * 4;
            od[i]   = sd[ir];    od[i+1] = sd[i+1];
            od[i+2] = sd[ib+2]; od[i+3] = sd[i+3];
          }
        }
        ctx.putImageData(out, 0, 0);
        canvas.style.mixBlendMode = 'normal';
        canvas.style.opacity = params.opacity || 0.9;
      } catch(e) {}
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  /**
   * Halo pulsé — fait respirer les hautes lumières d'une photo.
   *
   * Ne floute pas l'image : ne récolte que les pixels au-dessus d'un seuil de
   * luminosité, les étale, et superpose le résultat en « screen » avec une
   * opacité qui bat.
   *
   * La photo ne bougeant pas, la couche de halo est calculée une seule fois et
   * mise en cache ; chaque image ne fait plus que changer une opacité. Le
   * calcul se fait au quart de la définition — un halo est basse fréquence,
   * la perte ne se voit pas et le coût est divisé par seize.
   */
  function _runHalo(container, canvas, params, sourceEl) {
    const ctx = canvas.getContext('2d');
    let raf, couche = null, cle = '';

    function construire(w, h, img) {
      const ech = 4;
      const pw = Math.max(1, Math.round(w / ech));
      const ph = Math.max(1, Math.round(h / ech));

      const petit = document.createElement('canvas');
      petit.width = pw; petit.height = ph;
      const pc = petit.getContext('2d', { willReadFrequently: true });
      pc.drawImage(img, 0, 0, pw, ph);

      let d;
      try { d = pc.getImageData(0, 0, pw, ph); }
      catch (e) { return null; }          // image d'un autre domaine : canvas verrouillé

      const px = d.data;
      const seuil = Math.max(0, Math.min(1, params.seuil ?? 0.45)) * 255;
      const plage = Math.max(1, 255 - seuil);
      const rFlou = Math.max(1, (params.rayon ?? 26) / ech);
      // Un flou conserve l'énergie mais écrase le pic, d'autant plus que le
      // rayon est grand : sans rattrapage, un halo large s'éteint. On sature
      // donc le cœur AVANT d'étaler. Compenser après reviendrait à étirer un
      // dégradé déjà quantifié sur 8 bits, ce qui fait apparaître des anneaux.
      const rattrapage = 1 + rFlou * 0.85;
      for (let i = 0; i < px.length; i += 4) {
        const lum = 0.2126 * px[i] + 0.7152 * px[i + 1] + 0.0722 * px[i + 2];
        const k = lum <= seuil ? 0 : (lum - seuil) / plage;
        // Courbe quadratique : les très hautes lumières dominent nettement,
        // sinon tout le ciel gris participerait au halo.
        px[i + 3] = Math.min(255, 255 * k * k * rattrapage);
      }
      pc.putImageData(d, 0, 0);

      // Étalement. Le rayon est exprimé en pixels de l'affichage, donc divisé
      // par l'échelle de travail.
      const flou = document.createElement('canvas');
      flou.width = pw; flou.height = ph;
      // Pas de willReadFrequently ici : cette couche n'est plus relue, et le
      // drapeau bascule le canvas en rendu logiciel — or on la redessine à
      // chaque image. Il coûtait la moitié de la fluidité.
      const fc = flou.getContext('2d');
      fc.filter = 'blur(' + rFlou + 'px)';
      fc.drawImage(petit, 0, 0);

      return flou;
    }

    function draw(ms) {
      const { w, h } = _sync(container, canvas);
      const img = sourceEl || container.querySelector('img');
      if (!img || !img.complete || !img.naturalWidth || !w || !h) {
        raf = requestAnimationFrame(draw); return;
      }

      const cleNow = (img.currentSrc || img.src) + '|' + w + 'x' + h + '|'
                   + params.seuil + '|' + params.rayon;
      if (cleNow !== cle) { couche = construire(w, h, img); cle = cleNow; }

      ctx.clearRect(0, 0, w, h);
      if (couche) {
        const t = ms / 1000 * (params.speed ?? 1);
        // Deux battements de fréquences voisines : la respiration se déphase
        // lentement et ne se répète pas à l'identique, là où un sinus seul
        // donnerait une pulsation mécanique.
        const brut = 0.5 + 0.34 * Math.sin(t * 0.85) + 0.16 * Math.sin(t * 1.37 + 1.7);
        const creux = Math.max(0, Math.min(1, params.creux ?? 0.4));
        const battement = creux + (1 - creux) * Math.max(0, Math.min(1, brut));

        // L'étalement dilue fortement le halo : une tache vive étalée sur
        // trente pixels perd l'essentiel de son intensité. On compense en
        // cumulant plusieurs tirages en « lighter » — globalAlpha plafonnant
        // à 1, c'est le seul moyen d'amplifier au-delà de la source.
        // Dérive de teinte : sur une fréquence plus lente que le battement,
        // pour que couleur et intensité ne montent pas ensemble — sans quoi
        // on lirait un simple clignotement coloré.
        const derive = +(params.derive ?? 0);
        if (derive > 0) {
          const angle = derive * Math.sin(t * 0.37 + 0.9);
          ctx.filter = 'hue-rotate(' + angle.toFixed(1) + 'deg)';
        }

        let reste = Math.max(0, (params.gain ?? 2.2) * battement);
        ctx.globalCompositeOperation = 'lighter';
        for (let n = 0; n < 8 && reste > 0.01; n++) {
          ctx.globalAlpha = Math.min(1, reste);
          ctx.drawImage(couche, 0, 0, w, h);
          reste -= 1;
        }
        ctx.filter = 'none';

        // Teinte fixe : appliquée seulement là où il y a déjà du halo,
        // pour ne pas peindre un voile uniforme sur toute l'image.
        const melange = Math.max(0, Math.min(1, +(params.melange ?? 0)));
        if (melange > 0 && params.teinte) {
          ctx.globalCompositeOperation = 'source-atop';
          ctx.globalAlpha = melange;
          ctx.fillStyle = params.teinte;
          ctx.fillRect(0, 0, w, h);
        }

        ctx.globalCompositeOperation = 'source-over';
        ctx.globalAlpha = 1;
      }
      canvas.style.mixBlendMode = params.blendMode || 'screen';
      canvas.style.opacity = params.opacity ?? 0.75;
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => cancelAnimationFrame(raf);
  }

  function _runDuotone(container, canvas, params, sourceEl) {
    const ctx = canvas.getContext('2d');
    let raf;
    function hr(h) { return [1,3,5].map(i => parseInt((h||'#000').slice(i,i+2),16)); }
    function draw() {
      const { w, h } = _sync(container, canvas);
      const img = sourceEl || container.querySelector('img');
      if (!img || !img.complete || !img.naturalWidth) { raf = setTimeout(() => requestAnimationFrame(draw), 300); return; }
      const tmp = document.createElement('canvas');
      tmp.width = w; tmp.height = h;
      const t = tmp.getContext('2d');
      try {
        t.drawImage(img, 0, 0, w, h);
        const id = t.getImageData(0, 0, w, h); const d = id.data;
        const [r1,g1,b1] = hr(params.colorA || '#ff4400');
        const [r2,g2,b2] = hr(params.colorB || '#0044ff');
        for (let i = 0; i < d.length; i += 4) {
          const l = (d[i]*.299 + d[i+1]*.587 + d[i+2]*.114) / 255;
          d[i] = r1*(1-l)+r2*l|0; d[i+1] = g1*(1-l)+g2*l|0; d[i+2] = b1*(1-l)+b2*l|0;
        }
        ctx.putImageData(id, 0, 0);
        canvas.style.mixBlendMode = 'normal';
        canvas.style.opacity = params.opacity || 0.7;
      } catch(e) {}
      raf = setTimeout(() => requestAnimationFrame(draw), 400);
    }
    raf = requestAnimationFrame(draw);
    return () => { cancelAnimationFrame(raf); clearTimeout(raf); };
  }

  // ── Three.js GLSL ──────────────────────────────────────────────────────────
  const THREE_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js';
  const _VERT = `varying vec2 vUv;void main(){vUv=uv;gl_Position=vec4(position,1.0);}`;
  const _FRAGS = {
    plasma: `
      uniform sampler2D tD;uniform float uT;uniform float uMix;varying vec2 vUv;
      void main(){
        vec4 s=texture2D(tD,vUv);
        float v=sin(vUv.x*10.+uT)+sin(vUv.y*10.+uT*1.3)
               +sin((vUv.x+vUv.y)*10.+uT*.7)
               +sin(sqrt(dot(vUv-.5,vUv-.5))*15.+uT*2.);
        v=(v+4.)/8.;
        vec3 c=vec3(sin(v*3.14159),sin(v*3.14159+2.094),sin(v*3.14159+4.189))*.5+.5;
        gl_FragColor=vec4(mix(s.rgb,c,uMix),s.a);
      }`,
    wave: `
      uniform sampler2D tD;uniform float uT;uniform float uA;uniform float uF;varying vec2 vUv;
      void main(){
        vec2 uv=vUv;
        uv.x+=sin(uv.y*uF+uT*2.)*uA;
        uv.y+=cos(uv.x*uF+uT*2.)*uA*.5;
        gl_FragColor=texture2D(tD,clamp(uv,0.,1.));
      }`,
    glitch3: `
      uniform sampler2D tD;uniform float uT;uniform float uI;varying vec2 vUv;
      float rnd(float n){return fract(sin(n)*43758.5453);}
      void main(){
        float t=floor(uT*8.)/8.,st=floor(vUv.y*40.)/40.;
        float off=0.;
        if(rnd(st+t)<uI*.4) off=(rnd(st+t+1.7)-.5)*uI*.15;
        vec2 uv=vec2(fract(vUv.x+off),vUv.y);
        vec4 c=texture2D(tD,uv);
        float ro=rnd(st+t+2.3)*.01*uI;
        c.r=texture2D(tD,vec2(fract(uv.x+ro),uv.y)).r;
        gl_FragColor=c;
      }`,
    vhs3: `
      uniform sampler2D tD;uniform float uT;uniform float uI;varying vec2 vUv;
      float rnd(vec2 c){return fract(sin(dot(c,vec2(12.9898,78.233)))*43758.5453);}
      void main(){
        float wob=sin(uT*3.+vUv.y*50.)*.003*uI;
        float nz=(rnd(vec2(floor(vUv.y*200.),floor(uT*25.)))-.5)*.025*uI;
        vec2 uv=clamp(vec2(vUv.x+wob+nz,vUv.y),0.,1.);
        vec4 c=texture2D(tD,uv);
        c.rgb+=sin(vUv.y*600.)*.02*uI;
        c.r+=rnd(vec2(uv.y,floor(uT*30.)))*.018*uI;
        gl_FragColor=c;
      }`,
  };

  function _imgToTexture(img, THREE) {
    if (!img || !img.complete || !img.naturalWidth) return null;
    try {
      const tmp = document.createElement('canvas');
      tmp.width  = img.naturalWidth;
      tmp.height = img.naturalHeight;
      tmp.getContext('2d').drawImage(img, 0, 0);
      return new THREE.CanvasTexture(tmp);
    } catch(_) { return null; }
  }

  async function _runThreejs(container, canvas, effectId, params, sourceEl) {
    const THREE = await _cdn(THREE_CDN, 'THREE');
    const renderer = new THREE.WebGLRenderer({ canvas, alpha: true, antialias: false });
    renderer.setSize(canvas.width, canvas.height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

    const img = (sourceEl instanceof HTMLImageElement) ? sourceEl : container.querySelector('img');
    let texture = null;

    if (img && img.complete && img.naturalWidth) {
      // Image already loaded — use CanvasTexture directly (no HTTP request, no CORS issue)
      texture = _imgToTexture(img, THREE);
    } else if (img && !img.complete) {
      // Wait for image to finish loading
      texture = await new Promise(resolve => {
        img.addEventListener('load',  () => resolve(_imgToTexture(img, THREE)), { once: true });
        img.addEventListener('error', () => resolve(null), { once: true });
      });
    }

    if (!texture) {
      const vid = (sourceEl instanceof HTMLVideoElement) ? sourceEl
        : container.querySelector('video[style*="display:block"],video:not([style*="display:none"])');
      texture = vid ? new THREE.VideoTexture(vid)
                    : new THREE.DataTexture(new Uint8Array([50,50,50,255]), 1, 1);
    }

    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-1,1,1,-1,0,1);
    const mat = new THREE.ShaderMaterial({
      vertexShader: _VERT,
      fragmentShader: _FRAGS[effectId] || _FRAGS.vhs3,
      uniforms: {
        tD: { value: texture },
        uT: { value: 0 },
        uI: { value: params.intensity || 0.5 },
        uMix: { value: params.mix || 0.4 },
        uA:  { value: params.amplitude || 0.02 },
        uF:  { value: params.frequency || 8 },
      },
      transparent: true,
    });
    scene.add(new THREE.Mesh(new THREE.PlaneGeometry(2, 2), mat));

    let raf, t0 = Date.now();
    function draw() {
      const { w, h } = _sync(container, canvas);
      renderer.setSize(w, h);
      mat.uniforms.uT.value = (Date.now()-t0) / 1000 * (params.speed || 1);
      if (texture.isVideoTexture) texture.needsUpdate = true;
      renderer.render(scene, camera);
      canvas.style.opacity = params.opacity != null ? params.opacity : 1;
      canvas.style.mixBlendMode = 'normal';
      raf = requestAnimationFrame(draw);
    }
    raf = requestAnimationFrame(draw);
    return () => { cancelAnimationFrame(raf); renderer.dispose(); mat.dispose(); };
  }

  // ── Curtains.js ────────────────────────────────────────────────────────────
  const CURTAINS_CDN = 'https://unpkg.com/curtainsjs@8.1.6/dist/curtains.umd.min.js';
  const _CV = `
    #ifdef GL_ES
    precision mediump float;
    #endif
    attribute vec3 aVertexPosition;attribute vec2 aTextureCoord;
    uniform mat4 uMVMatrix;uniform mat4 uPMatrix;uniform mat4 uTextureMatrix0;
    varying vec3 vPos;varying vec2 vTex;
    void main(){vTex=(uTextureMatrix0*vec4(aTextureCoord,0.,1.)).xy;vPos=aVertexPosition;gl_Position=uPMatrix*uMVMatrix*vec4(aVertexPosition,1.);}`;
  const _CFS = {
    'curtains-ripple': `
      #ifdef GL_ES
      precision mediump float;
      #endif
      uniform sampler2D uSampler0;uniform float uT;uniform float uAmp;
      varying vec2 vTex;
      void main(){
        float d=distance(vTex,vec2(.5));
        vec2 uv=vTex;
        uv.x+=sin(d*40.-uT*3.)*(uAmp/1000.)*(1.-d);
        uv.y+=cos(d*40.-uT*3.)*(uAmp/1000.)*(1.-d);
        gl_FragColor=texture2D(uSampler0,uv);
      }`,
    'curtains-wave': `
      #ifdef GL_ES
      precision mediump float;
      #endif
      uniform sampler2D uSampler0;uniform float uT;uniform float uAmp;uniform float uF;
      varying vec2 vTex;
      void main(){
        vec2 uv=vTex;
        uv.x+=sin(uv.y*uF+uT*2.)*uAmp;
        gl_FragColor=texture2D(uSampler0,clamp(uv,0.,1.));
      }`,
  };

  async function _runCurtains(container, effectId, params, sourceEl) {
    const cjs = await _cdn(CURTAINS_CDN, 'curtainsjs');
    const Curtains = cjs && (cjs.Curtains || cjs);
    if (!Curtains || typeof Curtains !== 'function') { console.warn('EB80Effects: Curtains.js indisponible'); return null; }

    const img = sourceEl || container.querySelector('img');
    if (!img) return null;
    img.crossOrigin = 'anonymous';

    const curtainsInst = new Curtains({ container });
    curtainsInst.onError(() => { console.warn('EB80Effects: Curtains erreur WebGL'); });

    const plane = curtainsInst.addPlane(img, {
      vertexShader: _CV,
      fragmentShader: _CFS[effectId] || _CFS['curtains-ripple'],
      uniforms: {
        uT:   { name: 'uT',   type: '1f', value: 0 },
        uAmp: { name: 'uAmp', type: '1f', value: params.amplitude || 25 },
        uF:   { name: 'uF',   type: '1f', value: params.frequency || 4 },
      },
    });

    if (plane) curtainsInst.onRender(() => { plane.uniforms.uT.value += 0.016 * (params.speed || 1); });

    return () => { try { curtainsInst.dispose(); } catch(e) {} };
  }

  // ── Lottie Web ─────────────────────────────────────────────────────────────
  const LOTTIE_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js';

  async function _runLottie(container, params) {
    if (!params.jsonUrl) { console.warn('EB80Effects: lottie — jsonUrl manquant'); return null; }
    const lottie = await _cdn(LOTTIE_CDN, 'lottie');

    const div = document.createElement('div');
    div.className = 'eb80-lottie-wrap';
    div.style.cssText = `position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:3;opacity:${params.opacity || 0.8}`;
    container.appendChild(div);

    let data;
    try { const r = await fetch(params.jsonUrl); data = await r.json(); }
    catch(e) { console.error('EB80Effects: Lottie JSON load error', e); div.remove(); return null; }

    const anim = lottie.loadAnimation({
      container: div, renderer: 'canvas',
      loop: params.loop !== false, autoplay: true, animationData: data,
    });
    anim.setSpeed(params.speed || 1);

    return () => { anim.destroy(); div.remove(); };
  }

  // ── Hydra Synth ────────────────────────────────────────────────────────────
  const HYDRA_CDN = 'https://unpkg.com/hydra-synth@1.3.29/dist/hydra-synth.js';

  const _HYDRA_PROGS = {
    'hydra-osc': (h, params) => {
      h.osc(params.freq || 60, 0.1, 0.8).color(1, 0, 1).blend(h.src(h.o0), 0.5).out(h.o0);
    },
    'hydra-kaleido': (h, params) => {
      h.osc(10, 0.1, 0.8).kaleid(params.n || 4).rotate(0.05).out(h.o0);
    },
    'hydra-feedback': (h, params) => {
      h.src(h.o0).scale(params.scale || 1.002).rotate(0.003).blend(h.src(h.o0), 0.9).out(h.o0);
    },
  };

  async function _runHydra(container, effectId, params) {
    await _cdn(HYDRA_CDN, 'Hydra');
    const Hydra = window.Hydra;
    if (!Hydra) { console.warn('EB80Effects: Hydra indisponible'); return null; }

    const canvas = document.createElement('canvas');
    canvas.className = 'eb80-fx-canvas';
    canvas.width  = container.offsetWidth  || 400;
    canvas.height = container.offsetHeight || 300;
    canvas.style.cssText = `position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:3;opacity:${params.opacity || 0.4};mix-blend-mode:${params.blendMode || 'screen'}`;
    container.appendChild(canvas);

    try {
      const hydra = new Hydra({ canvas, detectAudio: false, makeGlobal: false, width: canvas.width, height: canvas.height });
      const h = hydra.synth;
      const prog = _HYDRA_PROGS[effectId];
      if (prog) prog(h, params);
      return () => { try { hydra.synth.hush(); } catch(e) {} canvas.remove(); };
    } catch(e) {
      console.warn('EB80Effects: Hydra init error', e);
      canvas.remove();
      return null;
    }
  }

  // ── Registre des handles actifs ────────────────────────────────────────────
  const _el_handles   = new Map(); // apply() → keyed by original element
  const _slot_handles = new Map(); // applyToSlot() → keyed by slotName

  // ── CSS Filter effect ──────────────────────────────────────────────────────
  function _runCSS(container, params, sourceEl) {
    if (!sourceEl) return null;
    const orig = sourceEl.style.filter || '';
    function _apply(p) {
      const parts = [];
      if (p.grayscale  > 0)                        parts.push(`grayscale(${p.grayscale})`);
      if (p.sepia      > 0)                        parts.push(`sepia(${p.sepia})`);
      if (p.hueRotate  != null && p.hueRotate !== 0) parts.push(`hue-rotate(${p.hueRotate}deg)`);
      if (p.saturate   != null && p.saturate  !== 1) parts.push(`saturate(${p.saturate})`);
      if (p.brightness != null && p.brightness !== 1) parts.push(`brightness(${p.brightness})`);
      if (p.contrast   != null && p.contrast  !== 1)  parts.push(`contrast(${p.contrast})`);
      if (p.invert     > 0)                        parts.push(`invert(${p.invert})`);
      if (p.blur       > 0)                        parts.push(`blur(${p.blur}px)`);
      sourceEl.style.filter = parts.length ? parts.join(' ') : '';
    }
    _apply(params);
    return () => { sourceEl.style.filter = orig; };
  }

  // ── Tint (color overlay) effect ────────────────────────────────────────────
  function _runTint(container, params) {
    const div = document.createElement('div');
    div.className = 'eb80-tint-overlay';
    const hex = params.color || '#ff006e';
    const r = parseInt(hex.slice(1,3),16)||0, g = parseInt(hex.slice(3,5),16)||0, b = parseInt(hex.slice(5,7),16)||0;
    const op = params.opacity != null ? parseFloat(params.opacity) : 0.4;
    const bm = params.blendMode || 'multiply';
    div.style.cssText = `position:absolute;inset:0;pointer-events:none;z-index:2;background:rgba(${r},${g},${b},${op});mix-blend-mode:${bm}`;
    container.appendChild(div);
    return () => { if (div.parentElement) div.remove(); };
  }

  // ── ShaderToy effect ──────────────────────────────────────────────────────
  // Uniforms Shadertoy communs (déclarés dans le wrapper)
  const _ST_UNIFORMS = `uniform vec3  iResolution;
uniform float iTime;
uniform float iTimeDelta;
uniform int   iFrame;
uniform vec4  iMouse;
uniform vec4  iDate;
uniform float iSampleRate;
uniform vec3  iChannelResolution[4];
uniform sampler2D iChannel0;
uniform sampler2D iChannel1;
uniform sampler2D iChannel2;
uniform sampler2D iChannel3;`;

  function _runShaderToy(container, canvas, params) {
    const glsl = (params.glsl || '').trim();
    if (!glsl) { console.warn('[EB80Effects] ShaderToy: code GLSL vide'); return null; }

    // Préférer WebGL2 : supporte GLSL ES 3.00 (boucles dynamiques, texture(), etc.)
    const gl2 = canvas.getContext('webgl2');
    const gl   = gl2 || canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    if (!gl) { console.warn('[EB80Effects] ShaderToy: WebGL indisponible'); return null; }

    let VERT, FRAG;
    if (gl2) {
      VERT = `#version 300 es\nin vec2 a_pos;\nvoid main(){gl_Position=vec4(a_pos,0.,1.);}`;
      FRAG = `#version 300 es
precision highp float;
precision highp int;
${_ST_UNIFORMS}
${glsl}
out vec4 _fragOut;
void main(){ _fragOut=vec4(0.); mainImage(_fragOut,gl_FragCoord.xy); }`;
    } else {
      VERT = `attribute vec2 a_pos;\nvoid main(){gl_Position=vec4(a_pos,0.0,1.0);}`;
      FRAG = `precision highp float;
precision highp int;
${_ST_UNIFORMS}
${glsl}
void main(){ vec4 c=vec4(0.); mainImage(c,gl_FragCoord.xy); gl_FragColor=c; }`;
    }

    function _compile(type, src) {
      const s = gl.createShader(type);
      gl.shaderSource(s, src);
      gl.compileShader(s);
      if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
        console.error('[EB80Effects] ShaderToy compile:', gl.getShaderInfoLog(s));
        gl.deleteShader(s); return null;
      }
      return s;
    }

    const vs = _compile(gl.VERTEX_SHADER, VERT);
    const fs = _compile(gl.FRAGMENT_SHADER, FRAG);
    if (!vs || !fs) return null;

    const prog = gl.createProgram();
    gl.attachShader(prog, vs); gl.attachShader(prog, fs);
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
      console.error('[EB80Effects] ShaderToy link:', gl.getProgramInfoLog(prog)); return null;
    }
    gl.useProgram(prog);

    // Quad plein écran
    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 1,-1, -1,1, 1,1]), gl.STATIC_DRAW);
    const aPos = gl.getAttribLocation(prog, 'a_pos');
    gl.enableVertexAttribArray(aPos);
    gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

    const U = n => gl.getUniformLocation(prog, n);
    const uRes=U('iResolution'), uTime=U('iTime'), uDelta=U('iTimeDelta'),
          uFrame=U('iFrame'),    uMouse=U('iMouse'), uDate=U('iDate');

    let raf, frame=0, prev=performance.now(), t0=performance.now();

    function draw(now) {
      const { w, h } = _sync(container, canvas);
      gl.viewport(0, 0, w, h);
      const t  = (now - t0)   / 1000;
      const dt = (now - prev) / 1000;
      prev = now;
      gl.useProgram(prog);
      if (uRes)   gl.uniform3f(uRes, w, h, 1.0);
      if (uTime)  gl.uniform1f(uTime, t);
      if (uDelta) gl.uniform1f(uDelta, dt);
      if (uFrame) gl.uniform1i(uFrame, frame++);
      if (uMouse) gl.uniform4f(uMouse, 0, 0, 0, 0);
      if (uDate) {
        const d = new Date();
        gl.uniform4f(uDate, d.getFullYear(), d.getMonth() + 1, d.getDate(),
          d.getHours()*3600 + d.getMinutes()*60 + d.getSeconds() + d.getMilliseconds()/1000);
      }
      gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
      raf = requestAnimationFrame(draw);
    }

    canvas.style.opacity      = params.opacity   != null ? params.opacity   : 1;
    canvas.style.mixBlendMode = params.blendMode || 'normal';
    raf = requestAnimationFrame(draw);

    return () => {
      cancelAnimationFrame(raf);
      try { gl.deleteProgram(prog); gl.deleteShader(vs); gl.deleteShader(fs); gl.deleteBuffer(buf); } catch(_) {}
    };
  }

  // ── GLSL Sandbox effect ───────────────────────────────────────────────────
  // Format : void main(void) avec uniform float time; uniform vec2 resolution;
  function _runGLSLSandbox(container, canvas, params) {
    const glsl = (params.glsl || '').trim();
    if (!glsl) { console.warn('[EB80Effects] GLSL Sandbox: code vide'); return null; }

    const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    if (!gl) { console.warn('[EB80Effects] GLSL Sandbox: WebGL indisponible'); return null; }

    const VERT = `attribute vec2 a_pos;
void main(){gl_Position=vec4(a_pos,0.0,1.0);}`;

    // Nettoie le code utilisateur pour éviter les déclarations dupliquées
    // 1. Extraire les directives #extension (doivent être en tête de shader)
    const extLines = [];
    let body = glsl.replace(/#extension[^\n]*/g, m => { extLines.push(m.trim()); return ''; });
    // 2. Supprimer les blocs #ifdef GL_ES ... #endif (precision y est souvent déclarée)
    body = body.replace(/#ifdef\s+GL_ES[\s\S]*?#endif/g, '');
    // 3. Supprimer les déclarations de precision et d'uniforms qu'on va fournir
    body = body.replace(/precision\s+\w+\s+\w+\s*;/g, '');
    body = body.replace(/uniform\s+float\s+time\s*;/g, '');
    body = body.replace(/uniform\s+vec2\s+resolution\s*;/g, '');
    body = body.replace(/uniform\s+vec2\s+mouse\s*;/g, '');

    const FRAG = [
      ...extLines,
      'precision highp float;',
      'uniform float time;',
      'uniform vec2  resolution;',
      'uniform vec2  mouse;',
      'uniform vec2  surfaceSize;',
      body.trim(),
    ].join('\n');

    function _compile(type, src) {
      const s = gl.createShader(type);
      gl.shaderSource(s, src);
      gl.compileShader(s);
      if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
        console.error('[EB80Effects] GLSL Sandbox compile:', gl.getShaderInfoLog(s));
        gl.deleteShader(s); return null;
      }
      return s;
    }

    const vs = _compile(gl.VERTEX_SHADER,   VERT);
    const fs = _compile(gl.FRAGMENT_SHADER, FRAG);
    if (!vs || !fs) return null;

    const prog = gl.createProgram();
    gl.attachShader(prog, vs); gl.attachShader(prog, fs);
    gl.linkProgram(prog);
    if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) {
      console.error('[EB80Effects] GLSL Sandbox link:', gl.getProgramInfoLog(prog)); return null;
    }
    gl.useProgram(prog);

    const buf = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buf);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1, 1,-1, -1,1, 1,1]), gl.STATIC_DRAW);
    const aPos = gl.getAttribLocation(prog, 'a_pos');
    gl.enableVertexAttribArray(aPos);
    gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

    const uTime  = gl.getUniformLocation(prog, 'time');
    const uRes   = gl.getUniformLocation(prog, 'resolution');
    const uMouse = gl.getUniformLocation(prog, 'mouse');
    const uSurf  = gl.getUniformLocation(prog, 'surfaceSize');

    let raf, t0 = performance.now();

    function draw(now) {
      const { w, h } = _sync(container, canvas);
      gl.viewport(0, 0, w, h);
      const t = (now - t0) / 1000;
      gl.useProgram(prog);
      if (uTime)  gl.uniform1f(uTime, t);
      if (uRes)   gl.uniform2f(uRes,  w, h);
      if (uMouse) gl.uniform2f(uMouse, 0, 0);
      if (uSurf)  gl.uniform2f(uSurf,  w, h);
      gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
      raf = requestAnimationFrame(draw);
    }

    canvas.style.opacity      = params.opacity != null ? params.opacity : 1;
    canvas.style.mixBlendMode = params.blendMode || 'normal';
    raf = requestAnimationFrame(draw);

    return () => {
      cancelAnimationFrame(raf);
      try { gl.deleteProgram(prog); gl.deleteShader(vs); gl.deleteShader(fs); gl.deleteBuffer(buf); } catch(_) {}
    };
  }

  // ── Dispatcher interne ─────────────────────────────────────────────────────
  async function _dispatch(container, canvas, effectId, params, sourceEl) {
    const def = CATALOG[effectId];
    if (!def) return null;
    const p = Object.assign({}, def.params, params);
    switch (def.lib) {
      case 'canvas': {
        const fns = { grain: _runGrain, scanlines: _runScanlines, glitch: _runGlitch,
                       vhs: _runVHS, chromaberr: _runChromaberr, duotone: _runDuotone,
                       halo: _runHalo };
        const fn = fns[effectId];
        return fn ? fn(container, canvas, p, sourceEl) : null;
      }
      case 'threejs':  return _runThreejs(container, canvas, effectId, p, sourceEl);
      case 'curtains': return _runCurtains(container, effectId, p, sourceEl);
      case 'lottie':   return _runLottie(container, p);
      case 'hydra':    return _runHydra(container, effectId, p);
      case 'css':       return _runCSS(container, p, sourceEl);
      case 'tint':      return _runTint(container, p, sourceEl);
      case 'shadertoy':   return _runShaderToy(container, canvas, p);
      case 'glslsandbox': return _runGLSLSandbox(container, canvas, p);
      default:            return null;
    }
  }

  // ── API publique ───────────────────────────────────────────────────────────
  return {
    CATALOG,

    /**
     * Applique un effet sur un img/video en le wrappant (usage boutique).
     * @param {HTMLElement} el  — img ou video cible
     * @param {Object} config   — { type, ...params } ou { type, params:{...} }
     */
    async apply(el, config) {
      if (!el || !config || !config.type) return;
      this.remove(el);
      const def = CATALOG[config.type];
      const params = config.params || config;
      let dispose = null;
      if (def && (def.lib === 'css')) {
        // CSS filters: apply directly to the element, no wrapper needed
        try { dispose = await _dispatch(null, null, config.type, params, el); }
        catch(e) { console.warn('[EB80Effects] apply:', e); }
        if (dispose) _el_handles.set(el, { container: null, canvas: null, dispose });
      } else if (def && def.lib === 'tint') {
        // Tint: needs a positioned parent, use wrapper
        const { container, canvas } = _wrapEl(el);
        try { dispose = await _dispatch(container, null, config.type, params, el); }
        catch(e) { console.warn('[EB80Effects] apply:', e); }
        if (dispose) _el_handles.set(el, { container, canvas: null, dispose });
      } else {
        const { container, canvas } = _wrapEl(el);
        try { dispose = await _dispatch(container, canvas, config.type, params, el); }
        catch(e) { console.warn('[EB80Effects] apply:', e); }
        if (dispose) _el_handles.set(el, { container, canvas, dispose });
      }
    },

    /**
     * Retire l'effet d'un élément wrappé.
     */
    remove(el) {
      const h = _el_handles.get(el);
      if (!h) return;
      try { if (typeof h.dispose === 'function') h.dispose(); } catch(e) {}
      // Unwrap
      const wrap = h.container;
      if (wrap && wrap.classList.contains('eb80-fx-wrap') && wrap.parentElement) {
        wrap.parentElement.insertBefore(el, wrap);
        wrap.remove();
      }
      _el_handles.delete(el);
    },

    /**
     * Applique un effet dans un slot landing (sans wrapper).
     * @param {string} slotName  — 'hero', 'photo1', 'bio'…
     * @param {Object} config    — { type, ...params } ou { type, params:{...} }
     * @param {HTMLElement} [sourceEl] — img/video pour les effets nécessitant les pixels
     */
    async applyToSlot(slotName, config, sourceEl) {
      if (!config || !config.type) return;
      this.removeFromSlot(slotName);
      const tov = document.getElementById('tov-' + slotName);
      if (!tov) return;
      const container = tov.parentElement;
      if (!container) return;

      const def = CATALOG[config.type];
      if (!def) return;

      let canvas = null;
      if (def.lib !== 'curtains' && def.lib !== 'lottie' && def.lib !== 'hydra'
          && def.lib !== 'css' && def.lib !== 'tint') {
        canvas = _newCanvas(container, 3);
      }
      const params = config.params || config;
      let dispose = null;
      try { dispose = await _dispatch(container, canvas, config.type, params, sourceEl); }
      catch(e) { console.warn('[EB80Effects] applyToSlot:', e); }
      if (dispose) _slot_handles.set(slotName, { container, canvas, dispose });
    },

    /**
     * Retire l'effet d'un slot landing.
     */
    removeFromSlot(slotName) {
      const h = _slot_handles.get(slotName);
      if (!h) return;
      try { if (typeof h.dispose === 'function') h.dispose(); } catch(e) {}
      if (h.canvas && h.canvas.parentElement) h.canvas.remove();
      _slot_handles.delete(slotName);
    },

    /** Liste tous les effets actifs { id, label, lib, group, params } */
    list() { return Object.entries(CATALOG).filter(([,e]) => e.active !== false).map(([id, e]) => ({ id, ...e })); },

    /** Liste tous les effets (y compris inactifs) — usage admin */
    listAll() { return Object.entries(CATALOG).map(([id, e]) => ({ id, ...e })); },

    /** Liste les effets actifs groupés par catégorie */
    byGroup() {
      const g = {};
      this.list().forEach(e => { (g[e.group] = g[e.group] || []).push(e); });
      return g;
    },

    /**
     * Applique une configuration catalog sauvegardée.
     * - Effets existants : surcharge label, active, params.
     * - Entrées avec `_clone` : crée un nouvel effet dans le CATALOG basé sur l'effet source.
     * @param {Object} config — { [effectId]: { label, active, params, _clone? } }
     */
    configure(config) {
      if (!config || typeof config !== 'object') return;
      for (const [id, cfg] of Object.entries(config)) {
        if (CATALOG[id]) {
          if (cfg.label  !== undefined) CATALOG[id].label  = cfg.label;
          if (cfg.active !== undefined) CATALOG[id].active = cfg.active;
          if (cfg.params && typeof cfg.params === 'object') Object.assign(CATALOG[id].params, cfg.params);
        } else if (cfg._clone && CATALOG[cfg._clone]) {
          const base = CATALOG[cfg._clone];
          CATALOG[id] = {
            label:   cfg.label  ?? (base.label + ' (copie)'),
            lib:     base.lib,
            group:   base.group,
            active:  cfg.active !== false,
            _custom: true,
            _clone:  cfg._clone,
            params:  Object.assign({}, base.params, cfg.params || {}),
          };
        } else if (cfg.lib) {
          // Nouvel effet complet (sans clone) — CSS, tint, etc.
          CATALOG[id] = {
            label:   cfg.label  ?? id,
            lib:     cfg.lib,
            group:   cfg.group  ?? cfg.lib,
            active:  cfg.active !== false,
            _custom: true,
            params:  Object.assign({}, cfg.params || {}),
          };
        }
      }
    },

    /**
     * Rend un seul frame d'un effet ShaderToy ou GLSL Sandbox et retourne un data URL.
     * Crée un contexte WebGL avec preserveDrawingBuffer, rend à t=1.5s, libère aussitôt.
     * Ne laisse aucun contexte persistant — sûr même avec de nombreux effets.
     * @param {string} effectId
     * @param {Object} params     — ex : { glsl:'...', opacity:1 }
     * @param {number} w
     * @param {number} h
     * @returns {Promise<string|null>} data URL ou null si échec
     */
    async snapshot(effectId, params, w, h) {
      const def = CATALOG[effectId];
      if (!def) return null;
      if (def.lib !== 'shadertoy' && def.lib !== 'glslsandbox') return null;

      const p    = Object.assign({}, def.params, params);
      const glsl = (p.glsl || '').trim();
      if (!glsl) return null;

      const c  = document.createElement('canvas');
      c.width  = w || 96; c.height = h || 56;

      // Pour les effets Shadertoy, préférer WebGL2 (GLSL ES 3.00)
      const gl2 = def.lib === 'shadertoy'
        ? c.getContext('webgl2', { preserveDrawingBuffer: true })
        : null;
      const gl = gl2
              || c.getContext('webgl',              { preserveDrawingBuffer: true })
              || c.getContext('experimental-webgl', { preserveDrawingBuffer: true });
      if (!gl) return null;

      let VERT, FRAG;
      if (def.lib === 'shadertoy') {
        if (gl2) {
          VERT = `#version 300 es\nin vec2 a_pos;\nvoid main(){gl_Position=vec4(a_pos,0.,1.);}`;
          FRAG = `#version 300 es\nprecision highp float;\nprecision highp int;\n${_ST_UNIFORMS}\n${glsl}\nout vec4 _fragOut;\nvoid main(){ _fragOut=vec4(0.); mainImage(_fragOut,gl_FragCoord.xy); }`;
        } else {
          VERT = `attribute vec2 a_pos;\nvoid main(){gl_Position=vec4(a_pos,0.,1.);}`;
          FRAG = `precision highp float;\nprecision highp int;\n${_ST_UNIFORMS}\n${glsl}\nvoid main(){vec4 _c=vec4(0.);mainImage(_c,gl_FragCoord.xy);gl_FragColor=_c;}`;
        }
      } else {
        VERT = `attribute vec2 a_pos;\nvoid main(){gl_Position=vec4(a_pos,0.,1.);}`;
        // Même nettoyage que _runGLSLSandbox : extraire extensions, supprimer déclarations dupliquées
        const _ext = []; let _b = glsl.replace(/#extension[^\n]*/g, m => { _ext.push(m.trim()); return ''; });
        _b = _b.replace(/#ifdef\s+GL_ES[\s\S]*?#endif/g, '').replace(/precision\s+\w+\s+\w+\s*;/g, '')
              .replace(/uniform\s+float\s+time\s*;/g, '').replace(/uniform\s+vec2\s+resolution\s*;/g, '')
              .replace(/uniform\s+vec2\s+mouse\s*;/g, '');
        FRAG = [..._ext, 'precision highp float;', 'uniform float time;', 'uniform vec2 resolution;', 'uniform vec2 mouse;', _b.trim()].join('\n');
      }

      const _sh = (type, src) => {
        const s = gl.createShader(type);
        gl.shaderSource(s, src); gl.compileShader(s);
        if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) {
          console.warn('[EB80Effects] snapshot compile:', gl.getShaderInfoLog(s));
          gl.deleteShader(s); return null;
        }
        return s;
      };
      const vs = _sh(gl.VERTEX_SHADER, VERT);
      const fs = _sh(gl.FRAGMENT_SHADER, FRAG);
      if (!vs || !fs) return null;

      const prog = gl.createProgram();
      gl.attachShader(prog, vs); gl.attachShader(prog, fs); gl.linkProgram(prog);
      if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) return null;
      gl.useProgram(prog);

      const buf = gl.createBuffer();
      gl.bindBuffer(gl.ARRAY_BUFFER, buf);
      gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1,-1,1,-1,-1,1,1,1]), gl.STATIC_DRAW);
      const aPos = gl.getAttribLocation(prog, 'a_pos');
      gl.enableVertexAttribArray(aPos); gl.vertexAttribPointer(aPos, 2, gl.FLOAT, false, 0, 0);

      gl.viewport(0, 0, c.width, c.height);
      const T = 1.5;
      if (def.lib === 'shadertoy') {
        const uR = gl.getUniformLocation(prog, 'iResolution');
        const uT = gl.getUniformLocation(prog, 'iTime');
        const uM = gl.getUniformLocation(prog, 'iMouse');
        if (uR) gl.uniform3f(uR, c.width, c.height, 1.0);
        if (uT) gl.uniform1f(uT, T);
        if (uM) gl.uniform4f(uM, 0, 0, 0, 0);
      } else {
        const uT = gl.getUniformLocation(prog, 'time');
        const uR = gl.getUniformLocation(prog, 'resolution');
        const uM = gl.getUniformLocation(prog, 'mouse');
        if (uT) gl.uniform1f(uT, T);
        if (uR) gl.uniform2f(uR, c.width, c.height);
        if (uM) gl.uniform2f(uM, 0, 0);
      }
      gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);

      let dataUrl = null;
      try { dataUrl = c.toDataURL(); } catch(_) {}

      try { gl.deleteProgram(prog); gl.deleteShader(vs); gl.deleteShader(fs); gl.deleteBuffer(buf); } catch(_) {}
      try { gl.getExtension('WEBGL_lose_context')?.loseContext(); } catch(_) {}

      return dataUrl;
    },
  };
})();
