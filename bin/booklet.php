#!/usr/bin/env php
<?php
declare(strict_types=1);

$autoload = $GLOBALS['_composer_autoload_path'] ?? (is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../../autoload.php');
if (!is_file($autoload)) {
    fwrite(STDERR, "dependency_missing: run composer install --no-plugins --no-scripts\n");
    exit(1);
}
require $autoload;

use Lofrano\Booklet\Demo;
use Lofrano\Booklet\Failure;
use Lofrano\Booklet\Imposer;
use Lofrano\Booklet\Selection;

try {
    $args = $argv;
    array_shift($args);
    $command = array_shift($args) ?? 'help';
    if ($command === 'help' && !$args) {
        echo "Booklet Imposition Pack development 0.1.0\n";
        echo "demo [--assert] [--json] [--output NEW_DIRECTORY]\n";
        echo "impose --input PDF --pages 2-5 --signature 4 --width 600 --height 400 --root EXISTING_DIRECTORY --name booklet [--back-rotation 180] [--json]\n";
        echo "recover --root EXISTING_DIRECTORY [--json]\n";
        exit(0);
    }
    $allowed = match ($command) {
        'demo' => ['assert', 'json', 'output'],
        'impose' => ['input', 'pages', 'signature', 'width', 'height', 'root', 'name', 'back-rotation', 'json'],
        'recover' => ['root', 'json'],
        default => throw new Failure('cli_command')
    };
    Failure::need(count($args) <= 24, 'cli_arguments');
    $options = [];
    while ($args) {
        $arg = array_shift($args);
        Failure::need(str_starts_with($arg, '--'), 'cli_arguments');
        $key = substr($arg, 2);
        Failure::need(in_array($key, $allowed, true) && !array_key_exists($key, $options), 'cli_arguments');
        if (in_array($key, ['json', 'assert'], true)) {
            $options[$key] = true;
        } else {
            $value = array_shift($args);
            Failure::need(is_string($value) && strlen($value) <= 4096 && !str_starts_with($value, '--'), 'cli_arguments');
            $options[$key] = $value;
        }
    }
    if ($command === 'demo') {
        $output = $options['output'] ?? sys_get_temp_dir() . '/booklet-demo-' . bin2hex(random_bytes(8));
        $result = Demo::run($output, isset($options['assert']));
        if (isset($options['json'])) {
            echo Failure::json($result);
        } else {
            echo "Booklet Imposition Pack development 0.1.0\n";
            echo 'Verified: ' . ($result['verified'] ? 'yes' : 'no') . "\nSheets: 2; sides: 4\nPairs: [8,1] [2,7] [6,3] [4,5]\n";
            echo "First side: 600 x 400 pixels; markers P8 P1\nSamples: 160,160,160 / 20,20,20 / 255,255,255\n";
            echo 'Bundle: ' . $result['bundle'] . "\nVisual inspection: not performed by this command\n";
        }
    } elseif ($command === 'recover') {
        Failure::need(isset($options['root']), 'cli_arguments');
        $result = ['removed_staging_directories' => (new Imposer())->recover($options['root'])];
        echo isset($options['json']) ? Failure::json($result) : 'Removed marked staging directories: ' . $result['removed_staging_directories'] . "\n";
    } else {
        foreach (['input', 'pages', 'signature', 'width', 'height', 'root', 'name'] as $key) {
            Failure::need(isset($options[$key]), 'cli_arguments');
        }
        Failure::need(preg_match('/^(4|8|16)$/D', $options['signature']) === 1, 'signature_size');
        foreach (['width', 'height'] as $key) {
            Failure::need(preg_match('/^[0-9]{1,4}(?:\.[0-9]{1,6})?$/D', $options[$key]) === 1, 'sheet_geometry');
        }
        Failure::need(in_array($options['back-rotation'] ?? '0', ['0', '180'], true), 'back_rotation');
        $result = (new Imposer())->impose($options['input'], Selection::ranges($options['pages']), (int) $options['signature'], ['width' => (float) $options['width'], 'height' => (float) $options['height'], 'back_rotation' => (int) ($options['back-rotation'] ?? '0')], ['root' => $options['root'], 'name' => $options['name']]);
        echo isset($options['json']) ? Failure::json($result) : 'Completed bundle: ' . $result['directory'] . "\nSides: " . count($result['map']['sides']) . "\n";
    }
} catch (Failure $error) {
    fwrite(STDERR, Failure::json(['error' => $error->reason]));
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "{\"error\":\"unexpected_failure\"}\n");
    exit(1);
}
