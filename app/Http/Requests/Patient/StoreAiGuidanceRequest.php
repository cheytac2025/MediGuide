<?php

namespace App\Http\Requests\Patient;

use App\Support\AiConversationContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreAiGuidanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $message = $this->input('message');

        if (is_string($message)) {
            $this->merge([
                'message' => trim($message),
            ]);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:'.AiConversationContext::MAX_CHARACTERS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required' => 'Please describe your concern before sending.',
            'message.string' => 'Please describe your concern before sending.',
            'message.max' => 'Please shorten your message and try again.',
        ];
    }

    public function messageText(): string
    {
        $message = $this->validated('message');

        return is_string($message) ? $message : '';
    }
}
