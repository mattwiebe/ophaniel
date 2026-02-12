# Ophaniel

Automatic local transcription pipeline for voice notes -> Markdown notes in Obsidian.

Any time a new audio file appears, it is automatically transcribed and moved to your Obsidian vault (or any target directory on your filesystem).

- Transcription backend: Lightning Whisper MLX (`distil-large-v3`)
- Metadata backend: OpenAI-compatible LM Studio API via `rmccue/requests`
- Audio input: direct `.m4a` (no WAV pre-conversion step in the PHP pipeline)

## Requirements

- macOS (Apple Silicon recommended)
- PHP 8.3+
- `ffmpeg` and `ffprobe` in `PATH`
- [`uv`](https://github.com/astral-sh/uv)
- LM Studio app + `lms` CLI with the metadata model available
- Composer
- Python is only needed through `uv run` for the Lightning Whisper script

Install PHP dependencies:

- `composer install`

## Configuration

Configuration is loaded from `config.ini` (default path: same directory as `voice-pipeline.php`).

1. Copy:
   - `cp config.ini.example config.ini`
2. Edit required paths:
   - `SOURCE_DIRECTORY`
   - `TARGET_DIRECTORY`

Optional path behavior:

- `TARGET_AUDIO_DIRECTORY` is optional:
  - If blank/missing, defaults to `TARGET_DIRECTORY/audio`
- `LEGACY_PROCESSED_FILE` is optional:
  - If blank/missing, defaults to `SOURCE_DIRECTORY/.processed`

Environment variables override `config.ini` keys one-for-one.
Example:

- `SOURCE_DIRECTORY=/some/other/path php voice-pipeline.php run-once`
- `LLM_MODEL=lmstudio/another-model php voice-pipeline.php test-llm /tmp/input.txt`

If you want a different config file location:

- `VOICE_PIPELINE_CONFIG=/path/to/config.ini php voice-pipeline.php run-once`

## Commands

Run from this directory (`/Users/matt/bin/transcribe`) or call with absolute paths.

- Run once:
  - `php voice-pipeline.php run-once`
- Run once silently (for LaunchAgent/automation):
  - `php voice-pipeline.php run-once --quiet`
- Daemon loop:
  - `php voice-pipeline.php daemon 30`
- Process one file:
  - `php voice-pipeline.php process-file "/absolute/path/to/file.m4a"`
- Re-transcribe existing vault notes in place:
  - `php voice-pipeline.php retranscribe-vault "/path/to/vault/subdir-or-note.md"`
- Status:
  - `php voice-pipeline.php status`
- No-copy test transcription:
  - `php voice-pipeline.php test-file "/path/to/file.m4a" /tmp/transcribe-tests`

## Commit Prompt Hook

This repo includes tracked Git hooks that append your prompt to commit messages under:

```text
PROMPT:
...
```

Enable hooks (one-time per clone):

- `git config core.hooksPath .githooks`

Commit with prompt context:

- `OPHANIEL_PROMPT="retranscribe january notes and tune paragraphing" git commit -m "Improve note formatting"`

Alternative prompt source:

- Put prompt text in `.git/OPHANIEL_PROMPT` before committing.

Safety checks:

- Commit is blocked if the prompt looks like a secret or clearly abusive language.
- Override only when intentional:
  - `OPHANIEL_ALLOW_RISKY_PROMPT=1 git commit ...`

## Behavior Notes

- Sequential processing (one file at a time)
- Lock file prevents overlapping runs
- JSON state tracks processed files by path + mtime + size
- Progress output includes `n/total` for `run-once` and `retranscribe-vault`
- `--quiet` suppresses CLI progress output
- Transcripts get heuristic paragraphization + repetition cleanup
- Title + summary come from one LLM call (JSON response)

## LaunchAgent

Use `--quiet` in `ProgramArguments` for production background runs.

Example:

```xml
<array>
  <string>/opt/homebrew/bin/php</string>
  <string>/Users/matt/bin/transcribe/voice-pipeline.php</string>
  <string>run-once</string>
  <string>--quiet</string>
</array>
```
