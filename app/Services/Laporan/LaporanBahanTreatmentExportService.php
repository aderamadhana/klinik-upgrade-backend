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

class LaporanBahanTreatmentExportService
{
    public function pdf(array $report)
    {
        $view = $report['jenis'] === 'rekap'
            ? 'laporan.bahan-treatment.rekap'
            : 'laporan.bahan-treatment.detail';

        $pdf = Pdf::loadView($view, [
            'report' => $report,
        ])->setPaper('a4', 'portrait');

        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Arial');

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
            ->setSubject('Laporan bahan treatment')
            ->setDescription('Detail dan rekap penggunaan bahan treatment.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($report['jenis'] === 'rekap' ? 'Rekap Bahan' : 'Detail Bahan');

        if ($report['jenis'] === 'rekap') {
            $this->writeRecapSheet($sheet, $report);
        } else {
            $this->writeDetailSheet($sheet, $report);
        }

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

    private function writeDetailSheet(Worksheet $sheet, array $report): void
    {
        $this->configureColumns($sheet, [
            'A' => 7,
            'B' => 19,
            'C' => 54,
            'D' => 14,
            'E' => 15,
        ]);
        $this->writeCommonHeader($sheet, $report, 'E');

        $headerRow = 7;
        $row = 8;

        $headers = [
            'A' => 'No.',
            'B' => 'Kode Bahan',
            'C' => 'Nama Bahan',
            'D' => 'Satuan',
            'E' => 'Jumlah',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $this->applyTableHeaderStyle($sheet, "A{$headerRow}:E{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(25);

        if ($report['groups'] === []) {
            $sheet->mergeCells("A{$row}:E{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada penggunaan bahan treatment pada periode ini.');
            $sheet->getStyle("A{$row}:E{$row}")->applyFromArray($this->emptyRowStyle());
            $row++;
        } else {
            foreach ($report['groups'] as $registration) {
                $sheet->mergeCells("A{$row}:E{$row}");
                $sheet->setCellValue(
                    "A{$row}",
                    sprintf(
                        '%s — %s — %s (%s)',
                        $registration['no_invoice'],
                        $registration['tanggal'],
                        $registration['nama_pasien'],
                        $registration['no_rm']
                    )
                );
                $sheet->getStyle("A{$row}:E{$row}")->applyFromArray($this->groupHeaderStyle());
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                foreach ($registration['treatments'] as $treatment) {
                    $sheet->mergeCells("A{$row}:E{$row}");
                    $sheet->setCellValue(
                        "A{$row}",
                        sprintf('[%s] %s', $treatment['kode_treatment'], $treatment['nama_treatment'])
                    );
                    $sheet->getStyle("A{$row}:E{$row}")->applyFromArray($this->subGroupHeaderStyle());
                    $sheet->getRowDimension($row)->setRowHeight(20);
                    $row++;

                    foreach ($treatment['items'] as $item) {
                        $sheet->setCellValue("A{$row}", (int) $item['no']);
                        $sheet->setCellValueExplicit("B{$row}", (string) $item['kode_bahan'], DataType::TYPE_STRING);
                        $sheet->setCellValue("C{$row}", (string) $item['nama_bahan']);
                        $sheet->setCellValue("D{$row}", (string) $item['satuan']);
                        $sheet->setCellValue("E{$row}", (float) $item['jumlah']);
                        $sheet->getStyle("A{$row}:E{$row}")->applyFromArray($this->bodyRowStyle());
                        $sheet->getStyle("A{$row}:B{$row}")->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->getStyle("D{$row}:E{$row}")->getAlignment()
                            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                        $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('0.####');
                        $sheet->getRowDimension($row)->setRowHeight(19);
                        $row++;
                    }
                }
            }
        }

        $lastRow = max($headerRow, $row - 1);
        $this->finishSheet($sheet, $headerRow, $lastRow, 'E');
    }

    private function writeRecapSheet(Worksheet $sheet, array $report): void
    {
        $this->configureColumns($sheet, [
            'A' => 7,
            'B' => 19,
            'C' => 47,
            'D' => 13,
            'E' => 15,
            'F' => 11,
        ]);
        $this->writeCommonHeader($sheet, $report, 'F');

        $headerRow = 7;
        $row = 8;

        $headers = [
            'A' => 'No.',
            'B' => 'Kode Bahan',
            'C' => 'Nama Bahan',
            'D' => 'Satuan',
            'E' => 'Total Jml',
            'F' => 'Frek.',
        ];

        foreach ($headers as $column => $label) {
            $sheet->setCellValue("{$column}{$headerRow}", $label);
        }

        $this->applyTableHeaderStyle($sheet, "A{$headerRow}:F{$headerRow}");
        $sheet->getRowDimension($headerRow)->setRowHeight(25);

        if ($report['groups'] === []) {
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->setCellValue("A{$row}", 'Tidak ada penggunaan bahan treatment pada periode ini.');
            $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($this->emptyRowStyle());
            $row++;
        } else {
            foreach ($report['groups'] as $treatment) {
                $sheet->mergeCells("A{$row}:F{$row}");
                $sheet->setCellValue(
                    "A{$row}",
                    sprintf('%s  %s', $treatment['kode_treatment'], $treatment['nama_treatment'])
                );
                $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($this->groupHeaderStyle());
                $sheet->getRowDimension($row)->setRowHeight(22);
                $row++;

                foreach ($treatment['items'] as $item) {
                    $sheet->setCellValue("A{$row}", (int) $item['no']);
                    $sheet->setCellValueExplicit("B{$row}", (string) $item['kode_bahan'], DataType::TYPE_STRING);
                    $sheet->setCellValue("C{$row}", (string) $item['nama_bahan']);
                    $sheet->setCellValue("D{$row}", (string) $item['satuan']);
                    $sheet->setCellValue("E{$row}", (float) $item['total_jumlah']);
                    $sheet->setCellValue("F{$row}", (int) $item['frekuensi']);
                    $sheet->getStyle("A{$row}:F{$row}")->applyFromArray($this->bodyRowStyle());
                    $sheet->getStyle("A{$row}:B{$row}")->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("D{$row}:F{$row}")->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle("E{$row}")->getNumberFormat()->setFormatCode('0.####');
                    $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('0');
                    $sheet->getRowDimension($row)->setRowHeight(19);
                    $row++;
                }
            }
        }

        $lastRow = max($headerRow, $row - 1);
        $this->finishSheet($sheet, $headerRow, $lastRow, 'F');
    }

    private function writeCommonHeader(Worksheet $sheet, array $report, string $lastColumn): void
    {
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(21);
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getRowDimension(4)->setRowHeight(8);
        $sheet->getRowDimension(5)->setRowHeight(18);
        $sheet->getRowDimension(6)->setRowHeight(18);

        $this->addLogo($sheet);

        $sheet->mergeCells("B1:{$lastColumn}1");
        $sheet->mergeCells("B2:{$lastColumn}2");
        $sheet->mergeCells("B3:{$lastColumn}3");
        $sheet->mergeCells("A5:{$lastColumn}5");
        $sheet->mergeCells("A6:{$lastColumn}6");

        $sheet->setCellValue('B1', $report['title']);
        $sheet->setCellValue('B2', 'MS GLOW AESTHETIC ' . $report['branch_label']);
        $sheet->setCellValue('B3', $report['company_name'] . ' | ' . $report['company_contact']);
        $sheet->setCellValue('A5', 'CABANG : ' . $report['branch_label']);
        $sheet->setCellValue('A6', 'PERIODE : ' . $report['period_label']);

        $sheet->getStyle("B1:{$lastColumn}1")->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle("B2:{$lastColumn}2")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("B3:{$lastColumn}3")->getFont()->setSize(8.5);
        $sheet->getStyle("B1:{$lastColumn}3")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A4:{$lastColumn}4")->getBorders()
            ->getBottom()->setBorderStyle(Border::BORDER_MEDIUM);
        $sheet->getStyle("A5:{$lastColumn}6")->getFont()->setBold(true)->setSize(9);
        $sheet->getStyle("A5:{$lastColumn}6")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function addLogo(Worksheet $sheet): void
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            $sheet->setCellValue('A1', 'MS GLOW');
            $sheet->setCellValue('A2', 'Aesthetic');
            $sheet->getStyle('A1:A2')->getFont()->setBold(true);
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Logo MS Glow Aesthetic');
        $drawing->setDescription('Logo MS Glow Aesthetic');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(58);
        $drawing->setOffsetX(6);
        $drawing->setOffsetY(4);
        $drawing->setWorksheet($sheet);
    }

    private function configureColumns(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function finishSheet(
        Worksheet $sheet,
        int $headerRow,
        int $lastRow,
        string $lastColumn
    ): void {
        $sheet->freezePane('A' . ($headerRow + 1));
        $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$headerRow}");
        $sheet->setShowGridlines(false);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true);
        $sheet->getPageMargins()
            ->setTop(0.35)
            ->setBottom(0.4)
            ->setLeft(0.3)
            ->setRight(0.3)
            ->setHeader(0.15)
            ->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter(
            '&LDicetak ' . now()->format('d/m/Y H:i:s') . '&RHalaman &P / &N'
        );
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$lastRow}");
        $sheet->getStyle("A1:{$lastColumn}{$lastRow}")->getAlignment()->setWrapText(true);
    }

    private function applyTableHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => '111827'],
                'size' => 9,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'D9E2F3'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '6B7280'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ]);
    }

    private function groupHeaderStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 9,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1F4E78'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '6B7280'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    private function subGroupHeaderStyle(): array
    {
        return [
            'font' => [
                'bold' => true,
                'italic' => true,
                'color' => ['rgb' => '334155'],
                'size' => 8.5,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DCE6F1'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '94A3B8'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }

    private function bodyRowStyle(): array
    {
        return [
            'font' => [
                'size' => 8.5,
                'color' => ['rgb' => '111827'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '9CA3AF'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
        ];
    }

    private function emptyRowStyle(): array
    {
        return [
            'font' => [
                'italic' => true,
                'color' => ['rgb' => '64748B'],
                'size' => 9,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '9CA3AF'],
                ],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];
    }
}
