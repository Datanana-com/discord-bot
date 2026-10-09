<?php

declare(strict_types=1);

namespace App\Commands;

use App\Privacy\OptOuts;
use App\Voice\VoiceSession;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Thread\Thread;
use Discord\Parts\Interactions\Interaction;
use Discord\Voice\VoiceClient;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

/**
 * What the commands that record a voice channel share: /record and /meet.
 */
trait RecordsCalls
{
    /** What the announcement of a recording says about what the bot remembers of it. */
    /** What whoever wants a call recorded is told while the bot is stopping. */
    private const string STOPPING = 'I am being stopped right now, so I can\'t record. Try again once I am back.';

    private const string REMEMBERED = ' I remember each group\'s calls: see what I remember with /memory, and delete it with /forget.';

    /**
     * Why a call can't be recorded in the server right now, or null when one can.
     *
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings The server's settings.
     */
    private function recordingProblem(Interaction $interaction, array $settings): ?string
    {
        return match (true) {
            VoiceSession::refusesNewCalls() => self::STOPPING,
            VoiceSession::forGuild((string) $interaction->guild_id) !== null => 'I am already recording in this server. Use /stop first.',
            // Discord lets a bot be in one voice channel per server, and joining one takes a while.
            VoiceSession::isStarting((string) $interaction->guild_id) => 'I am already joining a voice channel in this server.',
            $this->discord->voice === null => 'Voice is not available: libdave or ext-ffi could not be loaded. Check the bot logs.',
            default => VoiceSession::missingSetup($settings) ?? $this->optOutsProblem($interaction),
        };
    }

    /**
     * Tells whoever used the command why it does nothing. Only they see it.
     */
    private function refuse(Interaction $interaction, string $command, string $problem): PromiseInterface
    {
        $this->log->info("{$command} refused: {$problem}", ['guild' => $interaction->guild_id]);

        return $interaction->respondWithMessage(MessageBuilder::new()->setContent($problem), ephemeral: true);
    }

    /**
     * Has other calls refused in the server until this one has started, or couldn't.
     *
     * @param callable(): PromiseInterface<mixed> $start Starts the call. Its promise settles once it is known how that went.
     * @return PromiseInterface<mixed> That promise.
     */
    private function starting(Interaction $interaction, callable $start): PromiseInterface
    {
        $guildId = (string) $interaction->guild_id;
        VoiceSession::starting($guildId);

        return $start()->finally(fn () => VoiceSession::starting($guildId, false));
    }

    /**
     * Joins the voice channel and starts recording it.
     *
     * @param Channel|Thread $textChannel Where Claude's answers and the call's summary are posted.
     * @param array{wake_word: ?string, language: ?string, voice: ?string, model: ?string} $settings The server's settings.
     *
     * @return PromiseInterface<VoiceSession> Rejects with what to tell whoever used the command, when the call couldn't start.
     */
    private function record(Interaction $interaction, Channel $voiceChannel, Channel|Thread $textChannel, array $settings): PromiseInterface
    {
        return $this->discord->joinVoiceChannel($voiceChannel, mute: false, deaf: false)->then(
            function (VoiceClient $vc) use ($interaction, $textChannel, $settings) {
                // The bot was told to stop while it was joining: nothing would stop this call before it ends.
                if (VoiceSession::refusesNewCalls()) {
                    $vc->close();

                    throw new RuntimeException(self::STOPPING);
                }

                try {
                    return VoiceSession::start($vc, $textChannel, $this->discord, $settings);
                } catch (Throwable $e) {
                    // The call starts with the list as it is now, which could still be read before joining.
                    $vc->close();

                    throw new RuntimeException($this->optOutsUnreadable($e, $interaction));
                }
            },
            function (Throwable $e) use ($interaction, $voiceChannel) {
                $this->log->error('Could not join the voice channel: ' . $e->getMessage(), ['guild' => $interaction->guild_id, 'channel' => $voiceChannel->id]);

                throw new RuntimeException('Could not join the voice channel: ' . $e->getMessage());
            },
        );
    }

    /**
     * What the announcement of a recording says about talking to Claude.
     */
    private function howToTalk(VoiceSession $session): string
    {
        $name = VoiceSession::wakeWordName($session->wakeWord);
        // The first of the leave phrase's spellings, like the wake word's. There is none in a server without a wake word, unless VOICE_LEAVE_PHRASE is set.
        $leave = VoiceSession::wakeWordName($session->leavePhrase);
        $stop = VoiceSession::wakeWordName($session->stopPhrase);

        return ($name === ''
            ? 'I answer everything that is said.'
            // Every question needs it: nothing is answered because of what someone said before.
            : "I only answer what is said with \"{$name}\" in it: say it with every question.")
            // It stops what the bot is saying, whoever says it. There is none in a server without a wake word.
            . ($stop === '' ? '' : " Say \"{$stop}\" to make me stop.")
            . ($leave === '' ? '' : " Say \"{$leave}\" to make me leave.");
    }

    /**
     * Refuses to record when the list of who opted out can't be read: without it, someone who
     * opted out would be recorded.
     */
    private function optOutsProblem(Interaction $interaction): ?string
    {
        try {
            (new OptOuts())->all();
        } catch (Throwable $e) {
            return $this->optOutsUnreadable($e, $interaction);
        }

        return null;
    }

    private function optOutsUnreadable(Throwable $e, Interaction $interaction): string
    {
        $this->log->error('Could not read who opted out of recording: ' . $e->getMessage(), ['guild' => $interaction->guild_id]);

        return 'I can\'t check who opted out of recording right now. Check the bot logs.';
    }
}
