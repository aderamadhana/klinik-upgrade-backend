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
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanJumlahPeningkatanExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.jumlah-peningkatan.export', [
            'report' => $report,
        ])->setPaper('a4', 'portrait');

        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Times New Roman');

        return $pdf->stream($report['filename_base'] . '.pdf', [
            'Attachment' => false,
        ]);
    }

    public function excel(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('PT. Kosmetika Klinik Indonesia')
            ->setTitle($report['title'])
            ->setSubject('Laporan jumlah peningkatan')
            ->setDescription('Jumlah pembelian, perawatan, dan pasien baru per cabang.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Jumlah Peningkatan');
        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 6;
        $dataStartRow = 7;
        $row = $dataStartRow;

        $headers = [
            'A' => 'No.',
            'B' => 'Total Pembelian',
            'C' => 'Total Perawatan',
            'D' => 'Total Pasien Baru',
            'E' => 'Toko ID',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F3F3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$row}", (int) $item['no']);
            $sheet->setCellValue("B{$row}", (int) $item['total_pembelian']);
            $sheet->setCellValue("C{$row}", (int) $item['total_perawatan']);
            $sheet->setCellValue("D{$row}", (int) $item['total_pasien_baru']);
            $sheet->setCellValue("E{$row}", (string) $item['toko_nama']);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue(
                "A{$row}",
                'Tidak ada cabang yang sesuai dengan filter laporan.'
            );
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $row++;
        }

        $lastDataRow = max($dataStartRow, $row - 1);
        $sheet->getStyle("A{$dataStartRow}:E{$lastDataRow}")->applyFromArray([
            'font' => [
                'size' => 10,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A{$dataStartRow}:D{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$dataStartRow}:E{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("B{$dataStartRow}:D{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('#,##0');

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:E{$headerRow}");
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageMargins()
            ->setTop(0.45)
            ->setBottom(0.45)
            ->setLeft(0.35)
            ->setRight(0.35)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LDicetak ' . $report['generated_at'] . '&RHalaman &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:E{$lastDataRow}");

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
            'B' => 22,
            'C' => 22,
            'D' => 22,
            'E' => 28,
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

        $sheet->mergeCells('B1:E1');
        $sheet->mergeCells('B2:E2');
        $sheet->mergeCells('B3:E3');
        $sheet->setCellValue('B1', $report['company_name']);
        $sheet->setCellValue('B2', $report['company_contact']);
        $sheet->setCellValue('B3', $report['branch_label']);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('B2:B3')->getFont()->setSize(9);
        $sheet->getStyle('B1:E3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A4:E4')->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->mergeCells('A5:E5');
        $sheet->setCellValue('A5', 'TANGGAL : ' . $report['period_label']);
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
        $drawing->setOffsetX(12);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }
}
