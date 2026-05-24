<?php

namespace App\Http\Controllers\Api\Master;

use App\Http\Controllers\Controller;
use App\Models\Antrian\MasterAntrianKategori;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

class MasterAntrianKategoriController extends Controller
{
    public function index(Request $request)
    {
        $query = MasterAntrianKategori::query()
            ->with(['toko'])
            ->when($request->filled('toko_id'), function ($q) use ($request) {
                $q->where(function ($sub) use ($request) {
                    $sub->whereNull('toko_id')
                        ->orWhere('toko_id', $request->toko_id);
                });
            })
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('is_active', $request->status);
            })
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = $request->keyword;

                $q->where(function ($sub) use ($keyword) {
                    $sub->where('kode', 'LIKE', "%{$keyword}%")
                        ->orWhere('nama', 'LIKE', "%{$keyword}%")
                        ->orWhere('deskripsi', 'LIKE', "%{$keyword}%");
                });
            })
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->orderBy('toko_id')
            ->orderByDesc('is_priority')
            ->orderBy('nama');

        $perPage = $request->get('per_page', 15);

        return $this->success($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'toko_id' => ['nullable', 'integer'],
            'kode' => [
                'required',
                'string',
                'max:10',
                Rule::unique('master_antrian_kategori', 'kode')
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('toko_id', $request->toko_id)
                            ->where('is_delete', 0);
                    }),
            ],
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_priority' => ['nullable', 'boolean'],
            'priority_level' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = new MasterAntrianKategori();
        $data->toko_id = $request->toko_id;
        $data->kode = strtoupper($request->kode);
        $data->nama = $request->nama;
        $data->deskripsi = $request->deskripsi;
        $data->icon = $request->icon;
        $data->is_priority = $request->boolean('is_priority');
        $data->priority_level = $request->priority_level ?? 0;
        $data->is_active = $request->has('is_active') ? $request->boolean('is_active') : 1;
        $data->is_delete = 0;
        $data->created_by = $this->userId();
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success(
            $data->fresh(['toko']),
            'Kategori antrian berhasil dibuat'
        );
    }

    public function show($id)
    {
        $data = MasterAntrianKategori::query()
            ->with(['toko'])
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Kategori antrian tidak ditemukan', null, 404);
        }

        return $this->success($data);
    }

    public function update(Request $request, $id)
    {
        $data = MasterAntrianKategori::query()
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Kategori antrian tidak ditemukan', null, 404);
        }

        $validator = Validator::make($request->all(), [
            'toko_id' => ['nullable', 'integer'],
            'kode' => [
                'required',
                'string',
                'max:10',
                Rule::unique('master_antrian_kategori', 'kode')
                    ->ignore($data->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('toko_id', $request->toko_id)
                            ->where('is_delete', 0);
                    }),
            ],
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'is_priority' => ['nullable', 'boolean'],
            'priority_level' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data->toko_id = $request->toko_id;
        $data->kode = strtoupper($request->kode);
        $data->nama = $request->nama;
        $data->deskripsi = $request->deskripsi;
        $data->icon = $request->icon;
        $data->is_priority = $request->boolean('is_priority');
        $data->priority_level = $request->priority_level ?? 0;
        $data->is_active = $request->has('is_active') ? $request->boolean('is_active') : $data->is_active;
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success(
            $data->fresh(['toko']),
            'Kategori antrian berhasil diperbarui'
        );
    }

    public function destroy($id)
    {
        $data = MasterAntrianKategori::query()
            ->where('id', $id)
            ->where(function ($q) {
                $q->where('is_delete', 0)
                    ->orWhereNull('is_delete');
            })
            ->first();

        if (!$data) {
            return $this->error('Kategori antrian tidak ditemukan', null, 404);
        }

        $data->is_delete = 1;
        $data->is_active = 0;
        $data->updated_by = $this->userId();
        $data->save();

        return $this->success($data, 'Kategori antrian berhasil dihapus');
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
            'source_toko_id' => ['nullable'],
            'target_toko_ids' => ['required', 'array', 'min:1'],
            'target_toko_ids.*' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        try {
            $result = DB::transaction(function () use ($request) {
                $sourceTokoId = $request->source_toko_id;

                if ($sourceTokoId === '__global__' || $sourceTokoId === '' || $sourceTokoId === null) {
                    $sourceTokoId = null;
                }

                $sourceRows = MasterAntrianKategori::query()
                    ->where(function ($q) use ($sourceTokoId) {
                        if ($sourceTokoId === null) {
                            $q->whereNull('toko_id');
                        } else {
                            $q->where('toko_id', $sourceTokoId);
                        }
                    })
                    ->where(function ($q) {
                        $q->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    })
                    ->get();

                if ($sourceRows->isEmpty()) {
                    throw new \Exception('Data kategori sumber tidak ditemukan');
                }

                $created = 0;
                $updated = 0;

                foreach ($request->target_toko_ids as $targetTokoId) {
                    foreach ($sourceRows as $source) {
                        $existing = MasterAntrianKategori::query()
                            ->where('toko_id', $targetTokoId)
                            ->where('kode', $source->kode)
                            ->where(function ($q) {
                                $q->where('is_delete', 0)
                                    ->orWhereNull('is_delete');
                            })
                            ->first();

                        if ($existing) {
                            $existing->nama = $source->nama;
                            $existing->deskripsi = $source->deskripsi;
                            $existing->icon = $source->icon;
                            $existing->is_priority = $source->is_priority;
                            $existing->priority_level = $source->priority_level;
                            $existing->is_active = $source->is_active;
                            $existing->updated_by = $this->userId();
                            $existing->save();

                            $updated++;
                        } else {
                            $newData = new MasterAntrianKategori();
                            $newData->toko_id = $targetTokoId;
                            $newData->kode = $source->kode;
                            $newData->nama = $source->nama;
                            $newData->deskripsi = $source->deskripsi;
                            $newData->icon = $source->icon;
                            $newData->is_priority = $source->is_priority;
                            $newData->priority_level = $source->priority_level;
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

            return $this->success($result, 'Kategori antrian berhasil disamakan');
        } catch (\Throwable $e) {
            return $this->error('Gagal menyamakan kategori antrian', $e->getMessage(), 500);
        }
    }
}