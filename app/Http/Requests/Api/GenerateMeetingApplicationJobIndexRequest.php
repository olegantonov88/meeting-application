<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class GenerateMeetingApplicationJobIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'meeting_application_id' => ['sometimes', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:64'], // MeetingApplicationGenerationTaskStatus value/int
        ];
    }
}
