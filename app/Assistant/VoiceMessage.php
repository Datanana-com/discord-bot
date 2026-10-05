<?php

declare(strict_types=1);

namespace App\Assistant;

use App\Support\Download;
use App\Support\Shell;
use App\Voice\Transcriber;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;
use RuntimeException;

use function React\Promise\reject;

/**
 * A voice message sent in a direct message: Ogg Opus audio in the message's only attachment.
 *
 * It is downloaded, converted to WAV by ffmpeg and transcribed by whisper.cpp, all without
 * blocking the event loop. The files are deleted afterwards.
 */
final readonly class VoiceMessage
{
    /** Voice messages longer than this are refused. */
    public const int MAX_SECONDS = 300;

    /** What whisper.cpp reads best: 16 kHz, mono, 16 bits per sample. */
    private const int WAV_BYTES_PER_SECOND = 16000 * 2;

    public function __construct(
        private Transcriber $transcriber,
        private string $ffmpeg,
        private string $folder,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(Transcriber::fromEnv(), env('FFMPEG_BINARY', 'ffmpeg'), sys_get_temp_dir() . '/discord-bot-voice-messages');
    }

    public static function isOne(Message $message): bool
    {
        return (bool) (($message->flags ?? 0) & Message::FLAG_IS_VOICE_MESSAGE);
    }

    /**
     * How long the voice message is, as its sender's Discord client says.
     */
    public static function seconds(Message $message): float
    {
        return (float) (self::attachment($message)?->duration_secs ?? 0);
    }

    /**
     * @return PromiseInterface<string> What was said, or an empty string when nothing could be heard. It rejects
     *                                  with a {@see VoiceMessageTooLongException} for a message over
     *                                  {@see MAX_SECONDS}, and with another exception when it can't be transcribed.
     */
    public function transcribe(Message $message): PromiseInterface
    {
        $attachment = self::attachment($message);

        if ($attachment === null) {
            return reject(new RuntimeException('The voice message has no audio attached.'));
        }

        if (self::seconds($message) > self::MAX_SECONDS) {
            return reject(new VoiceMessageTooLongException());
        }

        if (! is_dir($this->folder) && ! @mkdir($this->folder, 0700, true) && ! is_dir($this->folder)) {
            return reject(new RuntimeException('The folder for voice messages could not be created.'));
        }

        // The temp folder is shared: a folder someone else made there, a link to somewhere else or a
        // folder others can read or change must not hold what people said.
        // getmyuid() is the owner of the script, which isn't who runs it when the code is deployed by another user.
        $user = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

        if (is_link($this->folder) || fileowner($this->folder) !== $user || (fileperms($this->folder) & 0077) !== 0) {
            return reject(new RuntimeException('The folder for voice messages is not private to the bot.'));
        }

        $base = $this->folder . '/' . bin2hex(random_bytes(8));
        $ogg = "{$base}.ogg";
        $wav = "{$base}.wav";

        return Download::toFile($attachment->url, $ogg)
            // The length Discord's client reported can't be trusted, so ffmpeg stops a second after the limit.
            ->then(fn () => Shell::run([$this->ffmpeg, '-loglevel', 'error', '-y', '-i', $ogg, '-t', (string) (self::MAX_SECONDS + 1), '-ar', '16000', '-ac', '1', '-c:a', 'pcm_s16le', $wav]))
            ->then(fn () => filesize($wav) > (self::MAX_SECONDS + 0.5) * self::WAV_BYTES_PER_SECOND
                ? reject(new VoiceMessageTooLongException())
                : $this->transcriber->transcribe($wav))
            ->finally(function () use ($ogg, $wav) {
                foreach ([$ogg, $wav] as $file) {
                    is_file($file) && unlink($file);
                }
            });
    }

    /**
     * @return object|null The first attachment: a voice message has no other.
     */
    private static function attachment(Message $message): ?object
    {
        foreach ($message->attachments ?? [] as $attachment) {
            return $attachment;
        }

        return null;
    }
}
