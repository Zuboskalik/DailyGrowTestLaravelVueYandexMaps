<?php

namespace App\Rules;

use App\Support\YandexOrganizationUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidYandexMapsOrganizationUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! YandexOrganizationUrl::isOrganizationUrl($value)) {
            $fail('The :attribute must be a link to a Yandex Maps organization page.');
        }
    }
}
