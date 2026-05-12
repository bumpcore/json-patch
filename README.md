# BumpCore JSON Patch

Reusable RFC 6902 JSON Patch support for PHP.

The package applies JSON Patch documents, generates deterministic JSON Patch
documents from two JSON-like values, and includes a small RFC 6901 JSON Pointer
helper for parsing, escaping, and reusing pointer tokens.

## Installation

```bash
composer require bumpcore/json-patch
```

Requires PHP 8.3 or newer.

## Usage

### Applying Patches

```php
use BumpCore\JsonPatch\JsonPatch;

$document = ['foo' => ['bar', 'baz']];

$patch = [
    ['op' => 'add', 'path' => '/foo/1', 'value' => 'qux'],
];

$result = JsonPatch::apply($document, $patch);

// ['foo' => ['bar', 'qux', 'baz']]
```

`JsonPatch::apply()` accepts PHP values that model JSON:

- PHP lists are treated as JSON arrays.
- `stdClass` objects are treated as JSON objects.
- Non-list PHP arrays are also treated as JSON objects for ergonomic PHP usage.

When exact JSON shape matters, especially empty objects or numeric object member
names, use `JsonPatch::applyJson()`:

```php
use BumpCore\JsonPatch\JsonPatch;

$json = JsonPatch::applyJson(
    '{"child":{}}',
    '[{"op":"add","path":"/child/0","value":"kept as an object member"}]',
);

// {"child":{"0":"kept as an object member"}}
```

### Generating Patches

```php
use BumpCore\JsonPatch\JsonPatch;

$source = ['name' => 'old', 'tags' => ['stable']];
$target = ['name' => 'new', 'tags' => ['stable', 'fast']];

$patch = JsonPatch::diff($source, $target);

// [
//     ['op' => 'replace', 'path' => '/name', 'value' => 'new'],
//     ['op' => 'add', 'path' => '/tags/-', 'value' => 'fast'],
// ]
```

`JsonPatch::diff()` generates readable `add`, `remove`, and `replace`
operations. It intentionally does not infer `move` or `copy` operations because
those require heuristic choices and can make generated patches harder to review.

For JSON text input and output, use `JsonPatch::diffJson()`:

```php
use BumpCore\JsonPatch\JsonPatch;

$patchJson = JsonPatch::diffJson(
    '{"name":"old"}',
    '{"name":"new","enabled":true}',
);

// [{"op":"add","path":"/enabled","value":true},{"op":"replace","path":"/name","value":"new"}]
```

## Supported Operations

- `add`
- `remove`
- `replace`
- `move`
- `copy`
- `test`

Patch application stops at the first failing operation, as RFC 6902 requires.

## JSON Pointer

```php
use BumpCore\JsonPatch\JsonPointer;

$pointer = JsonPointer::fromString('/foo/~01');

$pointer->tokens(); // ['foo', '~1']
JsonPointer::escapeToken('a/b~c'); // a~1b~0c
```

## Errors

All package-specific failures extend:

```php
BumpCore\JsonPatch\Exception\JsonPatchException
```

More specific exceptions are available for invalid patch documents, pointer
resolution failures, and failed `test` operations.

## Quality Gates

```bash
composer install
composer cs:check
composer analyse
composer test
composer test:coverage
```

The test suite includes the active upstream
`json-patch/json-patch-tests` fixtures. `composer test:coverage` enforces 100%
line and method coverage and requires PCOV, Xdebug, or phpdbg.
