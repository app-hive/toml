<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Toml;

/**
 * Tests that are "invalid" according to TOML 1.0 but valid in TOML 1.1.0.
 *
 * This parser implements TOML 1.1.0, which introduced several new features that
 * make previously invalid syntax valid. These tests are skipped because they
 * test for rejection of syntax that is now allowed.
 *
 * TOML 1.1.0 features that cause "invalid" tests to pass:
 * - Optional seconds in times: `HH:MM` is valid (seconds are optional)
 * - Newlines in inline tables: Multi-line inline tables are allowed
 * - Trailing commas in inline tables: `{ a = 1, }` is valid
 * - Byte escapes in strings: `\xNN` escape sequences are supported
 */
const KNOWN_INVALID_FAILURES = [
    // TOML 1.1.0 allows optional seconds in time values (HH:MM format)
    'datetime / no-secs',
    'local-datetime / no-secs',
    'local-time / no-secs',

    // TOML 1.1.0 allows newlines within inline tables
    'inline-table / linebreak-01',
    'inline-table / linebreak-02',
    'inline-table / linebreak-03',
    'inline-table / linebreak-04',

    // TOML 1.1.0 allows trailing commas in inline tables
    'inline-table / trailing-comma',

    // TOML 1.1.0 allows \xNN byte escape sequences in basic strings
    'string / basic-byte-escapes',
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
        // Note: .multi files contain \xNN sequences that need to be converted to actual bytes
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

                // Convert \xNN sequences to actual bytes (per toml-test .multi format)
                $processedLine = preg_replace_callback(
                    '/\\\\x([0-9a-fA-F]{2})/',
                    fn ($matches) => chr((int) hexdec($matches[1])),
                    $line
                );

                $cases["{$baseName} / {$keyName}"] = $processedLine;
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
