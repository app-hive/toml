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
}
