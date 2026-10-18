<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TouchActiveUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'max:64'],
            'action' => ['required', 'string', 'min:2', 'max:120'],
            'ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:86400'],
        ];
    }
}
