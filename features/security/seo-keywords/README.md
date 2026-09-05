# SEO keyword catalog

Token lists for SEO-spam signatures (`cs_0264`–`cs_0267`, `cs_0396`, `cs_0397`) and the matcher needle gate.

## Add a token

1. Edit the regional `seo-kw-*.php` file (not the catalog class).
2. Add a fixture that hits (malware) or must not hit (clean / substring overlap).
3. Rebuild the pack — list changes are **not** live in `current.csig` until:

```bash
php features/security/signatures/build/build_signatures.php --version=2.22.0
php bin/test-signature-fixtures.php
```

## Rules

- **Always merge every region.** Do not skip Turkish/Indonesian files on English sites.
- **Cap:** `core` + `brands` ≤ 50. The catalog throws at build/runtime if exceeded. Rotate dead brands; do not dump dictionaries.
- **Whole word:** `\b` on PHP/content rules (cs_0264–0267). The matcher gate uses alnum lookaround so underscore slugs (`buy_cialis_online`) still open cs_0396; spaced tokens also emit hyphen/underscore forms (`slot-gacor`). Doorway slugs are hyphen/`_` **segments**, not substrings (`macasion` must not match `casio`).
- **Generic** (`poker`, `roulette`, …) is gate + hide/href only — not slugs or cs_0397.
- **cs_0397** is two distinct core/brand tokens within 200 chars. Comparison copy (`vs`, `versus`, `compared`, `comparison`, `review`) in the gap is skipped so “Bet365 vs 1xbet” stays clean.
- **gated / needles[]:** catalog-backed SEO rules set `gated: true` (cs_0374 is `false`). The sealed pack stores `needles.seo` so the matcher gate cannot drift from baked regexes.
- **html files:** cs_0264 / cs_0396 / cs_0397 also target `html`/`htm`. The prefilter unions those with the JS/skimmer set (it does not replace it).
- **Japanese:** `オンラインカジノ` / `入金不要` are literals (no `\\b`). Do not dump a CJK dictionary.
- Do not add locale modifiers (`giriş`, `güncel`, `bahis`, `deneme`).
- Japanese file is a stub until a non-`\b` matcher exists.

Runtime ships this folder (the matcher loads it). `signatures_build.php` is build-only.
