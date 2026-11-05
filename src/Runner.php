<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

/** @internal Only the enumerated package profiles are supported. */
final class Runner
{
    public ?array $lastReport = null;
    public array $evidence = [];

    public function __construct(private readonly string $stage, private readonly int $deadline)
    {
    }

    public function run(string $profile, int $page = 1): string
    {
        Failure::time($this->deadline);
        $profiles = ['inspect-source', 'normalize', 'inspect-normalized', 'check-output', 'inspect-output', 'worker', 'text', 'render', 'version-qpdf', 'version-poppler', 'fixture-json', 'fixture-write', 'fixture-compress', 'fixture-encrypt', 'test-ok', 'test-worker-hang', 'test-flood', 'test-fail-render'];
        Failure::need(in_array($profile, $profiles, true) && $page >= 1 && $page <= 32, 'internal_profile');
        $remaining = max(1, (int) (($this->deadline - hrtime(true)) / 1000000));
        $command = ['/usr/bin/python3', '-I', dirname(__DIR__) . '/internal/supervisor.py', $profile, $this->stage, PHP_BINARY, (string) $remaining, (string) $page];
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r'], 4 => ['pipe', 'w']];
        $process = @proc_open($command, $spec, $pipes, $this->stage . '/work', ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
        Failure::need(is_resource($process), 'supervisor_start');
        foreach ([1, 2, 3, 4] as $fd) {
            stream_set_blocking($pipes[$fd], false);
        }
        $cap = str_starts_with($profile, 'inspect-') || $profile === 'fixture-json' ? 33554432 : ($profile === 'text' ? 1048576 : 65536);
        $caps = [1 => $cap, 2 => 65536, 4 => 16384];
        $buffers = [1 => '', 2 => '', 4 => ''];
        $overflow = false;
        $stopped = false;
        $ioFailure = false;
        // Do not reap or signal a numeric PID here. The retained helper owns that authority.
        while (true) {
            $read = [];
            foreach ([1, 2, 4] as $fd) {
                if (!feof($pipes[$fd])) {
                    $read[] = $pipes[$fd];
                }
            }
            if (!$read) {
                break;
            }
            if (($overflow || $ioFailure || hrtime(true) >= $this->deadline) && !$stopped) {
                @fwrite($pipes[3], "STOP\n");
                fclose($pipes[3]);
                $stopped = true;
            }
            $write = $except = [];
            $ready = @stream_select($read, $write, $except, 0, 20000);
            if ($ready === false) {
                $ioFailure = true;
                usleep(1000);
                continue;
            }
            foreach ($read as $stream) {
                $fd = array_search($stream, $pipes, true);
                $data = fread($stream, 65536);
                if ($data === false) {
                    $ioFailure = true;
                    continue;
                }
                $room = $caps[$fd] - strlen($buffers[$fd]);
                if (strlen($data) > $room) {
                    $overflow = true;
                }
                if ($room > 0) {
                    $buffers[$fd] .= substr($data, 0, $room);
                }
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $helperExit = proc_close($process);
        try {
            $report = json_decode($buffers[4], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new Failure('supervisor_cleanup');
        }
        $this->lastReport = is_array($report) ? $report : null;
        Failure::need(is_array($report) && $helperExit === 0 && ($report['sealed'] ?? false) === true && ($report['children_empty'] ?? false) === true, 'supervisor_cleanup');
        $this->evidence[] = ['profile' => $profile, 'exit' => $report['exit'], 'sealed_before_reap' => true, 'children_empty' => true];
        Failure::need(!$overflow, 'capture_limit');
        Failure::need(!$ioFailure, 'supervisor_io');
        Failure::need(($report['reason'] ?? '') === 'exit' && hrtime(true) < $this->deadline, 'workflow_deadline');
        if (($report['exit'] ?? -1) !== 0) {
            if ($profile === 'worker' && preg_match('/^WORKER_ERROR:([a-z_]+)\s*$/D', $buffers[2], $m) === 1 && in_array($m[1], ['source_geometry', 'source_page_count', 'worker_configuration', 'storage_write', 'json_encoding'], true)) {
                throw new Failure($m[1]);
            }
            throw new Failure('tool_failed');
        }
        if (!str_starts_with($profile, 'version-')) {
            Failure::need(trim($buffers[2]) === '', 'tool_warning');
        }
        return str_starts_with($profile, 'version-') ? trim($buffers[1] . $buffers[2]) : $buffers[1];
    }
}
