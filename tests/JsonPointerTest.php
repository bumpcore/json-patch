<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Tests;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use BumpCore\JsonPatch\Exception\JsonPointerException;
use BumpCore\JsonPatch\JsonPointer;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Covers direct JSON Pointer parsing and helper behavior.
 */
class JsonPointerTest extends TestCase
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
     * Confirms subclasses can reuse the pointer factory after removing final modifiers.
     */
    public function testItPreservesSubclassTypeWhenParsingPointers(): void
    {
        $pointer = JsonPointerTestPointer::fromString('/foo');

        self::assertInstanceOf(JsonPointerTestPointer::class, $pointer);
        self::assertSame(['foo'], $pointer->tokens());
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

    /**
     * Confirms pointer helpers can read nested values without leaking references.
     */
    public function testItReadsValuesFromDocuments(): void
    {
        $document = (object) [
            'foo' => (object) [
                'bar' => ['baz'],
            ],
            'escaped/key~' => 'escaped',
        ];

        self::assertSame('baz', JsonPointer::get($document, '/foo/bar/0'));
        self::assertSame('escaped', JsonPointer::get($document, JsonPointer::fromString('/escaped~1key~0')));

        $read = JsonPointer::get($document, '/foo');
        self::assertInstanceOf(stdClass::class, $read);

        $read->bar[0] = 'changed';

        self::assertSame('baz', $document->foo->bar[0]);
    }

    /**
     * Confirms pointer helpers report existence for valid pointer targets.
     */
    public function testItReportsPointerExistence(): void
    {
        $document = [
            'foo' => [
                'bar' => null,
            ],
        ];

        self::assertTrue(JsonPointer::has($document, ''));
        self::assertTrue(JsonPointer::has($document, '/foo/bar'));
        self::assertFalse(JsonPointer::has($document, '/missing'));
        self::assertFalse(JsonPointer::has($document, '/foo/bar/baz'));
        self::assertFalse(JsonPointer::has((object) [], '/missing'));
    }

    /**
     * Confirms pointer helpers set root, object, and array values without mutating the input.
     */
    public function testItSetsValuesInDocuments(): void
    {
        $document = (object) [
            'items' => [
                (object) ['name' => 'old'],
            ],
            'config' => (object) [],
        ];

        $result = JsonPointer::set($document, '/items/0/name', 'new');
        $result = JsonPointer::set($result, '/config/enabled', true);

        self::assertInstanceOf(stdClass::class, $result);
        self::assertIsArray($result->items);
        self::assertInstanceOf(stdClass::class, $result->items[0]);
        self::assertInstanceOf(stdClass::class, $result->config);
        self::assertSame('old', $document->items[0]->name);
        self::assertSame('new', $result->items[0]->name);
        self::assertTrue($result->config->enabled);
        self::assertSame(
            ['config' => ['enabled' => true]],
            JsonPointer::set(['config' => ['enabled' => false]], '/config/enabled', true),
        );
        self::assertSame(['new'], JsonPointer::set(['old'], '/0', 'new'));
        self::assertSame(['root'], JsonPointer::set('old', '', ['root']));
    }

    /**
     * Confirms pointer helpers remove root, object, and array values without mutating the input.
     */
    public function testItRemovesValuesFromDocuments(): void
    {
        $document = (object) [
            'keep' => true,
            'remove' => false,
            'items' => ['first', 'second'],
        ];

        $result = JsonPointer::remove($document, '/remove');
        $result = JsonPointer::remove($result, '/items/0');

        self::assertEquals((object) ['keep' => true, 'remove' => false, 'items' => ['first', 'second']], $document);
        self::assertInstanceOf(stdClass::class, $result);
        self::assertObjectNotHasProperty('remove', $result);
        self::assertSame(['second'], $result->items);
        self::assertSame(['keep' => true], JsonPointer::remove(['keep' => true, 'drop' => false], '/drop'));
        self::assertNull(JsonPointer::remove(['root'], ''));
    }

    /**
     * Confirms pointer helper failures expose pointer context.
     */
    public function testItAddsPointerContextToHelperFailures(): void
    {
        try {
            JsonPointer::get(['items' => []], '/items/0/name');
        } catch (JsonPointerException $exception) {
            self::assertSame('/items/0/name', $exception->path());
            self::assertNull($exception->operationIndex());
            self::assertNull($exception->operation());
            self::assertNull($exception->from());

            return;
        }

        self::fail('Pointer lookup should have failed.');
    }

    /**
     * Confirms array pointers must identify existing numeric indexes.
     */
    public function testItRejectsInvalidArrayIndexesInHelpers(): void
    {
        foreach (['/-', '/01', '/2'] as $pointer) {
            try {
                JsonPointer::get(['first'], $pointer);
            } catch (JsonPointerException $exception) {
                self::assertSame($pointer, $exception->path());

                continue;
            }

            self::fail(sprintf('Pointer %s should have failed.', $pointer));
        }
    }

    /**
     * Confirms pointer helpers reject writes and removals through scalar values.
     */
    public function testItRejectsScalarTargetsForPointerMutations(): void
    {
        $setFailed = false;

        try {
            JsonPointer::set('scalar', '/name', 'value');
        } catch (JsonPointerException $exception) {
            $setFailed = true;
            self::assertSame('/name', $exception->path());
        }

        self::assertTrue($setFailed);

        try {
            JsonPointer::remove('scalar', '/name');
        } catch (JsonPointerException $exception) {
            self::assertSame('/name', $exception->path());

            return;
        }

        self::fail('Scalar removal should have failed.');
    }

    /**
     * Confirms internal replacement guards reject scalar containers.
     */
    public function testItRejectsUnexpectedScalarReplacementTargets(): void
    {
        $this->expectException(JsonPointerException::class);

        $replaceChild = new \ReflectionMethod(JsonPointer::class, 'replaceChild');
        $replaceChild->invoke(null, 'scalar', 'foo', 'bar');
    }

    /**
     * Confirms pointer helpers reject non-JSON PHP values.
     */
    public function testItRejectsUnsupportedPointerValues(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('Unsupported JSON value');

        JsonPointer::get($resource, '');
    }

    /**
     * Confirms pointer helpers reject non-finite JSON numbers.
     */
    public function testItRejectsNonFinitePointerNumbers(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('finite');

        JsonPointer::set([], '', INF);
    }

    /**
     * Confirms existence checks validate object targets recursively.
     */
    public function testItReportsExistenceForObjectTargets(): void
    {
        $document = (object) [
            'config' => (object) ['nested' => (object) ['flag' => true]],
        ];

        self::assertTrue(JsonPointer::has($document, '/config'));
    }

    /**
     * Confirms existence checks reject non-finite numbers at the target.
     */
    public function testItRejectsNonFiniteNumbersInExistenceChecks(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('finite');

        JsonPointer::has(['bad' => INF], '/bad');
    }

    /**
     * Confirms existence checks reject unsupported values at the target.
     */
    public function testItRejectsUnsupportedValuesInExistenceChecks(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('Unsupported JSON value');

        JsonPointer::has(['bad' => $resource], '/bad');
    }

    /**
     * Confirms mutating a returned document never writes through to the input document.
     */
    public function testItSetsValuesWithoutSharingObjectsBetweenInputAndResult(): void
    {
        $document = (object) [
            'touched' => (object) ['name' => 'original'],
            'untouched' => (object) ['name' => 'stable'],
        ];

        $result = JsonPointer::set($document, '/touched/name', 'changed');

        self::assertInstanceOf(stdClass::class, $result);

        $result->untouched->name = 'mutated after set';

        self::assertSame('stable', $document->untouched->name);
    }
}

/**
 * Test subclass for verifying JsonPointer inheritance support.
 */
class JsonPointerTestPointer extends JsonPointer
{
    // ...
}
