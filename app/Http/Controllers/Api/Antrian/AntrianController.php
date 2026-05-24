<?php

namespace App\Http\Controllers\Api\Antrian;

use App\Http\Controllers\Controller;
use App\Models\Antrian\Antrian;
use App\Models\Antrian\AntrianLog;
use App\Models\Antrian\BookingLayanan;
use App\Models\Antrian\MasterAntrianCounter;
use App\Models\Antrian\MasterAntrianKategori;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AntrianController extends Controller
{
    public function kategori(Request $request)
    {
        $tokoId = $request->get('toko_id');

        $data = MasterAntrianKategori::query()
            ->aktif()
            ->globalAtauToko($tokoId)
            ->orderByDesc('is_priority')
            ->orderBy('nama')
            ->get();

        return $this->success($data);
    }

    public function counter(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = MasterAntrianCounter::query()
            ->aktif()
            ->where('toko_id', $request->toko_id)
            ->orderBy('nama')
            ->get();

        return $this->success($data);
    }

    public function ambilNomor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'kategori_id' => ['required', 'integer', 'exists:master_antrian_kategori,id'],
            'source_type' => ['nullable', 'in:walk_in,booking,manual'],
            'source_id' => ['nullable', 'integer'],
            'pasien_id' => ['nullable', 'integer'],
            'appointment_at' => ['nullable', 'date'],
            'priority_level' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $data = DB::transaction(function () use ($request) {
                $tanggal = now()->toDateString();
                $sourceType = $request->source_type ?: Antrian::SOURCE_WALK_IN;

                $kategori = MasterAntrianKategori::query()
                    ->where('id', $request->kategori_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($sourceType === Antrian::SOURCE_BOOKING && $request->source_id) {
                    $existing = Antrian::query()
                        ->where('toko_id', $request->toko_id)
                        ->where('tanggal', $tanggal)
                        ->where('source_type', Antrian::SOURCE_BOOKING)
                        ->where('source_id', $request->source_id)
                        ->where('is_delete', 0)
                        ->whereNotIn('status', [
                            Antrian::STATUS_CANCELLED,
                            Antrian::STATUS_FINISHED,
                        ])
                        ->first();

                    if ($existing) {
                        return $existing->load(['kategori', 'counter']);
                    }
                }

                $lastNumber = Antrian::query()
                    ->where('toko_id', $request->toko_id)
                    ->whereDate('tanggal', $tanggal)
                    ->where('kategori_id', $request->kategori_id)
                    ->where('is_delete', 0)
                    ->max('nomor');

                $nextNumber = ((int) $lastNumber) + 1;
                $kodeNomor = $kategori->kode . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

                $antrian = Antrian::create([
                    'tanggal' => $tanggal,
                    'toko_id' => $request->toko_id,
                    'kategori_id' => $request->kategori_id,
                    'nomor' => $nextNumber,
                    'kode_nomor' => $kodeNomor,
                    'source_type' => $sourceType,
                    'source_id' => $request->source_id,
                    'pasien_id' => $request->pasien_id,
                    'appointment_at' => $request->appointment_at,
                    'checkin_at' => now(),
                    'priority_level' => $request->priority_level ?? (int) $kategori->priority_level,
                    'status' => Antrian::STATUS_WAITING,
                    'notes' => $request->notes,
                    'is_delete' => 0,
                    'created_by' => $this->userId(),
                    'updated_by' => $this->userId(),
                ]);

                $antrian->logs()->create([
                    'toko_id' => $antrian->toko_id,
                    'counter_id' => null,
                    'action' => AntrianLog::ACTION_CREATED,
                    'status_before' => null,
                    'status_after' => Antrian::STATUS_WAITING,
                    'description' => 'Nomor antrian dibuat',
                    'action_by' => $this->userId(),
                    'action_at' => now(),
                ]);

                if ($sourceType === Antrian::SOURCE_BOOKING && $request->source_id) {
                    $booking = BookingLayanan::query()
                        ->where('id', $request->source_id)
                        ->lockForUpdate()
                        ->first();

                    if ($booking) {
                        $booking->status = BookingLayanan::STATUS_IN_QUEUE;
                        $booking->checked_in_at = now();
                        $booking->updated_by = $this->userId();
                        $booking->save();
                    }
                }

                return $antrian->load(['kategori', 'counter']);
            });

            return $this->success($data, 'Nomor antrian berhasil dibuat');
        } catch (QueryException $e) {
            return $this->error('Gagal membuat nomor antrian', $e->getMessage(), 500);
        } catch (\Throwable $e) {
            return $this->error('Gagal membuat nomor antrian', $e->getMessage(), 500);
        }
    }

    public function display(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'tanggal' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $tokoId = $request->toko_id;
        $tanggal = $request->tanggal ?: now()->toDateString();

        $kategori = MasterAntrianKategori::query()
            ->aktif()
            ->globalAtauToko($tokoId)
            ->orderByDesc('is_priority')
            ->orderBy('nama')
            ->get();

        $data = $kategori->map(function ($item) use ($tokoId, $tanggal) {
            $current = Antrian::query()
                ->with(['kategori', 'counter'])
                ->where('toko_id', $tokoId)
                ->whereDate('tanggal', $tanggal)
                ->where('kategori_id', $item->id)
                ->where('is_delete', 0)
                ->whereIn('status', [
                    Antrian::STATUS_CALLED,
                    Antrian::STATUS_SERVING,
                ])
                ->orderByDesc(DB::raw('COALESCE(served_at, called_at, updated_at)'))
                ->first();

            $next = Antrian::query()
                ->with(['kategori', 'counter'])
                ->where('toko_id', $tokoId)
                ->whereDate('tanggal', $tanggal)
                ->where('kategori_id', $item->id)
                ->where('is_delete', 0)
                ->where('status', Antrian::STATUS_WAITING)
                ->prioritasPanggilan()
                ->first();

            return [
                'kategori' => [
                    'id' => $item->id,
                    'kode' => $item->kode,
                    'nama' => $item->nama,
                    'icon' => $item->icon,
                    'is_priority' => (bool) $item->is_priority,
                    'priority_level' => (int) $item->priority_level,
                ],
                'current' => $current ? [
                    'id' => $current->id,
                    'kode_nomor' => $current->kode_nomor,
                    'status' => $current->status,
                    'counter' => $current->counter?->nama,
                    'called_at' => $current->called_at,
                    'served_at' => $current->served_at,
                ] : null,
                'next' => $next ? [
                    'id' => $next->id,
                    'kode_nomor' => $next->kode_nomor,
                    'status' => $next->status,
                    'source_type' => $next->source_type,
                    'appointment_at' => $next->appointment_at,
                    'checkin_at' => $next->checkin_at,
                ] : null,
            ];
        });

        return $this->success($data);
    }

    public function operatorList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'tanggal' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'kategori_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'in:walk_in,booking,manual'],
            'keyword' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $tanggal = $request->tanggal ?: now()->toDateString();
        $perPage = $request->per_page ?: 15;

        $query = Antrian::query()
            ->with(['kategori', 'counter', 'booking'])
            ->where('toko_id', $request->toko_id)
            ->whereDate('tanggal', $tanggal)
            ->where('is_delete', 0)
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->when($request->kategori_id, function ($q) use ($request) {
                $q->where('kategori_id', $request->kategori_id);
            })
            ->when($request->source_type, function ($q) use ($request) {
                $q->where('source_type', $request->source_type);
            })
            ->when($request->keyword, function ($q) use ($request) {
                $keyword = $request->keyword;

                $q->where(function ($sub) use ($keyword) {
                    $sub->where('kode_nomor', 'LIKE', "%{$keyword}%")
                        ->orWhereHas('booking', function ($booking) use ($keyword) {
                            $booking->where('booking_code', 'LIKE', "%{$keyword}%")
                                ->orWhere('nama_pasien', 'LIKE', "%{$keyword}%")
                                ->orWhere('no_hp', 'LIKE', "%{$keyword}%");
                        });
                });
            })
            ->orderByRaw("
                CASE status
                    WHEN 'called' THEN 1
                    WHEN 'serving' THEN 2
                    WHEN 'waiting' THEN 3
                    WHEN 'skipped' THEN 4
                    WHEN 'finished' THEN 5
                    WHEN 'cancelled' THEN 6
                    ELSE 7
                END
            ")
            ->prioritasPanggilan();

        return $this->success($query->paginate($perPage));
    }

    public function panggilBerikutnya(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'counter_id' => ['required', 'integer', 'exists:master_antrian_counter,id'],
            'kategori_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $data = DB::transaction(function () use ($request) {
                $query = Antrian::query()
                    ->where('toko_id', $request->toko_id)
                    ->whereDate('tanggal', now()->toDateString())
                    ->where('is_delete', 0)
                    ->whereIn('status', [
                        Antrian::STATUS_WAITING,
                        Antrian::STATUS_SKIPPED,
                    ])
                    ->when($request->kategori_id, function ($q) use ($request) {
                        $q->where('kategori_id', $request->kategori_id);
                    })
                    ->prioritasPanggilan()
                    ->lockForUpdate();

                $antrian = $query->first();

                if (!$antrian) {
                    return null;
                }

                $antrian->markCalled($request->counter_id, $this->userId());

                return $antrian->fresh(['kategori', 'counter', 'booking']);
            });

            if (!$data) {
                return $this->error('Tidak ada antrian yang menunggu', null, 404);
            }

            return $this->success($data, 'Antrian berhasil dipanggil');
        } catch (\Throwable $e) {
            return $this->error('Gagal memanggil antrian', $e->getMessage(), 500);
        }
    }

    public function panggil(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'counter_id' => ['required', 'integer', 'exists:master_antrian_counter,id'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (!$antrian->canCall()) {
            return $this->error('Antrian tidak bisa dipanggil dari status saat ini', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markCalled($request->counter_id, $this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil dipanggil'
        );
    }

    public function panggilUlang(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'counter_id' => ['required', 'integer', 'exists:master_antrian_counter,id'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (!in_array($antrian->status, [Antrian::STATUS_CALLED, Antrian::STATUS_SERVING], true)) {
            return $this->error('Antrian hanya bisa dipanggil ulang jika status called atau serving', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markRecalled($request->counter_id, $this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil dipanggil ulang'
        );
    }

    public function mulaiLayanan($id)
    {
        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (!$antrian->canServe()) {
            return $this->error('Antrian tidak bisa mulai dilayani dari status saat ini', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markServing($this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian mulai dilayani'
        );
    }

    public function lewati($id)
    {
        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (!in_array($antrian->status, [Antrian::STATUS_WAITING, Antrian::STATUS_CALLED], true)) {
            return $this->error('Antrian tidak bisa dilewati dari status saat ini', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markSkipped($this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil dilewati'
        );
    }

    public function selesai($id)
    {
        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (!$antrian->canFinish()) {
            return $this->error('Antrian tidak bisa diselesaikan dari status saat ini', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markFinished($this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil diselesaikan'
        );
    }

    public function batal($id)
    {
        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        if (in_array($antrian->status, [Antrian::STATUS_FINISHED, Antrian::STATUS_CANCELLED], true)) {
            return $this->error('Antrian sudah selesai atau sudah dibatalkan', [
                'status' => $antrian->status,
            ], 422);
        }

        $antrian->markCancelled($this->userId());

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil dibatalkan'
        );
    }

    public function hubungkanRegistrasi(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'registrasi_id' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $antrian = Antrian::query()
            ->active()
            ->where('id', $id)
            ->first();

        if (!$antrian) {
            return $this->error('Data antrian tidak ditemukan', null, 404);
        }

        $antrian->update([
            'registrasi_id' => $request->registrasi_id,
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $antrian->fresh(['kategori', 'counter', 'booking']),
            'Antrian berhasil dihubungkan ke registrasi layanan'
        );
    }

    public function cariBookingHariIni(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'keyword' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = BookingLayanan::query()
            ->with(['kategori'])
            ->where('toko_id', $request->toko_id)
            ->whereDate('booking_date', now()->toDateString())
            ->belumCheckIn()
            ->searchPasien($request->keyword)
            ->orderBy('appointment_at')
            ->limit(10)
            ->get();

        return $this->success($data);
    }

    public function checkInBooking(Request $request, $bookingId)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'counter_id' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $booking = BookingLayanan::query()
            ->where('id', $bookingId)
            ->where('toko_id', $request->toko_id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        if (!in_array($booking->status, [
            BookingLayanan::STATUS_BOOKED,
            BookingLayanan::STATUS_CONFIRMED,
            BookingLayanan::STATUS_LATE,
        ], true)) {
            return $this->error('Booking tidak bisa check-in dari status saat ini', [
                'status' => $booking->status,
            ], 422);
        }

        if (!$booking->kategori_id) {
            return $this->error('Booking belum memiliki kategori antrian', null, 422);
        }

        $booking->status = BookingLayanan::STATUS_CHECKED_IN;
        $booking->checked_in_at = now();
        $booking->updated_by = $this->userId();
        $booking->save();

        $request->merge([
            'kategori_id' => $booking->kategori_id,
            'source_type' => Antrian::SOURCE_BOOKING,
            'source_id' => $booking->id,
            'pasien_id' => $booking->pasien_id,
            'appointment_at' => $booking->appointment_at,
            'priority_level' => $this->resolveBookingPriority($booking),
            'notes' => 'Check-in dari booking ' . $booking->booking_code,
        ]);

        return $this->ambilNomor($request);
    }

    private function resolveBookingPriority(BookingLayanan $booking)
    {
        $now = now();
        $appointmentAt = $booking->appointment_at;

        if (!$appointmentAt) {
            return 10;
        }

        $diffMinutes = $now->diffInMinutes($appointmentAt, false);

        if ($diffMinutes <= 30 && $diffMinutes >= -15) {
            return 50;
        }

        if ($diffMinutes < -15) {
            return 5;
        }

        return 10;
    }

    private function userId()
    {
        try {
            return auth('api')->id() ?: auth()->id();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function success($data = null, $message = 'Berhasil')
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function error($message, $error = null, $code = 400)
    {
        return response()->json([
            'status' => false,
            'message' => $message,
            'error' => $error,
        ], $code);
    }

    private function validationError($validator)
    {
        return response()->json([
            'status' => false,
            'message' => 'Validasi gagal',
            'error' => $validator->errors(),
        ], 422);
    }
}