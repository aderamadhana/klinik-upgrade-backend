<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        try {
            $perPage = (int) $request->input('per_page', 10);
            $perPage = $perPage > 100 ? 100 : $perPage;

            $query = AuditLog::query()
                ->select([
                    'id',
                    'module_name',
                    'table_name',
                    'record_id',
                    'action',
                    'description',
                    'toko_id',
                    'user_id',
                    'username',
                    'role_name',
                    'ip_address',
                    'reason',
                    'created_at',
                ])
                ->orderByDesc('id');

            if ($request->filled('search')) {
                $search = trim($request->search);

                $query->where(function ($q) use ($search) {
                    $q->where('module_name', 'like', "%{$search}%")
                        ->orWhere('table_name', 'like', "%{$search}%")
                        ->orWhere('action', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%")
                        ->orWhere('role_name', 'like', "%{$search}%")
                        ->orWhere('ip_address', 'like', "%{$search}%")
                        ->orWhere('reason', 'like', "%{$search}%");
                });
            }

            if ($request->filled('module_name')) {
                $query->where('module_name', $request->module_name);
            }

            if ($request->filled('table_name')) {
                $query->where('table_name', $request->table_name);
            }

            if ($request->filled('action')) {
                $query->where('action', $request->action);
            }

            if ($request->filled('toko_id')) {
                $query->where('toko_id', $request->toko_id);
            }

            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }

            if ($request->filled('record_id')) {
                $query->where('record_id', $request->record_id);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $data = $query->paginate($perPage);

            return response()->json([
                'status' => true,
                'message' => 'Data audit logs berhasil diambil',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil data audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $data = AuditLog::query()->find($id);

            if (!$data) {
                return response()->json([
                    'status' => false,
                    'message' => 'Data audit log tidak ditemukan',
                ], 404);
            }

            $data->old_values_parsed = $this->parseJsonValue($data->old_values);
            $data->new_values_parsed = $this->parseJsonValue($data->new_values);

            return response()->json([
                'status' => true,
                'message' => 'Detail audit log berhasil diambil',
                'data' => $data,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil detail audit log',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function filters()
    {
        try {
            $modules = AuditLog::query()
                ->whereNotNull('module_name')
                ->select('module_name')
                ->distinct()
                ->orderBy('module_name')
                ->pluck('module_name');

            $tables = AuditLog::query()
                ->whereNotNull('table_name')
                ->select('table_name')
                ->distinct()
                ->orderBy('table_name')
                ->pluck('table_name');

            $actions = AuditLog::query()
                ->whereNotNull('action')
                ->select('action')
                ->distinct()
                ->orderBy('action')
                ->pluck('action');

            return response()->json([
                'status' => true,
                'message' => 'Filter audit logs berhasil diambil',
                'data' => [
                    'modules' => $modules,
                    'tables' => $tables,
                    'actions' => $actions,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil filter audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function summary(Request $request)
    {
        try {
            $query = AuditLog::query();

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $total = (clone $query)->count();

            $today = AuditLog::query()
                ->whereDate('created_at', now()->toDateString())
                ->count();

            $byAction = (clone $query)
                ->select('action', DB::raw('COUNT(*) as total'))
                ->groupBy('action')
                ->orderByDesc('total')
                ->get();

            return response()->json([
                'status' => true,
                'message' => 'Summary audit logs berhasil diambil',
                'data' => [
                    'total' => $total,
                    'today' => $today,
                    'by_action' => $byAction,
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Gagal mengambil summary audit logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function parseJsonValue($value)
    {
        if (!$value) {
            return null;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}