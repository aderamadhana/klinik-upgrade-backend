<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

class LaporanDetailPasienExportService
{
    private const COMPANY_NAME = 'PT. KOSMETIKA KLINIK INDONESIA';
    private const COMPANY_CONTACT =
        'Email : admin@msglowclinic.id | Website : www.msglowclinic.id';

    public function pdf(Collection $rows, array $filters): Response
    {
        $filename = $this->filename('pdf', $filters);
        $logoDataUri = $this->logoDataUri();

        $pdf = Pdf::loadView('laporan.detail-pasien.export', [
            'rows' => $rows,
            'filters' => $filters,
            'periodLabel' => $this->periodLabel($filters),
            'companyName' => self::COMPANY_NAME,
            'companyContact' => self::COMPANY_CONTACT,
            'logoDataUri' => $logoDataUri,
            'totalTreatment' => (float) $rows->sum('total_treatment'),
            'totalProduk' => (float) $rows->sum('total_produk'),
        ])->setPaper('a4', 'portrait');

        return $pdf->stream($filename, [
            'Attachment' => false,
        ]);
    }

    public function excel(Collection $rows, array $filters): Response
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Detail Pasien');

        $this->fillWorksheet($sheet, $rows, $filters);

        $filename = $this->filename('xlsx', $filters);
        $tempFile = tempnam(sys_get_temp_dir(), 'detail-pasien-');

        if ($tempFile === false) {
            throw new \RuntimeException('Gagal membuat file sementara untuk export Excel.');
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return response()
            ->download(
                $tempFile,
                $filename,
                [
                    'Content-Type' =>
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                    'Pragma' => 'public',
                ]
            )
            ->deleteFileAfterSend(true);
    }

    private function fillWorksheet($sheet, Collection $rows, array $filters): void
    {
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Times New Roman');
        $sheet->getParent()->getDefaultStyle()->getFont()->setSize(10);

        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(46);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(18);

        $sheet->mergeCells('A1:B4');
        $sheet->mergeCells('C1:E2');
        $sheet->mergeCells('C3:E3');

        $sheet->setCellValue('C1', self::COMPANY_NAME);
        $sheet->setCellValue('C3', self::COMPANY_CONTACT);

        $sheet->getStyle('C1:E2')->getFont()->setBold(true)->setSize(18);
        $sheet->getStyle('C1:E2')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C3:E3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        $sheet->getRowDimension(1)->setRowHeight(25);
        $sheet->getRowDimension(2)->setRowHeight(25);
        $sheet->getRowDimension(3)->setRowHeight(19);
        $sheet->getRowDimension(4)->setRowHeight(12);

        $this->addLogo($sheet);

        $sheet->getStyle('A5:E5')->applyFromArray([
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '8A8A8A'],
                ],
            ],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(8);

        $sheet->mergeCells('A7:E7');
        $periodText = 'TANGGAL : ' . $this->periodLabel($filters);

        if (! empty($filters['toko_nama'])) {
            $periodText .= ' - Cabang : ' . strtoupper((string) $filters['toko_nama']);
        }

        $sheet->setCellValue('A7', $periodText);
        $sheet->getStyle('A7')->getFont()->setSize(11);

        $headers = ['No.', 'Faktur', 'Pasien', 'Treatment', 'Produk'];
        $sheet->fromArray($headers, null, 'A8');

        $sheet->getStyle('A8:E8')->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E7E7E7'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '555555'],
                ],
            ],
        ]);

        $startRow = 9;
        $currentRow = $startRow;

        if ($rows->isEmpty()) {
            $sheet->mergeCells("A{$currentRow}:E{$currentRow}");
            $sheet->setCellValue(
                "A{$currentRow}",
                'Tidak ada data transaksi pada periode yang dipilih.'
            );
            $sheet->getStyle("A{$currentRow}:E{$currentRow}")
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $currentRow++;
        } else {
            foreach ($rows as $row) {
                $sheet->setCellValue("A{$currentRow}", (int) $row['no']);
                $sheet->setCellValueExplicit(
                    "B{$currentRow}",
                    (string) $row['no_invoice'],
                    DataType::TYPE_STRING
                );
                $sheet->setCellValue("C{$currentRow}", (string) $row['nama_pasien']);
                $sheet->setCellValue("D{$currentRow}", (float) $row['total_treatment']);
                $sheet->setCellValue("E{$currentRow}", (float) $row['total_produk']);
                $currentRow++;
            }
        }

        $dataEndRow = $currentRow - 1;
        $totalRow = $currentRow;

        $sheet->mergeCells("A{$totalRow}:C{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'GRAND TOTAL');
        $sheet->setCellValue("D{$totalRow}", (float) $rows->sum('total_treatment'));
        $sheet->setCellValue("E{$totalRow}", (float) $rows->sum('total_produk'));

        $sheet->getStyle("A{$totalRow}:E{$totalRow}")->applyFromArray([
            'font' => [
                'bold' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EEF5E6'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '555555'],
                ],
            ],
        ]);
        $sheet->getStyle("A{$totalRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->getStyle("A8:E{$dataEndRow}")->applyFromArray([
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '555555'],
                ],
            ],
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getStyle("A{$startRow}:A{$dataEndRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("D{$startRow}:E{$totalRow}")
            ->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("D{$startRow}:E{$totalRow}")
            ->getNumberFormat()
            ->setFormatCode('"Rp" #,##0');

        $sheet->getStyle("B{$startRow}:C{$dataEndRow}")
            ->getAlignment()
            ->setWrapText(true);

        $sheet->freezePane('A9');
        $autoFilterEndRow = max(8, $dataEndRow);
        $sheet->setAutoFilter("A8:E{$autoFilterEndRow}");

        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageSetup()->setFitToPage(true);
        $sheet->getPageMargins()->setTop(0.45);
        $sheet->getPageMargins()->setBottom(0.45);
        $sheet->getPageMargins()->setLeft(0.35);
        $sheet->getPageMargins()->setRight(0.35);
        $sheet->getHeaderFooter()->setOddFooter('&RHalaman &P / &N');
        $sheet->getPageSetup()->setPrintArea("A1:E{$totalRow}");
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(8, 8);
    }

    private function addLogo($sheet): void
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            return;
        }

        $drawing = new Drawing();
        $drawing->setName('MS Glow Aesthetics');
        $drawing->setDescription('MS Glow Aesthetics');
        $drawing->setPath($logoPath);
        $drawing->setHeight(60);
        $drawing->setCoordinates('A1');
        $drawing->setOffsetX(18);
        $drawing->setOffsetY(12);
        $drawing->setWorksheet($sheet);
    }

    private function logoDataUri(): ?string
    {
        $logoPath = public_path('logo.png');

        if (! is_file($logoPath)) {
            return null;
        }

        $contents = file_get_contents($logoPath);

        if ($contents === false) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($contents);
    }

    private function periodLabel(array $filters): string
    {
        return Carbon::parse($filters['tanggal_awal'])
            ->locale('id')
            ->translatedFormat('j F Y')
            . ' s/d '
            . Carbon::parse($filters['tanggal_akhir'])
                ->locale('id')
                ->translatedFormat('j F Y');
    }

    private function filename(string $extension, array $filters): string
    {
        return implode('-', [
            'laporan',
            'detail',
            'pasien',
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.' . $extension;
    }
}
