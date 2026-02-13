#!/opt/homebrew/bin/php
<?php

declare(strict_types=1);

/**
 * Robust local voice-note pipeline for Just Press Record -> Obsidian.
 *
 * Usage:
 *   /Users/matt/bin/transcribe/ophaniel.php ingest [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php daemon [seconds] [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php process-file /absolute/path/to/file.m4a [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php retranscribe-vault [/absolute/path1 [/absolute/path2 ...]] [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php repair-note-dates [/absolute/path1 [/absolute/path2 ...]] [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php retime-note-filenames [/absolute/path1 [/absolute/path2 ...]] [--dry-run] [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php refresh-heuristic-notes [/absolute/path1 [/absolute/path2 ...]] [--dry-run] [--only-metadata] [--quiet|-q]
 *   /Users/matt/bin/transcribe/ophaniel.php timing-report [audio_seconds]
 *   /Users/matt/bin/transcribe/ophaniel.php test-file /absolute/path/to/file.m4a [/tmp/outdir] [offset_ms] [duration_ms]
 *   /Users/matt/bin/transcribe/ophaniel.php test-llm /absolute/path/to/textfile
 *   /Users/matt/bin/transcribe/ophaniel.php status
 */

$bootConfig = load_ini_config();
@chdir(__DIR__);

$defaultSourceDir = '/Users/matt/Library/Mobile Documents/iCloud~com~openplanetsoftware~just-press-record/Documents';
$defaultTargetDir = '/Users/matt/Library/Mobile Documents/iCloud~md~obsidian/Documents/Matthew Brain/Voice Memos';
$sourceDir = cfg_string($bootConfig, 'SOURCE_DIRECTORY', $defaultSourceDir);
$targetDir = cfg_string($bootConfig, 'TARGET_DIRECTORY', $defaultTargetDir);
$targetAudioDir = cfg_string($bootConfig, 'TARGET_AUDIO_DIRECTORY', '');
if ($targetAudioDir === '') {
    $targetAudioDir = $targetDir . '/audio';
}

define('SOURCE_DIRECTORY', $sourceDir);
define('TARGET_DIRECTORY', $targetDir);
define('TARGET_AUDIO_DIRECTORY', $targetAudioDir);

define('STATE_FILE', cfg_string($bootConfig, 'STATE_FILE', __DIR__ . '/.ophaniel-state.json'));
define('LOCK_FILE', cfg_string($bootConfig, 'LOCK_FILE', __DIR__ . '/.ophaniel.lock'));
define('LOG_FILE', cfg_string($bootConfig, 'LOG_FILE', __DIR__ . '/ophaniel.log'));
define('LEGACY_PROCESSED_FILE', cfg_string($bootConfig, 'LEGACY_PROCESSED_FILE', SOURCE_DIRECTORY . '/.processed'));

define('LIGHTNING_UV_COMMAND', cfg_string($bootConfig, 'LIGHTNING_UV_COMMAND', '/opt/homebrew/bin/uv'));
define('LIGHTNING_SCRIPT', cfg_string($bootConfig, 'LIGHTNING_SCRIPT', __DIR__ . '/lightning_whisper_test.py'));
define('LIGHTNING_MODEL', cfg_string($bootConfig, 'LIGHTNING_MODEL', 'distil-large-v3'));
define('LIGHTNING_BATCH_SIZE', cfg_int($bootConfig, 'LIGHTNING_BATCH_SIZE', 1));
define('LIGHTNING_LANGUAGE', cfg_string($bootConfig, 'LIGHTNING_LANGUAGE', 'en'));

define('FILE_SETTLE_SECONDS', cfg_int($bootConfig, 'FILE_SETTLE_SECONDS', 20));
define('MAX_FILES_PER_RUN', max(1, cfg_int($bootConfig, 'MAX_FILES_PER_RUN', 3)));
define('WATERMARK_STALE_SECONDS', max(300, cfg_int($bootConfig, 'WATERMARK_STALE_SECONDS', 86400)));
define('ERROR_RETRY_SECONDS', max(60, cfg_int($bootConfig, 'ERROR_RETRY_SECONDS', 3600)));
define('ERROR_MAX_ATTEMPTS', max(1, cfg_int($bootConfig, 'ERROR_MAX_ATTEMPTS', 3)));
define('REPROCESS_ON_SOURCE_CHANGE', cfg_bool($bootConfig, 'REPROCESS_ON_SOURCE_CHANGE', false));
define('TEST_OUTPUT_ROOT', cfg_string($bootConfig, 'TEST_OUTPUT_ROOT', '/tmp/ophaniel-tests'));

define('ENABLE_LLM_SUMMARY', cfg_bool($bootConfig, 'ENABLE_LLM_SUMMARY', true));
define('LLM_MODEL', cfg_string($bootConfig, 'LLM_MODEL', 'lmstudio/qwen3-4b-instruct-2507-mlx'));
define('LMS_COMMAND', cfg_string($bootConfig, 'LMS_COMMAND', '/Users/matt/.cache/lm-studio/bin/lms'));
define('LMSTUDIO_BASE_URL', cfg_string($bootConfig, 'LMSTUDIO_BASE_URL', 'http://127.0.0.1:1234/v1'));
define('LMSTUDIO_API_KEY', cfg_string($bootConfig, 'LMSTUDIO_API_KEY', 'lm-studio'));
define('LMSTUDIO_TIMEOUT_SECONDS', max(10, cfg_int($bootConfig, 'LMSTUDIO_TIMEOUT_SECONDS', 120)));
define('LMS_COMMAND_TIMEOUT_SECONDS', max(5, cfg_int($bootConfig, 'LMS_COMMAND_TIMEOUT_SECONDS', 25)));
define('LLM_METADATA_MAX_CHARS', max(1000, cfg_int($bootConfig, 'LLM_METADATA_MAX_CHARS', 8000)));
define('LLM_METADATA_CONTEXT_FRACTION', min(0.95, max(0.1, (float)cfg_raw($bootConfig, 'LLM_METADATA_CONTEXT_FRACTION') ?: 0.75)));
define('LLM_METADATA_CHARS_PER_TOKEN', min(6.0, max(1.0, (float)cfg_raw($bootConfig, 'LLM_METADATA_CHARS_PER_TOKEN') ?: 3.0)));
define('FFPROBE_COMMAND', cfg_string($bootConfig, 'FFPROBE_COMMAND', '/opt/homebrew/bin/ffprobe'));
define('FFMPEG_COMMAND', cfg_string($bootConfig, 'FFMPEG_COMMAND', '/opt/homebrew/bin/ffmpeg'));
define('HYDRATE_WAIT_SECONDS', max(1, cfg_int($bootConfig, 'HYDRATE_WAIT_SECONDS', 20)));
define('HYDRATE_POLL_MS', max(100, cfg_int($bootConfig, 'HYDRATE_POLL_MS', 500)));

if (!defined('OPHANIEL_DISABLE_MAIN') && should_run_main()) {
    main($argv);
}

function should_run_main(): bool {
    if (PHP_SAPI !== 'cli') {
        return false;
    }
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!is_string($script) || $script === '') {
        return false;
    }
    return realpath($script) === __FILE__;
}

function main(array $argv): void {
    date_default_timezone_set(trim((string) shell_exec("/bin/ls -l /etc/localtime | /usr/bin/cut -d '/' -f 8,9")) ?: date_default_timezone_get());
    ensure_runtime_environment();

    $args = cli_args_without_flags($argv);
    $cmd = $args[0] ?? 'ingest';

    if ($cmd === 'test-file') {
        $file = trim((string)($args[1] ?? ''));
        if ($file === '' || !is_file($file)) {
            fwrite(STDERR, "test-file requires a valid path\n");
            exit(2);
        }
        $outDir = trim((string)($args[2] ?? TEST_OUTPUT_ROOT));
        $offsetMs = max(0, (int)($args[3] ?? 0));
        $durationMs = max(0, (int)($args[4] ?? 0));
        run_test_file($file, $outDir, $offsetMs, $durationMs);
        return;
    }
    if ($cmd === 'test-llm') {
        ensure_llm_metadata_preflight();
        $file = trim((string)($args[1] ?? ''));
        if ($file === '' || !is_file($file)) {
            fwrite(STDERR, "test-llm requires a valid text file path\n");
            exit(2);
        }
        run_test_llm($file);
        return;
    }
    if ($cmd === 'timing-report') {
        $audioSeconds = max(0.0, (float)($args[1] ?? 0));
        print_timing_report($audioSeconds);
        return;
    }

    with_lock(function () use ($cmd, $args): void {
        ensure_directories();
        if (in_array($cmd, ['ingest', 'daemon', 'process-file', 'retranscribe-vault'], true)
            || ($cmd === 'refresh-heuristic-notes' && !cli_is_dry_run())) {
            ensure_llm_metadata_preflight();
        }
        $state = load_state();

        if ($cmd === 'ingest') {
            run_once($state);
            save_state($state);
            return;
        }

        if ($cmd === 'daemon') {
            $interval = max(5, (int)($args[1] ?? 30));
            log_line('daemon_start', ['interval_seconds' => $interval]);
            while (true) {
                run_once($state);
                save_state($state);
                sleep($interval);
            }
        }

        if ($cmd === 'process-file') {
            $file = trim((string)($args[1] ?? ''));
            if ($file === '' || !is_file($file)) {
                fwrite(STDERR, "process-file requires a valid path\n");
                exit(2);
            }
            $result = process_candidate($file, $state);
            if ($result['status'] === 'processed_ok') {
                cli_out('processed: ' . $file);
            } elseif ($result['status'] === 'processed_error') {
                cli_out('error: ' . ($result['error'] ?? 'unknown'));
            } else {
                cli_out('skipped: ' . ($result['status'] ?? 'unknown'));
            }
            save_state($state);
            return;
        }

        if ($cmd === 'retranscribe-vault') {
            $paths = retranscribe_paths_from_args($args);
            $pathErrors = [];
            foreach ($paths as $path) {
                try {
                    retranscribe_vault($path, $state);
                } catch (Throwable $e) {
                    $pathErrors[] = ['path' => $path, 'error' => $e->getMessage()];
                    log_line('retranscribe_path_error', ['path' => $path, 'error' => $e->getMessage()]);
                    cli_out("Retranscribe path error: {$path} ({$e->getMessage()})");
                }
            }
            save_state($state);
            if ($pathErrors !== []) {
                throw new RuntimeException('retranscribe_failed_paths=' . count($pathErrors));
            }
            return;
        }

        if ($cmd === 'repair-note-dates') {
            $paths = retranscribe_paths_from_args($args);
            $pathErrors = [];
            foreach ($paths as $path) {
                try {
                    repair_note_dates($path);
                } catch (Throwable $e) {
                    $pathErrors[] = ['path' => $path, 'error' => $e->getMessage()];
                    log_line('repair_note_dates_path_error', ['path' => $path, 'error' => $e->getMessage()]);
                    cli_out("Repair date path error: {$path} ({$e->getMessage()})");
                }
            }
            if ($pathErrors !== []) {
                throw new RuntimeException('repair_note_dates_failed_paths=' . count($pathErrors));
            }
            return;
        }

        if ($cmd === 'retime-note-filenames') {
            $paths = retranscribe_paths_from_args($args);
            $pathErrors = [];
            foreach ($paths as $path) {
                try {
                    retime_note_filenames($path, cli_is_dry_run());
                } catch (Throwable $e) {
                    $pathErrors[] = ['path' => $path, 'error' => $e->getMessage()];
                    log_line('retime_note_filenames_path_error', ['path' => $path, 'error' => $e->getMessage()]);
                    cli_out("Retime path error: {$path} ({$e->getMessage()})");
                }
            }
            if ($pathErrors !== []) {
                throw new RuntimeException('retime_note_filenames_failed_paths=' . count($pathErrors));
            }
            return;
        }

        if ($cmd === 'refresh-heuristic-notes') {
            $paths = retranscribe_paths_from_args($args);
            $pathErrors = [];
            foreach ($paths as $path) {
                try {
                    refresh_heuristic_notes($path, $state, cli_is_dry_run(), cli_is_only_metadata());
                } catch (Throwable $e) {
                    $pathErrors[] = ['path' => $path, 'error' => $e->getMessage()];
                    log_line('refresh_heuristic_notes_path_error', ['path' => $path, 'error' => $e->getMessage()]);
                    cli_out("Heuristic refresh path error: {$path} ({$e->getMessage()})");
                }
            }
            save_state($state);
            if ($pathErrors !== []) {
                throw new RuntimeException('refresh_heuristic_notes_failed_paths=' . count($pathErrors));
            }
            return;
        }

        if ($cmd === 'status') {
            print_status($state);
            return;
        }

        fwrite(STDERR, "Unknown command: {$cmd}\n");
        exit(2);
    });
}

