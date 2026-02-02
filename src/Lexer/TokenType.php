<?php

declare(strict_types=1);

namespace AppHive\Toml\Lexer;

enum TokenType: string
{
    // Structural tokens
    case LeftBracket = 'LEFT_BRACKET';           // [
    case RightBracket = 'RIGHT_BRACKET';         // ]
    case LeftBrace = 'LEFT_BRACE';               // {
    case RightBrace = 'RIGHT_BRACE';             // }
    case Equals = 'EQUALS';                       // =
    case Dot = 'DOT';                             // .
    case Comma = 'COMMA';                         // ,
    case Newline = 'NEWLINE';                     // \n

    // Keys and identifiers
    case BareKey = 'BARE_KEY';                    // unquoted key like foo, bar123

    // String literals
    case BasicString = 'BASIC_STRING';            // "..."
    case LiteralString = 'LITERAL_STRING';        // '...'
    case MultilineBasicString = 'MULTILINE_BASIC_STRING';     // """..."""
    case MultilineLiteralString = 'MULTILINE_LITERAL_STRING'; // '''...'''

    // Number literals
    case Integer = 'INTEGER';                     // 123, +123, -123, 0x1A, 0o17, 0b1010
    case Float = 'FLOAT';                         // 1.0, 3.14, +1.0, -0.01, 5e+22, inf, nan

    // Boolean literals
    case Boolean = 'BOOLEAN';                     // true, false

    // Date/time literals
    case OffsetDateTime = 'OFFSET_DATETIME';      // 1979-05-27T07:32:00Z
    case LocalDateTime = 'LOCAL_DATETIME';        // 1979-05-27T07:32:00
    case LocalDate = 'LOCAL_DATE';                // 1979-05-27
    case LocalTime = 'LOCAL_TIME';                // 07:32:00

    // Special tokens
    case Eof = 'EOF';                             // End of file
}
