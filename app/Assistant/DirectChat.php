<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Support\CommandFailedException;
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
 * A voice message is transcribed first, and from then on is answered like a message that was
 * typed. The answer starts with a quote of what the bot heard.
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

    /** What the person is told when a voice message has no speech in it. */
    private const string NOT_HEARD = "I couldn't hear anything in that voice message.";

    /** @var array<string, self> Chats by user ID. */
    private static array $chats = [];

    /** The person's messages are answered one at a time, in the order they arrived. */
    private PromiseInterface $queue;

    /**
     * What was said since the memory was last updated, in order. A voice message keeps its place,
     * under its own key, as null until it is transcribed.
     *
     * @var array<int|string, string|null>
     */
    private array $unremembered = [];

    /** How many voice messages the chat got, to give each one its own key in $unremembered. */
    private int $voiceMessages = 0;

    /** Runs out when the conversation pauses. */
    private ?TimerInterface $pause = null;

    /** How often the person asked to be forgotten, to tell what was said before from what was said after. */
    private int $forgotten = 0;

    private function __construct(
        private readonly string $userId,
        private readonly Discord $discord,
        private readonly Memory $memory,
        private readonly Claude $claude,
        private readonly VoiceMessage $voiceMessage,
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
        $chat = self::$chats[$userId] ??= new self($userId, $discord, $memory = Memory::fromEnv(), $claude = Claude::fromEnv(), VoiceMessage::fromEnv(), new MemoryWriter($memory, $claude));
        $receivedAt = microtime(true);

        // Taken now: a message still waiting for its answer when the person uses /forget is forgotten too.
        $forgotten = $chat->forgotten;

        // What a voice message says is only known once it is transcribed, in its turn:
        // until then it only holds its place among what was said.
        $slot = null;

        if (VoiceMessage::isOne($message)) {
            $slot = 'voice-' . ++$chat->voiceMessages;
            $chat->unremembered[$slot] = null;
        } else {
            $chat->unremembered[] = "{$message->author->displayname}: {$message->content}";
        }

        $chat->waitForPause();
        // answer() never rejects, so one failure doesn't hold up the messages after it.
        $chat->queue = $chat->queue->then(fn () => $chat->answer($message, $receivedAt, $forgotten, $slot));
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
     * @param string|null $slot The key of a voice message in $unremembered, or null for a message that was typed.
     *                          A voice message is transcribed first.
     */
    private function answer(Message $message, float $receivedAt, int $forgotten, ?string $slot): PromiseInterface
    {
        $channel = $message->channel;
        $voice = $slot !== null;

        // Answers take a few seconds, and one typing indicator doesn't last that long.
        $this->showTyping($channel);
        $typing = $this->discord->getLoop()->addPeriodicTimer(self::TYPING_INTERVAL, fn () => $this->showTyping($channel));

        return ($voice ? $this->listen($message, $forgotten, $slot) : resolve($message->content))
            ->then(function (?string $text) use ($message, $channel, $receivedAt, $forgotten, $voice) {
                // The person was already told why the voice message was not listened to.
                if ($text === null) {
                    return null;
                }

                if ($text === '') {
                    return $this->send($channel, self::NOT_HEARD);
                }

                return $channel->getMessageHistory(['limit' => self::CONTEXT_MESSAGES])
                    ->then(fn (iterable $history) => $this->claude->ask($this->prompt($message, $text, $history), self::CHAT_PROMPT))
                    ->then(function (string $answer) use ($channel, $receivedAt, $forgotten, $voice, $text) {
                        // Discord refuses empty messages.
                        if ($answer === '') {
                            throw new RuntimeException('Claude gave an empty answer.');
                        }

                        $this->log('info', 'Answered a DM', ['ms' => (int) round((microtime(true) - $receivedAt) * 1000), 'characters' => mb_strlen($answer)]);

                        // An answer to something said before /forget isn't remembered either.
                        if ($forgotten === $this->forgotten) {
                            $this->unremembered[] = "Claude: {$answer}";
                        }

                        // What the bot heard comes first, so the person sees when whisper misheard. Voice messages
                        // show no text in the DM, so the quote is also what later prompts have of their words.
                        return $this->send($channel, $voice ? "> 🎤 {$text}\n{$answer}" : $answer);
                    })
                    ->catch(function (Throwable $e) use ($channel, $voice, $text) {
                        $this->log('warning', 'Could not answer a DM: ' . $e->getMessage());

                        // The quote is still posted: without it, the words of a voice message are in the DM nowhere.
                        return $this->send($channel, ($voice ? "> 🎤 {$text}\n" : '') . "Sorry, I couldn't get an answer from Claude. ({$e->getMessage()})");
                    });
            })
            ->finally(fn () => $this->discord->getLoop()->cancelTimer($typing));
    }

    /**
     * Turns a voice message into text, and counts it as something the person said.
     *
     * @param string $slot The voice message's key in $unremembered.
     *
     * @return PromiseInterface<string|null> What was said, an empty string when nothing could be heard, or null
     *                                       when the person was told the message couldn't be listened to.
     *                                       It never rejects.
     */
    private function listen(Message $message, int $forgotten, string $slot): PromiseInterface
    {
        $channel = $message->channel;
        $started = microtime(true);

        return $this->voiceMessage->transcribe($message)
            ->then(function (string $text) use ($message, $started, $forgotten, $slot) {
                $this->log('info', 'Transcribed a voice message', [
                    'seconds' => VoiceMessage::seconds($message),
                    'ms' => (int) round((microtime(true) - $started) * 1000),
                    'characters' => mb_strlen($text),
                ]);

                // Like a typed message, in the place the voice message held, unless the person
                // asked to be forgotten while it was transcribed.
                if ($text !== '' && $forgotten === $this->forgotten) {
                    $this->unremembered[$slot] = "{$message->author->displayname}: {$text}";
                }

                return $text;
            })
            ->catch(function (Throwable $e) use ($channel) {
                if ($e instanceof VoiceMessageTooLongException) {
                    return $this->send($channel, $e->getMessage())->then(fn () => null);
                }

                $this->log('warning', 'Could not transcribe a voice message: ' . self::reason($e));

                return $this->send($channel, "Sorry, I couldn't transcribe that voice message.")->then(fn () => null);
            })
            // A message that wasn't heard has nothing to remember.
            ->finally(function () use ($slot) {
                if (($this->unremembered[$slot] ?? null) === null) {
                    unset($this->unremembered[$slot]);
                }
            });
    }

    /**
     * Why something failed, without what a failed program printed on its standard output:
     * whisper.cpp prints what was said there, and what was said is never logged.
     */
    private static function reason(Throwable $e): string
    {
        $message = $e->getMessage();

        return $e instanceof CommandFailedException && $e->stdout !== '' ? (strstr($message, ':', true) ?: $message) : $message;
    }

    /**
     * @param iterable<Message> $history The DM's last messages, newest first.
     */
    private function prompt(Message $message, string $text, iterable $history): string
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
            . "\n\nReply to this message from {$name}:\n\n{$text}";
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
        // Room for the code block's opening and closing lines, which only a text that is cut needs.
        $parts = mb_strlen($content) <= 2000 ? [$content] : VoiceSession::split($content, 2000 - 2 * self::FENCE_LENGTH);
        $open = null;

        foreach ($parts as $index => &$part) {
            if ($open !== null) {
                $part = "{$open}\n{$part}";
                $open = null;
            }

            foreach (preg_split('/\R/u', $part) as $line) {
                // A fence is a line of its own: "```npm test``` runs the tests" only holds code.
                if (preg_match('/^\s*```[^`]*$/u', $line) === 1) {
                    $open = $open === null ? mb_substr(trim($line), 0, self::FENCE_LENGTH) : null;
                }
            }

            // Only where the text is cut: the end of the last part is as Claude wrote it.
            if ($open !== null && $index < count($parts) - 1) {
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
     * When that fails, the next update is given what was said, too.
     */
    private function updateMemory(): PromiseInterface
    {
        // A voice message still waiting to be transcribed is left for the next update.
        $said = array_filter($this->unremembered, fn (?string $line) => $line !== null);

        // E.g. the person used /forget since the conversation paused: nothing is left to remember.
        if ($said === []) {
            return resolve(null);
        }

        $this->unremembered = array_diff_key($this->unremembered, $said);
        $forgotten = $this->forgotten;

        return $this->writer->update($this->userId, array_values($said), fn () => $forgotten === $this->forgotten)->then(function (?string $memory) {
            // The person asked to be forgotten meanwhile, or there is nothing to remember about them.
            if ($memory !== null) {
                $this->log('info', 'Updated memory', ['characters' => mb_strlen($memory)]);
            }
        })->catch(function (Throwable $e) use ($said, $forgotten) {
            // Unless the person asked to be forgotten meanwhile.
            if ($forgotten === $this->forgotten) {
                $this->unremembered = [...$said, ...$this->unremembered];
            }

            throw $e;
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
