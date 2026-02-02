<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Toml;

describe('Toml::parse()', function () {
    it('returns an empty array for empty string', function () {
        expect(Toml::parse(''))->toBe([]);
    });

    it('returns an empty array for whitespace-only string', function () {
        expect(Toml::parse('   '))->toBe([]);
        expect(Toml::parse("\n\t"))->toBe([]);
    });

    it('returns an associative array', function () {
        $result = Toml::parse('');
        expect($result)->toBeArray();
    });

    describe('integers', function () {
        it('parses simple decimal integers', function () {
            expect(Toml::parse('count = 99'))->toBe(['count' => 99]);
        });

        it('parses zero', function () {
            expect(Toml::parse('zero = 0'))->toBe(['zero' => 0]);
        });

        it('parses positive signed integers', function () {
            expect(Toml::parse('positive = +99'))->toBe(['positive' => 99]);
        });

        it('parses negative integers', function () {
            expect(Toml::parse('negative = -17'))->toBe(['negative' => -17]);
        });

        it('parses hexadecimal integers', function () {
            expect(Toml::parse('hex = 0xDEADBEEF'))->toBe(['hex' => 0xDEADBEEF]);
        });

        it('parses hexadecimal integers case insensitive', function () {
            expect(Toml::parse('hex = 0xdeadbeef'))->toBe(['hex' => 0xDEADBEEF]);
            expect(Toml::parse('hex = 0XDeAdBeEf'))->toBe(['hex' => 0xDEADBEEF]);
        });

        it('parses octal integers', function () {
            expect(Toml::parse('octal = 0o755'))->toBe(['octal' => 0o755]);
        });

        it('parses binary integers', function () {
            expect(Toml::parse('binary = 0b11010110'))->toBe(['binary' => 0b11010110]);
        });

        it('parses integers with underscores for readability', function () {
            expect(Toml::parse('million = 1_000_000'))->toBe(['million' => 1000000]);
        });

        it('parses hexadecimal with underscores', function () {
            expect(Toml::parse('hex = 0xDEAD_BEEF'))->toBe(['hex' => 0xDEADBEEF]);
        });

        it('parses octal with underscores', function () {
            expect(Toml::parse('octal = 0o7_5_5'))->toBe(['octal' => 0o755]);
        });

        it('parses binary with underscores', function () {
            expect(Toml::parse('binary = 0b1101_0110'))->toBe(['binary' => 0b11010110]);
        });

        it('rejects leading zeros in decimal integers', function () {
            Toml::parse('invalid = 007');
        })->throws(TomlParseException::class, 'Leading zeros are not allowed');

        it('rejects leading zeros with sign', function () {
            Toml::parse('invalid = +007');
        })->throws(TomlParseException::class, 'Leading zeros are not allowed');

        it('allows 0 by itself', function () {
            expect(Toml::parse('zero = 0'))->toBe(['zero' => 0]);
        });

        it('returns string for values exceeding PHP_INT_MAX', function () {
            // PHP_INT_MAX is 9223372036854775807 on 64-bit systems
            $result = Toml::parse('big = 9223372036854775808');
            expect($result['big'])->toBeString();
            expect($result['big'])->toBe('9223372036854775808');
        });

        it('returns string for large negative values', function () {
            // PHP_INT_MIN is -9223372036854775808 on 64-bit systems
            $result = Toml::parse('big = -9223372036854775809');
            expect($result['big'])->toBeString();
            expect($result['big'])->toBe('-9223372036854775809');
        });

        it('returns PHP integer for values within range', function () {
            $result = Toml::parse('num = 9223372036854775807');
            expect($result['num'])->toBeInt();
            expect($result['num'])->toBe(PHP_INT_MAX);
        });

        it('parses multiple integer key-value pairs', function () {
            $toml = <<<'TOML'
a = 1
b = 2
c = 3
TOML;
            expect(Toml::parse($toml))->toBe([
                'a' => 1,
                'b' => 2,
                'c' => 3,
            ]);
        });

        it('parses integers in real-world TOML example', function () {
            $toml = <<<'TOML'
port = 8080
max_connections = 1_000
timeout_ms = 5000
flags = 0b1111
permissions = 0o644
color = 0xFF00FF
TOML;
            expect(Toml::parse($toml))->toBe([
                'port' => 8080,
                'max_connections' => 1000,
                'timeout_ms' => 5000,
                'flags' => 0b1111,
                'permissions' => 0o644,
                'color' => 0xFF00FF,
            ]);
        });
    });

    describe('booleans', function () {
        it('parses true as PHP true', function () {
            expect(Toml::parse('enabled = true'))->toBe(['enabled' => true]);
        });

        it('parses false as PHP false', function () {
            expect(Toml::parse('disabled = false'))->toBe(['disabled' => false]);
        });

        it('is case sensitive - rejects capitalized True', function () {
            Toml::parse('invalid = True');
        })->throws(TomlParseException::class);

        it('is case sensitive - rejects uppercase TRUE', function () {
            Toml::parse('invalid = TRUE');
        })->throws(TomlParseException::class);

        it('is case sensitive - rejects capitalized False', function () {
            Toml::parse('invalid = False');
        })->throws(TomlParseException::class);

        it('is case sensitive - rejects uppercase FALSE', function () {
            Toml::parse('invalid = FALSE');
        })->throws(TomlParseException::class);

        it('parses multiple boolean key-value pairs', function () {
            $toml = <<<'TOML'
a = true
b = false
c = true
TOML;
            expect(Toml::parse($toml))->toBe([
                'a' => true,
                'b' => false,
                'c' => true,
            ]);
        });

        it('parses booleans in real-world TOML example', function () {
            $toml = <<<'TOML'
debug = false
verbose = true
enable_logging = true
dry_run = false
TOML;
            expect(Toml::parse($toml))->toBe([
                'debug' => false,
                'verbose' => true,
                'enable_logging' => true,
                'dry_run' => false,
            ]);
        });
    });

    describe('floats', function () {
        it('parses simple decimal floats', function () {
            expect(Toml::parse('pi = 3.14'))->toBe(['pi' => 3.14]);
        });

        it('parses positive signed floats', function () {
            expect(Toml::parse('positive = +1.0'))->toBe(['positive' => 1.0]);
        });

        it('parses negative floats', function () {
            expect(Toml::parse('negative = -0.01'))->toBe(['negative' => -0.01]);
        });

        it('parses zero point zero', function () {
            expect(Toml::parse('zero = 0.0'))->toBe(['zero' => 0.0]);
        });

        it('parses floats with exponent', function () {
            expect(Toml::parse('big = 5e+22'))->toBe(['big' => 5e+22]);
        });

        it('parses floats with exponent without sign', function () {
            expect(Toml::parse('num = 1e06'))->toBe(['num' => 1e06]);
        });

        it('parses negative floats with negative exponent', function () {
            expect(Toml::parse('small = -2E-2'))->toBe(['small' => -2E-2]);
        });

        it('parses floats with both decimal and exponent', function () {
            expect(Toml::parse('planck = 6.626e-34'))->toBe(['planck' => 6.626e-34]);
        });

        it('parses floats with underscores', function () {
            expect(Toml::parse('big = 9_224_617.445_991_228_313'))->toBe(['big' => 9224617.445991228313]);
        });

        it('parses positive infinity', function () {
            $result = Toml::parse('value = inf');
            expect($result['value'])->toBe(INF);
        });

        it('parses explicit positive infinity', function () {
            $result = Toml::parse('value = +inf');
            expect($result['value'])->toBe(INF);
        });

        it('parses negative infinity', function () {
            $result = Toml::parse('value = -inf');
            expect($result['value'])->toBe(-INF);
        });

        it('parses nan', function () {
            $result = Toml::parse('value = nan');
            expect(is_nan($result['value']))->toBeTrue();
        });

        it('parses positive nan', function () {
            $result = Toml::parse('value = +nan');
            expect(is_nan($result['value']))->toBeTrue();
        });

        it('parses negative nan', function () {
            $result = Toml::parse('value = -nan');
            expect(is_nan($result['value']))->toBeTrue();
        });

        it('rejects leading zeros in floats', function () {
            Toml::parse('invalid = 03.14');
        })->throws(TomlParseException::class, 'Leading zeros are not allowed');

        it('rejects leading zeros with sign in floats', function () {
            Toml::parse('invalid = +03.14');
        })->throws(TomlParseException::class, 'Leading zeros are not allowed');

        it('allows 0.x format', function () {
            expect(Toml::parse('zero = 0.5'))->toBe(['zero' => 0.5]);
        });

        it('parses multiple float key-value pairs', function () {
            $toml = <<<'TOML'
a = 1.0
b = 2.5
c = -3.14
TOML;
            expect(Toml::parse($toml))->toBe([
                'a' => 1.0,
                'b' => 2.5,
                'c' => -3.14,
            ]);
        });

        it('parses floats in real-world TOML example', function () {
            $toml = <<<'TOML'
pi = 3.14159
gravity = 9.8
avogadro = 6.022e23
electron_mass = 9.109e-31
threshold = +1.0
offset = -0.5
max = inf
invalid_reading = nan
TOML;
            $result = Toml::parse($toml);
            expect($result['pi'])->toBe(3.14159);
            expect($result['gravity'])->toBe(9.8);
            expect($result['avogadro'])->toBe(6.022e23);
            expect($result['electron_mass'])->toBe(9.109e-31);
            expect($result['threshold'])->toBe(1.0);
            expect($result['offset'])->toBe(-0.5);
            expect($result['max'])->toBe(INF);
            expect(is_nan($result['invalid_reading']))->toBeTrue();
        });
    });

    describe('quoted keys', function () {
        it('parses basic quoted keys with special characters', function () {
            expect(Toml::parse('"127.0.0.1" = "value"'))->toBe(['127.0.0.1' => 'value']);
        });

        it('parses basic quoted keys with dots', function () {
            expect(Toml::parse('"site.name" = "My Site"'))->toBe(['site.name' => 'My Site']);
        });

        it('parses literal quoted keys with spaces', function () {
            expect(Toml::parse("'key with spaces' = \"value\""))->toBe(['key with spaces' => 'value']);
        });

        it('parses literal quoted keys with special characters', function () {
            expect(Toml::parse("'key:with:colons' = \"value\""))->toBe(['key:with:colons' => 'value']);
        });

        it('parses empty basic quoted keys', function () {
            expect(Toml::parse('"" = "blank"'))->toBe(['' => 'blank']);
        });

        it('parses empty literal quoted keys', function () {
            expect(Toml::parse("'' = \"blank\""))->toBe(['' => 'blank']);
        });

        it('parses quoted keys with escape sequences in basic strings', function () {
            expect(Toml::parse('"key\\nwith\\nnewlines" = "value"'))->toBe(["key\nwith\nnewlines" => 'value']);
        });

        it('parses quoted keys with unicode escape sequences', function () {
            expect(Toml::parse('"key\\u0041" = "value"'))->toBe(['keyA' => 'value']);
        });

        it('preserves backslashes in literal string keys', function () {
            // Literal strings preserve backslashes as-is (no escape processing)
            expect(Toml::parse("'C:\\path\\to\\file' = \"value\""))->toBe(['C:\path\to\file' => 'value']);
        });

        it('parses multiple key-value pairs with quoted keys', function () {
            $toml = <<<'TOML'
"127.0.0.1" = "localhost"
'key with spaces' = "spaced"
"" = "empty"
TOML;
            expect(Toml::parse($toml))->toBe([
                '127.0.0.1' => 'localhost',
                'key with spaces' => 'spaced',
                '' => 'empty',
            ]);
        });

        it('parses quoted keys in real-world TOML example', function () {
            $toml = <<<'TOML'
"ʎǝʞ" = "unicode key"
'key\nwith\nliteral\nbackslash\nn' = "literal"
"quoted \"key\" with quotes" = "meta"
TOML;
            expect(Toml::parse($toml))->toBe([
                'ʎǝʞ' => 'unicode key',
                'key\nwith\nliteral\nbackslash\nn' => 'literal',
                'quoted "key" with quotes' => 'meta',
            ]);
        });
    });
});

