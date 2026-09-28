#!/usr/bin/env python3
"""Background removal for a single image. Called from PHP via shell_exec.
Usage: rembg_cutout.py <input_path> <output_path>
"""
import sys
from PIL import Image
from rembg import remove


def main():
    if len(sys.argv) != 3:
        print("usage: rembg_cutout.py <input> <output>", file=sys.stderr)
        sys.exit(1)

    src, dst = sys.argv[1], sys.argv[2]
    im = Image.open(src)
    out = remove(im)
    out.thumbnail((1400, 1400))
    out.save(dst, "PNG")
    print(dst)


if __name__ == "__main__":
    main()
