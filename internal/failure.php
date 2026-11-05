<?php
declare(strict_types=1);
// Fixed synthetic regression programs. Not selected by public CLI options.
$mode = $argv[1] ?? '';
if ($mode === 'ok') {
    echo "fixed helper complete\n";
    exit(0);
}
if ($mode === 'render') {
    fwrite(STDERR, "fixed renderer failure\n");
    exit(23);
}
if ($mode === 'flood') {
    while (true) {
        fwrite(STDOUT, str_repeat('x', 65536));
    }
}
if ($mode === 'hang') {
    $child = proc_open([PHP_BINARY, __FILE__, 'child'], [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($child)) {
        exit(2);
    }
    while (true) {
        usleep(100000);
    }
}
if ($mode === 'child') {
    while (true) {
        usleep(100000);
    }
}
exit(2);