function ensure_directories(): void {
    foreach ([TARGET_DIRECTORY, TARGET_AUDIO_DIRECTORY] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("failed_to_create_directory: {$dir}");
        }
    }
}

function run_once(array &$state): void {
    $files = discover_m4a_files(SOURCE_DIRECTORY);
    log_line('run_once_start', ['source_files' => count($files), 'max_files_per_run' => MAX_FILES_PER_RUN]);
    if ($files === []) {
        cli_out('No source audio files found.');
        return;
    }

    $pending = [];
    foreach ($files as $file) {
        $check = evaluate_candidate($file, $state);
        if ($check['eligible']) {
            $meta = $check['meta'] ?? [];
            $sortTs = (int)($meta['capture_ts'] ?? 0);
            if ($sortTs <= 0) {
                $sortTs = (int)($meta['birthtime'] ?? 0);
            }
            $pending[] = [
                'file' => $file,
                'sort_ts' => $sortTs,
            ];
        }
    }

    $eligibleTotal = count($pending);
    if ($eligibleTotal === 0) {
        cli_out('No new settled files to process.');
        $state['watermark_mtime'] = max((int)($state['watermark_mtime'] ?? 0), time());
        return;
    }

    usort($pending, static function (array $a, array $b): int {
        return $b['sort_ts'] <=> $a['sort_ts'];
    });
    $pending = array_slice($pending, 0, MAX_FILES_PER_RUN);
    $total = count($pending);

    cli_out("Processing {$total} file(s) (eligible={$eligibleTotal}, max_per_run=" . MAX_FILES_PER_RUN . ")");
    $ok = 0;
    $err = 0;
    $skipped = 0;
    $statusCounts = [];
    foreach ($pending as $idx => $item) {
        $file = $item['file'];
        $n = $idx + 1;
        cli_out(sprintf("[%d/%d] processing %s", $n, $total, basename($file)));
        $result = process_candidate($file, $state);
        $status = (string)($result['status'] ?? 'unknown');
        $statusCounts[$status] = (int)($statusCounts[$status] ?? 0) + 1;
        if ($result['status'] === 'processed_ok') {
            $ok++;
            cli_out(sprintf("[%d/%d] ok", $n, $total));
            continue;
        }

        if ($result['status'] === 'processed_error') {
            $err++;
            cli_out(sprintf("[%d/%d] error: %s", $n, $total, $result['error'] ?? 'unknown'));
            continue;
        }

        $skipped++;
        cli_out(sprintf("[%d/%d] skipped: %s", $n, $total, $result['status'] ?? 'unknown'));
    }
    cli_out("Run complete: ok={$ok} error={$err}");
    log_line('run_once_complete', [
        'source_files' => count($files),
        'eligible' => $eligibleTotal,
        'attempted' => $total,
        'processed_ok' => $ok,
        'processed_error' => $err,
        'skipped' => $skipped,
        'status_counts' => $statusCounts,
    ]);

    $state['watermark_mtime'] = max((int)($state['watermark_mtime'] ?? 0), time());
}

function discover_m4a_files(string $root): array {
    if (!is_dir($root)) {
        return [];
    }

    $result = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iter as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }
        if (strtolower($fileInfo->getExtension()) !== 'm4a') {
            continue;
        }
        $result[] = $fileInfo->getPathname();
    }

    sort($result, SORT_STRING);
    return $result;
}

function evaluate_candidate(string $file, array $state): array {
    clearstatcache(true, $file);
    if (!is_file($file)) {
        return ['eligible' => false, 'status' => 'skipped_missing'];
    }

    $meta = file_meta($file);
    $watermark = (int)($state['watermark_mtime'] ?? 0);
    $fileMtime = (int)($meta['mtime'] ?? 0);
    $candidateTs = candidate_timestamp_for_queue($meta);
    $isStaleByWatermark = ($candidateTs > 0) && (($watermark - $candidateTs) >= WATERMARK_STALE_SECONDS);
    if (!isset($state['processed'][$file]) && $watermark > 0 && $fileMtime > 0 && $fileMtime <= $watermark && $isStaleByWatermark) {
        return ['eligible' => false, 'status' => 'skipped_watermark', 'meta' => $meta];
    }

    if (!is_file_settled($file, $meta)) {
        return ['eligible' => false, 'status' => 'skipped_unsettled', 'meta' => $meta];
    }
    if (already_processed($file, $meta, $state)) {
        return ['eligible' => false, 'status' => 'skipped_already_processed', 'meta' => $meta];
    }
    if (recent_error_backoff($file, $meta, $state)) {
        return ['eligible' => false, 'status' => 'skipped_recent_error', 'meta' => $meta];
    }

    return ['eligible' => true, 'status' => 'eligible', 'meta' => $meta];
}

function process_candidate(string $file, array &$state): array {
    $candidate = evaluate_candidate($file, $state);
    if (!$candidate['eligible']) {
        return ['status' => $candidate['status']];
    }

    $meta = $candidate['meta'];
    $startTs = microtime(true);
    if (!ensure_source_audio_available($file)) {
        return ['status' => 'skipped_unavailable'];
    }
    try {
        $output = process_file($file, $meta);
        $backend = selected_transcribe_backend();
        $state['processed'][$file] = [
            'mtime' => $meta['mtime'],
            'size' => $meta['size'],
            'processed_at' => date('c'),
            'output_md' => $output['md_file'],
            'output_audio' => $output['audio_file'],
            'engine' => $backend,
        ];
        $elapsed = round(microtime(true) - $startTs, 3);
        log_line('processed_ok', [
            'source' => $file,
            'md_file' => $output['md_file'],
            'elapsed_s' => $elapsed,
            'duration_s' => $output['duration_s'],
            'ratio' => $output['duration_s'] > 0 ? round($elapsed / $output['duration_s'], 4) : null,
            'dedupe_removed_tokens' => $output['dedupe_removed_tokens'],
        ]);
        return ['status' => 'processed_ok', 'output' => $output];
    } catch (Throwable $e) {
        $state['errors'][$file] = [
            'mtime' => $meta['mtime'],
            'size' => $meta['size'],
            'birthtime' => (int)($meta['birthtime'] ?? 0),
            'failed_at' => date('c'),
            'error' => $e->getMessage(),
            'attempts' => (int)(($state['errors'][$file]['attempts'] ?? 0)) + 1,
        ];
        log_line('processed_error', ['source' => $file, 'error' => $e->getMessage()]);
        return ['status' => 'processed_error', 'error' => $e->getMessage()];
    }
}

function process_file(string $file, ?array $meta = null): array {
    $duration = audio_duration_seconds($file);
    $audioStartTime = estimate_audio_start_time($file, $meta, $duration);

    $targetBase = base_name_from_timestamp($audioStartTime);
    [$targetBase, $targetAudioFile] = unique_audio_name($targetBase);

    $outputBase = TARGET_DIRECTORY . '/' . $targetBase;
    run_transcription_backend($file, $outputBase);

    $txtFile = $outputBase . '.txt';
    if (!is_file($txtFile)) {
        throw new RuntimeException("transcript_missing: {$txtFile}");
    }

    $textContent = (string)file_get_contents($txtFile);
    $textContent = normalize_text($textContent);

    $dedupeInfo = dedupe_repetition($textContent);
    $textContent = $dedupeInfo['text'];

    $llmMeta = build_llm_metadata($textContent, $audioStartTime);
    $title = $llmMeta['title'];
    $summary = $llmMeta['summary'];

    $mdBody = '';
    if ($summary !== '') {
        $mdBody .= $summary . "\n\n-----\n\n";
    }
    $copyErr = '';
    $copyOk = copy_with_retry($file, $targetAudioFile, $copyErr);
    if ($copyOk) {
        $mdBody .= '![[audio/' . basename($targetAudioFile) . "]]\n\n";
    } else {
        $mdBody .= "_Audio copy deferred: source temporarily unavailable for read._\n\n";
        log_line('audio_copy_deferred', [
            'source' => $file,
            'target_audio' => $targetAudioFile,
            'error' => $copyErr !== '' ? $copyErr : 'unknown_copy_error',
        ]);
        @unlink($targetAudioFile);
    }
    $mdBody .= $textContent . "\n";

    $mdFile = build_md_path($title, $audioStartTime);
    $mdDir = dirname($mdFile);
    if (!is_dir($mdDir) && !mkdir($mdDir, 0755, true) && !is_dir($mdDir)) {
        throw new RuntimeException("mkdir_failed: {$mdDir}");
    }

    file_put_contents($mdFile, $mdBody);
    @unlink($txtFile);

    return [
        'md_file' => $mdFile,
        'audio_file' => $copyOk ? $targetAudioFile : '',
        'duration_s' => $duration,
        'dedupe_removed_tokens' => $dedupeInfo['removed_tokens'],
    ];
}

