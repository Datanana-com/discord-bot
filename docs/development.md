# Development

[← Back to the README](../README.md)

## Basic Example

### index.php
```php

<?php

require_once 'bootstrap.php';

use App\Application;
use App\Logs\Failures;
use App\Logs\Logger;
use Discord\WebSockets\Intents;

/**
 * @see https://discord.com/developers/docs/intro
 */

$logger = new Logger();
// What nothing catches is written to the bot's log before PHP ends, not only to the terminal.
Failures::register($logger);

$app = new Application([
    'token' => env('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS,
    'loadAllMembers' => true,
    'logger' => $logger,
]);

// Until it is stopped: with Ctrl+C or a signal, it first leaves its calls.
exit($app->run());

```

### app/Events/MessageCreate.php
```php
<?php

declare(strict_types=1);

namespace App\Events;

use App\EventAbstract;
use Discord\Discord;
use Discord\Parts\Channel\Message;

class MessageCreate extends EventAbstract
{
    /**
     * Logger instance
     *
     * @var Psr\Log\LoggerInterface
     */
    protected $log;

    /**
     * Setup functions
     * The name of this function is not required to be `setup`
     *  the only function names you cannot use are the ones inside EventAbstract::class
     *
     * @param Message $message Message event object
     * @param Discord $discord Discord class
     *
     * @return void
     */
    public function setUp(Message $message, Discord $discord)
    {
        $this->log = $discord->getLogger();

        $this->log->info('Log stuff');
    }

    /**
     * In this function, the event will terminate IF the function returns true
     * if it doesn't return true, it will just keep going until there's no more functions in the class.
     *
     * @param Message $message Message event object
     * @param Discord $discord Discord class
     *
     * @return true
     */
    public function terminateExample($message, $discord)
    {
        $this->log->info('another example');

        return true;
    }
}
```

## Slash commands

Each class in `app/Commands/Global`, named `<Name>Command` and extending `App\CommandAbstract`, is registered as the slash command `/<name>` in every server the bot is in, when `BOT_SLASH_COMMANDS` is set. Commands for a single server, in `app/Commands/Guild`, aren't supported yet.

