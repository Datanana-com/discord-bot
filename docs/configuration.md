# Configuration

[← Back to the README](../README.md)

| Variable | Default | |
|---|---|---|
| `BOT_SLASH_COMMANDS` | | Must be set for `/record`, `/stop`, `/stats`, `/settings`, `/recall`, `/optout`, `/optin`, `/meet`, `/memory`, `/forget`, `/share`, `/unshare` and `/privacy` to be registered. |
| `RECORDINGS_PATH` | `recordings` | Where recordings, transcripts and summaries are saved. `/recall` answers from them. |
| `RECORDINGS_RETENTION_DAYS` | | Calls older than this many days are deleted, with their recordings, transcript and summary. A whole number, 1 or more. Leave it empty to keep everything. |
| `VOICE_WAKE_WORD` | `claude` | Claude only answers what mentions this word or phrase. A phrase also counts when punctuation is heard between its words: "Okay, computer" mentions `okay computer`. Whisper often writes the wake word differently from how it was said ("Claude" becomes "Cloud" or "Claud"), so it can have several spellings, separated by commas: `claude, cloud, claud`. A sentence that mentions any of them is for the bot, each heard as a whole word, in any case. The first is the wake word's name: it is the one the bot tells people to say when a call starts. Only list what whisper writes for your voice: with `cloud` in the list, the bot also answers when people talk about the cloud. Leave it empty to answer everything. Saying it opens a conversation: see [Voice calls with Claude](voice-calls.md#voice-calls-with-claude). |
| `VOICE_STOP_PHRASE` | `stop <wake word>`, for each spelling | What closes the conversation of whoever says it, e.g. `para claude`. It replaces the default for every server, whatever its wake word, and is heard like the wake word is, so it can also list several spellings, separated by commas. Servers without a wake word have no conversations, so it does nothing there. |
| `VOICE_PAUSE_SECONDS` | `0.6` | How long someone has to be silent for what they said to be over. A number of seconds, 0.1 or more: raise it for people who pause longer in the middle of a sentence. Sounds less than that apart are one thing said, also when it comes to interrupting the bot. |
| `WHISPER_BINARY` | `whisper-cli` | Path to whisper.cpp's `whisper-cli`. |
| `WHISPER_MODEL` | | Path to the whisper model, e.g. `~/whisper.cpp/models/ggml-base.bin`. |
| `WHISPER_LANGUAGE` | `auto` | Language spoken in the call, e.g. `en` or `pt`, or `auto` to detect it. Detecting it takes time, for everything anyone says: on an utterance of 2.2 s, whisper `base` took 1.8 to 3.1 s with `auto` and 1.05 s with `en`. When one language is spoken, set it, here or with `/settings`. |
| `WHISPER_THREADS` | | How many threads whisper uses: a whole number, 1 or more. Empty, or anything else, leaves it to whisper, which takes 4, or as many as the CPU has when that is fewer. More is faster, up to what your CPU has: the same utterance took 1.05 s with 4 threads, 0.71 s with 8, and no less with 10, on a CPU with 10 cores. |
| `WHISPER_PROMPT` | | Text whisper takes as what was said just before, in every call and in voice messages sent in DMs. It helps whisper write the wake word as it is: on seven sentences that began with "Claude", whisper `base` with `en` wrote "Claude" or "Claud" in 1 with no prompt, in 6 with `A voice call with the assistant Claude.`, and in 3 with `Hey Claude.`. A prompt can also make whisper write words that weren't said (the second one made it drop words from other sentences), so try one before keeping it, and still list the spellings it leaves out, such as "Claud". Leave it empty to give whisper no prompt. |
| `CLAUDE_BINARY` | `claude` | Path to the Claude Code CLI. |
| `CLAUDE_MODEL` | `haiku` | `haiku` answers fastest; `sonnet` or `opus` answer better, but slower. |
| `CLAUDE_LOOKUP_MODEL` | `sonnet` | The model that looks things up in the background, with web search as its only tool: see [Looking things up](lookups.md#looking-things-up). |
| `CLAUDE_LOOKUP_ADVISOR` | `opus` | The model the one that looks things up can consult (Claude Code's `--advisor`). Leave it empty for no advisor: lookups are then faster and use less of your subscription. |
| `PIPER_BINARY` | `piper` | Path to Piper. |
| `PIPER_MODEL` | | Path to the Piper voice, e.g. `~/piper/voices/en_US-lessac-medium.onnx`. The other voices in its folder can be chosen with `/settings`. |
| `FFMPEG_BINARY` | `ffmpeg` | Path to ffmpeg, which converts Piper's speech for Discord, and the voice messages sent in DMs for whisper.cpp. The voice library always uses the `ffmpeg` on your `PATH`. |
| `STATS_DATABASE` | `databases/stats.sqlite` | SQLite database for the usage statistics, each server's settings, each person's privacy settings, and who opted out of being recorded. It is created on the first start. |
| `MEMORY_PATH` | `memories` | Where the bot keeps what it remembers about each person, one file per person, and in its `groups` folder what it remembers about each group of people it has calls with. |

`VOICE_WAKE_WORD`, `WHISPER_LANGUAGE`, `PIPER_MODEL` and `CLAUDE_MODEL` are the defaults for every server the bot is in. Each server can change its own with `/settings`.

## Settings for each server

`/settings` changes how the bot behaves in the server it is used in, so that, say, a Portuguese server and an English one can share the same bot. All its options are optional:

| Option | |
|---|---|
| `wake_word` | A word or short phrase: at most 32 letters, numbers, spaces, apostrophes and hyphens, starting and ending with a letter or number. List up to 5 spellings separated by commas, for what whisper writes when it mishears it: `claude, cloud, claud`. The first is the one the bot tells people to say. `none` answers everything: Discord doesn't let an option be empty. |
| `language` | `auto`, or a whisper language code such as `en` or `pt`. With `auto`, whisper detects the language of everything said first, which makes the bot answer a second or two later. |
| `voice` | The name of a Piper voice in the same folder as `PIPER_MODEL`, e.g. `pt_BR-faber-medium` for `pt_BR-faber-medium.onnx`. |
| `model` | `haiku`, `sonnet` or `opus`. It answers in the server's calls, writes their summaries, and answers `/recall`. |
| `reset` | Goes back to the `.env` defaults. Other options given with it are applied after it. |

Without options, `/settings` shows the server's settings and which of them are the defaults. An invalid value is refused with what is allowed instead, e.g. the list of installed voices, and then nothing is changed. Only whoever used `/settings` sees its replies.

Changes apply from the next `/record` or `/meet`: a call in progress keeps the settings it started with.

Only members with the **Manage Server** permission can use `/settings`. Discord doesn't show it to anyone else, and the bot also checks the permission itself, because server admins can change who sees a command (Server Settings → Integrations).

Any `language` other than `en` needs a multilingual whisper model: `ggml-base.bin`, not `ggml-base.en.bin`. A voice has to be downloaded into `PIPER_MODEL`'s folder before a server can choose it, like in step 4 of the [setup](../README.md#setup-on-windows-wsl2).

The settings are kept in the `guild_settings` table of `STATS_DATABASE`, one row per server. A setting that is `NULL` there is the `.env` default. When the settings can't be read, that is logged and calls start with the `.env` defaults. A `STATS_DATABASE` that can't be opened at all stops `/record` instead, as it also holds who [opted out](opting-out.md#opting-out-of-being-recorded).
