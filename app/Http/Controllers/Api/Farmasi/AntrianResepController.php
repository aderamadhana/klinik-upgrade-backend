<?php

namespace App\Http\Controllers\Api\Farmasi;

use App\Http\Controllers\Controller;
use App\Models\Farmasi\FarmasiAntrianResep;
use App\Models\Master\MasterKaryawan;
use App\Models\Pembayaran\PembayaranInvoice;
use App\Models\Pembayaran\PembayaranInvoiceItem;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AntrianResepController extends Controller
{
    private const JABATAN_APOTEKER_IDS = [7, 9];

    public function index(Request $request)
    {
        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'toko_id' => ['nullable', 'integer', 'min:1'],
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:semua,menunggu,diproses,selesai,0,1,2'],
            'search' => ['nullable', 'string', 'max:150'],
        ]);

        $baseQuery = $this->buildQueueQuery($request);
        $summary = $this->buildSummary($baseQuery);

        $query = clone $baseQuery;
        $status = $this->normalizeStatus($request->get('status', 'semua'));

        if ($status !== null) {
            $this->applyStatusFilter($query, $status);
        }

        if ($status === FarmasiAntrianResep::STATUS_SELESAI) {
            $query
                ->orderByDesc('farmasi_queue.finished_at')
                ->orderByRaw('COALESCE(pembayaran_invoice.tanggal_lunas, pembayaran_invoice.tanggal_invoice) DESC')
                ->orderByDesc('pembayaran_invoice.id');
        } else {
            $query
                ->orderByRaw(
                    'COALESCE(farmasi_queue.started_at, pembayaran_invoice.tanggal_lunas, pembayaran_invoice.tanggal_invoice) ASC'
                )
                ->orderBy('pembayaran_invoice.id');
        }

        $rows = $query->paginate((int) $request->get('per_page', 10));
        $items = $rows->getCollection()
            ->map(fn (PembayaranInvoice $invoice) => $this->formatQueueRow($invoice))
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data antrian resep berhasil diambil',
            'rows' => $items,
            'total' => $rows->total(),
            'per_page' => $rows->perPage(),
            'current_page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(),
            'from' => $rows->firstItem(),
            'to' => $rows->lastItem(),
            'summary' => $summary,
        ]);
    }

    public function petugas(Request $request)
    {
        $validated = $request->validate([
            'toko_id' => ['required', 'integer', 'min:1'],
        ]);

        $tokoId = (int) $validated['toko_id'];
        $today = now()->toDateString();

        $petugas = MasterKaryawan::query()
            ->active()
            ->select(['id', 'jabatan_id', 'kode', 'nama'])
            ->with('jabatan:id,kode_jabatan,nama_jabatan')
            ->whereIn('jabatan_id', self::JABATAN_APOTEKER_IDS)
            ->whereHas('penempatan', function ($query) use ($tokoId, $today) {
                $query
                    ->active()
                    ->where('toko_id', $tokoId)
                    ->where(function ($dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('tanggal_mulai')
                            ->orWhereDate('tanggal_mulai', '<=', $today);
                    })
                    ->where(function ($dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('tanggal_selesai')
                            ->orWhereDate('tanggal_selesai', '>=', $today);
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('nama')
            ->get()
            ->map(function (MasterKaryawan $karyawan) {
                return [
                    'id' => (int) $karyawan->id,
                    'kode' => $karyawan->kode,
                    'nama' => $karyawan->nama,
                    'jabatan_id' => (int) $karyawan->jabatan_id,
                    'jabatan_kode' => $karyawan->jabatan?->kode_jabatan,
                    'jabatan_nama' => $karyawan->jabatan?->nama_jabatan,
                    'label' => trim(sprintf(
                        '%s - %s',
                        $karyawan->nama,
                        $karyawan->jabatan?->nama_jabatan ?: 'Apoteker'
                    )),
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Data apoteker berhasil diambil',
            'data' => $petugas,
        ]);
    }

    public function show(Request $request, $id)
    {
        $request->validate([
            'toko_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $this->buildQueueQuery($request);
        $invoice = $query->where('pembayaran_invoice.id', $id)->firstOrFail();

        return response()->json([
            'status' => true,
            'message' => 'Detail antrian resep berhasil diambil',
            'data' => $this->formatQueueRow($invoice),
        ]);
    }

    public function cetak(Request $request, $id)
    {
        $request->validate([
            'toko_id' => ['nullable', 'integer', 'min:1'],
            'auto_print' => ['nullable', 'boolean'],
        ]);

        $invoice = $this->buildQueueQuery($request)
            ->where('pembayaran_invoice.id', $id)
            ->firstOrFail();

        $farmasiStatus = (int) (
            $invoice->farmasi_status ?? FarmasiAntrianResep::STATUS_MENUNGGU
        );

        if ($farmasiStatus !== FarmasiAntrianResep::STATUS_SELESAI) {
            throw ValidationException::withMessages([
                'status' => ['Resep hanya dapat dicetak setelah proses farmasi selesai.'],
            ]);
        }

        $resep = $this->formatQueueRow($invoice);
        $soap = $invoice->registrasi?->dokterSoap;

        return response()
            ->view('farmasi.antrian-resep.cetak-resep', [
                'resep' => $resep,
                'soap' => [
                    'plan' => $soap?->plan,
                    'next_konsultasi_date' => optional(
                        $soap?->next_konsultasi_date
                    )->format('Y-m-d'),
                ],
                'dokterNama' => $soap?->dokter?->nama
                    ?: data_get($resep, 'konsultasi.dokter')
                    ?: 'Belum ditentukan',
                'qrDataUri' => $this->buildRecipeQrDataUri($invoice),
                'autoPrint' => $request->boolean('auto_print', true),
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function start(Request $request, $id)
    {
        $request->validate([
            'toko_id' => ['nullable', 'integer', 'min:1'],
            'apoteker_id' => ['required', 'integer', 'min:1'],
        ]);

        $invoice = DB::transaction(function () use ($request, $id) {
            $invoice = PembayaranInvoice::query()
                ->active()
                ->lockForUpdate()
                ->findOrFail($id);

            $this->assertInvoiceEligible($invoice, $request);
            $petugas = $this->resolvePetugas(
                (int) $request->input('apoteker_id'),
                (int) $invoice->toko_id
            );
            $queue = $this->lockOrCreateQueue($invoice);

            if ((int) $queue->status === FarmasiAntrianResep::STATUS_SELESAI) {
                throw ValidationException::withMessages([
                    'status' => ['Resep sudah selesai dan tidak dapat diproses ulang.'],
                ]);
            }

            if ((int) $queue->status === FarmasiAntrianResep::STATUS_BATAL) {
                throw ValidationException::withMessages([
                    'status' => ['Antrian resep sudah dibatalkan.'],
                ]);
            }

            if (
                (int) $queue->status === FarmasiAntrianResep::STATUS_PROSES
                && $queue->petugas_karyawan_id
                && (int) $queue->petugas_karyawan_id !== (int) $petugas->id
            ) {
                throw ValidationException::withMessages([
                    'apoteker_id' => [sprintf(
                        'Resep sudah diproses oleh %s.',
                        $queue->petugas_nama_snapshot ?: 'petugas farmasi lain'
                    )],
                ]);
            }

            $queue->update([
                'status' => FarmasiAntrianResep::STATUS_PROSES,
                'petugas_karyawan_id' => $petugas->id,
                'petugas_nama_snapshot' => $petugas->nama,
                'petugas_jabatan_snapshot' => $petugas->jabatan?->nama_jabatan ?: 'Apoteker',
                'started_at' => $queue->started_at ?: now(),
                'finished_at' => null,
                'updated_by' => $this->username(),
            ]);

            return $this->reloadInvoice($invoice->id);
        });

        return response()->json([
            'status' => true,
            'message' => 'Resep berhasil mulai diproses',
            'data' => $this->formatQueueRow($invoice),
        ]);
    }

    public function finish(Request $request, $id)
    {
        $request->validate([
            'toko_id' => ['nullable', 'integer', 'min:1'],
            'apoteker_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $invoice = DB::transaction(function () use ($request, $id) {
            $invoice = PembayaranInvoice::query()
                ->active()
                ->lockForUpdate()
                ->findOrFail($id);

            $this->assertInvoiceEligible($invoice, $request);
            $queue = FarmasiAntrianResep::query()
                ->where('pembayaran_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if (!$queue) {
                throw ValidationException::withMessages([
                    'status' => ['Resep harus mulai diproses terlebih dahulu.'],
                ]);
            }

            if ((int) $queue->status === FarmasiAntrianResep::STATUS_SELESAI) {
                return $this->reloadInvoice($invoice->id);
            }

            if ((int) $queue->status !== FarmasiAntrianResep::STATUS_PROSES) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya resep berstatus diproses yang dapat diselesaikan.'],
                ]);
            }

            if (!$queue->petugas_karyawan_id) {
                $apotekerId = (int) $request->input('apoteker_id');

                if ($apotekerId <= 0) {
                    throw ValidationException::withMessages([
                        'apoteker_id' => ['Pilih apoteker atau asisten apoteker yang memproses resep.'],
                    ]);
                }

                $petugas = $this->resolvePetugas($apotekerId, (int) $invoice->toko_id);
                $queue->fill([
                    'petugas_karyawan_id' => $petugas->id,
                    'petugas_nama_snapshot' => $petugas->nama,
                    'petugas_jabatan_snapshot' => $petugas->jabatan?->nama_jabatan ?: 'Apoteker',
                ]);
            }

            $queue->update([
                'status' => FarmasiAntrianResep::STATUS_SELESAI,
                'finished_at' => now(),
                'updated_by' => $this->username(),
            ]);

            return $this->reloadInvoice($invoice->id);
        });

        return response()->json([
            'status' => true,
            'message' => 'Resep berhasil diselesaikan',
            'data' => $this->formatQueueRow($invoice),
        ]);
    }

    private function buildQueueQuery(Request $request): Builder
    {
        $query = PembayaranInvoice::query()
            ->select('pembayaran_invoice.*')
            ->leftJoin(
                'farmasi_antrian_resep as farmasi_queue',
                'farmasi_queue.pembayaran_id',
                '=',
                'pembayaran_invoice.id'
            )
            ->addSelect([
                DB::raw('COALESCE(farmasi_queue.status, 0) as farmasi_status'),
                'farmasi_queue.id as farmasi_antrian_id',
                'farmasi_queue.started_at as farmasi_started_at',
                'farmasi_queue.finished_at as farmasi_finished_at',
                'farmasi_queue.petugas_karyawan_id as farmasi_petugas_id',
                'farmasi_queue.petugas_nama_snapshot as farmasi_petugas_nama',
                'farmasi_queue.petugas_jabatan_snapshot as farmasi_petugas_jabatan',
            ])
            ->with($this->queueRelations())
            ->active()
            ->where('pembayaran_invoice.status', PembayaranInvoice::STATUS_LUNAS)
            ->where(function (Builder $productQuery) {
                // Sumber utama adalah snapshot produk final di invoice.
                $productQuery->whereHas('items', function ($itemQuery) {
                    $itemQuery
                        ->active()
                        ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK);
                });

                // Fallback untuk invoice lama/stale yang sudah lunas dan tampil sebagai
                // Penjualan di daftar pembayaran, tetapi snapshot item_type=3 belum terbentuk.
                // Dibatasi ke invoice umum agar invoice deposit hasil split tidak ikut terbaca.
                $productQuery->orWhere(function (Builder $fallbackQuery) {
                    $fallbackQuery
                        ->where(function (Builder $suffixQuery) {
                            $suffixQuery
                                ->whereNull('pembayaran_invoice.invoice_suffix')
                                ->orWhere('pembayaran_invoice.invoice_suffix', '')
                                ->orWhere('pembayaran_invoice.invoice_suffix', 'U');
                        })
                        ->whereHas('registrasi.penjualanDetails', function ($detailQuery) {
                            $this->applyActiveRegistrationProductFilter($detailQuery);
                        });
                });
            });

        $tokoId = $this->requestedStoreId($request);

        if ($tokoId) {
            $query->where('pembayaran_invoice.toko_id', $tokoId);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate(
                DB::raw('COALESCE(pembayaran_invoice.tanggal_lunas, pembayaran_invoice.tanggal_invoice)'),
                $request->tanggal
            );
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->search);

            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('pembayaran_invoice.no_invoice', 'like', "%{$search}%")
                    ->orWhere('pembayaran_invoice.kode_registrasi', 'like', "%{$search}%")
                    ->orWhereHas('pasien', function ($patientQuery) use ($search) {
                        $patientQuery
                            ->where('nama', 'like', "%{$search}%")
                            ->orWhere('no_rm', 'like', "%{$search}%")
                            ->orWhere('no_hp', 'like', "%{$search}%")
                            ->orWhere('no_wa', 'like', "%{$search}%");
                    })
                    ->orWhereHas('registrasi.dokterAwal', function ($doctorQuery) use ($search) {
                        $doctorQuery->where('nama', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items', function ($itemQuery) use ($search) {
                        $itemQuery
                            ->active()
                            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
                            ->where('nama_item', 'like', "%{$search}%");
                    })
                    ->orWhereHas('registrasi.penjualanDetails', function ($detailQuery) use ($search) {
                        $this->applyActiveRegistrationProductFilter($detailQuery);
                        $detailQuery->where('nama_produk', 'like', "%{$search}%");
                    })
                    ->orWhere('farmasi_queue.petugas_nama_snapshot', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function queueRelations(): array
    {
        return [
            'pasien:id,no_rm,nama,no_hp,no_wa,alergi_obat',
            'registrasi:id,kode_registrasi,toko_id,pasien_id,tanggal_kunjungan,registered_at,dokter_awal_id,channel_konsultasi,konsultasi_source_code,konsultasi_source_name,is_konsultasi_tambahan_dokter,total_konsultasi,is_penjualan,status,is_delete',
            'registrasi.toko:id,kode_toko,nama_toko,no_telepon,alamat',
            'registrasi.dokterAwal:id,nama',
            'registrasi.dokterSoap:id,registrasi_id,dokter_id,plan,next_konsultasi_date,status',
            'registrasi.dokterSoap.dokter:id,nama',
            'registrasi.penjualanDetails' => function ($detailQuery) {
                $this->applyActiveRegistrationProductFilter($detailQuery);
                $detailQuery->orderBy('id');
            },
            'items' => function ($itemQuery) {
                $itemQuery
                    ->active()
                    ->whereIn('item_type', [
                        PembayaranInvoiceItem::ITEM_KONSULTASI,
                        PembayaranInvoiceItem::ITEM_TREATMENT,
                        PembayaranInvoiceItem::ITEM_PRODUK,
                    ])
                    ->orderBy('item_type')
                    ->orderBy('id');
            },
        ];
    }

    private function buildSummary(Builder $baseQuery): array
    {
        return [
            'menunggu' => $this->countByStatus($baseQuery, FarmasiAntrianResep::STATUS_MENUNGGU),
            'diproses' => $this->countByStatus($baseQuery, FarmasiAntrianResep::STATUS_PROSES),
            'selesai' => $this->countByStatus($baseQuery, FarmasiAntrianResep::STATUS_SELESAI),
        ];
    }

    private function countByStatus(Builder $baseQuery, int $status): int
    {
        $query = clone $baseQuery;
        $this->applyStatusFilter($query, $status);

        return (int) $query
            ->reorder()
            ->distinct()
            ->count('pembayaran_invoice.id');
    }

    private function applyStatusFilter(Builder $query, int $status): void
    {
        if ($status === FarmasiAntrianResep::STATUS_MENUNGGU) {
            $query->where(function ($statusQuery) {
                $statusQuery
                    ->whereNull('farmasi_queue.id')
                    ->orWhere('farmasi_queue.status', FarmasiAntrianResep::STATUS_MENUNGGU);
            });

            return;
        }

        $query->where('farmasi_queue.status', $status);
    }

    private function normalizeStatus($status): ?int
    {
        return match ((string) $status) {
            'semua' => null,
            '1', 'diproses' => FarmasiAntrianResep::STATUS_PROSES,
            '2', 'selesai' => FarmasiAntrianResep::STATUS_SELESAI,
            default => FarmasiAntrianResep::STATUS_MENUNGGU,
        };
    }

    private function assertInvoiceEligible(PembayaranInvoice $invoice, Request $request): void
    {
        if ((int) $invoice->status !== PembayaranInvoice::STATUS_LUNAS) {
            throw ValidationException::withMessages([
                'invoice' => ['Hanya invoice yang sudah lunas yang dapat masuk antrian resep.'],
            ]);
        }

        $tokoId = $this->requestedStoreId($request);

        if ($tokoId && (int) $invoice->toko_id !== $tokoId) {
            throw ValidationException::withMessages([
                'toko_id' => ['Invoice tidak berasal dari cabang yang sedang dipilih.'],
            ]);
        }

        $hasInvoiceProduct = $invoice->items()
            ->active()
            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
            ->exists();

        $hasRegistrationProduct = false;

        if (!$hasInvoiceProduct && $this->isGeneralInvoice($invoice)) {
            $hasRegistrationProduct = $invoice->registrasi()
                ->whereHas('penjualanDetails', function ($detailQuery) {
                    $this->applyActiveRegistrationProductFilter($detailQuery);
                })
                ->exists();
        }

        if (!$hasInvoiceProduct && !$hasRegistrationProduct) {
            throw ValidationException::withMessages([
                'invoice' => ['Invoice lunas tidak memiliki item produk atau obat aktif.'],
            ]);
        }
    }

    private function lockOrCreateQueue(PembayaranInvoice $invoice): FarmasiAntrianResep
    {
        $queue = FarmasiAntrianResep::query()
            ->where('pembayaran_id', $invoice->id)
            ->lockForUpdate()
            ->first();

        if ($queue) {
            return $queue;
        }

        try {
            FarmasiAntrianResep::query()->create([
                'pembayaran_id' => $invoice->id,
                'registrasi_id' => $invoice->registrasi_id,
                'toko_id' => $invoice->toko_id,
                'status' => FarmasiAntrianResep::STATUS_MENUNGGU,
                'created_by' => $this->username(),
                'updated_by' => $this->username(),
            ]);
        } catch (QueryException $exception) {
            $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);

            if ($driverErrorCode !== 1062) {
                throw $exception;
            }
        }

        return FarmasiAntrianResep::query()
            ->where('pembayaran_id', $invoice->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function reloadInvoice(int $invoiceId): PembayaranInvoice
    {
        return PembayaranInvoice::query()
            ->select('pembayaran_invoice.*')
            ->leftJoin(
                'farmasi_antrian_resep as farmasi_queue',
                'farmasi_queue.pembayaran_id',
                '=',
                'pembayaran_invoice.id'
            )
            ->addSelect([
                DB::raw('COALESCE(farmasi_queue.status, 0) as farmasi_status'),
                'farmasi_queue.id as farmasi_antrian_id',
                'farmasi_queue.started_at as farmasi_started_at',
                'farmasi_queue.finished_at as farmasi_finished_at',
                'farmasi_queue.petugas_karyawan_id as farmasi_petugas_id',
                'farmasi_queue.petugas_nama_snapshot as farmasi_petugas_nama',
                'farmasi_queue.petugas_jabatan_snapshot as farmasi_petugas_jabatan',
            ])
            ->with($this->queueRelations())
            ->where('pembayaran_invoice.id', $invoiceId)
            ->firstOrFail();
    }

    private function formatQueueRow(PembayaranInvoice $invoice): array
    {
        $registration = $invoice->registrasi;
        $patient = $invoice->pasien;
        $invoiceTreatmentItems = $invoice->items
            ->where('item_type', PembayaranInvoiceItem::ITEM_TREATMENT)
            ->values();
        $invoiceProductItems = $invoice->items
            ->where('item_type', PembayaranInvoiceItem::ITEM_PRODUK)
            ->values();
        $consultationItem = $invoice->items
            ->firstWhere('item_type', PembayaranInvoiceItem::ITEM_KONSULTASI);
        $hasConsultation = $this->hasConsultation($registration, $consultationItem);
        $status = (int) ($invoice->farmasi_status ?? FarmasiAntrianResep::STATUS_MENUNGGU);
        $registrationProductDetails = collect($registration?->penjualanDetails ?? []);
        $prescriptionUsageById = $this->loadPrescriptionUsageById(
            $registrationProductDetails,
            $invoiceProductItems
        );
        $treatments = $this->formatInvoiceTreatments($invoiceTreatmentItems);
        $products = $this->formatInvoiceProducts(
            $invoiceProductItems,
            $registrationProductDetails,
            $prescriptionUsageById
        );

        if ($products->isEmpty() && $this->isGeneralInvoice($invoice)) {
            $products = $this->formatRegistrationProducts(
                $registrationProductDetails,
                $prescriptionUsageById
            );
        }

        $paidAt = $invoice->tanggal_lunas ?: $invoice->tanggal_invoice;

        return [
            'id' => (int) $invoice->id,
            'farmasi_antrian_id' => $invoice->farmasi_antrian_id
                ? (int) $invoice->farmasi_antrian_id
                : null,
            'no_invoice' => $invoice->no_invoice,
            'kode_registrasi' => $invoice->kode_registrasi ?: $registration?->kode_registrasi,
            'tanggal_invoice' => optional($invoice->tanggal_invoice)->format('Y-m-d H:i:s'),
            'tanggal_lunas' => optional($paidAt)->format('Y-m-d H:i:s'),
            'grand_total' => (float) $invoice->grand_total,
            'toko' => [
                'id' => (int) $invoice->toko_id,
                'kode' => $registration?->toko?->kode_toko,
                'nama' => $registration?->toko?->nama_toko,
                'alamat' => $registration?->toko?->alamat,
                'no_telepon' => $registration?->toko?->no_telepon,
            ],
            'pasien' => [
                'id' => $patient?->id ? (int) $patient->id : null,
                'no_rm' => $patient?->no_rm,
                'nama' => $patient?->nama,
                'no_hp' => $patient?->no_wa ?: $patient?->no_hp,
                'alergi_obat' => $patient?->alergi_obat,
            ],
            'has_konsultasi' => $hasConsultation,
            'konsultasi' => $hasConsultation
                ? [
                    'jenis' => $registration?->jenis_konsultasi_label
                        ?: $consultationItem?->nama_item
                        ?: 'Konsultasi Dokter',
                    'channel' => $registration?->channel_konsultasi_label,
                    'dokter' => $registration?->dokterSoap?->dokter?->nama
                        ?: $registration?->dokterAwal?->nama,
                    'tanggal_kunjungan' => optional($registration?->tanggal_kunjungan)->format('Y-m-d'),
                ]
                : null,
            'treatment' => $treatments->values(),
            'jumlah_treatment' => $treatments->count(),
            'total_qty_treatment' => (float) $treatments->sum('qty'),
            'produk' => $products->values(),
            'jumlah_produk' => $products->count(),
            'total_qty' => (float) $products->sum('qty'),
            'farmasi_status' => $status,
            'farmasi_status_label' => $this->statusLabel($status),
            'petugas' => $invoice->farmasi_petugas_id
                ? [
                    'id' => (int) $invoice->farmasi_petugas_id,
                    'nama' => $invoice->farmasi_petugas_nama,
                    'jabatan' => $invoice->farmasi_petugas_jabatan,
                ]
                : null,
            'started_at' => $invoice->farmasi_started_at,
            'finished_at' => $invoice->farmasi_finished_at,
        ];
    }

    private function formatInvoiceTreatments($items)
    {
        return collect($items)->map(function (PembayaranInvoiceItem $item) {
            return [
                'id' => (int) $item->id,
                'nama' => $item->nama_item,
                'qty' => (float) $item->qty,
                'satuan' => $item->satuan ?: 'Treatment',
                'harga' => (float) $item->harga,
                'subtotal' => (float) $item->subtotal,
                'source_type' => (int) $item->source_type,
                'source_label' => 'Treatment invoice',
                'is_saran_dokter' => (bool) $item->is_saran_dokter,
                'is_deposit_claim' => !empty($item->deposit_treatment_id)
                    || !empty($item->deposit_claim_id),
            ];
        })->values();
    }

    private function formatInvoiceProducts(
        $items,
        $registrationDetails = null,
        $prescriptionUsageById = null
    ) {
        $detailsById = collect($registrationDetails ?? [])
            ->keyBy(fn ($detail) => (int) ($detail->id ?? 0));
        $usageById = collect($prescriptionUsageById ?? []);

        return collect($items)->map(function (PembayaranInvoiceItem $item) use (
            $detailsById,
            $usageById
        ) {
            $sourceDetail = $detailsById->get((int) ($item->source_detail_id ?? 0));
            $sourcePrescriptionId = (int) (
                (int) $item->source_type === PembayaranInvoiceItem::SOURCE_RESEP_DOKTER
                    ? ($item->source_detail_id ?? 0)
                    : (
                        $sourceDetail?->source_resep_detail_id
                        ?: $sourceDetail?->source_resep_id
                        ?: 0
                    )
            );
            $sourceUsage = $sourcePrescriptionId > 0
                ? $usageById->get($sourcePrescriptionId)
                : null;

            return [
                'id' => (int) $item->id,
                'nama' => $item->nama_item,
                'qty' => (float) $item->qty,
                'satuan' => $item->satuan,
                'harga' => (float) $item->harga,
                'subtotal' => (float) $item->subtotal,
                'source_type' => (int) $item->source_type,
                'source_label' => $this->sourceLabel((int) $item->source_type),
                'is_saran_dokter' => (bool) $item->is_saran_dokter,
                'frekuensi' => $item->frekuensi,
                'waktu_pakai' => $item->waktu_pakai,
                'instruksi_pemakaian' => $item->instruksi_pemakaian,
                'aturan_pakai' => $this->formatUsageSchedule(
                    $item->frekuensi,
                    $item->waktu_pakai
                ),
                'cara_penggunaan' => $this->resolveCaraPenggunaan(
                    $item->frekuensi,
                    $item->waktu_pakai,
                    $item->instruksi_pemakaian,
                    $sourceUsage
                ),
            ];
        })->values();
    }

    private function formatRegistrationProducts(
        $details,
        $prescriptionUsageById = null
    ) {
        $usageById = collect($prescriptionUsageById ?? []);

        return collect($details)->map(function ($detail) use ($usageById) {
            $isDoctorPrescription = (int) ($detail->source_type ?? 0) === 2;
            $sourceType = $isDoctorPrescription
                ? PembayaranInvoiceItem::SOURCE_RESEP_DOKTER
                : PembayaranInvoiceItem::SOURCE_REGISTRASI_PENJUALAN;
            $sourcePrescriptionId = (int) (
                $detail->source_resep_detail_id
                ?: $detail->source_resep_id
                ?: 0
            );
            $sourceUsage = $sourcePrescriptionId > 0
                ? $usageById->get($sourcePrescriptionId)
                : null;

            return [
                'id' => 'registrasi-' . (int) $detail->id,
                'nama' => $detail->nama_produk,
                'qty' => (float) $detail->jumlah,
                'satuan' => 'pcs',
                'harga' => (float) $detail->harga,
                'subtotal' => (float) $detail->subtotal,
                'source_type' => $sourceType,
                'source_label' => $isDoctorPrescription
                    ? 'Resep dokter'
                    : 'Penjualan registrasi',
                'is_saran_dokter' => (bool) $detail->is_saran_dokter,
                'frekuensi' => $detail->frekuensi_penggunaan,
                'waktu_pakai' => $detail->waktu_penggunaan,
                'instruksi_pemakaian' => $detail->instruksi_pemakaian,
                'aturan_pakai' => $this->formatUsageSchedule(
                    $detail->frekuensi_penggunaan,
                    $detail->waktu_penggunaan
                ),
                'cara_penggunaan' => $this->resolveCaraPenggunaan(
                    $detail->frekuensi_penggunaan,
                    $detail->waktu_penggunaan,
                    $detail->instruksi_pemakaian,
                    $sourceUsage
                ),
            ];
        })->values();
    }

    private function loadPrescriptionUsageById(
        $registrationDetails,
        $invoiceProductItems = null
    ) {
        $registrationSourceIds = collect($registrationDetails)
            ->flatMap(function ($detail) {
                return [
                    (int) ($detail->source_resep_detail_id ?? 0),
                    (int) ($detail->source_resep_id ?? 0),
                ];
            });
        $invoiceSourceIds = collect($invoiceProductItems ?? [])
            ->filter(function ($item) {
                return (int) ($item->source_type ?? 0)
                    === PembayaranInvoiceItem::SOURCE_RESEP_DOKTER;
            })
            ->map(fn ($item) => (int) ($item->source_detail_id ?? 0));
        $prescriptionDetailIds = $registrationSourceIds
            ->merge($invoiceSourceIds)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($prescriptionDetailIds->isEmpty()) {
            return collect();
        }

        return DB::table('registrasi_dokter_resep_detail')
            ->whereIn('id', $prescriptionDetailIds->all())
            ->where(function ($query) {
                $query->whereNull('is_delete')->orWhere('is_delete', 0);
            })
            ->pluck('penggunaan', 'id');
    }

    private function applyActiveRegistrationProductFilter($query): void
    {
        $query
            ->where('is_delete', 0)
            ->whereIn('status', [0, 1, 2])
            ->where('jumlah', '>', 0);
    }

    private function isGeneralInvoice(PembayaranInvoice $invoice): bool
    {
        $suffix = strtoupper(trim((string) ($invoice->invoice_suffix ?? 'U')));

        return $suffix === '' || $suffix === 'U';
    }

    private function hasConsultation($registration, ?PembayaranInvoiceItem $consultationItem): bool
    {
        if ($consultationItem) {
            return true;
        }

        if (!$registration) {
            return false;
        }

        $channel = strtolower(trim((string) ($registration->channel_konsultasi ?? '')));

        return in_array($channel, ['1', '2', 'offline', 'online', 'sppg', 'spkk'], true)
            || (int) ($registration->is_konsultasi_tambahan_dokter ?? 0) === 1
            || (float) ($registration->total_konsultasi ?? 0) > 0;
    }

    private function resolvePetugas(int $apotekerId, int $tokoId): MasterKaryawan
    {
        $today = now()->toDateString();

        $petugas = MasterKaryawan::query()
            ->active()
            ->with('jabatan:id,kode_jabatan,nama_jabatan')
            ->whereIn('jabatan_id', self::JABATAN_APOTEKER_IDS)
            ->whereHas('penempatan', function ($query) use ($tokoId, $today) {
                $query
                    ->active()
                    ->where('toko_id', $tokoId)
                    ->where(function ($dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('tanggal_mulai')
                            ->orWhereDate('tanggal_mulai', '<=', $today);
                    })
                    ->where(function ($dateQuery) use ($today) {
                        $dateQuery
                            ->whereNull('tanggal_selesai')
                            ->orWhereDate('tanggal_selesai', '>=', $today);
                    });
            })
            ->find($apotekerId);

        if (!$petugas) {
            throw ValidationException::withMessages([
                'apoteker_id' => [
                    'Apoteker yang dipilih tidak aktif atau tidak ditempatkan pada cabang invoice.',
                ],
            ]);
        }

        return $petugas;
    }

    private function formatUsageSchedule(
        ?string $frekuensi,
        ?string $waktuPakai
    ): string {
        $parts = array_values(array_filter([
            trim((string) $frekuensi),
            trim((string) $waktuPakai),
        ], fn ($value) => $value !== ''));

        return count($parts) > 0
            ? implode(' - ', $parts)
            : 'Aturan pakai belum diisi';
    }

    private function resolveCaraPenggunaan(
        ?string $frekuensi,
        ?string $waktuPakai,
        ?string $instruksi,
        ?string $sourceUsage = null
    ): ?string {
        $schedule = $this->formatUsageSchedule($frekuensi, $waktuPakai);
        $candidate = trim((string) ($sourceUsage ?: $instruksi));

        if ($candidate === '') {
            return null;
        }

        if (preg_match('/\(([^()]*)\)\s*$/u', $candidate, $matches)) {
            $insideParentheses = trim((string) ($matches[1] ?? ''));

            if ($insideParentheses !== '') {
                return $insideParentheses;
            }
        }

        $normalize = static function (string $value): string {
            $value = strtolower(trim($value));
            $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?: '';

            return trim(preg_replace('/\s+/u', ' ', $value) ?: '');
        };

        $normalizedSchedule = $schedule === 'Aturan pakai belum diisi'
            ? ''
            : $normalize($schedule);
        $normalizedCandidate = $normalize($candidate);

        if (
            $normalizedSchedule !== ''
            && $normalizedCandidate === $normalizedSchedule
        ) {
            return null;
        }

        if (
            $normalizedSchedule !== ''
            && str_starts_with($normalizedCandidate, $normalizedSchedule)
        ) {
            $remaining = trim(
                substr($candidate, strlen($schedule)),
                " \t\n\r\0\x0B-–—:;,.()[]{}"
            );

            if ($remaining !== '') {
                return $remaining;
            }
        }

        return $candidate;
    }

    private function buildRecipeQrDataUri(PembayaranInvoice $invoice): ?string
    {
        try {
            $payload = implode('|', [
                'RESEP',
                (string) ($invoice->no_invoice ?? '-'),
                (string) ($invoice->kode_registrasi ?? '-'),
                (string) ($invoice->pasien?->no_rm ?? '-'),
                (string) ($invoice->farmasi_finished_at ?? '-'),
            ]);

            $writer = new SvgWriter();
            $qrCode = new QrCode(
                data: $payload,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 150,
                margin: 2,
                roundBlockSizeMode: RoundBlockSizeMode::Margin
            );

            return $writer->write($qrCode)->getDataUri();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function sourceLabel(int $sourceType): string
    {
        return match ($sourceType) {
            PembayaranInvoiceItem::SOURCE_RESEP_DOKTER => 'Resep dokter',
            PembayaranInvoiceItem::SOURCE_REGISTRASI_PENJUALAN => 'Penjualan registrasi',
            PembayaranInvoiceItem::SOURCE_MANUAL => 'Input kasir',
            default => 'Produk / obat',
        };
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            FarmasiAntrianResep::STATUS_PROSES => 'Diproses',
            FarmasiAntrianResep::STATUS_SELESAI => 'Selesai',
            FarmasiAntrianResep::STATUS_BATAL => 'Dibatalkan',
            default => 'Menunggu',
        };
    }

    private function requestedStoreId(Request $request): ?int
    {
        $value = $request->input('toko_id', $request->header('X-Toko-Id'));

        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value) || (int) $value <= 0) {
            throw ValidationException::withMessages([
                'toko_id' => ['Cabang yang dipilih tidak valid.'],
            ]);
        }

        return (int) $value;
    }

    private function username(): string
    {
        $user = auth()->user();

        return (string) ($user?->username ?? $user?->name ?? $user?->id ?? 'system');
    }
}
