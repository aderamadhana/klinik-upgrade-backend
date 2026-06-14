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

class LaporanProdukResepDokterExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.produk-resep-dokter.export', [
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
            ->setSubject('Laporan produk resep dokter')
            ->setDescription('Daftar produk yang tercatat pada resep dokter per periode.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produk Resep Dokter');
        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 7;
        $dataStartRow = 8;
        $row = $dataStartRow;
        $headers = [
            'A' => 'Tanggal',
            'B' => 'Nama Pasien',
            'C' => 'Nama Produk',
            'D' => 'Jumlah',
            'E' => 'Harga Produk',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $sheet->getStyle("A{$headerRow}:E{$headerRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F3F3'],
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(24);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$row}", $item['tanggal']);
            $sheet->setCellValue("B{$row}", $item['nama_pasien']);
            $sheet->setCellValue("C{$row}", $item['nama_produk']);
            $sheet->setCellValue("D{$row}", (float) $item['jumlah']);
            $sheet->setCellValue("E{$row}", (float) $item['total_harga']);
            $sheet->getRowDimension($row)->setRowHeight(21);
            $row++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada produk resep dokter pada filter ini.');
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        }

        $lastDataRow = max($dataStartRow, $row - 1);
        $sheet->getStyle("A{$dataStartRow}:E{$lastDataRow}")->applyFromArray([
            'font' => ['size' => 10],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle("D{$dataStartRow}:D{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$dataStartRow}:E{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D{$dataStartRow}:D{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('#,##0.##');
        $sheet->getStyle("E{$dataStartRow}:E{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('[$Rp-421] #,##0');

        $totalRow = $row;
        $sheet->mergeCells("A{$totalRow}:D{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'GRAND TOTAL');
        $sheet->setCellValue("E{$totalRow}", (float) $report['totals']['grand_total']);
        $sheet->getStyle("A{$totalRow}:E{$totalRow}")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F3F3'],
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("E{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("E{$totalRow}")->getNumberFormat()
            ->setFormatCode('[$Rp-421] #,##0');

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
        $sheet->getPageSetup()->setPrintArea("A1:E{$totalRow}");

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
        foreach ([
            'A' => 15,
            'B' => 30,
            'C' => 38,
            'D' => 12,
            'E' => 21,
        ] as $column => $width) {
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
        $sheet->getRowDimension(6)->setRowHeight(20);

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
        $sheet->mergeCells('A6:E6');
        $sheet->setCellValue('A5', 'TANGGAL : ' . $report['period_label']);
        $sheet->setCellValue('A6', 'NAMA : ' . $report['doctor_name']);
        $sheet->getStyle('A5:A6')->getFont()->setSize(10);
        $sheet->getStyle('A5:A6')->getAlignment()
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
