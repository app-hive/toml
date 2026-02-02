<?php

declare(strict_types=1);

namespace AppHive\Toml\Parser;

/**
 * Configuration options for the TOML parser.
 *
 * Controls parser behavior for handling spec violations:
 *
 * **Strict mode (default):**
 * All spec violations throw TomlParseException immediately.
 * Use for production where invalid TOML should fail fast.
 *
 * **Lenient mode:**
 * Attempts to parse despite spec violations, collecting warnings.
 * Use for tooling, linters, or migration scenarios where you want
 * to identify all issues in a document rather than failing on the first.
 *
 * Note: Syntax errors (malformed tokens, unexpected characters) always
 * throw exceptions regardless of strictness mode. Only semantic violations
 * (like duplicate keys or table redefinitions) can be collected as warnings.
 */
final class ParserConfig
{
    /**
     * Create a new parser configuration.
     *
     * @param  bool  $strict  When true, reject all spec violations with exceptions.
     *                        When false, attempt to parse and collect warnings.
     */
    public function __construct(
        public readonly bool $strict = true,
    ) {}

    /**
     * Create a strict parser configuration.
     *
     * In strict mode, any spec violation immediately throws a TomlParseException.
     * This is the default and recommended mode for production use.
     */
    public static function strict(): self
    {
        return new self(strict: true);
    }

    /**
     * Create a lenient parser configuration.
     *
     * In lenient mode, the parser attempts to continue parsing after
     * encountering spec violations. Violations are collected as warnings
     * accessible via Parser::getWarnings().
     *
     * Use cases:
     * - IDE tooling that needs to show all issues
     * - Migration tools analyzing legacy TOML files
     * - Linters reporting multiple violations
     */
    public static function lenient(): self
    {
        return new self(strict: false);
    }
}
