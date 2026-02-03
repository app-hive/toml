<?php

declare(strict_types=1);

namespace AppHive\Toml\Exceptions;

use AppHive\Toml\Utilities\SnippetBuilder;
use Exception;

final class TomlParseException extends Exception
{
    private int $errorLine;

    private int $errorColumn;

    private string $snippet;

    public function __construct(
        string $message,
        int $line = 0,
        int $column = 0,
        string $source = '',
        ?Exception $previous = null
    ) {
        $this->errorLine = $line;
        $this->errorColumn = $column;
        $this->snippet = SnippetBuilder::build($source, $line, $column);

        $fullMessage = $this->buildMessage($message, $line, $column);

        parent::__construct($fullMessage, 0, $previous);
    }

    public function getErrorLine(): int
    {
        return $this->errorLine;
    }

    public function getErrorColumn(): int
    {
        return $this->errorColumn;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    private function buildMessage(string $message, int $line, int $column): string
    {
        if ($line === 0 && $column === 0) {
            return $message;
        }

        return sprintf('%s at line %d, column %d', $message, $line, $column);
    }

}
