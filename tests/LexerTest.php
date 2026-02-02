<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Lexer\Lexer;
use AppHive\Toml\Lexer\Token;
use AppHive\Toml\Lexer\TokenType;

describe('Lexer', function () {
    describe('structural tokens', function () {
        it('tokenizes brackets', function () {
            $lexer = new Lexer('[table]');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LeftBracket);
            expect($tokens[0]->value)->toBe('[');
            expect($tokens[1]->type)->toBe(TokenType::BareKey);
            expect($tokens[1]->value)->toBe('table');
            expect($tokens[2]->type)->toBe(TokenType::RightBracket);
            expect($tokens[2]->value)->toBe(']');
        });

        it('tokenizes braces', function () {
            $lexer = new Lexer('{ }');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LeftBrace);
            expect($tokens[1]->type)->toBe(TokenType::RightBrace);
        });

        it('tokenizes equals sign', function () {
            $lexer = new Lexer('key = value');
            $tokens = $lexer->tokenize();

            expect($tokens[1]->type)->toBe(TokenType::Equals);
            expect($tokens[1]->value)->toBe('=');
        });

        it('tokenizes dot', function () {
            $lexer = new Lexer('a.b.c');
            $tokens = $lexer->tokenize();

            expect($tokens[1]->type)->toBe(TokenType::Dot);
            expect($tokens[3]->type)->toBe(TokenType::Dot);
        });

        it('tokenizes comma', function () {
            $lexer = new Lexer('a, b, c');
            $tokens = $lexer->tokenize();

            expect($tokens[1]->type)->toBe(TokenType::Comma);
            expect($tokens[3]->type)->toBe(TokenType::Comma);
        });

        it('tokenizes newlines', function () {
            $lexer = new Lexer("a\nb");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[1]->type)->toBe(TokenType::Newline);
            expect($tokens[2]->type)->toBe(TokenType::BareKey);
        });

        it('handles CRLF newlines', function () {
            $lexer = new Lexer("a\r\nb");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[1]->type)->toBe(TokenType::Newline);
            expect($tokens[1]->value)->toBe("\r\n");
            expect($tokens[2]->type)->toBe(TokenType::BareKey);
        });
    });

    describe('bare keys', function () {
        it('tokenizes simple bare keys', function () {
            $lexer = new Lexer('key');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[0]->value)->toBe('key');
        });

        it('tokenizes bare keys with numbers', function () {
            $lexer = new Lexer('key123');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[0]->value)->toBe('key123');
        });

        it('tokenizes bare keys with underscores and hyphens', function () {
            $lexer = new Lexer('key_name-value');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[0]->value)->toBe('key_name-value');
        });
    });

    describe('basic strings', function () {
        it('tokenizes simple basic strings', function () {
            $lexer = new Lexer('"hello world"');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BasicString);
            expect($tokens[0]->value)->toBe('hello world');
        });

        it('tokenizes empty basic strings', function () {
            $lexer = new Lexer('""');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BasicString);
            expect($tokens[0]->value)->toBe('');
        });

        describe('standard escape sequences', function () {
            it('handles backspace escape \\b', function () {
                $lexer = new Lexer('"hello\\bworld"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\x08world");
            });

            it('handles tab escape \\t', function () {
                $lexer = new Lexer('"hello\\tworld"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\tworld");
            });

            it('handles newline escape \\n', function () {
                $lexer = new Lexer('"line1\\nline2"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("line1\nline2");
            });

            it('handles form feed escape \\f', function () {
                $lexer = new Lexer('"hello\\fworld"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\x0Cworld");
            });

            it('handles carriage return escape \\r', function () {
                $lexer = new Lexer('"hello\\rworld"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\rworld");
            });

            it('handles quote escape \\"', function () {
                $lexer = new Lexer('"say \\"hello\\""');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('say "hello"');
            });

            it('handles backslash escape \\\\', function () {
                $lexer = new Lexer('"path\\\\to\\\\file"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('path\\to\\file');
            });

            it('handles multiple escape sequences in one string', function () {
                $lexer = new Lexer('"\\b\\t\\n\\f\\r\\"\\\\"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("\x08\t\n\x0C\r\"\\");
            });
        });

        describe('unicode escape sequences', function () {
            it('handles 4-digit unicode escape \\uXXXX', function () {
                $lexer = new Lexer('"\\u0041\\u0042\\u0043"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('ABC');
            });

            it('handles 8-digit unicode escape \\UXXXXXXXX', function () {
                $lexer = new Lexer('"\\U0001F600"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('😀');
            });

            it('handles unicode escape for special characters', function () {
                $lexer = new Lexer('"\\u00A9"'); // copyright symbol
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('©');
            });

            it('handles unicode escape in the middle of a string', function () {
                $lexer = new Lexer('"hello \\u0041 world"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('hello A world');
            });

            it('throws on incomplete 4-digit unicode escape', function () {
                $lexer = new Lexer('"\\u041"');
                $lexer->tokenize();
            })->throws(TomlParseException::class);

            it('throws on incomplete 8-digit unicode escape', function () {
                $lexer = new Lexer('"\\U0001F60"');
                $lexer->tokenize();
            })->throws(TomlParseException::class);

            it('throws on invalid unicode escape character', function () {
                $lexer = new Lexer('"\\u00GG"');
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Invalid unicode escape character');
        });

        describe('TOML 1.1.0 escape sequences', function () {
            it('handles escape character \\e', function () {
                $lexer = new Lexer('"\\e[31mred\\e[0m"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("\x1B[31mred\x1B[0m");
            });

            it('handles hex escape \\xNN', function () {
                $lexer = new Lexer('"\\x41\\x42\\x43"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe('ABC');
            });

            it('handles hex escape with lowercase', function () {
                $lexer = new Lexer('"\\x7f"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("\x7F");
            });

            it('handles hex escape for null byte', function () {
                $lexer = new Lexer('"hello\\x00world"');
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\x00world");
            });

            it('throws on incomplete hex escape', function () {
                $lexer = new Lexer('"\\x4"');
                $lexer->tokenize();
            })->throws(TomlParseException::class);

            it('throws on invalid hex escape character', function () {
                $lexer = new Lexer('"\\xGG"');
                $lexer->tokenize();
            })->throws(TomlParseException::class);
        });

        describe('invalid escape sequences', function () {
            it('throws on invalid escape sequence \\a', function () {
                $lexer = new Lexer('"\\a"');
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Invalid escape sequence: \\a');

            it('throws on invalid escape sequence \\v', function () {
                $lexer = new Lexer('"\\v"');
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Invalid escape sequence: \\v');

            it('throws on invalid escape sequence \\z', function () {
                $lexer = new Lexer('"\\z"');
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Invalid escape sequence: \\z');

            it('throws on invalid escape sequence \\0 (not valid in TOML)', function () {
                $lexer = new Lexer('"\\0"');
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Invalid escape sequence: \\0');

            it('provides line and column for invalid escape sequence', function () {
                $lexer = new Lexer("key = \"test\\qvalue\"");

                try {
                    $lexer->tokenize();
                    expect(false)->toBeTrue(); // Should not reach here
                } catch (TomlParseException $e) {
                    expect($e->getErrorLine())->toBe(1);
                    expect($e->getMessage())->toContain('Invalid escape sequence: \\q');
                }
            });
        });

        describe('control characters', function () {
            it('allows tab character in basic string', function () {
                $lexer = new Lexer("\"hello\tworld\"");
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\tworld");
            });

            it('rejects null character (U+0000)', function () {
                $lexer = new Lexer("\"hello\x00world\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('rejects bell character (U+0007)', function () {
                $lexer = new Lexer("\"hello\x07world\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('rejects backspace character (U+0008)', function () {
                $lexer = new Lexer("\"hello\x08world\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('rejects form feed character (U+000C)', function () {
                $lexer = new Lexer("\"hello\x0Cworld\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('rejects escape character (U+001B)', function () {
                $lexer = new Lexer("\"hello\x1Bworld\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('rejects unit separator character (U+001F)', function () {
                $lexer = new Lexer("\"hello\x1Fworld\"");
                $lexer->tokenize();
            })->throws(TomlParseException::class, 'Control character');

            it('allows DEL character (U+007F)', function () {
                // DEL (U+007F) is not in the U+0000-U+001F range, so it's allowed
                $lexer = new Lexer("\"hello\x7Fworld\"");
                $tokens = $lexer->tokenize();

                expect($tokens[0]->value)->toBe("hello\x7Fworld");
            });

            it('provides line and column for control character error', function () {
                $lexer = new Lexer("\"hello\x00world\"");

                try {
                    $lexer->tokenize();
                    expect(false)->toBeTrue(); // Should not reach here
                } catch (TomlParseException $e) {
                    expect($e->getErrorLine())->toBe(1);
                    expect($e->getMessage())->toContain('Control character');
                }
            });
        });

        it('throws on unterminated string', function () {
            $lexer = new Lexer('"unterminated');
            $lexer->tokenize();
        })->throws(TomlParseException::class, 'Unterminated basic string');

        it('throws on newline in basic string', function () {
            $lexer = new Lexer("\"line1\nline2\"");
            $lexer->tokenize();
        })->throws(TomlParseException::class, 'Unterminated basic string');
    });

    describe('multiline basic strings', function () {
        it('tokenizes multiline basic strings', function () {
            $lexer = new Lexer('"""hello world"""');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::MultilineBasicString);
            expect($tokens[0]->value)->toBe('hello world');
        });

        it('strips first newline after opening delimiter', function () {
            $lexer = new Lexer("\"\"\"\nline1\nline2\"\"\"");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe("line1\nline2");
        });

        it('preserves newlines in content', function () {
            $lexer = new Lexer("\"\"\"line1\nline2\nline3\"\"\"");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe("line1\nline2\nline3");
        });

        it('handles line-ending backslash', function () {
            $lexer = new Lexer("\"\"\"line1 \\\n  line2\"\"\"");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe('line1 line2');
        });

        it('allows up to two quotes at end', function () {
            $lexer = new Lexer('"""hello""world"""""');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe('hello""world""');
        });
    });

    describe('literal strings', function () {
        it('tokenizes simple literal strings', function () {
            $lexer = new Lexer("'hello world'");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LiteralString);
            expect($tokens[0]->value)->toBe('hello world');
        });

        it('preserves backslashes without escaping', function () {
            $lexer = new Lexer("'C:\\path\\to\\file'");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe('C:\\path\\to\\file');
        });

        it('throws on unterminated literal string', function () {
            $lexer = new Lexer("'unterminated");
            $lexer->tokenize();
        })->throws(TomlParseException::class, 'Unterminated literal string');
    });

    describe('multiline literal strings', function () {
        it('tokenizes multiline literal strings', function () {
            $lexer = new Lexer("'''hello world'''");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::MultilineLiteralString);
            expect($tokens[0]->value)->toBe('hello world');
        });

        it('strips first newline after opening delimiter', function () {
            $lexer = new Lexer("'''\nline1\nline2'''");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe("line1\nline2");
        });

        it('preserves backslashes', function () {
            $lexer = new Lexer("'''C:\\path\\to\\file'''");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->value)->toBe('C:\\path\\to\\file');
        });
    });

    describe('integers', function () {
        it('tokenizes positive integers', function () {
            $lexer = new Lexer('42');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('42');
        });

        it('tokenizes signed integers', function () {
            $lexer = new Lexer('+99 -17');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('+99');
            expect($tokens[1]->type)->toBe(TokenType::Integer);
            expect($tokens[1]->value)->toBe('-17');
        });

        it('tokenizes integers with underscores', function () {
            $lexer = new Lexer('1_000_000');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('1000000');
        });

        it('tokenizes hexadecimal integers', function () {
            $lexer = new Lexer('0xDEADBEEF');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('0xDEADBEEF');
        });

        it('tokenizes octal integers', function () {
            $lexer = new Lexer('0o755');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('0o755');
        });

        it('tokenizes binary integers', function () {
            $lexer = new Lexer('0b11010110');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Integer);
            expect($tokens[0]->value)->toBe('0b11010110');
        });
    });

    describe('floats', function () {
        it('tokenizes simple floats', function () {
            $lexer = new Lexer('3.14');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('3.14');
        });

        it('tokenizes signed floats', function () {
            $lexer = new Lexer('+1.0 -0.5');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('+1.0');
            expect($tokens[1]->type)->toBe(TokenType::Float);
            expect($tokens[1]->value)->toBe('-0.5');
        });

        it('tokenizes floats with exponent', function () {
            $lexer = new Lexer('5e+22 1e6 -2E-2');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('5e+22');
            expect($tokens[1]->type)->toBe(TokenType::Float);
            expect($tokens[1]->value)->toBe('1e6');
            expect($tokens[2]->type)->toBe(TokenType::Float);
            expect($tokens[2]->value)->toBe('-2E-2');
        });

        it('tokenizes floats with decimal and exponent', function () {
            $lexer = new Lexer('6.626e-34');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('6.626e-34');
        });

        it('tokenizes inf', function () {
            $lexer = new Lexer('inf +inf -inf');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('inf');
            expect($tokens[1]->type)->toBe(TokenType::Float);
            expect($tokens[1]->value)->toBe('+inf');
            expect($tokens[2]->type)->toBe(TokenType::Float);
            expect($tokens[2]->value)->toBe('-inf');
        });

        it('tokenizes nan', function () {
            $lexer = new Lexer('nan +nan -nan');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Float);
            expect($tokens[0]->value)->toBe('nan');
            expect($tokens[1]->type)->toBe(TokenType::Float);
            expect($tokens[1]->value)->toBe('+nan');
            expect($tokens[2]->type)->toBe(TokenType::Float);
            expect($tokens[2]->value)->toBe('-nan');
        });
    });

    describe('booleans', function () {
        it('tokenizes true', function () {
            $lexer = new Lexer('true');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Boolean);
            expect($tokens[0]->value)->toBe('true');
        });

        it('tokenizes false', function () {
            $lexer = new Lexer('false');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Boolean);
            expect($tokens[0]->value)->toBe('false');
        });

        it('does not confuse truthy as boolean', function () {
            $lexer = new Lexer('truthy');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[0]->value)->toBe('truthy');
        });

        it('does not confuse falsey as boolean', function () {
            $lexer = new Lexer('falsey');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[0]->value)->toBe('falsey');
        });
    });

    describe('dates and times', function () {
        it('tokenizes offset datetime with Z', function () {
            $lexer = new Lexer('1979-05-27T07:32:00Z');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::OffsetDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27T07:32:00Z');
        });

        it('tokenizes offset datetime with offset', function () {
            $lexer = new Lexer('1979-05-27T00:32:00-07:00');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::OffsetDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27T00:32:00-07:00');
        });

        it('tokenizes offset datetime with positive offset', function () {
            $lexer = new Lexer('1979-05-27T00:32:00+05:30');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::OffsetDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27T00:32:00+05:30');
        });

        it('tokenizes offset datetime with fractional seconds', function () {
            $lexer = new Lexer('1979-05-27T07:32:00.999999Z');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::OffsetDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27T07:32:00.999999Z');
        });

        it('tokenizes local datetime', function () {
            $lexer = new Lexer('1979-05-27T07:32:00');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LocalDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27T07:32:00');
        });

        it('tokenizes local datetime with space separator', function () {
            $lexer = new Lexer('1979-05-27 07:32:00');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LocalDateTime);
            expect($tokens[0]->value)->toBe('1979-05-27 07:32:00');
        });

        it('tokenizes local date', function () {
            $lexer = new Lexer('1979-05-27');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LocalDate);
            expect($tokens[0]->value)->toBe('1979-05-27');
        });

        it('tokenizes local time', function () {
            $lexer = new Lexer('07:32:00');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LocalTime);
            expect($tokens[0]->value)->toBe('07:32:00');
        });

        it('tokenizes local time with fractional seconds', function () {
            $lexer = new Lexer('07:32:00.999');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::LocalTime);
            expect($tokens[0]->value)->toBe('07:32:00.999');
        });
    });

    describe('comments', function () {
        it('skips line comments', function () {
            $lexer = new Lexer("key = \"value\" # this is a comment\n");
            $tokens = $lexer->tokenize();

            // Should not include comment tokens
            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,
                TokenType::Equals,
                TokenType::BasicString,
                TokenType::Newline,
                TokenType::Eof,
            ]);
        });

        it('skips full-line comments', function () {
            $lexer = new Lexer("# This is a comment\nkey = \"value\"");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::Newline);
            expect($tokens[1]->type)->toBe(TokenType::BareKey);
        });

        it('handles comment at end of file without newline', function () {
            $lexer = new Lexer('key = "value" # comment');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,
                TokenType::Equals,
                TokenType::BasicString,
                TokenType::Eof,
            ]);
        });
    });

    describe('whitespace handling', function () {
        it('skips spaces between tokens', function () {
            $lexer = new Lexer('key    =    "value"');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[1]->type)->toBe(TokenType::Equals);
            expect($tokens[2]->type)->toBe(TokenType::BasicString);
        });

        it('skips tabs between tokens', function () {
            $lexer = new Lexer("key\t=\t\"value\"");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->type)->toBe(TokenType::BareKey);
            expect($tokens[1]->type)->toBe(TokenType::Equals);
            expect($tokens[2]->type)->toBe(TokenType::BasicString);
        });
    });

    describe('line and column tracking', function () {
        it('tracks position on first line', function () {
            $lexer = new Lexer('key = "value"');
            $tokens = $lexer->tokenize();

            expect($tokens[0]->line)->toBe(1);
            expect($tokens[0]->column)->toBe(1);

            expect($tokens[1]->line)->toBe(1);
            expect($tokens[1]->column)->toBe(5);

            expect($tokens[2]->line)->toBe(1);
            expect($tokens[2]->column)->toBe(7);
        });

        it('tracks position across multiple lines', function () {
            $lexer = new Lexer("line1\nline2\nline3");
            $tokens = $lexer->tokenize();

            expect($tokens[0]->line)->toBe(1);
            expect($tokens[0]->column)->toBe(1);

            expect($tokens[1]->line)->toBe(1); // newline token
            expect($tokens[1]->column)->toBe(6);

            expect($tokens[2]->line)->toBe(2);
            expect($tokens[2]->column)->toBe(1);

            expect($tokens[4]->line)->toBe(3);
            expect($tokens[4]->column)->toBe(1);
        });
    });

    describe('complete TOML examples', function () {
        it('tokenizes a simple key-value pair', function () {
            $lexer = new Lexer('title = "TOML Example"');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,
                TokenType::Equals,
                TokenType::BasicString,
                TokenType::Eof,
            ]);
        });

        it('tokenizes a table header', function () {
            $lexer = new Lexer('[database]');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::LeftBracket,
                TokenType::BareKey,
                TokenType::RightBracket,
                TokenType::Eof,
            ]);
        });

        it('tokenizes an array of tables', function () {
            $lexer = new Lexer('[[products]]');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::LeftBracket,
                TokenType::LeftBracket,
                TokenType::BareKey,
                TokenType::RightBracket,
                TokenType::RightBracket,
                TokenType::Eof,
            ]);
        });

        it('tokenizes inline table', function () {
            $lexer = new Lexer('name = { first = "Tom", last = "Preston-Werner" }');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,      // name
                TokenType::Equals,
                TokenType::LeftBrace,
                TokenType::BareKey,      // first
                TokenType::Equals,
                TokenType::BasicString,  // "Tom"
                TokenType::Comma,
                TokenType::BareKey,      // last
                TokenType::Equals,
                TokenType::BasicString,  // "Preston-Werner"
                TokenType::RightBrace,
                TokenType::Eof,
            ]);
        });

        it('tokenizes dotted keys', function () {
            $lexer = new Lexer('physical.color = "orange"');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,      // physical
                TokenType::Dot,
                TokenType::BareKey,      // color
                TokenType::Equals,
                TokenType::BasicString,  // "orange"
                TokenType::Eof,
            ]);
        });

        it('tokenizes array', function () {
            $lexer = new Lexer('integers = [ 1, 2, 3 ]');
            $tokens = $lexer->tokenize();

            $types = array_map(fn (Token $t) => $t->type, $tokens);
            expect($types)->toBe([
                TokenType::BareKey,
                TokenType::Equals,
                TokenType::LeftBracket,
                TokenType::Integer,
                TokenType::Comma,
                TokenType::Integer,
                TokenType::Comma,
                TokenType::Integer,
                TokenType::RightBracket,
                TokenType::Eof,
            ]);
        });
    });

    describe('EOF token', function () {
        it('always ends with EOF token', function () {
            $lexer = new Lexer('');
            $tokens = $lexer->tokenize();

            expect($tokens)->toHaveCount(1);
            expect($tokens[0]->type)->toBe(TokenType::Eof);
        });

        it('has EOF at end of non-empty input', function () {
            $lexer = new Lexer('key = "value"');
            $tokens = $lexer->tokenize();

            $lastToken = $tokens[count($tokens) - 1];
            expect($lastToken->type)->toBe(TokenType::Eof);
        });
    });

    describe('error handling', function () {
        it('throws on unexpected character', function () {
            $lexer = new Lexer('@invalid');
            $lexer->tokenize();
        })->throws(TomlParseException::class, 'Unexpected character');

        it('provides line and column in error', function () {
            $lexer = new Lexer("valid\n@invalid");

            try {
                $lexer->tokenize();
                expect(false)->toBeTrue(); // Should not reach here
            } catch (TomlParseException $e) {
                expect($e->getErrorLine())->toBe(2);
                expect($e->getErrorColumn())->toBe(1);
            }
        });
    });
});
