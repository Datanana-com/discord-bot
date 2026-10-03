<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Thrown when a program run through {@see Shell} fails or times out.
 */
final class CommandFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $stdout)
    {
        parent::__construct($message);
    }
}
