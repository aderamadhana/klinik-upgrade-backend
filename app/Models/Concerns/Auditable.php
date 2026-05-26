<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function ($model) {
            $model->writeAuditLog('create');
        });

        static::updated(function ($model) {
            $action = 'update';

            if ($model->wasChanged('is_delete')) {
                $action = (int) $model->getAttribute('is_delete') === 1
                    ? 'delete'
                    : 'restore';
            }

            $model->writeAuditLog($action);
        });

        static::deleted(function ($model) {
            $model->writeAuditLog('force_delete');
        });
    }

    protected function writeAuditLog(string $action): void
    {
        try {
            if ($this->shouldSkipAudit()) {
                return;
            }

            if ($this->getTable() === 'audit_logs') {
                return;
            }

            if (!Schema::hasTable('audit_logs')) {
                return;
            }

            $user = $this->getCurrentAuditUser();

            $oldValues = null;
            $newValues = null;

            if ($action === 'create') {
                $newValues = $this->filterAuditValues($this->getAttributes());
            } elseif ($action === 'force_delete') {
                $oldValues = $this->filterAuditValues($this->getOriginal());
            } else {
                $changedKeys = array_keys($this->getChanges());
                $changedKeys = $this->filterAuditChangedKeys($changedKeys);

                if (empty($changedKeys)) {
                    return;
                }

                $oldValues = $this->filterAuditValues(
                    $this->onlyOriginalKeys($changedKeys)
                );

                $newValues = $this->filterAuditValues(
                    $this->onlyCurrentKeys($changedKeys)
                );
            }

            if ($action === 'update' && empty($oldValues) && empty($newValues)) {
                return;
            }

            $payload = [
                'module_name' => $this->getAuditModuleName(),
                'table_name'  => $this->getTable(),
                'record_id'   => $this->getKey(),
                'action'      => $action,
                'description' => $this->getAuditDescription($action),

                'old_values'  => $this->toAuditJson($oldValues),
                'new_values'  => $this->toAuditJson($newValues),

                'toko_id'     => $this->getAuditTokoId($user),
                'user_id'     => $user ? ($user->id ?? null) : null,
                'username'    => $user ? ($user->username ?? $user->name ?? null) : null,
                'role_name'   => $this->getAuditRoleName($user),

                'ip_address'  => $this->getAuditIpAddress(),
                'user_agent'  => $this->getAuditUserAgent(),
                'reason'      => $this->getAuditReason(),

                'created_at'  => now(),
            ];

            if (Schema::hasColumn('audit_logs', 'updated_at')) {
                $payload['updated_at'] = now();
            }

            DB::table('audit_logs')->insert($payload);
        } catch (Throwable $e) {
            $this->logAuditFailure($e);
        }
    }

    protected function shouldSkipAudit(): bool
    {
        if (app()->runningInConsole()) {
            return true;
        }

        if (!app()->bound('request')) {
            return true;
        }

        try {
            $request = request();

            if ($request->is('docs') || $request->is('docs/*')) {
                return true;
            }

            if ($request->is('api/docs') || $request->is('api/docs/*')) {
                return true;
            }

            if ($request->is('docs/api') || $request->is('docs/api/*')) {
                return true;
            }

            if ($request->is('docs/api.json')) {
                return true;
            }
        } catch (Throwable $e) {
            return true;
        }

        return false;
    }

    protected function getCurrentAuditUser()
    {
        try {
            return Auth::guard('api')->user() ?: Auth::user();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getAuditRoleName($user = null)
    {
        if (!$user) {
            return null;
        }

        if (isset($user->role_name)) {
            return $user->role_name;
        }

        try {
            if (isset($user->role) && isset($user->role->nama)) {
                return $user->role->nama;
            }

            if (isset($user->role) && isset($user->role->name)) {
                return $user->role->name;
            }

            if (isset($user->role) && isset($user->role->role_name)) {
                return $user->role->role_name;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }

    protected function getAuditModuleName(): string
    {
        if (property_exists($this, 'auditModuleName') && !empty($this->auditModuleName)) {
            return $this->auditModuleName;
        }

        $classParts = explode('\\', get_class($this));
        $table = method_exists($this, 'getTable') ? $this->getTable() : '';

        if (in_array('Master', $classParts, true) || $this->auditTableStartsWith($table, 'master_')) {
            return 'Master';
        }

        if (in_array('Registrasi', $classParts, true) || $this->auditTableStartsWith($table, 'registrasi_')) {
            return 'Registrasi';
        }

        if (in_array('Stock', $classParts, true) || $this->auditTableStartsWith($table, 'stock_')) {
            return 'Stock';
        }

        if (in_array('Pembayaran', $classParts, true) || $this->auditTableStartsWith($table, 'pembayaran_')) {
            return 'Pembayaran';
        }

        if (in_array('PelayananMedis', $classParts, true)) {
            return 'Pelayanan Medis';
        }

        if (
            in_array('Poin', $classParts, true) ||
            in_array($table, [
                'member_point_ledger',
                'pasien_poin_ledger',
            ], true)
        ) {
            return 'Poin';
        }

        if (
            in_array('Pasien', $classParts, true) ||
            in_array($table, [
                'pasien',
                'pasien_member',
            ], true)
        ) {
            return 'Pasien';
        }

        return 'General';
    }

    protected function auditTableStartsWith(string $table, string $prefix): bool
    {
        if ($table === '') {
            return false;
        }

        return substr($table, 0, strlen($prefix)) === $prefix;
    }

    protected function getAuditDescription(string $action): string
    {
        $table = $this->getTable();
        $id = $this->getKey();

        if ($action === 'create') {
            return "Menambahkan data {$table} ID {$id}";
        }

        if ($action === 'update') {
            return "Mengubah data {$table} ID {$id}";
        }

        if ($action === 'delete') {
            return "Menghapus data {$table} ID {$id}";
        }

        if ($action === 'restore') {
            return "Mengembalikan data {$table} ID {$id}";
        }

        if ($action === 'force_delete') {
            return "Menghapus permanen data {$table} ID {$id}";
        }

        return "Aktivitas {$action} pada {$table} ID {$id}";
    }

    protected function getAuditTokoId($user = null)
    {
        if ($this->getAttribute('toko_id')) {
            return $this->getAttribute('toko_id');
        }

        if ($this->getAttribute('cabang_id')) {
            return $this->getAttribute('cabang_id');
        }

        try {
            if (app()->bound('request')) {
                $request = request();

                if ($request->filled('toko_id')) {
                    return $request->input('toko_id');
                }

                if ($request->filled('cabang_id')) {
                    return $request->input('cabang_id');
                }

                if ($request->header('X-Toko-Id')) {
                    return $request->header('X-Toko-Id');
                }

                if ($request->header('X-Cabang-Id')) {
                    return $request->header('X-Cabang-Id');
                }
            }
        } catch (Throwable $e) {
            //
        }

        if ($user && isset($user->toko_id)) {
            return $user->toko_id;
        }

        if ($user && isset($user->cabang_id)) {
            return $user->cabang_id;
        }

        return null;
    }

    protected function getAuditReason()
    {
        try {
            if (!app()->bound('request')) {
                return null;
            }

            $request = request();

            return $request->input('audit_reason')
                ?: $request->input('reason')
                ?: $request->header('X-Audit-Reason');
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getAuditIpAddress()
    {
        try {
            if (!app()->bound('request')) {
                return null;
            }

            return request()->ip();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function getAuditUserAgent()
    {
        try {
            if (!app()->bound('request')) {
                return null;
            }

            return request()->userAgent();
        } catch (Throwable $e) {
            return null;
        }
    }

    protected function onlyOriginalKeys(array $keys): array
    {
        $data = [];

        foreach ($keys as $key) {
            $data[$key] = $this->getOriginal($key);
        }

        return $data;
    }

    protected function onlyCurrentKeys(array $keys): array
    {
        $data = [];

        foreach ($keys as $key) {
            $data[$key] = $this->getAttribute($key);
        }

        return $data;
    }

    protected function filterAuditChangedKeys(array $keys): array
    {
        $ignored = [
            'updated_at',
        ];

        if (property_exists($this, 'auditIgnored') && is_array($this->auditIgnored)) {
            $ignored = array_merge($ignored, $this->auditIgnored);
        }

        return array_values(array_filter($keys, function ($key) use ($ignored) {
            return !in_array($key, $ignored, true);
        }));
    }

    protected function filterAuditValues(?array $values): ?array
    {
        if (empty($values)) {
            return null;
        }

        $hidden = [
            'password',
            'remember_token',
            'token',
            'api_token',
            'access_token',
            'refresh_token',
            'secret',
            'secret_key',
        ];

        if (property_exists($this, 'auditHidden') && is_array($this->auditHidden)) {
            $hidden = array_merge($hidden, $this->auditHidden);
        }

        foreach ($hidden as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '[HIDDEN]';
            }
        }

        return $values;
    }

    protected function toAuditJson(?array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        return json_encode(
            $values,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    protected function logAuditFailure(Throwable $e): void
    {
        try {
            logger()->warning('Audit log failed', [
                'model'   => get_class($this),
                'table'   => method_exists($this, 'getTable') ? $this->getTable() : null,
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
        } catch (Throwable $logException) {
            //
        }
    }
}