<?php

declare(strict_types=1);

namespace AppHive\Toml\Exceptions;

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
        $this->snippet = $this->buildSnippet($source, $line, $column);

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

    private function buildSnippet(string $source, int $line, int $column): string
    {
        if ($source === '' || $line === 0) {
            return '';
        }

        $lines = explode("\n", $source);
        $totalLines = count($lines);

        if ($line > $totalLines) {
            return '';
        }

        $snippetLines = [];
        $contextLines = 2;

        $startLine = max(1, $line - $contextLines);
        $endLine = min($totalLines, $line + $contextLines);

        $lineNumberWidth = strlen((string) $endLine);

        for ($i = $startLine; $i <= $endLine; $i++) {
            $lineContent = $lines[$i - 1];
            $lineNumber = str_pad((string) $i, $lineNumberWidth, ' ', STR_PAD_LEFT);
            $prefix = $i === $line ? '> ' : '  ';
            $snippetLines[] = sprintf('%s%s | %s', $prefix, $lineNumber, $lineContent);

            if ($i === $line && $column > 0) {
                $pointerPadding = str_repeat(' ', strlen($prefix) + $lineNumberWidth + 3 + $column - 1);
                $snippetLines[] = $pointerPadding.'^';
            }
        }

        return implode("\n", $snippetLines);
    }
}
