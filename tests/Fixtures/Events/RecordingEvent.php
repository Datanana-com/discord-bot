<?php

declare(strict_types=1);

namespace Tests\Fixtures\Events;

use App\EventAbstract;

/**
 * An event class that records what Application runs on it.
 *
 * Every public method that EventAbstract doesn't define is run as part of the event,
 * so this class has none besides first().
 */
final class RecordingEvent extends EventAbstract
{
    /** @var list<string> */
    public static array $calls = [];

    /** What before() returns, or throws when it is an exception. */
    public static mixed $before = null;

    public function before()
    {
        self::$calls[] = 'before';

        if (self::$before instanceof \Throwable) {
            throw self::$before;
        }

        return self::$before;
    }

    public function after(): void
    {
        self::$calls[] = 'after';
    }

    public function first(object $data): void
    {
        self::$calls[] = "first:{$data->content}";
    }
}
