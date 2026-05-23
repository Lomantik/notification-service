<?php

namespace App\Http\Requests\Api;

use App\Enums\ProviderCallbackStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\Attributes\StopOnFirstFailure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
#[StopOnFirstFailure]
class WebhookRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<string|string>|string>
     */
    public function rules(): array
    {
        return [
            'provider_id' => ['required', 'string'],
            'status' => ['required', Rule::enum(ProviderCallbackStatus::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'provider_id.required' => 'The provider_id field is required.',
        ];
    }
}
