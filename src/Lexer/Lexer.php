<?php

declare(strict_types=1);

namespace AppHive\Toml\Lexer;

use AppHive\Toml\Exceptions\TomlParseException;

final class Lexer
{
    private string $source;

    private int $position = 0;

    private int $line = 1;

    private int $column = 1;

    private int $length;

    public function __construct(string $source)
    {
        $this->source = $source;
        $this->length = strlen($source);
    }

    /**
     * Tokenize the entire source and return all tokens.
     *
     * @return list<Token>
     */
    public function tokenize(): array
    {
        $tokens = [];

        while (! $this->isAtEnd()) {
            $token = $this->nextToken();
            if ($token !== null) {
                $tokens[] = $token;
            }
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->line, $this->column);

        return $tokens;
    }

    private function nextToken(): ?Token
    {
        $this->skipWhitespace();

        if ($this->isAtEnd()) {
            return null;
        }

        $char = $this->peek();

        // Handle comments
        if ($char === '#') {
            $this->skipComment();

            return null;
        }

        // Handle newlines
        if ($char === "\n") {
            return $this->scanNewline();
        }

        // Handle carriage return (CRLF)
        if ($char === "\r") {
            $this->advance();
            if ($this->peek() === "\n") {
                $line = $this->line;
                $column = $this->column - 1;
                $this->advance();
                $this->line++;
                $this->column = 1;

                return new Token(TokenType::Newline, "\r\n", $line, $column);
            }

            // Bare CR is treated as whitespace, skip it
            return null;
        }

        // Structural tokens
        return match ($char) {
            '[' => $this->scanSingleChar(TokenType::LeftBracket),
            ']' => $this->scanSingleChar(TokenType::RightBracket),
            '{' => $this->scanSingleChar(TokenType::LeftBrace),
            '}' => $this->scanSingleChar(TokenType::RightBrace),
            '=' => $this->scanSingleChar(TokenType::Equals),
            '.' => $this->scanSingleChar(TokenType::Dot),
            ',' => $this->scanSingleChar(TokenType::Comma),
            '"' => $this->scanString(),
            "'" => $this->scanLiteralString(),
            default => $this->scanValue(),
        };
    }

    private function scanSingleChar(TokenType $type): Token
    {
        $line = $this->line;
        $column = $this->column;
        $char = $this->advance();

        return new Token($type, $char, $line, $column);
    }

    private function scanNewline(): Token
    {
        $line = $this->line;
        $column = $this->column;
        $this->advance();
        $this->line++;
        $this->column = 1;

        return new Token(TokenType::Newline, "\n", $line, $column);
    }

    private function scanString(): Token
    {
        $line = $this->line;
        $column = $this->column;

        // Check for multiline string
        if ($this->lookAhead(1) === '"' && $this->lookAhead(2) === '"') {
            return $this->scanMultilineBasicString($line, $column);
        }

        return $this->scanBasicString($line, $column);
    }

    private function scanBasicString(int $line, int $column): Token
    {
        $this->advance(); // consume opening quote
        $value = '';

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            assert($char !== null);

            if ($char === '"') {
                $this->advance();

                return new Token(TokenType::BasicString, $value, $line, $column);
            }

            if ($char === "\n" || $char === "\r") {
                throw new TomlParseException(
                    'Unterminated basic string',
                    $this->line,
                    $this->column,
                    $this->source
                );
            }

            if ($char === '\\') {
                $value .= $this->scanEscapeSequence();
            } else {
                $this->validateControlCharacter($char);
                $value .= $this->advance();
            }
        }

