"""
Banc d'essai de modèles image -> vidéo sur Modal (jamais déployé : sert à comparer avant de changer le service).

    modal run modal/video_models_test.py::download                         # télécharge les poids (processeur, peu coûteux)
    modal run modal/video_models_test.py::test --image photo.jpg --models 5b,14b --size 480x832 --outdir /tmp/essai
"""
import os
import time

import modal

app = modal.App("boutique-video-test")
models = modal.Volume.from_name("boutique-video-models", create_if_missing=True)
MODELS_DIR = "/models"

REPOS = {"5b": "Wan-AI/Wan2.2-TI2V-5B-Diffusers", "14b": "Wan-AI/Wan2.2-I2V-A14B-Diffusers"}

image = (
    modal.Image.debian_slim(python_version="3.11")
    .pip_install(
        "torch", "diffusers>=0.35.0", "transformers", "accelerate", "sentencepiece", "protobuf", "ftfy",
        "imageio", "imageio-ffmpeg", "numpy", "pillow", "huggingface_hub", "hf_transfer",
    )
    .env({"HF_HOME": MODELS_DIR, "HF_HUB_ENABLE_HF_TRANSFER": "1"})
)

NEGATIVE = ("Bright tones, overexposed, static, blurred details, subtitles, style, works, paintings, images, static, overall gray, worst quality, "
            "low quality, JPEG compression residue, ugly, incomplete, extra fingers, poorly drawn hands, poorly drawn faces, deformed, "
            "disfigured, misshapen limbs, fused fingers, still picture, messy background, many people in the background")
PROMPT = ("The camera slowly and smoothly moves a little closer to the product, a subtle, gentle push-in. The product stays perfectly sharp, "
          "stable and unchanged: same shape, colours, print, lettering and details. A faint natural movement in the scene: the light shifts "
          "very softly and soft materials move slightly as if in a light breeze. Soft natural light, photorealistic, high quality.")


@app.function(image=image, volumes={MODELS_DIR: models}, timeout=3 * 3600, cpu=4)
def fetch(repo: str) -> str:
    from huggingface_hub import snapshot_download
    path = snapshot_download(repo)
    models.commit()
    return path


def fit(data: bytes, width: int, height: int):
    import io
    from PIL import Image, ImageOps
    return ImageOps.fit(Image.open(io.BytesIO(data)).convert("RGB"), (width, height))


@app.cls(gpu="H100", image=image, volumes={MODELS_DIR: models}, timeout=1800, scaledown_window=60, memory=98304)
class Wan5B:
    @modal.enter()
    def load(self):
        import torch
        from diffusers import AutoencoderKLWan, WanImageToVideoPipeline
        vae = AutoencoderKLWan.from_pretrained(REPOS["5b"], subfolder="vae", torch_dtype=torch.float32)
        self.pipe = WanImageToVideoPipeline.from_pretrained(REPOS["5b"], vae=vae, torch_dtype=torch.bfloat16)
        self.pipe.to("cuda")

    @modal.method()
    def run(self, data: bytes, prompt: str, width: int, height: int, frames: int, steps: int) -> bytes:
        import torch
        from diffusers.utils import export_to_video
        t = time.time()
        out = self.pipe(image=fit(data, width, height), prompt=prompt, negative_prompt=NEGATIVE, height=height, width=width,
                        num_frames=frames, guidance_scale=5.0, num_inference_steps=steps,
                        generator=torch.Generator(device="cuda").manual_seed(42)).frames[0]
        print(f"[5b] génération : {time.time() - t:.0f} s")
        export_to_video(out, "/tmp/o.mp4", fps=24)
        return open("/tmp/o.mp4", "rb").read()


