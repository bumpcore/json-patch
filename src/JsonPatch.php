<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use BumpCore\JsonPatch\Exception\JsonPatchException;
use BumpCore\JsonPatch\Exception\JsonPointerException;
use BumpCore\JsonPatch\Exception\TestFailedException;
use JsonException;
use stdClass;

/**
 * Applies RFC 6902 JSON Patch documents to JSON-like PHP values.
 *
 * @phpstan-type JsonScalar bool|int|float|string|null
 * @phpstan-type JsonArray list<mixed>
 * @phpstan-type JsonObject array<string|int, mixed>|stdClass
 * @phpstan-type JsonValue JsonScalar|JsonArray|JsonObject
 * @phpstan-type JsonPatchArrayOperation array<string|int, mixed>
 * @phpstan-type JsonPatchOperation JsonPatchArrayOperation|stdClass
 * @phpstan-type JsonPatchDocument list<JsonPatchOperation>
 * @phpstan-type GeneratedAddOperation array{op: 'add', path: string, value: JsonValue}
 * @phpstan-type GeneratedRemoveOperation array{op: 'remove', path: string}
 * @phpstan-type GeneratedReplaceOperation array{op: 'replace', path: string, value: JsonValue}
 * @phpstan-type GeneratedPatchOperation GeneratedAddOperation|GeneratedRemoveOperation|GeneratedReplaceOperation
 * @phpstan-type GeneratedPatchDocument list<GeneratedPatchOperation>
 */
final class JsonPatch
{
    public const MEDIA_TYPE = 'application/json-patch+json';

    /**
     * Applies an RFC 6902 patch to a JSON-like PHP value.
     *
     * PHP lists represent JSON arrays. stdClass objects, and non-list PHP
     * arrays, represent JSON objects. Use applyJson() when empty objects or
     * numeric object keys need to be preserved exactly from JSON text.
     *
     * @param array<mixed> $patch
     *
     * @return mixed JSON values may change type when a patch targets the root.
     */
    public static function apply(mixed $document, array $patch): mixed
    {
        $patchOperations = self::patchOperations($patch);
        $workingDocument = self::deepCopy($document);

        foreach ($patchOperations as $index => $operation) {
            $workingDocument = self::applyOperation($workingDocument, $operation, $index);
        }

        return $workingDocument;
    }

    /**
     * Decodes JSON text, applies a JSON Patch document, and returns encoded JSON.
     *
     * @throws JsonException
     */
    public static function applyJson(
        string $documentJson,
        string $patchJson,
        int $encodeFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): string {
        $document = json_decode($documentJson, false, 512, JSON_THROW_ON_ERROR);
        $patch = json_decode($patchJson, false, 512, JSON_THROW_ON_ERROR);

        if (! is_array($patch)) {
            throw new InvalidPatchException('A JSON Patch document must decode to an array.');
        }

        return json_encode(self::apply($document, $patch), $encodeFlags | JSON_THROW_ON_ERROR);
    }

    /**
     * Builds a deterministic JSON Patch document that transforms source into target.
     *
     * The generator favors readable add, remove, and replace operations. It does
     * not infer move or copy operations because those require heuristic choices.
     *
     * @param mixed $source
     * @param mixed $target
     *
     * @phpstan-return GeneratedPatchDocument
     */
    public static function diff(mixed $source, mixed $target): array
    {
        $source = self::deepCopy($source);
        $target = self::deepCopy($target);

        return self::diffValue($source, $target, []);
    }

    /**
     * Decodes two JSON documents and returns an encoded JSON Patch document.
     *
     * @throws JsonException
     */
    public static function diffJson(
        string $sourceJson,
        string $targetJson,
        int $encodeFlags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ): string {
        $source = json_decode($sourceJson, false, 512, JSON_THROW_ON_ERROR);
        $target = json_decode($targetJson, false, 512, JSON_THROW_ON_ERROR);

        return json_encode(self::diff($source, $target), $encodeFlags | JSON_THROW_ON_ERROR);
    }

