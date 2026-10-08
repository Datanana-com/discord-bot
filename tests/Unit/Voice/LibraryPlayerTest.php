<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Voice\LibraryPlayer;
use App\Voice\Sentence;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function React\Promise\resolve;

final class LibraryPlayerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/library-player-test-' . uniqid();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->directory}/*") ?: []);
        rmdir($this->directory);
    }

    public function testHandsTheFileToTheVoiceLibraryAndSaysSoFirst(): void
    {
        $order = [];
        $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getStub();
        $vc->method('playFile')->willReturnCallback(function (string $file) use (&$order) {
            $order[] = "playFile {$file}";

            return resolve('played');
        });

        $played = (new LibraryPlayer($vc))->play($this->whole('claude-2.ogg'), function () use (&$order) {
            $order[] = 'started';
        });

        // The library sends the first packet half a second after it gets the file, and says nothing when it does.
        $this->assertSame(['started', "playFile {$this->directory}/claude-2.ogg"], $order);
        $result = null;
        $played->then(function ($value) use (&$result) {
            $result = $value;
        });
        $this->assertSame('played', $result, "The library's own promise is returned.");
    }

    public function testPlaysWithoutBeingAskedToSayWhenItStarts(): void
    {
        $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getMock();
        $vc->expects($this->once())->method('playFile')->with("{$this->directory}/claude-3.ogg")->willReturn(resolve(null));

        (new LibraryPlayer($vc))->play($this->whole('claude-3.ogg'));
    }

    public function testPlaysASentenceOnceItsFileIsWhole(): void
    {
        $played = [];
        $vc = static::getStubBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getStub();
        $vc->method('playFile')->willReturnCallback(function (string $file) use (&$played) {
            $played[] = file_get_contents($file);

            return resolve(null);
        });
        $sentence = new Sentence("{$this->directory}/claude-4.ogg", resolve(null));
        $started = $over = false;

        (new LibraryPlayer($vc))->play($sentence, function () use (&$started) {
            $started = true;
        })->then(function () use (&$over) {
            $over = true;
        });

        // The first of it has come from the encoder: the library plays files, and this one isn't written yet.
        $sentence->write('It is a quarter');
        $this->assertSame([[], false, false], [$played, $started, $over]);

        $sentence->write(' past four.');
        $sentence->end();

        $this->assertSame([['It is a quarter past four.'], true, true], [$played, $started, $over]);
    }

    public function testDoesNotPlayASentenceThatBecameWholeAfterItWasStopped(): void
    {
        $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile', 'stop'])->getMock();
        $vc->expects($this->never())->method('playFile');
        $player = new LibraryPlayer($vc);
        $sentence = new Sentence("{$this->directory}/claude-5.ogg", resolve(null));
        $started = false;
        $result = 'nothing yet';

        $player->play($sentence, function () use (&$started) {
            $started = true;
        })->then(function ($value) use (&$result) {
            $result = $value;
        });
        $sentence->write('It is a quarter past four.');
        // Someone talks over the answer while its sentence is still being encoded.
        $player->stop();
        $sentence->end();

        $this->assertFalse($started, 'Nobody heard it start.');
        $this->assertNull($result, 'Whoever waited for it is told it is over.');
    }

    public function testRejectsWhenTheSentenceCannotBeEncoded(): void
    {
        $vc = $this->getMockBuilder(VoiceClient::class)->disableOriginalConstructor()->onlyMethods(['playFile'])->getMock();
        $vc->expects($this->never())->method('playFile');
        $sentence = new Sentence("{$this->directory}/claude-6.ogg", resolve(null));
        $failure = null;

        (new LibraryPlayer($vc))->play($sentence)->catch(function (RuntimeException $e) use (&$failure) {
            $failure = $e;
        });
        $sentence->fail($failed = new RuntimeException('ffmpeg exited with code 1'));

        $this->assertSame($failed, $failure);
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

    /**
     * A sentence that is whole, whose file is in the test's folder.
     */
    private function whole(string $file): Sentence
    {
        $sentence = new Sentence("{$this->directory}/{$file}", resolve(null));
        $sentence->write('OggS');
        $sentence->end();

        return $sentence;
    }
}
