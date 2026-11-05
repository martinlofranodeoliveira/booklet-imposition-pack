<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Demo
{
    public static function run(string $directory, bool $assert): array
    {
        Failure::need(!file_exists($directory) && !is_link($directory) && @mkdir($directory, 0700), 'demo_directory');
        $directory = OwnedStore::local($directory, true);
        Failure::need(@mkdir($directory . '/bundles', 0700), 'storage_create');
        Fixtures::write($directory . '/gray-eight.pdf');
        $result = (new Imposer())->impose($directory . '/gray-eight.pdf', range(1, 8), 8, ['width' => 600, 'height' => 400], ['root' => $directory . '/bundles', 'name' => 'eight']);
        $pairs = [];
        foreach ($result['map']['sides'] as $side) {
            $pairs[] = array_map(static fn(array $slot): ?int => $slot['occurrence']['page'] ?? null, $side['slots']);
        }
        $first = $result['validation']['pages'][0];
        $expectedSamples = [['x' => 150, 'y' => 200, 'rgb' => [160, 160, 160]], ['x' => 450, 'y' => 200, 'rgb' => [20, 20, 20]], ['x' => 150, 'y' => 25, 'rgb' => [255, 255, 255]]];
        $checked = $pairs === [[8, 1], [2, 7], [6, 3], [4, 5]] && $first['pixels'] === [600, 400] && $first['samples'] === $expectedSamples && $first['text_sha256'] === hash('sha256', 'P8 P1');
        foreach ($result['map']['sides'] as $side) {
            foreach ($side['slots'] as $i => $slot) {
                $r = $slot['placement'];
                $checked = $checked && $r['x'] === (float) ($i * 300) && $r['top'] === 50.0 && $r['width'] === 300.0 && $r['height'] === 300.0;
            }
        }
        if ($assert) {
            Failure::need($checked, 'demo_assertion');
        }
        return ['development_version' => '0.1.0', 'verified' => $checked, 'sheets' => 2, 'sides' => count($pairs), 'pairs' => $pairs, 'first_side_markers' => $checked ? ['P8', 'P1'] : null, 'pixels' => $first['pixels'], 'samples' => $first['samples'], 'bundle' => $result['directory'], 'visual_inspection' => 'not performed by this command'];
    }
}