function run_test_file(string $file, string $outputRoot, int $offsetMs = 0, int $durationMs = 0): void {
    if (!is_dir($outputRoot) && !mkdir($outputRoot, 0755, true) && !is_dir($outputRoot)) {
        throw new RuntimeException("test_output_mkdir_failed: {$outputRoot}");
    }

    $duration = audio_duration_seconds($file);
    $runId = date('Ymd-His') . '-' . getmypid();
    $stem = sanitize_filename(pathinfo($file, PATHINFO_FILENAME));
    if ($stem === '') {
        $stem = 'input';
    }
    $runDir = rtrim($outputRoot, '/') . '/' . $runId . '-' . $stem;
    if (!mkdir($runDir, 0755, true) && !is_dir($runDir)) {
        throw new RuntimeException("test_run_mkdir_failed: {$runDir}");
    }

    $outputBase = $runDir . '/transcript';
    run_transcription_backend($file, $outputBase, $offsetMs, $durationMs);

    $txtFile = $outputBase . '.txt';
    if (!is_file($txtFile)) {
        throw new RuntimeException("transcript_missing: {$txtFile}");
    }

    $raw = trim((string)file_get_contents($txtFile));
    $normalized = normalize_text($raw);
    $dedupeInfo = dedupe_repetition($normalized);
    $clean = $dedupeInfo['text'];

    file_put_contents($runDir . '/raw.txt', $raw . "\n");
    file_put_contents($runDir . '/normalized.txt', $normalized . "\n");
    file_put_contents($runDir . '/deduped.txt', $clean . "\n");

    $metrics = [
        'source_file' => $file,
        'duration_s' => $duration,
        'raw_words' => word_count($raw),
        'normalized_words' => word_count($normalized),
        'deduped_words' => word_count($clean),
        'dedupe_removed_tokens' => $dedupeInfo['removed_tokens'],
        'run_dir' => $runDir,
        'offset_ms' => $offsetMs,
        'duration_ms' => $durationMs,
        'backend' => selected_transcribe_backend(),
    ];

    file_put_contents(
        $runDir . '/metrics.json',
        json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
    );

    echo json_encode($metrics, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function run_test_llm(string $textFile): void {
    $text = trim((string)file_get_contents($textFile));
    if ($text === '') {
        throw new RuntimeException("test_llm_empty_input: {$textFile}");
    }

    $llmMeta = build_llm_metadata($text, time());
    $title = $llmMeta['title'];
    $summary = $llmMeta['summary'];
    $payload = [
        'text_file' => $textFile,
        'title' => $title,
        'summary' => $summary,
        'model' => LLM_MODEL,
    ];
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
}

function retranscribe_vault(string $path, array &$state): void {
    $notes = discover_markdown_notes($path);
    if ($notes === []) {
        log_line('retranscribe_no_notes', ['path' => $path]);
        cli_out("No markdown notes found under {$path}");
        return;
    }

    $total = count($notes);
    cli_out("Retranscribing {$total} note(s) from {$path}");

    $ok = 0;
    $err = 0;
    foreach ($notes as $idx => $noteFile) {
        $n = $idx + 1;
        cli_out(sprintf("[%d/%d] processing %s", $n, $total, basename($noteFile)));
        try {
            retranscribe_note_file($noteFile, $state);
            $ok++;
            cli_out(sprintf("[%d/%d] ok", $n, $total));
        } catch (Throwable $e) {
            $err++;
            $state['errors'][$noteFile] = [
                'failed_at' => date('c'),
                'error' => $e->getMessage(),
            ];
            log_line('retranscribe_error', ['note' => $noteFile, 'error' => $e->getMessage()]);
            cli_out(sprintf("[%d/%d] error: %s", $n, $total, $e->getMessage()));
        }
    }

    cli_out("Retranscribe complete: ok={$ok} error={$err}");
}

function retranscribe_paths_from_args(array $args): array {
    $raw = array_slice($args, 1);
    $paths = [];
    foreach ($raw as $path) {
        $path = trim((string)$path);
        if ($path !== '') {
            $paths[] = $path;
        }
    }
    if ($paths === []) {
        return [TARGET_DIRECTORY];
    }
    return array_values(array_unique($paths));
}

function discover_markdown_notes(string $path): array {
    $p = new SplFileInfo($path);
    if (!$p->isFile() && !$p->isDir()) {
        throw new RuntimeException("retranscribe_path_not_found: {$path}");
    }

    $result = [];
    if ($p->isFile()) {
        if (strtolower($p->getExtension()) === 'md') {
            $result[] = $p->getPathname();
        }
        return $result;
    }

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($p->getPathname(), FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $fi) {
        /** @var SplFileInfo $fi */
        if ($fi->isFile() && strtolower($fi->getExtension()) === 'md') {
            $result[] = $fi->getPathname();
        }
    }
    sort($result, SORT_STRING);
    return $result;
}

function retranscribe_note_file(string $noteFile, array &$state): void {
    $contents = (string)file_get_contents($noteFile);
    $audioLink = extract_audio_link($contents);
    if ($audioLink === '') {
        throw new RuntimeException("audio_link_not_found");
    }

    $audioPath = resolve_audio_link_path($audioLink);
    if (!is_file($audioPath)) {
        throw new RuntimeException("audio_file_not_found: {$audioPath}");
    }

    $transcribed = transcribe_audio_no_copy($audioPath);
    $textContent = $transcribed['text'];
    $dedupeRemoved = $transcribed['dedupe_removed_tokens'];
    $audioStartTs = infer_audio_start_time($audioPath);
    $llmMeta = build_llm_metadata($textContent, $audioStartTs);
    $title = $llmMeta['title'];
    $summary = $llmMeta['summary'];

    $newBody = '';
    if ($summary !== '') {
        $newBody .= $summary . "\n\n-----\n\n";
    }
    $newBody .= '![[%s]]' . "\n\n";
    $newBody = str_replace('%s', $audioLink, $newBody) . $textContent . "\n";
    file_put_contents($noteFile, $newBody);

    $renamedPath = maybe_rename_note_to_title($noteFile, $title, $audioStartTs);

    $state['processed'][$renamedPath] = [
        'mtime' => filemtime($renamedPath) ?: 0,
        'size' => filesize($renamedPath) ?: 0,
        'processed_at' => date('c'),
        'engine' => selected_transcribe_backend(),
        'kind' => 'vault_retranscribe',
        'audio' => $audioPath,
    ];

    log_line('retranscribe_ok', [
        'note' => $renamedPath,
        'audio' => $audioPath,
        'backend' => selected_transcribe_backend(),
        'dedupe_removed_tokens' => $dedupeRemoved,
    ]);
}

function infer_audio_start_time(string $audioFile): int {
    $nameTs = audio_timestamp_from_path($audioFile);
    if ($nameTs > 0) {
        return $nameTs;
    }

    $duration = audio_duration_seconds($audioFile);
    $captureTs = audio_capture_timestamp($audioFile);
    if ($captureTs > 0) {
        return max(0, $captureTs - (int)round($duration));
    }
    return max(0, (filemtime($audioFile) ?: time()) - (int)round($duration));
}

function repair_note_dates(string $path): void {
    $notes = discover_markdown_notes($path);
    if ($notes === []) {
        log_line('repair_note_dates_no_notes', ['path' => $path]);
        cli_out("No markdown notes found under {$path}");
        return;
    }

    $total = count($notes);
    $updated = 0;
    $unchanged = 0;
    $errors = 0;
    cli_out("Repairing dates for {$total} note(s) from {$path}");

    foreach ($notes as $idx => $noteFile) {
        $n = $idx + 1;
        cli_out(sprintf("[%d/%d] checking %s", $n, $total, basename($noteFile)));
        try {
            $contents = (string)file_get_contents($noteFile);
            $audioLink = extract_audio_link($contents);
            if ($audioLink === '') {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: no audio link", $n, $total));
                continue;
            }

            $audioPath = resolve_audio_link_path($audioLink);
            if (!is_file($audioPath)) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: missing audio", $n, $total));
                continue;
            }

            $ts = infer_audio_start_time($audioPath);
            if ($ts <= 0) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: no timestamp", $n, $total));
                continue;
            }

            $currentStem = pathinfo($noteFile, PATHINFO_FILENAME);
            $currentTitle = (string)(preg_replace('/^\d{1,2}\s+/', '', $currentStem) ?? $currentStem);
            $renamedPath = maybe_rename_note_to_title($noteFile, $currentTitle, $ts);
            if ($renamedPath === $noteFile) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged", $n, $total));
                continue;
            }

            $updated++;
            log_line('repair_note_dates_renamed', [
                'from' => $noteFile,
                'to' => $renamedPath,
                'audio' => $audioPath,
                'audio_start_ts' => $ts,
            ]);
            cli_out(sprintf("[%d/%d] updated -> %s", $n, $total, basename($renamedPath)));
        } catch (Throwable $e) {
            $errors++;
            log_line('repair_note_dates_error', ['note' => $noteFile, 'error' => $e->getMessage()]);
            cli_out(sprintf("[%d/%d] error: %s", $n, $total, $e->getMessage()));
        }
    }

    cli_out("Repair-date complete: updated={$updated} unchanged={$unchanged} error={$errors}");
}

