# BumpCore JSON Patch

Reusable RFC 6902 JSON Patch and RFC 7396 JSON Merge Patch support for PHP.

The package applies JSON Patch documents, applies JSON Merge Patch documents,
generates deterministic JSON Patch documents from two JSON-like values, and
includes a small RFC 6901 JSON Pointer helper for parsing, escaping, and
addressing document values.

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

## JSON Merge Patch

```php
use BumpCore\JsonPatch\JsonMergePatch;

$document = [
    'title' => 'Goodbye!',
    'author' => [
        'givenName' => 'John',
        'familyName' => 'Doe',
    ],
    'tags' => ['example', 'sample'],
];

$result = JsonMergePatch::apply($document, [
    'title' => 'Hello!',
    'author' => [
        'familyName' => null,
    ],
    'tags' => ['example'],
]);

// [
//     'title' => 'Hello!',
//     'author' => ['givenName' => 'John'],
//     'tags' => ['example'],
// ]
```

`JsonMergePatch::apply()` follows RFC 7396 semantics:

- Object members are added or replaced.
- Object members set to `null` are removed.
- Arrays are replaced as whole values.
- Non-object patches replace the whole target document.

For JSON text input and output, use `JsonMergePatch::applyJson()`:

```php
use BumpCore\JsonPatch\JsonMergePatch;

$json = JsonMergePatch::applyJson(
    '{"a":"b","c":{"d":"e","f":"g"}}',
    '{"a":"z","c":{"f":null}}',
);

// {"a":"z","c":{"d":"e"}}
```

## JSON Pointer

```php
use BumpCore\JsonPatch\JsonPointer;

$pointer = JsonPointer::fromString('/foo/~01');

$pointer->tokens(); // ['foo', '~1']
JsonPointer::escapeToken('a/b~c'); // a~1b~0c
```

Pointer helpers can also read and return modified copies of JSON-like values:

```php
use BumpCore\JsonPatch\JsonPointer;

$document = ['items' => [['name' => 'Old']]];

JsonPointer::get($document, '/items/0/name'); // Old
JsonPointer::has($document, '/items/0/name'); // true

$updated = JsonPointer::set($document, '/items/0/name', 'New');
$removed = JsonPointer::remove($updated, '/items/0');
```

## Errors

All package-specific failures extend:

```php
BumpCore\JsonPatch\Exception\JsonPatchException
```

More specific exceptions are available for invalid patch documents, pointer
resolution failures, and failed `test` operations.

Patch operation failures expose context when it is available:

```php
$exception->operationIndex(); // 0
$exception->operation(); // remove
$exception->path(); // /items/0
$exception->from(); // /source for move/copy failures
```

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
