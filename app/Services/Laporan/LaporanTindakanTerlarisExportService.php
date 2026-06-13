<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class LaporanTindakanTerlarisExportService
{
    public function pdf(array $report): Response
    {
        $pdf = Pdf::loadView('laporan.tindakan-terlaris.export', [
            'report' => $report,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream($report['filename_base'] . '.pdf', [
            'Attachment' => false,
        ]);
    }

    public function excel(array $report): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Tindakan Terlaris');

        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 6;
        $dataStartRow = 7;

        $sheet->fromArray(
            ['No.', 'Tindakan', 'Jumlah', 'Total Harga'],
            null,
            "A{$headerRow}"
        );

        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->applyFromArray([
            'font' => [
                'bold' => false,
                'size' => 11,
            ],
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
        $sheet->getRowDimension($headerRow)->setRowHeight(22);

        $rowNumber = $dataStartRow;

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$rowNumber}", (int) $item['no']);
            $sheet->setCellValue("B{$rowNumber}", (string) $item['tindakan']);
            $sheet->setCellValue("C{$rowNumber}", (float) $item['jumlah']);
            $sheet->setCellValue("D{$rowNumber}", (float) $item['total_harga']);
            $rowNumber++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$rowNumber}:D{$rowNumber}");
            $sheet->setCellValue(
                "A{$rowNumber}",
                'Tidak ada data tindakan pada periode dan filter yang dipilih.'
            );
            $sheet->getStyle("A{$rowNumber}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($rowNumber)->setRowHeight(26);
            $rowNumber++;
        }

        $lastDataRow = max($dataStartRow, $rowNumber - 1);
        $totalRow = $rowNumber;

        $sheet->mergeCells("A{$totalRow}:B{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        $sheet->setCellValue("C{$totalRow}", (float) $report['total_jumlah']);
        $sheet->setCellValue("D{$totalRow}", (float) $report['total_harga']);

        $sheet->getStyle("A{$dataStartRow}:D{$totalRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => 'FF333333'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A{$totalRow}:D{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFEFF6E8'],
            ],
        ]);

        $sheet->getStyle("A{$totalRow}:B{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("A{$dataStartRow}:A{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("B{$dataStartRow}:B{$totalRow}")->getAlignment()
            ->setWrapText(true);
        $sheet->getStyle("C{$dataStartRow}:C{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("D{$dataStartRow}:D{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle("C{$dataStartRow}:C{$totalRow}")
            ->getNumberFormat()->setFormatCode('#,##0.####');
        $sheet->getStyle("D{$dataStartRow}:D{$totalRow}")
            ->getNumberFormat()->setFormatCode('"Rp" #,##0');

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:D{$headerRow}");
        $sheet->setShowGridlines(false);

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageMargins()
            ->setTop(0.4)
            ->setBottom(0.4)
            ->setLeft(0.35)
            ->setRight(0.35)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LGenerated ' . $report['generated_at'] . '&RPage &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:D{$totalRow}");

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            static function () use ($writer, $spreadsheet): void {
                $writer->save('php://output');
                $spreadsheet->disconnectWorksheets();
            },
            $report['filename_base'] . '.xlsx',
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'public',
            ]
        );
    }

    private function configureColumns(Worksheet $sheet): void
    {
        $widths = [
            'A' => 7,
            'B' => 50,
            'C' => 18,
            'D' => 28,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeHeader(Worksheet $sheet, array $report): void
    {
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(12);
        $sheet->getRowDimension(5)->setRowHeight(20);

        $this->addLogo($sheet);

        $sheet->mergeCells('B1:D1');
        $sheet->mergeCells('B2:D2');
        $sheet->mergeCells('B3:D3');
        $sheet->setCellValue('B1', $report['company_name']);
        $sheet->setCellValue('B2', $report['company_contact']);
        $sheet->setCellValue('B3', $report['branch_label']);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('B2:B3')->getFont()->setSize(9);
        $sheet->getStyle('B1:D3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A4:D4')->getBorders()
            ->getBottom()
            ->setBorderStyle(Border::BORDER_THIN);

        $period = 'TANGGAL : ' . $report['period_label'];
        if (($report['jenis_transaksi_label'] ?? '') !== 'Semua jenis transaksi') {
            $period .= ' | ' . $report['jenis_transaksi_label'];
        }

        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', $period);
        $sheet->getStyle('A5')->getFont()->setSize(10);
        $sheet->getStyle('A5')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function addLogo(Worksheet $sheet): void
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Logo MS Glow Aesthetic');
        $drawing->setDescription('Logo MS Glow Aesthetic');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(64);
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }
}
