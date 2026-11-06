#!/usr/bin/env python3
"""Télécharge les polices Google Fonts du catalogue (sous-ensembles latin et latin-ext)
dans assets/fonts/ et génère assets/fonts/fonts.css (@font-face locaux)."""
import os, re, sys, urllib.request

DEST = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "assets", "fonts")
UA = "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36"

# (requête Google Fonts, famille) — gammes variables pour limiter le nombre de fichiers.
FAMILIES = [
    "Schibsted+Grotesk:wght@400..700",
    "IBM+Plex+Mono:wght@400;500;700",
    "Fraunces:opsz,wght@9..144,300..700",
    "Archivo:wght@400..700",
    "Special+Elite",
    "Playfair+Display:wght@400..700",
    "DM+Serif+Display",
    "Cormorant+Garamond:wght@500..700",
    "Libre+Baskerville:wght@400..700",
    "Abril+Fatface",
    "Bricolage+Grotesque:opsz,wght@12..96,400..700",
    "Syne:wght@500..700",
    "Josefin+Sans:wght@400..700",
    "Pacifico",
    "Work+Sans:wght@400..700",
    "DM+Sans:wght@400..700",
    "Karla:wght@400..700",
    "Lato:wght@400;700",
    "Nunito:wght@400..700",
    "Lora:wght@400..700",
    "Source+Serif+4:opsz,wght@8..60,400..700",
]
KEEP = {"latin", "latin-ext"}

def get(url, binary=False):
    req = urllib.request.Request(url, headers={"User-Agent": UA})
    with urllib.request.urlopen(req, timeout=30) as r:
        data = r.read()
    return data if binary else data.decode("utf-8")

os.makedirs(DEST, exist_ok=True)
out = ["/* Polices hébergées en local (aucun appel à Google Fonts). Généré par scripts/fetch-fonts.py. */\n"]
files = {}
for fam in FAMILIES:
    css = get("https://fonts.googleapis.com/css2?family=" + fam + "&display=swap")
    # Blocs « /* subset */ @font-face { … } »
    for m in re.finditer(r"/\*\s*([a-z0-9-]+)\s*\*/\s*(@font-face\s*\{.*?\})", css, re.S):
        subset, block = m.group(1), m.group(2)
        if subset not in KEEP:
            continue
        url = re.search(r"url\((https://[^)]+\.woff2)\)", block).group(1)
        name = re.search(r"font-family:\s*'([^']+)'", block).group(1)
        style = re.search(r"font-style:\s*(\w+)", block).group(1)
        slug = re.sub(r"[^a-z0-9]+", "-", name.lower()).strip("-")
        weight = re.search(r"font-weight:\s*([\d ]+)", block).group(1).strip().replace(" ", "-")
        fname = f"{slug}-{style}-{weight}-{subset}.woff2"
        if url not in files:
            files[url] = fname
            with open(os.path.join(DEST, fname), "wb") as fh:
                fh.write(get(url, binary=True))
        block = block.replace(url, "fonts/" + files[url])
        block = re.sub(r"src:\s*url\(([^)]+)\)\s*format\('woff2'\)", r"src: url(\1) format('woff2')", block)
        out.append(f"/* {name} {subset} */\n{block}\n")
    print("ok", fam, file=sys.stderr)

css_path = os.path.join(os.path.dirname(DEST.rstrip("/")), "fonts.css")
open(css_path, "w").write("\n".join(out))
print(len(files), "fichiers woff2 ->", css_path, file=sys.stderr)
