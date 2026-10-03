<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class HelpersTest extends TestCase
{
    public function testLaravelsEnvIsUsedInsteadOfTheHelper(): void
    {
        // Composer loads the helpers after Laravel's, which already defines env(); loading them
        // again shows they leave existing functions alone.
        require dirname(__DIR__, 2) . '/helpers/main.php';

        $this->assertStringContainsString('/illuminate/', (new ReflectionFunction('env'))->getFileName());
    }

    public function testSnakeCasesEventClassNames(): void
    {
        // As above, loading it again leaves the existing function in place.
        require dirname(__DIR__, 2) . '/helpers/string.php';

        $this->assertSame('message_create', snake('MessageCreate'));
        $this->assertSame('ready', snake('ready'));
    }
}
