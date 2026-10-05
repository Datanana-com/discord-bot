<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Application;
use App\Commands\Global\MeetCommand;
use App\Commands\Global\RecordCommand;
use App\Commands\Global\StopCommand;
use App\Voice\Meeting;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Http\Exceptions\NoPermissionsException;
use Discord\Parts\Application\Command\Command;
use Discord\Parts\Application\Command\Option;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Overwrite;
use Discord\Parts\Channel\Thread\Thread;
use Discord\Parts\Guild\Guild;
use Discord\Parts\Interactions\ApplicationCommand;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\Part;
use Discord\Parts\WebSockets\VoiceStateUpdate as VoiceState;
use Discord\Voice\Manager;
use Discord\WebSockets\Event;
use Monolog\Handler\AbstractHandler;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\Fixtures\ManualTimers;
use Throwable;

use function React\Async\await;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * /meet against a fake server: the channel it makes is the one of {@see voiceChannel()}, and
 * people join and leave it through the bot's handler of Discord's voice state events.
 */
final class MeetCommandTest extends CommandTestCase
{
    /** The channel /meet makes. */
    private const string MEETING = '200';

    /** The text channel /meet is used in, and its category. */
    private const string TEXT_CHANNEL = '50';

    private const string CATEGORY = '40';

    /** Bits of the permissions, as Discord numbers them. */
    private const int VIEW_CHANNEL = 1 << 10;

    private const int CONNECT = 1 << 20;

    private const int SPEAK = 1 << 21;

    /** The names people have on Discord. The bot only knows what Bob goes by in the server: Spartan. */
    private const array USERS = ['555' => 'Alice', '666' => 'Bob', '777' => 'Carol', '888' => 'Dave', '999' => 'Claude', '1234' => 'Jukebox'];

    private const string RECORDING = '🔴 Recording the meeting in <#200>. Say "claude" to talk to me, and "stop claude" when you\'re done. It ends when everyone has left, and its channel is deleted. Use /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.';

    private const string MISSING_PERMISSION = 'I can\'t make the meeting\'s channel: I need the Manage Channels permission, besides View Channels, Connect and Speak. Ask a server admin to give it to me.';

    private ManualTimers $timers;

    /** The bot as it starts, which hands Discord's events to the classes in app/Events. It never connects. */
    private Application $app;

    /** Its Discord client, for DiscordPHP to build what Discord sends with. */
    private Discord $client;

    /** The server's channels: what was made and deleted in it, and what Discord answers. */
    private object $channels;

    /** @var list<array{Channel, bool, bool}> Calls to joinVoiceChannel: channel, mute, deaf. */
    private array $joins = [];

    /** @var list<bool> Whether each deferred response was only shown to whoever used the command. */
    private array $acknowledgements = [];

    /** @var list<array{content: string, mentions: mixed, ephemeral: bool}> Messages sent after the response. */
    private array $invitations = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Voice is available: DiscordPHP created its voice manager.
        $this->discord->voice = (new ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
        $this->app = new Application(['token' => 'test-token', 'loop' => new StreamSelectLoop(), 'logger' => new Logger('discord', [new NullHandler()])]);
        $this->client = $this->app->discord;

        // Behaves like DiscordPHP's ChannelRepository, without the requests to Discord.
        $this->channels = new class ($this->client) {
            /** @var list<array<string, mixed>> What was sent to Discord to make each channel. */
            public array $made = [];

            /** @var list<string> The IDs of the channels that were deleted. */
            public array $deleted = [];

            /** @var array<string, object> The channels the server already has, by ID. */
            public array $existing = [];

            /** The channel Discord answers with when one is made. */
            public ?Channel $channel = null;

            public ?Throwable $makeError = null;

            public ?Throwable $deleteError = null;

            public function __construct(private Discord $client)
            {
            }

            /** @param array<string, mixed> $attributes */
            public function create(array $attributes): Channel
            {
                return new Channel($this->client, $attributes + ['guild_id' => '100']);
            }

            public function save(Channel $channel): PromiseInterface
            {
                // What DiscordPHP's repository posts for a channel it didn't get from Discord.
                $this->made[] = $channel->getCreatableAttributes();

                return $this->makeError === null ? resolve($this->channel) : reject($this->makeError);
            }

            public function delete(Channel $channel): PromiseInterface
            {
                $this->deleted[] = $channel->id;

                return $this->deleteError === null ? resolve($channel) : reject($this->deleteError);
            }

            public function get(string $key, string $id): ?object
            {
                return $key === 'id' ? $this->existing[$id] ?? null : null;
            }
        };
        $this->channels->channel = $this->voiceChannel();
    }

