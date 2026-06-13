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

class LaporanPasienPalingSeringBelanjaExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.pasien-paling-sering-belanja.export', [
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
            ->setSubject('Laporan pasien paling sering belanja')
            ->setDescription('Peringkat pasien berdasarkan jumlah transaksi dan total nominal invoice lunas.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pasien Sering Belanja');
        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 6;
        $dataStartRow = 7;
        $row = $dataStartRow;
        $headers = [
            'A' => 'No.',
            'B' => 'Pasien',
            'C' => 'Jumlah Transaksi',
            'D' => 'Total Nominal',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $sheet->getStyle("A{$headerRow}:D{$headerRow}")->applyFromArray([
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
            $sheet->setCellValue("B{$row}", (string) $item['nama_pasien']);
            $sheet->setCellValue("C{$row}", (int) $item['jumlah_transaksi']);
            $sheet->setCellValue("D{$row}", (float) $item['total_nominal']);
            $sheet->getRowDimension($row)->setRowHeight(20);
            $row++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue(
                "A{$row}",
                'Tidak ada transaksi pasien pada periode dan cabang yang dipilih.'
            );
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(26);
            $row++;
        }

        $lastDataRow = max($dataStartRow, $row - 1);
        $sheet->getStyle("A{$dataStartRow}:D{$lastDataRow}")->applyFromArray([
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

        $sheet->getStyle("A{$dataStartRow}:A{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$dataStartRow}:C{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$dataStartRow}:D{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("B{$dataStartRow}:B{$lastDataRow}")
            ->getAlignment()->setWrapText(true);
        $sheet->getStyle("C{$dataStartRow}:C{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('#,##0');
        $sheet->getStyle("D{$dataStartRow}:D{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('"Rp" #,##0');

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:D{$headerRow}");
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
        $sheet->getPageSetup()->setPrintArea("A1:D{$lastDataRow}");

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
            'B' => 42,
            'C' => 20,
            'D' => 24,
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
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue(
            'A5',
            'TANGGAL : ' . $report['period_label'] . ' | ' . $report['peringkat_label']
        );
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
