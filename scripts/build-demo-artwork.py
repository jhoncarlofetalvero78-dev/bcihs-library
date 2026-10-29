"""Original CC0 typographic demo artwork; requires Pillow, not network access."""
import json
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
FONT = Path('/System/Library/Fonts/Supplemental')


def font(size, bold=False):
    name = 'Georgia Bold.ttf' if bold else 'Georgia.ttf'
    return ImageFont.truetype(str(FONT / name), size)


def lines(draw, text, face, width):
    result, line = [], ''
    for word in text.split():
        candidate = (line + ' ' + word).strip()
        if line and draw.textlength(candidate, font=face) > width:
            result.append(line)
            line = word
        else:
            line = candidate
    return result + [line]


def book_icon(draw, x, y, width, color, stroke=7):
    half = width // 2
    height = width * 3 // 5
    draw.line([(x, y), (x + half, y + 16), (x + width, y),
               (x + width, y + height), (x + half, y + height + 16),
               (x, y + height), (x, y)], fill=color, width=stroke)
    draw.line([(x + half, y + 16), (x + half, y + height + 16)], fill=color, width=stroke)


data = json.loads((ROOT / 'demo-assets/manifest.json').read_text())
palettes = [('#541722', '#f4e8cf'), ('#173e42', '#f0e4c8'),
            ('#383151', '#f2dfc3'), ('#5c432c', '#f8edcf'), ('#35482e', '#f2e4cf')]
for index, book in enumerate(data['books']):
    bg, ink = palettes[index % len(palettes)]
    image = Image.new('RGB', (600, 900), bg)
    draw = ImageDraw.Draw(image)
    draw.rectangle((26, 26, 573, 873), outline=ink, width=2)
    draw.rectangle((40, 40, 559, 859), outline=ink, width=1)
    draw.text((300, 90), 'LIBRARY COLLECTION', font=font(22), fill=ink, anchor='mm')
    book_icon(draw, 215, 175, 170, ink)
    title_lines = lines(draw, book['title'], font(45, True), 470)
    y = 375
    for line in title_lines:
        draw.text((300, y), line, font=font(45, True), fill=ink, anchor='mm')
        y += 59
    draw.line((110, 660, 490, 660), fill=ink, width=2)
    for row, line in enumerate(lines(draw, book['author'], font(27), 460)):
        draw.text((300, 708 + row * 36), line, font=font(27), fill=ink, anchor='mm')
    draw.text((300, 812), 'LIBRARY COVER ARTWORK', font=font(18), fill=ink, anchor='mm')
    image.save(ROOT / book['image_path'], optimize=True)

captions = [('Explore. Discover. Learn.', 'Books, requests, and library guides in one place'),
            ('Find your next read.', 'Request · Staff approval · Confirmed checkout'),
            ('Your request. Your pickup QR.', 'Staff verification before book handover'),
            ('Good readers care for books.', 'Read thoughtfully. Return responsibly.')]
for notice, (heading, subheading) in zip(data['announcements'], captions):
    image = Image.new('RGB', (1280, 720), '#f4ece4')
    draw = ImageDraw.Draw(image)
    draw.rectangle((0, 0, 1280, 26), fill='#681722')
    draw.ellipse((950, -80, 1430, 400), fill='#e7d7cb')
    draw.ellipse((-210, 520, 300, 1030), fill='#e7d7cb')
    draw.text((80, 100), 'BCIHS  /  LIBRARY PORTAL', font=font(25, True), fill='#681722')
    y = 224
    for line in lines(draw, heading, font(59, True), 870):
        draw.text((80, y), line, font=font(59, True), fill='#3d2630')
        y += 80
    draw.text((80, 465), subheading, font=font(28), fill='#5d4a47')
    book_icon(draw, 1025, 370, 155, '#681722', 6)
    draw.line((80, 575, 1200, 575), fill='#c8b6aa', width=2)
    draw.text((80, 613), 'Read with curiosity. Ask questions. Discover something new.',
              font=font(23), fill='#5d4a47')
    image.save(ROOT / notice['image_path'], optimize=True)

print('Created 10 original covers and 4 original 16:9 banners. No network access.')
