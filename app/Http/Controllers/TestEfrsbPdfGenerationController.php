<?php

namespace App\Http\Controllers;

use App\Models\EfrsbMessage\EfrsbDebtorMessage;
use App\Services\HtmlToPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestEfrsbPdfGenerationController extends Controller
{
    public function __construct(
        private HtmlToPdfService $htmlToPdfService
    ) {
    }

    /**
     * Тестирование генерации PDF из сообщения ЕФРСБ
     *
     * @param Request $request
     * @return JsonResponse|BinaryFileResponse
     */
    public function testGeneratePdf(Request $request): JsonResponse|BinaryFileResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'min:1'],
            'download' => ['nullable', 'boolean'],
        ]);

        $messageId = $validated['message_id'];
        $download = $validated['download'] ?? false;

        try {
            // Получаем сообщение из БД
            $message = EfrsbDebtorMessage::find($messageId);

            if (!$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сообщение не найдено',
                ], 404);
            }

            // Проверяем наличие body_html
            if (empty($message->body_html)) {
                return response()->json([
                    'success' => false,
                    'message' => 'У сообщения отсутствует body_html',
                    'message_id' => $messageId,
                    'message_uuid' => $message->uuid ?? null,
                ], 400);
            }

            // Декодируем HTML (может быть в base64)
            $html = base64_decode($message->body_html, true);
            if ($html === false) {
                $html = $message->body_html; // Если не base64, используем как есть
            }

            // Создаем временный файл для PDF
            $tempPath = $this->getTempFilePath('test_efrsb_message_' . $messageId . '.pdf');

            // Генерируем PDF
            $this->htmlToPdfService->generate($html, $tempPath, [], $message->title ?? null);

            // Проверяем, что файл создан
            if (!file_exists($tempPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'PDF файл не был создан',
                ], 500);
            }

            $fileSize = filesize($tempPath);

            Log::info('Test PDF generation completed', [
                'message_id' => $messageId,
                'pdf_path' => $tempPath,
                'file_size' => $fileSize,
            ]);

            // Если запрошена загрузка файла
            if ($download) {
                return response()->download($tempPath, 'efrsb_message_' . $messageId . '.pdf')
                    ->deleteFileAfterSend(true);
            }

            // Возвращаем информацию о файле
            return response()->json([
                'success' => true,
                'message' => 'PDF успешно сгенерирован',
                'data' => [
                    'message_id' => $messageId,
                    'message_uuid' => $message->uuid ?? null,
                    'pdf_path' => $tempPath,
                    'file_size' => $fileSize,
                    'file_size_mb' => round($fileSize / 1024 / 1024, 2),
                    'html_length' => strlen($html),
                    'body_html_encoded' => !empty($message->body_html) && base64_decode($message->body_html, true) !== false,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Test PDF generation failed', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка генерации PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить информацию о сообщении ЕФРСБ
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMessageInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message_id' => ['required', 'integer', 'min:1'],
        ]);

        $messageId = $validated['message_id'];

        try {
            $message = EfrsbDebtorMessage::find($messageId);

            if (!$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сообщение не найдено',
                ], 404);
            }

            $hasBodyHtml = !empty($message->body_html);
            $htmlLength = $hasBodyHtml ? strlen($message->body_html) : 0;
            $isBase64Encoded = false;

            if ($hasBodyHtml) {
                $decoded = base64_decode($message->body_html, true);
                $isBase64Encoded = $decoded !== false;
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $message->id,
                    'uuid' => $message->uuid ?? null,
                    'debtor_id' => $message->debtor_id ?? null,
                    'publish_date' => $message->publish_date?->toDateTimeString(),
                    'has_body_html' => $hasBodyHtml,
                    'body_html_length' => $htmlLength,
                    'body_html_is_base64' => $isBase64Encoded,
                    'created_at' => $message->created_at?->toDateTimeString(),
                    'updated_at' => $message->updated_at?->toDateTimeString(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get message info', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка получения информации о сообщении',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получает путь для временного файла
     *
     * @param string $filename
     * @return string
     */
    private function getTempFilePath(string $filename): string
    {
        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $safeName = Str::random(8) . '_' . Str::slug(pathinfo($filename, PATHINFO_FILENAME));
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $tmpDir . '/' . $safeName . ($extension ? ".{$extension}" : '');
    }
}
