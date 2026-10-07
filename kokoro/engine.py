"""Kokoro (82M, the `kokoro` package on PyTorch) speaking. The only file that imports torch, kokoro and numpy:
kokoro_serve.py takes the speaking from a module with these two functions, so that its tests need none of them.

    pick_device(requested) -> str   "cuda (<card's name>)" or "cpu"; requested is "cuda", "cpu" or None for the card when there is one
    load(voice, device)    -> object with .info (a dict for the READY line) and .speak(text) -> (pcm, first_ms),
                              pcm being 16-bit mono samples at 24 kHz as bytes, empty when the text has no sound
"""
import time

import numpy as np
import torch
from kokoro import KPipeline

# The first sentences a card or a processor speaks are slower, and that must not be somebody's answer: these are
# spoken before READY. A short, a middle and a long one, twice.
WARM_UP = [
    "Yes, that works for me.",
    "The meeting is tomorrow at three in the afternoon, so you have time to prepare.",
    "I looked at the weather for this weekend, and it should be mostly sunny on Saturday, with a light breeze, but Sunday may bring some rain in the late afternoon.",
]


def pick_device(requested):
    if requested is None:
        requested = "cuda" if torch.cuda.is_available() else "cpu"

    return f"cuda ({torch.cuda.get_device_name(0)})" if requested == "cuda" else "cpu"


def load(voice, device):
    return Speaker(voice, device)


class Speaker:
    def __init__(self, voice, device):
        self.voice = voice
        self.device = device
        # The voices starting with b are British, the others American.
        self.pipeline = KPipeline(lang_code="b" if voice.startswith("b") else "a", device=device, repo_id="hexgrad/Kokoro-82M")
        for sentence in WARM_UP * 2:
            self.speak(sentence)

        self.info = {"device": device, "torch": torch.__version__, "cuda": torch.version.cuda, "rss_mb": round(rss_mb(), 1)}

    def speak(self, text):
        started = time.perf_counter()
        first = None
        parts = []
        with torch.inference_mode():
            for _graphemes, _phonemes, audio in self.pipeline(text, voice=self.voice, speed=1.0):
                if first is None:
                    if self.device == "cuda":
                        torch.cuda.synchronize()
                    first = (time.perf_counter() - started) * 1000
                parts.append(audio.detach().cpu().numpy())

        if not parts:
            # No speakable phonemes ("...", "!"): kokoro_serve.py answers with silence.
            return b"", (time.perf_counter() - started) * 1000

        samples = np.clip(np.concatenate(parts), -1.0, 1.0)

        return (samples * 32767.0).astype("<i2").tobytes(), first


def rss_mb():
    for line in open("/proc/self/status"):
        if line.startswith("VmRSS:"):
            return int(line.split()[1]) / 1024.0

    return -1.0
