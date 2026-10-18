<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
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
            'follower_id' => ['required', 'string', 'max:64', 'different:followee_id'],
            'followee_id' => ['required', 'string', 'max:64'],
        ];
    }
}
