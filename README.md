# AppHive TOML

A fast, lightweight TOML parser for PHP with TOML 1.1.0 support.

## Requirements

- PHP 8.3 or higher

## Installation

Install via Composer:

```bash
composer require app-hive/toml
```

## Basic Usage

### Parsing a TOML String

```php
use AppHive\Toml\Toml;

$toml = <<<'TOML'
title = "My Application"
debug = false

[database]
host = "localhost"
port = 5432
name = "myapp"

[database.credentials]
username = "admin"
password = "secret"
TOML;

$config = Toml::parse($toml);

// Access values
echo $config['title'];                          // "My Application"
echo $config['database']['host'];               // "localhost"
echo $config['database']['credentials']['username']; // "admin"
```

### Parsing a TOML File

```php
use AppHive\Toml\Toml;

$config = Toml::parseFile('/path/to/config.toml');
```

## Supported Data Types

### Strings

```toml
# Basic strings (support escape sequences)
basic = "Hello\nWorld"

# Literal strings (no escaping)
literal = 'C:\Users\name'

# Multiline basic strings
multiline_basic = """
First line
Second line"""

# Multiline literal strings
multiline_literal = '''
First line
Second line'''
```

### Numbers

```toml
# Integers
integer = 42
positive = +99
negative = -17
large = 1_000_000

# Different bases
hex = 0xDEADBEEF
octal = 0o755
binary = 0b11010110

# Floats
float = 3.14159
exponent = 5e+22
both = 6.626e-34

# Special float values
infinity = inf
neg_infinity = -inf
not_a_number = nan
```

### Booleans

```toml
enabled = true
disabled = false
```

### Date and Time

```toml
# Offset date-time (with timezone)
odt1 = 1979-05-27T07:32:00Z
odt2 = 1979-05-27T07:32:00-07:00

# Local date-time (no timezone)
ldt = 1979-05-27T07:32:00

# Local date
ld = 1979-05-27

# Local time
lt = 07:32:00
lt_frac = 07:32:00.999999
```

### Arrays

```toml
# Simple array
colors = ["red", "yellow", "green"]

# Mixed types
mixed = ["string", 42, true]

# Nested arrays
nested = [[1, 2], [3, 4]]

# Multiline arrays
hosts = [
    "alpha",
    "omega",
]
```

### Tables

```toml
# Standard table
[server]
host = "localhost"
port = 8080

# Nested tables
[server.ssl]
enabled = true
cert = "/path/to/cert.pem"

# Dotted keys create nested structure
physical.color = "orange"
physical.shape = "round"
```

### Inline Tables

```toml
# Compact table syntax
point = { x = 1, y = 2 }

# With nested values
config = { name = "app", settings = { debug = true } }
```

### Array of Tables

```toml
[[products]]
name = "Hammer"
sku = 738594937

[[products]]
name = "Nail"
sku = 284758393

# Results in:
# [
#     { name = "Hammer", sku = 738594937 },
#     { name = "Nail", sku = 284758393 }
# ]
```

## Parser Configuration

The parser supports two modes via `ParserConfig`:

### Strict Mode (Default)

In strict mode, any spec violation immediately throws a `TomlParseException`. This is the default and recommended mode for production use.

```php
use AppHive\Toml\Toml;
use AppHive\Toml\Parser\ParserConfig;

// These are equivalent - strict mode is the default
$config = Toml::parse($toml);
$config = Toml::parse($toml, ParserConfig::strict());
```

### Lenient Mode

In lenient mode, the parser attempts to continue parsing after encountering spec violations (like duplicate keys or table redefinitions). Violations are collected as warnings that you can retrieve after parsing.

Use cases:
- IDE tooling that needs to show all issues
- Migration tools analyzing legacy TOML files
- Linters reporting multiple violations

```php
use AppHive\Toml\Toml;
use AppHive\Toml\Parser\ParserConfig;

// Create a parser with lenient mode to access warnings
$parser = Toml::createParser($toml, ParserConfig::lenient());
$result = $parser->parse();
$warnings = $parser->getWarnings();

foreach ($warnings as $warning) {
    echo $warning->getMessage();
    echo $warning->getErrorLine();
    echo $warning->getErrorColumn();
}
```

For files:

```php
$parser = Toml::createParserForFile('/path/to/config.toml', ParserConfig::lenient());
$result = $parser->parse();
$warnings = $parser->getWarnings();
```

**Note:** Syntax errors (malformed tokens, unexpected characters) always throw exceptions regardless of mode. Only semantic violations (like duplicate keys or table redefinitions) can be collected as warnings.

## Exception Handling

The parser throws `TomlParseException` for invalid TOML input. The exception provides detailed error information including line and column numbers:

```php
use AppHive\Toml\Toml;
use AppHive\Toml\Exceptions\TomlParseException;

try {
    $config = Toml::parse($invalidToml);
} catch (TomlParseException $e) {
    echo $e->getMessage();     // Error message with location
    echo $e->getErrorLine();   // Line number where error occurred
    echo $e->getErrorColumn(); // Column number where error occurred
    echo $e->getSnippet();     // Code snippet showing error location
}
```

### Common Errors

```php
// Duplicate key
Toml::parse('key = 1' . "\n" . 'key = 2');
// TomlParseException: Cannot redefine key 'key' at line 2, column 1

// Invalid escape sequence
Toml::parse('str = "hello\q"');
// TomlParseException: Invalid escape sequence: \q

// Unterminated string
Toml::parse('str = "hello');
// TomlParseException: Unterminated basic string

// File not found
Toml::parseFile('/nonexistent/file.toml');
// TomlParseException: File does not exist: /nonexistent/file.toml
```

## TOML 1.1.0 Features

This library supports TOML 1.1.0 features including:

### Escape Sequences

- `\e` - Escape character (U+001B)
- `\xNN` - Hexadecimal escape (2 hex digits)

```toml
escape = "Hello\e[31mRed\e[0m"
hex = "\x1B[31mRed\x1B[0m"
```

### Trailing Commas

Arrays and inline tables support trailing commas:

```toml
colors = [
    "red",
    "green",
    "blue",
]

point = {
    x = 1,
    y = 2,
}
```

### Newlines in Inline Tables

Inline tables can span multiple lines:

```toml
config = {
    name = "app",
    version = "1.0.0",
    debug = true,
}
```

## API Reference

### `Toml::parse(string $toml): array`

Parse a TOML string and return an associative array.

**Parameters:**
- `$toml` - The TOML string to parse

**Returns:** An associative array representing the parsed TOML

**Throws:** `TomlParseException` if the input is invalid TOML

### `Toml::parseFile(string $path): array`

Parse a TOML file and return an associative array.

**Parameters:**
- `$path` - Path to the TOML file

**Returns:** An associative array representing the parsed TOML

**Throws:** `TomlParseException` if the file doesn't exist, isn't readable, or contains invalid TOML

## Testing

Run the test suite:

```bash
composer test
```

This runs PHPStan static analysis followed by Pest tests, including the official TOML test suite.

## License

This library is open-sourced software licensed under the [MIT license](LICENSE).
