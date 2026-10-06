# Memory in calls

[← Back to the README](../README.md)

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
- While someone who opted out of being recorded (see [Opting out of being recorded](opting-out.md#opting-out-of-being-recorded)) is in the channel, no group memory is used or updated. What is said then is not remembered, and a memory belonging to someone who opts out is not used or updated from then on, even for a question already waiting for its turn, an answer made from it that is still being spoken, or an update Claude is already writing.
- When the voice states of the bot's cache don't show the bot in its voice channel, who is there is not known, and no group memory is used or updated either. Voice states come from the `GUILD_VOICE_STATES` intent, which the default intents include.

**Updates** run after the call ends and its summary is posted (also when the summary failed), one after the other, with one Claude request for each memory, however often the same people came back to the call: it gets the memory and the part of `transcript.txt` that was said while they were there. A memory that can't be updated is logged as a warning, and the others still are.

A memory is updated by one request at a time, whoever asks for it: your DMs, ten minutes after your last message; a call you were alone with the bot in; and, for a group, calls of the same people that end around the same time, in any server. Each update waits for the one before it and reads the memory that one saved, so neither overwrites what the other added. An update that fails doesn't hold up the next one. One that is forgotten with `/forget`, or whose people opted out, while it waited is dropped without Claude getting what was said. Waiting is not logged.

**Group memories** follow the same rules as personal ones: a markdown note under 4,000 characters, written by Claude, who is told to leave out passwords, tokens and other secrets, and kept in a file only the bot's user can read (mode 0600). A group's file is `MEMORY_PATH/groups/<the people's user IDs, sorted, joined with ->.md`, e.g. `groups/222333444555666777-888999000111222333.md`.

- `/memory` also lists the groups you have a memory with: "You also have memories with: Spartan; Spartan and Carol."
- `/memory with:@Spartan` shows the memory you share with Spartan. The options `with`, `with2`, `with3` and `with4` name the other people of the group, so a group of up to five people can be opened.
- `/forget with:@Spartan` deletes that memory, together with what was said in a call that is going on since its last update. `/forget` without options also drops what was said alone with the bot in such a call. What it drops is also taken out of that call's `transcript.txt`, so Claude's next answers, what is looked up, the summary and `/recall` don't have it either, and the file is deleted when nothing is left of it. What was said while others were there stays when only your own memory is forgotten: it belongs to the group's.
- Anyone in a group can see and delete its memory. Only they see the reply.
- Memories of calls recorded before this feature don't exist: only what is said from now on is remembered.

A call with more than five people uses and updates no group memory: `/memory` and `/forget` can only name you and four others, so the memory of a bigger group couldn't be seen or deleted. It also keeps a group's file name within what file systems allow.

Updates are logged as `Updated memory`, with the call's `session`, how many `people` the memory belongs to and its length in `characters`. The memory itself is never logged.

## Sharing your personal memory with a call

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

## Keeping your personal memory out of calls with others

Your personal memory is added to your questions in calls, also with other people listening. Some people want to be private with the bot and not with everyone else in the call, so `/privacy` lets each person keep their personal memory out of calls with others. It works in DMs and in servers, and only you see the reply.

- `/privacy` without options shows your privacy settings.
- `/privacy personal_memory_in_calls:<choice>` changes the setting. The choices are `when I ask` (the default: your personal memory is used whenever you ask Claude something in a call, as described above) and `only after /share`.
- With `only after /share`, your personal memory is never used in a call with other people, even when you ask, until you share it in that call with `/share`. It is still used when you are alone with the bot in the voice channel. Group memories, direct messages and other people's questions work as before: your memory only reaches them when you share it.
- Whether you are alone is read when you ask, and again while Claude answers: when someone joins a call that your memory was added to only because you were alone, the answer is dropped, neither spoken nor posted, like when someone takes a shared memory back. Ask again to get an answer without it.
- This fails closed: when your setting can't be read, it is treated as `only after /share`, and that is logged as a warning. Choosing again with `/privacy` repairs it. When the bot doesn't know who is in the call (see [Memory in calls](#memory-in-calls)), it is treated as someone else being there.
- A change counts from your next question, also in a call that is going on.
- What was already said stays said: an answer given while you were alone is in the call's transcript and its text channel, like everything the bot says, and the last lines of the transcript are part of every later question in the call. Claude can repeat such an answer when someone who joined afterwards asks about it.

The setting is kept in the `user_settings` table of `STATS_DATABASE`, created the first time it is needed: your user ID, your choice (`when_asked` or `after_share`) and when you changed it. A change is logged as `/privacy changed`, with the `user` and the new `personal_memory_in_calls` value.
