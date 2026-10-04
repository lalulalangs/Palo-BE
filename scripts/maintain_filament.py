#!/usr/bin/env python3
"""
Pemelihangan otomatis layer Filament.

Jalankan setiap kali ada Resource / Widget Filament baru:

    python3 scripts/maintain_filament.py

Tiga masalah yang diselesaikan, semuanya menyebabkan FATAL ERROR saat
Filament me-load widget/resource saat boot — dan karena itu **seluruh
aplikasi mati**, termasuk endpoint API yang tidak ada hubungannya dengan
panel admin.

1. Tipe & sifat statik properti navigasi Resource
   ----------------------------------------------
   Filament 4 mendeklarasikan, misalnya:

       protected static string | UnitEnum | null $navigationGroup = null;

   Menulis `protected static ?string $navigationGroup` tidak boleh: PHP
   memakai aturan INVARIANSI tipe properti (tipe anak wajib identik dengan
   tipe induk, bukan lebih sempit).

2. Properti Widget: static atau bukan?
   -------------------------------------
   Ini TIDAK seragam antar-kelas induk, dan itu sumber kesalahan yang
   paling sering muncul:

       Filament\\Widgets\\StatsOverviewWidget  ->  protected ?string $heading      (NON-static)
       Filament\\Widgets\\TableWidget         ->  protected static ?string $heading  (STATIC)

   Menyalin baris dari satu widget ke widget lain lalu menambahkan
   `static` (atau membuangnya) memicu:

       Cannot redeclare non static X::$heading as static Y::$heading

   Karena itu script ini MEMBACA deklarasi induknya dari vendor lalu
   menyesuaikan, bukan menebak.

3. Stub kelas halaman yang belum ada
   ----------------------------------
   Resource biasanya ditulis sebelum `ListX` / `CreateX` / `EditX`.
   Selama kelas itu belum ada, `getPages()` memanggil class yang tidak
   bisa di-resolve.

   Stub dibuat supaya backend tetap melayani request. Stub ditandai jelas
   dan akan tertimpa otomatis saat implementasi aslinya ditulis.
"""

from __future__ import annotations

import re
import sys
from pathlib import Path

BACKEND = Path(__file__).resolve().parent.parent
APP = BACKEND / "app"
FILAMENT = APP / "Filament"
VENDOR = BACKEND / "vendor"

# ---------------------------------------------------------------------------
# 1. Tipe properti navigasi Resource
# ---------------------------------------------------------------------------

# HANYA properti yang tanda tangannya di Filament memang UNION. Untuk itu
# `?string` TIDAK sah, karena PHP menuntut tipe properti identik dengan induk
# (aturan invariansi), bukan lebih sempit.
#
# PENTING: properti yang induknya `?string` (mis. $slug, $modelLabel) TIDAK
# boleh disentuh. Mengubahnya ke `string|null` mungkin terlihat lebih eksplisit, tapi
# Pint punya aturan `nullable_type_declaration` yang mengubah `string|null`
# kembali menjadi `?string`. Dua aturan itu akan saling bertabrakan
# selamanya: setiap menjalankan script, Pint langsung membatalkannya.
# Gejalanya menipu: script melaporkan "12 file diperbaiki" padahal filenya
# sebenarnya sudah benar sejak awal.
#
# Harus pakai .replace(): string pengganti mengandung backslash yang
# re.sub() akan Perlakukan sebagai escape sequence.
TYPE_FIXES: list[tuple[str, str]] = [
    ("protected static ?string $navigationGroup", "protected static string|\\UnitEnum|null $navigationGroup"),
    ("protected static ?string $navigationIcon", "protected static string|\\BackedEnum|null $navigationIcon"),
    ("protected static ?string $activeNavigationIcon", "protected static string|\\BackedEnum|null $activeNavigationIcon"),
    ("protected static ?string $navigationBadgeColor", "protected static string|\\UnitEnum|null $navigationBadgeColor"),
]

# ---------------------------------------------------------------------------
# 2. Widget: static atau bukan, ditentukan oleh kelas induk
# ---------------------------------------------------------------------------

# Properti widget yang dideklarasi berbeda sifat statik antar-induk.
WIDGET_PROPERTIES = ("heading", "description", "emptyHeading", "emptyDescription")

EXTENDS_RE = re.compile(r"^class\s+\w+\s+extends\s+([\\\w]+)", re.MULTILINE)
PROP_RE_TEMPLATE = r"^\s*(protected)\s+(static\s+)?[\w|?\\\\]*\s*\$%s\s*="


USE_LINE_RE = re.compile(r"^use\s+([\\A-Za-z0-9_]+)(?:\s+as\s+(\w+))?\s*;", re.MULTILINE)


