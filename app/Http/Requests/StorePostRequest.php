<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
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
            'author_id' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'min:3', 'max:160'],
            'content' => ['required', 'string', 'min:3', 'max:2000'],
            'tags' => ['nullable'],
        ];
    }
}
