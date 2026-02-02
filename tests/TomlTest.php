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
