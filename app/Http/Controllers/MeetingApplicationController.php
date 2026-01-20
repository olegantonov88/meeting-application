<?php

namespace App\Http\Controllers;

use App\Services\MeetingApplicationGenerationService;
use App\Jobs\GenerateMeetingApplicationJob;
use App\Models\Meeting\MeetingApplication;
use App\Enums\Meeting\MeetingApplicationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MeetingApplicationController extends Controller
{
    public function __construct(
        private MeetingApplicationGenerationService $generationService
    ) {
    }

    /**
     * Запуск генерации приложения к собранию
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meeting_application_id' => ['required', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $meetingApplicationId = $validated['meeting_application_id'];
        $userId = $validated['user_id'] ?? null;

        $application = MeetingApplication::find($meetingApplicationId);

        if (!$application) {
            return response()->json([
                'success' => false,
                'message' => 'Приложение к собранию не найдено',
            ], 404);
        }

        // Установить статус GENERATING и время начала генерации
        $application->latest_status = MeetingApplicationStatus::GENERATING;
        $application->start_generation = now();
        $application->addStatus(MeetingApplicationStatus::GENERATING, null, 'Начало генерации приложения');
        $application->save();

        // Запускаем генерацию в фоне через Job
        GenerateMeetingApplicationJob::dispatch($meetingApplicationId, false, $userId);

        return response()->json([
            'success' => true,
            'message' => 'Генерация приложения запущена',
            'meeting_application_id' => $meetingApplicationId,
        ]);
    }
}
