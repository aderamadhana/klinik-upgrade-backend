<?php

namespace App\Http\Controllers\Api\Registrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterAccurateItemMapping;
use App\Models\Master\MasterProdukToko;
use App\Models\Master\MasterTreatmentToko;
use App\Models\Pasien;
use App\Models\Registrasi\RegistrasiKonsultasiFoto;
use App\Models\Registrasi\RegistrasiKonsultasiIntake;
use App\Models\Registrasi\RegistrasiKunjungan;
use App\Models\Registrasi\RegistrasiPenjualanDetail;
use App\Models\Registrasi\RegistrasiTask;
use App\Models\Registrasi\RegistrasiTreatmentDetail;
use App\Models\Stock\StockProdukToko;
use App\Services\Stock\StockTransactionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class RegistrasiLayananController extends Controller
{
    protected StockTransactionService $stockTransactionService;

    public function __construct(StockTransactionService $stockTransactionService)
    {
        $this->stockTransactionService = $stockTransactionService;
    }

    public function index(Request $request)
    {
        $perPage = (int) $request->get('per_page', 15);

        if ($perPage <= 0) {
            $perPage = 15;
        }

        if ($perPage > 100) {
            $perPage = 100;
        }

        $registrasiTable = (new RegistrasiKunjungan())->getTable();

        $query = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'pasien:id,no_rm,nama,no_hp',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
            ])
            ->withCount([
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ])
            ->active();

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        if ($request->filled('tanggal_mulai')) {
            $query->whereDate('tanggal_kunjungan', '>=', $request->tanggal_mulai);
        }

        if ($request->filled('tanggal_akhir')) {
            $query->whereDate('tanggal_kunjungan', '<=', $request->tanggal_akhir);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('current_task')) {
            $query->where('current_task', $request->current_task);
        }

        if ($request->filled('layanan')) {
            $layanan = strtolower(trim((string) $request->layanan));

            $query->where(function ($q) use ($layanan, $registrasiTable) {
                if ($layanan === 'konsultasi') {
                    if (Schema::hasColumn($registrasiTable, 'is_konsultasi')) {
                        $q->orWhere('is_konsultasi', 1);
                    }

                    $q->orWhereNotNull('konsultasi_source_code')
                        ->orWhere(function ($sub) {
                            $sub->whereNotNull('channel_konsultasi')
                                ->where('channel_konsultasi', '<>', 0);
                        });
                }

                if ($layanan === 'treatment') {
                    $q->where('is_treatment', 1);
                }

                if ($layanan === 'penjualan') {
                    $q->where('is_penjualan', 1);
                }

                if ($layanan === 'pembelian_online') {
                    $q->where('is_pembelian_online', 1);
                }
            });
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($q) use ($search) {
                $q->where('kode_registrasi', 'like', "%{$search}%")
                    ->orWhere('konsultasi_source_code', 'like', "%{$search}%")
                    ->orWhere('konsultasi_source_name', 'like', "%{$search}%")
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

        $rows = $query
            ->orderByDesc('registered_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $sourceCodes = collect($rows->items())
            ->pluck('konsultasi_source_code')
            ->filter()
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->unique()
            ->values()
            ->all();

        $mappingCodes = collect($sourceCodes)
            ->push('PEMBELIAN_ONLINE')
            ->unique()
            ->values()
            ->all();

        $accurateMappings = MasterAccurateItemMapping::query()
            ->whereIn('source_code', $mappingCodes)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('is_active', 1)
                    ->orWhereNull('is_active');
            })
            ->get()
            ->keyBy(fn ($item) => strtoupper((string) $item->source_code));

        $toBool = function ($value): bool {
            return $value === true
                || $value === 1
                || $value === '1'
                || strtolower((string) $value) === 'true';
        };

        $channelLabel = function ($channel, ?string $sourceCode = null): string {
            $normalized = strtolower((string) $channel);
            $source = strtoupper((string) $sourceCode);

            if ($normalized === '2' || $normalized === 'online' || str_contains($source, 'ONLINE')) {
                return 'Konsultasi Online';
            }

            if ($normalized === '1' || $normalized === 'offline' || $source) {
                if (str_contains($source, 'SPPG')) {
                    return 'Konsultasi SPPG';
                }

                if (str_contains($source, 'SPKK')) {
                    return 'Konsultasi SPKK';
                }

                return 'Konsultasi Offline';
            }

            return '-';
        };

        $statusLabel = function ($status): array {
            return match ((int) $status) {
                1 => [
                    'value' => 1,
                    'label' => 'Aktif',
                    'color' => 'primary',
                    'icon' => 'mdi-progress-clock',
                ],
                2 => [
                    'value' => 2,
                    'label' => 'Selesai',
                    'color' => 'success',
                    'icon' => 'mdi-check-circle-outline',
                ],
                9 => [
                    'value' => 9,
                    'label' => 'Batal',
                    'color' => 'error',
                    'icon' => 'mdi-close-circle-outline',
                ],
                default => [
                    'value' => (int) $status,
                    'label' => 'Draft',
                    'color' => 'grey',
                    'icon' => 'mdi-file-outline',
                ],
            };
        };

        $taskLabel = function ($task): array {
            return match ((int) $task) {
                1 => [
                    'value' => 1,
                    'label' => 'Konsultasi',
                    'color' => 'primary',
                    'icon' => 'mdi-stethoscope',
                ],
                2 => [
                    'value' => 2,
                    'label' => 'Treatment',
                    'color' => 'success',
                    'icon' => 'mdi-face-woman-shimmer-outline',
                ],
                3 => [
                    'value' => 3,
                    'label' => 'Nurse Station',
                    'color' => 'teal',
                    'icon' => 'mdi-account-heart-outline',
                ],
                4 => [
                    'value' => 4,
                    'label' => 'Pembayaran',
                    'color' => 'info',
                    'icon' => 'mdi-cash-register',
                ],
                5 => [
                    'value' => 5,
                    'label' => 'Selesai',
                    'color' => 'success',
                    'icon' => 'mdi-check-all',
                ],
                default => [
                    'value' => (int) $task,
                    'label' => 'Draft',
                    'color' => 'grey',
                    'icon' => 'mdi-file-outline',
                ],
            };
        };

        $mappingPayload = function ($mapping) {
            if (!$mapping) {
                return null;
            }

            return [
                'id' => $mapping->id,
                'source_type' => $mapping->source_type,
                'source_code' => $mapping->source_code,
                'source_name' => $mapping->source_name,
                'legacy_treatment_id' => $mapping->legacy_treatment_id,
                'legacy_treatment_name' => $mapping->legacy_treatment_name,
                'kode_accurate' => $mapping->kode_accurate,
                'nama_accurate' => $mapping->nama_accurate,
                'default_harga' => (float) ($mapping->default_harga ?? 0),
                'is_billable' => (int) ($mapping->is_billable ?? 0),
                'is_send_to_accurate' => (int) ($mapping->is_send_to_accurate ?? 0),
                'send_when_zero' => (int) ($mapping->send_when_zero ?? 0),
            ];
        };

        $rows->getCollection()->transform(function ($row) use (
            $accurateMappings,
            $toBool,
            $channelLabel,
            $statusLabel,
            $taskLabel,
            $mappingPayload
        ) {
            $sourceCode = strtoupper(trim((string) $row->konsultasi_source_code));
            $konsultasiMapping = $sourceCode
                ? ($accurateMappings[$sourceCode] ?? null)
                : null;

            $isPembelianOnline = $toBool($row->is_pembelian_online ?? false);
            $pembelianOnlineMapping = $isPembelianOnline
                ? ($accurateMappings['PEMBELIAN_ONLINE'] ?? null)
                : null;

            $hasConsultation = !empty($row->konsultasi_source_code)
                || !empty($row->konsultasi_source_name)
                || !empty($row->channel_konsultasi);

            $status = $statusLabel($row->status);
            $currentTask = $taskLabel($row->current_task);

            return [
                'id' => $row->id,
                'kode_registrasi' => $row->kode_registrasi,
                'toko_id' => $row->toko_id,
                'toko_nama' => $row->toko?->nama_toko,

                'tanggal_kunjungan' => $row->tanggal_kunjungan,
                'registered_at' => $row->registered_at,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,

                'pasien_id' => $row->pasien_id,
                'pasien' => $row->pasien ? [
                    'id' => $row->pasien->id,
                    'no_rm' => $row->pasien->no_rm,
                    'nama' => $row->pasien->nama,
                    'no_hp' => $row->pasien->no_hp,
                ] : null,

                'dokter_awal_id' => $row->dokter_awal_id,
                'dokter_awal' => $row->dokterAwal ? [
                    'id' => $row->dokterAwal->id,
                    'nama' => $row->dokterAwal->nama,
                ] : null,

                'perawat_awal_id' => $row->perawat_awal_id,
                'perawat_awal' => $row->perawatAwal ? [
                    'id' => $row->perawatAwal->id,
                    'nama' => $row->perawatAwal->nama,
                ] : null,

                'channel_konsultasi' => $row->channel_konsultasi,
                'channel_konsultasi_label' => $channelLabel(
                    $row->channel_konsultasi,
                    $row->konsultasi_source_code
                ),

                'konsultasi_source_code' => $row->konsultasi_source_code,
                'konsultasi_source_name' => $row->konsultasi_source_name,
                'bukti_chat_konsultasi_online' => $row->bukti_chat_konsultasi_online ?? null,
                'bukti_chat_konsultasi_online_url' => $this->storagePublicUrl($row->bukti_chat_konsultasi_online ?? null),
                'is_upload_bukti_chat_konsultasi_online' => !empty($row->bukti_chat_konsultasi_online) ? 1 : 0,
                'konsultasi_mapping' => $mappingPayload($konsultasiMapping),

                'is_konsultasi' => $hasConsultation ? 1 : 0,
                'is_treatment' => (int) ($row->is_treatment ?? 0),
                'is_penjualan' => (int) ($row->is_penjualan ?? 0),
                'is_pembelian_online' => $isPembelianOnline ? 1 : 0,
                'pembelian_online_mapping' => $mappingPayload($pembelianOnlineMapping),

                'perlu_tindakan_perawat' => (int) ($row->perlu_tindakan_perawat ?? 0),
                'current_task' => (int) ($row->current_task ?? 0),
                'current_task_label' => $currentTask,
                'status' => (int) ($row->status ?? 0),
                'status_label' => $status,

                'total_treatment' => (float) ($row->total_treatment ?? 0),
                'total_penjualan' => (float) ($row->total_penjualan ?? 0),
                'total_konsultasi' => (float) ($row->total_konsultasi ?? 0),
                'grand_total' => (float) ($row->grand_total ?? 0),
                'rule_biaya_konsultasi' => (int) ($row->rule_biaya_konsultasi ?? 0),
                'catatan_biaya_konsultasi' => $row->catatan_biaya_konsultasi,
                'catatan_registrasi' => $row->catatan_registrasi,

                'tasks_count' => (int) ($row->tasks_count ?? 0),
                'treatment_details_count' => (int) ($row->treatment_details_count ?? 0),
                'penjualan_details_count' => (int) ($row->penjualan_details_count ?? 0),

                'layanan' => [
                    'ada_konsultasi' => $hasConsultation ? 1 : 0,
                    'channel_konsultasi' => $row->channel_konsultasi,
                    'channel_label' => $channelLabel(
                        $row->channel_konsultasi,
                        $row->konsultasi_source_code
                    ),
                    'konsultasi_source_code' => $row->konsultasi_source_code,
                    'konsultasi_source_name' => $row->konsultasi_source_name,
                    'bukti_chat_konsultasi_online' => $row->bukti_chat_konsultasi_online ?? null,
                    'bukti_chat_konsultasi_online_url' => $this->storagePublicUrl($row->bukti_chat_konsultasi_online ?? null),
                    'is_upload_bukti_chat_konsultasi_online' => !empty($row->bukti_chat_konsultasi_online) ? 1 : 0,
                    'konsultasi_mapping' => $mappingPayload($konsultasiMapping),

                    'ada_treatment' => (int) ($row->is_treatment ?? 0),
                    'ada_penjualan' => (int) ($row->is_penjualan ?? 0),

                    'is_pembelian_online' => $isPembelianOnline ? 1 : 0,
                    'pembelian_online_mapping' => $mappingPayload($pembelianOnlineMapping),
                ],
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'Data registrasi berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function show($id)
    {
        $row = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'pasien:id,no_rm,nama,no_hp',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
                'tasks',
                'treatmentDetails',
                'penjualanDetails',
            ])
            ->active()
            ->findOrFail($id);

        $sourceCodes = collect([
                $row->konsultasi_source_code,
                $row->is_pembelian_online ? 'PEMBELIAN_ONLINE' : null,
            ])
            ->filter()
            ->map(fn ($value) => strtoupper(trim((string) $value)))
            ->unique()
            ->values()
            ->all();

        $accurateMappings = MasterAccurateItemMapping::query()
            ->whereIn('source_code', $sourceCodes)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->where(function ($q) {
                $q->where('is_active', 1)
                    ->orWhereNull('is_active');
            })
            ->get()
            ->keyBy(fn ($item) => strtoupper((string) $item->source_code));

        $toBool = function ($value): bool {
            return $value === true
                || $value === 1
                || $value === '1'
                || strtolower((string) $value) === 'true';
        };

        $channelLabel = function ($channel, ?string $sourceCode = null): string {
            $normalized = strtolower((string) $channel);
            $source = strtoupper((string) $sourceCode);

            if ($normalized === '2' || $normalized === 'online' || str_contains($source, 'ONLINE')) {
                return 'Konsultasi Online';
            }

            if ($normalized === '1' || $normalized === 'offline' || $source) {
                if (str_contains($source, 'SPPG')) {
                    return 'Konsultasi SPPG';
                }

                if (str_contains($source, 'SPKK')) {
                    return 'Konsultasi SPKK';
                }

                return 'Konsultasi Offline';
            }

            return '-';
        };

        $statusLabel = function ($status): array {
            return match ((int) $status) {
                1 => [
                    'value' => 1,
                    'label' => 'Aktif',
                    'color' => 'primary',
                    'icon' => 'mdi-progress-clock',
                ],
                2 => [
                    'value' => 2,
                    'label' => 'Selesai',
                    'color' => 'success',
                    'icon' => 'mdi-check-circle-outline',
                ],
                9 => [
                    'value' => 9,
                    'label' => 'Batal',
                    'color' => 'error',
                    'icon' => 'mdi-close-circle-outline',
                ],
                default => [
                    'value' => (int) $status,
                    'label' => 'Draft',
                    'color' => 'grey',
                    'icon' => 'mdi-file-outline',
                ],
            };
        };

        $taskLabel = function ($task): array {
            return match ((int) $task) {
                1 => [
                    'value' => 1,
                    'label' => 'Konsultasi',
                    'color' => 'primary',
                    'icon' => 'mdi-stethoscope',
                ],
                2 => [
                    'value' => 2,
                    'label' => 'Treatment',
                    'color' => 'success',
                    'icon' => 'mdi-face-woman-shimmer-outline',
                ],
                3 => [
                    'value' => 3,
                    'label' => 'Nurse Station',
                    'color' => 'teal',
                    'icon' => 'mdi-account-heart-outline',
                ],
                4 => [
                    'value' => 4,
                    'label' => 'Pembayaran',
                    'color' => 'info',
                    'icon' => 'mdi-cash-register',
                ],
                5 => [
                    'value' => 5,
                    'label' => 'Selesai',
                    'color' => 'success',
                    'icon' => 'mdi-check-all',
                ],
                default => [
                    'value' => (int) $task,
                    'label' => 'Draft',
                    'color' => 'grey',
                    'icon' => 'mdi-file-outline',
                ],
            };
        };

        $mappingPayload = function ($mapping) {
            if (!$mapping) {
                return null;
            }

            return [
                'id' => $mapping->id,
                'source_type' => $mapping->source_type,
                'source_code' => $mapping->source_code,
                'source_name' => $mapping->source_name,
                'legacy_treatment_id' => $mapping->legacy_treatment_id,
                'legacy_treatment_name' => $mapping->legacy_treatment_name,
                'kode_accurate' => $mapping->kode_accurate,
                'nama_accurate' => $mapping->nama_accurate,
                'default_harga' => (float) ($mapping->default_harga ?? 0),
                'is_billable' => (int) ($mapping->is_billable ?? 0),
                'is_send_to_accurate' => (int) ($mapping->is_send_to_accurate ?? 0),
                'send_when_zero' => (int) ($mapping->send_when_zero ?? 0),
            ];
        };

        $sourceCode = strtoupper(trim((string) $row->konsultasi_source_code));

        $konsultasiMapping = $sourceCode
            ? ($accurateMappings[$sourceCode] ?? null)
            : null;

        $isPembelianOnline = $toBool($row->is_pembelian_online ?? false);

        $pembelianOnlineMapping = $isPembelianOnline
            ? ($accurateMappings['PEMBELIAN_ONLINE'] ?? null)
            : null;

        $hasConsultation = !empty($row->konsultasi_source_code)
            || !empty($row->konsultasi_source_name)
            || !empty($row->channel_konsultasi);

        $payload = [
            'id' => $row->id,
            'kode_registrasi' => $row->kode_registrasi,
            'toko_id' => $row->toko_id,
            'toko_nama' => $row->toko?->nama_toko,
            'toko' => $row->toko ? [
                'id' => $row->toko->id,
                'nama_toko' => $row->toko->nama_toko,
            ] : null,

            'tanggal_kunjungan' => $row->tanggal_kunjungan,
            'registered_at' => $row->registered_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,

            'pasien_id' => $row->pasien_id,
            'pasien_new_id' => $row->pasien_id,
            'pasien' => $row->pasien ? [
                'id' => $row->pasien->id,
                'no_rm' => $row->pasien->no_rm,
                'nama' => $row->pasien->nama,
                'no_hp' => $row->pasien->no_hp,
            ] : null,

            'dokter_awal_id' => $row->dokter_awal_id,
            'dokter_awal' => $row->dokterAwal ? [
                'id' => $row->dokterAwal->id,
                'nama' => $row->dokterAwal->nama,
            ] : null,

            'perawat_awal_id' => $row->perawat_awal_id,
            'perawat_awal' => $row->perawatAwal ? [
                'id' => $row->perawatAwal->id,
                'nama' => $row->perawatAwal->nama,
            ] : null,

            'channel_konsultasi' => $row->channel_konsultasi,
            'channel_konsultasi_label' => $channelLabel(
                $row->channel_konsultasi,
                $row->konsultasi_source_code
            ),

            'konsultasi_source_code' => $row->konsultasi_source_code,
            'konsultasi_source_name' => $row->konsultasi_source_name,
            'bukti_chat_konsultasi_online' => $row->bukti_chat_konsultasi_online ?? null,
            'bukti_chat_konsultasi_online_url' => $this->storagePublicUrl($row->bukti_chat_konsultasi_online ?? null),
            'is_upload_bukti_chat_konsultasi_online' => !empty($row->bukti_chat_konsultasi_online) ? 1 : 0,
            'konsultasi_mapping' => $mappingPayload($konsultasiMapping),

            'is_konsultasi' => $hasConsultation ? 1 : 0,
            'is_treatment' => (int) ($row->is_treatment ?? 0),
            'is_penjualan' => (int) ($row->is_penjualan ?? 0),
            'is_pembelian_online' => $isPembelianOnline ? 1 : 0,
            'pembelian_online_mapping' => $mappingPayload($pembelianOnlineMapping),

            'perlu_tindakan_perawat' => (int) ($row->perlu_tindakan_perawat ?? 0),
            'current_task' => (int) ($row->current_task ?? 0),
            'current_task_label' => $taskLabel($row->current_task),
            'status' => (int) ($row->status ?? 0),
            'status_label' => $statusLabel($row->status),

            'total_treatment' => (float) ($row->total_treatment ?? 0),
            'total_penjualan' => (float) ($row->total_penjualan ?? 0),
            'total_konsultasi' => (float) ($row->total_konsultasi ?? 0),
            'grand_total' => (float) ($row->grand_total ?? 0),
            'rule_biaya_konsultasi' => (int) ($row->rule_biaya_konsultasi ?? 0),
            'catatan_biaya_konsultasi' => $row->catatan_biaya_konsultasi,
            'catatan_registrasi' => $row->catatan_registrasi,

            'layanan' => [
                'ada_konsultasi' => $hasConsultation ? 1 : 0,
                'channel_konsultasi' => $row->channel_konsultasi,
                'channel_label' => $channelLabel(
                    $row->channel_konsultasi,
                    $row->konsultasi_source_code
                ),
                'konsultasi_source_code' => $row->konsultasi_source_code,
                'konsultasi_source_name' => $row->konsultasi_source_name,
                'bukti_chat_konsultasi_online' => $row->bukti_chat_konsultasi_online ?? null,
                'bukti_chat_konsultasi_online_url' => $this->storagePublicUrl($row->bukti_chat_konsultasi_online ?? null),
                'is_upload_bukti_chat_konsultasi_online' => !empty($row->bukti_chat_konsultasi_online) ? 1 : 0,
                'konsultasi_mapping' => $mappingPayload($konsultasiMapping),

                'ada_treatment' => (int) ($row->is_treatment ?? 0),
                'ada_penjualan' => (int) ($row->is_penjualan ?? 0),

                'is_pembelian_online' => $isPembelianOnline ? 1 : 0,
                'pembelian_online_mapping' => $mappingPayload($pembelianOnlineMapping),
            ],

            'tasks' => $row->tasks->map(function ($task) use ($taskLabel) {
                return [
                    'id' => $task->id,
                    'task_type' => $task->task_type ?? $task->current_task ?? null,
                    'task_label' => $task->task_label ?? $taskLabel($task->task_type ?? $task->current_task ?? null)['label'],
                    'status' => $task->status ?? null,
                    'started_at' => $task->started_at ?? null,
                    'finished_at' => $task->finished_at ?? null,
                ];
            })->values(),

            'treatment_details' => $row->treatmentDetails->map(function ($item) {
                return [
                    'id' => $item->id,
                    'treatment_id' => $item->treatment_id ?? $item->master_treatment_id ?? null,
                    'treatment_toko_id' => $item->treatment_toko_id ?? $item->master_treatment_toko_id ?? null,
                    'nama_treatment' => $item->nama_treatment ?? $item->treatment_nama ?? $item->nama ?? null,
                    'harga' => (float) ($item->harga ?? $item->harga_treatment ?? $item->treatment_harga ?? 0),
                    'jumlah' => (float) ($item->jumlah ?? $item->qty ?? 1),
                    'total' => (float) ($item->total ?? $item->subtotal ?? $item->total_harga ?? 0),
                ];
            })->values(),

            'penjualan_details' => $row->penjualanDetails->map(function ($item) {
                return [
                    'id' => $item->id,
                    'produk_id' => $item->produk_id ?? $item->obat_id ?? $item->master_produk_id ?? null,
                    'produk_toko_id' => $item->produk_toko_id ?? $item->master_produk_toko_id ?? null,
                    'nama_produk' => $item->nama_produk ?? $item->produk_nama ?? $item->nama_obat_bahan ?? $item->nama ?? null,
                    'harga' => (float) ($item->harga ?? $item->harga_jual ?? 0),
                    'jumlah' => (float) ($item->jumlah ?? $item->qty ?? 1),
                    'diskon_nilai' => (float) ($item->diskon_nilai ?? $item->diskon ?? 0),
                    'subtotal' => (float) ($item->subtotal ?? $item->total ?? $item->total_harga ?? 0),
                ];
            })->values(),
        ];

        return response()->json([
            'status' => true,
            'message' => 'Detail registrasi berhasil diambil',
            'data' => $payload,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => 'required|integer',
            'tanggal' => 'required|date',

            'pasien_id' => 'nullable|integer',
            'pasien_new_id' => 'nullable',

            'dokter_id' => 'nullable|integer',
            'perawat_id' => 'nullable|integer',

            'layanan' => 'required|array',
            'layanan.ada_konsultasi' => 'nullable|boolean',
            'layanan.channel_konsultasi' => 'nullable|string|in:offline,online',
            'layanan.konsultasi_source_code' => 'nullable|string|max:100',
            'layanan.konsultasi_source_name' => 'nullable|string|max:150',
            'layanan.konsultasi_mapping_id' => 'nullable|integer',
            'layanan.ada_treatment' => 'nullable|boolean',
            'layanan.ada_penjualan' => 'nullable|boolean',
            'layanan.route_treatment' => 'nullable|string|max:100',
            'layanan.is_pembelian_online' => 'nullable|boolean',

            'penjualan.items.*.produk_toko_id' => 'nullable|integer',
            'penjualan.items.*.produk_id' => 'nullable|integer',
            'penjualan.items.*.obat_id' => 'nullable|integer',
            'penjualan.items.*.tempat_produk_id' => 'nullable|integer',
            'penjualan.items.*.stock_produk_toko_id' => 'nullable|integer',
            'penjualan.items.*.jumlah' => 'nullable|numeric|min:0.0001',

            'konsultasi_source_code' => 'nullable|string|max:100',
            'konsultasi_source_name' => 'nullable|string|max:150',
            'konsultasi_mapping_id' => 'nullable|integer',
            'total_konsultasi' => 'nullable|numeric|min:0',
            'rule_biaya_konsultasi' => 'nullable|integer',
            'is_pembelian_online' => 'nullable|boolean',

            'catatan_registrasi' => 'nullable|string',

            'konsultasi_offline' => 'nullable|array',
            'konsultasi_offline.keluhan_awal' => 'nullable|string',
            'konsultasi_offline.catatan' => 'nullable|string',

            'konsultasi_online' => 'nullable|array',
            'konsultasi_online.request_dokter' => 'nullable',
            'konsultasi_online.alergi' => 'nullable|string',
            'konsultasi_online.keluhan' => 'nullable|string',
            'konsultasi_online.produk_sebelumnya' => 'nullable|string',
            'konsultasi_online.sedang_hamil' => 'nullable',
            'konsultasi_online.sedang_menyusui' => 'nullable',
            'konsultasi_online.bukti_foto_kiri' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'konsultasi_online.bukti_foto_depan' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',
            'konsultasi_online.bukti_foto_kanan' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:5120',

            'treatment' => 'nullable|array',
            'treatment.items' => 'nullable|array',

            'penjualan' => 'nullable|array',
            'penjualan.items' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $layanan = $request->input('layanan', []);

        $adaKonsultasi = $this->toBool($layanan['ada_konsultasi'] ?? false);
        $adaTreatment = $this->toBool($layanan['ada_treatment'] ?? false);
        $adaPenjualan = $this->toBool($layanan['ada_penjualan'] ?? false);

        if (!$adaKonsultasi && !$adaTreatment && !$adaPenjualan) {
            return response()->json([
                'status' => false,
                'message' => 'Minimal pilih satu layanan',
            ], 422);
        }

        $channelKonsultasiInput = $layanan['channel_konsultasi'] ?? null;

        $konsultasiSourceCode = $layanan['konsultasi_source_code']
            ?? $request->input('konsultasi_source_code')
            ?? null;

        if ($adaKonsultasi && !$konsultasiSourceCode) {
            $konsultasiSourceCode = $channelKonsultasiInput === 'online'
                ? 'KONSULTASI_ONLINE'
                : 'KONSULTASI_OFFLINE';
        }

        $konsultasiMapping = null;

        if ($adaKonsultasi) {
            $konsultasiMapping = MasterAccurateItemMapping::findActiveBySourceCode(
                $konsultasiSourceCode
            );

            if (!$konsultasiMapping) {
                return response()->json([
                    'status' => false,
                    'message' => 'Mapping Accurate untuk layanan konsultasi belum diset.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Mapping {$konsultasiSourceCode} belum ada atau belum aktif di master_accurate_item_mapping.",
                        ],
                    ],
                ], 422);
            }

            if (strtolower((string) $konsultasiMapping->source_type) !== 'konsultasi') {
                return response()->json([
                    'status' => false,
                    'message' => 'Source type mapping konsultasi tidak valid.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Source code {$konsultasiSourceCode} harus memiliki source_type konsultasi.",
                        ],
                    ],
                ], 422);
            }

            if (
                $this->toBool($konsultasiMapping->is_send_to_accurate ?? false)
                && empty($konsultasiMapping->kode_accurate)
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Kode Accurate konsultasi belum diisi.',
                    'errors' => [
                        'accurate_mapping' => [
                            "Kode Accurate untuk {$konsultasiSourceCode} masih kosong.",
                        ],
                    ],
                ], 422);
            }
        }

        $channelKonsultasi = $this->resolveChannelKonsultasiFromMapping(
            $adaKonsultasi,
            $channelKonsultasiInput,
            $konsultasiSourceCode
        );

        $isPembelianOnline = $this->toBool(
            $layanan['is_pembelian_online']
                ?? $request->input('is_pembelian_online', false)
        );

        $pembelianOnlineMapping = null;

        if ($isPembelianOnline) {
            $pembelianOnlineMapping = MasterAccurateItemMapping::findActiveBySourceCode(
                'PEMBELIAN_ONLINE'
            );

            if (!$pembelianOnlineMapping) {
                return response()->json([
                    'status' => false,
                    'message' => 'Mapping Accurate untuk pembelian online belum diset.',
                    'errors' => [
                        'accurate_mapping' => [
                            'Mapping PEMBELIAN_ONLINE belum ada atau belum aktif di master_accurate_item_mapping.',
                        ],
                    ],
                ], 422);
            }

            if (strtolower((string) $pembelianOnlineMapping->source_type) !== 'pembelian') {
                return response()->json([
                    'status' => false,
                    'message' => 'Source type mapping pembelian online tidak valid.',
                    'errors' => [
                        'accurate_mapping' => [
                            'PEMBELIAN_ONLINE harus memiliki source_type channel.',
                        ],
                    ],
                ], 422);
            }

            if (
                $this->toBool($pembelianOnlineMapping->is_send_to_accurate ?? false)
                && empty($pembelianOnlineMapping->kode_accurate)
            ) {
                return response()->json([
                    'status' => false,
                    'message' => 'Kode Accurate pembelian online belum diisi.',
                    'errors' => [
                        'accurate_mapping' => [
                            'Kode Accurate untuk PEMBELIAN_ONLINE masih kosong.',
                        ],
                    ],
                ], 422);
            }
        }

        if (($adaKonsultasi || $adaTreatment) && !$request->filled('dokter_id')) {
            return response()->json([
                'status' => false,
                'message' => 'Dokter wajib dipilih untuk layanan konsultasi atau treatment',
            ], 422);
        }

        $pasienId = $this->resolvePasienId($request);

        if (!$pasienId) {
            return response()->json([
                'status' => false,
                'message' => 'Pasien tidak ditemukan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $tokoId = (int) $request->toko_id;

            $treatmentItems = $this->normalizeTreatmentItems(
                $request->input('treatment.items', [])
            );

            $penjualanItems = $this->normalizePenjualanItems(
                $request->input('penjualan.items', [])
            );

            if ($adaTreatment && count($treatmentItems) === 0) {
                throw new \Exception('Minimal satu item treatment harus dipilih');
            }

            if ($adaPenjualan && count($penjualanItems) === 0) {
                throw new \Exception('Minimal satu produk harus dipilih');
            }

            if ($adaPenjualan) {
                $penjualanItems = $this->ensureAndValidatePenjualanStockFromMaster(
                    $penjualanItems,
                    $tokoId
                );
            }

            $needNurseStation = collect($treatmentItems)->contains(function ($item) {
                return $this->toBool($item['perlu_tindakan_perawat'] ?? false)
                    || ($item['route_treatment'] ?? null) === 'nurse_station';
            });

            $totalTreatment = collect($treatmentItems)->sum('total');
            $totalPenjualan = collect($penjualanItems)->sum('subtotal');

            $totalKonsultasi = $this->resolveTotalKonsultasi(
                $adaKonsultasi,
                $adaTreatment,
                $konsultasiMapping
            );

            $grandTotal = $totalTreatment + $totalPenjualan + $totalKonsultasi;

            $currentTask = $this->determineCurrentTask(
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan
            );

            $needPembayaran = $adaTreatment || $adaPenjualan || $totalKonsultasi > 0;

            $registrasiTable = (new RegistrasiKunjungan())->getTable();

            $registrasiPayload = [
                'kode_registrasi' => $this->generateKodeRegistrasi(
                    $request->toko_id,
                    $request->tanggal
                ),
                'toko_id' => $request->toko_id,
                'pasien_id' => $pasienId,
                'tanggal_kunjungan' => $request->tanggal,
                'registered_at' => now(),
                'catatan_registrasi' => $request->input('catatan_registrasi'),
                'dokter_awal_id' => $request->input('dokter_id'),
                'perawat_awal_id' => $request->input('perawat_id'),
                'channel_konsultasi' => $channelKonsultasi,
                'is_treatment' => $adaTreatment ? 1 : 0,
                'is_penjualan' => $adaPenjualan ? 1 : 0,
                'perlu_tindakan_perawat' => $needNurseStation ? 2 : ($adaTreatment ? 1 : 0),
                'current_task' => $currentTask,
                'status' => RegistrasiKunjungan::STATUS_AKTIF,
                'total_treatment' => $totalTreatment,
                'total_penjualan' => $totalPenjualan,
                'grand_total' => $grandTotal,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ];

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'is_konsultasi', $adaKonsultasi ? 1 : 0);

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_source_code', $konsultasiMapping?->source_code);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_source_name', $konsultasiMapping?->source_name);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_mapping_id', $konsultasiMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'konsultasi_accurate_mapping_id', $konsultasiMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'kode_accurate_konsultasi', $konsultasiMapping?->kode_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'nama_accurate_konsultasi', $konsultasiMapping?->nama_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'total_konsultasi', $totalKonsultasi);
            $this->putIfColumn(
                $registrasiPayload,
                $registrasiTable,
                'rule_biaya_konsultasi',
                $this->resolveRuleBiayaKonsultasi($adaKonsultasi, $adaTreatment, $totalKonsultasi)
            );
            $this->putIfColumn(
                $registrasiPayload,
                $registrasiTable,
                'catatan_biaya_konsultasi',
                $this->resolveCatatanBiayaKonsultasi($adaKonsultasi, $adaTreatment, $totalKonsultasi, $konsultasiMapping)
            );

            $this->putIfColumn($registrasiPayload, $registrasiTable, 'is_pembelian_online', $isPembelianOnline ? 1 : 0);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_source_code', $pembelianOnlineMapping?->source_code);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_source_name', $pembelianOnlineMapping?->source_name);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'pembelian_online_mapping_id', $pembelianOnlineMapping?->id);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'kode_accurate_pembelian_online', $pembelianOnlineMapping?->kode_accurate);
            $this->putIfColumn($registrasiPayload, $registrasiTable, 'nama_accurate_pembelian_online', $pembelianOnlineMapping?->nama_accurate);

            $registrasi = RegistrasiKunjungan::create($registrasiPayload);

            $tasks = $this->createTasks(
                $registrasi,
                $adaKonsultasi,
                $adaTreatment,
                $adaPenjualan,
                $needNurseStation,
                $needPembayaran,
                $request
            );

            $konsultasi = null;

            if ($adaKonsultasi) {
                $konsultasi = $this->createKonsultasiIntake(
                    $registrasi,
                    $request,
                    $channelKonsultasi
                );
            }

            if ($konsultasi && $channelKonsultasi === RegistrasiKunjungan::CHANNEL_ONLINE) {
                $this->storeKonsultasiFotos($request, $registrasi, $konsultasi);
            }

            if ($adaTreatment) {
                $this->createTreatmentDetails($registrasi, $treatmentItems, $tasks);
            }

            $createdPenjualanDetails = [];

            if ($adaPenjualan) {
                $createdPenjualanDetails = $this->createPenjualanDetails(
                    $registrasi,
                    $penjualanItems,
                    $tasks
                );

                $this->reservePenjualanStock($registrasi, $createdPenjualanDetails);
            }

            if (
                $adaKonsultasi
                && (int) $channelKonsultasi === RegistrasiKunjungan::CHANNEL_ONLINE
            ) {
                $this->saveKonsultasiOnlineIntake($request, $registrasi);
            }

            DB::commit();

            $registrasi->load([
                'pasien',
                'dokterAwal',
                'perawatAwal',
                'tasks',
                'konsultasiIntake',
                'treatmentDetails',
                'penjualanDetails',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Registrasi berhasil disimpan',
                'data' => $registrasi,
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyimpan registrasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function ensureAndValidatePenjualanStockFromMaster(array $items, int $tokoId): array
    {
        return collect($items)->map(function ($item) use ($tokoId) {
            $produkTokoId = (int) ($item['produk_toko_id'] ?? 0);
            $produkId = (int) ($item['produk_id'] ?? $item['obat_id'] ?? 0);
            $qty = (float) ($item['jumlah'] ?? $item['qty'] ?? 0);

            if ($qty <= 0) {
                throw new \Exception('Jumlah produk harus lebih dari 0.');
            }

            $master = MasterProdukToko::query()
                ->with(['produk'])
                ->where('toko_id', $tokoId)
                ->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                })
                ->when($produkTokoId > 0, function ($q) use ($produkTokoId) {
                    $q->where('id', $produkTokoId);
                })
                ->when($produkTokoId <= 0 && $produkId > 0, function ($q) use ($produkId) {
                    $q->where('produk_id', $produkId);
                })
                ->first();

            if (!$master || !$master->produk) {
                throw new \Exception("Produk toko tidak ditemukan untuk produk ID {$produkId}.");
            }

            $requestStockId = (int) ($item['stock_produk_toko_id'] ?? 0);
            $requestTempatId = (int) ($item['tempat_produk_id'] ?? 0);
            $defaultTempatId = (int) ($master->produk->tempat_produk_id ?? 0);

            $stockQuery = StockProdukToko::query()
                ->where('produk_toko_id', $master->id)
                ->where('produk_id', $master->produk_id)
                ->where('toko_id', $tokoId)
                ->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });

            $stock = null;

            if ($requestStockId > 0) {
                $stock = (clone $stockQuery)
                    ->where('id', $requestStockId)
                    ->first();
            }

            if (!$stock && $requestTempatId > 0) {
                $stock = (clone $stockQuery)
                    ->where('tempat_produk_id', $requestTempatId)
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$stock && $defaultTempatId > 0) {
                $stock = (clone $stockQuery)
                    ->where('tempat_produk_id', $defaultTempatId)
                    ->orderByDesc('id')
                    ->first();
            }

            if (!$stock) {
                $stock = (clone $stockQuery)
                    ->orderByDesc('id')
                    ->first();
            }

            if ($stock) {
                $stokAkhir = (float) ($stock->stok_akhir ?? 0);
                $stokReserved = (float) ($stock->stok_reserved ?? 0);
                $stokTersedia = max($stokAkhir - $stokReserved, 0);

                if ($stokTersedia < $qty) {
                    throw new \Exception(
                        "Stok tidak cukup untuk produk ID {$master->produk_id}. Stok tersedia: {$stokTersedia}, diminta: {$qty}."
                    );
                }

                $item['produk_toko_id'] = (int) $master->id;
                $item['produk_id'] = (int) $master->produk_id;
                $item['obat_id'] = (int) $master->produk_id;
                $item['tempat_produk_id'] = (int) $stock->tempat_produk_id;
                $item['stock_produk_toko_id'] = (int) $stock->id;

                $item['nama_produk'] = $item['nama_produk'] ?? ($master->produk->nama ?? '');
                $item['produk_nama'] = $item['produk_nama'] ?? ($master->produk->nama ?? '');

                $item['harga'] = (float) ($item['harga'] ?? $master->harga_jual ?? 0);
                $item['jumlah'] = $qty;
                $item['qty'] = $qty;
                $item['subtotal'] = (float) ($item['subtotal'] ?? ($item['harga'] * $qty));

                return $item;
            }

            $stokTersedia = (float) ($master->stok_awal ?? 0);

            if ($stokTersedia < $qty) {
                throw new \Exception(
                    "Stok tidak cukup untuk produk ID {$master->produk_id}. Stok tersedia: {$stokTersedia}, diminta: {$qty}."
                );
            }

            $item['produk_toko_id'] = (int) $master->id;
            $item['produk_id'] = (int) $master->produk_id;
            $item['obat_id'] = (int) $master->produk_id;
            $item['tempat_produk_id'] = $requestTempatId ?: ($defaultTempatId ?: 1);
            $item['stock_produk_toko_id'] = null;

            $item['nama_produk'] = $item['nama_produk'] ?? ($master->produk->nama ?? '');
            $item['produk_nama'] = $item['produk_nama'] ?? ($master->produk->nama ?? '');

            $item['harga'] = (float) ($item['harga'] ?? $master->harga_jual ?? 0);
            $item['jumlah'] = $qty;
            $item['qty'] = $qty;
            $item['subtotal'] = (float) ($item['subtotal'] ?? ($item['harga'] * $qty));

            return $item;
        })->values()->all();
    }

    public function antrianDokter(Request $request)
    {
        $query = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'pasien:id,no_rm,nama,no_hp',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
                'tasks' => function ($q) {
                    $q->orderBy('task_order');
                },
                'tasks.assignedKaryawan:id,nama',
            ])
            ->active()
            ->where('status', RegistrasiKunjungan::STATUS_AKTIF)
            ->where('current_task', RegistrasiKunjungan::TASK_KONSULTASI)
            ->where(function ($q) {
                $q->whereIn('channel_konsultasi', [
                    RegistrasiKunjungan::CHANNEL_OFFLINE,
                    RegistrasiKunjungan::CHANNEL_ONLINE,
                ])
                    ->orWhere('is_treatment', 1);
            });

        if ($request->filled('toko_id')) {
            $query->where('toko_id', $request->toko_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal_kunjungan', $request->tanggal);
        }

        if ($request->filled('status')) {
            $taskStatus = $this->mapQueueStatusToTaskStatus($request->status);

            if ($taskStatus !== null) {
                $query->whereHas('tasks', function ($q) use ($taskStatus) {
                    $q->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
                        ->where('status', $taskStatus);
                });
            }
        }

        if ($request->filled('channel')) {
            if ($request->channel === 'offline') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_OFFLINE);
            }

            if ($request->channel === 'online') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_ONLINE);
            }

            if ($request->channel === 'tanpa_konsultasi') {
                $query->where('channel_konsultasi', RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI);
            }
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
                    });
            });
        }

        $rows = $query
            ->orderBy('registered_at')
            ->orderBy('id')
            ->paginate($request->get('per_page', 15));

        $rows->getCollection()->transform(function ($row) {
            return $this->decorateAntrianDokterRow($row);
        });

        return response()->json([
            'status' => true,
            'message' => 'Data antrian dokter berhasil diambil',
            'data' => $rows,
        ]);
    }

    public function startTask($taskId)
    {
        $task = RegistrasiTask::with('registrasi')->findOrFail($taskId);

        if ((int) $task->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Task tidak dalam status menunggu',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_PROSES,
                'started_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $task->registrasi()->update([
                'current_task' => $task->task_type,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Task berhasil diproses',
                'data' => $task->fresh('registrasi'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal memproses task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function startCurrentTask($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ((int) $registrasi->status !== RegistrasiKunjungan::STATUS_AKTIF) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak aktif',
            ], 422);
        }

        $task = $registrasi->tasks()
            ->where('task_type', $registrasi->current_task)
            ->where('status', RegistrasiTask::STATUS_MENUNGGU)
            ->orderBy('task_order')
            ->first();

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task aktif tidak ditemukan atau sudah diproses',
            ], 422);
        }

        return $this->startTask($task->id);
    }

    public function finishTask($taskId)
    {
        $task = RegistrasiTask::with('registrasi.tasks')->findOrFail($taskId);

        if (!in_array((int) $task->status, [
            RegistrasiTask::STATUS_MENUNGGU,
            RegistrasiTask::STATUS_PROSES,
        ], true)) {
            return response()->json([
                'status' => false,
                'message' => 'Task tidak bisa diselesaikan',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $task->update([
                'status' => RegistrasiTask::STATUS_SELESAI,
                'finished_at' => now(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi = $task->registrasi;

            $nextTask = $registrasi->tasks()
                ->where('status', RegistrasiTask::STATUS_MENUNGGU)
                ->orderBy('task_order')
                ->first();

            if ($nextTask) {
                $registrasi->update([
                    'current_task' => $nextTask->task_type,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            } else {
                $registrasi->update([
                    'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                    'status' => RegistrasiKunjungan::STATUS_SELESAI,
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Task berhasil diselesaikan',
                'data' => $registrasi->fresh(['tasks']),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menyelesaikan task',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function finishCurrentTask($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        $task = $registrasi->tasks()
            ->where('task_type', $registrasi->current_task)
            ->whereIn('status', [
                RegistrasiTask::STATUS_MENUNGGU,
                RegistrasiTask::STATUS_PROSES,
            ])
            ->orderBy('task_order')
            ->first();

        if (!$task) {
            return response()->json([
                'status' => false,
                'message' => 'Task aktif tidak ditemukan',
            ], 422);
        }

        return $this->finishTask($task->id);
    }

    public function cancel($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ($this->hasStartedTask($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Registrasi tidak bisa dibatalkan karena pasien sudah mulai dilayani',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->releasePenjualanReserve($registrasi, 'Release stok karena registrasi dibatalkan');

            $registrasi->update([
                'status' => RegistrasiKunjungan::STATUS_BATAL,
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->tasks()->update([
                'status' => RegistrasiTask::STATUS_BATAL,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Registrasi berhasil dibatalkan',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal membatalkan registrasi',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroyAntrianDokter($id)
    {
        $registrasi = RegistrasiKunjungan::query()
            ->with('tasks')
            ->active()
            ->findOrFail($id);

        if ((int) $registrasi->current_task !== RegistrasiKunjungan::TASK_KONSULTASI) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena sudah berpindah proses',
            ], 422);
        }

        $doctorTask = $this->getDoctorTask($registrasi);

        if (!$doctorTask) {
            return response()->json([
                'status' => false,
                'message' => 'Task dokter tidak ditemukan',
            ], 422);
        }

        if ((int) $doctorTask->status !== RegistrasiTask::STATUS_MENUNGGU) {
            return response()->json([
                'status' => false,
                'message' => 'Antrian tidak bisa dihapus karena pasien sudah mulai dilayani',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->releasePenjualanReserve($registrasi, 'Release stok karena registrasi dibatalkan');

            $registrasi->update([
                'status' => RegistrasiKunjungan::STATUS_BATAL,
                'current_task' => RegistrasiKunjungan::TASK_DRAFT,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            $registrasi->tasks()->update([
                'status' => RegistrasiTask::STATUS_BATAL,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Antrian dokter berhasil dihapus',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal menghapus antrian dokter',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deleteAntrianDokter($id)
    {
        return $this->destroyAntrianDokter($id);
    }

    public function uploadBuktiChatKonsultasiOnline(Request $request, $id)
    {
        if (!Schema::hasColumn('registrasi_kunjungan', 'bukti_chat_konsultasi_online')) {
            return response()->json([
                'status' => false,
                'message' => 'Kolom bukti_chat_konsultasi_online belum tersedia di tabel registrasi_kunjungan',
            ], 500);
        }

        $validator = Validator::make($request->all(), [
            'bukti_chat_konsultasi_online' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'bukti_chat' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'file' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors(),
            ], 422);
        }

        $file = $request->file('bukti_chat_konsultasi_online')
            ?: $request->file('bukti_chat')
            ?: $request->file('file');

        if (!$file) {
            return response()->json([
                'status' => false,
                'message' => 'File bukti chat wajib diupload',
            ], 422);
        }

        $registrasi = RegistrasiKunjungan::query()
            ->active()
            ->findOrFail($id);

        if (!$this->isRegistrasiKonsultasiOnline($registrasi)) {
            return response()->json([
                'status' => false,
                'message' => 'Upload bukti chat hanya untuk layanan konsultasi online',
            ], 422);
        }

        if ((int) $registrasi->status !== RegistrasiKunjungan::STATUS_SELESAI) {
            return response()->json([
                'status' => false,
                'message' => 'Bukti chat hanya bisa diupload setelah proses konsultasi online selesai',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $oldPath = $registrasi->bukti_chat_konsultasi_online;

            $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'file');
            $filename = 'bukti-chat-' . $registrasi->id . '-' . now()->format('YmdHis') . '.' . $extension;

            $path = $file->storeAs(
                'registrasi/konsultasi-online/' . $registrasi->id,
                $filename,
                'public'
            );

            $registrasi->update([
                'bukti_chat_konsultasi_online' => $path,
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]);

            if (
                $oldPath &&
                !str_starts_with((string) $oldPath, 'http://') &&
                !str_starts_with((string) $oldPath, 'https://') &&
                Storage::disk('public')->exists($oldPath)
            ) {
                Storage::disk('public')->delete($oldPath);
            }

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Bukti chat konsultasi online berhasil diupload',
                'data' => [
                    'id' => $registrasi->id,
                    'kode_registrasi' => $registrasi->kode_registrasi,
                    'bukti_chat_konsultasi_online' => $path,
                    'bukti_chat_konsultasi_online_url' => $this->storagePublicUrl($path),
                    'is_upload_bukti_chat_konsultasi_online' => 1,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Gagal upload bukti chat konsultasi online',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function riwayatKonsultasiPasien(Request $request, $pasien)
    {
        $limit = (int) $request->get('limit', 10);
        $limit = max(1, min($limit, 50));

        $pasienRow = Pasien::query()
            ->where(function ($query) {
                $query->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->findOrFail($pasien);

        $registrasiRows = RegistrasiKunjungan::query()
            ->with([
                'toko:id,nama_toko',
                'dokterAwal:id,nama',
                'perawatAwal:id,nama',
            ])
            ->active()
            ->where('pasien_id', $pasienRow->id)
            ->where(function ($query) {
                $query->whereIn('channel_konsultasi', [1, 2])
                    ->orWhereNotNull('konsultasi_source_code')
                    ->orWhereExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('registrasi_konsultasi_intake')
                            ->whereColumn(
                                'registrasi_konsultasi_intake.registrasi_id',
                                'registrasi_kunjungan.id'
                            );
                    })
                    ->orWhereExists(function ($subQuery) {
                        $subQuery->select(DB::raw(1))
                            ->from('registrasi_dokter_soap')
                            ->whereColumn(
                                'registrasi_dokter_soap.registrasi_id',
                                'registrasi_kunjungan.id'
                            )
                            ->where(function ($statusQuery) {
                                $statusQuery->where('registrasi_dokter_soap.status', '<>', 9)
                                    ->orWhereNull('registrasi_dokter_soap.status');
                            });
                    });
            })
            ->orderByDesc('tanggal_kunjungan')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $registrasiIds = $registrasiRows->pluck('id')->filter()->values();

        if ($registrasiIds->isEmpty()) {
            return response()->json([
                'status' => true,
                'message' => 'Riwayat konsultasi pasien berhasil diambil',
                'data' => [],
            ]);
        }

        $getGroupedRows = function (string $table) use ($registrasiIds) {
            if (!Schema::hasTable($table)) {
                return collect();
            }

            $query = DB::table($table)
                ->whereIn('registrasi_id', $registrasiIds);

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($subQuery) use ($table) {
                    $subQuery->where($table . '.is_delete', 0)
                        ->orWhereNull($table . '.is_delete');
                });
            }

            if (Schema::hasColumn($table, 'status')) {
                $query->where(function ($subQuery) use ($table) {
                    $subQuery->where($table . '.status', '<>', 9)
                        ->orWhereNull($table . '.status');
                });
            }

            return $query
                ->orderBy('id')
                ->get()
                ->groupBy('registrasi_id');
        };

        $intakeRows = $getGroupedRows('registrasi_konsultasi_intake')
            ->map(function ($rows) {
                return $rows->last();
            });

        $soapRows = $getGroupedRows('registrasi_dokter_soap')
            ->map(function ($rows) {
                return $rows->last();
            });

        $soapIds = $soapRows->pluck('id')->filter()->values();

        $soapSubjectives = collect();
        if ($soapIds->isNotEmpty() && Schema::hasTable('registrasi_dokter_soap_subjective')) {
            $soapSubjectives = DB::table('registrasi_dokter_soap_subjective')
                ->whereIn('soap_id', $soapIds)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('soap_id');
        }

        $soapDiagnosas = collect();
        if ($soapIds->isNotEmpty() && Schema::hasTable('registrasi_dokter_soap_diagnosa')) {
            $soapDiagnosas = DB::table('registrasi_dokter_soap_diagnosa')
                ->whereIn('soap_id', $soapIds)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->groupBy('soap_id');
        }

        $treatmentRows = $getGroupedRows('registrasi_treatment_detail');
        $penjualanRows = $getGroupedRows('registrasi_penjualan_detail');
        $resepRows = $getGroupedRows('registrasi_dokter_resep_detail');

        $soapDoctorIds = $soapRows
            ->pluck('dokter_id')
            ->filter()
            ->unique()
            ->values();

        $soapDoctorNames = collect();
        if ($soapDoctorIds->isNotEmpty() && Schema::hasTable('master_karyawan')) {
            $soapDoctorNames = DB::table('master_karyawan')
                ->whereIn('id', $soapDoctorIds)
                ->pluck('nama', 'id');
        }

        $treatmentKaryawanIds = $treatmentRows
            ->flatMap(function ($rows) {
                return $rows;
            })
            ->pluck('source_karyawan_id')
            ->filter()
            ->unique()
            ->values();

        $treatmentKaryawanNames = collect();
        if ($treatmentKaryawanIds->isNotEmpty() && Schema::hasTable('master_karyawan')) {
            $treatmentKaryawanNames = DB::table('master_karyawan')
                ->whereIn('id', $treatmentKaryawanIds)
                ->pluck('nama', 'id');
        }

        $formatQty = function ($qty) {
            $qty = (float) $qty;

            if ($qty <= 1) {
                return '';
            }

            return ' x ' . rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        };

        $smallLine = function ($value) {
            $value = trim((string) $value);

            if ($value === '') {
                return '';
            }

            return '<br><small>' . nl2br(e($value)) . '</small>';
        };

        $pushCatatan = function (array &$rows, string $label, $value) {
            $value = trim((string) $value);

            if ($value === '') {
                return;
            }

            $rows[] = '<div><strong>' . e($label) . ':</strong> ' . nl2br(e($value)) . '</div>';
        };

        $data = $registrasiRows->map(function ($row) use (
            $intakeRows,
            $soapRows,
            $soapSubjectives,
            $soapDiagnosas,
            $treatmentRows,
            $penjualanRows,
            $resepRows,
            $soapDoctorNames,
            $treatmentKaryawanNames,
            $formatQty,
            $smallLine,
            $pushCatatan
        ) {
            $intake = $intakeRows->get($row->id);
            $soap = $soapRows->get($row->id);

            $tindakanHtml = collect($treatmentRows->get($row->id, collect()))
                ->map(function ($item) use ($formatQty, $smallLine, $treatmentKaryawanNames) {
                    $nama = trim((string) ($item->nama_treatment ?? ''));

                    if ($nama === '') {
                        return null;
                    }

                    $html = '<div>' . e($nama . $formatQty($item->jumlah ?? 1));

                    $perawatNama = !empty($item->source_karyawan_id)
                        ? ($treatmentKaryawanNames[$item->source_karyawan_id] ?? null)
                        : null;

                    if ($perawatNama) {
                        $html .= $smallLine('Perawat: ' . $perawatNama);
                    } elseif (
                        (int) ($item->perlu_tindakan_perawat ?? 0) === 1 ||
                        strtolower((string) ($item->route_treatment ?? '')) === 'nurse_station'
                    ) {
                        $html .= $smallLine('Perawat: Perlu tindakan perawat');
                    }

                    if (!empty($item->catatan)) {
                        $html .= $smallLine('Catatan: ' . $item->catatan);
                    }

                    $html .= '</div>';

                    return $html;
                })
                ->filter()
                ->implode('');

            $obatHtmlRows = [];

            foreach (collect($penjualanRows->get($row->id, collect())) as $item) {
                $nama = trim((string) ($item->nama_produk ?? ''));

                if ($nama === '') {
                    continue;
                }

                $usageParts = array_filter([
                    $item->frekuensi_penggunaan ?? null,
                    $item->waktu_penggunaan ?? null,
                    $item->instruksi_pemakaian ?? null,
                ]);

                $html = '<div>' . e($nama . $formatQty($item->jumlah ?? 1));

                if (!empty($usageParts)) {
                    $html .= $smallLine(implode(' - ', $usageParts));
                }

                $html .= '</div>';

                $obatHtmlRows[] = $html;
            }

            foreach (collect($resepRows->get($row->id, collect())) as $item) {
                $nama = trim((string) ($item->nama_produk ?? ''));

                if ($nama === '') {
                    continue;
                }

                $usageParts = array_filter([
                    $item->frekuensi ?? null,
                    $item->waktu_pakai ?? null,
                    $item->penggunaan ?? null,
                ]);

                $html = '<div>' . e($nama . $formatQty($item->jumlah ?? 1));

                if (!empty($usageParts)) {
                    $html .= $smallLine(implode(' - ', $usageParts));
                }

                $html .= '</div>';

                $obatHtmlRows[] = $html;
            }

            $catatanRows = [];

            if ($intake) {
                $pushCatatan($catatanRows, 'Keluhan', $intake->keluhan_utama ?? $intake->keluhan_awal ?? null);
                $pushCatatan($catatanRows, 'Alergi', $intake->alergi ?? null);
                $pushCatatan($catatanRows, 'Produk sebelumnya', $intake->produk_obat_sebelumnya ?? null);
                $pushCatatan($catatanRows, 'Catatan CS', $intake->catatan_cs ?? null);
                $pushCatatan($catatanRows, 'Catatan awal', $intake->catatan_awal ?? null);
            }

            if ($soap) {
                $subjectiveText = collect($soapSubjectives->get($soap->id, collect()))
                    ->map(function ($item) {
                        return trim((string) ($item->subjective_text ?? ''));
                    })
                    ->filter()
                    ->implode(', ');

                $diagnosaText = collect($soapDiagnosas->get($soap->id, collect()))
                    ->map(function ($item) {
                        return trim((string) ($item->diagnosa_text ?? ''));
                    })
                    ->filter()
                    ->implode(', ');

                $pushCatatan($catatanRows, 'Subjective', $subjectiveText);
                $pushCatatan($catatanRows, 'Subjective lainnya', $soap->subjective_lainnya ?? null);
                $pushCatatan($catatanRows, 'Objective', $soap->objective ?? null);
                $pushCatatan($catatanRows, 'Diagnosa', $diagnosaText);
                $pushCatatan($catatanRows, 'Assessment lainnya', $soap->assessment_lainnya ?? null);
                $pushCatatan($catatanRows, 'Plan', $soap->plan ?? null);

                if (!empty($soap->next_konsultasi_date)) {
                    $pushCatatan(
                        $catatanRows,
                        'Next konsultasi',
                        Carbon::parse($soap->next_konsultasi_date)->format('d/m/Y')
                    );
                }
            }

            $dokterNama = optional($row->dokterAwal)->nama;

            if (!$dokterNama && $soap && !empty($soap->dokter_id)) {
                $dokterNama = $soapDoctorNames[$soap->dokter_id] ?? null;
            }

            if (!$dokterNama && $intake) {
                $dokterNama = $intake->request_dokter_nama ?? null;
            }

            $tanggal = Carbon::parse($row->tanggal_kunjungan);

            return [
                'id' => $row->id,
                'registrasi_id' => $row->id,
                'kode_registrasi' => $row->kode_registrasi,
                'tgl' => $tanggal->format('d/m/Y'),
                'tanggal' => $tanggal->format('Y-m-d'),
                'dokter' => $dokterNama ?: '-',
                'tindakan_html' => $tindakanHtml ?: '-',
                'obat_html' => implode('', $obatHtmlRows) ?: '-',
                'catatan_html' => implode('', $catatanRows) ?: '-',
                'lokasi' => optional($row->toko)->nama_toko ?: '-',
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Riwayat konsultasi pasien berhasil diambil',
            'data' => $data,
        ]);
    }

    private function isRegistrasiKonsultasiOnline(RegistrasiKunjungan $registrasi): bool
    {
        $sourceCode = strtoupper((string) $registrasi->konsultasi_source_code);

        return (int) $registrasi->channel_konsultasi === RegistrasiKunjungan::CHANNEL_ONLINE
            || str_contains($sourceCode, 'ONLINE');
    }

    private function storagePublicUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    private function resolvePasienId(Request $request)
    {
        if ($request->filled('pasien_id')) {
            return Pasien::query()
                ->where('id', $request->pasien_id)
                ->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                })
                ->value('id');
        }

        if (!$request->filled('pasien_new_id')) {
            return null;
        }

        $model = new Pasien();
        $table = $model->getTable();
        $value = $request->pasien_new_id;

        $query = Pasien::query()
            ->where(function ($q) {
                $q->where('is_delete', 0)->orWhereNull('is_delete');
            });

        $query->where(function ($q) use ($table, $value) {
            if (Schema::hasColumn($table, 'new_id')) {
                $q->orWhere('new_id', $value);
            }

            if (Schema::hasColumn($table, 'pasien_new_id')) {
                $q->orWhere('pasien_new_id', $value);
            }

            if (Schema::hasColumn($table, 'no_rm')) {
                $q->orWhere('no_rm', $value);
            }

            $q->orWhere('id', $value);
        });

        return $query->value('id');
    }

    private function createTasks(
        RegistrasiKunjungan $registrasi,
        bool $adaKonsultasi,
        bool $adaTreatment,
        bool $adaPenjualan,
        bool $needNurseStation,
        bool $needPembayaran,
        Request $request
    ) {
        $tasks = [];
        $order = 1;

        if ($adaKonsultasi || $adaTreatment) {
            $tasks['dokter'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_KONSULTASI,
                'assigned_karyawan_id' => $request->input('dokter_id'),
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($needPembayaran) {
            $tasks['pembayaran'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_PEMBAYARAN,
                'assigned_karyawan_id' => null,
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        if ($adaTreatment) {
            $tasks['perawat'] = RegistrasiTask::create([
                'registrasi_id' => $registrasi->id,
                'task_type' => RegistrasiTask::TYPE_TINDAKAN_PERAWAT,
                'assigned_karyawan_id' => $request->input('perawat_id'),
                'task_order' => $order++,
                'status' => RegistrasiTask::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }

        return $tasks;
    }

    private function createKonsultasiIntake(
        RegistrasiKunjungan $registrasi,
        Request $request,
        string $channel
    ) {
        $payload = [
            'registrasi_id' => $registrasi->id,
            'jenis_konsultasi' => $channel === RegistrasiKunjungan::CHANNEL_ONLINE
                ? RegistrasiKonsultasiIntake::JENIS_ONLINE
                : RegistrasiKonsultasiIntake::JENIS_OFFLINE,
            'status' => RegistrasiKonsultasiIntake::STATUS_MENUNGGU,
            'created_by' => $this->username(),
            'created_at' => now(),
        ];

        if ($channel === RegistrasiKunjungan::CHANNEL_OFFLINE) {
            $offline = $request->input('konsultasi_offline', []);

            $payload['keluhan_awal'] = $offline['keluhan_awal'] ?? null;
            $payload['catatan_awal'] = $offline['catatan'] ?? null;
        }

        if ($channel === RegistrasiKunjungan::CHANNEL_ONLINE) {
            $online = $request->input('konsultasi_online', []);

            $payload['alergi'] = $online['alergi'] ?? null;
            $payload['keluhan_utama'] = $online['keluhan'] ?? null;
            $payload['produk_obat_sebelumnya'] = $online['produk_sebelumnya'] ?? null;
            $payload['sedang_hamil'] = $this->yesNoToTinyint($online['sedang_hamil'] ?? null);
            $payload['sedang_menyusui'] = $this->yesNoToTinyint($online['sedang_menyusui'] ?? null);

            $requestDokter = $online['request_dokter'] ?? null;

            if (is_numeric($requestDokter)) {
                $payload['request_dokter_id'] = $requestDokter;
            } elseif ($requestDokter && Schema::hasColumn('registrasi_konsultasi_intake', 'request_dokter_nama')) {
                $payload['request_dokter_nama'] = $requestDokter;
            }
        }

        return RegistrasiKonsultasiIntake::create($payload);
    }

    private function storeKonsultasiFotos(
        Request $request,
        RegistrasiKunjungan $registrasi,
        RegistrasiKonsultasiIntake $konsultasi
    ) {
        $files = [
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_kiri',
                    'bukti_foto_kiri',
                    'foto_kiri',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_KIRI,
            ],
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_depan',
                    'bukti_foto_depan',
                    'foto_depan',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_DEPAN,
            ],
            [
                'keys' => [
                    'konsultasi_online.bukti_foto_kanan',
                    'bukti_foto_kanan',
                    'foto_kanan',
                ],
                'posisi' => RegistrasiKonsultasiFoto::POSISI_KANAN,
            ],
        ];

        foreach ($files as $item) {
            $file = null;

            foreach ($item['keys'] as $key) {
                if ($request->hasFile($key)) {
                    $file = $request->file($key);
                    break;
                }
            }

            if (!$file) {
                continue;
            }

            $path = $file->store(
                "registrasi/konsultasi/{$registrasi->id}",
                'public'
            );

            RegistrasiKonsultasiFoto::create([
                'registrasi_id' => $registrasi->id,
                'konsultasi_id' => $konsultasi->id,
                'posisi_foto' => $item['posisi'],
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function createTreatmentDetails(
        RegistrasiKunjungan $registrasi,
        array $items,
        array $tasks
    ) {
        $task = $tasks['dokter'] ?? $tasks['perawat'] ?? null;

        foreach ($items as $item) {
            $ids = $this->resolveTreatmentIds($item, $registrasi->toko_id);

            if (empty($ids['treatment_toko_id']) || empty($ids['treatment_id'])) {
                throw new \Exception(
                    'Mapping treatment toko tidak ditemukan untuk treatment_id: ' .
                    ($item['treatment_id'] ?? $item['tindakan_id'] ?? $item['id'] ?? '-')
                );
            }

            RegistrasiTreatmentDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => RegistrasiTreatmentDetail::SOURCE_FO,
                'source_task_id' => $task?->id,
                'source_karyawan_id' => null,
                'is_deposit_claim' => $this->toBool($item['is_deposit_claim'] ?? false) ? 1 : 0,
                'deposit_treatment_id' => $item['deposit_treatment_id'] ?? null,
                'deposit_claim_id' => $item['deposit_claim_id'] ?? null,
                'treatment_toko_id' => $ids['treatment_toko_id'],
                'treatment_id' => $ids['treatment_id'],
                'nama_treatment' => $item['nama_treatment']
                    ?? $item['treatment_nama']
                    ?? $item['nama_tindakan']
                    ?? null,
                'harga' => $item['harga'],
                'jumlah' => $item['jumlah'],
                'total' => $item['total'],
                'catatan' => $item['catatan'] ?? null,
                'status' => RegistrasiTreatmentDetail::STATUS_BELUM_DIKERJAKAN,
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);
        }
    }

    private function createPenjualanDetails(
        RegistrasiKunjungan $registrasi,
        array $items,
        array $tasks
    ) {
        $task = $tasks['penjualan'] ?? $tasks['pembayaran'] ?? null;
        $createdDetails = [];

        foreach ($items as $item) {
            $ids = $this->resolveProdukIds($item, $registrasi->toko_id);

            if (empty($ids['produk_toko_id']) || empty($ids['produk_id'])) {
                throw new \Exception(
                    'Mapping produk toko tidak ditemukan untuk produk_id: ' .
                    ($item['produk_id'] ?? $item['obat_id'] ?? $item['id'] ?? '-')
                );
            }

            $resolvedTempatProdukId = $this->resolveTempatProdukId($item, $ids);

            $detail = RegistrasiPenjualanDetail::create([
                'registrasi_id' => $registrasi->id,
                'source_type' => RegistrasiPenjualanDetail::SOURCE_FO,
                'tempat_produk_id' => $resolvedTempatProdukId,
                'source_task_id' => $task?->id,
                'source_resep_id' => $item['source_resep_id'] ?? null,
                'source_karyawan_id' => null,
                'produk_toko_id' => $ids['produk_toko_id'],
                'produk_id' => $ids['produk_id'],
                'nama_produk' => $item['nama_produk'] ?? $item['produk_nama'] ?? null,
                'harga' => $item['harga'],
                'jumlah' => $item['jumlah'],
                'diskon_tipe' => $item['diskon_tipe'] ?? $item['diskon_type'] ?? 0,
                'diskon_nilai' => $item['diskon_nilai'] ?? $item['diskon_value'] ?? 0,
                'diskon_referral' => $item['diskon_referral'] ?? 0,
                'subtotal' => $item['subtotal'],
                'is_delete' => 0,
                'created_by' => $this->username(),
                'created_at' => now(),
            ]);

            $createdDetails[] = [
                'detail_id' => $detail->id,
                'produk_toko_id' => $ids['produk_toko_id'],
                'produk_id' => $ids['produk_id'],
                'tempat_produk_id' => $resolvedTempatProdukId,
                'jumlah' => (float) $item['jumlah'],
                'harga' => (float) $item['harga'],
            ];
        }

        return $createdDetails;
    }

    private function reservePenjualanStock(RegistrasiKunjungan $registrasi, array $createdPenjualanDetails)
    {
        if (empty($createdPenjualanDetails)) {
            return;
        }

        $items = [];

        foreach ($createdPenjualanDetails as $detail) {
            $items[] = [
                'produk_toko_id' => $detail['produk_toko_id'],
                'produk_id' => $detail['produk_id'],
                'tempat_produk_id' => $detail['tempat_produk_id'],
                'qty' => $detail['jumlah'],
                'harga_jual' => $detail['harga'],
                'source_detail_id' => $detail['detail_id'],
            ];
        }

        $this->stockTransactionService->reserveProduk($items, [
            'toko_id' => $registrasi->toko_id,
            'source_type' => 'REGISTRASI_LAYANAN',
            'source_table' => 'registrasi_kunjungan',
            'source_id' => $registrasi->id,
            'kode_reservasi' => $registrasi->kode_registrasi,
            'tanggal' => now(),
            'expired_at' => Carbon::parse($registrasi->registered_at ?? now())->addHours(6),
            'keterangan' => 'Reserve stok dari registrasi layanan',
            'created_by' => $this->username(),
        ]);
    }

    private function releasePenjualanReserve(RegistrasiKunjungan $registrasi, string $keterangan)
    {
        $this->stockTransactionService->releaseReservasiBySource(
            'REGISTRASI_LAYANAN',
            $registrasi->id,
            [
                'kode_mutasi' => $registrasi->kode_registrasi,
                'tanggal' => now(),
                'source_table' => 'registrasi_kunjungan',
                'keterangan' => $keterangan,
                'created_by' => $this->username(),
            ]
        );
    }

    private function resolveTempatProdukId(array $item, array $ids)
    {
        $explicitTempatId = $item['tempat_produk_id'] ?? null;

        if (!empty($explicitTempatId)) {
            return (int) $explicitTempatId;
        }

        $produkId = $ids['produk_id'] ?? null;

        if (!empty($ids['produk_toko_id'])) {
            $produkTokoTable = (new MasterProdukToko())->getTable();

            if (Schema::hasTable($produkTokoTable)) {
                $produkToko = DB::table($produkTokoTable)
                    ->where('id', $ids['produk_toko_id'])
                    ->first();

                if ($produkToko) {
                    if (
                        Schema::hasColumn($produkTokoTable, 'tempat_produk_id')
                        && !empty($produkToko->tempat_produk_id)
                    ) {
                        return (int) $produkToko->tempat_produk_id;
                    }

                    $produkId = $produkToko->produk_id
                        ?? $produkToko->master_produk_id
                        ?? $produkToko->obat_id
                        ?? $produkId;
                }
            }
        }

        $tempatProdukId = $this->getTempatProdukIdFromMasterProduk($produkId);

        if ($tempatProdukId) {
            return $tempatProdukId;
        }

        return 1;
    }

    private function getTempatProdukIdFromMasterProduk($produkId)
    {
        if (empty($produkId) || !Schema::hasTable('master_produk')) {
            return null;
        }

        $produk = DB::table('master_produk')
            ->where('id', $produkId)
            ->first();

        if (!$produk) {
            return null;
        }

        if (
            Schema::hasColumn('master_produk', 'tempat_produk_id')
            && !empty($produk->tempat_produk_id)
        ) {
            return (int) $produk->tempat_produk_id;
        }

        return null;
    }

    private function normalizeTreatmentItems(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $treatmentTokoId = $item['treatment_toko_id']
                ?? $item['master_treatment_toko_id']
                ?? $item['tindakan_toko_id']
                ?? $item['toko_treatment_id']
                ?? null;

            $treatmentId = $item['treatment_id']
                ?? $item['tindakan_id']
                ?? $item['master_treatment_id']
                ?? null;

            $candidateId = $item['id'] ?? null;

            if (!$treatmentId && !$treatmentTokoId && !$candidateId) {
                continue;
            }

            $harga = $this->toNumber($item['harga'] ?? 0);
            $jumlah = $this->toNumber($item['jumlah'] ?? 1);

            if ($jumlah <= 0) {
                $jumlah = 1;
            }

            $total = $this->toNumber($item['total'] ?? ($harga * $jumlah));

            $result[] = [
                ...$item,
                'treatment_toko_id' => $treatmentTokoId,
                'treatment_id' => $treatmentId,
                'candidate_id' => $candidateId,
                'harga' => $harga,
                'jumlah' => $jumlah,
                'total' => $total,
            ];
        }

        return $result;
    }

    private function normalizePenjualanItems(array $items)
    {
        $result = [];

        foreach ($items as $item) {
            $produkTokoId = $item['produk_toko_id']
                ?? $item['master_produk_toko_id']
                ?? $item['obat_toko_id']
                ?? $item['toko_produk_id']
                ?? null;

            $produkId = $item['produk_id']
                ?? $item['obat_id']
                ?? $item['master_produk_id']
                ?? null;

            $candidateId = $item['id'] ?? null;

            if (!$produkId && !$produkTokoId && !$candidateId) {
                continue;
            }

            $harga = $this->toNumber($item['harga'] ?? 0);
            $jumlah = $this->toNumber($item['jumlah'] ?? 1);

            if ($jumlah <= 0) {
                $jumlah = 1;
            }

            $subtotal = $this->toNumber($item['subtotal'] ?? ($harga * $jumlah));

            $result[] = [
                ...$item,
                'produk_id' => $produkId,
                'obat_id' => $produkId,
                'produk_toko_id' => $produkTokoId,
                'candidate_id' => $candidateId,

                'tempat_produk_id' => !empty($item['tempat_produk_id'])
                    ? (int) $item['tempat_produk_id']
                    : null,

                'stock_produk_toko_id' => !empty($item['stock_produk_toko_id'])
                    ? (int) $item['stock_produk_toko_id']
                    : null,

                'harga' => $harga,
                'jumlah' => $jumlah,
                'subtotal' => $subtotal,
            ];
        }

        return $result;
    }
    private function resolveTreatmentIds(array $item, $tokoId = null)
    {
        $treatmentTokoId = $item['treatment_toko_id']
            ?? $item['master_treatment_toko_id']
            ?? $item['tindakan_toko_id']
            ?? $item['toko_treatment_id']
            ?? null;

        $treatmentId = $item['treatment_id']
            ?? $item['tindakan_id']
            ?? $item['master_treatment_id']
            ?? null;

        $candidateId = $item['candidate_id'] ?? $item['id'] ?? null;

        $table = (new MasterTreatmentToko())->getTable();

        $row = null;

        if ($treatmentTokoId) {
            $row = MasterTreatmentToko::query()->find($treatmentTokoId);
        }

        if (!$row && !$treatmentId && $candidateId) {
            $row = MasterTreatmentToko::query()->find($candidateId);
        }

        if (!$row && $tokoId && ($treatmentId || $candidateId)) {
            $searchId = $treatmentId ?: $candidateId;

            $query = MasterTreatmentToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });
            }

            $query->where(function ($q) use ($table, $searchId) {
                if (Schema::hasColumn($table, 'treatment_id')) {
                    $q->orWhere('treatment_id', $searchId);
                }

                if (Schema::hasColumn($table, 'master_treatment_id')) {
                    $q->orWhere('master_treatment_id', $searchId);
                }
            });

            $row = $query->first();
        }

        return [
            'treatment_toko_id' => $row?->id ?? $treatmentTokoId,
            'treatment_id' => $row?->treatment_id
                ?? $row?->master_treatment_id
                ?? $treatmentId
                ?? null,
        ];
    }

    private function resolveProdukIds(array $item, $tokoId = null)
    {
        $produkTokoId = $item['produk_toko_id']
            ?? $item['master_produk_toko_id']
            ?? $item['obat_toko_id']
            ?? $item['toko_produk_id']
            ?? null;

        $produkId = $item['produk_id']
            ?? $item['obat_id']
            ?? $item['master_produk_id']
            ?? null;

        $candidateId = $item['candidate_id'] ?? $item['id'] ?? null;

        $table = (new MasterProdukToko())->getTable();

        $row = null;

        if ($produkTokoId) {
            $row = MasterProdukToko::query()->find($produkTokoId);
        }

        if (!$row && !$produkId && $candidateId) {
            $row = MasterProdukToko::query()->find($candidateId);
        }

        if (!$row && $tokoId && ($produkId || $candidateId)) {
            $searchId = $produkId ?: $candidateId;

            $query = MasterProdukToko::query();

            if (Schema::hasColumn($table, 'toko_id')) {
                $query->where('toko_id', $tokoId);
            }

            if (Schema::hasColumn($table, 'is_delete')) {
                $query->where(function ($q) {
                    $q->where('is_delete', 0)->orWhereNull('is_delete');
                });
            }

            $query->where(function ($q) use ($table, $searchId) {
                if (Schema::hasColumn($table, 'produk_id')) {
                    $q->orWhere('produk_id', $searchId);
                }

                if (Schema::hasColumn($table, 'master_produk_id')) {
                    $q->orWhere('master_produk_id', $searchId);
                }

                if (Schema::hasColumn($table, 'obat_id')) {
                    $q->orWhere('obat_id', $searchId);
                }
            });

            $row = $query->first();
        }

        return [
            'produk_toko_id' => $row?->id ?? $produkTokoId,
            'produk_id' => $row?->produk_id
                ?? $row?->master_produk_id
                ?? $row?->obat_id
                ?? $produkId
                ?? null,
        ];
    }

    private function determineCurrentTask(
        bool $adaKonsultasi,
        bool $adaTreatment,
        bool $adaPenjualan
    ) {
        if ($adaKonsultasi || $adaTreatment) {
            return RegistrasiKunjungan::TASK_KONSULTASI;
        }

        if ($adaPenjualan) {
            return RegistrasiKunjungan::TASK_PEMBAYARAN;
        }

        return RegistrasiKunjungan::TASK_DRAFT;
    }

    private function mapChannelKonsultasi(bool $adaKonsultasi, ?string $channel)
    {
        if (!$adaKonsultasi) {
            return RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI;
        }

        return $channel === 'online'
            ? RegistrasiKunjungan::CHANNEL_ONLINE
            : RegistrasiKunjungan::CHANNEL_OFFLINE;
    }

    private function decorateAntrianDokterRow(RegistrasiKunjungan $row)
    {
        $doctorTask = $this->getDoctorTask($row);

        $row->setAttribute('registrasi_id', $row->id);
        $row->setAttribute('nomor_antrian', $row->kode_registrasi);
        $row->setAttribute('nama_pasien', $row->pasien?->nama);
        $row->setAttribute('no_rm', $row->pasien?->no_rm);
        $row->setAttribute('no_hp', $row->pasien?->no_hp);
        $row->setAttribute('nama_dokter', $row->dokterAwal?->nama);
        $row->setAttribute('nama_perawat', $row->perawatAwal?->nama);
        $row->setAttribute('waktu_kunjungan', $this->formatTimeValue($row->registered_at));
        $row->setAttribute('ada_konsultasi', (int) $row->channel_konsultasi > 0);
        $row->setAttribute('ada_treatment', (int) $row->is_treatment === 1);
        $row->setAttribute('ada_penjualan', (int) $row->is_penjualan === 1);
        $row->setAttribute('status_antrian_dokter', $this->mapTaskStatusToQueueStatus($doctorTask?->status));
        $row->setAttribute('can_delete_antrian', $doctorTask && (int) $doctorTask->status === RegistrasiTask::STATUS_MENUNGGU);

        return $row;
    }

    private function getDoctorTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks
                ->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
                ->sortBy('task_order')
                ->first();
        }

        return $registrasi->tasks()
            ->where('task_type', RegistrasiTask::TYPE_KONSULTASI)
            ->orderBy('task_order')
            ->first();
    }

    private function hasStartedTask(RegistrasiKunjungan $registrasi)
    {
        if ($registrasi->relationLoaded('tasks')) {
            return $registrasi->tasks->contains(function ($task) {
                return in_array((int) $task->status, [
                    RegistrasiTask::STATUS_PROSES,
                    RegistrasiTask::STATUS_SELESAI,
                ], true);
            });
        }

        return $registrasi->tasks()
            ->whereIn('status', [
                RegistrasiTask::STATUS_PROSES,
                RegistrasiTask::STATUS_SELESAI,
            ])
            ->exists();
    }

    private function mapQueueStatusToTaskStatus($status)
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'menunggu' => RegistrasiTask::STATUS_MENUNGGU,
            'dipanggil', 'proses', 'diproses' => RegistrasiTask::STATUS_PROSES,
            'selesai' => RegistrasiTask::STATUS_SELESAI,
            'batal' => RegistrasiTask::STATUS_BATAL,
            default => null,
        };
    }

    private function mapTaskStatusToQueueStatus($status)
    {
        return match ((int) $status) {
            RegistrasiTask::STATUS_MENUNGGU => 'menunggu',
            RegistrasiTask::STATUS_PROSES => 'proses',
            RegistrasiTask::STATUS_SELESAI => 'selesai',
            RegistrasiTask::STATUS_BATAL => 'batal',
            default => 'menunggu',
        };
    }

    private function putIfColumn(array &$payload, string $table, string $column, $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $payload[$column] = $value;
        }
    }

    private function resolveChannelKonsultasiFromMapping(
        bool $adaKonsultasi,
        ?string $channelKonsultasi,
        ?string $sourceCode
    ): string {
        if (!$adaKonsultasi) {
            return RegistrasiKunjungan::CHANNEL_TIDAK_KONSULTASI;
        }

        if ($channelKonsultasi === 'online') {
            return RegistrasiKunjungan::CHANNEL_ONLINE;
        }

        if ($channelKonsultasi === 'offline') {
            return RegistrasiKunjungan::CHANNEL_OFFLINE;
        }

        if (str_contains(strtoupper((string) $sourceCode), 'ONLINE')) {
            return RegistrasiKunjungan::CHANNEL_ONLINE;
        }

        return RegistrasiKunjungan::CHANNEL_OFFLINE;
    }

    private function resolveTotalKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        ?MasterAccurateItemMapping $mapping
    ): float {
        if (!$adaKonsultasi || !$mapping) {
            return 0;
        }

        if ($adaTreatment) {
            return 0;
        }

        if (!$this->toBool($mapping->is_billable ?? false)) {
            return 0;
        }

        return (float) ($mapping->default_harga ?? 0);
    }

    private function resolveRuleBiayaKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        float $totalKonsultasi
    ): int {
        if (!$adaKonsultasi) {
            return 0;
        }

        if ($adaTreatment) {
            return 2;
        }

        if ($totalKonsultasi > 0) {
            return 1;
        }

        return 3;
    }

    private function resolveCatatanBiayaKonsultasi(
        bool $adaKonsultasi,
        bool $adaTreatment,
        float $totalKonsultasi,
        ?MasterAccurateItemMapping $mapping
    ): ?string {
        if (!$adaKonsultasi) {
            return null;
        }

        if ($adaTreatment) {
            return 'Biaya konsultasi Rp 0 karena konsultasi digabung dengan treatment.';
        }

        if ($totalKonsultasi > 0) {
            return 'Biaya konsultasi mengikuti master mapping: '
                . ($mapping?->source_name ?? $mapping?->source_code ?? '-');
        }

        return 'Biaya konsultasi Rp 0 sesuai master mapping.';
    }

    private function generateKodeRegistrasi($tokoId, $tanggal)
    {
        $date = Carbon::parse($tanggal)->format('Ymd');

        $count = RegistrasiKunjungan::query()
            ->where('toko_id', $tokoId)
            ->whereDate('tanggal_kunjungan', $tanggal)
            ->count() + 1;

        return 'REG-' .
            $date . '-' .
            str_pad($tokoId, 2, '0', STR_PAD_LEFT) . '-' .
            str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    private function formatTimeValue($value)
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

    private function yesNoToTinyint($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, ['ya', 'yes', 'y', '1', 'true'], true)) {
            return 1;
        }

        if (in_array($value, ['tidak', 'no', 'n', '0', 'false'], true)) {
            return 0;
        }

        return null;
    }

    private function toBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'ya'], true);
    }

    private function toNumber($value)
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if ($value === null || $value === '') {
            return 0;
        }

        return (float) preg_replace('/[^0-9.-]/', '', (string) $value);
    }

    private function username()
    {
        return auth()->user()->username
            ?? auth()->user()->name
            ?? 'system';
    }

    private function saveKonsultasiOnlineIntake(Request $request, RegistrasiKunjungan $registrasi): void
    {
        $payload = $request->input('konsultasi_online', []);

        [$requestDokterId, $requestDokterNama] = $this->resolveRequestDokterOnline(
            $payload['request_dokter'] ?? null
        );

        $intake = RegistrasiKonsultasiIntake::query()->updateOrCreate(
            [
                'registrasi_id' => $registrasi->id,
            ],
            [
                'request_dokter_id' => $requestDokterId,
                'request_dokter_nama' => $requestDokterNama,
                'alergi' => $this->emptyToNull($payload['alergi'] ?? null),
                'keluhan_utama' => $this->emptyToNull($payload['keluhan'] ?? null),
                'produk_obat_sebelumnya' => $this->emptyToNull($payload['produk_sebelumnya'] ?? null),
                'sedang_hamil' => $this->toNullableTinyBool($payload['sedang_hamil'] ?? null),
                'sedang_menyusui' => $this->toNullableTinyBool($payload['sedang_menyusui'] ?? null),
                'jenis_konsultasi' => RegistrasiKonsultasiIntake::JENIS_ONLINE,
                'keluhan_awal' => $this->emptyToNull($payload['keluhan'] ?? null),
                'catatan_awal' => $this->emptyToNull($request->input('catatan_registrasi')),
                'status' => RegistrasiKonsultasiIntake::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
                'updated_at' => now(),
            ]
        );

        $this->saveKonsultasiOnlineFotos($request, $registrasi, $intake);
    }

    private function saveKonsultasiOnlineFotos(
        Request $request,
        RegistrasiKunjungan $registrasi,
        RegistrasiKonsultasiIntake $intake
    ): void {
        $photoFields = [
            RegistrasiKonsultasiFoto::POSISI_KIRI => 'bukti_foto_kiri',
            RegistrasiKonsultasiFoto::POSISI_DEPAN => 'bukti_foto_depan',
            RegistrasiKonsultasiFoto::POSISI_KANAN => 'bukti_foto_kanan',
        ];

        foreach ($photoFields as $position => $field) {
            $file = $this->getKonsultasiOnlineFile($request, $field);

            if (!$file) {
                continue;
            }

            $oldPhoto = RegistrasiKonsultasiFoto::query()
                ->where('registrasi_id', $registrasi->id)
                ->where('posisi_foto', $position)
                ->first();

            if (
                $oldPhoto
                && $oldPhoto->file_path
                && Storage::disk('public')->exists($oldPhoto->file_path)
            ) {
                Storage::disk('public')->delete($oldPhoto->file_path);
            }

            $path = $file->store(
                "registrasi/konsultasi-online/{$registrasi->id}",
                'public'
            );

            RegistrasiKonsultasiFoto::query()->updateOrCreate(
                [
                    'registrasi_id' => $registrasi->id,
                    'posisi_foto' => $position,
                ],
                [
                    'konsultasi_id' => $intake->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_url' => Storage::url($path),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'is_delete' => 0,
                    'created_by' => $this->username(),
                    'updated_by' => $this->username(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    private function getKonsultasiOnlineFile(Request $request, string $field)
    {
        $dotKey = "konsultasi_online.{$field}";

        if ($request->hasFile($dotKey)) {
            return $request->file($dotKey);
        }

        $files = $request->file('konsultasi_online');

        if (is_array($files) && isset($files[$field])) {
            return $files[$field];
        }

        return null;
    }

    private function resolveRequestDokterOnline($value): array
    {
        if ($value === null || $value === '') {
            return [null, null];
        }

        if (is_array($value)) {
            $id = $value['id'] ?? $value['value'] ?? null;
            $name = $value['nama'] ?? $value['label'] ?? $value['text'] ?? null;

            if ($id && !$name) {
                $name = DB::table('master_karyawan')
                    ->where('id', $id)
                    ->value('nama');
            }

            return [$id ? (int) $id : null, $name ?: null];
        }

        if (is_numeric($value)) {
            $name = DB::table('master_karyawan')
                ->where('id', (int) $value)
                ->value('nama');

            return [(int) $value, $name ?: null];
        }

        return [null, trim((string) $value)];
    }

    private function toNullableTinyBool($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'ya', 'yes'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'tidak', 'no'], true)) {
            return 0;
        }

        return null;
    }

    private function emptyToNull($value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}