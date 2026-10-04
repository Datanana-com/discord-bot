Discord PHP Framework
====

This project was made to make it easier to start a bot, without having the clogged index file with the `->on` function & other things.

Requires PHP 8.5. It also includes a voice bot that records calls and lets people talk to Claude: see [Voice calls with Claude](#voice-calls-with-claude).

### Basic Example

##### index.php
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

##### app/Events/MessageCreate.php
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

##### Slash commands

Each class in `app/Commands/Global`, named `<Name>Command` and extending `App\CommandAbstract`, is registered as the slash command `/<name>` in every server the bot is in, when `BOT_SLASH_COMMANDS` is set. Commands for a single server, in `app/Commands/Guild`, aren't supported yet.

## Voice calls with Claude

`/record` joins your voice channel, records it, and lets everyone in it talk to Claude:

1. Each speaker is recorded to their own WAV file in `recordings/<server id>/<date>/`, next to a `transcript.txt` of the call.
2. When someone stops talking, what they said is transcribed on your machine with [whisper.cpp](https://github.com/ggml-org/whisper.cpp).
3. If it mentions the wake word ("Claude" by default), the recent transcript is sent to Claude through the [Claude Code CLI](https://code.claude.com/docs/en/headless), so it uses the Claude subscription you're logged in with instead of an API key.
4. Claude's answer is posted in the text channel and spoken back into the call with [Piper](https://github.com/OHF-Voice/piper1-gpl).
5. When the call ends, Claude summarizes its whole transcript: what was discussed, what was decided, and who does what next. The summary is posted in the text channel where `/record` was used, and saved as `summary.md` next to `transcript.txt`.

`/stop` finishes the recordings and leaves the channel, and `/stats` shows how the server has used the bot (see [Logs and statistics](#logs-and-statistics)).

A call ends with `/stop`, or when someone disconnects the bot from the voice channel. Its summary is written in the language of the call, once everything said is transcribed, so it includes the last thing said. A summary that doesn't fit in one Discord message is split into several, never in the middle of a sentence. When nobody said anything, there is no summary. When Claude can't make one (it isn't logged in, the usage limit is reached, ...), the bot says so in the text channel, and why.

> [!IMPORTANT]
> Only record people who have agreed to it. The bot announces in the channel when it starts recording.

Claude runs with every tool disabled, no MCP servers and from an empty directory, so nothing said in the call can make it read or change anything on your computer. Its answers and summaries do count against your subscription's usage limits, and anyone in the server can use `/record`.

### Setup on Windows (WSL2)

The voice library doesn't support native Windows, so run the bot inside WSL2 (these steps use Ubuntu 24.04). Keep the project in the Linux filesystem, e.g. `~/discord-bot`, not under `/mnt/c`.

1. Install PHP 8.5 and the system packages. `ext-ffi`, which voice needs, comes with `php8.5-common` and is enabled for the CLI by default. Then install [Composer](https://getcomposer.org/download/).

    ```bash
    sudo add-apt-repository ppa:ondrej/php
    sudo apt update
    sudo apt install php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip php8.5-sqlite3 \
        ffmpeg libopus0 unzip git curl cmake build-essential python3-venv
    ```

2. Install the bot's dependencies and libdave, Discord's end-to-end voice encryption, which every voice connection has required since March 2026. Run the script from the project folder: it installs into `.cache/libdave`, where the bot finds it automatically.

    ```bash
    cd ~/discord-bot
    composer install
    bash vendor/discord-php-helpers/voice/scripts/setup-libdave.sh
    ```

3. Build whisper.cpp and download a model. `base` handles every language; `small` is more accurate but slower.

    ```bash
    git clone https://github.com/ggml-org/whisper.cpp ~/whisper.cpp
    cd ~/whisper.cpp
    cmake -B build -DCMAKE_BUILD_TYPE=Release
    cmake --build build -j --config Release --target whisper-cli
    sh ./models/download-ggml-model.sh base
    ```

4. Install Piper and a voice. Pick a voice in your language from the [voice list](https://huggingface.co/rhasspy/piper-voices), e.g. `pt_BR-faber-medium` for Brazilian Portuguese.

    ```bash
    python3 -m venv ~/piper
    ~/piper/bin/pip install piper-tts
    ~/piper/bin/python -m piper.download_voices --download-dir ~/piper/voices en_US-lessac-medium
    ```

5. Install Claude Code, run it once and log in with your Claude Pro or Max account.

    ```bash
    curl -fsSL https://claude.ai/install.sh | bash
    claude
    ```

6. In the [Discord Developer Portal](https://discord.com/developers/applications), enable the bot's **Server Members Intent**, then invite it with the `bot` and `applications.commands` scopes and the View Channels, Send Messages, Connect and Speak permissions.

7. Copy `.env-local` to `.env`, fill in your bot token and the paths from the steps above, and start the bot:

    ```bash
    cp .env-local .env
    composer serve
    ```

### Configuration

| Variable | Default | |
|---|---|---|
| `BOT_SLASH_COMMANDS` | | Must be set for `/record`, `/stop` and `/stats` to be registered. |
| `RECORDINGS_PATH` | `recordings` | Where recordings and transcripts are saved. |
| `VOICE_WAKE_WORD` | `claude` | Claude only answers what mentions this word. Leave it empty to answer everything. |
| `WHISPER_BINARY` | `whisper-cli` | Path to whisper.cpp's `whisper-cli`. |
| `WHISPER_MODEL` | | Path to the whisper model, e.g. `~/whisper.cpp/models/ggml-base.bin`. |
| `WHISPER_LANGUAGE` | `auto` | Language spoken in the call, e.g. `en` or `pt`, or `auto` to detect it. |
| `CLAUDE_BINARY` | `claude` | Path to the Claude Code CLI. |
| `CLAUDE_MODEL` | `haiku` | `haiku` answers fastest; `sonnet` or `opus` answer better, but slower. |
| `PIPER_BINARY` | `piper` | Path to Piper. |
| `PIPER_MODEL` | | Path to the Piper voice, e.g. `~/piper/voices/en_US-lessac-medium.onnx`. |
| `FFMPEG_BINARY` | `ffmpeg` | Path to ffmpeg, which converts Piper's speech for Discord. The voice library always uses the `ffmpeg` on your `PATH`. |
| `STATS_DATABASE` | `databases/stats.sqlite` | SQLite database for the usage statistics. It is created on the first start. |

### Logs and statistics

Neither the logs nor the statistics contain what anyone said: that is only in the call's `transcript.txt` and `summary.md`.

**Logs** are printed to the console and written to `logs/<date>.log`, one JSON object per line. Each step of a call is logged with the server (`guild`), a `session` ID for the call, the `user` it concerns, and how long it took in milliseconds: the call starting, each new speaker, each utterance, its transcription, Claude's answer, the speech synthesis, failures, the call ending with its totals, and its summary. Slash commands are logged with who used them, and where. To search the log with [jq](https://jqlang.org/):

```bash
# Everything that happened in one call
jq -c 'select(.context.session == "1a2b3c4d") | [.datetime, .message, .context]' logs/2026-10-03.log
# How long Claude took to answer, in milliseconds
jq 'select(.message == "Claude answered") | .context.ms' logs/*.log
# Warnings and errors only
jq -c 'select(.level >= 300) | [.datetime, .message, .context]' logs/*.log
```

**Statistics** are kept in `STATS_DATABASE`, one row per event in the `events` table: `call_started`, `call_ended` (with the call's length), `utterance` (with its length), `answered` (with the time from the end of the question to the answer being posted) and `failed` (something said couldn't be transcribed or answered, or the answer couldn't be spoken), each with the server, channel, user and session. `/stats` shows the server it is used in its totals: calls and minutes recorded, utterances and people speaking, questions answered and how long that took on average, and failures. Summaries aren't counted. Only whoever used `/stats` sees them. To query the statistics yourself:

```bash
sqlite3 databases/stats.sqlite "SELECT guild_id, COUNT(*) AS answers FROM events WHERE type = 'answered' GROUP BY guild_id"
```

### Known limitations

- Answers take a few seconds: transcription, Claude Code starting up, and speech synthesis each add some.
- Speech recognition sometimes mishears the wake word (e.g. "cloud"). Change `VOICE_WAKE_WORD` if that happens often.
- The voice library (`discord-php-helpers/voice` 8.3.0) keeps every decoded audio frame in memory until `/stop`, roughly 12 MB per speaker per minute of speech. That's fine for normal calls; for very long ones, `/stop` and `/record` again now and then.

### Tests

```bash
composer test
composer test -- --testsuite Unit      # or Feature
```

Unit tests cover each class on its own. Feature tests run the whole voice flow and the `/record` and `/stop` commands against a fake Discord, with whisper.cpp, Claude Code and Piper replaced by the scripts in `tests/Fixtures`, so they need no models, Claude login or Discord connection.

`tests/Feature/VoiceCallTest.php` goes further: the call's audio travels over a local UDP socket standing in for Discord's media server, encrypted and Opus-encoded like in a real call, and the spoken answer is encoded by ffmpeg. It needs ffmpeg and libopus, like the bot itself, and is skipped without them.

GitHub Actions runs these tests with coverage on every pull request and push to `master` (`.github/workflows/tests.yml`), with ffmpeg and libopus installed so `VoiceCallTest` runs too.

To see the code coverage, install a coverage driver (`sudo apt install php8.5-pcov`, or Xdebug) and run:

```bash
composer test:coverage
```

### Live voice test

`tests/Live` asks the bot a question in a real Discord voice call. A second bot plays the spoken question "Hey Claude, what time is it?" and records the answer.

Everything except Claude is real: Discord with its end-to-end encryption, whisper.cpp and Piper. Claude is replaced by a fixed answer, so no Claude subscription is used. It runs in GitHub Actions (`.github/workflows/live-voice.yml`) every night, on demand, and on pull requests that change the bot. Every run keeps its recordings as an artifact you can download and listen to.

It needs its own private Discord server:

1. Create a server with a voice channel.
2. In the [Developer Portal](https://discord.com/developers/applications), create two applications, one for the bot under test and one for the "speaker". Copy each bot's token. On the bot under test, enable the **Server Members Intent**.
3. Invite both bots with the `bot` scope and the View Channels, Connect, Speak and Send Messages permissions. Add the `applications.commands` scope for the bot under test.
4. In the GitHub repository, under Settings → Secrets and variables → Actions, add:
    - the secrets `DISCORD_TEST_BOT_TOKEN` and `DISCORD_TEST_SPEAKER_TOKEN`;
    - the variable `DISCORD_TEST_VOICE_CHANNEL_ID` (right-click the channel → Copy Channel ID, with Developer Mode enabled).

Until those are set, the workflow skips itself.

## Contributing

We are open to contributions.
