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
        // First validate UTF-8 encoding before any other checks
        // This catches malformed byte sequences, overlong encodings, and surrogates
        self::validateUtf8Encoding($toml);

        // Then check for invalid control characters before any trimming
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

    /**
     * Validate that the input string is valid UTF-8.
     *
     * This checks for:
     * - Invalid byte sequences (unexpected continuation bytes, incomplete sequences)
     * - Overlong encodings (characters encoded with more bytes than necessary)
     * - Surrogate code points (U+D800 to U+DFFF, which are invalid in UTF-8)
     * - Invalid lead bytes (0xFE, 0xFF)
     *
     * @throws TomlParseException When invalid UTF-8 encoding is found
     */
    private static function validateUtf8Encoding(string $toml): void
    {
        $length = strlen($toml);
        $line = 1;
        $column = 1;
        $i = 0;

        while ($i < $length) {
            $byte = ord($toml[$i]);

            // ASCII characters (0x00-0x7F) are valid single-byte UTF-8
            if ($byte <= 0x7F) {
                if ($toml[$i] === "\n") {
                    $line++;
                    $column = 1;
                } else {
                    $column++;
                }
                $i++;

                continue;
            }

            // Determine expected sequence length and validate lead byte
            // Use match expression for PHPStan compatibility
            $sequenceInfo = self::getUtf8SequenceInfo($byte, $line, $column, $toml);
            $sequenceLength = $sequenceInfo['length'];
            $minCodePoint = $sequenceInfo['minCodePoint'];
            $codePoint = $sequenceInfo['initialCodePoint'];

            // Check we have enough bytes remaining
            if ($i + $sequenceLength > $length) {
                throw new TomlParseException(
                    sprintf('Invalid UTF-8 encoding: incomplete %d-byte sequence at end of input', $sequenceLength),
                    $line,
                    $column,
                    $toml
                );
            }

            // Process continuation bytes and build code point
            for ($j = 1; $j < $sequenceLength; $j++) {
                $contByte = ord($toml[$i + $j]);

                // Continuation bytes must be in range 0x80-0xBF
                if ($contByte < 0x80 || $contByte > 0xBF) {
                    throw new TomlParseException(
                        sprintf('Invalid UTF-8 encoding: expected continuation byte, got 0x%02X', $contByte),
                        $line,
                        $column,
                        $toml
                    );
                }

                $codePoint = ($codePoint << 6) | ($contByte & 0x3F);
            }

            // Check for overlong encoding
            if ($codePoint < $minCodePoint) {
                throw new TomlParseException(
                    sprintf(
                        'Invalid UTF-8 encoding: overlong encoding for code point U+%04X',
                        $codePoint
                    ),
                    $line,
                    $column,
                    $toml
                );
            }

            // Check for surrogate code points (U+D800 to U+DFFF)
            if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
                throw new TomlParseException(
                    sprintf(
                        'Invalid UTF-8 encoding: surrogate code point U+%04X is not allowed',
                        $codePoint
                    ),
                    $line,
                    $column,
                    $toml
                );
            }

            // Check for code points beyond U+10FFFF
            if ($codePoint > 0x10FFFF) {
                throw new TomlParseException(
                    sprintf(
                        'Invalid UTF-8 encoding: code point U+%X exceeds maximum U+10FFFF',
                        $codePoint
                    ),
                    $line,
                    $column,
                    $toml
                );
            }

            // Advance past the full sequence
            $column++;
            $i += $sequenceLength;
        }
    }

    /**
     * Get UTF-8 sequence information for a lead byte.
     *
     * @return array{length: int, minCodePoint: int, initialCodePoint: int}
     *
     * @throws TomlParseException When the lead byte is invalid
     */
    private static function getUtf8SequenceInfo(int $byte, int $line, int $column, string $toml): array
    {
        // Continuation bytes (0x80-0xBF) without a lead byte are invalid
        if ($byte <= 0xBF) {
            throw new TomlParseException(
                sprintf('Invalid UTF-8 encoding: unexpected continuation byte 0x%02X', $byte),
                $line,
                $column,
                $toml
            );
        }

        // 0xC0 and 0xC1 lead to overlong encodings (code points < 0x80)
        if ($byte <= 0xC1) {
            throw new TomlParseException(
                sprintf('Invalid UTF-8 encoding: overlong sequence starting with 0x%02X', $byte),
                $line,
                $column,
                $toml
            );
        }

        // 2-byte sequence: 110xxxxx 10xxxxxx (0xC2-0xDF)
        if ($byte <= 0xDF) {
            return [
                'length' => 2,
                'minCodePoint' => 0x80,
                'initialCodePoint' => $byte & 0x1F,
            ];
        }

        // 3-byte sequence: 1110xxxx 10xxxxxx 10xxxxxx (0xE0-0xEF)
        if ($byte <= 0xEF) {
            return [
                'length' => 3,
                'minCodePoint' => 0x800,
                'initialCodePoint' => $byte & 0x0F,
            ];
        }

        // 4-byte sequence: 11110xxx 10xxxxxx 10xxxxxx 10xxxxxx (0xF0-0xF4)
        if ($byte <= 0xF4) {
            return [
                'length' => 4,
                'minCodePoint' => 0x10000,
                'initialCodePoint' => $byte & 0x07,
            ];
        }

        // 0xF5-0xFD would encode code points > U+10FFFF, 0xFE-0xFF are never valid
        throw new TomlParseException(
            sprintf('Invalid UTF-8 encoding: byte 0x%02X is not valid in UTF-8', $byte),
            $line,
            $column,
            $toml
        );
    }
}
