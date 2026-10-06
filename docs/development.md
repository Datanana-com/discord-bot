# Development

[← Back to the README](../README.md)

## Basic Example

### index.php
```php

<?php

require_once 'bootstrap.php';

use App\Application;
use Discord\WebSockets\Event;
use Discord\WebSockets\Intents;

/**
 * @see https://discord.com/developers/docs/intro
 */

$app = new Application([
    'token' => env('DISCORD_TOKEN'),
    'intents' => Intents::getDefaultIntents() | Intents::GUILD_MEMBERS,
    'loadAllMembers' => true,
]);

$app->discord->run();

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

The lookup depends on things only the real Claude Code shows, and Claude Code doesn't update itself here, so the tests with a stand-in can't notice when an update changes them. `composer check:lookups` (the `Real` suite, by hand, not in CI) starts the lookup's real command, once for a hard task and once for another, and checks what Claude Code prints: its web search is its only tool, no MCP server or hook of the user the bot runs as is there, the advisor's model ran for the hard task and not for the other. It uses your Claude subscription (about 0.3 USD and two minutes), and reads `CLAUDE_BINARY`, `CLAUDE_LOOKUP_MODEL` and `CLAUDE_LOOKUP_ADVISOR` from the shell, not from `.env`.

The code style is [Laravel Pint](https://laravel.com/docs/pint) with the rules of `pint.json`. `composer pint` fixes the files; `composer pint -- --test` only lists what it would change. The same check runs in GitHub Actions (the `pint` job of `tests.yml`, next to the tests), so a pull request with a style issue fails there instead of the issue reaching `master`.

## Live voice test

`tests/Live` asks the bot a question in a real Discord voice call. A second bot plays the spoken question "Hey Claude, what time is it?" and records the answer.

Everything except Claude is real: Discord with its end-to-end encryption, whisper.cpp and Piper. Claude is replaced by a fixed answer of two sentences, written one after the other like Claude Code does, so no Claude subscription is used. Like in any call, the stand-in is already running and waiting when the question comes, and one Piper process, the real one, speaks both sentences; the test fails when either had to be started for the answer. The same question is then asked a second time, and the stand-in hands it off, as Claude does for what it can't answer at once: the test checks that it is looked up through the real call (by the stand-in again), and what it found is posted, told and counted for `/stats`. The stand-in's answer for it is written to the file in `FAKE_ENV`, which the process that waits for a question reads once its question is there. It runs in GitHub Actions (`.github/workflows/live-voice.yml`) every night, on demand, and on pull requests that change the bot once they're no longer drafts. Every run keeps its recordings as an artifact you can download and listen to.

It needs its own private Discord server:

1. Create a server with a voice channel.
2. In the [Developer Portal](https://discord.com/developers/applications), create two applications, one for the bot under test and one for the "speaker". Copy each bot's token. On the bot under test, enable the **Server Members Intent**. Don't use the applications of a bot anyone uses: the test starts the bot of the branch under test with `BOT_SLASH_COMMANDS`, so its commands change with every branch, and a branch's start would warn about the commands of another one (and remove them, were `BOT_REMOVE_OLD_COMMANDS` set; the workflow doesn't set it).
3. Invite both bots with the `bot` scope and the View Channels, Connect, Speak and Send Messages permissions. Add the `applications.commands` scope for the bot under test.
4. In the GitHub repository, under Settings → Secrets and variables → Actions, add:
    - the secrets `DISCORD_TEST_BOT_TOKEN` and `DISCORD_TEST_SPEAKER_TOKEN`;
    - the variable `DISCORD_TEST_VOICE_CHANNEL_ID` (right-click the channel → Copy Channel ID, with Developer Mode enabled).

Until those are set, the workflow skips itself.
