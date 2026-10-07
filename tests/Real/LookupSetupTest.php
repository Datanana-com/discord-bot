<?php

declare(strict_types=1);

namespace Tests\Real;

use App\Assistant\LookupSlots;
use App\Assistant\Lookups;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

use function React\Async\await;

/**
 * Starts the command a lookup really runs, once with and once without an advisor, and checks what only the
 * real Claude Code shows: its `init` event and its `result`. The tests with a stand-in can't see these, and
 * they change whenever Claude Code is updated by hand: the bot doesn't update it.
 *
 * Run by hand, with `composer check:lookups`: it uses the logged-in subscription of whoever runs it, about
 * 0.3 USD and two minutes each time, so it is not in CI and not in the default test suites.
 *
 * The bot's own class runs it, with `CLAUDE_BINARY` pointing at a script that keeps what Claude Code prints.
 */
final class LookupSetupTest extends TestCase
{
    private const string HARD = 'Is Hetzner or AWS cheaper for a game server with 200 concurrent players? Compare dedicated servers with EC2 plus a load balancer and data transfer.';

    private const string SIMPLE = 'What is the latest stable version of PHP?';

    private string $folder;

    private string $run;

    /** @var array<string, string|false> What the environment was before the test. */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/lookup-setup-' . uniqid();
        mkdir($this->folder);
        $this->run = "{$this->folder}/run.jsonl";

        foreach (['CLAUDE_RUN_LOG', 'REAL_CLAUDE_BINARY'] as $name) {
            $this->environment[$name] = getenv($name);
        }

        // What Claude Code prints is kept next to the bot reading it.
        $wrapper = "{$this->folder}/keep-output";
        file_put_contents($wrapper, "#!/bin/bash\nset -o pipefail\n\"\$REAL_CLAUDE_BINARY\" \"\$@\" | tee \"\$CLAUDE_RUN_LOG\"\n");
        chmod($wrapper, 0755);

        putenv("REAL_CLAUDE_BINARY=" . env('CLAUDE_BINARY', 'claude'));
        putenv("CLAUDE_RUN_LOG={$this->run}");
        $_ENV['CLAUDE_BINARY'] = $wrapper;
        // Like the bot started by `composer serve`: a lookup must think anyway.
        putenv('MAX_THINKING_TOKENS=0');
        LookupSlots::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        putenv('MAX_THINKING_TOKENS');
        unset($_ENV['CLAUDE_BINARY']);
        LookupSlots::reset();
        exec('rm -rf ' . escapeshellarg($this->folder));
    }

    public function testStartsWithWebSearchAsTheOnlyToolAndConsultsTheAdvisorOfAHardTask(): void
    {
        $answer = $this->lookUp(self::HARD, hard: true);
        $events = $this->events();

        $this->assertNotSame('', $answer);
        $this->assertSetUp($events);

        // The advisor's model did work: it is in the usage of the run, with the model that looked it up.
        $advisor = (string) env('CLAUDE_LOOKUP_ADVISOR', 'opus');
        $models = array_keys($this->resultOf($events)['modelUsage'] ?? []);
        $this->assertNotSame([], array_filter($models, fn (string $model) => str_contains($model, $advisor)), "{$advisor} was consulted: " . implode(', ', $models));
        $this->report('hard', $events, $models);
    }

    public function testConsultsNoAdvisorForATaskThatIsNotHard(): void
    {
        $answer = $this->lookUp(self::SIMPLE, hard: false);
        $events = $this->events();

        $this->assertNotSame('', $answer);
        $this->assertSetUp($events);

        $advisor = (string) env('CLAUDE_LOOKUP_ADVISOR', 'opus');
        $models = array_keys($this->resultOf($events)['modelUsage'] ?? []);
        $this->assertSame([], array_filter($models, fn (string $model) => str_contains($model, $advisor)), "{$advisor} was not asked: " . implode(', ', $models));
        $this->report('simple', $events, $models);
    }

    private function lookUp(string $task, bool $hard): string
    {
        $lookups = Lookups::fromEnv(Loop::get(), function (string $level, string $message, array $context): void {
        });

        return (string) await($lookups->lookUp($task, 'check', 'Transcript of the voice call so far', fn () => "Alice: Hey Claude, I need this looked up.\nClaude: Let me look into that.", $hard));
    }

    /**
     * @return list<array<string, mixed>> The events Claude Code printed, in order.
     */
    private function events(): array
    {
        $this->assertFileExists($this->run, 'Claude Code printed nothing.');

        return array_values(array_filter(array_map(
            fn (string $line) => json_decode($line, true),
            file($this->run, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        ), is_array(...)));
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function assertSetUp(array $events): void
    {
        $init = array_values(array_filter($events, fn (array $event) => ($event['type'] ?? null) === 'system' && ($event['subtype'] ?? null) === 'init'))[0] ?? null;
        $this->assertNotNull($init, 'Claude Code printed no init event.');

        // Web search and nothing else: no file, shell or MCP tools, whoever runs the bot.
        $this->assertSame(['WebSearch'], $init['tools'], 'Its only tool is web search.');
        $this->assertSame([], $init['mcp_servers'] ?? [], 'No MCP server is started.');
        // Claude Code's own plugins are always there: only the ones of the user the bot runs as must not be.
        $plugins = array_filter($init['plugins'] ?? [], fn (array $plugin) => ($plugin['path'] ?? null) !== 'builtin');
        $this->assertSame([], array_values($plugins), 'No plugin of the user the bot runs as is loaded.');

        // None of that user's hooks ran either.
        $hooks = array_filter($events, fn (array $event) => str_starts_with((string) ($event['subtype'] ?? ''), 'hook_'));
        $this->assertSame([], array_values($hooks), 'No hook ran.');

        $result = $this->resultOf($events);
        $this->assertFalse($result['is_error'] ?? true, 'The lookup succeeded.');
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function resultOf(array $events): array
    {
        $results = array_values(array_filter($events, fn (array $event) => ($event['type'] ?? null) === 'result'));
        $this->assertNotSame([], $results, 'Claude Code printed no result.');

        return end($results);
    }

    /**
     * What a person running this wants to see: it is not asserted.
     *
     * @param list<array<string, mixed>> $events
     * @param list<string> $models
     */
    private function report(string $task, array $events, array $models): void
    {
        $result = $this->resultOf($events);
        $thinking = count(array_filter($events, fn (array $event) => ($event['event']['content_block']['type'] ?? null) === 'thinking'));
        fwrite(STDERR, sprintf(
            "\n[%s] %.0f s, %.2f USD, models: %s, thinking blocks: %d\n",
            $task,
            ($result['duration_ms'] ?? 0) / 1000,
            $result['total_cost_usd'] ?? 0,
            implode(', ', $models),
            $thinking,
        ));
    }
}
