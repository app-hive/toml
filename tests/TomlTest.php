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

    describe('key-value pairs', function () {
        it('parses basic key = value format', function () {
            expect(Toml::parse('key = "value"'))->toBe(['key' => 'value']);
        });

        it('parses key-value with integer value', function () {
            expect(Toml::parse('count = 42'))->toBe(['count' => 42]);
        });

        it('parses key-value with float value', function () {
            expect(Toml::parse('ratio = 3.14'))->toBe(['ratio' => 3.14]);
        });

        it('parses key-value with boolean value', function () {
            expect(Toml::parse('enabled = true'))->toBe(['enabled' => true]);
            expect(Toml::parse('disabled = false'))->toBe(['disabled' => false]);
        });

        it('parses key-value with date-time value', function () {
            expect(Toml::parse('created = 1979-05-27T07:32:00Z'))->toBe(['created' => '1979-05-27T07:32:00Z']);
        });

        it('allows whitespace before equals sign', function () {
            expect(Toml::parse('key   = "value"'))->toBe(['key' => 'value']);
        });

        it('allows whitespace after equals sign', function () {
            expect(Toml::parse('key =   "value"'))->toBe(['key' => 'value']);
        });

        it('allows whitespace around equals sign', function () {
            expect(Toml::parse('key   =   "value"'))->toBe(['key' => 'value']);
        });

        it('allows tabs as whitespace around equals sign', function () {
            expect(Toml::parse("key\t=\t\"value\""))->toBe(['key' => 'value']);
        });

        it('allows mixed spaces and tabs around equals sign', function () {
            expect(Toml::parse("key \t = \t \"value\""))->toBe(['key' => 'value']);
        });

        it('allows no whitespace around equals sign', function () {
            expect(Toml::parse('key="value"'))->toBe(['key' => 'value']);
        });

        it('rejects duplicate keys with clear error', function () {
            $toml = <<<'TOML'
key = "first"
key = "second"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, "Cannot redefine key 'key'");

        it('rejects duplicate keys even with different value types', function () {
            $toml = <<<'TOML'
key = 42
key = "string"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, "Cannot redefine key 'key'");

        it('rejects duplicate keys in nested dotted structure', function () {
            $toml = <<<'TOML'
a.b = 1
a.b = 2
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, "Cannot redefine key 'b'");

        it('handles all value types in key-value pairs', function () {
            $toml = <<<'TOML'
string = "hello"
integer = 42
float = 3.14
boolean = true
date = 1979-05-27
time = 07:32:00
datetime = 1979-05-27T07:32:00Z
TOML;
            expect(Toml::parse($toml))->toBe([
                'string' => 'hello',
                'integer' => 42,
                'float' => 3.14,
                'boolean' => true,
                'date' => '1979-05-27',
                'time' => '07:32:00',
                'datetime' => '1979-05-27T07:32:00Z',
            ]);
        });

        it('parses multiple key-value pairs', function () {
            $toml = <<<'TOML'
first = "one"
second = "two"
third = "three"
TOML;
            expect(Toml::parse($toml))->toBe([
                'first' => 'one',
                'second' => 'two',
                'third' => 'three',
            ]);
        });

        it('handles key-value pairs with comments', function () {
            $toml = <<<'TOML'
# This is a comment
key = "value" # inline comment
# Another comment
other = 42
TOML;
            expect(Toml::parse($toml))->toBe([
                'key' => 'value',
                'other' => 42,
            ]);
        });

        it('handles key-value pairs with blank lines between them', function () {
            $toml = <<<'TOML'
first = 1

second = 2


third = 3
TOML;
            expect(Toml::parse($toml))->toBe([
                'first' => 1,
                'second' => 2,
                'third' => 3,
            ]);
        });

        it('rejects key-value pairs without equals sign', function () {
            Toml::parse('key "value"');
        })->throws(TomlParseException::class);

        it('rejects key-value pairs without value', function () {
            Toml::parse('key =');
        })->throws(TomlParseException::class);

        it('rejects multiple values on same line', function () {
            Toml::parse('key = "value" extra');
        })->throws(TomlParseException::class);

        it('parses key-value pairs in real-world config example', function () {
            $toml = <<<'TOML'
# Application configuration
name = "MyApp"
version = "1.0.0"
debug = false
port = 8080
timeout = 30.5
created = 2024-01-15T10:30:00Z
TOML;
            expect(Toml::parse($toml))->toBe([
                'name' => 'MyApp',
                'version' => '1.0.0',
                'debug' => false,
                'port' => 8080,
                'timeout' => 30.5,
                'created' => '2024-01-15T10:30:00Z',
            ]);
        });
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

        it('parses hexadecimal integers with case insensitive digits', function () {
            // Hex digits are case insensitive, but the prefix must be lowercase (0x)
            expect(Toml::parse('hex = 0xdeadbeef'))->toBe(['hex' => 0xDEADBEEF]);
            expect(Toml::parse('hex = 0xDeAdBeEf'))->toBe(['hex' => 0xDEADBEEF]);
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

        it('parses floats with underscore in significand and exponent', function () {
            // Underscore in significand
            expect(Toml::parse('a = 1_000e2'))->toBe(['a' => 100000.0]);
            // Underscore in significand with decimal
            expect(Toml::parse('b = 1_000.5e2'))->toBe(['b' => 100050.0]);
            // Underscore in exponent
            expect(Toml::parse('c = 1e1_0'))->toBe(['c' => 1e10]);
            // Underscore in both significand and exponent
            expect(Toml::parse('d = 1_000.5_0e1_0'))->toBe(['d' => 1000.50e10]);
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

    describe('dotted keys', function () {
        it('parses simple dotted keys', function () {
            expect(Toml::parse('physical.color = "orange"'))->toBe([
                'physical' => ['color' => 'orange'],
            ]);
        });

        it('parses dotted keys with multiple levels', function () {
            expect(Toml::parse('physical.shape.type = "sphere"'))->toBe([
                'physical' => ['shape' => ['type' => 'sphere']],
            ]);
        });

        it('allows whitespace around dots', function () {
            expect(Toml::parse('physical . color = "orange"'))->toBe([
                'physical' => ['color' => 'orange'],
            ]);
        });

        it('allows whitespace around dots with multiple levels', function () {
            expect(Toml::parse('a . b . c = "value"'))->toBe([
                'a' => ['b' => ['c' => 'value']],
            ]);
        });

        it('mixes bare and quoted keys', function () {
            expect(Toml::parse('site."google.com" = true'))->toBe([
                'site' => ['google.com' => true],
            ]);
        });

        it('mixes bare and literal quoted keys', function () {
            expect(Toml::parse("site.'google.com' = true"))->toBe([
                'site' => ['google.com' => true],
            ]);
        });

        it('parses all quoted dotted keys', function () {
            expect(Toml::parse('"first"."second" = "value"'))->toBe([
                'first' => ['second' => 'value'],
            ]);
        });

        it('parses dotted keys with empty quoted key parts', function () {
            expect(Toml::parse('"".name = "blank"'))->toBe([
                '' => ['name' => 'blank'],
            ]);
        });

        it('creates nested structure from multiple dotted key assignments', function () {
            $toml = <<<'TOML'
fruit.apple.color = "red"
fruit.apple.taste = "sweet"
fruit.orange.color = "orange"
TOML;
            expect(Toml::parse($toml))->toBe([
                'fruit' => [
                    'apple' => [
                        'color' => 'red',
                        'taste' => 'sweet',
                    ],
                    'orange' => [
                        'color' => 'orange',
                    ],
                ],
            ]);
        });

        it('merges dotted keys with regular keys', function () {
            $toml = <<<'TOML'
name = "Apple"
physical.color = "red"
physical.shape = "round"
TOML;
            expect(Toml::parse($toml))->toBe([
                'name' => 'Apple',
                'physical' => [
                    'color' => 'red',
                    'shape' => 'round',
                ],
            ]);
        });

        it('parses deeply nested dotted keys', function () {
            expect(Toml::parse('a.b.c.d.e = 5'))->toBe([
                'a' => ['b' => ['c' => ['d' => ['e' => 5]]]],
            ]);
        });

        it('rejects redefining an existing key', function () {
            $toml = <<<'TOML'
fruit.apple = 1
fruit.apple = 2
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class);

        it('rejects defining a key that was already used as a table', function () {
            $toml = <<<'TOML'
fruit.apple.color = "red"
fruit.apple = "bad"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class);

        it('rejects defining a dotted key under a non-table', function () {
            $toml = <<<'TOML'
fruit = "apple"
fruit.color = "red"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class);

        it('parses dotted keys with integer values', function () {
            expect(Toml::parse('data.count = 42'))->toBe([
                'data' => ['count' => 42],
            ]);
        });

        it('parses dotted keys with float values', function () {
            expect(Toml::parse('data.ratio = 3.14'))->toBe([
                'data' => ['ratio' => 3.14],
            ]);
        });

        it('parses dotted keys with quoted keys containing special characters', function () {
            expect(Toml::parse('"127.0.0.1".port = 8080'))->toBe([
                '127.0.0.1' => ['port' => 8080],
            ]);
        });
    });

    describe('numeric keys', function () {
        it('parses integer bare keys', function () {
            expect(Toml::parse('1 = true'))->toBe(['1' => true]);
        });

        it('parses integer bare keys as string keys', function () {
            expect(Toml::parse('1234 = "value"'))->toBe(['1234' => 'value']);
        });

        it('parses numeric dotted keys as nested structure', function () {
            // 1.2 in key position is a dotted key, not a float
            expect(Toml::parse('1.2 = true'))->toBe([
                '1' => ['2' => true],
            ]);
        });

        it('parses bare keys with leading zeros', function () {
            // Leading zeros are valid in bare keys (treated as string, not integer)
            expect(Toml::parse('0123 = true'))->toBe(['0123' => true]);
        });

        it('parses numeric dotted keys with leading zeros', function () {
            expect(Toml::parse('01.23 = true'))->toBe([
                '01' => ['23' => true],
            ]);
        });

        it('parses negative numeric bare keys', function () {
            expect(Toml::parse('-1 = true'))->toBe(['-1' => true]);
        });

        it('parses negative numeric bare keys with leading zeros', function () {
            expect(Toml::parse('-01 = true'))->toBe(['-01' => true]);
        });

        it('distinguishes 1 and 01 as different keys', function () {
            $toml = <<<'TOML'
1  = 'one'
01 = 'zero one'
TOML;
            expect(Toml::parse($toml))->toBe([
                '1' => 'one',
                '01' => 'zero one',
            ]);
        });

        it('parses numeric keys in table headers', function () {
            $toml = <<<'TOML'
[1]
name = "first"
TOML;
            expect(Toml::parse($toml))->toBe([
                '1' => ['name' => 'first'],
            ]);
        });

        it('parses numeric dotted keys in table headers', function () {
            $toml = <<<'TOML'
[1.2]
value = true
TOML;
            expect(Toml::parse($toml))->toBe([
                '1' => ['2' => ['value' => true]],
            ]);
        });
    });

    describe('multi-line basic strings', function () {
        it('parses simple multi-line basic string with triple double quotes', function () {
            $toml = 'str = """hello world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('trims newline immediately following opening delimiter', function () {
            $toml = <<<'TOML'
str = """
hello world"""
TOML;
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('preserves content when no newline after opening delimiter', function () {
            $toml = 'str = """hello world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('preserves newlines within the string content', function () {
            $toml = <<<'TOML'
str = """
line 1
line 2
line 3"""
TOML;
            expect(Toml::parse($toml))->toBe(['str' => "line 1\nline 2\nline 3"]);
        });

        it('handles line-ending backslash to trim whitespace and newlines', function () {
            $toml = <<<'TOML'
str = """
The quick brown \
    fox jumps over \
    the lazy dog."""
TOML;
            expect(Toml::parse($toml))->toBe(['str' => 'The quick brown fox jumps over the lazy dog.']);
        });

        it('handles line-ending backslash at start of content', function () {
            $toml = "str = \"\"\"\\\n  hello\"\"\"";
            expect(Toml::parse($toml))->toBe(['str' => 'hello']);
        });

        it('processes escape sequences same as basic strings - newline', function () {
            $toml = 'str = """hello\\nworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\nworld"]);
        });

        it('processes escape sequences same as basic strings - tab', function () {
            $toml = 'str = """hello\\tworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\tworld"]);
        });

        it('processes escape sequences same as basic strings - carriage return', function () {
            $toml = 'str = """hello\\rworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\rworld"]);
        });

        it('processes escape sequences same as basic strings - backslash', function () {
            $toml = 'str = """hello\\\\world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\world']);
        });

        it('processes escape sequences same as basic strings - double quote', function () {
            $toml = 'str = """hello \\"world\\"!"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello "world"!']);
        });

        it('processes unicode escape sequences - short form', function () {
            $toml = 'str = """hello \\u0041 world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello A world']);
        });

        it('processes unicode escape sequences - long form', function () {
            $toml = 'str = """hello \\U0001F600 world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello 😀 world']);
        });

        it('processes backspace escape sequence', function () {
            $toml = 'str = """hello\\bworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\x08world"]);
        });

        it('processes form feed escape sequence', function () {
            $toml = 'str = """hello\\fworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\x0Cworld"]);
        });

        it('allows up to two quotes at the end before closing delimiter', function () {
            $toml = 'str = """hello"""""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello""']);
        });

        it('allows one quote at the end before closing delimiter', function () {
            $toml = 'str = """hello""""';
            expect(Toml::parse($toml))->toBe(['str' => 'hello"']);
        });

        it('allows quotes within the string content', function () {
            $toml = 'str = """Here are two quotes: "". Simple enough."""';
            expect(Toml::parse($toml))->toBe(['str' => 'Here are two quotes: "". Simple enough.']);
        });

        it('trims CRLF immediately following opening delimiter', function () {
            $toml = "str = \"\"\"\r\nhello world\"\"\"";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('normalizes CRLF to LF within content', function () {
            $toml = "str = \"\"\"\r\nline 1\r\nline 2\"\"\"";
            expect(Toml::parse($toml))->toBe(['str' => "line 1\nline 2"]);
        });

        it('handles empty multi-line basic string', function () {
            $toml = 'str = """"""';
            expect(Toml::parse($toml))->toBe(['str' => '']);
        });

        it('handles multi-line string with only whitespace', function () {
            $toml = 'str = """   """';
            expect(Toml::parse($toml))->toBe(['str' => '   ']);
        });

        it('handles line-ending backslash followed by multiple newlines', function () {
            $toml = "str = \"\"\"hello \\\n\n\n  world\"\"\"";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('handles line-ending backslash with tabs and spaces', function () {
            $toml = "str = \"\"\"hello \\\n\t  \t  world\"\"\"";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('parses multi-line basic strings in real-world TOML example', function () {
            $toml = <<<'TOML'
description = """
This is a multi-line description
that spans several lines.
It preserves the line breaks."""

sql = """
SELECT *
FROM users
WHERE active = true"""

trimmed = """
The quick brown \
    fox jumps over \
    the lazy dog."""
TOML;
            $result = Toml::parse($toml);
            expect($result['description'])->toBe("This is a multi-line description\nthat spans several lines.\nIt preserves the line breaks.");
            expect($result['sql'])->toBe("SELECT *\nFROM users\nWHERE active = true");
            expect($result['trimmed'])->toBe('The quick brown fox jumps over the lazy dog.');
        });

        it('handles escape sequence (e) for escape character', function () {
            $toml = 'str = """hello\\eworld"""';
            expect(Toml::parse($toml))->toBe(['str' => "hello\x1Bworld"]);
        });

        it('handles hex escape sequence (x)', function () {
            $toml = 'str = """hello\\x41world"""';
            expect(Toml::parse($toml))->toBe(['str' => 'helloAworld']);
        });
    });

    describe('multi-line literal strings', function () {
        it('parses simple multi-line literal string with triple single quotes', function () {
            $toml = "str = '''hello world'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('trims newline immediately following opening delimiter', function () {
            $toml = <<<'TOML'
str = '''
hello world'''
TOML;
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('preserves content when no newline after opening delimiter', function () {
            $toml = "str = '''hello world'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('preserves newlines within the string content', function () {
            $toml = <<<'TOML'
str = '''
line 1
line 2
line 3'''
TOML;
            expect(Toml::parse($toml))->toBe(['str' => "line 1\nline 2\nline 3"]);
        });

        it('does NOT process escape sequences - backslash n remains literal', function () {
            $toml = "str = '''hello\\nworld'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\nworld']);
        });

        it('does NOT process escape sequences - backslash t remains literal', function () {
            $toml = "str = '''hello\\tworld'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\tworld']);
        });

        it('does NOT process escape sequences - backslash r remains literal', function () {
            $toml = "str = '''hello\\rworld'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\rworld']);
        });

        it('does NOT process escape sequences - double backslash remains literal', function () {
            $toml = "str = '''hello\\\\world'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\\\world']);
        });

        it('does NOT process unicode escape sequences', function () {
            $toml = "str = '''hello\\u0041world'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello\\u0041world']);
        });

        it('preserves Windows-style paths without escape processing', function () {
            $toml = "str = '''C:\\Users\\admin\\Documents'''";
            expect(Toml::parse($toml))->toBe(['str' => 'C:\\Users\\admin\\Documents']);
        });

        it('preserves regex patterns without escape processing', function () {
            $toml = "str = '''<\\i\\c*\\s*>'''";
            expect(Toml::parse($toml))->toBe(['str' => '<\\i\\c*\\s*>']);
        });

        it('allows single quotes within the content', function () {
            $toml = "str = '''I'm a string with 'quotes' inside'''";
            expect(Toml::parse($toml))->toBe(['str' => "I'm a string with 'quotes' inside"]);
        });

        it('allows two consecutive single quotes within the content', function () {
            $toml = "str = '''Here are two quotes: ''. Simple.'''";
            expect(Toml::parse($toml))->toBe(['str' => "Here are two quotes: ''. Simple."]);
        });

        it('allows up to two quotes at the end before closing delimiter', function () {
            $toml = "str = '''hello'''''";
            expect(Toml::parse($toml))->toBe(['str' => "hello''"]);
        });

        it('allows one quote at the end before closing delimiter', function () {
            $toml = "str = '''hello''''";
            expect(Toml::parse($toml))->toBe(['str' => "hello'"]);
        });

        it('trims CRLF immediately following opening delimiter', function () {
            $toml = "str = '''\r\nhello world'''";
            expect(Toml::parse($toml))->toBe(['str' => 'hello world']);
        });

        it('normalizes CRLF to LF within content', function () {
            $toml = "str = '''\r\nline 1\r\nline 2'''";
            expect(Toml::parse($toml))->toBe(['str' => "line 1\nline 2"]);
        });

        it('handles empty multi-line literal string', function () {
            $toml = "str = ''''''";
            expect(Toml::parse($toml))->toBe(['str' => '']);
        });

        it('handles multi-line string with only whitespace', function () {
            $toml = "str = '''   '''";
            expect(Toml::parse($toml))->toBe(['str' => '   ']);
        });

        it('does NOT support line-ending backslash (preserved literally)', function () {
            // Unlike basic strings, literal strings don't process line-ending backslash
            $toml = "str = '''hello \\\nworld'''";
            expect(Toml::parse($toml))->toBe(['str' => "hello \\\nworld"]);
        });

        it('parses multi-line literal strings in real-world TOML example', function () {
            $toml = <<<'TOML'
regex = '''I [dw]on't need \d{2} apples'''

winpath = '''C:\Users\nodejs\templates'''

winpath2 = '''\\ServerX\admin$\system32\'''

quoted = '''Tom "Dubs" Preston-Werner'''
TOML;
            $result = Toml::parse($toml);
            expect($result['regex'])->toBe('I [dw]on\'t need \\d{2} apples');
            expect($result['winpath'])->toBe('C:\\Users\\nodejs\\templates');
            expect($result['winpath2'])->toBe('\\\\ServerX\\admin$\\system32\\');
            expect($result['quoted'])->toBe('Tom "Dubs" Preston-Werner');
        });

        it('handles multi-line literal string with embedded double quotes', function () {
            $toml = "str = '''He said \"Hello World\"'''";
            expect(Toml::parse($toml))->toBe(['str' => 'He said "Hello World"']);
        });

        it('handles multi-line content across many lines', function () {
            $toml = <<<'TOML'
str = '''
The first newline is
trimmed in raw strings.
   All other whitespace
   is preserved.
'''
TOML;
            expect(Toml::parse($toml))->toBe(['str' => "The first newline is\ntrimmed in raw strings.\n   All other whitespace\n   is preserved.\n"]);
        });
    });

    describe('offset date-times', function () {
        it('parses RFC 3339 format with Z suffix', function () {
            expect(Toml::parse('odt1 = 1979-05-27T07:32:00Z'))->toBe(['odt1' => '1979-05-27T07:32:00Z']);
        });

        it('parses with positive offset', function () {
            expect(Toml::parse('odt2 = 1979-05-27T07:32:00-07:00'))->toBe(['odt2' => '1979-05-27T07:32:00-07:00']);
        });

        it('parses with negative offset', function () {
            expect(Toml::parse('odt3 = 1979-05-27T07:32:00+09:00'))->toBe(['odt3' => '1979-05-27T07:32:00+09:00']);
        });

        it('normalizes space separator to T', function () {
            expect(Toml::parse('odt4 = 1979-05-27 07:32:00Z'))->toBe(['odt4' => '1979-05-27T07:32:00Z']);
        });

        it('supports fractional seconds', function () {
            expect(Toml::parse('odt5 = 1979-05-27T00:32:00.999999Z'))->toBe(['odt5' => '1979-05-27T00:32:00.999999Z']);
        });

        it('supports fractional seconds with offset', function () {
            expect(Toml::parse('odt6 = 1979-05-27T00:32:00.999999-07:00'))->toBe(['odt6' => '1979-05-27T00:32:00.999999-07:00']);
        });

        it('normalizes lowercase z suffix to uppercase Z', function () {
            expect(Toml::parse('odt7 = 1979-05-27T07:32:00z'))->toBe(['odt7' => '1979-05-27T07:32:00Z']);
        });

        it('normalizes lowercase t separator to uppercase T', function () {
            expect(Toml::parse('odt8 = 1979-05-27t07:32:00Z'))->toBe(['odt8' => '1979-05-27T07:32:00Z']);
        });

        it('parses multiple offset date-time key-value pairs', function () {
            $toml = <<<'TOML'
created = 1979-05-27T07:32:00Z
updated = 2024-01-15T14:30:00-05:00
expires = 2025-12-31T23:59:59.999999+00:00
TOML;
            expect(Toml::parse($toml))->toBe([
                'created' => '1979-05-27T07:32:00Z',
                'updated' => '2024-01-15T14:30:00-05:00',
                'expires' => '2025-12-31T23:59:59.999999+00:00',
            ]);
        });

        it('parses offset date-times in real-world TOML example', function () {
            $toml = <<<'TOML'
start_time = 2023-06-15T09:00:00-04:00
end_time = 2023-06-15T17:00:00-04:00
utc_timestamp = 2023-06-15T13:00:00Z
precise_time = 2023-06-15T13:00:00.123456Z
TOML;
            $result = Toml::parse($toml);
            expect($result['start_time'])->toBe('2023-06-15T09:00:00-04:00');
            expect($result['end_time'])->toBe('2023-06-15T17:00:00-04:00');
            expect($result['utc_timestamp'])->toBe('2023-06-15T13:00:00Z');
            expect($result['precise_time'])->toBe('2023-06-15T13:00:00.123456Z');
        });

        it('returns string type for offset date-times', function () {
            $result = Toml::parse('odt = 1979-05-27T07:32:00Z');
            expect($result['odt'])->toBeString();
        });
    });

    describe('local date-times', function () {
        it('parses local date-time without timezone', function () {
            expect(Toml::parse('ldt1 = 1979-05-27T07:32:00'))->toBe(['ldt1' => '1979-05-27T07:32:00']);
        });

        it('supports fractional seconds', function () {
            expect(Toml::parse('ldt2 = 1979-05-27T00:32:00.999999'))->toBe(['ldt2' => '1979-05-27T00:32:00.999999']);
        });

        it('normalizes space separator to T', function () {
            expect(Toml::parse('ldt3 = 1979-05-27 07:32:00'))->toBe(['ldt3' => '1979-05-27T07:32:00']);
        });

        it('supports fractional seconds with space separator (normalized)', function () {
            expect(Toml::parse('ldt4 = 1979-05-27 00:32:00.999999'))->toBe(['ldt4' => '1979-05-27T00:32:00.999999']);
        });

        it('normalizes lowercase t separator to uppercase T', function () {
            expect(Toml::parse('ldt5 = 1979-05-27t07:32:00'))->toBe(['ldt5' => '1979-05-27T07:32:00']);
        });

        it('parses multiple local date-time key-value pairs', function () {
            $toml = <<<'TOML'
start = 1979-05-27T07:32:00
end = 2024-01-15T14:30:00
precise = 2025-12-31T23:59:59.999999
TOML;
            expect(Toml::parse($toml))->toBe([
                'start' => '1979-05-27T07:32:00',
                'end' => '2024-01-15T14:30:00',
                'precise' => '2025-12-31T23:59:59.999999',
            ]);
        });

        it('parses local date-times in real-world TOML example', function () {
            $toml = <<<'TOML'
meeting_start = 2023-06-15T09:00:00
meeting_end = 2023-06-15T17:00:00
deadline = 2023-06-15 23:59:59
precise_time = 2023-06-15T13:00:00.123456
TOML;
            $result = Toml::parse($toml);
            expect($result['meeting_start'])->toBe('2023-06-15T09:00:00');
            expect($result['meeting_end'])->toBe('2023-06-15T17:00:00');
            expect($result['deadline'])->toBe('2023-06-15T23:59:59'); // normalized
            expect($result['precise_time'])->toBe('2023-06-15T13:00:00.123456');
        });

        it('returns string type for local date-times', function () {
            $result = Toml::parse('ldt = 1979-05-27T07:32:00');
            expect($result['ldt'])->toBeString();
        });
    });

    describe('local dates', function () {
        it('parses local date', function () {
            expect(Toml::parse('ld1 = 1979-05-27'))->toBe(['ld1' => '1979-05-27']);
        });

        it('parses various valid dates', function () {
            expect(Toml::parse('ld1 = 2024-01-01'))->toBe(['ld1' => '2024-01-01']);
            expect(Toml::parse('ld2 = 2000-12-31'))->toBe(['ld2' => '2000-12-31']);
            expect(Toml::parse('ld3 = 1999-06-15'))->toBe(['ld3' => '1999-06-15']);
        });

        it('parses multiple local date key-value pairs', function () {
            $toml = <<<'TOML'
birthday = 1979-05-27
anniversary = 2024-01-15
deadline = 2025-12-31
TOML;
            expect(Toml::parse($toml))->toBe([
                'birthday' => '1979-05-27',
                'anniversary' => '2024-01-15',
                'deadline' => '2025-12-31',
            ]);
        });

        it('parses local dates in real-world TOML example', function () {
            $toml = <<<'TOML'
release_date = 2023-06-15
end_of_life = 2025-06-15
start_date = 2020-01-01
TOML;
            $result = Toml::parse($toml);
            expect($result['release_date'])->toBe('2023-06-15');
            expect($result['end_of_life'])->toBe('2025-06-15');
            expect($result['start_date'])->toBe('2020-01-01');
        });

        it('returns string type for local dates', function () {
            $result = Toml::parse('ld = 1979-05-27');
            expect($result['ld'])->toBeString();
        });
    });

    describe('local times', function () {
        it('parses local time', function () {
            expect(Toml::parse('lt1 = 07:32:00'))->toBe(['lt1' => '07:32:00']);
        });

        it('supports fractional seconds', function () {
            expect(Toml::parse('lt2 = 00:32:00.999999'))->toBe(['lt2' => '00:32:00.999999']);
        });

        it('parses various valid times', function () {
            expect(Toml::parse('lt1 = 00:00:00'))->toBe(['lt1' => '00:00:00']);
            expect(Toml::parse('lt2 = 23:59:59'))->toBe(['lt2' => '23:59:59']);
            expect(Toml::parse('lt3 = 12:30:45'))->toBe(['lt3' => '12:30:45']);
        });

        it('parses multiple local time key-value pairs', function () {
            $toml = <<<'TOML'
start_time = 07:32:00
end_time = 17:30:00
precise = 12:00:00.999999
TOML;
            expect(Toml::parse($toml))->toBe([
                'start_time' => '07:32:00',
                'end_time' => '17:30:00',
                'precise' => '12:00:00.999999',
            ]);
        });

        it('parses local times in real-world TOML example', function () {
            $toml = <<<'TOML'
opening_time = 09:00:00
closing_time = 17:00:00
lunch_break = 12:30:00
precise_measurement = 13:45:30.123456
TOML;
            $result = Toml::parse($toml);
            expect($result['opening_time'])->toBe('09:00:00');
            expect($result['closing_time'])->toBe('17:00:00');
            expect($result['lunch_break'])->toBe('12:30:00');
            expect($result['precise_measurement'])->toBe('13:45:30.123456');
        });

        it('returns string type for local times', function () {
            $result = Toml::parse('lt = 07:32:00');
            expect($result['lt'])->toBeString();
        });
    });

    describe('standard tables', function () {
        it('parses simple table header', function () {
            $toml = <<<'TOML'
[table]
key = "value"
TOML;
            expect(Toml::parse($toml))->toBe([
                'table' => ['key' => 'value'],
            ]);
        });

        it('parses empty table', function () {
            expect(Toml::parse('[table]'))->toBe(['table' => []]);
        });

        it('parses table with multiple keys', function () {
            $toml = <<<'TOML'
[server]
host = "localhost"
port = 8080
enabled = true
TOML;
            expect(Toml::parse($toml))->toBe([
                'server' => [
                    'host' => 'localhost',
                    'port' => 8080,
                    'enabled' => true,
                ],
            ]);
        });

        it('parses multiple tables', function () {
            $toml = <<<'TOML'
[server]
host = "localhost"

[database]
name = "mydb"
TOML;
            expect(Toml::parse($toml))->toBe([
                'server' => ['host' => 'localhost'],
                'database' => ['name' => 'mydb'],
            ]);
        });

        it('parses nested tables with dots', function () {
            $toml = <<<'TOML'
[a.b.c]
key = "deep"
TOML;
            expect(Toml::parse($toml))->toBe([
                'a' => ['b' => ['c' => ['key' => 'deep']]],
            ]);
        });

        it('parses nested table headers creating hierarchy', function () {
            $toml = <<<'TOML'
[parent]
name = "parent"

[parent.child]
name = "child"
TOML;
            expect(Toml::parse($toml))->toBe([
                'parent' => [
                    'name' => 'parent',
                    'child' => ['name' => 'child'],
                ],
            ]);
        });

        it('allows defining super-tables implicitly', function () {
            $toml = <<<'TOML'
[a.b.c]
key = "value"

[a]
name = "defined later"
TOML;
            expect(Toml::parse($toml))->toBe([
                'a' => [
                    'b' => ['c' => ['key' => 'value']],
                    'name' => 'defined later',
                ],
            ]);
        });

        it('allows adding keys to implicitly defined super-table', function () {
            $toml = <<<'TOML'
[x.y.z]
deep = true

[x.y]
middle = true

[x]
top = true
TOML;
            expect(Toml::parse($toml))->toBe([
                'x' => [
                    'y' => [
                        'z' => ['deep' => true],
                        'middle' => true,
                    ],
                    'top' => true,
                ],
            ]);
        });

        it('parses table with quoted keys', function () {
            $toml = <<<'TOML'
["127.0.0.1"]
port = 8080
TOML;
            expect(Toml::parse($toml))->toBe([
                '127.0.0.1' => ['port' => 8080],
            ]);
        });

        it('parses nested table with mixed quoted and bare keys', function () {
            $toml = <<<'TOML'
[servers."alpha.example.com"]
ip = "10.0.0.1"
TOML;
            expect(Toml::parse($toml))->toBe([
                'servers' => [
                    'alpha.example.com' => ['ip' => '10.0.0.1'],
                ],
            ]);
        });

        it('allows whitespace around table header brackets', function () {
            $toml = <<<'TOML'
[  table  ]
key = "value"
TOML;
            expect(Toml::parse($toml))->toBe([
                'table' => ['key' => 'value'],
            ]);
        });

        it('allows keys before first table header to be root level', function () {
            $toml = <<<'TOML'
root_key = "root"

[table]
table_key = "table"
TOML;
            expect(Toml::parse($toml))->toBe([
                'root_key' => 'root',
                'table' => ['table_key' => 'table'],
            ]);
        });

        it('allows dotted keys within a table', function () {
            $toml = <<<'TOML'
[table]
a.b.c = "value"
TOML;
            expect(Toml::parse($toml))->toBe([
                'table' => [
                    'a' => ['b' => ['c' => 'value']],
                ],
            ]);
        });

        it('rejects duplicate table definitions', function () {
            $toml = <<<'TOML'
[table]
key = "first"

[table]
key = "second"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'already defined');

        it('rejects duplicate nested table definitions', function () {
            $toml = <<<'TOML'
[a.b]
key = "first"

[a.b]
key = "second"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'already defined');

        it('rejects redefining a key as a table', function () {
            $toml = <<<'TOML'
a = "scalar"

[a]
key = "value"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'not a table');

        it('rejects redefining a nested key as a table', function () {
            $toml = <<<'TOML'
a.b = "scalar"

[a.b]
key = "value"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'not a table');

        it('rejects defining key inside dotted-key-defined table that conflicts', function () {
            $toml = <<<'TOML'
[fruit]
apple.color = "red"
apple.taste.sweet = true

[fruit.apple]
texture = "smooth"
TOML;
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'implicitly defined');

        it('rejects redefining table as scalar', function () {
            $toml = <<<'TOML'
[a]
key = "value"

[b]
a = "scalar"
TOML;
            // This should succeed - different tables, not related
            expect(Toml::parse($toml))->toBe([
                'a' => ['key' => 'value'],
                'b' => ['a' => 'scalar'],
            ]);
        });

        it('parses table headers with comments', function () {
            $toml = <<<'TOML'
# Comment before table
[table] # Comment after table header
key = "value"
TOML;
            expect(Toml::parse($toml))->toBe([
                'table' => ['key' => 'value'],
            ]);
        });

        it('handles empty lines between table header and keys', function () {
            $toml = <<<'TOML'
[table]

key = "value"
TOML;
            expect(Toml::parse($toml))->toBe([
                'table' => ['key' => 'value'],
            ]);
        });

        it('parses tables in real-world TOML example', function () {
            $toml = <<<'TOML'
# Application configuration
title = "TOML Example"

[owner]
name = "Tom Preston-Werner"
dob = 1979-05-27T07:32:00-08:00

[database]
server = "192.168.1.1"
ports = 8001
enabled = true

[servers.alpha]
ip = "10.0.0.1"
dc = "eqdc10"

[servers.beta]
ip = "10.0.0.2"
dc = "eqdc10"
TOML;
            expect(Toml::parse($toml))->toBe([
                'title' => 'TOML Example',
                'owner' => [
                    'name' => 'Tom Preston-Werner',
                    'dob' => '1979-05-27T07:32:00-08:00',
                ],
                'database' => [
                    'server' => '192.168.1.1',
                    'ports' => 8001,
                    'enabled' => true,
                ],
                'servers' => [
                    'alpha' => ['ip' => '10.0.0.1', 'dc' => 'eqdc10'],
                    'beta' => ['ip' => '10.0.0.2', 'dc' => 'eqdc10'],
                ],
            ]);
        });
    });
});

describe('inline tables', function () {
    it('parses simple inline table', function () {
        expect(Toml::parse('table = { key = "value" }'))->toBe([
            'table' => ['key' => 'value'],
        ]);
    });

    it('parses empty inline table', function () {
        expect(Toml::parse('empty = {}'))->toBe(['empty' => []]);
    });

    it('parses inline table with multiple key-value pairs', function () {
        expect(Toml::parse('point = { x = 1, y = 2 }'))->toBe([
            'point' => ['x' => 1, 'y' => 2],
        ]);
    });

    it('parses inline table with different value types', function () {
        $toml = 'data = { name = "test", count = 42, enabled = true, ratio = 3.14 }';
        expect(Toml::parse($toml))->toBe([
            'data' => [
                'name' => 'test',
                'count' => 42,
                'enabled' => true,
                'ratio' => 3.14,
            ],
        ]);
    });

    it('parses inline table with quoted keys', function () {
        expect(Toml::parse('table = { "127.0.0.1" = "localhost" }'))->toBe([
            'table' => ['127.0.0.1' => 'localhost'],
        ]);
    });

    it('parses inline table with dotted keys', function () {
        expect(Toml::parse('table = { a.b = "value" }'))->toBe([
            'table' => ['a' => ['b' => 'value']],
        ]);
    });

    it('parses inline table with multiple dotted keys', function () {
        expect(Toml::parse('table = { a.b = 1, a.c = 2 }'))->toBe([
            'table' => ['a' => ['b' => 1, 'c' => 2]],
        ]);
    });

    it('parses nested inline tables', function () {
        expect(Toml::parse('outer = { inner = { key = "value" } }'))->toBe([
            'outer' => ['inner' => ['key' => 'value']],
        ]);
    });

    it('parses deeply nested inline tables', function () {
        expect(Toml::parse('a = { b = { c = { d = 42 } } }'))->toBe([
            'a' => ['b' => ['c' => ['d' => 42]]],
        ]);
    });

    it('parses inline table with datetime values', function () {
        expect(Toml::parse('event = { date = 1979-05-27, time = 07:32:00 }'))->toBe([
            'event' => [
                'date' => '1979-05-27',
                'time' => '07:32:00',
            ],
        ]);
    });

    it('allows trailing comma (TOML 1.1.0)', function () {
        expect(Toml::parse('table = { key = "value", }'))->toBe([
            'table' => ['key' => 'value'],
        ]);
    });

    it('allows trailing comma with multiple pairs (TOML 1.1.0)', function () {
        expect(Toml::parse('point = { x = 1, y = 2, }'))->toBe([
            'point' => ['x' => 1, 'y' => 2],
        ]);
    });

    it('allows newlines within inline tables (TOML 1.1.0)', function () {
        $toml = <<<'TOML'
point = {
    x = 1,
    y = 2
}
TOML;
        expect(Toml::parse($toml))->toBe([
            'point' => ['x' => 1, 'y' => 2],
        ]);
    });

    it('allows newlines with trailing comma (TOML 1.1.0)', function () {
        $toml = <<<'TOML'
config = {
    name = "app",
    version = "1.0",
}
TOML;
        expect(Toml::parse($toml))->toBe([
            'config' => ['name' => 'app', 'version' => '1.0'],
        ]);
    });

    it('allows newlines after opening brace (TOML 1.1.0)', function () {
        $toml = <<<'TOML'
table = {
key = "value" }
TOML;
        expect(Toml::parse($toml))->toBe([
            'table' => ['key' => 'value'],
        ]);
    });

    it('allows newlines before closing brace (TOML 1.1.0)', function () {
        $toml = <<<'TOML'
table = { key = "value"
}
TOML;
        expect(Toml::parse($toml))->toBe([
            'table' => ['key' => 'value'],
        ]);
    });

    it('parses multiple inline tables in document', function () {
        $toml = <<<'TOML'
point1 = { x = 1, y = 2 }
point2 = { x = 3, y = 4 }
TOML;
        expect(Toml::parse($toml))->toBe([
            'point1' => ['x' => 1, 'y' => 2],
            'point2' => ['x' => 3, 'y' => 4],
        ]);
    });

    it('parses inline table inside standard table', function () {
        $toml = <<<'TOML'
[section]
config = { debug = true, level = 3 }
TOML;
        expect(Toml::parse($toml))->toBe([
            'section' => [
                'config' => ['debug' => true, 'level' => 3],
            ],
        ]);
    });

    it('allows whitespace around equals and commas', function () {
        expect(Toml::parse('t = { a   =   1   ,   b   =   2 }'))->toBe([
            't' => ['a' => 1, 'b' => 2],
        ]);
    });

    it('allows no whitespace around equals and commas', function () {
        expect(Toml::parse('t={a=1,b=2}'))->toBe([
            't' => ['a' => 1, 'b' => 2],
        ]);
    });

    it('rejects duplicate keys in inline table', function () {
        Toml::parse('table = { key = 1, key = 2 }');
    })->throws(TomlParseException::class, "Cannot redefine key 'key'");

    it('rejects redefining dotted key in inline table', function () {
        Toml::parse('table = { a.b = 1, a.b = 2 }');
    })->throws(TomlParseException::class, "Cannot redefine key 'b'");

    it('rejects defining key under non-table in inline table', function () {
        Toml::parse('table = { a = 1, a.b = 2 }');
    })->throws(TomlParseException::class, 'not a table');

    it('parses inline tables in real-world TOML example', function () {
        $toml = <<<'TOML'
# Server configuration
name = "My Server"
connection = { host = "localhost", port = 8080 }
database = { name = "mydb", user = "admin", password = "secret" }
features = { logging = true, caching = false }
TOML;
        expect(Toml::parse($toml))->toBe([
            'name' => 'My Server',
            'connection' => ['host' => 'localhost', 'port' => 8080],
            'database' => ['name' => 'mydb', 'user' => 'admin', 'password' => 'secret'],
            'features' => ['logging' => true, 'caching' => false],
        ]);
    });

    it('parses complex nested structure with inline tables', function () {
        $toml = <<<'TOML'
[servers]
alpha = { ip = "10.0.0.1", dc = "eqdc10" }
beta = { ip = "10.0.0.2", dc = "eqdc10" }

[clients]
data = { id = 1, name = "client1" }
TOML;
        expect(Toml::parse($toml))->toBe([
            'servers' => [
                'alpha' => ['ip' => '10.0.0.1', 'dc' => 'eqdc10'],
                'beta' => ['ip' => '10.0.0.2', 'dc' => 'eqdc10'],
            ],
            'clients' => [
                'data' => ['id' => 1, 'name' => 'client1'],
            ],
        ]);
    });

    it('parses inline table with string containing special characters', function () {
        expect(Toml::parse('table = { msg = "hello, world!" }'))->toBe([
            'table' => ['msg' => 'hello, world!'],
        ]);
    });

    it('parses inline table with literal string value', function () {
        expect(Toml::parse("table = { path = 'C:\\Users\\admin' }"))->toBe([
            'table' => ['path' => 'C:\\Users\\admin'],
        ]);
    });
});

describe('arrays', function () {
    it('parses simple array of integers', function () {
        expect(Toml::parse('numbers = [ 1, 2, 3 ]'))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('parses empty array', function () {
        expect(Toml::parse('empty = []'))->toBe(['empty' => []]);
    });

    it('parses array with single element', function () {
        expect(Toml::parse('single = [ 42 ]'))->toBe(['single' => [42]]);
    });

    it('parses array of strings', function () {
        expect(Toml::parse('colors = [ "red", "green", "blue" ]'))->toBe([
            'colors' => ['red', 'green', 'blue'],
        ]);
    });

    it('parses array of floats', function () {
        expect(Toml::parse('values = [ 1.1, 2.2, 3.3 ]'))->toBe([
            'values' => [1.1, 2.2, 3.3],
        ]);
    });

    it('parses array of booleans', function () {
        expect(Toml::parse('flags = [ true, false, true ]'))->toBe([
            'flags' => [true, false, true],
        ]);
    });

    it('parses array of dates', function () {
        expect(Toml::parse('dates = [ 1979-05-27, 2024-01-15 ]'))->toBe([
            'dates' => ['1979-05-27', '2024-01-15'],
        ]);
    });

    it('parses array of datetimes', function () {
        expect(Toml::parse('times = [ 1979-05-27T07:32:00Z, 2024-01-15T10:30:00-05:00 ]'))->toBe([
            'times' => ['1979-05-27T07:32:00Z', '2024-01-15T10:30:00-05:00'],
        ]);
    });

    it('parses array of local times', function () {
        expect(Toml::parse('times = [ 07:32:00, 14:30:00 ]'))->toBe([
            'times' => ['07:32:00', '14:30:00'],
        ]);
    });

    it('allows mixed types in arrays', function () {
        expect(Toml::parse('mixed = [ "string", 42, 3.14, true ]'))->toBe([
            'mixed' => ['string', 42, 3.14, true],
        ]);
    });

    it('allows mixed types with dates and strings', function () {
        expect(Toml::parse('data = [ "event", 2024-01-15, 42 ]'))->toBe([
            'data' => ['event', '2024-01-15', 42],
        ]);
    });

    it('allows trailing comma', function () {
        expect(Toml::parse('numbers = [ 1, 2, 3, ]'))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('allows trailing comma with single element', function () {
        expect(Toml::parse('single = [ 42, ]'))->toBe(['single' => [42]]);
    });

    it('allows newlines within arrays', function () {
        $toml = <<<'TOML'
numbers = [
    1,
    2,
    3
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('allows newlines with trailing comma', function () {
        $toml = <<<'TOML'
numbers = [
    1,
    2,
    3,
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('allows newlines after opening bracket', function () {
        $toml = <<<'TOML'
numbers = [
1, 2, 3 ]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('allows newlines before closing bracket', function () {
        $toml = <<<'TOML'
numbers = [ 1, 2, 3
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('allows multiple newlines between elements', function () {
        $toml = <<<'TOML'
numbers = [
    1,

    2,

    3
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('parses nested arrays', function () {
        expect(Toml::parse('nested = [ [ 1, 2 ], [ 3, 4 ] ]'))->toBe([
            'nested' => [[1, 2], [3, 4]],
        ]);
    });

    it('parses deeply nested arrays', function () {
        expect(Toml::parse('deep = [ [ [ 1 ] ] ]'))->toBe([
            'deep' => [[[1]]],
        ]);
    });

    it('parses nested arrays with different types', function () {
        expect(Toml::parse('nested = [ [ "a", "b" ], [ 1, 2 ] ]'))->toBe([
            'nested' => [['a', 'b'], [1, 2]],
        ]);
    });

    it('parses nested arrays with newlines', function () {
        $toml = <<<'TOML'
matrix = [
    [ 1, 2, 3 ],
    [ 4, 5, 6 ],
    [ 7, 8, 9 ]
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'matrix' => [
                [1, 2, 3],
                [4, 5, 6],
                [7, 8, 9],
            ],
        ]);
    });

    it('parses array of inline tables', function () {
        expect(Toml::parse('points = [ { x = 1, y = 2 }, { x = 3, y = 4 } ]'))->toBe([
            'points' => [
                ['x' => 1, 'y' => 2],
                ['x' => 3, 'y' => 4],
            ],
        ]);
    });

    it('parses array of inline tables with newlines', function () {
        $toml = <<<'TOML'
points = [
    { x = 1, y = 2 },
    { x = 3, y = 4 },
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'points' => [
                ['x' => 1, 'y' => 2],
                ['x' => 3, 'y' => 4],
            ],
        ]);
    });

    it('parses array of complex inline tables', function () {
        $toml = 'users = [ { name = "Alice", age = 30 }, { name = "Bob", age = 25 } ]';
        expect(Toml::parse($toml))->toBe([
            'users' => [
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ],
        ]);
    });

    it('parses array of inline tables with nested values', function () {
        $toml = 'items = [ { data = { value = 1 } }, { data = { value = 2 } } ]';
        expect(Toml::parse($toml))->toBe([
            'items' => [
                ['data' => ['value' => 1]],
                ['data' => ['value' => 2]],
            ],
        ]);
    });

    it('allows no whitespace around brackets', function () {
        expect(Toml::parse('arr=[1,2,3]'))->toBe(['arr' => [1, 2, 3]]);
    });

    it('allows extra whitespace around elements', function () {
        expect(Toml::parse('arr = [   1   ,   2   ,   3   ]'))->toBe([
            'arr' => [1, 2, 3],
        ]);
    });

    it('parses array as value in standard table', function () {
        $toml = <<<'TOML'
[section]
items = [ 1, 2, 3 ]
TOML;
        expect(Toml::parse($toml))->toBe([
            'section' => ['items' => [1, 2, 3]],
        ]);
    });

    it('parses array with dotted key', function () {
        expect(Toml::parse('data.items = [ 1, 2, 3 ]'))->toBe([
            'data' => ['items' => [1, 2, 3]],
        ]);
    });

    it('parses arrays in real-world TOML example', function () {
        $toml = <<<'TOML'
# Configuration file
name = "My App"
ports = [ 8001, 8002, 8003 ]
hosts = [ "alpha", "omega" ]

[database]
connection_max = 5000
enabled = true

[servers]
data = [
    { name = "alpha", ip = "10.0.0.1" },
    { name = "beta", ip = "10.0.0.2" },
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'name' => 'My App',
            'ports' => [8001, 8002, 8003],
            'hosts' => ['alpha', 'omega'],
            'database' => [
                'connection_max' => 5000,
                'enabled' => true,
            ],
            'servers' => [
                'data' => [
                    ['name' => 'alpha', 'ip' => '10.0.0.1'],
                    ['name' => 'beta', 'ip' => '10.0.0.2'],
                ],
            ],
        ]);
    });

    it('parses array with comments on separate lines', function () {
        $toml = <<<'TOML'
numbers = [
    # First number
    1,
    # Second number
    2,
    # Third number
    3
]
TOML;
        expect(Toml::parse($toml))->toBe([
            'numbers' => [1, 2, 3],
        ]);
    });

    it('parses array with literal strings', function () {
        expect(Toml::parse("paths = [ 'C:\\path', 'D:\\other' ]"))->toBe([
            'paths' => ['C:\\path', 'D:\\other'],
        ]);
    });

    it('parses array with multiline strings', function () {
        $toml = 'texts = [ """line 1""", """line 2""" ]';
        expect(Toml::parse($toml))->toBe([
            'texts' => ['line 1', 'line 2'],
        ]);
    });

    it('rejects array without closing bracket', function () {
        Toml::parse('arr = [ 1, 2, 3');
    })->throws(TomlParseException::class);

    it('rejects array with consecutive commas', function () {
        Toml::parse('arr = [ 1, , 3 ]');
    })->throws(TomlParseException::class);

    it('rejects array with only comma', function () {
        Toml::parse('arr = [ , ]');
    })->throws(TomlParseException::class);
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

describe('array of tables', function () {
    it('parses simple array of tables', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
sku = 738594937

[[products]]
name = "Nail"
sku = 284758393
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                ['name' => 'Hammer', 'sku' => 738594937],
                ['name' => 'Nail', 'sku' => 284758393],
            ],
        ]);
    });

    it('parses array of tables with single element', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                ['name' => 'Hammer'],
            ],
        ]);
    });

    it('parses empty array of tables element', function () {
        $toml = <<<'TOML'
[[products]]

[[products]]
name = "Nail"
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                [],
                ['name' => 'Nail'],
            ],
        ]);
    });

    it('parses array of tables with nested path', function () {
        $toml = <<<'TOML'
[[fruits]]
name = "apple"

[[fruits.varieties]]
name = "red delicious"

[[fruits.varieties]]
name = "granny smith"

[[fruits]]
name = "banana"

[[fruits.varieties]]
name = "plantain"
TOML;
        expect(Toml::parse($toml))->toBe([
            'fruits' => [
                [
                    'name' => 'apple',
                    'varieties' => [
                        ['name' => 'red delicious'],
                        ['name' => 'granny smith'],
                    ],
                ],
                [
                    'name' => 'banana',
                    'varieties' => [
                        ['name' => 'plantain'],
                    ],
                ],
            ],
        ]);
    });

    it('allows sub-tables within array of tables elements', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
sku = 738594937

[products.details]
weight = 1.5
color = "silver"

[[products]]
name = "Nail"
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                [
                    'name' => 'Hammer',
                    'sku' => 738594937,
                    'details' => [
                        'weight' => 1.5,
                        'color' => 'silver',
                    ],
                ],
                ['name' => 'Nail'],
            ],
        ]);
    });

    it('parses nested sub-tables within array of tables', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"

[products.physical]
color = "gray"

[products.physical.dimensions]
width = 5
height = 2
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                [
                    'name' => 'Hammer',
                    'physical' => [
                        'color' => 'gray',
                        'dimensions' => [
                            'width' => 5,
                            'height' => 2,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('allows whitespace inside array of tables brackets', function () {
        $toml = <<<'TOML'
[[  products  ]]
name = "Hammer"
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                ['name' => 'Hammer'],
            ],
        ]);
    });

    it('parses array of tables with quoted keys', function () {
        $toml = <<<'TOML'
[["special.items"]]
name = "Widget"
TOML;
        expect(Toml::parse($toml))->toBe([
            'special.items' => [
                ['name' => 'Widget'],
            ],
        ]);
    });

    it('parses array of tables with dotted quoted keys', function () {
        $toml = <<<'TOML'
[[servers."alpha.example.com"]]
ip = "10.0.0.1"
TOML;
        expect(Toml::parse($toml))->toBe([
            'servers' => [
                'alpha.example.com' => [
                    ['ip' => '10.0.0.1'],
                ],
            ],
        ]);
    });

    it('parses array of tables with root-level keys', function () {
        $toml = <<<'TOML'
title = "Store"

[[products]]
name = "Hammer"
TOML;
        expect(Toml::parse($toml))->toBe([
            'title' => 'Store',
            'products' => [
                ['name' => 'Hammer'],
            ],
        ]);
    });

    it('parses array of tables with standard tables', function () {
        $toml = <<<'TOML'
[store]
name = "Hardware Store"

[[store.products]]
name = "Hammer"

[[store.products]]
name = "Nail"
TOML;
        expect(Toml::parse($toml))->toBe([
            'store' => [
                'name' => 'Hardware Store',
                'products' => [
                    ['name' => 'Hammer'],
                    ['name' => 'Nail'],
                ],
            ],
        ]);
    });

    it('allows multiple array of tables at different paths', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"

[[categories]]
name = "Tools"

[[products]]
name = "Nail"
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                ['name' => 'Hammer'],
                ['name' => 'Nail'],
            ],
            'categories' => [
                ['name' => 'Tools'],
            ],
        ]);
    });

    it('rejects redefining array of tables as standard table', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"

[products]
name = "Invalid"
TOML;
        Toml::parse($toml);
    })->throws(TomlParseException::class);

    it('rejects redefining standard table as array of tables', function () {
        $toml = <<<'TOML'
[products]
name = "Single"

[[products]]
name = "Invalid"
TOML;
        Toml::parse($toml);
    })->throws(TomlParseException::class);

    it('rejects defining key under non-table in array of tables path', function () {
        $toml = <<<'TOML'
products = "scalar"

[[products]]
name = "Invalid"
TOML;
        Toml::parse($toml);
    })->throws(TomlParseException::class);

    it('parses array of tables in real-world TOML example', function () {
        $toml = <<<'TOML'
# Application configuration
title = "TOML Example"

[[servers]]
name = "alpha"
ip = "10.0.0.1"
dc = "eqdc10"

[[servers]]
name = "beta"
ip = "10.0.0.2"
dc = "eqdc10"

[database]
server = "192.168.1.1"
enabled = true
TOML;
        expect(Toml::parse($toml))->toBe([
            'title' => 'TOML Example',
            'servers' => [
                ['name' => 'alpha', 'ip' => '10.0.0.1', 'dc' => 'eqdc10'],
                ['name' => 'beta', 'ip' => '10.0.0.2', 'dc' => 'eqdc10'],
            ],
            'database' => [
                'server' => '192.168.1.1',
                'enabled' => true,
            ],
        ]);
    });

    it('parses complex nested structure with array of tables', function () {
        $toml = <<<'TOML'
[[fruit]]
name = "apple"

[fruit.physical]
color = "red"
shape = "round"

[[fruit.variety]]
name = "red delicious"

[[fruit.variety]]
name = "granny smith"

[[fruit]]
name = "banana"

[[fruit.variety]]
name = "plantain"
TOML;
        expect(Toml::parse($toml))->toBe([
            'fruit' => [
                [
                    'name' => 'apple',
                    'physical' => [
                        'color' => 'red',
                        'shape' => 'round',
                    ],
                    'variety' => [
                        ['name' => 'red delicious'],
                        ['name' => 'granny smith'],
                    ],
                ],
                [
                    'name' => 'banana',
                    'variety' => [
                        ['name' => 'plantain'],
                    ],
                ],
            ],
        ]);
    });

    it('parses deeply nested array of tables', function () {
        $toml = <<<'TOML'
[[a.b.c]]
key = "value"
TOML;
        expect(Toml::parse($toml))->toBe([
            'a' => [
                'b' => [
                    'c' => [
                        ['key' => 'value'],
                    ],
                ],
            ],
        ]);
    });

    it('allows array of inline tables in combination with array of tables', function () {
        $toml = <<<'TOML'
[[products]]
name = "Hammer"
tags = [ { name = "tool" }, { name = "hardware" } ]
TOML;
        expect(Toml::parse($toml))->toBe([
            'products' => [
                [
                    'name' => 'Hammer',
                    'tags' => [
                        ['name' => 'tool'],
                        ['name' => 'hardware'],
                    ],
                ],
            ],
        ]);
    });
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
