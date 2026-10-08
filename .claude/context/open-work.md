# Open work

Snapshot of 2026-10-07 12:15 Lisbon (master at ec45a5b). Eight PRs are open. Run `gh pr list --state open` and `git fetch` before trusting anything below; master moved four to six times a day this week.

## The PR workflow in this repo

- A PR starts as a **brief**: a draft with an empty "Start ..." commit and a description with `## Why` (measurements and the decision that led here), `## What it does` (numbered parts with checkboxes, the decisions still to ask Sky marked), `## Tests and docs`, `## Where in the code` (pinned to a master commit), `## Out of scope`, `## Depends on`, `## How to work on this PR`, `## Ground rules for this repo`.
- The agent working it ticks boxes as they are done, writes decisions and measured numbers into the description, and merges `master` into the branch whenever it moves (never rebase or force-push). Editing the body from a Windows script doubles `\r`: read, strip `\r`, pass bytes.
- Drafts get no CI. Before `gh pr ready`: the CI command green locally, `pint --test` clean, a mutation run over the changed lines, the bench on the voice path (`composer bench`, on an idle machine, fastest of five against the master baseline), `gh workflow run live-voice.yml --ref <branch>`.
- Finish order that has survived: local run green → `git fetch` and `git rev-list --left-right --count origin/master...origin/<branch>` as its own step → `gh pr ready` → wait for the runs `ready_for_review` created → read `Lines: 100.00%` and that the `live` job ran (a draft push leaves a skipped run for the same SHA next to the real one: pick the run `ready_for_review` created) → check master again (it moves while CI runs) → tick the last box → merge with `gh pr merge N --merge`. Sky does not want to be the step between a green PR and master; open questions go in a "Left for Sky to decide" section of the description.
- Issues are turned off; follow-up lists live in PRs (#26 was one).

## Open PRs

Open, all against `master`: #35 to #37, #39 to #42 and #44 are briefs (one empty "Start ..." commit each, no files), #38 has code on its branch. #46 is a draft brief too.

| PR | Branch | State | Holds |
|---|---|---|---|
| #35 | `feature/stop-phrase-at-once` | draft brief, no code | Transcribe a sentence when it ends, not when its turn comes; the stop phrase closes the conversation at once (cuts the spoken answer, never speaks one Claude is still writing, drops waiting sentences; "Okay." once); something new from the same person drops what the bot was going to say to them. Decisions for Sky in part 3 (sound vs words as "something new", whether the dropped answer is still posted, whether Claude is stopped, the cut answer in the next prompt). Pinned to 689e1ca. Depends on #25 (satisfied: the whisper server is on master), #31 (satisfied: merged; its `inTurn()` failure `->catch` and `apologize()` sit in the same methods), #34 (satisfied: `Player::stop()` and the first-packet log line exist). #30's leave phrase is on master and must be heard at once in the same place as the stop phrase. |
| #36 | `fix/statistics-off-the-answer-path` | draft brief, no code | Each `Usage::record()` (SQLite, journal `delete`, synchronous `FULL`) blocks the loop 3.5 ms median, 70 ms worst, once per utterance before whisper and while speaking. Recommended: hold a call's rows and write them in one transaction when nobody waits; not WAL+NORMAL (same file holds opt-outs). Pinned to 689e1ca. Depends on nothing; #31 is merged, so its flush at shutdown has to meet `Application::stop()`/`close()` (draining summaries before exit). |
| #37 | `feature/early-whisper` | draft brief, no code | Start whisper at 0.3 s of silence, drop the text if more packets come, use it at 0.6 s (same bytes, same text). Saves up to 300 ms of whisper time (233 ms average from one call's log). **Claude is not asked early: decided.** Part 1 first: a log line per sentence with the longest gap inside it, so Sky's calls say how often the early text is thrown away. Pinned to 906c3c6. Needs #25's server (satisfied: on master); build on #35 (still open); #34's log lines (`Asked Claude`, `Claude started answering`) are on master. Part 4: the GPU drops its clock after 5 s idle (+100 to 250 ms); Sky to try NVIDIA "Prefer maximum performance" first. The brief still says "on `feature/bench`" for the whisper server and `WhisperServer.php`: it is on master. |
| #38 | `feature/kokoro-voice` | draft, code on the branch (2026-10-08) | Kokoro (82M, PyTorch, CUDA) voice `af_heart`, picked by Sky on 2026-10-06. Built: `Speech::TRIM` takes the silence off both ends of every sentence, Piper's too (`silenceremove` at -60 dB, 20 ms kept at the start, 50 ms at the end; `SpeechTrimTest`); `kokoro/` (`kokoro` launcher, `kokoro_serve.py`, `engine.py`, `install.sh`, `requirements.txt`) is the program `PIPER_BINARY` points at, with `KokoroProgramTest`; `/settings voice:` lists the empty `kokoro/voices/<name>.onnx` files `install.sh` makes. Part 2: #39, #40 and #41 are in; #42 (card full, no card) waits for a time slot from Sky. Open: the fallback when Kokoro fails or runs on the processor (#42), the start in the first half minute (#40's recommendation: start with `/record`, one warm-up sentence, tell the text channel), `composer bench` with Piper and with Kokoro, the call with Sky. **Stop condition for Sky:** #41 found Kokoro 70 to 100 ms slower than Piper for a 5-word first sentence and equal at 15 words. |
| #39 to #42 | `chore/kokoro-check-{install,startup,with-whisper,card-full}` | draft briefs, measurements only, pinned to 906c3c6 | Install from nothing in `~/kokoro` with pinned versions and offline model files; start-up time and a sentence that arrives while loading; Kokoro and whisper on the same card at once (the whisper server is on master now); the card full and no card at all (#42 fills the card on purpose: ask Sky for a time slot). Results go into #38's description. |

Merged into master, newest first: #45 `/settings voice:` completed while typed, with simple names (`SuggestsOptions`, `VoiceLabel`; merged 2026-10-08), #26 background lookups follow-up (hard tasks, `LookupSlots` cap, stoppable lookups, `/forget` takes lines out of the transcript, no link previews, `composer check:lookups`), #34 own Ogg player (merge ec45a5b; it had merged master and #25 in), #25 `composer bench` with #33 the warm whisper server (merge 50b5f9e; #33 was merged into `feature/bench` first, as f2fc8bf), #31 failures said aloud and a clean exit (merge fb59d33), #28 lookups privacy and injection (906c3c6), #29 README split into `docs/`, #30 leave phrase (`disconnect <wake word>`), #32 remove old slash commands (`BOT_REMOVE_OLD_COMMANDS`), #27 wake word on a fresh install (`claude, claud` default, `WHISPER_PROMPT`).

The briefs pin their "Where in the code" to the master of their day: #35 and #36 to 689e1ca, #37 to #42 to 906c3c6 (`git merge-base origin/master origin/<branch>`). Master has moved since: #31 (`guarded()`, `apologize()`, `abandon()`, `FailedReply::WHISPER` in `handleUtterance()`), #34 (`player()`, `Asked Claude`, `Claude started answering`, `Started speaking` at the first packet) and #25/#33 (`$seconds` in `handleUtterance()`, the server) reshaped `VoiceSession`: re-read the methods before trusting a brief's description of them.

## Collision map

`app/Voice/VoiceSession.php` will be touched by every brief.

- #35 and #37: `handleUtterance()` (:1038), `inTurn()` (:972), `UtteranceSplitter`; #35 also `hear()` (:1371) and `sayOkay()`; #36: `track()` (:1927) and `Usage`; #38: `Speech`, `synthesize()`.

Conflicts (`git merge-tree --write-tree`): the briefs have no code yet, so there is nothing to merge against; #35 and #37 are the pair that must be built in order. #26 is merged: `VoiceSession` now has `$lookingUp`, `dropUnwantedLookups()`, `removeFromTranscript()` and `handleUtterance(..., float $seconds, array $forgotten)`, so a brief that adds a parameter or a turn there merges against that.

## Suggested merge order

1. Briefs, each merging the new master first: **#36**, then **#35**, **#37** on #35, **#38** part 3 (after #34, already in), the check PRs #39 to #42 as measurements.

Whichever of two overlapping PRs merges second merges master in first and re-runs the full suite before pushing: a clean `git merge` has still broken the class (two branches each added a `$waiting` property).

## Decisions waiting on Sky (as of this snapshot)

- #34 (merged): try `VOICE_PLAYER` in a call (`bot` is the default, `library` the way back) and say whether the first syllable is whole (`OggPlayer::HEAD_START` is 40 ms; the live test bounds the loss at about 20 ms, since #38 takes the silence off the start of every file but 20 ms); whether to fix the half second upstream in `discord-php-helpers/voice` (`ManagesPlayback::playOggStream()`, `startTime = now + 0.5`), after which this repository can drop its player; mono (`-ac 2` in `Speech::synthesize()` costs 20 to 75 ms per sentence) only if a client plays it wrong.
- WSL clock: the wall clock steps about every 34 s here, so bot code that measures with `microtime()` is exposed (the 0.6 s gate in `UtteranceSplitter`, log `ms`, `Interrupted`, the bench); a change of its own, and why WSL2 does it. Re-save the bench baseline (`composer bench:baseline` from master) now that #34 changed what `Started speaking` measures, on an idle machine.
- #31 (merged): whether the terminal of the run that crashed on 2026-10-05 still shows the error; Ctrl+C during a real call (is the bot out of the channel at once); commands registered too early (wait for `application-init` before `prepareCommandClasses()`, a PR of its own); an idle bench run.
- #35: what counts as "something new"; post the dropped answer; stop Claude; cut answer in the next prompt.
- #36: hold rows vs WAL vs a writer process (recommended: hold rows).
- #37: 0.3 s constant vs a variable; the NVIDIA power setting.
- #38: how a server picks a Kokoro voice; fallback to Piper when Kokoro fails.
- #42: a time slot when nothing else uses the card.

## Measured, do not retry (from the latency study, 2026-10-06)

Claude flags and env (`--effort`, `--bare`, warm-up turn, pre-sent prefix), sonnet for calls (+350 ms), Piper `--cuda`, Piper `high` voices as default (+0.4 to 0.7 s), semantic end-pointing from whisper punctuation, rolling partial transcriptions, streaming ASR sidecars, `VOICE_PAUSE_SECONDS` below 0.55 (splits sentences), end-of-turn models (Smart Turn, LiveKit), a bigger whisper model for "stop" heard as "top" (the "s" is missing from the audio Discord sends), PHP opcache/JIT (start-up only), greedy whisper decoding (changes words), Kokoro on onnxruntime, Supertonic, Pocket TTS. The remaining floor is the 0.6 s gate, Discord's ~240 ms hangover, and Claude's ~780 ms to a first sentence.
