<?php

namespace App\Http\Controllers\Api\Administrasi;

use App\Http\Controllers\Controller;
use App\Models\Master\MasterToko;
use App\Models\Pasien;
use App\Models\SkinAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

class SkinAnalyzerController extends Controller
{
    public function index(int $id): JsonResponse
    {
        try {
            $pasien = Pasien::query()
                ->active()
                ->find($id);

            if (!$pasien) {
                return response()->json([
                    'status' => false,
                    'message' => 'Data pasien tidak ditemukan.',
                ], 404);
            }

            $items = SkinAnalyzer::query()
                ->active()
                ->where('pasien_id', $pasien->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (SkinAnalyzer $item) => $this->transformItem($item))
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Data Skin Analyzer pasien berhasil diambil.',
                'data' => $items,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Data Skin Analyzer pasien gagal diambil.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make(
            $request->all(),
            [
                'url' => [
                    'required',
                    'string',
                    'max:2048',
                    function (string $attribute, mixed $value, callable $fail): void {
                        $url = trim((string) $value);
                        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

                        if (
                            !filter_var($url, FILTER_VALIDATE_URL)
                            || !in_array($scheme, ['http', 'https'], true)
                        ) {
                            $fail('Link Skin Analyzer harus berupa URL lengkap yang diawali http:// atau https://.');
                        }
                    },
                ],
            ],
            [
                'url.required' => 'Link hasil Skin Analyzer wajib diisi.',
                'url.string' => 'Link hasil Skin Analyzer tidak valid.',
                'url.max' => 'Link hasil Skin Analyzer maksimal 2048 karakter.',
            ],
        );

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validasi link Skin Analyzer gagal.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $pasien = Pasien::query()
                ->active()
                ->with('toko')
                ->find($id);

            if (!$pasien) {
                return response()->json([
                    'status' => false,
                    'message' => 'Data pasien tidak ditemukan.',
                ], 404);
            }

            $user = auth('api')->user();

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'Sesi pengguna tidak ditemukan.',
                ], 401);
            }

            $user->loadMissing([
                'karyawan',
                'penempatan.toko',
            ]);

            $activePlacements = $user->penempatan
                ->filter(fn ($placement) => (int) $placement->is_active === 1)
                ->values();

            $placement = $activePlacements->first(
                fn ($item) => (int) $item->is_primary === 1,
            ) ?? $activePlacements->first();

            $selectedTokoId = (int) $request->header('X-Toko-Id', 0);
            $selectedToko = $selectedTokoId > 0
                ? MasterToko::query()
                    ->where('id', $selectedTokoId)
                    ->where(function ($query) {
                        $query->where('is_delete', 0)
                            ->orWhereNull('is_delete');
                    })
                    ->first()
                : null;

            $tokoId = $selectedToko?->id
                ?: ($placement?->toko_id ?: $pasien->toko_id);
            $branchName = trim((string) (
                $selectedToko?->nama_toko
                ?: $placement?->toko?->nama_toko
                ?: $pasien->toko?->nama_toko
            ));
            $operatorName = $this->resolveOperatorName($user);

            $item = SkinAnalyzer::query()->create([
                'pasien_id' => $pasien->id,
                'toko_id' => $tokoId ?: null,
                'url' => trim((string) $validator->validated()['url']),
                'operator_name' => $operatorName,
                'branch_name' => $branchName !== '' ? $branchName : '-',
                'is_delete' => 0,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Link hasil Skin Analyzer berhasil disimpan.',
                'data' => $this->transformItem($item),
            ], 201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => false,
                'message' => 'Link hasil Skin Analyzer gagal disimpan.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }

    private function resolveOperatorName(object $user): string
    {
        $candidates = [
            $user->karyawan?->nama,
            $user->display_name,
            $user->nama,
            $user->username,
        ];

        foreach ($candidates as $candidate) {
            $name = trim((string) $candidate);

            if ($name !== '') {
                return $name;
            }
        }

        return 'Sistem';
    }

    private function transformItem(SkinAnalyzer $item): array
    {
        return [
            'id' => (int) $item->id,
            'pasien_id' => (int) $item->pasien_id,
            'url' => (string) $item->url,
            'created_at' => $item->created_at?->format('Y-m-d H:i:s'),
            'operate_by' => $item->operator_name ?: '-',
            'branch' => $item->branch_name ?: '-',
        ];
    }
}
