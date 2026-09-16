"""Builds the readme banners, assets/img/hero.{light,dark}.webp, from Cascade's portrait.

Run from the plugin root: python3 docs/hero.py. Needs Pillow. Fetches Quicksand once into /tmp.
"""
from PIL import Image, ImageDraw, ImageFont
import os, urllib.request

FONT = '/tmp/Quicksand.ttf'
if not os.path.exists(FONT):
    urllib.request.urlretrieve('https://github.com/google/fonts/raw/main/ofl/quicksand/Quicksand%5Bwght%5D.ttf', FONT)
W, H = 1600, 600
CARD_TOP = 60           # the hat pokes above the card
RADIUS = 56

def font(size, weight):
    f = ImageFont.truetype(FONT, size)
    f.set_variation_by_name(weight)
    return f

def hero(out, card, title_col, tag_col, sub_col):
    im = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(im)
    d.rounded_rectangle([0, CARD_TOP, W, H], RADIUS, fill=card)

    fox = Image.open('assets/img/cascade.portrait.800.webp').convert('RGBA')
    fh = H - 20
    fox = fox.resize((fh, fh), Image.LANCZOS)
    # bottom flush with the card, right edge inset so the brush clears the corner
    im.alpha_composite(fox, (W - fh - 12, H - fh))

    x = 96
    t = font(210, 'Bold')
    d.text((x, CARD_TOP + 110), 'Sassy', font=t, fill=title_col)
    d.text((x + 6, CARD_TOP + 362), 'A rather saucy way of doing SCSS on WordPress.', font=font(46, 'SemiBold'), fill=tag_col)

    im.save(out, 'WEBP', quality=88, method=6)
    print(out, im.size)

hero('assets/img/hero.light.webp', (246, 239, 230, 255), (50, 55, 60, 255), (127, 0, 177, 255), (110, 100, 105, 255))
hero('assets/img/hero.dark.webp',  (36, 26, 43, 255),    (246, 239, 230, 255), (199, 125, 255, 255), (170, 160, 175, 255))
