#!/usr/bin/python3
"""Retained Linux child-group anchor. Internal fixed profiles, not an exec API."""
import ctypes
import json
import math
import os
from pathlib import Path
import resource
import select
import signal
import subprocess
import sys
import time


def main():
    if len(sys.argv) != 6:
        raise ValueError('configuration')
    profile, directory, php, budget_text, page_text = sys.argv[1:]
    stage = Path(directory)
    if not stage.is_absolute() or stage.resolve() != stage:
        raise ValueError('namespace')
    marker = stage / '.booklet-stage.json'
    if marker.is_symlink() or marker.stat().st_size > 4096:
        raise ValueError('namespace')
    identity = json.loads(marker.read_text())
    if identity.get('app') != 'lofrano-booklet-imposition-pack/1' or identity.get('stage') != stage.name:
        raise ValueError('namespace')
    if php not in ('/usr/local/bin/php', '/usr/bin/php8.4'):
        raise ValueError('php_binary')
    budget = min(20.0, max(0.001, int(budget_text) / 1000.0))
    page = int(page_text)
    if not 1 <= page <= 32:
        raise ValueError('page')
    work = stage / 'work'
    if not work.is_dir() or work.is_symlink():
        raise ValueError('namespace')
    package = Path(__file__).resolve().parent
    source = str(work / 'input.pdf')
    normalized = str(work / 'normalized.pdf')
    output = str(stage / 'booklet.pdf')
    fixture_base = str(work / 'fixture-base.pdf')
    fixture_output = str(work / 'fixture.pdf')
    qpdf = '/usr/bin/qpdf'
    inspect = ['--json=2', '--json-stream-data=none']
    profiles = {
        'inspect-source': [qpdf, *inspect, source],
        'normalize': [qpdf, '--object-streams=disable', source, normalized],
        'inspect-normalized': [qpdf, *inspect, normalized],
        'check-output': [qpdf, '--check', output],
        'inspect-output': [qpdf, *inspect, output],
        'worker': [php, '-d', 'memory_limit=256M', '-d', 'display_errors=0', '-d', 'log_errors=0', str(package / 'worker.php'), str(stage)],
        'text': ['/usr/bin/pdftotext', '-f', str(page), '-l', str(page), '-layout', '-enc', 'UTF-8', output, '-'],
        'render': ['/usr/bin/pdftoppm', '-f', str(page), '-l', str(page), '-singlefile', '-r', '72', '-png', output, str(work / 'raster')],
        'version-qpdf': [qpdf, '--version'],
        'version-poppler': ['/usr/bin/pdftoppm', '-v'],
        'fixture-json': [qpdf, '--json=2', '--json-stream-data=inline', fixture_base],
        'fixture-write': [qpdf, '--json-input', '--preserve-unreferenced', str(work / 'fixture.json'), fixture_output],
        'fixture-compress': [qpdf, '--object-streams=generate', '--stream-data=compress', fixture_base, fixture_output],
        'fixture-encrypt': [qpdf, '--encrypt', '', 'synthetic-owner', '256', '--', fixture_base, fixture_output],
        'test-ok': [php, str(package / 'failure.php'), 'ok'],
        'test-worker-hang': [php, '-d', 'memory_limit=256M', str(package / 'failure.php'), 'hang'],
        'test-flood': [php, str(package / 'failure.php'), 'flood'],
        'test-fail-render': [php, str(package / 'failure.php'), 'render'],
    }
    if profile not in profiles:
        raise ValueError('profile')
    libc = ctypes.CDLL(None, use_errno=True)
    libc.prctl.argtypes = [ctypes.c_int, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong, ctypes.c_ulong]
    libc.prctl.restype = ctypes.c_int
    if libc.prctl(36, 1, 0, 0, 0) != 0:
        raise OSError('subreaper')
    # Ignored SIGCHLD survives exec and would auto-reap our leader before WNOWAIT.
    # This single-threaded helper must be the sole reaper until signal authority is sealed.
    signal.signal(signal.SIGCHLD, signal.SIG_DFL)
    requested = [False]

    def stop(_sig, _frame):
        requested[0] = True

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    file_limit = 8388608 if profile == 'render' else 67108864

    def limits():
        os.umask(0o077)
        resource.setrlimit(resource.RLIMIT_AS, (805306368, 805306368))
        resource.setrlimit(resource.RLIMIT_FSIZE, (file_limit, file_limit))
        resource.setrlimit(resource.RLIMIT_NOFILE, (64, 64))
        cpu = max(1, math.ceil(budget))
        resource.setrlimit(resource.RLIMIT_CPU, (cpu, cpu))
        resource.setrlimit(resource.RLIMIT_CORE, (0, 0))

    env = {'PATH': '/usr/bin:/bin', 'HOME': str(work), 'TMPDIR': str(work), 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}
    trace = []
    # Popen must not poll, communicate, or wait before the signal authority is sealed.
    child = subprocess.Popen(profiles[profile], stdin=subprocess.DEVNULL, stdout=1, stderr=2,
                             cwd=work, env=env, start_new_session=True, close_fds=True,
                             preexec_fn=limits)
    deadline = time.monotonic() + max(0.001, budget - 0.20)
    reason = 'exit'
    cleanup_ok = False
    exit_code = None
    adopted = 0
    try:
        while True:
            status = os.waitid(os.P_PID, child.pid, os.WEXITED | os.WNOHANG | os.WNOWAIT)
            if status is not None:
                trace.append('observe_without_reap')
                break
            ready, _, _ = select.select([3], [], [], 0.01)
            if ready:
                os.read(3, 128)
                reason = 'stop'
                trace.append('control_stop_or_eof')
                break
            if requested[0] or time.monotonic() >= deadline:
                reason = 'deadline'
                trace.append('deadline_or_signal')
                break
    finally:
        # The unreaped leader retains its PID. No existence probe authorizes signals.
        for sig in (signal.SIGTERM, signal.SIGKILL):
            trace.append('signal_' + sig.name)
            try:
                os.killpg(child.pid, sig)
            except ProcessLookupError:
                pass
            if sig == signal.SIGTERM:
                time.sleep(0.03)
        trace.append('seal_signals')
        exit_code = child.wait(timeout=2)
        trace.append('reap_leader')
        until = time.monotonic() + 2
        while True:
            try:
                pid, _status = os.waitpid(-1, os.WNOHANG)
            except ChildProcessError:
                cleanup_ok = True
                break
            if pid:
                adopted += 1
                trace.append('reap_adopted')
                continue
            if time.monotonic() >= until:
                break
            time.sleep(0.005)
    report = {'reason': reason, 'exit': exit_code, 'sealed': True,
              'children_empty': cleanup_ok, 'adopted_reaped': adopted,
              'trace': trace, 'subreaper': True}
    os.write(4, (json.dumps(report, separators=(',', ':')) + '\n').encode())
    return 0 if cleanup_ok else 1


if __name__ == '__main__':
    try:
        sys.exit(main())
    except Exception:
        # No source paths, tool output, or PDF contents in diagnostics.
        try:
            os.write(4, b'{"sealed":false,"children_empty":false}\n')
        except OSError:
            pass
        sys.exit(1)
