<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\RecordCommand;
use App\Voice\VoiceSession;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Manager;
use ReflectionClass;
use RuntimeException;

use function React\Promise\reject;
use function React\Promise\resolve;

final class RecordCommandTest extends CommandTestCase
{
    /** @var list<array{Channel, bool, bool}> Calls to joinVoiceChannel: channel, mute, deaf. */
    private array $joins = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Voice is available: DiscordPHP created its voice manager.
        $this->discord->voice = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
    }

    public function testJoinsTheVoiceChannelAndStartsRecording(): void
    {
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        // Unmuted to speak answers, undeafened to hear the call.
        $this->assertSame([[$channel, false, false]], $this->joins);
        $this->assertTrue($this->acknowledged, 'Discord got a response within 3 seconds.');
        $this->assertSame(['🔴 Recording <#200>. Say "claude" to talk to me. Use /stop to end the recording.'], $this->updates);
        $this->assertNotNull($session = VoiceSession::forGuild(self::GUILD_ID));
        $this->assertDirectoryExists($session->directory);
        $this->assertStringStartsWith("{$this->recordings}/" . self::GUILD_ID . '/', $session->directory);
    }

    public function testMentionsThatEverythingIsAnsweredWithoutAWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        $this->assertSame(['🔴 Recording <#200>. I answer everything that is said. Use /stop to end the recording.'], $this->updates);
    }

    public function testReportsWhenTheVoiceChannelCannotBeJoined(): void
    {
        $this->joinsWith(reject(new RuntimeException('Missing the Speak permission.')));

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->updates);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->loggedProblems());
    }

    public function testRequiresTheMemberToBeInAVoiceChannel(): void
    {
        $this->record($this->interaction(null));

        $this->assertRefused('Join a voice channel first.');
    }

    public function testRefusesWhenAlreadyRecordingInTheServer(): void
    {
        $channel = $this->voiceChannel();
        VoiceSession::start($this->voiceClient($channel), $channel, $this->discord);

        $this->record($this->interaction($channel));

        $this->assertRefused('I am already recording in this server. Use /stop first.');
    }

    public function testRefusesWhenVoiceIsUnavailable(): void
    {
        $this->discord->voice = null;

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('Voice is not available: libdave or ext-ffi could not be loaded. Check the bot logs.');
    }

    public function testRefusesWhenAProgramIsMissing(): void
    {
        $this->setEnv(['WHISPER_BINARY' => '/nowhere/whisper-cli']);

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('`/nowhere/whisper-cli` was not found. Install it or set its path in .env.');
    }

    public function testRefusesWhenAModelIsMissing(): void
    {
        $this->setEnv(['PIPER_MODEL' => '/nowhere/voice.onnx']);

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('PIPER_MODEL in .env does not point to a model file.');
    }

    private function record(object $interaction): void
    {
        (new RecordCommand($this->discord))->handle($interaction);
    }

    private function joinsWith(object $promise): void
    {
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function (Channel $channel, $mute, $deaf) use ($promise) {
            $this->joins[] = [$channel, $mute, $deaf];

            return $promise;
        });
    }

    private function assertRefused(string $message): void
    {
        $this->assertSame([['content' => $message, 'ephemeral' => true]], $this->responses);
        $this->assertSame([], $this->joins);
        $this->assertFalse($this->acknowledged);
    }
}
