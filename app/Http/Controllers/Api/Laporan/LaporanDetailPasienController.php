<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanDetailPasienController extends Controller
{
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
        $rows = $this->getRows($filters);

        return response()->json([
            'status' => true,
            'message' => 'Ringkasan data detail pasien berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'total_pasien' => $rows->count(),
                'total_member' => $rows->where('status_member', 'Aktif')->count(),
                'total_no_wa_terisi' => $rows->filter(function ($row) {
                    return trim((string) ($row['no_wa'] ?? '')) !== '';
                })->count(),
                'total_spending' => (float) $rows->sum('total_spending'),
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
        $rows = $this->getRows($filters);
        $columns = $this->columns();
        $title = 'DATA LAPORAN DETAIL PASIEN';
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

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'toko_id' => $tokoId,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
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
        $toko = null;

        if (! empty($filters['toko_id'])) {
            $toko = DB::table('master_toko')->where('id', $filters['toko_id'])->first(['id', 'nama_toko']);
        }

        return [
            'tanggal_awal' => $filters['tanggal_awal'],
            'tanggal_akhir' => $filters['tanggal_akhir'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal daftar pasien',
        ];
    }

    private function getRows(array $filters)
    {
        $invoiceAgg = DB::table('pembayaran_invoice')
            ->selectRaw('
                pasien_id,
                COUNT(DISTINCT id) as total_invoice,
                SUM(COALESCE(grand_total, 0)) as total_spending,
                MAX(DATE(tanggal_lunas)) as tanggal_transaksi_terakhir
            ')
            ->where('status', 3)
            ->where('is_delete', 0)
            ->groupBy('pasien_id');

        $visitAgg = DB::table('registrasi_kunjungan')
            ->selectRaw('
                pasien_id,
                COUNT(DISTINCT id) as total_kunjungan,
                MAX(tanggal_kunjungan) as tanggal_kunjungan_terakhir
            ')
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where('status', '<>', 9)
            ->groupBy('pasien_id');

        $memberSub = DB::table('pasien_member as pm')
            ->selectRaw('pm.*')
            ->join(DB::raw('(
                SELECT pasien_id, MAX(id) AS max_id
                FROM pasien_member
                WHERE is_delete = 0
                GROUP BY pasien_id
            ) as latest_pm'), function ($join) {
                $join->on('latest_pm.max_id', '=', 'pm.id');
            });

        $query = DB::table('pasien as p')
            ->leftJoin('master_toko as mt', 'mt.id', '=', 'p.toko_id')
            ->leftJoin('master_pekerjaan as mp', 'mp.id', '=', 'p.pekerjaan_id')
            ->leftJoin('master_agama as ma', 'ma.id', '=', 'p.agama_id')
            ->leftJoinSub($memberSub, 'pm', function ($join) {
                $join->on('pm.pasien_id', '=', 'p.id');
            })
            ->leftJoin('master_member_tier as mmt', 'mmt.id', '=', 'pm.member_tier_id')
            ->leftJoinSub($invoiceAgg, 'inv', function ($join) {
                $join->on('inv.pasien_id', '=', 'p.id');
            })
            ->leftJoinSub($visitAgg, 'vis', function ($join) {
                $join->on('vis.pasien_id', '=', 'p.id');
            })
            ->where(function ($q) {
                $q->where('p.is_delete', 0)
                    ->orWhereNull('p.is_delete');
            })
            ->whereRaw('DATE(p.created_at) BETWEEN ? AND ?', [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ]);

        if (! empty($filters['toko_id'])) {
            $query->where('p.toko_id', (int) $filters['toko_id']);
        }

        return $query
            ->orderBy('p.created_at')
            ->orderBy('p.nama')
            ->get([
                'p.id',
                'p.created_at',
                'p.no_rm',
                'p.nama',
                'p.tipe_pasien',
                'p.no_identitas',
                'p.jenis_kelamin',
                'p.tempat_lahir',
                'p.tanggal_lahir',
                'p.status_pernikahan',
                'p.no_telp',
                'p.no_hp',
                'p.no_wa',
                'p.email',
                'p.provinsi_kode',
                'p.kota_kode',
                'p.kecamatan_kode',
                'p.kelurahan_kode',
                'p.alamat',
                'p.sumber_info',
                'p.alergi_obat',
                'p.masalah_kulit',
                'p.catatan',
                'mt.nama_toko',
                'mp.nama_pekerjaan',
                'ma.nama_agama',
                'pm.no_member',
                'pm.tanggal_daftar as tanggal_member',
                'pm.tanggal_expired as tanggal_expired_member',
                'pm.status as status_member_raw',
                'mmt.nama as tier_member',
                DB::raw('COALESCE(vis.total_kunjungan, 0) as total_kunjungan'),
                DB::raw('vis.tanggal_kunjungan_terakhir'),
                DB::raw('COALESCE(inv.total_invoice, 0) as total_invoice'),
                DB::raw('COALESCE(inv.total_spending, 0) as total_spending'),
                DB::raw('inv.tanggal_transaksi_terakhir'),
            ])
            ->map(function ($row, $index) {
                $tanggalLahir = $row->tanggal_lahir ? Carbon::parse($row->tanggal_lahir) : null;

                return [
                    'no' => $index + 1,
                    'tanggal_daftar' => $this->dateTime($row->created_at),
                    'no_rm' => $row->no_rm,
                    'nama_pasien' => $row->nama,
                    'tipe_pasien' => ((int) $row->tipe_pasien === 2) ? 'Non Pasien' : 'Pasien',
                    'no_identitas' => $row->no_identitas,
                    'jenis_kelamin' => $this->genderLabel($row->jenis_kelamin),
                    'tempat_lahir' => $row->tempat_lahir,
                    'tanggal_lahir' => $row->tanggal_lahir ? Carbon::parse($row->tanggal_lahir)->format('d/m/Y') : '-',
                    'umur' => $tanggalLahir ? $tanggalLahir->age . ' tahun' : '-',
                    'status_pernikahan' => $this->maritalLabel($row->status_pernikahan),
                    'agama' => $row->nama_agama ?: '-',
                    'pekerjaan' => $row->nama_pekerjaan ?: '-',
                    'no_telp' => $row->no_telp,
                    'no_hp' => $row->no_hp,
                    'no_wa' => $row->no_wa,
                    'email' => $row->email,
                    'cabang_daftar' => $row->nama_toko ?: '-',
                    'no_member' => $row->no_member ?: '-',
                    'tier_member' => $row->tier_member ?: '-',
                    'status_member' => $this->memberStatusLabel($row->status_member_raw),
                    'tanggal_member' => $row->tanggal_member ? Carbon::parse($row->tanggal_member)->format('d/m/Y') : '-',
                    'expired_member' => $row->tanggal_expired_member ? Carbon::parse($row->tanggal_expired_member)->format('d/m/Y') : '-',
                    'sumber_info' => $row->sumber_info ?: '-',
                    'provinsi_kode' => $row->provinsi_kode ?: '-',
                    'kota_kode' => $row->kota_kode ?: '-',
                    'kecamatan_kode' => $row->kecamatan_kode ?: '-',
                    'kelurahan_kode' => $row->kelurahan_kode ?: '-',
                    'alamat' => $row->alamat ?: '-',
                    'alergi_obat' => $row->alergi_obat ?: '-',
                    'masalah_kulit' => $row->masalah_kulit ?: '-',
                    'catatan' => $row->catatan ?: '-',
                    'total_kunjungan' => (int) $row->total_kunjungan,
                    'kunjungan_terakhir' => $row->tanggal_kunjungan_terakhir ? Carbon::parse($row->tanggal_kunjungan_terakhir)->format('d/m/Y') : '-',
                    'total_invoice' => (int) $row->total_invoice,
                    'transaksi_terakhir' => $row->tanggal_transaksi_terakhir ? Carbon::parse($row->tanggal_transaksi_terakhir)->format('d/m/Y') : '-',
                    'total_spending' => (float) $row->total_spending,
                ];
            })
            ->values();
    }

    private function columns(): array
    {
        return [
            ['key' => 'no', 'label' => 'No', 'type' => 'number'],
            ['key' => 'tanggal_daftar', 'label' => 'Tanggal Daftar'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'nama_pasien', 'label' => 'Nama Pasien'],
            ['key' => 'tipe_pasien', 'label' => 'Tipe Pasien'],
            ['key' => 'no_identitas', 'label' => 'No Identitas'],
            ['key' => 'jenis_kelamin', 'label' => 'Jenis Kelamin'],
            ['key' => 'tempat_lahir', 'label' => 'Tempat Lahir'],
            ['key' => 'tanggal_lahir', 'label' => 'Tanggal Lahir'],
            ['key' => 'umur', 'label' => 'Umur'],
            ['key' => 'status_pernikahan', 'label' => 'Status Pernikahan'],
            ['key' => 'agama', 'label' => 'Agama'],
            ['key' => 'pekerjaan', 'label' => 'Pekerjaan'],
            ['key' => 'no_telp', 'label' => 'No Telepon'],
            ['key' => 'no_hp', 'label' => 'No HP'],
            ['key' => 'no_wa', 'label' => 'No WA'],
            ['key' => 'email', 'label' => 'Email'],
            ['key' => 'cabang_daftar', 'label' => 'Cabang Daftar'],
            ['key' => 'no_member', 'label' => 'No Member'],
            ['key' => 'tier_member', 'label' => 'Tier Member'],
            ['key' => 'status_member', 'label' => 'Status Member'],
            ['key' => 'tanggal_member', 'label' => 'Tanggal Member'],
            ['key' => 'expired_member', 'label' => 'Expired Member'],
            ['key' => 'sumber_info', 'label' => 'Sumber Info'],
            ['key' => 'provinsi_kode', 'label' => 'Kode Provinsi'],
            ['key' => 'kota_kode', 'label' => 'Kode Kota'],
            ['key' => 'kecamatan_kode', 'label' => 'Kode Kecamatan'],
            ['key' => 'kelurahan_kode', 'label' => 'Kode Kelurahan'],
            ['key' => 'alamat', 'label' => 'Alamat'],
            ['key' => 'alergi_obat', 'label' => 'Alergi Obat'],
            ['key' => 'masalah_kulit', 'label' => 'Masalah Kulit'],
            ['key' => 'catatan', 'label' => 'Catatan'],
            ['key' => 'total_kunjungan', 'label' => 'Total Kunjungan', 'type' => 'number'],
            ['key' => 'kunjungan_terakhir', 'label' => 'Kunjungan Terakhir'],
            ['key' => 'total_invoice', 'label' => 'Total Invoice', 'type' => 'number'],
            ['key' => 'transaksi_terakhir', 'label' => 'Transaksi Terakhir'],
            ['key' => 'total_spending', 'label' => 'Total Spending', 'type' => 'currency'],
        ];
    }

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

        return implode('-', [
            'data',
            'laporan',
            'detail',
            'pasien',
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
        $totalSpending = (float) $rows->sum('total_spending');

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
            $tbody = '<tr><td colspan="' . count($columns) . '" class="empty">Tidak ada data pasien pada filter ini.</td></tr>';
        }

        $autoPrint = $printable ? '<script>window.addEventListener("load", function () { window.print(); });</script>' : '';

        return '<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>' . e($title) . '</title>
<style>
    body { font-family: Arial, Helvetica, sans-serif; color: #111827; font-size: 11px; margin: 18px; }
    h1 { font-size: 18px; margin: 0 0 10px; }
    .meta { margin-bottom: 14px; color: #374151; line-height: 1.7; }
    .summary { display: flex; gap: 18px; margin: 12px 0 16px; font-size: 12px; font-weight: 700; }
    .table-wrap { width: 100%; overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f3f4f6; border: 1px solid #d1d5db; padding: 6px; text-align: left; white-space: nowrap; }
    td { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .empty { text-align: center; color: #6b7280; padding: 20px; }
    @media print {
        body { margin: 10mm; }
        @page { size: landscape; }
    }
</style>
</head>
<body>
<h1>' . e($title) . '</h1>
<div class="meta">
    Periode: <strong>' . e($period) . '</strong><br>
    Berdasarkan: <strong>' . e($publicFilters['tanggal_berdasarkan']) . '</strong><br>
    Cabang: <strong>' . e($publicFilters['toko_nama'] ?: 'Semua cabang / sesuai akses') . '</strong>
</div>
<div class="summary">
    <span>Total Pasien: ' . e($this->number((float) $rows->count())) . '</span>
    <span>Total Spending: Rp ' . e($this->money($totalSpending)) . '</span>
</div>
<div class="table-wrap">
<table>
<thead><tr>' . $thead . '</tr></thead>
<tbody>' . $tbody . '</tbody>
</table>
</div>
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

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : '-';
    }

    private function dateTime($value): string
    {
        if (! $value) {
            return '-';
        }

        return Carbon::parse($value)->format('d/m/Y H:i');
    }

    private function genderLabel($value): string
    {
        return match (strtoupper((string) $value)) {
            'L' => 'Laki-laki',
            'P' => 'Perempuan',
            default => '-',
        };
    }

    private function maritalLabel($value): string
    {
        return match ((int) $value) {
            1 => 'Belum Menikah',
            2 => 'Menikah',
            3 => 'Cerai',
            default => '-',
        };
    }

    private function memberStatusLabel($value): string
    {
        return match ((int) $value) {
            1 => 'Aktif',
            2 => 'Expired',
            3 => 'Suspend',
            9 => 'Batal',
            default => '-',
        };
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
