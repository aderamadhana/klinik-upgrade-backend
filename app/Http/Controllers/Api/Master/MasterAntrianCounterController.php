<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Antrian\MasterAntrianCounter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MasterAntrianCounterController extends Controller
{
    public function index(Request $request)
    {
        $query = MasterAntrianCounter::query()
            ->with(['toko'])
            ->when($request->filled('toko_id'), function ($q) use ($request) {
                $q->where('toko_id', $request->toko_id);
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_active', $request->status);
            })
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = $request->keyword;

                $q->where(function ($sub) use ($keyword) {
                    $sub->where('kode', 'LIKE', "%{$keyword}%")
                        ->orWhere('nama', 'LIKE', "%{$keyword}%")
                        ->orWhere('keterangan', 'LIKE', "%{$keyword}%");
                });
            })
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderBy('toko_id')
            ->orderBy('nama');

        $perPage = $request->get('per_page', 15);

        return $this->success($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'nama' => ['required', 'string', 'max:100'],
            'kode' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('master_antrian_counter', 'kode')
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('toko_id', $request->toko_id)
                            ->where('is_delete', 0);
                    }),
            ],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = new MasterAntrianCounter();
        $data->toko_id = $request->toko_id;
        $data->nama = $request->nama;
        $data->kode = $request->kode ? strtoupper($request->kode) : null;
        $data->keterangan = $request->keterangan;
        $data->is_active = $request->has('is_active') ? $request->boolean('is_active') : 1;
        $data->is_delete = 0;
        $data->created_by = $this->userId();
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success(
            $data->fresh(['toko']),
            'Counter antrian berhasil dibuat'
        );
    }

    public function show($id)
    {
        $data = MasterAntrianCounter::query()
            ->with(['toko'])
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Counter antrian tidak ditemukan', null, 404);
        }

        return $this->success($data);
    }

    public function update(Request $request, $id)
    {
        $data = MasterAntrianCounter::query()
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Counter antrian tidak ditemukan', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'toko_id' => ['required', 'integer'],
            'nama' => ['required', 'string', 'max:100'],
            'kode' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('master_antrian_counter', 'kode')
                    ->ignore($data->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('toko_id', $request->toko_id)
                            ->where('is_delete', 0);
                    }),
            ],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data->toko_id = $request->toko_id;
        $data->nama = $request->nama;
        $data->kode = $request->kode ? strtoupper($request->kode) : null;
        $data->keterangan = $request->keterangan;
        $data->is_active = $request->has('is_active') ? $request->boolean('is_active') : $data->is_active;
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success(
            $data->fresh(['toko']),
            'Counter antrian berhasil diperbarui'
        );
    }

    public function destroy($id)
    {
        $data = MasterAntrianCounter::query()
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Counter antrian tidak ditemukan', null, 404);
        }

        $data->is_delete = 1;
        $data->is_active = 0;
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success($data, 'Counter antrian berhasil dihapus');
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

    public function syncFromBranch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_toko_id' => ['required', 'integer'],
            'target_toko_ids' => ['required', 'array', 'min:1'],
            'target_toko_ids.*' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $sourceRows = MasterAntrianCounter::query()
                    ->where('toko_id', $request->source_toko_id)
                    ->where(function ($q) {
                        $q->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    })
                    ->get();

                if ($sourceRows->isEmpty()) {
                    throw new \Exception('Data counter sumber tidak ditemukan');
                }

                $created = 0;
                $updated = 0;

                foreach ($request->target_toko_ids as $targetTokoId) {
                    foreach ($sourceRows as $source) {
                        $existingQuery = MasterAntrianCounter::query()
                            ->where('toko_id', $targetTokoId)
                            ->where(function ($q) {
                                $q->where('is_delete', 0)
                                    ->orWhereNull('is_delete');
                            });

                        if ($source->kode) {
                            $existingQuery->where('kode', $source->kode);
                        } else {
                            $existingQuery->where('nama', $source->nama);
                        }

                        $existing = $existingQuery->first();

                        if ($existing) {
                            $existing->nama = $source->nama;
                            $existing->kode = $source->kode;
                            $existing->keterangan = $source->keterangan;
                            $existing->is_active = $source->is_active;
                            $existing->updated_by = $this->userId();
                            $existing->save();

                            $updated++;
                        } else {
                            $newData = new MasterAntrianCounter();
                            $newData->toko_id = $targetTokoId;
                            $newData->nama = $source->nama;
                            $newData->kode = $source->kode;
                            $newData->keterangan = $source->keterangan;
                            $newData->is_active = $source->is_active;
                            $newData->is_delete = 0;
                            $newData->created_by = $this->userId();
                            $newData->updated_by = $this->userId();
                            $newData->save();

                            $created++;
                        }
                    }
                }

                return [
                    'created' => $created,
                    'updated' => $updated,
                    'total_source' => $sourceRows->count(),
                    'total_target' => count($request->target_toko_ids),
                ];
            });

            return $this->success($result, 'Counter antrian berhasil disamakan');
        } catch (\Throwable $e) {
            return $this->error('Gagal menyamakan counter antrian', $e->getMessage(), 500);
        }
    }
}