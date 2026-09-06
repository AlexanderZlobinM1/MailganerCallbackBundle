#!/usr/bin/env python3
"""Build standalone Mautic packages from shared sources and variant additions.

Only explicit __TOKEN__ placeholders are rendered. No PHP code rewriting or
source synchronization occurs. Common files cannot be overridden by a variant.
"""
import argparse
import hashlib
import json
import re
import stat
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TOKEN = re.compile(r"__([A-Z]+(?:_[A-Z]+)*)__")


def render(text, values):
    def substitute(match):
        key = match.group(1)
        if key in {'DIR', 'FILE', 'CLASS', 'METHOD', 'FUNCTION', 'NAMESPACE', 'TRAIT', 'LINE'}:
            return match.group(0)
        if key not in values:
            raise ValueError(f"Unknown package token: {key}")
        return values[key]
    return TOKEN.sub(substitute, text)


def package_files(variant):
    values = json.loads((ROOT / "packages.json").read_text())[variant]
    result = {}
    for area in ("shared", variant):
        for source in sorted((ROOT / "src" / area).rglob("*")):
            if not source.is_file():
                continue
            if source.is_symlink():
                raise ValueError(f"Symlink in source: {source}")
            relative = source.relative_to(ROOT / "src" / area).as_posix()
            name = render(relative, values) if area == "shared" else relative
            if name in result:
                raise ValueError(f"Variant overrides shared source: {name}")
            data = source.read_bytes()
            if area == "shared" and source.suffix != ".png":
                data = render(data.decode("utf-8"), values).encode("utf-8")
            result[name] = data
    return values, result


def build(variant, output):
    values, files = package_files(variant)
    bundle = values["BUNDLE"]
    archive = output / f'{bundle}-{values["VERSION"]}.zip'
    if archive.exists() or (output / bundle).exists():
        raise ValueError(f"Output already exists for {bundle}; use a fresh directory")
    output.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, data in sorted(files.items()):
            target = output / bundle / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)
            entry = zipfile.ZipInfo(f"{bundle}/{name}", date_time=(2026, 1, 1, 0, 0, 0))
            entry.create_system = 3
            entry.external_attr = (stat.S_IFREG | 0o644) << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(entry, data)
    return archive, hashlib.sha256(archive.read_bytes()).hexdigest()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--variant", choices=["callback", "api", "all"], default="all")
    args = parser.parse_args()
    output = args.output.resolve()
    if output == ROOT or ROOT in output.parents:
        parser.error("Build output must be outside the source repository")
    variants = ["callback", "api"] if args.variant == "all" else [args.variant]
    checksums = []
    for variant in variants:
        archive, checksum = build(variant, output)
        checksums.append(f"{checksum}  {archive.name}\n")
        print(archive)
    (output / "SHA256SUMS").write_text("".join(checksums))
