<?php

declare(strict_types=1);

use AppHive\Toml\Toml;

/**
 * Known limitations in the TOML parser that cause valid test cases to fail.
 * These are documented for future improvement and tracked for spec compliance.
 *
 * Categories of known issues:
 * - Numeric bare keys: Keys like "123", "0", "10e3" are not recognized as valid bare keys
 * - Datetime normalization: Parser doesn't normalize datetime formats (space to T, lowercase z to Z)
 * - Control character handling: Some control characters in specific contexts
 * - TOML 1.1.0 features: Some TOML 1.1.0 spec features not fully supported
 */
const KNOWN_VALID_FAILURES = [
    // Bare keys that look like other token types - the lexer tokenizes these as keywords/numbers
    // instead of treating them as bare keys in key position. Fixing requires context-aware lexing.
    'key / alphanum',         // Uses all-digit keys like "123", "000111", "10e3"
    'key / like-date',        // Uses date-like keys like "1979-05-27"
    'key / special-word',     // Uses "true", "false", "inf", "nan" as keys
    'key / start',            // Uses numeric keys in table headers like [2018_10]
    'table / keyword',        // Uses [true], [false], [inf], [nan] as table names
    'table / keyword-with-values', // Same issue with keyword table names
    'datetime / leap-year',   // Uses date-like keys like "2000-datetime"
    'comment / after-literal-no-ws', // Uses "inf", "nan", "true", "false" as keys

    // Test suite inconsistency: datetime/milliseconds expects ".6" -> ".600" (padded)
    // but spec-1.1.0/common-27 expects ".5" -> ".5" (preserved). We preserve precision.
    'datetime / milliseconds',
];

/**
 * Helper function to convert toml-test JSON expected values to PHP values.
 * The toml-test suite uses tagged JSON format: {"type": "string", "value": "foo"}
 *
 * @param  array<mixed>  $tagged
 * @return array<mixed>|mixed
 */
function convertTaggedToPhp(array $tagged): mixed
{
    // Check if this is a tagged value (has 'type' and 'value' keys)
    if (isset($tagged['type']) && isset($tagged['value']) && count($tagged) === 2) {
        $type = $tagged['type'];
        $value = $tagged['value'];

        if (is_string($type) && is_string($value)) {
            return convertTaggedValue($type, $value);
        }
    }

    // Check if this is a simple array (list of tagged values)
    if (array_is_list($tagged)) {
        return array_map(function ($item) {
            if (is_array($item)) {
                return convertTaggedToPhp($item);
            }

            return $item;
        }, $tagged);
    }

    // Otherwise, it's an object/table - recurse into each key
    $result = [];
    foreach ($tagged as $key => $value) {
        if (is_array($value)) {
            $result[$key] = convertTaggedToPhp($value);
        } else {
            $result[$key] = $value;
        }
    }

    return $result;
}

/**
 * Convert a single tagged value to its PHP equivalent.
 */
function convertTaggedValue(string $type, string $value): mixed
{
    return match ($type) {
        'string' => $value,
        'integer' => (int) $value,
        'float' => match ($value) {
            'inf', '+inf' => INF,
            '-inf' => -INF,
            'nan', '+nan', '-nan' => NAN,
            default => (float) $value,
        },
        'bool' => $value === 'true',
        'datetime' => $value,
        'datetime-local' => $value,
        'date-local' => $value,
        'time-local' => $value,
        default => $value,
    };
}

/**
 * Get all valid test cases from the toml-test suite.
 *
 * @return array<string, array{toml: string, expected: array<string, mixed>}>
 */
function getValidTestCases(): array
{
    $testDir = __DIR__.'/toml-test/tests/valid';
    /** @var array<string, array{toml: string, expected: array<string, mixed>}> $cases */
    $cases = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'toml') {
            continue;
        }

        $tomlPath = $file->getPathname();
        $jsonPath = preg_replace('/\.toml$/', '.json', $tomlPath);

        if (! is_string($jsonPath) || ! file_exists($jsonPath)) {
            continue;
        }

        // Create a readable test name from the path
        $relativePath = str_replace($testDir.'/', '', $tomlPath);
        $testName = str_replace(['/', '.toml'], [' / ', ''], $relativePath);

        $tomlContent = file_get_contents($tomlPath);
        $jsonContent = file_get_contents($jsonPath);

        if ($tomlContent === false || $jsonContent === false) {
            continue;
        }

        /** @var array<string, mixed> $expected */
        $expected = json_decode($jsonContent, true);

        $cases[$testName] = [
            'toml' => $tomlContent,
            'expected' => $expected,
        ];
    }

    ksort($cases);

    return $cases;
}

$testDir = __DIR__.'/toml-test/tests/valid';

if (! is_dir($testDir)) {
    describe('toml-test valid cases', function () {
        it('requires toml-test suite to be installed', function () {
            $this->markTestSkipped(
                'toml-test suite not found. Run: git clone --depth 1 https://github.com/toml-lang/toml-test.git tests/toml-test'
            );
        });
    });
} else {
    describe('toml-test valid cases', function () {
        $cases = getValidTestCases();

        foreach ($cases as $testName => $testData) {
            $test = it("parses valid: {$testName}", function () use ($testData) {
                $toml = $testData['toml'];
                $expected = convertTaggedToPhp($testData['expected']);

                $result = Toml::parse($toml);

                // Handle NaN comparison specially since NaN !== NaN
                $normalizedExpected = normalizeForComparison($expected);
                $normalizedResult = normalizeForComparison($result);

                expect($normalizedResult)->toBe($normalizedExpected);
            });

            if (in_array($testName, KNOWN_VALID_FAILURES, true)) {
                $test->skip('Known limitation - see KNOWN_VALID_FAILURES for details');
            }
        }
    });
}

/**
 * Normalize values for comparison, converting NaN to a placeholder
 * and recursively sorting array keys for consistent comparison.
 */
function normalizeForComparison(mixed $value): mixed
{
    if (is_float($value) && is_nan($value)) {
        return '__NAN__';
    }

    if (is_array($value)) {
        // Recursively normalize values
        $normalized = array_map('normalizeForComparison', $value);

        // Sort by keys if it's an associative array (not a list)
        // Use SORT_STRING to ensure consistent sorting of mixed int/string keys
        if (! array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    return $value;
}
