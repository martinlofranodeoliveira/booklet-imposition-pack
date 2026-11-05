<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

use Composer\InstalledVersions;

/** @internal Independent structural, text and raster evidence; not semantic equivalence. */
final class Validator
{
    public static function validate(string $stage, Runner $runner, array $map, bool $failRenderer): array
    {
        clearstatcache();
        Failure::need(is_file($stage . '/booklet.pdf') && filesize($stage . '/booklet.pdf') <= 67108864 && filesize($stage . '/booklet.pdf') > 0, 'output_size');
        $runner->run('check-output');
        Failure::need(Inspector::inspect($runner->run('inspect-output')) === count($map['sides']), 'output_page_count');
        self::occurrences($map);
        $qpdf = $runner->run('version-qpdf');
        $poppler = $runner->run('version-poppler');
        $pages = [];
        foreach ($map['sides'] as $side) {
            $n = $side['side'];
            $text = $runner->run('text', $n);
            Failure::need(preg_match('//u', $text) === 1, 'text_encoding');
            // Poppler terminates each page with form feed, which PHP trim()
            // does not remove. Collapse Unicode whitespace before trimming so
            // the normalized hash has no synthetic trailing separator.
            $text = trim(preg_replace('/\s+/u', ' ', $text));
            if ($failRenderer) {
                $runner->run('test-fail-render');
            }
            $runner->run('render', $n);
            $png = $stage . '/work/raster.png';
            clearstatcache(true, $png);
            Failure::need(is_file($png) && !is_link($png) && filesize($png) > 0 && filesize($png) <= 8388608, 'raster_size');
            $size = @getimagesize($png);
            Failure::need(is_array($size) && $size[2] === IMAGETYPE_PNG && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 4000000, 'raster_geometry');
            Failure::need($size[0] === (int) ceil($map['geometry']['width']) && $size[1] === (int) ceil($map['geometry']['height']), 'raster_geometry');
            $image = @imagecreatefrompng($png);
            Failure::need($image !== false, 'raster_decode');
            try {
                $samples = [];
                $points = [[(int) floor($size[0] / 4), (int) floor($size[1] / 2)], [(int) floor(3 * $size[0] / 4), (int) floor($size[1] / 2)], [(int) floor($size[0] / 4), (int) floor($size[1] / 16)]];
                foreach ($points as [$x, $y]) {
                    $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                    $samples[] = ['x' => $x, 'y' => $y, 'rgb' => [$color['red'], $color['green'], $color['blue']]];
                }
            } finally {
                imagedestroy($image);
            }
            if ($n === 1) {
                Failure::need(@copy($png, $stage . '/first-side.png'), 'storage_write');
                @chmod($stage . '/first-side.png', 0600);
            }
            $pages[] = ['side' => $n, 'text_sha256' => hash('sha256', $text), 'pixels' => [$size[0], $size[1]], 'samples' => $samples];
            Failure::need(@unlink($png), 'storage_cleanup');
        }
        $lock = dirname(__DIR__) . '/composer.lock';
        $report = ['schema' => 'booklet-validation/1', 'development_version' => '0.1.0', 'checks' => ['qpdf_exit' => 0, 'qpdf_warning_policy' => 'reject', 'occurrence_permutation' => true, 'all_sides_text_extracted' => true, 'all_sides_png_decoded' => true], 'source_sha256' => $map['source_sha256'], 'pdf_sha256' => hash_file('sha256', $stage . '/booklet.pdf'), 'map_sha256' => hash_file('sha256', $stage . '/page-map.json'), 'runtime' => ['php' => PHP_VERSION, 'gd' => gd_info()['GD Version'], 'fpdi' => InstalledVersions::getPrettyVersion('setasign/fpdi'), 'fpdf' => InstalledVersions::getPrettyVersion('setasign/fpdf'), 'qpdf' => $qpdf, 'poppler' => $poppler, 'package_composer_lock_sha256' => is_file($lock) ? hash_file('sha256', $lock) : null], 'pages' => $pages, 'processes' => $runner->evidence];
        Failure::write($stage . '/validation.json', Failure::json($report));
        return $report;
    }

    private static function occurrences(array $map): void
    {
        $ordinals = [];
        $blanks = 0;
        foreach ($map['sides'] as $side) {
            foreach ($side['slots'] as $slot) {
                if ($slot['blank']) {
                    ++$blanks;
                    Failure::need($slot['occurrence'] === null && $slot['placement'] === null && $slot['effective_placement'] === null, 'map_validation');
                } else {
                    $ordinal = $slot['occurrence']['ordinal'];
                    Failure::need(!isset($ordinals[$ordinal]) && is_array($slot['placement']), 'map_validation');
                    $ordinals[$ordinal] = true;
                }
            }
        }
        $keys = array_keys($ordinals);
        sort($keys);
        Failure::need($keys === range(1, $map['selected_occurrences']) && $blanks === $map['blank_slots'], 'map_validation');
    }
}
