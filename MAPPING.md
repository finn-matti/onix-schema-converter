# ONIX 3.1 → schema.org v1 field mapping

Scope decisions for the first version of the converter. Input is one ONIX
3.1 `<Product>` record, in either reference-tag (`<Product>`,
`<ProductForm>`) or short-tag (`<product>`, `<b012>`) syntax — the dialect
is auto-detected per document; output is one schema.org `Book` JSON-LD
node with a nested `Offer`.

## Design decisions

- **Output shape:** a `Book` node with `offers` set directly to an `Offer`
  object. Google's own recommended pattern (from the BISG/EDItEUR working
  group Graham Bell chaired) is stricter — `Book.workExample → Product →
  offers` — because `Offer` isn't formally a property of `Book`. v1 uses
  the flatter, widely-used shape since it's what most publisher sites
  actually emit and it's simpler to validate by eye; the stricter
  `workExample` wrapping is a documented v2 option, not a v1 gap.
- **One record in, one record out.** No handling of `<NotificationType>`
  05 (delete) or related/child products yet — those change what "one
  output record" even means, so they're deferred.
- **Reference-tag and short-tag, both syntaxes.** The field mapping table
  below is written against reference-tag element names for readability;
  `OnixToSchemaConverter::SHORT_TAGS` holds the short-tag equivalent for
  each one (`ProductIdentifier`→`productidentifier`,
  `ProductIDType`→`b221`, …), auto-selected per document by checking
  whether `<Product>` or `<product>` is present at the root. Real
  distributor feeds (VLB/Libri-style) commonly ship short-tag, lowercase,
  unnamespaced ONIX rather than the reference-tag form used in the ONIX
  spec's own examples.
