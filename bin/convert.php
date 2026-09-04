#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Onix2Schema\OnixToSchemaConverter;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/convert.php path/to/onix-file.xml\n");
    exit(1);
}

$path = $argv[1];

try {
    $converter = new OnixToSchemaConverter();
    $books = $converter->convertFile($path);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Conversion failed: ' . $e->getMessage() . "\n");
    exit(1);
}

// A single-product message converts to one Book node (not a one-element
// array) so the output can be dropped straight into a <script type="ld+json">
// tag; a multi-product message emits a JSON array of Book nodes.
$output = count($books) === 1 ? $books[0] : $books;

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
