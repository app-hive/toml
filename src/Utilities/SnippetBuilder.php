<?php

declare(strict_types=1);

namespace AppHive\Toml\Utilities;

final class SnippetBuilder
{
    public static function build(string $source, int $line, int $column): string
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
