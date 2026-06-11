<?php

namespace App\Http\Controllers\Api\Laporan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LaporanPasienTreatmentTerbanyakController extends Controller
{
    private const ALLOWED_JENIS_TRANSAKSI = [0, 1, 2, 3, 4];

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
        $jenisOptions = $this->getJenisTransaksiOptions();

        return response()->json([
            'status' => true,
            'message' => 'Data pasien treatment terbanyak berhasil diambil.',
            'data' => [
                'filters' => $this->publicFilters($filters),
                'jenis_transaksi_options' => $jenisOptions->values(),
                'total_pasien' => $rows->count(),
                'total_invoice' => (int) $rows->sum('total_invoice'),
                'total_hari_transaksi' => (int) $rows->sum('total_hari_transaksi'),
                'total_qty_treatment' => (float) $rows->sum('total_qty_treatment'),
                'total_nilai_treatment' => (float) $rows->sum('total_nilai_treatment'),
                'rata_qty_per_pasien' => $rows->count() > 0
                    ? round((float) $rows->sum('total_qty_treatment') / $rows->count(), 2)
                    : 0,
                'rows' => $rows->values(),
                'top_pasien' => $rows->first(),
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
        $title = 'DATA PASIEN TREATMENT TERBANYAK';
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

        $peringkat = $request->query('peringkat', $request->query('limit'));
        if ($peringkat === null || $peringkat === '') {
            $peringkat = 10;
        } elseif (is_numeric($peringkat)) {
            $peringkat = (int) $peringkat;
        }

        $jenisTransaksi = $request->query('jenis_transaksi');
        if ($jenisTransaksi === null || $jenisTransaksi === '' || $jenisTransaksi === 'all') {
            $jenisTransaksi = null;
        } elseif (is_numeric($jenisTransaksi)) {
            $jenisTransaksi = (int) $jenisTransaksi;
        }

        $data = [
            'tanggal_awal' => $request->query('tanggal_awal', $today),
            'tanggal_akhir' => $request->query('tanggal_akhir', $request->query('tanggal_awal', $today)),
            'peringkat' => $peringkat,
            'toko_id' => $tokoId,
            'jenis_transaksi' => $jenisTransaksi,
        ];

        $validator = Validator::make($data, [
            'tanggal_awal' => ['required', 'date_format:Y-m-d'],
            'tanggal_akhir' => ['required', 'date_format:Y-m-d', 'after_or_equal:tanggal_awal'],
            'peringkat' => ['required', 'integer', 'min:1', 'max:1000'],
            'toko_id' => ['nullable', 'integer', 'exists:master_toko,id'],
            'jenis_transaksi' => ['nullable', 'integer', 'in:0,1,2,3,4'],
        ], [
            'tanggal_akhir.after_or_equal' => 'Tanggal akhir tidak boleh lebih kecil dari tanggal awal.',
            'peringkat.min' => 'Peringkat minimal 1.',
            'peringkat.max' => 'Peringkat maksimal 1000 agar laporan tetap ringan.',
            'jenis_transaksi.in' => 'Jenis transaksi harus 0, 1, 2, 3, atau 4.',
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
            'peringkat' => (int) $filters['peringkat'],
            'toko_id' => $filters['toko_id'],
            'toko_nama' => $toko->nama_toko ?? null,
            'tanggal_berdasarkan' => 'Tanggal lunas invoice',
            'jenis_transaksi' => $filters['jenis_transaksi'],
            'jenis_transaksi_label' => $this->jenisTransaksiLabel($filters['jenis_transaksi']),
        ];
    }

    private function getRows(array $filters)
    {
        $tanggalSql = 'COALESCE(pi.tanggal_lunas, pi.tanggal_invoice)';

        $details = DB::table('pembayaran_invoice_item as pii')
            ->join('pembayaran_invoice as pi', 'pi.id', '=', 'pii.pembayaran_id')
            ->leftJoin('master_toko as toko', 'toko.id', '=', 'pi.toko_id')
            ->leftJoin('pasien as pasien', 'pasien.id', '=', 'pi.pasien_id')
            ->leftJoin('master_treatment as treatment', 'treatment.id', '=', 'pii.treatment_id')
            ->leftJoin('master_jenis_transaksi as jt', 'jt.id', '=', 'pii.jenis_transaksi')
            ->leftJoin('master_karyawan as dokter', function ($join) {
                $join->on('dokter.id', '=', DB::raw('COALESCE(pii.dokter_id, pi.dokter_id, pi.referensi_dokter_id)'));
            })
            ->leftJoin('master_karyawan as perawat', 'perawat.id', '=', 'pii.perawat_id')
            ->where('pi.status', 3)
            ->where('pi.is_delete', 0)
            ->where('pii.item_type', 2)
            ->where('pii.status', 1)
            ->where('pii.is_delete', 0)
            ->whereIn('pii.jenis_transaksi', self::ALLOWED_JENIS_TRANSAKSI)
            ->whereRaw("DATE({$tanggalSql}) BETWEEN ? AND ?", [
                $filters['tanggal_awal'],
                $filters['tanggal_akhir'],
            ])
            ->when(! empty($filters['toko_id']), function ($query) use ($filters) {
                $query->where('pi.toko_id', (int) $filters['toko_id']);
            })
            ->when($filters['jenis_transaksi'] !== null, function ($query) use ($filters) {
                $query->where('pii.jenis_transaksi', (int) $filters['jenis_transaksi']);
            })
            ->selectRaw("\n                pi.id as pembayaran_id,\n                pi.no_invoice,\n                pi.kode_registrasi,\n                pi.toko_id,\n                toko.nama_toko,\n                pi.pasien_id,\n                pasien.no_rm,\n                pasien.nama as nama_pasien,\n                pasien.no_hp,\n                pasien.no_wa,\n                DATE({$tanggalSql}) as tanggal_transaksi,\n                pii.id as item_id,\n                pii.treatment_id,\n                COALESCE(treatment.nama, pii.nama_item) as nama_treatment,\n                pii.nama_item,\n                pii.qty,\n                pii.harga,\n                pii.diskon_amount,\n                pii.diskon_referral,\n                pii.diskon_subtotal_amount,\n                pii.subtotal,\n                pii.jenis_transaksi as jenis_transaksi_id,\n                COALESCE(jt.nama_jenis_transaksi, CONCAT('Jenis ', pii.jenis_transaksi)) as jenis_transaksi_nama,\n                dokter.nama as nama_dokter,\n                perawat.nama as nama_perawat\n            ")
            ->orderBy('pasien.nama')
            ->orderBy('pi.id')
            ->get();

        $rows = $details
            ->groupBy('pasien_id')
            ->map(function ($items) {
                $first = $items->first();
                $treatments = $items->groupBy(function ($row) {
                    return ($row->treatment_id ?: 'item-' . $row->nama_treatment) . '|' . $row->nama_treatment;
                })->map(function ($treatmentItems) {
                    $firstTreatment = $treatmentItems->first();

                    return [
                        'treatment_id' => $firstTreatment->treatment_id,
                        'nama_treatment' => $firstTreatment->nama_treatment ?: $firstTreatment->nama_item,
                        'total_qty' => (float) $treatmentItems->sum('qty'),
                        'total_net' => (float) $treatmentItems->sum('subtotal'),
                    ];
                })->sortByDesc('total_qty')->values();

                $topTreatment = $treatments->first();
                $cabangs = $items->pluck('nama_toko')->filter()->unique()->values();
                $jenisTransaksi = $items->pluck('jenis_transaksi_nama')->filter()->unique()->values();
                $dokters = $items->pluck('nama_dokter')->filter()->unique()->values();
                $perawats = $items->pluck('nama_perawat')->filter()->unique()->values();

                return [
                    'peringkat' => 0,
                    'pasien_id' => $first->pasien_id,
                    'no_rm' => $first->no_rm ?: '-',
                    'nama_pasien' => $first->nama_pasien ?: '-',
                    'no_hp' => $this->normalizePhone($first->no_wa ?: $first->no_hp),
                    'cabang' => $cabangs->isNotEmpty() ? $cabangs->implode(', ') : '-',
                    'total_invoice' => $items->pluck('pembayaran_id')->unique()->count(),
                    'total_hari_transaksi' => $items->pluck('tanggal_transaksi')->unique()->count(),
                    'total_item_treatment' => $items->count(),
                    'total_qty_treatment' => (float) $items->sum('qty'),
                    'total_jenis_treatment' => $treatments->count(),
                    'total_nilai_treatment' => (float) $items->sum('subtotal'),
                    'rata_nilai_per_qty' => (float) $items->sum('qty') > 0
                        ? round((float) $items->sum('subtotal') / (float) $items->sum('qty'), 2)
                        : 0,
                    'treatment_terbanyak' => $topTreatment['nama_treatment'] ?? '-',
                    'qty_treatment_terbanyak' => (float) ($topTreatment['total_qty'] ?? 0),
                    'nilai_treatment_terbanyak' => (float) ($topTreatment['total_net'] ?? 0),
                    'jenis_transaksi' => $jenisTransaksi->isNotEmpty() ? $jenisTransaksi->implode(', ') : '-',
                    'dokter' => $dokters->isNotEmpty() ? $dokters->implode(', ') : '-',
                    'perawat' => $perawats->isNotEmpty() ? $perawats->implode(', ') : '-',
                    'invoice_terkait' => $items->pluck('no_invoice')->filter()->unique()->values()->implode(', '),
                    'treatment_detail' => $treatments->take(5)->map(function ($item) {
                        return $item['nama_treatment'] . ' (' . $this->formatNumber($item['total_qty']) . 'x)';
                    })->implode(', '),
                ];
            })
            ->sort(function ($a, $b) {
                $qtyCompare = $b['total_qty_treatment'] <=> $a['total_qty_treatment'];

                if ($qtyCompare !== 0) {
                    return $qtyCompare;
                }

                $valueCompare = $b['total_nilai_treatment'] <=> $a['total_nilai_treatment'];

                if ($valueCompare !== 0) {
                    return $valueCompare;
                }

                return strcmp($a['nama_pasien'], $b['nama_pasien']);
            })
            ->values()
            ->take((int) $filters['peringkat'])
            ->values();

        return $rows->map(function ($row, $index) {
            $row['peringkat'] = $index + 1;

            return $row;
        });
    }

    private function columns(): array
    {
        return [
            ['key' => 'peringkat', 'label' => 'Peringkat', 'type' => 'number'],
            ['key' => 'no_rm', 'label' => 'No RM'],
            ['key' => 'nama_pasien', 'label' => 'Nama Pasien'],
            ['key' => 'no_hp', 'label' => 'No HP/WA'],
            ['key' => 'cabang', 'label' => 'Cabang'],
            ['key' => 'total_invoice', 'label' => 'Jumlah Invoice', 'type' => 'number'],
            ['key' => 'total_hari_transaksi', 'label' => 'Jumlah Hari', 'type' => 'number'],
            ['key' => 'total_item_treatment', 'label' => 'Jumlah Item', 'type' => 'number'],
            ['key' => 'total_qty_treatment', 'label' => 'Total Qty Treatment', 'type' => 'number'],
            ['key' => 'total_jenis_treatment', 'label' => 'Jenis Treatment', 'type' => 'number'],
            ['key' => 'total_nilai_treatment', 'label' => 'Total Net Treatment', 'type' => 'currency'],
            ['key' => 'rata_nilai_per_qty', 'label' => 'Rata-rata per Qty', 'type' => 'currency'],
            ['key' => 'treatment_terbanyak', 'label' => 'Treatment Terbanyak'],
            ['key' => 'qty_treatment_terbanyak', 'label' => 'Qty Treatment Terbanyak', 'type' => 'number'],
            ['key' => 'treatment_detail', 'label' => 'Top 5 Treatment'],
            ['key' => 'jenis_transaksi', 'label' => 'Jenis Transaksi'],
            ['key' => 'dokter', 'label' => 'Dokter'],
            ['key' => 'perawat', 'label' => 'Nurse/Beautician'],
            ['key' => 'invoice_terkait', 'label' => 'Invoice Terkait'],
        ];
    }

    private function buildHtml(string $title, array $columns, $rows, array $filters, bool $autoPrint = false): string
    {
        $filterRows = [
            ['Tanggal', $this->formatDate($filters['tanggal_awal']) . ' s/d ' . $this->formatDate($filters['tanggal_akhir'])],
            ['Peringkat', 'Top ' . (int) $filters['peringkat']],
            ['Cabang', $this->publicFilters($filters)['toko_nama'] ?: 'Semua cabang'],
            ['Jenis Transaksi', $this->jenisTransaksiLabel($filters['jenis_transaksi'])],
            ['Tanggal Berdasarkan', 'Tanggal lunas invoice'],
        ];

        $head = collect($columns)->map(function ($column) {
            return '<th>' . e($column['label']) . '</th>';
        })->implode('');

        $body = $rows->map(function ($row) use ($columns) {
            $cells = collect($columns)->map(function ($column) use ($row) {
                $value = $row[$column['key']] ?? null;
                $type = $column['type'] ?? 'text';
                $class = in_array($type, ['currency', 'number'], true) ? ' class="num"' : '';

                if ($type === 'currency') {
                    $value = $this->formatCurrency($value);
                } elseif ($type === 'number') {
                    $value = $this->formatNumber($value);
                }

                return '<td' . $class . '>' . e((string) ($value ?? '-')) . '</td>';
            })->implode('');

            return '<tr>' . $cells . '</tr>';
        })->implode('');

        if ($rows->isEmpty()) {
            $body = '<tr><td colspan="' . count($columns) . '" style="text-align:center;color:#777;padding:18px;">Tidak ada data pada periode ini.</td></tr>';
        }

        $filterHtml = collect($filterRows)->map(function ($item) {
            return '<tr><td style="width:160px;font-weight:bold;">' . e($item[0]) . '</td><td>' . e($item[1]) . '</td></tr>';
        })->implode('');

        $printedAt = Carbon::now()->format('d/m/Y H:i:s');
        $autoPrintScript = $autoPrint ? '<script>window.addEventListener("load", function(){ window.print(); });</script>' : '';

        return '<!doctype html>
<html>
<head>
<meta charset="UTF-8">
<title>' . e($title) . '</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#111;margin:18px;}
h1{font-size:18px;margin:0 0 10px;}
.meta{margin-bottom:14px;border-collapse:collapse;}
.meta td{padding:4px 8px;border:1px solid #ddd;}
table.report{border-collapse:collapse;width:100%;}
.report th{background:#f3f4f6;font-weight:bold;}
.report th,.report td{border:1px solid #d7d7d7;padding:6px;vertical-align:top;}
.num{text-align:right;white-space:nowrap;}
.footer{margin-top:12px;font-size:11px;color:#666;}
@media print{body{margin:10mm;} .no-print{display:none;}}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:12px;"><button onclick="window.print()">Print / Save PDF</button></div>
<h1>' . e($title) . '</h1>
<table class="meta">' . $filterHtml . '</table>
<table class="report"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>
<div class="footer">Dicetak: ' . e($printedAt) . '</div>
' . $autoPrintScript . '
</body>
</html>';
    }

    private function getJenisTransaksiOptions()
    {
        $rows = DB::table('master_jenis_transaksi')
            ->whereIn('id', self::ALLOWED_JENIS_TRANSAKSI)
            ->where('is_active', 1)
            ->where('is_delete', 0)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'kode_jenis_transaksi', 'nama_jenis_transaksi']);

        if ($rows->count() === count(self::ALLOWED_JENIS_TRANSAKSI)) {
            return $rows;
        }

        return collect(self::ALLOWED_JENIS_TRANSAKSI)->map(function ($id) use ($rows) {
            $row = $rows->firstWhere('id', $id);

            return (object) [
                'id' => $id,
                'kode_jenis_transaksi' => $row->kode_jenis_transaksi ?? $this->defaultJenisTransaksiCode($id),
                'nama_jenis_transaksi' => $row->nama_jenis_transaksi ?? $this->defaultJenisTransaksiLabel($id),
            ];
        });
    }

    private function jenisTransaksiLabel($id): string
    {
        if ($id === null || $id === '' || $id === 'all') {
            return 'Semua jenis transaksi';
        }

        $row = DB::table('master_jenis_transaksi')
            ->where('id', (int) $id)
            ->where('is_delete', 0)
            ->first(['nama_jenis_transaksi']);

        return $row->nama_jenis_transaksi ?? $this->defaultJenisTransaksiLabel((int) $id);
    }

    private function defaultJenisTransaksiCode(int $id): string
    {
        return match ($id) {
            0 => 'UMUM',
            1 => 'ENDORSE_FASKAR',
            2 => 'ELITEGLOWBAL',
            3 => 'OWNER',
            4 => 'DEPOSIT',
            default => 'UNKNOWN',
        };
    }

    private function defaultJenisTransaksiLabel(int $id): string
    {
        return match ($id) {
            0 => 'Umum',
            1 => 'Endorse / Fasilitas Karyawan',
            2 => 'EliteGlowbal',
            3 => 'Owner',
            4 => 'Deposit',
            default => 'Jenis ' . $id,
        };
    }

    private function filename(string $format, array $filters): string
    {
        $extension = $format === 'excel' ? 'xls' : 'html';

        return sprintf(
            'data-pasien-treatment-terbanyak-%s-sd-%s-top-%s.%s',
            $filters['tanggal_awal'],
            $filters['tanggal_akhir'],
            (int) $filters['peringkat'],
            $extension
        );
    }

    private function normalizePhone(?string $value): string
    {
        $phone = preg_replace('/\s+/', '', (string) $value);

        if ($phone === '') {
            return '-';
        }

        if (str_starts_with($phone, '8')) {
            return '62' . $phone;
        }

        if (str_starts_with($phone, '0')) {
            return '62' . substr($phone, 1);
        }

        return $phone;
    }

    private function formatDate(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        return Carbon::parse($value)->format('d/m/Y');
    }

    private function formatCurrency($value): string
    {
        return 'Rp ' . number_format((float) $value, 0, ',', '.');
    }

    private function formatNumber($value): string
    {
        return number_format((float) $value, 2, ',', '.');
    }
}
