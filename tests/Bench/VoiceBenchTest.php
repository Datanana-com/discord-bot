<?php

declare(strict_types=1);

namespace Tests\Bench;

use App\Settings\GuildSettings;
use App\Support\Shell;
use App\Voice\Transcriber;
use App\Voice\VoiceSession;
use Discord\Voice\Processes\OpusDecoderInterface;
use Dotenv\Dotenv;
use ReflectionProperty;
use Tests\Feature\VoiceTestCase;

use function React\Async\await;

/**
 * Times a question asked in a call, with the whisper.cpp, Claude Code and Piper of this machine:
 * the ones in .env, which the bot itself runs with. Discord is the fake one of the feature tests,
 * so what is timed is the bot's own code and the programs it starts, not the network to Discord.
 *
 * `composer bench:baseline` saves the times as this machine's baseline, and `composer bench` fails
 * when a later run is slower than it. Not for CI, which has neither the programs nor the hardware:
 * run it before merging something that could slow the bot down. See "Benchmark" in README.md.
 */
final class VoiceBenchTest extends VoiceTestCase
{
    /**
     * What is asked, spoken by Piper. Its answer always has two sentences, so the first one is spoken
     * while Claude still writes the second, like most answers in a call: the time to it is what counts.
     *
     * Nothing Claude would rather have looked up: asked for facts about a city, it handed the question off
     * now and then, which starts a web search with a bigger model and is no answer to time.
     */
    private const string QUESTION = 'Hey Claude, tell me in two short sentences why people like the weekend.';

    /**
     * How often it is asked. The times compared are the fastest of them: whatever else the machine or
     * the network is doing only ever adds time, so the fastest is the one that says most about the bot.
     */
    private const int QUESTIONS = 5;

    /** The settings of .env the bench runs with. Not where the bot keeps its data, nor its Discord login. */
    private const string SETTINGS = '/^(WHISPER|CLAUDE|PIPER|FFMPEG|VOICE)_/';

    /** Nor anything that looks like a login: the settings are printed, and saved with the baseline. */
    private const string SECRETS = '/TOKEN|KEY|SECRET|PASSWORD/';

    /** Bytes in 20 ms of 48 kHz, 16-bit stereo PCM: what one packet of a speaker's audio decodes to. */
    private const int FRAME = 3840;

    /** The times a slower run fails on. The others are only shown. */
    private const array COMPARED = ['whisper', 'claude', 'total'];

    /** A time is slower when it is this much over the baseline: both this share of it and this many milliseconds. */
    private const float SLOWER = 0.25;

    private const int SLOWER_MS = 200;

    /** The question as the voice client decodes it: 48 kHz, 16-bit stereo PCM. */
    private string $question;

    /** @var array<string, string> What the bench ran with, shown with the times and saved with the baseline. */
    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $file = getenv('BENCH_ENV_FILE') ?: dirname(__DIR__, 2) . '/.env';

        if (! is_file($file)) {
            $this->markTestSkipped("No settings to run the real programs with: {$file} doesn't exist. Set BENCH_ENV_FILE to the bot's .env.");
        }

        $settings = array_filter(
            Dotenv::parse(file_get_contents($file)),
            fn (string $name) => preg_match(self::SETTINGS, $name) === 1 && preg_match(self::SECRETS, $name) === 0,
            ARRAY_FILTER_USE_KEY,
        );
        // Without a wake word, so the bench doesn't depend on whisper hearing "Claude" exactly.
        $this->settings = ['FFMPEG_BINARY' => 'ffmpeg', ...array_map(strval(...), $settings), 'VOICE_WAKE_WORD' => ''];
        $this->setEnv($this->settings);

        // Claude Code reads this one from the environment it is started in. Answers in a call never think,
        // whatever it says, but the call's summary does, and takes seconds longer for it.
        if (getenv('MAX_THINKING_TOKENS') !== false) {
            $this->settings['MAX_THINKING_TOKENS'] = getenv('MAX_THINKING_TOKENS');
        }

        // Which whisper the bot runs, found like the bot finds it: the engine is a different time, and the baseline says which it was.
        $this->settings['WHISPER_SERVER'] = Transcriber::fromEnv()->server?->binary ?? 'none';

        if (($missing = VoiceSession::missingSetup(GuildSettings::DEFAULTS)) !== null) {
            $this->markTestSkipped("The bot isn't set up in {$file}: {$missing}");
        }

