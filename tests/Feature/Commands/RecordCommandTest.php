<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\RecordCommand;
use App\Privacy\OptOuts;
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
        $this->assertSame(['🔴 Recording <#200>. Say "claude" to talk to me. Use /stop to end the recording, or /optout if you don\'t want to be recorded.'], $this->updates);
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

        $this->assertSame(['🔴 Recording <#200>. I answer everything that is said. Use /stop to end the recording, or /optout if you don\'t want to be recorded.'], $this->updates);
    }

    public function testReportsWhenTheVoiceChannelCannotBeJoined(): void
    {
        $this->joinsWith(reject(new RuntimeException('Missing the Speak permission.')));

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->updates);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->loggedProblems());
        $this->assertSame([['guild' => self::GUILD_ID, 'channel' => '200']], $this->logged('Could not join the voice channel: Missing the Speak permission.'));
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

    public function testRefusesWhenTheOptOutListCannotBeRead(): void
    {
        $this->breakStatsDatabase();

        $this->record($this->interaction($this->voiceChannel()));

        // Without the list, someone who opted out would be recorded.
        $this->assertRefused('I can\'t check who opted out of recording right now. Check the bot logs.');
        $this->assertSame(
            [['guild' => self::GUILD_ID]],
            $this->logged('Could not read who opted out of recording: Database connection [stats] not configured.'),
        );
    }

    public function testChecksOtherProblemsBeforeTheOptOutList(): void
    {
        $this->breakStatsDatabase();

        $this->record($this->interaction(null));

        $this->assertRefused('Join a voice channel first.');
        $this->assertSame([], $this->loggedProblems());
    }

    public function testLeavesTheCallWhenTheOptOutListCannotBeReadAfterJoining(): void
    {
        $channel = $this->voiceChannel();
        // It expects to be closed exactly once.
        $vc = $this->voiceClient($channel, connected: true);
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function () use ($vc) {
            // The list could be read when /record was used, but no longer when the call starts.
            $this->breakStatsDatabase();

            return resolve($vc);
        });

        $this->record($this->interaction($channel));

        $this->assertSame(['I can\'t check who opted out of recording right now. Check the bot logs.'], $this->updates);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertDirectoryDoesNotExist("{$this->recordings}/" . self::GUILD_ID, 'Nothing was recorded.');
        $this->assertSame(['Could not read who opted out of recording: Database connection [stats] not configured.'], $this->loggedProblems());
    }

    public function testStartsTheCallWithWhoOptedOutWhileJoining(): void
    {
        $this->setProcessEnv(['FAKE_WHISPER_OUTPUT' => 'Sounds good.']);
        $channel = $this->voiceChannel();
        $vc = $this->voiceClient($channel);
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function () use ($vc) {
            // Bob opts out after /record was used, while the bot is still joining.
            (new OptOuts())->add('666');

            return resolve($vc);
        });

        $this->record($this->interaction($channel));
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->runFor(1.5);

        $this->assertSame('', $this->transcript(VoiceSession::forGuild(self::GUILD_ID)));
        $this->assertCount(1, $this->logged('Skipping a speaker who opted out'));
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
        $this->assertSame([['guild' => self::GUILD_ID]], $this->logged("/record refused: {$message}"));
    }
}
