<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use BumpCore\JsonPatch\Exception\JsonPointerException;
use stdClass;

/**
 * Parsed RFC 6901 JSON Pointer with escaped token helpers.
 *
 * @phpstan-consistent-constructor
 */
class JsonPointer
{
    /**
     * Stores the original pointer string and its parsed tokens.
     *
     * @param list<string> $tokens
     */
    protected function __construct(
        private readonly string $value,
        private readonly array $tokens,
    ) {
    }

    /**
     * Parses the JSON string representation from RFC 6901.
     */
    public static function fromString(string $pointer): static
    {
        if ($pointer === '') {
            return new static('', []);
        }

        if (! str_starts_with($pointer, '/')) {
            throw new JsonPointerException('JSON Pointer must be empty or start with "/".');
        }

        $tokens = explode('/', substr($pointer, 1));

        foreach ($tokens as $index => $token) {
            $tokens[$index] = self::unescapeToken($token);
        }

        return new static($pointer, $tokens);
    }

    /**
     * Escapes one reference token for use inside a JSON Pointer.
     */
    public static function escapeToken(string $token): string
    {
        if (strpbrk($token, '~/') === false) {
            return $token;
        }

        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /**
     * Unescapes one reference token according to RFC 6901.
     */
    public static function unescapeToken(string $token): string
    {
        if (! str_contains($token, '~')) {
            return $token;
        }

        if (preg_match('/~(?![01])/', $token) === 1) {
            throw new JsonPointerException('JSON Pointer contains an invalid escape sequence.');
        }

        return str_replace(['~1', '~0'], ['/', '~'], $token);
    }

    /**
     * Reads a JSON-like value from a document by pointer.
     */
    public static function get(mixed $document, string|self $pointer): mixed
    {
        $pointer = self::coerce($pointer);

        try {
            return self::import(self::valueAt($document, $pointer->tokens));
        } catch (JsonPointerException $exception) {
            throw $exception->withContext(path: (string) $pointer);
        }
    }

    /**
     * Reports whether a pointer can resolve inside a JSON-like document.
     */
    public static function has(mixed $document, string|self $pointer): bool
    {
        $pointer = self::coerce($pointer);

        try {
            self::assertJsonValue(self::valueAt($document, $pointer->tokens));

            return true;
        } catch (JsonPointerException) {
            return false;
        }
    }

    /**
     * Returns a copy of a JSON-like document with the pointer target set.
     *
     * Object members are created or replaced. Array indexes must already exist.
     */
    public static function set(mixed $document, string|self $pointer, mixed $value): mixed
    {
        $pointer = self::coerce($pointer);

        try {
            return self::setAt(self::import($document), $pointer->tokens, self::import($value));
        } catch (JsonPointerException $exception) {
            throw $exception->withContext(path: (string) $pointer);
        }
    }

    /**
     * Returns a copy of a JSON-like document with the pointer target removed.
     *
     * Removing the root pointer returns null because PHP has no undefined value.
     */
    public static function remove(mixed $document, string|self $pointer): mixed
    {
        $pointer = self::coerce($pointer);

        try {
            return self::removeAt(self::import($document), $pointer->tokens);
        } catch (JsonPointerException $exception) {
            throw $exception->withContext(path: (string) $pointer);
        }
    }

    /**
     * @return list<string>
     */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * Reports whether the pointer addresses the whole document.
     */
    public function isRoot(): bool
    {
        return $this->tokens === [];
    }

    /**
     * Reports whether this pointer is a strict ancestor of another pointer.
     */
    public function isProperPrefixOf(self $other): bool
    {
        if (count($this->tokens) >= count($other->tokens)) {
            return false;
        }

        foreach ($this->tokens as $index => $token) {
            if ($other->tokens[$index] !== $token) {
                return false;
            }
        }

        return true;
    }

    #[\Override]
    /**
     * Returns the original JSON Pointer string.
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Normalizes pointer input to a parsed pointer object.
     */
    private static function coerce(string|self $pointer): self
    {
        if ($pointer instanceof self) {
            return $pointer;
        }

        return self::fromString($pointer);
    }

    /**
     * Reads a nested value by parsed pointer tokens.
     *
     * @param list<string> $tokens
     */
    private static function valueAt(mixed $document, array $tokens): mixed
    {
        $target = $document;

        foreach ($tokens as $token) {
            $target = self::childValue($target, $token);
        }

        return $target;
    }

    /**
     * Sets a nested value by parsed pointer tokens.
     *
     * @param list<string> $tokens
     */
    private static function setAt(mixed $target, array $tokens, mixed $value, int $depth = 0): mixed
    {
        if (! isset($tokens[$depth])) {
            return $value;
        }

        $token = $tokens[$depth];

        if (! isset($tokens[$depth + 1])) {
            if (self::isJsonArray($target)) {
                $target[self::arrayIndexForExistingValue($target, $token)] = $value;

                return $target;
            }

            if (self::isJsonObject($target)) {
                self::writeObjectMember($target, $token, $value);

                return $target;
            }

            throw new JsonPointerException('Cannot set a member on a scalar value.');
        }

        $child = self::childValue($target, $token);
        $updatedChild = self::setAt($child, $tokens, $value, $depth + 1);

        return self::replaceChild($target, $token, $updatedChild);
    }

    /**
     * Removes a nested value by parsed pointer tokens.
     *
     * @param list<string> $tokens
     */
    private static function removeAt(mixed $target, array $tokens, int $depth = 0): mixed
    {
        if (! isset($tokens[$depth])) {
            return null;
        }

        $token = $tokens[$depth];

        if (! isset($tokens[$depth + 1])) {
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
        $updatedChild = self::removeAt($child, $tokens, $depth + 1);

        return self::replaceChild($target, $token, $updatedChild);
    }

    /**
     * Reads one child from a JSON array or object container.
     */
    private static function childValue(mixed $target, string $token): mixed
    {
        if (self::isJsonArray($target)) {
            return $target[self::arrayIndexForExistingValue($target, $token)];
        }

        if (self::isJsonObject($target)) {
            self::assertObjectMemberExists($target, $token);

            return self::readObjectMember($target, $token);
        }

        throw new JsonPointerException('Cannot dereference a scalar value.');
    }

    /**
     * Replaces one child in a JSON array or object container.
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
     * @phpstan-assert-if-true list<mixed> $value
     */
    private static function isJsonArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * Reports whether a PHP value should be treated as a JSON object.
     *
     * @phpstan-assert-if-true array<string|int, mixed>|stdClass $value
     */
    private static function isJsonObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ! array_is_list($value));
    }

