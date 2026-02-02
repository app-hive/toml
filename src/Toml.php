<?php

declare(strict_types=1);

namespace AppHive\Toml;

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Parser\Parser;
use AppHive\Toml\Parser\ParserConfig;

/**
 * Main entry point for parsing TOML documents.
 *
 * Provides static methods for parsing TOML strings and files. Supports
 * configurable strictness for different use cases.
 *
 * **Strict mode (default):** Throws on any spec violation.
 * **Lenient mode:** Collects warnings and continues parsing where possible.
 *
 * For lenient parsing with warnings, use createParser() to get a Parser instance.
 */
final class Toml
{
    /**
     * Parse a TOML string into an associative array.
     *
     * Uses strict mode by default. For lenient parsing with warnings,
     * use createParser() instead.
     *
     * @param  string  $toml  The TOML string to parse
     * @param  ParserConfig|null  $config  Parser configuration. Defaults to strict mode.
     * @return array<string, mixed>
     *
     * @throws TomlParseException In strict mode, when a spec violation is encountered
     */
    public static function parse(string $toml, ?ParserConfig $config = null): array
    {
        // First check for invalid control characters before any trimming
        // This catches cases like a document containing only a null byte
        self::validateNoInvalidControlCharacters($toml);

        if (trim($toml) === '') {
            return [];
        }

        $parser = new Parser($toml, $config);

        return $parser->parse();
    }

    /**
     * Parse a TOML file into an associative array.
     *
     * Uses strict mode by default. For lenient parsing with warnings,
     * use createParserForFile() instead.
     *
     * @param  string  $path  Path to the TOML file
     * @param  ParserConfig|null  $config  Parser configuration. Defaults to strict mode.
     * @return array<string, mixed>
     *
     * @throws TomlParseException If the file doesn't exist, isn't readable,
     *                            or (in strict mode) contains spec violations
     */
    public static function parseFile(string $path, ?ParserConfig $config = null): array
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

        return self::parse($contents, $config);
    }

    /**
     * Create a parser instance for advanced usage.
     *
     * Use this method when you need access to warnings after lenient parsing:
     *
     * ```php
     * $parser = Toml::createParser($toml, ParserConfig::lenient());
     * $result = $parser->parse();
     * $warnings = $parser->getWarnings();
     * ```
     *
     * @param  string  $toml  The TOML string to parse
     * @param  ParserConfig|null  $config  Parser configuration. Defaults to strict mode.
     */
    public static function createParser(string $toml, ?ParserConfig $config = null): Parser
    {
        return new Parser($toml, $config);
    }

    /**
     * Create a parser instance for a file.
     *
     * Use this method when you need access to warnings after lenient parsing:
     *
     * ```php
     * $parser = Toml::createParserForFile($path, ParserConfig::lenient());
     * $result = $parser->parse();
     * $warnings = $parser->getWarnings();
     * ```
     *
     * @param  string  $path  Path to the TOML file
     * @param  ParserConfig|null  $config  Parser configuration. Defaults to strict mode.
     *
     * @throws TomlParseException If the file doesn't exist or isn't readable
     */
    public static function createParserForFile(string $path, ?ParserConfig $config = null): Parser
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

        return new Parser($contents, $config);
    }

    /**
     * Validate that the input string doesn't contain invalid control characters.
     * This is called before trimming to catch documents that consist only of control characters.
     *
     * Invalid control characters are U+0000-U+001F (except tab U+0009, newline U+000A, and CRLF)
     * and U+007F (DEL). Bare CR (U+000D not followed by U+000A) is also invalid.
     *
     * @throws TomlParseException When an invalid control character is found
     */
    private static function validateNoInvalidControlCharacters(string $toml): void
    {
        $length = strlen($toml);
        $line = 1;
        $column = 1;

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($toml[$i]);

            // Check for invalid control characters
            // Allowed: tab (0x09), newline (0x0A), CRLF (0x0D 0x0A)
            if ($ord <= 0x1F && $ord !== 0x09 && $ord !== 0x0A && $ord !== 0x0D) {
                throw new TomlParseException(
                    sprintf('Control character U+%04X is not allowed in TOML documents', $ord),
                    $line,
                    $column,
                    $toml
                );
            }

            // Carriage return is only valid when followed by newline (CRLF)
            if ($ord === 0x0D) {
                $nextOrd = ($i + 1 < $length) ? ord($toml[$i + 1]) : null;
                if ($nextOrd !== 0x0A) {
                    throw new TomlParseException(
                        'Bare carriage return (U+000D) is not allowed; must be followed by newline (CRLF)',
                        $line,
                        $column,
                        $toml
                    );
                }
            }

            // DEL character (0x7F) is also not allowed
            if ($ord === 0x7F) {
                throw new TomlParseException(
                    'Control character U+007F (DEL) is not allowed in TOML documents',
                    $line,
                    $column,
                    $toml
                );
            }

            // Track line and column for error reporting
            if ($toml[$i] === "\n") {
                $line++;
                $column = 1;
            } else {
                $column++;
            }
        }
    }
}
