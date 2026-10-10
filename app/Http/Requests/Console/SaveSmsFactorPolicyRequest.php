<?php

declare(strict_types=1);

namespace App\Http\Requests\Console;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The environment's SMS second-factor policy as the Authentication policy page posts it.
 * Shape only — which countries exist, and that SMS on needs at least one, is the action's
 * to say, so the API and the page refuse the same way.
 */
final class SaveSmsFactorPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'allowedCountries' => ['present', 'array', 'max:250'],
            'allowedCountries.*' => ['string', 'size:2'],
            'privilegedNeedStrongerFactor' => ['required', 'boolean'],
        ];
    }

    /** @return array{enabled: bool, allowed_countries: list<string>, privileged_need_stronger_factor: bool} */
    public function policyInput(): array
    {
        $countries = $this->input('allowedCountries');

        return [
            'enabled' => $this->boolean('enabled'),
            'allowed_countries' => array_values(array_map(
                static fn (mixed $country): string => strtoupper(is_string($country) ? trim($country) : ''),
                is_array($countries) ? $countries : [],
            )),
            'privileged_need_stronger_factor' => $this->boolean('privilegedNeedStrongerFactor'),
        ];
    }
}
