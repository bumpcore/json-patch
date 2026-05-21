<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use JsonException;
use stdClass;

/**
 * Applies RFC 7396 JSON Merge Patch documents to JSON-like PHP values.
 */
class JsonMergePatch
{
    public const MEDIA_TYPE = 'application/merge-patch+json';

    /**
     * Applies an RFC 7396 merge patch to a JSON-like PHP value.
     *
     * PHP lists represent JSON arrays. stdClass objects, and non-list PHP
     * arrays, represent JSON objects. Use applyJson() when empty objects or
     * numeric object keys need to be preserved exactly from JSON text.
     *
     * @return mixed JSON values may change type when the patch is not an object.
     */
    public static function apply(mixed $document, mixed $patch): mixed
    {
        return self::mergeValue(self::deepCopy($document), self::deepCopy($patch));
    }

    /**
     * Decodes JSON text, applies a JSON Merge Patch document, and returns encoded JSON.
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

        return json_encode(self::apply($document, $patch), $encodeFlags | JSON_THROW_ON_ERROR);
    }

    /**
     * Applies one merge patch value to one target value.
     */
    private static function mergeValue(mixed $target, mixed $patch): mixed
    {
        if (! self::isJsonObject($patch)) {
            return self::deepCopy($patch);
        }

        if (! self::isJsonObject($target)) {
            $target = $patch instanceof stdClass ? new stdClass() : [];
        }

        foreach (self::objectMembers($patch) as $member => $value) {
            $member = (string) $member;

            if ($value === null) {
                if (self::hasObjectMember($target, $member)) {
                    self::unsetObjectMember($target, $member);
                }

                continue;
            }

            $currentValue = self::hasObjectMember($target, $member)
                ? self::readObjectMember($target, $member)
                : null;

            self::writeObjectMember($target, $member, self::mergeValue($currentValue, $value));
        }

        return $target;
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
     * Returns an object's members as an associative array.
     *
     * @param array<string|int, mixed>|stdClass $object
     *
     * @return array<string|int, mixed>
     */
    private static function objectMembers(array|stdClass $object): array
    {
        if ($object instanceof stdClass) {
            return get_object_vars($object);
        }

        return $object;
    }

    /**
     * Reports whether an object contains a member.
     *
     * @param array<string|int, mixed>|stdClass $object
     */
    private static function hasObjectMember(array|stdClass $object, string $member): bool
    {
        if ($object instanceof stdClass) {
            return property_exists($object, $member);
        }

        return array_key_exists($member, $object);
    }

    /**
     * Reads an object member from stdClass or associative array objects.
     *
     * @param array<string|int, mixed>|stdClass $object
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
     * @param array<string|int, mixed>|stdClass $object
     */
    private static function writeObjectMember(array|stdClass &$object, string $member, mixed $value): void
    {
        if ($object instanceof stdClass) {
            $object->{$member} = self::deepCopy($value);

            return;
        }

        $object[$member] = self::deepCopy($value);
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
     * Copies JSON-like PHP values while rejecting values JSON cannot encode.
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
