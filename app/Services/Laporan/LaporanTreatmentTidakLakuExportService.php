<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class LaporanTreatmentTidakLakuExportService
{
    public function pdf(array $report)
    {
        return Pdf::loadView('laporan.treatment-tidak-laku.export', [
            'report' => $report,
        ])->setPaper('a4', 'portrait')
            ->stream($report['filename_base'] . '.pdf', [
                'Attachment' => false,
            ]);
    }

    public function excel(array $report)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Treatment Tidak Laku');
        $sheet->getColumnDimension('A')->setWidth(8);
        $sheet->getColumnDimension('B')->setWidth(70);

        $this->writeHeader($sheet, $report);

        $headerRow = 6;
        $dataRow = 7;

        $sheet->fromArray(['No.', 'Nama Treatment'], null, "A{$headerRow}");
        $sheet->getStyle("A{$headerRow}:B{$headerRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFF3F3F3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF333333'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$dataRow}", (int) $item['no']);
            $sheet->setCellValue("B{$dataRow}", (string) $item['nama']);
            $dataRow++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$dataRow}:B{$dataRow}");
            $sheet->setCellValue("A{$dataRow}", 'Tidak ada treatment tidak laku pada periode terpilih.');
            $sheet->getStyle("A{$dataRow}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $dataRow++;
        }

        $lastDataRow = max($dataRow - 1, $headerRow);
        $sheet->getStyle("A{$headerRow}:B{$lastDataRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF333333'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle("A7:A{$lastDataRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->freezePane('A7');
        $sheet->setAutoFilter("A{$headerRow}:B{$headerRow}");
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.4)
            ->setRight(0.4)
            ->setBottom(0.4)
            ->setLeft(0.4);

        $temporaryFile = tempnam(sys_get_temp_dir(), 'laporan-treatment-tidak-laku-');
        $writer = new Xlsx($spreadsheet);
        $writer->save($temporaryFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response()->download(
            $temporaryFile,
            $report['filename_base'] . '.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
        )->deleteFileAfterSend(true);
    }

    private function writeHeader($sheet, array $report): void
    {
        $sheet->mergeCells('A1:B1');
        $sheet->mergeCells('A2:B2');
        $sheet->mergeCells('A3:B3');
        $sheet->mergeCells('A4:B4');

        $sheet->setCellValue('A1', $report['company_name']);
        $sheet->setCellValue('A2', $report['company_contact']);
        $sheet->setCellValue('A3', $report['branch_label']);
        $sheet->setCellValue(
            'A4',
            sprintf('TANGGAL : %s s/d %s', $report['tanggal_awal'], $report['tanggal_akhir'])
        );

        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1:A3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }
}
