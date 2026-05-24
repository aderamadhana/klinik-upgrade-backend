<?php

namespace App\Http\Controllers\Api\Antrian;

use App\Http\Controllers\Controller;
use App\Models\Antrian\BookingLayanan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class BookingLayananController extends Controller
{
    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'tanggal' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'keyword' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $tanggal = $request->tanggal ?: now()->toDateString();
        $perPage = $request->per_page ?: 15;

        $query = BookingLayanan::query()
            ->with(['kategori', 'pasien', 'dokter', 'treatment'])
            ->where('toko_id', $request->toko_id)
            ->whereDate('booking_date', $tanggal)
            ->when($request->status, function ($q) use ($request) {
                $q->where('status', $request->status);
            })
            ->searchPasien($request->keyword)
            ->orderBy('appointment_at');

        return $this->success($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'pasien_id' => ['nullable', 'integer'],
            'nama_pasien' => ['nullable', 'string', 'max:150'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'kategori_id' => ['required', 'integer', 'exists:master_antrian_kategori,id'],
            'booking_date' => ['required', 'date'],
            'booking_time' => ['required', 'date_format:H:i'],
            'dokter_id' => ['nullable', 'integer'],
            'treatment_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:booked,confirmed,checked_in,in_queue,called,serving,completed,cancelled,no_show,rescheduled,late'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        if (!$request->pasien_id && !$request->nama_pasien && !$request->no_hp) {
            return $this->error('Isi pasien_id atau minimal nama_pasien / no_hp', null, 422);
        }

        $appointmentAt = $request->booking_date . ' ' . $request->booking_time . ':00';

        $booking = BookingLayanan::create([
            'booking_code' => $this->generateBookingCode($request->toko_id),
            'toko_id' => $request->toko_id,
            'pasien_id' => $request->pasien_id,
            'nama_pasien' => $request->nama_pasien,
            'no_hp' => $request->no_hp,
            'kategori_id' => $request->kategori_id,
            'booking_date' => $request->booking_date,
            'booking_time' => $request->booking_time,
            'appointment_at' => $appointmentAt,
            'dokter_id' => $request->dokter_id,
            'treatment_id' => $request->treatment_id,
            'source' => $request->source,
            'notes' => $request->notes,
            'status' => $request->status ?: BookingLayanan::STATUS_BOOKED,
            'created_by' => $this->userId(),
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $booking->fresh(['kategori', 'pasien', 'dokter', 'treatment']),
            'Booking berhasil dibuat'
        );
    }

    public function show($id)
    {
        $booking = BookingLayanan::query()
            ->with(['kategori', 'pasien', 'dokter', 'treatment', 'antrian'])
            ->where('id', $id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        return $this->success($booking);
    }

    public function update(Request $request, $id)
    {
        $booking = BookingLayanan::query()
            ->where('id', $id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        if (in_array($booking->status, [
            BookingLayanan::STATUS_IN_QUEUE,
            BookingLayanan::STATUS_CALLED,
            BookingLayanan::STATUS_SERVING,
            BookingLayanan::STATUS_COMPLETED,
        ], true)) {
            return $this->error('Booking tidak bisa diedit dari status saat ini', [
                'status' => $booking->status,
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'pasien_id' => ['nullable', 'integer'],
            'nama_pasien' => ['nullable', 'string', 'max:150'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'kategori_id' => ['nullable', 'integer', 'exists:master_antrian_kategori,id'],
            'booking_date' => ['nullable', 'date'],
            'booking_time' => ['nullable', 'date_format:H:i'],
            'dokter_id' => ['nullable', 'integer'],
            'treatment_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:booked,confirmed,cancelled,no_show,rescheduled,late'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $bookingDate = $request->booking_date ?: $booking->booking_date->format('Y-m-d');
        $bookingTime = $request->booking_time ?: $booking->booking_time->format('H:i');

        $booking->update([
            'pasien_id' => $request->has('pasien_id') ? $request->pasien_id : $booking->pasien_id,
            'nama_pasien' => $request->has('nama_pasien') ? $request->nama_pasien : $booking->nama_pasien,
            'no_hp' => $request->has('no_hp') ? $request->no_hp : $booking->no_hp,
            'kategori_id' => $request->kategori_id ?: $booking->kategori_id,
            'booking_date' => $bookingDate,
            'booking_time' => $bookingTime,
            'appointment_at' => $bookingDate . ' ' . $bookingTime . ':00',
            'dokter_id' => $request->has('dokter_id') ? $request->dokter_id : $booking->dokter_id,
            'treatment_id' => $request->has('treatment_id') ? $request->treatment_id : $booking->treatment_id,
            'source' => $request->has('source') ? $request->source : $booking->source,
            'notes' => $request->has('notes') ? $request->notes : $booking->notes,
            'status' => $request->status ?: $booking->status,
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $booking->fresh(['kategori', 'pasien', 'dokter', 'treatment']),
            'Booking berhasil diperbarui'
        );
    }

    public function cancel(Request $request, $id)
    {
        $booking = BookingLayanan::query()
            ->where('id', $id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        if (in_array($booking->status, [
            BookingLayanan::STATUS_COMPLETED,
            BookingLayanan::STATUS_CANCELLED,
            BookingLayanan::STATUS_NO_SHOW,
        ], true)) {
            return $this->error('Booking sudah selesai / batal / no-show', [
                'status' => $booking->status,
            ], 422);
        }

        $booking->update([
            'status' => BookingLayanan::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'notes' => $request->notes ?: $booking->notes,
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $booking->fresh(['kategori', 'pasien', 'dokter', 'treatment']),
            'Booking berhasil dibatalkan'
        );
    }

    public function noShow($id)
    {
        $booking = BookingLayanan::query()
            ->where('id', $id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        if (!in_array($booking->status, [
            BookingLayanan::STATUS_BOOKED,
            BookingLayanan::STATUS_CONFIRMED,
            BookingLayanan::STATUS_LATE,
        ], true)) {
            return $this->error('Booking tidak bisa ditandai no-show dari status saat ini', [
                'status' => $booking->status,
            ], 422);
        }

        $booking->update([
            'status' => BookingLayanan::STATUS_NO_SHOW,
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $booking->fresh(['kategori', 'pasien', 'dokter', 'treatment']),
            'Booking ditandai no-show'
        );
    }

    public function markLate($id)
    {
        $booking = BookingLayanan::query()
            ->where('id', $id)
            ->first();

        if (!$booking) {
            return $this->error('Data booking tidak ditemukan', null, 404);
        }

        if (!in_array($booking->status, [
            BookingLayanan::STATUS_BOOKED,
            BookingLayanan::STATUS_CONFIRMED,
        ], true)) {
            return $this->error('Booking tidak bisa ditandai terlambat dari status saat ini', [
                'status' => $booking->status,
            ], 422);
        }

        $booking->update([
            'status' => BookingLayanan::STATUS_LATE,
            'updated_by' => $this->userId(),
        ]);

        return $this->success(
            $booking->fresh(['kategori', 'pasien', 'dokter', 'treatment']),
            'Booking ditandai terlambat'
        );
    }

    private function generateBookingCode($tokoId)
    {
        $prefix = 'BK-' . now()->format('ymd') . '-' . $tokoId . '-';

        $last = BookingLayanan::query()
            ->where('booking_code', 'LIKE', $prefix . '%')
            ->orderByDesc('id')
            ->value('booking_code');

        $next = 1;

        if ($last) {
            $lastNumber = (int) Str::afterLast($last, '-');
            $next = $lastNumber + 1;
        }

        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
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