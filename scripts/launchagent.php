#!/usr/bin/env php
<?php

declare(strict_types=1);

main($argv);

function main(array $argv): void {
    $cmd = $argv[1] ?? 'help';

    $ctx = build_context();
    $dest = $ctx['launch_agents_dir'] . '/' . $ctx['label'] . '.plist';

    switch ($cmd) {
        case 'add':
            $xml = render_template($ctx);
            write_file($dest, $xml);
            echo "Wrote {$dest}\n";
            return;

        case 'load':
            ensure_exists($dest);
            run("launchctl bootstrap gui/{$ctx['uid']} " . escapeshellarg($dest));
            echo "Loaded {$ctx['label']}\n";
            return;

        case 'unload':
            run("launchctl bootout gui/{$ctx['uid']}/{$ctx['label']}", false);
            echo "Unloaded {$ctx['label']}\n";
            return;

        case 'reload':
            run("launchctl bootout gui/{$ctx['uid']}/{$ctx['label']}", false);
            ensure_exists($dest);
            run("launchctl bootstrap gui/{$ctx['uid']} " . escapeshellarg($dest));
            echo "Reloaded {$ctx['label']}\n";
            return;

        case 'kick':
            run("launchctl kickstart -k gui/{$ctx['uid']}/{$ctx['label']}");
            echo "Kicked {$ctx['label']}\n";
            return;

        case 'status':
            run("launchctl print gui/{$ctx['uid']}/{$ctx['label']}", false);
            return;

        case 'path':
            echo $dest . "\n";
            return;

        case 'help':
        default:
            echo "Usage: composer run launchagent -- <add|load|unload|reload|kick|status|path>\n";
            echo "Env overrides:\n";
            echo "  OPHANIEL_LAUNCHAGENT_LABEL (default: com.mattwiebe.ophaniel)\n";
            echo "  OPHANIEL_LAUNCHAGENT_INTERVAL (default: 300)\n";
            return;
    }
}

function build_context(): array {
    $root = realpath(__DIR__ . '/..');
    if (!is_string($root) || $root === '') {
        throw new RuntimeException('Cannot resolve project root');
    }

    $uid = (string)posix_getuid();
    $label = trim((string)(getenv('OPHANIEL_LAUNCHAGENT_LABEL') ?: 'com.mattwiebe.ophaniel'));
    $interval = (int)(getenv('OPHANIEL_LAUNCHAGENT_INTERVAL') ?: 300);
    if ($interval < 5) {
        $interval = 5;
    }

    $home = getenv('HOME') ?: '';
    if ($home === '') {
        throw new RuntimeException('HOME is not set');
    }

    return [
        'uid' => $uid,
        'label' => $label,
        'interval' => $interval,
        'home' => $home,
        'path' => normalize_path((string)(getenv('PATH') ?: '')),
        'xdg_cache_home' => $home . '/.cache',
        'hf_home' => $home . '/.cache/huggingface',
        'working_dir' => $root,
        'php_bin' => PHP_BINARY,
        'pipeline_bin' => $root . '/ophaniel.php',
        'stdout' => $root . '/ophaniel.stdout.log',
        'stderr' => $root . '/ophaniel.stderr.log',
        'launch_agents_dir' => $home . '/Library/LaunchAgents',
        'template' => $root . '/launchagent.template.plist',
    ];
}

function render_template(array $ctx): string {
    $tpl = (string)file_get_contents($ctx['template']);
    if ($tpl === '') {
        throw new RuntimeException('Cannot read template: ' . $ctx['template']);
    }

    $replace = [
        '{{LABEL}}' => xml((string)$ctx['label']),
        '{{PHP_BIN}}' => xml((string)$ctx['php_bin']),
        '{{PIPELINE_BIN}}' => xml((string)$ctx['pipeline_bin']),
        '{{HOME}}' => xml((string)$ctx['home']),
        '{{PATH}}' => xml((string)$ctx['path']),
        '{{XDG_CACHE_HOME}}' => xml((string)$ctx['xdg_cache_home']),
        '{{HF_HOME}}' => xml((string)$ctx['hf_home']),
        '{{WORKING_DIR}}' => xml((string)$ctx['working_dir']),
        '{{INTERVAL}}' => (string)$ctx['interval'],
        '{{STDOUT}}' => xml((string)$ctx['stdout']),
        '{{STDERR}}' => xml((string)$ctx['stderr']),
    ];
    return strtr($tpl, $replace);
}

function xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function write_file(string $path, string $contents): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write file: ' . $path);
    }
}

function ensure_exists(string $path): void {
    if (!is_file($path)) {
        throw new RuntimeException("Missing launch agent plist: {$path}. Run add first.");
    }
}

function run(string $cmd, bool $throwOnError = true): void {
    passthru($cmd, $code);
    if ($throwOnError && $code !== 0) {
        throw new RuntimeException("Command failed ({$code}): {$cmd}");
    }
}

function normalize_path(string $path): string {
    $base = '/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin';
    $path = trim($path);
    if ($path === '') {
        return $base;
    }
    if (str_contains($path, '/opt/homebrew/bin')) {
        return $path;
    }
    return $base . ':' . $path;
}
