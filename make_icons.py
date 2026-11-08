import zlib
import struct
import math

def create_png(width, height, get_pixel):
    """
    Generate an uncompressed RGBA PNG file using Python standard library (zlib & struct).
    get_pixel(x, y) should return (r, g, b, a) where r,g,b in 0..255 and a in 0..255.
    """
    raw_bytes = bytearray()
    for y in range(height):
        raw_bytes.append(0)  # filter type 0 (None)
        for x in range(width):
            r, g, b, a = get_pixel(x, y)
            raw_bytes.extend((int(r) & 0xff, int(g) & 0xff, int(b) & 0xff, int(a) & 0xff))

    compressed = zlib.compress(bytes(raw_bytes), level=9)

    png = bytearray(b'\x89PNG\r\n\x1a\n')

    # IHDR chunk
    ihdr_data = struct.pack('>IIBBBBB', width, height, 8, 6, 0, 0, 0)
    png.extend(struct.pack('>I', len(ihdr_data)))
    png.extend(b'IHDR')
    png.extend(ihdr_data)
    png.extend(struct.pack('>I', zlib.crc32(b'IHDR' + ihdr_data) & 0xffffffff))

    # IDAT chunk
    png.extend(struct.pack('>I', len(compressed)))
    png.extend(b'IDAT')
    png.extend(compressed)
    png.extend(struct.pack('>I', zlib.crc32(b'IDAT' + compressed) & 0xffffffff))

    # IEND chunk
    png.extend(struct.pack('>I', 0))
    png.extend(b'IEND')
    png.extend(struct.pack('>I', zlib.crc32(b'IEND') & 0xffffffff))

    return bytes(png)

def make_ico(png_data_list):
    """
    Packs a list of (width, height, png_bytes) into a valid Windows .ico file.
    """
    count = len(png_data_list)
    ico = bytearray(struct.pack('<HHH', 0, 1, count))  # Reserved, Type=1 (ICO), Image count

    offset = 6 + 16 * count
    entries = []

    for w, h, data in png_data_list:
        width_byte = 0 if w >= 256 else w
        height_byte = 0 if h >= 256 else h
        entry = struct.pack('<BBBBHHII', 
            width_byte, height_byte, 0, 0, 1, 32, len(data), offset)
        entries.append((entry, data))
        offset += len(data)

    for entry, _ in entries:
        ico.extend(entry)

    for _, data in entries:
        ico.extend(data)

    return bytes(ico)

