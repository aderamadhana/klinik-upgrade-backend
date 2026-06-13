<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class LaporanInsentifApotekerExportService
{
    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     */
    public function pdf(Collection $rows, array $filters, string $filename): Response
    {
        $groups = $this->groupRows($rows, $filters);

        $pdf = Pdf::loadView('laporan.insentif-apoteker.export', [
            'groups' => $groups,
            'filters' => $filters,
            'periodeLabel' => $this->periodLabel($filters),
            'logoDataUri' => $this->logoDataUri(),
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'Times-Roman',
                'dpi' => 96,
            ]);

        return $pdf->download($filename);
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     */
    public function excel(Collection $rows, array $filters, string $filename): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);
        $groups = $this->groupRows($rows, $filters);

        foreach ($groups as $index => $group) {
            $title = $this->uniqueSheetTitle(
                $spreadsheet,
                (string) ($group['apoteker_nama'] ?? 'Apoteker'),
                $index + 1
            );

            $sheet = new Worksheet($spreadsheet, $title);
            $spreadsheet->addSheet($sheet);
            $this->fillWorksheet($sheet, $group, $filters);
        }

        if ($spreadsheet->getSheetCount() === 0) {
            $sheet = new Worksheet($spreadsheet, 'Insentif Apoteker');
            $spreadsheet->addSheet($sheet);
            $this->fillWorksheet($sheet, [
                'apoteker_nama' => $filters['apoteker_nama'] ?: 'Semua Apoteker / Asisten',
                'apoteker_jabatan' => $filters['apoteker_jabatan'],
                'rows' => collect(),
                'total_insentif' => 0,
            ], $filters);
        }

        $spreadsheet->setActiveSheetIndex(0);
        $spreadsheet->getProperties()
            ->setCreator('MS Glow Aesthetic Clinic')
            ->setTitle('Laporan Insentif Apoteker')
            ->setSubject($this->periodLabel($filters));

        $temporaryPath = tempnam(sys_get_temp_dir(), 'insentif-apoteker-');

        if ($temporaryPath === false) {
            throw new \RuntimeException('Gagal membuat file sementara laporan Excel.');
        }

        $xlsxPath = $temporaryPath . '.xlsx';
        @unlink($temporaryPath);

        (new Xlsx($spreadsheet))->save($xlsxPath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response()
            ->download(
                $xlsxPath,
                $filename,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
            )
            ->deleteFileAfterSend(true);
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(Collection $rows, array $filters): Collection
    {
        if ($rows->isEmpty()) {
            return collect([[
                'apoteker_id' => $filters['apoteker_id'] ?? null,
                'apoteker_nama' => $filters['apoteker_nama'] ?: 'Semua Apoteker / Asisten Apoteker',
                'apoteker_jabatan' => $filters['apoteker_jabatan'],
                'rows' => collect(),
                'total_insentif' => 0.0,
            ]]);
        }

        return $rows
            ->groupBy(static fn (array $row): string => implode('|', [
                $row['apoteker_id'] ?? 0,
                $row['apoteker_nama'] ?? '-',
            ]))
            ->map(static function (Collection $items): array {
                $first = $items->first();

                return [
                    'apoteker_id' => $first['apoteker_id'] ?? null,
                    'apoteker_nama' => $first['apoteker_nama'] ?? '-',
                    'apoteker_jabatan' => $first['apoteker_jabatan'] ?? null,
                    'rows' => $items->values(),
                    'total_insentif' => (float) $items->sum('nilai_insentif'),
                ];
            })
            ->sortBy('apoteker_nama')
            ->values();
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $filters
     */
    private function fillWorksheet(Worksheet $sheet, array $group, array $filters): void
    {
        $sheet->getParent()->getDefaultStyle()->getFont()
            ->setName('Times New Roman')
            ->setSize(11);

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(28);

        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->getRowDimension(2)->setRowHeight(20);
        $sheet->getRowDimension(3)->setRowHeight(20);
        $sheet->getRowDimension(4)->setRowHeight(10);

        $sheet->mergeCells('A1:A3');
        $sheet->mergeCells('B1:B1');
        $sheet->mergeCells('B2:B2');
        $sheet->mergeCells('B3:B3');

        $this->addLogo($sheet);

        $sheet->setCellValue('B1', 'PT. KOSMETIKA KLINIK INDONESIA');
        $sheet->setCellValue('B2', 'Email : admin@msglowclinic.id | Website : www.msglowclinic.id');
        $sheet->setCellValue('B3', $filters['toko_nama'] ?: 'MS GLOW AESTHETIC CLINIC');

        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(17);
        $sheet->getStyle('B2:B3')->getFont()->setSize(10);
        $sheet->getStyle('B1:B3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $bottomBorder = $sheet->getStyle('A4:B4')->getBorders()->getBottom();
        $bottomBorder->setBorderStyle(Border::BORDER_THIN);
        $bottomBorder->getColor()->setRGB('8A8A8A');

        $sheet->mergeCells('A5:B5');
        $sheet->setCellValue(
            'A5',
            'TANGGAL : ' . $this->periodLabel($filters)
            . ' - Nama : ' . mb_strtoupper((string) ($group['apoteker_nama'] ?? '-'))
        );
        $sheet->getStyle('A5')->getFont()->setBold(true);
        $sheet->getStyle('A5')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(5)->setRowHeight(22);

        $sheet->setCellValue('A6', 'No. Faktur');
        $sheet->setCellValue('B6', 'Fee');
        $sheet->getStyle('A6:B6')->getFont()->setBold(true);
        $sheet->getStyle('A6:B6')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_LEFT)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A6:B6')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E7E7E7');

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = $group['rows'];
        $rowNumber = 7;

        if ($rows->isEmpty()) {
            $sheet->mergeCells("A{$rowNumber}:B{$rowNumber}");
            $sheet->setCellValue("A{$rowNumber}", 'Tidak ada data insentif pada periode dan filter yang dipilih.');
            $sheet->getStyle("A{$rowNumber}")->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $rowNumber++;
        } else {
            foreach ($rows as $item) {
                $sheet->setCellValueExplicit(
                    "A{$rowNumber}",
                    (string) ($item['no_invoice'] ?? '-'),
                    DataType::TYPE_STRING
                );
                $sheet->setCellValue("B{$rowNumber}", (float) ($item['nilai_insentif'] ?? 0));
                $rowNumber++;
            }
        }

        $totalRow = $rowNumber;
        $sheet->setCellValue("A{$totalRow}", 'TOTAL INSENTIF');
        $sheet->setCellValue("B{$totalRow}", (float) ($group['total_insentif'] ?? 0));
        $sheet->getStyle("A{$totalRow}:B{$totalRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$totalRow}:B{$totalRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EEF5E8');
        $sheet->getStyle("A{$totalRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle("B7:B{$totalRow}")
            ->getNumberFormat()
            ->setFormatCode('"Rp" #,##0');

        $allBorders = $sheet->getStyle("A6:B{$totalRow}")->getBorders()->getAllBorders();
        $allBorders->setBorderStyle(Border::BORDER_THIN);
        $allBorders->getColor()->setRGB('555555');

        $sheet->getStyle("A6:B{$totalRow}")->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);

        $noteRow = $totalRow + 2;
        $sheet->setCellValue("A{$noteRow}", 'Catatan:');
        $sheet->getStyle("A{$noteRow}")->getFont()->setItalic(true);
        $sheet->mergeCells("A" . ($noteRow + 1) . ":B" . ($noteRow + 1));
        $sheet->setCellValue(
            'A' . ($noteRow + 1),
            'Insentif dihitung satu kali untuk setiap resep/faktur yang selesai diproses oleh petugas.'
        );
        $sheet->mergeCells("A" . ($noteRow + 2) . ":B" . ($noteRow + 2));
        $sheet->setCellValue(
            'A' . ($noteRow + 2),
            'Fee per resep: Rp ' . number_format((float) ($filters['fee_per_resep'] ?? 0), 0, ',', '.') . '.'
        );

        $sheet->freezePane('A7');
        $sheet->setAutoFilter("A6:B{$totalRow}");
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()
            ->setTop(0.45)
            ->setRight(0.45)
            ->setBottom(0.45)
            ->setLeft(0.45)
            ->setHeader(0.2)
            ->setFooter(0.2);
        $sheet->getPageSetup()->setPrintArea("A1:B" . ($noteRow + 2));
        $sheet->getHeaderFooter()->setOddFooter('&RHalaman &P / &N');
    }

    private function addLogo(Worksheet $sheet): void
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            $sheet->setCellValue('A1', 'MS GLOW AESTHETICS');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_CENTER);
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('Logo MS Glow Aesthetics');
        $drawing->setPath($logoPath);
        $drawing->setCoordinates('A1');
        $drawing->setHeight(62);
        // Lebar kolom A sekitar 290 px; offset ini menempatkan logo di tengah.
        $drawing->setOffsetX(48);
        $drawing->setOffsetY(9);
        $drawing->setWorksheet($sheet);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function periodLabel(array $filters): string
    {
        return $this->indonesianDate((string) $filters['tanggal_awal'])
            . ' s/d '
            . $this->indonesianDate((string) $filters['tanggal_akhir']);
    }

    private function indonesianDate(string $date): string
    {
        $monthNames = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $carbon = Carbon::parse($date);

        return $carbon->day . ' ' . $monthNames[$carbon->month] . ' ' . $carbon->year;
    }

    private function logoDataUri(): ?string
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            return null;
        }

        $mime = mime_content_type($logoPath) ?: 'image/png';
        $content = file_get_contents($logoPath);

        if ($content === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    private function uniqueSheetTitle(Spreadsheet $spreadsheet, string $name, int $index): string
    {
        // Karakter berikut tidak diperbolehkan pada nama worksheet Excel:
        // backslash, slash, question mark, asterisk, square brackets, dan colon.
        // Gunakan str_replace agar tidak bergantung pada escaping regex delimiter.
        $sanitizedName = str_replace(
            ['\\', '/', '?', '*', '[', ']', ':'],
            ' ',
            $name
        );

        $base = Str::limit(Str::squish($sanitizedName), 25, '');
        $base = $base !== '' ? $base : 'Apoteker ' . $index;
        $candidate = $base;
        $counter = 1;

        while ($spreadsheet->getSheetByName($candidate) !== null) {
            $suffix = '-' . $counter++;
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix)) . $suffix;
        }

        return mb_substr($candidate, 0, 31);
    }
}