function retime_note_filenames(string $path, bool $dryRun = false): void {
    $notes = discover_markdown_notes($path);
    if ($notes === []) {
        log_line('retime_note_filenames_no_notes', ['path' => $path]);
        cli_out("No markdown notes found under {$path}");
        return;
    }

    $total = count($notes);
    $updated = 0;
    $unchanged = 0;
    $errors = 0;
    $mode = $dryRun ? 'DRY RUN' : 'APPLY';
    cli_out("Retiming filenames ({$mode}) for {$total} note(s) from {$path}");

    foreach ($notes as $idx => $noteFile) {
        $n = $idx + 1;
        cli_out(sprintf("[%d/%d] checking: %s", $n, $total, basename($noteFile)));
        try {
            $contents = (string)file_get_contents($noteFile);
            $audioLink = extract_audio_link($contents);
            if ($audioLink === '') {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: no audio link", $n, $total));
                continue;
            }

            $audioPath = resolve_audio_link_path($audioLink);
            if (!is_file($audioPath)) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: missing audio", $n, $total));
                continue;
            }

            $ts = infer_audio_start_time($audioPath);
            if ($ts <= 0) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: no timestamp", $n, $total));
                continue;
            }

            $currentStem = pathinfo($noteFile, PATHINFO_FILENAME);
            $titlePart = note_title_without_prefixes($currentStem);
            $targetStem = format_note_title_with_time($titlePart, $ts);
            if ($targetStem === '') {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged: empty target", $n, $total));
                continue;
            }

            $renamedPath = maybe_rename_note_to_stem($noteFile, $targetStem, $dryRun);
            if ($renamedPath === $noteFile) {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged", $n, $total));
                continue;
            }

            $updated++;
            if ($dryRun) {
                cli_out(sprintf("[%d/%d] new name: %s", $n, $total, basename($renamedPath)));
            } else {
                cli_out(sprintf("[%d/%d] updated -> %s", $n, $total, basename($renamedPath)));
            }
            log_line('retime_note_filename', [
                'dry_run' => $dryRun,
                'from' => $noteFile,
                'to' => $renamedPath,
                'audio' => $audioPath,
                'audio_start_ts' => $ts,
            ]);
        } catch (Throwable $e) {
            $errors++;
            log_line('retime_note_filename_error', ['note' => $noteFile, 'error' => $e->getMessage()]);
            cli_out(sprintf("[%d/%d] error: %s", $n, $total, $e->getMessage()));
        }
    }

    $label = $dryRun ? 'Retime dry-run complete' : 'Retime complete';
    cli_out("{$label}: updated={$updated} unchanged={$unchanged} error={$errors}");
}

function refresh_heuristic_notes(string $path, array &$state, bool $dryRun = false, bool $onlyMetadata = false): void {
    $notes = discover_markdown_notes($path);
    if ($notes === []) {
        log_line('refresh_heuristic_notes_no_notes', ['path' => $path]);
        cli_out("No markdown notes found under {$path}");
        return;
    }

    $total = count($notes);
    $matched = 0;
    $matchedMetadata = 0;
    $matchedRetranscribe = 0;
    $matchedRetranscribeAudioSeconds = 0.0;
    $updated = 0;
    $unchanged = 0;
    $filtered = 0;
    $errors = 0;
    $mode = $dryRun ? 'DRY RUN' : 'APPLY';
    $filterLabel = $onlyMetadata ? 'only-metadata' : 'all';
    cli_out("Heuristic refresh ({$mode}, filter={$filterLabel}) for {$total} note(s) from {$path}");

    foreach ($notes as $idx => $noteFile) {
        $n = $idx + 1;
        cli_out(sprintf("[%d/%d] checking: %s", $n, $total, basename($noteFile)));
        try {
            $plan = analyze_note_refresh_plan($noteFile);
            if ($plan['action'] === 'none') {
                $unchanged++;
                cli_out(sprintf("[%d/%d] unchanged", $n, $total));
                continue;
            }
            if ($onlyMetadata) {
                if ($plan['action'] === 'retranscribe' && (bool)($plan['needs_metadata'] ?? false)) {
                    $plan['action'] = 'metadata';
                    $plan['reason'] = (string)($plan['metadata_reason'] ?? 'missing_metadata');
                } elseif ($plan['action'] !== 'metadata') {
                    $filtered++;
                    cli_out(sprintf("[%d/%d] skipped: only-metadata filter", $n, $total));
                    continue;
                }
            }

            $matched++;
            $reason = (string)($plan['reason'] ?? 'heuristic');
            cli_out(sprintf("[%d/%d] action: %s (%s)", $n, $total, $plan['action'], $reason));
            if ($plan['action'] === 'metadata') {
                $matchedMetadata++;
            } elseif ($plan['action'] === 'retranscribe') {
                $matchedRetranscribe++;
                $matchedRetranscribeAudioSeconds += (float)($plan['audio_duration_s'] ?? 0.0);
            }

            if ($dryRun) {
                continue;
            }

            if ($plan['action'] === 'metadata') {
                $renamedPath = refresh_note_metadata_only($noteFile, $state, $plan);
                $updated++;
                cli_out(sprintf("[%d/%d] updated -> %s", $n, $total, basename($renamedPath)));
                continue;
            }

            if ($plan['action'] === 'retranscribe') {
                retranscribe_note_file($noteFile, $state);
                $updated++;
                cli_out(sprintf("[%d/%d] retranscribed", $n, $total));
                continue;
            }

            $unchanged++;
        } catch (Throwable $e) {
            $errors++;
            log_line('refresh_heuristic_note_error', ['note' => $noteFile, 'error' => $e->getMessage()]);
            cli_out(sprintf("[%d/%d] error: %s", $n, $total, $e->getMessage()));
        }
    }

    $label = $dryRun ? 'Heuristic dry-run complete' : 'Heuristic refresh complete';
    cli_out("{$label}: matched={$matched} (metadata={$matchedMetadata}, retranscribe={$matchedRetranscribe}) filtered={$filtered} updated={$updated} unchanged={$unchanged} error={$errors}");
    if ($dryRun && $matchedRetranscribe > 0) {
        $timing = load_timing_ratio_stats();
        if ($timing !== null) {
            $avgEta = $matchedRetranscribeAudioSeconds * $timing['avg_ratio'];
            $p50Eta = $matchedRetranscribeAudioSeconds * $timing['p50_ratio'];
            $p90Eta = $matchedRetranscribeAudioSeconds * $timing['p90_ratio'];
            cli_out(
                'Estimated retranscribe runtime from logs: '
                . 'audio_s=' . round($matchedRetranscribeAudioSeconds, 1)
                . ' eta_avg=' . format_duration_hms($avgEta)
                . ' eta_p50=' . format_duration_hms($p50Eta)
                . ' eta_p90=' . format_duration_hms($p90Eta)
            );
            if ($matchedMetadata > 0) {
                cli_out("Note: metadata={$matchedMetadata} items are extra and not included in ETA.");
            }
        }
    }
}

function analyze_note_refresh_plan(string $noteFile): array {
    $contents = (string)file_get_contents($noteFile);
    $audioLink = extract_audio_link($contents);
    if ($audioLink === '') {
        return ['action' => 'none', 'reason' => 'missing_audio_link'];
    }

    $audioPath = resolve_audio_link_path($audioLink);
    if (!is_file($audioPath)) {
        return ['action' => 'none', 'reason' => 'missing_audio_file'];
    }

    $transcript = extract_transcript_body($contents);
    if ($transcript === '') {
        return ['action' => 'none', 'reason' => 'missing_transcript_body'];
    }

    $stem = pathinfo($noteFile, PATHINFO_FILENAME);
    $titlePart = note_title_without_prefixes($stem);
    $titleLooksMissing = ($titlePart === '' || preg_match('/^\d+$/', $titlePart) === 1);
    $summaryMissing = !note_has_summary($contents);

    $flat = preg_replace('/\s+/', ' ', trim($transcript)) ?? '';
    $dedupe = dedupe_repetition($flat);
    $removed = (int)($dedupe['removed_tokens'] ?? 0);
    $excessiveRepetition = $removed >= 20;

    if ($excessiveRepetition) {
        return [
            'action' => 'retranscribe',
            'reason' => "excessive_repetition_removed_tokens={$removed}",
            'needs_metadata' => ($titleLooksMissing || $summaryMissing),
            'metadata_reason' => metadata_reason_label($titleLooksMissing, $summaryMissing),
            'audio_link' => $audioLink,
            'audio_path' => $audioPath,
            'audio_duration_s' => audio_duration_seconds($audioPath),
            'transcript' => $transcript,
        ];
    }

    if ($titleLooksMissing || $summaryMissing) {
        $reasons = [];
        if ($titleLooksMissing) {
            $reasons[] = 'missing_title';
        }
        if ($summaryMissing) {
            $reasons[] = 'missing_summary';
        }
        return [
            'action' => 'metadata',
            'reason' => implode('+', $reasons),
            'needs_metadata' => true,
            'metadata_reason' => implode('+', $reasons),
            'audio_link' => $audioLink,
            'audio_path' => $audioPath,
            'transcript' => $transcript,
        ];
    }

    return [
        'action' => 'none',
        'reason' => 'no_heuristic_match',
        'needs_metadata' => false,
        'metadata_reason' => '',
    ];
}

function metadata_reason_label(bool $titleLooksMissing, bool $summaryMissing): string {
    $reasons = [];
    if ($titleLooksMissing) {
        $reasons[] = 'missing_title';
    }
    if ($summaryMissing) {
        $reasons[] = 'missing_summary';
    }
    return $reasons === [] ? 'missing_metadata' : implode('+', $reasons);
}

function refresh_note_metadata_only(string $noteFile, array &$state, array $plan): string {
    $audioLink = (string)($plan['audio_link'] ?? '');
    $audioPath = (string)($plan['audio_path'] ?? '');
    $transcript = trim((string)($plan['transcript'] ?? ''));
    if ($audioLink === '' || $audioPath === '' || $transcript === '') {
        throw new RuntimeException('invalid_metadata_refresh_plan');
    }

    $audioStartTs = infer_audio_start_time($audioPath);
    $llmMeta = build_llm_metadata($transcript, $audioStartTs);
    $title = (string)($llmMeta['title'] ?? '');
    $summary = (string)($llmMeta['summary'] ?? '');

    $newBody = '';
    if ($summary !== '') {
        $newBody .= $summary . "\n\n-----\n\n";
    }
    $newBody .= '![[%s]]' . "\n\n";
    $newBody = str_replace('%s', $audioLink, $newBody) . $transcript . "\n";
    file_put_contents($noteFile, $newBody);

    $renamedPath = maybe_rename_note_to_title($noteFile, $title, $audioStartTs);
    $state['processed'][$renamedPath] = [
        'mtime' => filemtime($renamedPath) ?: 0,
        'size' => filesize($renamedPath) ?: 0,
        'processed_at' => date('c'),
        'engine' => selected_transcribe_backend(),
        'kind' => 'heuristic_metadata_refresh',
        'audio' => $audioPath,
    ];
    log_line('heuristic_metadata_refresh_ok', [
        'note' => $renamedPath,
        'audio' => $audioPath,
    ]);
    return $renamedPath;
}

function note_has_summary(string $contents): bool {
    $parts = preg_split('/\n\s*-----\s*\n/', $contents, 2);
    if (!is_array($parts) || count($parts) < 2) {
        return false;
    }
    $summary = trim((string)$parts[0]);
    if ($summary === '') {
        return false;
    }
    if (preg_match('/^!\[\[[^\]]+\.m4a\]\]$/i', $summary) === 1) {
        return false;
    }
    return strlen($summary) >= 20;
}

