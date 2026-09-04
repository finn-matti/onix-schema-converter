<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Onix2Schema\OnixToSchemaConverter;

$failures = 0;
$assertions = 0;

function check(bool $condition, string $message): void
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

$converter = new OnixToSchemaConverter();

// --- Full sample: every mapped field should be present and correct.
$full = $converter->convertFile(__DIR__ . '/../examples/sample-onix-3.1.xml');
check(count($full) === 1, 'full sample yields exactly one product');
$book = $full[0];

check($book['@type'] === 'Book', 'root node is a Book');
check($book['isbn'] === '9783161484100', 'ISBN-13 preferred over other identifiers');
check($book['name'] === 'The Quiet Harbour: A Novel of the Baltic Coast', 'title + subtitle joined');
check($book['bookFormat'] === 'https://schema.org/Paperback', 'ProductForm BC maps to Paperback');
check($book['author']['name'] === 'Elina Vartiainen', 'primary author name mapped');
check(str_starts_with($book['author']['description'], 'Elina Vartiainen is a Helsinki-based'), 'author bio mapped');
check($book['translator']['name'] === 'Mika Laine', 'translator role B06 mapped');
check($book['inLanguage'] === 'en', 'ONIX eng converted to ISO 639-1 en');
check($book['numberOfPages'] === 312, 'ExtentType 11 (VLB-recommended total page count) mapped and cast to int');
check($book['genre'] === 'FICTION / Literary', 'BISAC subject preferred for genre');
check(str_starts_with($book['description'], 'When a storm strands'), 'long description (TextType 03) preferred');
check($book['image'] === 'https://covers.nordwind-verlag.example/9783161484100.jpg', 'front cover image mapped');
check($book['publisher']['name'] === 'Nordwind Verlag GmbH', 'publisher name mapped');
check($book['datePublished'] === '2026-09-12', 'ONIX YYYYMMDD converted to ISO date');
check($book['offers']['price'] === '18.90', 'price amount mapped');
check($book['offers']['priceCurrency'] === 'EUR', 'currency code mapped');
check($book['offers']['availability'] === 'https://schema.org/InStock', 'availability 20 maps to InStock');

// --- Sparse sample: fallback paths and multi-product messages.
$sparse = $converter->convertFile(__DIR__ . '/../examples/sample-onix-3.1-sparse.xml');
check(count($sparse) === 2, 'multi-product message yields one Book per Product');

$minimal = $sparse[0];
check($minimal['isbn'] === '9780000000024', 'falls back to GTIN-13 (IDType 03) when no ISBN-13 present');
check($minimal['bookFormat'] === 'https://schema.org/EBook', 'ProductForm EA maps to EBook');
check($minimal['genre'] === 'Thema fallback heading', 'falls back to Thema when no BISAC subject present');
check($minimal['description'] === 'Short description only.', 'falls back to short description (TextType 02)');
check(!isset($minimal['translator']), 'no translator property when no B06 contributor present');
check($minimal['offers']['availability'] === 'https://schema.org/OutOfStock', 'availability 40 maps to OutOfStock');
check(!isset($minimal['offers']['price']), 'no price key when no Price element present');

$second = $sparse[1];
check($second['name'] === 'Second Product In Message', 'second Product in the message is converted independently');
check(!isset($second['offers']), 'offers omitted entirely when no ProductSupply present');
check(!isset($second['publisher']), 'publisher omitted when no PublishingDetail present');

// --- Short-tag sparse: same fallback/omission paths as the sparse sample
// above, in short-tag form (multi-product, missing ProductSupply, etc.).
$sparseShortTag = $converter->convertFile(__DIR__ . '/../examples/sample-onix-3.1-sparse-short-tag.xml');
check($sparseShortTag === $sparse, 'short-tag sparse sample matches the reference-tag sparse sample field for field');

