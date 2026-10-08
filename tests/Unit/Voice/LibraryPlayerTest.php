<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\LibraryPlayer;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function React\Promise\resolve;

final class LibraryPlayerTest extends TestCase
{
    public function testHandsTheFileToTheVoiceLibraryAndSaysSoFirst(): void
    {
        $order = [];
        $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getStub();
        $vc->method('playFile')->willReturnCallback(function (string $file) use (&$order) {
            $order[] = "playFile {$file}";

            return resolve('played');
        });

        $played = (new LibraryPlayer($vc))->play('/calls/claude-2.ogg', function () use (&$order) {
            $order[] = 'started';
        });

        // The library sends the first packet half a second after it gets the file, and says nothing when it does.
        $this->assertSame(['started', 'playFile /calls/claude-2.ogg'], $order);
        $result = null;
        $played->then(function ($value) use (&$result) {
            $result = $value;
        });
        $this->assertSame('played', $result, "The library's own promise is returned.");
    }

    public function testPlaysWithoutBeingAskedToSayWhenItStarts(): void
    {
        $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getMock();
        $vc->expects($this->once())->method('playFile')->with('/calls/claude-3.ogg')->willReturn(resolve(null));

        (new LibraryPlayer($vc))->play('/calls/claude-3.ogg');
    }

    public function testStopsTheVoiceLibraryAndIgnoresThatNothingWasPlaying(): void
    {
        $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getStub();
        $vc->method('stop')->willThrowException(new RuntimeException('Audio must be playing to stop it.'));

        (new LibraryPlayer($vc))->stop();

        $this->expectNotToPerformAssertions();
    }

    public function testStopsTheVoiceLibrary(): void
    {
        $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['stop'])->getMock();
        $vc->expects($this->once())->method('stop');

        (new LibraryPlayer($vc))->stop();
    }
}
