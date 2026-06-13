<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanInsentifNurseBeauticianExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanInsentifNurseBeauticianController extends Controller
{
    public function __construct(
        private readonly LaporanInsentifNurseBeauticianExportService $exportService
    ) {
    }

    public function staff(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 50);
        $limit = $limit > 0 ? min($limit, 100) : 50;

        $query = DB::table('master_karyawan as k')
            ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
            ->where(function ($q) {
                $q->where('k.is_delete', 0)
                    ->orWhereNull('k.is_delete');
            })
            ->where(function ($q) {
                $q->whereIn('j.kode_jabatan', ['BC', 'NS'])
                    ->orWhere('j.nama_jabatan', 'like', '%beautician%')
                    ->orWhere('j.nama_jabatan', 'like', '%nurse%')
                    ->orWhere('j.nama_jabatan', 'like', '%perawat%');
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('k.nama', 'like', "%{$search}%")
                    ->orWhere('k.kode', 'like', "%{$search}%")
                    ->orWhere('j.nama_jabatan', 'like', "%{$search}%");
            });
        }

        $items = $query
            ->orderBy('j.sort_order')
            ->orderBy('k.nama')
            ->limit($limit)
            ->get([
                'k.id',
                'k.kode',
                'k.nama',
                'j.kode_jabatan',
                'j.nama_jabatan as jabatan',
            ])
            ->map(function ($item) {
                $jabatan = $item->jabatan ?: 'Nurse/Beautician';

                return [
                    'id' => (int) $item->id,
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'jabatan' => $jabatan,
                    'kode_jabatan' => $item->kode_jabatan,
                    'label' => trim(($item->nama ?? '-') . ' - ' . $jabatan),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data nurse/beautician berhasil diambil.',
            'data' => $items,
        ]);
    }

    public function summary(Request $request)
    {
        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows('summary', $filters);
        $aggregate = $this->makeSummaryAggregate($rows);

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan insentif nurse/beautician berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'treatment' => $aggregate,
                'grand_total_insentif' => $aggregate['total_insentif'],
            ],
        ]);
    }

    public function export(Request $request, string $jenis, string $format)
    {
        $jenis = strtolower($jenis);
        $format = strtolower($format);

        if (! in_array($jenis, ['summary', 'detail'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Jenis laporan harus summary atau detail.',
            ], 422);
        }

        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Format laporan harus pdf atau excel.',
            ], 422);
        }

        $filters = $this->normalizeFilters($request);

        if ($filters['validator']->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Filter laporan tidak valid.',
                'errors' => $filters['validator']->errors(),
            ], 422);
        }

        $filters = $filters['data'];
        $rows = $this->getRows($jenis, $filters);
        $publicFilters = $this->publicFilters($filters);
        $filename = $this->filename($jenis, $format, $filters);
        $totalInsentif = (float) $rows->sum(function ($row) {
            return $row['total_insentif'] ?? $row['nilai_insentif'] ?? 0;
        });

        $payload = [
            'title' => $this->title($jenis),
            'jenis' => $jenis,
            'rows' => $rows,
            'filters' => $publicFilters,
            'period' => Carbon::parse($filters['tanggal_awal'])->format('d/m/Y')
                . ' s/d '
                . Carbon::parse($filters['tanggal_akhir'])->format('d/m/Y'),
            'clinicName' => $publicFilters['toko_nama']
                ? 'MS GLOW AESTHETIC ' . strtoupper($publicFilters['toko_nama'])
                : 'MS GLOW AESTHETIC',
            'totalInsentif' => $totalInsentif,
        ];

        if ($format === 'excel') {
            return $this->exportService->excel($payload, $filename);
        }

        return $this->exportService->pdf($payload, $filename);
    }

    private function normalizeFilters(Request $request): array
    {
        $today = now()->toDateString();
        $tokoId = $request->query('toko_id', $request->header('X-Toko-Id'));
        $tokoId = is_numeric($tokoId) ? (int) $tokoId : null;

        $staffId = $request->query('staff_id', $request->query('perawat_id'));
        $staffId = is_numeric($staffId) ? (int) $staffId : null;

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query(
                'tanggal_akhir',
                $request->query('tanggal_awal', $today)
            ),
            'staff_id' => $staffId,
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'staff_id' => ['nullable', 'integer', 'exists:master_karyawan,id'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function publicFilters(array $filters): array
    {
        $staff = null;
        $toko = null;

        if (! empty($filters['staff_id'])) {
            $staff = DB::table('master_karyawan as k')
                ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
                ->where('k.id', $filters['staff_id'])
                ->first([
                    'k.id',
                    'k.nama',
                    'j.nama_jabatan as jabatan',
                ]);
        }

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')
                ->where('id', $filters['toko_id'])
                ->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'staff_id' => $filters['staff_id'] ? (int) $filters['staff_id'] : null,
            'staff_nama' => $staff->nama ?? null,
            'staff_jabatan' => $staff->jabatan ?? null,
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
        ];
    }

    private function makeSummaryAggregate($rows): array
    {
        return [
            'total_item' => $rows->count(),
            'total_qty' => (float) $rows->sum('total_qty'),
            'total_omzet' => (float) $rows->sum('total_omzet'),
            'total_insentif' => (float) $rows->sum('total_insentif'),
        ];
    }

    private function getRows(string $jenis, array $filters)
    {
        $details = $this->getTreatmentDetailRows($filters);

        if ($jenis === 'detail') {
            return $details;
        }

        return $details
            ->groupBy(function ($row) {
                return implode('|', [
                    $row['item_id'] ?? 0,
                    $row['nama_item'] ?? '-',
                    $row['insentif_rupiah'] ?? 0,
                ]);
            })
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'nama_item' => $first['nama_item'] ?? '-',
                    'total_qty' => (float) $items->sum('qty'),
                    'harga_awal' => (float) $items->sum('harga_awal'),
                    'insentif_rupiah' => (float) ($first['insentif_rupiah'] ?? 0),
                    'total_omzet' => (float) $items->sum('setelah_diskon'),
                    'total_insentif' => (float) $items->sum('nilai_insentif'),
                ];
            })
            ->sortBy('nama_item')
            ->values();
    }

    private function getTreatmentDetailRows(array $filters)
    {
        $effectiveDateSql = $this->effectiveDateSql();
        $effectiveStaffIdSql = $this->effectiveStaffIdSql();
        $effectiveTokoIdSql = $this->effectiveTokoIdSql();
        $effectiveQtySql = $this->effectiveQtySql();
        $effectiveGrossSql = $this->effectiveGrossSql();
        $effectiveNetSql = $this->effectiveNetSql();
        $jenisTransaksiSql = $this->jenisTransaksiSql();
        $incentiveSql = "COALESCE(mtt.tarif_beautician, 0) * ({$effectiveQtySql})";

        return $this->baseTreatmentQuery($filters)
            ->selectRaw("
                {$effectiveDateSql} as tanggal,
                pi.no_invoice,
                {$effectiveTokoIdSql} as toko_id,
                COALESCE(mt_claim.nama_toko, mt_invoice.nama_toko) as toko_nama,
                ps.no_rm,
                ps.nama as pasien_nama,
                {$effectiveStaffIdSql} as staff_id,
                COALESCE(k_claim.nama, k_invoice.nama) as staff_nama,
                COALESCE(j_claim.nama_jabatan, j_invoice.nama_jabatan) as staff_jabatan,
                pii.treatment_id as item_id,
                pii.treatment_toko_id,
                pii.nama_item,
                {$jenisTransaksiSql} as jenis_transaksi,
                {$effectiveQtySql} as qty,
                {$effectiveGrossSql} as harga_awal,
                {$effectiveNetSql} as setelah_diskon,
                COALESCE(mtt.tarif_beautician, 0) as insentif_rupiah,
                {$incentiveSql} as nilai_insentif,
                CASE WHEN pdc.id IS NOT NULL THEN 1 ELSE 0 END as is_realisasi_deposit
            ")
            ->orderByRaw("{$effectiveDateSql} asc")
            ->orderBy('pi.no_invoice')
            ->orderBy('pii.nama_item')
            ->get()
            ->map(function ($row) {
                $jenisTransaksi = (int) ($row->jenis_transaksi ?? 0);

                return [
                    'tanggal' => $row->tanggal
                        ? Carbon::parse($row->tanggal)->format('d/m/Y')
                        : '-',
                    'no_invoice' => $row->no_invoice ?: '-',
                    'toko_id' => $row->toko_id ? (int) $row->toko_id : null,
                    'toko_nama' => $row->toko_nama ?: '-',
                    'no_rm' => $row->no_rm ?: '-',
                    'pasien_nama' => $row->pasien_nama ?: '-',
                    'staff_id' => $row->staff_id ? (int) $row->staff_id : null,
                    'staff_nama' => $row->staff_nama ?: '-',
                    'staff_jabatan' => $row->staff_jabatan ?: '-',
                    'item_id' => $row->item_id ? (int) $row->item_id : null,
                    'treatment_toko_id' => $row->treatment_toko_id
                        ? (int) $row->treatment_toko_id
                        : null,
                    'nama_item' => $row->nama_item ?: '-',
                    'jenis_transaksi' => $jenisTransaksi,
                    'jenis_transaksi_label' => $this->jenisTransaksiLabel($jenisTransaksi),
                    'qty' => (float) ($row->qty ?? 0),
                    'harga_awal' => (float) ($row->harga_awal ?? 0),
                    'setelah_diskon' => (float) ($row->setelah_diskon ?? 0),
                    'insentif_rupiah' => (float) ($row->insentif_rupiah ?? 0),
                    'nilai_insentif' => (float) ($row->nilai_insentif ?? 0),
                    'is_realisasi_deposit' => (int) ($row->is_realisasi_deposit ?? 0),
                ];
            })
            ->values();
    }

    private function baseTreatmentQuery(array $filters)
    {
        $effectiveDateSql = $this->effectiveDateSql();
        $effectiveStaffIdSql = $this->effectiveStaffIdSql();
        $effectiveTokoIdSql = $this->effectiveTokoIdSql();
        $jenisTransaksiSql = $this->jenisTransaksiSql();

        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('pembayaran_deposit_treatment_claim as pdc', function ($join) {
                $join->on('pdc.id', '=', 'pii.deposit_claim_id')
                    ->orOn('pdc.pembayaran_item_id', '=', 'pii.id');
            })
            ->leftJoin('master_treatment_toko as mtt', 'mtt.id', '=', 'pii.treatment_toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_karyawan as k_invoice', 'k_invoice.id', '=', 'pii.perawat_id')
            ->leftJoin('master_jabatan as j_invoice', 'j_invoice.id', '=', 'k_invoice.jabatan_id')
            ->leftJoin('master_karyawan as k_claim', 'k_claim.id', '=', 'pdc.claim_perawat_id')
            ->leftJoin('master_jabatan as j_claim', 'j_claim.id', '=', 'k_claim.jabatan_id')
            ->leftJoin('master_toko as mt_invoice', 'mt_invoice.id', '=', 'pi.toko_id')
            ->leftJoin('master_toko as mt_claim', 'mt_claim.id', '=', 'pdc.toko_claim_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 2)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->where(function ($q) {
                $q->whereNull('pdc.id')
                    ->orWhere(function ($claimQuery) {
                        $claimQuery->where('pdc.status', 1)
                            ->where('pdc.is_delete', 0);
                    });
            })
            ->whereRaw("{$effectiveStaffIdSql} IS NOT NULL")
            ->whereRaw("{$effectiveDateSql} BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            // Pembelian deposit tidak menghasilkan insentif nurse. Yang masuk hanya
            // treatment invoice biasa dan pemakaian/realisasi deposit.
            ->whereRaw("(pdc.id IS NOT NULL OR {$jenisTransaksiSql} <> 4)");

        if (! empty($filters['staff_id'])) {
            $query->whereRaw("{$effectiveStaffIdSql} = ?", [(int) $filters['staff_id']]);
        }

        if (! empty($filters['toko_id'])) {
            $query->whereRaw("{$effectiveTokoIdSql} = ?", [(int) $filters['toko_id']]);
        }

        return $query;
    }

    private function effectiveDateSql(): string
    {
        return 'DATE(CASE WHEN pdc.id IS NOT NULL '
            . 'THEN COALESCE(pdc.claimed_at, pi.tanggal_lunas, pi.tanggal_invoice) '
            . 'ELSE COALESCE(pi.tanggal_lunas, pi.tanggal_invoice) END)';
    }

    private function effectiveStaffIdSql(): string
    {
        return 'COALESCE(pdc.claim_perawat_id, pii.perawat_id)';
    }

    private function effectiveTokoIdSql(): string
    {
        return 'COALESCE(pdc.toko_claim_id, pi.toko_id)';
    }

    private function effectiveQtySql(): string
    {
        return 'CASE WHEN pdc.id IS NOT NULL '
            . 'THEN COALESCE(NULLIF(pdc.qty_claim, 0), pii.qty) '
            . 'ELSE pii.qty END';
    }

    private function effectiveGrossSql(): string
    {
        return 'CASE WHEN pdc.id IS NOT NULL '
            . 'THEN COALESCE(pdc.nilai_realisasi, 0) '
            . 'ELSE (pii.qty * pii.harga) END';
    }

    private function effectiveNetSql(): string
    {
        return 'CASE '
            . 'WHEN pdc.id IS NOT NULL THEN COALESCE(pdc.nilai_realisasi, 0) '
            . 'WHEN pii.subtotal_before_diskon_subtotal <> 0 '
            . '  OR pii.diskon_subtotal_amount <> 0 '
            . '  OR pii.subtotal_after_diskon_subtotal <> 0 '
            . 'THEN pii.subtotal_after_diskon_subtotal '
            . 'ELSE pii.subtotal END';
    }

    private function jenisTransaksiSql(): string
    {
        return 'CASE '
            . 'WHEN pdc.id IS NOT NULL THEN 5 '
            . 'WHEN COALESCE(pii.jenis_transaksi, 0) <> 0 THEN pii.jenis_transaksi '
            . 'ELSE COALESCE(pi.jenis_transaksi, 0) END';
    }

    private function jenisTransaksiLabel(int $jenis): string
    {
        return match ($jenis) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            5 => 'Realisasi Deposit',
            default => 'Lainnya',
        };
    }

    private function title(string $jenis): string
    {
        return 'LAPORAN INSENTIF TREATMENT ('
            . strtoupper($jenis === 'detail' ? 'DETAIL' : 'SUMMARY')
            . ')';
    }

    private function filename(string $jenis, string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xlsx' : 'pdf';

        return implode('-', [
            'laporan',
            'insentif',
            'nurse',
            $jenis,
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.' . $extension;
    }
}