def draw_momo_pixel(x, y, size):
    """
    Draws a stylized, warm, delicious momo/dumpling badge.
    Normalized coordinates: nx = x / size, ny = y / size (0..1)
    Center at (0.5, 0.5)
    """
    nx = x / float(size)
    ny = y / float(size)
    dx = nx - 0.5
    dy = ny - 0.5

    # 1. Base Squircle / Rounded Circle
    # Distance function for squircle: (dx^4 + dy^4)^(1/4)
    r_sq = (dx**4 + dy**4)**0.25
    r_circ = math.sqrt(dx*dx + dy*dy)

    # Edge antialiasing
    # Outer bound at 0.46
    if r_sq > 0.47:
        return (0, 0, 0, 0)
    
    alpha = 1.0
    if r_sq > 0.44:
        alpha = max(0.0, min(1.0, (0.47 - r_sq) / 0.03))

    # Background gradient: Coral Red #EF4444 to Crimson #991B1B
    bg_t = max(0.0, min(1.0, (nx + ny) * 0.7))
    bg_r = int(239 * (1.0 - bg_t) + 153 * bg_t)
    bg_g = int(68 * (1.0 - bg_t) + 27 * bg_t)
    bg_b = int(68 * (1.0 - bg_t) + 27 * bg_t)

    # Golden border rim
    if r_sq > 0.41:
        rim_t = (r_sq - 0.41) / 0.03
        gold_r, gold_g, gold_b = 245, 158, 11
        bg_r = int(bg_r * (1.0 - rim_t) + gold_r * rim_t)
        bg_g = int(bg_g * (1.0 - rim_t) + gold_g * rim_t)
        bg_b = int(bg_b * (1.0 - rim_t) + gold_b * rim_t)

    # 2. Steamed Momo Body
    # Center of momo around cx=0.5, cy=0.62
    mcx, mcy = 0.5, 0.62
    mdx = (nx - mcx) / 0.32
    mdy = (ny - mcy) / 0.22

    # Bottom pouch curve
    in_momo = False
    momo_shade = 0.0

    # Asymmetric dumpling shape: flatter top knot, plump rounded bottom
    if mdy >= -0.2:
        dist_body = math.sqrt(mdx*mdx + mdy*mdy)
        if dist_body < 1.0:
            in_momo = True
            momo_shade = dist_body
    else:
        # Top pinch crest: curves inwards to knot at (0.5, 0.40)
        knot_dx = (nx - 0.5) / 0.16
        knot_dy = (ny - 0.42) / 0.18
        if math.sqrt(knot_dx*knot_dx + knot_dy*knot_dy) < 1.0 and ny < 0.65:
            in_momo = True
            momo_shade = math.sqrt(knot_dx*knot_dx + knot_dy*knot_dy)

    # Dumpling drop shadow on red base
    shadow_dist = math.sqrt(((nx - 0.5)/0.36)**2 + ((ny - 0.78)/0.09)**2)
    if shadow_dist < 1.0 and not in_momo:
        s_alpha = (1.0 - shadow_dist) * 0.45
        bg_r = int(bg_r * (1.0 - s_alpha))
        bg_g = int(bg_g * (1.0 - s_alpha))
        bg_b = int(bg_b * (1.0 - s_alpha))

    if in_momo:
        # Dumpling Dough Colors: Warm Ivory #FFFFFF -> #FFFBEB -> #FDE68A
        # Top highlight to bottom warm golden steam
        dt = max(0.0, min(1.0, (ny - 0.40) / 0.38))
        dr = int(255 * (1.0 - dt) + 253 * dt)
        dg = int(252 * (1.0 - dt) + 230 * dt)
        db = int(235 * (1.0 - dt) + 138 * dt)

        # Pleat folds (sinusoidal shading across x gathered at top)
        pinch_factor = max(0.1, (ny - 0.38) / 0.35)
        pleat_wave = math.sin((nx - 0.5) / (0.05 * pinch_factor) * math.pi)
        if pleat_wave > 0.4 and ny > 0.44 and ny < 0.78:
            fold_intensity = (pleat_wave - 0.4) * 0.35 * (1.0 - abs(mdx)*0.5)
            dr = int(dr * (1.0 - fold_intensity) + 217 * fold_intensity)
            dg = int(dg * (1.0 - fold_intensity) + 119 * fold_intensity)
            db = int(db * (1.0 - fold_intensity) + 6 * fold_intensity)

        # Golden outer edge contour
        edge_glow = max(0.0, min(1.0, momo_shade))
        if edge_glow > 0.85:
            eg = (edge_glow - 0.85) / 0.15
            dr = int(dr * (1.0 - eg) + 245 * eg)
            dg = int(dg * (1.0 - eg) + 158 * eg)
            db = int(db * (1.0 - eg) + 11 * eg)

        return (dr, dg, db, int(alpha * 255))

    # 3. Rising Steam Vapor
    # Wisp 1: centered around x=0.48, y from 0.14 to 0.38
    steam_val = 0.0
    for wisp_cx, wisp_w, wisp_amp, phase in [(0.46, 0.022, 0.03, 0.0), (0.54, 0.02, 0.025, 2.0), (0.62, 0.016, 0.02, 4.0)]:
        if 0.12 < ny < 0.38:
            target_x = wisp_cx + math.sin((ny - 0.12) * 22.0 + phase) * wisp_amp
            x_dist = abs(nx - target_x)
            if x_dist < wisp_w:
                w_val = (1.0 - x_dist / wisp_w) * (1.0 - (0.38 - ny) / 0.3)
                steam_val = max(steam_val, w_val)

    if steam_val > 0.05:
        st_alpha = min(0.9, steam_val * 0.85)
        sr, sg, sb = 255, 253, 240
        res_r = int(bg_r * (1.0 - st_alpha) + sr * st_alpha)
        res_g = int(bg_g * (1.0 - st_alpha) + sg * st_alpha)
        res_b = int(bg_b * (1.0 - st_alpha) + sb * st_alpha)
        return (res_r, res_g, res_b, int(alpha * 255))

    # 4. Tiny Golden Sparkle at top right
    sp_dx = abs(nx - 0.78)
    sp_dy = abs(ny - 0.22)
    if (sp_dx + sp_dy) < 0.035 or (sp_dx < 0.008 and sp_dy < 0.035) or (sp_dy < 0.008 and sp_dx < 0.035):
        return (254, 240, 138, int(alpha * 255))

    return (bg_r, bg_g, bg_b, int(alpha * 255))

if __name__ == '__main__':
    print("Generating Mama Momo Favicons...")

    # 1. 180x180 Apple Touch Icon
    png_180 = create_png(180, 180, lambda x, y: draw_momo_pixel(x, y, 180))
    with open('apple-touch-icon.png', 'wb') as f:
        f.write(png_180)
    print("[OK] Created apple-touch-icon.png (180x180)")

    # 2. 48x48 PNG
    png_48 = create_png(48, 48, lambda x, y: draw_momo_pixel(x, y, 48))

    # 3. 32x32 Favicon PNG
    png_32 = create_png(32, 32, lambda x, y: draw_momo_pixel(x, y, 32))
    with open('favicon-32x32.png', 'wb') as f:
        f.write(png_32)
    print("[OK] Created favicon-32x32.png (32x32)")

    # 4. 16x16 Favicon PNG
    png_16 = create_png(16, 16, lambda x, y: draw_momo_pixel(x, y, 16))
    with open('favicon-16x16.png', 'wb') as f:
        f.write(png_16)
    print("[OK] Created favicon-16x16.png (16x16)")

    # 5. Multi-resolution Windows ICO (16x16, 32x32, 48x48)
    ico_bytes = make_ico([(16, 16, png_16), (32, 32, png_32), (48, 48, png_48)])
    with open('favicon.ico', 'wb') as f:
        f.write(ico_bytes)
    print("[OK] Created favicon.ico (multi-resolution 16+32+48)")
