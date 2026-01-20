<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = config('services.meeting_application.api_key');

        // Если API ключ не настроен, пропускаем проверку
        if (empty($apiKey)) {
            Log::warning('MEETING_APPLICATION_API_KEY not configured, skipping API key verification');
            return $next($request);
        }

        // Получаем токен из заголовка Authorization
        $authHeader = $request->header('Authorization');

        if (!$authHeader) {
            return response()->json([
                'success' => false,
                'message' => 'Отсутствует заголовок Authorization',
            ], 401);
        }

        // Проверяем формат Bearer токена
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат токена. Ожидается: Bearer {token}',
            ], 401);
        }

        // Извлекаем токен
        $token = substr($authHeader, 7);

        // Проверяем токен
        if ($token !== $apiKey) {
            Log::warning('Invalid API key attempt', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Неверный API ключ',
            ], 401);
        }

        return $next($request);
    }
}
