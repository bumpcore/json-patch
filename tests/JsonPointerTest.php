<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Tests;

use BumpCore\JsonPatch\Exception\JsonPointerException;
use BumpCore\JsonPatch\JsonPointer;
use PHPUnit\Framework\TestCase;

/**
 * Covers direct JSON Pointer parsing and helper behavior.
 */
final class JsonPointerTest extends TestCase
{
    /**
     * Confirms the empty pointer addresses the whole document.
     */
    public function testItParsesTheRootPointer(): void
    {
        $pointer = JsonPointer::fromString('');

        self::assertTrue($pointer->isRoot());
        self::assertSame([], $pointer->tokens());
        self::assertSame('', (string) $pointer);
    }

    /**
     * Confirms pointer tokens are decoded in RFC 6901 order.
     */
    public function testItParsesAndUnescapesReferenceTokens(): void
    {
        $pointer = JsonPointer::fromString('/foo/~01/~1');

        self::assertFalse($pointer->isRoot());
        self::assertSame(['foo', '~1', '/'], $pointer->tokens());
        self::assertSame('/foo/~01/~1', (string) $pointer);
    }

    /**
     * Confirms reference tokens are escaped for pointer use.
     */
    public function testItEscapesReferenceTokens(): void
    {
        self::assertSame('a~1b~0c', JsonPointer::escapeToken('a/b~c'));
    }

    /**
     * Confirms pointers must be empty or slash-prefixed.
     */
    public function testItRejectsInvalidPointerSyntax(): void
    {
        $this->expectException(JsonPointerException::class);
        $this->expectExceptionMessage('start with "/"');

        JsonPointer::fromString('foo');
    }

    /**
     * Confirms invalid escape sequences fail during parsing.
     */
    public function testItRejectsInvalidEscapeSequences(): void
    {
        $this->expectException(JsonPointerException::class);
        $this->expectExceptionMessage('invalid escape');

        JsonPointer::fromString('/foo/~2');
    }

    /**
     * Confirms proper-prefix checks compare complete pointer tokens.
     */
    public function testItDetectsProperPrefixesByToken(): void
    {
        $parent = JsonPointer::fromString('/a/b');
        $child = JsonPointer::fromString('/a/b/c');
        $differentBranch = JsonPointer::fromString('/a/c/d');
        $sibling = JsonPointer::fromString('/a/bc');

        self::assertTrue($parent->isProperPrefixOf($child));
        self::assertFalse($parent->isProperPrefixOf($differentBranch));
        self::assertFalse($parent->isProperPrefixOf($sibling));
        self::assertFalse($parent->isProperPrefixOf($parent));
    }
}