    protected function tearDown(): void
    {
        // Meetings outlive a command, like calls do.
        (new ReflectionProperty(Meeting::class, 'meetings'))->setValue(null, []);
        parent::tearDown();
    }

    protected function loop(): LoopInterface
    {
        return $this->timers ??= new ManualTimers();
    }

    public function testMakesAPrivateVoiceChannelForThePeopleInvited(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666', '777']);

        $access = self::VIEW_CHANNEL | self::CONNECT | self::SPEAK;
        $this->assertSame([false], $this->acknowledgements, 'Discord got a response within 3 seconds, which everyone sees.');
        $this->assertCount(1, $this->channels->made);
        $this->assertEquals(
            [
                // Whoever used the command first, by the names they go by in the server when the bot knows them.
                'name' => 'Meeting: Alex, Spartan, Carol',
                'type' => Channel::TYPE_GUILD_VOICE,
                // In the category of the channel the command was used in.
                'parent_id' => self::CATEGORY,
                'permission_overwrites' => [
                    // Nobody sees the channel: the role everyone has is named after the server.
                    ['id' => self::GUILD_ID, 'type' => Overwrite::TYPE_ROLE, 'allow' => 0, 'deny' => self::VIEW_CHANNEL, 'channel_id' => null],
                    // But for the bot, whoever used the command and the people invited.
                    ['id' => self::BOT_ID, 'type' => Overwrite::TYPE_MEMBER, 'allow' => $access, 'deny' => 0, 'channel_id' => null],
                    ['id' => '555', 'type' => Overwrite::TYPE_MEMBER, 'allow' => $access, 'deny' => 0, 'channel_id' => null],
                    ['id' => '666', 'type' => Overwrite::TYPE_MEMBER, 'allow' => $access, 'deny' => 0, 'channel_id' => null],
                    ['id' => '777', 'type' => Overwrite::TYPE_MEMBER, 'allow' => $access, 'deny' => 0, 'channel_id' => null],
                ],
                // DiscordPHP's default for a voice channel, which is Discord's too.
                'bitrate' => 64000,
            ],
            $this->channels->made[0],
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testInvitesEachPersonOnceAndNeitherWhoeverUsedTheCommandNorTheBot(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        // Alice invites herself, Bob twice and the bot.
        $this->meet(['555', '666', '666', self::BOT_ID]);

        $this->assertSame('Meeting: Alex, Spartan', $this->channels->made[0]['name']);
        $this->assertSame([self::GUILD_ID, self::BOT_ID, '555', '666'], array_column($this->channels->made[0]['permission_overwrites'], 'id'));
        // As a list: Discord refuses anything else.
        $this->assertSame(['666'], $this->invitations[0]['mentions']['users']);
        $this->assertSame(1, $this->logged('Meeting started')[0]['invited']);
    }

    public function testAMeetingWithOnlyTheBotPingsNobody(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        // The closest there is to calling the bot in a direct message.
        $this->meet([self::BOT_ID]);

        $this->assertSame('Meeting: Alex', $this->channels->made[0]['name']);
        $this->assertSame([self::GUILD_ID, self::BOT_ID, '555'], array_column($this->channels->made[0]['permission_overwrites'], 'id'));
        $this->assertSame([self::RECORDING], $this->updates);
        $this->assertSame([], $this->invitations);
        $this->assertSame(0, $this->logged('Meeting started')[0]['invited']);
    }

    public function testLeavesOutTheNameOfSomeoneDiscordSentNothingAbout(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        // And Carol's name is one PHP takes for nothing.
        $this->meet(['4321', '777'], names: ['777' => '0']);

        $this->assertSame('Meeting: Alex, 0', $this->channels->made[0]['name']);
        $this->assertSame(['4321', '777'], $this->invitations[0]['mentions']['users'], 'They are still invited.');
    }

    public function testCutsANameThatIsTooLongForAChannel(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666', '777', '888'], names: ['777' => str_repeat('é', 60), '888' => str_repeat('ü', 60)]);

        $this->assertSame('Meeting: Alex, Spartan, ' . str_repeat('é', 60) . ', ' . str_repeat('ü', 14), $this->channels->made[0]['name']);
        $this->assertSame(100, mb_strlen($this->channels->made[0]['name']), 'Discord refuses a longer name.');
    }

    public function testMakesTheChannelOutsideAnyCategoryWhenTheCommandWasUsedOutsideOne(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666'], source: $this->textChannel(category: null));

        $this->assertNull($this->channels->made[0]['parent_id']);
    }

    /**
     * @param class-string<Part> $class What DiscordPHP makes of the thread: a thread, or a channel when it wasn't sent that thread.
     */
    #[DataProvider('threads')]
    public function testMakesTheChannelInTheCategoryOfAThreadsChannel(string $class, int $type): void
    {
        // It expects to be closed exactly once.
        $this->joinsWith(resolve($vc = $this->voiceClient($this->channels->channel, connected: true)));
        // A thread's parent is the channel it is in, which Discord refuses as a category.
        $thread = $this->thread($class, $type);
        $this->channels->existing[self::TEXT_CHANNEL] = (object) ['parent_id' => self::CATEGORY];

        $this->meet(['666'], source: $thread);

        $this->assertSame(self::CATEGORY, $this->channels->made[0]['parent_id']);

        // The answers and the summary are posted in the thread: it is where the command was used.
        $session = VoiceSession::forGuild(self::GUILD_ID);
        $this->assertSame($thread, $this->textChannelOf($session));
        $this->joinsVoice('666');
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->leavesVoice('666');
        await($session->stop());

        $this->assertSame(['It is a quarter past four.'], $this->sent, 'The summary of the meeting.');
        $this->assertSame([], $this->loggedProblems());
    }

    /**
     * @return iterable<string, array{class-string<Part>, int}>
     */
    public static function threads(): iterable
    {
        yield 'a thread' => [Thread::class, Channel::TYPE_PUBLIC_THREAD];
        yield 'a private thread' => [Thread::class, Channel::TYPE_PRIVATE_THREAD];
        yield 'a thread of an announcement channel' => [Thread::class, Channel::TYPE_ANNOUNCEMENT_THREAD];
        yield 'a thread the bot was not sent' => [Channel::class, Channel::TYPE_PUBLIC_THREAD];
    }

    public function testMakesTheChannelOutsideAnyCategoryWhenAThreadsChannelIsUnknown(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666'], source: $this->thread(Thread::class, Channel::TYPE_PUBLIC_THREAD));

        $this->assertNull($this->channels->made[0]['parent_id'], 'Not in the thread\'s channel, which is no category.');
    }

    public function testJoinsTheChannelAndRecordsIt(): void
    {
        $source = $this->textChannel();
        $this->joinsWith(resolve($vc = $this->voiceClient($this->channels->channel, connected: true)));

        $this->meet(['666'], source: $source);

        // Unmuted to speak answers, undeafened to hear the meeting.
        $this->assertSame([[$this->channels->channel, false, false]], $this->joins);
        $this->assertSame([self::RECORDING], $this->updates);
        $this->assertNotNull($session = VoiceSession::forGuild(self::GUILD_ID));
        $this->assertStringStartsWith("{$this->recordings}/" . self::GUILD_ID . '/', $session->directory);
        // Like /record, answers and the summary are posted where the command was used.
        $this->assertSame($source, $this->textChannelOf($session));
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'channel' => self::MEETING, 'invited' => 1, 'session' => $session->id]],
            $this->logged('Meeting started'),
        );

