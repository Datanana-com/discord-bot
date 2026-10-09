# Opting out of being recorded

[← Back to the README](../README.md)

Anyone can use `/optout`, in any server the bot is in or in a direct message with it. From then on, in every server:

- no recording of them is kept;
- what they say isn't transcribed, so it is not in `transcript.txt` or the summary, and is never sent to Claude;
- Claude doesn't answer them, also when they say the wake word;
- no `utterance` statistics are saved for them.

`/optin` undoes it. Both commands answer with what changed, and only whoever used them sees that.

Opting out during a call takes effect right away, also while a call that just ended is still being transcribed and summarized. What they say from then on is dropped, their recording files of that call are deleted, and the rest of an answer Claude was giving them is neither spoken nor posted. A sentence of theirs that still waited for whisper is deleted, and a question of theirs that waited for its turn is no longer answered. A question that was asked of Claude while they paused (see [Interrupting the bot, and pauses](voice-calls.md#interrupting-the-bot-and-pauses)) is ended, and nothing of its answer is used. That question had been sent up to 0.3 seconds before the sentence was over, so what they said in the last 0.3 seconds before opting out may have reached Claude already, which a sentence that names the bot would not have before. What was already transcribed stays in the transcript, so it is in the summary too, and Claude is given it with the rest of the call, for as long as the call goes on, whenever someone else asks it something or something is looked up. Someone who opts back in during a call is transcribed and answered again right away, but only recorded again once they rejoin the call.

The opt-outs are kept in the `opt_outs` table of `STATS_DATABASE`: the user ID and when they opted out. They are read when a call starts. When they can't be read, `/record` refuses to start, as it can't tell who must not be recorded.

Discord sends the bot everyone's audio, so the voice library still receives and decodes that of people who opted out, and keeps it in memory until the call ends, like everyone's (see [Known limitations](voice-calls.md#known-limitations)). The bot keeps none of it. The library also writes each speaker's audio to a file in the system's temp folder, which the bot deletes when the call ends (see [Known limitations](voice-calls.md#known-limitations)).

The library names a speaker by their SSRC, a number for their audio stream, when it doesn't know who they are. That can happen when someone leaves the call and comes back. The bot then doesn't record or transcribe that speaker, as they may have opted out, and logs a warning.
