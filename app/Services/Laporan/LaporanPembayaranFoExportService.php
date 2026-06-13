<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanPembayaranFoExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.pembayaran-fo.export', [
            'report' => $report,
        ])->setPaper('a4', 'landscape');

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
            ->setSubject('Laporan pembayaran Front Office')
            ->setDescription('Rekap transaksi harian dan tipe pembayaran berdasarkan kasir Front Office.');

        $transactionSheet = $spreadsheet->getActiveSheet();
        $transactionSheet->setTitle('Pembayaran FO');
        $this->configureTransactionColumns($transactionSheet);
        $this->writeTransactionHeader($transactionSheet, $report);
        $this->writeTransactions($transactionSheet, $report);

        $paymentSheet = $spreadsheet->createSheet();
        $paymentSheet->setTitle('Tipe Pembayaran');
        $this->configurePaymentColumns($paymentSheet);
        $this->writePaymentHeader($paymentSheet, $report);
        $this->writePaymentTypes($paymentSheet, $report);

        $spreadsheet->setActiveSheetIndex(0);
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

    private function writeTransactions(Worksheet $sheet, array $report): void
    {
        $headerRow = 7;
        $dataStartRow = 8;
        $row = $dataStartRow;
        $headers = [
            'A' => 'No.',
            'B' => 'Faktur',
            'C' => 'Pasien',
            'D' => 'Treatment',
            'E' => 'Produk',
            'F' => 'Total Pembelian',
            'G' => 'Diskon Subtotal',
            'H' => 'Bayar',
            'I' => 'Kembalian',
            'J' => 'Jenis Transaksi',
            'K' => 'Status',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $this->styleTableHeader($sheet, "A{$headerRow}:K{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(34);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$row}", (int) $item['no']);
            $sheet->setCellValueExplicit("B{$row}", (string) $item['faktur'], DataType::TYPE_STRING);
            $sheet->setCellValue("C{$row}", (string) $item['pasien']);
            $sheet->setCellValue("D{$row}", (float) $item['treatment']);
            $sheet->setCellValue("E{$row}", (float) $item['produk']);
            $sheet->setCellValue("F{$row}", (float) $item['total_pembelian']);
            $sheet->setCellValue("G{$row}", (float) $item['diskon_subtotal']);
            $sheet->setCellValue("H{$row}", (float) $item['bayar']);
            $sheet->setCellValue("I{$row}", (float) $item['kembalian']);
            $sheet->setCellValue("J{$row}", (string) $item['jenis_transaksi']);
            $sheet->setCellValue("K{$row}", (string) $item['status_label']);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$row}:K{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada data pembayaran untuk kasir dan tanggal yang dipilih.');
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        }

        $totalRow = $row;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        $sheet->setCellValue("D{$totalRow}", (float) $report['totals']['total_treatment']);
        $sheet->setCellValue("E{$totalRow}", (float) $report['totals']['total_produk']);
        $sheet->setCellValue("F{$totalRow}", (float) $report['totals']['total_pembelian']);
        $sheet->setCellValue("G{$totalRow}", (float) $report['totals']['total_diskon_subtotal']);
        $sheet->setCellValue("H{$totalRow}", (float) $report['totals']['total_bayar']);
        $sheet->setCellValue("I{$totalRow}", (float) $report['totals']['total_kembalian']);
        $sheet->mergeCells("J{$totalRow}:K{$totalRow}");
        $sheet->setCellValue(
            "J{$totalRow}",
            'Lunas: ' . $report['totals']['total_lunas'] . ' | Belum lunas: ' . $report['totals']['total_belum_lunas']
        );

        $lastRow = $totalRow;
        $sheet->getStyle("A{$dataStartRow}:K{$lastRow}")->applyFromArray([
            'font' => ['size' => 9],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getStyle("A{$totalRow}:K{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EEF4E6'],
            ],
        ]);
        $sheet->getStyle("D{$dataStartRow}:I{$lastRow}")
            ->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $sheet->getStyle("A{$dataStartRow}:A{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("K{$dataStartRow}:K{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:K{$headerRow}");
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageMargins()
            ->setTop(0.35)
            ->setBottom(0.35)
            ->setLeft(0.25)
            ->setRight(0.25)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LDicetak ' . $report['generated_at'] . '&RHalaman &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:K{$lastRow}");
    }

    private function writePaymentTypes(Worksheet $sheet, array $report): void
    {
        $headerRow = 7;
        $dataStartRow = 8;
        $row = $dataStartRow;

        $sheet->setCellValue("A{$headerRow}", 'Tipe Pembayaran');
        $sheet->setCellValue("B{$headerRow}", 'Jumlah');
        $this->styleTableHeader($sheet, "A{$headerRow}:B{$headerRow}");

        foreach ($report['payment_types'] as $item) {
            $sheet->setCellValue("A{$row}", (string) $item['nama']);
            $sheet->setCellValue("B{$row}", (float) $item['jumlah']);
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;
        }

        if ($report['payment_types'] === []) {
            $sheet->mergeCells("A{$row}:B{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada tipe pembayaran.');
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $lastRow = max($dataStartRow, $row - 1);
        $sheet->getStyle("A{$dataStartRow}:B{$lastRow}")->applyFromArray([
            'font' => ['size' => 10],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("B{$dataStartRow}:B{$lastRow}")
            ->getNumberFormat()->setFormatCode('"Rp" #,##0');
        $sheet->getStyle("B{$dataStartRow}:B{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageSetup()->setPrintArea("A1:B{$lastRow}");
    }

    private function styleTableHeader(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 9,
            ],
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
    }

    private function configureTransactionColumns(Worksheet $sheet): void
    {
        $widths = [
            'A' => 6,
            'B' => 20,
            'C' => 24,
            'D' => 15,
            'E' => 15,
            'F' => 17,
            'G' => 17,
            'H' => 15,
            'I' => 15,
            'J' => 21,
            'K' => 11,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function configurePaymentColumns(Worksheet $sheet): void
    {
        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(22);
    }

    private function writeTransactionHeader(Worksheet $sheet, array $report): void
    {
        $this->writeCommonHeader($sheet, $report, 'K');
        $sheet->mergeCells('A5:K5');
        $sheet->setCellValue('A5', 'KASIR : ' . $report['cashier_name']);
        $sheet->mergeCells('A6:K6');
        $sheet->setCellValue('A6', 'TANGGAL : ' . $report['date_label']);
        $sheet->getStyle('A5:A6')->getFont()->setBold(true)->setSize(10);
    }

    private function writePaymentHeader(Worksheet $sheet, array $report): void
    {
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(10);
        $sheet->getRowDimension(5)->setRowHeight(20);
        $sheet->getRowDimension(6)->setRowHeight(20);

        $sheet->mergeCells('A1:B1');
        $sheet->mergeCells('A2:B2');
        $sheet->mergeCells('A3:B3');
        $sheet->setCellValue('A1', $report['company_name']);
        $sheet->setCellValue('A2', $report['company_contact']);
        $sheet->setCellValue('A3', $report['branch_name']);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A2:A3')->getFont()->setSize(9);
        $sheet->getStyle('A1:B3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:B4')->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->mergeCells('A5:B5');
        $sheet->setCellValue('A5', 'KASIR : ' . $report['cashier_name']);
        $sheet->mergeCells('A6:B6');
        $sheet->setCellValue('A6', 'TANGGAL : ' . $report['date_label']);
        $sheet->getStyle('A5:A6')->getFont()->setBold(true)->setSize(10);
    }

    private function writeCommonHeader(Worksheet $sheet, array $report, string $lastColumn): void
    {
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(10);
        $sheet->getRowDimension(5)->setRowHeight(20);
        $sheet->getRowDimension(6)->setRowHeight(20);

        $this->addLogo($sheet);

        $sheet->mergeCells("C1:{$lastColumn}1");
        $sheet->mergeCells("C2:{$lastColumn}2");
        $sheet->mergeCells("C3:{$lastColumn}3");
        $sheet->setCellValue('C1', $report['company_name']);
        $sheet->setCellValue('C2', $report['company_contact']);
        $sheet->setCellValue('C3', $report['branch_name']);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('C2:C3')->getFont()->setSize(9);
        $sheet->getStyle("C1:{$lastColumn}3")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A4:{$lastColumn}4")->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);
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
