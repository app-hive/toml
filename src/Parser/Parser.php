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
     * @param array<string, mixed> $result
     */
    private function parseKeyValue(array &$result): void
    {
        $key = $this->parseKey();

        $this->expect(TokenType::Equals);

        $value = $this->parseValue();

        $result[$key] = $value;

        // Expect newline or EOF after value
        if (! $this->isAtEnd() && ! $this->check(TokenType::Newline)) {
            $token = $this->peek();
            throw new TomlParseException(
                "Expected newline after value",
                $token->line,
                $token->column,
                $this->source
            );
        }

        $this->skipNewlines();
    }

    /**
     * Parse a key (bare key or quoted string).
     */
    private function parseKey(): string
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
     * Parse a value (integer, float, string, boolean, etc.).
     */
    private function parseValue(): mixed
    {
        $token = $this->peek();

        return match ($token->type) {
            TokenType::Integer => $this->parseInteger(),
            TokenType::BasicString,
            TokenType::LiteralString,
            TokenType::MultilineBasicString,
            TokenType::MultilineLiteralString => $this->parseString(),
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
