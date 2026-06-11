<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class LaporanInsentifApotekerController extends Controller
{
    public function petugas(Request $request)
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
                $q->whereIn('j.kode_jabatan', ['AP', 'AA'])
                    ->orWhere('j.nama_jabatan', 'like', '%apoteker%')
                    ->orWhere('j.nama_jabatan', 'like', '%farmasi%');
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
                $jabatan = $item->jabatan ?: 'Apoteker / Asisten Apoteker';

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
            'message' => 'Data apoteker berhasil diambil.',
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
            'message' => 'Ringkasan insentif apoteker berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'produk' => $aggregate,
                'grand_total_insentif' => $aggregate['total_insentif'],
            ],
        ]);
    }

    public function export(Request $request, string $format)
    {
        $format = strtolower($format);

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
        $rows = $this->getRows('detail', $filters);
        $columns = $this->columns();
        $title = 'LAPORAN INSENTIF APOTEKER';
        $filename = $this->filename($format, $filters);
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
        $petugasId = $request->query('apoteker_id', $request->query('petugas_id'));
        $petugasId = is_numeric($petugasId) ? (int) $petugasId : null;

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'apoteker_id' => $petugasId,
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'apoteker_id' => ['nullable', 'integer', 'exists:master_karyawan,id'],
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
        $petugas = null;
        $toko = null;

        if (! empty($filters['apoteker_id'])) {
            $petugas = DB::table('master_karyawan as k')
                ->leftJoin('master_jabatan as j', 'j.id', '=', 'k.jabatan_id')
                ->where('k.id', $filters['apoteker_id'])
                ->first([
                    'k.id',
                    'k.nama',
                    'j.nama_jabatan as jabatan',
                ]);
        }

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'apoteker_id' => $filters['apoteker_id'] ? (int) $filters['apoteker_id'] : null,
            'apoteker_nama' => $petugas->nama ?? null,
            'apoteker_jabatan' => $petugas->jabatan ?? null,
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
        $details = $this->getProdukDetailRows($filters);

        if ($jenis === 'detail') {
            return $details;
        }

        return $details
            ->groupBy(function ($row) {
                return implode('|', [
                    $row['apoteker_id'] ?? 0,
                    $row['item_id'] ?? 0,
                    $row['nama_item'] ?? '-',
                    $row['tarif_insentif'] ?? 0,
                ]);
            })
            ->map(function ($items) {
                $first = $items->first();

                return [
                    'apoteker_nama' => $first['apoteker_nama'] ?? '-',
                    'apoteker_jabatan' => $first['apoteker_jabatan'] ?? '-',
                    'nama_item' => $first['nama_item'] ?? '-',
                    'total_qty' => (float) $items->sum('qty'),
                    'total_omzet' => (float) $items->sum('nilai_net'),
                    'dasar_insentif' => $first['dasar_insentif'] ?? '-',
                    'total_insentif' => (float) $items->sum('nilai_insentif'),
                ];
            })
            ->sortBy([
                ['apoteker_nama', 'asc'],
                ['nama_item', 'asc'],
            ])
            ->values();
    }

    private function getProdukDetailRows(array $filters)
    {
        $netSql = 'COALESCE(NULLIF(pii.subtotal_after_diskon_subtotal, 0), NULLIF(pii.subtotal, 0), (pii.qty * pii.harga))';
        $feeColumn = $this->feeColumnSql();
        $incentiveSql = "COALESCE({$feeColumn}, 0) * pii.qty";
        $tanggalSql = 'COALESCE(far.finished_at, pi.tanggal_lunas, pi.tanggal_invoice)';

        return $this->baseResepQuery($filters)
            ->leftJoin('master_produk_toko as mpt', 'mpt.id', '=', 'pii.produk_toko_id')
            ->selectRaw("
                DATE({$tanggalSql}) as tanggal,
                far.finished_at,
                pi.no_invoice,
                pi.toko_id,
                mt.nama_toko,
                ps.no_rm,
                ps.nama as pasien_nama,
                far.petugas_karyawan_id as apoteker_id,
                COALESCE(kp.nama, far.petugas_nama_snapshot) as apoteker_nama,
                COALESCE(jp.nama_jabatan, far.petugas_jabatan_snapshot) as apoteker_jabatan,
                pii.produk_id as item_id,
                pii.nama_item,
                pii.satuan,
                pii.qty,
                pii.harga,
                {$netSql} as nilai_net,
                COALESCE({$feeColumn}, 0) as tarif_insentif,
                {$incentiveSql} as nilai_insentif
            ")
            ->orderBy('tanggal')
            ->orderBy('pi.no_invoice')
            ->orderBy('kp.nama')
            ->get()
            ->map(function ($row) {
                $tarif = (float) ($row->tarif_insentif ?? 0);

                return [
                    'tanggal' => $row->tanggal,
                    'finished_at' => $row->finished_at,
                    'no_invoice' => $row->no_invoice,
                    'toko_nama' => $row->nama_toko,
                    'no_rm' => $row->no_rm,
                    'pasien_nama' => $row->pasien_nama,
                    'apoteker_id' => (int) $row->apoteker_id,
                    'apoteker_nama' => $row->apoteker_nama,
                    'apoteker_jabatan' => $row->apoteker_jabatan,
                    'item_id' => $row->item_id,
                    'nama_item' => $row->nama_item,
                    'satuan' => $row->satuan,
                    'qty' => (float) $row->qty,
                    'harga' => (float) $row->harga,
                    'nilai_net' => (float) $row->nilai_net,
                    'tarif_insentif' => $tarif,
                    'dasar_insentif' => 'Fee Rp ' . $this->money($tarif) . ' x qty',
                    'nilai_insentif' => (float) $row->nilai_insentif,
                ];
            })
            ->values();
    }

    private function baseResepQuery(array $filters)
    {
        $tanggalSql = 'COALESCE(far.finished_at, pi.tanggal_lunas, pi.tanggal_invoice)';

        $query = DB::table('farmasi_antrian_resep as far')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'far.pembayaran_id')
            ->join('pembayaran_invoice_item as pii', 'pii.pembayaran_id', '=', 'pi.id')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as ps', 'ps.id', '=', 'pi.pasien_id')
            ->leftJoin('master_karyawan as kp', 'kp.id', '=', 'far.petugas_karyawan_id')
            ->leftJoin('master_jabatan as jp', 'jp.id', '=', 'kp.jabatan_id')
            ->where('far.status', 2)
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 3)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereNotNull('far.petugas_karyawan_id')
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['apoteker_id'])) {
            $query->where('far.petugas_karyawan_id', (int) $filters['apoteker_id']);
        }

        if (! empty($filters['toko_id'])) {
            $query->where('pi.toko_id', (int) $filters['toko_id']);
        }

        return $query;
    }

    private function feeColumnSql(): string
    {
        if (Schema::hasColumn('master_produk_toko', 'fee_apoteker')) {
            return 'mpt.fee_apoteker';
        }

        return 'mpt.fee_beautician';
    }

    private function columns(): array
    {
        return [
            ['key' => 'tanggal', 'label' => 'Tanggal Selesai'],
            ['key' => 'no_invoice', 'label' => 'No Invoice'],
            ['key' => 'toko_nama', 'label' => 'Cabang'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'pasien_nama', 'label' => 'Pasien'],
            ['key' => 'apoteker_nama', 'label' => 'Apoteker / Asisten'],
            ['key' => 'apoteker_jabatan', 'label' => 'Jabatan'],
            ['key' => 'nama_item', 'label' => 'Produk / Obat'],
            ['key' => 'qty', 'label' => 'Qty', 'type' => 'number'],
            ['key' => 'satuan', 'label' => 'Satuan'],
            ['key' => 'harga', 'label' => 'Harga', 'type' => 'currency'],
            ['key' => 'nilai_net', 'label' => 'Subtotal Net', 'type' => 'currency'],
            ['key' => 'dasar_insentif', 'label' => 'Dasar Insentif'],
            ['key' => 'nilai_insentif', 'label' => 'Insentif', 'type' => 'currency'],
        ];
    }

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

        return implode('-', [
            'laporan',
            'insentif',
            'apoteker',
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
        $totalInsentif = (float) $rows->sum('nilai_insentif');
        $petugasName = $publicFilters['apoteker_nama']
            ? trim($publicFilters['apoteker_nama'] . ($publicFilters['apoteker_jabatan'] ? ' - ' . $publicFilters['apoteker_jabatan'] : ''))
            : 'Semua apoteker / asisten apoteker';

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
    Apoteker / Asisten: <strong>' . e($petugasName) . '</strong><br>
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
