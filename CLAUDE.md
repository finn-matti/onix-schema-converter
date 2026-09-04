# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A small, dependency-free PHP library (`Onix2Schema\OnixToSchemaConverter`) that converts ONIX for Books 3.1 `<Product>` records into schema.org `Book` JSON-LD. The whole implementation is one class: `src/OnixToSchemaConverter.php`.

## Commands

```bash
composer install         # only pulls dev tooling; the library itself has zero runtime deps
php tests/ConverterTest.php   # or: composer test
php bin/convert.php examples/sample-onix-3.1.xml   # CLI conversion, pretty-printed JSON-LD to stdout
```

There is no PHPUnit, no build step, and no linter configured. `tests/ConverterTest.php` is a single self-contained script using a local `check(bool, string)` assertion helper; it exits non-zero on any failure and prints `FAIL: <message>` per failure to stderr. There's no way to run a single assertion — the whole script runs top to bottom against the fixtures in `examples/`.

## Architecture

**Everything lives in one class**, deliberately: this is a v1 library, not a framework. `convertFile()`/`convertString()` always return `array<int, array>` — one Book node per `<Product>`, even for single-product files — so callers never special-case cardinality.

**Dual ONIX syntax support via a translation layer, not duplicated methods.** ONIX 3.0/3.1 ships in two real-world dialects:
- reference-tag: mixed-case elements (`<Product>`, `<ProductForm>`), the form used in the spec's own examples.
- short-tag: lowercase composites + numeric/alpha leaf codes (`<product>`, `<b012>`), common in real distributor feeds (VLB/Libri-style).

All XPath in every `mapX()` method is written **once**, against reference-tag names only. `convertString()` detects the dialect per document (`count(//o:Product)` vs falling back to short-tag) and sets `$this->shortTagMap` accordingly (`null` = reference-tag, `self::SHORT_TAGS` = short-tag) for the duration of that single conversion. Every XPath expression passes through `translate()` before hitting `DOMXPath`, which rewrites `o:Word` tokens via `self::SHORT_TAGS` when short-tag mode is active. If you add a new mapped field, write the XPath in reference-tag form only and add the corresponding entry to `self::SHORT_TAGS` — do not write short-tag-specific XPath by hand anywhere else. Short-tag codes in that map were verified against a real production ONIX parser's source, not guessed from the spec.

**Namespace auto-detection.** Real feeds are inconsistent about declaring the ONIX reference namespace. `withDefaultNamespaceIfMissing()` string-injects `xmlns="<ONIX_NS>"` onto the root element before parsing, but only if the root declares no `xmlns` at all — a document with some other namespace already declared is left untouched.

**XPath call wrappers, always used instead of raw `DOMXPath` calls:**
- `evalString()` — runs `string(...)` expressions, trims, and guards against `evaluate()` returning non-string.
- `queryElements()` — runs node-set queries and guards against `query()` returning `false`, filtering to `DOMElement` only.

Both route the expression through `translate()` first. New mapping code should go through these two wrappers rather than calling `$xpath->evaluate()`/`$xpath->query()` directly.

**Field mapping is declarative where possible**: `BOOK_FORMAT_MAP`, `CONTRIBUTOR_ROLE_MAP`, `LANGUAGE_MAP`, `AVAILABILITY_MAP` are the ONIX-codelist-subset → schema.org-value tables. Unmapped codes are omitted from output rather than guessed — this pattern should be preserved for any new codelist mapping.

See `MAPPING.md` for the exact ONIX path → schema.org property table and the reasoning behind each v1 scope decision (what's mapped, what's a documented gap, and why). See `README.md` for usage and what's explicitly out of scope for v1.

## Fixtures

`examples/` holds hand-built ONIX fixtures (not copied from any real feed — EDItEUR's samples require a licence click-through, and this project's own converter output is asserted against them):
- `sample-onix-3.1.xml` / `sample-onix-3.1-sparse.xml` — full and fallback-path reference-tag fixtures.
- `sample-onix-3.1-short-tag.xml` / `sample-onix-3.1-sparse-short-tag.xml` — short-tag siblings, field-identical in content to the two above. The test suite asserts these convert to output identical to their reference-tag counterparts — that parity check is the main regression guard for the `translate()` layer, so if you add a new mapped field, extend both the reference-tag and short-tag fixtures with the same value and assert equality, rather than writing a one-off short-tag-only test.
