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
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanTreatmentExportService
{
    public function pdf(array $report): Response
    {
        $pdf = Pdf::loadView('laporan.treatment.export', [
            'report' => $report,
        ])->setPaper('a4', 'landscape');

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
            ->setSubject('Data laporan treatment')
            ->setDescription('Rekap treatment reguler, Premier Lounge, dan realisasi deposit.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Laporan Treatment');

        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 7;
        $dataStartRow = $headerRow + 1;
        $row = $dataStartRow;

        $headers = [
            'A' => 'No.',
            'B' => 'Nama Treatment',
            'C' => 'Kode Accurate',
            'D' => 'Jumlah Biasa',
            'E' => 'Jumlah Premiere',
            'F' => 'Jumlah Realisasi Deposit',
            'G' => 'Jumlah Total',
            'H' => 'Harga Treatment',
            'I' => 'Akumulasi Diskon',
            'J' => 'Total Harga',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $sheet->getStyle("A{$headerRow}:J{$headerRow}")->applyFromArray([
            'font' => [
                'bold' => true,
                'size' => 9,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9DDE3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '4A4A4A'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
        $sheet->getRowDimension($headerRow)->setRowHeight(34);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$row}", (int) $item['no']);
            $sheet->setCellValue("B{$row}", $item['nama_treatment']);
            $sheet->setCellValue("C{$row}", $item['kode_accurate']);
            $sheet->setCellValue("D{$row}", (float) $item['jumlah_biasa']);
            $sheet->setCellValue("E{$row}", (float) $item['jumlah_premiere']);
            $sheet->setCellValue("F{$row}", (float) $item['jumlah_realisasi_deposit']);
            $sheet->setCellValue("G{$row}", (float) $item['jumlah_total']);
            $sheet->setCellValue("H{$row}", (float) $item['harga_treatment']);
            $sheet->setCellValue("I{$row}", (float) $item['akumulasi_diskon']);
            $sheet->setCellValue("J{$row}", (float) $item['total_harga']);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }

        if (empty($report['rows'])) {
            $sheet->mergeCells("A{$row}:J{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada data treatment pada periode dan filter yang dipilih.');
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $totalRow = $row;
        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'TOTAL');
        $sheet->setCellValue("D{$totalRow}", (float) $report['totals']['jumlah_biasa']);
        $sheet->setCellValue("E{$totalRow}", (float) $report['totals']['jumlah_premiere']);
        $sheet->setCellValue("F{$totalRow}", (float) $report['totals']['jumlah_realisasi_deposit']);
        $sheet->setCellValue("G{$totalRow}", (float) $report['totals']['jumlah_total']);
        $sheet->setCellValue("H{$totalRow}", null);
        $sheet->setCellValue("I{$totalRow}", (float) $report['totals']['akumulasi_diskon']);
        $sheet->setCellValue("J{$totalRow}", (float) $report['totals']['total_harga']);

        $lastRow = $totalRow;
        $sheet->getStyle("A{$dataStartRow}:J{$lastRow}")->applyFromArray([
            'font' => [
                'size' => 9,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '4A4A4A'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A{$totalRow}:J{$totalRow}")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EEF4E6'],
            ],
        ]);

        $sheet->getStyle("A{$dataStartRow}:A{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$dataStartRow}:G{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("H{$dataStartRow}:J{$lastRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("B{$dataStartRow}:B{$lastRow}")
            ->getAlignment()->setWrapText(true);

        $sheet->getStyle("D{$dataStartRow}:G{$lastRow}")
            ->getNumberFormat()->setFormatCode('#,##0.####');
        $sheet->getStyle("H{$dataStartRow}:J{$lastRow}")
            ->getNumberFormat()->setFormatCode('"Rp" #,##0');

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:J{$headerRow}");
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
            ->setLeft(0.3)
            ->setRight(0.3)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LGenerated ' . $report['generated_at'] . '&RPage &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:J{$lastRow}");

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
            'A' => 6,
            'B' => 35,
            'C' => 17,
            'D' => 13,
            'E' => 14,
            'F' => 18,
            'G' => 13,
            'H' => 18,
            'I' => 18,
            'J' => 18,
        ];

        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeHeader(Worksheet $sheet, array $report): void
    {
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(10);
        $sheet->getRowDimension(5)->setRowHeight(20);

        $this->addLogo($sheet);

        $sheet->mergeCells('C1:J1');
        $sheet->mergeCells('C2:J2');
        $sheet->mergeCells('C3:J3');
        $sheet->setCellValue('C1', $report['company_name']);
        $sheet->setCellValue('C2', $report['company_contact']);
        $sheet->setCellValue('C3', $report['branch_label']);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('C2:C3')->getFont()->setSize(9);
        $sheet->getStyle('C1:J3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A4:J4')->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '8A8A8A'],
                ],
            ],
        ]);

        $sheet->mergeCells('A5:J5');
        $sheet->setCellValue(
            'A5',
            'TANGGAL : ' . $report['period_label']
            . ' | ' . $report['jenis_transaksi_label']
        );
        $sheet->getStyle('A5')->getFont()->setSize(9);
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
        $drawing->setHeight(62);
        $drawing->setOffsetX(18);
        $drawing->setOffsetY(5);
        $drawing->setWorksheet($sheet);
    }
}
