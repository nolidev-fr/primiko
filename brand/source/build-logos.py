"""Primiko identity: mark, wordmark and lockups as SVG.

Usage: python3 build-logos.py [output folder]   (default: ../logos)

No dependency: the outlines of the wordmark are read straight from the
TrueType font in fonts/ (Manrope ExtraBold, SIL Open Font License), so the
logotype is a drawing that never depends on the font being installed.
PNG previews are rendered separately from the SVG files.
"""
import struct
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE.parent / 'logos'
OUT.mkdir(parents=True, exist_ok=True)

INK = '#20211E'
PAPER = '#F4F4F4'
YELLOW = '#F2B705'
GREEN = '#5E8A2A'


# ---------- Mark ----------
# A spirit level seen from above, bubble dead centre: everything is level.
# Body with almost square corners (a real level, not a switch), dark vial
# window, green liquid, two marks, the paper bubble between them.
def mark_shapes():
    return (
        f'<rect x="14" y="88" width="228" height="80" rx="10" fill="{YELLOW}"/>'
        f'<rect x="62" y="102" width="132" height="52" rx="26" fill="{INK}"/>'
        f'<rect x="69" y="109" width="118" height="38" rx="19" fill="{GREEN}"/>'
        f'<rect x="100" y="109" width="7" height="38" fill="{INK}"/>'
        f'<rect x="149" y="109" width="7" height="38" fill="{INK}"/>'
        f'<ellipse cx="128" cy="128" rx="17" ry="12" fill="{PAPER}"/>'
    )


def mark(tile=True):
    tile_rect = f'<rect width="256" height="256" rx="56" fill="{INK}"/>' if tile else ''
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="256" height="256">'
            f'<title>Primiko</title>{tile_rect}{mark_shapes()}</svg>')


def mark_mono(fill='#000000'):
    """One colour level for the WordPress admin menu, small favicons and stamps:
    the body, the vial cut out, the bubble inside."""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="{fill}" fill-rule="evenodd" '
            'd="M1.5 6.5A1 1 0 0 1 2.5 5.5H17.5A1 1 0 0 1 18.5 6.5V13.5A1 1 0 0 1 17.5 14.5H2.5A1 1 0 0 1 1.5 13.5Z'
            'M7 7.75A2.25 2.25 0 0 0 7 12.25H13A2.25 2.25 0 0 0 13 7.75Z"/>'
            f'<ellipse fill="{fill}" cx="10" cy="10" rx="1.4" ry="1"/></svg>')


