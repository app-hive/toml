<?php

declare(strict_types=1);

use AppHive\Toml\Toml;

/**
 * Tests for RFC 3339 datetime normalization.
 *
 * TOML spec allows variations in datetime input (space vs T, lowercase z, etc.)
 * but output should be normalized to RFC 3339 format for consistency.
 */
describe('datetime RFC 3339 normalization', function () {
    describe('offset date-times', function () {
        it('normalizes space separator to T', function () {
            expect(Toml::parse('odt = 1987-07-05 17:45:00Z'))->toBe(['odt' => '1987-07-05T17:45:00Z']);
        });

        it('normalizes lowercase z to uppercase Z', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:00z'))->toBe(['odt' => '1987-07-05T17:45:00Z']);
        });

        it('normalizes lowercase t and z to uppercase', function () {
            expect(Toml::parse('odt = 1987-07-05t17:45:00z'))->toBe(['odt' => '1987-07-05T17:45:00Z']);
        });

        it('normalizes space separator with lowercase z', function () {
            expect(Toml::parse('odt = 1987-07-05 17:45:00z'))->toBe(['odt' => '1987-07-05T17:45:00Z']);
        });

        it('preserves uppercase T and Z', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:00Z'))->toBe(['odt' => '1987-07-05T17:45:00Z']);
        });

        it('preserves timezone offset format', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:00+08:00'))->toBe(['odt' => '1987-07-05T17:45:00+08:00']);
        });

        it('normalizes space separator with timezone offset', function () {
            expect(Toml::parse('odt = 1987-07-05 17:45:00+08:00'))->toBe(['odt' => '1987-07-05T17:45:00+08:00']);
        });

        it('preserves fractional seconds', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:56.123Z'))->toBe(['odt' => '1987-07-05T17:45:56.123Z']);
        });

        it('pads fractional seconds to milliseconds (single digit)', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:56.6Z'))->toBe(['odt' => '1987-07-05T17:45:56.600Z']);
        });

        it('pads fractional seconds to milliseconds (two digits)', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:56.12Z'))->toBe(['odt' => '1987-07-05T17:45:56.120Z']);
        });

        it('preserves fractional seconds precision (six digits)', function () {
            expect(Toml::parse('odt = 1987-07-05T17:45:56.123456Z'))->toBe(['odt' => '1987-07-05T17:45:56.123456Z']);
        });

        it('adds missing seconds', function () {
            expect(Toml::parse('odt = 1979-05-27 07:32Z'))->toBe(['odt' => '1979-05-27T07:32:00Z']);
        });

        it('adds missing seconds with timezone offset', function () {
            expect(Toml::parse('odt = 1979-05-27 07:32-07:00'))->toBe(['odt' => '1979-05-27T07:32:00-07:00']);
        });
    });

    describe('local date-times', function () {
        it('normalizes space separator to T', function () {
            expect(Toml::parse('ldt = 1987-07-05 17:45:00'))->toBe(['ldt' => '1987-07-05T17:45:00']);
        });

        it('normalizes lowercase t to uppercase T', function () {
            expect(Toml::parse('ldt = 1987-07-05t17:45:00'))->toBe(['ldt' => '1987-07-05T17:45:00']);
        });

        it('preserves uppercase T', function () {
            expect(Toml::parse('ldt = 1987-07-05T17:45:00'))->toBe(['ldt' => '1987-07-05T17:45:00']);
        });

        it('preserves fractional seconds', function () {
            expect(Toml::parse('ldt = 1977-12-21T10:32:00.555'))->toBe(['ldt' => '1977-12-21T10:32:00.555']);
        });

        it('adds missing seconds', function () {
            expect(Toml::parse('ldt = 1979-05-27T07:32'))->toBe(['ldt' => '1979-05-27T07:32:00']);
        });
    });

    describe('local dates', function () {
        it('preserves YYYY-MM-DD format', function () {
            expect(Toml::parse('ld = 1987-07-05'))->toBe(['ld' => '1987-07-05']);
        });

        it('preserves edge case dates', function () {
            expect(Toml::parse('ld = 0001-01-01'))->toBe(['ld' => '0001-01-01']);
            expect(Toml::parse('ld = 9999-12-31'))->toBe(['ld' => '9999-12-31']);
        });
    });

    describe('local times', function () {
        it('preserves HH:MM:SS format', function () {
            expect(Toml::parse('lt = 17:45:00'))->toBe(['lt' => '17:45:00']);
        });

        it('preserves fractional seconds (milliseconds)', function () {
            expect(Toml::parse('lt = 10:32:00.555'))->toBe(['lt' => '10:32:00.555']);
        });

        it('pads fractional seconds to milliseconds', function () {
            expect(Toml::parse('lt = 10:32:00.6'))->toBe(['lt' => '10:32:00.600']);
        });

        it('adds missing seconds', function () {
            expect(Toml::parse('lt = 13:37'))->toBe(['lt' => '13:37:00']);
        });
    });
});
