# BumpCore JSON Patch

BumpCore JSON Patch is a dependency-free PHP package for working with JSON
change documents. It supports RFC 6902 JSON Patch, RFC 7396 JSON Merge Patch,
and RFC 6901 JSON Pointer.

Use it when you need to apply HTTP PATCH payloads, generate deterministic patch
documents, address values inside JSON-like PHP data, or expose precise patch
errors to API clients.

## Table Of Contents

* [Version Table](#version-table)
* [Installation](#installation)
* [Quick Start](#quick-start)
* [Choosing a Patch Format](#choosing-a-patch-format)
* [JSON Patch](#json-patch)
  + [Applying JSON Patch Documents](#applying-json-patch-documents)
  + [Generating JSON Patch Documents](#generating-json-patch-documents)
  + [Supported Operations](#supported-operations)
* [JSON Merge Patch](#json-merge-patch)
* [JSON Pointer](#json-pointer)
* [JSON Values in PHP](#json-values-in-php)
* [Exceptions](#exceptions)
* [Testing](#testing)
* [Contribution](#contribution)
* [Changelog](#changelog)
* [Credits](#credits)
* [License](#license)

## Version Table

| BumpCore JSON Patch | PHP  |
|---------------------|------|
| 0.x                 | ^8.3 |

## Installation

Install the package with Composer:

```bash
composer require bumpcore/json-patch
```

## Quick Start

```php
use BumpCore\JsonPatch\JsonPatch;

$document = ['foo' => ['bar', 'baz']];

$result = JsonPatch::apply($document, [
    ['op' => 'add', 'path' => '/foo/1', 'value' => 'qux'],
]);

// ['foo' => ['bar', 'qux', 'baz']]
```

For JSON string input and output:

```php
use BumpCore\JsonPatch\JsonPatch;

$json = JsonPatch::applyJson(
    '{"foo":["bar","baz"]}',
    '[{"op":"add","path":"/foo/1","value":"qux"}]',
);

// {"foo":["bar","qux","baz"]}
```

## Choosing a Patch Format

This package supports two patch formats because they solve different problems.

Use **JSON Patch** when you need explicit operations:

```json
[
  {"op": "replace", "path": "/title", "value": "Hello!"},
  {"op": "remove", "path": "/author/familyName"}
]
```

Use **JSON Merge Patch** when the patch should look like the document shape:

```json
{
  "title": "Hello!",
  "author": {
    "familyName": null
  }
}
```

JSON Patch can update individual array elements. JSON Merge Patch replaces
arrays as whole values and uses `null` object members as removals.

## JSON Patch

`JsonPatch` implements RFC 6902.

### Applying JSON Patch Documents

```php
use BumpCore\JsonPatch\JsonPatch;

$document = [
    'title' => 'Goodbye!',
    'tags' => ['example', 'sample'],
];

$patched = JsonPatch::apply($document, [
    ['op' => 'replace', 'path' => '/title', 'value' => 'Hello!'],
    ['op' => 'remove', 'path' => '/tags/1'],
    ['op' => 'add', 'path' => '/published', 'value' => true],
]);

// [
//     'title' => 'Hello!',
//     'tags' => ['example'],
//     'published' => true,
// ]
```

Patch application does not mutate the input document. It stops at the first
failing operation, as RFC 6902 requires.

### Generating JSON Patch Documents

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

For JSON text input and output:

```php
use BumpCore\JsonPatch\JsonPatch;

$patchJson = JsonPatch::diffJson(
    '{"name":"old"}',
    '{"name":"new","enabled":true}',
);

// [{"op":"add","path":"/enabled","value":true},{"op":"replace","path":"/name","value":"new"}]
```

### Supported Operations

* `add`
* `remove`
* `replace`
* `move`
* `copy`
* `test`

The package exposes the RFC media type:

```php
JsonPatch::MEDIA_TYPE; // application/json-patch+json
```

## JSON Merge Patch

`JsonMergePatch` implements RFC 7396.

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

$patched = JsonMergePatch::apply($document, [
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

Merge Patch rules are intentionally simple:

* Object members are added or replaced.
* Object members set to `null` are removed.
* Arrays are replaced as whole values.
* Non-object patches replace the whole target document.

For JSON text input and output:

```php
use BumpCore\JsonPatch\JsonMergePatch;

$json = JsonMergePatch::applyJson(
    '{"a":"b","c":{"d":"e","f":"g"}}',
    '{"a":"z","c":{"f":null}}',
);

// {"a":"z","c":{"d":"e"}}
```

The package exposes the RFC media type:

```php
JsonMergePatch::MEDIA_TYPE; // application/merge-patch+json
```

## JSON Pointer

`JsonPointer` implements RFC 6901 pointer parsing and escaping.

```php
use BumpCore\JsonPatch\JsonPointer;

$pointer = JsonPointer::fromString('/foo/~01');

$pointer->tokens(); // ['foo', '~1']
JsonPointer::escapeToken('a/b~c'); // a~1b~0c
JsonPointer::unescapeToken('a~1b~0c'); // a/b~c
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

The helper methods do not mutate the original document.

## JSON Values in PHP

The PHP-value APIs accept JSON-like PHP data:

* PHP lists are treated as JSON arrays.
* `stdClass` objects are treated as JSON objects.
* Non-list PHP arrays are treated as JSON objects for ergonomic PHP usage.
* Non-finite floats and non-JSON PHP values are rejected.

When exact JSON shape matters, especially empty objects or numeric object member
names, use the JSON string helpers:

```php
use BumpCore\JsonPatch\JsonPatch;

$json = JsonPatch::applyJson(
    '{"child":{}}',
    '[{"op":"add","path":"/child/0","value":"kept as an object member"}]',
);

// {"child":{"0":"kept as an object member"}}
```

## Exceptions

All package-specific failures extend:

```php
BumpCore\JsonPatch\Exception\JsonPatchException
```

More specific exceptions are available:

* `InvalidPatchException`
* `JsonPointerException`
* `TestFailedException`

Patch operation failures expose context when it is available:

```php
$exception->operationIndex(); // 0
$exception->operation(); // remove
$exception->path(); // /items/0
$exception->from(); // /source for move/copy failures
```

## Testing

Install development dependencies:

```bash
composer install
```

Run the test suite:

```bash
composer test
```

Run static analysis:

```bash
composer analyse
```

Check code style:

```bash
composer cs:check
```

Run the 100% coverage gate:

```bash
composer test:coverage
```

The test suite includes the active upstream `json-patch/json-patch-tests`
fixtures. Coverage requires PCOV, Xdebug, or phpdbg.

## Contribution

Contributions are welcome. If you find a bug or have a suggestion for
improvement, please open an issue or create a pull request.

Please include tests for behavioral changes and run the quality checks before
submitting a pull request:

```bash
composer cs:check
composer analyse
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## Credits

* [Abdulkadir Cemiloglu](https://github.com/megastive19)
* [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
