<?php

namespace App\Http\Controllers;

use App\Enums\EfrsbMessageRequestStatus;
use App\Models\EfrsbMessage\EfrsbDebtorMessage;
use App\Models\Meeting\MeetingApplication;
use App\Models\Meeting\MeetingApplicationGenerationTask;
use App\Services\MeetingApplicationGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EfrsbMessageCallbackController extends Controller
{
    public function __construct(
        private MeetingApplicationGenerationService $generationService
    ) {
    }

    /**
     * Callback от efrsb-debtor-message о получении body_html
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message_id' => 'required|integer',
            'message_uuid' => 'required|string',
            'status' => 'required|string|in:success,error',
            'error' => 'nullable|string',
            'meeting_application_id' => 'nullable|integer|min:1',
        ]);

        try {
            // Проверяем существование сообщения (но не обновляем его - это делает efrsb-debtor-message)
            $message = EfrsbDebtorMessage::find($data['message_id']);

            if (!$message) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сообщение не найдено',
                ], 404);
            }

            if ($data['status'] === 'success') {
                // Обновляем статус запроса
                DB::table('efrsb_message_requests')
                    ->where('message_id', $data['message_id'])
                    ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                    ->update([
                        'status' => EfrsbMessageRequestStatus::COMPLETED->value,
                        'updated_at' => now(),
                    ]);

                // Проверяем, все ли сообщения готовы, и продолжаем генерацию
                $this->checkAndContinueGeneration($data['message_id'], $data['meeting_application_id'] ?? null);
            } else {
                // Ошибка при получении body_html
                DB::table('efrsb_message_requests')
                    ->where('message_id', $data['message_id'])
                    ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                    ->update([
                        'status' => EfrsbMessageRequestStatus::ERROR->value,
                        'error' => $data['error'] ?? 'Unknown error',
                        'updated_at' => now(),
                    ]);

                Log::warning('EFRSB message body request failed', [
                    'message_id' => $data['message_id'],
                    'error' => $data['error'] ?? 'Unknown error',
                ]);

                // Даже при ошибке проверяем, все ли сообщения обработаны, и продолжаем генерацию
                $this->checkAndContinueGeneration($data['message_id'], $data['meeting_application_id'] ?? null);
            }

            return response()->json([
                'success' => true,
                'message' => 'Callback обработан',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to process EFRSB callback', [
                'data' => $data,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ошибка обработки callback',
            ], 500);
        }
    }

    /**
     * Проверяет, все ли сообщения готовы, и продолжает генерацию
     */
    private function checkAndContinueGeneration(int $messageId, ?int $meetingApplicationId = null): void
    {
        // Определяем application_id: используем переданный из callback или ищем по message_id
        $applicationId = $meetingApplicationId;
        
        if (!$applicationId) {
            // Если не передан, ищем приложение по message_id
            $request = DB::table('efrsb_message_requests')
                ->where('message_id', $messageId)
                ->whereIn('status', [
                    EfrsbMessageRequestStatus::COMPLETED->value,
                    EfrsbMessageRequestStatus::ERROR->value,
                ])
                ->first();

            if (!$request) {
                Log::warning('EFRSB message request not found', [
                    'message_id' => $messageId,
                ]);
                return;
            }

            $applicationId = $request->meeting_application_id;
        }

        // Проверяем, что приложение существует
        $application = MeetingApplication::find($applicationId);
        if (!$application) {
            Log::error('Meeting application not found for callback', [
                'message_id' => $messageId,
                'meeting_application_id' => $applicationId,
            ]);
            return;
        }

        // Проверяем, есть ли еще ожидающие сообщения
        $pendingCount = DB::table('efrsb_message_requests')
            ->where('meeting_application_id', $applicationId)
            ->where('status', EfrsbMessageRequestStatus::PENDING->value)
            ->count();

        if ($pendingCount === 0) {
            // Все сообщения обработаны (успешно или с ошибкой), продолжаем генерацию

            // Получаем user_id из последней задачи генерации
            $generationTask = MeetingApplicationGenerationTask::where('meeting_application_id', $applicationId)
                ->orderBy('started_at', 'desc')
                ->first();
            $userId = $generationTask?->user_id;

            // Запускаем продолжение генерации с флагом continueAfterCallback
            \App\Jobs\GenerateMeetingApplicationJob::dispatch($applicationId, true, $userId);
        }
    }
}
