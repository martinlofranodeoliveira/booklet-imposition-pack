<?php
declare(strict_types=1);

use Lofrano\Booklet\Failure;
use Lofrano\Booklet\Fixtures;
use Lofrano\Booklet\Imposer;
use Lofrano\Booklet\OwnedStore;
use Lofrano\Booklet\Runner;

$tests = [];
function test(string $name, Closure $body): void
{
    global $tests;
    $tests[$name] = $body;
}
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
}
function fails(Closure $body, ?string $reason = null): Failure
{
    try {
        $body();
    } catch (Failure $e) {
        if ($reason !== null) {
            same($reason, $e->reason, 'stable error');
        }
        return $e;
    }
    throw new RuntimeException('expected failure');
}
function sandbox(): array
{
    $base = sys_get_temp_dir() . '/booklet-test-' . bin2hex(random_bytes(10));
    check(mkdir($base, 0700), 'create test directory');
    check(mkdir($base . '/out', 0700), 'create output root');
    Fixtures::write($base . '/source.pdf');
    return [$base, $base . '/source.pdf', $base . '/out'];
}
function completed(string $root): int
{
    $n = 0;
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        if ($entry->isDir() && !$entry->isLink() && is_file($entry->getPathname() . '/.booklet-complete.json')) {
            ++$n;
        }
    }
    return $n;
}
function pairs(array $map): array
{
    return array_map(static fn(array $side): array => array_map(static fn(array $slot): ?int => $slot['occurrence']['page'] ?? null, $side['slots']), $map['sides']);
}
function imposeCase(string $source, string $root, string $name, array $pages, int $signature = 8, array $geometry = ['width' => 600, 'height' => 400]): array
{
    return (new Imposer())->impose($source, $pages, $signature, $geometry, ['root' => $root, 'name' => $name]);
}
/** Generate variants through qpdf's JSON writer, retaining {} as stdClass. */
function variant(string $base, string $kind): string
{
    $root = $base . '/fixture-' . $kind;
    check(mkdir($root, 0700), 'fixture root');
    $store = new OwnedStore($root, hrtime(true) + 20000000000);
    $stage = $store->stage();
    $rect = in_array($kind, ['crop', 'unit'], true);
    Fixtures::write($stage . '/work/fixture-base.pdf', $rect ? 'rectangle' : 'gray');
    $runner = new Runner($stage, hrtime(true) + 20000000000);
    if ($kind === 'compressed') {
        $runner->run('fixture-compress');
    } elseif ($kind === 'encrypted') {
        $runner->run('fixture-encrypt');
    } else {
        $json = json_decode($runner->run('fixture-json'), false, 128, JSON_THROW_ON_ERROR);
        foreach ($json->qpdf[1] as $object) {
            if (isset($object->value) && is_object($object->value) && ($object->value->{'/Type'} ?? null) === '/Page') {
                if ($kind === 'crop') {
                    $object->value->{'/CropBox'} = [10, 20, 190, 80];
                    $object->value->{'/Rotate'} = 90;
                }
                if ($kind === 'unit') {
                    $object->value->{'/UserUnit'} = 2;
                }
            }
        }
        if ($kind === 'unreferenced') {
            $id = ++$json->qpdf[0]->maxobjectid;
            $json->qpdf[1]->{'obj:' . $id . ' 0 R'} = (object) ['value' => (object) ['/Type' => '/Action', '/S' => '/JavaScript', '/JS' => 'u:synthetic inert test string']];
            $json->qpdf[1]->trailer->value->{'/Size'} = $id + 1;
        }
        Failure::write($stage . '/work/fixture.json', Failure::json($json));
        $runner->run('fixture-write');
    }
    $target = $base . '/' . $kind . '.pdf';
    check(copy($stage . '/work/fixture.pdf', $target), 'copy fixture out of owned namespace');
    same(1, $store->recover(), 'fixture staging recovered');
    unset($store);
    return $target;
}
/** Independent folding oracle: unwrap sheet faces into reading positions. */
function unfold(array $sides, int $size): array
{
    $reading = [];
    $fronts = [];
    $backs = [];
    foreach ($sides as $side) {
        if ($side['face'] === 'front') {
            $fronts[] = $side['slots'];
        } else {
            $backs[] = $side['slots'];
        }
    }
    // Reading half climbs outer-to-inner right fronts then left backs.
    foreach ($fronts as $i => $front) {
        $reading[] = $front[1]['occurrence']['ordinal'] ?? null;
        $reading[] = $backs[$i][0]['occurrence']['ordinal'] ?? null;
    }
    // The other half unwinds the nested sheets in reverse order.
    foreach (array_reverse(array_keys($fronts)) as $i) {
        $reading[] = $backs[$i][1]['occurrence']['ordinal'] ?? null;
        $reading[] = $fronts[$i][0]['occurrence']['ordinal'] ?? null;
    }
    same($size, count($reading), 'fold has a whole signature');
    return $reading;
}