def resolve_vendor_class(class_name: str, file_text: str = "") -> Path | None:
    """Petakan nama kelas induk -> file .php-nya di vendor/.

    Dua kasus yang harus ditangani:

    1. `extends TableWidget` — hanya NAMA PENDEK, diselesaikan lewat `use`
       statement di file yang sama. Tanpa ini, kelas induk tidak ditemukan
       dan script diam-diam menganggap tidak ada yang perlu diperbaiki —
       padahal justru itu sumber bug-nya.

    2. `extends \\Filament\\Widgets\\TableWidget` — sudah FQCN.

    PENCOCOKAN memakai deklarasi `namespace` di dalam file, bukan
    menebak struktur folder. Alasannya: FQCN tidak memetakan langsung ke
    lokasi file di vendor. `Filament\\Widgets\\TableWidget` berada di
    `filament/widgets/src/TableWidget.php` — bukan
    `filament/widgets/src/Widgets/TableWidget.php`. Kalau dipetakan dengan
    cara kurasa, hasilnya tidak ditemukan.
    """
    if "\\" not in class_name:
        for fqcn, alias in USE_LINE_RE.findall(file_text):
            short = alias or fqcn.split("\\")[-1]
            if short == class_name:
                class_name = fqcn
                break
        else:
            # Tanpa `use` yang cocok: cari file dengan nama yang sama.
            matches = sorted(VENDOR.glob(f"filament/**/src/{class_name}.php"))
            return matches[0] if matches else None

    namespace, _, short_name = class_name.rpartition("\\")
    if not namespace:
        return None

    needle = f"namespace {namespace};"
    for candidate in sorted(VENDOR.glob("filament/**/src/**/*.php")):
        try:
            if needle in candidate.read_text(encoding="utf-8", errors="ignore")[:2000]:
                if candidate.stem == short_name:
                    return candidate
        except OSError:
            continue
    return None


def parent_property_is_static(widget_file: Path, property_name: str) -> bool | None:
    """Cari deklarasi properti di kelas induk. None = tidak ditemukan."""
    text = widget_file.read_text(encoding="utf-8")
    match = EXTENDS_RE.search(text)
    if not match:
        return None

    parent_file = resolve_vendor_class(match.group(1), text)
    if parent_file is None:
        return None

    parent_text = parent_file.read_text(encoding="utf-8")
    pattern = re.compile(PROP_RE_TEMPLATE % property_name, re.MULTILINE)
    found = pattern.search(parent_text)

    if not found:
        return None
    return "static" in (found.group(2) or "")


# Baris deklarasi properti, contoh:
#     protected static ?string $heading = 'X';
#     protected ?string $description = null;
DECL_RE = {
    prop: re.compile(
        r"^(?P<indent>\s*)(?P<vis>protected)(?P<static>\s+static)?"
        r"(?P<type>[\s\w|?\\]*?)(?P<gap>\s*)\$(?P<name>" + prop + r")(?P<tail>\s*=.*)$"
    )
    for prop in WIDGET_PROPERTIES
}


def fix_widget_properties() -> list[str]:
    """Samakan sifat statik properti widget dengan kelas induknya.

    Diproses per BARIS, bukan per regex span. Alasannya: performanya
   dievaluasi terhadap teks yang sudah dimodifikasi di iterasi sebelumnya,
    sehingga posisinya jadi basi dan penggantiannya mendarat di tempat
    yang salah.
    """
    widgets_dir = FILAMENT / "Widgets"
    if not widgets_dir.exists():
        return []

    changed: list[str] = []

    for path in sorted(widgets_dir.rglob("*.php")):
        text = path.read_text(encoding="utf-8")
        original = text

        # Cache status statik per properti, supaya tidak membaca vendor
        # berulang kali untuk setiap baris.
        want_static: dict[str, bool] = {}
        for prop in WIDGET_PROPERTIES:
            value = parent_property_is_static(path, prop)
            if value is not None:
                want_static[prop] = value

        if not want_static:
            continue

        out_lines: list[str] = []
        for line in text.split("\n"):
            replaced = False
            for prop, should_be_static in want_static.items():
                m = DECL_RE[prop].match(line)
                if not m:
                    continue

                static_part = " static" if should_be_static else ""
                out_lines.append(
                    f"{m.group('indent')}{m.group('vis')}{static_part}"
                    f"{m.group('type')}{m.group('gap')}${prop}{m.group('tail')}"
                )
                replaced = True
                break

            if not replaced:
                out_lines.append(line)

        new_text = "\n".join(out_lines)
        if new_text != original:
            path.write_text(new_text, encoding="utf-8")
            changed.append(str(path.relative_to(BACKEND)))

    return changed