        throw new TomlParseException(
            'Unterminated basic string',
            $line,
            $column,
            $this->source
        );
    }

    private function validateControlCharacter(string $char): void
    {
        $ord = ord($char);
        // Control characters U+0000 to U+001F are not allowed, except tab (U+0009)
        if ($ord <= 0x1F && $ord !== 0x09) {
            throw new TomlParseException(
                sprintf('Control character U+%04X is not allowed in basic strings', $ord),
                $this->line,
                $this->column,
                $this->source
            );
        }
    }

    private function scanMultilineBasicString(int $line, int $column): Token
    {
        // Consume opening """
        $this->advance();
        $this->advance();
        $this->advance();

        // Skip newline immediately after opening delimiter
        if ($this->peek() === "\n") {
            $this->advance();
            $this->line++;
            $this->column = 1;
        } elseif ($this->peek() === "\r" && $this->lookAhead(1) === "\n") {
            $this->advance();
            $this->advance();
            $this->line++;
            $this->column = 1;
        }

        $value = '';

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            assert($char !== null);

            if ($char === '"' && $this->lookAhead(1) === '"' && $this->lookAhead(2) === '"') {
                $this->advance();
                $this->advance();
                $this->advance();

                // Handle up to two additional quotes at the end (e.g., """foo""""" = "foo"")
                $extraQuotes = '';
                while ($this->peek() === '"' && strlen($extraQuotes) < 2) {
                    $extraQuotes .= $this->advance();
                }

                return new Token(TokenType::MultilineBasicString, $value.$extraQuotes, $line, $column);
            }

            if ($char === '\\') {
                // Check for line-ending backslash (trim newlines and whitespace)
                $next = $this->lookAhead(1);
                if ($next === "\n" || $next === "\r") {
                    $this->advance(); // consume backslash
                    $this->skipLineEndingBackslash();
                } else {
                    $value .= $this->scanEscapeSequence();
                }
            } elseif ($char === "\n") {
                $value .= $this->advance();
                $this->line++;
                $this->column = 1;
            } elseif ($char === "\r") {
                $this->advance();
                if ($this->peek() === "\n") {
                    $value .= "\n";
                    $this->advance();
                }
                $this->line++;
                $this->column = 1;
            } else {
                $this->validateControlCharacter($char);
                $value .= $this->advance();
            }
        }

        throw new TomlParseException(
            'Unterminated multiline basic string',
            $line,
            $column,
            $this->source
        );
    }

    private function skipLineEndingBackslash(): void
    {
        // Skip whitespace and newlines until non-whitespace
        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char === "\n") {
                $this->advance();
                $this->line++;
                $this->column = 1;
            } elseif ($char === "\r") {
                $this->advance();
                if ($this->peek() === "\n") {
                    $this->advance();
                }
                $this->line++;
                $this->column = 1;
            } elseif ($char === ' ' || $char === "\t") {
                $this->advance();
            } else {
                break;
            }
        }
    }

    private function scanEscapeSequence(): string
    {
        $this->advance(); // consume backslash
        $char = $this->advance();

        return match ($char) {
            'b' => "\x08",
            't' => "\t",
            'n' => "\n",
            'f' => "\x0C",
            'r' => "\r",
            '"' => '"',
            '\\' => '\\',
            'u' => $this->scanUnicodeEscape(4),
            'U' => $this->scanUnicodeEscape(8),
            'e' => "\x1B", // TOML 1.1.0: escape character
            'x' => $this->scanHexEscape(), // TOML 1.1.0: \xNN
            default => throw new TomlParseException(
                "Invalid escape sequence: \\{$char}",
                $this->line,
                $this->column - 2,
                $this->source
            ),
        };
    }

    private function scanHexEscape(): string
    {
        $hex = '';
        for ($i = 0; $i < 2; $i++) {
            if ($this->isAtEnd()) {
                throw new TomlParseException(
                    'Incomplete hex escape sequence',
                    $this->line,
                    $this->column,
                    $this->source
                );
            }
            $char = $this->peek();
            if (! ctype_xdigit($char)) {
                throw new TomlParseException(
                    "Invalid hex escape character: {$char}",
                    $this->line,
                    $this->column,
                    $this->source
                );
            }
            $hex .= $this->advance();
        }

        return chr((int) hexdec($hex));
    }

    private function scanUnicodeEscape(int $length): string
    {
        $hex = '';
        for ($i = 0; $i < $length; $i++) {
            if ($this->isAtEnd()) {
                throw new TomlParseException(
                    'Incomplete unicode escape sequence',
                    $this->line,
                    $this->column,
                    $this->source
                );
            }
            $char = $this->peek();
            if (! ctype_xdigit($char)) {
                throw new TomlParseException(
                    "Invalid unicode escape character: {$char}",
                    $this->line,
                    $this->column,
                    $this->source
                );
            }
            $hex .= $this->advance();
        }

        $codePoint = hexdec($hex);

        return mb_chr((int) $codePoint, 'UTF-8');
    }

    private function scanLiteralString(): Token
    {
        $line = $this->line;
        $column = $this->column;

        // Check for multiline literal string
        if ($this->lookAhead(1) === "'" && $this->lookAhead(2) === "'") {
            return $this->scanMultilineLiteralString($line, $column);
        }

        return $this->scanSingleLiteralString($line, $column);
    }

    private function scanSingleLiteralString(int $line, int $column): Token
    {
        $this->advance(); // consume opening quote
        $value = '';

        while (! $this->isAtEnd()) {
            $char = $this->peek();

            if ($char === "'") {
                $this->advance();

                return new Token(TokenType::LiteralString, $value, $line, $column);
            }

            if ($char === "\n" || $char === "\r") {
                throw new TomlParseException(
                    'Unterminated literal string',
                    $this->line,
                    $this->column,
                    $this->source
                );
            }

            $value .= $this->advance();
        }

        throw new TomlParseException(
            'Unterminated literal string',
            $line,
            $column,
            $this->source
        );
    }

    private function scanMultilineLiteralString(int $line, int $column): Token
    {
        // Consume opening '''
        $this->advance();
        $this->advance();
        $this->advance();

        // Skip newline immediately after opening delimiter
        if ($this->peek() === "\n") {
            $this->advance();
            $this->line++;
            $this->column = 1;
        } elseif ($this->peek() === "\r" && $this->lookAhead(1) === "\n") {
            $this->advance();
            $this->advance();
            $this->line++;
            $this->column = 1;
        }

        $value = '';

        while (! $this->isAtEnd()) {
            $char = $this->peek();

            if ($char === "'" && $this->lookAhead(1) === "'" && $this->lookAhead(2) === "'") {
                $this->advance();
                $this->advance();
                $this->advance();

                // Handle up to two additional quotes at the end
                $extraQuotes = '';
                while ($this->peek() === "'" && strlen($extraQuotes) < 2) {
                    $extraQuotes .= $this->advance();
                }

                return new Token(TokenType::MultilineLiteralString, $value.$extraQuotes, $line, $column);
            }

            if ($char === "\n") {
                $value .= $this->advance();
                $this->line++;
                $this->column = 1;
            } elseif ($char === "\r") {
                $this->advance();
                if ($this->peek() === "\n") {
                    $value .= "\n";
                    $this->advance();
                }
                $this->line++;
                $this->column = 1;
            } else {
                $value .= $this->advance();
            }
        }

        throw new TomlParseException(
            'Unterminated multiline literal string',
            $line,
            $column,
            $this->source
        );
    }

    private function scanValue(): Token
    {
        $line = $this->line;
        $column = $this->column;

        // Check for date/time first (more specific patterns)
        if ($this->isDigit($this->peek())) {
            $dateTimeToken = $this->tryScanDateTime($line, $column);
            if ($dateTimeToken !== null) {
                return $dateTimeToken;
            }

            return $this->scanNumber($line, $column);
        }

        // Check for signed numbers or inf/nan
        if ($this->peek() === '+' || $this->peek() === '-') {
            $next = $this->lookAhead(1);
            if ($this->isDigit($next) || $next === 'i' || $next === 'n') {
                return $this->scanNumber($line, $column);
            }
        }

        // Check for inf/nan without sign
        if ($this->peek() === 'i' && $this->lookAhead(1) === 'n' && $this->lookAhead(2) === 'f') {
            return $this->scanInfNan($line, $column);
        }
        if ($this->peek() === 'n' && $this->lookAhead(1) === 'a' && $this->lookAhead(2) === 'n') {
            return $this->scanInfNan($line, $column);
        }

        // Check for boolean
        if ($this->peek() === 't' || $this->peek() === 'f') {
            $boolToken = $this->tryScanBoolean($line, $column);
            if ($boolToken !== null) {
                return $boolToken;
            }
        }

        // Otherwise, scan as bare key
        return $this->scanBareKey($line, $column);
    }

    private function tryScanDateTime(int $line, int $column): ?Token
    {
        // Save position for backtracking
        $savedPosition = $this->position;
        $savedLine = $this->line;
        $savedColumn = $this->column;

        // First, check for local time pattern: HH:MM:SS (before date check)
        $timePattern = '';
        for ($i = 0; $i < 20; $i++) { // 20 chars to support HH:MM:SS.fractional (up to 6+ decimals)
            $char = $this->lookAhead($i);
            if ($char === null) {
                break;
            }
            $timePattern .= $char;
        }

        // Check for local time: HH:MM:SS, HH:MM:SS.fraction, or HH:MM (no seconds)
        // We need to check for the 3rd char being ':' to distinguish from dates (YYYY-...)
        if ($this->lookAhead(2) === ':') {
            // Match HH:MM:SS or HH:MM:SS.fraction first (with seconds)
            if (preg_match('/^(\d{2}:\d{2}:\d{2}(?:\.\d+)?)/', $timePattern, $matches)) {
                $timeValue = $matches[1];
                for ($i = 0; $i < strlen($timeValue); $i++) {
                    $this->advance();
                }

                return new Token(TokenType::LocalTime, $timeValue, $line, $column);
            }
            // Match HH:MM (without seconds) - TOML 1.1.0
            if (preg_match('/^(\d{2}:\d{2})(?![:\d])/', $timePattern, $matches)) {
                $timeValue = $matches[1];
                for ($i = 0; $i < strlen($timeValue); $i++) {
                    $this->advance();
                }

                return new Token(TokenType::LocalTime, $timeValue, $line, $column);
            }
        }

        // Try to match date pattern: YYYY-MM-DD
        // We need to look ahead to see the full pattern before committing
        $datePattern = '';
        for ($i = 0; $i < 10; $i++) {
            $char = $this->lookAhead($i);
            if ($char === null) {
                break;
            }
            // Stop if we hit a character that can't be part of a date
            if ($char !== '-' && ! $this->isDigit($char)) {
                break;
            }
            $datePattern .= $char;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePattern)) {
            return null;
        }

        // Consume the date part
        for ($i = 0; $i < 10; $i++) {
            $this->advance();
        }

        $value = $datePattern;

        // Check for time component
        $next = $this->peek();
        $hasTime = false;

        if ($next === 'T' || $next === 't') {
            // T or t separator - definitely a datetime
            $separator = $this->advance();
            $value .= $separator;

            // Parse time: HH:MM:SS or HH:MM (without seconds)
            $timePattern = '';
            for ($i = 0; $i < 8; $i++) {
                $char = $this->peek();
                if ($char === null || (! $this->isDigit($char) && $char !== ':')) {
                    break;
                }
                $timePattern .= $this->advance();
            }

            // Accept HH:MM:SS or HH:MM (TOML 1.1.0 allows omitting seconds)
            if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timePattern)) {
                // Restore position and return null for invalid time
                $this->position = $savedPosition;
                $this->line = $savedLine;
                $this->column = $savedColumn;

                return null;
            }

            $value .= $timePattern;
            $hasTime = true;
        } elseif ($next === ' ' && $this->isDigit($this->lookAhead(1))) {
            // Space separator with digit following - might be a datetime
            $separator = $this->advance();
            $value .= $separator;

            // Parse time: HH:MM:SS or HH:MM (without seconds)
            $timePattern = '';
            for ($i = 0; $i < 8; $i++) {
                $char = $this->peek();
                if ($char === null || (! $this->isDigit($char) && $char !== ':')) {
                    break;
                }
                $timePattern .= $this->advance();
            }

            // Accept HH:MM:SS or HH:MM (TOML 1.1.0 allows omitting seconds)
            if (! preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timePattern)) {
                // Not a valid datetime, restore to after the date and return as LocalDate
                // We need to "unadvance" by the separator and failed time pattern
                $this->position -= strlen($timePattern) + 1;
                $this->column -= strlen($timePattern) + 1;

                // Return just the date
                return new Token(TokenType::LocalDate, $datePattern, $line, $column);
            }

            $value .= $timePattern;
            $hasTime = true;
        }

        if ($hasTime) {
            // Check for fractional seconds
            if ($this->peek() === '.') {
                $value .= $this->advance();
                while ($this->isDigit($this->peek())) {
                    $value .= $this->advance();
                }
            }

            // Check for timezone offset
            $tzChar = $this->peek();
            if ($tzChar === 'Z' || $tzChar === 'z') {
                $value .= $this->advance();

                return new Token(TokenType::OffsetDateTime, $value, $line, $column);
            }

            if ($tzChar === '+' || $tzChar === '-') {
                $value .= $this->advance();
                // Parse offset: HH:MM
                for ($i = 0; $i < 5; $i++) {
                    $char = $this->peek();
                    if ($char === null || (! $this->isDigit($char) && $char !== ':')) {
                        break;
                    }
                    $value .= $this->advance();
                }

                return new Token(TokenType::OffsetDateTime, $value, $line, $column);
            }

            return new Token(TokenType::LocalDateTime, $value, $line, $column);
        }

        return new Token(TokenType::LocalDate, $value, $line, $column);
    }

    private function scanNumber(int $line, int $column): Token
    {
        $value = '';
        $isFloat = false;

        // Handle sign
        $currentChar = $this->peek();
        if ($currentChar === '+' || $currentChar === '-') {
            $sign = $this->advance();

            // Check for inf/nan after sign
            $nextChar = $this->peek();
            if ($nextChar === 'i' && $this->lookAhead(1) === 'n' && $this->lookAhead(2) === 'f') {
                $this->advance();
                $this->advance();
                $this->advance();

                return new Token(TokenType::Float, $sign.'inf', $line, $column);
            }
            if ($nextChar === 'n' && $this->lookAhead(1) === 'a' && $this->lookAhead(2) === 'n') {
                $this->advance();
                $this->advance();
                $this->advance();

                return new Token(TokenType::Float, $sign.'nan', $line, $column);
            }

            $value .= $sign;
        }

        // Check for hex, octal, or binary
        if ($this->peek() === '0' && $this->lookAhead(1) !== null) {
            $prefix = $this->lookAhead(1);
            if ($prefix === 'x' || $prefix === 'X') {
                return $this->scanHexNumber($line, $column, $value);
            }
            if ($prefix === 'o' || $prefix === 'O') {
                return $this->scanOctalNumber($line, $column, $value);
            }
            if ($prefix === 'b' || $prefix === 'B') {
                return $this->scanBinaryNumber($line, $column, $value);
            }
        }

        // Scan integer part
        $value .= $this->scanDigits();

        // Check for decimal point (float)
        if ($this->peek() === '.' && $this->isDigit($this->lookAhead(1))) {
            $isFloat = true;
            $value .= $this->advance(); // consume dot
            $value .= $this->scanDigits();
        }

        // Check for exponent (float)
        $expChar = $this->peek();
        if ($expChar === 'e' || $expChar === 'E') {
            $isFloat = true;
            $value .= $this->advance(); // consume 'e' or 'E'
            $signChar = $this->peek();
            if ($signChar === '+' || $signChar === '-') {
                $value .= $this->advance();
            }
            $value .= $this->scanDigits();
        }

        return new Token($isFloat ? TokenType::Float : TokenType::Integer, $value, $line, $column);
    }

    private function scanDigits(): string
    {
        $digits = '';
        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($this->isDigit($char)) {
                $digits .= $this->advance();
            } elseif ($char === '_') {
                // Underscores are allowed between digits for readability
                $this->advance();
            } else {
                break;
            }
        }

        return $digits;
    }

    private function scanHexNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'x'

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if (ctype_xdigit($char)) {
                $value .= $this->advance();
            } elseif ($char === '_') {
                $this->advance();
            } else {
                break;
            }
        }

        return new Token(TokenType::Integer, $value, $line, $column);
    }

    private function scanOctalNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'o'

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char >= '0' && $char <= '7') {
                $value .= $this->advance();
            } elseif ($char === '_') {
                $this->advance();
            } else {
                break;
            }
        }

        return new Token(TokenType::Integer, $value, $line, $column);
    }

    private function scanBinaryNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'b'

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char === '0' || $char === '1') {
                $value .= $this->advance();
            } elseif ($char === '_') {
                $this->advance();
            } else {
                break;
            }
        }

        return new Token(TokenType::Integer, $value, $line, $column);
    }

    private function scanInfNan(int $line, int $column): Token
    {
        $value = '';
        for ($i = 0; $i < 3; $i++) {
            $value .= $this->advance();
        }

        return new Token(TokenType::Float, $value, $line, $column);
    }

    private function tryScanBoolean(int $line, int $column): ?Token
    {
        if ($this->peek() === 't') {
            if ($this->lookAhead(1) === 'r' && $this->lookAhead(2) === 'u' && $this->lookAhead(3) === 'e') {
                // Make sure it's not part of a longer identifier
                $afterTrue = $this->lookAhead(4);
                if ($afterTrue === null || ! $this->isBareKeyChar($afterTrue)) {
                    $this->advance();
                    $this->advance();
                    $this->advance();
                    $this->advance();

                    return new Token(TokenType::Boolean, 'true', $line, $column);
                }
            }
        } elseif ($this->peek() === 'f') {
            if ($this->lookAhead(1) === 'a' && $this->lookAhead(2) === 'l' && $this->lookAhead(3) === 's' && $this->lookAhead(4) === 'e') {
                $afterFalse = $this->lookAhead(5);
                if ($afterFalse === null || ! $this->isBareKeyChar($afterFalse)) {
                    $this->advance();
                    $this->advance();
                    $this->advance();
                    $this->advance();
                    $this->advance();

                    return new Token(TokenType::Boolean, 'false', $line, $column);
                }
            }
        }

        return null;
    }

    private function scanBareKey(int $line, int $column): Token
    {
        $value = '';

        while (! $this->isAtEnd() && $this->isBareKeyChar($this->peek())) {
            $value .= $this->advance();
        }

        if ($value === '') {
            throw new TomlParseException(
                "Unexpected character: {$this->peek()}",
                $line,
                $column,
                $this->source
            );
        }

        return new Token(TokenType::BareKey, $value, $line, $column);
    }

    private function skipWhitespace(): void
    {
        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char === ' ' || $char === "\t") {
                $this->advance();
            } else {
                break;
            }
        }
    }

    private function skipComment(): void
    {
        // Consume the '#' and everything until end of line
        while (! $this->isAtEnd() && $this->peek() !== "\n" && $this->peek() !== "\r") {
            $this->advance();
        }
    }

    private function isAtEnd(): bool
    {
        return $this->position >= $this->length;
    }

    private function peek(): ?string
    {
        if ($this->isAtEnd()) {
            return null;
        }

        return $this->source[$this->position];
    }

    private function lookAhead(int $offset): ?string
    {
        $pos = $this->position + $offset;
        if ($pos >= $this->length) {
            return null;
        }

        return $this->source[$pos];
    }

    private function advance(): string
    {
        $char = $this->source[$this->position];
        $this->position++;
        $this->column++;

        return $char;
    }

    private function isDigit(?string $char): bool
    {
        return $char !== null && $char >= '0' && $char <= '9';
    }

    private function isBareKeyChar(?string $char): bool
    {
        if ($char === null) {
            return false;
        }

        // TOML spec: unquoted-key = 1*( ALPHA / DIGIT / %x2D / %x5F )
        // Only A-Z, a-z, 0-9, hyphen (-), and underscore (_) are allowed
        return ctype_alnum($char) || $char === '-' || $char === '_';
    }
}