# ---------- TrueType outlines ----------
class Font:
    def __init__(self, path):
        self.d = Path(path).read_bytes()
        n = struct.unpack('>H', self.d[4:6])[0]
        self.t = {}
        for i in range(n):
            tag, _, off, ln = struct.unpack('>4sIII', self.d[12 + 16 * i:28 + 16 * i])
            self.t[tag.decode()] = (off, ln)
        head = self.t['head'][0]
        self.upm = struct.unpack('>H', self.d[head + 18:head + 20])[0]
        self.loc_long = struct.unpack('>h', self.d[head + 50:head + 52])[0] == 1
        maxp = self.t['maxp'][0]
        self.n = struct.unpack('>H', self.d[maxp + 4:maxp + 6])[0]
        hhea = self.t['hhea'][0]
        self.n_hm = struct.unpack('>H', self.d[hhea + 34:hhea + 36])[0]
        self.cmap = self._cmap()

    def _cmap(self):
        base = self.t['cmap'][0]
        count = struct.unpack('>H', self.d[base + 2:base + 4])[0]
        for i in range(count):
            pid, eid, off = struct.unpack('>HHI', self.d[base + 4 + 8 * i:base + 12 + 8 * i])
            sub = base + off
            if struct.unpack('>H', self.d[sub:sub + 2])[0] == 4:
                return self._format4(sub)
        raise ValueError('no format 4 cmap')

    def _format4(self, s):
        segx2 = struct.unpack('>H', self.d[s + 6:s + 8])[0]
        seg = segx2 // 2
        ends = struct.unpack(f'>{seg}H', self.d[s + 14:s + 14 + segx2])
        starts = struct.unpack(f'>{seg}H', self.d[s + 16 + segx2:s + 16 + 2 * segx2])
        deltas = struct.unpack(f'>{seg}h', self.d[s + 16 + 2 * segx2:s + 16 + 3 * segx2])
        ro_pos = s + 16 + 3 * segx2
        ranges = struct.unpack(f'>{seg}H', self.d[ro_pos:ro_pos + segx2])
        m = {}
        for i in range(seg):
            for c in range(starts[i], ends[i] + 1):
                if c == 0xFFFF:
                    continue
                if ranges[i] == 0:
                    g = (c + deltas[i]) & 0xFFFF
                else:
                    a = ro_pos + 2 * i + ranges[i] + 2 * (c - starts[i])
                    g = struct.unpack('>H', self.d[a:a + 2])[0]
                    g = (g + deltas[i]) & 0xFFFF if g else 0
                m[c] = g
        return m

    def advance(self, g):
        h = self.t['hmtx'][0]
        i = min(g, self.n_hm - 1)
        return struct.unpack('>H', self.d[h + 4 * i:h + 4 * i + 2])[0]

    def _loc(self, g):
        l = self.t['loca'][0]
        if self.loc_long:
            return struct.unpack('>II', self.d[l + 4 * g:l + 4 * g + 8])
        a, b = struct.unpack('>HH', self.d[l + 2 * g:l + 2 * g + 4])
        return a * 2, b * 2

    def contours(self, g):
        """List of contours, each a list of (x, y, on_curve)."""
        a, b = self._loc(g)
        if a == b:
            return []
        p = self.t['glyf'][0] + a
        nc = struct.unpack('>h', self.d[p:p + 2])[0]
        if nc < 0:
            return self._composite(p)
        ends = struct.unpack(f'>{nc}H', self.d[p + 10:p + 10 + 2 * nc])
        npts = ends[-1] + 1
        q = p + 10 + 2 * nc
        ilen = struct.unpack('>H', self.d[q:q + 2])[0]
        q += 2 + ilen
        flags = []
        while len(flags) < npts:
            f = self.d[q]; q += 1
            flags.append(f)
            if f & 8:
                r = self.d[q]; q += 1
                flags.extend([f] * r)
        xs, ys = [], []
        for coords, short, same in ((xs, 2, 16), (ys, 4, 32)):
            v = 0
            for f in flags:
                if f & short:
                    dv = self.d[q]; q += 1
                    v += dv if f & same else -dv
                elif not f & same:
                    v += struct.unpack('>h', self.d[q:q + 2])[0]; q += 2
                coords.append(v)
        out, start = [], 0
        for e in ends:
            out.append([(xs[i], ys[i], bool(flags[i] & 1)) for i in range(start, e + 1)])
            start = e + 1
        return out

    def _composite(self, p):
        q = p + 10
        out = []
        while True:
            flags, gi = struct.unpack('>HH', self.d[q:q + 4]); q += 4
            if flags & 1:
                dx, dy = struct.unpack('>hh', self.d[q:q + 4]); q += 4
            else:
                dx, dy = struct.unpack('>bb', self.d[q:q + 2]); q += 2
            if flags & 8:
                q += 2
            elif flags & 0x40:
                q += 4
            elif flags & 0x80:
                q += 8
            for c in self.contours(gi):
                out.append([(x + dx, y + dy, on) for x, y, on in c])
            if not flags & 0x20:
                return out


def contour_path(c, ox, oy, s):
    """SVG path of a quadratic contour, y flipped, scaled by s, offset."""
    def P(pt):
        return f'{ox + pt[0] * s:.2f} {oy - pt[1] * s:.2f}'
    pts = list(c)
    # Start on an on-curve point; between two off-curve points, add the implied midpoint.
    if not pts[0][2]:
        if pts[-1][2]:
            pts = [pts[-1]] + pts[:-1]
        else:
            mid = ((pts[0][0] + pts[-1][0]) / 2, (pts[0][1] + pts[-1][1]) / 2, True)
            pts = [mid] + pts
    d = [f'M{P(pts[0])}']
    i, n = 1, len(pts)
    while i <= n:
        cur = pts[i % n]
        if cur[2]:
            d.append(f'L{P(cur)}'); i += 1
        else:
            nxt = pts[(i + 1) % n]
            if nxt[2]:
                d.append(f'Q{P(cur)} {P(nxt)}'); i += 2
            else:
                mid = ((cur[0] + nxt[0]) / 2, (cur[1] + nxt[1]) / 2, True)
                d.append(f'Q{P(cur)} {P(mid)}'); i += 1
    return ''.join(d) + 'Z'


