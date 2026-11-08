import qrcode
import qrcode.image.svg
import zlib
import struct
import math

TARGET_URL = "https://mama-momo.vercel.app/"

def create_png_from_matrix(modules, box_size=30, border=4):
    """
    Renders the QR code boolean matrix into a high-res RGBA PNG using standard zlib and struct.
    Includes branded styling:
    - Subtle dark crimson/charcoal modules for reliable camera scanning
    - Clean white quiet zone
    """
    qr_len = len(modules)
    total_cells = qr_len + border * 2
    img_size = total_cells * box_size

    # Prepare raw RGBA scanlines
    raw = bytearray()
    for y in range(img_size):
        raw.append(0)  # filter type 0
        cell_y = (y // box_size) - border

        for x in range(img_size):
            cell_x = (x // box_size) - border

            # Default: white quiet zone / light background
            is_dark = False
            if 0 <= cell_x < qr_len and 0 <= cell_y < qr_len:
                is_dark = modules[cell_y][cell_x]

            if is_dark:
                # Elegant dark charcoal / crimson tint #1E1B18
                raw.extend((26, 26, 26, 255))
            else:
                # Crisp clean white
                raw.extend((255, 255, 255, 255))

    compressed = zlib.compress(bytes(raw), level=9)
    png = bytearray(b'\x89PNG\r\n\x1a\n')

    # IHDR
    ihdr_data = struct.pack('>IIBBBBB', img_size, img_size, 8, 6, 0, 0, 0)
    png.extend(struct.pack('>I', len(ihdr_data)))
    png.extend(b'IHDR')
    png.extend(ihdr_data)
    png.extend(struct.pack('>I', zlib.crc32(b'IHDR' + ihdr_data) & 0xffffffff))

    # IDAT
    png.extend(struct.pack('>I', len(compressed)))
    png.extend(b'IDAT')
    png.extend(compressed)
    png.extend(struct.pack('>I', zlib.crc32(b'IDAT' + compressed) & 0xffffffff))

    # IEND
    png.extend(struct.pack('>I', 0))
    png.extend(b'IEND')
    png.extend(struct.pack('>I', zlib.crc32(b'IEND') & 0xffffffff))

    return bytes(png)

def generate():
    print(f"Generating QR Code for: {TARGET_URL}")

    # 1. Initialize QR Code with High Error Correction (allows clean logo embedding / robust outdoor camera scanning)
    qr = qrcode.QRCode(
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_H,
        box_size=32,
        border=4,
    )
    qr.add_data(TARGET_URL)
    qr.make(fit=True)

    # 2. Vector SVG QR Code
    svg_factory = qrcode.image.svg.SvgPathImage
    svg_img = qr.make_image(image_factory=svg_factory)
    svg_path = "qrcode.svg"
    svg_img.save(svg_path)
    svg_img.save("mama_momo_qr.svg")
    print(f"[OK] Saved SVG QR code -> {svg_path} & mama_momo_qr.svg")

    # 3. High-Resolution PNG QR Code (1000px+ resolution)
    png_bytes = create_png_from_matrix(qr.modules, box_size=28, border=4)
    png_path = "qrcode.png"
    with open(png_path, "wb") as f:
        f.write(png_bytes)
    with open("mama_momo_qr.png", "wb") as f:
        f.write(png_bytes)
    print(f"[OK] Saved PNG QR code -> {png_path} & mama_momo_qr.png")

if __name__ == "__main__":
    generate()
