<?php

declare(strict_types=1);

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Toml;

describe('UTF-8 validation', function () {
    describe('invalid UTF-8 byte sequences', function () {
        it('rejects incomplete 2-byte sequence (C3 without continuation)', function () {
            // 0xC3 is a lead byte for 2-byte sequence, needs continuation byte
            $toml = "bad = \"\xC3\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects incomplete 3-byte sequence', function () {
            // 0xE2 starts a 3-byte sequence, but only has 1 continuation
            $toml = "bad = \"\xE2\x82\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects incomplete 4-byte sequence', function () {
            // 0xF0 starts a 4-byte sequence
            $toml = "bad = \"\xF0\x9F\x98\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects unexpected continuation byte', function () {
            // 0x80-0xBF are continuation bytes, invalid without lead byte
            $toml = "bad = \"\x80\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid lead byte 0xFE', function () {
            // 0xFE is never valid in UTF-8
            $toml = "bad = \"\xFE\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid lead byte 0xFF', function () {
            // 0xFF is never valid in UTF-8
            $toml = "bad = \"\xFF\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');
    });

    describe('overlong encodings', function () {
        it('rejects overlong encoding of NULL (C0 80 instead of 00)', function () {
            // ASCII NULL should be encoded as 0x00, not 0xC0 0x80
            $toml = "bad = \"\xC0\x80\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects overlong encoding of slash (C0 AF instead of 2F)', function () {
            // ASCII / (0x2F) encoded as overlong 2-byte sequence
            $toml = "bad = \"\xC0\xAF\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects overlong 3-byte encoding of character < U+0800', function () {
            // Characters < U+0800 should use 2 bytes max, not 3
            // 0xE0 0x80 0xAF is overlong for U+002F
            $toml = "bad = \"\xE0\x80\xAF\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects overlong 4-byte encoding of character < U+10000', function () {
            // Characters < U+10000 should use 3 bytes max, not 4
            // 0xF0 0x80 0x80 0xAF is overlong
            $toml = "bad = \"\xF0\x80\x80\xAF\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');
    });

    describe('surrogate code points', function () {
        it('rejects surrogate pair U+D800 encoded as UTF-8', function () {
            // U+D800 encoded as UTF-8: ED A0 80
            $toml = "bad = \"\xED\xA0\x80\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects surrogate pair U+DFFF encoded as UTF-8', function () {
            // U+DFFF encoded as UTF-8: ED BF BF
            $toml = "bad = \"\xED\xBF\xBF\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects surrogate pair U+DB80 encoded as UTF-8', function () {
            // U+DB80 (high surrogate) encoded as UTF-8: ED AE 80
            $toml = "bad = \"\xED\xAE\x80\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');
    });

    describe('invalid UTF-8 in various contexts', function () {
        it('rejects invalid UTF-8 in basic string', function () {
            $toml = "bad = \"\xC3\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid UTF-8 in literal string', function () {
            $toml = "bad = '\xC3'";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid UTF-8 in multiline basic string', function () {
            $toml = "bad = \"\"\"\xC3\"\"\"";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid UTF-8 in multiline literal string', function () {
            $toml = "bad = '''\xC3'''";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid UTF-8 in comment', function () {
            $toml = "# \xC3\n";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');

        it('rejects invalid UTF-8 in bare key area', function () {
            // Invalid UTF-8 outside of strings/comments
            $toml = "\xC3 = 1";
            Toml::parse($toml);
        })->throws(TomlParseException::class, 'Invalid UTF-8');
    });

    describe('valid UTF-8 should pass', function () {
        it('accepts valid 2-byte UTF-8 sequences', function () {
            // U+00E9 (é) = C3 A9
            $toml = 'name = "café"';
            $result = Toml::parse($toml);
            expect($result['name'])->toBe('café');
        });

        it('accepts valid 3-byte UTF-8 sequences', function () {
            // U+4E2D (中) = E4 B8 AD
            $toml = 'name = "中文"';
            $result = Toml::parse($toml);
            expect($result['name'])->toBe('中文');
        });

        it('accepts valid 4-byte UTF-8 sequences', function () {
            // U+1F600 (😀) = F0 9F 98 80
            $toml = 'emoji = "😀"';
            $result = Toml::parse($toml);
            expect($result['emoji'])->toBe('😀');
        });

        it('accepts valid UTF-8 in comments', function () {
            $toml = "# Café résumé 中文 😀\nkey = 1";
            $result = Toml::parse($toml);
            expect($result['key'])->toBe(1);
        });

        it('accepts character just before surrogate range U+D7FF', function () {
            // U+D7FF = ED 9F BF
            $toml = "valid = \"\xED\x9F\xBF\"";
            $result = Toml::parse($toml);
            expect(mb_ord($result['valid'], 'UTF-8'))->toBe(0xD7FF);
        });

        it('accepts character just after surrogate range U+E000', function () {
            // U+E000 = EE 80 80
            $toml = "valid = \"\xEE\x80\x80\"";
            $result = Toml::parse($toml);
            expect(mb_ord($result['valid'], 'UTF-8'))->toBe(0xE000);
        });
    });

    describe('error messages', function () {
        it('provides clear error message for invalid UTF-8', function () {
            $toml = "bad = \"\xC3\"";

            try {
                Toml::parse($toml);
                expect(false)->toBeTrue();
            } catch (TomlParseException $e) {
                expect($e->getMessage())->toContain('Invalid UTF-8');
                expect($e->getErrorLine())->toBe(1);
            }
        });

        it('provides line number for encoding error', function () {
            $toml = "key = 1\nbad = \"\xC3\"";

            try {
                Toml::parse($toml);
                expect(false)->toBeTrue();
            } catch (TomlParseException $e) {
                expect($e->getErrorLine())->toBe(2);
            }
        });
    });
});
