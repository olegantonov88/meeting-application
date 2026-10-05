<?php

namespace App\Services;

use App\Enums\EfrsbMessage\EfrsbDebtorMessageBodyFormat;
use App\Models\EfrsbMessage\EfrsbDebtorMessage;
use App\Services\EfrsbMessageRender\EfrsbMessageRenderer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class EfrsbDebtorMessageService
{
    private string $baseUrl;
    private ?string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.efrsb_debtor_message.url', env('EFRSB_DEBTOR_MESSAGE_URL', 'http://localhost'));
        // Получаем API key из конфигурации (которая берет значение из .env)
        $this->apiKey = config('services.efrsb_debtor_message.api_key');
    }

    /**
     * Запрашивает парсинг body_html для массива сообщений
     *
     * @param array $messages Массив сообщений в формате [['message_id' => int, 'message_uuid' => string], ...]
     * @param int|null $meetingApplicationId ID приложения для callback
     * @return array|null Возвращает ответ с job_id и parse_job_id или null при ошибке
     */
    public function requestMessageBodies(array $messages, ?int $meetingApplicationId = null): ?array
    {
        try {
            if (empty($messages)) {
                Log::warning('Empty messages array provided to requestMessageBodies');
                return null;
            }

            $url = rtrim($this->baseUrl, '/') . '/api/fedresurs/enqueue/message-tables';

            $data = [
                'messages' => $messages,
            ];

            if ($meetingApplicationId) {
                $data['meeting_application_id'] = $meetingApplicationId;
                // Добавляем callback_url для получения уведомлений о готовности body_html
                $callbackUrl = rtrim(config('app.url'), '/') . '/api/efrsb-message/callback';
                $data['callback_url'] = $callbackUrl;
            }

            // Всегда используем API key для авторизации запроса
            // efrsb-debtor-message ожидает Authorization: Bearer {key}
            if (empty($this->apiKey)) {
                Log::error('EFRSB_DEBTOR_MESSAGE_API_KEY is not configured');
                return null;
            }

            $headers = [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ];

            $response = Http::withHeaders($headers)
                ->timeout(30)
                ->post($url, $data);

            if ($response->successful()) {
                $result = $response->json();
                return $result;
            } else {
                Log::error('Failed to request message bodies', [
                    'messages_count' => count($messages),
                    'meeting_application_id' => $meetingApplicationId,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error('Exception while requesting message bodies', [
                'messages_count' => count($messages),
                'meeting_application_id' => $meetingApplicationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Проверяет наличие body_html для сообщения
     *
     * @param int $messageId ID сообщения
     * @return bool
     */
    public function hasMessageBody(int $messageId): bool
    {
        // Этот метод будет использоваться для проверки наличия body_html в БД
        // Реализация зависит от структуры БД
        return false;
    }

    /**
     * HTML сообщения для PDF.
     * Данные в html - как раньше: base64, а если не декодируется - как есть.
     * Данные в json - строим html сами в разметке Федресурса (EfrsbMessageRenderer, копия из auapp)
     *
     * @throws \RuntimeException если данных нет или json не разбирается
     */
    public function messageHtml(EfrsbDebtorMessage $message): string
    {
        if ($message->body_format === EfrsbDebtorMessageBodyFormat::JSON) {
            $json = base64_decode((string) $message->body_json, true);
            $data = json_decode($json === false ? (string) $message->body_json : $json, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Не удалось разобрать json сообщения ЕФРСБ ' . $message->id);
            }

            return app(EfrsbMessageRenderer::class)->render($data);
        }

        if (empty($message->body_html)) {
            throw new \RuntimeException('У сообщения ЕФРСБ ' . $message->id . ' нет данных');
        }

        $html = base64_decode($message->body_html, true);

        return $html === false ? $message->body_html : $html;
    }
}
