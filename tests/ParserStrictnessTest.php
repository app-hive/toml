<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Parser\ParserConfig;
use AppHive\Toml\Parser\ParserWarning;
use AppHive\Toml\Toml;

describe('Parser Strictness', function () {
    describe('ParserConfig', function () {
        it('defaults to strict mode', function () {
            $config = new ParserConfig;
            expect($config->strict)->toBeTrue();
        });

        it('creates strict config via factory method', function () {
            $config = ParserConfig::strict();
            expect($config->strict)->toBeTrue();
        });

        it('creates lenient config via factory method', function () {
            $config = ParserConfig::lenient();
            expect($config->strict)->toBeFalse();
        });
    });

    describe('strict mode', function () {
        it('throws on duplicate keys', function () {
            $toml = <<<'TOML'
key = "first"
key = "second"
TOML;
            Toml::parse($toml, ParserConfig::strict());
        })->throws(TomlParseException::class, "Cannot redefine key 'key'");

        it('throws on duplicate tables', function () {
            $toml = <<<'TOML'
[table]
key = "first"

[table]
key = "second"
TOML;
            Toml::parse($toml, ParserConfig::strict());
        })->throws(TomlParseException::class, "Table 'table' already defined");

        it('throws on leading zeros in integers', function () {
            Toml::parse('num = 007', ParserConfig::strict());
        })->throws(TomlParseException::class, 'Leading zeros are not allowed in decimal integers');

        it('throws on leading zeros in floats', function () {
            Toml::parse('num = 007.5', ParserConfig::strict());
        })->throws(TomlParseException::class, 'Leading zeros are not allowed in floats');

        it('throws when table conflicts with array of tables', function () {
            $toml = <<<'TOML'
[[products]]
name = "first"

[products]
name = "invalid"
TOML;
            Toml::parse($toml, ParserConfig::strict());
        })->throws(TomlParseException::class, "Cannot define table 'products' because it was already defined as an array of tables");

        it('throws when array of tables conflicts with table', function () {
            $toml = <<<'TOML'
[products]
name = "first"

[[products]]
name = "invalid"
TOML;
            Toml::parse($toml, ParserConfig::strict());
        })->throws(TomlParseException::class, "Cannot define array of tables 'products' because it was already defined as a table");

        it('throws on duplicate keys in inline tables', function () {
            Toml::parse('table = { key = 1, key = 2 }', ParserConfig::strict());
        })->throws(TomlParseException::class, "Cannot redefine key 'key' in inline table");
    });

    describe('lenient mode', function () {
        it('collects warning for duplicate keys instead of throwing', function () {
            $toml = <<<'TOML'
key = "first"
key = "second"
TOML;
            $parser = Toml::createParser($toml, ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            // Should keep first value
            expect($result)->toBe(['key' => 'first']);

            // Should have warning
            expect($warnings)->toHaveCount(1);
            expect($warnings[0])->toBeInstanceOf(ParserWarning::class);
            expect($warnings[0]->message)->toBe("Cannot redefine key 'key'");
            expect($warnings[0]->line)->toBe(2);
        });

        it('collects warning for duplicate tables instead of throwing', function () {
            $toml = <<<'TOML'
[table]
key = "first"

[table]
other = "second"
TOML;
            $parser = Toml::createParser($toml, ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            // Should have both keys in the table (continues adding to same table)
            expect($result)->toBe([
                'table' => [
                    'key' => 'first',
                    'other' => 'second',
                ],
            ]);

            // Should have warning
            expect($warnings)->toHaveCount(1);
            expect($warnings[0]->message)->toBe("Table 'table' already defined");
        });

        it('collects warning for leading zeros in integers', function () {
            $parser = Toml::createParser('num = 007', ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            // Should still parse the value
            expect($result)->toBe(['num' => 7]);

            // Should have warning
            expect($warnings)->toHaveCount(1);
            expect($warnings[0]->message)->toBe('Leading zeros are not allowed in decimal integers');
        });

        it('collects warning for leading zeros in floats', function () {
            $parser = Toml::createParser('num = 007.5', ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            // Should still parse the value
            expect($result)->toBe(['num' => 7.5]);

            // Should have warning
            expect($warnings)->toHaveCount(1);
            expect($warnings[0]->message)->toBe('Leading zeros are not allowed in floats');
        });

        it('collects warning for duplicate keys in inline tables', function () {
            $parser = Toml::createParser('table = { key = 1, key = 2 }', ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            // Should keep first value
            expect($result)->toBe(['table' => ['key' => 1]]);

            // Should have warning
            expect($warnings)->toHaveCount(1);
            expect($warnings[0]->message)->toBe("Cannot redefine key 'key' in inline table");
        });

        it('collects multiple warnings', function () {
            $toml = <<<'TOML'
a = 007
b = 008
c = "valid"
c = "duplicate"
TOML;
            $parser = Toml::createParser($toml, ParserConfig::lenient());
            $result = $parser->parse();
            $warnings = $parser->getWarnings();

            expect($result)->toBe([
                'a' => 7,
                'b' => 8,
                'c' => 'valid',
            ]);

            expect($warnings)->toHaveCount(3);
            expect($warnings[0]->message)->toBe('Leading zeros are not allowed in decimal integers');
            expect($warnings[1]->message)->toBe('Leading zeros are not allowed in decimal integers');
            expect($warnings[2]->message)->toBe("Cannot redefine key 'c'");
        });

        it('returns empty warnings array for valid TOML', function () {
            $parser = Toml::createParser('key = "value"', ParserConfig::lenient());
            $parser->parse();

            expect($parser->getWarnings())->toBe([]);
        });
    });

    describe('ParserWarning', function () {
        it('stores message, line, column, and snippet', function () {
            $warning = new ParserWarning('Test message', 5, 10, 'snippet content');

            expect($warning->message)->toBe('Test message');
            expect($warning->line)->toBe(5);
            expect($warning->column)->toBe(10);
            expect($warning->snippet)->toBe('snippet content');
        });

        it('formats message with location', function () {
            $warning = new ParserWarning('Test message', 5, 10, '');

            expect($warning->getFormattedMessage())->toBe('Test message at line 5, column 10');
        });

        it('returns plain message when no location', function () {
            $warning = new ParserWarning('Test message', 0, 0, '');

            expect($warning->getFormattedMessage())->toBe('Test message');
        });
    });

    describe('Toml facade', function () {
        it('parse() defaults to strict mode', function () {
            Toml::parse('key = 007');
        })->throws(TomlParseException::class);

        it('parse() accepts config parameter', function () {
            $result = Toml::parse('key = 007', ParserConfig::lenient());
            expect($result)->toBe(['key' => 7]);
        });

        it('createParser() returns Parser instance', function () {
            $parser = Toml::createParser('key = "value"');
            expect($parser)->toBeInstanceOf(\AppHive\Toml\Parser\Parser::class);
        });

        it('createParser() allows accessing warnings', function () {
            $parser = Toml::createParser('key = 007', ParserConfig::lenient());
            $parser->parse();

            expect($parser->getWarnings())->toHaveCount(1);
        });
    });

    describe('syntax errors always throw', function () {
        it('throws on unexpected token even in lenient mode', function () {
            Toml::parse('= value', ParserConfig::lenient());
        })->throws(TomlParseException::class);

        it('throws on missing value even in lenient mode', function () {
            Toml::parse('key = ', ParserConfig::lenient());
        })->throws(TomlParseException::class);

        it('throws on unclosed string even in lenient mode', function () {
            Toml::parse('key = "unclosed', ParserConfig::lenient());
        })->throws(TomlParseException::class);

        it('throws on unclosed inline table even in lenient mode', function () {
            Toml::parse('key = { a = 1', ParserConfig::lenient());
        })->throws(TomlParseException::class);

        it('throws on unclosed array even in lenient mode', function () {
            Toml::parse('key = [1, 2', ParserConfig::lenient());
        })->throws(TomlParseException::class);
    });
});
