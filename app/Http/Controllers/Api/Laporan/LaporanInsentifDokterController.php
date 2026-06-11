<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class LaporanInsentifDokterController extends Controller
{
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
        $columns = $this->columns($kategori, $jenis);
        $title = $this->title($kategori, $jenis);
        $filename = $this->filename($kategori, $jenis, $format, $filters);

        $html = $this->buildHtml($title, $columns, $rows, $filters, $format === 'pdf');

        if ($format === 'excel') {
            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
                'Pragma' => 'no-cache',
            ]);
        }

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
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
        $doctor = DB::table('master_karyawan')->where('id', $filters['dokter_id'])->first(['id', 'nama']);
        $toko = null;

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'dokter_id' => (int) $filters['dokter_id'],
            'dokter_nama' => $doctor->nama ?? '-',
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
                    $row['dasar_insentif'] ?? '-',
                ]);
            })
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'dokter_nama' => $first['dokter_nama'] ?? '-',
                    'nama_item' => $first['nama_item'] ?? '-',
                    'total_qty' => (float) $items->sum('qty'),
                    'total_omzet' => (float) $items->sum('nilai_net'),
                    'dasar_insentif' => $first['dasar_insentif'] ?? '-',
                    'total_insentif' => (float) $items->sum('nilai_insentif'),
                ];
            })
            ->sortBy('nama_item')
            ->values();
    }

    private function getTreatmentDetailRows(array $filters)
    {
        $netSql = 'COALESCE(NULLIF(pii.subtotal_after_diskon_subtotal, 0), NULLIF(pii.subtotal, 0), (pii.qty * pii.harga))';
        $incentiveSql = "
            CASE
                WHEN COALESCE(kd.is_dokter_spesialis, 0) = 1
                    AND LOWER(COALESCE(mtt.insentif_use_sp, '')) IN ('percent', 'persen', 'percentage')
                    THEN ({$netSql}) * COALESCE(mtt.presentase_tarif_dokter_sp, 0) / 100
                WHEN COALESCE(kd.is_dokter_spesialis, 0) = 1
                    THEN COALESCE(NULLIF(mtt.flat_tarif_dokter_sp, 0), NULLIF(mtt.tarif_dokter, 0), 0) * pii.qty
                WHEN LOWER(COALESCE(mtt.insentif_use, '')) IN ('percent', 'persen', 'percentage')
                    THEN ({$netSql}) * COALESCE(mtt.presentase_tarif_dokter, 0) / 100
                ELSE COALESCE(NULLIF(mtt.flat_tarif_dokter, 0), NULLIF(mtt.tarif_dokter, 0), 0) * pii.qty
            END
        ";

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
                pii.treatment_id as item_id,
                pii.nama_item,
                pii.qty,
                pii.harga,
                {$netSql} as nilai_net,
                mtt.insentif_use,
                mtt.insentif_use_sp,
                mtt.presentase_tarif_dokter,
                mtt.presentase_tarif_dokter_sp,
                mtt.flat_tarif_dokter,
                mtt.flat_tarif_dokter_sp,
                mtt.tarif_dokter,
                {$incentiveSql} as nilai_insentif
            ")
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->get()
            ->map(function ($row) {
                $isSp = (int) ($row->is_dokter_spesialis ?? 0) === 1;
                $mode = $this->normalizeInsentifMode($isSp ? $row->insentif_use_sp : $row->insentif_use);
                $rate = $isSp
                    ? (float) ($row->presentase_tarif_dokter_sp ?? 0)
                    : (float) ($row->presentase_tarif_dokter ?? 0);
                $flat = $isSp
                    ? (float) ($row->flat_tarif_dokter_sp ?? 0)
                    : (float) ($row->flat_tarif_dokter ?? 0);
                $fallbackFlat = (float) ($row->tarif_dokter ?? 0);
                $flat = $flat > 0 ? $flat : $fallbackFlat;

                return [
                    'tanggal' => $row->tanggal,
                    'no_invoice' => $row->no_invoice,
                    'toko_nama' => $row->nama_toko,
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'dokter_id' => (int) $row->dokter_id,
                    'dokter_nama' => $row->dokter_nama,
                    'item_id' => $row->item_id,
                    'nama_item' => $row->nama_item,
                    'qty' => (float) $row->qty,
                    'harga' => (float) $row->harga,
                    'nilai_net' => (float) $row->nilai_net,
                    'dasar_insentif' => $mode === 'percent'
                        ? $this->number($rate) . '% dari omzet net'
                        : 'Flat Rp ' . $this->money($flat) . ' x qty',
                    'nilai_insentif' => (float) $row->nilai_insentif,
                ];
            })
            ->values();
    }

    private function getProdukDetailRows(array $filters)
    {
        $netSql = 'COALESCE(NULLIF(pii.subtotal_after_diskon_subtotal, 0), NULLIF(pii.subtotal, 0), (pii.qty * pii.harga))';
        $incentiveSql = 'COALESCE(mpt.fee_dokter, 0) * pii.qty';

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
                pii.produk_id as item_id,
                pii.nama_item,
                pii.qty,
                pii.harga,
                {$netSql} as nilai_net,
                COALESCE(mpt.fee_dokter, 0) as fee_dokter,
                {$incentiveSql} as nilai_insentif
            ")
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->get()
            ->map(function ($row) {
                return [
                    'tanggal' => $row->tanggal,
                    'no_invoice' => $row->no_invoice,
                    'toko_nama' => $row->nama_toko,
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'dokter_id' => (int) $row->dokter_id,
                    'dokter_nama' => $row->dokter_nama,
                    'item_id' => $row->item_id,
                    'nama_item' => $row->nama_item,
                    'qty' => (float) $row->qty,
                    'harga' => (float) $row->harga,
                    'nilai_net' => (float) $row->nilai_net,
                    'dasar_insentif' => 'Fee dokter Rp ' . $this->money((float) $row->fee_dokter) . ' x qty',
                    'nilai_insentif' => (float) $row->nilai_insentif,
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

    private function normalizeInsentifMode($mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['percent', 'persen', 'percentage'], true) ? 'percent' : 'flat';
    }

    private function columns(string $kategori, string $jenis): array
    {
        if ($jenis === 'summary') {
            return [
                ['key' => 'dokter_nama', 'label' => 'Dokter'],
                ['key' => 'nama_item', 'label' => $kategori === 'treatment' ? 'Treatment' : 'Produk'],
                ['key' => 'total_qty', 'label' => 'Total Qty', 'type' => 'number'],
                ['key' => 'total_omzet', 'label' => 'Total Omzet Net', 'type' => 'currency'],
                ['key' => 'dasar_insentif', 'label' => 'Dasar Insentif'],
                ['key' => 'total_insentif', 'label' => 'Total Insentif', 'type' => 'currency'],
            ];
        }

        return [
            ['key' => 'tanggal', 'label' => 'Tanggal'],
            ['key' => 'no_invoice', 'label' => 'No Invoice'],
            ['key' => 'toko_nama', 'label' => 'Cabang'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'pasien_nama', 'label' => 'Pasien'],
            ['key' => 'dokter_nama', 'label' => 'Dokter'],
            ['key' => 'nama_item', 'label' => $kategori === 'treatment' ? 'Treatment' : 'Produk'],
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'number'],
            ['key' => 'harga', 'label' => 'Harga', 'type' => 'currency'],
            ['key' => 'nilai_net', 'label' => 'Subtotal Net', 'type' => 'currency'],
            ['key' => 'dasar_insentif', 'label' => 'Dasar Insentif'],
            ['key' => 'nilai_insentif', 'label' => 'Insentif', 'type' => 'currency'],
        ];
    }

    private function title(string $kategori, string $jenis): string
    {
        $prefix = $jenis === 'detail' ? '[DETAIL] ' : '';
        $item = $kategori === 'treatment' ? 'TREATMENT' : 'PRODUK';

        return $prefix . 'LAPORAN INSENTIF ' . $item;
    }

    private function filename(string $kategori, string $jenis, string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

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

    private function buildHtml(string $title, array $columns, $rows, array $filters, bool $printable): string
    {
        $publicFilters = $this->publicFilters($filters);
        $period = Carbon::parse($filters['tanggal_awal'])->format('d/m/Y')
            . ' - '
            . Carbon::parse($filters['tanggal_akhir'])->format('d/m/Y');
        $totalInsentif = (float) $rows->sum(function ($row) {
            return $row['total_insentif'] ?? $row['nilai_insentif'] ?? 0;
        });

        $thead = collect($columns)->map(function ($column) {
            return '<th>' . e($column['label']) . '</th>';
        })->implode('');

        $tbody = $rows->map(function ($row) use ($columns) {
            $cells = collect($columns)->map(function ($column) use ($row) {
                $type = $column['type'] ?? 'text';
                $value = $row[$column['key']] ?? null;
                $class = in_array($type, ['number', 'currency'], true) ? ' class="num"' : '';

                return '<td' . $class . '>' . e($this->formatValue($value, $type)) . '</td>';
            })->implode('');

            return '<tr>' . $cells . '</tr>';
        })->implode('');

        if ($tbody === '') {
            $tbody = '<tr><td colspan="' . count($columns) . '" class="empty">Tidak ada data pada filter ini.</td></tr>';
        }

        $autoPrint = $printable ? '<script>window.addEventListener("load", function () { window.print(); });</script>' : '';

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>' . e($title) . '</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 12px; margin: 24px; }
    h1 { font-size: 18px; margin: 0 0 10px; }
    .meta { margin-bottom: 16px; color: #374151; line-height: 1.7; }
    .summary { margin: 12px 0 16px; font-size: 13px; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 7px; text-align: left; }
    td { border: 1px solid #d1d5db; padding: 7px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .empty { text-align: center; color: #6b7280; padding: 20px; }
    @media print { body { margin: 12mm; } }
</style>
</head>
<body>
<h1>' . e($title) . '</h1>
<div class="meta">
    Periode: <strong>' . e($period) . '</strong><br>
    Dokter: <strong>' . e($publicFilters['dokter_nama']) . '</strong><br>
    Cabang: <strong>' . e($publicFilters['toko_nama'] ?: 'Semua cabang / sesuai akses') . '</strong>
</div>
<div class="summary">Total Insentif: Rp ' . e($this->money($totalInsentif)) . '</div>
<table>
<thead><tr>' . $thead . '</tr></thead>
<tbody>' . $tbody . '</tbody>
</table>
' . $autoPrint . '
</body>
</html>';
    }

    private function formatValue($value, string $type): string
    {
        if ($type === 'currency') {
            return 'Rp ' . $this->money((float) $value);
        }

        if ($type === 'number') {
            return $this->number((float) $value);
        }

        return (string) ($value ?? '-');
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
