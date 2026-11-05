<?php
declare(strict_types=1);

use Lofrano\Booklet\Fixtures;
use Lofrano\Booklet\Imposer;
use Lofrano\Booklet\Inspector;
use Lofrano\Booklet\OwnedStore;
use Lofrano\Booklet\Runner;

 test('mature inspection rejects links, encryption, unreferenced actions and nonunit UserUnit', function (): void {
    [$base, $source, $root] = sandbox();
    Fixtures::write($base . '/link.pdf', 'link');
    $inputs = [$base . '/link.pdf', variant($base, 'encrypted'), variant($base, 'unreferenced'), variant($base, 'unit')];
    foreach ($inputs as $i => $input) {
        $hash = hash_file('sha256', $input);
        fails(fn() => imposeCase($input, $root, 'reject' . $i, [1], 4));
        same($hash, hash_file('sha256', $input), 'rejected input immutable');
        same(0, completed($root), 'unsupported content publishes nothing');
    }
    same(4, (new Imposer())->recover($root), 'only four failed stages');
});

test('compressed xref is normalized before FPDI import', function (): void {
    [$base, $source, $root] = sandbox();
    $input = variant($base, 'compressed');
    $directRejected = false;
    try {
        $pdf = new setasign\Fpdi\Fpdi();
        $pdf->setSourceFile($input);
    } catch (setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException) {
        $directRejected = true;
    }
    check($directRejected, 'qualified free parser limitation exercised');
    $result = imposeCase($input, $root, 'normalized', range(1, 8));
    same([[8, 1], [2, 7], [6, 3], [4, 5]], pairs($result['map']), 'normalization preserves pages');
    same(hash('sha256', 'P8 P1'), $result['validation']['pages'][0]['text_sha256'], 'independent text after normalization');
});

test('CropBox and source Rotate come from FPDI, not a raw syntax parser', function (): void {
    [$base, $source, $root] = sandbox();
    $input = variant($base, 'crop');
    $result = imposeCase($input, $root, 'cropped', [1], 4);
    $slot = $result['map']['sides'][0]['slots'][1];
    same(['width' => 60.0, 'height' => 180.0], $slot['source_size'], 'qualified crop/rotation dimensions');
    $r = $slot['placement'];
    check(abs($r['width'] / $r['height'] - 1 / 3) < 1e-12, 'aspect preserved');
    check(abs($r['top']) < 1e-12 && abs($r['height'] - 400) < 1e-12, 'fit vertically');
});

test('malformed bytes fail mature parsing without publication', function (): void {
    [$base, $source, $root] = sandbox();
    file_put_contents($base . '/malformed.pdf', "not a PDF\n");
    fails(fn() => imposeCase($base . '/malformed.pdf', $root, 'bad', [1], 4));
    same(0, completed($root), 'no malformed output');
});

test('inspection schema and traversal limits fail closed', function (): void {
    fails(fn() => Inspector::inspect('{"version":1}'), 'inspection_schema');
    fails(fn() => Inspector::inspect('{'), 'inspection_json');
    fails(fn() => Inspector::inspect(str_repeat(' ', 33554433)), 'inspection_limit');
});
