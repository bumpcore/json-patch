<?php

declare(strict_types=1);

namespace BumpCore\JsonPatch\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for JSON Patch and JSON Pointer failures.
 */
class JsonPatchException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private ?int $operationIndex = null,
        private ?string $operation = null,
        private ?string $path = null,
        private ?string $from = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Adds operation context while preserving the concrete exception type.
     */
    public function withContext(
        ?int $operationIndex = null,
        ?string $operation = null,
        ?string $path = null,
        ?string $from = null,
    ): static {
        $this->operationIndex ??= $operationIndex;
        $this->operation ??= $operation;
        $this->path ??= $path;
        $this->from ??= $from;

        return $this;
    }

    /**
     * Returns the zero-based JSON Patch operation index when available.
     */
    public function operationIndex(): ?int
    {
        return $this->operationIndex;
    }

    /**
     * Returns the JSON Patch operation name when available.
     */
    public function operation(): ?string
    {
        return $this->operation;
    }

    /**
     * Returns the JSON Pointer path involved in the failure when available.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Returns the JSON Patch source pointer for move/copy failures when available.
     */
    public function from(): ?string
    {
        return $this->from;
    }
}