@app.cls(gpu="H100", image=image, volumes={MODELS_DIR: models}, timeout=3000, scaledown_window=60, memory=131072)
class Wan14B:
    @modal.enter()
    def load(self):
        import torch
        from diffusers import WanImageToVideoPipeline
        self.pipe = WanImageToVideoPipeline.from_pretrained(REPOS["14b"], torch_dtype=torch.bfloat16)
        self.pipe.enable_model_cpu_offload()

    @modal.method()
    def run(self, data: bytes, prompt: str, width: int, height: int, frames: int, steps: int) -> bytes:
        import torch
        from diffusers.utils import export_to_video
        t = time.time()
        out = self.pipe(image=fit(data, width, height), prompt=prompt, negative_prompt=NEGATIVE, height=height, width=width,
                        num_frames=frames, guidance_scale=3.5, num_inference_steps=steps,
                        generator=torch.Generator(device="cuda").manual_seed(42)).frames[0]
        print(f"[14b] génération : {time.time() - t:.0f} s")
        export_to_video(out, "/tmp/o.mp4", fps=16)
        return open("/tmp/o.mp4", "rb").read()


@app.local_entrypoint()
def download(which: str = "5b,14b"):
    repos = [REPOS[k.strip()] for k in which.split(",")]
    for repo, path in zip(repos, fetch.map(repos)):
        print("prêt :", repo, "->", path)


@app.local_entrypoint()
def test(image: str, models_to_run: str = "5b,14b", size: str = "480x832", outdir: str = "/tmp/video-test", frames: int = 0, steps: int = 30):
    os.makedirs(outdir, exist_ok=True)
    data = open(image, "rb").read()
    if size == "auto":  # format naturel de la photo, surface ~ 480 x 832, multiples de 32
        from PIL import Image
        import io, math
        w0, h0 = Image.open(io.BytesIO(data)).size
        ratio = h0 / w0
        height = round(math.sqrt(480 * 832 * ratio) / 32) * 32
        width = round(math.sqrt(480 * 832 / ratio) / 32) * 32
        print(f"format naturel : {w0}x{h0} -> {width}x{height}")
    else:
        width, height = (int(v) for v in size.split("x"))
    if models_to_run == "5b-prompts":  # un même modèle chargé, plusieurs consignes de mouvement
        prompts = {
            "push": "The camera steadily pushes in towards the product, getting clearly closer so the print fills more of the frame, while staying perfectly smooth. "
                    "The product stays sharp, stable and unchanged: same shape, colours, print and lettering. Soft natural light, photorealistic, high quality.",
            "pan": "The camera glides slowly and smoothly from left to right across the product, with a clear, continuous lateral movement and gentle parallax. "
                   "The product stays sharp, stable and unchanged: same shape, colours, print and lettering. Soft natural light, photorealistic, high quality.",
            "orbit": "The camera slowly arcs around the product, revealing it from a slightly different angle as it moves, with clear smooth motion. "
                     "The product stays sharp, stable and unchanged: same shape, colours, print and lettering. Soft natural light, photorealistic, high quality.",
            "life": "The camera slowly pushes in a little. The light moves softly across the product, and the fabric gently ripples and settles as if touched by a light breeze, "
                    "with visible but natural movement. The product stays sharp and unchanged: same shape, colours, print and lettering. Photorealistic, high quality.",
        }
        model = Wan5B()
        for name, prompt in prompts.items():
            video = model.run.remote(data, prompt, width, height, frames or 81, steps)
            open(f"{outdir}/wan5b_{name}.mp4", "wb").write(video)
            print(f"écrit : {outdir}/wan5b_{name}.mp4 ({len(video)} octets)")
        return
    for key in models_to_run.split(","):
        key = key.strip()
        started = time.time()
        if key == "5b":
            video = Wan5B().run.remote(data, PROMPT, width, height, frames or 81, steps)
        else:
            video = Wan14B().run.remote(data, PROMPT, width, height, frames or 81, steps)
        path = f"{outdir}/wan_{key}.mp4"
        open(path, "wb").write(video)
        print(f"écrit : {path} ({len(video)} octets, {time.time() - started:.0f} s au total, chargement compris)")