describe('Toml::parseFile()', function () {
    it('throws TomlParseException when file does not exist', function () {
        Toml::parseFile('/nonexistent/path/to/file.toml');
    })->throws(TomlParseException::class, 'File does not exist');

    it('parses an existing file and returns an array', function () {
        $tempFile = sys_get_temp_dir().'/test.toml';
        file_put_contents($tempFile, '');

        try {
            $result = Toml::parseFile($tempFile);
            expect($result)->toBeArray();
        } finally {
            unlink($tempFile);
        }
    });

    it('throws TomlParseException when file is not readable', function () {
        $tempFile = sys_get_temp_dir().'/unreadable.toml';
        file_put_contents($tempFile, '');
        chmod($tempFile, 0000);

        try {
            Toml::parseFile($tempFile);
        } finally {
            chmod($tempFile, 0644);
            unlink($tempFile);
        }
    })->throws(TomlParseException::class, 'File is not readable');
});

describe('TomlParseException', function () {
    it('provides line number via getErrorLine()', function () {
        $exception = new TomlParseException('Unexpected token', 5, 10, '');

        expect($exception->getErrorLine())->toBe(5);
    });

    it('provides column number via getErrorColumn()', function () {
        $exception = new TomlParseException('Unexpected token', 5, 10, '');

        expect($exception->getErrorColumn())->toBe(10);
    });

    it('includes line and column in error message', function () {
        $exception = new TomlParseException('Unexpected token', 5, 10, '');

        expect($exception->getMessage())->toBe('Unexpected token at line 5, column 10');
    });

    it('returns simple message when no line/column provided', function () {
        $exception = new TomlParseException('File does not exist');

        expect($exception->getMessage())->toBe('File does not exist');
        expect($exception->getErrorLine())->toBe(0);
        expect($exception->getErrorColumn())->toBe(0);
    });

    it('provides snippet showing context around the error', function () {
        $source = <<<'TOML'
[database]
host = "localhost"
port = invalid
user = "admin"
TOML;

        $exception = new TomlParseException('Invalid value', 3, 8, $source);
        $snippet = $exception->getSnippet();

        expect($snippet)->toContain('> 3 | port = invalid');
        expect($snippet)->toContain('^');
    });

    it('returns empty snippet when no source provided', function () {
        $exception = new TomlParseException('Invalid value', 3, 8, '');

        expect($exception->getSnippet())->toBe('');
    });

    it('returns empty snippet when line is 0', function () {
        $exception = new TomlParseException('Error', 0, 0, 'some content');

        expect($exception->getSnippet())->toBe('');
    });

    it('shows context lines before and after the error line', function () {
        $source = <<<'TOML'
line1
line2
line3
line4
line5
line6
line7
TOML;

        $exception = new TomlParseException('Error', 4, 1, $source);
        $snippet = $exception->getSnippet();

        expect($snippet)->toContain('2 | line2');
        expect($snippet)->toContain('3 | line3');
        expect($snippet)->toContain('> 4 | line4');
        expect($snippet)->toContain('5 | line5');
        expect($snippet)->toContain('6 | line6');
    });

    it('handles error on first line gracefully', function () {
        $source = <<<'TOML'
invalid
line2
line3
TOML;

        $exception = new TomlParseException('Error', 1, 1, $source);
        $snippet = $exception->getSnippet();

        expect($snippet)->toContain('> 1 | invalid');
        expect($snippet)->toContain('2 | line2');
        expect($snippet)->toContain('3 | line3');
    });

    it('handles error on last line gracefully', function () {
        $source = <<<'TOML'
line1
line2
invalid
TOML;

        $exception = new TomlParseException('Error', 3, 1, $source);
        $snippet = $exception->getSnippet();

        expect($snippet)->toContain('1 | line1');
        expect($snippet)->toContain('2 | line2');
        expect($snippet)->toContain('> 3 | invalid');
    });

    it('returns empty snippet when line exceeds source lines', function () {
        $source = "line1\nline2";

        $exception = new TomlParseException('Error', 10, 1, $source);

        expect($exception->getSnippet())->toBe('');
    });

    it('positions caret at correct column', function () {
        $source = 'key = "value';

        $exception = new TomlParseException('Unterminated string', 1, 12, $source);
        $snippet = $exception->getSnippet();

        expect($snippet)->toContain('> 1 | key = "value');
        $lines = explode("\n", $snippet);
        $caretLine = $lines[1] ?? '';
        expect($caretLine)->toContain('^');
        $caretPosition = strpos($caretLine, '^');
        expect($caretPosition)->toBe(17); // "> 1 | " prefix (6 chars) + 11 chars to column 12
    });
});
