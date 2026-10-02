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

## Voice calls with Claude

`/record` joins your voice channel, records it, and lets everyone in it talk to Claude:

1. Each speaker is recorded to their own WAV file in `recordings/<server id>/<date>/`, next to a `transcript.txt` of the call.
2. When someone stops talking, what they said is transcribed on your machine with [whisper.cpp](https://github.com/ggml-org/whisper.cpp).
3. If it mentions the wake word ("Claude" by default), the recent transcript is sent to Claude through the [Claude Code CLI](https://code.claude.com/docs/en/headless), so it uses the Claude subscription you're logged in with instead of an API key.
4. Claude's answer is posted in the text channel and spoken back into the call with [Piper](https://github.com/OHF-Voice/piper1-gpl).

`/stop` finishes the recordings and leaves the channel.

> [!IMPORTANT]
> Only record people who have agreed to it. The bot announces in the channel when it starts recording.

Claude runs with every tool disabled, no MCP servers and from an empty directory, so nothing said in the call can make it read or change anything on your computer. Its answers do count against your subscription's usage limits, and anyone in the server can use `/record`.

### Setup on Windows (WSL2)

The voice library doesn't support native Windows, so run the bot inside WSL2 (these steps use Ubuntu 24.04). Keep the project in the Linux filesystem, e.g. `~/discord-bot`, not under `/mnt/c`.

1. Install PHP 8.5 and the system packages. `ext-ffi`, which voice needs, comes with `php8.5-common` and is enabled for the CLI by default. Then install [Composer](https://getcomposer.org/download/).

    ```bash
    sudo add-apt-repository ppa:ondrej/php
    sudo apt update
    sudo apt install php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip \
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
| `BOT_SLASH_COMMANDS` | | Must be set for `/record` and `/stop` to be registered. |
| `RECORDINGS_PATH` | `recordings` | Where recordings and transcripts are saved. |
| `VOICE_WAKE_WORD` | `claude` | Claude only answers what mentions this word. Leave it empty to answer everything. |
| `WHISPER_BINARY` | `whisper-cli` | Path to whisper.cpp's `whisper-cli`. |
| `WHISPER_MODEL` | | Path to the whisper model, e.g. `~/whisper.cpp/models/ggml-base.bin`. |
| `WHISPER_LANGUAGE` | `auto` | Language spoken in the call, e.g. `en` or `pt`, or `auto` to detect it. |
| `CLAUDE_BINARY` | `claude` | Path to the Claude Code CLI. |
| `CLAUDE_MODEL` | `haiku` | `haiku` answers fastest; `sonnet` or `opus` answer better, but slower. |
| `PIPER_BINARY` | `piper` | Path to Piper. |
| `PIPER_MODEL` | | Path to the Piper voice, e.g. `~/piper/voices/en_US-lessac-medium.onnx`. |

### Known limitations

- Answers take a few seconds: transcription, Claude Code starting up, and speech synthesis each add some.
- Speech recognition sometimes mishears the wake word (e.g. "cloud"). Change `VOICE_WAKE_WORD` if that happens often.
- The voice library (`discord-php-helpers/voice` 8.3.0) keeps every decoded audio frame in memory until `/stop`, roughly 12 MB per speaker per minute of speech. That's fine for normal calls; for very long ones, `/stop` and `/record` again now and then.

### Tests

```bash
composer test
```

## Contributing

We are open to contributions.
