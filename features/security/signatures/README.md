# Clean Sweep Signatures

## Authoring

Edit **`../signatures_build.php`** only (plain source). Do **not** hand-edit `categories.json` — it is regenerated on build.

### Add a rule (append-only)

Never insert in the middle of the list (positional `cs_NNNN` IDs would shift). Always **append**.

**Preferred (explicit metadata):**

```php
[
    'pattern'  => '/(?s)your_regex_here/is',
    'category' => 'wp_specific',      // php_dangerous | wp_specific | obfuscation | js_web | general | …
    'severity'=> 'critical',         // critical | high | medium | low
    'targets'  => ['php', 'phtml', 'inc', 'phar', 'db'],
    'family'   => 'auth_hijack',      // optional label
    // 'id'    => 'cs_0362',          // optional; default is cs_NNNN by position
],
```

**Still supported:** a bare string `'/regex/i'` — the build fills category/severity/targets via heuristics.

### Build

```bash
# Validate only
php features/security/signatures/build/build_signatures.php --version=2.9.0 --check

# Seal + write current.csig
php features/security/signatures/build/build_signatures.php --version=2.9.0
```

Ship to runtime: `signatures/versions/current.csig` (+ crypto helpers). Do **not** ship `signatures_build.php` or `keys/private/` if you want pack privacy.

### Runtime contract

Scanners still call `set_signatures($mgr->get_signatures())` (flat patterns). Categories/targets come from pack metadata. File and DB scanners share `CleanSweep_SignatureMatcher` for order/match/enrich so detection cannot drift. Severity/targets/family are on each entry (`get_severity()` / `get_targets()` / `get_family()`).

### Regression

```bash
php bin/test-signature-prefilter.php
php bin/test-signature-fixtures.php
```

Fixtures live in `fixtures/malware` and `fixtures/clean` with expectations in `fixtures/expectations.json`. The build runs the fixture corpus after writing a pack (and during `--check` against the installed `current.csig`).
