<?php

declare(strict_types=1);

namespace AppHive\Toml;

use AppHive\Toml\Exceptions\TomlParseException;

final class Toml
{
    /**
     * Parse a TOML string into an associative array.
     *
     * @return array<string, mixed>
     */
    public static function parse(string $toml): array
    {
        // Basic implementation - returns empty array for empty input
        // Full parser implementation will be added in future user stories
        if (trim($toml) === '') {
            return [];
        }

        // Placeholder for actual parsing logic
        return [];
    }

    /**
     * Parse a TOML file into an associative array.
     *
     * @return array<string, mixed>
     *
     * @throws TomlParseException If the file doesn't exist or isn't readable
     */
    public static function parseFile(string $path): array
    {
        if (! file_exists($path)) {
            throw new TomlParseException("File does not exist: {$path}");
        }

        if (! is_readable($path)) {
            throw new TomlParseException("File is not readable: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new TomlParseException("Failed to read file: {$path}");
        }

        return self::parse($contents);
    }
}
