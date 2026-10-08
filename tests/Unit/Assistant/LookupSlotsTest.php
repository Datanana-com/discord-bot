<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\LookupSlots;
use Closure;
use PHPUnit\Framework\TestCase;

final class LookupSlotsTest extends TestCase
{
    private int $limit = 2;

    /** @var list<string> The tasks that got a slot, in the order they did. */
    private array $started = [];

    protected function setUp(): void
    {
        LookupSlots::reset();
    }

    protected function tearDown(): void
    {
        LookupSlots::reset();
        unset($_ENV['CLAUDE_LOOKUP_AT_ONCE']);
    }

    public function testGivesAFreeSlotAtOnce(): void
    {
        $slots = $this->slots();

        $this->assertInstanceOf(Closure::class, $this->take($slots, 'first'));
        $this->assertInstanceOf(Closure::class, $this->take($slots, 'second'));
        $this->assertSame(['first', 'second'], $this->started);
    }

    public function testMakesTheOnesThatCannotStartWaitAndLetsTheOneThatWaitedLongestStartFirst(): void
    {
        $slots = $this->slots();
        $first = $this->take($slots, 'first');
        $this->take($slots, 'second');
        $this->take($slots, 'third');
        $this->take($slots, 'fourth');

        $this->assertSame(['first', 'second'], $this->started);

        $first();

        $this->assertSame(['first', 'second', 'third'], $this->started);
    }

    public function testGivingASlotBackTwiceFreesOnlyOne(): void
    {
        $slots = $this->slots();
        $first = $this->take($slots, 'first');
        $this->take($slots, 'second');
        $this->take($slots, 'third');
        $this->take($slots, 'fourth');

        $first();
        $first();

        // The second give-back would have started the fourth too, over the limit.
        $this->assertSame(['first', 'second', 'third'], $this->started);
    }

    public function testLeavingTheLineKeepsTheOnesAfterItsPlace(): void
    {
        $slots = $this->slots();
        $first = $this->take($slots, 'first');
        $this->take($slots, 'second');
        $leaving = $slots->acquire();
        $this->take($slots, 'fourth');

        $given = 'nothing yet';
        $leaving->then(function (?Closure $release) use (&$given) {
            $given = $release;
        });
        $leaving->cancel();

        // It is told there is no slot to give back, and is not started later.
        $this->assertNull($given);
        $first();
        $this->assertSame(['first', 'second', 'fourth'], $this->started);
    }

    public function testCancellingAfterTheSlotWasGivenKeepsIt(): void
    {
        $slots = $this->slots();
        $slot = $slots->acquire();
        $slot->cancel();
        $given = null;
        $slot->then(function (?Closure $release) use (&$given) {
            $given = $release;
        });

        $this->assertInstanceOf(Closure::class, $given, 'It was given at once: there was nothing to leave.');
    }

    public function testReadsTheLimitAgainEachTimeASlotIsWanted(): void
    {
        $slots = $this->slots();
        $first = $this->take($slots, 'first');
        $this->take($slots, 'second');
        $this->take($slots, 'third');
        $this->take($slots, 'fourth');

        $this->limit = 3;
        $first();

        // One slot freed, and room for two more: both waiting ones start.
        $this->assertSame(['first', 'second', 'third', 'fourth'], $this->started);

        // Less than are taken: nothing new starts until enough were given back.
        $this->limit = 1;
        $fifth = $this->take($slots, 'fifth');
        $this->assertSame(['first', 'second', 'third', 'fourth'], $this->started);
        $this->assertNull($fifth);
    }

    public function testNeverLetsANewcomerJumpTheLineWhenTheLimitWasRaised(): void
    {
        $this->limit = 1;
        $slots = $this->slots();
        $this->take($slots, 'first');
        $this->take($slots, 'second');

        // Room for two more, but nobody gave a slot back: the one that waits starts first, and the newcomer after it.
        $this->limit = 3;
        $this->take($slots, 'third');

        $this->assertSame(['first', 'second', 'third'], $this->started);
    }

    public function testSharesTheSlotsEveryCallAndChatUsesAndStartsFromTheDefaultOfTwo(): void
    {
        $this->assertSame(LookupSlots::shared(), LookupSlots::shared());

        $slots = LookupSlots::shared();
        $this->take($slots, 'first');
        $this->take($slots, 'second');
        $this->assertNull($this->take($slots, 'third'), 'Two at once, unless env says otherwise.');

        LookupSlots::reset();
        $this->assertNotSame($slots, LookupSlots::shared());
    }

    /**
     * Wants a slot for a task.
     *
     * @return Closure|null What gives the slot back, or null while it waits.
     */
    private function take(LookupSlots $slots, string $task): ?Closure
    {
        $release = null;
        $slots->acquire()->then(function (?Closure $given) use ($task, &$release) {
            $this->started[] = $task;
            $release = $given;
        });

        return $release;
    }

    private function slots(): LookupSlots
    {
        return new LookupSlots(fn () => $this->limit);
    }
}
