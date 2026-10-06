# Private meetings

[← Back to the README](../README.md)

Discord doesn't let bots join the calls of direct messages and group DMs, only voice channels in a server. `/meet` is the closest there is: `/meet person:@Spartan` makes a private voice channel for you, the people you pick and the bot, which the bot joins and records like `/record` does. Pick up to four people, with `person`, `person2`, `person3` and `person4`. To talk to the bot alone, pick the bot itself.

- The channel is named after the people in the meeting, e.g. "Meeting: Alex, Spartan", and is made in the category of the channel `/meet` was used in.
- Only you, the people you picked and the bot can see and join it: everyone else is denied View Channel, and the people in the meeting are allowed View Channel, Connect and Speak. Server admins and the server's owner see every channel, whatever its permissions.
- The bot replies with a link to the channel, and mentions the people you picked in a message of its own, so that Discord notifies them. Nobody else is pinged.
- The meeting ends when the last person leaves the channel: the bot stops recording, as with `/stop`, and deletes the channel. Bots don't count as people. A channel nobody is in 5 minutes after it was made is deleted too.
- Like with `/record`, Claude's answers and the meeting's summary are posted in the channel where `/meet` was used: the meeting's own chat is deleted with its channel. Use `/meet` in a channel only the people in the meeting can read when nobody else should see them. The meeting is saved with the server's other calls, so `/recall` answers from it as well.
- `/meet` checks what `/record` checks, and refuses while a call is being recorded in the server, or the bot is joining a channel to record one: Discord lets a bot be in one voice channel per server. `/stop` during a meeting stops the recording, and the channel stays until everyone has left it.
- The bot needs the **Manage Channels** permission to make and delete the channel, and says so when it lacks it.
