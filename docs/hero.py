"""Builds the readme banners, assets/img/hero.{light,dark}.webp, the WordPress plugin banner,
assets/img/banner.webp, and the plugin icons, assets/img/icon.{128,256}.webp, from Cascade's portrait.

Run from the plugin root: python3 docs/hero.py. Needs Pillow. Fetches the two faces once into /tmp:
Sansita Swashed for the title, Manrope, the Digitalis brand face, for the tagline.
"""
from PIL import Image, ImageDraw, ImageFont
import os, urllib.request

FONTS = {
    'title':   ('/tmp/SansitaSwashed.ttf', 'https://github.com/google/fonts/raw/main/ofl/sansitaswashed/SansitaSwashed%5Bwght%5D.ttf'),
    'tagline': ('/tmp/Manrope.ttf',        'https://github.com/google/fonts/raw/main/ofl/manrope/Manrope%5Bwght%5D.ttf'),
}
for path, url in FONTS.values():
    if not os.path.exists(path): urllib.request.urlretrieve(url, path)

W, H = 1600, 600
CARD_TOP = 60           # the hat pokes above the card
RADIUS = 56
X = 96                  # left margin for the text
GAP = 22                # between the y's descender and the tagline

def font(size, weight, face='tagline'):
    f = ImageFont.truetype(FONTS[face][0], size)
    f.set_variation_by_name(weight)
    return f

def hero(out, card, title_col, tag_col):
    im = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle([0, CARD_TOP, W, H], RADIUS, fill=card)

    fox = Image.open('assets/img/cascade.portrait.800.webp').convert('RGBA')
    fh = H - 20
    fox = fox.resize((fh, fh), Image.LANCZOS)
    fox_x = W - fh - 12
    im.alpha_composite(fox, (fox_x, H - fh))
    # her leftmost opaque column, so the tagline is fitted to her rather than to the box
    fox_left = fox_x + min(x for x in range(fh) if any(fox.getpixel((x, y))[3] > 40 for y in range(H - fh, fh, 8)))

    title = font(230, 'Bold', 'title')
    tag = 'A rather saucy way of doing SCSS on WordPress.'
    size = 46
    while d.textlength(tag, font=font(size, 'SemiBold')) > fox_left - X - 48: size -= 1
    tagf = font(size, 'SemiBold')

    # centre the ink of both lines in the card, so the space above the S equals the space below the tagline
    tb = d.textbbox((0, 0), 'Sassy', font=title)
    gb = d.textbbox((0, 0), tag, font=tagf)
    block = (tb[3] - tb[1]) + GAP + (gb[3] - gb[1])
    top = CARD_TOP + (H - CARD_TOP - block) // 2
    d.text((X, top - tb[1]), 'Sassy', font=title, fill=title_col)
    d.text((X + 4, top + (tb[3] - tb[1]) + GAP - gb[1]), tag, font=tagf, fill=tag_col)

    im.save(out, 'WEBP', quality=88, method=6)
    print(out, im.size, 'tagline', size, 'px, fox from', fox_left)

hero('assets/img/hero.light.webp', (246, 239, 230, 255), (50, 55, 60, 255), (127, 0, 177, 255))
hero('assets/img/hero.dark.webp',  (36, 26, 43, 255),    (246, 239, 230, 255), (199, 125, 255, 255))


def banner(out):
    """1544x500, what core shows at 2x in the plugin details modal. Core lays the title across the
    bottom band from 174/250 down, so the text stays in the top two thirds and the ground is solid."""
    W, H = 1544, 500
    im = Image.new('RGBA', (W, H), (36, 26, 43, 255))
    d = ImageDraw.Draw(im)
    fox = Image.open('assets/img/cascade.portrait.800.webp').convert('RGBA').resize((H, H), Image.LANCZOS)
    im.alpha_composite(fox, (W - H - 24, 0))
    fox_left = W - H - 24 + min(x for x in range(H) if any(fox.getpixel((x, y))[3] > 40 for y in range(0, H, 8)))
    title = font(190, 'Bold', 'title')
    tag = 'A rather saucy way of doing SCSS on WordPress.'
    size = 44
    while d.textlength(tag, font=font(size, 'SemiBold')) > fox_left - X - 48: size -= 1
    tagf = font(size, 'SemiBold')
    tb = d.textbbox((0, 0), 'Sassy', font=title)
    gb = d.textbbox((0, 0), tag, font=tagf)
    top = 60
    d.text((X, top - tb[1]), 'Sassy', font=title, fill=(246, 239, 230, 255))
    d.text((X + 4, top + (tb[3] - tb[1]) + GAP - gb[1]), tag, font=tagf, fill=(199, 125, 255, 255))
    im.convert('RGB').save(out, 'WEBP', quality=88, method=6)
    print(out, im.size, 'text ends at', top + (tb[3] - tb[1]) + GAP + (gb[3] - gb[1]), 'of', H)

def icons():
    fox = Image.open('assets/img/cascade.portrait.800.webp').convert('RGBA')
    for px in (128, 256):
        out = f'assets/img/icon.{px}.webp'
        fox.resize((px, px), Image.LANCZOS).save(out, 'WEBP', quality=90, method=6)
        print(out)

banner('assets/img/banner.webp')
icons()
