#!/usr/bin/env bash
# Installs Kokoro (82M, PyTorch) and its voices into the folder this script is in, and nothing outside it: the
# virtual environment (venv/), the model's files (hf/) and one empty file for each voice (voices/<name>.onnx, see
# below) are all there, so that removing those three folders removes everything. They are in .gitignore.
#
#   bash kokoro/install.sh [voice ...]      af_heart, the voice the bot was made for, and any others you name
#
# Needs python3 with venv (Ubuntu: python3-venv), internet for this one run, about 7 GB free and, for this PyTorch,
# a graphics card driver 580 or newer (see docs/voice-calls.md); without a card Kokoro runs on the processor.
# The versions are those in requirements.txt (a `pip freeze`). Set PIP_NO_CACHE_DIR=1 to keep pip's cache in
# ~/.cache/pip from growing by 3 GB.
set -euo pipefail
cd "$(dirname "$(readlink -f "$0")")"

# af_heart always: model.sha256 checks it.
mapfile -t voices < <(printf '%s\n' af_heart "$@" | sort -u)

python3 -m venv venv
venv/bin/pip install --quiet --disable-pip-version-check -r requirements.txt </dev/null

# The model and the voices, fetched once into hf/: the program that speaks reads them from there, offline.
HF_HOME=$PWD/hf venv/bin/python -c '
import sys
from huggingface_hub import hf_hub_download
for name in ["config.json", "kokoro-v1_0.pth"] + [f"voices/{voice}.pt" for voice in sys.argv[1:]]:
    print(hf_hub_download("hexgrad/Kokoro-82M", name))
' "${voices[@]}" </dev/null

# What was fetched must be what was measured: this fails when the files on the hub changed. Only af_heart is in
# the list.
sha256sum --check model.sha256

# The bot lists a server's voices by the .onnx files next to PIPER_MODEL, and refuses to start a call when
# PIPER_MODEL is not a file. Kokoro keeps its voices elsewhere, so each voice is a name here: an empty file whose
# name is all the program reads.
mkdir -p voices
for voice in "${voices[@]}"; do
    : > "voices/${voice}.onnx"
done

echo "kokoro: installed in $PWD. In .env:"
echo "  PIPER_BINARY=$PWD/kokoro"
echo "  PIPER_MODEL=$PWD/voices/af_heart.onnx"
