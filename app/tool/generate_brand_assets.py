"""Buat semua aset merek AKUR (ikon Android/iOS/web + logo halaman login) dari satu gambar sumber.

Pemakaian (butuh Pillow: `pip install pillow`):
    python tool/generate_brand_assets.py assets/branding/source/akur-logo-source.jpg

Sumber berisi kartu putih bersudut bulat dengan gambar rumah + tangan di tengah, di atas latar abu-abu.
Skrip memotong area gambar (artwork), lalu menempatkannya di kanvas putih dengan ukuran yang sesuai aturan
tiap platform:
  - iOS: persegi putih tanpa transparansi (Apple menolak ikon beralpha), semua ukuran di AppIcon.appiconset.
  - Android: ikon lama (persegi bersudut bulat) + adaptive icon (latar putih, foreground di zona aman 66/108).
  - Web: favicon, Icon-192/512, versi maskable (zona aman 80%).
  - Login: assets/branding/akur_logo.png (persegi putih; sudut dibulatkan di Flutter).
"""
import json
import re
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter

APP = Path(__file__).resolve().parent.parent
WHITE = (255, 255, 255)


def artwork(source: Path) -> Image.Image:
    """Potong artwork: piksel berwarna/gelap di dalam kartu putih, dengan margin putih tipis."""
    img = Image.open(source).convert("RGB")
    px = img.load()
    w, h = img.size
    xs, ys = [], []
    for y in range(0, h, 2):
        for x in range(0, w, 2):
            r, g, b = px[x, y]
            if max(r, g, b) - min(r, g, b) > 40 or max(r, g, b) < 200:
                xs.append(x)
                ys.append(y)
    pad = 6
    box = (min(xs) - pad, min(ys) - pad, max(xs) + pad, max(ys) + pad)
    art = img.crop(box)
    # Bersihkan sisa noise JPEG di area putih sekitar artwork agar menyatu dengan kanvas putih.
    pixels = art.get_flattened_data() if hasattr(art, "get_flattened_data") else art.getdata()
    data = [WHITE if min(p) >= 246 else p for p in pixels]
    art.putdata(data)
    return art


def on_white(art: Image.Image, size: int, fill: float) -> Image.Image:
    """Artwork di tengah kanvas putih persegi; `fill` = porsi sisi terpanjang artwork terhadap kanvas."""
    canvas = Image.new("RGB", (size, size), WHITE)
    scale = fill * size / max(art.size)
    resized = art.resize((round(art.width * scale), round(art.height * scale)), Image.LANCZOS)
    canvas.paste(resized, ((size - resized.width) // 2, (size - resized.height) // 2))
    return canvas


def rounded(img: Image.Image, radius_ratio: float = 0.22) -> Image.Image:
    """Sudut bulat dengan alpha (anti-alias lewat supersampling 4x)."""
    size = img.width
    big = size * 4
    mask = Image.new("L", (big, big), 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, big - 1, big - 1), radius=round(big * radius_ratio), fill=255)
    out = img.convert("RGBA")
    out.putalpha(mask.resize((size, size), Image.LANCZOS).filter(ImageFilter.SMOOTH))
    return out


def save(img: Image.Image, path: Path, size: int) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    img.resize((size, size), Image.LANCZOS).save(path, optimize=True)
    print(f"  {path.relative_to(APP)} ({size}px)")


def main(source: Path) -> None:
    art = artwork(source)
    master = on_white(art, 1024, 0.74)            # proporsi sama seperti kartu sumber
    adaptive_fg = on_white(art, 1024, 0.56)       # muat di zona aman adaptive icon (66/108 ≈ 61%)
    maskable = on_white(art, 1024, 0.62)          # zona aman maskable web (lingkaran 80%)

    print("Login")
    save(master, APP / "assets/branding/akur_logo.png", 512)

    print("iOS")
    iconset = APP / "ios/Runner/Assets.xcassets/AppIcon.appiconset"
    for entry in json.loads((iconset / "Contents.json").read_text())["images"]:
        if "filename" not in entry:
            continue
        points = float(entry["size"].split("x")[0])
        scale = int(entry["scale"].rstrip("x"))
        save(master, iconset / entry["filename"], round(points * scale))

    print("Android")
    res = APP / "android/app/src/main/res"
    legacy = rounded(master)
    densities = {"mdpi": 1, "hdpi": 1.5, "xhdpi": 2, "xxhdpi": 3, "xxxhdpi": 4}
    for name, factor in densities.items():
        save(legacy, res / f"mipmap-{name}/ic_launcher.png", round(48 * factor))
        save(adaptive_fg, res / f"mipmap-{name}/ic_launcher_foreground.png", round(108 * factor))
    (res / "mipmap-anydpi-v26").mkdir(exist_ok=True)
    (res / "mipmap-anydpi-v26/ic_launcher.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@color/ic_launcher_background"/>\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>\n'
        '</adaptive-icon>\n'
    )
    (res / "values/ic_launcher_background.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '    <color name="ic_launcher_background">#FFFFFF</color>\n</resources>\n'
    )

    print("Web")
    web = APP / "web"
    save(rounded(master), web / "favicon.png", 64)
    save(rounded(master), web / "icons/Icon-192.png", 192)
    save(rounded(master), web / "icons/Icon-512.png", 512)
    save(maskable, web / "icons/Icon-maskable-192.png", 192)
    save(maskable, web / "icons/Icon-maskable-512.png", 512)
    # iOS Safari "Add to Home Screen": area transparan dirender hitam, jadi pakai versi tanpa alpha.
    save(master, web / "icons/apple-touch-icon.png", 180)


if __name__ == "__main__":
    if len(sys.argv) != 2 or not re.search(r"\.(jpe?g|png)$", sys.argv[1], re.I):
        sys.exit(__doc__)
    main(Path(sys.argv[1]).resolve())
