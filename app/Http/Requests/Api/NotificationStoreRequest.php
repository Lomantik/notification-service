<?php

namespace App\Http\Requests\Api;

use App\Enums\NotificationChannel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\Attributes\StopOnFirstFailure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
#[StopOnFirstFailure]
class NotificationStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'uuid' => $this->header('Idempotency-Key'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<string|string>|string>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'text' => ['required', 'string'],
            'user_ids' => ['required', 'array'],
            'user_ids.*' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['nullable', 'integer', 'between:1,10'],
            'uuid' => 'required|uuid',
        ];
    }

    public function messages(): array
    {
        return [
            'uuid.required' => 'The Idempotency-Key header is missing.',
            'uuid.uuid' => 'The Idempotency-Key must be a valid UUID',
        ];
    }
}