# ---------------------------------------------------------------------------
# 3. Stub kelas halaman
# ---------------------------------------------------------------------------

USE_RE = re.compile(r"^use\s+(App\\Filament\\[A-Za-z0-9_\\]+);", re.MULTILINE)
FQCN_RE = re.compile(r"(App\\Filament\\[A-Za-z0-9_\\]+)::class")
NAMESPACE_RE = re.compile(r"^namespace\s+([A-Za-z0-9_\\]+);", re.MULTILINE)

# Referensi relatif tanpa import, mis. `ViewOrder::route('/{record}')`.
RELATIVE_PAGE_RE = re.compile(r"\b((?:List|Create|Edit|View)[A-Z][A-Za-z0-9]*)::(?:route|getRouteName)")

PAGE_BASE = {
    "List": ("Filament\\Resources\\Pages\\ListRecords", "Resource induknya mendefinisikan getEloquentQuery()."),
    "Create": ("Filament\\Resources\\Pages\\CreateRecord", "Form schema ada di Resource induknya (getForm())."),
    "Edit": ("Filament\\Resources\\Pages\\EditRecord", "Form & aksi ada di Resource induknya (getForm()/getHeaderActions())."),
    "View": ("Filament\\Resources\\Pages\\ViewRecord", "Isi halaman lewat Infolist di Resource induknya (getInfolist())."),
}

STUB_TEMPLATE = '''<?php

namespace {namespace};

/**
 * STUB — belum diimplementasikan.
 *
 * Dibuat otomatis oleh `scripts/maintain_filament.py` karena Resource induknya
 * sudah mendaftarkan kelas ini sementara implementasinya belum ditulis.
 *
 * Kenapa ini penting: Filament me-load seluruh Resource saat boot, jadi satu
 * kelas yang hilang menyebabkan FATAL ERROR di seluruh aplikasi — termasuk
 * endpoint API yang tidak ada hubungannya dengan panel admin.
 *
 * TODO: ganti dengan implementasi sebenarnya. {hint}
 *
 * Stub ini tertimpa otomatis begitu implementasi asli ditulis.
 */
class {short} extends \\{base}
{{
}}
'''


def referenced_classes() -> set[str]:
    found: set[str] = set()
    for path in FILAMENT.rglob("*.php"):
        text = path.read_text(encoding="utf-8")
        found.update(USE_RE.findall(text))
        found.update(FQCN_RE.findall(text))

        namespace_match = NAMESPACE_RE.search(text)
        if not namespace_match:
            continue
        for short in RELATIVE_PAGE_RE.findall(text):
            found.add(f"{namespace_match.group(1)}\\Pages\\{short}")

    return found


def create_missing_pages() -> list[str]:
    created: list[str] = []
    for class_name in sorted(referenced_classes()):
        parts = class_name.split("\\")
        if len(parts) < 2 or parts[-2] != "Pages" or parts[0] != "App":
            continue

        target = BACKEND.joinpath("app", *parts[1:]).with_suffix(".php")
        if target.exists():
            continue

        short = parts[-1]
        for prefix, (base, hint) in PAGE_BASE.items():
            if short.startswith(prefix):
                break
        else:
            base, hint = PAGE_BASE["List"]

        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(
            STUB_TEMPLATE.format(
                namespace="\\".join(parts[:-1]),
                short=short,
                base=base,
                hint=hint,
            ),
            encoding="utf-8",
        )
        created.append(str(target.relative_to(BACKEND)))

    return created


def main() -> int:
    if not FILAMENT.exists():
        print(f"Direktori Filament tidak ditemukan: {FILAMENT}")
        return 1

    fixed: list[str] = []
    for path in sorted(FILAMENT.rglob("*.php")):
        text = path.read_text(encoding="utf-8")
        original = text
        for wrong, right in TYPE_FIXES:
            text = text.replace(wrong, right)
        if text != original:
            path.write_text(text, encoding="utf-8")
            fixed.append(str(path.relative_to(BACKEND)))

    print(f"Tipe properti Resource: {len(fixed)} file diperbaiki." if fixed else "Tipe properti Resource: semua sudah sesuai.")
    for path in fixed:
        print(f"  ~ {path}")

    widgets = fix_widget_properties()
    print(f"Properti Widget: {len(widgets)} file diperbaiki." if widgets else "Properti Widget: semua sudah sesuai induknya.")
    for path in widgets:
        print(f"  ~ {path}")

    created = create_missing_pages()
    print(f"Stub halaman: {len(created)} dibuat." if created else "Stub halaman: semua kelas sudah ada.")
    for path in created:
        print(f"  + {path}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
