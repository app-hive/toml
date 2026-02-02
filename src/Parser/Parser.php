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

    /**
     * Track which paths are array of tables (not regular tables).
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $arrayOfTablesPaths = [];

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

            // Parse table header [table] or [table.subtable] or array of tables [[table]]
            if ($this->check(TokenType::LeftBracket)) {
                // Check if this is an array of tables [[...]]
                if ($this->isArrayOfTables()) {
                    $this->parseArrayOfTablesHeader($result);
                } else {
                    $this->parseTableHeader($result);
                }

                continue;
            }

            // Parse key-value pair
            if ($this->isKeyToken()) {
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
     * Check if the next tokens form an array of tables header [[...]].
     */
    private function isArrayOfTables(): bool
    {
        // We're at '[', check if next token is also '['
        $savedPosition = $this->position;
        $this->advance(); // consume first '['

        $isArray = $this->check(TokenType::LeftBracket);

        // Restore position
        $this->position = $savedPosition;

        return $isArray;
    }

    /**
     * Parse an array of tables header [[table]] or [[table.subtable]].
     *
     * @param  array<string, mixed>  $result
     */
    private function parseArrayOfTablesHeader(array &$result): void
    {
        $startToken = $this->advance(); // consume first '['
        $this->advance(); // consume second '['

        // Parse the table key (may be dotted)
        $keyParts = $this->parseDottedKey();

        $this->expect(TokenType::RightBracket);
        $this->expect(TokenType::RightBracket);

        // Expect newline or EOF after array of tables header
        if (! $this->isAtEnd() && ! $this->check(TokenType::Newline)) {
            $token = $this->peek();
            throw new TomlParseException(
                'Expected newline after array of tables header',
                $token->line,
                $token->column,
                $this->source
            );
        }

        $this->skipNewlines();

        // Build the table path string
        $tablePath = implode('.', $keyParts);

        // Check if this path was already defined as a regular table
        if (isset($this->definedTables[$tablePath]) && ! isset($this->arrayOfTablesPaths[$tablePath])) {
            throw new TomlParseException(
                "Cannot define array of tables '{$tablePath}' because it was already defined as a table",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Check if this path was created by dotted keys (cannot redefine)
        if (isset($this->implicitDottedKeyTables[$tablePath])) {
            throw new TomlParseException(
                "Cannot redefine '{$tablePath}' that was implicitly defined by dotted keys",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Mark this path as an array of tables
        $this->arrayOfTablesPaths[$tablePath] = true;

        // Add a new element to the array
        $this->addArrayOfTablesElement($result, $keyParts, $startToken);

        // Set current table path for subsequent key-value pairs
        // For array of tables, we point to the current element
        $this->currentTablePath = $keyParts;
    }

    /**
     * Add a new element to an array of tables.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keyParts
     */
    private function addArrayOfTablesElement(array &$result, array $keyParts, Token $token): void
    {
        /** @var array<string, mixed> $current */
        $current = &$result;

        // Navigate to the parent of the final key, handling array of tables along the way
        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $key = $keyParts[$i];
            $partialPath = implode('.', array_slice($keyParts, 0, $i + 1));

            if (! isset($current[$key])) {
                $current[$key] = [];
            }

            // If this path is an array of tables, navigate to the last element
            if (isset($this->arrayOfTablesPaths[$partialPath])) {
                /** @var array<int, array<string, mixed>> $arrayValue */
                $arrayValue = &$current[$key];
                if (empty($arrayValue)) {
                    throw new TomlParseException(
                        'Cannot define nested array of tables under non-array',
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
                // Navigate to the last element of this array
                $lastIndex = count($arrayValue) - 1;
                $current = &$arrayValue[$lastIndex];
            } else {
                /** @var mixed $keyValue */
                $keyValue = $current[$key];
                if (! is_array($keyValue)) {
                    throw new TomlParseException(
                        "Cannot define key '{$key}' as a table because it is not a table",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
                /** @var array<string, mixed> $nextCurrent */
                $nextCurrent = &$current[$key];
                $current = &$nextCurrent;
            }
        }

        // Now handle the final key - this is where we add the new array element
        $finalKey = $keyParts[count($keyParts) - 1];

        if (! isset($current[$finalKey])) {
            $current[$finalKey] = [];
        } else {
            /** @var mixed $finalValue */
            $finalValue = $current[$finalKey];
            if (! is_array($finalValue)) {
                throw new TomlParseException(
                    "Cannot define '{$finalKey}' as array of tables because it is not an array",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }
        }

        // Add a new element to the array
        /** @var array<int, array<string, mixed>> $arrayTarget */
        $arrayTarget = &$current[$finalKey];
        $arrayTarget[] = [];
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

        // Check if this path was already defined as an array of tables
        if (isset($this->arrayOfTablesPaths[$tablePath])) {
            throw new TomlParseException(
                "Cannot define table '{$tablePath}' because it was already defined as an array of tables",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

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
        $this->ensureTableExistsForTable($result, $keyParts, $startToken);

        // Set current table path for subsequent key-value pairs
        $this->currentTablePath = $keyParts;
    }

    /**
     * Ensure the table path exists for a standard [table] header.
     * Handles navigation through array of tables when needed.
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>  $keyParts
     */
    private function ensureTableExistsForTable(array &$result, array $keyParts, Token $token): void
    {
        /** @var array<string, mixed> $current */
        $current = &$result;

        for ($i = 0; $i < count($keyParts); $i++) {
            $key = $keyParts[$i];
            $partialPath = implode('.', array_slice($keyParts, 0, $i + 1));

            if (! isset($current[$key])) {
                $current[$key] = [];
            } else {
                /** @var mixed $keyValue */
                $keyValue = $current[$key];
                if (! is_array($keyValue)) {
                    // Trying to define a table where a scalar value exists
                    throw new TomlParseException(
                        "Cannot redefine key '{$key}' as a table because it is not a table",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
            }

            // If this partial path is an array of tables, navigate to the last element
            if (isset($this->arrayOfTablesPaths[$partialPath])) {
                /** @var array<int, array<string, mixed>> $arrayValue */
                $arrayValue = &$current[$key];
                if (empty($arrayValue)) {
                    throw new TomlParseException(
                        "Cannot define sub-table under empty array of tables '{$partialPath}'",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
                // Navigate to the last element
                $lastIndex = count($arrayValue) - 1;
                $current = &$arrayValue[$lastIndex];
            } else {
                /** @var array<string, mixed> $nextCurrent */
                $nextCurrent = &$current[$key];
                $current = &$nextCurrent;
            }
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
     * Float tokens like "1.2" are treated as dotted keys "1"."2" in key context.
     *
     * @return list<string>
     */
    private function parseDottedKey(): array
    {
        $parts = [];

        // Handle first key part - may be a Float which needs splitting
        $this->parseKeyParts($parts);

        while ($this->check(TokenType::Dot)) {
            $this->advance(); // consume the dot
            $this->parseKeyParts($parts);
        }

        return $parts;
    }

    /**
     * Parse key parts and add them to the parts array.
     * Float tokens are split by '.' into multiple key parts.
     *
     * @param  list<string>  $parts
     */
    private function parseKeyParts(array &$parts): void
    {
        $token = $this->peek();

        // Float tokens in key context are dotted keys (e.g., "1.2" = "1"."2")
        if ($token->type === TokenType::Float) {
            $this->advance();
            $floatParts = explode('.', $token->value);
            foreach ($floatParts as $part) {
                $parts[] = $part;
            }

            return;
        }

        $parts[] = $this->parseSimpleKey();
    }

    /**
     * Parse a simple key (bare key, quoted string, or numeric value).
     * Numeric values are allowed as bare keys in TOML and are stored as strings.
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

        // Numeric tokens (Integer, Float) are valid as bare keys
        // They are stored as strings, preserving the original representation
        if ($token->type === TokenType::Integer || $token->type === TokenType::Float) {
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
     * Handles array of tables by navigating to the last element of each array.
     *
     * @param  array<string, mixed>  $array
     * @param  list<string>  $keyParts
     *
     * @throws TomlParseException
     */
    private function setNestedValue(array &$array, array $keyParts, mixed $value): void
    {
        /** @var array<string, mixed> $current */
        $current = &$array;

        // Navigate/create intermediate tables
        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $key = $keyParts[$i];
            $partialPath = implode('.', array_slice($keyParts, 0, $i + 1));

            if (! isset($current[$key])) {
                $current[$key] = [];
            } else {
                /** @var mixed $keyValue */
                $keyValue = $current[$key];
                if (! is_array($keyValue)) {
                    // Trying to use a scalar value as a table
                    $token = $this->peek();
                    throw new TomlParseException(
                        "Cannot define key '{$keyParts[$i + 1]}' because '{$key}' is not a table",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
            }

            // If this partial path is an array of tables, navigate to the last element
            if (isset($this->arrayOfTablesPaths[$partialPath])) {
                /** @var array<int, array<string, mixed>> $arrayValue */
                $arrayValue = &$current[$key];
                if (empty($arrayValue)) {
                    $token = $this->peek();
                    throw new TomlParseException(
                        "Cannot set value under empty array of tables '{$partialPath}'",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
                // Navigate to the last element
                $lastIndex = count($arrayValue) - 1;
                $current = &$arrayValue[$lastIndex];
            } else {
                /** @var array<string, mixed> $nextCurrent */
                $nextCurrent = &$current[$key];
                $current = &$nextCurrent;
            }
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
            TokenType::LeftBracket => $this->parseArray(),
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
     * Normalizes the value to RFC 3339 format:
     * - Space separator → T
     * - Lowercase t → T
     * - Lowercase z → Z
     * - Missing seconds → :00
     * - Fractional seconds < 3 digits → pad to 3 digits
     */
    private function parseDateTime(): string
    {
        $token = $this->advance();

        return $this->normalizeDateTimeValue($token->value, $token->type);
    }

    /**
     * Normalize a datetime string to RFC 3339 format.
     */
    private function normalizeDateTimeValue(string $value, TokenType $type): string
    {
        // LocalDate doesn't need normalization (YYYY-MM-DD)
        if ($type === TokenType::LocalDate) {
            return $value;
        }

        // LocalTime: ensure HH:MM:SS format
        if ($type === TokenType::LocalTime) {
            return $this->normalizeTimeComponent($value);
        }

        // LocalDateTime and OffsetDateTime: normalize separator, time, and timezone
        return $this->normalizeFullDateTime($value, $type);
    }

    /**
     * Normalize a time component (HH:MM:SS or HH:MM with optional fractional seconds).
     * Preserves original fractional second precision as per TOML spec.
     */
    private function normalizeTimeComponent(string $time): string
    {
        // Check if we have fractional seconds
        if (str_contains($time, '.')) {
            [$timePart, $fraction] = explode('.', $time, 2);
            $normalizedTime = $this->ensureSeconds($timePart);

            // Preserve original precision (don't pad fractional seconds)
            return $normalizedTime.'.'.$fraction;
        }

        return $this->ensureSeconds($time);
    }

    /**
     * Ensure a time has seconds (HH:MM → HH:MM:00).
     */
    private function ensureSeconds(string $time): string
    {
        // If time is HH:MM (5 chars), add :00
        if (strlen($time) === 5 && preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }

        return $time;
    }

    /**
     * Normalize a full datetime (LocalDateTime or OffsetDateTime).
     */
    private function normalizeFullDateTime(string $value, TokenType $type): string
    {
        // Split into date, time, and timezone parts
        // Format: YYYY-MM-DD[T or t or space]HH:MM[:SS][.fraction][Z or z or +/-HH:MM]

        // First, extract the date (first 10 chars: YYYY-MM-DD)
        $date = substr($value, 0, 10);

        // The separator is at position 10
        // Normalize to 'T'
        $normalized = $date.'T';

        // Extract the rest (after separator)
        $rest = substr($value, 11);

        // Handle timezone for OffsetDateTime
        $timezone = '';
        if ($type === TokenType::OffsetDateTime) {
            // Find timezone: Z, z, +HH:MM, or -HH:MM
            if (preg_match('/([Zz]|[+-]\d{2}:\d{2})$/', $rest, $matches)) {
                $timezone = $matches[1];
                $rest = substr($rest, 0, -strlen($timezone));
                // Normalize lowercase z to Z
                if ($timezone === 'z') {
                    $timezone = 'Z';
                }
            }
        }

        // Normalize the time part
        $normalizedTime = $this->normalizeTimeComponent($rest);

        return $normalized.$normalizedTime.$timezone;
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
     * Parse an array: [ value, value, ... ]
     * TOML allows mixed types in arrays.
     * TOML 1.1.0 allows trailing commas and newlines within arrays.
     *
     * @return list<mixed>
     */
    private function parseArray(): array
    {
        $startToken = $this->advance(); // consume '['
        $result = [];

        // Skip any whitespace and newlines after opening bracket
        $this->skipArrayWhitespace();

        // Check for empty array
        if ($this->check(TokenType::RightBracket)) {
            $this->advance();

            return $result;
        }

        // Parse array elements
        while (true) {
            $value = $this->parseValue();
            $result[] = $value;

            // Skip whitespace and newlines after value
            $this->skipArrayWhitespace();

            // Check for comma or closing bracket
            if ($this->check(TokenType::Comma)) {
                $this->advance(); // consume comma
                // Skip whitespace and newlines after comma
                $this->skipArrayWhitespace();

                // Allow trailing comma
                if ($this->check(TokenType::RightBracket)) {
                    $this->advance();

                    return $result;
                }
            } elseif ($this->check(TokenType::RightBracket)) {
                $this->advance();

                return $result;
            } else {
                $token = $this->peek();
                throw new TomlParseException(
                    "Expected ',' or ']' in array, got {$token->type->value}",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }
        }
    }

    /**
     * Skip whitespace and newlines within arrays (TOML 1.1.0 feature).
     */
    private function skipArrayWhitespace(): void
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
     * Check if the current token can be used as a key.
     * In TOML, keys can be bare keys, quoted strings, or numeric values.
     */
    private function isKeyToken(): bool
    {
        $type = $this->peek()->type;

        return $type === TokenType::BareKey
            || $type === TokenType::BasicString
            || $type === TokenType::LiteralString
            || $type === TokenType::Integer
            || $type === TokenType::Float;
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
