#!/usr/bin/env python3
"""
Periksa nama ikon Heroicon yang dipakai Filament benar-benar ADA.

MASALAH YANG INI SELESAIKAN
===========================
Filament memakai `blade-ui-kit/blade-icons` untuk ikon sidebar. Kalau nama
ikon tidak ada di paket `heroicons`, halaman dashboard gagal render dengan:

    BladeUI\\Icons\\Exceptions\\SvgNotFound:
    Svg by name "o-barcode" from set "heroicons" not found.

Yang membuatnya berbahaya: resource, policy, dan route semuanya lolos
`php -l` dan `php artisan route:list`. Error-nya baru muncul saat Blade
me-render komponen sidebar — artinya SETIAP halaman panel admin mati,
termasuk halaman login setelah redirect.

Sifat gagalnya persis sama dengan kelas bug Filament lain: tidak terlihat
sampai aplikasi benar-benar merender.

Jalankan:
    python3 scripts/check_icons.py          # hanya laporan
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parent.parent
SOURCE_DIRS = [BACKEND / "app" / "Filament", BACKEND / "app" / "Providers"]

# Pola pemakaian ikon: heroicon-o-x, heroicon-s-x, atau kelas Blade::heroicon.
ICON_RE = re.compile(
    r"""heroicon-([os])-([a-z0-9-]+)"""
)


def heroicon_names() -> set[str] | None:
    """Kumpulkan semua nama ikon yang benar-benar ada di paket heroicons."""
    # Paket ikon terpisah: `blade-heroicons` (bukan `blade-icons`).
    candidates = list(
        BACKEND.glob("vendor/**/blade-heroicons/resources/svg/*.svg")
    )
    if not candidates:
        # Beberapa versi menaruh ikon sebagai satu file `heroicons.svg`.
        bundle = list(
            BACKEND.glob("vendor/**/blade-heroicons/resources/svg/heroicons.svg")
        )
        if not bundle:
            return None
        names: set[str] = set()
        for path in bundle:
            names.update(re.findall(r'id="([os]?-[a-z0-9-]+)"', path.read_text(encoding="utf-8")))
        return names

    names = set()
    for path in candidates:
        stem = path.stem            # o-barcode, s-shopping-bag, dll.
        if "-" in stem:
            names.add(stem)
    return names


def main() -> int:
    available = heroicon_names()
    if available is None:
        print("Paket heroicons tidak ditemukan. Lewati pemeriksaan.")
        return 0

    # Blade-icons menerima nama tanpa prefiks gaya maupun ikon default,
    # jadi normalisasi ke bentuk "o-x" / "s-x" untuk perbandingan.
    problems: list[tuple[str, str]] = []

    for directory in SOURCE_DIRS:
        if not directory.exists():
            continue
        for path in sorted(directory.rglob("*.php")):
            text = path.read_text(encoding="utf-8")
            for style, name in ICON_RE.findall(text):
                icon = f"{style}-{name}"
                if icon not in available:
                    problems.append((str(path.relative_to(BACKEND)), icon))

    if problems:
        print(f"{len(problems)} nama ikon tidak ditemukan:")
        seen: set[tuple[str, str]] = set()
        for path, icon in problems:
            if (path, icon) in seen:
                continue
            seen.add((path, icon))
            print(f"  x {icon:32} di {path}")
        print()
        print("Ikon terkait barcode/QR yang tersedia:")
        for candidate in sorted(n for n in available if "bar" in n or "qr" in n):
            print(f"    {candidate}")
        return 1

    print(f"Semua nama ikon valid ({len(available)} ikon tersedia).")
    return 0


if __name__ == "__main__":
    sys.exit(main())
