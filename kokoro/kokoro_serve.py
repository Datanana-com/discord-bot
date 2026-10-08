"""Kokoro kept running for a whole call, one sentence per line of stdin, started the way the bot starts Piper:

    kokoro --model <folder>/voices/af_heart.onnx --output-dir <folder>

Each line in gets a WAV file in the output folder (24 kHz, mono, 16 bits) and `INFO:__main__:Wrote <path>` on stderr,
which is what Speech.php waits for. Lines are answered in the order they came, one "Wrote" for each, also for a line
that has nothing to say (an empty line, or only punctuation): that one gets 0.1 s of silence, so that the bot's
bookkeeping, one line in and one "Wrote" out, cannot slip by one sentence. It ends when its stdin is closed.

--model is the voice: only the file's name counts (af_heart.onnx is the voice af_heart), because the bot lists a
server's voices by the .onnx files next to PIPER_MODEL. See install.sh, which makes one empty file for each voice.

Stderr, besides the "Wrote" lines (the bot keeps every other line as the reason a run failed, so there is little):
    DEVICE cuda (NVIDIA GeForce RTX 3080)   first, before the model loads; cpu when there is no card
    READY {...}                             once the model is loaded and warm
    FIRST <ms> AUDIO <s>                    before each "Wrote": ms to the first audio, seconds of audio
READY and FIRST are left out when TTS_QUIET is set. Nothing is written to stdout.

The speaking itself is in engine.py (torch and kokoro are only imported there). KOKORO_ENGINE names another module for
it: the tests give it one that needs neither.

The model's files are read from hf/ next to this file, never from the internet: a missing file is an error at once,
not a wait for the network."""
import argparse
import importlib
import json
import os
import sys
import wave

HERE = os.path.dirname(os.path.abspath(__file__))
# Before anything imports huggingface_hub: the model lives in this folder.
os.environ.setdefault("HF_HOME", os.path.join(HERE, "hf"))
os.environ.setdefault("HF_HUB_OFFLINE", "1")
sys.path.insert(0, HERE)

RATE = 24000
# What a line with nothing to say gets: 0.1 s of silence as 16-bit samples.
SILENCE = bytes(2 * RATE // 10)


def write_wav(path, pcm):
    with wave.open(path, "wb") as wav:
        wav.setnchannels(1)
        wav.setsampwidth(2)
        wav.setframerate(RATE)
        wav.writeframes(pcm)


def say(message):
    print(message, file=sys.stderr, flush=True)


def main():
    parser = argparse.ArgumentParser()
    # --model: so that it can sit behind PIPER_BINARY and PIPER_MODEL.
    parser.add_argument("--voice", "--model", dest="voice", default="af_heart")
    parser.add_argument("--device", default=None, choices=["cuda", "cpu"])
    parser.add_argument("--output-dir", required=True)
    args = parser.parse_args()
    voice = os.path.basename(args.voice)
    voice = voice[:-5] if voice.endswith(".onnx") else voice

    engine = importlib.import_module(os.environ.get("KOKORO_ENGINE", "engine"))
    device = engine.pick_device(args.device)
    say(f"DEVICE {device}")
    speaker = engine.load(voice, device.split(" ")[0])

    quiet = bool(os.environ.get("TTS_QUIET"))
    os.makedirs(args.output_dir, exist_ok=True)
    if not quiet:
        say("READY " + json.dumps(speaker.info))

    for number, line in enumerate(sys.stdin, start=1):
        text = line.strip()
        pcm, first_ms = speaker.speak(text) if text else (b"", 0.0)
        path = os.path.join(args.output_dir, f"{number}.wav")
        write_wav(path, pcm or SILENCE)
        if not quiet:
            say(f"FIRST {first_ms:.1f} AUDIO {len(pcm or SILENCE) / 2 / RATE:.3f}")
        say(f"INFO:__main__:Wrote {path}")


if __name__ == "__main__":
    main()
