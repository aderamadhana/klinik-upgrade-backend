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

class LaporanPasienTerakhirTransaksiTreatmentExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.pasien-terakhir-transaksi-treatment.export', [
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
            ->setSubject('Laporan pasien terakhir transaksi treatment')
            ->setDescription(
                'Daftar transaksi treatment terakhir setiap pasien pada cabang dan periode yang dipilih.'
            );

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Transaksi Terakhir');
        $this->configureColumns($sheet);
        $this->writeHeader($sheet, $report);

        $headerRow = 6;
        $dataStartRow = 7;
        $row = $dataStartRow;
        $headers = [
            'A' => 'No.',
            'B' => 'Nama',
            'C' => 'No RM',
            'D' => 'Treatment Terakhir',
            'E' => 'Tanggal Terakhir',
            'F' => 'Faktur',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $sheet->getStyle("A{$headerRow}:F{$headerRow}")->applyFromArray([
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
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        foreach ($report['rows'] as $item) {
            $sheet->setCellValue("A{$row}", (int) $item['no']);
            $sheet->setCellValue("B{$row}", (string) $item['nama_pasien']);
            $sheet->setCellValueExplicit(
                "C{$row}",
                (string) $item['no_rm'],
                DataType::TYPE_STRING
            );
            $sheet->setCellValue("D{$row}", (string) $item['treatment_terakhir']);
            $sheet->setCellValue("E{$row}", (string) $item['tanggal_terakhir']);
            $sheet->setCellValueExplicit(
                "F{$row}",
                (string) $item['faktur'],
                DataType::TYPE_STRING
            );
            $sheet->getRowDimension($row)->setRowHeight(34);
            $row++;
        }

        if ($report['rows'] === []) {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue(
                "A{$row}",
                'Tidak ada pasien dengan transaksi treatment terakhir pada periode dan cabang yang dipilih.'
            );
            $sheet->getStyle("A{$row}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;
        }

        $lastDataRow = max($dataStartRow, $row - 1);
        $sheet->getStyle("A{$dataStartRow}:F{$lastDataRow}")->applyFromArray([
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
                'wrapText' => true,
            ],
        ]);

        $sheet->getStyle("A{$dataStartRow}:A{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("C{$dataStartRow}:C{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$dataStartRow}:F{$lastDataRow}")
            ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E{$dataStartRow}:E{$lastDataRow}")
            ->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        $sheet->freezePane("A{$dataStartRow}");
        $sheet->setAutoFilter("A{$headerRow}:F{$headerRow}");
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
            ->setLeft(0.3)
            ->setRight(0.3)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LDicetak ' . $report['generated_at'] . '&RHalaman &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:F{$lastDataRow}");

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
            'B' => 27,
            'C' => 20,
            'D' => 43,
            'E' => 18,
            'F' => 24,
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
        $sheet->getRowDimension(5)->setRowHeight(22);

        $this->addLogo($sheet);

        $sheet->mergeCells('C1:F1');
        $sheet->mergeCells('C2:F2');
        $sheet->mergeCells('C3:F3');
        $sheet->setCellValue('C1', $report['company_name']);
        $sheet->setCellValue('C2', $report['company_contact']);
        $sheet->setCellValue('C3', $report['branch_name']);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('C2:C3')->getFont()->setSize(9);
        $sheet->getStyle('C1:F3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle('A4:F4')->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_THIN);

        $sheet->mergeCells('A5:F5');
        $sheet->setCellValue('A5', 'TANGGAL : ' . $report['period_label']);
        $sheet->getStyle('A5')->getFont()->setSize(10)->setBold(true);
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
