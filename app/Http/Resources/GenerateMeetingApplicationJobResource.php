<?php

namespace App\Http\Resources;

use App\Models\Meeting\MeetingApplicationGenerationTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GenerateMeetingApplicationJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MeetingApplicationGenerationTask $task */
        $task = $this->resource;

        // Загружаем приложение для получения статистики из ValueObjects
        $application = $task->meetingApplication;

        // Подсчитываем статистику по документам из ArbitratorFilesMeetingApplicationObject
        $documentsStats = $this->calculateDocumentsStats($application);
        
        // Подсчитываем статистику по сообщениям ЕФРСБ из EfrsbDebtorMessagesMeetingApplicationObject
        $efrsbMessagesStats = $this->calculateEfrsbMessagesStats($application);

        return [
            'data' => [
                'id' => $task->id,
                'meeting_application_id' => $task->meeting_application_id,
                'user_id' => $task->user_id,
                'status' => $task->status?->value ?? (string) $task->status,
                'status_name' => $task->status?->name,
                'status_text' => $task->status?->text(),
                'started_at' => $task->started_at?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
                'finished_at' => $task->finished_at?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
                'documents_total' => $documentsStats['total'],
                'documents_success' => $documentsStats['success'],
                'documents_error' => $documentsStats['error'],
                'efrsb_messages_total' => $efrsbMessagesStats['total'],
                'efrsb_messages_success' => $efrsbMessagesStats['success'],
                'efrsb_messages_error' => $efrsbMessagesStats['error'],
                'created_at' => $task->created_at?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
                'updated_at' => $task->updated_at?->timezone('Europe/Moscow')->format('d.m.Y H:i:s'),
            ],
        ];
    }

    /**
     * Подсчитывает статистику по документам из ArbitratorFilesMeetingApplicationObject
     *
     * @param \App\Models\Meeting\MeetingApplication|null $application
     * @return array{total: int, success: int, error: int}
     */
    private function calculateDocumentsStats($application): array
    {
        $total = 0;
        $success = 0;
        $error = 0;

        if (!$application) {
            return ['total' => 0, 'success' => 0, 'error' => 0];
        }

        $arbitratorFiles = $application->arbitrator_files;
        if (!$arbitratorFiles || !$arbitratorFiles->value) {
            return ['total' => 0, 'success' => 0, 'error' => 0];
        }

        $fileTypes = ['insurance', 'incoming_letters', 'outgoing_letters', 'inventory', 'estimate', 'trade', 'trade_contract'];

        foreach ($fileTypes as $type) {
            $files = $arbitratorFiles->value[$type] ?? collect();
            foreach ($files as $file) {
                $fileData = $file instanceof \Illuminate\Support\Collection ? $file : collect($file);
                $status = $fileData->get('status');
                $fileError = $fileData->get('error');

                $total++;

                if ($status === 'generated') {
                    $success++;
                } elseif ($status === 'error' || !empty($fileError)) {
                    $error++;
                }
            }
        }

        return ['total' => $total, 'success' => $success, 'error' => $error];
    }

    /**
     * Подсчитывает статистику по сообщениям ЕФРСБ из EfrsbDebtorMessagesMeetingApplicationObject
     *
     * @param \App\Models\Meeting\MeetingApplication|null $application
     * @return array{total: int, success: int, error: int}
     */
    private function calculateEfrsbMessagesStats($application): array
    {
        $total = 0;
        $success = 0;
        $error = 0;

        if (!$application) {
            return ['total' => 0, 'success' => 0, 'error' => 0];
        }

        $efrsbMessages = $application->efrsb_debtor_messages;
        if (!$efrsbMessages || $efrsbMessages->isEmpty()) {
            return ['total' => 0, 'success' => 0, 'error' => 0];
        }

        foreach ($efrsbMessages as $message) {
            $messageData = $message instanceof \Illuminate\Support\Collection ? $message : collect($message);
            $status = $messageData->get('status');
            $messageError = $messageData->get('error');

            $total++;

            if ($status === 'generated') {
                $success++;
            } elseif ($status === 'error' || !empty($messageError)) {
                $error++;
            }
        }

        return ['total' => $total, 'success' => $success, 'error' => $error];
    }
}