function extract_transcript_body(string $contents): string {
    if (preg_match('/!\[\[[^\]]+\.m4a\]\]/i', $contents, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }
    $match = $m[0][0];
    $pos = (int)$m[0][1];
    $start = $pos + strlen($match);
    if ($start >= strlen($contents)) {
        return '';
    }
    $body = trim((string)substr($contents, $start));
    return $body;
}

function maybe_rename_note_to_title(string $noteFile, string $title, int $audioStartTs): string {
    $safeTitle = format_note_title($title, $audioStartTs);
    if ($safeTitle === '') {
        return $noteFile;
    }
    return maybe_rename_note_to_stem($noteFile, $safeTitle, false);
}

function maybe_rename_note_to_stem(string $noteFile, string $targetStem, bool $dryRun = false): string {
    $dir = dirname($noteFile);
    $target = $dir . '/' . $targetStem . '.md';
    if ($target === $noteFile) {
        return $noteFile;
    }

    if (file_exists($target)) {
        $base = $dir . '/' . $targetStem;
        $i = 2;
        while (file_exists($base . ' ' . $i . '.md')) {
            $i++;
        }
        $target = $base . ' ' . $i . '.md';
    }

    if ($dryRun) {
        return $target;
    }

    if (!@rename($noteFile, $target)) {
        $err = error_get_last();
        $msg = is_array($err) ? (string)($err['message'] ?? '') : '';
        if ($msg === '') {
            $msg = 'rename_failed';
        }
        throw new RuntimeException("rename_failed: {$noteFile} -> {$target} ({$msg})");
    }
    return $target;
}

function extract_audio_link(string $contents): string {
    if (preg_match('/!\[\[([^\]]+\.m4a)\]\]/i', $contents, $m) === 1) {
        return trim((string)$m[1]);
    }
    return '';
}

function resolve_audio_link_path(string $audioLink): string {
    $link = trim($audioLink);
    $candidate = TARGET_DIRECTORY . '/' . $link;
    if (is_file($candidate)) {
        return $candidate;
    }

    $base = basename($link);
    $candidate = TARGET_AUDIO_DIRECTORY . '/' . $base;
    return $candidate;
}

function transcribe_audio_no_copy(string $audioFile): array {
    $tmpBase = sprintf('/tmp/retranscribe-%d-%s', getmypid(), uniqid('', true));
    run_transcription_backend($audioFile, $tmpBase);

    $txtFile = $tmpBase . '.txt';
    if (!is_file($txtFile)) {
        throw new RuntimeException("transcript_missing: {$txtFile}");
    }

    $raw = trim((string)file_get_contents($txtFile));
    $normalized = normalize_text($raw);
    $dedupeInfo = dedupe_repetition($normalized);

    @unlink($txtFile);
    @unlink($tmpBase . '.metrics.json');

    return [
        'text' => $dedupeInfo['text'],
        'dedupe_removed_tokens' => $dedupeInfo['removed_tokens'],
    ];
}

function selected_transcribe_backend(): string {
    return 'lightning_whisper';
}

function run_transcription_backend(string $audioFile, string $outputBase, int $offsetMs = 0, int $durationMs = 0): void {
    run_lightning_whisper($audioFile, $outputBase, $offsetMs, $durationMs);
}

function run_lightning_whisper(string $audioFile, string $outputBase, int $offsetMs = 0, int $durationMs = 0): void {
    $batchSize = max(1, (int)(getenv('OPHANIEL_LIGHTNING_BATCH_SIZE') ?: LIGHTNING_BATCH_SIZE));
    $parts = [
        escapeshellarg(LIGHTNING_UV_COMMAND),
        'run',
        '--with',
        'lightning-whisper-mlx',
        'python',
        escapeshellarg(LIGHTNING_SCRIPT),
        '--audio',
        escapeshellarg($audioFile),
        '--model',
        escapeshellarg(LIGHTNING_MODEL),
        '--batch-size',
        (string)$batchSize,
        '--language',
        escapeshellarg(LIGHTNING_LANGUAGE),
        '--out-prefix',
        escapeshellarg($outputBase),
    ];
    if ($offsetMs > 0) {
        $parts[] = '--offset-ms';
        $parts[] = (string)$offsetMs;
    }
    if ($durationMs > 0) {
        $parts[] = '--duration-ms';
        $parts[] = (string)$durationMs;
    }

    run_or_throw(implode(' ', $parts), 'lightning_whisper_failed');
}

function run_or_throw(string $cmd, string $prefix): void {
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new RuntimeException($prefix . " (exit {$code})");
    }
}

function copy_with_retry(string $source, string $dest, string &$error = '', int $attempts = 4): bool {
    $attempts = max(1, $attempts);
    $lastError = '';
    for ($i = 1; $i <= $attempts; $i++) {
        clearstatcache(true, $source);
        hydrate_source_with_ffprobe($source);
        @unlink($dest);
        $copyErr = '';
        if (copy_via_stream($source, $dest, $copyErr)) {
            return true;
        }
        if ($copyErr !== '') {
            $lastError = $copyErr;
        }

        if ($i < $attempts) {
            usleep($i * 500000); // 0.5s, 1.0s, 1.5s...
        }
    }

    if ($lastError !== '') {
        log_line('copy_failed_retry_exhausted', ['source' => $source, 'dest' => $dest, 'error' => $lastError]);
    }
    $error = $lastError;
    return false;
}

function audio_duration_seconds(string $file): float {
    $ffprobe = FFPROBE_COMMAND;
    if (!is_executable($ffprobe)) {
        $ffprobe = 'ffprobe';
    }
    $cmd = sprintf('%s -v quiet -show_entries format=duration -of csv=p=0 %s 2>/dev/null', escapeshellarg($ffprobe), escapeshellarg($file));
    $out = trim((string)shell_exec($cmd));
    if ($out === '' || !is_numeric($out)) {
        return 0.0;
    }
    return max(0.0, (float)$out);
}

function word_count(string $text): int {
    $tokens = preg_split('/\s+/', trim($text));
    if (!$tokens) {
        return 0;
    }
    return count(array_filter($tokens, static fn(string $token): bool => $token !== ''));
}

function base_name_from_timestamp(int $unixTs): string {
    return sprintf('%s at %s', date('Y-m-d', $unixTs), date('Hi', $unixTs));
}

function unique_audio_name(string $base): array {
    $iter = 0;
    $raw = $base;

    while (true) {
        $candidate = TARGET_AUDIO_DIRECTORY . '/' . $base . '.m4a';
        if (!file_exists($candidate)) {
            return [$base, $candidate];
        }
        $iter++;
        $base = $raw . '-' . $iter;
    }
}

function normalize_text(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);

    // Preserve sentence flow by removing hard line breaks mid-sentence.
    $text = preg_replace('/(?<![.!?])\n+/', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
    $text = str_replace("\n", ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    return paragraphize_text($text);
}

function paragraphize_text(string $text): string {
    $sentences = split_sentences($text);
    if ($sentences === []) {
        return $text;
    }

    $paragraphs = [];
    $current = [];
    $currentWords = 0;
    $maxSentences = 3;
    $minSentences = 1;
    $maxWords = 70;

    foreach ($sentences as $idx => $sentence) {
        $sentenceWords = word_count($sentence);
        $startsNewThought = sentence_starts_new_thought($sentence);
        $hasCurrent = $current !== [];

        $shouldBreak = $hasCurrent && (
            count($current) >= $maxSentences
            || ($currentWords + $sentenceWords) > $maxWords
            || ($startsNewThought && count($current) >= $minSentences)
        );

        if ($shouldBreak) {
            $paragraphs[] = implode(' ', $current);
            $current = [];
            $currentWords = 0;
        }

        $current[] = $sentence;
        $currentWords += $sentenceWords;

        $isLast = ($idx === count($sentences) - 1);
        if ($isLast && $current !== []) {
            $paragraphs[] = implode(' ', $current);
        }
    }

    $paragraphs = array_values(array_filter(array_map('trim', $paragraphs), static fn(string $p): bool => $p !== ''));
    if ($paragraphs === []) {
        return $text;
    }

    $out = implode("\n\n", $paragraphs);
    if (strpos($out, "\n\n") === false && word_count($out) >= 45) {
        return hard_wrap_paragraphs($out, 45);
    }

    return $out;
}

function split_sentences(string $text): array {
    // Primary path: split at sentence punctuation before probable sentence starts.
    $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z0-9"\'])/', $text);
    $parts = array_values(array_filter(array_map('trim', $parts ?: []), static fn(string $p): bool => $p !== ''));
    if (count($parts) >= 2) {
        return $parts;
    }

    // Fallback for weak punctuation: chunk by word count.
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $words = array_values(array_filter($words, static fn(string $w): bool => $w !== ''));
    if ($words === []) {
        return [];
    }

    $chunks = [];
    $chunkSize = 40;
    for ($i = 0; $i < count($words); $i += $chunkSize) {
        $chunks[] = implode(' ', array_slice($words, $i, $chunkSize));
    }
    return $chunks;
}

function sentence_starts_new_thought(string $sentence): bool {
    return preg_match('/^(anyway|so|but|then|okay|ok|also|meanwhile|on the other hand)\b/i', ltrim($sentence)) === 1;
}

function hard_wrap_paragraphs(string $text, int $wordsPerParagraph): string {
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $words = array_values(array_filter($words, static fn(string $w): bool => $w !== ''));
    if ($words === []) {
        return '';
    }

    $paras = [];
    for ($i = 0; $i < count($words); $i += $wordsPerParagraph) {
        $paras[] = implode(' ', array_slice($words, $i, $wordsPerParagraph));
    }
    return implode("\n\n", $paras);
}

function dedupe_repetition(string $text): array {
    $tokens = preg_split('/\s+/', trim($text));
    if (!$tokens || count($tokens) < 12) {
        return ['text' => $text, 'removed_tokens' => 0];
    }

    $removed = 0;
    $minPhrase = 4;
    $maxPhrase = 18;

    for ($n = min($maxPhrase, intdiv(count($tokens), 2)); $n >= $minPhrase; $n--) {
        $i = 0;
        while ($i + (2 * $n) <= count($tokens)) {
            $a = array_slice($tokens, $i, $n);
            $b = array_slice($tokens, $i + $n, $n);
            if ($a !== $b) {
                $i++;
                continue;
            }

            // Collapse immediate repeats: keep one copy, drop the next.
            array_splice($tokens, $i + $n, $n);
            $removed += $n;
        }
    }

    return [
        'text' => implode(' ', $tokens),
        'removed_tokens' => $removed,
    ];
}

function build_title(string $text, int $audioStartTs): string {
    return build_llm_metadata($text, $audioStartTs)['title'];
}

function build_summary(string $text): string {
    return build_llm_metadata($text, time())['summary'];
}

function build_llm_metadata(string $text, int $audioStartTs): array {
    $fallbackTitle = fallback_title_from_text($text, $audioStartTs);
    $fallbackSummary = fallback_summary_from_text($text);
    if (!ENABLE_LLM_SUMMARY) {
        return ['title' => $fallbackTitle, 'summary' => $fallbackSummary];
    }
    ensure_llm_metadata_preflight();

    $promptText = llm_metadata_prompt_text($text);
    $prompt = "You create metadata for a voice memo transcript.\n"
        . "Return only compact JSON with this exact schema:\n"
        . "{\"title\":\"string\",\"summary\":\"string\"}\n"
        . "Rules:\n"
        . "- title: very short descriptive phrase, max 10 words.\n"
        . "- title: natural phrase with spaces (no snake_case, no kebab-case, no CamelCase).\n"
        . "- summary: one terse paragraph.\n"
        . "- no markdown, no extra keys, no commentary.\n"
        . "---\n{$promptText}\n---";
    $raw = query_llm($prompt);
    $parsed = parse_llm_metadata_json($raw);
    if ($parsed === null) {
        log_line('llm_metadata_parse_failed', [
            'model' => LLM_MODEL,
            'input_chars' => strlen($promptText),
            'raw_excerpt' => substr($raw, 0, 280),
        ]);
        return ['title' => $fallbackTitle, 'summary' => $fallbackSummary];
    }

    $title = format_note_title((string)($parsed['title'] ?? ''), $audioStartTs);
    if ($title === '') {
        $title = $fallbackTitle;
    }

    $summary = trim((string)($parsed['summary'] ?? ''));
    if ($summary === '') {
        $summary = $fallbackSummary;
    }
    return ['title' => $title, 'summary' => $summary];
}

function parse_llm_metadata_json(string $raw): ?array {
    $candidate = trim($raw);
    if ($candidate === '') {
        return null;
    }

    // If wrapped in fenced markdown, extract the fenced body first.
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $candidate, $m) === 1) {
        $candidate = trim((string)$m[1]);
    }

    $json = json_decode($candidate, true);
    if (is_array($json)) {
        return $json;
    }

    // Fallback: extract a JSON object from mixed output.
    if (preg_match('/\{[\s\S]*\}/', $candidate, $m) !== 1) {
        return null;
    }
    $json = json_decode((string)$m[0], true);
    if (!is_array($json)) {
        return null;
    }
    return $json;
}

