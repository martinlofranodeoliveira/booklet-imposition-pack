<?php
declare(strict_types=1);

$autoload = is_file(__DIR__ . '/../vendor/autoload.php') ? __DIR__ . '/../vendor/autoload.php' : __DIR__ . '/../../../autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "WORKER_ERROR:dependency_missing\n");
    exit(1);
}
require $autoload;
try {
    Lofrano\Booklet\Failure::need($argc === 2, 'worker_configuration');
    Lofrano\Booklet\Worker::execute($argv[1]);
} catch (Lofrano\Booklet\Failure $error) {
    fwrite(STDERR, 'WORKER_ERROR:' . $error->reason . "\n");
    exit(1);
} catch (Throwable) {
    fwrite(STDERR, "WORKER_ERROR:parser_failure\n");
    exit(1);
}