- **ONIX 3.0/3.1 only, no 2.1.** Confirmed as the right cutoff, not just a
  convenient one: VLB (the German book trade's central catalog) is
  retiring ONIX 2.1 import entirely from 2027, and already shut off its
  own 2.1 data feeds at the end of 2025.
- **First value wins** where ONIX allows repeats we don't yet fold
  together (e.g. multiple `<Subject>` schemes, multiple `<Price>` blocks
  for different territories/currencies) — v1 takes the first sensible
  match per field rather than modelling every repeat, and notes each
  case below.

## Field mapping table

| ONIX 3.1 path | Condition | schema.org target | Notes |
|---|---|---|---|
| `ProductIdentifier/IDValue` | `ProductIDType=15` (ISBN-13) | `Book.isbn` | Falls back to `ProductIDType=03` (GTIN-13) if no 15 is present. |
| `DescriptiveDetail/TitleDetail/TitleElement/TitleText` | `TitleType=01` (distinctive title) | `Book.name` | `Subtitle`, if present, is appended as `"Title: Subtitle"`. |
| `DescriptiveDetail/ProductForm` (+ `ProductFormDetail`) | — | `Book.bookFormat` | Mapped via a small ONIX List150 → schema.org `BookFormatType` table (`BC`→`Paperback`, `BB`→`Hardcover`, `ED`/`EA`→`EBook`, `AJ`→`AudiobookFormat`). Unmapped codes are omitted rather than guessed. |
| `DescriptiveDetail/Contributor` | `ContributorRole=A01` | `Book.author` (`Person`) | First A01 contributor only in v1; co-authors are a documented gap. |
| `DescriptiveDetail/Contributor/BiographicalNote` | on the mapped author/editor | `author.description` / `editor.description` | |
| `DescriptiveDetail/Contributor` | `ContributorRole=B01` | `Book.editor` (`Person`) | First B01 contributor only, same as author. Common on edited volumes/Festschriften, which otherwise have no A01 author at all. |
| `DescriptiveDetail/Contributor` | `ContributorRole=B06` | `Book.translator` (`Person`) | |
| `DescriptiveDetail/Contributor/PersonName` (or `PersonNameInverted` if `PersonName` is absent) | on the mapped author/editor/translator | `.name` | Some feeds only send the inverted "Surname, Forename" form; used as-is rather than guessed apart, since name-part order isn't safe to assume across locales. |
| `DescriptiveDetail/Language` | `LanguageRole=01` (language of text) | `Book.inLanguage` | ONIX/MARC 3-letter code converted to ISO 639-1 where a mapping exists (`eng`→`en`, `ger`→`de`, `fin`→`fi`, …); otherwise the 3-letter code passes through. |
| `DescriptiveDetail/Extent` | `ExtentType=11` (total page count, VLB's recommended primary code), `ExtentUnit=03` (pages) | `Book.numberOfPages` | Falls back to `ExtentType=00` (main content page count) for feeds still using that older convention. Other extent units (word count, running time) are skipped in v1. |
| `DescriptiveDetail/Subject` | `SubjectSchemeIdentifier=93` (BISAC) | `Book.genre` | If no BISAC subject exists, falls back to the first Thema heading text. Only one genre string in v1 — no modelling of primary vs. secondary subjects. |
| `CollateralDetail/TextContent` | `TextType=03` (description/annotation) | `Book.description` | Falls back to `TextType=02` (short description) if no long description exists. |
| `CollateralDetail/SupportingResource` | `ResourceContentType=01` (front cover), `ResourceMode=03` (image) | `Book.image` | Uses `ResourceLink`; the first matching resource wins if several are present. |
| `PublishingDetail/Publisher/PublisherName` | `PublishingRole=01` | `Book.publisher` (`Organization`) | Imprint is not separately modelled in v1 — it's a real ONIX/schema.org mismatch (ONIX distinguishes publisher vs. imprint; schema.org has no imprint property) and is called out as a known gap rather than silently folded in. |
| `PublishingDetail/PublishingDate` | `PublishingDateRole=01` (publication date) | `Book.datePublished` | ONIX `YYYYMMDD` converted to ISO `YYYY-MM-DD`. |
| `ProductSupply/SupplyDetail/Price/PriceAmount` + `CurrencyCode` | `PriceType=04` (fixed retail price incl. tax) preferred, then `02` (unbound RRP incl. tax), else first price present | `Offer.price` / `Offer.priceCurrency` | `04` is the price actually bound by German Buchpreisbindung law — confirmed as the right default by two independent sources: VLB names it "Gebundener Ladenpreis", and it's the only example value Libri's own ONIX 3.1 spec gives for this field. Multiple territories/currencies collapse to one `Offer` in v1 — documented gap. |
| `ProductSupply/SupplyDetail/ProductAvailability` | — | `Offer.availability` | Mapped via a List65 subset, following the temporary-vs-permanent taxonomy in Börsenverein's official ONIX best-practice guide: `20`/`21`/`23` (incl. print-on-demand) → `InStock`; `10`/`11`/`12` → `PreOrder`; `30`/`31`/`32`/`33`/`34`/`44` (temporary) → `OutOfStock`; `01`/`40`/`41`/`42`/`43`/`46`/`47`/`48`/`51`/`52` (permanent) → `Discontinued`. `45` ("not sold separately") and `50` ("not sold as set") are deliberately left unmapped — the same guide's own examples pair them with an *active* `PublishingStatus`, i.e. the product is available, just not standalone. `09` ("postponed indefinitely") is also left unmapped: no clean schema.org equivalent. Unmapped codes omit `availability` rather than guess. |

## Explicitly out of scope for v1

- Series/collection metadata (`Collection`, `Series`).
- Awards, reviews/endorsement quotes as structured `Review` nodes (the
  text exists in the sample as `TextType=04` but v1 doesn't emit it).
- Accessibility metadata (EPUB accessibility summary, EAA-driven codes).
- Multiple contributors of the same role, multiple prices/territories,
  multiple cover images.
- Audience/reading-age codes — ONIX's audience model and schema.org's
  `audience`/`contentRating` don't line up cleanly enough to map
  confidently in v1.
- Any `<RelatedProduct>` handling (e.g. "also available as").
- `PublishingStatus` (b394/List64) is not read at all — v1 converts
  whatever `<Product>` records it's given regardless of status, so a
  cancelled or not-yet-released title converts the same as a shipping one.
  Less urgent a gap than it first looked, though: Börsenverein's official
  ONIX best-practice guide documents `PublishingStatus` and
  `ProductAvailability` as required to stay non-contradictory (e.g.
  `PublishingStatus=01` Cancelled should never appear with
  `ProductAvailability=20` Available), so `ProductAvailability` — which we
  already read — should reliably reflect the same lifecycle state on its
  own in well-formed feeds. A v2 that wants to *detect* a feed violating
  that (send-side bug) rather than trust `ProductAvailability` alone would
  still need to read `PublishingStatus`.
- Contributor `NameIdentifier` (ISNI/ORCID/GND, List44) isn't mapped to
  `Person.sameAs` or similar — contributors are name + bio only.
- Only `PublishingDateRole=01` (publication date) is read. VLB also names
  `02` (first-sale/embargo date), `09` (earliest announcement date), and
  `20` (original work's first publication date) as meaningful roles.
- Libri's `ProductClassification` composite (`ProductClassificationType`
  values like "Libri Rabattgruppe", "Libri Produktklassifikation",
  "Libri Lieferzeitklasse") is not read. Checked and deliberately excluded,
  not an oversight: it's a logistics/trade classification (bundle
  markers, shipping-weight flags, discount groups), not a genre or subject
  scheme, so it has no schema.org target worth mapping to for a Book node.
