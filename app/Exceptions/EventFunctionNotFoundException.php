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
final class EventFunctionNotFoundException extends Exception
{
    /**
     * EventFunctionNotFoundException constructor.
     *
     * @param string $event The function that doesn't exist.
     * @param integer $code
     * @param Throwable|null $previous
     *
     * @return mixed
     */
    public function __construct(string $function, int $code = 0, ?Throwable $previous = null)
    {
        return parent::__construct("Event function name <$function> not found", $code, $previous);
    }
}
