<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader\PageBoundaries;

/** @internal Executed only in the bounded package worker. */
final class Worker
{
    public static function execute(string $stage): void
    {
        $config = $stage . '/work/job.json';
        Failure::need(is_file($config) && filesize($config) <= 131072, 'worker_configuration');
        $job = json_decode((string) file_get_contents($config), true, 64, JSON_THROW_ON_ERROR);
        $map = $job['map'];
        $geometry = Geometry::parse($map['geometry']);
        $pdf = new class('P', 'pt') extends Fpdi {
            protected function _putinfo()
            {
                $this->CreationDate = 946684800;
                parent::_putinfo();
            }
        };
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $count = $pdf->setSourceFile($stage . '/work/normalized.pdf');
        Failure::need($count === $map['source_pages'], 'source_page_count');
        $templates = [];
        foreach ($map['sides'] as &$side) {
            $pdf->AddPage($geometry['width'] > $geometry['height'] ? 'L' : 'P', [$geometry['width'], $geometry['height']], $side['rotation']);
            foreach ($side['slots'] as $position => &$slot) {
                if ($slot['blank']) {
                    continue;
                }
                $page = $slot['occurrence']['page'];
                if (!isset($templates[$page])) {
                    $template = $pdf->importPage($page, PageBoundaries::CROP_BOX, true, false);
                    $size = $pdf->getTemplateSize($template);
                    $templates[$page] = [$template, (float) $size['width'], (float) $size['height']];
                }
                [$template, $width, $height] = $templates[$page];
                $rect = Geometry::placement($width, $height, $geometry, $position);
                $slot['source_size'] = ['width' => $width, 'height' => $height];
                $slot['placement'] = $rect;
                $slot['effective_placement'] = Geometry::effective($rect, $geometry, $side['rotation']);
                $pdf->useTemplate($template, $rect['x'], $rect['top'], $rect['width'], $rect['height'], false);
            }
            unset($slot);
        }
        unset($side);
        $pdf->Output('F', $stage . '/booklet.pdf');
        Failure::write($stage . '/page-map.json', Failure::json($map));
    }
}
