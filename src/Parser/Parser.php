<?php

declare(strict_types=1);

namespace AppHive\Toml\Parser;

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Lexer\Lexer;
use AppHive\Toml\Lexer\Token;
use AppHive\Toml\Lexer\TokenType;

final class Parser
{
    /** @var list<Token> */
    private array $tokens;

    private int $position = 0;

    private string $source;

    /**
     * Current table path for key assignments.
     *
     * @var list<string>
     */
    private array $currentTablePath = [];

    /**
     * Track explicitly defined tables to prevent duplicates.
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $definedTables = [];

    /**
     * Track tables implicitly created by dotted keys within a table.
     * These cannot be redefined as explicit tables.
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $implicitDottedKeyTables = [];

    public function __construct(string $source)
    {
        $this->source = $source;
        $lexer = new Lexer($source);
        $this->tokens = $lexer->tokenize();
    }

    /**
     * Parse the TOML source and return an associative array.
     *
     * @return array<string, mixed>
     */
    public function parse(): array
    {
        $result = [];

        while (! $this->isAtEnd()) {
            $this->skipNewlines();

            if ($this->isAtEnd()) {
                break;
            }

            // Parse table header [table] or [table.subtable]
            if ($this->check(TokenType::LeftBracket)) {
                $this->parseTableHeader($result);

                continue;
            }

            // Parse key-value pair
            if ($this->check(TokenType::BareKey) || $this->check(TokenType::BasicString) || $this->check(TokenType::LiteralString)) {
                $this->parseKeyValue($result);
            } else {
                $token = $this->peek();
                throw new TomlParseException(
                    "Unexpected token: {$token->type->value}",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }
        }

        return $result;
    }

    /**
     * Parse a key-value pair and add it to the result array.
     *
     * @param  array<string, mixed>  $result
     */
    private function parseKeyValue(array &$result): void
    {
        $keyParts = $this->parseDottedKey();

        $this->expect(TokenType::Equals);

        $value = $this->parseValue();

        // Prepend current table path to key parts
        $fullKeyParts = array_merge($this->currentTablePath, $keyParts);

        // Track intermediate tables created by dotted keys for conflict detection
        if (count($keyParts) > 1) {
            $this->trackDottedKeyTables($keyParts);
        }

        $this->setNestedValue($result, $fullKeyParts, $value);

        // Expect newline or EOF after value
        if (! $this->isAtEnd() && ! $this->check(TokenType::Newline)) {
            $token = $this->peek();
            throw new TomlParseException(
                'Expected newline after value',
                $token->line,
                $token->column,
                $this->source
            );
        }

        $this->skipNewlines();
    }

    /**
     * Parse a table header [table] or [table.subtable].
     *
     * @param  array<string, mixed>  $result
     */
    private function parseTableHeader(array &$result): void
    {
        $startToken = $this->advance(); // consume '['

        // Parse the table key (may be dotted)
        $keyParts = $this->parseDottedKey();

        $this->expect(TokenType::RightBracket);

        // Expect newline or EOF after table header
        if (! $this->isAtEnd() && ! $this->check(TokenType::Newline)) {
            $token = $this->peek();
            throw new TomlParseException(
                'Expected newline after table header',
                $token->line,
                $token->column,
                $this->source
            );
        }

        $this->skipNewlines();

        // Build the table path string for duplicate detection
        $tablePath = implode('.', $keyParts);

        // Check if this table was already explicitly defined
        if (isset($this->definedTables[$tablePath])) {
            throw new TomlParseException(
                "Table '{$tablePath}' already defined",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Check if this path was created by dotted keys (cannot redefine)
        if (isset($this->implicitDottedKeyTables[$tablePath])) {
            throw new TomlParseException(
                "Cannot redefine table '{$tablePath}' that was implicitly defined by dotted keys",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Mark this table as explicitly defined
        $this->definedTables[$tablePath] = true;

        // Ensure the table path exists in the result, creating intermediate tables as needed
        $this->ensureTableExists($result, $keyParts, $startToken);

        // Set current table path for subsequent key-value pairs
        $this->currentTablePath = $keyParts;
    }

    /**
     * Ensure the table path exists in the result array.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keyParts
     */
    private function ensureTableExists(array &$result, array $keyParts, Token $token): void
    {
        $current = &$result;

        for ($i = 0; $i < count($keyParts); $i++) {
            $key = $keyParts[$i];

            if (! isset($current[$key])) {
                $current[$key] = [];
            } elseif (! is_array($current[$key])) {
                // Trying to define a table where a scalar value exists
                throw new TomlParseException(
                    "Cannot redefine key '{$key}' as a table because it is not a table",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }

            $current = &$current[$key];
        }
    }

    /**
     * Track tables implicitly created by dotted keys within the current table.
     *
     * @param  list<string>  $keyParts  The dotted key parts (without table prefix)
     */
    private function trackDottedKeyTables(array $keyParts): void
    {
        // Track all intermediate paths created by dotted keys
        // For a.b.c = value, we track: currentTable.a and currentTable.a.b
        $basePath = $this->currentTablePath;

        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $basePath[] = $keyParts[$i];
            $pathStr = implode('.', $basePath);
            $this->implicitDottedKeyTables[$pathStr] = true;
        }
    }

    /**
     * Parse a dotted key (e.g., "physical.color" or "site.'google.com'").
     *
     * @return list<string>
     */
    private function parseDottedKey(): array
    {
        $parts = [];
        $parts[] = $this->parseSimpleKey();

        while ($this->check(TokenType::Dot)) {
            $this->advance(); // consume the dot
            $parts[] = $this->parseSimpleKey();
        }

        return $parts;
    }

    /**
     * Parse a simple key (bare key or quoted string).
     */
    private function parseSimpleKey(): string
    {
        $token = $this->peek();

        if ($token->type === TokenType::BareKey) {
            $this->advance();

            return $token->value;
        }

        if ($token->type === TokenType::BasicString || $token->type === TokenType::LiteralString) {
            $this->advance();

            return $token->value;
        }

        throw new TomlParseException(
            "Expected key, got {$token->type->value}",
            $token->line,
            $token->column,
            $this->source
        );
    }

    /**
     * Set a value in a nested array structure, creating intermediate arrays as needed.
     *
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keyParts
     *
     * @throws TomlParseException
     */
    private function setNestedValue(array &$array, array $keyParts, mixed $value): void
    {
        $current = &$array;

        // Navigate/create intermediate tables
        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $key = $keyParts[$i];

            if (! isset($current[$key])) {
                $current[$key] = [];
            } elseif (! is_array($current[$key])) {
                // Trying to use a scalar value as a table
                $token = $this->peek();
                throw new TomlParseException(
                    "Cannot define key '{$keyParts[$i + 1]}' because '{$key}' is not a table",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }

            $current = &$current[$key];
        }

        // Set the final value
        $finalKey = $keyParts[count($keyParts) - 1];

        if (isset($current[$finalKey])) {
            $token = $this->peek();
            throw new TomlParseException(
                "Cannot redefine key '{$finalKey}'",
                $token->line,
                $token->column,
                $this->source
            );
        }

        $current[$finalKey] = $value;
    }

    /**
     * Parse a value (integer, float, string, boolean, inline table, etc.).
     */
    private function parseValue(): mixed
    {
        $token = $this->peek();

        return match ($token->type) {
            TokenType::Integer => $this->parseInteger(),
            TokenType::Float => $this->parseFloat(),
            TokenType::Boolean => $this->parseBoolean(),
            TokenType::BasicString,
            TokenType::LiteralString,
            TokenType::MultilineBasicString,
            TokenType::MultilineLiteralString => $this->parseString(),
            TokenType::OffsetDateTime,
            TokenType::LocalDateTime,
            TokenType::LocalDate,
            TokenType::LocalTime => $this->parseDateTime(),
            TokenType::LeftBrace => $this->parseInlineTable(),
            default => throw new TomlParseException(
                "Unexpected value type: {$token->type->value}",
                $token->line,
                $token->column,
                $this->source
            ),
        };
    }

    /**
     * Parse an integer value.
     * Handles decimal, hexadecimal, octal, and binary formats.
     * Returns a string for values exceeding PHP_INT_MAX.
     */
    private function parseInteger(): int|string
    {
        $token = $this->advance();
        $value = $token->value;

        // Check for leading zeros in decimal integers (invalid in TOML)
        $this->validateNoLeadingZeros($value, $token);

        // Determine the base and parse accordingly
        if (str_starts_with($value, '0x') || str_starts_with($value, '0X')) {
            return $this->parseHexInteger($value);
        }

        if (str_starts_with($value, '0o') || str_starts_with($value, '0O')) {
            return $this->parseOctalInteger($value);
        }

        if (str_starts_with($value, '0b') || str_starts_with($value, '0B')) {
            return $this->parseBinaryInteger($value);
        }

        return $this->parseDecimalInteger($value);
    }

    /**
     * Validate that decimal integers don't have leading zeros.
     */
    private function validateNoLeadingZeros(string $value, Token $token): void
    {
        // Remove sign if present
        $unsigned = $value;
        if (str_starts_with($value, '+') || str_starts_with($value, '-')) {
            $unsigned = substr($value, 1);
        }

        // Skip validation for hex, octal, binary prefixes
        if (str_starts_with($unsigned, '0x') || str_starts_with($unsigned, '0X') ||
            str_starts_with($unsigned, '0o') || str_starts_with($unsigned, '0O') ||
            str_starts_with($unsigned, '0b') || str_starts_with($unsigned, '0B')) {
            return;
        }

        // Check for leading zeros: length > 1 and starts with 0
        if (strlen($unsigned) > 1 && $unsigned[0] === '0') {
            throw new TomlParseException(
                'Leading zeros are not allowed in decimal integers',
                $token->line,
                $token->column,
                $this->source
            );
        }
    }

    /**
     * Parse a decimal integer, handling overflow.
     */
    private function parseDecimalInteger(string $value): int|string
    {
        // Handle sign
        $sign = '';
        if (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '-')) {
            $sign = '-';
            $value = substr($value, 1);
        }

        // Check if value exceeds PHP_INT_MAX
        $fullValue = $sign.$value;

        if ($this->exceedsIntRange($fullValue)) {
            return $fullValue;
        }

        return (int) $fullValue;
    }

    /**
     * Parse a hexadecimal integer.
     */
    private function parseHexInteger(string $value): int|string
    {
        // Remove 0x prefix
        $hex = substr($value, 2);

        // Check for overflow (max hex value for 64-bit is 16 hex digits)
        if (strlen($hex) > 16 || (strlen($hex) === 16 && strtolower($hex) > '7fffffffffffffff')) {
            // Return as string, but convert to decimal representation
            $decimal = gmp_strval(gmp_init($hex, 16), 10);

            return $decimal;
        }

        return (int) hexdec($hex);
    }

    /**
     * Parse an octal integer.
     */
    private function parseOctalInteger(string $value): int|string
    {
        // Remove 0o prefix
        $octal = substr($value, 2);

        // Check for overflow
        $decimal = gmp_strval(gmp_init($octal, 8), 10);

        if ($this->exceedsIntRange($decimal)) {
            return $decimal;
        }

        return (int) octdec($octal);
    }

    /**
     * Parse a binary integer.
     */
    private function parseBinaryInteger(string $value): int|string
    {
        // Remove 0b prefix
        $binary = substr($value, 2);

        // Check for overflow (max binary value for 64-bit is 63 bits for positive)
        if (strlen($binary) > 63) {
            $decimal = gmp_strval(gmp_init($binary, 2), 10);

            return $decimal;
        }

        return (int) bindec($binary);
    }

    /**
     * Check if a decimal string value exceeds PHP integer range.
     */
    private function exceedsIntRange(string $value): bool
    {
        $isNegative = str_starts_with($value, '-');
        $absValue = $isNegative ? substr($value, 1) : $value;

        // PHP_INT_MAX = 9223372036854775807
        // PHP_INT_MIN = -9223372036854775808
        $maxStr = $isNegative ? '9223372036854775808' : '9223372036854775807';

        if (strlen($absValue) > strlen($maxStr)) {
            return true;
        }

        if (strlen($absValue) < strlen($maxStr)) {
            return false;
        }

        return $absValue > $maxStr;
    }

    /**
     * Parse a string value.
     */
    private function parseString(): string
    {
        $token = $this->advance();

        return $token->value;
    }

    /**
     * Parse a boolean value.
     */
    private function parseBoolean(): bool
    {
        $token = $this->advance();

        return $token->value === 'true';
    }

    /**
     * Parse a date-time value (offset or local).
     * Returns the value as a string, preserving the original format.
     */
    private function parseDateTime(): string
    {
        $token = $this->advance();

        return $token->value;
    }

    /**
     * Parse an inline table: { key = value, ... }
     * TOML 1.1.0 allows trailing commas and newlines within inline tables.
     * Inline tables are fully defined inline and cannot be extended.
     *
     * @return array<string, mixed>
     */
    private function parseInlineTable(): array
    {
        $startToken = $this->advance(); // consume '{'
        $result = [];

        // Skip any whitespace and newlines after opening brace (TOML 1.1.0)
        $this->skipInlineTableWhitespace();

        // Check for empty inline table
        if ($this->check(TokenType::RightBrace)) {
            $this->advance();

            return $result;
        }

        // Parse key-value pairs
        while (true) {
            // Parse key (may be dotted)
            $keyParts = $this->parseDottedKey();

            $this->expect(TokenType::Equals);

            $value = $this->parseValue();

            // Set the value in the result, handling dotted keys
            $this->setInlineTableValue($result, $keyParts, $value, $startToken);

            // Skip whitespace and newlines after value (TOML 1.1.0)
            $this->skipInlineTableWhitespace();

            // Check for comma or closing brace
            if ($this->check(TokenType::Comma)) {
                $this->advance(); // consume comma
                // Skip whitespace and newlines after comma (TOML 1.1.0)
                $this->skipInlineTableWhitespace();

                // Allow trailing comma (TOML 1.1.0)
                if ($this->check(TokenType::RightBrace)) {
                    $this->advance();

                    return $result;
                }
            } elseif ($this->check(TokenType::RightBrace)) {
                $this->advance();

                return $result;
            } else {
                $token = $this->peek();
                throw new TomlParseException(
                    "Expected ',' or '}' in inline table, got {$token->type->value}",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }
        }
    }

    /**
     * Skip whitespace and newlines within inline tables (TOML 1.1.0 feature).
     */
    private function skipInlineTableWhitespace(): void
    {
        while (! $this->isAtEnd() && $this->check(TokenType::Newline)) {
            $this->advance();
        }
    }

    /**
     * Set a value in an inline table, handling dotted keys.
     *
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keyParts
     *
     * @throws TomlParseException
     */
    private function setInlineTableValue(array &$array, array $keyParts, mixed $value, Token $startToken): void
    {
        $current = &$array;

        // Navigate/create intermediate tables
        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $key = $keyParts[$i];

            if (! isset($current[$key])) {
                $current[$key] = [];
            } elseif (! is_array($current[$key])) {
                throw new TomlParseException(
                    "Cannot define key '{$keyParts[$i + 1]}' because '{$key}' is not a table",
                    $startToken->line,
                    $startToken->column,
                    $this->source
                );
            }

            $current = &$current[$key];
        }

        // Set the final value
        $finalKey = $keyParts[count($keyParts) - 1];

        if (isset($current[$finalKey])) {
            throw new TomlParseException(
                "Cannot redefine key '{$finalKey}' in inline table",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        $current[$finalKey] = $value;
    }

    /**
     * Parse a float value.
     * Handles decimal floats, exponent notation, and special values (inf, nan).
     */
    private function parseFloat(): float
    {
        $token = $this->advance();
        $value = $token->value;

        // Validate no leading zeros (same rule as integers, but for the integer part of floats)
        $this->validateFloatNoLeadingZeros($value, $token);

        // Handle special values
        if ($value === 'inf' || $value === '+inf') {
            return INF;
        }
        if ($value === '-inf') {
            return -INF;
        }
        if ($value === 'nan' || $value === '+nan' || $value === '-nan') {
            return NAN;
        }

        return (float) $value;
    }

    /**
     * Validate that the integer part of a float doesn't have leading zeros.
     */
    private function validateFloatNoLeadingZeros(string $value, Token $token): void
    {
        // Skip special values
        if (in_array($value, ['inf', '+inf', '-inf', 'nan', '+nan', '-nan'], true)) {
            return;
        }

        // Remove sign if present
        $unsigned = $value;
        if (str_starts_with($value, '+') || str_starts_with($value, '-')) {
            $unsigned = substr($value, 1);
        }

        // Extract the integer part (before decimal point or exponent)
        $integerPart = $unsigned;
        $dotPos = strpos($unsigned, '.');
        $ePos = stripos($unsigned, 'e');

        if ($dotPos !== false) {
            $integerPart = substr($unsigned, 0, $dotPos);
        } elseif ($ePos !== false) {
            $integerPart = substr($unsigned, 0, $ePos);
        }

        // Check for leading zeros: length > 1 and starts with 0
        if (strlen($integerPart) > 1 && $integerPart[0] === '0') {
            throw new TomlParseException(
                'Leading zeros are not allowed in floats',
                $token->line,
                $token->column,
                $this->source
            );
        }
    }

    /**
     * Skip newline tokens.
     */
    private function skipNewlines(): void
    {
        while (! $this->isAtEnd() && $this->check(TokenType::Newline)) {
            $this->advance();
        }
    }

    /**
     * Check if we've reached the end of tokens.
     */
    private function isAtEnd(): bool
    {
        return $this->peek()->type === TokenType::Eof;
    }

    /**
     * Check if the current token matches the given type.
     */
    private function check(TokenType $type): bool
    {
        return $this->peek()->type === $type;
    }

    /**
     * Get the current token without advancing.
     */
    private function peek(): Token
    {
        return $this->tokens[$this->position];
    }

    /**
     * Advance to the next token and return the previous one.
     */
    private function advance(): Token
    {
        $token = $this->tokens[$this->position];
        if (! $this->isAtEnd()) {
            $this->position++;
        }

        return $token;
    }

    /**
     * Expect a specific token type and advance.
     */
    private function expect(TokenType $type): Token
    {
        $token = $this->peek();

        if ($token->type !== $type) {
            throw new TomlParseException(
                "Expected {$type->value}, got {$token->type->value}",
                $token->line,
                $token->column,
                $this->source
            );
        }

        return $this->advance();
    }
}
