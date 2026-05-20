<?php

namespace App\Http\Controllers\Api\PelayananMedis;

use App\Http\Controllers\Controller;
use App\Models\Registrasi\RegistrasiKunjungan;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RiwayatPelayananController extends Controller
{
    public function index(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
            ])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_SELESAI);

        $this->applyFilters($query, $request);

        $summaryQuery = clone $query;

        $rows = $query
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate((int) $request->get('per_page', 15));

        $items = $rows->getCollection()->map(function ($row) {
            return $this->formatHistoryRow($row);
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Data riwayat pelayanan berhasil diambil',
            'rows' => $items,
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->lastItem(),
            'summary' => $this->buildSummary($summaryQuery),
        ]);
    }

    public function show($id)
    {
        $row = RegistrasiKunjungan::query()
            ->with([
                'toko',
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan',
                'konsultasiIntake.fotos',
                'konsultasiFotos',
                'dokterSoap.resepDetails',
                'treatmentDetails.treatment',
                'treatmentDetails.treatmentToko',
                'penjualanDetails.produk',
                'penjualanDetails.produkToko',
                'perawatCppts',
                'beforeAfterFotos',
                'bahanTreatmentDetails',
            ])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_SELESAI)
            ->findOrFail($id);

        return response()->json([
            'status' => true,
            'message' => 'Detail riwayat pelayanan berhasil diambil',
            'data' => $this->formatHistoryRow($row),
        ]);
    }

    private function applyFilters($query, Request $request)
    {
        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal_mulai')) {
            $query->whereDate('tanggal_kunjungan', '>=', $request->tanggal_mulai);
        }

        if ($request->filled('tanggal_selesai')) {
            $query->whereDate('tanggal_kunjungan', '<=', $request->tanggal_selesai);
        }

        if ($request->filled('channel')) {
            $this->applyChannelFilter($query, $request->channel);
        }

        if ($request->filled('layanan')) {
            $this->applyLayananFilter($query, $request->layanan);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);

            $query->where(function ($q) use ($search) {
                $q->where('kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('pasien', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%");
                    })
                    ->orWhereHas('dokterAwal', function ($d) use ($search) {
                        $d->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhereHas('perawatAwal', function ($p) use ($search) {
                        $p->where('nama', 'like', "%{$search}%");
                    });
            });
        }
    }

    private function applyChannelFilter($query, $channel)
    {
        $channel = strtolower(trim((string) $channel));

        if ($channel === 'offline') {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
            return;
        }

        if ($channel === 'online') {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
            return;
        }

        if (in_array($channel, ['tanpa_konsultasi', 'tanpa konsultasi'], true)) {
            $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
        }
    }

    private function applyLayananFilter($query, $layanan)
    {
        $layanan = strtolower(trim((string) $layanan));

        if ($layanan === 'konsultasi') {
            $query->whereIn('channel_konsultasi', [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ])
            ->where('is_treatment', 0)
            ->where('is_penjualan', 0);

            return;
        }

        if ($layanan === 'treatment') {
            $query->where('is_treatment', 1)
                ->where('is_penjualan', 0)
                ->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);

            return;
        }

        if ($layanan === 'penjualan') {
            $query->where('is_penjualan', 1)
                ->where('is_treatment', 0)
                ->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);

            return;
        }

        if ($layanan === 'konsultasi_treatment') {
            $query->whereIn('channel_konsultasi', [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ])
            ->where('is_treatment', 1)
            ->where('is_penjualan', 0);

            return;
        }

        if ($layanan === 'konsultasi_penjualan') {
            $query->whereIn('channel_konsultasi', [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ])
            ->where('is_treatment', 0)
            ->where('is_penjualan', 1);

            return;
        }

        if ($layanan === 'treatment_penjualan') {
            $query->where('is_treatment', 1)
                ->where('is_penjualan', 1)
                ->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);

            return;
        }

        if ($layanan === 'full') {
            $query->whereIn('channel_konsultasi', [
                RegistrasiKunjungan::CHANNEL_OFFLINE,
                RegistrasiKunjungan::CHANNEL_ONLINE,
            ])
            ->where('is_treatment', 1)
            ->where('is_penjualan', 1);
        }
    }

    private function buildSummary($query)
    {
        $rows = $query->get([
            'id',
            'channel_konsultasi',
            'is_treatment',
            'is_penjualan',
        ]);

        return [
            'total' => $rows->count(),
            'konsultasi' => $rows->filter(function ($row) {
                return $this->hasConsultation($row);
            })->count(),
            'treatment' => $rows->filter(function ($row) {
                return (int) $row->is_treatment === 1;
            })->count(),
            'penjualan' => $rows->filter(function ($row) {
                return (int) $row->is_penjualan === 1;
            })->count(),
        ];
    }

    private function formatHistoryRow(RegistrasiKunjungan $row)
    {
        $row->setAttribute('registrasi_id', $row->id);
        $row->setAttribute('nomor_kunjungan', $row->kode_registrasi);
        $row->setAttribute('nomor_invoice', $this->getInvoiceNumber($row));

        $row->setAttribute('nama_pasien', $row->pasien?->nama);
        $row->setAttribute('no_rm', $row->pasien?->no_rm);
        $row->setAttribute('no_hp', $row->pasien?->no_hp);

        $row->setAttribute('nama_dokter', $row->dokterAwal?->nama);
        $row->setAttribute('nama_perawat', $row->perawatAwal?->nama);

        $row->setAttribute('waktu_kunjungan', $this->formatTime($row->registered_at));

        $row->setAttribute('ada_konsultasi', $this->hasConsultation($row));
        $row->setAttribute('ada_treatment', (int) $row->is_treatment === 1);
        $row->setAttribute('ada_penjualan', (int) $row->is_penjualan === 1);

        $row->setAttribute('channel_label', $this->formatChannel($row->channel_konsultasi));
        $row->setAttribute('layanan_label', $this->formatLayanan($row));

        $row->setAttribute('total_pembayaran', $this->getTotalPembayaran($row));
        $row->setAttribute('status_label', 'Selesai');

        return $row;
    }

    private function getInvoiceNumber(RegistrasiKunjungan $row)
    {
        return $row->nomor_invoice
            ?? $row->invoice_number
            ?? $row->faktur
            ?? null;
    }

    private function getTotalPembayaran(RegistrasiKunjungan $row)
    {
        return $row->total_pembayaran
            ?? $row->grand_total
            ?? $row->total_bayar
            ?? 0;
    }

    private function hasConsultation(RegistrasiKunjungan $row)
    {
        return in_array((int) $row->channel_konsultasi, [
            RegistrasiKunjungan::CHANNEL_OFFLINE,
            RegistrasiKunjungan::CHANNEL_ONLINE,
        ], true);
    }

    private function formatChannel($channel)
    {
        return match ((int) $channel) {
            RegistrasiKunjungan::CHANNEL_OFFLINE => 'Konsultasi Offline',
            RegistrasiKunjungan::CHANNEL_ONLINE => 'Konsultasi Online',
            default => 'Tanpa Konsultasi',
        };
    }

    private function formatLayanan(RegistrasiKunjungan $row)
    {
        $hasConsultation = $this->hasConsultation($row);
        $hasTreatment = (int) $row->is_treatment === 1;
        $hasSales = (int) $row->is_penjualan === 1;

        if ($hasConsultation && $hasTreatment && $hasSales) {
            return 'Konsultasi + Treatment + Penjualan';
        }

        if ($hasConsultation && $hasTreatment) {
            return 'Konsultasi + Treatment';
        }

        if ($hasConsultation && $hasSales) {
            return 'Konsultasi + Penjualan';
        }

        if ($hasTreatment && $hasSales) {
            return 'Treatment + Penjualan';
        }

        if ($hasConsultation) {
            return 'Konsultasi';
        }

        if ($hasTreatment) {
            return 'Treatment';
        }

        if ($hasSales) {
            return 'Penjualan';
        }

        return '-';
    }

    private function formatTime($value)
    {
        if (!$value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }
}