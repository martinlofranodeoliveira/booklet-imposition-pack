<?php
declare(strict_types=1);

use Lofrano\Booklet\Geometry;
use Lofrano\Booklet\Imposer;
use Lofrano\Booklet\Planner;
use Lofrano\Booklet\Selection;

 test('eight pages: manual slots, exact placement, actual structure, text and decoded pixels', function (): void {
    [$base, $source, $root] = sandbox();
    $inputHash = hash_file('sha256', $source);
    $result = imposeCase($source, $root, 'eight', range(1, 8));
    $map = $result['map'];
    same([[8, 1], [2, 7], [6, 3], [4, 5]], pairs($map), 'manual eight-page oracle');
    same(4, count($map['sides']), 'two sheets, four sides');
    same(0, $map['blank_slots'], 'no padding');
    foreach ($map['sides'] as $side) {
        foreach ($side['slots'] as $i => $slot) {
            same(['x' => (float) ($i * 300), 'top' => 50.0, 'width' => 300.0, 'height' => 300.0], $slot['placement'], 'square fit');
        }
    }
    $report = $result['validation'];
    same(0, $report['checks']['qpdf_exit'], 'actual qpdf --check exit');
    same(hash('sha256', 'P8 P1'), $report['pages'][0]['text_sha256'], 'exact normalized first side text');
    same([600, 400], $report['pages'][0]['pixels'], 'actual raster size');
    same([[160, 160, 160], [20, 20, 20], [255, 255, 255]], array_column($report['pages'][0]['samples'], 'rgb'), 'exact GD-decoded Poppler samples');
    $png = imagecreatefrompng($result['directory'] . '/first-side.png');
    same(600, imagesx($png), 'retained PNG width');
    imagedestroy($png);
    same($inputHash, hash_file('sha256', $source), 'source hash unchanged');
    check(is_file($result['directory'] . '/.booklet-complete.json') && !is_dir($result['directory'] . '/work'), 'only completed public bundle');
    same($report['pdf_sha256'], hash_file('sha256', $result['directory'] . '/booklet.pdf'), 'reported PDF hash');
    same($report['map_sha256'], hash_file('sha256', $result['directory'] . '/page-map.json'), 'reported map hash');
});

test('five pages: three explicit blanks and each source marker exactly once', function (): void {
    [$base, $source, $root] = sandbox();
    $result = imposeCase($source, $root, 'five', range(1, 5));
    same([[null, 1], [2, null], [null, 3], [4, 5]], pairs($result['map']), 'manual padded oracle');
    same(3, $result['map']['blank_slots'], 'three explicit blanks');
    $expected = ['P1', 'P2', 'P3', 'P4 P5'];
    foreach ($expected as $i => $text) {
        same(hash('sha256', $text), $result['validation']['pages'][$i]['text_sha256'], 'all markers exactly once with no blank marker');
    }
});

test('inclusive selection 2 through 5 and duplicate occurrence labels', function (): void {
    [$base, $source, $root] = sandbox();
    $result = imposeCase($source, $root, 'subset', Selection::ranges('2-5'), 4);
    same([[5, 2], [3, 4]], pairs($result['map']), 'manual subset oracle');
    same(hash('sha256', 'P5 P2'), $result['validation']['pages'][0]['text_sha256'], 'subset imported content');
    $repeated = imposeCase($source, $root, 'repeated', [['page' => 2, 'label' => 'Oficina — ação'], ['page' => 2, 'label' => '再版'], 5, 2], 4);
    same([[2, 2], [2, 5]], pairs($repeated['map']), 'repeated page numbers not collapsed');
    $found = [];
    foreach ($repeated['map']['sides'] as $side) {
        foreach ($side['slots'] as $slot) {
            $found[$slot['occurrence']['ordinal']] = $slot['occurrence']['label'];
        }
    }
    ksort($found);
    same([1 => 'Oficina — ação', 2 => '再版', 3 => null, 4 => null], $found, 'UTF-8 metadata and ordinal identity preserved');
});

