# onix-schema-converter

A small PHP library that converts ONIX for Books 3.1 product records into
schema.org `Book` JSON-LD, so publishers can generate the structured data
their website needs for Google rich results directly from metadata they
already produce for their distributors.

Background: EDItEUR itself (via a BISG working group chaired by its
executive director, Graham Bell) has flagged the ONIX → schema.org mapping
as an open, unsolved problem for the industry — see `MAPPING.md` for the
concrete v1 scope this project commits to, and what's deliberately left
for later.

## Requirements

- PHP 8.1+ with the `dom` and `libxml` extensions (both part of PHP's
  standard `ext-dom`/`ext-libxml`). No third-party packages needed for v1
  — Composer is used only for autoloading and the `bin/convert.php` shim.

## Installation

```bash
composer install
```

## Usage

```bash
php bin/convert.php examples/sample-onix-3.1.xml
```

Or from code:

```php
require 'vendor/autoload.php';

use Onix2Schema\OnixToSchemaConverter;

$converter = new OnixToSchemaConverter();
$books = $converter->convertFile('path/to/onix-message.xml'); // array<Book>
```

`convertFile()`/`convertString()` always return an array — one entry per
`<Product>` in the ONIX message — even for a single-product file, so
multi-product messages don't need special-case handling downstream.

## What it handles (v1)

ISBN, title/subtitle, book format, primary author + translator, language,
page count, genre (BISAC or Thema), description, cover image, publisher,
publication date, and one price/availability offer. See `MAPPING.md` for
the exact ONIX path → schema.org property table and the reasoning behind
each decision.

Both ONIX 3.0/3.1 syntaxes are supported and auto-detected per document:
reference-tag (`<Product>`, `<ProductForm>`) and short-tag (`<product>`,
`<b012>`) — the latter is common in real distributor feeds (VLB/Libri-style)
even though it's less often seen in spec examples. A document's default
namespace is also optional; feeds that omit `xmlns` entirely still parse.

## What it doesn't (yet)

Series/collection data, multiple contributors per role, multiple
territories/prices, reviews as structured data, and accessibility
metadata. All listed explicitly in `MAPPING.md` so they're a backlog, not
a silent gap.

## Testing

No PHPUnit dependency for v1 — `tests/ConverterTest.php` is a
self-contained assertion script:

```bash
php tests/ConverterTest.php
# or: composer test
```

It covers the fully-populated sample (`examples/sample-onix-3.1.xml`) and
a sparse, two-product message (`examples/sample-onix-3.1-sparse.xml`) that
exercises the fallback paths (GTIN-13 instead of ISBN-13, Thema instead of
BISAC, short description instead of long, missing `ProductSupply`, a
second `<Product>` in the same message); short-tag equivalents of both
(`examples/sample-onix-3.1-short-tag.xml`,
`examples/sample-onix-3.1-sparse-short-tag.xml`) asserted to convert to
identical output as their reference-tag counterparts; a namespace-less
variant of the full sample; and the error paths (non-ONIX input, malformed
XML).

## On the sample files

`examples/sample-onix-3.1.xml` is a hand-built, spec-accurate ONIX 3.1
reference-tag record rather than a byte-for-byte copy of an EDItEUR or
German National Library sample — EDItEUR's own downloads require an
interactive licence click-through on editeur.org, and the DNB's mirror is
a `.zip` on a host this environment can't reach. It's built to the same
structure real feeds use (see the comment at the top of the file), so it
exercises the same composites a production converter will see. Its
short-tag sibling files are likewise hand-built (not copied from any real
feed) but use element/attribute short tags cross-checked against a
production ONIX parser's source to make sure the codes are real.

## Validating output

Once you have a real feed to test, paste the JSON-LD output into Google's
[Rich Results Test](https://search.google.com/test/rich-results) to check
it's actually eligible for Book rich results, since Google's accepted
shape is stricter than bare schema.org validity.
