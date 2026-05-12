<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Tests;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use BumpCore\JsonPatch\Exception\JsonPatchException;
use BumpCore\JsonPatch\Exception\TestFailedException;
use BumpCore\JsonPatch\JsonPatch;
use JsonException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Covers package API behavior that is not fully expressed by upstream fixtures.
 */
final class JsonPatchTest extends TestCase
{
    /**
     * Confirms patch application does not mutate the input document.
     */
    public function testItAppliesPatchWithoutMutatingOriginalDocument(): void
    {
        $document = (object) [
            'items' => [
                (object) ['name' => 'original'],
            ],
        ];

        $result = JsonPatch::apply($document, [
            (object) ['op' => 'replace', 'path' => '/items/0/name', 'value' => 'changed'],
        ]);

        self::assertInstanceOf(stdClass::class, $result);
        self::assertSame('original', $document->items[0]->name);
        self::assertSame('changed', $result->items[0]->name);
    }

    /**
     * Confirms JSON-string helpers preserve empty object semantics.
     */
    public function testItAppliesJsonAndPreservesEmptyObjects(): void
    {
        $result = JsonPatch::applyJson(
            '{"child":{}}',
            '[{"op":"add","path":"/child/0","value":"kept as an object member"}]',
        );

        self::assertSame('{"child":{"0":"kept as an object member"}}', $result);
    }

    /**
     * Confirms equal documents generate no patch operations.
     */
    public function testItGeneratesAnEmptyPatchForEqualDocuments(): void
    {
        self::assertSame([], JsonPatch::diff((object) ['foo' => 'bar'], ['foo' => 'bar']));
    }

    /**
     * Confirms root-level type changes generate a root replace operation.
     */
    public function testItGeneratesRootReplaceOperations(): void
    {
        $patch = JsonPatch::diff('old', ['new']);

        self::assertSame([
            ['op' => 'replace', 'path' => '', 'value' => ['new']],
        ], $patch);
        self::assertSame(['new'], JsonPatch::apply('old', $patch));
    }

    /**
     * Confirms object differences generate deterministic remove, add, and replace operations.
     */
    public function testItGeneratesPatchForObjectDifferences(): void
    {
        $source = (object) [
            'remove' => 'gone',
            'same' => 1,
            'nested' => (object) [
                'change' => 'before',
                'same' => true,
            ],
            'escaped/key~' => 'old',
        ];
        $target = (object) [
            'same' => 1,
            'nested' => (object) [
                'change' => 'after',
                'same' => true,
                'added' => null,
            ],
            'escaped/key~' => 'new',
            'added' => ['tail'],
        ];

        $patch = JsonPatch::diff($source, $target);

        self::assertSame([
            ['op' => 'remove', 'path' => '/remove'],
            ['op' => 'add', 'path' => '/added', 'value' => ['tail']],
            ['op' => 'add', 'path' => '/nested/added', 'value' => null],
            ['op' => 'replace', 'path' => '/nested/change', 'value' => 'after'],
            ['op' => 'replace', 'path' => '/escaped~1key~0', 'value' => 'new'],
        ], $patch);
        self::assertEquals($target, JsonPatch::apply($source, $patch));
    }

    /**
     * Confirms associative arrays are diffed as JSON objects when they are not lists.
     */
    public function testItGeneratesPatchForAssociativeArrayObjects(): void
    {
        $patch = JsonPatch::diff(['foo' => 'bar'], ['foo' => 'baz', 'added' => true]);

        self::assertSame([
            ['op' => 'add', 'path' => '/added', 'value' => true],
            ['op' => 'replace', 'path' => '/foo', 'value' => 'baz'],
        ], $patch);
        self::assertSame(['foo' => 'baz', 'added' => true], JsonPatch::apply(['foo' => 'bar'], $patch));
    }

    /**
     * Confirms list differences recurse into shared indexes and append tail values.
     */
    public function testItGeneratesPatchForArrayDifferencesAndAppendsTailValues(): void
    {
        $patch = JsonPatch::diff([1, ['old']], [1, ['new'], 3]);

        self::assertSame([
            ['op' => 'replace', 'path' => '/1/0', 'value' => 'new'],
            ['op' => 'add', 'path' => '/-', 'value' => 3],
        ], $patch);
        self::assertSame([1, ['new'], 3], JsonPatch::apply([1, ['old']], $patch));
    }

    /**
     * Confirms list tail removals are generated from the end to preserve indexes.
     */
    public function testItGeneratesPatchForArrayTailRemovalsFromTheEnd(): void
    {
        $patch = JsonPatch::diff([1, 2, 3], [1]);

        self::assertSame([
            ['op' => 'remove', 'path' => '/2'],
            ['op' => 'remove', 'path' => '/1'],
        ], $patch);
        self::assertSame([1], JsonPatch::apply([1, 2, 3], $patch));
    }