    /**
     * Builds patch operations for one JSON value pair.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-param JsonValue $source
     * @phpstan-param JsonValue $target
     *
     * @phpstan-return GeneratedPatchDocument
     */
    private static function diffValue(mixed $source, mixed $target, array $pathTokens): array
    {
        if (self::jsonValuesAreEqual($source, $target)) {
            return [];
        }

        if (self::isJsonArray($source) && self::isJsonArray($target)) {
            return self::diffArray($source, $target, $pathTokens);
        }

        if (self::isJsonObject($source) && self::isJsonObject($target)) {
            return self::diffObject($source, $target, $pathTokens);
        }

        return [
            self::replaceOperation($pathTokens, $target),
        ];
    }

    /**
     * Builds patch operations for one JSON array pair.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-param JsonArray $source
     * @phpstan-param JsonArray $target
     *
     * @phpstan-return GeneratedPatchDocument
     */
    private static function diffArray(array $source, array $target, array $pathTokens): array
    {
        $operations = [];
        $sharedLength = min(count($source), count($target));

        for ($index = 0; $index < $sharedLength; $index++) {
            array_push(
                $operations,
                ...self::diffValue(
                    self::deepCopy($source[$index]),
                    self::deepCopy($target[$index]),
                    [...$pathTokens, (string) $index],
                ),
            );
        }

        for ($index = count($source) - 1; $index >= count($target); $index--) {
            $operations[] = self::removeOperation([...$pathTokens, (string) $index]);
        }

        for ($index = count($source); $index < count($target); $index++) {
            $operations[] = self::addOperation([...$pathTokens, '-'], self::deepCopy($target[$index]));
        }

        return $operations;
    }

    /**
     * Builds patch operations for one JSON object pair.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-param JsonObject $source
     * @phpstan-param JsonObject $target
     *
     * @phpstan-return GeneratedPatchDocument
     */
    private static function diffObject(array|stdClass $source, array|stdClass $target, array $pathTokens): array
    {
        $sourceMembers = self::objectMembers($source);
        $targetMembers = self::objectMembers($target);
        $operations = [];

        foreach ($sourceMembers as $member => $value) {
            if (! array_key_exists($member, $targetMembers)) {
                $operations[] = self::removeOperation([...$pathTokens, (string) $member]);
            }
        }

        foreach ($targetMembers as $member => $value) {
            if (! array_key_exists($member, $sourceMembers)) {
                $operations[] = self::addOperation([...$pathTokens, (string) $member], self::deepCopy($value));
            }
        }

        foreach ($targetMembers as $member => $targetValue) {
            if (array_key_exists($member, $sourceMembers)) {
                array_push(
                    $operations,
                    ...self::diffValue(
                        self::deepCopy($sourceMembers[$member]),
                        self::deepCopy($targetValue),
                        [...$pathTokens, (string) $member],
                    ),
                );
            }
        }

        return $operations;
    }

    /**
     * Returns an object's members as an associative array.
     *
     * @phpstan-param JsonObject $object
     *
     * @phpstan-return array<string|int, mixed>
     */
    private static function objectMembers(array|stdClass $object): array
    {
        if ($object instanceof stdClass) {
            return get_object_vars($object);
        }

        return $object;
    }

    /**
     * Creates an add operation for a generated patch.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return GeneratedAddOperation
     */
    private static function addOperation(array $pathTokens, mixed $value): array
    {
        return [
            'op' => 'add',
            'path' => self::pathFromTokens($pathTokens),
            'value' => self::deepCopy($value),
        ];
    }

    /**
     * Creates a remove operation for a generated patch.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-return GeneratedRemoveOperation
     */
    private static function removeOperation(array $pathTokens): array
    {
        return [
            'op' => 'remove',
            'path' => self::pathFromTokens($pathTokens),
        ];
    }

    /**
     * Creates a replace operation for a generated patch.
     *
     * @param list<string> $pathTokens
     *
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return GeneratedReplaceOperation
     */
    private static function replaceOperation(array $pathTokens, mixed $value): array
    {
        return [
            'op' => 'replace',
            'path' => self::pathFromTokens($pathTokens),
            'value' => self::deepCopy($value),
        ];
    }

