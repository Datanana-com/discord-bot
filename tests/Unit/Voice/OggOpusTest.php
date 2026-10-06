<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\Shell;
use App\Voice\OggOpus;
use Discord\Voice\Ogg\Buffer;
use Discord\Voice\Ogg\OggStream;
use Discord\Voice\Processes\Ffmpeg;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Ogg;

use function React\Async\await;

final class OggOpusTest extends TestCase
{
    public function testReadsThePacketsAfterTheTwoHeaders(): void
    {
        $packets = ['a', str_repeat('b', 254), str_repeat('c', 255), str_repeat('d', 300), 'e'];

        $this->assertSame($packets, OggOpus::packets(Ogg::opus($packets)));
    }

    public function testReadsPacketsSpreadOverSeveralPages(): void
    {
        $packets = ['one', 'two', 'three', 'four', 'five'];

        $this->assertSame($packets, OggOpus::packets(Ogg::opus($packets, perPage: 2)));
    }

    public function testReadsAPacketThatGoesOnFromOnePageToTheNext(): void
    {
        $long = str_repeat('x', 300);
        $stream = substr(Ogg::opus(['before']), 0, -strlen(Ogg::page(['before'], 2, Ogg::LAST)))
            . Ogg::page(['before'], 2)
            // The first 255 bytes end the page without ending the packet: the next page goes on with it.
            . Ogg::rawPage("\xff", substr($long, 0, 255), 3)
            . Ogg::rawPage(chr(45) . Ogg::lacing(5), substr($long, 255) . 'after', 4, Ogg::CONTINUED | Ogg::LAST);

        $this->assertSame(['before', $long, 'after'], OggOpus::packets($stream));
    }

    public function testAFileWithNothingButTheHeadersHasNoPackets(): void
    {
        $this->assertSame([], OggOpus::packets(Ogg::opus([])));
    }

    public function testAFileCutOffInAPacketGivesThePacketsThatAreWhole(): void
    {
        $stream = Ogg::opus(['one', 'two', str_repeat('three', 100)], perPage: 1);

        $this->assertSame(['one', 'two'], OggOpus::packets(substr($stream, 0, -7)), 'Cut in the middle of the last packet.');
        $this->assertSame(['one', 'two'], OggOpus::packets(substr($stream, 0, -strlen(str_repeat('three', 100)) - 10)), 'Cut in the header of the last page.');
        $this->assertSame(['one', 'two', str_repeat('three', 100)], OggOpus::packets($stream . 'Ogg'), 'A few stray bytes after the last page.');
    }

    public function testRefusesWhatIsNotAnOggOpusStream(): void
    {
        foreach (['' => 'nothing', 'RIFF....WAVEfmt ' => 'a WAV file', Ogg::page(["\x01vorbis"], 0, Ogg::FIRST) . Ogg::page(["\x03vorbis"], 1) => 'an Ogg Vorbis stream', Ogg::page(['OpusHead'], 0, Ogg::FIRST) => 'OpusHead alone'] as $bytes => $what) {
            try {
                OggOpus::packets($bytes);
                $this->fail("{$what} was read as Ogg Opus.");
            } catch (RuntimeException $e) {
                $this->assertSame('Not an Ogg Opus file: it does not start with the OpusHead and OpusTags headers.', $e->getMessage(), $what);
            }
        }
    }

    public function testReadsWhatFfmpegWritesLikeTheVoiceLibraryDoes(): void
    {
        if (! Ffmpeg::checkForFFmpeg()) {
            $this->markTestSkipped('Needs ffmpeg.');
        }

        // A second of tone, encoded the way Speech has Piper's sentences encoded.
        $file = await(Shell::run(['ffmpeg', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=660:duration=1', '-c:a', 'libopus', '-f', 'ogg', 'pipe:1']));
        $packets = OggOpus::packets($file);

        // 50 frames of 20 ms, and the one the encoder's delay adds.
        $this->assertGreaterThanOrEqual(50, count($packets));
        $this->assertLessThanOrEqual(52, count($packets));

        // The same packets the voice library reads out of it.
        $buffer = new Buffer();
        $buffer->end($file);
        $stream = await(OggStream::fromBuffer($buffer));
        $theirs = [];

        while (($packet = await($stream->getPacket())) !== null) {
            $theirs[] = $packet;
        }

        $this->assertSame($theirs, $packets);
    }
}
