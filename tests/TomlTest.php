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
