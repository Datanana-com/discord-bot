# Logs and statistics

[← Back to the README](../README.md)

Neither the logs nor the statistics contain what anyone said or what Claude answered: that is only in the call's `transcript.txt` and `summary.md`, and for direct messages in the DM itself and in the person's memory.

**Logs** are printed to the console and written to `logs/<date>.log`, a new file each day, one JSON object per line. Each step of a call is logged with the server (`guild`), a `session` ID for the call, the `user` it concerns, and how long it took in milliseconds: the call starting, each new speaker, each utterance, its transcription, the bot starting to speak, Claude's answer, failures, the call ending with its totals, and its summary. A speaker who opted out is logged as `Skipping a speaker who opted out`, with their user ID, when they start speaking in the call and when they opt out during it. What was already being transcribed or answered for them then still logs its steps, with their ID, and nothing they said. A speaker the voice library can't name is logged as `Not recording a speaker the voice client cannot name`, a warning, with their SSRC. Slash commands are logged with who used them, and where, and `/settings changed` with the settings that changed and their new values: settings aren't speech. `/recall answered` is logged with how long the answer took (`ms`), how many calls were sent to Claude (`calls`) and the answer's length (`characters`), never with the question or the answer. A meeting made with `/meet` is logged as `Meeting started` and `Meeting ended`, with the server (`guild`), its `channel`, how many people were `invited` and the `session` of its call, but not the channel's name, which holds people's names. Direct messages are logged as `Answered a DM`, with the `user`, how long the answer took and its length in characters, voice messages in them as `Transcribed a voice message`, with the `user`, the message's length in `seconds`, how long transcribing it took (`ms`) and the length of what was said (`characters`), never what was said, and memory updates as `Updated memory`, with the `user` and the memory's length. Something handed off to be looked up is logged as `Looking something up`, with the `user`, the call's `session` when it is in a call, the `model`, the `advisor` and the task's length (`characters`), and once it is there as `Looked something up`, with how long it took (`ms`) and the answer's length (`characters`), never with the task, the conversation or the answer. One that fails or is given up is logged as the warning `Could not look something up`, with why. A memory updated from a call is logged as `Updated memory` too, with the `session`, how many `people` it belongs to and its length (`characters`), and one that can't be updated as the warning `Could not update the memory`. When old recordings are deleted, `Deleted old recordings` is logged with the number of `calls` deleted and the `days` they are kept for. A call's folder that can't be deleted is logged as a warning, and tried again an hour later. A `RECORDINGS_RETENTION_DAYS` that isn't a whole number of days is logged as a warning too, when the bot starts, and nothing is deleted. To search the log with [jq](https://jqlang.org/):

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

A call also logs `Interrupted` when the person the bot is answering talks over it, with the `user`, the call's `session` and how long the bot had been speaking (`ms`). Three warnings are about the programs a call keeps running: `No Claude Code process was waiting for the question` and `The waiting Claude Code process did not answer`, each with the `user` who asked, when a question had to start its own Claude Code, and `Piper had stopped: starting it again`. A `VOICE_PAUSE_SECONDS` that isn't a number of seconds, 0.1 or more, is logged as a warning when a call starts, and the call then uses 0.6.

**Errors** are logged with the `exception` (its class, message, file and line) and a `trace`: what called what, each with its file and line, and never what it was called with. PHP's own stack traces show the start of every text a function was given, which in a call is what someone said, so none is logged or printed.

- `/<name> failed`: a slash command threw, or the promise it worked with was rejected. With the `guild`, the `channel` and the `user`. `Could not tell that /<name> failed` is the warning for a reply that couldn't be sent either.
- `Error while handling event` and `Event "<method>" failed with the following error`: a class of `app/Events` threw, with the `event`.
- `Error while preparing command classes`: the bot's slash commands couldn't be set up when it started, and it ends.
- `Voice reply failed`: something said in a call couldn't be transcribed or answered, or the answer couldn't be spoken, with the `user` and the `step` that failed: `whisper`, `claude`, `speech`, or `other` for what the bot doesn't expect to fail.
- `Something failed in the call`, and `Leaving the call: the same thing has failed 3 times`: see [Voice calls with Claude](voice-calls.md#when-something-fails). `Could not keep what was being said` is the warning for what someone was saying when a call stopped, and couldn't be transcribed for it.
- `Something failed in the event loop`, and the critical `Too much is failing in the event loop: leaving every call and stopping`: see [Stopping the bot, and starting it again](stopping-and-errors.md).
- `A promise was rejected, and nothing handled that`: the bot goes on.
- `Stopping the bot`, with the `reason` (`received SIGINT`, `received SIGTERM` or `too many errors`) and how many `calls` weren't over, and the warning `Stopping now, without waiting for the calls` for a second Ctrl+C.
- The critical `The bot ends: ...`: an exception nothing caught, or a PHP fatal error, with its `file` and `line`. It is the last line of a bot that didn't stop by itself. A log that ends in the middle of a call without it, and without `Stopping the bot`, is of a bot that was killed (`kill -9`, the machine going down).

**Statistics** are kept in `STATS_DATABASE`, one row per event in the `events` table: `call_started`, `call_ended` (with the call's length), `utterance` (with its length), `answered` (with the time from the end of the question to the answer being posted) and `failed` (something said couldn't be transcribed or answered, or the answer couldn't be spoken), each with the server, channel, user and session. `/stats` shows the server it is used in its totals: calls and minutes recorded, utterances and people speaking, questions answered and how long that took on average, and failures. Summaries, `/recall`, direct messages and what is looked up aren't counted; telling a call what was looked up is an answer, and counts as one, timed from when it was found, so with the time it waited for its turn. Only whoever used `/stats` sees them. To query the statistics yourself:

```bash
sqlite3 databases/stats.sqlite "SELECT guild_id, COUNT(*) AS answers FROM events WHERE type = 'answered' GROUP BY guild_id"
```