function fallback_title_from_text(string $text, int $audioStartTs): string {
    $slug = first_words_slug($text, 8);
    if ($slug === '') {
        return base_name_from_timestamp($audioStartTs);
    }

    return format_note_title($slug, $audioStartTs);
}

function fallback_summary_from_text(string $text): string {
    $clean = trim((string)(preg_replace('/\s+/', ' ', $text) ?? $text));
    if ($clean === '') {
        return '';
    }
    if (strlen($clean) <= 280) {
        return $clean;
    }
    return rtrim(substr($clean, 0, 277)) . '...';
}

function llm_metadata_prompt_text(string $text): string {
    $clean = trim($text);
    if ($clean === '') {
        return $clean;
    }
    $charLimit = llm_metadata_char_limit();
    if (strlen($clean) <= $charLimit) {
        return $clean;
    }
    return rtrim(substr($clean, 0, $charLimit)) . "\n\n[Transcript truncated for metadata generation]";
}

function llm_metadata_char_limit(): int {
    static $cached = null;
    if (is_int($cached) && $cached > 0) {
        return $cached;
    }

    $fallback = max(1000, LLM_METADATA_MAX_CHARS);
    $model = lms_model_name_from_llm_model(LLM_MODEL);
    if ($model === '') {
        $cached = $fallback;
        return $cached;
    }

    $ctxTokens = lmstudio_model_context_tokens($model);
    if ($ctxTokens <= 0) {
        $cached = $fallback;
        return $cached;
    }

    $derived = (int)floor($ctxTokens * LLM_METADATA_CONTEXT_FRACTION * LLM_METADATA_CHARS_PER_TOKEN);
    $cached = max(1000, $derived);
    log_line('llm_metadata_char_limit', [
        'model' => $model,
        'context_tokens' => $ctxTokens,
        'fraction' => LLM_METADATA_CONTEXT_FRACTION,
        'chars_per_token' => LLM_METADATA_CHARS_PER_TOKEN,
        'chars' => $cached,
        'fallback_chars' => $fallback,
    ]);
    return $cached;
}

