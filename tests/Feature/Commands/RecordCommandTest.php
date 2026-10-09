<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Analytics\Usage;
use App\Commands\Global\RecordCommand;
use App\Privacy\OptOuts;
use App\Settings\GuildSettings;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Thread\Thread;
use Discord\Voice\Manager;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;
use React\Promise\Deferred;
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
        $this->assertSame(['🔴 Recording <#200>. I only answer what is said with "claude" in it: say it with every question. Say "stop claude" to make me stop. Say "disconnect claude" to make me leave. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);
        $this->assertNotNull($session = VoiceSession::forGuild(self::GUILD_ID));
        $this->assertDirectoryExists($session->directory);
        $this->assertStringStartsWith("{$this->recordings}/" . self::GUILD_ID . '/', $session->directory);
    }

    public function testTellsPeopleToSayTheFirstSpellingOnly(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        // The stop phrase has a spelling for each of the wake word's, and people are told the first.
        $this->assertStringContainsString('I only answer what is said with "claude" in it: say it with every question. Say "stop claude" to make me stop. Say "disconnect claude" to make me leave. Use /stop', $this->updates[0]);
        $this->assertStringNotContainsString('cloud', $this->updates[0]);
    }

    public function testAnnouncesTheStopPhraseByItsFirstSpelling(): void
    {
        // Saying it stops what the bot is saying, whoever says it: people are told what it is in this server.
        $this->setEnv(['VOICE_STOP_PHRASE' => 'para claude, parar claude']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        $this->assertSame(['🔴 Recording <#200>. I only answer what is said with "claude" in it: say it with every question. Say "para claude" to make me stop. Say "disconnect claude" to make me leave. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);
        $this->assertStringNotContainsString('parar claude', $this->updates[0]);
        $this->assertStringNotContainsString('stop claude', $this->updates[0]);
    }

    public function testAnnouncesTheLeavePhraseThatReplacesTheDefault(): void
    {
        // Its first spelling is the one people are told.
        $this->setEnv(['VOICE_LEAVE_PHRASE' => 'hang up claude, hang up on claude']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        $this->assertStringContainsString('say it with every question. Say "stop claude" to make me stop. Say "hang up claude" to make me leave. Use /stop', $this->updates[0]);
        $this->assertStringNotContainsString('hang up on claude', $this->updates[0]);
    }

    public function testAnnouncesTheLeavePhraseOfAServerWithoutAWakeWordToo(): void
    {
        // It has no stop phrase, whatever the variable says: everything said is answered.
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'VOICE_LEAVE_PHRASE' => 'hang up', 'VOICE_STOP_PHRASE' => 'quiet']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        $this->assertStringContainsString('I answer everything that is said. Say "hang up" to make me leave. Use /stop', $this->updates[0]);
    }

    public function testMentionsThatEverythingIsAnsweredWithoutAWakeWord(): void
    {
        // There is nothing to stop either, whatever the stop phrase is.
        $this->setEnv(['VOICE_WAKE_WORD' => '', 'VOICE_STOP_PHRASE' => 'para claude']);
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        $this->assertSame(['🔴 Recording <#200>. I answer everything that is said. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);
    }

    public function testPostsInTheThreadTheCommandWasUsedIn(): void
    {
        $channel = $this->voiceChannel();
        // Not a channel to DiscordPHP, but one to post in all the same.
        $posted = [];
        $thread = static::getStubBuilder(Thread::class)->disableOriginalConstructor()->onlyMethods(['sendMessage'])->getStub();
        $thread->method('sendMessage')->willReturnCallback(function (MessageBuilder $message) use (&$posted) {
            $posted[] = $message->getContent();

            return resolve(null);
        });
        $this->joinsWith(resolve($vc = $this->voiceClient($channel)));

        $this->record($this->interaction($channel, channel: $thread));

        $this->assertSame(['🔴 Recording <#200>. I only answer what is said with "claude" in it: say it with every question. Say "stop claude" to make me stop. Say "disconnect claude" to make me leave. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        // By reference: an arrow function would keep the list as it is now.
        $this->waitUntil(function () use (&$posted) {
            return $posted !== [];
        }, 'the answer to be posted');

        $this->assertSame(["> **Alice:** Hey Claude, what time is it?\nIt is a quarter past four."], $posted);
        $this->assertSame([], $this->sent, 'Not in the voice channel\'s own chat.');
        $this->assertSame([], $this->loggedProblems());
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
        $this->assertSame(['🔴 Recording <#200>. I only answer what is said with "jarvis" in it: say it with every question. Say "stop jarvis" to make me stop. Say "disconnect jarvis" to make me leave. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);

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
        // Only the settings can't be read: without the list of who opted out, nothing would be recorded.
        DB::connection(Usage::CONNECTION)->statement('CREATE VIEW guild_settings AS SELECT * FROM missing');
        $channel = $this->voiceChannel();
        $this->joinsWith(resolve($vc = $this->voiceClient($channel)));

        $this->record($this->interaction($channel));

        // Settings never stop a call from starting.
        $this->assertNotNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['🔴 Recording <#200>. I only answer what is said with "computer" in it: say it with every question. Say "stop computer" to make me stop. Say "disconnect computer" to make me leave. Use /stop to end the recording, or /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'], $this->updates);

        $this->speak($vc, ssrc: 1, userId: '555', seconds: 1.0);
        $this->waitUntil(fn () => $this->played !== [], 'the answer to be spoken');

        $this->assertStringContainsString("arg=--language\narg=en\n", file_get_contents("{$this->recordings}/whisper.log"));
        $this->assertStringContainsString("arg=--model\narg=opus\n", file_get_contents($this->claudeLog));
        $this->assertStringContainsString(
            "arg=--model\narg={$this->recordings}/models/voice.onnx\n",
            file_get_contents("{$this->recordings}/piper.log"),
        );

        // It is logged, once for the call.
        $problems = $this->loggedProblems();
        $this->assertCount(1, $problems);
        $this->assertStringStartsWith('Could not read the server settings: ', $problems[0]);
        $this->assertSame([['guild' => self::GUILD_ID]], $this->logged($problems[0]));
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

    public function testRefusesWhileTheBotIsStillJoiningForAnotherCall(): void
    {
        $channel = $this->voiceChannel();
        $joining = new Deferred();
        $joins = 0;
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function () use ($joining, &$joins) {
            // The bot joins for the first call, and is still joining for the one after it.
            return ++$joins === 1 ? $joining->promise() : (new Deferred())->promise();
        });
        $this->record($this->interaction($channel));

        // Until the bot has joined, there is no call to find in the server.
        $this->record($this->interaction($channel));

        $this->assertSame([['content' => 'I am already joining a voice channel in this server.', 'ephemeral' => true]], $this->responses);
        $this->assertSame(1, $joins);

        // Once it has, it is the call that is in the way.
        $joining->resolve($this->voiceClient($channel));
        $this->record($this->interaction($channel));

        $this->assertSame('I am already recording in this server. Use /stop first.', $this->responses[1]['content']);

        // And once that call is over, nothing is.
        VoiceSession::forGuild(self::GUILD_ID)->stop();
        $this->record($this->interaction($channel));

        $this->assertCount(2, $this->responses);
        $this->assertSame(2, $joins);
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
        $this->assertSame([], $this->logged('Could not read who opted out of recording: Database connection [stats] not configured.'));
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

    public function testRefusesWhileTheBotIsStopping(): void
    {
        // It waits for its calls to be summarized, however long: nothing would stop a call that starts meanwhile.
        VoiceSession::refuseNewCalls();

        $this->record($this->interaction($this->voiceChannel()));

        $this->assertRefused('I am being stopped right now, so I can\'t record. Try again once I am back.');
    }

    public function testLeavesTheChannelItWasJoiningWhenTheBotIsStoppedMeanwhile(): void
    {
        $channel = $this->voiceChannel();
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->record($this->interaction($channel));

        VoiceSession::refuseNewCalls();
        // It expects to be closed exactly once.
        $joining->resolve($this->voiceClient($channel, connected: true));

        $this->assertSame(['I am being stopped right now, so I can\'t record. Try again once I am back.'], $this->updates);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->logged('Voice session started'));
        $this->assertFalse(VoiceSession::isStarting(self::GUILD_ID));
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
