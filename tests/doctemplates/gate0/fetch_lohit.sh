#!/usr/bin/env bash
#
# G0.2 — fetch Lohit fonts (OFL 1.1) for the 7 Indic target scripts.
#
# WHY LOHIT, NOT NOTO: current Google-Fonts Noto TTFs fail mPDF 8.3.1's parser
# with "GPOS Lookup Type 5, Format 3 not supported" / "contains MarkGlyphSets"
# — a hard exception at font-registration time. mPDF bundles Lohit-Kannada and
# parses it cleanly, so the Lohit family is the compatible choice.
# See the G0.2 FINDING in EXECUTION_PLAN_v1.1.md.
#
# SOURCE: Debian package pool. GitHub raw/pagure were rate-limited (429/503).
# Latin is NOT fetched — Lohit has no Latin coverage; we use mPDF's bundled
# DejaVuSans, which already parses.
#
# Run:  bash tests/doctemplates/gate0/fetch_lohit.sh

set -uo pipefail

DEST="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/fonts"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$DEST"

# package -> expected font filename
PKGS=(
  "fonts-lohit-deva:Lohit-Devanagari.ttf"
  "fonts-lohit-beng-bengali:Lohit-Bengali.ttf"
  "fonts-lohit-gujr:Lohit-Gujarati.ttf"
  "fonts-lohit-knda:Lohit-Kannada.ttf"
  "fonts-lohit-mlym:Lohit-Malayalam.ttf"
  "fonts-lohit-taml:Lohit-Tamil.ttf"
  "fonts-lohit-telu:Lohit-Telugu.ttf"
)

ok=0; fail=0
for entry in "${PKGS[@]}"; do
  pkg="${entry%%:*}"
  want="${entry##*:}"

  if [ -s "$DEST/$want" ]; then
    echo "  skip  $want (present)"
    ok=$((ok+1)); continue
  fi

  base="http://deb.debian.org/debian/pool/main/f/$pkg/"
  deb=$(curl -sSL --max-time 45 "$base" 2>/dev/null \
        | grep -oE "${pkg}_[0-9][^\"]*_all\.deb" | sort -V | tail -1)

  if [ -z "$deb" ]; then
    echo "  FAIL  $pkg — no .deb found in pool listing"
    fail=$((fail+1)); continue
  fi

  work="$TMP/$pkg"; mkdir -p "$work"
  if ! curl -sSL --max-time 90 -o "$work/pkg.deb" "$base$deb"; then
    echo "  FAIL  $pkg — download error"
    fail=$((fail+1)); continue
  fi

  ( cd "$work" && ar x pkg.deb >/dev/null 2>&1 && tar xf data.tar.* >/dev/null 2>&1 )

  src=$(find "$work" -iname "$want" -print -quit 2>/dev/null)
  if [ -z "$src" ]; then
    # Some packages name the file differently; take any .ttf as a fallback.
    src=$(find "$work" -iname '*.ttf' -print -quit 2>/dev/null)
  fi

  if [ -z "$src" ]; then
    echo "  FAIL  $pkg — no .ttf inside $deb"
    fail=$((fail+1)); continue
  fi

  cp "$src" "$DEST/$want"
  # sfnt magic check: 00010000 (TrueType)
  magic=$(head -c4 "$DEST/$want" | xxd -p)
  if [ "$magic" != "00010000" ] && [ "$magic" != "74727565" ]; then
    echo "  FAIL  $want — not an sfnt file (magic=$magic)"
    rm -f "$DEST/$want"; fail=$((fail+1)); continue
  fi

  printf "  ok    %-24s %8s b   (%s)\n" "$want" "$(stat -f%z "$DEST/$want")" "$deb"
  ok=$((ok+1))
done

echo
echo "lohit fonts ok: $ok/${#PKGS[@]}   failed: $fail"
[ "$fail" -eq 0 ]
