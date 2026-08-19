<?php

declare(strict_types=1);

namespace Magna\MessageLite\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A file this plugin will not store.
 *
 * Every refusal says what was wrong and what would be acceptable. "Upload
 * failed" leaves somebody trying the same 40 MB file three times.
 */
final class AttachmentException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public static function empty(): self
    {
        return new self('That file is empty.');
    }

    public static function tooLarge(int $maxMb): self
    {
        return new self("Attachments must be {$maxMb} MB or smaller.");
    }

    /**
     * @param  list<string>  $allowed
     */
    public static function wrongType(string $detected, array $allowed): self
    {
        return new self(sprintf(
            'Files of type %s cannot be attached. Allowed: %s.',
            $detected,
            implode(', ', $allowed),
        ));
    }

    public static function tooMany(int $max): self
    {
        return new self("A message can carry at most {$max} attachments.");
    }

    public static function storageFailed(): self
    {
        return new self('That file could not be saved. Try again.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
