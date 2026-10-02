<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Throwable;

/**
 * Exception used whenever an event doesn't exist.
 *
 * @package App\Exceptions
 * @author  alexandre433 <alexandreluisbarreto@gmail.com>
 * @license MIT
 */
final class EventNotFoundException extends Exception
{
    /**
     * EventNotFoundException constructor.
     *
     * @param string $event The event that doesn't exist.
     * @param integer $code
     * @param Throwable|null $previous
     *
     * @return mixed
     */
    public function __construct(string $event, int $code = 0, ?Throwable $previous = null)
    {
        return parent::__construct("Event $event not found", $code, $previous);
    }
}
