Discord PHP Framework
====

This project was made to make it easier to start a bot, without having the clogged index file with the `->on` function & other things.

Requires PHP 8.5. It also includes a voice bot that records calls and lets people talk to Claude: see [Voice calls with Claude](#voice-calls-with-claude). In direct messages, Claude answers in text and remembers who it's talking to: see [Direct messages and memory](#direct-messages-and-memory). It remembers calls too: see [Memory in calls](#memory-in-calls).

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
6. Once the summary is posted, the bot updates its memories of the people in the call, from the transcript: see [Memory in calls](#memory-in-calls).

Saying the wake word opens a conversation for whoever said it, so there is no need to say "Claude" in every sentence: until they say the stop phrase, everything that person says is answered. A conversation belongs to one person. What others in the call say is only answered when it mentions the wake word, which opens a conversation of their own, and several people can have one open at the same time. Their sentences are still answered one at a time, in the order they ended.

- The stop phrase is `stop <wake word>`: "stop Claude" by default, or "stop computer" in a server whose wake word is "computer". A wake word with several spellings (`claude, cloud, claud`) has a stop phrase for each of them: "stop cloud" counts too. `VOICE_STOP_PHRASE` replaces it, for every server (see [Configuration](#configuration)). It is heard the way the wake word is, so "Stop, Claude." counts, and it is checked first, as by default it contains the wake word.
- A sentence with the stop phrase closes the conversation of whoever said it, and only theirs. Like everything said, it is in the transcript, but it is never sent to Claude, also when they had no conversation open. When it closed one, the bot says "Okay." in the call, with the call's Piper voice. That isn't posted in the text channel, added to the transcript or counted as an answer.
- A conversation also closes after 60 seconds of quiet, counted from the end of that person's last sentence or from when the bot finished speaking its answer to them, whichever is later. It never closes while something they said is waiting for its turn or being answered. It closes too when they use `/optout`, and every conversation ends with the call.
- A server without a wake word (`VOICE_WAKE_WORD` empty, or `/settings wake_word:none`) answers everything already: it has no conversations, and a sentence with the stop phrase is answered like any other.
- Conversations are logged as `Conversation opened` and `Conversation closed`, with the `user`, the call's `session` and, when closed, the `reason`: `stop phrase`, `quiet` or `opted out`. The end of the call closes them without a log. An "Okay." that can't be spoken is logged as the warning `Could not say okay`, and the conversation is closed all the same. Like every log, none holds what was said.
- Saying "Stop, Claude." over an answer the bot is speaking to you stops it speaking, like anything you say over it: see below. Like any sentence, the stop phrase then waits for its turn, so your conversation closes, with its "Okay.", once Claude has finished writing the answer you interrupted. Said over an answer to someone else, it interrupts nothing, and waits until that answer is spoken.

`/stop` finishes the recordings and leaves the channel, and `/stats` shows how the server has used the bot (see [Logs and statistics](#logs-and-statistics)). `/settings` gives a server its own wake word, language, voice and Claude model (see [Settings for each server](#settings-for-each-server)). `/recall` asks Claude a question about the server's saved calls (see [Asking about past calls](#asking-about-past-calls)). `/optout` stops the bot from recording, transcribing or answering whoever uses it, and `/optin` undoes that (see [Opting out of being recorded](#opting-out-of-being-recorded)). `/meet` makes a private voice channel for the people you pick, and records it (see [Private meetings](#private-meetings)).

You can interrupt the bot: it stops speaking when the person it is answering starts talking. Half a second of their voice does it, which is also what it takes for something said to be transcribed, so a cough doesn't. The sentence being spoken is cut off, and the rest of the answer is neither synthesized nor spoken, but the whole answer is still posted in the text channel and added to the transcript. What they said over it is handled like anything else they say, once Claude has finished writing the answer they interrupted: it is transcribed, and answered when they have a conversation open or it mentions the wake word. Only the person being answered can interrupt: other people talking in the call don't stop the bot.

What someone says is over once they have been silent for 0.6 seconds, which the bot checks for every 0.05 seconds. Set `VOICE_PAUSE_SECONDS` to more for people who pause longer in the middle of a sentence: the bot then waits that much longer before it answers. Sounds less than that pause apart are one thing said, so they also add up towards the half second that interrupts the bot.

A call ends with `/stop`, or when someone disconnects the bot from the voice channel. An answer the bot is speaking at that moment is cut off, but still posted in the text channel. For the summary, the whole transcript is sent to Claude, including what was said without the wake word. The summary is written in the language of the call, once everything said is transcribed, so it includes the last thing said. A summary that doesn't fit in one Discord message is split into several, never in the middle of a sentence. When nobody said anything, there is no summary. When Claude can't make one (it isn't logged in, the usage limit is reached, ...), the bot says so in the text channel, and why.

> [!IMPORTANT]
> Only record people who have agreed to it. The bot announces in the channel when it starts recording, that it remembers each group's calls (and that `/memory` and `/forget` show and delete those memories), and that anyone who doesn't want to be recorded can use `/optout`. Memories of a group are kept for exactly the people who were in the call: see [Memory in calls](#memory-in-calls).

Recordings are kept until you delete them, unless `RECORDINGS_RETENTION_DAYS` is set. The bot then deletes each call's folder, with its recordings, transcript and summary, once the call is older than that many days. It goes by the date in the folder's name, and checks when it starts and every hour after that. The folder of a call that isn't over, including one that stopped and is still being summarized, is never deleted, and a server's folder is removed once it is empty. Everything else in `RECORDINGS_PATH` is left alone, and so are the statistics and the logs, which hold nothing anyone said.

Claude runs with every tool disabled, no MCP servers and from an empty directory, so nothing said in the call can make it read or change anything on your computer. Its answers and summaries do count against your subscription's usage limits, and anyone in the server can use `/record`, `/meet` and `/recall`.

Claude Code never loads the settings of the user the bot runs as (`--setting-sources ""`), in calls, summaries, `/recall`, direct messages and memory updates: their plugins, skills and hooks would be loaded for every question, which takes seconds, and a plugin can change how Claude answers. The subscription login still works. Answers in a call are written without thinking first (`MAX_THINKING_TOKENS=0`), as thinking takes seconds before the first word of a one-line answer. Everything else thinks as much as Claude Code does by default, or as `MAX_THINKING_TOKENS` says when it is set in the environment the bot is started from, such as its shell or service unit: Claude Code doesn't get what is in `.env`. Every Claude Code process the bot starts also gets `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1` and `DISABLE_AUTOUPDATER=1`, whatever that environment says, which makes it ready sooner. It then doesn't update itself: run `claude` yourself now and then to update it.

During a call, a Claude Code process is already running and waiting for the next question, so a question doesn't wait for Claude Code to start. It answers that one question and ends, and another one is started for the next: every question still gets its own process and its own prompt, so nothing is carried over from one question to the next but what the transcript holds. Claude Code ends by itself when it waits for some minutes, and is then replaced. When no process is waiting, or the one that was ends without writing anything, the question is asked the way it was before, by a process started for it, and a warning is logged. The waiting process is ended when the call ends, and with the bot.

Piper keeps running for the whole call as well, so its voice is loaded once, when the call starts, and not for every sentence. It writes each sentence into a `piper` folder next to the recordings, from where the sentence is converted into its `claude-<n>.ogg`; the folder is removed when Piper ends. When Piper stops by itself, the sentence it was working on isn't spoken, and neither is the rest of that answer, which is logged like any answer that can't be spoken. Piper is started again for the next sentence there is to speak, in the next answer, with a warning. It is ended when the call ends, once it has spoken the sentence it may be working on, and with the bot.

### Opting out of being recorded

Anyone can use `/optout`, in any server the bot is in or in a direct message with it. From then on, in every server:

- no recording of them is kept;
- what they say isn't transcribed, so it is not in `transcript.txt` or the summary, and is never sent to Claude;
- Claude doesn't answer them, also when they say the wake word, and a conversation they had open is closed;
- no `utterance` statistics are saved for them.

`/optin` undoes it. Both commands answer with what changed, and only whoever used them sees that.

Opting out during a call takes effect right away, also while a call that just ended is still being transcribed and summarized. What they say from then on is dropped, their recording files of that call are deleted, and the rest of an answer Claude was giving them is neither spoken nor posted. What was already transcribed stays in the transcript, so it is in the summary too. Someone who opts back in during a call is transcribed and answered again right away, but only recorded again once they rejoin the call.

The opt-outs are kept in the `opt_outs` table of `STATS_DATABASE`: the user ID and when they opted out. They are read when a call starts. When they can't be read, `/record` refuses to start, as it can't tell who must not be recorded.

Discord sends the bot everyone's audio, so the voice library still receives and decodes that of people who opted out, and keeps it in memory until the call ends, like everyone's (see [Known limitations](#known-limitations)). The bot keeps none of it. The library also writes each speaker's audio to a file in the system's temp folder, which the bot deletes when the call ends (see [Known limitations](#known-limitations)).

The library names a speaker by their SSRC, a number for their audio stream, when it doesn't know who they are. That can happen when someone leaves the call and comes back. The bot then doesn't record or transcribe that speaker, as they may have opted out, and logs a warning.

### Private meetings

Discord doesn't let bots join the calls of direct messages and group DMs, only voice channels in a server. `/meet` is the closest there is: `/meet person:@Spartan` makes a private voice channel for you, the people you pick and the bot, which the bot joins and records like `/record` does. Pick up to four people, with `person`, `person2`, `person3` and `person4`. To talk to the bot alone, pick the bot itself.

- The channel is named after the people in the meeting, e.g. "Meeting: Alex, Spartan", and is made in the category of the channel `/meet` was used in.
- Only you, the people you picked and the bot can see and join it: everyone else is denied View Channel, and the people in the meeting are allowed View Channel, Connect and Speak. Server admins and the server's owner see every channel, whatever its permissions.
- The bot replies with a link to the channel, and mentions the people you picked in a message of its own, so that Discord notifies them. Nobody else is pinged.
- The meeting ends when the last person leaves the channel: the bot stops recording, as with `/stop`, and deletes the channel. Bots don't count as people. A channel nobody is in 5 minutes after it was made is deleted too.
- Like with `/record`, Claude's answers and the meeting's summary are posted in the channel where `/meet` was used: the meeting's own chat is deleted with its channel. Use `/meet` in a channel only the people in the meeting can read when nobody else should see them. The meeting is saved with the server's other calls, so `/recall` answers from it as well.
- `/meet` checks what `/record` checks, and refuses while a call is being recorded in the server, or the bot is joining a channel to record one: Discord lets a bot be in one voice channel per server. `/stop` during a meeting stops the recording, and the channel stays until everyone has left it.
- The bot needs the **Manage Channels** permission to make and delete the channel, and says so when it lacks it.

### Direct messages and memory

Send the bot a direct message, and Claude answers it there, in text, as your personal assistant. The bot shows it's typing while Claude works.

- Each answer is made from what the bot remembers about you and from the DM's last 20 messages.
- Your messages are answered one at a time, in the order you sent them.
- An answer that doesn't fit in one Discord message is split into several, never in the middle of a sentence.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the bot replies with the reason.
- Only text and [voice messages](#voice-messages-in-direct-messages) are read: a message with nothing but a picture or another attachment gets no answer. Messages in servers and messages from other bots are never answered.

#### Voice messages in direct messages

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

**The memory** is a markdown note that Claude writes about each person, at `MEMORY_PATH/<user id>.md`. It holds what helps Claude help that person later: their projects, plans, decisions, preferences, open questions and the people they mention. Claude is told to leave out passwords, tokens and other secrets. The note stays under 4,000 characters, so it fits in every prompt: when it's full, Claude keeps what's most useful.

The memory is updated when a conversation pauses. Ten minutes after your last message, one Claude request gets the current memory and what was said since its last update, and returns the new memory. When that fails (the usage limit is reached, ...), the next update gets what was said, too. If the bot stops before then, that update is lost. When a call is updating the same memory at that moment, the update waits for it: see [Memory in calls](#memory-in-calls).

- `/memory` shows you what the bot remembers about you, and lists the groups you have a memory with (see [Memory in calls](#memory-in-calls)). `/share` lets the bot use it in a call for everyone there, until the call ends (see [Sharing your personal memory with a call](#sharing-your-personal-memory-with-a-call)).
- `/forget` deletes it, together with what you said since its last update. The messages themselves stay in the DM, where the last 20 are still sent to Claude with your next message.

- `/privacy` shows your privacy settings, and `/privacy personal_memory_in_calls:only after /share` keeps your personal memory out of calls with other people, unless you share it (see [Keeping your personal memory out of calls with others](#keeping-your-personal-memory-out-of-calls-with-others)).

`/memory`, `/forget` and `/privacy` work in DMs and in servers, and only you see their replies.

> [!IMPORTANT]
> Memories are kept on the bot's machine, where anyone with access to the machine can read them. The files themselves can only be read by the user the bot runs as (mode 0600).

Claude runs with the same restrictions as in calls: no tools, no MCP servers and an empty directory. Every DM answer and every memory update uses your Claude subscription, and anyone who shares a server with the bot can send it direct messages.

### Memory in calls

Most things are discussed and decided in calls, so the bot remembers those too. What is said in a call with Spartan belongs to you and Spartan, not to your private memory, so the bot keeps your **personal memory** (the one of your DMs) and a separate **group memory** for each group of people it has calls with.

**Which memory a call uses** depends on who is in the voice channel. The bot doesn't count, and neither do other bots; everyone else does, not only the people who spoke, so a memory isn't brought up in front of someone it doesn't belong to.

- Alone with the bot, you have your personal memory: it is added to what Claude is asked, and updated from the call, like in DMs.
- With others, the call uses the group memory of exactly the people in the channel. You and Spartan have one; you, Spartan and Carol have another. It is added to what Claude is asked, and updated from what was said while that group was there.
- When people join or leave, the next question uses the memory of the new group. What was said is kept with the people who were there when it was said, also for someone who left right after speaking.
- A question waits for its turn and is transcribed before Claude is asked, and people come and go meanwhile. So the bot looks at who is in the channel again when it asks Claude: when it isn't the same people any more, because someone joined or left, that answer gets no group memory. Your personal memory, as far as your `/privacy` setting lets it, and the shared ones are still added.
- An answer made from a group memory is cut off when someone joins who that memory doesn't belong to, while Claude is still writing it or the bot is still speaking it. The sentence being spoken ends, which is the one thing they can hear of it, and the rest isn't spoken. An answer Claude hadn't finished is neither posted nor kept in the transcript; one it had finished was already posted in the text channel. Ask again, and the answer uses the memory of the people who are there now. The same happens when one of the group opts out of being recorded meanwhile. Someone leaving changes nothing, as they hear no more of it, and neither does someone joining while the answer has no group memory in it.
- Other bots in the channel, like a music bot, are nobody to a memory. With you, Spartan and a music bot there, the call uses the memory of you and Spartan; with you and a music bot, your personal memory; and five people and a music bot still have a group memory. What a bot says is transcribed like anyone's and kept with the people who are there, and a bot that talks to the bot with nobody else there is answered without any memory. A user Discord doesn't say is a bot counts as a person, so that no memory is brought up in front of someone the bot can't place.
- Whenever you ask Claude something in a call with others, your personal memory is added to the prompt as well, labeled with your name, even with others listening, unless you chose otherwise with `/privacy` (see [Keeping your personal memory out of calls with others](#keeping-your-personal-memory-out-of-calls-with-others)). The other people's personal memories are only used when they shared them with `/share`. Claude's instructions say whose memory is whose, that everyone in the call hears its answer, and that it should only bring up what the question needs.
- Personal memories are never updated from calls with other people: what Spartan says there goes into the group's memory, never into yours.
- A group's memory is made from what its people said, not from what Claude answered them. An answer may quote your personal memory, or one that was shared, and it would then stay in the group's memory, where `/memory with:` shows it and your own `/forget` doesn't reach it. So in a call with others, Claude's answers are only in the call's transcript and summary. Alone with the bot, Claude's answers do count for your personal memory, like in DMs, except the ones given while someone else is sharing their memory with the call.
- While someone who opted out of being recorded (see [Opting out of being recorded](#opting-out-of-being-recorded)) is in the channel, no group memory is used or updated. What is said then is not remembered, and a memory belonging to someone who opts out is not used or updated from then on, even for a question already waiting for its turn, an answer made from it that is still being spoken, or an update Claude is already writing.
- When the voice states of the bot's cache don't show the bot in its voice channel, who is there is not known, and no group memory is used or updated either. Voice states come from the `GUILD_VOICE_STATES` intent, which the default intents include.

**Updates** run after the call ends and its summary is posted (also when the summary failed), one after the other, with one Claude request for each memory, however often the same people came back to the call: it gets the memory and the part of `transcript.txt` that was said while they were there. A memory that can't be updated is logged as a warning, and the others still are.

A memory is updated by one request at a time, whoever asks for it: your DMs, ten minutes after your last message; a call you were alone with the bot in; and, for a group, calls of the same people that end around the same time, in any server. Each update waits for the one before it and reads the memory that one saved, so neither overwrites what the other added. An update that fails doesn't hold up the next one. One that is forgotten with `/forget`, or whose people opted out, while it waited is dropped without Claude getting what was said. Waiting is not logged.

**Group memories** follow the same rules as personal ones: a markdown note under 4,000 characters, written by Claude, who is told to leave out passwords, tokens and other secrets, and kept in a file only the bot's user can read (mode 0600). A group's file is `MEMORY_PATH/groups/<the people's user IDs, sorted, joined with ->.md`, e.g. `groups/222333444555666777-888999000111222333.md`.

- `/memory` also lists the groups you have a memory with: "You also have memories with: Spartan; Spartan and Carol."
- `/memory with:@Spartan` shows the memory you share with Spartan. The options `with`, `with2`, `with3` and `with4` name the other people of the group, so a group of up to five people can be opened.
- `/forget with:@Spartan` deletes that memory, together with what was said in a call that is going on since its last update. `/forget` without options also drops what was said alone with the bot in such a call.
- Anyone in a group can see and delete its memory. Only they see the reply.
- Memories of calls recorded before this feature don't exist: only what is said from now on is remembered.

A call with more than five people uses and updates no group memory: `/memory` and `/forget` can only name you and four others, so the memory of a bigger group couldn't be seen or deleted. It also keeps a group's file name within what file systems allow.

Updates are logged as `Updated memory`, with the call's `session`, how many `people` the memory belongs to and its length in `characters`. The memory itself is never logged.

#### Sharing your personal memory with a call

By default, only your own question gets your personal memory. When you and Spartan ask "what are we missing from each other's point of view?", the bot needs both of your personal memories, and that only happens when each of you says so.

- `/share`, used by someone in the voice channel the bot is recording, adds their personal memory to the call: until the call ends, the bot may use it to answer anyone there, and to compare people's points of view. `/unshare` takes it back, also from someone who left the call or who writes to the bot in a direct message. An answer that Claude is still writing when someone takes their memory back, or opts out, is dropped: it is neither spoken nor posted.
- Sharing is an explicit choice for this call, so it works whatever your `/privacy` setting is. Only having opted out of being recorded blocks it, as below.
- It lasts until the call ends, and not longer: the next call starts with nobody sharing. Opting out of being recorded with `/optout` also stops it, and whoever opted out can't `/share` until they `/optin`: someone the bot doesn't record or answer doesn't have their memory used in calls either.
- The call's text channel gets a notice when someone shares or stops sharing ("Alex shared their memory with this call."), so everyone reading it knows. If the bot can't post there, that is logged as a warning and sharing goes on. Replies to the person who used the command are ephemeral: only the notice is public.
- Every question's prompt has the group memory and the asker's personal memory, as above, plus the personal memory of everyone who shared, each labeled with the person's name. A prompt holds at most 5 shared memories: when more people share, the 5 who shared most recently are used. A memory that was forgotten with `/forget` after it was shared is not used.
- Claude's instructions say whose memory is whose, that shared memories may be used for anyone in the call, and that when asked, it can compare what each person knows or wants and point out what they might be missing from each other.
- `/share` outside a call the bot is recording, or by someone who isn't in that call, only explains that it works in a recorded call. Someone with no personal memory yet is told there is nothing to share.
- Shared memories are only used to answer. Personal memories are still never updated from calls with others, and what Claude answers while someone else shares is remembered in no memory: not in the group's, and not in the personal memory of someone who is alone with the bot after the sharer left.
- A memory is shared with the call, so with whoever is in it: someone who joins hears answers made from it too, and an answer isn't cut off for them as it is for a group memory.

`/share` and `/unshare` are logged as `Shared memory` and `Stopped sharing memory`, with the `user` and the call's `session`. The memory itself is never logged.

#### Keeping your personal memory out of calls with others

Your personal memory is added to your questions in calls, also with other people listening. Some people want to be private with the bot and not with everyone else in the call, so `/privacy` lets each person keep their personal memory out of calls with others. It works in DMs and in servers, and only you see the reply.

- `/privacy` without options shows your privacy settings.
- `/privacy personal_memory_in_calls:<choice>` changes the setting. The choices are `when I ask` (the default: your personal memory is used whenever you ask Claude something in a call, as described above) and `only after /share`.
- With `only after /share`, your personal memory is never used in a call with other people, even when you ask, until you share it in that call with `/share`. It is still used when you are alone with the bot in the voice channel. Group memories, direct messages and other people's questions work as before: your memory only reaches them when you share it.
- Whether you are alone is read when you ask, and again while Claude answers: when someone joins a call that your memory was added to only because you were alone, the answer is dropped, neither spoken nor posted, like when someone takes a shared memory back. Ask again to get an answer without it.
- This fails closed: when your setting can't be read, it is treated as `only after /share`, and that is logged as a warning. Choosing again with `/privacy` repairs it. When the bot doesn't know who is in the call (see [Memory in calls](#memory-in-calls)), it is treated as someone else being there.
- A change counts from your next question, also in a call that is going on.
- What was already said stays said: an answer given while you were alone is in the call's transcript and its text channel, like everything the bot says, and the last lines of the transcript are part of every later question in the call. Claude can repeat such an answer when someone who joined afterwards asks about it.

The setting is kept in the `user_settings` table of `STATS_DATABASE`, created the first time it is needed: your user ID, your choice (`when_asked` or `after_share`) and when you changed it. A change is logged as `/privacy changed`, with the `user` and the new `personal_memory_in_calls` value.

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

6. In the [Discord Developer Portal](https://discord.com/developers/applications), enable the bot's **Server Members Intent**, then invite it with the `bot` and `applications.commands` scopes and the View Channels, Send Messages, Connect, Speak and Manage Channels permissions. Manage Channels is only needed for `/meet`.

7. Copy `.env-local` to `.env`, fill in your bot token and the paths from the steps above, and start the bot:

    ```bash
    cp .env-local .env
    composer serve
    ```

### Configuration

| Variable | Default | |
|---|---|---|
| `BOT_SLASH_COMMANDS` | | Must be set for `/record`, `/stop`, `/stats`, `/settings`, `/recall`, `/optout`, `/optin`, `/meet`, `/memory`, `/forget`, `/share`, `/unshare` and `/privacy` to be registered. |
| `RECORDINGS_PATH` | `recordings` | Where recordings, transcripts and summaries are saved. `/recall` answers from them. |
| `RECORDINGS_RETENTION_DAYS` | | Calls older than this many days are deleted, with their recordings, transcript and summary. A whole number, 1 or more. Leave it empty to keep everything. |
| `VOICE_WAKE_WORD` | `claude` | Claude only answers what mentions this word or phrase. A phrase also counts when punctuation is heard between its words: "Okay, computer" mentions `okay computer`. Whisper often writes the wake word differently from how it was said ("Claude" becomes "Cloud" or "Claud"), so it can have several spellings, separated by commas: `claude, cloud, claud`. A sentence that mentions any of them is for the bot, each heard as a whole word, in any case. The first is the wake word's name: it is the one the bot tells people to say when a call starts. Only list what whisper writes for your voice: with `cloud` in the list, the bot also answers when people talk about the cloud. Leave it empty to answer everything. Saying it opens a conversation: see [Voice calls with Claude](#voice-calls-with-claude). |
| `VOICE_STOP_PHRASE` | `stop <wake word>`, for each spelling | What closes the conversation of whoever says it, e.g. `para claude`. It replaces the default for every server, whatever its wake word, and is heard like the wake word is, so it can also list several spellings, separated by commas. Servers without a wake word have no conversations, so it does nothing there. |
| `VOICE_PAUSE_SECONDS` | `0.6` | How long someone has to be silent for what they said to be over. A number of seconds, 0.1 or more: raise it for people who pause longer in the middle of a sentence. Sounds less than that apart are one thing said, also when it comes to interrupting the bot. |
| `WHISPER_BINARY` | `whisper-cli` | Path to whisper.cpp's `whisper-cli`. |
| `WHISPER_MODEL` | | Path to the whisper model, e.g. `~/whisper.cpp/models/ggml-base.bin`. |
| `WHISPER_LANGUAGE` | `auto` | Language spoken in the call, e.g. `en` or `pt`, or `auto` to detect it. Detecting it takes time, for everything anyone says: on an utterance of 2.2 s, whisper `base` took 1.8 to 3.1 s with `auto` and 1.05 s with `en`. When one language is spoken, set it, here or with `/settings`. |
| `WHISPER_THREADS` | | How many threads whisper uses: a whole number, 1 or more. Empty, or anything else, leaves it to whisper, which takes 4, or as many as the CPU has when that is fewer. More is faster, up to what your CPU has: the same utterance took 1.05 s with 4 threads, 0.71 s with 8, and no less with 10, on a CPU with 10 cores. |
| `WHISPER_PROMPT` | | Text whisper takes as what was said just before, in every call and in voice messages sent in DMs. It helps whisper write the wake word as it is: on seven sentences that began with "Claude", whisper `base` with `en` wrote "Claude" or "Claud" in 1 with no prompt, in 6 with `A voice call with the assistant Claude.`, and in 3 with `Hey Claude.`. A prompt can also make whisper write words that weren't said (the second one made it drop words from other sentences), so try one before keeping it, and still list the spellings it leaves out, such as "Claud". Leave it empty to give whisper no prompt. |
| `CLAUDE_BINARY` | `claude` | Path to the Claude Code CLI. |
| `CLAUDE_MODEL` | `haiku` | `haiku` answers fastest; `sonnet` or `opus` answer better, but slower. |
| `PIPER_BINARY` | `piper` | Path to Piper. |
| `PIPER_MODEL` | | Path to the Piper voice, e.g. `~/piper/voices/en_US-lessac-medium.onnx`. The other voices in its folder can be chosen with `/settings`. |
| `FFMPEG_BINARY` | `ffmpeg` | Path to ffmpeg, which converts Piper's speech for Discord, and the voice messages sent in DMs for whisper.cpp. The voice library always uses the `ffmpeg` on your `PATH`. |
| `STATS_DATABASE` | `databases/stats.sqlite` | SQLite database for the usage statistics, each server's settings, each person's privacy settings, and who opted out of being recorded. It is created on the first start. |
| `MEMORY_PATH` | `memories` | Where the bot keeps what it remembers about each person, one file per person, and in its `groups` folder what it remembers about each group of people it has calls with. |

`VOICE_WAKE_WORD`, `WHISPER_LANGUAGE`, `PIPER_MODEL` and `CLAUDE_MODEL` are the defaults for every server the bot is in. Each server can change its own with `/settings`.

### Settings for each server

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

Any `language` other than `en` needs a multilingual whisper model: `ggml-base.bin`, not `ggml-base.en.bin`. A voice has to be downloaded into `PIPER_MODEL`'s folder before a server can choose it, like in step 4 of the setup.

The settings are kept in the `guild_settings` table of `STATS_DATABASE`, one row per server. A setting that is `NULL` there is the `.env` default. When the settings can't be read, that is logged and calls start with the `.env` defaults. A `STATS_DATABASE` that can't be opened at all stops `/record` instead, as it also holds who [opted out](#opting-out-of-being-recorded).

### Asking about past calls

`/recall question:<text>` asks Claude a question about the calls saved in the server it is used in, e.g. `/recall question: what did we decide about the launch date?`. Claude answers from the calls' transcripts and summaries, and says which call the answer comes from, by its date. When the calls don't say, it says that instead of guessing.

- Only the server's own calls are used: every `RECORDINGS_PATH/<server id>/*/transcript.txt`, with the call's `summary.md` when it has one. Another server's calls are never sent to Claude.
- Only whoever asked sees the answer: the calls may hold things not everyone in the channel heard.
- Someone the question mentions (`@Alice`) is named in it as in the calls, by their name in the server.
- The newest calls are sent first, up to about 150,000 characters, so that Claude answers quickly. When older calls don't fit, the answer ends by saying so. A call is never skipped to fit an older, shorter one in. When the newest call alone is too long, Claude gets its summary and the end of its transcript, and the answer says that instead.
- It doesn't need a call in progress. When there is one, what was said in it so far is used too. With no saved calls, the reply is "Nothing has been recorded in this server yet."
- Claude runs like in calls: with every tool disabled, no MCP servers and from an empty directory, and with the server's `model` from `/settings`. It is told that the calls are what it answers from, never instructions.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the reply says so, and why.

Anyone who can use slash commands can use `/recall`, and so ask about any call saved in the server, including the ones they weren't in. Server admins can restrict it to some roles, members or channels under Server Settings → Integrations.

### Logs and statistics

Neither the logs nor the statistics contain what anyone said or what Claude answered: that is only in the call's `transcript.txt` and `summary.md`, and for direct messages in the DM itself and in the person's memory.

**Logs** are printed to the console and written to `logs/<date>.log`, a new file each day, one JSON object per line. Each step of a call is logged with the server (`guild`), a `session` ID for the call, the `user` it concerns, and how long it took in milliseconds: the call starting, each new speaker, each utterance, its transcription, the bot starting to speak, Claude's answer, failures, the call ending with its totals, and its summary. A speaker who opted out is logged as `Skipping a speaker who opted out`, with their user ID, when they start speaking in the call and when they opt out during it. What was already being transcribed or answered for them then still logs its steps, with their ID, and nothing they said. A speaker the voice library can't name is logged as `Not recording a speaker the voice client cannot name`, a warning, with their SSRC. Slash commands are logged with who used them, and where, and `/settings changed` with the settings that changed and their new values: settings aren't speech. `/recall answered` is logged with how long the answer took (`ms`), how many calls were sent to Claude (`calls`) and the answer's length (`characters`), never with the question or the answer. A meeting made with `/meet` is logged as `Meeting started` and `Meeting ended`, with the server (`guild`), its `channel`, how many people were `invited` and the `session` of its call, but not the channel's name, which holds people's names. Direct messages are logged as `Answered a DM`, with the `user`, how long the answer took and its length in characters, voice messages in them as `Transcribed a voice message`, with the `user`, the message's length in `seconds`, how long transcribing it took (`ms`) and the length of what was said (`characters`), never what was said, and memory updates as `Updated memory`, with the `user` and the memory's length. A memory updated from a call is logged as `Updated memory` too, with the `session`, how many `people` it belongs to and its length (`characters`), and one that can't be updated as the warning `Could not update the memory`. When old recordings are deleted, `Deleted old recordings` is logged with the number of `calls` deleted and the `days` they are kept for. A call's folder that can't be deleted is logged as a warning, and tried again an hour later. A `RECORDINGS_RETENTION_DAYS` that isn't a whole number of days is logged as a warning too, when the bot starts, and nothing is deleted. To search the log with [jq](https://jqlang.org/):

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

**Statistics** are kept in `STATS_DATABASE`, one row per event in the `events` table: `call_started`, `call_ended` (with the call's length), `utterance` (with its length), `answered` (with the time from the end of the question to the answer being posted) and `failed` (something said couldn't be transcribed or answered, or the answer couldn't be spoken), each with the server, channel, user and session. `/stats` shows the server it is used in its totals: calls and minutes recorded, utterances and people speaking, questions answered and how long that took on average, and failures. Summaries, `/recall` and direct messages aren't counted. Only whoever used `/stats` sees them. To query the statistics yourself:

```bash
sqlite3 databases/stats.sqlite "SELECT guild_id, COUNT(*) AS answers FROM events WHERE type = 'answered' GROUP BY guild_id"
```

### Known limitations

- The bot starts on its answer about two seconds after a short question, as measured on a 10-core desktop CPU (i9-10900K) with whisper `base`, `WHISPER_LANGUAGE=en`, `WHISPER_THREADS=8`, `CLAUDE_MODEL=haiku` and the `en_US-lessac-medium` voice: 0.6 to 0.65 s of silence before the question counts as over, about 0.7 s of transcription, about 0.5 s until Claude's first words, and 0.1 to 0.25 s for Piper to speak the first sentence. With `WHISPER_LANGUAGE=auto`, transcription takes a second or two longer, and a bigger whisper model or a slower CPU adds to it as well. The voice library then waits half a second before it sends a sentence's audio, which is also the pause between two sentences.
- Only the person the bot is answering can interrupt it. Someone who listens to the bot on speakers, without echo cancellation, may interrupt it with its own voice.
- Speech recognition sometimes mishears the wake word (e.g. "cloud" for "Claude"). List the spellings whisper writes, separated by commas, in `VOICE_WAKE_WORD` or `/settings`, and give whisper a `WHISPER_PROMPT` that names the wake word.
- The wake word is looked for as whole words. In languages written without spaces between words, such as Japanese or Thai, it is only heard when whisper writes a space or punctuation around it.
- The voice library (`discord-php-helpers/voice` 8.3.0) keeps every decoded audio frame in memory until `/stop`, roughly 12 MB per speaker per minute of speech. That's fine for normal calls; for very long ones, `/stop` and `/record` again now and then.
- The voice library also writes a copy of each speaker's audio to the system's temp folder, as `<date>_<time>-<SSRC>.ogg`. The bot doesn't use these files. It deletes them when the call ends, and when it starts, those that a call it didn't get to end left behind, as when it crashed. It takes every file named like that in the temp folder to be one of them.
- The bot only remembers its meetings while it runs. When it stops during a meeting made with `/meet`, the meeting's channel stays: delete it by hand.

### Tests

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

The code style is [Laravel Pint](https://laravel.com/docs/pint) with the rules of `pint.json`. `composer pint` fixes the files; `composer pint -- --test` only lists what it would change. The same check runs in GitHub Actions (the `pint` job of `tests.yml`, next to the tests), so a pull request with a style issue fails there instead of the issue reaching `master`.

### Benchmark

The tests above can't tell whether the bot got slower: they run with stand-ins that answer at once. `composer bench` asks the bot a spoken question with the real whisper.cpp, Claude Code and Piper of your machine, the ones in `.env`, and times each step. Run it before merging something that could slow the bot down. It isn't run in CI, which has neither the programs nor your hardware.

```bash
composer bench:baseline   # on master: save this machine's times as the baseline
composer bench            # on the branch: fails when it is slower than the baseline
```

Piper speaks the question, "Hey Claude, tell me two short facts about Canberra, one sentence each.", into the fake Discord of the feature tests, 5 times in one call. The call goes through the bot's own code from there: the wait for silence, whisper, Claude and Piper. The times come from the bot's log, in milliseconds:

| Step | |
|---|---|
| waiting for silence | From the end of the question to the bot taking it as over. |
| whisper | Transcribing the question. Compared. |
| Claude, the whole answer | From asking Claude to its whole answer. Compared. |
| from the utterance to the first sentence spoken | `Started speaking` in the log. |
| from the end of the question to the first sentence spoken | What someone in the call waits for. Compared. |
| the call's summary, once | Only shown. |

For each step it shows the fastest and the median of the 5 questions. The fastest is what is compared with the baseline: whatever else the machine and the network are doing only ever adds time, so it says most about the bot. In three runs of the same code on a busy machine the fastest times were within 10% of each other, and the medians within 25%. A compared step fails when it is more than 25% and more than 200 ms over the baseline, so the bench catches what makes the bot clearly slower, not a few milliseconds.

It also shows the settings it ran with, and which of them differ from the baseline's: a slower model in `.env` is not a slower bot. `MAX_THINKING_TOKENS` is shown when it is set where the bench is started, as Claude Code reads it from there: it changes how long the call's summary takes, not the answers, which are written without thinking whatever it says.

- The baseline is kept in `~/.cache/discord-bot-bench.json`, so every checkout of the bot on the machine compares with the same one. `BENCH_BASELINE` is another file to use.
- The settings are read from the checkout's `.env`. In a checkout without one, `BENCH_ENV_FILE` is the `.env` to read. Only the settings of the programs are used (`WHISPER_*`, `CLAUDE_*`, `PIPER_*`, `FFMPEG_*`, `VOICE_*`), never the Discord token or where the bot keeps its recordings, memories and statistics, and the wake word is left out so the bench doesn't depend on whisper hearing it. A setting with `TOKEN`, `KEY`, `SECRET` or `PASSWORD` in its name is left out too: the settings are printed, and saved with the baseline.
- A baseline file that can't be read as one fails the bench, with what to do: it is never taken for no baseline.
- Each run asks Claude 6 times with your subscription: the 5 questions and the call's summary.
- Without a `.env`, or with a program or model it names missing, the bench is skipped and says why.

### Live voice test

`tests/Live` asks the bot a question in a real Discord voice call. A second bot plays the spoken question "Hey Claude, what time is it?" and records the answer.

Everything except Claude is real: Discord with its end-to-end encryption, whisper.cpp and Piper. Claude is replaced by a fixed answer of two sentences, written one after the other like Claude Code does, so no Claude subscription is used. Like in any call, the stand-in is already running and waiting when the question comes, and one Piper process, the real one, speaks both sentences; the test fails when either had to be started for the answer. It runs in GitHub Actions (`.github/workflows/live-voice.yml`) every night, on demand, and on pull requests that change the bot once they're no longer drafts. Every run keeps its recordings as an artifact you can download and listen to.

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
