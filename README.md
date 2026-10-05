Discord PHP Framework
====

This project was made to make it easier to start a bot, without having the clogged index file with the `->on` function & other things.

Requires PHP 8.5. It also includes a voice bot that records calls and lets people talk to Claude: see [Voice calls with Claude](#voice-calls-with-claude). In direct messages, Claude answers in text and remembers who it's talking to: see [Direct messages and memory](#direct-messages-and-memory).

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

A command can take options and be shown only to members with a permission: set its `$options`, each as Discord's [option object](https://docs.discord.com/developers/interactions/application-commands#application-command-object-application-command-option-structure), and its `$defaultMemberPermissions`, like `app/Commands/Global/SettingsCommand.php` does. Server admins can change who sees a command, so a command that needs a permission also checks it in `handle()`. A command is saved to Discord again when its description, options or permissions change.

## Voice calls with Claude

`/record` joins your voice channel, records it, and lets everyone in it talk to Claude:

1. Each speaker is recorded to their own WAV file in `recordings/<server id>/<date>/`, next to a `transcript.txt` of the call.
2. When someone stops talking, what they said is transcribed on your machine with [whisper.cpp](https://github.com/ggml-org/whisper.cpp).
3. If it mentions the wake word ("Claude" by default), the recent transcript is sent to Claude through the [Claude Code CLI](https://code.claude.com/docs/en/headless), so it uses the Claude subscription you're logged in with instead of an API key.
4. Claude's answer is spoken back into the call with [Piper](https://github.com/OHF-Voice/piper1-gpl), sentence by sentence while Claude is still writing it, and posted in the text channel once it is whole, in several messages when it doesn't fit in one. Each spoken sentence is saved as a `claude-<n>.ogg` file next to the recordings.
5. When the call ends, Claude summarizes its whole transcript: what was discussed, what was decided, and who does what next. The summary is posted in the text channel where `/record` was used, and saved as `summary.md` next to `transcript.txt`.

`/stop` finishes the recordings and leaves the channel, and `/stats` shows how the server has used the bot (see [Logs and statistics](#logs-and-statistics)). `/settings` gives a server its own wake word, language, voice and Claude model (see [Settings for each server](#settings-for-each-server)). `/recall` asks Claude a question about the server's saved calls (see [Asking about past calls](#asking-about-past-calls)). `/optout` stops the bot from recording, transcribing or answering whoever uses it, and `/optin` undoes that (see [Opting out of being recorded](#opting-out-of-being-recorded)).

A call ends with `/stop`, or when someone disconnects the bot from the voice channel. An answer the bot is speaking at that moment is cut off, but still posted in the text channel. For the summary, the whole transcript is sent to Claude, including what was said without the wake word. The summary is written in the language of the call, once everything said is transcribed, so it includes the last thing said. A summary that doesn't fit in one Discord message is split into several, never in the middle of a sentence. When nobody said anything, there is no summary. When Claude can't make one (it isn't logged in, the usage limit is reached, ...), the bot says so in the text channel, and why.

> [!IMPORTANT]
> Only record people who have agreed to it. The bot announces in the channel when it starts recording, and that anyone who doesn't want to be recorded can use `/optout`.

Recordings are kept until you delete them, unless `RECORDINGS_RETENTION_DAYS` is set. The bot then deletes each call's folder, with its recordings, transcript and summary, once the call is older than that many days. It goes by the date in the folder's name, and checks when it starts and every hour after that. The folder of a call that isn't over, including one that stopped and is still being summarized, is never deleted, and a server's folder is removed once it is empty. Everything else in `RECORDINGS_PATH` is left alone, and so are the statistics and the logs, which hold nothing anyone said.

Claude runs with every tool disabled, no MCP servers and from an empty directory, so nothing said in the call can make it read or change anything on your computer. Its answers and summaries do count against your subscription's usage limits, and anyone in the server can use `/record` and `/recall`.

### Opting out of being recorded

Anyone can use `/optout`, in any server the bot is in or in a direct message with it. From then on, in every server:

- no recording of them is kept;
- what they say isn't transcribed, so it is not in `transcript.txt` or the summary, and is never sent to Claude;
- Claude doesn't answer them, also when they say the wake word;
- no `utterance` statistics are saved for them.

`/optin` undoes it. Both commands answer with what changed, and only whoever used them sees that.

Opting out during a call takes effect right away, also while a call that just ended is still being transcribed and summarized. What they say from then on is dropped, their recording files of that call are deleted, and the rest of an answer Claude was giving them is neither spoken nor posted. What was already transcribed stays in the transcript, so it is in the summary too. Someone who opts back in during a call is transcribed and answered again right away, but only recorded again once they rejoin the call.

The opt-outs are kept in the `opt_outs` table of `STATS_DATABASE`: the user ID and when they opted out. They are read when a call starts. When they can't be read, `/record` refuses to start, as it can't tell who must not be recorded.

Discord sends the bot everyone's audio, so the voice library still receives and decodes that of people who opted out, and keeps it in memory until the call ends, like everyone's (see [Known limitations](#known-limitations)). The bot keeps none of it. The library also writes each speaker's audio to a file in the system's temp folder, which the bot deletes when the call ends (see [Known limitations](#known-limitations)).

The library names a speaker by their SSRC, a number for their audio stream, when it doesn't know who they are. That can happen when someone leaves the call and comes back. The bot then doesn't record or transcribe that speaker, as they may have opted out, and logs a warning.

### Direct messages and memory

Send the bot a direct message, and Claude answers it there, in text, as your personal assistant. The bot shows it's typing while Claude works.

- Each answer is made from what the bot remembers about you and from the DM's last 20 messages.
- Your messages are answered one at a time, in the order you sent them.
- An answer that doesn't fit in one Discord message is split into several, never in the middle of a sentence.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the bot replies with the reason.
- Only text is read: a message with nothing but a picture or another attachment gets no answer. Messages in servers and messages from other bots are never answered.

**The memory** is a markdown note that Claude writes about each person, at `MEMORY_PATH/<user id>.md`. It holds what helps Claude help that person later: their projects, plans, decisions, preferences, open questions and the people they mention. Claude is told to leave out passwords, tokens and other secrets. The note stays under 4,000 characters, so it fits in every prompt: when it's full, Claude keeps what's most useful.

The memory is updated when a conversation pauses. Ten minutes after your last message, one Claude request gets the current memory and what was said since its last update, and returns the new memory. When that fails (the usage limit is reached, ...), the next update gets what was said, too. If the bot stops before then, that update is lost.

- `/memory` shows you what the bot remembers about you.
- `/forget` deletes it, together with what you said since its last update. The messages themselves stay in the DM, where the last 20 are still sent to Claude with your next message.

Both commands work in DMs and in servers, and only you see their replies.

> [!IMPORTANT]
> Memories are kept on the bot's machine, where anyone with access to the machine can read them. The files themselves can only be read by the user the bot runs as (mode 0600).

Claude runs with the same restrictions as in calls: no tools, no MCP servers and an empty directory. Every DM answer and every memory update uses your Claude subscription, and anyone who shares a server with the bot can send it direct messages.

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
| `BOT_SLASH_COMMANDS` | | Must be set for `/record`, `/stop`, `/stats`, `/settings`, `/recall`, `/optout`, `/optin`, `/memory` and `/forget` to be registered. |
| `RECORDINGS_PATH` | `recordings` | Where recordings, transcripts and summaries are saved. `/recall` answers from them. |
| `RECORDINGS_RETENTION_DAYS` | | Calls older than this many days are deleted, with their recordings, transcript and summary. A whole number, 1 or more. Leave it empty to keep everything. |
| `VOICE_WAKE_WORD` | `claude` | Claude only answers what mentions this word or phrase. A phrase also counts when punctuation is heard between its words: "Okay, computer" mentions `okay computer`. Leave it empty to answer everything. |
| `WHISPER_BINARY` | `whisper-cli` | Path to whisper.cpp's `whisper-cli`. |
| `WHISPER_MODEL` | | Path to the whisper model, e.g. `~/whisper.cpp/models/ggml-base.bin`. |
| `WHISPER_LANGUAGE` | `auto` | Language spoken in the call, e.g. `en` or `pt`, or `auto` to detect it. |
| `CLAUDE_BINARY` | `claude` | Path to the Claude Code CLI. |
| `CLAUDE_MODEL` | `haiku` | `haiku` answers fastest; `sonnet` or `opus` answer better, but slower. |
| `PIPER_BINARY` | `piper` | Path to Piper. |
| `PIPER_MODEL` | | Path to the Piper voice, e.g. `~/piper/voices/en_US-lessac-medium.onnx`. The other voices in its folder can be chosen with `/settings`. |
| `FFMPEG_BINARY` | `ffmpeg` | Path to ffmpeg, which converts Piper's speech for Discord. The voice library always uses the `ffmpeg` on your `PATH`. |
| `STATS_DATABASE` | `databases/stats.sqlite` | SQLite database for the usage statistics, each server's settings, and who opted out of being recorded. It is created on the first start. |
| `MEMORY_PATH` | `memories` | Where the bot keeps what it remembers about each person, one file per person. |

`VOICE_WAKE_WORD`, `WHISPER_LANGUAGE`, `PIPER_MODEL` and `CLAUDE_MODEL` are the defaults for every server the bot is in. Each server can change its own with `/settings`.

### Settings for each server

`/settings` changes how the bot behaves in the server it is used in, so that, say, a Portuguese server and an English one can share the same bot. All its options are optional:

| Option | |
|---|---|
| `wake_word` | A word or short phrase: at most 32 letters, numbers, spaces, apostrophes and hyphens, starting and ending with a letter or number. `none` answers everything: Discord doesn't let an option be empty. |
| `language` | `auto`, or a whisper language code such as `en` or `pt`. |
| `voice` | The name of a Piper voice in the same folder as `PIPER_MODEL`, e.g. `pt_BR-faber-medium` for `pt_BR-faber-medium.onnx`. |
| `model` | `haiku`, `sonnet` or `opus`. It answers in the server's calls, writes their summaries, and answers `/recall`. |
| `reset` | Goes back to the `.env` defaults. Other options given with it are applied after it. |

Without options, `/settings` shows the server's settings and which of them are the defaults. An invalid value is refused with what is allowed instead, e.g. the list of installed voices, and then nothing is changed. Only whoever used `/settings` sees its replies.

Changes apply from the next `/record`: a call in progress keeps the settings it started with.

Only members with the **Manage Server** permission can use `/settings`. Discord doesn't show it to anyone else, and the bot also checks the permission itself, because server admins can change who sees a command (Server Settings → Integrations).

Any `language` other than `en` needs a multilingual whisper model: `ggml-base.bin`, not `ggml-base.en.bin`. A voice has to be downloaded into `PIPER_MODEL`'s folder before a server can choose it, like in step 4 of the setup.

The settings are kept in the `guild_settings` table of `STATS_DATABASE`, one row per server. A setting that is `NULL` there is the `.env` default. When the database can't be read, that is logged and calls start with the `.env` defaults.

### Asking about past calls

`/recall question:<text>` asks Claude a question about the calls saved in the server it is used in, e.g. `/recall question: what did we decide about the launch date?`. Claude answers from the calls' transcripts and summaries, and says which call the answer comes from, by its date. When the calls don't say, it says that instead of guessing.

- Only the server's own calls are used: every `RECORDINGS_PATH/<server id>/*/transcript.txt`, with the call's `summary.md` when it has one. Another server's calls are never sent to Claude.
- Only whoever asked sees the answer: the calls may hold things not everyone in the channel heard.
- The newest calls are sent first, up to about 150,000 characters, so that Claude answers quickly. When older calls don't fit, the answer ends by saying so. A call is never skipped to fit an older, shorter one in. When the newest call alone is too long, Claude gets its summary and the end of its transcript, and the answer says that instead.
- It doesn't need a call in progress. When there is one, what was said in it so far is used too. With no saved calls, the reply is "Nothing has been recorded in this server yet."
- Claude runs like in calls: with every tool disabled, no MCP servers and from an empty directory, and with the server's `model` from `/settings`. It is told that the calls are what it answers from, never instructions.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the reply says so, and why.

Anyone who can use slash commands can use `/recall`, and so ask about any call saved in the server, including the ones they weren't in. Server admins can restrict it to some roles, members or channels under Server Settings → Integrations.

### Logs and statistics

Neither the logs nor the statistics contain what anyone said or what Claude answered: that is only in the call's `transcript.txt` and `summary.md`, and for direct messages in the DM itself and in the person's memory.

**Logs** are printed to the console and written to `logs/<date>.log`, one JSON object per line. Each step of a call is logged with the server (`guild`), a `session` ID for the call, the `user` it concerns, and how long it took in milliseconds: the call starting, each new speaker, each utterance, its transcription, the bot starting to speak, Claude's answer, failures, the call ending with its totals, and its summary. A speaker who opted out is logged as `Skipping a speaker who opted out`, with their user ID, when they start speaking in the call and when they opt out during it. What was already being transcribed or answered for them then still logs its steps, with their ID, and nothing they said. A speaker the voice library can't name is logged as `Not recording a speaker the voice client cannot name`, a warning, with their SSRC. Slash commands are logged with who used them, and where, and `/settings changed` with the settings that changed and their new values: settings aren't speech. `/recall answered` is logged with how long the answer took (`ms`), how many calls were sent to Claude (`calls`) and the answer's length (`characters`), never with the question or the answer. Direct messages are logged as `Answered a DM`, with the `user`, how long the answer took and its length in characters, and memory updates as `Updated memory`, with the `user` and the memory's length. When old recordings are deleted, `Deleted old recordings` is logged with the number of `calls` deleted and the `days` they are kept for. A call's folder that can't be deleted is logged as a warning, and tried again an hour later. A `RECORDINGS_RETENTION_DAYS` that isn't a whole number of days is logged as a warning too, when the bot starts, and nothing is deleted. To search the log with [jq](https://jqlang.org/):

```bash
# Everything that happened in one call
jq -c 'select(.context.session == "1a2b3c4d") | [.datetime, .message, .context]' logs/2026-10-03.log
# How long Claude took to write its answer, in milliseconds
jq 'select(.message == "Claude answered") | .context.ms' logs/*.log
# How long the bot took to start speaking, from the end of the question
jq 'select(.message == "Started speaking") | .context.ms' logs/*.log
# Warnings and errors only
jq -c 'select(.level >= 300) | [.datetime, .message, .context]' logs/*.log
```

**Statistics** are kept in `STATS_DATABASE`, one row per event in the `events` table: `call_started`, `call_ended` (with the call's length), `utterance` (with its length), `answered` (with the time from the end of the question to the answer being posted) and `failed` (something said couldn't be transcribed or answered, or the answer couldn't be spoken), each with the server, channel, user and session. `/stats` shows the server it is used in its totals: calls and minutes recorded, utterances and people speaking, questions answered and how long that took on average, and failures. Summaries, `/recall` and direct messages aren't counted. Only whoever used `/stats` sees them. To query the statistics yourself:

```bash
sqlite3 databases/stats.sqlite "SELECT guild_id, COUNT(*) AS answers FROM events WHERE type = 'answered' GROUP BY guild_id"
```

### Known limitations

- The bot starts speaking a few seconds after a question: transcription, Claude Code starting up, and the synthesis of the first sentence each add some. The rest of the answer no longer adds to the wait, but there is a pause of about half a second between sentences.
- The bot doesn't stop speaking when someone talks over it.
- Speech recognition sometimes mishears the wake word (e.g. "cloud"). Change it, with `VOICE_WAKE_WORD` or `/settings`, if that happens often.
- The wake word is looked for as whole words. In languages written without spaces between words, such as Japanese or Thai, it is only heard when whisper writes a space or punctuation around it.
- The voice library (`discord-php-helpers/voice` 8.3.0) keeps every decoded audio frame in memory until `/stop`, roughly 12 MB per speaker per minute of speech. That's fine for normal calls; for very long ones, `/stop` and `/record` again now and then.
- The voice library also writes a copy of each speaker's audio to the system's temp folder, as `<date>_<time>-<SSRC>.ogg`. The bot doesn't use these files. It deletes them when the call ends, and when it starts, those that a call it didn't get to end left behind, as when it crashed. It takes every file named like that in the temp folder to be one of them.

### Tests

```bash
composer test
composer test -- --testsuite Unit      # or Feature
```

Unit tests cover each class on its own. Feature tests run the whole voice flow, the direct messages with their memory, and the slash commands against a fake Discord, with whisper.cpp, Claude Code and Piper replaced by the scripts in `tests/Fixtures`, so they need no models, Claude login or Discord connection.

`tests/Feature/VoiceCallTest.php` goes further: the call's audio travels over a local UDP socket standing in for Discord's media server, encrypted and Opus-encoded like in a real call, and the spoken answer is encoded by ffmpeg. It needs ffmpeg and libopus, like the bot itself, and is skipped without them.

GitHub Actions runs these tests with coverage on pull requests and pushes to `master` that change PHP code, the tests, the dependencies or `phpunit.xml` (`.github/workflows/tests.yml`), with ffmpeg and libopus installed so `VoiceCallTest` runs too. Draft pull requests aren't tested until they're marked ready for review.

To see the code coverage, install a coverage driver (`sudo apt install php8.5-pcov`, or Xdebug) and run:

```bash
composer test:coverage
```

### Live voice test

`tests/Live` asks the bot a question in a real Discord voice call. A second bot plays the spoken question "Hey Claude, what time is it?" and records the answer.

Everything except Claude is real: Discord with its end-to-end encryption, whisper.cpp and Piper. Claude is replaced by a fixed answer of two sentences, written one after the other like Claude Code does, so no Claude subscription is used. It runs in GitHub Actions (`.github/workflows/live-voice.yml`) every night, on demand, and on pull requests that change the bot once they're no longer drafts. Every run keeps its recordings as an artifact you can download and listen to.

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
