<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MutualFriendsRequest extends FormRequest
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
            'user_a_id' => ['required', 'string', 'max:64', 'different:user_b_id'],
            'user_b_id' => ['required', 'string', 'max:64'],
        ];
    }
}
