<?php

namespace App\Jobs;

use App\Enums\EfrsbMessageRequestStatus;
use App\Models\Meeting\MeetingApplication;
use App\Models\Meeting\MeetingApplicationGenerationTask;
use App\Enums\Meeting\MeetingApplicationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckEfrsbMessageTimeoutJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $meetingApplicationId
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $timeoutMinutes = (int) config('meeting_application.efrsb_timeout_minutes', 5);
            $timeoutTime = now()->subMinutes($timeoutMinutes);

            // Находим запросы, которые старше таймаута и еще в статусе pending
            $timeoutRequests = DB::table('efrsb_message_requests')
                ->where('meeting_application_id', $this->meetingApplicationId)
                ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                ->where('requested_at', '<', $timeoutTime)
                ->get();

            if ($timeoutRequests->isEmpty()) {
                return;
            }

            Log::warning('EFRSB message requests timeout', [
                'application_id' => $this->meetingApplicationId,
                'count' => $timeoutRequests->count(),
            ]);

            // Помечаем запросы как timeout и обновляем статистику
            $generationTask = MeetingApplicationGenerationTask::where('meeting_application_id', $this->meetingApplicationId)
                ->orderBy('started_at', 'desc')
                ->first();

            // Получаем приложение для обновления статусов сообщений
            $application = MeetingApplication::find($this->meetingApplicationId);

            foreach ($timeoutRequests as $request) {
                DB::table('efrsb_message_requests')
                    ->where('id', $request->id)
                    ->update([
                        'status' => EfrsbMessageRequestStatus::TIMEOUT->value,
                        'error' => "Превышено время ожидания ({$timeoutMinutes} минут)",
                        'updated_at' => now(),
                    ]);

                // Обновляем статус сообщения в efrsb_debtor_messages
                if ($application) {
                    $this->updateEfrsbMessageStatus(
                        $application,
                        $request->message_id,
                        'error',
                        "Превышено время ожидания ({$timeoutMinutes} минут)"
                    );
                }
            }

            // Сохраняем приложение после обновления статусов
            if ($application) {
                $application->save();
            }

            // Проверяем, есть ли еще ожидающие запросы
            $pendingCount = DB::table('efrsb_message_requests')
                ->where('meeting_application_id', $this->meetingApplicationId)
                ->where('status', EfrsbMessageRequestStatus::PENDING->value)
                ->count();

            if ($pendingCount === 0) {
                // Все запросы обработаны (успешно или с ошибкой), продолжаем генерацию

                // Получаем user_id из последней задачи генерации
                $generationTask = MeetingApplicationGenerationTask::where('meeting_application_id', $this->meetingApplicationId)
                    ->orderBy('started_at', 'desc')
                    ->first();
                $userId = $generationTask?->user_id;

                // Запускаем продолжение генерации с флагом continueAfterCallback
                GenerateMeetingApplicationJob::dispatch($this->meetingApplicationId, true, $userId);
            }
        } catch (\Exception $e) {
            Log::error('Failed to check EFRSB message timeout', [
                'application_id' => $this->meetingApplicationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обновляет статус ЕФРСБ сообщения в EfrsbDebtorMessagesMeetingApplicationObject
     *
     * @param MeetingApplication $application
     * @param int $messageId ID сообщения
     * @param string $status Статус ('generated', 'error')
     * @param string|null $errorMessage Сообщение об ошибке (если статус 'error')
     * @return void
     */
    private function updateEfrsbMessageStatus(MeetingApplication $application, int $messageId, string $status, ?string $errorMessage = null): void
    {
        $efrsbMessages = $application->efrsb_debtor_messages;
        if (!$efrsbMessages || $efrsbMessages->isEmpty()) {
            return;
        }

        // Ищем сообщение по ID
        $messageIndex = $efrsbMessages->search(function ($messageData) use ($messageId) {
            if ($messageData instanceof \Illuminate\Support\Collection) {
                return $messageData->get('id') == $messageId;
            }
            return ($messageData['id'] ?? null) == $messageId;
        });

        if ($messageIndex !== false) {
            $messageData = $efrsbMessages->get($messageIndex);
            if ($messageData instanceof \Illuminate\Support\Collection) {
                $messageData->put('status', $status);
                if ($errorMessage) {
                    $messageData->put('error', $errorMessage);
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    $messageData->forget('error');
                }
            } elseif (is_array($messageData)) {
                $messageData['status'] = $status;
                if ($errorMessage) {
                    $messageData['error'] = $errorMessage;
                } elseif ($status === 'generated') {
                    // При успешной генерации удаляем ошибку, если она была
                    unset($messageData['error']);
                }
                $efrsbMessages->put($messageIndex, $messageData);
            }
        }
    }
}
