"""
Service de vidéo IA de la boutique : LTX-Video (Lightricks) sur Modal, image -> vidéo.

Déploiement (une seule fois, depuis ce dossier) :
    pip install modal
    modal setup
    modal secret create boutique-video-token AUTH_TOKEN=<un jeton de votre choix, 32 caractères au hasard>
    modal deploy modal/ltx_video_app.py

« modal deploy » affiche l'adresse du service (https://<compte>--boutique-ltx-video-web.modal.run) : à saisir, avec le
même jeton, dans Administration > Réglages du site > « Vidéo IA gratuite (Modal) ».

Points d'entrée (tous protégés par « Authorization: Bearer <jeton> ») :
    GET  /health            -> {"ok": true}
    POST /submit            -> {"call_id": "..."}   corps JSON : image (base64), prompt, negative_prompt, width, height,
                                                      num_frames, steps, seed
    GET  /status/{call_id}  -> {"status": "pending" | "done" | "failed", "error": "..."}
    GET  /video/{call_id}   -> le fichier MP4

Coût : facturation à la seconde, uniquement pendant la génération. Un GPU L40S coûte environ 0,0005 $ par seconde ; une vidéo
prend une dizaine de secondes de GPU (la première demande après un moment d'inactivité charge aussi le modèle, un peu plus long).
Le plan gratuit de Modal offre 30 $ de crédit par mois.
"""
import inspect
import io
import os

import modal

APP_NAME = "boutique-ltx-video"
MODEL_ID = "Lightricks/LTX-Video"
CACHE_DIR = "/cache"
FPS = 24

app = modal.App(APP_NAME)

# Les poids du modèle (plusieurs Go) sont téléchargés une seule fois, puis gardés dans ce volume.
cache = modal.Volume.from_name("boutique-ltx-video-cache", create_if_missing=True)

