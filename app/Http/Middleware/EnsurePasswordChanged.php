<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->isMethod('OPTIONS')
            || ! $request->bearerToken()
            || $this->isAllowedRequest($request)
        ) {
            return $next($request);
        }

        try {
            $user = Auth::guard('api')->user();
        } catch (Throwable) {
            // Biarkan middleware autentikasi menangani token yang tidak valid/kedaluwarsa.
            return $next($request);
        }

        if (! $user || (int) ($user->must_change_password ?? 0) !== 1) {
            return $next($request);
        }

        return response()->json([
            'status' => false,
            'code' => 'PASSWORD_CHANGE_REQUIRED',
            'message' => 'Anda wajib mengganti password sebelum melanjutkan.',
            'data' => [
                'must_change_password' => 1,
            ],
        ], Response::HTTP_PRECONDITION_REQUIRED);
    }

    private function isAllowedRequest(Request $request): bool
    {
        return $request->is(
            'api/login',
            'login',
            'api/auth/refresh',
            'auth/refresh',
            'api/change-password',
            'change-password',
            'api/logout',
            'logout',
            'api/me',
            'me',
        );
    }
}
