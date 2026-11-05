<?php
declare(strict_types=1);

use Lofrano\Booklet\Failure;
use Lofrano\Booklet\Imposer;
use Lofrano\Booklet\OwnedStore;
use Lofrano\Booklet\Runner;

function sealed(Runner $runner): void
{
    $report = $runner->lastReport;
    check(is_array($report) && $report['children_empty'] && $report['sealed'] && $report['subreaper'], 'confirmed owned child cleanup');
    $trace = $report['trace'];
    $seal = array_search('seal_signals', $trace, true);
    $term = array_search('signal_SIGTERM', $trace, true);
    $kill = array_search('signal_SIGKILL', $trace, true);
    check(is_int($seal) && is_int($term) && is_int($kill) && $term < $kill && $kill < $seal, 'all signals precede seal');
    foreach ($trace as $i => $event) {
        if (str_starts_with($event, 'reap_')) {
            check($i > $seal, 'no reaping while signal authority open');
        }
    }
}

test('retained anchor normal exit, worker hang with descendant, and bounded flood', function (): void {
    [$base, $source, $root] = sandbox();
    $store = new OwnedStore($root, hrtime(true) + 20000000000);
    $stage = $store->stage();
    $normal = new Runner($stage, hrtime(true) + 3000000000);
    same("fixed helper complete\n", $normal->run('test-ok'), 'fixed normal helper');
    sealed($normal);
    $hang = new Runner($stage, hrtime(true) + 800000000);
    $start = hrtime(true);
    fails(fn() => $hang->run('test-worker-hang'), 'workflow_deadline');
    check(hrtime(true) - $start < 4000000000, 'hang preempted');
    sealed($hang);
    $flood = new Runner($stage, hrtime(true) + 3000000000);
    fails(fn() => $flood->run('test-flood'), 'capture_limit');
    sealed($flood);
    same(1, $store->recover(), 'lifecycle test stage recovery');
});

test('renderer failure after PDF staging publishes nothing; recovery removes exactly one', function (): void {
    [$base, $source, $root] = sandbox();
    $hash = hash_file('sha256', $source);
    mkdir($root . '/.booklet-stage-unrelated', 0700);
    file_put_contents($root . '/.booklet-stage-unrelated/keep', 'unrelated');
    $imposer = new Imposer();
    $reflection = new ReflectionProperty(Imposer::class, 'failRenderer');
    $reflection->setValue($imposer, true);
    fails(fn() => $imposer->impose($source, range(1, 8), 8, ['width' => 600, 'height' => 400], ['root' => $root, 'name' => 'failed']), 'tool_failed');
    same(0, completed($root), 'failed renderer cannot publish');
    $stagedPdf = false;
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && is_file($entry->getPathname() . '/booklet.pdf')) {
            $stagedPdf = true;
        }
    }
    check($stagedPdf, 'failure occurred after PDF staging');
    same($hash, hash_file('sha256', $source), 'failed workflow leaves source untouched');
    same(1, $imposer->recover($root), 'recover exactly one owned stage');
    check(is_file($root . '/.booklet-stage-unrelated/keep'), 'unrelated prefix retained');
    same(0, $imposer->recover($root), 'recovery idempotent');
    $result = imposeCase($source, $root, 'normal', range(1, 8));
    same(4, count($result['map']['sides']), 'subsequent normal run');
    same(0, $imposer->recover($root), 'completed outputs never recovered');
    same(1, completed($root), 'completed result retained');
});

test('recovery ignores forged markers and symlinks; sources in owned namespace rejected', function (): void {
    [$base, $source, $root] = sandbox();
    $store = new OwnedStore($root, hrtime(true) + 20000000000);
    $stage = $store->stage();
    check(symlink($source, $stage . '/work/link'), 'test symlink');
    $forged = $root . '/.booklet-stage-' . str_repeat('a', 32);
    mkdir($forged, 0700);
    file_put_contents($forged . '/.booklet-stage.json', '{"app":"lofrano-booklet-imposition-pack/1","token":"wrong"}');
    same(0, $store->recover(), 'symlink and forged stage not deleted');
    unset($store);
    check(is_link($stage . '/work/link'), 'symlink retained without following');
    check(is_file($source), 'source retained');
    check(copy($source, $root . '/source.pdf'), 'copy owned source');
    fails(fn() => imposeCase($root . '/source.pdf', $root, 'unsafe', [1], 4), 'source_namespace');
    check(symlink($source, $base . '/linked.pdf'), 'linked input');
    fails(fn() => imposeCase($base . '/linked.pdf', $root, 'linked', [1], 4), 'local_path');
});

test('cooperative lock has deadline and completed names cannot be overwritten', function (): void {
    [$base, $source, $root] = sandbox();
    $one = new OwnedStore($root, hrtime(true) + 2000000000);
    fails(fn() => new OwnedStore($root, hrtime(true) + 100000000), 'workflow_deadline');
    unset($one);
    $result = imposeCase($source, $root, 'one', [1, 2, 3, 4], 4);
    $hash = hash_file('sha256', $result['directory'] . '/booklet.pdf');
    fails(fn() => imposeCase($source, $root, 'one', [5, 6, 7, 8], 4), 'bundle_exists');
    same($hash, hash_file('sha256', $result['directory'] . '/booklet.pdf'), 'no overwrite');
});


test('helper resets inherited ignored SIGCHLD before acquiring child signal authority', function (): void {
    [$base, $source, $root] = sandbox();
    $store = new OwnedStore($root, hrtime(true) + 20000000000);
    $stage = $store->stage();
    // The PHP parent remains the normal sole reaper of its direct Python helper.
    // Only the exec launcher marks SIGCHLD ignored; the actual helper must reset it.
    $launcher = "import os,signal,sys;signal.signal(signal.SIGCHLD,signal.SIG_IGN);os.execv('/usr/bin/python3',['/usr/bin/python3',*sys.argv[1:]])";
    $command = ['/usr/bin/python3', '-I', '-c', $launcher, dirname(__DIR__) . '/internal/supervisor.py', 'test-ok', $stage, PHP_BINARY, '3000', '1'];
    $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'], 3 => ['pipe', 'r'], 4 => ['pipe', 'w']];
    $process = proc_open($command, $spec, $pipes, $stage . '/work', ['LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8']);
    check(is_resource($process), 'native inherited-signal launcher');
    $stdout = stream_get_contents($pipes[1], 65536);
    $stderr = stream_get_contents($pipes[2], 65536);
    $evidence = stream_get_contents($pipes[4], 16384);
    foreach ($pipes as $pipe) { fclose($pipe); }
    same(0, proc_close($process), 'helper exits naturally after retained-child cleanup');
    same("fixed helper complete\n", $stdout, 'real fixed child ran');
    same('', $stderr, 'no native signal/reaping error');
    $report = json_decode($evidence, true, 32, JSON_THROW_ON_ERROR);
    same('exit', $report['reason'], 'normal child completion observed');
    same(0, $report['exit'], 'actual child exit status retained');
    check(in_array('observe_without_reap', $report['trace'], true), 'unreaped child observation retained despite inherited SIGCHLD ignore');
    $runner = new Runner($stage, hrtime(true) + 3000000000);
    $runner->lastReport = $report;
    sealed($runner);
    same(1, $store->recover(), 'owned test stage recovered');
});
