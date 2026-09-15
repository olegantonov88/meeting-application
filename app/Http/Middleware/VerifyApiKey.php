<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    /**
     * Запросы от CRM: генерация приложений к собранию и сборка архивов для zakaznoe.
     *
     * Ключ не настроен - отказываем, а не пропускаем: тем же ключом воркер
     * подписывает callback'и в CRM, и молча открытый вход здесь не заметили бы.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = (string) config('services.meeting_application.api_key');

        if ($apiKey === '') {
            Log::error('MEETING_APPLICATION_API_KEY not configured, request rejected', [
                'path' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ключ API сервиса не настроен',
            ], 401);
        }

        $authHeader = (string) $request->header('Authorization');

        if ($authHeader === '') {
            return response()->json([
                'success' => false,
                'message' => 'Отсутствует заголовок Authorization',
            ], 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'success' => false,
                'message' => 'Неверный формат токена. Ожидается: Bearer {token}',
            ], 401);
        }

        if (!hash_equals($apiKey, substr($authHeader, 7))) {
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
