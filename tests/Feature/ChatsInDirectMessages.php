<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Assistant\DirectChat;
use App\Events\MessageCreate;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use ReflectionProperty;
use Tests\Fixtures\ManualTimers;
use Throwable;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Lets a {@see VoiceTestCase} send the bot direct messages, as fake Discord messages in fake DMs.
 * The bot's timers only run out when the test says so, with {@see $timers}.
 */
trait ChatsInDirectMessages
{
    protected const string ANSWER = 'Then ship the **beta** on Friday.';

    protected ManualTimers $timers;

    /** @var array<string, list<object>> The messages in each person's DM with the bot, oldest first. */
    protected array $dms = [];

    /** @var list<string> What happened in the DMs, in order: "typing", "history" or "sent", each with whose DM it was. */
    protected array $events = [];

    /** @var list<array<string, mixed>> The options the bot fetched message histories with. */
    protected array $historyOptions = [];

    /** When set, the typing indicator can't be shown. */
    protected ?Throwable $typingError = null;

    /** When set, the DM's messages can't be fetched. */
    protected ?Throwable $historyError = null;

    /** @var array<string, Channel> */
    private array $dmChannels = [];

    protected function loop(): LoopInterface
    {
        return $this->timers ??= new ManualTimers();
    }

    /**
     * Call from setUp(): gives Claude an answer to give.
     */
    protected function setUpDirectMessages(): void
    {
        $this->setProcessEnv(['FAKE_CLAUDE_OUTPUT' => $this->claudeSays(self::ANSWER)]);
    }

    /**
     * Call from tearDown(): chats outlive a message, like calls outlive a command.
     */
    protected function endDirectMessages(): void
    {
        (new ReflectionProperty(DirectChat::class, 'chats'))->setValue(null, []);
    }

    /**
     * Someone writes to the bot, and the bot gets the message like it gets every message: as a MESSAGE_CREATE event.
     *
     * @param string|null $guildId The server the message was sent in, or null for a direct message.
     */
    protected function write(string $content, string $userId = '555', string $name = 'Alice', bool $bot = false, ?string $guildId = null): void
    {
        $author = (object) ['id' => $userId, 'displayname' => $name, 'bot' => $bot];
        $channel = $this->dm($userId);

        if ($guildId === null) {
            $this->dms[$userId][] = (object) ['content' => $content, 'author' => $author];
        }

        $message = static::getStubBuilder(Message::class)->disableOriginalConstructor()->onlyMethods(['__get', '__isset'])->getStub();
        $attributes = fn (string $attribute) => match ($attribute) {
            'content' => $content,
            'author' => $author,
            'guild_id' => $guildId,
            'channel' => $channel,
            default => null,
        };
        $message->method('__get')->willReturnCallback($attributes);
        $message->method('__isset')->willReturnCallback(fn (string $attribute) => $attributes($attribute) !== null);

        // The method Application::handleEvent() finds on the class.
        (new MessageCreate($message, $this->discord, ['answerDirectMessage']))->handle();
    }

    /**
     * Alice writes to the bot and gets her answer.
     */
    protected function chat(string $content): void
    {
        $answers = count($this->sent);
        $this->write($content);
        $this->waitUntil(fn () => count($this->sent) > $answers, 'the answer');
    }

    /**
     * A person's DM with the bot. What the bot sends there is collected in {@see VoiceTestCase::$sent}.
     */
    private function dm(string $userId): Channel
    {
        if (isset($this->dmChannels[$userId])) {
            return $this->dmChannels[$userId];
        }

        $this->dms[$userId] ??= [];
        $channel = static::getStubBuilder(Channel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['broadcastTyping', 'getMessageHistory', 'sendMessage'])
            ->getStub();
        $channel->method('broadcastTyping')->willReturnCallback(function () use ($userId): PromiseInterface {
            $this->events[] = "typing {$userId}";

            return $this->typingError === null ? resolve(null) : reject($this->typingError);
        });
        // Like Discord: the newest messages, newest first.
        $channel->method('getMessageHistory')->willReturnCallback(function (array $options) use ($userId): PromiseInterface {
            $this->events[] = "history {$userId}";
            $this->historyOptions[] = $options;

            return $this->historyError === null
                ? resolve(array_reverse(array_slice($this->dms[$userId], -$options['limit'])))
                : reject($this->historyError);
        });
        $channel->method('sendMessage')->willReturnCallback(function (MessageBuilder $message) use ($userId): PromiseInterface {
            $this->assertSame(['parse' => []], $message->jsonSerialize()['allowed_mentions'] ?? null, 'Mentions are disabled.');
            $this->events[] = "sent {$userId}";
            $this->sent[] = $message->getContent();
            $this->dms[$userId][] = (object) ['content' => $message->getContent(), 'author' => (object) ['id' => '999', 'displayname' => 'Bot', 'bot' => true]];

            return $this->sendError === null ? $this->sending ?? resolve(null) : reject($this->sendError);
        });

        return $this->dmChannels[$userId] = $channel;
    }

    /**
     * What Claude Code was last given on its standard input: the prompt.
     */
    protected function lastPrompt(): string
    {
        preg_match('/^stdin=(.*)\n\z/ms', file_get_contents($this->claudeLog), $match);

        return $match[1] ?? '';
    }

    /**
     * The system prompt Claude Code was last given, on one line.
     */
    protected function lastSystemPrompt(): string
    {
        preg_match('/^arg=--system-prompt\narg=(.*?)\narg=--tools$/ms', file_get_contents($this->claudeLog), $match);

        return preg_replace('/\s+/', ' ', $match[1] ?? '');
    }

    /**
     * Claude was last run like in calls: without tools or MCP servers, in an empty directory.
     */
    protected function assertClaudeWasRestricted(): void
    {
        $claudeCall = file_get_contents($this->claudeLog);
        $workingDirectory = sys_get_temp_dir() . '/discord-bot-claude';

        $this->assertStringContainsString("arg=--tools\narg=\narg=--strict-mcp-config\narg=--no-session-persistence\n", $claudeCall);
        $this->assertStringContainsString("cwd={$workingDirectory}\n", $claudeCall);
        $this->assertSame([], array_diff(scandir($workingDirectory), ['.', '..']), 'Its working directory is empty.');
    }
}