    /**
     * Formats parsed path tokens as a JSON Pointer string.
     *
     * @param list<string> $pathTokens
     */
    private static function pathFromTokens(array $pathTokens): string
    {
        if ($pathTokens === []) {
            return '';
        }

        return '/' . implode(
            '/',
            array_map(static fn (string $token): string => JsonPointer::escapeToken($token), $pathTokens),
        );
    }

    /**
     * Validates the top-level JSON Patch document shape.
     *
     * @param array<mixed> $patch
     *
     * @phpstan-return JsonPatchDocument
     */
    private static function patchOperations(array $patch): array
    {
        if (! array_is_list($patch)) {
            throw new InvalidPatchException('A JSON Patch document must be an array of operations.');
        }

        $operations = [];

        foreach ($patch as $index => $operation) {
            if (! is_array($operation) && ! $operation instanceof stdClass) {
                throw new InvalidPatchException(sprintf('Operation %d must be an object.', $index));
            }

            $operations[] = $operation;
        }

        return $operations;
    }

    /**
     * Dispatches one validated operation object by its op member.
     *
     * @phpstan-param JsonValue $document
     * @phpstan-param JsonPatchOperation $operation
     *
     * @phpstan-return JsonValue
     */
    private static function applyOperation(mixed $document, array|stdClass $operation, int $index): mixed
    {
        $op = self::operationMember($operation, 'op', $index);
        $path = JsonPointer::fromString(self::operationMember($operation, 'path', $index));

        return match ($op) {
            'add' => self::add($document, $path, self::operationValue($operation, 'value', $index)),
            'remove' => self::remove($document, $path),
            'replace' => self::replace($document, $path, self::operationValue($operation, 'value', $index)),
            'move' => self::move($document, JsonPointer::fromString(self::operationMember($operation, 'from', $index)), $path),
            'copy' => self::copy($document, JsonPointer::fromString(self::operationMember($operation, 'from', $index)), $path),
            'test' => self::test($document, $path, self::operationValue($operation, 'value', $index)),
            default => throw new InvalidPatchException(sprintf('Unsupported operation "%s".', $op)),
        };
    }

    /**
     * Reads a required string member from an operation object.
     *
     * @phpstan-param JsonPatchOperation $operation
     */
    private static function operationMember(array|stdClass $operation, string $name, int $index): string
    {
        if (! self::hasMember($operation, $name)) {
            throw new InvalidPatchException(sprintf('Operation %d is missing "%s".', $index, $name));
        }

        $value = self::readMember($operation, $name);

        if (! is_string($value)) {
            throw new InvalidPatchException(sprintf('Operation %d member "%s" must be a string.', $index, $name));
        }

        return $value;
    }

    /**
     * Reads a required JSON value member from an operation object.
     *
     * @phpstan-param JsonPatchOperation $operation
     *
     * @phpstan-return JsonValue
     */
    private static function operationValue(array|stdClass $operation, string $name, int $index): mixed
    {
        if (! self::hasMember($operation, $name)) {
            throw new InvalidPatchException(sprintf('Operation %d is missing "%s".', $index, $name));
        }

        return self::deepCopy(self::readMember($operation, $name));
    }

    /**
     * Reports whether an operation object contains a member.
     *
     * @phpstan-param JsonPatchOperation $operation
     */
    private static function hasMember(array|stdClass $operation, string $name): bool
    {
        if ($operation instanceof stdClass) {
            return property_exists($operation, $name);
        }

        return array_key_exists($name, $operation);
    }

    /**
     * Reads a member from an operation object after existence is known.
     *
     * @phpstan-param JsonPatchOperation $operation
     */
    private static function readMember(array|stdClass $operation, string $name): mixed
    {
        if ($operation instanceof stdClass) {
            return $operation->{$name};
        }

        return $operation[$name];
    }

    /**
     * Applies the add operation.
     *
     * @phpstan-param JsonValue $document
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return JsonValue
     */
    private static function add(mixed $document, JsonPointer $path, mixed $value): mixed
    {
        return self::addAt($document, $path->tokens(), $value);
    }

