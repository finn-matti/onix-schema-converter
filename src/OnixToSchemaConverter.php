<?php

declare(strict_types=1);

namespace Onix2Schema;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Converts ONIX for Books 3.1 <Product> records into schema.org Book
 * JSON-LD nodes. Reads both reference-tag (<Product>, <ProductForm>) and
 * short-tag (<product>, <b012>) ONIX 3.0/3.1, auto-detected per document.
 *
 * See MAPPING.md in the project root for the exact field mapping and
 * documented v1 scope decisions this class implements.
 */
final class OnixToSchemaConverter
{
    private const ONIX_NS = 'http://ns.editeur.org/onix/3.0/reference';

    /**
     * Reference-tag element name -> short-tag equivalent, ONIX 3.0/3.1.
     * All XPath expressions in this class are written against reference-tag
     * names; translate() rewrites them to short-tag names when a document
     * turns out to be short-tag. Verified against a real production ONIX
     * short-tag parser/fixtures, not the ONIX spec directly.
     */
    private const SHORT_TAGS = [
        'Product' => 'product',
        'ProductIdentifier' => 'productidentifier',
        'ProductIDType' => 'b221',
        'IDValue' => 'b244',
        'DescriptiveDetail' => 'descriptivedetail',
        'TitleDetail' => 'titledetail',
        'TitleType' => 'b202',
        'TitleElement' => 'titleelement',
        'TitleText' => 'b203',
        'Subtitle' => 'b029',
        'ProductForm' => 'b012',
        'Contributor' => 'contributor',
        'ContributorRole' => 'b035',
        'PersonName' => 'b036',
        'BiographicalNote' => 'b044',
        'Language' => 'language',
        'LanguageRole' => 'b253',
        'LanguageCode' => 'b252',
        'Extent' => 'extent',
        'ExtentType' => 'b218',
        'ExtentUnit' => 'b220',
        'ExtentValue' => 'b219',
        'Subject' => 'subject',
        'SubjectSchemeIdentifier' => 'b067',
        'SubjectHeadingText' => 'b070',
        'CollateralDetail' => 'collateraldetail',
        'TextContent' => 'textcontent',
        'TextType' => 'x426',
        'Text' => 'd104',
        'SupportingResource' => 'supportingresource',
        'ResourceContentType' => 'x436',
        'ResourceMode' => 'x437',
        'ResourceVersion' => 'resourceversion',
        'ResourceLink' => 'x435',
        'PublishingDetail' => 'publishingdetail',
        'Publisher' => 'publisher',
        'PublishingRole' => 'b291',
        'PublisherName' => 'b081',
        'PublishingDate' => 'publishingdate',
        'PublishingDateRole' => 'x448',
        'Date' => 'b306',
        'ProductSupply' => 'productsupply',
        'SupplyDetail' => 'supplydetail',
        'Price' => 'price',
        'PriceType' => 'x462',
        'PriceAmount' => 'j151',
        'CurrencyCode' => 'j152',
        'ProductAvailability' => 'j396',
    ];

    /** ONIX List150 (ProductForm) -> schema.org BookFormatType, v1 subset. */
    private const BOOK_FORMAT_MAP = [
        'BB' => 'https://schema.org/Hardcover',
        'BC' => 'https://schema.org/Paperback',
        'ED' => 'https://schema.org/EBook',
        'EA' => 'https://schema.org/EBook',
        'AJ' => 'https://schema.org/AudiobookFormat',
    ];

    /** ONIX List91 (ContributorRole) -> schema.org property name, v1 subset. */
    private const CONTRIBUTOR_ROLE_MAP = [
        'A01' => 'author',
        'B06' => 'translator',
    ];

    /** ONIX/MARC 3-letter language code -> ISO 639-1, common subset. */
    private const LANGUAGE_MAP = [
        'eng' => 'en',
        'ger' => 'de',
        'deu' => 'de',
        'fre' => 'fr',
        'fra' => 'fr',
        'fin' => 'fi',
        'swe' => 'sv',
        'spa' => 'es',
        'ita' => 'it',
    ];

    /** ONIX List65 (ProductAvailability) -> schema.org ItemAvailability, v1 subset. */
    private const AVAILABILITY_MAP = [
        '10' => 'https://schema.org/PreOrder',
        '11' => 'https://schema.org/PreOrder',
        '20' => 'https://schema.org/InStock',
        '21' => 'https://schema.org/InStock',
        '30' => 'https://schema.org/OutOfStock',
        '31' => 'https://schema.org/Discontinued',
        '40' => 'https://schema.org/OutOfStock',
    ];

