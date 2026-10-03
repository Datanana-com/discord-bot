<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testSnakeCasesEventClassNames(): void
    {
        // Composer already loaded it; loading it again shows it leaves existing functions alone.
        require dirname(__DIR__, 2) . '/helpers/string.php';

        $this->assertSame('message_create', snake('MessageCreate'));
        $this->assertSame('ready', snake('ready'));
    }
}
