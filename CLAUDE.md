# CLAUDE.md

PHP 8.5 Discord bot (DiscordPHP + discord-php-helpers/voice, ReactPHP). It records voice calls, transcribes them with whisper.cpp, answers through the Claude Code CLI, speaks with Piper, keeps per-person and per-group memories, and looks things up in the background. One process, one event loop, no framework beyond `app/Application.php`.

User-facing behaviour is documented in `README.md` and `docs/*.md`. Do not restate it; read it first for any feature.

## Context files (read the one your task touches)

- `.claude/context/architecture.md`: call lifecycle, `VoiceSession` state, the one-turn queue, privacy seams, how Claude Code, Piper, the whisper server and the `Player` are run, error handling and exit codes, the main log messages and statistics (the full list is `docs/logs-and-statistics.md`), code conventions. Read before editing `app/`.
- `.claude/context/testing.md`: fixtures and their `FAKE_*` variables, `VoiceTestCase` helpers, command and DM test skeletons, `EventLoopCheck`, CI, timing traps, running the suite from Windows. Read before writing or running a test.
- `.claude/context/open-work.md`: the open PRs, what each branch holds, which files collide, the merge order, decisions waiting on Sky. Read before starting on a PR, merging master, or quoting a commit. Dated snapshot: check `gh pr list` when it is older than a day.

## Ground rules

- **Never block the event loop.** A frozen loop drops the voice connection. Run programs through `App\Support\Shell` (`run`, `stream`, `open`), never `exec()`, `shell_exec()`, `sleep()` or blocking reads. SQLite writes are the one accepted blocking call (PR #36, open, is about moving them). `GuardedLoop` only catches what a loop callback throws: handle failures in your own promise chains.
- **Logs and statistics hold IDs, counts, lengths and durations only.** Never what anyone said, what Claude answered, a memory, a prompt, a transcript, or a signed Discord URL. Exceptions quote stderr, never stdout (stdout can hold speech).
- **Privacy is re-checked at every async seam.** Opt-out, `/forget`, `/unshare`, `/privacy` and people joining can change between a question and its answer. Capture who was there when something was said, and check again before using a memory, speaking, posting or remembering. See the seams list in `architecture.md`.
- **One turn at a time per call.** Work on a call goes through `VoiceSession::inTurn()`. A turn never returns `stop()`'s promise (it is the queue itself: deadlock).
- **Arrow functions copy variables when created.** Use `function () use (&$x)` for anything that changes after the closure exists. This has bitten four separate PRs.
- **Coverage stays at 100% lines**, and `composer test:coverage -- --fail-on-skipped` is the CI command. A mutation run over changed lines is expected for feature PRs (see `testing.md`).
- **Docs move with the code.** New behaviour, slash commands and variables go in `README.md` or `docs/<topic>.md`, variables also in `.env-local`, log lines in `docs/logs-and-statistics.md`.
- **Match the surrounding style:** `declare(strict_types=1)`, `final` classes, typed constants, readonly promoted properties, comments as full sentences that say why. `composer pint` fixes formatting; CI runs `pint --test`.

## Commands

```bash
composer test                          # Unit + Feature (needs Linux: WSL on this machine)
composer test:coverage -- --fail-on-skipped   # what CI runs
composer test -- --testsuite Unit      # or Feature; Live and Bench are opt-in; Real exists only on PR #26
composer pint                          # fix style; `composer pint -- --test` to check
composer serve                         # run the bot (needs .env, PHP 8.5 with ffi)
```

## Working on a PR here

Every feature PR is a brief: a description with `## Why`, `## What it does` (checkboxes), `## Tests and docs`, `## Where in the code`, `## Depends on`, `## How to work on this PR`. Tick boxes as you go, write decisions and measurements into the description, merge `master` into the branch (never rebase or force-push), and mark it ready only when every box is ticked or struck. Drafts get no CI: run the CI command locally and dispatch `live-voice.yml` by hand. Open questions go into the description, not into a hold-up; once CI is green on the head commit Sky wants it merged with `--merge`. Details and the finish checklist: `open-work.md`.