test('signature fold oracle, multiple signatures and maximum occurrence boundary', function (): void {
    $geometry = Geometry::parse(['width' => 600, 'height' => 400]);
    foreach ([4, 8, 16] as $size) {
        foreach ([1, $size - 1, $size, $size + 1, 64] as $count) {
            $plan = Planner::plan(Selection::parse(array_fill(0, $count, 1)), $size, $geometry);
            foreach (array_chunk($plan['sides'], $size / 2) as $i => $signature) {
                $start = $i * $size + 1;
                $end = min($count, $start + $size - 1);
                $expected = array_pad(range($start, $end), $size, null);
                same($expected, unfold($signature, $size), 'independent reading-order fold');
            }
        }
    }
    [$base, $source, $root] = sandbox();
    $actual = imposeCase($source, $root, 'multi', [1, 2, 3, 4, 5, 6, 7, 8, 2], 8);
    same([[8, 1], [2, 7], [6, 3], [4, 5], [null, 2], [null, null], [null, null], [null, null]], pairs($actual['map']), 'manual two-signature boundary');
});

test('maximum admitted occurrence count imports and independently validates all 32 sides', function (): void {
    [$base, $source, $root] = sandbox();
    $result = imposeCase($source, $root, 'maximum', array_fill(0, 64, 1), 16);
    same(32, count($result['map']['sides']), 'actual maximum sides');
    same(32, count($result['validation']['pages']), 'every maximum side independently checked');
    same(0, $result['map']['blank_slots'], 'no padding at exact maximum');
    foreach ($result['validation']['pages'] as $page) {
        same(hash('sha256', 'P1 P1'), $page['text_sha256'], 'two real imported occurrences per side');
        same([600, 400], $page['pixels'], 'each maximum side rendered');
    }
});

test('whole-back 180 degree rotation has matching effective rectangles and raster positions', function (): void {
    [$base, $source, $root] = sandbox();
    $result = imposeCase($source, $root, 'rotated', range(1, 8), 8, ['width' => 600, 'height' => 400, 'back_rotation' => 180]);
    $back = $result['map']['sides'][1];
    same(180, $back['rotation'], 'whole back rotation');
    same(['x' => 300.0, 'top' => 50.0, 'width' => 300.0, 'height' => 300.0], $back['slots'][0]['effective_placement'], 'left logical page becomes right effective rectangle');
    same([[140, 140, 140], [40, 40, 40], [255, 255, 255]], array_column($result['validation']['pages'][1]['samples'], 'rgb'), 'Poppler honors whole-page rotation');
});

test('invalid requests and finite resource boundaries fail before publication', function (): void {
    [$base, $source, $root] = sandbox();
    $hash = hash_file('sha256', $source);
    fails(fn() => imposeCase($source, $root, 'page9', [9]), 'selection_page_missing');
    fails(fn() => imposeCase($source, $root, 'sig6', [1], 6), 'signature_size');
    foreach ([NAN, INF, -1, 0, 71, 1441, '600'] as $width) {
        fails(fn() => Geometry::parse(['width' => $width, 'height' => 400]), 'sheet_geometry');
    }
    fails(fn() => Selection::parse(array_fill(0, 65, 1)), 'selection_limit');
    fails(fn() => Selection::parse([0]), 'selection_page');
    fails(fn() => Selection::parse([['page' => 1, 'label' => str_repeat('a', 129)]]), 'selection_label');
    fails(fn() => Selection::parse([['page' => 1, 'label' => "bad\0label"]]), 'selection_label');
    fails(fn() => Selection::parse([['page' => 1, 'label' => "\xff"]]), 'selection_label');
    fails(fn() => Selection::ranges('5-2'), 'selection_limit');
    same(0, completed($root), 'zero completed invalid requests');
    same($hash, hash_file('sha256', $source), 'all failures preserve source');
    $large = $base . '/large.pdf';
    $file = fopen($large, 'wb');
    ftruncate($file, 16777217);
    fclose($file);
    fails(fn() => imposeCase($large, $root, 'large', [1], 4), 'input_size');
    same(0, completed($root), 'oversize input not published');
});

test('execution outside caller working directory uses package-owned helpers', function (): void {
    [$base, $source, $root] = sandbox();
    $old = getcwd();
    chdir($base);
    try {
        $result = imposeCase($source, $root, 'outside', [2, 3, 4, 5], 4);
        same([[5, 2], [3, 4]], pairs($result['map']), 'helpers independent of CWD');
    } finally {
        chdir($old);
    }
});
