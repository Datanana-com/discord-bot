# Stopping the bot, and when something fails

[← Back to the README](../README.md)

## Stopping it

**Ctrl+C in the bot's terminal, or `kill` of its process** (SIGINT or SIGTERM, which is also what a service manager sends), stops the bot the way `/stop` stops a call:

- It leaves every voice channel at once, and finishes the recordings. The meetings made with `/meet` end too, and their channels are deleted.
- It then goes on until every call is summarized and its memories are updated, however long that takes, closes its connection to Discord and ends, with exit code 0. What is still being [looked up](lookups.md) is dropped. Meanwhile it starts no call: `/record` and `/meet` answer that it is being stopped.
- Ctrl+C a second time ends it without waiting for that. A call that wasn't summarized by then keeps its `transcript.txt`.
- The programs it started that are still running, such as a Claude Code that is looking something up, are ended with it.
- The programs the bot runs (whisper.cpp, Claude Code, Piper, ffmpeg) are started in a session of their own, with `setsid`, where there is one. Ctrl+C goes to everything that runs in the terminal, and would otherwise end the Claude Code that is writing a summary.
- This needs PHP's `pcntl` extension. Without it, the bot says so in a warning when it starts, and Ctrl+C ends it at once, still in its calls, while the programs it had started go on until they are done.

## When something fails

The bot goes on where it can. What a call does when something said can't be transcribed, answered or spoken is in [Voice calls](voice-calls.md#when-something-fails), and what a slash command does in [Development](development.md#slash-commands).

- An exception in a callback of the event loop, the bot's own or a library's, is logged as `Something failed in the event loop`, and the bot goes on. Ten of them within ten seconds are no longer something that failed once: the bot tells the text channel of each call that it had to leave, leaves, and ends with exit code 1.
- A bot that can't set its slash commands up when it starts, as when a command's class can't be made, logs `Error while preparing command classes` and ends with exit code 1.
- An exception nothing caught, and a PHP fatal error such as running out of memory, are logged as `The bot ends: ...`, and the bot ends with exit code 255. It can't leave its calls then: see [Known limitations](voice-calls.md#known-limitations).

## Starting it again

**Nothing starts the bot again** once it has ended: `composer serve` runs it once. To have it started again after it ended over an error, but not after you stopped it, run it under something that looks at its exit code. A bot that fails the same way every time it starts is then started over and over, every few seconds. In a terminal, with `php` itself, as Composer ends with an error of its own when it is interrupted with Ctrl+C:

```bash
until php index.php; do sleep 5; done
```

Or as a systemd user service, in `~/.config/systemd/user/discord-bot.service` (`systemctl --user enable --now discord-bot`), which also stops it with SIGTERM, and waits for the summaries. `KillMode=mixed` is what has it send that signal to the bot alone: without it, the programs the bot runs get it too, and a summary that was being written is lost.

```ini
[Unit]
Description=Discord bot

[Service]
WorkingDirectory=%h/discord-bot
ExecStart=/usr/bin/php index.php
Restart=on-failure
RestartSec=5
# Only the bot is told to stop, and it ends by itself once its calls are summarized.
KillMode=mixed
TimeoutStopSec=infinity

[Install]
WantedBy=default.target
```