// --- Short-tag ONIX: real-world distributor feeds (VLB/Libri-style) often
// ship lowercase short-tag elements (<product>, <b012>) rather than the
// mixed-case reference-tag form (<Product>, <ProductForm>). Same content
// as the full sample, so the converted output should match field for field.
$shortTag = $converter->convertFile(__DIR__ . '/../examples/sample-onix-3.1-short-tag.xml');
check(count($shortTag) === 1, 'short-tag sample yields exactly one product');
$shortTagBook = $shortTag[0];
check($shortTagBook['isbn'] === $book['isbn'], 'short-tag: ISBN matches the reference-tag equivalent');
check($shortTagBook['name'] === $book['name'], 'short-tag: title+subtitle matches the reference-tag equivalent');
check($shortTagBook['bookFormat'] === $book['bookFormat'], 'short-tag: ProductForm (b012) mapped like reference-tag');
check($shortTagBook['author']['name'] === $book['author']['name'], 'short-tag: contributor (b035/b036) mapped like reference-tag');
check($shortTagBook['translator']['name'] === $book['translator']['name'], 'short-tag: second contributor role mapped');
check($shortTagBook['inLanguage'] === $book['inLanguage'], 'short-tag: language (b253/b252) mapped like reference-tag');
check($shortTagBook['numberOfPages'] === $book['numberOfPages'], 'short-tag: extent (b218/b219/b220) mapped like reference-tag');
check($shortTagBook['genre'] === $book['genre'], 'short-tag: BISAC subject (b067/b070) mapped like reference-tag');
check($shortTagBook['description'] === $book['description'], 'short-tag: long description (x426/d104) mapped like reference-tag');
check($shortTagBook['image'] === $book['image'], 'short-tag: cover resource link (x436/x437/x435) mapped like reference-tag');
check($shortTagBook['publisher']['name'] === $book['publisher']['name'], 'short-tag: publisher (b291/b081) mapped like reference-tag');
check($shortTagBook['datePublished'] === $book['datePublished'], 'short-tag: publishing date (x448/b306) mapped like reference-tag');
check($shortTagBook['offers'] === $book['offers'], 'short-tag: price/availability (x462/j151/j152/j396) mapped like reference-tag');

// --- ExtentType 00 fallback: some feeds still use the older "main content
// page count" convention instead of VLB's recommended ExtentType 11.
$extentFallbackXml = str_replace(
    '<ExtentType>11</ExtentType>',
    '<ExtentType>00</ExtentType>',
    (string) file_get_contents(__DIR__ . '/../examples/sample-onix-3.1.xml')
);
$extentFallback = $converter->convertString($extentFallbackXml);
check($extentFallback[0]['numberOfPages'] === 312, 'falls back to ExtentType 00 when ExtentType 11 is absent');

// --- Namespace-less input: real-world feeds sometimes omit the default
// xmlns on the root element entirely; the converter should still parse them.
$fullXml = (string) file_get_contents(__DIR__ . '/../examples/sample-onix-3.1.xml');
$namespaceLessXml = str_replace(' xmlns="http://ns.editeur.org/onix/3.0/reference"', '', $fullXml);
check(!str_contains($namespaceLessXml, 'xmlns'), 'fixture actually has no xmlns left to test against');
$namespaceLess = $converter->convertString($namespaceLessXml);
check($namespaceLess[0]['isbn'] === '9783161484100', 'converts correctly when root element has no xmlns at all');

// --- Error handling
try {
    $converter->convertString('<not-onix/>');
    check(false, 'should throw when no <Product> nodes are found');
} catch (\RuntimeException $e) {
    check(str_contains($e->getMessage(), 'No <Product> records'), 'clear error message on non-ONIX input');
}

try {
    $converter->convertString('<broken');
    check(false, 'should throw on malformed XML');
} catch (\RuntimeException $e) {
    check(str_contains($e->getMessage(), 'Invalid XML'), 'clear error message on malformed XML');
}

printf("%d assertions, %d failures\n", $assertions, $failures);
exit($failures > 0 ? 1 : 0);
