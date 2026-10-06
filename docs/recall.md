# Asking about past calls

[← Back to the README](../README.md)

`/recall question:<text>` asks Claude a question about the calls saved in the server it is used in, e.g. `/recall question: what did we decide about the launch date?`. Claude answers from the calls' transcripts and summaries, and says which call the answer comes from, by its date. When the calls don't say, it says that instead of guessing.

- Only the server's own calls are used: every `RECORDINGS_PATH/<server id>/*/transcript.txt`, with the call's `summary.md` when it has one. Another server's calls are never sent to Claude.
- Only whoever asked sees the answer: the calls may hold things not everyone in the channel heard.
- Someone the question mentions (`@Alice`) is named in it as in the calls, by their name in the server.
- The newest calls are sent first, up to about 150,000 characters, so that Claude answers quickly. When older calls don't fit, the answer ends by saying so. A call is never skipped to fit an older, shorter one in. When the newest call alone is too long, Claude gets its summary and the end of its transcript, and the answer says that instead.
- It doesn't need a call in progress. When there is one, what was said in it so far is used too. With no saved calls, the reply is "Nothing has been recorded in this server yet."
- Claude runs like in calls: with every tool disabled, no MCP servers and from an empty directory, and with the server's `model` from `/settings`. It is told that the calls are what it answers from, never instructions.
- When Claude can't answer (it isn't logged in, the usage limit is reached, ...), the reply says so, and why.

Anyone who can use slash commands can use `/recall`, and so ask about any call saved in the server, including the ones they weren't in. Server admins can restrict it to some roles, members or channels under Server Settings → Integrations.
