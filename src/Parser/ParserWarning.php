<?php

declare(strict_types=1);

namespace AppHive\Toml\Parser;

/**
 * Represents a warning collected during lenient parsing.
 *
 * Warnings capture spec violations that the parser encountered but
 * continued past in lenient mode. Each warning includes the message,
 * location in the source, and a code snippet for context.
 */
final class ParserWarning
{
    /**
     * Create a new parser warning.
     *
     * @param  string  $message  Description of the spec violation
     * @param  int  $line  Line number where the violation occurred (1-indexed)
     * @param  int  $column  Column number where the violation occurred (1-indexed)
     * @param  string  $snippet  Source code snippet showing context around the violation
     */
    public function __construct(
        public readonly string $message,
        public readonly int $line,
        public readonly int $column,
        public readonly string $snippet,
    ) {}

    /**
     * Get a formatted message including line and column.
     */
    public function getFormattedMessage(): string
    {
        if ($this->line === 0 && $this->column === 0) {
            return $this->message;
        }

        return sprintf('%s at line %d, column %d', $this->message, $this->line, $this->column);
    }
}
