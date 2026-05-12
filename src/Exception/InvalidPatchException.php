<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Exception;

/**
 * Raised when a patch document or JSON value cannot be processed as RFC 6902.
 */
final class InvalidPatchException extends JsonPatchException
{
    // ...
}