    /**
     * Applies the remove operation.
     *
     * @phpstan-param JsonValue $document
     *
     * @phpstan-return JsonArray|JsonObject|null
     */
    private static function remove(mixed $document, JsonPointer $path): mixed
    {
        return self::removeAt($document, $path->tokens());
    }

    /**
     * Applies the replace operation.
     *
     * @phpstan-param JsonValue $document
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return JsonValue
     */
    private static function replace(mixed $document, JsonPointer $path, mixed $value): mixed
    {
        self::valueAt($document, $path);

        return self::replaceAt($document, $path->tokens(), $value);
    }

    /**
     * Applies the move operation.
     *
     * @phpstan-param JsonValue $document
     *
     * @phpstan-return JsonValue
     */
    private static function move(mixed $document, JsonPointer $from, JsonPointer $path): mixed
    {
        if ($from->isProperPrefixOf($path)) {
            throw new JsonPatchException('A value cannot be moved into one of its children.');
        }

        $value = self::valueAt($document, $from);
        $document = self::remove($document, $from);

        return self::add($document, $path, $value);
    }

    /**
     * Applies the copy operation.
     *
     * @phpstan-param JsonValue $document
     *
     * @phpstan-return JsonValue
     */
    private static function copy(mixed $document, JsonPointer $from, JsonPointer $path): mixed
    {
        return self::add($document, $path, self::deepCopy(self::valueAt($document, $from)));
    }

    /**
     * Applies the test operation.
     *
     * @phpstan-param JsonValue $document
     * @phpstan-param JsonValue $expected
     *
     * @phpstan-return JsonValue
     */
    private static function test(mixed $document, JsonPointer $path, mixed $expected): mixed
    {
        $actual = self::valueAt($document, $path);

        if (! self::jsonValuesAreEqual($actual, $expected)) {
            throw new TestFailedException(sprintf('Value at "%s" did not match the expected value.', (string) $path));
        }

        return $document;
    }

    /**
     * Adds a value at the provided token path.
     *
     * @param list<string> $tokens
     *
     * @phpstan-param JsonValue $target
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return JsonValue
     */
    private static function addAt(mixed $target, array $tokens, mixed $value): mixed
    {
        if ($tokens === []) {
            return $value;
        }

        $token = array_shift($tokens);

        if ($tokens === []) {
            if (self::isJsonArray($target)) {
                array_splice($target, self::arrayIndexForAdd($target, $token), 0, [$value]);

                return $target;
            }

            if (self::isJsonObject($target)) {
                self::writeObjectMember($target, $token, $value);

                return $target;
            }

            throw new JsonPointerException('Cannot add a member to a scalar value.');
        }

        $child = self::childValue($target, $token);
        $updatedChild = self::addAt($child, $tokens, $value);

        return self::replaceChild($target, $token, $updatedChild);
    }

    /**
     * Removes a value at the provided token path.
     *
     * @param list<string> $tokens
     *
     * @phpstan-param JsonValue $target
     *
     * @phpstan-return JsonArray|JsonObject|null
     */
    private static function removeAt(mixed $target, array $tokens): mixed
    {
        if ($tokens === []) {
            return null;
        }

        $token = array_shift($tokens);

        if ($tokens === []) {
            if (self::isJsonArray($target)) {
                array_splice($target, self::arrayIndexForExistingValue($target, $token), 1);

                return $target;
            }

            if (self::isJsonObject($target)) {
                self::assertObjectMemberExists($target, $token);
                self::unsetObjectMember($target, $token);

                return $target;
            }

            throw new JsonPointerException('Cannot remove a member from a scalar value.');
        }

        $child = self::childValue($target, $token);
        $updatedChild = self::removeAt($child, $tokens);

        return self::replaceChild($target, $token, $updatedChild);
    }

    /**
     * Replaces a value at the provided token path.
     *
     * @param list<string> $tokens
     *
     * @phpstan-param JsonValue $target
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return JsonValue
     */
    private static function replaceAt(mixed $target, array $tokens, mixed $value): mixed
    {
        if ($tokens === []) {
            return $value;
        }

        $token = array_shift($tokens);

        if ($tokens === []) {
            return self::replaceChild($target, $token, $value);
        }

        $child = self::childValue($target, $token);
        $updatedChild = self::replaceAt($child, $tokens, $value);

        return self::replaceChild($target, $token, $updatedChild);
    }

