<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Commands\Global\RecordCommand;
use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Manager;
use Monolog\Logger;
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

    public function testUsesTheServersSettings(): void
    {
        touch("{$this->recordings}/models/pt_BR-faber-medium.onnx");
        (new GuildSettings(new Logger('test')))->save(
            self::GUILD_ID,
            ['wake_word' => 'jarvis', 'language' => 'pt', 'voice' => 'pt_BR-faber-medium', 'model' => 'sonnet'],
            '555',
        );
        $this->setProcessEnv([
            'FAKE_WHISPER_OUTPUT' => 'Jarvis, que horas são?',
            'FAKE_WHISPER_LOG' => "{$this->recordings}/whisper.log",
            'FAKE_PIPER_LOG' => "{$this->recordings}/piper.log",
        ]);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($vc = $this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        // The announcement tells the call the server's wake word, not the one in .env ("claude").
        $this->assertSame(['🔴 Recording <#200>. Say "jarvis" to talk to me. Use /stop to end the recording.'], $this->updates);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        // Alice was understood in the server's language, and answered by its model, in its voice.
        $this->assertSame(["> **Alice:** Jarvis, que horas são?\nIt is a quarter past four."], $this->sent);
        $this->assertStringContainsString("arg=--language\narg=pt\n", file_get_contents("{$this->recordings}/whisper.log"));
        $this->assertStringContainsString("arg=--model\narg=sonnet\n", file_get_contents($this->claudeLog));
        $this->assertStringContainsString(
            "arg=--model\narg={$this->recordings}/models/pt_BR-faber-medium.onnx\n",
            file_get_contents("{$this->recordings}/piper.log"),
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testUsesTheEnvDefaultsWhenTheSettingsCannotBeRead(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'computer', 'WHISPER_LANGUAGE' => 'en', 'CLAUDE_MODEL' => 'opus']);
        $this->setProcessEnv([
            'FAKE_WHISPER_OUTPUT' => 'Computer, what time is it?',
            'FAKE_WHISPER_LOG' => "{$this->recordings}/whisper.log",
            'FAKE_PIPER_LOG' => "{$this->recordings}/piper.log",
        ]);
        $this->breakStatsDatabase();
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($vc = $this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        // Settings never stop a call from starting.
        $this->assertNotNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['🔴 Recording <#200>. Say "computer" to talk to me. Use /stop to end the recording.'], $this->updates);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertStringContainsString("arg=--language\narg=en\n", file_get_contents("{$this->recordings}/whisper.log"));
        $this->assertStringContainsString("arg=--model\narg=opus\n", file_get_contents($this->claudeLog));
        $this->assertStringContainsString(
            "arg=--model\narg={$this->recordings}/models/voice.onnx\n",
            file_get_contents("{$this->recordings}/piper.log"),
        );

        // It is logged, once for the call. The statistics are in the same database.
        $problems = array_count_values($this->loggedProblems());
        $this->assertSame(1, $problems['Could not read the server settings: Database connection [stats] not configured.']);
        $this->assertSame(
            ['Could not read the server settings: Database connection [stats] not configured.', 'Could not save usage statistics: Database connection [stats] not configured.'],
            array_keys($problems),
        );
        $this->assertSame([['guild' => self::GUILD_ID]], $this->logged('Could not read the server settings: Database connection [stats] not configured.'));
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

    public function testRefusesWhenTheWhisperModelIsMissing(): void
    {
        $this->setEnv(['WHISPER_MODEL' => '/nowhere/ggml-base.bin']);

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('WHISPER_MODEL in .env does not point to a model file.');
    }

    public function testRefusesWhenTheServersVoiceIsNoLongerInstalled(): void
    {
        // The voice was installed when it was chosen with /settings.
        (new GuildSettings(new Logger('test')))->save(self::GUILD_ID, [...GuildSettings::DEFAULTS, 'voice' => 'pt_BR-faber-medium'], '555');

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('The voice `pt_BR-faber-medium` is no longer installed. Choose another one with /settings.');
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
