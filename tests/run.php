<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/support.php';
require __DIR__ . '/inspection.php';
require __DIR__ . '/lifecycle.php';
require __DIR__ . '/integration.php';

$failed = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS $name\n";
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n");
    }
}
echo count($tests) . ' tests; ' . $failed . " failures\n";
exit($failed === 0 ? 0 : 1);
