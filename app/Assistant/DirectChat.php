<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Voice\Claude;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

use function React\Promise\resolve;

/**
 * A person's chat with Claude in their direct messages with the bot.
 *
 * Each message is answered in text, with what the bot remembers about the person and the
 * DM's recent messages. When the conversation pauses, Claude rewrites that memory from what
 * was said since its last update.
 *
 * The logs never include what was said, what Claude answered or the memory.
 */
final class DirectChat
{
    /** Messages of the DM given to Claude as context. */
    private const int CONTEXT_MESSAGES = 20;

    /** Seconds after the person's last message before their memory is updated. */
    private const float PAUSE = 600.0;

    /** Characters a code block's opening line keeps when a message opens it again, e.g. "```php". */
    private const int FENCE_LENGTH = 20;

    /** Seconds between typing indicators: Discord shows each one for about 10 seconds. */
    private const float TYPING_INTERVAL = 8.0;

    /** What Claude is asked to do with a direct message. */
    private const string CHAT_PROMPT = <<<'PROMPT'
        You are Claude, this person's personal assistant, chatting with them in their direct
        messages with a Discord bot. You get what you remember about them from earlier
        conversations and the recent messages of this chat, and reply to the message you are
        pointed to. Write in the language they write in. Discord's markdown is allowed. Keep a
        reply under 2000 characters, so it fits in one Discord message, unless they ask for more.
        Your memory of them is updated on its own when the conversation pauses, so you can agree to
        remember things, except passwords, tokens and other secrets, which are never remembered.
        They can read what you remember about them with /memory and erase it with /forget.
        PROMPT;

    /** @var array<string, self> Chats by user ID. */
    private static array $chats = [];

    /** The person's messages are answered one at a time, in the order they arrived. */
    private PromiseInterface $queue;

    /** @var list<string> What was said since the memory was last updated. */
    private array $unremembered = [];

    /** Runs out when the conversation pauses. */
    private ?TimerInterface $pause = null;

    /** How often the person asked to be forgotten, to tell what was said before from what was said after. */
    private int $forgotten = 0;

    private function __construct(
        private readonly string $userId,
        private readonly Discord $discord,
        private readonly Memory $memory,
        private readonly Claude $claude,
        private readonly MemoryWriter $writer,
    ) {
        $this->queue = resolve(null);
    }

    /**
     * Answers a direct message, after the ones the person sent before it.
     */
    public static function receive(Message $message, Discord $discord): void
    {
        $userId = (string) $message->author->id;
        $chat = self::$chats[$userId] ??= new self($userId, $discord, $memory = Memory::fromEnv(), $claude = Claude::fromEnv(), new MemoryWriter($memory, $claude));
        $receivedAt = microtime(true);

        // Taken now: a message still waiting for its answer when the person uses /forget is forgotten too.
        $forgotten = $chat->forgotten;

        $chat->unremembered[] = "{$message->author->displayname}: {$message->content}";
        $chat->waitForPause();
        // answer() never rejects, so one failure doesn't hold up the messages after it.
        $chat->queue = $chat->queue->then(fn () => $chat->answer($message, $receivedAt, $forgotten));
    }

    /**
     * Drops what the person said since their memory was last updated, so it is never remembered.
     */
    public static function forget(string $userId): void
    {
        $chat = self::$chats[$userId] ?? null;

        if ($chat === null) {
            return;
        }

        $chat->forgotten++;
        $chat->unremembered = [];
        $chat->cancelPause();
    }

    /**
     * @param int $forgotten How often the person had asked to be forgotten when the message arrived.
     */
    private function answer(Message $message, float $receivedAt, int $forgotten): PromiseInterface
    {
        $channel = $message->channel;

        // Answers take a few seconds, and one typing indicator doesn't last that long.
        $this->showTyping($channel);
        $typing = $this->discord->getLoop()->addPeriodicTimer(self::TYPING_INTERVAL, fn () => $this->showTyping($channel));

        return $channel->getMessageHistory(['limit' => self::CONTEXT_MESSAGES])
            ->then(fn (iterable $history) => $this->claude->ask($this->prompt($message, $history), self::CHAT_PROMPT))
            ->finally(fn () => $this->discord->getLoop()->cancelTimer($typing))
            ->then(function (string $answer) use ($channel, $receivedAt, $forgotten) {
                // Discord refuses empty messages.
                if ($answer === '') {
                    throw new RuntimeException('Claude gave an empty answer.');
                }

                $this->log('info', 'Answered a DM', ['ms' => (int) round((microtime(true) - $receivedAt) * 1000), 'characters' => mb_strlen($answer)]);

                // An answer to something said before /forget isn't remembered either.
                if ($forgotten === $this->forgotten) {
                    $this->unremembered[] = "Claude: {$answer}";
                }

                return $this->send($channel, $answer);
            })
            ->catch(function (Throwable $e) use ($channel) {
                $this->log('warning', 'Could not answer a DM: ' . $e->getMessage());

                return $this->send($channel, "Sorry, I couldn't get an answer from Claude. ({$e->getMessage()})");
            });
    }

