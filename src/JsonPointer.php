<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch;

use BumpCore\JsonPatch\Exception\JsonPointerException;

/**
 * Parsed RFC 6901 JSON Pointer with escaped token helpers.
 */
final class JsonPointer
{
    /**
     * Stores the original pointer string and its parsed tokens.
     *
     * @param list<string> $tokens
     */
    private function __construct(
        private readonly string $value,
        private readonly array $tokens,
    ) {
    }

    /**
     * Parses the JSON string representation from RFC 6901.
     */
    public static function fromString(string $pointer): self
    {
        if ($pointer === '') {
            return new self('', []);
        }

        if (! str_starts_with($pointer, '/')) {
            throw new JsonPointerException('JSON Pointer must be empty or start with "/".');
        }

        $tokens = array_map(
            static fn (string $token): string => self::unescapeToken($token),
            explode('/', substr($pointer, 1)),
        );

        return new self($pointer, $tokens);
    }

    /**
     * Escapes one reference token for use inside a JSON Pointer.
     */
    public static function escapeToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /**
     * Unescapes one reference token according to RFC 6901.
     */
    public static function unescapeToken(string $token): string
    {
        if (preg_match('/~(?![01])/', $token) === 1) {
            throw new JsonPointerException('JSON Pointer contains an invalid escape sequence.');
        }

        return str_replace(['~1', '~0'], ['/', '~'], $token);
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
}
