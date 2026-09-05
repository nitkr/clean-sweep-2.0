# SEO keyword catalog

Token lists for SEO-spam signatures (`cs_0264`–`cs_0267`, `cs_0396`) and the matcher needle gate.

## Add a token

1. Edit the regional `seo-kw-*.php` file (not the catalog class).
2. Add a fixture that hits (malware) or must not hit (clean / substring overlap).
3. Rebuild the pack — list changes are **not** live in `current.csig` until:

```bash
php features/security/signatures/build/build_signatures.php --version=2.19.0
php bin/test-signature-fixtures.php
```

## Rules

- **Always merge every region.** Do not skip Turkish/Indonesian files on English sites.
- **Cap:** `core` + `brands` ≤ 50. The catalog throws at build/runtime if exceeded. Rotate dead brands; do not dump dictionaries.
- **Whole word:** `\b` on PHP/content rules (cs_0264–0267). The matcher gate uses alnum lookaround so underscore slugs (`buy_cialis_online`) still open cs_0396; spaced tokens also emit hyphen/underscore forms (`slot-gacor`). Doorway slugs are hyphen/`_` **segments**, not substrings (`macasion` must not match `casio`).
- **Generic** (`poker`, `roulette`, …) is gate + hide/href only — not slugs.
- Do not add locale modifiers (`giriş`, `güncel`, `bahis`, `deneme`).
- Japanese file is a stub until a non-`\b` matcher exists.

Runtime ships this folder (the matcher loads it). `signatures_build.php` is build-only.