        $spoken = "{$this->recordings}/question.wav";
        await(Shell::run([env('PIPER_BINARY', 'piper'), '--model', env('PIPER_MODEL', ''), '--output_file', $spoken], self::QUESTION));
        await(Shell::run([env('FFMPEG_BINARY', 'ffmpeg'), '-loglevel', 'error', '-y', '-i', $spoken, '-f', 's16le', '-ar', '48000', '-ac', '2', "{$spoken}.pcm"]));
        $this->question = file_get_contents("{$spoken}.pcm");
        $this->assertGreaterThanOrEqual(self::FRAME, strlen($this->question), 'Piper spoke the question.');
    }

    public function testAnswersAQuestionAsFastAsTheBaseline(): void
    {
        $session = VoiceSession::start($vc = $this->voiceClient($channel = $this->voiceChannel()), $channel, $this->discord);

        // A whisper server transcribes once it has loaded its model, which a person talking to the bot takes longer to start than this does.
        if (Transcriber::fromEnv()->server !== null) {
            $this->waitUntil(fn () => $this->logged('Whisper server ready') !== [], 'the whisper server to be ready', 30.0);
        }

        $frames = intdiv(strlen($this->question), self::FRAME);
        // Stands in for libopus: every packet decodes to the next 20 ms of the question.
        $vc->opusdecoder = $decoder = new class ($this->question, $frames, self::FRAME) implements OpusDecoderInterface {
            /** Packets decoded since the question started. */
            public int $decoded = 0;

            public function __construct(private readonly string $pcm, private readonly int $frames, private readonly int $frame)
            {
            }

            public function decode($data, int $channels = 2, int $audioRate = 48000): string
            {
                return substr($this->pcm, ($this->decoded++ % $this->frames) * $this->frame, $this->frame);
            }
        };

        $saidAt = [];

        for ($asked = 0; $asked < self::QUESTIONS; $asked++) {
            // Every question from its first frame. Half a frame less than its length: speak() sends a packet
            // for every started 20 ms, and a length that isn't exact in binary would count as one more.
            $decoder->decoded = 0;
            $this->speak($vc, ssrc: 1, userId: '555', seconds: ($frames - 0.5) / 50);
            $saidAt[] = microtime(true);
            $this->waitUntil(
                fn () => $this->loggedProblems() !== [] || (count($this->sent) > $asked && count($this->logged('Started speaking')) > $asked),
                'answer ' . ($asked + 1),
                120.0,
            );
            $this->assertSame([], $this->loggedProblems(), 'The question was answered.');
            // The rest of the answer is synthesized and spoken before the next question, which would wait for it.
            await((new ReflectionProperty(VoiceSession::class, 'queue'))->getValue($session));
            // Handed off, its answer is one sentence that says so, and its times aren't those of an answer.
            $this->assertSame([], $this->logged('Looking something up'), 'The question was answered at once, not looked up in the background.');
        }

        await($session->stop());
        $this->assertSame([], $this->loggedProblems(), 'The call ended without a problem.');

        $ended = array_map(fn ($record) => (float) $record->datetime->format('U.u'), $this->recorded('Utterance ended'));
        $speaking = array_column($this->logged('Started speaking'), 'ms');
        $runs = [];

        for ($asked = 0; $asked < self::QUESTIONS; $asked++) {
            $silence = (int) round(($ended[$asked] - $saidAt[$asked]) * 1000);
            $runs[] = [
                'silence' => $silence,
                'whisper' => $this->logged('Transcribed')[$asked]['ms'],
                'claude' => $this->logged('Claude answered')[$asked]['ms'],
                'speaking' => $speaking[$asked],
                'total' => $silence + $speaking[$asked],
            ];
        }

        $steps = array_combine(array_keys($runs[0]), array_keys($runs[0]));
        $times = array_map(fn (string $step) => min(array_column($runs, $step)), $steps);
        $medians = array_map(fn (string $step) => self::median(array_column($runs, $step)), $steps);
        $times['summary'] = $medians['summary'] = $this->logged('Summarized the call')[0]['ms'] ?? 0;
        // A run that saves the baseline has nothing to compare with: the one before it may be of another format.
        $baseline = getenv('BENCH_SAVE') ? null : $this->baseline();

        $this->report($runs, $times, $medians, $baseline);

        if (getenv('BENCH_SAVE')) {
            self::save(['question' => self::QUESTION, 'settings' => $this->settings, 'commit' => self::commit(), 'saved' => date('Y-m-d H:i'), 'times' => $times]);
            fwrite(STDOUT, 'Saved as the baseline in ' . self::baselineFile() . ".\n");

            return;
        }

        if ($baseline === null) {
            fwrite(STDOUT, "No baseline to compare with yet: save one with `composer bench:baseline`.\n");

            return;
        }

        foreach (self::COMPARED as $step) {
            $limit = (int) round(max($baseline['times'][$step] * (1 + self::SLOWER), $baseline['times'][$step] + self::SLOWER_MS));
            $this->assertLessThanOrEqual($limit, $times[$step], "{$step} took {$times[$step]} ms at its fastest, against {$baseline['times'][$step]} ms in the baseline of {$baseline['saved']}.");
        }
    }

    /**
     * The middle one of the times.
     *
     * @param list<int> $times
     */
    private static function median(array $times): int
    {
        sort($times);

        return $times[intdiv(count($times), 2)];
    }

    /**
     * @return list<\Monolog\LogRecord> Each time the message was logged, with when.
     */
    private function recorded(string $message): array
    {
        return array_values(array_filter($this->logs->getRecords(), fn ($record) => $record->message === $message));
    }

    /**
     * Prints each question's times, the fastest and the median of each step, and how the fastest compare with the baseline.
     *
     * @param list<array<string, int>> $runs
     * @param array<string, int> $times The fastest of each step.
     * @param array<string, int> $medians
     * @param array{question: string, settings: array<string, string>, commit: string, saved: string, times: array<string, int>}|null $baseline
     */
    private function report(array $runs, array $times, array $medians, ?array $baseline): void
    {
        $steps = [
            'silence' => 'waiting for silence',
            'whisper' => 'whisper',
            'claude' => 'Claude, the whole answer',
            'speaking' => 'from the utterance to the first sentence spoken',
            'total' => 'from the end of the question to the first sentence spoken',
            'summary' => "the call's summary, once",
        ];
        $lines = ['', 'Milliseconds for "' . self::QUESTION . '", asked ' . self::QUESTIONS . ' times, at ' . (self::commit() ?: 'an unknown commit') . ':', ''];

        foreach ($runs as $number => $run) {
            $lines[] = sprintf('  question %d: silence %d, whisper %d, Claude %d, first sentence %d, total %d', $number + 1, ...array_values($run));
        }

        $lines[] = '';
        $lines[] = sprintf('  %-58s %7s %7s%s', '', 'fastest', 'median', $baseline === null ? '' : ' baseline  change');

        foreach ($steps as $step => $label) {
            $before = $baseline['times'][$step] ?? null;
            $lines[] = sprintf(
                '  %-58s %7d %7d%s%s',
                $label,
                $times[$step],
                $medians[$step],
                $before === null ? '' : sprintf(' %8d %+7d', $before, $times[$step] - $before),
                in_array($step, self::COMPARED, true) ? '   compared' : '',
            );
        }

        $lines[] = '';

        foreach ($this->settings + ($baseline['settings'] ?? []) as $name => $value) {
            $now = $this->settings[$name] ?? null;
            $was = $baseline['settings'][$name] ?? null;
            $lines[] = '  ' . ($now === null ? "{$name} is not set" : "{$name}={$now}")
                . ($baseline !== null && $was !== $now ? '   (baseline: ' . ($was ?? 'not set') . ')' : '');
        }

        if ($baseline !== null) {
            $lines[] = '';
            $lines[] = "  Baseline saved {$baseline['saved']}" . ($baseline['commit'] === '' ? '' : " at {$baseline['commit']}") . '.';
        }

        fwrite(STDOUT, implode("\n", $lines) . "\n\n");
    }

    private static function baselineFile(): string
    {
        return getenv('BENCH_BASELINE') ?: (getenv('HOME') ?: sys_get_temp_dir()) . '/.cache/discord-bot-bench.json';
    }

    /**
     * The saved baseline, or null when none was saved yet. A file that isn't one fails the bench:
     * taking it for no baseline would let a slower bot pass. So does the baseline of another question,
     * whose times say nothing about this one's.
     *
     * @return array{question: string, settings: array<string, string>, commit: string, saved: string, times: array<string, int>}|null
     */
    private function baseline(): ?array
    {
        $file = self::baselineFile();

        if (! is_file($file)) {
            return null;
        }

        $baseline = json_decode(file_get_contents($file), true);
        $again = "{$file} is not a baseline of this bench. Save it again with `composer bench:baseline`.";
        $this->assertIsArray($baseline, $again);

        foreach (['settings' => [], 'commit' => '', 'saved' => '', 'times' => []] as $part => $like) {
            $this->assertSame(gettype($like), gettype($baseline[$part] ?? null), $again);
        }

        foreach (self::COMPARED as $step) {
            $this->assertIsInt($baseline['times'][$step] ?? null, $again);
        }

        $this->assertSame(self::QUESTION, $baseline['question'] ?? null, $again);

        return $baseline;
    }

    /**
     * @param array{question: string, settings: array<string, string>, commit: string, saved: string, times: array<string, int>} $baseline
     */
    private static function save(array $baseline): void
    {
        if (! is_dir(dirname(self::baselineFile()))) {
            mkdir(dirname(self::baselineFile()), 0755, true);
        }

        // Written next to it and moved into place, so a run that is stopped meanwhile leaves the old baseline whole.
        file_put_contents(self::baselineFile() . '.new', json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        rename(self::baselineFile() . '.new', self::baselineFile());
    }

    /**
     * The commit the bench ran at, or nothing where there is no repository.
     */
    private static function commit(): string
    {
        return trim((string) @shell_exec('git rev-parse --short HEAD 2>/dev/null'));
    }
}