The bot also looks at the global commands Discord has for its application when it starts. A command it has no class for (Discord tells commands apart by name and type, so a user or message command named like one of the bot's slash commands is also a leftover), such as one registered by an earlier project that used the same bot application or by another checkout of this repository, is named in a warning in the log: `Discord has global commands the bot has no class for: join, leave.` It is only removed when `BOT_REMOVE_OLD_COMMANDS` is set (each removal is logged by name), so switch it on only for an application that this checkout alone registers commands for: two checkouts with different commands under one application would remove each other's, and every command created again counts towards the limit Discord has per day. The bot doesn't remove commands of a single server: it doesn't fetch them.

A command can take options and be shown only to members with a permission: set its `$options`, each as Discord's [option object](https://docs.discord.com/developers/interactions/application-commands#application-command-object-application-command-option-structure), and its `$defaultMemberPermissions`, like `app/Commands/Global/SettingsCommand.php` does. Server admins can change who sees a command, so a command that needs a permission also checks it in `handle()`. A command is saved to Discord again when its description, options or permissions change.

A command's `handle()` returns the promise of what it is still doing when it returns, such as sending its reply, or `null`. When `handle()` throws, or that promise is rejected, whoever used the command is told "Something went wrong with /<name>. The bot's logs say what.", and the bot goes on. Only they see it, unless the command had already replied: that reply is then changed to it, as Discord takes one reply to a command, and stays as visible as it was, which for `/record` and `/meet` is to everyone in the channel. The message never says what failed: that is logged as `/<name> failed`, with the error (see [Logs and statistics](logs-and-statistics.md#logs-and-statistics)). An error in a class of `app/Events` is logged with the event's name, and the bot goes on too.

## Tests

```bash
composer test
composer test -- --testsuite Unit      # or Feature
```

Unit tests cover each class on its own. Feature tests run the whole voice flow, the direct messages with their memory, and the slash commands against a fake Discord, with whisper.cpp, Claude Code, Piper and, for voice messages, ffmpeg replaced by the scripts in `tests/Fixtures`, and Discord's attachment hosts by a small web server on your machine, so they need no models, Claude login or Discord connection.

The stand-ins for Claude Code and Piper keep running like the real programs do in a call: `fake-claude` waits for its question when it is started with `--input-format stream-json`, and `fake-piper` speaks a sentence for each line it reads.

`tests/Feature/VoiceCallTest.php` goes further: the call's audio travels over a local UDP socket standing in for Discord's media server, encrypted and Opus-encoded like in a real call, and the spoken answer is encoded by ffmpeg. It needs ffmpeg and libopus, like the bot itself, and is skipped without them.

A test that leaves a socket or a timer in ReactPHP's event loop fails the run. ReactPHP runs the loop when PHP ends, and a loop with something waiting in it never ends: the tests used to print `OK` and then hang, and CI waited for its 15 minute timeout without saying why. `tests/EventLoopCheck.php`, an extension set in `phpunit.xml`, looks into the loop when the tests are over and reports what is still waiting there: sockets with their addresses, and timers with the file and line of their callbacks, each with the test that left it. It then takes it all out of the loop, so the run ends. A test that starts a server or a timer closes or cancels it in `tearDown()`, as `tests/Fixtures/FakeCdn.php` does. The check reads ReactPHP's default event loop, so it needs PHP without the `ev`, `event` and `uv` extensions (CI has none), and it leaves out the Live suite, which talks to real Discord.

GitHub Actions runs these tests with coverage on pull requests and pushes to `master` that change PHP code, the tests, the dependencies, `phpunit.xml` or `pint.json` (`.github/workflows/tests.yml`), with ffmpeg and libopus installed so `VoiceCallTest` runs too. Draft pull requests aren't tested until they're marked ready for review.

To see the code coverage, install a coverage driver (`sudo apt install php8.5-pcov`, or Xdebug) and run:

```bash
composer test:coverage
```

The code style is [Laravel Pint](https://laravel.com/docs/pint) with the rules of `pint.json`. `composer pint` fixes the files; `composer pint -- --test` only lists what it would change. The same check runs in GitHub Actions (the `pint` job of `tests.yml`, next to the tests), so a pull request with a style issue fails there instead of the issue reaching `master`.

## Benchmark

The tests above can't tell whether the bot got slower: they run with stand-ins that answer at once. `composer bench` asks the bot a spoken question with the real whisper.cpp, Claude Code and Piper of your machine, the ones in `.env`, and times each step. Run it before merging something that could slow the bot down. It isn't run in CI, which has neither the programs nor your hardware.

```bash
composer bench:baseline   # on master: save this machine's times as the baseline
composer bench            # on the branch: fails when it is slower than the baseline
```

Piper speaks the question, "Hey Claude, tell me in two short sentences why people like the weekend.", into the fake Discord of the feature tests, 5 times in one call. It is one Claude answers at once: a question it hands off to be [looked up](lookups.md#looking-things-up) starts a web search with a bigger model, and is no answer to time, so the bench fails if that ever happens. The call goes through the bot's own code from there: the wait for silence, whisper, Claude and Piper. The times come from the bot's log, in milliseconds:

| Step | |
|---|---|
| waiting for silence | From the end of the question to the bot taking it as over. |
| whisper | Transcribing the question. Compared. |
| Claude, the whole answer | From asking Claude to its whole answer. Compared. |
| from the utterance to the first sentence spoken | `Started speaking` in the log. |
| from the end of the question to the first sentence spoken | What someone in the call waits for. Compared. |
| the call's summary, once | Only shown. |

For each step it shows the fastest and the median of the 5 questions. The fastest is what is compared with the baseline: whatever else the machine and the network are doing only ever adds time, so it says most about the bot. In three runs of the same code on a busy machine the fastest times were within 10% of each other, and the medians within 25%. A compared step fails when it is more than 25% and more than 200 ms over the baseline, so the bench catches what makes the bot clearly slower, not a few milliseconds.

It also shows the settings it ran with, and which of them differ from the baseline's: a slower model in `.env` is not a slower bot, and `WHISPER_SERVER` says which `whisper-server` the call ran, or `none` for `whisper-cli` alone: a baseline saved before the server was `none`, and has whatever `whisper-cli` took. The call waits for its whisper server to be ready before the first question, and the bench fails when it can't be. `MAX_THINKING_TOKENS` is shown when it is set where the bench is started, as Claude Code reads it from there: it changes how long the call's summary takes, not the answers, which are written without thinking whatever it says.

- The baseline is kept in `~/.cache/discord-bot-bench.json`, so every checkout of the bot on the machine compares with the same one. `BENCH_BASELINE` is another file to use.
- The settings are read from the checkout's `.env`. In a checkout without one, `BENCH_ENV_FILE` is the `.env` to read. Only the settings of the programs are used (`WHISPER_*`, `CLAUDE_*`, `PIPER_*`, `FFMPEG_*`, `VOICE_*`), never the Discord token or where the bot keeps its recordings, memories and statistics, and the wake word is left out so the bench doesn't depend on whisper hearing it. A setting with `TOKEN`, `KEY`, `SECRET` or `PASSWORD` in its name is left out too: the settings are printed, and saved with the baseline.
- A baseline file that can't be read as one, or that was saved for another question, fails the bench, with what to do: it is never taken for no baseline.
- Each run asks Claude 6 times with your subscription: the 5 questions and the call's summary.
- Without a `.env`, or with a program or model it names missing, the bench is skipped and says why.

## Live voice test

`tests/Live` asks the bot a question in a real Discord voice call. A second bot plays the spoken question "Hey Claude, what time is it?" and records the answer.

Everything except Claude is real: Discord with its end-to-end encryption, whisper.cpp (the `whisper-server` the call starts, built next to `whisper-cli`) and Piper. Claude is replaced by a fixed answer of two sentences, written one after the other like Claude Code does, so no Claude subscription is used. Like in any call, the stand-in is already running and waiting when the question comes, and one Piper process, the real one, speaks both sentences; the test fails when either had to be started for the answer. It runs in GitHub Actions (`.github/workflows/live-voice.yml`) every night, on demand, and on pull requests that change the bot once they're no longer drafts. Every run keeps its recordings as an artifact you can download and listen to.

It needs its own private Discord server:

1. Create a server with a voice channel.
2. In the [Developer Portal](https://discord.com/developers/applications), create two applications, one for the bot under test and one for the "speaker". Copy each bot's token. On the bot under test, enable the **Server Members Intent**. Don't use the applications of a bot anyone uses: the test starts the bot of the branch under test with `BOT_SLASH_COMMANDS`, so its commands change with every branch, and a branch's start would warn about the commands of another one (and remove them, were `BOT_REMOVE_OLD_COMMANDS` set; the workflow doesn't set it).
3. Invite both bots with the `bot` scope and the View Channels, Connect, Speak and Send Messages permissions. Add the `applications.commands` scope for the bot under test.
4. In the GitHub repository, under Settings → Secrets and variables → Actions, add:
    - the secrets `DISCORD_TEST_BOT_TOKEN` and `DISCORD_TEST_SPEAKER_TOKEN`;
    - the variable `DISCORD_TEST_VOICE_CHANNEL_ID` (right-click the channel → Copy Channel ID, with Developer Mode enabled).

Until those are set, the workflow skips itself.
