<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
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

class LaporanInsentifNurseBeauticianExportService
{
    private const HEADER_ROW = 6;

    public function pdf(array $payload, string $filename): Response
    {
        $pdf = Pdf::loadView(
            'laporan.insentif-nurse-beautician.export',
            array_merge($payload, [
                'logoDataUri' => $this->logoDataUri(),
            ])
        )
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isJavascriptEnabled' => false,
            ]);

        return $pdf->stream($filename, [
            'Attachment' => false,
        ]);
    }

    public function excel(array $payload, string $filename): StreamedResponse
    {
        $filename = $this->withExtension($filename, 'xlsx');
        $spreadsheet = $this->buildSpreadsheet($payload);

        return response()->streamDownload(
            static function () use ($spreadsheet): void {
                try {
                    $writer = new Xlsx($spreadsheet);
                    $writer->setPreCalculateFormulas(false);
                    $writer->save('php://output');
                } finally {
                    $spreadsheet->disconnectWorksheets();
                }
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'public',
                'Expires' => '0',
            ]
        );
    }

    private function buildSpreadsheet(array $payload): Spreadsheet
    {
        $jenis = (string) ($payload['jenis'] ?? 'summary');
        $rows = collect($payload['rows'] ?? []);
        $columns = $this->columns($jenis);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MS Glow Aesthetic')
            ->setLastModifiedBy('MS Glow Aesthetic')
            ->setTitle((string) ($payload['title'] ?? 'Laporan Insentif Treatment'))
            ->setSubject('Laporan Insentif Nurse/Beautician')
            ->setDescription('Laporan insentif treatment nurse/beautician yang dibuat oleh sistem klinik.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($jenis === 'detail' ? 'Detail' : 'Summary');
        $sheet->setShowGridlines(false);
        $sheet->setSelectedCell('A1');

        $this->applyColumnWidths($sheet, $columns);
        $this->writeReportHeader($sheet, $payload, count($columns));
        $lastTableRow = $this->writeTable($sheet, $payload, $rows, $columns);
        $lastNoteRow = $this->writeNotes(
            $sheet,
            $lastTableRow + 2,
            count($columns),
            $jenis
        );
        $this->configureSheet($sheet, $columns, max($lastTableRow, $lastNoteRow));

        return $spreadsheet;
    }

    private function writeReportHeader(Worksheet $sheet, array $payload, int $columnCount): void
    {
        $jenis = (string) ($payload['jenis'] ?? 'summary');
        $isSummary = $jenis === 'summary';
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);

        $logoEndColumn = $isSummary ? 'A' : 'B';
        $titleStartColumn = $isSummary ? 'B' : 'C';
        $titleEndColumn = $isSummary ? 'D' : 'G';
        $metaStartColumn = $isSummary ? 'E' : 'H';

        $sheet->mergeCells("A1:{$logoEndColumn}4");
        $sheet->mergeCells("{$titleStartColumn}1:{$titleEndColumn}2");
        $sheet->mergeCells("{$titleStartColumn}3:{$titleEndColumn}4");
        $this->mergeIfNeeded($sheet, "{$metaStartColumn}1", "{$lastColumn}1");
        $this->mergeIfNeeded($sheet, "{$metaStartColumn}2", "{$lastColumn}2");
        $this->mergeIfNeeded($sheet, "{$metaStartColumn}3", "{$lastColumn}3");

        $sheet->setCellValueExplicit(
            "{$titleStartColumn}1",
            strtoupper((string) ($payload['title'] ?? 'LAPORAN INSENTIF TREATMENT')),
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            "{$titleStartColumn}3",
            strtoupper((string) ($payload['clinicName'] ?? 'MS GLOW AESTHETIC')),
            DataType::TYPE_STRING
        );

        $filters = (array) ($payload['filters'] ?? []);
        $staffLabel = strtoupper((string) ($filters['staff_nama'] ?? 'SEMUA NURSE/BEAUTICIAN'));

        $sheet->setCellValueExplicit(
            "{$metaStartColumn}1",
            'Periode: ' . (string) ($payload['period'] ?? '-'),
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            "{$metaStartColumn}2",
            'Perawat: ' . $staffLabel,
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            "{$metaStartColumn}3",
            'Halaman 1 / 1',
            DataType::TYPE_STRING
        );

        foreach ([1 => 20, 2 => 20, 3 => 16, 4 => 16, 5 => 8] as $row => $height) {
            $sheet->getRowDimension($row)->setRowHeight($height);
        }

        if (! $this->addCenteredLogo($sheet, $logoEndColumn)) {
            $sheet->setCellValueExplicit('A1', 'MS GLOW AESTHETICS', DataType::TYPE_STRING);
            $sheet->getStyle("A1:{$logoEndColumn}4")->getFont()
                ->setName('Georgia')
                ->setSize(17)
                ->setBold(true);
        }

        $sheet->getStyle("A1:{$lastColumn}4")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:{$logoEndColumn}4")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("{$titleStartColumn}1:{$titleEndColumn}2")->getFont()
            ->setName('Arial')
            ->setSize($isSummary ? 13 : 14)
            ->setBold(true);
        $sheet->getStyle("{$titleStartColumn}3:{$titleEndColumn}4")->getFont()
            ->setName('Arial')
            ->setSize(10);
        $sheet->getStyle("{$titleStartColumn}1:{$titleEndColumn}4")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle("{$metaStartColumn}1:{$lastColumn}3")->getFont()
            ->setName('Arial')
            ->setSize($isSummary ? 8 : 9);
        $sheet->getStyle("{$metaStartColumn}1:{$lastColumn}3")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
    }

    private function writeTable(
        Worksheet $sheet,
        array $payload,
        Collection $rows,
        array $columns
    ): int {
        $jenis = (string) ($payload['jenis'] ?? 'summary');
        $headerRow = self::HEADER_ROW;
        $lastColumnIndex = count($columns);
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);

        foreach ($columns as $index => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValueExplicit(
                "{$columnLetter}{$headerRow}",
                (string) $column['label'],
                DataType::TYPE_STRING
            );
        }

        $sheet->getRowDimension($headerRow)->setRowHeight(28);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => $jenis === 'summary' ? 9 : 8,
                'bold' => true,
                'color' => ['argb' => 'FF080808'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFD8DAE0'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => $this->tableBorders(),
        ]);

        $currentRow = $headerRow + 1;

        if ($rows->isEmpty()) {
            $sheet->mergeCells("A{$currentRow}:{$lastColumn}{$currentRow}");
            $sheet->setCellValueExplicit(
                "A{$currentRow}",
                'Tidak ada data insentif pada periode dan filter yang dipilih.',
                DataType::TYPE_STRING
            );
            $sheet->getStyle("A{$currentRow}:{$lastColumn}{$currentRow}")->applyFromArray([
                'font' => [
                    'name' => 'Arial',
                    'size' => 9,
                    'color' => ['argb' => 'FF666666'],
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => $this->tableBorders(),
            ]);
            $sheet->getRowDimension($currentRow)->setRowHeight(24);
            $currentRow++;
        } else {
            foreach ($rows as $row) {
                foreach ($columns as $index => $column) {
                    $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
                    $coordinate = "{$columnLetter}{$currentRow}";
                    $value = data_get($row, $column['key']);
                    $type = $column['type'] ?? 'text';

                    if (in_array($type, ['number', 'currency'], true)) {
                        $sheet->setCellValue($coordinate, (float) ($value ?? 0));
                    } else {
                        $sheet->setCellValueExplicit(
                            $coordinate,
                            (string) ($value ?? '-'),
                            DataType::TYPE_STRING
                        );
                    }
                }

                $sheet->getStyle("A{$currentRow}:{$lastColumn}{$currentRow}")->applyFromArray([
                    'font' => [
                        'name' => 'Arial',
                        'size' => $jenis === 'summary' ? 8.5 : 7.5,
                    ],
                    'alignment' => [
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => $this->tableBorders(),
                ]);
                $sheet->getRowDimension($currentRow)->setRowHeight(-1);
                $currentRow++;
            }
        }

        $grandTotalRow = $currentRow;
        $labelEndColumn = Coordinate::stringFromColumnIndex($lastColumnIndex - 1);
        $sheet->mergeCells("A{$grandTotalRow}:{$labelEndColumn}{$grandTotalRow}");
        $sheet->setCellValueExplicit(
            "A{$grandTotalRow}",
            'GRAND TOTAL',
            DataType::TYPE_STRING
        );
        $sheet->setCellValue(
            "{$lastColumn}{$grandTotalRow}",
            (float) ($payload['totalInsentif'] ?? 0)
        );
        $sheet->getStyle("A{$grandTotalRow}:{$lastColumn}{$grandTotalRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => 9,
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFEDF4E5'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_RIGHT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => $this->tableBorders(),
        ]);
        $sheet->getStyle("{$lastColumn}{$grandTotalRow}")
            ->getNumberFormat()
            ->setFormatCode('#,##0');
        $sheet->getRowDimension($grandTotalRow)->setRowHeight(22);

        $dataStartRow = $headerRow + 1;
        $dataEndRow = max($grandTotalRow - 1, $dataStartRow);

        if ($rows->isNotEmpty()) {
            foreach ($columns as $index => $column) {
                $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
                $type = $column['type'] ?? 'text';
                $range = "{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}";

                if ($type === 'currency') {
                    $sheet->getStyle($range)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                    $sheet->getStyle($range)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } elseif ($type === 'number') {
                    $sheet->getStyle($range)
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.##');
                    $sheet->getStyle($range)
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $sheet->getStyle($range)
                        ->getAlignment()
                        ->setHorizontal($column['align'] ?? Alignment::HORIZONTAL_LEFT);
                }
            }
        }

        return $grandTotalRow;
    }

    private function writeNotes(
        Worksheet $sheet,
        int $startRow,
        int $columnCount,
        string $jenis
    ): int {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->mergeCells("A{$startRow}:{$lastColumn}{$startRow}");
        $sheet->setCellValueExplicit("A{$startRow}", 'Catatan:', DataType::TYPE_STRING);
        $sheet->getStyle("A{$startRow}")->getFont()->setItalic(true)->setSize(9);

        $notes = $jenis === 'summary'
            ? [
                '• Total Insentif = QTY x Insentif (Rp).',
                '• Data diambil dari transaksi reguler (pembayaran) dan realisasi deposit.',
                '• Insentif (Rp) menggunakan rate "Tarif Nurse" dari master treatment.',
            ]
            : [
                '• Total Insentif = QTY x Insentif (Rp). Insentif (Rp) menggunakan rate "Tarif Nurse".',
            ];

        $lastRow = $startRow;
        foreach ($notes as $offset => $note) {
            $row = $startRow + 1 + $offset;
            $lastRow = $row;
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValueExplicit("A{$row}", $note, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'font' => [
                    'name' => 'Arial',
                    'size' => 9,
                ],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }

        return $lastRow;
    }

    private function configureSheet(Worksheet $sheet, array $columns, int $lastContentRow): void
    {
        $this->applyColumnWidths($sheet, $columns);

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(self::HEADER_ROW, self::HEADER_ROW);

        $sheet->getPageMargins()
            ->setTop(0.35)
            ->setRight(0.30)
            ->setBottom(0.35)
            ->setLeft(0.30)
            ->setHeader(0.15)
            ->setFooter(0.15);

        $sheet->getHeaderFooter()->setOddFooter('&RHalaman &P / &N');
        $sheet->freezePane('A' . (self::HEADER_ROW + 1));
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}{$lastContentRow}");
    }

    private function applyColumnWidths(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $index => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($columnLetter)->setWidth((float) $column['width']);
        }
    }

    private function addCenteredLogo(Worksheet $sheet, string $endColumn): bool
    {
        $logoPath = public_path('logo.png');
        if (! is_file($logoPath) || ! is_readable($logoPath)) {
            return false;
        }

        $imageSize = @getimagesize($logoPath);
        if (! is_array($imageSize) || empty($imageSize[0]) || empty($imageSize[1])) {
            return false;
        }

        $areaWidth = 0;
        $endColumnIndex = Coordinate::columnIndexFromString($endColumn);
        for ($columnIndex = 1; $columnIndex <= $endColumnIndex; $columnIndex++) {
            $columnLetter = Coordinate::stringFromColumnIndex($columnIndex);
            $columnWidth = (float) $sheet->getColumnDimension($columnLetter)->getWidth();
            $areaWidth += $this->excelColumnWidthToPixels($columnWidth);
        }

        $areaHeight = 0;
        for ($row = 1; $row <= 4; $row++) {
            $rowHeight = (float) $sheet->getRowDimension($row)->getRowHeight();
            $areaHeight += $this->pointsToPixels($rowHeight > 0 ? $rowHeight : 15.0);
        }

        $sourceWidth = (int) $imageSize[0];
        $sourceHeight = (int) $imageSize[1];
        $maxWidth = max(1, $areaWidth - 24);
        $maxHeight = max(1, $areaHeight - 16);
        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1.0);

        $drawingWidth = max(1, (int) round($sourceWidth * $scale));
        $drawingHeight = max(1, (int) round($sourceHeight * $scale));
        $offsetX = max(0, (int) floor(($areaWidth - $drawingWidth) / 2));
        $offsetY = max(0, (int) floor(($areaHeight - $drawingHeight) / 2));

        $drawing = new Drawing();
        $drawing->setName('MS Glow Aesthetic');
        $drawing->setDescription('MS Glow Aesthetic');
        $drawing->setPath($logoPath);
        $drawing->setResizeProportional(false);
        $drawing->setWidth($drawingWidth);
        $drawing->setHeight($drawingHeight);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX($offsetX);
        $drawing->setOffsetY($offsetY);
        $drawing->setWorksheet($sheet);

        return true;
    }

    private function mergeIfNeeded(Worksheet $sheet, string $start, string $end): void
    {
        if ($start !== $end) {
            $sheet->mergeCells("{$start}:{$end}");
        }
    }

    private function excelColumnWidthToPixels(float $width): int
    {
        if ($width <= 0) {
            return 0;
        }

        return $width < 1
            ? (int) round($width * 12)
            : (int) round(($width * 7) + 5);
    }

    private function pointsToPixels(float $points): int
    {
        return (int) round($points * 96 / 72);
    }

    private function columns(string $jenis): array
    {
        if ($jenis === 'summary') {
            return [
                [
                    'key' => 'nama_item',
                    'label' => 'NAMA TREATMENT',
                    'type' => 'text',
                    'width' => 42,
                ],
                [
                    'key' => 'total_qty',
                    'label' => 'QTY',
                    'type' => 'number',
                    'width' => 10,
                ],
                [
                    'key' => 'harga_awal',
                    'label' => 'HARGA AWAL',
                    'type' => 'currency',
                    'width' => 20,
                ],
                [
                    'key' => 'insentif_rupiah',
                    'label' => 'INSENTIF (Rp)',
                    'type' => 'currency',
                    'width' => 18,
                ],
                [
                    'key' => 'total_insentif',
                    'label' => 'TOTAL INSENTIF',
                    'type' => 'currency',
                    'width' => 20,
                ],
            ];
        }

        return [
            [
                'key' => 'tanggal',
                'label' => 'TANGGAL',
                'type' => 'text',
                'width' => 13,
                'align' => Alignment::HORIZONTAL_CENTER,
            ],
            [
                'key' => 'no_invoice',
                'label' => 'FAKTUR',
                'type' => 'text',
                'width' => 20,
                'align' => Alignment::HORIZONTAL_CENTER,
            ],
            [
                'key' => 'pasien_nama',
                'label' => 'NAMA PASIEN',
                'type' => 'text',
                'width' => 25,
            ],
            [
                'key' => 'nama_item',
                'label' => 'NAMA TREATMENT',
                'type' => 'text',
                'width' => 38,
            ],
            [
                'key' => 'jenis_transaksi_label',
                'label' => 'JENIS TRANSAKSI',
                'type' => 'text',
                'width' => 22,
            ],
            [
                'key' => 'qty',
                'label' => 'QTY',
                'type' => 'number',
                'width' => 9,
            ],
            [
                'key' => 'harga_awal',
                'label' => 'HARGA AWAL',
                'type' => 'currency',
                'width' => 18,
            ],
            [
                'key' => 'setelah_diskon',
                'label' => 'SETELAH DISKON',
                'type' => 'currency',
                'width' => 18,
            ],
            [
                'key' => 'insentif_rupiah',
                'label' => 'INSENTIF (Rp)',
                'type' => 'currency',
                'width' => 18,
            ],
            [
                'key' => 'nilai_insentif',
                'label' => 'TOTAL INSENTIF',
                'type' => 'currency',
                'width' => 20,
            ],
        ];
    }

    private function tableBorders(): array
    {
        return [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['argb' => 'FF666666'],
            ],
        ];
    }

    private function withExtension(string $filename, string $extension): string
    {
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $basename = trim($basename) !== '' ? trim($basename) : 'laporan-insentif-nurse';

        return $basename . '.' . ltrim($extension, '.');
    }

    private function logoDataUri(): ?string
    {
        $logoPath = public_path('logo.png');
        if (! is_file($logoPath) || ! is_readable($logoPath)) {
            return null;
        }

        $binary = file_get_contents($logoPath);
        if ($binary === false) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($binary);
    }
}
