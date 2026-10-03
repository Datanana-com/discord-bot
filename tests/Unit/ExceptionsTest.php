<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\EventFunctionNotFoundException;
use App\Exceptions\EventNotFoundException;
use PHPUnit\Framework\TestCase;

final class ExceptionsTest extends TestCase
{
    public function testNamesTheMissingEvent(): void
    {
        $this->assertSame('Event Typo not found', (new EventNotFoundException('Typo'))->getMessage());
    }

    public function testNamesTheMissingEventFunction(): void
    {
        $this->assertSame('Event function name <typo> not found', (new EventFunctionNotFoundException('typo'))->getMessage());
    }
}