    /**
     * Confirms JSON-string diff helpers encode a patch document.
     */
    public function testItGeneratesJsonPatchText(): void
    {
        $patchJson = JsonPatch::diffJson('{"foo":"bar"}', '{"foo":"baz","extra":true}');

        self::assertSame('[{"op":"add","path":"/extra","value":true},{"op":"replace","path":"/foo","value":"baz"}]', $patchJson);
        self::assertSame('{"foo":"baz","extra":true}', JsonPatch::applyJson('{"foo":"bar"}', $patchJson));
    }

    /**
     * Confirms JSON patch text must decode to an array document.
     */
    public function testItRejectsPatchJsonThatDoesNotDecodeToAnArray(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('must decode to an array');

        JsonPatch::applyJson('{"foo":"bar"}', '{"op":"remove","path":"/foo"}');
    }

    /**
     * Confirms invalid JSON text surfaces the native decode exception.
     */
    public function testItSurfacesJsonDecodeErrorsFromJsonHelper(): void
    {
        $this->expectException(JsonException::class);

        JsonPatch::applyJson('{', '[]');
    }

    /**
     * Confirms PHP array patches must be list-shaped.
     */
    public function testItRejectsNonListPatchDocuments(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('must be an array of operations');

        JsonPatch::apply([], ['op' => 'remove', 'path' => '/foo']);
    }

    /**
     * Confirms each patch operation must be object-shaped.
     */
    public function testItRejectsNonObjectOperations(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('must be an object');

        JsonPatch::apply([], [null]);
    }

    /**
     * Confirms operation path and op members must be strings.
     */
    public function testItRequiresOperationMembersToBeStrings(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('must be a string');

        JsonPatch::apply([], [
            ['op' => 'add', 'path' => 0, 'value' => 'nope'],
        ]);
    }

    /**
     * Confirms non-JSON PHP values are rejected.
     */
    public function testItRejectsUnsupportedJsonValues(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('Unsupported JSON value');

        JsonPatch::apply(fopen('php://memory', 'rb'), []);
    }

    /**
     * Confirms non-finite floats are rejected because JSON cannot represent them.
     */
    public function testItRejectsNonFiniteNumbers(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('finite');

        JsonPatch::apply(INF, []);
    }

    /**
     * Confirms move cannot place a value below itself.
     */
    public function testItRejectsMovingAValueIntoItsOwnChild(): void
    {
        $this->expectException(JsonPatchException::class);
        $this->expectExceptionMessage('children');

        JsonPatch::apply((object) ['a' => (object) ['b' => 1]], [
            (object) ['op' => 'move', 'from' => '/a', 'path' => '/a/b/c'],
        ]);
    }

    /**
     * Confirms the test operation distinguishes strings from numbers.
     */
    public function testItFailsTestOperationForDifferentValues(): void
    {
        $this->expectException(TestFailedException::class);
        $this->expectExceptionMessage('did not match');

        JsonPatch::apply((object) ['count' => 10], [
            (object) ['op' => 'test', 'path' => '/count', 'value' => '10'],
        ]);
    }

    /**
     * Confirms associative arrays and stdClass can compare as JSON objects.
     */
    public function testItAllowsStdClassAndAssociativeArraysToCompareAsJsonObjects(): void
    {
        $document = (object) [
            'config' => [
                'enabled' => true,
                'name' => 'same',
            ],
        ];

        $result = JsonPatch::apply($document, [
            (object) [
                'op' => 'test',
                'path' => '/config',
                'value' => (object) [
                    'name' => 'same',
                    'enabled' => true,
                ],
            ],
        ]);

        self::assertEquals($document, $result);
    }

    /**
     * Confirms associative arrays can be patched as JSON objects.
     */
    public function testItCanUseAssociativeArraysAsJsonObjects(): void
    {
        $result = JsonPatch::apply(['foo' => ['bar' => 'baz']], [
            ['op' => 'replace', 'path' => '/foo/bar', 'value' => 'qux'],
        ]);

        self::assertSame(['foo' => ['bar' => 'qux']], $result);
    }

    /**
     * Confirms associative array object members can be removed.
     */
    public function testItCanRemoveAssociativeArrayMembers(): void
    {
        $result = JsonPatch::apply(['foo' => 'bar', 'baz' => 'qux'], [
            ['op' => 'remove', 'path' => '/foo'],
        ]);

        self::assertSame(['baz' => 'qux'], $result);
    }

    /**
     * Confirms missing associative array object members fail.
     */
    public function testItRejectsMissingAssociativeArrayMembers(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply(['foo' => 'bar'], [
            ['op' => 'remove', 'path' => '/baz'],
        ]);
    }