gpu_image = (
    modal.Image.debian_slim(python_version="3.11")
    .pip_install(
        "torch",
        "diffusers>=0.33.0",
        "transformers",
        "accelerate",
        "sentencepiece",
        "protobuf",
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
    from diffusers import LTXImageToVideoPipeline
    from diffusers.utils import export_to_video
    from PIL import Image, ImageOps


@app.cls(gpu="L40S", image=gpu_image, volumes={CACHE_DIR: cache}, timeout=900, scaledown_window=180)
class Generator:
    @modal.enter()
    def load(self):
        self.pipe = LTXImageToVideoPipeline.from_pretrained(MODEL_ID, torch_dtype=torch.bfloat16)
        self.pipe.to("cuda")
        try:
            cache.commit()  # garde les poids téléchargés pour les prochains démarrages
        except Exception:
            pass

    @modal.method()
    def generate(self, image_bytes: bytes, prompt: str, negative_prompt: str, width: int, height: int,
                 num_frames: int, steps: int, seed: int, guidance_scale: float = 3.0, cond_noise: float = 0.15,
                 jpeg_quality: int = 0) -> bytes:
        # LTX-Video : largeur et hauteur multiples de 32, nombre d'images de la forme 8n + 1.
        width, height = max(256, width // 32 * 32), max(256, height // 32 * 32)
        num_frames = max(9, (num_frames - 1) // 8 * 8 + 1)
        image = ImageOps.fit(Image.open(io.BytesIO(image_bytes)).convert("RGB"), (width, height))
        if jpeg_quality:
            # LTX-Video fige l'image quand la photo de départ est trop nette : une légère compression JPEG aide.
            buf = io.BytesIO()
            image.save(buf, "JPEG", quality=jpeg_quality)
            image = Image.open(buf).convert("RGB")
        generator = torch.Generator(device="cuda").manual_seed(seed)
        kwargs = dict(
            image=image,
            prompt=prompt,
            negative_prompt=negative_prompt,
            width=width,
            height=height,
            num_frames=num_frames,
            num_inference_steps=steps,
            guidance_scale=guidance_scale,
            generator=generator,
        )
        # Bruit ajouté à l'image de départ : plus il est élevé, plus la vidéo bouge (selon la version du modèle).
        if "image_cond_noise_scale" in inspect.signature(self.pipe.__call__).parameters:
            kwargs["image_cond_noise_scale"] = cond_noise
        frames = self.pipe(**kwargs).frames[0]
        path = f"/tmp/out-{seed}.mp4"
        export_to_video(frames, path, fps=FPS)
        with open(path, "rb") as fh:
            data = fh.read()
        os.remove(path)
        return data


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
                int(body.get("width", 768)),
                int(body.get("height", 448)),
                int(body.get("num_frames", 65)),
                int(body.get("steps", 30)),
                int(body.get("seed", 0)) or 42,
                float(body.get("guidance_scale", 3.0)),
                float(body.get("cond_noise", 0.15)),
                int(body.get("jpeg_quality", 60)),
            )
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

    @api.get("/video/{call_id}", dependencies=[Depends(check)])
    async def video(call_id: str):
        data, state = await fetch(call_id)
        if state is not None:
            return JSONResponse({"status": state}, status_code=202 if state == "pending" else 404)
        return Response(content=data, media_type="video/mp4")

    return api


@app.local_entrypoint()
def test(image: str, outdir: str = "/tmp/ltx-test", variants: str = "E,F,G,H", prompt: str = "", size: str = "768x448", name: str = "custom"):
    """Essai de réglages : modal run modal/ltx_video_app.py --image photo.jpg  (une vidéo par variante, dans outdir)."""
    os.makedirs(outdir, exist_ok=True)
    data = open(image, "rb").read()
    negative = "worst quality, inconsistent motion, blurry, jittery, distorted, deformed object, text, watermark, subtitles, people, hands"
    old = ("Animate this product photograph into a short, smooth, photorealistic video. Camera: a slow, smooth push-in towards the product. "
           "The product must stay exactly as in the photo. Steady camera, soft natural lighting, one continuous shot, no cuts.")
    new = ("The camera pushes steadily in towards the product, getting noticeably closer. The product stays exactly the same: same shape, "
           "colours, pattern and details. Gentle natural movement in the scene: the light shifts softly, loose fabric sways slightly, "
           "with subtle depth and parallax between foreground and background. Smooth, continuous motion throughout the whole shot, photorealistic, high quality.")
    vague = ("Animate this product photograph into a short, smooth, photorealistic video. Camera: a gentle, natural camera movement chosen to show "
             "the product at its best. The product must stay exactly as in the photo. Steady camera, soft natural lighting, one continuous shot, no cuts.")
    subtle = ("The camera slowly and smoothly moves a little closer to the product, a subtle, gentle push-in. The product stays perfectly sharp, stable "
              "and unchanged: same shape, colours, print, lettering and details. Soft natural light, photorealistic, high quality.")
    ambient = ("The camera slowly and smoothly moves a little closer to the product, a subtle, gentle push-in. The product stays perfectly sharp, stable "
               "and unchanged: same shape, colours, print, lettering and details. A faint natural movement in the scene: the light shifts very softly "
               "and the fabric moves slightly as if in a light breeze. Soft natural light, photorealistic, high quality.")
    configs = {
        "E": dict(prompt=subtle, frames=97, cond_noise=0.15, jpeg_quality=60),
        "F": dict(prompt=subtle, frames=65, cond_noise=0.15, jpeg_quality=60),
        "G": dict(prompt=vague, frames=97, cond_noise=0.15, jpeg_quality=0),   # le prompt « au choix de l'IA » de la première version
        "H": dict(prompt=ambient, frames=65, cond_noise=0.15, jpeg_quality=60),
    }
    gen = Generator()
    if prompt:  # un seul essai avec le prompt donné (comme le fait la boutique : 65 images, photo compressée à 60)
        width, height = (int(v) for v in size.split("x"))
        video = gen.generate.remote(data, prompt, negative, width, height, 65, 30, 42, 3.0, 0.15, 60)
        with open(f"{outdir}/{name}.mp4", "wb") as fh:
            fh.write(video)
        print("écrit :", f"{outdir}/{name}.mp4", len(video), "octets")
        return
    for name in variants.split(","):
        cfg = configs[name.strip()]
        video = gen.generate.remote(data, cfg["prompt"], negative, 768, 448, cfg["frames"], 30, 42, 3.0, cfg["cond_noise"], cfg["jpeg_quality"])
        path = f"{outdir}/{name.strip()}.mp4"
        with open(path, "wb") as fh:
            fh.write(video)
        print("écrit :", path, len(video), "octets")