    /**
     * Set by convertString() for the duration of one conversion: null for
     * reference-tag documents (XPath used as written), or self::SHORT_TAGS
     * for short-tag documents (XPath rewritten before use). See translate().
     *
     * @var array<string, string>|null
     */
    private ?array $shortTagMap = null;

    /**
     * Convert every <Product> in an ONIX message file into an array of
     * schema.org Book nodes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function convertFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException("Cannot read ONIX file: {$path}");
        }

        return $this->convertString((string) file_get_contents($path));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function convertString(string $xml): array
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;

        libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($this->withDefaultNamespaceIfMissing($xml), LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (!$loaded) {
            $messages = array_map(static fn ($e) => trim($e->message), $errors);
            throw new RuntimeException('Invalid XML: ' . implode('; ', $messages));
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('o', self::ONIX_NS);

        // Reference-tag (<Product>) and short-tag (<product>) are the two
        // real-world ONIX 3.0/3.1 syntaxes; detect which one this document
        // uses so translate() knows whether to rewrite queries below.
        $referenceProductCount = $xpath->evaluate('count(//o:Product)');
        $this->shortTagMap = (is_numeric($referenceProductCount) && (float) $referenceProductCount > 0)
            ? null
            : self::SHORT_TAGS;

        $productNodes = $this->queryElements($xpath, '//o:Product', $doc);
        if ($productNodes === []) {
            throw new RuntimeException('No <Product> records found. Expected reference-tag or short-tag ONIX 3.0/3.1, with or without the reference namespace declared.');
        }

        $books = [];
        foreach ($productNodes as $productNode) {
            $books[] = $this->convertProduct($productNode, $xpath);
        }

        return $books;
    }

    /**
     * Real-world ONIX feeds are inconsistent about declaring the reference
     * namespace on the root element — some do, some ship bare unprefixed
     * elements. XPath below is written against the `o:` prefix, so a file
     * with no namespace at all would otherwise silently match nothing. If
     * the root element declares no `xmlns` at all, assume it's meant to be
     * the ONIX reference namespace and inject it before parsing. A file
     * that already declares some other namespace is left untouched.
     */
    private function withDefaultNamespaceIfMissing(string $xml): string
    {
        if (!preg_match('/<([A-Za-z_][\w.\-]*)((?:\s+[^>]*)?)>/s', $xml, $m, PREG_OFFSET_CAPTURE)) {
            return $xml;
        }

        if (str_contains($m[2][0], 'xmlns')) {
            return $xml;
        }

        $insertAt = $m[1][1] + strlen($m[1][0]);
        return substr($xml, 0, $insertAt) . ' xmlns="' . self::ONIX_NS . '"' . substr($xml, $insertAt);
    }

    /**
     * @return array<string, mixed>
     */
    private function convertProduct(DOMElement $product, DOMXPath $xpath): array
    {
        $book = [
            '@context' => 'https://schema.org',
            '@type' => 'Book',
        ];

        $isbn = $this->mapIsbn($product, $xpath);
        if ($isbn !== null) {
            $book['isbn'] = $isbn;
        }

        $name = $this->mapTitle($product, $xpath);
        if ($name !== null) {
            $book['name'] = $name;
        }

        $bookFormat = $this->mapBookFormat($product, $xpath);
        if ($bookFormat !== null) {
            $book['bookFormat'] = $bookFormat;
        }

        foreach ($this->mapContributors($product, $xpath) as $property => $person) {
            $book[$property] = $person;
        }

        $language = $this->mapLanguage($product, $xpath);
        if ($language !== null) {
            $book['inLanguage'] = $language;
        }

        $pages = $this->mapPageCount($product, $xpath);
        if ($pages !== null) {
            $book['numberOfPages'] = $pages;
        }

        $genre = $this->mapGenre($product, $xpath);
        if ($genre !== null) {
            $book['genre'] = $genre;
        }

        $description = $this->mapDescription($product, $xpath);
        if ($description !== null) {
            $book['description'] = $description;
        }

        $image = $this->mapImage($product, $xpath);
        if ($image !== null) {
            $book['image'] = $image;
        }

        $publisher = $this->mapPublisher($product, $xpath);
        if ($publisher !== null) {
            $book['publisher'] = $publisher;
        }

        $datePublished = $this->mapDatePublished($product, $xpath);
        if ($datePublished !== null) {
            $book['datePublished'] = $datePublished;
        }

        $offer = $this->mapOffer($product, $xpath);
        if ($offer !== null) {
            $book['offers'] = $offer;
        }

        return $book;
    }

