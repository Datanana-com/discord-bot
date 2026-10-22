"""Stands in for kokoro/engine.py, so that kokoro_serve.py runs without torch, the model or a card (KOKORO_ENGINE names it).

It says "cpu" unless a device is asked for (or FAKE_KOKORO_DEVICE says which) (and then "cuda (Stand-in card)", as the real one names the card), speaks
0.1 s of a 440 Hz tone for each word, has nothing to say for a line of only punctuation, and fails on an empty line,
which kokoro_serve.py answers itself, and on a line that contains FAIL, as a voice fails on something it can't speak.
FAKE_KOKORO_LOG, when set, is the file it appends the voice and device it was loaded with to, and where the model
would be read from: the program must keep it from the network."""
import math
import os
import struct
import time

RATE = 24000


def pick_device(requested):
    if os.environ.get("FAKE_KOKORO_DEVICE"):
        return os.environ["FAKE_KOKORO_DEVICE"]

    return "cuda (Stand-in card)" if requested == "cuda" else "cpu"


def load(voice, device):
    if os.environ.get("FAKE_KOKORO_LOG"):
        with open(os.environ["FAKE_KOKORO_LOG"], "a") as log:
            log.write(f"voice={voice} device={device} offline={os.environ.get('HF_HUB_OFFLINE')} home={os.environ.get('HF_HOME')}\n")

    # The seconds the model takes to load, as the real one does.
    time.sleep(float(os.environ.get("FAKE_KOKORO_LOAD", "0")))

    return Speaker(voice, device)


class Speaker:
    def __init__(self, voice, device):
        self.info = {"voice": voice, "device": device}

    def speak(self, text):
        time.sleep(float(os.environ.get("FAKE_KOKORO_DELAY", "0")))

        if not text:
            raise RuntimeError("An empty line is not for the voice.")

        if "FAIL" in text:
            raise RuntimeError("The voice could not speak that.")

        words = [word for word in text.split() if any(character.isalnum() for character in word)]
        samples = int(0.1 * RATE) * len(words)

        return b"".join(struct.pack("<h", int(8000 * math.sin(2 * math.pi * 440 * i / RATE))) for i in range(samples)), 12.5
