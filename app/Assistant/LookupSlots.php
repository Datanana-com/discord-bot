<?php

declare(strict_types=1);

namespace App\Assistant;

use Closure;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * How many tasks are looked up at once, in every call and every chat together: see {@see Lookups}.
 *
 * Each call and each person's DMs looks one task up at a time, but a lookup uses about twenty times
 * as much of the subscription as an answer, so what they all start is limited too. A task that
 * can't start waits its turn, the ones that waited longest first.
 */
final class LookupSlots
{
    /** How many tasks are looked up at once, unless CLAUDE_LOOKUP_AT_ONCE says otherwise. */
    public const int AT_ONCE = 2;

    /** The slots of this bot, which every call and every chat uses. */
    private static ?self $shared = null;

    /** How many slots are taken. */
    private int $taken = 0;

    /** @var list<Deferred<Closure|null>> Tasks waiting for a slot, the first one first. */
    private array $waiting = [];

    /**
     * @param Closure(): int $limit How many tasks may be looked up at once, asked for again each time a slot is wanted.
     */
    public function __construct(private readonly Closure $limit)
    {
    }

    /**
     * The slots every call and every chat shares.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self(static function (): int {
            $limit = env('CLAUDE_LOOKUP_AT_ONCE', self::AT_ONCE);

            // A number below one would never let a task start.
            return filter_var($limit, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: self::AT_ONCE;
        });
    }

    /**
     * Forgets the shared slots, and who holds one. For tests: each starts with all of them free.
     */
    public static function reset(): void
    {
        self::$shared = null;
    }

    /**
     * Takes a slot once there is one.
     *
     * @return PromiseInterface<Closure|null> Resolves with what gives the slot back, which must be called once the
     *                                        task is over, whatever came of it. Cancelling it leaves the line,
     *                                        and resolves it with null: there is no slot to give back.
     */
    public function acquire(): PromiseInterface
    {
        $turn = new Deferred(function () use (&$turn) {
            $this->waiting = array_values(array_filter($this->waiting, static fn (Deferred $waiting) => $waiting !== $turn));
            $turn->resolve(null);
        });
        $this->waiting[] = $turn;
        $this->start();

        return $turn->promise();
    }

    /**
     * Gives a slot to the ones waiting, in the order they asked, for as long as there are free ones.
     */
    private function start(): void
    {
        while ($this->waiting !== [] && $this->taken < $this->limit()) {
            array_shift($this->waiting)->resolve($this->take());
        }
    }

    /**
     * @return int How many tasks may be looked up at once.
     */
    private function limit(): int
    {
        return ($this->limit)();
    }

    /**
     * @return Closure(): void What gives the slot back. Giving it back more than once does nothing.
     */
    private function take(): Closure
    {
        $this->taken++;
        $held = true;

        return function () use (&$held): void {
            if (! $held) {
                return;
            }

            $held = false;
            $this->taken--;

            $this->start();
        };
    }
}
