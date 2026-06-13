<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use App\Services\Laporan\LaporanInsentifDokterExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanInsentifDokterController extends Controller
{
    private const PPN_RATE = 11;

    public function __construct(
        private readonly LaporanInsentifDokterExportService $exportService
    ) {
    }

    public function dokter(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $limit = (int) $request->query('limit', 30);
        $limit = $limit > 0 ? min($limit, 100) : 30;

        $query = DB::table('master_karyawan as k')
            ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
            ->where(function ($q) {
                $q->where('k.is_delete', 0)
                    ->orWhereNull('k.is_delete');
            })
            ->where(function ($q) {
                $q->where('j.kode_jabatan', 'like', '%DOK%')
                    ->orWhere('j.nama_jabatan', 'like', '%dokter%')
                    ->orWhereNotNull('k.no_sip_dok');
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('k.nama', 'like', "%{$search}%")
                    ->orWhere('k.kode', 'like', "%{$search}%")
                    ->orWhere('j.nama_jabatan', 'like', "%{$search}%");
            });
        }

        $items = $query
            ->orderBy('k.nama')
            ->limit($limit)
            ->get([
                'k.id',
                'k.kode',
                'k.nama',
                'k.no_sip_dok',
                'k.is_dokter_spesialis',
                'j.nama_jabatan as jabatan',
            ])
            ->map(function ($item) {
                return [
                    'id' => (int) $item->id,
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'jabatan' => $item->jabatan,
                    'no_sip_dok' => $item->no_sip_dok,
                    'is_dokter_spesialis' => (int) ($item->is_dokter_spesialis ?? 0),
                    'label' => trim(($item->nama ?? '-') . ($item->jabatan ? ' - ' . $item->jabatan : '')),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data dokter berhasil diambil.',
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
        $treatmentRows = $this->getRows('treatment', 'summary', $filters);
        $produkRows = $this->getRows('produk', 'summary', $filters);
        $treatment = $this->makeSummaryAggregate($treatmentRows);
        $produk = $this->makeSummaryAggregate($produkRows);

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan insentif dokter berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'treatment' => $treatment,
                'produk' => $produk,
                'grand_total_insentif' => $treatment['total_insentif'] + $produk['total_insentif'],
            ],
        ]);
    }

    public function export(Request $request, string $kategori, string $jenis, string $format)
    {
        $kategori = strtolower($kategori);
        $jenis = strtolower($jenis);
        $format = strtolower($format);

        if (! in_array($kategori, ['treatment', 'produk'], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Kategori laporan harus treatment atau produk.',
            ], 422);
        }

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
        $rows = $this->getRows($kategori, $jenis, $filters);
        $publicFilters = $this->publicFilters($filters);
        $filename = $this->filename($kategori, $jenis, $format, $filters);
        $title = $this->title($kategori, $jenis);
        $totalInsentif = (float) $rows->sum(function ($row) {
            return $row['total_insentif'] ?? $row['nilai_insentif'] ?? 0;
        });

        $payload = [
            'title' => $title,
            'kategori' => $kategori,
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
            'ppnRate' => self::PPN_RATE,
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

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'dokter_id' => $request->query('dokter_id'),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'dokter_id' => ['required', 'integer', 'exists:master_karyawan,id'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
        ], [
            'dokter_id.required' => 'Dokter wajib dipilih.',
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
        ]);

        return [
            'validator' => $validator,
            'data' => $data,
        ];
    }

    private function publicFilters(array $filters): array
    {
        $doctor = DB::table('master_karyawan')
            ->where('id', $filters['dokter_id'])
            ->first(['id', 'nama', 'is_dokter_spesialis']);

        $toko = null;
        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')
                ->where('id', $filters['toko_id'])
                ->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'dokter_id' => (int) $filters['dokter_id'],
            'dokter_nama' => $doctor->nama ?? '-',
            'is_dokter_spesialis' => (int) ($doctor->is_dokter_spesialis ?? 0),
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

    private function getRows(string $kategori, string $jenis, array $filters)
    {
        $details = $kategori === 'treatment'
            ? $this->getTreatmentDetailRows($filters)
            : $this->getProdukDetailRows($filters);

        if ($jenis === 'detail') {
            return $details;
        }

        return $details
            ->groupBy(function ($row) {
                return implode('|', [
                    $row['dokter_id'] ?? 0,
                    $row['item_id'] ?? 0,
                    $row['nama_item'] ?? '-',
                    $row['skema_insentif'] ?? 'flat',
                    $row['insentif_persen'] ?? 0,
                    $row['insentif_rupiah'] ?? 0,
                ]);
            })
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'dokter_nama' => $first['dokter_nama'] ?? '-',
                    'nama_item' => $first['nama_item'] ?? '-',
                    'total_qty' => (float) $items->sum('qty'),
                    'harga_awal' => (float) $items->sum('harga_awal'),
                    'setelah_diskon' => (float) $items->sum('setelah_diskon'),
                    'ppn_11' => (float) $items->sum('ppn_11'),
                    'dasar_fee' => (float) $items->sum('dasar_fee'),
                    'insentif_persen' => (float) ($first['insentif_persen'] ?? 0),
                    'insentif_rupiah' => (float) ($first['insentif_rupiah'] ?? 0),
                    'skema_insentif' => $first['skema_insentif'] ?? 'flat',
                    'total_omzet' => (float) $items->sum('setelah_diskon'),
                    'dasar_insentif' => $first['dasar_insentif'] ?? '-',
                    'total_insentif' => (float) $items->sum('nilai_insentif'),
                ];
            })
            ->sortBy('nama_item')
            ->values();
    }

    private function getTreatmentDetailRows(array $filters)
    {
        $netSql = $this->netItemSql();
        $jenisTransaksiSql = $this->jenisTransaksiSql();

        return $this->baseItemQuery($filters)
            ->where('pii.item_type', 2)
            ->leftJoin('master_treatment_toko as mtt', 'mtt.id', '=', 'pii.treatment_toko_id')
            ->selectRaw("
                DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal,
                pi.no_invoice,
                pi.toko_id,
                mt.nama_toko,
                ps.no_rm,
                ps.nama as pasien_nama,
                COALESCE(pii.dokter_id, pi.dokter_id) as dokter_id,
                kd.nama as dokter_nama,
                COALESCE(kd.is_dokter_spesialis, 0) as is_dokter_spesialis,
                {$jenisTransaksiSql} as jenis_transaksi,
                pii.treatment_id as item_id,
                pii.nama_item,
                pii.qty,
                pii.harga,
                (pii.qty * pii.harga) as harga_awal,
                {$netSql} as nilai_net,
                mtt.insentif_use,
                mtt.insentif_use_sp,
                mtt.presentase_tarif_dokter,
                mtt.presentase_tarif_dokter_sp,
                mtt.flat_tarif_dokter,
                mtt.flat_tarif_dokter_sp,
                mtt.tarif_dokter
            ")
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->get()
            ->map(function ($row) {
                $isSp = (int) ($row->is_dokter_spesialis ?? 0) === 1;
                $configuredMode = $this->normalizeInsentifMode(
                    $isSp ? $row->insentif_use_sp : $row->insentif_use
                );
                $jenisTransaksi = (int) ($row->jenis_transaksi ?? 0);
                $isRegularPercentage = $jenisTransaksi === 0 && $configuredMode === 'percent';

                $rate = $isSp
                    ? (float) ($row->presentase_tarif_dokter_sp ?? 0)
                    : (float) ($row->presentase_tarif_dokter ?? 0);

                $flat = $isSp
                    ? (float) ($row->flat_tarif_dokter_sp ?? 0)
                    : (float) ($row->flat_tarif_dokter ?? 0);

                $fallbackFlat = (float) ($row->tarif_dokter ?? 0);
                $flat = $flat > 0 ? $flat : $fallbackFlat;

                $qty = (float) $row->qty;
                $setelahDiskon = max((float) $row->nilai_net, 0);
                $ppn = $isRegularPercentage
                    ? $this->extractInclusiveTax($setelahDiskon, self::PPN_RATE)
                    : 0.0;
                $dasarFee = $isRegularPercentage
                    ? max($setelahDiskon - $ppn, 0)
                    : 0.0;
                $nilaiInsentif = $isRegularPercentage
                    ? $dasarFee * $rate / 100
                    : $flat * $qty;

                return [
                    'tanggal' => $row->tanggal,
                    'no_invoice' => $row->no_invoice,
                    'toko_nama' => $row->nama_toko,
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'dokter_id' => (int) $row->dokter_id,
                    'dokter_nama' => $row->dokter_nama,
                    'jenis_transaksi' => $jenisTransaksi,
                    'jenis_transaksi_label' => $this->jenisTransaksiLabel($jenisTransaksi),
                    'item_id' => $row->item_id,
                    'nama_item' => $row->nama_item,
                    'qty' => $qty,
                    'harga' => (float) $row->harga,
                    'harga_awal' => (float) $row->harga_awal,
                    'nilai_net' => $setelahDiskon,
                    'setelah_diskon' => $setelahDiskon,
                    'ppn_11' => $ppn,
                    'dasar_fee' => $dasarFee,
                    'insentif_persen' => $isRegularPercentage ? $rate : 0.0,
                    'insentif_rupiah' => $isRegularPercentage ? 0.0 : $flat,
                    'skema_insentif' => $isRegularPercentage ? 'percent' : 'flat',
                    'dasar_insentif' => $isRegularPercentage
                        ? $this->number($rate) . '% dari dasar fee'
                        : 'Flat Rp ' . $this->money($flat) . ' x qty',
                    'nilai_insentif' => $nilaiInsentif,
                ];
            })
            ->values();
    }

    private function getProdukDetailRows(array $filters)
    {
        $netSql = $this->netItemSql();
        $jenisTransaksiSql = $this->jenisTransaksiSql();

        return $this->baseItemQuery($filters)
            ->where('pii.item_type', 3)
            ->where(function ($q) {
                $q->where('pii.is_saran_dokter', 1)
                    ->orWhereNotNull('pii.dokter_id');
            })
            ->leftJoin('master_produk_toko as mpt', 'mpt.id', '=', 'pii.produk_toko_id')
            ->selectRaw("
                DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) as tanggal,
                pi.no_invoice,
                pi.toko_id,
                mt.nama_toko,
                ps.no_rm,
                ps.nama as pasien_nama,
                COALESCE(pii.dokter_id, pi.dokter_id) as dokter_id,
                kd.nama as dokter_nama,
                {$jenisTransaksiSql} as jenis_transaksi,
                pii.produk_id as item_id,
                pii.nama_item,
                pii.qty,
                pii.harga,
                (pii.qty * pii.harga) as harga_awal,
                {$netSql} as nilai_net,
                COALESCE(mpt.fee_dokter, 0) as fee_dokter
            ")
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->get()
            ->map(function ($row) {
                $qty = (float) $row->qty;
                $feeDokter = (float) ($row->fee_dokter ?? 0);
                $setelahDiskon = max((float) $row->nilai_net, 0);
                $jenisTransaksi = (int) ($row->jenis_transaksi ?? 0);

                return [
                    'tanggal' => $row->tanggal,
                    'no_invoice' => $row->no_invoice,
                    'toko_nama' => $row->nama_toko,
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'dokter_id' => (int) $row->dokter_id,
                    'dokter_nama' => $row->dokter_nama,
                    'jenis_transaksi' => $jenisTransaksi,
                    'jenis_transaksi_label' => $this->jenisTransaksiLabel($jenisTransaksi),
                    'item_id' => $row->item_id,
                    'nama_item' => $row->nama_item,
                    'qty' => $qty,
                    'harga' => (float) $row->harga,
                    'harga_awal' => (float) $row->harga_awal,
                    'nilai_net' => $setelahDiskon,
                    'setelah_diskon' => $setelahDiskon,
                    'ppn_11' => 0.0,
                    'dasar_fee' => 0.0,
                    'insentif_persen' => 0.0,
                    'insentif_rupiah' => $feeDokter,
                    'skema_insentif' => 'flat',
                    'dasar_insentif' => 'Fee dokter Rp ' . $this->money($feeDokter) . ' x qty',
                    'nilai_insentif' => $feeDokter * $qty,
                ];
            })
            ->values();
    }

    private function baseItemQuery(array $filters)
    {
        $query = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_karyawan as kd', function ($join) {
                $join->on('kd.id', '=', DB::raw('COALESCE(pii.dokter_id, pi.dokter_id)'));
            })
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereRaw('DATE(COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)) BETWEEN ? AND ?', [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->whereRaw('COALESCE(pii.dokter_id, pi.dokter_id) = ?', [(int) $filters['dokter_id']]);

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        return $query;
    }

    private function netItemSql(): string
    {
        return "
            CASE
                WHEN COALESCE(pii.subtotal_before_diskon_subtotal, 0) <> 0
                    OR COALESCE(pii.diskon_subtotal_amount, 0) <> 0
                THEN GREATEST(
                    COALESCE(pii.subtotal_before_diskon_subtotal, 0)
                    - COALESCE(pii.diskon_subtotal_amount, 0),
                    0
                )
                WHEN COALESCE(pii.subtotal, 0) <> 0
                THEN GREATEST(pii.subtotal, 0)
                ELSE GREATEST(
                    (pii.qty * pii.harga)
                    - COALESCE(pii.diskon_amount, 0)
                    - COALESCE(pii.diskon_referral, 0),
                    0
                )
            END
        ";
    }

    private function jenisTransaksiSql(): string
    {
        return "
            CASE
                WHEN COALESCE(pii.jenis_transaksi, 0) <> 0
                THEN pii.jenis_transaksi
                ELSE COALESCE(pi.jenis_transaksi, 0)
            END
        ";
    }

    private function normalizeInsentifMode($mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['percent', 'persen', 'percentage'], true) ? 'percent' : 'flat';
    }

    private function extractInclusiveTax(float $amount, float $rate): float
    {
        if ($amount <= 0 || $rate <= 0) {
            return 0.0;
        }

        return $amount * $rate / (100 + $rate);
    }

    private function jenisTransaksiLabel(int $jenisTransaksi): string
    {
        return match ($jenisTransaksi) {
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Umum',
        };
    }

    private function title(string $kategori, string $jenis): string
    {
        $item = $kategori === 'treatment' ? 'TREATMENT' : 'PRODUK';

        return 'LAPORAN INSENTIF ' . $item . ' (' . strtoupper($jenis) . ')';
    }

    private function filename(string $kategori, string $jenis, string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xlsx' : 'pdf';

        return implode('-', [
            'laporan',
            'insentif',
            'dokter',
            $kategori,
            $jenis,
            Carbon::parse($filters['tanggal_awal'])->format('Ymd'),
            Carbon::parse($filters['tanggal_akhir'])->format('Ymd'),
        ]) . '.' . $extension;
    }

    private function money(float $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function number(float $value): string
    {
        $decimals = floor($value) == $value ? 0 : 2;

        return number_format($value, $decimals, ',', '.');
    }
}