        $this->joinsVoice('666');
        $this->speak($vc, ssrc: 2, userId: '666', seconds: 1.0);
        $this->leavesVoice('666');
        await($session->stop());

        // What Bob said was recorded and transcribed, and the meeting summarized.
        $this->assertWavDuration(1.0, "{$session->directory}/666-1.wav");
        $this->assertStringContainsString('Bob: Hey Claude, what time is it?', $this->transcript($session));
        $this->assertFileExists("{$session->directory}/summary.md");
        $this->assertSame([], $this->loggedProblems());
    }

    public function testTellsPeopleToSayTheFirstSpellingOnly(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => 'claude, cloud, claud']);
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666']);

        $this->assertSame([self::RECORDING], $this->updates);
    }

    public function testAnnouncesThatEverythingIsAnsweredWithoutAWakeWord(): void
    {
        $this->setEnv(['VOICE_WAKE_WORD' => '']);
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666']);

        $this->assertSame(
            ['🔴 Recording the meeting in <#200>. I answer everything that is said. It ends when everyone has left, and its channel is deleted. Use /optout if you don\'t want to be recorded. I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.'],
            $this->updates,
        );
    }

    public function testPingsOnlyThePeopleInvited(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));

        $this->meet(['666', '777']);

        // In a message of its own, which links the channel: Discord doesn't notify anyone of a response that was edited.
        $this->assertSame(
            [[
                'content' => '<@666> <@777> You are invited to the meeting in <#200>. It is recorded: use /optout if you don\'t want to be.',
                // Not Alice, who used the command, and nobody a name could mention.
                'mentions' => ['parse' => [], 'users' => ['666', '777']],
                'ephemeral' => false,
            ]],
            $this->invitations,
        );
        $this->assertSame([self::RECORDING], $this->updates);
        $this->assertSame([], $this->responses);
    }

    public function testEndsWhenTheLastPersonLeaves(): void
    {
        // It expects to be closed exactly once.
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel, connected: true)));
        $this->meet(['666']);
        $session = VoiceSession::forGuild(self::GUILD_ID);

        $this->joinsVoice('555');
        $this->joinsVoice('666');
        // Muting, or anything else that changes while they stay in the channel.
        $this->joinsVoice('666');
        $this->leavesVoice('666');

        $this->assertSame([], $this->channels->deleted, 'Alice is still in the meeting.');
        $this->assertSame($session, VoiceSession::forGuild(self::GUILD_ID));

        // Moving to another channel is leaving too.
        $this->joinsVoice('555', channel: '201');

        // The recording stopped as with /stop, and the channel is gone.
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'channel' => self::MEETING, 'invited' => 1, 'session' => $session->id]],
            $this->logged('Meeting ended'),
        );
        $this->assertNotContains(Meeting::JOIN_SECONDS, $this->timers->pending(), 'Nothing is left waiting for people to join.');
        $this->assertSame([], $this->loggedProblems());

        // It is over: nothing happens when someone leaves the channel that is gone.
        $this->leavesVoice('555');
        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertCount(1, $this->logged('Meeting ended'));
    }

    public function testDeletesTheChannelWhenNobodyJoinsInTime(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel, connected: true)));
        $this->meet(['666']);
        $session = VoiceSession::forGuild(self::GUILD_ID);

        $this->assertSame([300.0], array_values(array_diff($this->timers->pending(), [0.25])), 'The people invited have 5 minutes to join.');
        $this->assertSame([], $this->channels->deleted);

        $this->assertSame(1, $this->timers->elapse(Meeting::JOIN_SECONDS));

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'channel' => self::MEETING, 'invited' => 1, 'session' => $session->id]],
            $this->logged('Meeting ended'),
        );
        $this->assertSame([], $this->loggedProblems());
    }

    public function testKeepsTheChannelOfAMeetingSomeoneIsInOnceTheTimeToJoinIsUp(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);

        $this->joinsVoice('666');
        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([], $this->channels->deleted);
        $this->assertNotNull(VoiceSession::forGuild(self::GUILD_ID));

        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted);
    }

    public function testEndsWhenEveryoneLeftBeforeTheTimeToJoinIsUp(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);

        $this->joinsVoice('666');
        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted);
        // Nothing is left to happen once the time to join would have been up.
        $this->assertSame(0, $this->timers->elapse(Meeting::JOIN_SECONDS));
        $this->assertCount(1, $this->logged('Meeting ended'));
    }

    public function testTheBotIsNotOneOfThePeople(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);

        // Discord says that the bot joined, without saying that it is a bot.
        $this->joinsVoice(self::BOT_ID, bot: null);
        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([self::MEETING], $this->channels->deleted, 'Nobody joined: the bot doesn\'t count.');
    }

    public function testAnotherBotIsNotOneOfThePeople(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);

        // A bot a server admin moved in: admins see every channel.
        $this->joinsVoice('1234', bot: true);
        $this->joinsVoice('666');
        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted, 'The last person left: a bot staying behind doesn\'t count.');
    }

    public function testIsNotEndedByWhatHappensInOtherChannels(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);

        // While nobody is in the meeting yet, Carol joins and leaves another voice channel.
        $this->joinsVoice('777', channel: '201');
        $this->leavesVoice('777');

        $this->assertSame([], $this->channels->deleted, 'Bob still has time to join.');

        // And while Bob is in it, so does Dave in another server.
        $this->joinsVoice('666');
        $this->joinsVoice('888', channel: '202', guild: '101');
        $this->leavesVoice('888', guild: '101');

        $this->assertSame([], $this->channels->deleted);
        $this->assertNotNull(VoiceSession::forGuild(self::GUILD_ID));
    }

    public function testSomeoneWhoJoinsBeforeTheBotCounts(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666']);

        // The people invited see the channel as soon as it is made.
        $this->joinsVoice('666');
        $joining->resolve($this->voiceClient($this->channels->channel));
        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([], $this->channels->deleted, 'Bob is in the meeting.');

        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted);
    }

    public function testSomeoneLeavingBeforeTheBotJoinedLeavesTheOthersTheirTimeToJoin(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666', '777']);

        $this->joinsVoice('666');
        $this->leavesVoice('666');

        $this->assertSame([], $this->channels->deleted, 'Carol can still join.');

        $joining->resolve($this->voiceClient($this->channels->channel));

        $this->assertSame([], $this->channels->deleted);
        $this->assertSame([self::RECORDING], $this->updates);

        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([self::MEETING], $this->channels->deleted, 'Nobody is in it once the time to join is up.');
    }

    public function testSaysSoWhenTheBotTookLongerToJoinThanThePeopleInvitedHadTo(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666']);

        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame([], $this->logged('Meeting ended'), 'It never started.');

        // It expects to be closed exactly once.
        $joining->resolve($this->voiceClient($this->channels->channel, connected: true));

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID), 'A channel that is gone is not recorded.');
        $this->assertSame(['The meeting was over before I could join it, so I deleted its channel.'], $this->updates);
        $this->assertSame([], $this->invitations);
        $this->assertSame([], $this->logged('Meeting started'));
        $this->assertSame([self::MEETING], $this->channels->deleted);
    }

    public function testEndsWhenTheLastPersonLeavesAfterTheTimeToJoinWhileTheBotIsStillJoining(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666']);

        $this->joinsVoice('666');
        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([], $this->channels->deleted, 'Bob is in the channel.');

        $this->leavesVoice('666');

        // Nobody has time left to join, and nothing else would ever end the meeting.
        $this->assertSame([self::MEETING], $this->channels->deleted);

        // It expects to be closed exactly once.
        $joining->resolve($this->voiceClient($this->channels->channel, connected: true));

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['The meeting was over before I could join it, so I deleted its channel.'], $this->updates);
    }

    public function testFollowsEveryMeetingOnItsOwn(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);
        // Another meeting, which the bot didn't get to join.
        $other = static::getStubBuilder(Channel::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getStub();
        $other->method('__get')->willReturnCallback(fn (string $name) => $name === 'id' ? '201' : null);
        Meeting::open($other, $this->guild(), $this->discord, 1);

        $this->joinsVoice('666');
        $this->joinsVoice('777', channel: '201');
        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted, 'Carol is still in the other meeting.');

        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame([self::MEETING], $this->channels->deleted);

        $this->leavesVoice('777');

        $this->assertSame([self::MEETING, '201'], $this->channels->deleted);
    }

    public function testDeletesTheChannelOnceWhenTheBotFailsToJoinAfterTheTimeToJoinIsUp(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666']);

        $this->timers->elapse(Meeting::JOIN_SECONDS);
        $joining->reject(new RuntimeException('Voice client closed.'));

        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame(['Could not join the voice channel: Voice client closed.'], $this->updates);
    }

    public function testKeepsTheChannelUntilEveryoneLeftWhenTheRecordingIsStopped(): void
    {
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel, connected: true)));
        $this->meet(['666']);
        $this->joinsVoice('666');

        (new StopCommand($this->discord))->handle($this->interaction($this->channels->channel));

        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame([], $this->channels->deleted, 'Bob is still in the channel.');

        $this->leavesVoice('666');

        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertCount(1, $this->logged('Meeting ended'));
        $this->assertSame([], $this->loggedProblems());
    }

    public function testLogsWhenTheChannelCannotBeDeleted(): void
    {
        $this->channels->deleteError = new RuntimeException('Unknown Channel');
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel)));
        $this->meet(['666']);
        $session = VoiceSession::forGuild(self::GUILD_ID);

        $this->timers->elapse(Meeting::JOIN_SECONDS);

        $this->assertSame(['Could not delete the meeting\'s channel: Unknown Channel'], $this->loggedProblems());
        $this->assertSame(
            [['guild' => self::GUILD_ID, 'channel' => self::MEETING, 'invited' => 1, 'session' => $session->id]],
            $this->logged('Could not delete the meeting\'s channel: Unknown Channel'),
        );
    }

    public function testDeletesTheChannelWhenItCannotBeJoined(): void
    {
        $this->joinsWith(reject(new RuntimeException('Missing the Speak permission.')));

        $this->meet(['666']);

        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->updates);
        $this->assertSame([self::MEETING], $this->channels->deleted, 'A channel nobody can meet in is not left behind.');
        $this->assertSame([], $this->invitations, 'Nobody is invited to it.');
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertSame(['Could not join the voice channel: Missing the Speak permission.'], $this->loggedProblems());
        $this->assertSame([['guild' => self::GUILD_ID, 'channel' => self::MEETING]], $this->logged('Could not join the voice channel: Missing the Speak permission.'));
        $this->assertSame([], $this->logged('Meeting ended'), 'It never started.');
        $this->assertSame([], $this->timers->pending());
    }

    public function testDeletesTheChannelWhenTheOptOutListCannotBeReadAfterJoining(): void
    {
        // It expects to be closed exactly once.
        $vc = $this->voiceClient($this->channels->channel, connected: true);
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function () use ($vc) {
            // The list could be read when /meet was used, but no longer when the call starts.
            $this->breakStatsDatabase();

            return resolve($vc);
        });

        $this->meet(['666']);

        $this->assertSame(['I can\'t check who opted out of recording right now. Check the bot logs.'], $this->updates);
        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame([], $this->invitations);
        $this->assertNull(VoiceSession::forGuild(self::GUILD_ID));
        $this->assertDirectoryDoesNotExist("{$this->recordings}/" . self::GUILD_ID, 'Nothing was recorded.');
    }

    public function testSaysSoWhenTheBotMayNotManageChannels(): void
    {
        // Discord is asked, and refuses: the bot's roles alone don't tell, as a category can allow what they don't.
        $this->channels->makeError = new NoPermissionsException('Forbidden - {"message": "Missing Permissions", "code": 50013}');

        $this->meet(['666']);

        $this->assertSame([self::MISSING_PERMISSION], $this->updates);
        $this->assertSame([], $this->joins);
        $this->assertSame([], $this->invitations);
        $this->assertSame(['Could not make the meeting\'s channel: Forbidden - {"message": "Missing Permissions", "code": 50013}'], $this->loggedProblems());
        $this->assertSame([['guild' => self::GUILD_ID]], $this->logged($this->loggedProblems()[0]));
        $this->assertSame([], $this->timers->pending(), 'There is no meeting.');
    }

    public function testReportsWhenTheChannelCannotBeMade(): void
    {
        $this->channels->makeError = new RuntimeException('Maximum number of server channels reached (500)');

        $this->meet(['666']);

        $this->assertSame(['Could not make the meeting\'s channel: Maximum number of server channels reached (500)'], $this->updates);
        $this->assertSame([], $this->joins);
        $this->assertSame(['Could not make the meeting\'s channel: Maximum number of server channels reached (500)'], $this->loggedProblems());
    }

    public function testRefusesWhileTheBotIsStillJoiningAnotherCall(): void
    {
        $joining = new Deferred();
        $this->joinsWith($joining->promise());
        $this->meet(['666']);

        // Until the bot has joined, there is no call to find in the server, and Discord lets a bot be in one voice channel there.
        $this->meet(['777']);
        (new RecordCommand($this->discord))->handle($this->interaction($this->voiceChannel()));

        $refusal = ['content' => 'I am already joining a voice channel in this server.', 'ephemeral' => true];
        $this->assertSame([$refusal, $refusal], $this->responses);
        $this->assertCount(1, $this->channels->made, 'No channel is made to be deleted right away.');
        $this->assertCount(1, $this->joins);
        $this->assertSame([['guild' => self::GUILD_ID]], $this->logged('/meet refused: I am already joining a voice channel in this server.'));

        // In another server, nothing is in the way.
        (new RecordCommand($this->discord))->handle($this->interaction($this->voiceChannel(), guildId: '101'));

        $this->assertCount(2, $this->responses);
        $this->assertCount(2, $this->joins);

        // Once the bot could not join, nothing is in the way of another meeting.
        $joining->reject(new RuntimeException('Voice client closed.'));
        $this->meet(['777']);

        $this->assertCount(2, $this->channels->made);
        $this->assertCount(2, $this->responses);
    }

    public function testAnotherMeetingCanStartOnceOneIsOver(): void
    {
        // The bot joins the first meeting, and is still joining the second one.
        $this->discord->method('joinVoiceChannel')->willReturnOnConsecutiveCalls(
            resolve($this->voiceClient($this->channels->channel)),
            (new Deferred())->promise(),
        );
        $this->meet(['666']);
        $this->joinsVoice('666');
        $this->leavesVoice('666');

        $this->meet(['777']);

        $this->assertSame([], $this->responses, 'It is not refused.');
        $this->assertCount(2, $this->channels->made);
    }

    public function testDeletesTheChannelWhenTheCallCannotBeStopped(): void
    {
        // It expects to be closed exactly once.
        $this->joinsWith(resolve($this->voiceClient($this->channels->channel, connected: true)));
        $this->meet(['666']);
        $this->joinsVoice('666');
        // The disk is full: stopping the call fails when it logs that it stopped.
        $this->discord->getLogger()->pushHandler(new class () extends AbstractHandler {
            public function handle(LogRecord $record): bool
            {
                return $record->message === 'Voice session stopped' ? throw new RuntimeException('No space left on device') : false;
            }
        });

        $this->voiceState('666', channel: null);

        // Nothing else would delete a channel that only its people see.
        $this->assertSame([self::MEETING], $this->channels->deleted);
        $this->assertSame(
            ['Event "followMeetings" failed with the following error: No space left on device'],
            preg_grep('/^Event "/', $this->loggedProblems()),
        );
    }

    public function testRefusesOutsideAServer(): void
    {
        $this->meet(['666'], guildId: null);

        $this->assertRefused('Use /meet in a server.', guildId: null);
    }

    public function testRefusesWhileACallIsRecordedInTheServer(): void
    {
        // Discord lets a bot be in one voice channel per server.
        $channel = $this->voiceChannel();
        VoiceSession::start($this->voiceClient($channel), $channel, $this->discord);

        $this->meet(['666']);

        $this->assertRefused('I am already recording in this server. Use /stop first.');
    }

    public function testRefusesWhenVoiceIsUnavailable(): void
    {
        $this->discord->voice = null;

        $this->meet(['666']);

        $this->assertRefused('Voice is not available: libdave or ext-ffi could not be loaded. Check the bot logs.');
    }

    public function testRefusesWhenAProgramIsMissing(): void
    {
        $this->setEnv(['PIPER_BINARY' => '/nowhere/piper']);

        $this->meet(['666']);

        $this->assertRefused('`/nowhere/piper` was not found. Install it or set its path in .env.');
    }

    public function testRefusesWhenTheOptOutListCannotBeRead(): void
    {
        $this->breakStatsDatabase();

        $this->meet(['666']);

        // Without the list, someone who opted out would be recorded.
        $this->assertRefused('I can\'t check who opted out of recording right now. Check the bot logs.');
    }

    /**
     * Alice uses /meet in a text channel.
     *
     * @param list<string>          $people  The IDs of the people she picks, in the order of the command's options.
     * @param array<string, string> $names   What some of them are named on Discord, when not as in {@see USERS}.
     * @param Part|null             $source  The channel or thread she uses it in, when not {@see textChannel()}.
     * @param string|null           $guildId The server she uses it in, or null for a direct message.
     */
    private function meet(array $people, array $names = [], ?Part $source = null, ?string $guildId = self::GUILD_ID): void
    {
        $alice = (object) ['id' => '555', 'username' => 'alice', 'global_name' => 'Alice'];
        $names += self::USERS;
        $users = [];

        foreach ($people as $id) {
            // Discord sends who a picked ID is, for the people it knows.
            if (isset($names[$id])) {
                $users[$id] = (object) ['id' => $id, 'username' => strtolower($names[$id]), 'global_name' => $names[$id]];
            }
        }

        $interaction = static::getStubBuilder(ApplicationCommand::class)
            ->setConstructorArgs([
                $this->client,
                [
                    'id' => '901',
                    'type' => Interaction::TYPE_APPLICATION_COMMAND,
                    'token' => 'interaction-token',
                    'channel_id' => self::TEXT_CHANNEL,
                    'data' => (object) [
                        'id' => '900',
                        'name' => 'meet',
                        'type' => Command::CHAT_INPUT,
                        'options' => array_map(
                            fn (string $id, int $index) => (object) ['name' => 'person' . ($index === 0 ? '' : $index + 1), 'type' => Option::USER, 'value' => $id],
                            $people,
                            array_keys($people),
                        ),
                        'resolved' => (object) ['users' => (object) $users],
                    ],
                    ...($guildId === null
                        ? ['user' => $alice]
                        // Alice goes by Alex in the server.
                        : ['guild_id' => $guildId, 'member' => (object) ['user' => $alice, 'nick' => 'Alex', 'roles' => [], 'permissions' => '2048']]),
                ],
                true,
            ])
            ->onlyMethods(['getGuildAttribute', 'getChannelAttribute', 'respondWithMessage', 'acknowledgeWithResponse', 'updateOriginalResponse', 'sendFollowUpMessage'])
            ->getStub();
        $interaction->method('getGuildAttribute')->willReturn($guildId === null ? null : $this->guild());
        $interaction->method('getChannelAttribute')->willReturn($source ?? $this->textChannel());
        $interaction->method('respondWithMessage')->willReturnCallback(
            function (MessageBuilder $message, bool $ephemeral = false): PromiseInterface {
                $this->responses[] = ['content' => $message->getContent(), 'ephemeral' => $ephemeral];

                return resolve(null);
            }
        );
        $interaction->method('acknowledgeWithResponse')->willReturnCallback(function (bool $ephemeral = false): PromiseInterface {
            $this->acknowledgements[] = $ephemeral;

            return resolve(null);
        });
        $interaction->method('updateOriginalResponse')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            $this->updates[] = $message->getContent();

            return resolve(null);
        });
        $interaction->method('sendFollowUpMessage')->willReturnCallback(
            function (MessageBuilder $message, bool $ephemeral = false): PromiseInterface {
                $this->invitations[] = [
                    'content' => $message->getContent(),
                    'mentions' => $message->jsonSerialize()['allowed_mentions'] ?? null,
                    'ephemeral' => $ephemeral,
                ];

                return resolve(null);
            }
        );

        (new MeetCommand($this->discord))->handle($interaction);
    }

    /**
     * The server: its channels are {@see $channels}.
     */
    private function guild(): Guild
    {
        $members = new class () {
            public function get(string $key, string $id): ?object
            {
                return $key === 'id' && $id === '666' ? (object) ['displayname' => 'Spartan'] : null;
            }
        };

        $guild = static::getStubBuilder(Guild::class)->disableOriginalConstructor()->onlyMethods(['__get'])->getStub();
        $guild->method('__get')->willReturnCallback(fn (string $name) => match ($name) {
            'id' => self::GUILD_ID,
            'channels' => $this->channels,
            'members' => $members,
            default => null,
        });

        return $guild;
    }

    /**
     * The text channel /meet is used in. Messages sent to it are collected in {@see $sent}.
     */
    private function textChannel(?string $category = self::CATEGORY): Channel
    {
        $channel = static::getStubBuilder(Channel::class)->disableOriginalConstructor()->onlyMethods(['__get', 'sendMessage'])->getStub();
        $channel->method('__get')->willReturnCallback(fn (string $name) => match ($name) {
            'id' => self::TEXT_CHANNEL,
            'guild_id' => self::GUILD_ID,
            'parent_id' => $category,
            default => null,
        });
        $channel->method('sendMessage')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            $this->sent[] = $message->getContent();

            return resolve(null);
        });

        return $channel;
    }

    /**
     * A thread of the text channel, as DiscordPHP knows it. Messages sent to it are collected in {@see $sent}.
     *
     * @param class-string<Part> $class
     */
    private function thread(string $class, int $type): Part
    {
        $thread = static::getStubBuilder($class)->disableOriginalConstructor()->onlyMethods(['__get', 'sendMessage'])->getStub();
        $thread->method('__get')->willReturnCallback(fn (string $name) => match ($name) {
            'id' => '60',
            'type' => $type,
            'guild_id' => self::GUILD_ID,
            'parent_id' => self::TEXT_CHANNEL,
            default => null,
        });
        $thread->method('sendMessage')->willReturnCallback(function (MessageBuilder $message): PromiseInterface {
            $this->sent[] = $message->getContent();

            return resolve(null);
        });

        return $thread;
    }

    /**
     * Discord says that someone is in a voice channel now: they joined it, moved to it, or changed something while in it.
     *
     * @param bool|null $bot Whether Discord says they are a bot, or null when it doesn't say.
     */
    private function joinsVoice(string $userId, ?string $channel = self::MEETING, ?bool $bot = false, string $guild = self::GUILD_ID): void
    {
        $this->voiceState($userId, $channel, $bot, $guild);
        $this->assertSame([], preg_grep('/^(Event "|Error while handling event)/', $this->loggedProblems()), 'The event was handled.');
    }

    /**
     * Discord says which voice channel someone is in now, or that they are in none.
     */
    private function voiceState(string $userId, ?string $channel, ?bool $bot = false, string $guild = self::GUILD_ID): void
    {
        $user = ['id' => $userId, 'username' => strtolower(self::USERS[$userId])] + ($bot === null ? [] : ['bot' => $bot]);
        $state = new VoiceState($this->client, ['guild_id' => $guild, 'channel_id' => $channel, 'user_id' => $userId, 'member' => (object) ['user' => (object) $user]], true);

        // Through the bot as it starts, which has app/Events/VoiceStateUpdate.php handle the event.
        $this->app->discord->emit(Event::VOICE_STATE_UPDATE, [$state, $this->discord]);
    }

    /**
     * Discord says that someone left the voice channel they were in.
     */
    private function leavesVoice(string $userId, string $guild = self::GUILD_ID): void
    {
        $this->joinsVoice($userId, channel: null, guild: $guild);
    }

    private function joinsWith(PromiseInterface $promise): void
    {
        $this->discord->method('joinVoiceChannel')->willReturnCallback(function (Channel $channel, $mute, $deaf) use ($promise) {
            $this->joins[] = [$channel, $mute, $deaf];

            return $promise;
        });
    }

    private function textChannelOf(VoiceSession $session): Part
    {
        return (new ReflectionProperty(VoiceSession::class, 'textChannel'))->getValue($session);
    }

    private function assertRefused(string $message, ?string $guildId = self::GUILD_ID): void
    {
        $this->assertSame([['content' => $message, 'ephemeral' => true]], $this->responses);
        $this->assertSame([], $this->acknowledgements);
        $this->assertSame([], $this->channels->made, 'No channel was made.');
        $this->assertSame([], $this->joins);
        $this->assertSame([['guild' => $guildId]], $this->logged("/meet refused: {$message}"));
    }
}
