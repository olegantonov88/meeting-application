<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\GenerateMeetingApplicationJobIndexRequest;
use App\Http\Resources\GenerateMeetingApplicationJobResource;
use App\Models\Meeting\MeetingApplicationGenerationTask;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GenerateMeetingApplicationJobApiController extends Controller
{
    public function index(GenerateMeetingApplicationJobIndexRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $query = MeetingApplicationGenerationTask::query()
            ->with('meetingApplication')
            ->orderByDesc('id');

        if (!empty($data['meeting_application_id'])) {
            $query->where('meeting_application_id', (int) $data['meeting_application_id']);
        }

        if (!empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }

        if (!empty($data['status'])) {
            $query->where('status', (string) $data['status']);
        }

        $perPage = (int) ($data['per_page'] ?? 25);

        return GenerateMeetingApplicationJobResource::collection($query->paginate($perPage));
    }
}
