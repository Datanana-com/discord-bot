<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use App\Assistant\VoiceMessage;
use App\Assistant\VoiceMessageTooLongException;
use App\Voice\Transcriber;
use Discord\Parts\Channel\Message;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function React\Async\await;

final class VoiceMessageTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/voice-message-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    public function testKnowsAVoiceMessageByItsFlag(): void
    {
        $this->assertTrue(VoiceMessage::isOne($this->message(flags: Message::FLAG_IS_VOICE_MESSAGE | Message::FLAG_CROSSPOSTED)));
        $this->assertFalse(VoiceMessage::isOne($this->message(flags: Message::FLAG_CROSSPOSTED)));
        $this->assertFalse(VoiceMessage::isOne($this->message(flags: null)));
    }

    public function testKnowsHowLongItIs(): void
    {
        $this->assertSame(4.2, VoiceMessage::seconds($this->message(attachments: [(object) ['duration_secs' => 4.2]])));
        $this->assertSame(0.0, VoiceMessage::seconds($this->message(attachments: [(object) ['duration_secs' => null]])));
        $this->assertSame(0.0, VoiceMessage::seconds($this->message(attachments: [])));
    }

    public function testRefusesAVoiceMessageWithoutAudio(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The voice message has no audio attached.');

        await($this->voiceMessage()->transcribe($this->message(attachments: [])));
    }

    public function testRefusesAMessageOfOverFiveMinutesBeforeDownloadingIt(): void
    {
        $this->expectException(VoiceMessageTooLongException::class);
        $this->expectExceptionMessage('longer than 5 minutes');

        // Not even a valid URL: it is never looked at.
        await($this->voiceMessage()->transcribe($this->message(attachments: [(object) ['url' => 'nonsense', 'duration_secs' => 300.001]])));
    }

    public function testFailsWhenTheFolderForItsFilesCannotBeCreated(): void
    {
        // A folder can't be made inside a file.
        touch($this->folder);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The folder for voice messages could not be created.');

        await($this->voiceMessage("{$this->folder}/voice-messages")->transcribe($this->message(attachments: [(object) ['url' => 'https://cdn.discordapp.com/voice.ogg', 'duration_secs' => 3.0]])));
    }

    public function testDoesNotUseAFolderOthersCanAccess(): void
    {
        mkdir($this->folder, 0777);
        chmod($this->folder, 0777);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The folder for voice messages is not private to the bot.');

        await($this->voiceMessage()->transcribe($this->message(attachments: [(object) ['url' => 'https://cdn.discordapp.com/voice.ogg', 'duration_secs' => 3.0]])));
    }

    public function testDoesNotUseALinkInsteadOfItsFolder(): void
    {
        $target = "{$this->folder}-target";
        mkdir($target, 0700);
        symlink($target, $this->folder);

        try {
            await($this->voiceMessage()->transcribe($this->message(attachments: [(object) ['url' => 'https://cdn.discordapp.com/voice.ogg', 'duration_secs' => 3.0]])));
            $this->fail('A link must not be used.');
        } catch (RuntimeException $e) {
            $this->assertSame('The folder for voice messages is not private to the bot.', $e->getMessage());
        } finally {
            exec('rm -rf ' . escapeshellarg($target));
        }
    }

    private function voiceMessage(?string $folder = null): VoiceMessage
    {
        return new VoiceMessage(new Transcriber('whisper-cli', '/models/ggml-base.bin', 'auto'), 'ffmpeg', $folder ?? $this->folder);
    }

    /**
     * @param list<object> $attachments
     */
    private function message(?int $flags = Message::FLAG_IS_VOICE_MESSAGE, array $attachments = []): Message
    {
        $message = static::getStubBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['__get', '__isset'])->getStub();
        $attributes = fn (string $attribute) => match ($attribute) {
            'flags' => $flags,
            'attachments' => $attachments,
            default => null,
        };
        $message->method('__get')->willReturnCallback($attributes);
        $message->method('__isset')->willReturnCallback(fn (string $attribute) => $attributes($attribute) !== null);

        return $message;
    }
}
