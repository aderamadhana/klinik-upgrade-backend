<?php

namespace App\Services\Laporan;

use Barryvdh\DomPDF\Facade\Pdf;

class LaporanBelumRealisasiDepositExportService
{
    public function pdf(array $report)
    {
        $pdf = Pdf::loadView('laporan.belum-realisasi-deposit.export', [
            'report' => $report,
        ])->setPaper('a4', 'portrait');

        $pdf->setOption('isRemoteEnabled', true);
        $pdf->setOption('defaultFont', 'Times New Roman');

        return $pdf->stream($report['filename_base'] . '.pdf', [
            'Attachment' => false,
        ]);
    }
}
