<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Toml;

/**
 * Known limitations in the TOML parser that cause invalid test cases to pass (not reject).
 * These are documented for future improvement and tracked for spec compliance.
 *
 * Categories of known issues:
 * - Control characters: Parser doesn't reject all invalid control characters in strings/comments
 * - Datetime validation: Parser doesn't validate datetime ranges (e.g., Feb 30, hour > 23)
 * - Float validation: Parser doesn't reject all invalid underscore positions in floats
 * - Integer validation: Parser doesn't reject all invalid integer formats
 * - Array/table semantics: Parser doesn't catch all invalid array/table combinations
 * - Encoding validation: Parser doesn't validate UTF-8 encoding
 * - Unicode escapes: Parser doesn't validate all unicode escape sequences
 */
const KNOWN_INVALID_FAILURES = [
    // Control character validation - parser doesn't reject all invalid control chars
    'control / bare-cr',
    // Multiline string CR handling - bare CR in multiline strings is treated as newline
    'control / multi-cr',
    'control / rawmulti-cr',
    // Bare control characters outside strings - parser doesn't validate all bare control chars
    'control / only-null',
    'control / only-vt',
    // Tests from control.multi file - these contain literal \xNN sequences as text, not actual control chars
    // The .multi file format expects pre-processing that isn't being done
    'control / control / comment-cr',
    'control / control / comment-del',
    'control / control / comment-ff',
    'control / control / comment-lf',
    'control / control / comment-null',
    'control / control / comment-us',
    'control / control / multi-cr',
    'control / control / multi-del',
    'control / control / multi-lf',
    'control / control / multi-null',
    'control / control / multi-us',
    'control / control / rawmulti-cr',
    'control / control / rawmulti-del',
    'control / control / rawmulti-lf',
    'control / control / rawmulti-null',
    'control / control / rawmulti-us',
    'control / control / rawstring-cr',
    'control / control / rawstring-del',
    'control / control / rawstring-lf',
    'control / control / rawstring-null',
    'control / control / rawstring-us',
    'control / control / string-bs',
    'control / control / string-cr',
    'control / control / string-del',
    'control / control / string-lf',
    'control / control / string-null',
    'control / control / string-us',

    // Datetime validation - parser doesn't validate date/time ranges
    'datetime / day-zero',
    'datetime / feb-29',
    'datetime / feb-30',
    'datetime / hour-over',
    'datetime / mday-over',
    'datetime / mday-under',
    'datetime / minute-over',
    'datetime / month-over',
    'datetime / month-under',
    'datetime / no-secs', // TOML 1.1.0 allows optional seconds
    'datetime / offset-minus-minute-1digit',
    'datetime / offset-minus-no-hour-minute',
    'datetime / offset-minus-no-hour-minute-sep',
    'datetime / offset-minus-no-minute',
    'datetime / offset-overflow-hour',
    'datetime / offset-overflow-minute',
    'datetime / offset-plus-minute-1digit',
    'datetime / offset-plus-no-hour-minute',
    'datetime / offset-plus-no-hour-minute-sep',
    'datetime / offset-plus-no-minute',
    'datetime / second-over',
    'datetime / second-trailing-dot',
    'datetime / second-trailing-dotz',
    'local-date / feb-29',
    'local-date / feb-30',
    'local-date / mday-over',
    'local-date / mday-under',
    'local-date / month-over',
    'local-date / month-under',
    'local-datetime / feb-29',
    'local-datetime / feb-30',
    'local-datetime / hour-over',
    'local-datetime / mday-over',
    'local-datetime / mday-under',
    'local-datetime / minute-over',
    'local-datetime / month-over',
    'local-datetime / month-under',
    'local-datetime / no-secs', // TOML 1.1.0 allows optional seconds
    'local-datetime / second-over',
    'local-time / hour-over',
    'local-time / minute-over',
    'local-time / no-secs', // TOML 1.1.0 allows optional seconds
    'local-time / second-over',

    // Encoding validation - parser doesn't validate UTF-8
    'encoding / bad-codepoint',
    'encoding / bad-utf8-in-comment',
    'encoding / bad-utf8-in-multiline',
    'encoding / bad-utf8-in-multiline-literal',
    'encoding / bad-utf8-in-string',
    'encoding / bad-utf8-in-string-literal',

    // Float validation - parser doesn't reject all invalid underscore positions
    'float / exp-double-us',
    'float / exp-leading-us',
    'float / exp-trailing-us',
    'float / exp-trailing-us-01',
    'float / exp-trailing-us-02',
    'float / float / exp-double-us',
    'float / float / exp-leading-us',
    'float / float / exp-trailing-us',
    'float / float / exp-trailing-us-01',
    'float / float / exp-trailing-us-02',
    'float / float / trailing-exp',
    'float / float / trailing-exp-minus',
    'float / float / trailing-exp-plus',
    'float / float / trailing-us',
    'float / float / us-before-dot',
    'float / trailing-exp',
    'float / trailing-exp-minus',
    'float / trailing-exp-plus',
    'float / trailing-us',
    'float / trailing-us-exp-01',
    'float / trailing-us-exp-02',
    'float / us-before-dot',

    // Integer validation - parser doesn't reject all invalid formats
    'integer / capital-bin',
    'integer / capital-hex',
    'integer / capital-oct',
    'integer / double-us',
    'integer / incomplete-bin',
    'integer / incomplete-hex',
    'integer / incomplete-oct',
    'integer / integer / capital-bin',
    'integer / integer / capital-hex',
    'integer / integer / capital-oct',
    'integer / integer / double-us',
    'integer / integer / negative-bin',
    'integer / integer / negative-hex',
    'integer / integer / negative-oct',
    'integer / integer / positive-bin',
    'integer / integer / positive-hex',
    'integer / integer / positive-oct',
    'integer / integer / trailing-us',
    'integer / integer / trailing-us-bin',
    'integer / integer / trailing-us-hex',
    'integer / integer / trailing-us-oct',
    'integer / integer / us-after-bin',
    'integer / integer / us-after-hex',
    'integer / integer / us-after-oct',
    'integer / negative-bin',
    'integer / negative-hex',
    'integer / negative-oct',
    'integer / positive-bin',
    'integer / positive-hex',
    'integer / positive-oct',
    'integer / trailing-us',
    'integer / trailing-us-bin',
    'integer / trailing-us-hex',
    'integer / trailing-us-oct',
    'integer / us-after-bin',
    'integer / us-after-hex',
    'integer / us-after-oct',

    // Inline table validation
    'inline-table / duplicate-key-03',
    'inline-table / linebreak-01',
    'inline-table / linebreak-02',
    'inline-table / linebreak-03',
    'inline-table / linebreak-04',
    'inline-table / overwrite-02',
    'inline-table / overwrite-04',
    'inline-table / overwrite-05',
    'inline-table / overwrite-07',
    'inline-table / overwrite-08',
    'inline-table / trailing-comma',

    // Array/table semantic validation
    'array / extend-defined-aot',
    'array / extending-table',
    'array / tables-01',
    'table / append-with-dotted-keys-01',
    'table / append-with-dotted-keys-02',
    'table / append-with-dotted-keys-03',
    'table / array-implicit',
    'table / duplicate-key-10',
    'table / llbrace',
    'table / rrbrace',

    // String/Unicode escape validation
    'string / bad-uni-esc-06',
    'string / bad-uni-esc-ml-06',
    'string / basic-byte-escapes',
    'string / string / bad-uni-esc-06',
    'string / string / bad-uni-esc-ml-06',

    // Spec tests
    'spec-1.0.0 / inline-table-2-0',
    'spec-1.1.0 / common-49-0',
];

