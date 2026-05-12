<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Exception;

/**
 * Raised when a JSON Patch test operation compares unequal values.
 */
final class TestFailedException extends JsonPatchException
{
    // ...
}
