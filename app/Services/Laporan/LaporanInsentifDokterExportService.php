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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LaporanInsentifDokterExportService
{
    private const HEADER_ROW = 6;

    /**
     * Menghasilkan PDF biner menggunakan Dompdf.
     */
    public function pdf(array $payload, string $filename): Response
    {
        $pdf = Pdf::loadView('laporan.insentif-dokter.export', array_merge($payload, [
            'logoDataUri' => $this->logoDataUri(),
        ]))
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

    /**
     * Menghasilkan workbook XLSX asli menggunakan PhpSpreadsheet.
     */
    public function excel(array $payload, string $filename): StreamedResponse
    {
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
        $kategori = (string) ($payload['kategori'] ?? 'treatment');
        $jenis = (string) ($payload['jenis'] ?? 'summary');
        $rows = collect($payload['rows'] ?? []);
        $columns = $this->columns($kategori, $jenis);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('MS Glow Aesthetic')
            ->setLastModifiedBy('MS Glow Aesthetic')
            ->setTitle((string) ($payload['title'] ?? 'Laporan Insentif Dokter'))
            ->setSubject('Laporan Insentif Dokter')
            ->setDescription('Laporan insentif dokter yang dibuat oleh sistem klinik.');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($jenis === 'summary' ? 'Summary' : 'Detail');
        $sheet->setShowGridlines(false);
        $sheet->setSelectedCell('A1');

        // Lebar kolom harus ditetapkan sebelum logo dihitung agar posisi tengah akurat.
        $this->applyColumnWidths($sheet, $columns);
        $this->writeReportHeader($sheet, $payload, count($columns));
        $lastDataRow = $this->writeTable($sheet, $payload, $rows, $columns);
        $this->writeNotes($sheet, $lastDataRow + 2, count($columns), (int) ($payload['ppnRate'] ?? 11));
        $this->configureSheet($sheet, $columns, $lastDataRow);

        return $spreadsheet;
    }

    private function writeReportHeader(Worksheet $sheet, array $payload, int $columnCount): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $isSummary = ($payload['jenis'] ?? 'summary') === 'summary';

        $logoEndColumn = $isSummary ? 'B' : 'C';
        $titleStartColumn = $isSummary ? 'C' : 'D';
        $titleEndColumn = $isSummary ? 'F' : 'I';
        $metaStartColumn = $isSummary ? 'G' : 'J';

        $sheet->mergeCells("A1:{$logoEndColumn}4");
        $sheet->mergeCells("{$titleStartColumn}1:{$titleEndColumn}2");
        $sheet->mergeCells("{$titleStartColumn}3:{$titleEndColumn}4");
        $sheet->mergeCells("{$metaStartColumn}1:{$lastColumn}1");
        $sheet->mergeCells("{$metaStartColumn}2:{$lastColumn}2");
        $sheet->mergeCells("{$metaStartColumn}3:{$lastColumn}3");

        $sheet->setCellValueExplicit(
            "{$titleStartColumn}1",
            strtoupper((string) ($payload['title'] ?? 'LAPORAN INSENTIF DOKTER')),
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            "{$titleStartColumn}3",
            strtoupper((string) ($payload['clinicName'] ?? 'MS GLOW AESTHETIC')),
            DataType::TYPE_STRING
        );

        $filters = (array) ($payload['filters'] ?? []);
        $doctorLabel = strtoupper((string) ($filters['dokter_nama'] ?? '-'));
        if ((int) ($filters['is_dokter_spesialis'] ?? 0) === 1) {
            $doctorLabel .= ' (Spesialis)';
        }

        $sheet->setCellValueExplicit(
            "{$metaStartColumn}1",
            'Periode: ' . (string) ($payload['period'] ?? '-'),
            DataType::TYPE_STRING
        );
        $sheet->setCellValueExplicit(
            "{$metaStartColumn}2",
            'Dokter: ' . $doctorLabel,
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

        $sheet->getStyle("A1:{$lastColumn}4")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:{$logoEndColumn}4")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getStyle("{$titleStartColumn}1:{$titleEndColumn}2")->getFont()
            ->setName('Arial')
            ->setSize(14)
            ->setBold(true);
        $sheet->getStyle("{$titleStartColumn}3:{$titleEndColumn}4")->getFont()
            ->setName('Arial')
            ->setSize(10);

        $sheet->getStyle("{$titleStartColumn}1:{$titleEndColumn}4")->getBorders()->getLeft()
            ->setBorderStyle(Border::BORDER_MEDIUM)
            ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DC8B19'));
        $sheet->getStyle("{$titleStartColumn}1:{$titleEndColumn}4")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle("{$metaStartColumn}1:{$lastColumn}3")->getFont()
            ->setName('Arial')
            ->setSize(9);
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

        $sheet->getRowDimension($headerRow)->setRowHeight(30);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
            'font' => [
                'name' => 'Arial',
                'size' => ($payload['jenis'] ?? 'summary') === 'summary' ? 9 : 8,
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
                'font' => ['name' => 'Arial', 'size' => 9, 'color' => ['argb' => 'FF666666']],
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

                    if (in_array($type, ['number', 'currency', 'percent'], true)) {
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
                    'font' => ['name' => 'Arial', 'size' => ($payload['jenis'] ?? 'summary') === 'summary' ? 8.5 : 7.5],
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
        $sheet->setCellValueExplicit("A{$grandTotalRow}", 'GRAND TOTAL', DataType::TYPE_STRING);
        $sheet->setCellValue(
            "{$lastColumn}{$grandTotalRow}",
            (float) ($payload['totalInsentif'] ?? 0)
        );
        $sheet->getStyle("A{$grandTotalRow}:{$lastColumn}{$grandTotalRow}")->applyFromArray([
            'font' => ['name' => 'Arial', 'size' => 9, 'bold' => true],
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

                if ($type === 'currency') {
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0');
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } elseif ($type === 'number') {
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.##');
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } elseif ($type === 'percent') {
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getNumberFormat()
                        ->setFormatCode('0.##');
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                } else {
                    $sheet->getStyle("{$columnLetter}{$dataStartRow}:{$columnLetter}{$dataEndRow}")
                        ->getAlignment()
                        ->setHorizontal($column['align'] ?? Alignment::HORIZONTAL_LEFT);
                }
            }
        }

        return $grandTotalRow;
    }

    private function writeNotes(Worksheet $sheet, int $startRow, int $columnCount, int $ppnRate): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($columnCount);
        $sheet->mergeCells("A{$startRow}:{$lastColumn}{$startRow}");
        $sheet->setCellValueExplicit("A{$startRow}", 'Catatan:', DataType::TYPE_STRING);
        $sheet->getStyle("A{$startRow}")->getFont()->setItalic(true)->setSize(9);

        $notes = [
            "• Dasar Fee dihitung dari Setelah Diskon – PPN {$ppnRate}% (hanya berlaku untuk transaksi reguler dengan skema Persen).",
            '• Insentif untuk transaksi endorse atau transaksi reguler dengan skema Flat dihitung berdasarkan Insentif (Rp) satuan dikali Qty.',
            '• Jika kolom Insentif (%) berisi angka, maka perhitungan menggunakan persentase dari Dasar Fee.',
            '• Jika kolom Insentif (Rp) berisi angka, maka perhitungan menggunakan nominal flat satuan.',
        ];

        foreach ($notes as $offset => $note) {
            $row = $startRow + 1 + $offset;
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->setCellValueExplicit("A{$row}", $note, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->applyFromArray([
                'font' => ['name' => 'Arial', 'size' => 9],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_TOP,
                    'wrapText' => true,
                ],
            ]);
            $sheet->getRowDimension($row)->setRowHeight(16);
        }
    }

    private function configureSheet(Worksheet $sheet, array $columns, int $lastDataRow): void
    {
        $this->applyColumnWidths($sheet, $columns);

        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getPageSetup()
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
            ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
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
        $sheet->getPageSetup()->setPrintArea("A1:{$lastColumn}" . ($lastDataRow + 6));
    }

    private function applyColumnWidths(Worksheet $sheet, array $columns): void
    {
        foreach ($columns as $index => $column) {
            $columnLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->getColumnDimension($columnLetter)->setWidth((float) $column['width']);
        }
    }

    /**
     * Menempatkan logo tepat di tengah area merge A1:{endColumn}4.
     * Perhitungan dilakukan dalam pixel agar stabil pada summary maupun detail.
     */
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

    private function excelColumnWidthToPixels(float $width): int
    {
        if ($width <= 0) {
            return 0;
        }

        // Aproksimasi Excel untuk font default Calibri/Arial ukuran normal.
        return $width < 1
            ? (int) round($width * 12)
            : (int) round(($width * 7) + 5);
    }

    private function pointsToPixels(float $points): int
    {
        return (int) round($points * 96 / 72);
    }

    private function columns(string $kategori, string $jenis): array
    {
        $itemLabel = $kategori === 'treatment' ? 'TREATMENT' : 'PRODUK';

        if ($jenis === 'summary') {
            return [
                ['key' => 'nama_item', 'label' => "NAMA {$itemLabel}", 'type' => 'text', 'width' => 34],
                ['key' => 'total_qty', 'label' => 'QTY', 'type' => 'number', 'width' => 9],
                ['key' => 'harga_awal', 'label' => 'HARGA AWAL', 'type' => 'currency', 'width' => 17],
                ['key' => 'setelah_diskon', 'label' => 'SETELAH DISKON', 'type' => 'currency', 'width' => 18],
                ['key' => 'ppn_11', 'label' => 'PPN 11%', 'type' => 'currency', 'width' => 15],
                ['key' => 'dasar_fee', 'label' => 'DASAR FEE', 'type' => 'currency', 'width' => 17],
                ['key' => 'insentif_persen', 'label' => 'INSENTIF (%)', 'type' => 'percent', 'width' => 14],
                ['key' => 'insentif_rupiah', 'label' => 'INSENTIF (Rp)', 'type' => 'currency', 'width' => 17],
                ['key' => 'total_insentif', 'label' => 'TOTAL INSENTIF', 'type' => 'currency', 'width' => 19],
            ];
        }

        return [
            ['key' => 'tanggal', 'label' => 'TANGGAL', 'type' => 'text', 'width' => 12, 'align' => Alignment::HORIZONTAL_CENTER],
            ['key' => 'no_invoice', 'label' => 'NO INVOICE', 'type' => 'text', 'width' => 19],
            ['key' => 'toko_nama', 'label' => 'CABANG', 'type' => 'text', 'width' => 14],
            ['key' => 'no_rm', 'label' => 'NO RM', 'type' => 'text', 'width' => 13],
            ['key' => 'pasien_nama', 'label' => 'PASIEN', 'type' => 'text', 'width' => 22],
            ['key' => 'jenis_transaksi_label', 'label' => 'JENIS TRANSAKSI', 'type' => 'text', 'width' => 22],
            ['key' => 'nama_item', 'label' => "NAMA {$itemLabel}", 'type' => 'text', 'width' => 34],
            ['key' => 'qty', 'label' => 'QTY', 'type' => 'number', 'width' => 9],
            ['key' => 'harga_awal', 'label' => 'HARGA AWAL', 'type' => 'currency', 'width' => 16],
            ['key' => 'setelah_diskon', 'label' => 'SETELAH DISKON', 'type' => 'currency', 'width' => 17],
            ['key' => 'ppn_11', 'label' => 'PPN 11%', 'type' => 'currency', 'width' => 14],
            ['key' => 'dasar_fee', 'label' => 'DASAR FEE', 'type' => 'currency', 'width' => 16],
            ['key' => 'insentif_persen', 'label' => 'INSENTIF (%)', 'type' => 'percent', 'width' => 13],
            ['key' => 'insentif_rupiah', 'label' => 'INSENTIF (Rp)', 'type' => 'currency', 'width' => 16],
            ['key' => 'nilai_insentif', 'label' => 'TOTAL INSENTIF', 'type' => 'currency', 'width' => 18],
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
