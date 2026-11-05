<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Planner
{
    public static function plan(array $selection, int $signatureSize, array $geometry): array
    {
        Failure::need(in_array($signatureSize, [4, 8, 16], true), 'signature_size');
        $sideCount = (int) ceil(count($selection) / $signatureSize) * ($signatureSize / 2);
        Failure::need($sideCount <= 32, 'side_limit');
        $sides = [];
        $blanks = 0;
        foreach (array_chunk($selection, $signatureSize) as $signature => $chunk) {
            $chunk = array_pad($chunk, $signatureSize, null);
            for ($sheet = 0; $sheet < $signatureSize / 4; ++$sheet) {
                $pairs = [[$signatureSize - 1 - 2 * $sheet, 2 * $sheet], [2 * $sheet + 1, $signatureSize - 2 - 2 * $sheet]];
                foreach ($pairs as $back => $pair) {
                    $slots = [];
                    foreach ($pair as $position => $index) {
                        $occurrence = $chunk[$index];
                        $blank = $occurrence === null;
                        $blanks += $blank ? 1 : 0;
                        $slots[] = ['position' => $position === 0 ? 'left' : 'right', 'blank' => $blank, 'occurrence' => $occurrence, 'source_size' => null, 'placement' => null, 'effective_placement' => null];
                    }
                    $sides[] = ['side' => count($sides) + 1, 'signature' => $signature + 1, 'sheet' => $sheet + 1, 'face' => $back === 0 ? 'front' : 'back', 'rotation' => $back === 0 ? 0 : $geometry['back_rotation'], 'slots' => $slots];
                }
            }
        }
        return ['schema' => 'booklet-map/1', 'development_version' => '0.1.0', 'units' => 'pt', 'origin' => 'top-left', 'placement_tolerance_pt' => 0.02, 'geometry' => $geometry, 'signature_size' => $signatureSize, 'selected_occurrences' => count($selection), 'blank_slots' => $blanks, 'sides' => $sides];
    }
}
