<?php

namespace App\Services\Zakaznoe;

use App\Services\Zakaznoe\Exceptions\ZakaznoeBuildAborted;
use App\Services\Zakaznoe\Exceptions\ZakaznoeCallbackRejected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * События сборки в CRM.
 *
 * Ключ - тот же, которым CRM обращается к воркеру. Повтор - только при сетевой
 * ошибке и 5xx: 409/404 значат «сборку перезапустили или реестра нет»,
 * 422 - CRM не приняла данные, и повтор тех же данных ничего не изменит.
 */
class ZakaznoeCallbackClient
{
    public function __construct(
        private readonly string $url,
        private readonly int $mailRegistryId,
        private readonly string $buildUuid,
    ) {
    }

    /**
     * @param  array<string, mixed>  $fields  поля события сверх общих
     *
     * @throws ZakaznoeBuildAborted
     * @throws ZakaznoeCallbackRejected
     * @throws RuntimeException CRM недоступна после всех попыток или ответила неожиданно
     */
    public function send(string $event, array $fields = []): void
    {
        $payload = [
            'mail_registry_id' => $this->mailRegistryId,
            'build_uuid' => $this->buildUuid,
            'event' => $event,
            ...$fields,
        ];

        $attempts = max(1, (int) config('zakaznoe.callback.attempts', 4));

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::timeout((int) config('zakaznoe.callback.timeout', 30))
                    ->acceptJson()
                    ->withToken((string) config('services.meeting_application.api_key'))
                    ->post($this->url, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $attempts) {
                    throw new RuntimeException("CRM недоступна: {$e->getMessage()}", previous: $e);
                }

                $this->pause($attempt);

                continue;
            }

            if ($response->successful()) {
                return;
            }

            $status = $response->status();

            if (in_array($status, [404, 409], true)) {
                throw new ZakaznoeBuildAborted("CRM ответила {$status} на {$event}: ".($response->json('message') ?? ''));
            }

            if ($status === 422) {
                Log::error('Zakaznoe build callback rejected by CRM', [
                    'build_uuid' => $this->buildUuid,
                    'event' => $event,
                    'errors' => $response->json('errors') ?? $response->body(),
                ]);

                throw new ZakaznoeCallbackRejected("CRM отклонила событие {$event}");
            }

            if ($response->serverError() && $attempt < $attempts) {
                $this->pause($attempt);

                continue;
            }

            throw new RuntimeException("CRM ответила {$status} на событие {$event}");
        }
    }

    private function pause(int $attempt): void
    {
        Sleep::for(min(30, 2 ** $attempt))->seconds();
    }
}