    /**
     * Resolves an array index that must already exist.
     *
     * @param list<mixed> $array
     */
    private static function arrayIndexForExistingValue(array $array, string $token): int
    {
        if ($token === '-') {
            throw new JsonPointerException('"-" does not reference an existing array value.');
        }

        if ($token !== '0' && ($token === '' || $token[0] === '0' || strspn($token, '0123456789') !== strlen($token))) {
            throw new JsonPointerException('Array index must be a non-negative integer without leading zeros.');
        }

        $index = (int) $token;

        if (! array_key_exists($index, $array)) {
            throw new JsonPointerException('Array index does not exist.');
        }

        return $index;
    }

    /**
     * Ensures an object member exists before read, replace, or removal.
     *
     * @param array<string|int, mixed>|stdClass $object
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
     * @param array<string|int, mixed>|stdClass $object
     */
    private static function readObjectMember(array|stdClass $object, string $member): mixed
    {
        if ($object instanceof stdClass) {
            return $object->{$member};
        }

        return $object[$member];
    }

    /**
     * Writes an object member on stdClass or associative array objects.
     *
     * @param array<string|int, mixed>|stdClass $object
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
     * @param array<string|int, mixed>|stdClass $object
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
     * Validates a JSON-like value and returns a copy that is safe to hand out.
     *
     * Arrays already behave as values through engine copy-on-write, so they are
     * shared instead of rebuilt. stdClass instances have reference semantics
     * and are cloned, including clones of every array holding one.
     */
    private static function import(mixed $value): mixed
    {
        $containsObject = false;

        return self::importValue($value, $containsObject);
    }

    /**
     * Recursive worker for import() that rebuilds only object-bearing subtrees.
     */
    private static function importValue(mixed $value, bool &$containsObject): mixed
    {
        if (is_array($value)) {
            $result = $value;

            foreach ($value as $key => $child) {
                $childContainsObject = false;
                $copy = self::importValue($child, $childContainsObject);

                if ($childContainsObject) {
                    $containsObject = true;
                    $result[$key] = $copy;
                }
            }

            return $result;
        }

        if ($value instanceof stdClass) {
            $containsObject = true;
            $copy = new stdClass();

            foreach (get_object_vars($value) as $name => $child) {
                $copy->{$name} = self::import($child);
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

    /**
     * Validates a JSON-like value without copying any part of it.
     */
    private static function assertJsonValue(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertJsonValue($child);
            }

            return;
        }

        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $child) {
                self::assertJsonValue($child);
            }

            return;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidPatchException('JSON numbers must be finite.');
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        throw new InvalidPatchException(sprintf('Unsupported JSON value of type %s.', get_debug_type($value)));
    }
}
