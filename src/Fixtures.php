<?php
declare(strict_types=1);
namespace Lofrano\Booklet;

/** Original, synthetic vector fixtures. No source PDFs are distributed. */
final class Fixtures
{
    public static function write(string $path, string $kind = 'gray'): void
    {
        Failure::need(in_array($kind, ['gray', 'rectangle', 'link'], true), 'fixture_kind');
        Failure::need(!file_exists($path) && !is_link($path), 'fixture_exists');
        $size = $kind === 'rectangle' ? [200, 100] : [200, 200];
        $pdf = new class($kind === 'rectangle' ? 'L' : 'P', 'pt', $size) extends \FPDF {
            protected function _putinfo()
            {
                $this->CreationDate = 946684800;
                parent::_putinfo();
            }
        };
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        for ($n = 1; $n <= ($kind === 'gray' ? 8 : 1); ++$n) {
            $pdf->AddPage();
            if ($kind === 'gray') {
                $pdf->SetFillColor(20 * $n);
                $pdf->Rect(0, 0, 200, 200, 'F');
                $pdf->SetTextColor(20 * $n < 100 ? 255 : 0);
            }
            $pdf->SetFont('Helvetica', '', 12);
            $pdf->Text(12, 20, $kind === 'rectangle' ? 'RECT' : 'P' . $n);
            if ($kind === 'link') {
                $pdf->Link(10, 10, 80, 30, 'https://example.invalid/');
            }
        }
        $pdf->Output('F', $path);
        Failure::need(@chmod($path, 0600), 'storage_permissions');
    }
}
