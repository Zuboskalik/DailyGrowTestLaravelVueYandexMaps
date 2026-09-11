<?php

namespace App\Http\Requests;

use App\Rules\ValidYandexMapsOrganizationUrl;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', new ValidYandexMapsOrganizationUrl()],
        ];
    }
}
