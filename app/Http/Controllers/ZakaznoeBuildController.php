<?php

namespace App\Http\Controllers;

use App\Http\Requests\ZakaznoeBuildStoreRequest;
use App\Jobs\ZakaznoeBuildJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Приём задания на сборку архива для zakaznoe.pochta.ru.
 *
 * Задание только ставится в очередь zakaznoe - сборка идёт минуты, ход и итог
 * уходят в CRM callback'ами.
 */
class ZakaznoeBuildController extends Controller
{
    public function store(ZakaznoeBuildStoreRequest $request): JsonResponse
    {
        $task = $request->validated();

        // CRM могла не дождаться ответа и спросить ещё раз - вторую сборку того же uuid не ставим
        if (!Cache::add('zakaznoe-build:'.$task['build_uuid'], true, now()->addDay())) {
            Log::info('Zakaznoe build task duplicate', ['build_uuid' => $task['build_uuid']]);

            return response()->json(['accepted' => true, 'duplicate' => true], 202);
        }

        ZakaznoeBuildJob::dispatch($task);

        Log::info('Zakaznoe build task accepted', [
            'mail_registry_id' => $task['mail_registry_id'],
            'build_uuid' => $task['build_uuid'],
            'letters' => count($task['letters']),
        ]);

        return response()->json(['accepted' => true], 202);
    }
}
