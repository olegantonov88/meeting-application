<?php

namespace App\Jobs;

use App\Services\MeetingApplicationGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateMeetingApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $meetingApplicationId,
        public bool $continueAfterCallback = false,
        public ?int $userId = null
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(MeetingApplicationGenerationService $generationService): void
    {
        try {
            Log::info('Starting meeting application generation job', [
                'application_id' => $this->meetingApplicationId,
                'user_id' => $this->userId,
            ]);

            $generationService->generate($this->meetingApplicationId, $this->continueAfterCallback, $this->userId);

            Log::info('Meeting application generation job completed', [
                'application_id' => $this->meetingApplicationId,
            ]);
        } catch (\Exception $e) {
            Log::error('Meeting application generation job failed', [
                'application_id' => $this->meetingApplicationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
