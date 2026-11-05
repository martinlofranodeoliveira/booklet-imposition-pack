<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

final class Geometry
{
    public static function parse(array $geometry): array
    {
        Failure::need(count(array_diff(array_keys($geometry), ['width', 'height', 'back_rotation'])) === 0, 'sheet_geometry');
        foreach (['width', 'height'] as $key) {
            $n = $geometry[$key] ?? null;
            Failure::need((is_int($n) || is_float($n)) && is_finite((float) $n) && $n >= 72 && $n <= 1440, 'sheet_geometry');
        }
        $rotation = $geometry['back_rotation'] ?? 0;
        Failure::need(is_int($rotation) && in_array($rotation, [0, 180], true), 'back_rotation');
        return ['width' => (float) $geometry['width'], 'height' => (float) $geometry['height'], 'back_rotation' => $rotation];
    }

    public static function placement(float $sourceWidth, float $sourceHeight, array $sheet, int $slot): array
    {
        Failure::need(is_finite($sourceWidth) && is_finite($sourceHeight) && $sourceWidth > 0 && $sourceHeight > 0 && $sourceWidth <= 14400 && $sourceHeight <= 14400, 'source_geometry');
        $slotWidth = $sheet['width'] / 2;
        $scale = min($slotWidth / $sourceWidth, $sheet['height'] / $sourceHeight);
        $width = $sourceWidth * $scale;
        $height = $sourceHeight * $scale;
        return ['x' => $slot * $slotWidth + ($slotWidth - $width) / 2, 'top' => ($sheet['height'] - $height) / 2, 'width' => $width, 'height' => $height];
    }

    public static function effective(array $rect, array $sheet, int $rotation): array
    {
        if ($rotation === 0) {
            return $rect;
        }
        return ['x' => $sheet['width'] - $rect['x'] - $rect['width'], 'top' => $sheet['height'] - $rect['top'] - $rect['height'], 'width' => $rect['width'], 'height' => $rect['height']];
    }
}
