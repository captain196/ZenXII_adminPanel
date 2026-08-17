# Lohit fonts — licence notice

These fonts are used by the ZenXii Document Engine for Indic script rendering.

| | |
|---|---|
| Family | Lohit (Devanagari, Bengali, Gujarati, Kannada, Malayalam, Tamil, Telugu) |
| Licence | **SIL Open Font License 1.1 (OFL-1.1)** — free for commercial use and redistribution |
| Source | Debian package pool, `fonts-lohit-*` (see `tests/doctemplates/gate0/fetch_lohit.sh`) |

## Why Lohit and not Noto

Gate G0.2 established that **current Google-Fonts Noto TTFs cannot be parsed by mPDF** — every
build, Latin included, throws `GPOS Lookup Type 5, Format 3 not supported` or
`contains MarkGlyphSets` from `TTFontFile::_getCoverage()`. That is a hard exception at font
registration, not a degraded render. mPDF parses Lohit cleanly.

## Known limitation

**Lohit ships Regular only — there is no true Bold face.** mPDF synthesises bold. If synthesised
bold proves unacceptable for Indic text at UAT, a per-script bold source must be sourced.

## Latin

Latin does **not** use Lohit (no coverage). It resolves to mPDF's bundled `DejaVuSans`.

## TODO before distribution

Include the full OFL-1.1 licence text in this directory. The licence requires it to accompany
redistribution.
