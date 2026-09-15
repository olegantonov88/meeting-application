<?php

namespace App\Jobs;

use App\Services\Zakaznoe\ZakaznoeBuildService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Сборка архива для zakaznoe.pochta.ru.
 *
 * Своё подключение очереди: сборка идёт минуты, а retry_after общей очереди -
 * 90 секунд. Там задание запустилось бы второй раз параллельно, а заодно
 * придержало бы генерацию приложений к собранию.
 *
 * Одна попытка: повтор пересобрал бы архив поверх уже отправленных событий.
 * Упавшую сборку пользователь перезапускает из CRM.
 */
class ZakaznoeBuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    /**
     * @param  array<string, mixed>  $task  провалидированный ZakaznoeBuildStoreRequest
     */
    public function __construct(public array $task)
    {
        $this->timeout = (int) config('zakaznoe.job_timeout', 3600);

        $this->onConnection('zakaznoe');
        $this->onQueue('zakaznoe');
    }

    public function handle(ZakaznoeBuildService $service): void
    {
        $service->build($this->task);
    }

    /**
     * Сюда попадает только то, что сборка не поймала сама: таймаут и падение процесса.
     */
    public function failed(?Throwable $exception): void
    {
        app(ZakaznoeBuildService::class)->reportCrash($this->task, $exception);
    }
}