    /**
     * Confirms removing the root leaves a null JSON value.
     */
    public function testItRemovesTheRootAsNull(): void
    {
        self::assertNull(JsonPatch::apply(['foo' => 'bar'], [
            ['op' => 'remove', 'path' => ''],
        ]));
    }

    /**
     * Confirms the package exposes the RFC media type.
     */
    public function testItUsesMediaTypeConstant(): void
    {
        self::assertSame('application/json-patch+json', JsonPatch::MEDIA_TYPE);
    }

    /**
     * Confirms nested writes into scalar values fail.
     */
    public function testItRejectsWritesIntoScalars(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply((object) ['foo' => 'bar'], [
            (object) ['op' => 'add', 'path' => '/foo/baz', 'value' => 'qux'],
        ]);
    }

    /**
     * Confirms removals from scalar values fail.
     */
    public function testItRejectsRemovingFromScalars(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply('scalar', [
            (object) ['op' => 'remove', 'path' => '/foo'],
        ]);
    }

    /**
     * Confirms "-" is only valid for array add targets.
     */
    public function testItRejectsArrayDashWhenAnExistingValueIsRequired(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply(['foo'], [
            (object) ['op' => 'remove', 'path' => '/-'],
        ]);
    }

    /**
     * Confirms replacements inside scalar values fail.
     */
    public function testItRejectsReplacingInsideScalars(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply('scalar', [
            (object) ['op' => 'replace', 'path' => '/foo', 'value' => 'bar'],
        ]);
    }

    /**
     * Confirms internal replacement guards reject scalar containers.
     */
    public function testItRejectsUnexpectedScalarReplacementTargets(): void
    {
        $this->expectException(JsonPatchException::class);

        $replaceChild = new \ReflectionMethod(JsonPatch::class, 'replaceChild');
        $replaceChild->invoke(null, 'scalar', 'foo', 'bar');
    }

    /**
     * Confirms replacing a missing object member fails.
     */
    public function testItRejectsReplacingMissingObjectMembers(): void
    {
        $this->expectException(JsonPatchException::class);

        JsonPatch::apply(new stdClass(), [
            (object) ['op' => 'replace', 'path' => '/missing', 'value' => 'qux'],
        ]);
    }

    /**
     * Confirms stdClass objects compare to associative arrays as JSON objects.
     */
    public function testItAllowsStdClassToCompareAgainstAssociativeArrays(): void
    {
        $document = (object) [
            'config' => (object) [
                'enabled' => true,
                'name' => 'same',
            ],
        ];

        $result = JsonPatch::apply($document, [
            (object) [
                'op' => 'test',
                'path' => '/config',
                'value' => [
                    'name' => 'same',
                    'enabled' => true,
                ],
            ],
        ]);

        self::assertEquals($document, $result);
    }

    /**
     * Confirms list comparisons fail when lengths differ.
     */
    public function testItFailsTestOperationForListsWithDifferentLengths(): void
    {
        $this->expectException(TestFailedException::class);

        JsonPatch::apply([1, 2], [
            ['op' => 'test', 'path' => '', 'value' => [1]],
        ]);
    }

    /**
     * Confirms list comparisons fail when item values differ.
     */
    public function testItFailsTestOperationForListsWithDifferentValues(): void
    {
        $this->expectException(TestFailedException::class);

        JsonPatch::apply([1, 2], [
            ['op' => 'test', 'path' => '', 'value' => [1, 3]],
        ]);
    }

    /**
     * Confirms object comparisons fail when keys differ.
     */
    public function testItFailsTestOperationForObjectsWithDifferentKeys(): void
    {
        $this->expectException(TestFailedException::class);

        JsonPatch::apply(['foo' => 'bar'], [
            ['op' => 'test', 'path' => '', 'value' => ['baz' => 'bar']],
        ]);
    }

    /**
     * Confirms object comparisons fail when member counts differ.
     */
    public function testItFailsTestOperationForObjectsWithDifferentSizes(): void
    {
        $this->expectException(TestFailedException::class);

        JsonPatch::apply((object) ['foo' => 'bar'], [
            ['op' => 'test', 'path' => '', 'value' => (object) ['foo' => 'bar', 'baz' => 'qux']],
        ]);
    }

    /**
     * Confirms object comparisons fail when member values differ.
     */
    public function testItFailsTestOperationForObjectsWithDifferentValues(): void
    {
        $this->expectException(TestFailedException::class);

        JsonPatch::apply((object) ['foo' => 'bar'], [
            ['op' => 'test', 'path' => '', 'value' => (object) ['foo' => 'baz']],
        ]);
    }
}
