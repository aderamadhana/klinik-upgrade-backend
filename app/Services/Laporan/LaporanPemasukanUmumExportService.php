<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanPemasukanUmumExportService
{
    public function pdf(array $report): Response
    {
        $pdf = Pdf::loadView('laporan.pemasukan-umum.export', [
            'report' => $report,
        ])->setPaper('a4', 'portrait');

        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Arial');

        return $pdf->stream($report['filename_base'] . '.pdf');
    }

    public function excel(array $report): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('PT. Kosmetika Klinik Indonesia')
            ->setTitle($report['title'])
            ->setSubject('Laporan pemasukan')
            ->setDescription('Laporan pemasukan klinik berdasarkan transaksi lunas.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Pemasukan');

        $sheet->getColumnDimension('A')->setWidth(47);
        $sheet->getColumnDimension('B')->setWidth(24);

        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(12);

        $this->addLogo($sheet);

        $sheet->setCellValue('B1', 'PT. KOSMETIKA KLINIK INDONESIA');
        $sheet->setCellValue('B2', 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id');
        $sheet->setCellValue('B3', $report['branch_label']);
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('B2:B3')->getFont()->setSize(9);
        $sheet->getStyle('B1:B3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle('A4:B4')->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '8A8A8A'],
                ],
            ],
        ]);

        $sheet->mergeCells('A6:B6');
        $sheet->setCellValue('A6', $report['title']);
        $sheet->getStyle('A6')->getFont()->setBold(true)->setSize(13);

        $sheet->mergeCells('A7:B7');
        $sheet->setCellValue(
            'A7',
            'TANGGAL : ' . $report['period_label']
            . ' | ' . $report['jenis_pemasukan_label']
            . ' | ' . $report['jenis_transaksi_label']
        );
        $sheet->getStyle('A7')->getFont()->setSize(9);

        $row = 9;
        $row = $this->writeSalesSection($sheet, $row, $report['regular'], false);
        $row += 1;
        $row = $this->writeSalesSection($sheet, $row, $report['premier'], true);
        $row += 1;

        $this->writeValueRow($sheet, $row, 'TOTAL DISKON SUBTOTAL', $report['total_diskon_subtotal'], true);
        $row++;
        $this->writeValueRow($sheet, $row, 'TOTAL PENDAPATAN ALL', $report['total_pendapatan_all'], true);
        $row += 2;

        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->setCellValue("A{$row}", 'RINCIAN PEMBAYARAN');
        $this->applySectionTitle($sheet, "A{$row}:B{$row}");
        $row++;

        if (empty($report['payment_methods'])) {
            $this->writeValueRow($sheet, $row, 'BELUM ADA METODE PEMBAYARAN', 0);
            $row++;
        } else {
            foreach ($report['payment_methods'] as $method) {
                $this->writeValueRow($sheet, $row, $method['nama'], $method['nominal']);
                $row++;
            }
        }

        $this->writeValueRow($sheet, $row, 'TOTAL CASH', $report['total_cash'], true);
        $row++;
        $this->writeValueRow($sheet, $row, 'TOTAL NON CASH', $report['total_non_cash'], true);

        $lastRow = $row;
        $sheet->getStyle("B9:B{$lastRow}")
            ->getNumberFormat()
            ->setFormatCode('"Rp" #,##0');

        $sheet->getStyle("A9:B{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("B9:B{$lastRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getPageSetup()
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()
            ->setTop(0.45)
            ->setBottom(0.45)
            ->setLeft(0.45)
            ->setRight(0.45)
            ->setHeader(0.2)
            ->setFooter(0.2);
        $sheet->getHeaderFooter()->setOddFooter('&LGenerated ' . $report['generated_at'] . '&RPage &P / &N');
        $sheet->getPageSetup()->setPrintArea("A1:B{$lastRow}");
        $sheet->setShowGridlines(false);

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

    private function writeSalesSection(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        array $data,
        bool $premier
    ): int {
        $suffix = $premier ? ' PREMIER LOUNGE' : '';

        $rows = [
            ['PENJUALAN PRODUK' . $suffix, $data['penjualan_produk'], false],
            ['DISC PRODUK' . $suffix, $data['diskon_produk'], false],
            ['TOTAL PENJUALAN PRODUK' . $suffix, $data['total_penjualan_produk'], true],
            ['PENJUALAN TREATMENT' . $suffix, $data['penjualan_treatment'], false],
            ['DISC TREATMENT' . $suffix, $data['diskon_treatment'], false],
            ['TOTAL PENJUALAN TREATMENT' . $suffix, $data['total_penjualan_treatment'], true],
            ['TOTAL PENJUALAN' . $suffix, $data['total_penjualan'], true],
        ];

        foreach ($rows as [$label, $value, $bold]) {
            $this->writeValueRow($sheet, $row, $label, $value, $bold);
            $row++;
        }

        return $row;
    }

    private function writeValueRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        int $row,
        string $label,
        float|int $value,
        bool $bold = false
    ): void {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", (float) $value);
        $sheet->getRowDimension($row)->setRowHeight(18);

        $style = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '444444'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        if ($bold) {
            $style['font'] = ['bold' => true];
        }

        $sheet->getStyle("A{$row}:B{$row}")->applyFromArray($style);
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    private function applySectionTitle(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $range
    ): void {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '111111'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E9EDF3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '444444'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);
    }

    private function addLogo(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
    ): void {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Logo MS Glow Aesthetic');
        $drawing->setDescription('Logo MS Glow Aesthetic');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(58);
        $drawing->setOffsetX(8);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }
}