    private function mapIsbn(DOMElement $product, DOMXPath $xpath): ?string
    {
        $isbn13 = $this->evalString($xpath, 'string(.//o:ProductIdentifier[o:ProductIDType="15"]/o:IDValue)', $product);
        if ($isbn13 !== '') {
            return $isbn13;
        }

        $gtin13 = $this->evalString($xpath, 'string(.//o:ProductIdentifier[o:ProductIDType="03"]/o:IDValue)', $product);
        return $gtin13 !== '' ? $gtin13 : null;
    }

    private function mapTitle(DOMElement $product, DOMXPath $xpath): ?string
    {
        $titleElements = $this->queryElements($xpath, './/o:TitleDetail[o:TitleType="01"]/o:TitleElement[1]', $product);
        if ($titleElements === []) {
            return null;
        }
        $titleElement = $titleElements[0];

        $title = $this->evalString($xpath, 'string(o:TitleText)', $titleElement);
        if ($title === '') {
            return null;
        }

        $subtitle = $this->evalString($xpath, 'string(o:Subtitle)', $titleElement);

        return $subtitle !== '' ? "{$title}: {$subtitle}" : $title;
    }

    private function mapBookFormat(DOMElement $product, DOMXPath $xpath): ?string
    {
        $productForm = $this->evalString($xpath, 'string(.//o:DescriptiveDetail/o:ProductForm)', $product);

        return self::BOOK_FORMAT_MAP[$productForm] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by schema.org property (author|translator)
     */
    private function mapContributors(DOMElement $product, DOMXPath $xpath): array
    {
        $result = [];

        foreach ($this->queryElements($xpath, './/o:DescriptiveDetail/o:Contributor', $product) as $contributor) {
            $role = $this->evalString($xpath, 'string(o:ContributorRole)', $contributor);
            $property = self::CONTRIBUTOR_ROLE_MAP[$role] ?? null;
            if ($property === null || isset($result[$property])) {
                // v1 keeps only the first contributor per mapped role.
                continue;
            }

            $name = $this->evalString($xpath, 'string(o:PersonName)', $contributor);
            if ($name === '') {
                continue;
            }

            $person = [
                '@type' => 'Person',
                'name' => $name,
            ];

            $bio = $this->evalString($xpath, 'string(o:BiographicalNote)', $contributor);
            if ($bio !== '') {
                $person['description'] = $bio;
            }

            $result[$property] = $person;
        }

        return $result;
    }

    private function mapLanguage(DOMElement $product, DOMXPath $xpath): ?string
    {
        $code = $this->evalString(
            $xpath,
            'string(.//o:DescriptiveDetail/o:Language[o:LanguageRole="01"]/o:LanguageCode)',
            $product
        );
        if ($code === '') {
            return null;
        }

        return self::LANGUAGE_MAP[$code] ?? $code;
    }

    private function mapPageCount(DOMElement $product, DOMXPath $xpath): ?int
    {
        $value = $this->evalString(
            $xpath,
            'string(.//o:DescriptiveDetail/o:Extent[o:ExtentType="00" and o:ExtentUnit="03"]/o:ExtentValue)',
            $product
        );

        return ($value !== '' && ctype_digit($value)) ? (int) $value : null;
    }

    private function mapGenre(DOMElement $product, DOMXPath $xpath): ?string
    {
        $bisac = $this->evalString(
            $xpath,
            'string(.//o:DescriptiveDetail/o:Subject[o:SubjectSchemeIdentifier="93"]/o:SubjectHeadingText)',
            $product
        );
        if ($bisac !== '') {
            return $bisac;
        }

        $thema = $this->evalString(
            $xpath,
            'string(.//o:DescriptiveDetail/o:Subject[o:SubjectSchemeIdentifier="73" or o:SubjectSchemeIdentifier="94"]/o:SubjectHeadingText)',
            $product
        );

        return $thema !== '' ? $thema : null;
    }

    private function mapDescription(DOMElement $product, DOMXPath $xpath): ?string
    {
        $long = $this->evalString(
            $xpath,
            'string(.//o:CollateralDetail/o:TextContent[o:TextType="03"]/o:Text)',
            $product
        );
        if ($long !== '') {
            return $long;
        }

        $short = $this->evalString(
            $xpath,
            'string(.//o:CollateralDetail/o:TextContent[o:TextType="02"]/o:Text)',
            $product
        );

        return $short !== '' ? $short : null;
    }

    private function mapImage(DOMElement $product, DOMXPath $xpath): ?string
    {
        $link = $this->evalString(
            $xpath,
            'string(.//o:CollateralDetail/o:SupportingResource[o:ResourceContentType="01" and o:ResourceMode="03"]/o:ResourceVersion[1]/o:ResourceLink)',
            $product
        );

        return $link !== '' ? $link : null;
    }

    /**
     * @return array<string, string>|null
     */
    private function mapPublisher(DOMElement $product, DOMXPath $xpath): ?array
    {
        $name = $this->evalString(
            $xpath,
            'string(.//o:PublishingDetail/o:Publisher[o:PublishingRole="01"]/o:PublisherName)',
            $product
        );
        if ($name === '') {
            return null;
        }

        return [
            '@type' => 'Organization',
            'name' => $name,
        ];
    }

    private function mapDatePublished(DOMElement $product, DOMXPath $xpath): ?string
    {
        $raw = $this->evalString(
            $xpath,
            'string(.//o:PublishingDetail/o:PublishingDate[o:PublishingDateRole="01"]/o:Date)',
            $product
        );
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
            return null;
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapOffer(DOMElement $product, DOMXPath $xpath): ?array
    {
        $supplyDetails = $this->queryElements($xpath, './/o:ProductSupply/o:SupplyDetail[1]', $product);
        if ($supplyDetails === []) {
            return null;
        }
        $supply = $supplyDetails[0];

        // Prefer PriceType 02 (RRP including tax); fall back to the first price present.
        $priceElements = $this->queryElements($xpath, 'o:Price[o:PriceType="02"][1]', $supply);
        if ($priceElements === []) {
            $priceElements = $this->queryElements($xpath, 'o:Price[1]', $supply);
        }

        $offer = ['@type' => 'Offer'];
        $hasData = false;

        if ($priceElements !== []) {
            $price = $priceElements[0];
            $amount = $this->evalString($xpath, 'string(o:PriceAmount)', $price);
            $currency = $this->evalString($xpath, 'string(o:CurrencyCode)', $price);
            if ($amount !== '') {
                $offer['price'] = $amount;
                $hasData = true;
            }
            if ($currency !== '') {
                $offer['priceCurrency'] = $currency;
                $hasData = true;
            }
        }

        $availabilityCode = $this->evalString($xpath, 'string(o:ProductAvailability)', $supply);
        if (isset(self::AVAILABILITY_MAP[$availabilityCode])) {
            $offer['availability'] = self::AVAILABILITY_MAP[$availabilityCode];
            $hasData = true;
        }

        return $hasData ? $offer : null;
    }

    /**
     * Evaluates an XPath string() expression, trimmed. Centralizes the
     * false/non-string guard XPath::evaluate() needs (e.g. on malformed
     * expressions) instead of repeating a cast-and-trim at every call site.
     */
    private function evalString(DOMXPath $xpath, string $expression, DOMNode $context): string
    {
        $result = $xpath->evaluate($this->translate($expression), $context);

        return is_string($result) ? trim($result) : '';
    }

    /**
     * Runs an XPath query and returns only the element results, never
     * false. Centralizes the false-on-invalid-expression guard
     * DOMXPath::query() needs instead of repeating it at every call site.
     *
     * @return array<int, DOMElement>
     */
    private function queryElements(DOMXPath $xpath, string $expression, DOMNode $context): array
    {
        $nodes = $xpath->query($this->translate($expression), $context);
        if ($nodes === false) {
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }

    /**
     * Every XPath expression in this class is written against reference-tag
     * element names (o:ProductIdentifier, o:IDValue, ...). For a short-tag
     * document, rewrite each o:Name token to its short-tag equivalent via
     * self::SHORT_TAGS before it reaches DOMXPath. A no-op for
     * reference-tag documents ($this->shortTagMap is null).
     */
    private function translate(string $expression): string
    {
        if ($this->shortTagMap === null) {
            return $expression;
        }

        return preg_replace_callback(
            '/o:([A-Za-z]+)/',
            fn (array $m): string => 'o:' . ($this->shortTagMap[$m[1]] ?? $m[1]),
            $expression
        );
    }
}
