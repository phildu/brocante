"""
Service IA de la boutique sur Modal : vidéo (Wan 2.2 TI2V-5B, image -> vidéo) et détourage (BiRefNet via rembg).
(Le nom du fichier et de l'application, « ltx », est historique : LTX-Video a été remplacé par Wan 2.2 après comparaison ;
le garder évite de changer l'adresse du service déjà saisie dans les réglages de la boutique.)

Déploiement (une seule fois, depuis ce dossier) :
    pip install modal
    modal setup
    modal secret create boutique-video-token AUTH_TOKEN=<un jeton de votre choix, 32 caractères au hasard>
    modal deploy modal/ltx_video_app.py

« modal deploy » affiche l'adresse du service (https://<compte>--boutique-ltx-video-web.modal.run) : à saisir, avec le
même jeton, dans Administration > Réglages du site > « Modal ». Au premier lancement, les poids du modèle (~25 Go) sont
téléchargés dans un volume : « modal run modal/video_models_test.py::download --which 5b » le fait à l'avance, sur un
processeur (peu coûteux), pour que la première vidéo ne paie pas ce téléchargement sur GPU.

Points d'entrée (tous protégés par « Authorization: Bearer <jeton> ») :
    GET  /health            -> {"ok": true}
    POST /submit            -> {"call_id": "..."}   corps JSON : image (base64), prompt, negative_prompt, width, height,
                                                      num_frames, steps, seed          [vidéo]
    GET  /status/{call_id}  -> {"status": "pending" | "done" | "failed", "error": "..."}
    GET  /video/{call_id}   -> le fichier MP4
    POST /cutout            -> {"call_id": "..."}   corps JSON : image (base64), model (facultatif)  [détourage]
    GET  /png/{call_id}     -> le PNG détouré (fond transparent) ; l'état se lit avec /status/{call_id}

Coût : facturation à la seconde, uniquement pendant la génération. Une vidéo de ~3,4 s (81 images, 24 i/s) prend environ 20 à
25 s sur un H100 (≈ 0,0011 $ par seconde), plus ~40 s de chargement du modèle après une période d'inactivité.
Le plan gratuit de Modal offre 30 $ de crédit par mois.
"""
import io
import os

import modal

APP_NAME = "boutique-ltx-video"
MODEL_ID = "Wan-AI/Wan2.2-TI2V-5B-Diffusers"
CACHE_DIR = "/cache"  # volume partagé : poids de Wan 2.2 (voir video_models_test.py) et de BiRefNet
FPS = 24

app = modal.App(APP_NAME)

# Les poids du modèle (plusieurs Go) sont téléchargés une seule fois, puis gardés dans ce volume.
cache = modal.Volume.from_name("boutique-video-models", create_if_missing=True)

gpu_image = (
    modal.Image.debian_slim(python_version="3.11")
    .pip_install(
        "torch",
        "diffusers>=0.35.0",
        "transformers",
        "accelerate",
        "sentencepiece",
        "protobuf",
        "ftfy",
        "huggingface_hub",
        "imageio",
        "imageio-ffmpeg",
        "numpy",
        "pillow",
    )
    .env({"HF_HOME": CACHE_DIR})
)
web_image = modal.Image.debian_slim(python_version="3.11").pip_install("fastapi[standard]")

with gpu_image.imports():
    import torch
    from diffusers import AutoencoderKLWan, WanImageToVideoPipeline
    from diffusers.utils import export_to_video
    from PIL import Image, ImageOps


