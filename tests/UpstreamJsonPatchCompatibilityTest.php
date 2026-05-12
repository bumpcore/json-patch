<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Tests;

use BumpCore\JsonPatch\Exception\JsonPatchException;
use BumpCore\JsonPatch\JsonPatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Runs the upstream JSON Patch compatibility corpus against this implementation.
 */
final class UpstreamJsonPatchCompatibilityTest extends TestCase
{
    #[DataProvider('upstreamCases')]
    /**
     * Applies one active upstream fixture case.
     */
    public function testItPassesUpstreamJsonPatchTests(string $source, int $index, stdClass $case): void
    {
        $label = sprintf('%s case %d: %s', $source, $index, $case->comment ?? 'unnamed');

        if (property_exists($case, 'error')) {
            try {
                JsonPatch::apply($case->doc, $case->patch);
            } catch (JsonPatchException $exception) {
                self::assertNotSame('', $exception->getMessage(), $label);

                return;
            }

            self::fail($label . ' should have failed.');
        }

        self::assertEquals($case->expected, JsonPatch::apply($case->doc, $case->patch), $label);
    }

    /**
     * Provides every enabled fixture case from the upstream test files.
     *
     * @return iterable<string, array{string, int, stdClass}>
     */
    public static function upstreamCases(): iterable
    {
        foreach (self::fixtureFiles() as $source => $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);

            $cases = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($cases);

            foreach ($cases as $index => $case) {
                self::assertInstanceOf(stdClass::class, $case);

                if (property_exists($case, 'disabled') && $case->disabled === true) {
                    continue;
                }

                if (! property_exists($case, 'doc') || ! property_exists($case, 'patch')) {
                    continue;
                }

                yield sprintf('%s:%d', $source, $index) => [$source, $index, $case];
            }
        }
    }

    /**
     * Lists the vendored upstream fixture files.
     *
     * @return array<string, string>
     */
    private static function fixtureFiles(): array
    {
        $fixtureDirectory = __DIR__ . '/fixtures/json-patch-tests';

        return [
            'tests.json' => $fixtureDirectory . '/tests.json',
            'spec_tests.json' => $fixtureDirectory . '/spec_tests.json',
        ];
    }
}
