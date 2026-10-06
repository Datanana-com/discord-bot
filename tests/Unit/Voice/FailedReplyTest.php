<?php

declare(strict_types=1);

namespace Tests\Unit\Voice;

use App\Support\CommandFailedException;
use App\Voice\FailedReply;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailedReplyTest extends TestCase
{
    public function testSaysWhichStepFailedWithTheMessageOfWhatItFailedWith(): void
    {
        $whisper = new CommandFailedException('whisper-cli exited with code 1', '');

        $failed = new FailedReply(FailedReply::WHISPER, $whisper);

        $this->assertSame('whisper', $failed->step);
        // The log line of a reply that failed is that of what failed.
        $this->assertSame('whisper-cli exited with code 1', $failed->getMessage());
        $this->assertSame($whisper, $failed->getPrevious());
        $this->assertSame('whisper', FailedReply::stepOf($failed));
    }

    public function testWhatIsNoStepOfAReplyIsSomethingElse(): void
    {
        $this->assertSame('other', FailedReply::stepOf(new RuntimeException('A bug')));
    }

    public function testAStepThatFailedIsNotTakenForTheOneThatWaitedForIt(): void
    {
        // A sentence waits for the ones before it, and fails with them: it is the first one that failed.
        $first = new FailedReply(FailedReply::CLAUDE, new RuntimeException('Claude Code: Not logged in'));

        $this->assertSame($first, FailedReply::of(FailedReply::SPEECH, $first));

        $piper = new RuntimeException('piper exited with code 1');
        $failed = FailedReply::of(FailedReply::SPEECH, $piper);

        $this->assertSame(['speech', $piper], [$failed->step, $failed->getPrevious()]);
    }
}
