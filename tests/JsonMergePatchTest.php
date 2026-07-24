<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Tests;

use BumpCore\JsonPatch\Exception\InvalidPatchException;
use BumpCore\JsonPatch\JsonMergePatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers RFC 7396 JSON Merge Patch behavior.
 */
class JsonMergePatchTest extends TestCase
{
    #[DataProvider('appendixCases')]
    /**
     * Confirms the implementation matches every RFC 7396 Appendix A case.
     */
    public function testItPassesRfc7396AppendixCases(string $original, string $patch, string $expected): void
    {
        self::assertEquals(
            json_decode($expected, false, 512, JSON_THROW_ON_ERROR),
            json_decode(JsonMergePatch::applyJson($original, $patch), false, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Confirms PHP-value merge patches preserve input documents.
     */
    public function testItAppliesMergePatchWithoutMutatingOriginalDocument(): void
    {
        $document = [
            'title' => 'Goodbye!',
            'author' => [
                'givenName' => 'John',
                'familyName' => 'Doe',
            ],
            'tags' => ['example', 'sample'],
            'content' => 'This will be unchanged',
        ];

        $result = JsonMergePatch::apply($document, [
            'title' => 'Hello!',
            'phoneNumber' => '+01-123-456-7890',
            'author' => [
                'familyName' => null,
            ],
            'tags' => ['example'],
        ]);

        self::assertSame('Goodbye!', $document['title']);
        self::assertSame(['example', 'sample'], $document['tags']);
        self::assertSame([
            'title' => 'Hello!',
            'author' => [
                'givenName' => 'John',
            ],
            'tags' => ['example'],
            'content' => 'This will be unchanged',
            'phoneNumber' => '+01-123-456-7890',
        ], $result);
        self::assertSame(['a' => 'b'], JsonMergePatch::apply([1, 2], ['a' => 'b', 'c' => null]));
    }

    /**
     * Confirms JSON helpers preserve empty object semantics.
     */
    public function testItAppliesJsonAndPreservesEmptyObjects(): void
    {
        self::assertSame('{"a":{"bb":{}}}', JsonMergePatch::applyJson('{}', '{"a":{"bb":{"ccc":null}}}'));
    }

    /**
     * Confirms the package exposes the RFC media type.
     */
    public function testItUsesMediaTypeConstant(): void
    {
        self::assertSame('application/merge-patch+json', JsonMergePatch::MEDIA_TYPE);
    }

    /**
     * Confirms merge patches reject non-JSON PHP values.
     */
    public function testItRejectsUnsupportedMergePatchValues(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('Unsupported JSON value');

        JsonMergePatch::apply([], $resource);
    }

    /**
     * Confirms merge patches reject non-finite numbers.
     */
    public function testItRejectsNonFiniteMergePatchNumbers(): void
    {
        $this->expectException(InvalidPatchException::class);
        $this->expectExceptionMessage('finite');

        JsonMergePatch::apply([], INF);
    }

    /**
     * Provides the examples from RFC 7396 Appendix A.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function appendixCases(): iterable
    {
        yield 'replace object member' => ['{"a":"b"}', '{"a":"c"}', '{"a":"c"}'];
        yield 'add object member' => ['{"a":"b"}', '{"b":"c"}', '{"a":"b","b":"c"}'];
        yield 'remove only object member' => ['{"a":"b"}', '{"a":null}', '{}'];
        yield 'remove one object member' => ['{"a":"b","b":"c"}', '{"a":null}', '{"b":"c"}'];
        yield 'replace array with scalar' => ['{"a":["b"]}', '{"a":"c"}', '{"a":"c"}'];
        yield 'replace scalar with array' => ['{"a":"c"}', '{"a":["b"]}', '{"a":["b"]}'];
        yield 'merge nested object' => ['{"a":{"b":"c"}}', '{"a":{"b":"d","c":null}}', '{"a":{"b":"d"}}'];
        yield 'replace array of objects' => ['{"a":[{"b":"c"}]}', '{"a":[1]}', '{"a":[1]}'];
        yield 'replace root array' => ['["a","b"]', '["c","d"]', '["c","d"]'];
        yield 'replace object with array' => ['{"a":"b"}', '["c"]', '["c"]'];
        yield 'replace object with null' => ['{"a":"foo"}', 'null', 'null'];
        yield 'replace object with string' => ['{"a":"foo"}', '"bar"', '"bar"'];
        yield 'preserve explicit null members' => ['{"e":null}', '{"a":1}', '{"e":null,"a":1}'];
        yield 'replace array target with object patch' => ['[1,2]', '{"a":"b","c":null}', '{"a":"b"}'];
        yield 'remove absent nested null' => ['{}', '{"a":{"bb":{"ccc":null}}}', '{"a":{"bb":{}}}'];
    }

    /**
     * Confirms mutating a returned document never writes through to either input.
     */
    public function testItReturnsResultsThatDoNotShareObjectsWithInputs(): void
    {
        $document = (object) [
            'untouched' => (object) ['name' => 'stable'],
        ];
        $patch = (object) [
            'added' => (object) ['name' => 'from patch'],
        ];

        $result = JsonMergePatch::apply($document, $patch);

        self::assertInstanceOf(\stdClass::class, $result);

        $result->untouched->name = 'mutated after apply';
        $result->added->name = 'mutated after apply';

        self::assertSame('stable', $document->untouched->name);
        self::assertSame('from patch', $patch->added->name);
    }
}
