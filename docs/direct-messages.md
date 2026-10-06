# Direct messages and memory

[← Back to the README](../README.md)

Send the bot a direct message, and Claude answers it there, in text, as your personal assistant. The bot shows it's typing while Claude works.

- Each answer is made from what the bot remembers about you and from the DM's last 20 messages.
- Your messages are answered one at a time, in the order you sent them.
- An answer that doesn't fit in one Discord message is split into several, never in the middle of a sentence.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the bot replies with the reason.
- Only text and [voice messages](#voice-messages-in-direct-messages) are read: a message with nothing but a picture or another attachment gets no answer. Messages in servers and messages from other bots are never answered.
- What Claude can't answer well at once, it has looked up in the background, like in calls: see [Looking things up in direct messages](#looking-things-up-in-direct-messages).

## Looking things up in direct messages

Claude answers a DM at once too, without tools. A question that needs current or checked information, or more careful work than a quick reply allows, is handed off and looked up as in calls (see [Looking things up](lookups.md#looking-things-up)): by the same model, with the same advisor, with web search as its only tool, and with the same limits of one task at a time, 3 more waiting, and 5 minutes for each.

- Claude's reply is one short sentence, such as "Let me look into that.". The bot shows it's typing while it looks something up, and the chat goes on meanwhile: your messages are answered as usual.
- The model that looks it up gets the DM's last 100 messages and the task. It isn't given your memory, though those messages and the task can hold what Claude said from it.
- The answer is sent in the DM, whole, in several messages when it doesn't fit in one. Nothing else is said about it: in a DM the answer is the text.
- From then on it is part of the DM: later answers see it among the DM's last 20 messages, and your memory is updated from it like from any answer, as `Looked up for <name>: ...`, once the conversation has paused for ten minutes after it arrived. What it found comes from the web, so in what Claude gets of the DM, the later lines of a message of the bot's are indented: none of them can pass for a message of yours.
- A [voice message](#voice-messages-in-direct-messages) that asks for something to be looked up works like a written one.
- When something can't be looked up, the bot sends that it couldn't, and why.
- When a fourth task would wait, the bot answers "I'm still looking into other things. Ask me again in a moment." and nothing is handed off.

## Voice messages in direct messages

Typing isn't always convenient: record a Discord voice message in the DM instead, and the bot answers it in text. The bot recognizes it by Discord's voice message flag, downloads its Ogg Opus audio, converts it to WAV with ffmpeg (`FFMPEG_BINARY`) and transcribes it with whisper.cpp on your machine, with the `WHISPER_*` settings of `.env`.

From then on it is a message like one you typed: the same prompt, memory, order and error handling, and what you said counts in the memory update.

- The answer starts with a quote of what the bot heard, `> 🎤 <what you said>`, so you can tell when whisper misheard. Discord shows voice messages without text, so this quote is also what keeps your words in the DM's last 20 messages that Claude gets with your next message.
- When nothing could be heard, the bot says so and doesn't ask Claude.
- A voice message longer than 5 minutes is refused with a short explanation, as transcribing it would take too long. That is checked against the length Discord reports and against the audio itself, as the former comes from the sender's app.
- whisper.cpp is given 3 seconds for each second of the message, and at least 2 minutes, before it is stopped: a message of 5 minutes can take up to 15, for a big model or a slow CPU. If it still takes longer, the bot says it couldn't transcribe the message and logs that whisper timed out. (The short utterances of a call get the 2 minutes.)
- The audio is only downloaded from Discord's own attachment hosts (`cdn.discordapp.com` and `media.discordapp.net`, over https), with a timeout and a size limit, without following redirects and without blocking the bot. Anything else is refused.
- The downloaded and converted files are kept in the `discord-bot-voice-messages` folder of the system's temp folder, and deleted once the message is transcribed, whether that worked or not.
- When a voice message can't be transcribed, the bot says so, and logs why. The logs never include what was said: a voice message is logged as `Transcribed a voice message`, with the `user`, its length in `seconds`, how long it took in `ms` and the `characters` it came to.
- Voice messages in servers are not read, and the bot doesn't answer with voice messages.

## Personal memory

**The memory** is a markdown note that Claude writes about each person, at `MEMORY_PATH/<user id>.md`. It holds what helps Claude help that person later: their projects, plans, decisions, preferences, open questions and the people they mention. Claude is told to leave out passwords, tokens and other secrets. The note stays under 4,000 characters, so it fits in every prompt: when it's full, Claude keeps what's most useful.

The memory is updated when a conversation pauses. Ten minutes after your last message, or after the last thing that was looked up for you arrived, one Claude request gets the current memory and what was said since its last update, and returns the new memory. When that fails (the usage limit is reached, ...), the next update gets what was said, too. If the bot stops before then, that update is lost. When a call is updating the same memory at that moment, the update waits for it: see [Memory in calls](memory.md#memory-in-calls).

- `/memory` shows you what the bot remembers about you, and lists the groups you have a memory with (see [Memory in calls](memory.md#memory-in-calls)). `/share` lets the bot use it in a call for everyone there, until the call ends (see [Sharing your personal memory with a call](memory.md#sharing-your-personal-memory-with-a-call)).
- `/forget` deletes it, together with what you said since its last update. The messages themselves stay in the DM, where the last 20 are still sent to Claude with your next message.

- `/privacy` shows your privacy settings, and `/privacy personal_memory_in_calls:only after /share` keeps your personal memory out of calls with other people, unless you share it (see [Keeping your personal memory out of calls with others](memory.md#keeping-your-personal-memory-out-of-calls-with-others)).

`/memory`, `/forget` and `/privacy` work in DMs and in servers, and only you see their replies.

> [!IMPORTANT]
> Memories are kept on the bot's machine, where anyone with access to the machine can read them. The files themselves can only be read by the user the bot runs as (mode 0600).

Claude runs with the same restrictions as in calls: no tools, no MCP servers and an empty directory, except for the model that looks things up, which can search the web and do nothing else. Every DM answer, every lookup and every memory update uses your Claude subscription, and anyone who shares a server with the bot can send it direct messages.