# ---------- Wordmark ----------
# "primiko" in lowercase, Manrope ExtraBold, tracking -1.5 %. The dot of the
# first i becomes the vial of the level, in yellow: the only colour of the
# wordmark, as the red tally light is in the Offair wordmark.
TEXT = 'primiko'
TRACKING = -0.015
SIZE = 100  # cap size reference: the wordmark is drawn at 100 units per em


def wordmark(ink=INK, accent=YELLOW):
    f = Font(HERE / 'fonts' / 'manrope-800.ttf')
    s = SIZE / f.upm
    x = 0.0
    letters, dot_done = [], False
    baseline = 0.0
    minx = miny = 1e9
    maxx = maxy = -1e9
    for ch in TEXT:
        g = f.cmap[ord(ch)]
        cs = f.contours(g)
        if ch == 'i' and not dot_done and len(cs) > 1:
            # The dot is the contour that sits highest.
            dot = max(cs, key=lambda c: min(p[1] for p in c))
            body = [c for c in cs if c is not dot]
            dx0 = min(p[0] for p in dot) * s + x
            dx1 = max(p[0] for p in dot) * s + x
            dy0 = min(p[1] for p in dot) * s
            dy1 = max(p[1] for p in dot) * s
            h = dy1 - dy0
            cx = (dx0 + dx1) / 2
            w = h * 1.9
            letters.append(('vial', (cx - w / 2, -dy1, w, h)))
            cs = body
            dot_done = True
        for c in cs:
            letters.append(('path', contour_path(c, x, baseline, s)))
            for px, py, _ in c:
                minx, maxx = min(minx, x + px * s), max(maxx, x + px * s)
                miny, maxy = min(miny, -py * s), max(maxy, -py * s)
        x += f.advance(g) * s + TRACKING * SIZE
    # The vial may stand wider than the letters: include it in the box.
    for kind, v in letters:
        if kind == 'vial':
            vx, vy, vw, vh = v
            minx, maxx = min(minx, vx), max(maxx, vx + vw)
            miny = min(miny, vy)
    pad = 2
    W, H = maxx - minx + 2 * pad, maxy - miny + 2 * pad
    tx, ty = -minx + pad, -miny + pad
    paths = ''.join(v for k, v in letters if k == 'path')
    vial = next(v for k, v in letters if k == 'vial')
    vx, vy, vw, vh = vial
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W:.1f} {H:.1f}" width="{W:.0f}" height="{H:.0f}">'
           f'<title>Primiko</title><g transform="translate({tx:.2f} {ty:.2f})">'
           f'<path fill="{ink}" fill-rule="nonzero" d="{paths}"/>'
           f'<rect x="{vx:.2f}" y="{vy:.2f}" width="{vw:.2f}" height="{vh:.2f}" rx="{vh / 2:.2f}" fill="{accent}"/>'
           f'</g></svg>')
    return svg, W, H


def inner(svg):
    return svg[svg.index('>') + 1:svg.rindex('</svg>')].replace('<title>Primiko</title>', '')


def lockup(ink, background=None):
    """Mark and wordmark side by side, the wordmark as tall as the body of the level."""
    w_svg, W, H = wordmark(ink)
    mark_size = 100
    scale = 46 / H * 1.0
    gap = 24
    total_w = mark_size + gap + W * scale
    total_h = mark_size
    bg = f'<rect width="{total_w:.1f}" height="{total_h}" fill="{background}"/>' if background else ''
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {total_w:.1f} {total_h}" width="{total_w:.0f}" height="{total_h}">'
            f'<title>Primiko</title>{bg}'
            f'<g transform="scale({mark_size / 256:.5f})">{inner(mark())}</g>'
            f'<g transform="translate({mark_size + gap} {(total_h - H * scale) / 2 + 2:.2f}) scale({scale:.5f})">{inner(w_svg)}</g>'
            f'</svg>')


files = {
    'primiko-mark.svg': mark(),
    'primiko-level.svg': mark(tile=False),
    'primiko-mono.svg': mark_mono(),
    'primiko-wordmark.svg': wordmark(INK)[0],
    'primiko-wordmark-paper.svg': wordmark(PAPER)[0],
    'primiko-logo-ink.svg': lockup(INK),
    'primiko-logo-paper.svg': lockup(PAPER),
}
for name, svg in files.items():
    (OUT / name).write_text(svg + '\n')
    print(OUT / name)
