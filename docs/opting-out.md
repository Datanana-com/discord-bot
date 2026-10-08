# Opting out of being recorded

[← Back to the README](../README.md)

Anyone can use `/optout`, in any server the bot is in or in a direct message with it. From then on, in every server:

- no recording of them is kept;
- what they say isn't transcribed, so it is not in `transcript.txt` or the summary, and is never sent to Claude;
- Claude doesn't answer them, also when they say the wake word, and a conversation they had open is closed;
- no `utterance` statistics are saved for them.

`/optin` undoes it. Both commands answer with what changed, and only whoever used them sees that.

Opting out during a call takes effect right away, also while a call that just ended is still being transcribed and summarized. What they say from then on is dropped, their recording files of that call are deleted, and the rest of an answer Claude was giving them is neither spoken nor posted. What was already transcribed stays in the transcript, so it is in the summary too. Someone who opts back in during a call is transcribed and answered again right away, but only recorded again once they rejoin the call.

The opt-outs are kept in the `opt_outs` table of `STATS_DATABASE`: the user ID and when they opted out. They are read when a call starts. When they can't be read, `/record` refuses to start, as it can't tell who must not be recorded.

Discord sends the bot everyone's audio, so the voice library still receives and decodes that of people who opted out, and keeps it in memory until the call ends, like everyone's (see [Known limitations](voice-calls.md#known-limitations)). The bot keeps none of it. The library also writes each speaker's audio to a file in the system's temp folder, which the bot deletes when the call ends (see [Known limitations](voice-calls.md#known-limitations)).

The library names a speaker by their SSRC, a number for their audio stream, when it doesn't know who they are. That can happen when someone leaves the call and comes back. The bot then doesn't record or transcribe that speaker, as they may have opted out, and logs a warning.