function query_llm(string $prompt): string {
    try {
        $url = rtrim(LMSTUDIO_BASE_URL, '/') . '/chat/completions';
        $payload = json_encode([
            'model' => LLM_MODEL,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
            'stream' => false,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('llm_payload_encode_failed');
        }

        $resp = requests_post_json($url, $payload, LMSTUDIO_TIMEOUT_SECONDS);
        if ((int)$resp['status_code'] !== 200) {
            throw new RuntimeException('llm_http_status_' . (int)$resp['status_code'] . ': ' . substr($resp['body'], 0, 280));
        }

        $decoded = json_decode($resp['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('llm_response_decode_failed');
        }
        $content = (string)($decoded['choices'][0]['message']['content'] ?? '');
        return sanitize_llm_output($content);
    } catch (Throwable $e) {
        log_line('llm_query_error', [
            'model' => LLM_MODEL,
            'error' => $e->getMessage(),
        ]);
        return '';
    }
}

function sanitize_llm_output(string $text): string {
    // Strip ANSI escape sequences in case a backend emits colored output.
    $text = preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return trim($text);
}

function first_words_slug(string $text, int $count): string {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
    if ($text === '') {
        return '';
    }
    $words = explode(' ', $text);
    $words = array_slice($words, 0, $count);
    return sanitize_filename(implode(' ', $words));
}

function sanitize_filename(string $name): string {
    return trim(normalize_title_phrase($name), '. ');
}

function normalize_title_phrase(string $text): string {
    // Split camelCase / PascalCase boundaries into spaces.
    $text = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $text) ?? $text;
    $text = str_replace(['_', '-'], ' ', $text);
    $text = preg_replace('/[\/:*?"<>|]/', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', trim($text)) ?? $text;
    return trim($text, '. ');
}

function format_note_title(string $title, int $audioStartTs): string {
    $phrase = normalize_title_phrase($title);
    // If model returns day/time prefixes, replace with canonical values from audio timestamp.
    $phrase = preg_replace('/^\d{1,2}\s+(?:\d{4}\s+)?/', '', $phrase) ?? $phrase;
    $phrase = trim($phrase);
    if ($phrase === '') {
        $phrase = base_name_from_timestamp($audioStartTs);
    }

    return date('d', $audioStartTs) . ' ' . date('Hi', $audioStartTs) . ' ' . $phrase;
}

function format_note_title_with_time(string $title, int $audioStartTs): string {
    $phrase = sanitize_existing_note_title($title);
    // If model/file already contains a day/time prefix, replace with canonical values from audio timestamp.
    $phrase = preg_replace('/^\d{1,2}\s+(?:\d{4}\s+)?/', '', $phrase) ?? $phrase;
    $phrase = trim($phrase);
    if ($phrase === '') {
        $phrase = base_name_from_timestamp($audioStartTs);
    }

    return date('d', $audioStartTs) . ' ' . date('Hi', $audioStartTs) . ' ' . $phrase;
}

function note_title_without_prefixes(string $stem): string {
    $title = preg_replace('/^\d{1,2}\s+(?:\d{4}\s+)?/', '', $stem) ?? $stem;
    $title = trim((string)$title);
    if ($title === '') {
        return $stem;
    }
    return $title;
}

function sanitize_existing_note_title(string $title): string {
    $text = trim($title);
    // Keep '-' and '_' from existing filenames; only strip filesystem-invalid chars.
    $text = preg_replace('/[\/:*?"<>|]/', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text, '. ');
}

function build_md_path(string $title, int $audioStartTs): string {
    $safe = format_note_title($title, $audioStartTs);
    if ($safe === '') {
        $safe = base_name_from_timestamp($audioStartTs);
    }

    return sprintf(
        '%s/%s/%s/%s.md',
        TARGET_DIRECTORY,
        date('Y', $audioStartTs),
        date('m', $audioStartTs),
        $safe
    );
}

function file_meta(string $file): array {
    $mtime = (int)(filemtime($file) ?: 0);
    $size = (int)(filesize($file) ?: 0);
    $captureTs = audio_capture_timestamp($file);
    $birth = source_file_timestamp($file);
    $pathTs = source_path_timestamp_guess($file);
    return [
        'mtime' => $mtime,
        'size' => $size,
        'birthtime' => $birth,
        'capture_ts' => $captureTs,
        'path_ts' => $pathTs,
    ];
}

function candidate_timestamp_for_queue(array $meta): int {
    $capture = (int)($meta['capture_ts'] ?? 0);
    if ($capture > 0) {
        return $capture;
    }
    $pathTs = (int)($meta['path_ts'] ?? 0);
    if ($pathTs > 0) {
        return $pathTs;
    }
    $birth = (int)($meta['birthtime'] ?? 0);
    if ($birth > 0) {
        return $birth;
    }
    return (int)($meta['mtime'] ?? 0);
}

function estimate_audio_start_time(string $file, ?array $meta, float $duration): int {
    $pathTs = (int)(($meta['path_ts'] ?? 0));
    if ($pathTs <= 0) {
        $pathTs = source_path_timestamp_guess($file);
    }
    if ($pathTs > 0) {
        // Just Press Record file names encode start time.
        return $pathTs;
    }

    $captureTs = (int)(($meta['capture_ts'] ?? 0));
    if ($captureTs <= 0) {
        $captureTs = audio_capture_timestamp($file);
    }
    if ($captureTs > 0) {
        return max(0, $captureTs - (int)round($duration));
    }

    return max(0, source_file_timestamp($file) - (int)round($duration));
}

function already_processed(string $file, array $meta, array $state): bool {
    if (!isset($state['processed'][$file])) {
        return false;
    }
    if (!REPROCESS_ON_SOURCE_CHANGE) {
        return true;
    }
    $prev = $state['processed'][$file];
    return (int)($prev['mtime'] ?? -1) === $meta['mtime']
        && (int)($prev['size'] ?? -1) === $meta['size'];
}

function recent_error_backoff(string $file, array $meta, array $state): bool {
    $err = $state['errors'][$file] ?? null;
    if (!is_array($err)) {
        return false;
    }

    $failedAt = strtotime((string)($err['failed_at'] ?? ''));
    if ($failedAt === false) {
        return false;
    }

    $sameVersion = (int)($err['mtime'] ?? -1) === (int)$meta['mtime']
        && (int)($err['size'] ?? -1) === (int)$meta['size'];
    if (!$sameVersion) {
        return false;
    }

    $attempts = (int)($err['attempts'] ?? 1);
    if ($attempts >= ERROR_MAX_ATTEMPTS) {
        return true;
    }

    // Do not back off fresh files; allow quick retries while they are actively syncing.
    if ((int)$meta['mtime'] >= (time() - 7200)) {
        return false;
    }

    return (time() - $failedAt) < ERROR_RETRY_SECONDS;
}

function is_file_settled(string $file, array $meta): bool {
    if ($meta['size'] <= 0 || $meta['mtime'] <= 0) {
        return false;
    }

    if (time() - $meta['mtime'] < FILE_SETTLE_SECONDS) {
        return false;
    }

    // Confirm size isn't currently changing.
    clearstatcache(true, $file);
    $sizeA = filesize($file);
    usleep(350000);
    clearstatcache(true, $file);
    $sizeB = filesize($file);

    return $sizeA !== false && $sizeB !== false && $sizeA === $sizeB;
}

function load_state(): array {
    if (!is_file(STATE_FILE)) {
        $watermark = 0;
        if (is_file(LEGACY_PROCESSED_FILE)) {
            $raw = trim((string)file_get_contents(LEGACY_PROCESSED_FILE));
            if (ctype_digit($raw)) {
                $watermark = (int)$raw;
            }
        }
        return ['processed' => [], 'errors' => [], 'watermark_mtime' => $watermark];
    }

    $decoded = json_decode((string)file_get_contents(STATE_FILE), true);
    if (!is_array($decoded)) {
        return ['processed' => [], 'errors' => [], 'watermark_mtime' => 0];
    }

    $decoded['processed'] = is_array($decoded['processed'] ?? null) ? $decoded['processed'] : [];
    $decoded['errors'] = is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [];
    $decoded['watermark_mtime'] = (int)($decoded['watermark_mtime'] ?? 0);
    return $decoded;
}

function print_status(array $state): void {
    $processed = is_array($state['processed'] ?? null) ? count($state['processed']) : 0;
    $errors = is_array($state['errors'] ?? null) ? count($state['errors']) : 0;
    $watermark = (int)($state['watermark_mtime'] ?? 0);

    echo 'processed=' . $processed . PHP_EOL;
    echo 'errors=' . $errors . PHP_EOL;
    echo 'watermark_mtime=' . $watermark . PHP_EOL;
    if ($watermark > 0) {
        echo 'watermark_iso=' . date('c', $watermark) . PHP_EOL;
    }
}

function print_timing_report(float $audioSeconds = 0.0): void {
    $stats = load_timing_ratio_stats();
    if ($stats === null) {
        echo "No processed_ok timing entries found." . PHP_EOL;
        return;
    }
    $avg = $stats['avg_ratio'];
    $p50 = $stats['p50_ratio'];
    $p90 = $stats['p90_ratio'];

    echo "timing_samples=" . $stats['samples'] . PHP_EOL;
    echo "avg_ratio=" . round($avg, 4) . " (" . round(1.0 / max(0.0001, $avg), 2) . "x realtime)" . PHP_EOL;
    echo "p50_ratio=" . round($p50, 4) . " (" . round(1.0 / max(0.0001, $p50), 2) . "x realtime)" . PHP_EOL;
    echo "p90_ratio=" . round($p90, 4) . " (" . round(1.0 / max(0.0001, $p90), 2) . "x realtime)" . PHP_EOL;

    if ($audioSeconds > 0) {
        $avgEta = $audioSeconds * $avg;
        $p50Eta = $audioSeconds * $p50;
        $p90Eta = $audioSeconds * $p90;
        echo "estimate_for_audio_s=" . round($audioSeconds, 2) . PHP_EOL;
        echo "eta_avg=" . format_duration_hms($avgEta) . PHP_EOL;
        echo "eta_p50=" . format_duration_hms($p50Eta) . PHP_EOL;
        echo "eta_p90=" . format_duration_hms($p90Eta) . PHP_EOL;
    }
}

function load_timing_ratio_stats(): ?array {
    if (!is_file(LOG_FILE)) {
        return null;
    }

    $ratios = [];
    $count = 0;
    $fh = fopen(LOG_FILE, 'r');
    if ($fh === false) {
        throw new RuntimeException('timing_report_log_open_failed');
    }
    while (($line = fgets($fh)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row)) {
            continue;
        }
        if (($row['event'] ?? '') !== 'processed_ok') {
            continue;
        }
        $ctx = $row['ctx'] ?? null;
        if (!is_array($ctx)) {
            continue;
        }
        $elapsed = (float)($ctx['elapsed_s'] ?? 0);
        $duration = (float)($ctx['duration_s'] ?? 0);
        if ($elapsed <= 0 || $duration <= 0) {
            continue;
        }
        $ratios[] = $elapsed / $duration;
        $count++;
    }
    fclose($fh);

    if ($ratios === []) {
        return null;
    }

    sort($ratios, SORT_NUMERIC);
    return [
        'samples' => $count,
        'avg_ratio' => array_sum($ratios) / count($ratios),
        'p50_ratio' => percentile($ratios, 0.5),
        'p90_ratio' => percentile($ratios, 0.9),
    ];
}

function percentile(array $sortedValues, float $p): float {
    if ($sortedValues === []) {
        return 0.0;
    }
    $n = count($sortedValues);
    if ($n === 1) {
        return (float)$sortedValues[0];
    }
    $p = max(0.0, min(1.0, $p));
    $idx = (int)round($p * ($n - 1));
    return (float)$sortedValues[$idx];
}

function format_duration_hms(float $seconds): string {
    $seconds = max(0, (int)round($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%dh %dm %ds', $h, $m, $s);
    }
    if ($m > 0) {
        return sprintf('%dm %ds', $m, $s);
    }
    return sprintf('%ds', $s);
}

function save_state(array $state): void {
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('state_encode_failed');
    }

    file_put_contents(STATE_FILE, $json . "\n");
}

function with_lock(callable $fn): void {
    $lock = fopen(LOCK_FILE, 'c+');
    if ($lock === false) {
        throw new RuntimeException('lock_open_failed');
    }

    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        log_line('lock_busy', ['lock_file' => LOCK_FILE]);
        cli_out('Another pipeline process is already running; exiting.');
        fclose($lock);
        return;
    }

    try {
        $fn();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function cli_args_without_flags(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--quiet' || $arg === '-q') {
            $GLOBALS['ophaniel_quiet'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $GLOBALS['ophaniel_dry_run'] = true;
            continue;
        }
        if ($arg === '--only-metadata') {
            $GLOBALS['ophaniel_only_metadata'] = true;
            continue;
        }
        $out[] = (string)$arg;
    }
    return $out;
}

function cli_is_quiet(): bool {
    return (bool)($GLOBALS['ophaniel_quiet'] ?? false);
}

function cli_is_dry_run(): bool {
    return (bool)($GLOBALS['ophaniel_dry_run'] ?? false);
}

function cli_is_only_metadata(): bool {
    return (bool)($GLOBALS['ophaniel_only_metadata'] ?? false);
}

function cli_out(string $message): void {
    if (cli_is_quiet()) {
        return;
    }
    echo $message . PHP_EOL;
}

function ensure_llm_metadata_preflight(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (!ENABLE_LLM_SUMMARY) {
        return;
    }
    ensure_requests_available();
    if (!is_executable(LMS_COMMAND)) {
        throw new RuntimeException('LMS command is not executable: ' . LMS_COMMAND);
    }

    $lmsModel = lms_model_name_from_llm_model(LLM_MODEL);
    if ($lmsModel === '') {
        return;
    }
    if (lmstudio_model_loaded($lmsModel)) {
        return;
    }

    $load = run_lms_command(['load', $lmsModel, '-y'], LMS_COMMAND_TIMEOUT_SECONDS);
    if ($load['timed_out']) {
        throw new RuntimeException("Failed to load model '{$lmsModel}' via 'lms load' (timed out). Please load it manually in LM Studio.");
    }
    if ($load['exit_code'] !== 0 || !lmstudio_model_loaded($lmsModel)) {
        throw new RuntimeException("Failed to load model '{$lmsModel}' via 'lms load'. Please load it manually in LM Studio.");
    }
}

function ensure_requests_available(): void {
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('\\WpOrg\\Requests\\Requests') && !class_exists('\\Requests')) {
        throw new RuntimeException('rmccue/requests not found. Run: composer install');
    }
}

function requests_post_json(string $url, string $jsonBody, int $timeoutSeconds): array {
    ensure_requests_available();

    $headers = [
        'Authorization' => 'Bearer ' . LMSTUDIO_API_KEY,
        'Content-Type' => 'application/json',
    ];
    $options = [
        'timeout' => max(1, $timeoutSeconds),
    ];

    if (class_exists('\\WpOrg\\Requests\\Requests')) {
        $resp = \WpOrg\Requests\Requests::post($url, $headers, $jsonBody, $options);
        return [
            'status_code' => (int)$resp->status_code,
            'body' => (string)$resp->body,
        ];
    }

    /** @var \Requests_Response $resp */
    $resp = \Requests::post($url, $headers, $jsonBody, $options);
    return [
        'status_code' => (int)$resp->status_code,
        'body' => (string)$resp->body,
    ];
}

function lms_model_name_from_llm_model(string $model): string {
    if (str_starts_with($model, 'lmstudio/')) {
        return substr($model, strlen('lmstudio/'));
    }
    return $model;
}

function lmstudio_model_loaded(string $model): bool {
    $res = run_lms_command(['ps', '--json'], LMS_COMMAND_TIMEOUT_SECONDS);
    if ($res['timed_out']) {
        return false;
    }
    $raw = trim($res['stdout'] . "\n" . $res['stderr']);
    if (trim($raw) === '') {
        return false;
    }

    $parsed = json_decode($raw, true);
    if (is_array($parsed)) {
        $jsonFlat = json_encode($parsed, JSON_UNESCAPED_SLASHES);
        if (is_string($jsonFlat) && stripos($jsonFlat, $model) !== false) {
            return true;
        }
    }

    return stripos($raw, $model) !== false;
}