    /**
     * Reads the value addressed by a pointer.
     *
     * @phpstan-param JsonValue $document
     *
     * @phpstan-return JsonValue
     */
    private static function valueAt(mixed $document, JsonPointer $path): mixed
    {
        $target = $document;

        foreach ($path->tokens() as $token) {
            $target = self::childValue($target, $token);
        }

        return $target;
    }

    /**
     * Reads one child from a JSON array or object container.
     *
     * @phpstan-param JsonValue $target
     *
     * @phpstan-return JsonValue
     */
    private static function childValue(mixed $target, string $token): mixed
    {
        if (self::isJsonArray($target)) {
            return self::deepCopy($target[self::arrayIndexForExistingValue($target, $token)]);
        }

        if (self::isJsonObject($target)) {
            self::assertObjectMemberExists($target, $token);

            return self::readObjectMember($target, $token);
        }

        throw new JsonPointerException('Cannot dereference a scalar value.');
    }

    /**
     * Replaces one child in a JSON array or object container.
     *
     * @phpstan-param JsonValue $target
     * @phpstan-param JsonValue $value
     *
     * @phpstan-return JsonArray|JsonObject
     */
    private static function replaceChild(mixed $target, string $token, mixed $value): mixed
    {
        if (self::isJsonArray($target)) {
            $target[self::arrayIndexForExistingValue($target, $token)] = $value;

            return $target;
        }

        if (self::isJsonObject($target)) {
            self::assertObjectMemberExists($target, $token);
            self::writeObjectMember($target, $token, $value);

            return $target;
        }

        throw new JsonPointerException('Cannot replace a member on a scalar value.');
    }

