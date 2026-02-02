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
        assert($char !== null);

        // Validate that control characters are not present in bare document content
        // Control characters must be inside strings (escaped) or are simply not allowed
        $this->validateBareControlCharacter($char);

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

            // Bare CR is not allowed - should have been caught by validateBareControlCharacter
            // but if we get here somehow, reject it
            throw new TomlParseException(
                'Bare carriage return (U+000D) is not allowed outside strings',
                $this->line,
                $this->column - 1,
                $this->source
            );
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
        // Also U+007F (DEL) is not allowed
        if (($ord <= 0x1F && $ord !== 0x09) || $ord === 0x7F) {
            throw new TomlParseException(
                sprintf('Control character U+%04X is not allowed in basic strings', $ord),
                $this->line,
                $this->column,
                $this->source
            );
        }
    }

    private function validateLiteralControlCharacter(string $char): void
    {
        $ord = ord($char);
        // In literal strings, tab (U+0009) is allowed, but other control chars are not
        // Control characters U+0000 to U+001F (except tab) and U+007F (DEL) are not allowed
        if (($ord <= 0x1F && $ord !== 0x09) || $ord === 0x7F) {
            throw new TomlParseException(
                sprintf('Control character U+%04X is not allowed in literal strings', $ord),
                $this->line,
                $this->column,
                $this->source
            );
        }
    }

    /**
     * Validate that a character is not a control character appearing as bare document content.
     * Control characters are not allowed outside of strings (where they must be escaped).
     * Allowed: newline (U+000A), carriage return followed by newline (CRLF), and tab (U+0009).
     * Tab is only allowed as whitespace, not in bare keys or values.
     */
    private function validateBareControlCharacter(string $char): void
    {
        $ord = ord($char);
        // Control characters U+0000 to U+001F are not allowed in bare document content
        // Exceptions:
        // - Tab (U+0009) is allowed as whitespace (handled by skipWhitespace)
        // - Newline (U+000A) is a valid token
        // - Carriage return (U+000D) is only allowed when followed by newline (CRLF)
        // U+007F (DEL) is also not allowed
        if ($ord <= 0x1F && $ord !== 0x09 && $ord !== 0x0A && $ord !== 0x0D) {
            throw new TomlParseException(
                sprintf('Control character U+%04X is not allowed in TOML documents', $ord),
                $this->line,
                $this->column,
                $this->source
            );
        }
        if ($ord === 0x7F) {
            throw new TomlParseException(
                'Control character U+007F (DEL) is not allowed in TOML documents',
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
                // A backslash followed by optional whitespace (spaces/tabs) and then a newline
                // is a line-ending backslash that trims all following whitespace and newlines
                if ($this->isLineEndingBackslash()) {
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

    /**
     * Check if the current backslash starts a line-ending backslash sequence.
     * A line-ending backslash is a backslash followed by optional whitespace and then a newline.
     */
    private function isLineEndingBackslash(): bool
    {
        $offset = 1; // Start after the backslash
        while (true) {
            $char = $this->lookAhead($offset);
            if ($char === null) {
                return false;
            }
            if ($char === "\n" || $char === "\r") {
                return true;
            }
            if ($char === ' ' || $char === "\t") {
                $offset++;

                continue;
            }

            return false;
        }
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

        $codePoint = (int) hexdec($hex);

        // Validate code point is within Unicode range (U+0000 to U+10FFFF)
        if ($codePoint > 0x10FFFF) {
            throw new TomlParseException(
                'Unicode code point U+'.strtoupper(dechex($codePoint)).' is out of range (max U+10FFFF)',
                $this->line,
                $this->column,
                $this->source
            );
        }

        // Reject surrogate pairs (U+D800 to U+DFFF) - not valid Unicode scalar values
        if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
            throw new TomlParseException(
                'Unicode code point U+'.strtoupper(dechex($codePoint)).' is a surrogate pair (U+D800 to U+DFFF) which is not allowed',
                $this->line,
                $this->column,
                $this->source
            );
        }

        return mb_chr($codePoint, 'UTF-8');
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
            assert($char !== null);

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

            $this->validateLiteralControlCharacter($char);
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
            assert($char !== null);

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
                $this->validateLiteralControlCharacter($char);
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
        // Make sure it's not part of a longer identifier (e.g., "infinity" or "nan_value")
        if ($this->peek() === 'i' && $this->lookAhead(1) === 'n' && $this->lookAhead(2) === 'f') {
            $afterInf = $this->lookAhead(3);
            if ($afterInf === null || ! $this->isBareKeyChar($afterInf)) {
                return $this->scanInfNan($line, $column);
            }
        }
        if ($this->peek() === 'n' && $this->lookAhead(1) === 'a' && $this->lookAhead(2) === 'n') {
            $afterNan = $this->lookAhead(3);
            if ($afterNan === null || ! $this->isBareKeyChar($afterNan)) {
                return $this->scanInfNan($line, $column);
            }
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
        $hasSign = false;
        if ($currentChar === '+' || $currentChar === '-') {
            $sign = $this->advance();
            $hasSign = true;

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

            // Reject capital prefixes (0X, 0O, 0B)
            if ($prefix === 'X') {
                throw new TomlParseException(
                    'Hexadecimal prefix must be lowercase (0x, not 0X)',
                    $line,
                    $column,
                    $this->source
                );
            }
            if ($prefix === 'O') {
                throw new TomlParseException(
                    'Octal prefix must be lowercase (0o, not 0O)',
                    $line,
                    $column,
                    $this->source
                );
            }
            if ($prefix === 'B') {
                throw new TomlParseException(
                    'Binary prefix must be lowercase (0b, not 0B)',
                    $line,
                    $column,
                    $this->source
                );
            }

            if ($prefix === 'x') {
                // Reject signed hex numbers
                if ($hasSign) {
                    throw new TomlParseException(
                        'Hexadecimal integers cannot have a sign prefix',
                        $line,
                        $column,
                        $this->source
                    );
                }

                return $this->scanHexNumber($line, $column, $value);
            }
            if ($prefix === 'o') {
                // Reject signed octal numbers
                if ($hasSign) {
                    throw new TomlParseException(
                        'Octal integers cannot have a sign prefix',
                        $line,
                        $column,
                        $this->source
                    );
                }

                return $this->scanOctalNumber($line, $column, $value);
            }
            if ($prefix === 'b') {
                // Reject signed binary numbers
                if ($hasSign) {
                    throw new TomlParseException(
                        'Binary integers cannot have a sign prefix',
                        $line,
                        $column,
                        $this->source
                    );
                }

                return $this->scanBinaryNumber($line, $column, $value);
            }
        }

        // Scan integer part
        $value .= $this->scanDigits($line, $column);

        // Check for decimal point (float)
        if ($this->peek() === '.' && $this->isDigit($this->lookAhead(1))) {
            $isFloat = true;
            $value .= $this->advance(); // consume dot
            $value .= $this->scanDigits($line, $column);
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

            // Exponent must have at least one digit
            if (! $this->isDigit($this->peek())) {
                throw new TomlParseException(
                    'Exponent requires at least one digit',
                    $line,
                    $this->column,
                    $this->source
                );
            }

            $value .= $this->scanDigits($line, $column);
        }

        return new Token($isFloat ? TokenType::Float : TokenType::Integer, $value, $line, $column);
    }

    private function scanDigits(int $line, int $column): string
    {
        $digits = '';
        $lastWasUnderscore = false;
        $hasDigits = false;

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($this->isDigit($char)) {
                $digits .= $this->advance();
                $lastWasUnderscore = false;
                $hasDigits = true;
            } elseif ($char === '_') {
                // Reject leading underscore (underscore before any digit)
                if (! $hasDigits) {
                    throw new TomlParseException(
                        'Leading underscore is not allowed in integers',
                        $line,
                        $this->column,
                        $this->source
                    );
                }
                // Reject double underscore
                if ($lastWasUnderscore) {
                    throw new TomlParseException(
                        'Double underscore is not allowed in integers',
                        $line,
                        $this->column,
                        $this->source
                    );
                }
                $this->advance();
                $lastWasUnderscore = true;
            } else {
                break;
            }
        }

        // Reject trailing underscore
        if ($lastWasUnderscore) {
            throw new TomlParseException(
                'Trailing underscore is not allowed in integers',
                $line,
                $this->column - 1,
                $this->source
            );
        }

        return $digits;
    }

    private function scanHexNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'x'

        $lastWasUnderscore = false;
        $hasDigits = false;

        // Check for leading underscore after prefix
        if ($this->peek() === '_') {
            throw new TomlParseException(
                'Underscore immediately after hex prefix is not allowed',
                $line,
                $this->column,
                $this->source
            );
        }

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if (ctype_xdigit($char)) {
                $value .= $this->advance();
                $lastWasUnderscore = false;
                $hasDigits = true;
            } elseif ($char === '_') {
                // Reject double underscore
                if ($lastWasUnderscore) {
                    throw new TomlParseException(
                        'Double underscore is not allowed in integers',
                        $line,
                        $this->column,
                        $this->source
                    );
                }
                $this->advance();
                $lastWasUnderscore = true;
            } else {
                break;
            }
        }

        // Reject trailing underscore
        if ($lastWasUnderscore) {
            throw new TomlParseException(
                'Trailing underscore is not allowed in integers',
                $line,
                $this->column - 1,
                $this->source
            );
        }

        // Reject incomplete hex (0x with no digits)
        if (! $hasDigits) {
            throw new TomlParseException(
                'Hexadecimal integer must have at least one digit after prefix',
                $line,
                $column,
                $this->source
            );
        }

        return new Token(TokenType::Integer, $value, $line, $column);
    }

    private function scanOctalNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'o'

        $lastWasUnderscore = false;
        $hasDigits = false;

        // Check for leading underscore after prefix
        if ($this->peek() === '_') {
            throw new TomlParseException(
                'Underscore immediately after octal prefix is not allowed',
                $line,
                $this->column,
                $this->source
            );
        }

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char >= '0' && $char <= '7') {
                $value .= $this->advance();
                $lastWasUnderscore = false;
                $hasDigits = true;
            } elseif ($char === '_') {
                // Reject double underscore
                if ($lastWasUnderscore) {
                    throw new TomlParseException(
                        'Double underscore is not allowed in integers',
                        $line,
                        $this->column,
                        $this->source
                    );
                }
                $this->advance();
                $lastWasUnderscore = true;
            } else {
                break;
            }
        }

        // Reject trailing underscore
        if ($lastWasUnderscore) {
            throw new TomlParseException(
                'Trailing underscore is not allowed in integers',
                $line,
                $this->column - 1,
                $this->source
            );
        }

        // Reject incomplete octal (0o with no digits)
        if (! $hasDigits) {
            throw new TomlParseException(
                'Octal integer must have at least one digit after prefix',
                $line,
                $column,
                $this->source
            );
        }

        return new Token(TokenType::Integer, $value, $line, $column);
    }

    private function scanBinaryNumber(int $line, int $column, string $prefix): Token
    {
        $value = $prefix;
        $value .= $this->advance(); // consume '0'
        $value .= $this->advance(); // consume 'b'

        $lastWasUnderscore = false;
        $hasDigits = false;

        // Check for leading underscore after prefix
        if ($this->peek() === '_') {
            throw new TomlParseException(
                'Underscore immediately after binary prefix is not allowed',
                $line,
                $this->column,
                $this->source
            );
        }

        while (! $this->isAtEnd()) {
            $char = $this->peek();
            if ($char === '0' || $char === '1') {
                $value .= $this->advance();
                $lastWasUnderscore = false;
                $hasDigits = true;
            } elseif ($char === '_') {
                // Reject double underscore
                if ($lastWasUnderscore) {
                    throw new TomlParseException(
                        'Double underscore is not allowed in integers',
                        $line,
                        $this->column,
                        $this->source
                    );
                }
                $this->advance();
                $lastWasUnderscore = true;
            } else {
                break;
            }
        }

        // Reject trailing underscore
        if ($lastWasUnderscore) {
            throw new TomlParseException(
                'Trailing underscore is not allowed in integers',
                $line,
                $this->column - 1,
                $this->source
            );
        }

        // Reject incomplete binary (0b with no digits)
        if (! $hasDigits) {
            throw new TomlParseException(
                'Binary integer must have at least one digit after prefix',
                $line,
                $column,
                $this->source
            );
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
            $char = $this->peek();
            assert($char !== null);
            $this->validateCommentControlCharacter($char);
            $this->advance();
        }
    }

    private function validateCommentControlCharacter(string $char): void
    {
        $ord = ord($char);
        // Control characters U+0000 to U+001F are not allowed, except tab (U+0009)
        // Also U+007F (DEL) is not allowed
        if (($ord <= 0x1F && $ord !== 0x09) || $ord === 0x7F) {
            throw new TomlParseException(
                sprintf('Control character U+%04X is not allowed in comments', $ord),
                $this->line,
                $this->column,
                $this->source
            );
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