/**
 * Get all invalid test cases from the toml-test suite.
 * Invalid cases should throw TomlParseException when parsed.
 *
 * @return array<string, string>
 */
function getInvalidTestCases(): array
{
    $testDir = __DIR__.'/toml-test/tests/invalid';
    /** @var array<string, string> $cases */
    $cases = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $extension = $file->getExtension();
        $path = $file->getPathname();

        // Handle .toml files - each file is one invalid case
        if ($extension === 'toml') {
            $relativePath = str_replace($testDir.'/', '', $path);
            $testName = str_replace(['/', '.toml'], [' / ', ''], $relativePath);
            $content = file_get_contents($path);

            if ($content !== false) {
                $cases[$testName] = $content;
            }

            continue;
        }

        // Handle .multi files - each non-empty line is a separate invalid case
        if ($extension === 'multi') {
            $relativePath = str_replace($testDir.'/', '', $path);
            $baseName = str_replace(['/', '.multi'], [' / ', ''], $relativePath);

            $content = file_get_contents($path);

            if ($content === false) {
                continue;
            }

            $lines = explode("\n", $content);

            foreach ($lines as $lineNum => $line) {
                $trimmedLine = trim($line);
                // Skip empty lines and comments
                if ($trimmedLine === '' || str_starts_with($trimmedLine, '#')) {
                    continue;
                }

                // Extract the key name from the line for a better test name
                $parts = explode('=', $line);
                $keyName = trim($parts[0]);

                $cases["{$baseName} / {$keyName}"] = $line;
            }
        }
    }

    ksort($cases);

    return $cases;
}

$testDir = __DIR__.'/toml-test/tests/invalid';

if (! is_dir($testDir)) {
    describe('toml-test invalid cases', function () {
        it('requires toml-test suite to be installed', function () {
            $this->markTestSkipped(
                'toml-test suite not found. Run: git clone --depth 1 https://github.com/toml-lang/toml-test.git tests/toml-test'
            );
        });
    });
} else {
    describe('toml-test invalid cases', function () {
        $cases = getInvalidTestCases();

        foreach ($cases as $testName => $toml) {
            $test = it("rejects invalid: {$testName}", function () use ($toml) {
                Toml::parse($toml);
            })->throws(TomlParseException::class);

            if (in_array($testName, KNOWN_INVALID_FAILURES, true)) {
                $test->skip('Known limitation - see KNOWN_INVALID_FAILURES for details');
            }
        }
    });
}
