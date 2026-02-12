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

Preferred: run via Composer scripts from this directory (`/Users/matt/bin/transcribe`).

- Run once:
  - `composer run run-once`
- Run once silently (for LaunchAgent/automation):
  - `composer run run-once:quiet`
- Daemon loop:
  - `composer run daemon`
- Status:
  - `composer run pipeline-status`
- Run tests:
  - `composer run test`

Direct CLI remains available:

- `php voice-pipeline.php process-file "/absolute/path/to/file.m4a"`
- `php voice-pipeline.php retranscribe-vault "/path/to/vault/subdir-or-note.md"`
- `php voice-pipeline.php test-file "/path/to/file.m4a" /tmp/transcribe-tests`

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
