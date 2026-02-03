<?php

declare(strict_types=1);

namespace AppHive\Toml\Parser;

use AppHive\Toml\Exceptions\TomlParseException;
use AppHive\Toml\Lexer\Lexer;
use AppHive\Toml\Lexer\Token;
use AppHive\Toml\Lexer\TokenType;
use AppHive\Toml\Utilities\SnippetBuilder;

final class Parser
{
    /** @var list<Token> */
    private array $tokens;

    private int $position = 0;

    private string $source;

    private ParserConfig $config;

    /** @var list<ParserWarning> */
    private array $warnings = [];

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

    /**
     * Track paths defined within inline tables (including the inline table itself).
     * These are immutable and cannot be extended by table headers or dotted keys.
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $inlineTablePaths = [];

    /**
     * Track paths that are static arrays (defined via `key = [...]`).
     * These cannot be extended with array of tables syntax `[[key]]`.
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $staticArrayPaths = [];

    /**
     * Track paths implicitly created as tables by array of tables headers.
     * For example, [[a.b]] implicitly creates 'a' as a table.
     * These cannot later be defined as array of tables.
     * Keys are dot-joined paths.
     *
     * @var array<string, bool>
     */
    private array $implicitTablesByArrayOfTables = [];

    /**
     * Create a new parser instance.
     *
     * @param  string  $source  The TOML source to parse
     * @param  ParserConfig|null  $config  Parser configuration. Defaults to strict mode.
     */
    public function __construct(string $source, ?ParserConfig $config = null)
    {
        $this->source = $source;
        $this->config = $config ?? new ParserConfig;
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
     * Get warnings collected during lenient parsing.
     *
     * In lenient mode, spec violations are collected as warnings instead of
     * throwing exceptions. Call this method after parse() to retrieve them.
     *
     * In strict mode (default), this always returns an empty array since
     * any violation throws an exception.
     *
     * @return list<ParserWarning>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Report a spec violation - throws in strict mode, collects warning in lenient mode.
     *
     * @return bool True if parsing should continue (lenient mode), false otherwise.
     *              In strict mode, this method always throws.
     *
     * @throws TomlParseException In strict mode
     */
    private function reportViolation(string $message, int $line, int $column): bool
    {
        if ($this->config->strict) {
            throw new TomlParseException($message, $line, $column, $this->source);
        }

        $snippet = SnippetBuilder::build($this->source, $line, $column);
        $this->warnings[] = new ParserWarning($message, $line, $column, $snippet);

        return true;
    }

    /**
     * Parse a key-value pair and add it to the result array.
     *
     * @param  array<string, mixed>  $result
     */
    private function parseKeyValue(array &$result): void
    {
        $keyParts = $this->parseDottedKey();
        $keyToken = $this->peek(); // For error reporting

        $this->expect(TokenType::Equals);

        // Check if the value is an inline table (starts with '{')
        $isInlineTable = $this->check(TokenType::LeftBrace);
        // Check if the value is a static array (starts with '[')
        $isStaticArray = $this->check(TokenType::LeftBracket);

        $value = $this->parseValue();

        // Prepend current table path to key parts
        $fullKeyParts = array_merge($this->currentTablePath, $keyParts);

        // Track intermediate tables created by dotted keys for conflict detection
        if (count($keyParts) > 1) {
            $this->trackDottedKeyTables($keyParts);
        }

        // Check if we're trying to extend an inline table
        $this->checkInlineTableImmutability($fullKeyParts, $keyToken);

        $this->setNestedValue($result, $fullKeyParts, $value);

        // If the value is an inline table, track it and all its nested paths as immutable
        // We check $isInlineTable to handle empty inline tables {} which appear as empty arrays
        if ($isInlineTable) {
            $this->trackInlineTablePaths($fullKeyParts, is_array($value) ? $value : []);
        }

        // If the value is a static array, track it so it can't be extended with [[]]
        if ($isStaticArray && is_array($value)) {
            $this->staticArrayPaths[implode('.', $fullKeyParts)] = true;
        }

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
     * For valid array-of-tables syntax, the two opening brackets must be adjacent
     * (no whitespace between them). Same for closing brackets.
     */
    private function isArrayOfTables(): bool
    {
        // We're at '[', check if next token is also '[' AND they are adjacent
        $firstBracket = $this->tokens[$this->position];
        $nextPosition = $this->position + 1;

        if ($nextPosition >= count($this->tokens)) {
            return false;
        }

        $secondToken = $this->tokens[$nextPosition];

        // Must be another left bracket
        if ($secondToken->type !== TokenType::LeftBracket) {
            return false;
        }

        // Brackets must be adjacent (same line, column diff of 1)
        if ($secondToken->line !== $firstBracket->line ||
            $secondToken->column !== $firstBracket->column + 1) {
            return false;
        }

        return true;
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

        // Expect first closing bracket
        $firstCloseBracket = $this->expect(TokenType::RightBracket);

        // Expect second closing bracket - must be adjacent (no space between)
        $nextToken = $this->peek();
        if ($nextToken->type !== TokenType::RightBracket) {
            throw new TomlParseException(
                "Expected ], got {$nextToken->type->value}",
                $nextToken->line,
                $nextToken->column,
                $this->source
            );
        }

        // Validate brackets are adjacent
        if ($nextToken->line !== $firstCloseBracket->line ||
            $nextToken->column !== $firstCloseBracket->column + 1) {
            throw new TomlParseException(
                'Closing brackets must be adjacent in array of tables header (no space allowed)',
                $nextToken->line,
                $nextToken->column,
                $this->source
            );
        }

        $this->advance(); // consume second ']'

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

        // Check if this path was already defined as a static array (via key = [...])
        if (isset($this->staticArrayPaths[$tablePath])) {
            throw new TomlParseException(
                "Cannot define array of tables '{$tablePath}' because it was already defined as a static array",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Check if this path was already defined as a regular table
        if (isset($this->definedTables[$tablePath]) && ! isset($this->arrayOfTablesPaths[$tablePath])) {
            $this->reportViolation(
                "Cannot define array of tables '{$tablePath}' because it was already defined as a table",
                $startToken->line,
                $startToken->column
            );

            // In lenient mode, skip this array of tables header
            return;
        }

        // Check if this path was created by dotted keys (cannot redefine)
        if (isset($this->implicitDottedKeyTables[$tablePath])) {
            $this->reportViolation(
                "Cannot redefine '{$tablePath}' that was implicitly defined by dotted keys",
                $startToken->line,
                $startToken->column
            );

            // In lenient mode, skip this array of tables header
            return;
        }

        // Check if this path was implicitly created as a table by a nested array of tables
        // e.g., [[a.b]] implicitly creates 'a' as a table, so [[a]] should fail
        if (isset($this->implicitTablesByArrayOfTables[$tablePath])) {
            throw new TomlParseException(
                "Cannot define '{$tablePath}' as array of tables because it was implicitly defined as a table",
                $startToken->line,
                $startToken->column,
                $this->source
            );
        }

        // Check if this path or any parent path is within an inline table (immutable)
        $this->checkInlineTableImmutability($keyParts, $startToken);

        // Track parent paths as implicit tables (they cannot become array of tables later)
        // For [[a.b.c]], we track 'a' and 'a.b' as implicit tables
        for ($i = 0; $i < count($keyParts) - 1; $i++) {
            $partialPath = implode('.', array_slice($keyParts, 0, $i + 1));
            // Only mark as implicit table if it's not already an array of tables
            if (! isset($this->arrayOfTablesPaths[$partialPath])) {
                $this->implicitTablesByArrayOfTables[$partialPath] = true;
            }
        }

        // Mark this path as an array of tables
        $this->arrayOfTablesPaths[$tablePath] = true;

        // Clear inline table paths that are within this array of tables.
        // When we add a new element to the array, any inline tables defined in
        // previous elements don't apply to the new element.
        $this->clearInlineTablePathsUnder($tablePath);

        // Add a new element to the array
        $this->addArrayOfTablesElement($result, $keyParts, $startToken);

        // Set current table path for subsequent key-value pairs
        // For array of tables, we point to the current element
        $this->currentTablePath = $keyParts;
    }

    /**
     * Clear inline table paths that are under a given array of tables path.
     * Called when a new element is added to an array of tables.
     */
    private function clearInlineTablePathsUnder(string $arrayPath): void
    {
        $prefix = $arrayPath.'.';
        foreach (array_keys($this->inlineTablePaths) as $path) {
            if (str_starts_with($path, $prefix)) {
                unset($this->inlineTablePaths[$path]);
            }
        }
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

            // Check if this path is a static array - cannot navigate into it
            if (isset($this->staticArrayPaths[$partialPath])) {
                throw new TomlParseException(
                    "Cannot define array of tables under '{$partialPath}' which is a static array",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }

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
            $this->reportViolation(
                "Cannot define table '{$tablePath}' because it was already defined as an array of tables",
                $startToken->line,
                $startToken->column
            );

            // In lenient mode, skip this table header
            return;
        }

        // Check if this table was already explicitly defined
        if (isset($this->definedTables[$tablePath])) {
            $this->reportViolation(
                "Table '{$tablePath}' already defined",
                $startToken->line,
                $startToken->column
            );
            // In lenient mode, continue adding to the same table
        }

        // Check if this path was created by dotted keys (cannot redefine)
        if (isset($this->implicitDottedKeyTables[$tablePath])) {
            $this->reportViolation(
                "Cannot redefine table '{$tablePath}' that was implicitly defined by dotted keys",
                $startToken->line,
                $startToken->column
            );
            // In lenient mode, continue adding to the same table
        }

        // Check if this path or any parent path is within an inline table (immutable)
        $this->checkInlineTableImmutability($keyParts, $startToken);

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
     * Check if any prefix of the key path is within an inline table.
     * Inline tables are immutable and cannot be extended.
     *
     * @param  list<string>  $keyParts
     *
     * @throws TomlParseException If the path violates inline table immutability
     */
    private function checkInlineTableImmutability(array $keyParts, Token $token): void
    {
        // Check each prefix path to see if any parent is an inline table
        for ($i = 1; $i <= count($keyParts); $i++) {
            $partialPath = implode('.', array_slice($keyParts, 0, $i));

            if (isset($this->inlineTablePaths[$partialPath])) {
                throw new TomlParseException(
                    "Cannot extend inline table '{$partialPath}' - inline tables are immutable",
                    $token->line,
                    $token->column,
                    $this->source
                );
            }
        }
    }

    /**
     * Track an inline table and all its nested paths as immutable.
     *
     * @param  list<string>  $keyParts  The full key path to the inline table
     * @param  array<int|string, mixed>  $table  The inline table value
     */
    private function trackInlineTablePaths(array $keyParts, array $table): void
    {
        $basePath = implode('.', $keyParts);
        $this->inlineTablePaths[$basePath] = true;

        // Recursively track all nested paths
        $this->trackNestedInlineTablePaths($table, $basePath);
    }

    /**
     * Recursively track nested paths within an inline table.
     *
     * @param  array<int|string, mixed>  $table
     */
    private function trackNestedInlineTablePaths(array $table, string $basePath): void
    {
        foreach ($table as $key => $value) {
            $fullPath = $basePath.'.'.$key;
            $this->inlineTablePaths[$fullPath] = true;

            if (is_array($value) && ! array_is_list($value)) {
                /** @var array<int|string, mixed> $value */
                $this->trackNestedInlineTablePaths($value, $fullPath);
            }
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

            // Check if this path was explicitly defined as a table and we're trying to extend it
            // from a different (parent) table context using dotted keys
            if (isset($this->definedTables[$partialPath])) {
                // If we're in a different table context trying to add via dotted keys, reject it
                $currentTablePathStr = implode('.', $this->currentTablePath);
                if ($partialPath !== $currentTablePathStr && ! str_starts_with($currentTablePathStr, $partialPath.'.')) {
                    $token = $this->peek();
                    throw new TomlParseException(
                        "Cannot add keys to table '{$partialPath}' using dotted keys from table '{$currentTablePathStr}' - table was already explicitly defined",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
            }

            // Check if this path is an array of tables and we're trying to extend it
            // from a different table context using dotted keys
            if (isset($this->arrayOfTablesPaths[$partialPath])) {
                $currentTablePathStr = implode('.', $this->currentTablePath);
                // If we're not inside this array of tables path, reject
                if (! str_starts_with($currentTablePathStr, $partialPath)) {
                    $token = $this->peek();
                    throw new TomlParseException(
                        "Cannot add keys to array of tables '{$partialPath}' using dotted keys from table '{$currentTablePathStr}'",
                        $token->line,
                        $token->column,
                        $this->source
                    );
                }
            }

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
            $this->reportViolation(
                "Cannot redefine key '{$finalKey}'",
                $token->line,
                $token->column
            );

            // In lenient mode, we skip this assignment (keep original value)
            return;
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
            $this->reportViolation(
                'Leading zeros are not allowed in decimal integers',
                $token->line,
                $token->column
            );
            // In lenient mode, continue parsing the value anyway
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
     * Fractional seconds are padded to at least millisecond precision (3 digits)
     * as per TOML spec requirement for implementations to support millisecond precision.
     */
    private function normalizeTimeComponent(string $time): string
    {
        // Check if we have fractional seconds
        if (str_contains($time, '.')) {
            [$timePart, $fraction] = explode('.', $time, 2);
            $normalizedTime = $this->ensureSeconds($timePart);

            // Pad to at least millisecond precision (3 digits)
            $normalizedFraction = str_pad($fraction, 3, '0');

            return $normalizedTime.'.'.$normalizedFraction;
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

        // Track immutable paths within this inline table (inline subtables and their implicit paths)
        /** @var array<string, bool> $immutablePaths */
        $immutablePaths = [];

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
            $this->setInlineTableValue($result, $keyParts, $value, $startToken, $immutablePaths);

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
     * @param  array<string, bool>  $immutablePaths  Paths that are immutable within this inline table (passed by reference)
     *
     * @throws TomlParseException
     */
    private function setInlineTableValue(array &$array, array $keyParts, mixed $value, Token $startToken, array &$immutablePaths = []): void
    {
        $current = &$array;

        // Check if any prefix path is immutable (defined as inline table or within one)
        for ($i = 1; $i <= count($keyParts); $i++) {
            $partialPath = implode('.', array_slice($keyParts, 0, $i));

            if (isset($immutablePaths[$partialPath])) {
                throw new TomlParseException(
                    "Cannot extend value '{$partialPath}' - inline tables are immutable",
                    $startToken->line,
                    $startToken->column,
                    $this->source
                );
            }
        }

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
            $this->reportViolation(
                "Cannot redefine key '{$finalKey}' in inline table",
                $startToken->line,
                $startToken->column
            );

            // In lenient mode, skip this assignment (keep original value)
            return;
        }

        $current[$finalKey] = $value;

        // If the value is an inline table (array with string keys), mark it and its implicit paths as immutable
        if (is_array($value)) {
            $fullPath = implode('.', $keyParts);
            $immutablePaths[$fullPath] = true;

            // Also mark all intermediate paths from dotted keys as immutable
            for ($i = 0; $i < count($keyParts) - 1; $i++) {
                $partialPath = implode('.', array_slice($keyParts, 0, $i + 1));
                $immutablePaths[$partialPath] = true;
            }

            // Recursively mark all nested paths within the inline table as immutable
            $this->markNestedPathsImmutable($value, $fullPath, $immutablePaths);
        }
    }

    /**
     * Recursively mark all nested paths within an inline table as immutable.
     *
     * @param  array<int|string, mixed>  $table
     * @param  array<string, bool>  $immutablePaths
     */
    private function markNestedPathsImmutable(array $table, string $basePath, array &$immutablePaths): void
    {
        foreach ($table as $key => $value) {
            $fullPath = $basePath.'.'.$key;
            $immutablePaths[$fullPath] = true;

            if (is_array($value) && ! array_is_list($value)) {
                /** @var array<int|string, mixed> $value */
                $this->markNestedPathsImmutable($value, $fullPath, $immutablePaths);
            }
        }
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
            $this->reportViolation(
                'Leading zeros are not allowed in floats',
                $token->line,
                $token->column
            );
            // In lenient mode, continue parsing the value anyway
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