function lmstudio_model_context_tokens(string $model): int {
    $res = run_lms_command(['ps', '--json'], LMS_COMMAND_TIMEOUT_SECONDS);
    if ($res['timed_out']) {
        return 0;
    }

    $raw = trim($res['stdout'] . "\n" . $res['stderr']);
    if ($raw === '') {
        return 0;
    }
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return 0;
    }

    $values = [];
    collect_context_token_candidates_for_model($parsed, $model, $values);
    if ($values !== []) {
        return max($values);
    }

    $fallbackValues = [];
    collect_context_token_candidates($parsed, $fallbackValues);
    if ($fallbackValues === []) {
        return 0;
    }
    return max($fallbackValues);
}

function collect_context_token_candidates_for_model(mixed $node, string $model, array &$out): void {
    if (!is_array($node)) {
        return;
    }
    if (node_mentions_model($node, $model)) {
        collect_context_token_candidates($node, $out);
    }
    foreach ($node as $value) {
        if (is_array($value)) {
            collect_context_token_candidates_for_model($value, $model, $out);
        }
    }
}

function node_mentions_model(array $node, string $model): bool {
    $json = json_encode($node, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return false;
    }
    return stripos($json, $model) !== false;
}

function collect_context_token_candidates(mixed $node, array &$out): void {
    if (!is_array($node)) {
        return;
    }
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            collect_context_token_candidates($value, $out);
            continue;
        }
        if (!is_numeric($value)) {
            continue;
        }
        $k = strtolower((string)$key);
        if (!str_contains($k, 'ctx') && !str_contains($k, 'context') && !str_contains($k, 'token')) {
            continue;
        }
        $n = (int)$value;
        if ($n >= 512 && $n <= 1048576) {
            $out[] = $n;
        }
    }
}

function run_lms_command(array $args, int $timeoutSeconds): array {
    $cmd = array_merge([LMS_COMMAND], $args);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'proc_open_failed', 'timed_out' => false];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);
        $running = (bool)($status['running'] ?? false);

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';

        if (!$running) {
            break;
        }
        if ((microtime(true) - $start) > max(1, $timeoutSeconds)) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }
        usleep(100000);
    }

    $stdout .= stream_get_contents($pipes[1]) ?: '';
    $stderr .= stream_get_contents($pipes[2]) ?: '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exit = proc_close($proc);
    if ($timedOut && $exit === 0) {
        $exit = 124;
    }

    return [
        'exit_code' => (int)$exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}

function ensure_runtime_environment(): void {
    $home = trim((string)getenv('HOME'));
    if ($home === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = posix_getpwuid(posix_geteuid());
        if (is_array($pw) && isset($pw['dir']) && is_string($pw['dir'])) {
            $home = trim($pw['dir']);
            if ($home !== '') {
                putenv("HOME={$home}");
            }
        }
    }

    if ($home !== '') {
        if ((string)getenv('XDG_CACHE_HOME') === '') {
            putenv("XDG_CACHE_HOME={$home}/.cache");
        }
        if ((string)getenv('HF_HOME') === '') {
            putenv("HF_HOME={$home}/.cache/huggingface");
        }
    }

    $requiredPath = '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin';
    $path = trim((string)getenv('PATH'));
    if ($path === '') {
        putenv("PATH={$requiredPath}");
        return;
    }
    if (str_contains($path, '/opt/homebrew/bin')) {
        return;
    }
    putenv("PATH={$requiredPath}:{$path}");
}

function copy_via_cp(string $source, string $dest): bool {
    $cmd = sprintf('/bin/cp -f %s %s', escapeshellarg($source), escapeshellarg($dest));
    exec($cmd, $out, $code);
    return $code === 0 && is_file($dest);
}

function copy_via_stream(string $source, string $dest, string &$error = ''): bool {
    $src = @fopen($source, 'rb');
    if (!is_resource($src)) {
        $error = 'fopen_source_failed';
        return false;
    }
    $dst = @fopen($dest, 'wb');
    if (!is_resource($dst)) {
        fclose($src);
        $error = 'fopen_dest_failed';
        return false;
    }

    $ok = true;
    while (!feof($src)) {
        $readErr = '';
        set_error_handler(static function (int $severity, string $message) use (&$readErr): bool {
            $readErr = $message;
            return true;
        });
        $chunk = fread($src, 1024 * 1024);
        restore_error_handler();
        if ($chunk === false) {
            $ok = false;
            $error = $readErr !== '' ? $readErr : 'fread_failed';
            break;
        }
        if ($chunk === '') {
            continue;
        }
        $written = fwrite($dst, $chunk);
        if ($written === false || $written !== strlen($chunk)) {
            $ok = false;
            $error = 'fwrite_failed';
            break;
        }
    }

    fclose($src);
    fflush($dst);
    fclose($dst);

    if (!$ok) {
        @unlink($dest);
        return false;
    }
    return is_file($dest) && filesize($dest) > 0;
}

function hydrate_source_with_ffprobe(string $source): void {
    $ffprobe = FFPROBE_COMMAND;
    if (!is_executable($ffprobe)) {
        $ffprobe = 'ffprobe';
    }
    $cmd = sprintf(
        '%s -v error -show_entries format=duration -of default=nk=1:nw=1 %s >/dev/null 2>&1',
        escapeshellarg($ffprobe),
        escapeshellarg($source)
    );
    exec($cmd, $out, $code);
}

function source_audio_available(string $source): bool {
    return ffprobe_can_open($source) && ffmpeg_can_decode_probe($source);
}

function ffprobe_can_open(string $source): bool {
    $ffprobe = FFPROBE_COMMAND;
    if (!is_executable($ffprobe)) {
        $ffprobe = 'ffprobe';
    }
    $cmd = sprintf(
        '%s -v error -show_entries format=duration -of default=nk=1:nw=1 %s >/dev/null 2>&1',
        escapeshellarg($ffprobe),
        escapeshellarg($source)
    );
    exec($cmd, $out, $code);
    return $code === 0;
}

function ffmpeg_can_decode_probe(string $source): bool {
    $ffmpeg = FFMPEG_COMMAND;
    if (!is_executable($ffmpeg)) {
        $ffmpeg = 'ffmpeg';
    }
    $cmd = sprintf(
        '%s -v error -nostdin -i %s -t 0.1 -f null - >/dev/null 2>&1',
        escapeshellarg($ffmpeg),
        escapeshellarg($source)
    );
    exec($cmd, $out, $code);
    return $code === 0;
}

function source_file_timestamp(string $file): int {
    $st = @stat($file);
    if (is_array($st)) {
        $birth = (int)($st['birthtime'] ?? 0);
        if ($birth > 0) {
            return $birth;
        }
    }
    return (int)(filemtime($file) ?: time());
}

function audio_capture_timestamp(string $file): int {
    $ffprobe = FFPROBE_COMMAND;
    if (!is_executable($ffprobe)) {
        $ffprobe = 'ffprobe';
    }
    $cmd = sprintf(
        '%s -v quiet -show_entries format_tags=creation_time -of default=nk=1:nw=1 %s 2>/dev/null',
        escapeshellarg($ffprobe),
        escapeshellarg($file)
    );
    $out = trim((string)shell_exec($cmd));
    if ($out === '') {
        return 0;
    }
    $ts = strtotime($out);
    if ($ts === false) {
        return 0;
    }
    return (int)$ts;
}

function source_path_timestamp_guess(string $file): int {
    if (preg_match('~/(\\d{4})-(\\d{2})-(\\d{2})/(\\d{2})-(\\d{2})-(\\d{2})\\.m4a$~', $file, $m) !== 1) {
        return 0;
    }
    $year = (int)$m[1];
    $month = (int)$m[2];
    $day = (int)$m[3];
    $hour = (int)$m[4];
    $min = (int)$m[5];
    $sec = (int)$m[6];
    $ts = @mktime($hour, $min, $sec, $month, $day, $year);
    if ($ts === false) {
        return 0;
    }
    return (int)$ts;
}

function audio_timestamp_from_path(string $file): int {
    // Obsidian copied audio: YYYY-MM-DD at HHMM(.m4a), with optional de-dupe suffix.
    if (preg_match('~/(\\d{4})-(\\d{2})-(\\d{2}) at (\\d{2})(\\d{2})(?:-\\d+)?\\.m4a$~i', $file, $m) === 1) {
        $ts = @mktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]);
        if ($ts !== false) {
            return (int)$ts;
        }
    }
    return source_path_timestamp_guess($file);
}

function ensure_source_audio_available(string $source): bool {
    if (source_audio_available($source)) {
        return true;
    }

    request_cloud_download($source);
    $deadline = microtime(true) + HYDRATE_WAIT_SECONDS;
    while (microtime(true) < $deadline) {
        if (source_audio_available($source)) {
            return true;
        }
        usleep(HYDRATE_POLL_MS * 1000);
    }
    return false;
}

function request_cloud_download(string $source): void {
    if (!str_contains($source, '/Library/Mobile Documents/')) {
        return;
    }
    $brctl = '/usr/bin/brctl';
    if (!is_executable($brctl)) {
        return;
    }
    $cmd = sprintf('%s download %s >/dev/null 2>&1', escapeshellarg($brctl), escapeshellarg($source));
    exec($cmd, $out, $code);
}

function log_line(string $event, array $ctx = []): void {
    $row = [
        'ts' => date('c'),
        'event' => $event,
        'ctx' => $ctx,
    ];
    file_put_contents(LOG_FILE, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function load_ini_config(): array {
    $path = trim((string)(getenv('OPHANIEL_CONFIG') ?: ''));
    if ($path === '') {
        $path = __DIR__ . '/config.ini';
    }
    if (!is_file($path)) {
        return [];
    }

    $parsed = parse_ini_file($path, false, INI_SCANNER_TYPED);
    if (!is_array($parsed)) {
        return [];
    }
    return $parsed;
}

function cfg_raw(array $cfg, string $key): mixed {
    $env = getenv($key);
    if ($env !== false) {
        return $env;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : null;
}

function cfg_string(array $cfg, string $key, string $default): string {
    $raw = cfg_raw($cfg, $key);
    if ($raw === null) {
        return $default;
    }
    $val = trim((string)$raw);
    return $val === '' ? $default : $val;
}

function cfg_int(array $cfg, string $key, int $default): int {
    $raw = cfg_raw($cfg, $key);
    if ($raw === null || $raw === '') {
        return $default;
    }
    return (int)$raw;
}

function cfg_bool(array $cfg, string $key, bool $default): bool {
    $raw = cfg_raw($cfg, $key);
    if ($raw === null || $raw === '') {
        return $default;
    }
    if (is_bool($raw)) {
        return $raw;
    }
    $norm = strtolower(trim((string)$raw));
    if (in_array($norm, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($norm, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }
    return $default;
}