    /**
     * @param iterable<Message> $history The DM's last messages, newest first.
     */
    private function prompt(Message $message, iterable $history): string
    {
        $name = $message->author->displayname;
        $memory = $this->memory->read($this->userId);
        $lines = [];

        foreach ($history as $earlier) {
            // Messages without text, e.g. a lone attachment, say nothing here.
            if ($earlier->content !== '') {
                // The only bot in a DM with this bot is this bot.
                array_unshift($lines, ($earlier->author?->bot ? 'Claude' : $name) . ": {$earlier->content}");
            }
        }

        // Messages still waiting for their answer can already be among the recent ones,
        // so Claude is told which one to reply to.
        return "What you remember about {$name}:\n\n"
            . ($memory === '' ? 'Nothing yet.' : $memory)
            . "\n\nThe recent messages of your chat with {$name}:\n\n"
            . implode("\n", $lines)
            . "\n\nReply to this message from {$name}:\n\n{$message->content}";
    }

    /**
     * Sends a text in the DM, split into several messages when it doesn't fit in one.
     *
     * @return PromiseInterface<mixed> It never rejects.
     */
    private function send(Channel $channel, string $content): PromiseInterface
    {
        // One message after the other, so they arrive in order.
        return array_reduce(
            self::parts($content),
            fn (PromiseInterface $sent, string $part) => $sent->then(fn () => $channel->sendMessage(
                // What Claude writes must never ping anyone.
                MessageBuilder::new()->setContent($part)->setAllowedMentions(['parse' => []]),
            )),
            resolve(null),
        )->catch(function (Throwable $e) {
            $this->log('warning', 'Could not send a DM: ' . $e->getMessage());
        });
    }

    /**
     * Splits a text into messages. A code block cut in two is closed at the end of the first
     * message and opened again at the start of the next, so both still show it as code.
     *
     * @return list<string>
     */
    public static function parts(string $content): array
    {
        // Room for the code block's opening and closing lines.
        $parts = VoiceSession::split($content, 2000 - 2 * self::FENCE_LENGTH);
        $open = null;

        foreach ($parts as &$part) {
            if ($open !== null) {
                $part = "{$open}\n{$part}";
                $open = null;
            }

            foreach (preg_split('/\R/u', $part) as $line) {
                if (str_starts_with(ltrim($line), '```')) {
                    $open = $open === null ? mb_substr(trim($line), 0, self::FENCE_LENGTH) : null;
                }
            }

            if ($open !== null) {
                $part .= "\n```";
            }
        }

        return $parts;
    }

    private function showTyping(Channel $channel): void
    {
        $channel->broadcastTyping()->catch(function (Throwable $e) {
            $this->log('debug', 'Could not show typing in a DM: ' . $e->getMessage());
        });
    }

    /**
     * Starts waiting for the conversation to pause, from the person's last message on.
     */
    private function waitForPause(): void
    {
        $this->cancelPause();
        $this->pause = $this->discord->getLoop()->addTimer(self::PAUSE, function () {
            $this->pause = null;
            // After the answers still being written, so the update includes them.
            $this->queue = $this->queue
                ->then($this->updateMemory(...))
                ->catch(function (Throwable $e) {
                    $this->log('warning', 'Could not update the memory: ' . $e->getMessage());
                });
        });
    }

    private function cancelPause(): void
    {
        if ($this->pause !== null) {
            $this->discord->getLoop()->cancelTimer($this->pause);
            $this->pause = null;
        }
    }

    /**
     * Has Claude rewrite the person's memory with what was said since its last update.
     * When that fails, what was said is not remembered.
     */
    private function updateMemory(): PromiseInterface
    {
        $said = $this->unremembered;

        // E.g. the person used /forget since the conversation paused: nothing is left to remember.
        if ($said === []) {
            return resolve(null);
        }

        $this->unremembered = [];
        $forgotten = $this->forgotten;

        return $this->writer->update($this->userId, $said, fn () => $forgotten === $this->forgotten)->then(function (?string $memory) {
            // The person asked to be forgotten meanwhile, or there is nothing to remember about them.
            if ($memory !== null) {
                $this->log('info', 'Updated memory', ['characters' => mb_strlen($memory)]);
            }
        });
    }

    /**
     * Logs something about the chat, with whose it is.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->discord->getLogger()->log($level, $message, ['user' => $this->userId, ...$context]);
    }
}