    /**
     * Reports whether a PHP value should be treated as a JSON array.
     *
     * @phpstan-assert-if-true JsonArray $value
     */
    private static function isJsonArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Reports whether a PHP value should be treated as a JSON object.
     *
     * @phpstan-assert-if-true JsonObject $value
     */
    private static function isJsonObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ! array_is_list($value));
    }

    /**
     * Resolves an array index for add, where "-" means append.
     *
     * @phpstan-param JsonArray $array
     */
    private static function arrayIndexForAdd(array $array, string $token): int
    {
        if ($token === '-') {
            return count($array);
        }

        $index = self::arrayIndex($token);

        if ($index > count($array)) {
            throw new JsonPointerException('Array index is greater than the array length.');
        }

        return $index;
    }

    /**
     * Resolves an array index that must already exist.
     *
     * @phpstan-param JsonArray $array
     */
    private static function arrayIndexForExistingValue(array $array, string $token): int
    {
        if ($token === '-') {
            throw new JsonPointerException('"-" does not reference an existing array value.');
        }

        $index = self::arrayIndex($token);

        if (! array_key_exists($index, $array)) {
            throw new JsonPointerException('Array index does not exist.');
        }

        return $index;
    }

    /**
     * Parses a JSON Pointer array token as an RFC-compatible index.
     */
    private static function arrayIndex(string $token): int
    {
        if (preg_match('/^(0|[1-9][0-9]*)$/', $token) !== 1) {
            throw new JsonPointerException('Array index must be a non-negative integer without leading zeros.');
        }

        return (int) $token;
    }

    /**
     * Ensures an object member exists before read, replace, or removal.
     *
     * @phpstan-param JsonObject $object
     */
    private static function assertObjectMemberExists(array|stdClass $object, string $member): void
    {
        if ($object instanceof stdClass) {
            if (! property_exists($object, $member)) {
                throw new JsonPointerException(sprintf('Object member "%s" does not exist.', $member));
            }

            return;
        }

        if (! array_key_exists($member, $object)) {
            throw new JsonPointerException(sprintf('Object member "%s" does not exist.', $member));
        }
    }

    /**
     * Reads an object member from stdClass or associative array objects.
     *
     * @phpstan-param JsonObject $object
     *
     * @phpstan-return JsonValue
     */
    private static function readObjectMember(array|stdClass $object, string $member): mixed
    {
        if ($object instanceof stdClass) {
            return self::deepCopy($object->{$member});
        }

        return self::deepCopy($object[$member]);
    }

    /**
     * Writes an object member on stdClass or associative array objects.
     *
     * @phpstan-param JsonObject $object
     * @phpstan-param JsonValue $value
     */
    private static function writeObjectMember(array|stdClass &$object, string $member, mixed $value): void
    {
        if ($object instanceof stdClass) {
            $object->{$member} = $value;

            return;
        }

        $object[$member] = $value;
    }

    /**
     * Removes an object member from stdClass or associative array objects.
     *
     * @phpstan-param JsonObject $object
     */
    private static function unsetObjectMember(array|stdClass &$object, string $member): void
    {
        if ($object instanceof stdClass) {
            unset($object->{$member});

            return;
        }

        unset($object[$member]);
    }

    /**
     * Compares two PHP values using JSON value equality rules.
     *
     * @phpstan-param JsonValue $left
     * @phpstan-param JsonValue $right
     */
    private static function jsonValuesAreEqual(mixed $left, mixed $right): bool
    {
        if (self::isJsonNumber($left) && self::isJsonNumber($right)) {
            return (float) $left === (float) $right;
        }

        if (get_debug_type($left) !== get_debug_type($right)) {
            if (! self::sameJsonContainerType($left, $right)) {
                return false;
            }
        }

        if (is_array($left) && is_array($right)) {
            return self::arraysAreEqual($left, $right);
        }

        if ($left instanceof stdClass && $right instanceof stdClass) {
            return self::objectsAreEqual(get_object_vars($left), get_object_vars($right));
        }

        if ($left instanceof stdClass && is_array($right) && ! array_is_list($right)) {
            return self::objectsAreEqual(get_object_vars($left), $right);
        }

        if (is_array($left) && ! array_is_list($left) && $right instanceof stdClass) {
            return self::objectsAreEqual($left, get_object_vars($right));
        }

        return $left === $right;
    }

    /**
     * Reports whether a PHP value represents a JSON number.
     *
     * @phpstan-assert-if-true int|float $value
     */
    private static function isJsonNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value);
    }

    /**
     * Reports whether two PHP containers share the same JSON container type.
     *
     * @phpstan-param JsonValue $left
     * @phpstan-param JsonValue $right
     */
    private static function sameJsonContainerType(mixed $left, mixed $right): bool
    {
        return self::isJsonArray($left) && self::isJsonArray($right)
            || self::isJsonObject($left) && self::isJsonObject($right);
    }

    /**
     * Compares JSON arrays, including associative arrays treated as objects.
     *
     * @template TKey of array-key
     *
     * @phpstan-param array<TKey, mixed> $left
     * @phpstan-param array<TKey, mixed> $right
     */
    private static function arraysAreEqual(array $left, array $right): bool
    {
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }

        if (! array_is_list($left)) {
            return self::objectsAreEqual($left, $right);
        }

        foreach ($left as $index => $value) {
            if (! self::jsonValuesAreEqual(self::deepCopy($value), self::deepCopy($right[$index]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compares JSON object members without depending on member order.
     *
     * @phpstan-param array<string|int, mixed> $left
     * @phpstan-param array<string|int, mixed> $right
     */
    private static function objectsAreEqual(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right)) {
                return false;
            }

            if (! self::jsonValuesAreEqual(self::deepCopy($value), self::deepCopy($right[$key]))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Copies JSON-like PHP values while rejecting values JSON cannot encode.
     *
     * @phpstan-return JsonValue
     */
    private static function deepCopy(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $child): mixed => self::deepCopy($child), $value);
        }

        if ($value instanceof stdClass) {
            $copy = new stdClass();

            foreach (get_object_vars($value) as $name => $child) {
                $copy->{$name} = self::deepCopy($child);
            }

            return $copy;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidPatchException('JSON numbers must be finite.');
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw new InvalidPatchException(sprintf('Unsupported JSON value of type %s.', get_debug_type($value)));
    }
}