@app.cls(gpu="H100", image=gpu_image, volumes={CACHE_DIR: cache}, timeout=900, scaledown_window=180)
class Generator:
    @modal.enter()
    def load(self):
        vae = AutoencoderKLWan.from_pretrained(MODEL_ID, subfolder="vae", torch_dtype=torch.float32)
        self.pipe = WanImageToVideoPipeline.from_pretrained(MODEL_ID, vae=vae, torch_dtype=torch.bfloat16)
        self.pipe.to("cuda")
        try:
            cache.commit()  # garde les poids s'ils viennent d'être téléchargés
        except Exception:
            pass

    @modal.method()
    def generate(self, image_bytes: bytes, prompt: str, negative_prompt: str, width: int, height: int,
                 num_frames: int, steps: int, seed: int, guidance_scale: float = 5.0) -> bytes:
        # Wan 2.2 5B : largeur et hauteur multiples de 32. La photo est recadrée au format demandé (sans bandes ajoutées :
        # le modèle les prendrait pour un vrai décor).
        width, height = max(256, width // 32 * 32), max(256, height // 32 * 32)
        image = ImageOps.fit(Image.open(io.BytesIO(image_bytes)).convert("RGB"), (width, height))
        frames = self.pipe(
            image=image,
            prompt=prompt,
            negative_prompt=negative_prompt,
            height=height,
            width=width,
            num_frames=num_frames,
            guidance_scale=guidance_scale,
            num_inference_steps=steps,
            generator=torch.Generator(device="cuda").manual_seed(seed),
        ).frames[0]
        path = f"/tmp/out-{seed}.mp4"
        export_to_video(frames, path, fps=FPS)
        with open(path, "rb") as fh:
            data = fh.read()
        os.remove(path)
        return data


# Détourage (fond transparent) : bibliothèque rembg sur processeur, sans GPU (quelques secondes par photo).
CUTOUT_MODEL = "birefnet-general"
cutout_image = (
    modal.Image.debian_slim(python_version="3.11")
    .pip_install("rembg[cpu]", "pillow", "numpy")
    .env({"U2NET_HOME": CACHE_DIR + "/rembg"})
)


@app.cls(cpu=4, memory=6144, image=cutout_image, volumes={CACHE_DIR: cache}, timeout=900, scaledown_window=300)
class Cutter:
    @modal.enter()
    def load(self):
        self.sessions = {}

    def session(self, model: str):
        from rembg import new_session
        if model not in self.sessions:
            self.sessions[model] = new_session(model)
            try:
                cache.commit()  # garde le modèle téléchargé pour les prochains démarrages
            except Exception:
                pass
        return self.sessions[model]

    @modal.method()
    def cutout(self, image_bytes: bytes, model: str = CUTOUT_MODEL) -> bytes:
        from rembg import remove
        return remove(image_bytes, session=self.session(model))


@app.function(image=web_image, secrets=[modal.Secret.from_name("boutique-video-token")])
@modal.asgi_app()
def web():
    import base64
    import secrets

    from fastapi import Depends, FastAPI, HTTPException
    from fastapi.responses import JSONResponse, Response
    from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

    api = FastAPI()
    scheme = HTTPBearer()

    def check(token: HTTPAuthorizationCredentials = Depends(scheme)):
        expected = os.environ.get("AUTH_TOKEN", "")
        if not expected or not secrets.compare_digest(token.credentials, expected):
            raise HTTPException(status_code=401, detail="Jeton refusé")

    @api.get("/health", dependencies=[Depends(check)])
    def health():
        return {"ok": True}

    @api.post("/submit", dependencies=[Depends(check)])
    async def submit(body: dict):
        try:
            image = base64.b64decode(body["image"], validate=False)
            call = await Generator().generate.spawn.aio(
                image,
                str(body.get("prompt", ""))[:1500],
                str(body.get("negative_prompt", ""))[:500],
                int(body.get("width", 544)),
                int(body.get("height", 736)),
                int(body.get("num_frames", 81)),
                int(body.get("steps", 30)),
                int(body.get("seed", 0)) or 42,
                float(body.get("guidance_scale", 5.0)),
            )
        except (KeyError, ValueError) as err:
            raise HTTPException(status_code=400, detail=f"Requête invalide : {err}")
        return {"call_id": call.object_id}

    @api.post("/cutout", dependencies=[Depends(check)])
    async def cutout(body: dict):
        try:
            image = base64.b64decode(body["image"], validate=False)
            model = str(body.get("model") or CUTOUT_MODEL)
            if model not in ("birefnet-general", "isnet-general-use", "u2net"):
                model = CUTOUT_MODEL
            call = await Cutter().cutout.spawn.aio(image, model)
        except (KeyError, ValueError) as err:
            raise HTTPException(status_code=400, detail=f"Requête invalide : {err}")
        return {"call_id": call.object_id}

    async def fetch(call_id: str):
        call = modal.FunctionCall.from_id(call_id)
        try:
            return await call.get.aio(timeout=0), None
        except TimeoutError:
            return None, "pending"
        except modal.exception.OutputExpiredError:
            return None, "expired"
        except Exception as err:  # la génération a planté dans le conteneur
            return None, f"failed:{str(err)[:300]}"

    @api.get("/status/{call_id}", dependencies=[Depends(check)])
    async def status(call_id: str):
        data, state = await fetch(call_id)
        if state is None:
            return {"status": "done"}
        if state == "pending":
            return {"status": "pending"}
        if state == "expired":
            return {"status": "failed", "error": "Résultat expiré"}
        return {"status": "failed", "error": state.removeprefix("failed:")}

    @api.get("/png/{call_id}", dependencies=[Depends(check)])
    async def png(call_id: str):
        data, state = await fetch(call_id)
        if state is not None:
            return JSONResponse({"status": state}, status_code=202 if state == "pending" else 404)
        return Response(content=data, media_type="image/png")

    @api.get("/video/{call_id}", dependencies=[Depends(check)])
    async def video(call_id: str):
        data, state = await fetch(call_id)
        if state is not None:
            return JSONResponse({"status": state}, status_code=202 if state == "pending" else 404)
        return Response(content=data, media_type="video/mp4")

    return api


@app.local_entrypoint()
def cutout_test(image: str, outdir: str = "/tmp/cutout-test", models: str = "isnet-general-use,birefnet-general"):
    """Essai de modèles de détourage : modal run modal/ltx_video_app.py::cutout_test --image photo.jpg"""
    import time
    os.makedirs(outdir, exist_ok=True)
    data = open(image, "rb").read()
    cutter = Cutter()
    for model in models.split(","):
        started = time.time()
        png = cutter.cutout.remote(data, model.strip())
        path = f"{outdir}/{model.strip()}.png"
        with open(path, "wb") as fh:
            fh.write(png)
        print(f"écrit : {path} ({len(png)} octets, {time.time() - started:.1f} s)")


@app.local_entrypoint()
def video_test(image: str, size: str = "544x736", outdir: str = "/tmp/video-test"):
    """Essai de la vidéo : modal run modal/ltx_video_app.py::video_test --image photo.jpg --size 544x736"""
    import time
    os.makedirs(outdir, exist_ok=True)
    width, height = (int(v) for v in size.split("x"))
    prompt = ("The camera steadily pushes in towards the product, getting clearly closer so the print fills more of the frame, while staying perfectly smooth. "
              "The product stays sharp, stable and unchanged: same shape, colours, print and lettering. Soft natural light, photorealistic, high quality.")
    negative = "static, blurred details, worst quality, low quality, deformed, still picture, hands, feet"
    started = time.time()
    video = Generator().generate.remote(open(image, "rb").read(), prompt, negative, width, height, 81, 30, 42, 5.0)
    path = f"{outdir}/video.mp4"
    with open(path, "wb") as fh:
        fh.write(video)
    print(f"écrit : {path} ({len(video)} octets, {time.time() - started:.0f} s au total)")
